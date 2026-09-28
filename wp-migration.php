<?php
/**
 * Plugin Name:       WP Migration
 * Plugin URI:        https://github.com/fred-selest/wp-migration
 * Description:       Copie un site WordPress complet (fichiers + base de données) vers un nouveau domaine et/ou un nouvel hébergement. Crée un package (archive + installer.php) à déposer sur le serveur de destination.
 * Version:           1.2.0
 * Requires at least: 4.9
 * Requires PHP:      5.6
 * Author:            fred-selest
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-migration
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPMIG_VERSION', '1.2.0' );
define( 'WPMIG_FILE', __FILE__ );
define( 'WPMIG_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPMIG_URL', plugin_dir_url( __FILE__ ) );

require_once WPMIG_DIR . 'includes/lib/class-wpmig-archive.php';
require_once WPMIG_DIR . 'includes/lib/class-wpmig-replacer.php';
require_once WPMIG_DIR . 'includes/lib/class-wpmig-sql.php';
require_once WPMIG_DIR . 'includes/class-wpmig-plugin.php';
require_once WPMIG_DIR . 'includes/class-wpmig-package.php';
require_once WPMIG_DIR . 'includes/class-wpmig-scanner.php';
require_once WPMIG_DIR . 'includes/class-wpmig-db-exporter.php';
require_once WPMIG_DIR . 'includes/class-wpmig-archiver.php';
require_once WPMIG_DIR . 'includes/class-wpmig-installer-builder.php';
require_once WPMIG_DIR . 'includes/class-wpmig-transfer.php';

if ( is_admin() ) {
	require_once WPMIG_DIR . 'includes/class-wpmig-admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WPMIG_DIR . 'includes/class-wpmig-cli.php';
}

register_activation_hook( __FILE__, array( 'WPMIG_Plugin', 'activate' ) );

WPMIG_Plugin::init();
