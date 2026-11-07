<?php

namespace MakerspaceNaestved\Software;

defined( 'ABSPATH' ) || exit;

const CONTROL_PAGE = 'mkrspce-software-control';
const RELEASE_CACHE = 'mkrspce_software_latest_release';
const RELEASES = 'https://github.com/mkrspce/makerspace-software-collection/releases';

function register_control_page() {
	add_submenu_page(
		'edit.php?post_type=software',
		'Softwarekontrol',
		'Kontrol',
		'manage_options',
		CONTROL_PAGE,
		__NAMESPACE__ . '\\render_control_page'
	);
}

function control_url() {
	return admin_url( 'edit.php?post_type=software&page=' . CONTROL_PAGE );
}

function handle_control_action() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Du har ikke adgang til softwarekontrollen.', '', array( 'response' => 403 ) );
	}
	check_admin_referer( CONTROL_PAGE );
	$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] )
		? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
	switch ( $operation ) {
		case 'sync':
			$result = sync_catalog( true );
			break;
		case 'check-update':
			if ( ! current_user_can( 'update_plugins' ) ) {
				wp_die( 'Du har ikke adgang til pluginopdateringer.', '', array( 'response' => 403 ) );
			}
			$release = latest_release( true );
			if ( ! $release ) {
				$result = 'update-error';
				break;
			}
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			delete_site_transient( 'update_plugins' );
			wp_update_plugins();
			$installed = get_plugin_data( PLUGIN_FILE, false, false )['Version'];
			$result = version_compare( $release['version'], $installed, '>' ) ? 'update-found' : 'up-to-date';
			break;
		default:
			wp_die( 'Ukendt handling.', '', array( 'response' => 400 ) );
	}
	wp_safe_redirect( add_query_arg( 'result', $result, control_url() ) );
	exit;
}

function latest_release( $refresh = false ) {
	$cached = get_site_transient( RELEASE_CACHE );
	if ( ! $refresh && $cached !== false ) {
		return $cached ?: null;
	}

	$response = wp_safe_remote_get(
		'https://api.github.com/repos/mkrspce/makerspace-software-collection/releases/latest',
		array( 'timeout' => 5, 'limit_response_size' => 256 * 1024 )
	);
	$release = wp_remote_retrieve_response_code( $response ) === 200
		? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
	$tag = $release['tag_name'] ?? null;
	if ( is_string( $tag ) && preg_match( '/\Av(\d+\.\d+\.\d+)\z/', $tag, $matches )
		&& ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
		$package = RELEASES . '/download/' . $tag . '/makerspace-software.zip';
		foreach ( $release['assets'] as $asset ) {
			if ( ( $asset['browser_download_url'] ?? null ) === $package ) {
				$result = array(
					'version' => $matches[1],
					'url'     => RELEASES . '/tag/' . $tag,
					'package' => $package,
				);
				set_site_transient( RELEASE_CACHE, $result, 6 * HOUR_IN_SECONDS );
				return $result;
			}
		}
	}
	if ( $cached === false ) {
		set_site_transient( RELEASE_CACHE, array(), 15 * MINUTE_IN_SECONDS );
	}
	return null;
}

function plugin_update( $update, $data, $file ) {
	if ( $file !== plugin_basename( PLUGIN_FILE ) ) {
		return $update;
	}

	$release = latest_release();
	if ( ! $release ) {
		return $update;
	}
	return $release + array(
		'slug'         => 'makerspace-software',
		'requires_php' => '7.4',
	);
}

function control_date( $timestamp ) {
	return $timestamp ? wp_date( 'd.m.Y H:i:s', (int) $timestamp ) : 'Ingen registreret';
}

