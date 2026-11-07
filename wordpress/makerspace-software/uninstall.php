<?php

namespace MakerspaceNaestved\Software;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/cleanup.php';

if ( is_multisite() ) {
	$offset = 0;
	do {
		$sites = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset, 'orderby' => 'id' ) );
		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );
			try {
				uninstall_site();
			} finally {
				restore_current_blog();
			}
		}
		$offset += count( $sites );
	} while ( count( $sites ) === 100 );
} else {
	uninstall_site();
}

delete_site_transient( RELEASE_CACHE );
