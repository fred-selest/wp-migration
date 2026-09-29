<?php
/**
 * Uninstall: remove the packages and the plugin data.
 *
 * @package WPMigration
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wpmig_installed' );
delete_option( 'wpmig_settings' );
delete_option( 'wpmig_last_cleanup' );
delete_option( 'wpmig_report' );
delete_option( 'wpmig_sync_link' );
delete_option( 'wpmig_sync_state' );
delete_option( 'wpmig_sync_history' );
delete_option( 'wpmig_search_state' );
delete_option( 'wpmig_search_history' );
$GLOBALS['wpdb']->query( 'DROP TABLE IF EXISTS `' . $GLOBALS['wpdb']->prefix . 'wpmig_sync_map`' ); // phpcs:ignore WordPress.DB
delete_site_transient( 'wpmig_update_release' );
wp_clear_scheduled_hook( 'wpmig_daily_cleanup' );

$wpmig_dir = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/wpmig-backups';

/**
 * Recursive delete.
 *
 * @param string $dir Directory.
 */
function wpmig_uninstall_rrmdir( $dir ) {
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		@unlink( $dir ); // phpcs:ignore
		return;
	}
	foreach ( (array) scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item || false === $item ) {
			continue;
		}
		wpmig_uninstall_rrmdir( $dir . '/' . $item );
	}
	@rmdir( $dir ); // phpcs:ignore
}

wpmig_uninstall_rrmdir( $wpmig_dir );
