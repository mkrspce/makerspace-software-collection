<?php
/**
 * Plugin Name: Makerspace Næstved Software
 * Description: Importerer softwarekataloget fra Makerspace Næstved til et lokalt WordPress-arkiv.
 * Version: 1.3.0
 * Update URI: https://github.com/mkrspce/makerspace-software-collection
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: makerspace-software
 */

namespace MakerspaceNaestved\Software;

defined( 'ABSPATH' ) || exit;

const SOURCE = 'https://raw.githubusercontent.com/mkrspce/makerspace-software-collection/main/software.json';
const PLUGIN_FILE = __FILE__;

require_once __DIR__ . '/cleanup.php';

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
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
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
	$details = $entry['details'] ?? null;
	$content = '<p>' . esc_html(
		is_string( $details ) && trim( $details ) !== '' ? $details : $entry['description']
	) . '</p>';
	if ( is_web_url( $entry['detailsSourceUrl'] ?? null ) ) {
		$content .= '<p><a href="' . esc_url( $entry['detailsSourceUrl'] ) . '">Kilde til beskrivelsen</a></p>';
	}
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

function sync_logo( $post_id, $entry ) {
	$logo = $entry['logo'] ?? null;
	$path = 'logos/' . $entry['id'] . '.png';
	$hash = $logo['sha256'] ?? null;
	if ( ( $logo['path'] ?? null ) !== $path || ! is_string( $hash )
		|| ! preg_match( '/\A[a-f0-9]{64}\z/', $hash ) ) {
		return;
	}

	$attachment_id = get_post_thumbnail_id( $post_id );
	if ( $attachment_id && get_post_meta( $attachment_id, LOGO_HASH, true ) === $hash
		&& wp_attachment_is_image( $attachment_id ) ) {
		return;
	}

	// Reuse an already imported attachment if the featured image was changed.
	$matches = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_parent'    => $post_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => LOGO_HASH,
			'meta_value'     => $hash,
		)
	);
	if ( $matches && wp_attachment_is_image( $matches[0] ) ) {
		set_post_thumbnail( $post_id, $matches[0] );
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$temp = wp_tempnam( basename( $path ) );
	if ( ! $temp ) {
		return;
	}
	try {
		$url = add_query_arg( 'sha256', $hash, dirname( SOURCE ) . '/' . $path );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'limit_response_size' => 512 * 1024,
				'stream'              => true,
				'filename'            => $temp,
			)
		);
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200
			|| hash_file( 'sha256', $temp ) !== $hash || wp_get_image_mime( $temp ) !== 'image/png' ) {
			return;
		}
		$new_id = media_handle_sideload(
			array( 'name' => basename( $path ), 'tmp_name' => $temp ),
			$post_id,
			sanitize_text_field( $entry['name'] ) . ' logo'
		);
		if ( ! is_wp_error( $new_id ) ) {
			update_post_meta( $new_id, LOGO_HASH, $hash );
			update_post_meta( $new_id, '_wp_attachment_image_alt', sanitize_text_field( $entry['name'] ) );
			set_post_thumbnail( $post_id, $new_id );
		}
	} finally {
		if ( is_file( $temp ) ) {
			wp_delete_file( $temp );
		}
	}
}

function sync_catalog( $manual = false ) {
	return with_catalog_lock(
		function () use ( $manual ) {
			if ( ! $manual && get_option( IMPORT_PAUSED ) ) {
				return 'paused';
			}
			$result = sync_catalog_locked( $manual );
			if ( $manual && $result === 'success' ) {
				delete_option( IMPORT_PAUSED );
			}
			return $result;
		}
	);
}

function sync_catalog_locked( $manual ) {
	// Keep the limit across cron retries and plugin reactivation.
	$now = time();
	$last_attempt = get_option( LAST_ATTEMPT );
	if ( ! $manual && $last_attempt !== false && $now - (int) $last_attempt < DAY_IN_SECONDS ) {
		return 'cached';
	}
	update_option( LAST_ATTEMPT, $now, false );

	$response = wp_safe_remote_get( SOURCE, array( 'timeout' => 10, 'limit_response_size' => 1024 * 1024 ) );
	if ( is_wp_error( $response ) ) {
		return record_sync( $now, 'download-error', $response->get_error_message() );
	}
	$http_status = wp_remote_retrieve_response_code( $response );
	if ( $http_status !== 200 ) {
		return record_sync( $now, 'download-error', 'HTTP ' . $http_status );
	}
	$entries = read_catalog( wp_remote_retrieve_body( $response ) );
	if ( $entries === null ) {
		return record_sync( $now, 'invalid-catalog' );
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
		$excerpt = sanitize_text_field( $entry['description'] );
		$content = entry_content( $entry );
		if ( $post && $post->post_status === 'publish' && $post->post_title === $title
			&& $post->post_excerpt === $excerpt && $post->post_content === $content ) {
			sync_logo( $post->ID, $entry );
			continue;
		}
		$fields = array(
			'post_type'    => 'software',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_excerpt' => $excerpt,
			'post_content' => $content,
		);
		if ( $post ) {
			$fields['ID'] = $post->ID;
		} else {
			$fields['post_name'] = $id;
			$fields['meta_input'] = array( SOURCE_ID => $id );
		}
		$post_id = wp_insert_post( wp_slash( $fields ), true );
		if ( is_wp_error( $post_id ) ) {
			return record_sync( $now, 'post-error', $post_id->get_error_message() );
		}
		sync_logo( $post_id, $entry );
	}

	// Keep removed entries as drafts, so they can reuse the same post if restored upstream.
	foreach ( array_diff_key( $existing, $entries ) as $post ) {
		if ( $post->post_status === 'publish' ) {
			$updated = wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft' ), true );
			if ( is_wp_error( $updated ) ) {
				return record_sync( $now, 'post-error', $updated->get_error_message() );
			}
		}
	}
	update_option( LAST_SUCCESS, array( 'time' => time(), 'count' => count( $entries ) ), false );
	return record_sync( $now, 'success' );
}

function record_sync( $attempt, $status, $error = '' ) {
	update_option( SYNC_RESULT, array( 'attempt' => $attempt, 'status' => $status, 'error' => $error ), false );
	return $status;
}

add_action( 'init', __NAMESPACE__ . '\\register_type' );
add_action( 'pre_get_posts', __NAMESPACE__ . '\\sort_archive' );
add_action( CRON_HOOK, __NAMESPACE__ . '\\sync_catalog' );
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

require_once __DIR__ . '/admin.php';
