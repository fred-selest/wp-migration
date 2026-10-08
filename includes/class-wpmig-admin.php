<?php
/**
 * Administration screens, AJAX endpoints and downloads.
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin.
 */
class WPMIG_Admin {

	const CAP  = 'manage_options';
	const SLUG = 'wp-migration';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_wpmig_create', array( __CLASS__, 'ajax_create' ) );
		add_action( 'wp_ajax_wpmig_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'wp_ajax_wpmig_build', array( __CLASS__, 'ajax_build' ) );
		add_action( 'wp_ajax_wpmig_delete', array( __CLASS__, 'ajax_delete' ) );
		add_action( 'wp_ajax_wpmig_transfer_link', array( __CLASS__, 'ajax_transfer_link' ) );
		add_action( 'wp_ajax_wpmig_transfer_revoke', array( __CLASS__, 'ajax_transfer_revoke' ) );
		add_action( 'wp_ajax_wpmig_import_prepare', array( __CLASS__, 'ajax_import_prepare' ) );
		foreach ( array( 'sync_probe', 'sync_start', 'sync_step', 'sync_confirm', 'sync_undo', 'sync_dismiss', 'sync_link', 'sync_revoke', 'search_start', 'search_step', 'search_confirm', 'search_undo', 'search_dismiss', 'settings_list', 'settings_preview', 'settings_apply', 'settings_undo', 'compare_run', 'restore_prepare', 'restore_cancel' ) as $action ) {
			add_action( 'wp_ajax_wpmig_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
		add_action( 'admin_post_wpmig_download', array( __CLASS__, 'download' ) );
		add_action( 'admin_post_wpmig_cleanup_install', array( __CLASS__, 'cleanup_install' ) );
		add_action( 'admin_post_wpmig_cleanup_settings', array( __CLASS__, 'cleanup_settings' ) );
		add_action( 'admin_post_wpmig_report_download', array( __CLASS__, 'report_download' ) );
		add_action( 'admin_post_wpmig_report_delete', array( __CLASS__, 'report_delete' ) );
		add_action( 'admin_post_wpmig_report_recheck', array( __CLASS__, 'report_recheck' ) );
		add_action( 'admin_init', array( __CLASS__, 'post_install' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPMIG_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		// Monochrome icon, recolored by WordPress like the dashicons.
		$icon = (string) @file_get_contents( WPMIG_DIR . 'assets/logo/menu-icon.svg' ); // phpcs:ignore
		add_menu_page( 'WP Migration', 'WP Migration', self::CAP, self::SLUG, array( __CLASS__, 'page' ), '' !== $icon ? 'data:image/svg+xml;base64,' . base64_encode( $icon ) : 'dashicons-migrate', 80 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Plugin list link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::tab_url( 'backups' ) ) . '">Sauvegardes</a>' );
		return $links;
	}

	/**
	 * Scripts and styles.
	 *
	 * @param string $hook Page hook.
	 */
	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wpmig-admin', WPMIG_URL . 'assets/admin.css', array(), WPMIG_VERSION );
		wp_enqueue_script( 'wpmig-admin', WPMIG_URL . 'assets/admin.js', array(), WPMIG_VERSION, true );
		wp_localize_script(
			'wpmig-admin',
			'WPMIG',
			array(
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wpmig' ),
				'download' => admin_url( 'admin-post.php?action=wpmig_download&_wpnonce=' . wp_create_nonce( 'wpmig_download' ) ),
			)
		);
	}

	/**
	 * Check permissions for AJAX calls.
	 */
	private static function check_ajax() {
		if ( ! current_user_can( self::CAP ) || ! check_ajax_referer( 'wpmig', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Accès refusé ou session expirée : rechargez la page.' ), 403 );
		}
		WPMIG_Plugin::raise_limits();
	}

	/**
	 * Load the package of the request.
	 *
	 * @return WPMIG_Package
	 */
	private static function request_package() {
		$id      = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$package = WPMIG_Package::load( $id );
		if ( ! $package ) {
			wp_send_json_error( array( 'message' => 'Sauvegarde introuvable.' ) );
		}
		return $package;
	}

	/**
	 * Create a package and start the scan.
	 */
	public static function ajax_create() {
		self::check_ajax();
		$raw = isset( $_POST['options'] ) ? json_decode( wp_unslash( $_POST['options'] ), true ) : array(); // phpcs:ignore
		try {
			$package = WPMIG_Package::create( is_array( $raw ) ? $raw : array() );
			wp_send_json_success( $package->step( WPMIG_Plugin::time_budget() ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Continue the current stage.
	 */
	public static function ajax_step() {
		self::check_ajax();
		$package = self::request_package();
		wp_send_json_success( $package->step( WPMIG_Plugin::time_budget() ) );
	}

	/**
	 * Start the build after the scan.
	 */
	public static function ajax_build() {
		self::check_ajax();
		$package = self::request_package();
		try {
			$package->start_build();
			wp_send_json_success( $package->step( WPMIG_Plugin::time_budget() ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Delete a package.
	 */
	public static function ajax_delete() {
		self::check_ajax();
		$package = self::request_package();
		$package->delete();
		wp_send_json_success();
	}

	/**
	 * Create (or replace) the direct transfer link of a package.
	 */
	public static function ajax_transfer_link() {
		self::check_ajax();
		$package = self::request_package();
		try {
			wp_send_json_success( WPMIG_Transfer::create( $package ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Revoke the direct transfer link of a package.
	 */
	public static function ajax_transfer_revoke() {
		self::check_ajax();
		WPMIG_Transfer::revoke( self::request_package() );
		wp_send_json_success();
	}

	/**
	 * Import: place the installer of the source package on this site.
	 */
	public static function ajax_import_prepare() {
		self::check_ajax();
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( array( 'message' => 'Droits insuffisants : l\'import nécessite de pouvoir installer des extensions.' ) );
		}
		$link = isset( $_POST['link'] ) ? esc_url_raw( wp_unslash( $_POST['link'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		try {
			wp_send_json_success( array( 'url' => WPMIG_Transfer::prepare_import( $link ) ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Current synchronization or error.
	 *
	 * @return WPMIG_Sync
	 */
	private static function request_sync() {
		$sync = WPMIG_Sync::current();
		if ( ! $sync ) {
			wp_send_json_error( array( 'message' => 'Aucune synchronisation en cours.' ) );
		}
		return $sync;
	}

	/**
	 * Information about the source of a link (default date, packages).
	 */
	public static function ajax_sync_probe() {
		self::check_ajax();
		$link = isset( $_POST['link'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['link'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		try {
			wp_send_json_success( WPMIG_Sync::probe( $link ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Start a synchronization (analysis).
	 */
	public static function ajax_sync_start() {
		self::check_ajax();
		// phpcs:disable WordPress.Security.NonceVerification -- checked in check_ajax().
		$link  = isset( $_POST['link'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['link'] ) ) ) : '';
		$kinds = isset( $_POST['kinds'] ) ? array_map( 'sanitize_key', explode( ',', sanitize_text_field( wp_unslash( $_POST['kinds'] ) ) ) ) : array();
		$since = isset( $_POST['since'] ) ? sanitize_text_field( wp_unslash( $_POST['since'] ) ) : '';
		$force = ! empty( $_POST['force'] );
		// phpcs:enable
		try {
			$sync = WPMIG_Sync::start( $link, $kinds, $since, $force );
			wp_send_json_success( $sync->step( microtime( true ) + WPMIG_Plugin::time_budget() ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Continue the synchronization.
	 */
	public static function ajax_sync_step() {
		self::check_ajax();
		wp_send_json_success( self::request_sync()->step( microtime( true ) + WPMIG_Plugin::time_budget() ) );
	}

	/**
	 * Import after the analysis.
	 */
	public static function ajax_sync_confirm() {
		self::check_ajax();
		$sync = self::request_sync();
		try {
			$sync->confirm();
			wp_send_json_success( $sync->step( microtime( true ) + WPMIG_Plugin::time_budget() ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Undo the last synchronization.
	 */
	public static function ajax_sync_undo() {
		self::check_ajax();
		$sync = self::request_sync();
		try {
			$sync->undo();
			wp_send_json_success( $sync->step( microtime( true ) + WPMIG_Plugin::time_budget() ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Close the synchronization panel.
	 */
	public static function ajax_sync_dismiss() {
		self::check_ajax();
		try {
			WPMIG_Sync::dismiss();
			wp_send_json_success();
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Current search & replace operation of the request.
	 *
	 * @return WPMIG_Search
	 */
	private static function request_search() {
		$search = WPMIG_Search::current();
		if ( ! $search ) {
			wp_send_json_error( array( 'message' => 'Aucune opération en cours.' ) );
		}
		return $search;
	}

	/**
	 * Start a search & replace (analysis).
	 */
	public static function ajax_search_start() {
		self::check_ajax();
		// phpcs:disable WordPress.Security.NonceVerification -- checked in check_ajax().
		$params = array(
			'search'      => isset( $_POST['search'] ) ? (string) wp_unslash( $_POST['search'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'replace'     => isset( $_POST['replace'] ) ? (string) wp_unslash( $_POST['replace'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'mode'        => isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'text',
			'ignore_case' => ! empty( $_POST['ignore_case'] ),
			'variants'    => ! empty( $_POST['variants'] ),
			'www'         => ! empty( $_POST['www'] ),
			'guid'        => ! empty( $_POST['guid'] ),
			'tables'      => isset( $_POST['tables'] ) && '' !== $_POST['tables'] ? array_map( 'sanitize_text_field', explode( ',', wp_unslash( $_POST['tables'] ) ) ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		);
		// phpcs:enable
		try {
			$search = WPMIG_Search::start( $params );
			wp_send_json_success( $search->step( WPMIG_Plugin::time_budget() ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Continue the search & replace.
	 */
	public static function ajax_search_step() {
		self::check_ajax();
		try {
			wp_send_json_success( self::request_search()->step( WPMIG_Plugin::time_budget() ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Apply the replacement after the analysis.
	 */
	public static function ajax_search_confirm() {
		self::check_ajax();
		try {
			$search = self::request_search();
			$search->confirm();
			wp_send_json_success( $search->step( WPMIG_Plugin::time_budget() ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Undo a replacement (the current one, or one of the history).
	 */
	public static function ajax_search_undo() {
		self::check_ajax();
		$id = isset( $_POST['id'] ) ? preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( wp_unslash( $_POST['id'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		try {
			$search = WPMIG_Search::undo( $id );
			wp_send_json_success( $search->step( WPMIG_Plugin::time_budget() ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Settings of the source site: list.
	 */
	public static function ajax_settings_list() {
		self::check_ajax();
		// phpcs:disable WordPress.Security.NonceVerification -- checked in check_ajax().
		$link = isset( $_POST['link'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['link'] ) ) ) : '';
		$q    = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		// phpcs:enable
		try {
			wp_send_json_success( WPMIG_Settings::remote_list( $link, $q ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Names, link and options of a settings request.
	 *
	 * @return array link, names, adapt.
	 */
	private static function request_settings() {
		// phpcs:disable WordPress.Security.NonceVerification -- checked in check_ajax().
		$link  = isset( $_POST['link'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['link'] ) ) ) : '';
		$names = isset( $_POST['names'] ) ? array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['names'] ) ) ) ), 'strlen' ) : array();
		$adapt = ! empty( $_POST['adapt'] );
		// phpcs:enable
		return array( $link, $names, $adapt );
	}

	/**
	 * Settings of the source site: comparison with this site.
	 */
	public static function ajax_settings_preview() {
		self::check_ajax();
		list( $link, $names, $adapt ) = self::request_settings();
		try {
			wp_send_json_success( WPMIG_Settings::public_plan( WPMIG_Settings::plan( $link, $names, $adapt ) ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Settings of the source site: copy.
	 */
	public static function ajax_settings_apply() {
		self::check_ajax();
		list( $link, $names, $adapt ) = self::request_settings();
		try {
			wp_send_json_success( WPMIG_Settings::apply( $link, $names, $adapt ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Undo a copy of settings.
	 */
	public static function ajax_settings_undo() {
		self::check_ajax();
		$id = isset( $_POST['id'] ) ? preg_replace( '/[^a-f0-9_]/', '', sanitize_text_field( wp_unslash( $_POST['id'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		try {
			wp_send_json_success( WPMIG_Settings::undo( $id ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Compare this site with another one (read only).
	 */
	public static function ajax_compare_run() {
		self::check_ajax();
		$link = isset( $_POST['link'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['link'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		try {
			wp_send_json_success( WPMIG_Compare::run( $link ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Prepare the restoration of a backup (installer and archive in the site root).
	 */
	public static function ajax_restore_prepare() {
		self::check_ajax();
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( array( 'message' => 'Droits insuffisants : la restauration nécessite de pouvoir installer des extensions.' ) );
		}
		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		try {
			wp_send_json_success( WPMIG_Restore::prepare( $id ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Cancel the preparation of a restoration.
	 */
	public static function ajax_restore_cancel() {
		self::check_ajax();
		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		try {
			WPMIG_Restore::cancel( $id );
			wp_send_json_success();
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Close the search & replace panel.
	 */
	public static function ajax_search_dismiss() {
		self::check_ajax();
		try {
			$search = WPMIG_Search::current();
			if ( $search ) {
				$search->dismiss();
			}
			wp_send_json_success();
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Source side: create the synchronization link.
	 */
	public static function ajax_sync_link() {
		self::check_ajax();
		$hours = isset( $_POST['hours'] ) ? absint( $_POST['hours'] ) : 24; // phpcs:ignore WordPress.Security.NonceVerification
		wp_send_json_success( WPMIG_Sync_Source::create( $hours ) );
	}

	/**
	 * Source side: revoke the synchronization link.
	 */
	public static function ajax_sync_revoke() {
		self::check_ajax();
		WPMIG_Sync_Source::revoke();
		wp_send_json_success();
	}

	/**
	 * Stream a package file.
	 */
	public static function download() {
		if ( ! current_user_can( self::CAP ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wpmig_download' ) ) {
			wp_die( 'Accès refusé.', 403 );
		}
		$package = WPMIG_Package::load( isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '' );
		$which   = isset( $_GET['file'] ) ? sanitize_key( $_GET['file'] ) : '';
		if ( ! $package || 'complete' !== $package->data['status'] || ! in_array( $which, array( 'archive', 'installer' ), true ) ) {
			wp_die( 'Fichier introuvable.', 404 );
		}
		$path = 'archive' === $which ? $package->archive_path() : $package->installer_path();
		$name = 'archive' === $which ? $package->data['files']['archive'] : 'installer.php';
		if ( ! is_file( $path ) ) {
			wp_die( 'Fichier introuvable.', 404 );
		}
		WPMIG_Plugin::raise_limits();
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . sprintf( '%u', filesize( $path ) ) );
		header( 'X-Content-Type-Options: nosniff' );
		$fh = fopen( $path, 'rb' ); // phpcs:ignore
		while ( $fh && ! feof( $fh ) ) {
			echo fread( $fh, 1048576 ); // phpcs:ignore
			flush();
		}
		if ( $fh ) {
			fclose( $fh ); // phpcs:ignore
		}
		exit;
	}

	/**
	 * First admin load after an installation made by the installer.
	 */
	public static function post_install() {
		$flag = get_option( 'wpmig_installed' );
		if ( ! $flag || ! current_user_can( self::CAP ) ) {
			return;
		}
		$data = is_array( $flag ) ? $flag : json_decode( (string) $flag, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		if ( empty( $data['flushed'] ) ) {
			// Regenerate the rewrite rules (and .htaccess) with the WordPress API.
			flush_rewrite_rules( true );
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
			$data['flushed'] = time();
			update_option( 'wpmig_installed', wp_json_encode( $data ) );
		}
	}

	/**
	 * Remove the installation files left on the server.
	 */
	public static function cleanup_install() {
		if ( ! current_user_can( self::CAP ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wpmig_cleanup_install' ) ) {
			wp_die( 'Accès refusé.', 403 );
		}
		foreach ( WPMIG_Plugin::leftover_install_files() as $path ) {
			WPMIG_Plugin::rrmdir( $path );
		}
		delete_option( 'wpmig_installed' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&wpmig_cleaned=1' ) );
		exit;
	}

	/**
	 * Save the cleanup settings, and optionally clean now.
	 */
	public static function cleanup_settings() {
		if ( ! current_user_can( self::CAP ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wpmig_cleanup_settings' ) ) {
			wp_die( 'Accès refusé.', 403 );
		}
		WPMIG_Cleanup::save_settings(
			array(
				'keep' => isset( $_POST['keep'] ) ? absint( $_POST['keep'] ) : 5,
				'days' => isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 30,
			)
		);
		$args = array( 'page' => self::SLUG, 'tab' => 'settings' );
		if ( isset( $_POST['clean_now'] ) ) {
			$result          = WPMIG_Cleanup::run();
			$args['cleaned'] = $result['count'];
			$args['freed']   = rawurlencode( wpmig_size( $result['bytes'] ) );
		} else {
			$args['saved'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) . '#wpmig-cleanup' );
		exit;
	}

	/**
	 * Download the migration report as a text file.
	 */
	public static function report_download() {
		if ( ! current_user_can( self::CAP ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wpmig_report_download' ) ) {
			wp_die( 'Accès refusé.', 403 );
		}
		$report = WPMIG_Report::get();
		if ( ! $report ) {
			wp_die( 'Aucun rapport de migration.', 404 );
		}
		$host = (string) wp_parse_url( isset( $report['destination']['home'] ) ? $report['destination']['home'] : home_url(), PHP_URL_HOST );
		$name = 'rapport-migration-' . sanitize_file_name( $host ) . '-' . gmdate( 'Ymd-His', (int) $report['finished'] ) . '.txt';
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo "\xEF\xBB\xBF" . WPMIG_Report::to_text( $report ); // phpcs:ignore WordPress.Security.EscapeOutput -- plain text download.
		exit;
	}

	/**
	 * Delete the migration report.
	 */
	public static function report_delete() {
		if ( ! current_user_can( self::CAP ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wpmig_report_delete' ) ) {
			wp_die( 'Accès refusé.', 403 );
		}
		WPMIG_Report::delete();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&report_deleted=1' ) );
		exit;
	}

	/**
	 * Run the consistency checks again.
	 */
	public static function report_recheck() {
		if ( ! current_user_can( self::CAP ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wpmig_report_recheck' ) ) {
			wp_die( 'Accès refusé.', 403 );
		}
		WPMIG_Report::recheck();
		wp_safe_redirect( self::report_url() . '&rechecked=1#wpmig-consistency' );
		exit;
	}

	/**
	 * URL of the report page.
	 *
	 * @return string
	 */
	public static function report_url() {
		return admin_url( 'admin.php?page=' . self::SLUG . '&view=report' );
	}

	/**
	 * Admin notices.
	 */
	public static function notices() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		if ( isset( $_GET['wpmig_cleaned'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$left = WPMIG_Plugin::leftover_install_files();
			if ( $left ) {
				echo '<div class="notice notice-error"><p><strong>WP Migration :</strong> impossible de supprimer : ' . esc_html( implode( ', ', array_map( 'basename', $left ) ) ) . '. Supprimez-les par FTP.</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p><strong>WP Migration :</strong> fichiers d\'installation supprimés. La migration est terminée.' . ( WPMIG_Report::get() ? ' <a href="' . esc_url( self::report_url() ) . '">Voir le rapport de migration</a>' : '' ) . '</p></div>';
			}
			return;
		}
		$flag = get_option( 'wpmig_installed' );
		if ( ! $flag ) {
			return;
		}
		$data  = json_decode( (string) $flag, true );
		$left  = WPMIG_Plugin::leftover_install_files();
		$url   = wp_nonce_url( admin_url( 'admin-post.php?action=wpmig_cleanup_install' ), 'wpmig_cleanup_install' );
		$from  = is_array( $data ) && ! empty( $data['from'] ) ? $data['from'] : '';
		$report = WPMIG_Report::get();
		echo '<div class="notice notice-' . ( $left ? 'warning' : 'success' ) . '"><p><strong>WP Migration :</strong> site migré' . ( $from ? ' depuis <code>' . esc_html( $from ) . '</code>' : '' ) . '. ';
		if ( $report ) {
			echo ( ! empty( $report['checks']['ok'] ) ? 'Contrôles réussis : la copie est complète. ' : '<strong>Des points sont à vérifier.</strong> ' ) . '<a href="' . esc_url( self::report_url() ) . '">Voir le rapport de migration</a>. ';
		}
		if ( $left ) {
			echo 'Des fichiers d\'installation sont encore présents sur le serveur (' . esc_html( implode( ', ', array_map( 'basename', $left ) ) ) . ') : ils contiennent une copie complète du site et doivent être supprimés' . ( $report ? ' (le rapport de migration est conservé)' : '' ) . '.</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">Supprimer les fichiers d\'installation</a></p></div>';
		} else {
			echo 'Pensez à vérifier les réglages des permaliens et de vos extensions de cache / SEO.</p>';
			echo '<p><a class="button" href="' . esc_url( $url ) . '">Masquer ce message</a></p></div>';
		}
	}

	/**
	 * Tabs of the page (slug => label).
	 *
	 * @return array
	 */
	public static function tabs() {
		return array(
			'home'     => 'Accueil',
			'backups'  => 'Sauvegardes',
			'receive'  => 'Recevoir un site',
			'sync'     => 'Synchronisation',
			'search'   => 'Rechercher / Remplacer',
			'settings' => 'Réglages',
			'help'     => 'Aide',
		);
	}

	/**
	 * Address of a tab.
	 *
	 * @param string $tab  Tab.
	 * @param array  $args Other query arguments.
	 * @return string
	 */
	public static function tab_url( $tab, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Tab of the request.
	 *
	 * @return string
	 */
	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'home'; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		return isset( self::tabs()[ $tab ] ) ? $tab : 'home';
	}

	/**
	 * Main page.
	 */
	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- display only.
		if ( isset( $_GET['view'] ) && 'report' === $_GET['view'] ) {
			self::render_report_page();
			return;
		}
		$tab = self::current_tab();
		echo '<div class="wrap wpmig">';
		echo '<h1 class="wp-heading-inline">' . self::logo() . 'WP Migration</h1>';
		echo '<hr class="wp-header-end">';
		if ( is_multisite() ) {
			echo '<div class="notice notice-error"><p>Les installations multisite ne sont pas prises en charge.</p></div></div>';
			return;
		}

		try {
			WPMIG_Plugin::storage_dir();
		} catch ( Exception $e ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div></div>';
			return;
		}

		echo '<nav class="nav-tab-wrapper wpmig-tabs" aria-label="Sections">';
		foreach ( self::tabs() as $slug => $label ) {
			echo '<a class="nav-tab' . ( $slug === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::tab_url( $slug ) ) . '"' . ( $slug === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		echo '<div class="wpmig-tab wpmig-tab-' . esc_attr( $tab ) . '">';
		switch ( $tab ) {
			case 'backups':
				self::render_backup_start();
				self::render_wizard();
				self::render_packages();
				break;
			case 'receive':
				self::render_receive();
				break;
			case 'sync':
				self::render_sync_source();
				self::render_sync();
				self::render_settings_pull();
				self::render_compare();
				break;
			case 'search':
				self::render_search();
				break;
			case 'settings':
				self::render_cleanup();
				self::render_about();
				break;
			case 'help':
				self::render_help();
				break;
			default:
				self::render_home();
		}
		echo '</div></div>';
	}

	/**
	 * Home: what does the visitor want to do?
	 */
	private static function render_home() {
		$done   = array();
		foreach ( WPMIG_Package::all() as $package ) {
			if ( 'complete' === $package->data['status'] ) {
				$done[] = $package;
			}
		}
		$link    = WPMIG_Sync_Source::link();
		$sync    = WPMIG_Sync::current();
		$replace = WPMIG_Search::current();

		// Status.
		echo '<ul class="wpmig-status">';
		if ( $done ) {
			$last = $done[0]->data;
			echo '<li class="wpmig-status-ok"><span class="dashicons dashicons-yes-alt"></span> Dernière sauvegarde : <strong>' . esc_html( wpmig_date( $last['created'] ) ) . '</strong>' . ( ! empty( $last['sizes']['archive'] ) ? ' (' . esc_html( size_format( $last['sizes']['archive'], 1 ) ) . ')' : '' ) . ' — <a href="' . esc_url( self::tab_url( 'backups' ) ) . '">' . esc_html( count( $done ) ) . ' sauvegarde(s)</a></li>';
		} else {
			echo '<li class="wpmig-status-warn"><span class="dashicons dashicons-warning"></span> Aucune sauvegarde de ce site pour l\'instant — <a href="' . esc_url( self::tab_url( 'backups' ) ) . '">en créer une</a> avant toute modification importante.</li>';
		}
		if ( $link ) {
			echo '<li class="wpmig-status-info"><span class="dashicons dashicons-admin-links"></span> Lien de synchronisation actif jusqu\'au <strong>' . esc_html( wpmig_date( $link['expires'] ) ) . '</strong> — <a href="' . esc_url( self::tab_url( 'sync' ) ) . '">le gérer</a></li>';
		}
		if ( $sync ) {
			echo '<li class="wpmig-status-info"><span class="dashicons dashicons-update"></span> Une synchronisation du contenu est ouverte — <a href="' . esc_url( self::tab_url( 'sync' ) ) . '">la reprendre</a></li>';
		}
		if ( $replace ) {
			echo '<li class="wpmig-status-info"><span class="dashicons dashicons-search"></span> Un rechercher / remplacer est ouvert — <a href="' . esc_url( self::tab_url( 'search' ) ) . '">le reprendre</a></li>';
		}
		echo '</ul>';

		echo '<h2 class="wpmig-home-title">Que voulez-vous faire ?</h2>';
		$tiles = array(
			array(
				'tab'    => 'backups',
				'icon'   => 'cloud-upload',
				'title'  => 'Déménager ou sauvegarder ce site',
				'text'   => 'Créez une sauvegarde complète (fichiers et base de données) pour changer d\'hébergeur ou de nom de domaine, faire une copie de travail ou simplement garder une sauvegarde.',
				'button' => 'Créer une sauvegarde',
			),
			array(
				'tab'    => 'receive',
				'icon'   => 'download',
				'title'  => 'Recevoir un site ici',
				'text'   => 'Vous avez une sauvegarde ou un autre site à installer sur cet hébergement ? Remplacez ce site par une copie, sans FTP si le site d\'origine vous donne un lien.',
				'button' => 'Recevoir un site',
			),
			array(
				'tab'    => 'sync',
				'icon'   => 'update',
				'title'  => 'Récupérer les commandes et contenus',
				'text'   => 'Vous travaillez sur une copie pendant que le site en ligne continue de vendre ? Ramenez sur la copie les commandes, clients, produits et articles créés entre-temps.',
				'button' => 'Synchroniser',
			),
			array(
				'tab'    => 'search',
				'icon'   => 'search',
				'title'  => 'Changer une adresse ou un texte',
				'text'   => 'Remplacez une adresse, un domaine ou un texte partout dans la base de données, sans casser les réglages. Une analyse précède le changement, et il peut être annulé.',
				'button' => 'Rechercher / remplacer',
			),
		);
		echo '<div class="wpmig-tiles">';
		foreach ( $tiles as $tile ) {
			echo '<a class="wpmig-tile" href="' . esc_url( self::tab_url( $tile['tab'] ) ) . '">';
			echo '<span class="wpmig-tile-icon dashicons dashicons-' . esc_attr( $tile['icon'] ) . '"></span>';
			echo '<h2>' . esc_html( $tile['title'] ) . '</h2>';
			echo '<p>' . esc_html( $tile['text'] ) . '</p>';
			echo '<span class="button button-primary">' . esc_html( $tile['button'] ) . '</span>';
			echo '</a>';
		}
		echo '</div>';
		self::render_report_card();
		echo '<p class="wpmig-home-help">Première fois ? <a href="' . esc_url( self::tab_url( 'help' ) ) . '">Consultez le guide pas à pas</a>.</p>';
	}

	/**
	 * Quick start of a backup, above the customizable form.
	 */
	private static function render_backup_start() {
		?>
		<div class="wpmig-card wpmig-quick" id="wpmig-quick">
			<h2>Créer une sauvegarde</h2>
			<p>Une sauvegarde se compose d'une <strong>archive</strong> et d'un <strong>installeur</strong> (<code>installer.php</code>) à déposer sur un autre hébergement pour y recréer le site, ou à conserver pour revenir en arrière.</p>
			<div class="wpmig-quick-choices">
				<button type="button" class="wpmig-choice" data-quick="full">
					<strong>Sauvegarde complète</strong>
					<span>Fichiers et base de données. Recommandée pour déménager ou copier le site.</span>
				</button>
				<button type="button" class="wpmig-choice" data-quick="db">
					<strong>Base de données seulement</strong>
					<span>Plus rapide. Utile avant une modification des contenus.</span>
				</button>
			</div>
			<p><button type="button" class="button-link" id="wpmig-new">Personnaliser (dossiers exclus, tables, mot de passe…)</button></p>
			<div id="wpmig-quick-box"></div>
		</div>
		<?php
	}

	/**
	 * Receive a site here.
	 */
	private static function render_receive() {
		?>
		<div class="wpmig-card">
			<h2>Recevoir un site sur cet hébergement</h2>
			<p>Deux façons de remplacer <strong>ce site</strong> par une copie d'un autre site. Dans les deux cas, tous les contenus, réglages, extensions et comptes de ce site sont remplacés.</p>
			<h3>Vous avez les deux fichiers d'une sauvegarde</h3>
			<ol>
				<li>Créez une <strong>base de données MySQL</strong> vide (nom, utilisateur, mot de passe) depuis le panneau de votre hébergeur.</li>
				<li>Envoyez l'<strong>archive</strong> (<code>.wpmig</code>) et <code>installer.php</code> dans le dossier du site, par FTP/SFTP en mode <em>binaire</em>. L'ancien contenu du dossier peut y rester : il sera remplacé.</li>
				<li>Ouvrez <code>https://votre-domaine.fr/installer.php</code> dans le navigateur, saisissez le mot de passe de la sauvegarde et suivez les étapes. Les adresses et chemins sont remplacés automatiquement.</li>
				<li>Connectez-vous avec vos identifiants habituels, puis <strong>supprimez les fichiers d'installation</strong> (bouton proposé à la fin).</li>
			</ol>
		</div>
		<?php
		self::render_import();
	}

	/**
	 * About and version.
	 */
	private static function render_about() {
		?>
		<div class="wpmig-card">
			<h2>À propos</h2>
			<p>WP Migration <strong><?php echo esc_html( WPMIG_VERSION ); ?></strong>. Les mises à jour sont proposées par WordPress comme pour les autres extensions (bouton « Vérifier les mises à jour » dans la liste des extensions).</p>
			<p class="description">Dossier de stockage des sauvegardes : <code><?php echo esc_html( WPMIG_Plugin::storage_dir() ); ?></code></p>
		</div>
		<?php
	}

	/**
	 * Backup creation wizard (customizable).
	 */
	private static function render_wizard() {
		$tables = WPMIG_DB_Exporter::site_tables();
		?>
		<div id="wpmig-wizard" class="wpmig-card" hidden>
			<ol class="wpmig-steps">
				<li data-step="1">1. Configuration</li>
				<li data-step="2">2. Analyse</li>
				<li data-step="3">3. Création</li>
			</ol>

			<form id="wpmig-form" data-step="1" class="wpmig-panel">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpmig-name">Nom de la sauvegarde</label></th>
						<td><input type="text" id="wpmig-name" name="name" class="regular-text" value="<?php echo esc_attr( WPMIG_Package::default_name() ); ?>" maxlength="40">
						<p class="description">Lettres, chiffres et tirets. Il sert à nommer les fichiers.</p></td>
					</tr>
					<tr>
						<th scope="row">Contenu</th>
						<td>
							<label><input type="radio" name="db_only" value="0" checked> Site complet (fichiers + base de données)</label><br>
							<label><input type="radio" name="db_only" value="1"> Base de données uniquement</label>
						</td>
					</tr>
					<tr class="wpmig-files-only">
						<th scope="row">Fichiers</th>
						<td>
							<label><input type="checkbox" name="exclude_host_files" value="1" checked> Exclure les composants propres à l'hébergeur (extensions « must-use » des hébergeurs infogérés, drop-ins de cache <code>object-cache.php</code> / <code>advanced-cache.php</code>)</label><br>
							<label><input type="checkbox" name="exclude_vcs" value="1" checked> Exclure <code>.git</code>, <code>.svn</code> et <code>node_modules</code></label><br>
							<label><input type="checkbox" name="exclude_uploads" value="1"> Exclure la médiathèque (<code>uploads</code>) — à transférer séparément</label>
							<p class="description">Les caches, journaux et sauvegardes d'autres extensions sont toujours exclus.</p>
						</td>
					</tr>
					<tr class="wpmig-files-only">
						<th scope="row"><label for="wpmig-exclude-dirs">Dossiers exclus</label></th>
						<td><textarea id="wpmig-exclude-dirs" name="exclude_dirs" rows="3" class="large-text code" placeholder="wp-content/uploads/grosses-videos"></textarea>
						<p class="description">Un chemin par ligne, relatif à la racine de WordPress (<code><?php echo esc_html( WPMIG_Plugin::normalize( ABSPATH ) ); ?></code>).</p></td>
					</tr>
					<tr class="wpmig-files-only">
						<th scope="row"><label for="wpmig-exclude-ext">Extensions exclues</label></th>
						<td><input type="text" id="wpmig-exclude-ext" name="exclude_ext" class="regular-text" placeholder="log, zip, mp4">
						<p class="description">Séparées par des virgules.</p></td>
					</tr>
					<tr>
						<th scope="row">Base de données</th>
						<td>
							<label><input type="checkbox" name="skip_transients" value="1" checked> Ignorer les données temporaires (transients)</label><br>
							<label><input type="checkbox" name="skip_spam" value="1" checked> Ignorer les commentaires indésirables et la corbeille</label><br>
							<label><input type="checkbox" name="skip_revisions" value="1"> Ignorer les révisions des articles</label>
							<details class="wpmig-tables"><summary>Exclure les données de certaines tables (<?php echo count( $tables ); ?> tables)</summary>
								<p class="description">Une table cochée est recréée <strong>vide</strong> sur la destination (sa structure est conservée, les extensions continuent de fonctionner). Réservé aux journaux et caches, signalés ci-dessous : n'excluez jamais les données des contenus, réglages, comptes ou commandes (<code>posts</code>, <code>postmeta</code>, <code>options</code>, <code>users</code>, tables WooCommerce…).</p>
								<div class="wpmig-table-list">
								<?php foreach ( $tables as $t ) : ?>
									<label><input type="checkbox" name="exclude_tables[]" value="<?php echo esc_attr( $t['name'] ); ?>"> <code><?php echo esc_html( $t['name'] ); ?></code> <span class="description"><?php echo esc_html( size_format( $t['size'], 1 ) . ' — ' . number_format_i18n( $t['rows'] ) . ' lignes' ); ?></span><?php if ( WPMIG_DB_Exporter::is_log_table( $t['name'] ) ) : ?> <span class="wpmig-log-table">journal / cache</span><?php endif; ?></label>
								<?php endforeach; ?>
								</div>
							</details>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmig-password">Mot de passe de l'installeur</label></th>
						<td><input type="text" id="wpmig-password" name="password" class="regular-text code" autocomplete="off" spellcheck="false">
						<button type="button" class="button" data-action="genpass">Générer</button>
						<p class="description">Généré automatiquement : <strong>notez-le</strong>, il sera demandé à l'ouverture de <code>installer.php</code>. Sans mot de passe, n'importe qui trouvant l'installeur en ligne pourrait lancer l'installation avec sa propre base de données et prendre le contrôle du site.</p></td>
					</tr>
					<tr>
						<th scope="row">Archive</th>
						<td><label><input type="checkbox" name="compress" value="1" checked> Compresser l'archive</label></td>
					</tr>
				</table>
				<p class="wpmig-actions">
					<button type="button" class="button" data-action="cancel">Annuler</button>
					<button type="submit" class="button button-primary">Analyser le site</button>
				</p>
			</form>

			<div data-step="2" class="wpmig-panel" hidden>
				<div class="wpmig-progress"><div class="wpmig-bar"><span></span></div><p class="wpmig-msg"></p></div>
				<div class="wpmig-report"></div>
				<p class="wpmig-actions" hidden>
					<button type="button" class="button" data-action="discard">Retour</button>
					<button type="button" class="button button-primary" data-action="build">Créer la sauvegarde</button>
				</p>
			</div>

			<div data-step="3" class="wpmig-panel" hidden>
				<div class="wpmig-progress"><div class="wpmig-bar"><span></span></div><p class="wpmig-msg"></p></div>
				<div class="wpmig-result"></div>
			</div>
			<div class="wpmig-error" hidden></div>
		</div>
		<?php
	}

	/**
	 * Backups list.
	 */
	private static function render_packages() {
		$packages = WPMIG_Package::all();
		echo '<div class="wpmig-card"><h2>Vos sauvegardes</h2>';
		if ( ! $packages ) {
			echo '<p class="wpmig-empty">Aucune sauvegarde. Créez-en une ci-dessus pour commencer.</p></div>';
			return;
		}
		echo '<table class="widefat striped wpmig-packages"><thead><tr><th>Nom</th><th>Créé le</th><th>Taille</th><th>Statut</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $packages as $package ) {
			$d      = $package->data;
			$labels = array(
				'scanning' => 'Analyse interrompue',
				'scanned'  => 'Analysée (non créée)',
				'building' => 'Création interrompue',
				'complete' => 'Prête',
				'error'    => 'Erreur',
			);
			echo '<tr data-id="' . esc_attr( $d['id'] ) . '">';
			echo '<td><strong>' . esc_html( $d['name'] ) . '</strong><br><span class="description">' . esc_html( $d['id'] ) . '</span></td>';
			echo '<td>' . esc_html( wpmig_date( $d['created'] ) ) . '</td>';
			echo '<td>' . ( ! empty( $d['sizes']['archive'] ) ? esc_html( size_format( $d['sizes']['archive'], 1 ) ) : '—' ) . '</td>';
			echo '<td>' . esc_html( isset( $labels[ $d['status'] ] ) ? $labels[ $d['status'] ] : $d['status'] );
			$prepared = 'complete' === $d['status'] ? WPMIG_Restore::prepared( $package ) : '';
			if ( $prepared ) {
				echo '<br><span class="wpmig-transfer-active">Restauration préparée : <a href="' . esc_url( $prepared ) . '">ouvrir l\'installeur</a> · <button type="button" class="button-link wpmig-danger" data-restore-cancel="1">annuler</button></span>';
			}
			$until = 'complete' === $d['status'] ? WPMIG_Transfer::active_until( $package ) : 0;
			if ( $until ) {
				echo '<br><span class="wpmig-transfer-active">Lien de transfert actif jusqu\'au ' . esc_html( wpmig_date( $until ) ) . '</span>';
			}
			if ( 'error' === $d['status'] && $d['error'] ) {
				echo '<br><span class="wpmig-status-error">' . esc_html( $d['error'] ) . '</span>';
			}
			echo '</td><td class="wpmig-row-actions">';
			if ( 'complete' === $d['status'] ) {
				echo '<a class="button button-primary" data-download="archive" href="#">Archive</a> ';
				echo '<a class="button button-primary" data-download="installer" href="#">Installeur</a> ';
				echo '<button type="button" class="button" data-download="both">Les deux</button> ';
				echo '<button type="button" class="button" data-transfer="1">Transfert direct</button> ';
				if ( WPMIG_Restore::available( $package ) && current_user_can( 'install_plugins' ) && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ) {
					echo '<button type="button" class="button" data-restore="1">Restaurer</button> ';
				}
			} elseif ( in_array( $d['status'], array( 'scanning', 'scanned', 'building' ), true ) ) {
				echo '<button type="button" class="button" data-resume="1">Reprendre</button> ';
			}
			echo '<button type="button" class="button button-link-delete" data-delete="1">Supprimer</button>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Logo in the page titles.
	 *
	 * @return string HTML.
	 */
	private static function logo() {
		return '<img class="wpmig-logo" src="' . esc_url( WPMIG_URL . 'assets/logo/logo.svg' ) . '" width="36" height="36" alt="">';
	}

	/**
	 * Status badge.
	 *
	 * @param bool   $ok    Success.
	 * @param string $label Text.
	 * @return string HTML.
	 */
	private static function badge( $ok, $label = '' ) {
		return '<span class="wpmig-badge ' . ( $ok ? 'wpmig-ok' : 'wpmig-warning' ) . '">' . esc_html( '' !== $label ? $label : ( $ok ? 'OK' : 'À vérifier' ) ) . '</span>';
	}

	/**
	 * Summary of the migration that created this site.
	 */
	private static function render_report_card() {
		// phpcs:ignore WordPress.Security.NonceVerification -- display only.
		if ( isset( $_GET['report_deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Rapport de migration supprimé.</p></div>';
		}
		$report = WPMIG_Report::get();
		if ( ! $report ) {
			return;
		}
		$c = $report['checks'];
		?>
		<div class="wpmig-card wpmig-report-card">
			<h2>Rapport de migration <?php echo self::badge( ! empty( $c['ok'] ), ! empty( $c['ok'] ) ? 'Copie complète' : count( $c['issues'] ) . ' point(s) à vérifier' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php
				$warn = WPMIG_Consistency::counts( $report['consistency'] );
				if ( $warn['warning'] ) {
					echo ' <a href="' . esc_url( self::report_url() . '#wpmig-consistency' ) . '">' . self::badge( false, $warn['warning'] . ' point(s) de cohérence' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>
			</h2>
			<p>
				<?php
				echo esc_html(
					sprintf(
						'Site migré depuis %s le %s : %s fichiers, %s tables, %s lignes, %s erreur(s) SQL.',
						isset( $report['source']['home'] ) ? $report['source']['home'] : '?',
						wpmig_date( $report['finished'] ),
						number_format_i18n( isset( $c['files'] ) ? $c['files'] : 0 ),
						number_format_i18n( isset( $c['tables'] ) ? $c['tables'] : 0 ),
						number_format_i18n( isset( $c['rows_imported'] ) ? $c['rows_imported'] : 0 ),
						number_format_i18n( isset( $c['sql_errors'] ) ? $c['sql_errors'] : 0 )
					)
				);
				?>
			</p>
			<p><a class="button button-primary" href="<?php echo esc_url( self::report_url() ); ?>">Voir le rapport complet</a>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpmig_report_download' ), 'wpmig_report_download' ) ); ?>">Télécharger (.txt)</a></p>
		</div>
		<?php
	}

	/**
	 * Consistency checks of the new site.
	 *
	 * @param array $report Report.
	 */
	private static function render_consistency( array $report ) {
		$items  = $report['consistency'];
		$counts = WPMIG_Consistency::counts( $items );
		$badges = array(
			'ok'      => array( 'wpmig-ok', 'OK' ),
			'warning' => array( 'wpmig-warning', 'À vérifier' ),
			'info'    => array( 'wpmig-info', 'Info' ),
		);
		?>
		<div class="wpmig-card" id="wpmig-consistency">
			<h2>Cohérence du site
				<?php echo $counts['warning'] ? self::badge( false, $counts['warning'] . ' point(s) à vérifier' ) : ( $items ? self::badge( true, 'Rien à signaler' ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</h2>
			<?php if ( isset( $_GET['rechecked'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success inline"><p>Contrôles relancés.</p></div>
			<?php endif; ?>
			<p class="description">Contrôles réalisés après l'installation : emplacements de menu, permaliens et, avec WPML ou Polylang, liens de traduction, langue par défaut et domaines de langue. Ils ne modifient rien.</p>
			<?php if ( $items ) : ?>
				<table class="widefat striped wpmig-report-table"><tbody>
				<?php foreach ( $items as $item ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $item['label'] ); ?></th>
						<td><?php echo esc_html( $item['message'] ); ?>
						<?php if ( $item['details'] ) : ?>
							<ul class="wpmig-warnings"><?php foreach ( $item['details'] as $detail ) : ?><li><?php echo esc_html( $detail ); ?></li><?php endforeach; ?></ul>
						<?php endif; ?>
						</td>
						<td class="wpmig-report-badge"><span class="wpmig-badge <?php echo esc_attr( $badges[ $item['status'] ][0] ); ?>"><?php echo esc_html( $badges[ $item['status'] ][1] ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php else : ?>
				<p>Aucun contrôle enregistré : cette migration a été faite avec une version précédente de WP Migration.</p>
			<?php endif; ?>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpmig_report_recheck' ), 'wpmig_report_recheck' ) ); ?>">Relancer les contrôles</a>
				<span class="description">
					<?php echo esc_html( ! empty( $report['consistency_checked'] ) ? 'Dernière vérification : ' . wpmig_date( $report['consistency_checked'] ) . '.' : 'Faits à la fin de l\'installation.' ); ?>
					Après avoir corrigé un point (menus, permaliens, langues), relancez pour le vérifier.
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Full migration report.
	 */
	private static function render_report_page() {
		$report = WPMIG_Report::get();
		echo '<div class="wrap wpmig wpmig-report">';
		echo '<h1 class="wp-heading-inline">' . self::logo() . 'Rapport de migration</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( self::tab_url( 'home' ) ) . '">← WP Migration</a>';
		echo '<hr class="wp-header-end">';
		if ( ! $report ) {
			echo '<div class="wpmig-card"><p>Aucun rapport : ce site n\'a pas été installé avec l\'installeur WP Migration 1.3.0 ou plus récent, ou le rapport a été supprimé.</p></div></div>';
			return;
		}
		$c      = $report['checks'];
		$tables = WPMIG_Report::tables( $report );
		$counts = array_count_values( wp_list_pluck( $tables, 'status' ) );
		$left   = WPMIG_Plugin::leftover_install_files();
		?>
		<div class="wpmig-card wpmig-report-status <?php echo ! empty( $c['ok'] ) ? 'is-ok' : 'is-warning'; ?>">
			<h2><?php echo ! empty( $c['ok'] ) ? '<span class="dashicons dashicons-yes-alt"></span> Migration vérifiée : la copie est complète' : '<span class="dashicons dashicons-warning"></span> Migration terminée : ' . esc_html( count( $c['issues'] ) ) . ' point(s) à vérifier'; ?></h2>
			<p class="wpmig-report-route"><code><?php echo esc_html( isset( $report['source']['home'] ) ? $report['source']['home'] : '' ); ?></code> → <code><?php echo esc_html( isset( $report['destination']['home'] ) ? $report['destination']['home'] : '' ); ?></code></p>
			<?php if ( ! empty( $c['issues'] ) ) : ?>
				<ul class="wpmig-warnings">
					<?php foreach ( $c['issues'] as $issue ) : ?>
						<li><?php echo esc_html( $issue ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p>Toutes les sommes de contrôle de l'archive sont correctes, tous les fichiers ont été écrits, chaque table contient exactement le nombre de lignes exportées par le site d'origine et aucune requête SQL n'a échoué.</p>
			<?php endif; ?>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpmig_report_download' ), 'wpmig_report_download' ) ); ?>">Télécharger le rapport (.txt)</a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpmig_report_delete' ), 'wpmig_report_delete' ) ); ?>" onclick="return confirm('Supprimer définitivement le rapport de migration ?');">Supprimer le rapport</a>
			</p>
			<?php if ( $left ) : ?>
				<div class="notice notice-warning inline"><p>Fichiers d'installation encore présents : <?php echo esc_html( implode( ', ', array_map( 'basename', $left ) ) ); ?>. <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpmig_cleanup_install' ), 'wpmig_cleanup_install' ) ); ?>">Les supprimer</a> (le rapport est conservé).</p></div>
			<?php endif; ?>
		</div>

		<div class="wpmig-card">
			<h2>Contrôles</h2>
			<table class="widefat striped wpmig-report-table"><tbody>
				<?php foreach ( WPMIG_Report::checks( $report ) as $row ) : ?>
					<tr><th scope="row"><?php echo esc_html( $row[0] ); ?></th><td><?php echo esc_html( $row[1] ); ?></td><td class="wpmig-report-badge"><?php echo self::badge( $row[2] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
		</div>

		<?php self::render_consistency( $report ); ?>

		<div class="wpmig-card">
			<h2>Informations</h2>
			<table class="widefat striped wpmig-report-table"><tbody>
				<?php foreach ( WPMIG_Report::summary( $report ) as $row ) : ?>
					<tr><th scope="row"><?php echo esc_html( $row[0] ); ?></th><td><?php echo esc_html( $row[1] ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
		</div>

		<div class="wpmig-card">
			<h2>Source et destination</h2>
			<table class="widefat striped wpmig-report-table">
				<thead><tr><th></th><th>Site d'origine</th><th>Ce site</th></tr></thead>
				<tbody>
				<?php foreach ( WPMIG_Report::comparison( $report ) as $row ) : ?>
					<tr><th scope="row"><?php echo esc_html( $row[0] ); ?></th><td><code><?php echo esc_html( '' !== $row[1] ? $row[1] : '—' ); ?></code></td><td><code><?php echo esc_html( '' !== $row[2] ? $row[2] : '—' ); ?></code></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="wpmig-card">
			<h2>Tables (<?php echo esc_html( count( $tables ) ); ?>)</h2>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						'Nombre de lignes exportées par le site d\'origine et comptées dans chaque table juste après l\'import (avant l\'activation des nouvelles tables). %d identique(s), %d structure seule, %d en écart ou absente(s).',
						isset( $counts['ok'] ) ? $counts['ok'] : 0,
						isset( $counts['empty'] ) ? $counts['empty'] : 0,
						( isset( $counts['diff'] ) ? $counts['diff'] : 0 ) + ( isset( $counts['missing'] ) ? $counts['missing'] : 0 )
					)
				);
				?>
			</p>
			<details <?php echo ( isset( $counts['diff'] ) || isset( $counts['missing'] ) ) ? 'open' : ''; ?>>
				<summary>Détail par table</summary>
				<table class="widefat striped wpmig-report-tables">
					<thead><tr><th>Table</th><th class="num">Lignes exportées</th><th class="num">Lignes importées</th><th>Statut</th></tr></thead>
					<tbody>
					<?php foreach ( $tables as $t ) : ?>
						<tr class="wpmig-t-<?php echo esc_attr( $t['status'] ); ?>">
							<td><code><?php echo esc_html( $t['name'] ); ?></code></td>
							<td class="num"><?php echo esc_html( null === $t['exported'] ? '?' : number_format_i18n( $t['exported'] ) ); ?></td>
							<td class="num"><?php echo esc_html( null === $t['imported'] ? '—' : number_format_i18n( $t['imported'] ) ); ?></td>
							<td><span class="dashicons <?php echo in_array( $t['status'], array( 'ok', 'empty' ), true ) ? 'dashicons-yes' : 'dashicons-warning'; ?>"></span> <?php echo esc_html( $t['label'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		</div>

		<?php if ( $report['replacements'] ) : ?>
		<div class="wpmig-card">
			<h2>Remplacements dans la base de données</h2>
			<p class="description">Appliqués à toutes les tables, y compris dans les données sérialisées et JSON (avec leurs variantes échappées et encodées).</p>
			<ul class="wpmig-report-list">
				<?php foreach ( $report['replacements'] as $pair ) : ?>
					<li><code><?php echo esc_html( $pair[0] ); ?></code> → <code><?php echo esc_html( $pair[1] ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<?php if ( $report['excluded'] || $report['warnings'] || $report['notices'] ) : ?>
		<div class="wpmig-card">
			<h2>Exclusions et remarques</h2>
			<?php if ( $report['excluded'] ) : ?>
				<details><summary>Exclus volontairement de la sauvegarde par le site d'origine (<?php echo esc_html( count( $report['excluded'] ) ); ?>)</summary>
					<ul class="wpmig-report-list">
						<?php foreach ( $report['excluded'] as $item ) : ?>
							<li><code><?php echo esc_html( $item ); ?></code></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
			<?php if ( $report['warnings'] ) : ?>
				<details open><summary>Avertissements (<?php echo esc_html( count( $report['warnings'] ) ); ?>)</summary>
					<ul class="wpmig-warnings">
						<?php foreach ( $report['warnings'] as $item ) : ?>
							<li><?php echo esc_html( $item ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
			<?php if ( $report['notices'] ) : ?>
				<details><summary>Remarques (<?php echo esc_html( count( $report['notices'] ) ); ?>)</summary>
					<ul class="wpmig-report-list">
						<?php foreach ( $report['notices'] as $item ) : ?>
							<li><?php echo esc_html( $item ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php if ( '' !== (string) $report['log'] ) : ?>
		<div class="wpmig-card">
			<h2>Journal de l'installation</h2>
			<p class="description">Heures UTC. Copie du fichier <code>install.log</code> de l'installeur.</p>
			<pre class="wpmig-pre wpmig-log"><?php echo esc_html( $report['log'] ); ?></pre>
		</div>
		<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Cleanup settings.
	 */
	private static function render_cleanup() {
		$settings = WPMIG_Cleanup::settings();
		$last     = get_option( WPMIG_Cleanup::LAST );
		$pending  = WPMIG_Cleanup::plan();
		// phpcs:disable WordPress.Security.NonceVerification -- display only.
		?>
		<div class="wpmig-card" id="wpmig-cleanup">
			<h2>Nettoyage automatique</h2>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success inline"><p>Réglages enregistrés.</p></div>
			<?php elseif ( isset( $_GET['cleaned'] ) ) : ?>
				<div class="notice notice-success inline"><p><?php echo esc_html( absint( $_GET['cleaned'] ) ? sprintf( 'Nettoyage effectué : %d élément(s) supprimé(s), %s libérés.', absint( $_GET['cleaned'] ), isset( $_GET['freed'] ) ? sanitize_text_field( wp_unslash( $_GET['freed'] ) ) : '0' ) : 'Nettoyage effectué : rien à supprimer.' ); ?></p></div>
			<?php endif; ?>
			<p>Les anciennes sauvegardes occupent de l'espace sur l'hébergement et contiennent une copie complète du site. Le nettoyage s'exécute après chaque création et une fois par jour. Les sauvegardes ayant un lien de transfert actif ne sont jamais supprimées ; les créations abandonnées ou en échec le sont après 24 h.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpmig-cleanup-form">
				<input type="hidden" name="action" value="wpmig_cleanup_settings">
				<?php wp_nonce_field( 'wpmig_cleanup_settings' ); ?>
				<label>Conserver les <input type="number" name="keep" min="0" max="1000" value="<?php echo esc_attr( $settings['keep'] ); ?>" class="small-text"> dernières sauvegardes</label>
				<label>Supprimer les sauvegardes de plus de <input type="number" name="days" min="0" max="3650" value="<?php echo esc_attr( $settings['days'] ); ?>" class="small-text"> jours</label>
				<p class="description">0 désactive la règle correspondante.</p>
				<p>
					<button type="submit" class="button">Enregistrer</button>
					<button type="submit" name="clean_now" value="1" class="button button-secondary">Enregistrer et nettoyer maintenant</button>
				</p>
			</form>
			<p class="description">
				<?php
				echo esc_html( 'Espace utilisé par les sauvegardes : ' . wpmig_size( WPMIG_Cleanup::storage_size() ) . '.' );
				if ( $pending ) {
					$bytes = 0;
					foreach ( $pending as $item ) {
						$bytes += $item['size'];
					}
					echo ' ' . esc_html( sprintf( 'Prochain nettoyage : %d élément(s), %s.', count( $pending ), wpmig_size( $bytes ) ) );
				}
				if ( is_array( $last ) && ! empty( $last['time'] ) ) {
					echo ' ' . esc_html( $last['count'] ? sprintf( 'Dernier nettoyage : %s (%d élément(s), %s libérés).', wpmig_date( $last['time'] ), $last['count'], wpmig_size( $last['bytes'] ) ) : sprintf( 'Dernier nettoyage : %s (rien à supprimer).', wpmig_date( $last['time'] ) ) );
				}
				?>
			</p>
		</div>
		<?php
		// phpcs:enable
	}

	/**
	 * Source side of the synchronization.
	 */
	private static function render_sync_source() {
		$link = WPMIG_Sync_Source::link();
		?>
		<div class="wpmig-card wpmig-sync-source" id="wpmig-sync-source">
			<h2>1. Sur le site en ligne : autoriser la synchronisation</h2>
			<p>Pour récupérer sur une copie de travail de ce site (préproduction, développement) les commandes, clients, produits, articles et pages créés ou modifiés ici depuis la copie, ou pour y reprendre des réglages précis (moyen de paiement, langues…) : créez un lien et collez-le dans <strong>WP Migration → Synchronisation</strong> sur la copie. Ce site est seulement lu, jamais modifié.</p>
			<div class="wpmig-sync-link-status">
				<?php if ( $link ) : ?>
					<p><span class="wpmig-transfer-active">● Lien actif jusqu'au <?php echo esc_html( wpmig_date( $link['expires'] ) ); ?></span>
					<?php
					if ( ! empty( $link['log'] ) ) {
						$last = end( $link['log'] );
						echo ' — ' . esc_html( sprintf( 'dernière utilisation : %s (%s)', wpmig_date( $last[0] ), $last[1] ) );
					}
					?>
					</p>
				<?php endif; ?>
			</div>
			<p>
				<label>Validité <select id="wpmig-sync-hours"><option value="24">24 heures</option><option value="72">3 jours</option><option value="168">7 jours</option></select></label>
				<button type="button" class="button" data-sync-link><?php echo $link ? 'Créer un nouveau lien' : 'Créer un lien de synchronisation'; ?></button>
				<?php if ( $link ) : ?>
					<button type="button" class="button-link wpmig-danger" data-sync-revoke>Révoquer le lien</button>
				<?php endif; ?>
			</p>
			<div id="wpmig-sync-link-panel"></div>
		</div>
		<?php
	}

	/**
	 * Destination side of the synchronization.
	 */
	private static function render_sync() {
		$current = WPMIG_Sync::current();
		$report  = WPMIG_Report::get();
		$default = '';
		if ( $report && ! empty( $report['source']['home'] ) ) {
			$default = WPMIG_Sync::default_threshold( $report['source']['home'] );
		}
		$labels = WPMIG_Sync::labels();
		?>
		<div class="wpmig-card wpmig-sync" id="wpmig-sync" data-state="<?php echo esc_attr( $current ? wp_json_encode( $current->public_state() ) : '' ); ?>" data-labels="<?php echo esc_attr( wp_json_encode( array( 'kinds' => $labels, 'actions' => WPMIG_Sync::action_labels(), 'done' => WPMIG_Sync::done_labels() ) ) ); ?>">
			<h2>2. Sur la copie de travail : récupérer le contenu</h2>
			<p>Vous travaillez sur ce site pendant que le site d'origine reste en ligne ? Récupérez ici ce qui y a été créé ou modifié depuis la copie : <strong>commandes, clients, produits (et leur stock), codes promo, articles, pages, médias, avis</strong>. Les numéros de commande sont conservés ; vos modifications faites ici sur les produits, pages et médias sont gardées. Une analyse montre tout avant l'import, et une synchronisation peut être annulée.</p>
			<form id="wpmig-sync-form" <?php echo $current ? 'hidden' : ''; ?>>
				<p><label for="wpmig-sync-link"><strong>Lien de synchronisation</strong> (créé sur le site d'origine, avec WP Migration 1.5.0 ou plus récent)</label><br>
				<input type="url" id="wpmig-sync-link" class="large-text code" required placeholder="https://site-origine.fr/wp-admin/admin-ajax.php?action=wpmig_sync&amp;key=…"></p>
				<fieldset class="wpmig-sync-kinds"><legend><strong>Contenus</strong></legend>
					<?php foreach ( $labels as $kind => $label ) : ?>
						<label><input type="checkbox" name="kinds" value="<?php echo esc_attr( $kind ); ?>" checked> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<p><label for="wpmig-sync-since"><strong>Date de la copie</strong></label><br>
				<input type="datetime-local" id="wpmig-sync-since">
				<select id="wpmig-sync-packages" hidden><option value="">Sauvegarde utilisée pour la copie…</option></select>
				<span class="description" id="wpmig-sync-since-help">
					<?php
					if ( $default ) {
						echo esc_html( sprintf( 'Laissez vide pour utiliser le %s (%s).', wpmig_date( strtotime( $default . ' UTC' ) ), WPMIG_Sync::history() ? 'dernière synchronisation ou rapport de migration' : 'rapport de migration' ) );
					} else {
						echo esc_html( 'Moment où ce site a été copié depuis le site d\'origine (laissez vide après une première synchronisation).' );
					}
					?>
				</span></p>
				<p><label><input type="checkbox" id="wpmig-sync-force"> Remplacer aussi les produits, pages et médias modifiés sur ce site (sinon leur version est conservée, seuls le stock et les ventes des produits sont repris)</label></p>
				<p><button type="submit" class="button button-primary">Analyser</button> <span class="description">Rien n'est modifié avant votre confirmation.</span></p>
			</form>
			<div id="wpmig-sync-panel"></div>
			<?php
			$history = WPMIG_Sync::history();
			if ( $history ) :
				?>
				<details class="wpmig-sync-history"><summary>Synchronisations précédentes (<?php echo esc_html( count( $history ) ); ?>)</summary>
					<table class="widefat striped"><thead><tr><th>Date</th><th>Site d'origine</th><th>Résultat</th></tr></thead><tbody>
					<?php foreach ( $history as $h ) : ?>
						<tr>
							<td><?php echo esc_html( wpmig_date( $h['finished'] ) ); ?></td>
							<td><code><?php echo esc_html( $h['source'] ); ?></code></td>
							<td>
								<?php
								$parts = array();
								foreach ( $h['counts'] as $kind => $counts ) {
									$n = ( isset( $counts['insert'] ) ? $counts['insert'] : 0 ) + ( isset( $counts['update'] ) ? $counts['update'] : 0 ) + ( isset( $counts['stock'] ) ? $counts['stock'] : 0 );
									if ( $n ) {
										$parts[] = ( isset( $labels[ $kind ] ) ? $labels[ $kind ] : $kind ) . ' : ' . $n;
									}
								}
								echo esc_html( ( 'undone' === $h['status'] ? 'Annulée. ' : '' ) . ( $parts ? implode( ', ', $parts ) : 'rien à importer' ) );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody></table>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Settings taken from another site.
	 */
	private static function render_settings_pull() {
		$history = WPMIG_Settings::history();
		?>
		<div class="wpmig-card wpmig-settings" id="wpmig-settings">
			<h2>Reprendre des réglages d'un autre site</h2>
			<p>Un réglage a disparu ou diffère de celui du site d'origine (moyen de paiement, TVA, langues, widgets…) ? Choisissez-le ici : il est lu sur le site d'origine avec le même lien de synchronisation, <strong>comparé au vôtre avant toute écriture</strong>, puis copié. Les adresses du site d'origine sont remplacées par celles de ce site, les copies peuvent être annulées, et les réglages propres à chaque site (adresse, thème, extensions, versions) ne sont jamais touchés.</p>
			<form id="wpmig-settings-form">
				<p><label for="wpmig-settings-link"><strong>Lien de synchronisation</strong> du site d'origine (WP Migration 1.9.0 ou plus récent)</label><br>
				<input type="url" id="wpmig-settings-link" class="large-text code" required placeholder="https://site-origine.fr/wp-admin/admin-ajax.php?action=wpmig_sync&amp;key=…"></p>
				<p><label for="wpmig-settings-q"><strong>Nom du réglage</strong></label><br>
				<input type="text" id="wpmig-settings-q" class="regular-text code" autocomplete="off" spellcheck="false" placeholder="monetico, woocommerce_, polylang…">
				<button type="submit" class="button button-primary">Chercher</button></p>
				<p class="description">Filtres rapides :
					<?php foreach ( array( 'woocommerce_' => 'WooCommerce', '_settings' => 'Réglages d\'extensions', 'polylang' => 'Polylang', 'icl_' => 'WPML', 'widget' => 'Widgets', 'theme_mods_' => 'Thème' ) as $q => $label ) : ?>
						<button type="button" class="button-link" data-settings-q="<?php echo esc_attr( $q ); ?>"><?php echo esc_html( $label ); ?></button> ·
					<?php endforeach; ?>
					<button type="button" class="button-link" data-settings-q="*">Tout lister</button>
				</p>
			</form>
			<div id="wpmig-settings-panel"></div>
			<?php if ( $history ) : ?>
				<details class="wpmig-sync-history"><summary>Copies précédentes (<?php echo esc_html( count( $history ) ); ?>)</summary>
					<table class="widefat striped"><thead><tr><th>Date</th><th>Site d'origine</th><th>Réglages</th><th>Résultat</th><th></th></tr></thead><tbody>
					<?php foreach ( $history as $h ) : ?>
						<tr>
							<td><?php echo esc_html( wpmig_date( $h['finished'] ) ); ?></td>
							<td><code><?php echo esc_html( $h['source'] ); ?></code></td>
							<td><?php echo esc_html( implode( ', ', array_slice( $h['names'], 0, 4 ) ) . ( $h['count'] > 4 ? '…' : '' ) ); ?></td>
							<td><?php echo esc_html( 'undone' === $h['status'] ? 'Annulée' : sprintf( '%d réglage(s) copié(s)', $h['count'] ) ); ?></td>
							<td><?php if ( 'done' === $h['status'] && is_file( WPMIG_Plugin::storage_dir() . 'settings-' . $h['id'] . '.php' ) ) : ?><button type="button" class="button-link wpmig-danger" data-settings-undo="<?php echo esc_attr( $h['id'] ); ?>">Annuler</button><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody></table>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Comparison with another site.
	 */
	private static function render_compare() {
		?>
		<div class="wpmig-card wpmig-compare" id="wpmig-compare">
			<h2>Comparer ce site avec un autre</h2>
			<p>Quelles extensions, quels réglages ou quelles langues diffèrent entre ce site et le site d'origine (ou sa copie) ? La comparaison est <strong>en lecture seule</strong> : rien n'est modifié sur aucun des deux sites. Elle ne transmet aucun mot de passe ni clé.</p>
			<form id="wpmig-compare-form">
				<p><label for="wpmig-compare-link"><strong>Lien de synchronisation</strong> de l'autre site (WP Migration 1.10.0 ou plus récent)</label><br>
				<input type="url" id="wpmig-compare-link" class="large-text code" required placeholder="https://autre-site.fr/wp-admin/admin-ajax.php?action=wpmig_sync&amp;key=…">
				<button type="submit" class="button button-primary">Comparer</button></p>
			</form>
			<div id="wpmig-compare-panel"></div>
		</div>
		<?php
	}

	/**
	 * Search & replace in the database.
	 */
	private static function render_search() {
		$current = WPMIG_Search::current();
		$tables  = WPMIG_DB_Exporter::site_tables();
		$history = WPMIG_Search::history();
		// Old address of a migrated site, when it still differs from the current one.
		$report  = WPMIG_Report::get();
		$suggest = '';
		if ( $report && ! empty( $report['source']['home'] ) && untrailingslashit( $report['source']['home'] ) !== untrailingslashit( home_url() ) ) {
			$suggest = untrailingslashit( $report['source']['home'] );
		}
		?>
		<div class="wpmig-card wpmig-search" id="wpmig-search" data-state="<?php echo esc_attr( $current ? wp_json_encode( $current->public_state() ) : '' ); ?>">
			<h2>Rechercher et remplacer dans la base de données</h2>
			<p>Changez une adresse, un domaine ou un texte dans <strong>tout le contenu du site</strong> (articles, pages, réglages, commandes…), y compris dans les données que WordPress mémorise sous forme sérialisée et qu'un simple « rechercher / remplacer » SQL casserait. Une analyse montre ce qui serait modifié avant le moindre changement, et l'opération peut être annulée.</p>
			<?php if ( $suggest ) : ?>
				<p class="wpmig-suggest">Ce site a été migré depuis <code><?php echo esc_html( $suggest ); ?></code> : <button type="button" class="button-link" data-search-suggest="<?php echo esc_attr( $suggest ); ?>|<?php echo esc_attr( untrailingslashit( home_url() ) ); ?>">remplacer l'ancienne adresse par l'adresse actuelle</button></p>
			<?php endif; ?>
			<form id="wpmig-search-form" <?php echo $current ? 'hidden' : ''; ?>>
				<table class="form-table" role="presentation"><tbody>
					<tr>
						<th scope="row"><label for="wpmig-search-text">Rechercher</label></th>
						<td><input type="text" id="wpmig-search-text" class="large-text code" required autocomplete="off" spellcheck="false" placeholder="https://www.ancien-site.fr"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmig-search-replace">Remplacer par</label></th>
						<td><input type="text" id="wpmig-search-replace" class="large-text code" autocomplete="off" spellcheck="false" placeholder="https://www.nouveau-site.fr">
						<p class="description">Laissez vide pour supprimer le texte trouvé. Une adresse est reconnue automatiquement : ses variantes (<code>https</code>, <code>www</code>, formes encodées) sont traitées.</p></td>
					</tr>
				</tbody></table>
				<details class="wpmig-advanced"><summary>Options avancées : type de recherche, casse, tables</summary>
					<table class="form-table" role="presentation"><tbody>
						<tr>
							<th scope="row">Type de recherche</th>
							<td>
								<label><input type="radio" name="wpmig-search-mode" value="auto" checked> <strong>Automatique</strong> — adresse, domaine ou chemin si le texte y ressemble, sinon texte</label><br>
								<label><input type="radio" name="wpmig-search-mode" value="url"> <strong>URL, domaine ou chemin</strong> — mots entiers : <code>http://a.fr</code> ne touche pas <code>http://a.frite.com</code></label><br>
								<label><input type="radio" name="wpmig-search-mode" value="text"> <strong>Texte</strong> — toutes les occurrences, où qu'elles soient</label><br>
								<label><input type="radio" name="wpmig-search-mode" value="regex"> <strong>Expression régulière</strong> — <code>/motif/i</code> ; <code>$1</code>, <code>$2</code>… désignent les groupes capturés (ajoutez <code>u</code> pour les caractères accentués)</label>
							</td>
						</tr>
						<tr>
							<th scope="row">Options</th>
							<td>
								<label><input type="checkbox" id="wpmig-search-case"> Ignorer la casse</label><br>
								<label data-mode="url"><input type="checkbox" id="wpmig-search-www" checked> Traiter aussi la variante avec / sans <code>www.</code></label>
								<label data-mode="text" hidden><input type="checkbox" id="wpmig-search-variants" checked> Traiter aussi le texte dans les URL encodées (<code>%2F</code>) et le JSON (<code>\/</code>)</label><br>
								<label><input type="checkbox" id="wpmig-search-guid"> Modifier aussi les identifiants (<code>guid</code>) des articles <span class="description">— déconseillé : ce ne sont pas des liens, et les flux RSS s'en servent pour reconnaître les articles déjà lus</span></label>
								<details class="wpmig-tables"><summary>Limiter à certaines tables (<?php echo count( $tables ); ?> tables, toutes par défaut)</summary>
									<p><button type="button" class="button-link" data-search-tables="all">Tout cocher</button> · <button type="button" class="button-link" data-search-tables="none">Tout décocher</button></p>
									<div class="wpmig-table-list">
									<?php foreach ( $tables as $t ) : ?>
										<label><input type="checkbox" name="wpmig-search-table" value="<?php echo esc_attr( $t['name'] ); ?>" checked> <code><?php echo esc_html( $t['name'] ); ?></code> <span class="description"><?php echo esc_html( number_format_i18n( $t['rows'] ) . ' lignes' ); ?></span></label>
									<?php endforeach; ?>
									</div>
								</details>
							</td>
						</tr>
					</tbody></table>
				</details>
				<p><button type="submit" class="button button-primary">Analyser</button> <span class="description">Rien n'est modifié avant votre confirmation. Les noms de réglages et de métadonnées, les mots de passe et les tables d'autres sites ne sont jamais touchés.</span></p>
			</form>
			<div id="wpmig-search-panel"></div>
			<?php if ( $history ) : ?>
				<details class="wpmig-sync-history"><summary>Remplacements précédents (<?php echo esc_html( count( $history ) ); ?>)</summary>
					<table class="widefat striped"><thead><tr><th>Date</th><th>Recherche</th><th>Remplacement</th><th>Résultat</th><th></th></tr></thead><tbody>
					<?php foreach ( $history as $h ) : ?>
						<tr>
							<td><?php echo esc_html( wpmig_date( $h['finished'] ) ); ?></td>
							<td><code><?php echo esc_html( mb_strimwidth( $h['params']['search'], 0, 60, '…' ) ); ?></code></td>
							<td><code><?php echo esc_html( mb_strimwidth( $h['params']['replace'], 0, 60, '…' ) ); ?></code></td>
							<td><?php echo esc_html( 'undone' === $h['status'] ? 'Annulé' : sprintf( '%s occurrence(s) dans %s ligne(s)', number_format_i18n( $h['matches'] ), number_format_i18n( $h['changed'] ) ) ); ?></td>
							<td><?php if ( 'done' === $h['status'] && is_file( WPMIG_Plugin::storage_dir() . 'search-' . $h['id'] . '/undo.php' ) ) : ?><button type="button" class="button-link wpmig-danger" data-search-undo="<?php echo esc_attr( $h['id'] ); ?>">Annuler</button><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody></table>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Import from another site (direct transfer link).
	 */
	private static function render_import() {
		if ( ! current_user_can( 'install_plugins' ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ) {
			return;
		}
		?>
		<div class="wpmig-card wpmig-import">
			<h2>Ou copier directement un autre site (sans FTP)</h2>
			<p>Sur le site d'origine, créez une sauvegarde puis cliquez sur « Transfert direct » : collez ici le lien obtenu. L'installeur de la sauvegarde est placé sur ce serveur et récupère l'archive directement ; les accès à la base de données sont repris de ce site.</p>
			<form id="wpmig-import-form" class="wpmig-copy">
				<input type="url" id="wpmig-import-link" class="large-text code" required placeholder="https://site-origine.fr/wp-admin/admin-ajax.php?action=wpmig_transfer&amp;id=…&amp;key=…">
				<button type="submit" class="button button-primary">Importer</button>
			</form>
			<p class="description">Attention : tous les contenus, réglages, extensions et comptes de ce site seront remplacés par ceux du site d'origine. Le mot de passe de l'installeur de la sauvegarde vous sera demandé.</p>
			<div id="wpmig-import-msg"></div>
		</div>
		<?php
	}

	/**
	 * How-to.
	 */
	private static function render_help() {
		?>
		<div class="wpmig-card wpmig-help">
			<h2>Je change d'hébergeur ou de nom de domaine</h2>
			<ol>
				<li>Sur l'ancien site : onglet <a href="<?php echo esc_url( self::tab_url( 'backups' ) ); ?>"><strong>Sauvegardes</strong></a>, puis <strong>Sauvegarde complète</strong>. Téléchargez l'<em>archive</em> (<code>.wpmig</code>) et l'<em>installeur</em> (<code>installer.php</code>) et notez le mot de passe affiché.</li>
				<li>Chez le nouvel hébergeur, <strong>créez une base de données MySQL</strong> vide (nom, utilisateur, mot de passe).</li>
				<li><strong>Envoyez les deux fichiers</strong> par FTP/SFTP (en mode <em>binaire</em>) dans le dossier du site, par exemple <code>public_html/</code>. Le dossier peut être vide ou contenir un ancien WordPress, qui sera remplacé.</li>
				<li>Ouvrez <code>https://nouveau-domaine.fr/installer.php</code> dans le navigateur et suivez les étapes. Les adresses et chemins sont remplacés automatiquement, y compris dans les données sérialisées.</li>
				<li>Connectez-vous avec vos identifiants habituels puis <strong>supprimez les fichiers d'installation</strong> (bouton proposé à la fin de l'installation et dans l'administration).</li>
			</ol>
			<p><strong>Sans FTP</strong> : sur l'ancien site, cliquez sur « Transfert direct » à côté de la sauvegarde et déposez seulement <code>installer.php</code> sur le nouveau serveur ; il télécharge l'archive lui-même avec le lien secret et temporaire fourni. Ou, sur un WordPress déjà installé, utilisez l'onglet <a href="<?php echo esc_url( self::tab_url( 'receive' ) ); ?>">Recevoir un site</a>.</p>
		</div>
		<div class="wpmig-card wpmig-help">
			<h2>Je travaille sur une copie pendant que le site en ligne continue de vivre</h2>
			<ol>
				<li>Copiez le site sur le serveur de travail (voir ci-dessus).</li>
				<li>Sur le site en ligne : onglet <a href="<?php echo esc_url( self::tab_url( 'sync' ) ); ?>"><strong>Synchronisation</strong></a>, <em>1. autoriser la synchronisation</em>, puis copiez le lien.</li>
				<li>Sur la copie, quand vous êtes prêt : <em>2. récupérer le contenu</em>, collez le lien et analysez. Les commandes, clients, produits, articles et pages créés entre-temps arrivent après votre confirmation.</li>
				<li>Recommencez autant que nécessaire, puis révoquez le lien.</li>
			</ol>
		</div>
		<div class="wpmig-card wpmig-help">
			<h2>Je veux changer une adresse ou un texte partout</h2>
			<p>Onglet <a href="<?php echo esc_url( self::tab_url( 'search' ) ); ?>"><strong>Rechercher / Remplacer</strong></a> : saisissez l'ancienne et la nouvelle valeur, lisez l'analyse, sauvegardez la base de données d'un clic, puis remplacez. Le remplacement peut être annulé.</p>
			<p class="description">Accès SSH ? L'installeur fonctionne aussi en ligne de commande : <code>php installer.php --help</code>. Et l'extension se pilote avec WP-CLI : <code>wp migration build</code>, <code>wp migration sync</code>, <code>wp migration replace</code>.</p>
		</div>
		<?php
	}
}
