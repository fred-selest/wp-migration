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
		add_action( 'admin_post_wpmig_download', array( __CLASS__, 'download' ) );
		add_action( 'admin_post_wpmig_cleanup_install', array( __CLASS__, 'cleanup_install' ) );
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
				echo '<div class="notice notice-success is-dismissible"><p><strong>WP Migration :</strong> fichiers d\'installation supprimés. La migration est terminée.</p></div>';
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
		echo '<div class="notice notice-' . ( $left ? 'warning' : 'success' ) . '"><p><strong>WP Migration :</strong> site migré' . ( $from ? ' depuis <code>' . esc_html( $from ) . '</code>' : '' ) . '. ';
		if ( $left ) {
			echo 'Des fichiers d\'installation sont encore présents sur le serveur (' . esc_html( implode( ', ', array_map( 'basename', $left ) ) ) . ') : ils contiennent une copie complète du site et doivent être supprimés.</p>';
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

		self::render_wizard();
		self::render_packages();
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
							<details class="wpmig-tables"><summary>Exclure des tables (<?php echo count( $tables ); ?> tables)</summary>
								<div class="wpmig-table-list">
								<?php foreach ( $tables as $t ) : ?>
									<label><input type="checkbox" name="exclude_tables[]" value="<?php echo esc_attr( $t['name'] ); ?>"> <code><?php echo esc_html( $t['name'] ); ?></code> <span class="description"><?php echo esc_html( size_format( $t['size'], 1 ) . ' — ' . number_format_i18n( $t['rows'] ) . ' lignes' ); ?></span></label>
								<?php endforeach; ?>
								</div>
								<p class="description">Attention : n'excluez jamais une table indispensable (options, users, posts…).</p>
							</details>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wpmig-password">Mot de passe de l'installeur</label></th>
						<td><input type="password" id="wpmig-password" name="password" class="regular-text" autocomplete="new-password">
						<p class="description">Facultatif mais recommandé : il sera demandé à l'ouverture de <code>installer.php</code>.</p></td>
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
			if ( 'error' === $d['status'] && $d['error'] ) {
				echo '<br><span class="wpmig-status-error">' . esc_html( $d['error'] ) . '</span>';
			}
			echo '</td><td class="wpmig-row-actions">';
			if ( 'complete' === $d['status'] ) {
				echo '<a class="button button-primary" data-download="archive" href="#">Archive</a> ';
				echo '<a class="button button-primary" data-download="installer" href="#">Installeur</a> ';
				echo '<button type="button" class="button" data-download="both">Les deux</button> ';
			} elseif ( in_array( $d['status'], array( 'scanning', 'scanned', 'building' ), true ) ) {
				echo '<button type="button" class="button" data-resume="1">Reprendre</button> ';
			}
			echo '<button type="button" class="button button-link-delete" data-delete="1">Supprimer</button>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
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
			<p class="description">Accès SSH ? L'installeur fonctionne aussi en ligne de commande : <code>php installer.php --help</code>. Et le package peut être créé avec WP-CLI : <code>wp migration build</code>.</p>
		</div>
		<?php
	}
}

if ( ! function_exists( 'wpmig_date' ) ) {
	/**
	 * Localized date (wp_date() only exists since WordPress 5.3).
	 *
	 * @param int $timestamp Timestamp.
	 * @return string
	 */
	function wpmig_date( $timestamp ) {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		if ( function_exists( 'wp_date' ) ) {
			return wp_date( $format, $timestamp );
		}
		return date_i18n( $format, $timestamp + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
	}
}
