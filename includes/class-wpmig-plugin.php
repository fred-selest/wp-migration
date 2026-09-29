<?php
/**
 * Core helpers: storage directory, defaults, post-installation tasks.
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin core.
 */
class WPMIG_Plugin {

	/**
	 * Hooks.
	 */
	public static function init() {
		WPMIG_Transfer::init();
		WPMIG_Cleanup::init();
		WPMIG_Updater::init();
		if ( is_admin() && class_exists( 'WPMIG_Admin' ) ) {
			WPMIG_Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WPMIG_CLI' ) ) {
			WP_CLI::add_command( 'migration', 'WPMIG_CLI' );
		}
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::storage_dir();
	}

	/**
	 * Storage directory (created and protected on first use).
	 *
	 * @return string Absolute path with trailing slash.
	 * @throws WPMIG_Exception When it can't be created.
	 */
	public static function storage_dir() {
		$dir = apply_filters( 'wpmig_storage_dir', self::normalize( WP_CONTENT_DIR ) . '/wpmig-backups' );
		$dir = rtrim( $dir, '/' ) . '/';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new WPMIG_Exception( sprintf( 'Impossible de créer le dossier de stockage %s : vérifiez les permissions.', $dir ) );
		}
		self::protect_dir( $dir );
		return $dir;
	}

	/**
	 * Deny web access to a directory (Apache, LiteSpeed, IIS) and prevent listing.
	 * Package file names also contain a random hash for servers ignoring these files (nginx).
	 *
	 * @param string $dir Directory with trailing slash.
	 */
	public static function protect_dir( $dir ) {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
			'.htaccess'  => "# WP Migration\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\nOptions -Indexes\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
		);
		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $dir . $name ) ) {
				@file_put_contents( $dir . $name, $content ); // phpcs:ignore
			}
		}
	}

	/**
	 * Normalize a filesystem path (forward slashes, no trailing slash).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function normalize( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$path = preg_replace( '#(?<!^)/+#', '/', $path );
		return '/' === $path ? $path : rtrim( $path, '/' );
	}

	/**
	 * Seconds of work per HTTP request.
	 *
	 * @return float
	 */
	public static function time_budget() {
		$max    = (int) ini_get( 'max_execution_time' );
		$budget = 15;
		if ( $max > 0 ) {
			$budget = min( $budget, max( 3, $max * 0.4 ) );
		}
		return (float) apply_filters( 'wpmig_time_budget', $budget );
	}

	/**
	 * Try to raise the limits for a long running request.
	 */
	public static function raise_limits() {
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore
		}
		@ignore_user_abort( true ); // phpcs:ignore
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory.
	 */
	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			@unlink( $dir ); // phpcs:ignore
			return;
		}
		$items = scandir( $dir );
		foreach ( (array) $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::rrmdir( $path );
			} else {
				@unlink( $path ); // phpcs:ignore
			}
		}
		@rmdir( $dir ); // phpcs:ignore
	}

	/**
	 * Directories excluded by default (relative to the WordPress root). They contain
	 * caches, logs or backups that are useless (or harmful) on the new server.
	 *
	 * @return array
	 */
	public static function default_excluded_dirs() {
		$content = self::content_rel();
		$dirs    = array(
			$content . '/cache',
			$content . '/et-cache',
			$content . '/litespeed',
			$content . '/wflogs',
			$content . '/upgrade',
			$content . '/upgrade-temp-backup',
			$content . '/updraft',
			$content . '/ai1wm-backups',
			$content . '/backups-dup-lite',
			$content . '/backups-dup-pro',
			$content . '/backup-db',
			$content . '/backups',
			$content . '/wpvividbackups',
			$content . '/backup-guard',
			$content . '/uploads/backup-guard',
			$content . '/uploads/wp-staging',
			$content . '/wp-staging',
			$content . '/wp-rocket-config',
			'dup-installer',
		);
		return apply_filters( 'wpmig_default_excluded_dirs', $dirs );
	}

	/**
	 * Hosting-specific files (must-use plugins of managed hosts, cache drop-ins) that
	 * break a site when moved to another host.
	 *
	 * @return array Paths relative to the WordPress root.
	 */
	public static function host_specific_paths() {
		$content = self::content_rel();
		$mu      = $content . '/mu-plugins/';
		$paths   = array(
			$content . '/object-cache.php',
			$content . '/advanced-cache.php',
			$mu . 'wpengine-common',
			$mu . 'mu-plugin.php',
			$mu . 'wpe-wp-sign-on-plugin',
			$mu . 'wpe-wp-sign-on-plugin.php',
			$mu . 'wpe-elasticpress-autosuggest-logger',
			$mu . 'wpe-elasticpress-autosuggest-logger.php',
			$mu . 'wpe-cache-plugin',
			$mu . 'wpe-cache-plugin.php',
			$mu . 'force-strong-passwords',
			$mu . 'slt-force-strong-passwords.php',
			$mu . 'kinsta-mu-plugins',
			$mu . 'kinsta-mu-plugins.php',
			$mu . 'gd-system-plugin',
			$mu . 'gd-system-plugin.php',
			$mu . 'sso.php',
			$mu . 'endurance-page-cache.php',
			$mu . 'endurance-browser-cache.php',
			$mu . 'endurance-php-edge.php',
			$mu . 'wpcomsh',
			$mu . 'wpcomsh-loader.php',
			$mu . 'pantheon',
			$mu . 'pantheon.php',
			$mu . 'sg-cachepress-mu.php',
			$mu . 'wp-stack-cache.php',
			$mu . 'lws-optimize',
			$mu . 'o2switch-wp-tools.php',
		);
		return apply_filters( 'wpmig_host_specific_paths', $paths );
	}

	/**
	 * WP_CONTENT_DIR relative to ABSPATH (or "wp-content" when it lives elsewhere).
	 *
	 * @return string
	 */
	public static function content_rel() {
		$root    = self::normalize( ABSPATH );
		$content = self::normalize( WP_CONTENT_DIR );
		if ( 0 === strpos( $content . '/', $root . '/' ) ) {
			return ltrim( substr( $content, strlen( $root ) ), '/' );
		}
		return 'wp-content';
	}

	/**
	 * Is WP_CONTENT_DIR outside of ABSPATH?
	 *
	 * @return bool
	 */
	public static function content_relocated() {
		$root    = self::normalize( ABSPATH );
		$content = self::normalize( WP_CONTENT_DIR );
		return 0 !== strpos( $content . '/', $root . '/' );
	}

	/**
	 * Location of wp-config.php (it may live one level above ABSPATH).
	 *
	 * @return string|false
	 */
	public static function wp_config_path() {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$parent = dirname( ABSPATH ) . '/wp-config.php';
		if ( @file_exists( $parent ) && ! @file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) { // phpcs:ignore
			return $parent;
		}
		return false;
	}

	/**
	 * Files left behind by an installation in the WordPress root.
	 *
	 * @return array Absolute paths.
	 */
	public static function leftover_install_files() {
		$root  = self::normalize( ABSPATH );
		$found = array();
		foreach ( (array) @scandir( $root ) as $item ) { // phpcs:ignore
			if ( 'installer.php' === $item
				|| preg_match( '/_installer\.php$/', $item )
				|| preg_match( '/\.wpmig$/', $item )
				|| 0 === strpos( $item, 'wpmig-installer-data' )
				// wp-config backups of versions 1.0 / 1.1, readable over HTTP.
				|| 0 === strpos( $item, 'wp-config.php.wpmig-backup-' ) ) {
				$found[] = $root . '/' . $item;
			}
		}
		return $found;
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

if ( ! function_exists( 'wpmig_size' ) ) {
	/**
	 * Human readable size (size_format() returns false for 0.0).
	 *
	 * @param float $bytes Bytes.
	 * @return string
	 */
	function wpmig_size( $bytes ) {
		return $bytes > 0 ? size_format( $bytes, 1 ) : '0 o';
	}
}
