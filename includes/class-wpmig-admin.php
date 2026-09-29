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
		foreach ( array( 'sync_probe', 'sync_start', 'sync_step', 'sync_confirm', 'sync_undo', 'sync_dismiss', 'sync_link', 'sync_revoke' ) as $action ) {
			add_action( 'wp_ajax_wpmig_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
		add_action( 'admin_post_wpmig_download', array( __CLASS__, 'download' ) );
		add_action( 'admin_post_wpmig_cleanup_install', array( __CLASS__, 'cleanup_install' ) );
		add_action( 'admin_post_wpmig_cleanup_settings', array( __CLASS__, 'cleanup_settings' ) );
		add_action( 'admin_post_wpmig_report_download', array( __CLASS__, 'report_download' ) );
		add_action( 'admin_post_wpmig_report_delete', array( __CLASS__, 'report_delete' ) );
		add_action( 'admin_init', array( __CLASS__, 'post_install' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPMIG_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_menu_page( 'WP Migration', 'WP Migration', self::CAP, self::SLUG, array( __CLASS__, 'page' ), 'dashicons-migrate', 80 );
	}

	/**
	 * Plugin list link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">Packages</a>' );
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
			wp_send_json_error( array( 'message' => 'Package introuvable.' ) );
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
		$args = array( 'page' => self::SLUG );
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
		echo '<div class="wrap wpmig">';
		echo '<h1 class="wp-heading-inline">WP Migration</h1>';
		if ( is_multisite() ) {
			echo '<div class="notice notice-error"><p>Les installations multisite ne sont pas prises en charge.</p></div></div>';
			return;
		}
		echo ' <button type="button" class="page-title-action" id="wpmig-new">Créer un package</button>';
		echo '<hr class="wp-header-end">';

		try {
			WPMIG_Plugin::storage_dir();
		} catch ( Exception $e ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div></div>';
			return;
		}

		self::render_report_card();
		self::render_wizard();
		self::render_packages();
		self::render_sync_source();
		self::render_cleanup();
		self::render_import();
		self::render_sync();
		self::render_help();
		echo '</div>';
	}

	/**
	 * Package creation wizard.
	 */
	private static function render_wizard() {
		$tables = WPMIG_DB_Exporter::site_tables();
		?>
		<div id="wpmig-wizard" class="wpmig-card" hidden>
			<ol class="wpmig-steps">
				<li data-step="1">1. Configuration</li>
				<li data-step="2">2. Analyse</li>
				<li data-step="3">3. Construction</li>
			</ol>

			<form id="wpmig-form" data-step="1" class="wpmig-panel">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpmig-name">Nom du package</label></th>
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
					<button type="button" class="button button-primary" data-action="build">Construire le package</button>
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
	 * Packages list.
	 */
	private static function render_packages() {
		$packages = WPMIG_Package::all();
		echo '<div class="wpmig-card"><h2>Packages</h2>';
		if ( ! $packages ) {
			echo '<p class="wpmig-empty">Aucun package. Cliquez sur « Créer un package » pour commencer.</p></div>';
			return;
		}
		echo '<table class="widefat striped wpmig-packages"><thead><tr><th>Nom</th><th>Créé le</th><th>Taille</th><th>Statut</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $packages as $package ) {
			$d      = $package->data;
			$labels = array(
				'scanning' => 'Analyse interrompue',
				'scanned'  => 'Analysé (non construit)',
				'building' => 'Construction interrompue',
				'complete' => 'Prêt',
				'error'    => 'Erreur',
			);
			echo '<tr data-id="' . esc_attr( $d['id'] ) . '">';
			echo '<td><strong>' . esc_html( $d['name'] ) . '</strong><br><span class="description">' . esc_html( $d['id'] ) . '</span></td>';
			echo '<td>' . esc_html( wpmig_date( $d['created'] ) ) . '</td>';
			echo '<td>' . ( ! empty( $d['sizes']['archive'] ) ? esc_html( size_format( $d['sizes']['archive'], 1 ) ) : '—' ) . '</td>';
			echo '<td>' . esc_html( isset( $labels[ $d['status'] ] ) ? $labels[ $d['status'] ] : $d['status'] );
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
			} elseif ( in_array( $d['status'], array( 'scanning', 'scanned', 'building' ), true ) ) {
				echo '<button type="button" class="button" data-resume="1">Reprendre</button> ';
			}
			echo '<button type="button" class="button button-link-delete" data-delete="1">Supprimer</button>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
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
			<h2>Rapport de migration <?php echo self::badge( ! empty( $c['ok'] ), ! empty( $c['ok'] ) ? 'Copie complète' : count( $c['issues'] ) . ' point(s) à vérifier' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></h2>
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
	 * Full migration report.
	 */
	private static function render_report_page() {
		$report = WPMIG_Report::get();
		echo '<div class="wrap wpmig wpmig-report">';
		echo '<h1 class="wp-heading-inline">Rapport de migration</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">← WP Migration</a>';
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
				<details><summary>Exclus volontairement du package par le site d'origine (<?php echo esc_html( count( $report['excluded'] ) ); ?>)</summary>
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
			<p>Les anciens packages occupent de l'espace sur l'hébergement et contiennent une copie complète du site. Le nettoyage s'exécute après chaque construction et une fois par jour. Les packages ayant un lien de transfert actif ne sont jamais supprimés ; les constructions abandonnées ou en échec le sont après 24 h.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpmig-cleanup-form">
				<input type="hidden" name="action" value="wpmig_cleanup_settings">
				<?php wp_nonce_field( 'wpmig_cleanup_settings' ); ?>
				<label>Conserver les <input type="number" name="keep" min="0" max="1000" value="<?php echo esc_attr( $settings['keep'] ); ?>" class="small-text"> derniers packages</label>
				<label>Supprimer les packages de plus de <input type="number" name="days" min="0" max="3650" value="<?php echo esc_attr( $settings['days'] ); ?>" class="small-text"> jours</label>
				<p class="description">0 désactive la règle correspondante.</p>
				<p>
					<button type="submit" class="button">Enregistrer</button>
					<button type="submit" name="clean_now" value="1" class="button button-secondary">Enregistrer et nettoyer maintenant</button>
				</p>
			</form>
			<p class="description">
				<?php
				echo esc_html( 'Espace utilisé par les packages : ' . wpmig_size( WPMIG_Cleanup::storage_size() ) . '.' );
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
			<h2>Autoriser la synchronisation depuis ce site</h2>
			<p>Pour récupérer sur une copie de travail de ce site (préproduction, développement) les commandes, clients, produits, articles et pages créés ou modifiés ici depuis la copie : créez un lien et collez-le dans <strong>WP Migration → Synchroniser le contenu</strong> sur la copie. Ce site est seulement lu, jamais modifié.</p>
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
			<h2>Synchroniser le contenu depuis le site d'origine</h2>
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
				<select id="wpmig-sync-packages" hidden><option value="">Package utilisé pour la copie…</option></select>
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
	 * Import from another site (direct transfer link).
	 */
	private static function render_import() {
		if ( ! current_user_can( 'install_plugins' ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ) {
			return;
		}
		?>
		<div class="wpmig-card wpmig-import">
			<h2>Importer un site sur ce WordPress</h2>
			<p>Pour remplacer <strong>ce site</strong> par un autre sans passer par le FTP : sur le site d'origine, créez un package puis cliquez sur « Transfert direct », et collez ici le lien obtenu. L'installeur du package est placé sur ce serveur et récupère l'archive directement ; les accès à la base de données sont repris de ce site.</p>
			<form id="wpmig-import-form" class="wpmig-copy">
				<input type="url" id="wpmig-import-link" class="large-text code" required placeholder="https://site-origine.fr/wp-admin/admin-ajax.php?action=wpmig_transfer&amp;id=…&amp;key=…">
				<button type="submit" class="button button-primary">Importer</button>
			</form>
			<p class="description">Attention : tous les contenus, réglages, extensions et comptes de ce site seront remplacés par ceux du site d'origine. Le mot de passe de l'installeur du package vous sera demandé.</p>
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
			<h2>Comment migrer le site ?</h2>
			<ol>
				<li><strong>Créez un package</strong> ici puis téléchargez l'<em>archive</em> (<code>.wpmig</code>) et l'<em>installeur</em> (<code>installer.php</code>).</li>
				<li>Sur le nouvel hébergement, <strong>créez une base de données MySQL</strong> vide (nom, utilisateur, mot de passe) depuis le panneau de l'hébergeur.</li>
				<li><strong>Envoyez les deux fichiers</strong> par FTP/SFTP (en mode <em>binaire</em>) dans le dossier du site, par exemple <code>public_html/</code>. Le dossier peut être vide ou contenir un ancien WordPress, qui sera remplacé.</li>
				<li>Ouvrez <code>https://nouveau-domaine.fr/installer.php</code> dans le navigateur et suivez les étapes : vérifications, base de données, installation. Les URL et chemins sont remplacés automatiquement, y compris dans les données sérialisées.</li>
				<li>Connectez-vous avec vos identifiants habituels puis <strong>supprimez les fichiers d'installation</strong> (bouton proposé à la fin de l'installation et dans l'administration).</li>
			</ol>
			<p><strong>Transfert direct de serveur à serveur</strong> : au lieu d'envoyer l'archive par FTP, cliquez sur « Transfert direct » et déposez seulement <code>installer.php</code> sur le nouveau serveur ; l'installeur y télécharge l'archive directement depuis ce site, avec le lien secret et temporaire fourni.</p>
			<p><strong>Travail sur une copie</strong> : si le site d'origine reste en ligne pendant que vous travaillez sur la copie, « Synchroniser le contenu » y rapatrie ensuite les commandes, clients, produits, articles et pages créés entre-temps.</p>
			<p class="description">Accès SSH ? L'installeur fonctionne aussi en ligne de commande : <code>php installer.php --help</code>. Et le package peut être créé avec WP-CLI : <code>wp migration build</code>.</p>
		</div>
		<?php
	}
}

