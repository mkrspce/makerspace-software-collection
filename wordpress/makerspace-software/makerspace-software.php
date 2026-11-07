<?php
/**
 * Plugin Name: Makerspace Næstved Software
 * Description: Importerer softwarekataloget fra Makerspace Næstved til et lokalt WordPress-arkiv.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: makerspace-software
 */

namespace MakerspaceNaestved\Software;

defined( 'ABSPATH' ) || exit;

const CRON_HOOK = 'mkrspce_software_sync';
const SOURCE = 'https://raw.githubusercontent.com/mkrspce/makerspace-software-collection/main/software.json';
const SOURCE_ID = '_mkrspce_software_source_id';
const LAST_ATTEMPT = 'mkrspce_software_last_attempt';

function register_type() {
	register_post_type(
		'software',
		array(
			'labels' => array(
				'name'          => 'Software',
				'singular_name' => 'Software',
			),
			'public'       => true,
			'has_archive'  => true,
			'rewrite'      => array( 'slug' => 'software', 'with_front' => false ),
			'supports'     => array( 'title', 'editor' ),
			'show_in_rest' => true,
		)
	);
}

function activate() {
	register_type();
	flush_rewrite_rules();
	if ( ! wp_next_scheduled( CRON_HOOK ) ) {
		wp_schedule_event( time(), 'hourly', CRON_HOOK );
	}
}

function deactivate() {
	wp_clear_scheduled_hook( CRON_HOOK );
	unregister_post_type( 'software' );
	flush_rewrite_rules();
}

function sort_archive( $query ) {
	if ( ! is_admin() && $query->is_main_query() && $query->is_post_type_archive( 'software' ) ) {
		$query->set( 'orderby', array( 'title' => 'ASC', 'ID' => 'ASC' ) );
	}
}

function is_web_url( $value ) {
	if ( ! is_string( $value ) ) {
		return false;
	}

	$parts = wp_parse_url( $value );
	return ! empty( $parts['host'] ) && ( $parts['scheme'] ?? '' ) === 'https'
		&& filter_var( $value, FILTER_VALIDATE_URL );
}

function read_catalog( $body ) {
	$catalog = json_decode( $body, true );
	if ( ( $catalog['schemaVersion'] ?? null ) !== 1 || ! is_array( $catalog['tools'] ?? null ) || ! $catalog['tools'] ) {
		return null;
	}

	$entries = array();
	foreach ( $catalog['tools'] as $entry ) {
		$id = $entry['id'] ?? null;
		if ( ! is_string( $id ) || ! preg_match( '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $id )
			|| isset( $entries[ $id ] ) || ! is_string( $entry['name'] ?? null )
			|| trim( $entry['name'] ) === '' || ! is_string( $entry['description'] ?? null )
			|| trim( $entry['description'] ) === '' || ! is_web_url( $entry['url'] ?? null ) ) {
			return null;
		}
		$entries[ $id ] = $entry;
	}
	return $entries;
}

function entry_content( $entry ) {
	$content = '<p>' . esc_html( $entry['description'] ) . '</p>';
	if ( is_string( $entry['license'] ?? null ) && $entry['license'] !== '' ) {
		$content .= '<p><strong>Licens</strong> ' . esc_html( $entry['license'] );
		if ( $entry['license'] === 'Open source' && is_web_url( $entry['githubUrl'] ?? null )
			&& wp_parse_url( $entry['githubUrl'], PHP_URL_HOST ) === 'github.com' ) {
			$content .= ' (<a href="' . esc_url( $entry['githubUrl'] ) . '">GitHub</a>)';
		}
		$content .= '</p>';
	}
	if ( is_array( $entry['hardware'] ?? null ) ) {
		$hardware = array_filter( $entry['hardware'], 'is_string' );
		if ( $hardware ) {
			$content .= '<p><strong>Udstyr</strong> ' . esc_html( implode( ', ', $hardware ) ) . '</p>';
		}
	}
	return $content . '<p><a href="' . esc_url( $entry['url'] ) . '">Besøg ' . esc_html( $entry['name'] ) . '</a></p>';
}

function sync_catalog() {
	// Keep the limit across cron retries and plugin reactivation.
	$now = time();
	$last_attempt = get_option( LAST_ATTEMPT );
	if ( $last_attempt !== false && $now - (int) $last_attempt < DAY_IN_SECONDS ) {
		return;
	}
	if ( $last_attempt === false ) {
		if ( ! add_option( LAST_ATTEMPT, $now, '', false ) ) {
			return;
		}
	} else {
		// Atomically claim the next attempt if another request is running cron too.
		global $wpdb;
		$claimed = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => (string) $now ),
			array( 'option_name' => LAST_ATTEMPT, 'option_value' => (string) $last_attempt )
		);
		if ( $claimed !== 1 ) {
			return;
		}
		wp_cache_delete( LAST_ATTEMPT, 'options' );
	}

	$response = wp_safe_remote_get( SOURCE, array( 'timeout' => 10, 'limit_response_size' => 1024 * 1024 ) );
	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
		return;
	}
	$entries = read_catalog( wp_remote_retrieve_body( $response ) );
	if ( $entries === null ) {
		return;
	}

	$posts = get_posts(
		array(
			'post_type'      => 'software',
			'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future', 'trash' ),
			'posts_per_page' => -1,
			'meta_key'       => SOURCE_ID,
		)
	);
	$existing = array();
	foreach ( $posts as $post ) {
		$existing[ get_post_meta( $post->ID, SOURCE_ID, true ) ] = $post;
	}

	foreach ( $entries as $id => $entry ) {
		$post = $existing[ $id ] ?? null;
		$title = sanitize_text_field( $entry['name'] );
		$content = entry_content( $entry );
		if ( $post && $post->post_status === 'publish' && $post->post_title === $title && $post->post_content === $content ) {
			continue;
		}
		$fields = array(
			'post_type'    => 'software',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
		);
		if ( $post ) {
			$fields['ID'] = $post->ID;
		} else {
			$fields['post_name'] = $id;
			$fields['meta_input'] = array( SOURCE_ID => $id );
		}
		if ( is_wp_error( wp_insert_post( wp_slash( $fields ), true ) ) ) {
			return;
		}
	}

	// Keep removed entries as drafts, so they can reuse the same post if restored upstream.
	foreach ( array_diff_key( $existing, $entries ) as $post ) {
		if ( $post->post_status === 'publish' ) {
			wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft' ) );
		}
	}
}

add_action( 'init', __NAMESPACE__ . '\\register_type' );
add_action( 'pre_get_posts', __NAMESPACE__ . '\\sort_archive' );
add_action( CRON_HOOK, __NAMESPACE__ . '\\sync_catalog' );
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
