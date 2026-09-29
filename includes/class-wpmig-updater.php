<?php
/**
 * Updates from the GitHub releases of the plugin.
 *
 * The "Update URI" header of the plugin keeps WordPress from looking for it on
 * wordpress.org (where another, closed plugin uses the same slug); the update is
 * provided through the update_plugins_github.com filter (WordPress 5.8+), or the
 * update_plugins transient on older versions. The package is the zip attached
 * to the latest release by the release workflow.
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin updater.
 */
class WPMIG_Updater {

	const REPO      = 'fred-selest/wp-migration';
	const CACHE     = 'wpmig_update_release';
	const TTL       = 43200;
	const TTL_ERROR = 3600;
	const SLUG      = 'wp-migration';

	/**
	 * Hooks.
	 */
	public static function init() {
		global $wp_version;
		if ( version_compare( $wp_version, '5.8', '>=' ) ) {
			add_filter( 'update_plugins_github.com', array( __CLASS__, 'update_uri' ), 10, 3 );
		} else {
			add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'transient' ) );
		}
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'source_selection' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_wpmig_check_update', array( __CLASS__, 'check_now' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
	}

	/**
	 * Plugin basename.
	 *
	 * @return string
	 */
	public static function basename() {
		return plugin_basename( WPMIG_FILE );
	}

	/**
	 * Latest release (cached).
	 *
	 * @param bool $force Ignore the cache.
	 * @return array|null array( version, tag, package, url, published, requires, tested, requires_php, changelog ).
	 */
	public static function release( $force = false ) {
		$cached = $force ? false : get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}
		$release = self::fetch();
		// A failure is cached too (GitHub limits anonymous requests to 60 per hour and per IP).
		set_site_transient( self::CACHE, $release ? $release : array( 'error' => time() ), $release ? self::TTL : self::TTL_ERROR );
		return $release;
	}

	/**
	 * Query GitHub.
	 *
	 * @return array|null
	 */
	private static function fetch() {
		$args = array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'WP-Migration/' . WPMIG_VERSION . '; ' . home_url( '/' ),
			),
		);
		$url  = apply_filters( 'wpmig_update_api_url', 'https://api.github.com/repos/' . self::REPO . '/releases?per_page=10' );
		$res  = wp_remote_get( $url, $args );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$list = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $list ) ) {
			return null;
		}
		$latest    = null;
		$changelog = array();
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) || ! empty( $item['draft'] ) || ! empty( $item['prerelease'] ) || empty( $item['tag_name'] ) ) {
				continue;
			}
			$version = ltrim( (string) $item['tag_name'], 'vV' );
			if ( ! preg_match( '/^\d+(\.\d+)*$/', $version ) ) {
				continue;
			}
			$changelog[] = array( $version, isset( $item['published_at'] ) ? (string) $item['published_at'] : '', isset( $item['body'] ) ? (string) $item['body'] : '' );
			if ( $latest ) {
				continue;
			}
			foreach ( isset( $item['assets'] ) ? (array) $item['assets'] : array() as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && preg_match( '/^wp-migration-[\d.]+\.zip$/', $asset['name'] ) ) {
					$latest = array(
						'version'      => $version,
						'tag'          => (string) $item['tag_name'],
						'package'      => (string) $asset['browser_download_url'],
						'url'          => isset( $item['html_url'] ) ? (string) $item['html_url'] : 'https://github.com/' . self::REPO,
						'published'    => isset( $item['published_at'] ) ? (string) $item['published_at'] : '',
						'size'         => isset( $asset['size'] ) ? (int) $asset['size'] : 0,
						'requires'     => '',
						'tested'       => '',
						'requires_php' => '',
					);
					break;
				}
			}
		}
		if ( ! $latest ) {
			return null;
		}
		$latest['changelog'] = array_slice( $changelog, 0, 5 );

		// Requirements of the new version, from its readme.txt.
		$readme = wp_remote_get( apply_filters( 'wpmig_update_readme_url', 'https://raw.githubusercontent.com/' . self::REPO . '/' . rawurlencode( $latest['tag'] ) . '/readme.txt', $latest['tag'] ), $args );
		if ( ! is_wp_error( $readme ) && 200 === (int) wp_remote_retrieve_response_code( $readme ) ) {
			$headers = array(
				'requires'     => 'Requires at least',
				'tested'       => 'Tested up to',
				'requires_php' => 'Requires PHP',
			);
			foreach ( $headers as $key => $label ) {
				if ( preg_match( '/^' . preg_quote( $label, '/' ) . ':\s*([\d.]+)/mi', wp_remote_retrieve_body( $readme ), $m ) ) {
					$latest[ $key ] = $m[1];
				}
			}
		}
		return $latest;
	}

	/**
	 * "Tested up to" as wordpress.org reports it: "7.1" covers 7.1.x.
	 *
	 * @param string $tested Value of the readme.
	 * @return string
	 */
	private static function tested( $tested ) {
		$wp = get_bloginfo( 'version' );
		if ( preg_match( '/^\d+\.\d+$/', $tested ) && 0 === strpos( $wp . '.', $tested . '.' ) ) {
			return $wp;
		}
		return $tested;
	}

	/**
	 * Update data in the format of the update_plugins transient.
	 *
	 * @param array $release Release.
	 * @return object
	 */
	private static function item( array $release ) {
		return (object) array(
			'id'           => 'https://github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'plugin'       => self::basename(),
			'version'      => $release['version'],
			'new_version'  => $release['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $release['package'],
			'tested'       => self::tested( $release['tested'] ),
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
			'icons'        => array(
				'1x'  => WPMIG_URL . 'assets/logo/icon-128x128.png',
				'2x'  => WPMIG_URL . 'assets/logo/icon-256x256.png',
				'svg' => WPMIG_URL . 'assets/logo/logo.svg',
			),
			'banners'      => array(),
			'banners_rtl'  => array(),
			'translations' => array(),
		);
	}

	/**
	 * WordPress 5.8+: update data for plugins whose Update URI is on github.com.
	 *
	 * @param array|false $update      Update data.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 * @return array|false
	 */
	public static function update_uri( $update, $plugin_data, $plugin_file ) {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}
		$release = self::release();
		return $release ? (array) self::item( $release ) : $update;
	}

	/**
	 * Older WordPress: add the update to the transient.
	 *
	 * @param object $transient update_plugins transient.
	 * @return object
	 */
	public static function transient( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}
		$file = self::basename();
		// Never offer the unrelated plugin of wordpress.org using the same slug.
		unset( $transient->response[ $file ], $transient->no_update[ $file ] );
		$release = self::release();
		if ( ! $release ) {
			return $transient;
		}
		$current = isset( $transient->checked[ $file ] ) ? $transient->checked[ $file ] : WPMIG_VERSION;
		if ( version_compare( $release['version'], $current, '>' ) ) {
			$transient->response[ $file ] = self::item( $release );
		} else {
			$transient->no_update[ $file ] = self::item( $release );
		}
		return $transient;
	}

	/**
	 * "View details" window.
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action Action.
	 * @param object             $args   Arguments.
	 * @return false|object|array
	 */
	public static function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::release();
		if ( ! $release ) {
			return $result;
		}
		$changelog = '';
		foreach ( $release['changelog'] as $entry ) {
			$changelog .= '<h4>' . esc_html( $entry[0] ) . ( $entry[1] ? ' <small>(' . esc_html( substr( $entry[1], 0, 10 ) ) . ')</small>' : '' ) . '</h4>' . self::markdown( $entry[2] );
		}
		return (object) array(
			'name'          => 'WP Migration',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/fred-selest">fred-selest</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => $release['requires'],
			'tested'        => self::tested( $release['tested'] ),
			'requires_php'  => $release['requires_php'],
			'last_updated'  => $release['published'] ? gmdate( 'Y-m-d H:i:s', strtotime( $release['published'] ) ) . ' GMT' : '',
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => '<p>Copie un site WordPress complet (fichiers + base de données) vers un nouveau domaine et/ou un nouvel hébergement, avec un installeur autonome, un transfert direct de serveur à serveur et un rapport de migration vérifié.</p><p><a href="https://github.com/' . self::REPO . '#readme">Documentation</a></p>',
				'changelog'     => $changelog,
			),
			'banners'       => array(),
		);
	}

	/**
	 * Minimal Markdown (release notes) to HTML.
	 *
	 * @param string $text Markdown.
	 * @return string HTML.
	 */
	public static function markdown( $text ) {
		$html = '';
		$list = false;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line   = rtrim( $line );
			$inline = function ( $s ) {
				$s = esc_html( $s );
				$s = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $s );
				return preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s );
			};
			if ( preg_match( '/^\s*[-*]\s+(.*)$/', $line, $m ) ) {
				$html .= ( $list ? '' : '<ul>' ) . '<li>' . $inline( $m[1] ) . '</li>';
				$list  = true;
				continue;
			}
			if ( $list ) {
				$html .= '</ul>';
				$list  = false;
			}
			if ( preg_match( '/^#{1,6}\s+(.*)$/', $line, $m ) ) {
				$html .= '<h5>' . $inline( $m[1] ) . '</h5>';
			} elseif ( '' !== trim( $line ) ) {
				$html .= '<p>' . $inline( $line ) . '</p>';
			}
		}
		return $html . ( $list ? '</ul>' : '' );
	}

	/**
	 * Keep the plugin folder name when the zip root folder differs from it.
	 *
	 * @param string      $source        Extracted folder.
	 * @param string      $remote_source Parent folder.
	 * @param WP_Upgrader $upgrader      Upgrader.
	 * @param array       $hook_extra    Extra data.
	 * @return string|WP_Error
	 */
	public static function source_selection( $source, $remote_source, $upgrader = null, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] || ! $wp_filesystem ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . dirname( self::basename() ) . '/';
		if ( trailingslashit( $source ) === $wanted || ! $wp_filesystem->exists( trailingslashit( $source ) . basename( WPMIG_FILE ) ) ) {
			return $source;
		}
		return $wp_filesystem->move( $source, $wanted, true ) ? $wanted : $source;
	}

	/**
	 * "Check for updates" link on the Plugins screen.
	 *
	 * @param array  $links Links.
	 * @param string $file  Plugin basename.
	 * @return array
	 */
	public static function row_meta( $links, $file ) {
		if ( self::basename() === $file && current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpmig_check_update' ), 'wpmig_check_update' ) ) . '">Vérifier les mises à jour</a>';
		}
		return $links;
	}

	/**
	 * Check now (ignores the caches).
	 */
	public static function check_now() {
		if ( ! current_user_can( 'update_plugins' ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wpmig_check_update' ) ) {
			wp_die( 'Accès refusé.', 403 );
		}
		$release = self::release( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		if ( ! $release ) {
			$result = 'error';
		} else {
			$result = version_compare( $release['version'], WPMIG_VERSION, '>' ) ? 'available' : 'latest';
		}
		wp_safe_redirect( add_query_arg( 'wpmig_update', $result, admin_url( 'plugins.php' ) ) );
		exit;
	}

	/**
	 * Result of a manual check.
	 */
	public static function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification -- display only.
		$result = isset( $_GET['wpmig_update'] ) ? sanitize_key( $_GET['wpmig_update'] ) : '';
		if ( '' === $result || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$release = self::release();
		if ( 'available' === $result && $release ) {
			echo '<div class="notice notice-warning is-dismissible"><p><strong>WP Migration :</strong> la version ' . esc_html( $release['version'] ) . ' est disponible (version installée : ' . esc_html( WPMIG_VERSION ) . ').</p></div>';
		} elseif ( 'latest' === $result ) {
			echo '<div class="notice notice-success is-dismissible"><p><strong>WP Migration :</strong> la version installée (' . esc_html( WPMIG_VERSION ) . ') est la plus récente.</p></div>';
		} elseif ( 'error' === $result ) {
			echo '<div class="notice notice-error is-dismissible"><p><strong>WP Migration :</strong> impossible de joindre GitHub pour vérifier les mises à jour. Réessayez plus tard.</p></div>';
		}
	}

	/**
	 * Forget the cached release after an update.
	 */
	public static function flush() {
		delete_site_transient( self::CACHE );
	}
}
