<?php

namespace MakerspaceNaestved\Software;

defined( 'ABSPATH' ) || exit;

const CRON_HOOK = 'mkrspce_software_sync';
const SOURCE_ID = '_mkrspce_software_source_id';
const LOGO_HASH = '_mkrspce_software_logo_hash';
const LAST_ATTEMPT = 'mkrspce_software_last_attempt';
const SYNC_RESULT = 'mkrspce_software_sync_result';
const LAST_SUCCESS = 'mkrspce_software_last_success';
const RELEASE_CACHE = 'mkrspce_software_latest_release';
const IMPORT_PAUSED = 'mkrspce_software_import_paused';
const PURGE_ON_UNINSTALL = 'mkrspce_software_purge_on_uninstall';

function with_catalog_lock( $operation ) {
	global $wpdb;
	$key = hash( 'sha256', DB_NAME . ':' . $wpdb->options . ':mkrspce-software' );
	if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ) !== 1 ) {
		return 'busy';
	}
	try {
		return $operation();
	} finally {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
	}
}

function delete_imported_content() {
	$posts = get_posts(
		array(
			'post_type'      => 'software',
			'post_status'    => array_keys( get_post_stati() ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => SOURCE_ID,
		)
	);
	$success = true;
	foreach ( $posts as $post_id ) {
		$images = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => array_keys( get_post_stati() ),
				'post_parent'    => $post_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => LOGO_HASH,
			)
		);
		$images_deleted = true;
		foreach ( $images as $image_id ) {
			if ( ! wp_delete_attachment( $image_id, true ) ) {
				$images_deleted = false;
			}
		}
		// Retain the parent on error so its remaining images can be found on retry.
		if ( ! $images_deleted || ! wp_delete_post( $post_id, true ) ) {
			$success = false;
		}
	}
	if ( $success ) {
		delete_option( LAST_SUCCESS );
	}
	return $success ? 'deleted' : 'delete-error';
}

function purge_catalog() {
	return with_catalog_lock(
		function () {
			update_option( IMPORT_PAUSED, true, false );
			return delete_imported_content();
		}
	);
}

function uninstall_site() {
	$result = with_catalog_lock(
		function () {
			if ( get_option( PURGE_ON_UNINSTALL ) && delete_imported_content() !== 'deleted' ) {
				return 'delete-error';
			}
			wp_clear_scheduled_hook( CRON_HOOK );
			foreach ( array( LAST_ATTEMPT, SYNC_RESULT, LAST_SUCCESS, IMPORT_PAUSED, PURGE_ON_UNINSTALL ) as $option ) {
				delete_option( $option );
			}
			return 'deleted';
		}
	);
	if ( $result !== 'deleted' ) {
		wp_die( 'Softwarekataloget kunne ikke ryddes op. Prøv at slette pluginnet igen om lidt.' );
	}
}