function render_control_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$installed = get_plugin_data( PLUGIN_FILE, false, false )['Version'];
	$release = get_site_transient( RELEASE_CACHE );
	$attempt = (int) get_option( LAST_ATTEMPT );
	$result = get_option( SYNC_RESULT, array() );
	$success = get_option( LAST_SUCCESS, array() );
	$messages = array(
		'success'         => 'Kataloget blev genindlæst.',
		'busy'            => 'En synkronisering kører allerede, eller startede i dette sekund. Prøv igen om lidt.',
		'download-error'  => 'Katalogfilen kunne ikke hentes.',
		'invalid-catalog' => 'Den hentede katalogfil er ugyldig.',
		'post-error'      => 'En softwarepost kunne ikke gemmes.',
		'update-error'    => 'En pluginopdatering kunne ikke kontrolleres på GitHub.',
		'update-found'    => 'En ny pluginversion er tilgængelig.',
		'up-to-date'      => 'Den installerede pluginversion er opdateret.',
	);
	$notice = isset( $_GET['result'] ) && is_string( $_GET['result'] )
		? sanitize_key( wp_unslash( $_GET['result'] ) ) : '';
	$posts = get_posts(
		array(
			'post_type'      => 'software',
			'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future', 'trash' ),
			'posts_per_page' => -1,
			'meta_key'       => SOURCE_ID,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);
	?>
	<div class="wrap">
		<h1>Softwarekontrol</h1>
		<?php if ( isset( $messages[ $notice ] ) ) : ?>
			<div class="notice notice-info"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div>
		<?php endif; ?>
		<h2>Katalog</h2>
		<p><a href="<?php echo esc_url( SOURCE ); ?>">Åbn katalogfilen på GitHub</a></p>
		<p>Seneste henteforsøg: <?php echo esc_html( control_date( $attempt ) ); ?></p>
		<?php if ( $attempt && ( $result['attempt'] ?? null ) === $attempt ) : ?>
			<p><?php echo esc_html( $messages[ $result['status'] ] ?? '' ); ?> <?php echo esc_html( $result['error'] ?? '' ); ?></p>
		<?php endif; ?>
		<p>Seneste gennemførte import: <?php echo esc_html( control_date( $success['time'] ?? 0 ) ); ?>
			<?php if ( isset( $success['count'] ) ) : ?>
				(<?php echo esc_html( (string) $success['count'] ); ?> værktøjer)
			<?php endif; ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( CONTROL_PAGE ); ?>
			<input type="hidden" name="action" value="mkrspce_software_control">
			<button class="button button-primary" name="operation" value="sync">Genindlæs katalog</button>
		</form>
		<p>Den manuelle hentning tilsidesætter 24-timersgrænsen og starter et nyt interval for automatisk hentning.</p>

		<h2>Plugin</h2>
		<p>Installeret version: <?php echo esc_html( $installed ); ?></p>
		<?php if ( $release ) : ?>
			<p>Seneste version på GitHub: <a href="<?php echo esc_url( $release['url'] ); ?>"><?php echo esc_html( $release['version'] ); ?></a></p>
		<?php endif; ?>
		<?php if ( current_user_can( 'update_plugins' ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( CONTROL_PAGE ); ?>
				<input type="hidden" name="action" value="mkrspce_software_control">
				<button class="button" name="operation" value="check-update">Tjek pluginopdatering</button>
				<a href="<?php echo esc_url( network_admin_url( 'update-core.php' ) ); ?>">Åbn WordPress-opdateringer</a>
			</form>
		<?php endif; ?>

		<h2>Importerede poster</h2>
		<?php if ( ! $posts ) : ?>
			<p>Der er endnu ingen importerede poster.</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th scope="col">Software</th><th scope="col">Status</th><th scope="col">Fremhævet billede</th></tr></thead>
				<tbody>
				<?php foreach ( $posts as $post ) : ?>
					<tr>
						<td><?php echo esc_html( $post->post_title ); ?></td>
						<td><?php echo esc_html( get_post_status_object( $post->post_status )->label ); ?></td>
						<td><?php echo has_post_thumbnail( $post ) ? 'Ja' : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

add_action( 'admin_menu', __NAMESPACE__ . '\\register_control_page' );
add_action( 'admin_post_mkrspce_software_control', __NAMESPACE__ . '\\handle_control_action' );
add_filter( 'update_plugins_github.com', __NAMESPACE__ . '\\plugin_update', 10, 3 );
