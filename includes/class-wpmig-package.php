<?php
/**
 * Package model and build state machine.
 *
 * Phases: scan -> scan_db -> (scanned: waits for confirmation) -> dump -> archive -> installer -> complete.
 * Every phase is resumable: the state is saved in a JSON file so the build can be
 * spread over many short HTTP requests (shared hosting friendly) or run in one go (WP-CLI).
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Package.
 */
class WPMIG_Package {

	/**
	 * Package data (persisted).
	 *
	 * @var array
	 */
	public $data = array();

	/**
	 * Last save timestamp.
	 *
	 * @var float
	 */
	private $last_save = 0;

	/**
	 * Default options.
	 *
	 * @return array
	 */
	public static function default_options() {
		return array(
			'name'               => '',
			'db_only'            => false,
			'exclude_dirs'       => array(),
			'exclude_ext'        => array(),
			'exclude_tables'     => array(),
			'exclude_uploads'    => false,
			'exclude_host_files' => true,
			'exclude_vcs'        => true,
			'skip_transients'    => true,
			'skip_spam'          => true,
			'skip_revisions'     => false,
			'compress'           => true,
			'password'           => '',
		);
	}

	/**
	 * Sanitize user options.
	 *
	 * @param array $input Raw options.
	 * @return array
	 */
	public static function sanitize_options( array $input ) {
		$opts = self::default_options();
		foreach ( array( 'db_only', 'exclude_uploads', 'exclude_host_files', 'exclude_vcs', 'skip_transients', 'skip_spam', 'skip_revisions', 'compress' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$opts[ $key ] = in_array( $input[ $key ], array( true, 1, '1', 'true', 'on', 'yes' ), true );
			}
		}
		$name         = isset( $input['name'] ) ? (string) $input['name'] : '';
		$name         = strtolower( preg_replace( '/[^A-Za-z0-9_\-]+/', '-', remove_accents( $name ) ) );
		$name         = trim( substr( $name, 0, 40 ), '-' );
		$opts['name'] = '' !== $name ? $name : self::default_name();

		foreach ( array( 'exclude_dirs', 'exclude_ext', 'exclude_tables' ) as $key ) {
			$list = isset( $input[ $key ] ) ? $input[ $key ] : array();
			if ( is_string( $list ) ) {
				$list = preg_split( '/[\r\n,;]+/', $list );
			}
			$clean = array();
			foreach ( (array) $list as $item ) {
				$item = trim( (string) $item );
				if ( 'exclude_dirs' === $key ) {
					$item = WPMIG_Plugin::normalize( $item );
					$root = WPMIG_Plugin::normalize( ABSPATH );
					if ( 0 === strpos( $item . '/', $root . '/' ) ) {
						$item = substr( $item, strlen( $root ) );
					}
					$item = trim( $item, '/' );
					if ( false !== strpos( '/' . $item . '/', '/../' ) ) {
						continue;
					}
				} elseif ( 'exclude_ext' === $key ) {
					$item = strtolower( ltrim( $item, '.* ' ) );
				}
				if ( '' !== $item ) {
					$clean[] = $item;
				}
			}
			$opts[ $key ] = array_values( array_unique( $clean ) );
		}
		$opts['password'] = isset( $input['password'] ) ? (string) $input['password'] : '';
		return $opts;
	}

	/**
	 * Default package name from the site host.
	 *
	 * @return string
	 */
	public static function default_name() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$name = strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $host ) );
		return trim( '' !== $name ? $name : 'site', '-' );
	}

	/**
	 * Create a new package.
	 *
	 * @param array $options Options.
	 * @return WPMIG_Package
	 * @throws WPMIG_Exception On error.
	 */
	public static function create( array $options ) {
		if ( is_multisite() ) {
			throw new WPMIG_Exception( 'Les installations multisite ne sont pas prises en charge.' );
		}
		$options = self::sanitize_options( $options );
		$id      = gmdate( 'Ymd_His' ) . '_' . self::random_hex( 12 );
		// Only a salted hash of the installer password is kept.
		$options['password_salt'] = '';
		$options['password_hash'] = '';
		if ( '' !== $options['password'] ) {
			$options['password_salt'] = self::random_hex( 16 );
			$options['password_hash'] = hash( 'sha256', $options['password_salt'] . $options['password'] );
			$options['password']      = '(défini)';
		}
		$package = new self();

		$package->data = array(
			'id'        => $id,
			'name'      => $options['name'],
			'created'   => time(),
			'status'    => 'scanning',
			'phase'     => 'scan',
			'options'   => $options,
			'error'     => '',
			'warnings'  => array(),
			'log'       => array(),
			'scan'      => array(),
			'dump'      => array(),
			'archive'   => array(),
			'report'    => array(),
			'progress'  => 0,
			'message'   => 'Analyse des fichiers…',
			'files'     => array(
				'archive'   => $options['name'] . '_' . $id . '_archive.wpmig',
				'installer' => $options['name'] . '_' . $id . '_installer.php',
			),
			'sizes'     => array(),
		);
		if ( ! wp_mkdir_p( $package->work_dir() ) ) {
			throw new WPMIG_Exception( 'Impossible de créer le dossier de travail du package.' );
		}
		WPMIG_Plugin::protect_dir( $package->work_dir() );
		$package->log( 'Package créé (WP Migration ' . WPMIG_VERSION . ', WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION . ').' );
		$package->save();
		return $package;
	}

	/**
	 * Random hex string.
	 *
	 * @param int $len Length.
	 * @return string
	 */
	public static function random_hex( $len ) {
		if ( function_exists( 'random_bytes' ) ) {
			return substr( bin2hex( random_bytes( (int) ceil( $len / 2 ) ) ), 0, $len );
		}
		return substr( md5( wp_generate_password( 32, true, true ) . microtime() ), 0, $len );
	}

	/**
	 * Load a package.
	 *
	 * @param string $id Package id.
	 * @return WPMIG_Package|null
	 */
	public static function load( $id ) {
		if ( ! preg_match( '/^\d{8}_\d{6}_[a-f0-9]{12}$/', (string) $id ) ) {
			return null;
		}
		$file = WPMIG_Plugin::storage_dir() . $id . '.json';
		if ( ! is_file( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$package       = new self();
		$package->data = $data;
		return $package;
	}

	/**
	 * All packages, newest first.
	 *
	 * @return WPMIG_Package[]
	 */
	public static function all() {
		$packages = array();
		foreach ( (array) glob( WPMIG_Plugin::storage_dir() . '*.json' ) as $file ) {
			$package = self::load( basename( $file, '.json' ) );
			if ( $package ) {
				$packages[] = $package;
			}
		}
		usort(
			$packages,
			function ( $a, $b ) {
				return $b->data['created'] - $a->data['created'];
			}
		);
		return $packages;
	}

	/**
	 * Persist.
	 */
	public function save() {
		$file = WPMIG_Plugin::storage_dir() . $this->data['id'] . '.json';
		$json = wp_json_encode( $this->data );
		if ( false === $json ) {
			// Invalid UTF-8 somewhere in logs / warnings (file names): sanitize and retry.
			$this->data['warnings'] = array_map( array( __CLASS__, 'utf8' ), $this->data['warnings'] );
			$this->data['log']      = array_map( array( __CLASS__, 'utf8' ), $this->data['log'] );
			$json                   = wp_json_encode( $this->data );
		}
		$tmp = $file . '.tmp';
		if ( false === @file_put_contents( $tmp, $json ) || ! @rename( $tmp, $file ) ) { // phpcs:ignore
			@file_put_contents( $file, $json ); // phpcs:ignore
		}
		$this->last_save = microtime( true );
	}

	/**
	 * Save if the last save is older than 2 seconds.
	 */
	public function maybe_save() {
		if ( microtime( true ) - $this->last_save > 2 ) {
			$this->save();
		}
	}

	/**
	 * Make a string valid UTF-8.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function utf8( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
		}
		return wp_check_invalid_utf8( $value, true );
	}

	/**
	 * Work directory.
	 *
	 * @return string
	 */
	public function work_dir() {
		return WPMIG_Plugin::storage_dir() . $this->data['id'] . '/';
	}

	/**
	 * Archive path.
	 *
	 * @return string
	 */
	public function archive_path() {
		return WPMIG_Plugin::storage_dir() . $this->data['files']['archive'];
	}

	/**
	 * Installer path.
	 *
	 * @return string
	 */
	public function installer_path() {
		return WPMIG_Plugin::storage_dir() . $this->data['files']['installer'];
	}

	/**
	 * Add a log line.
	 *
	 * @param string $message Message.
	 */
	public function log( $message ) {
		$this->data['log'][] = gmdate( 'H:i:s' ) . ' ' . $message;
		if ( count( $this->data['log'] ) > 500 ) {
			$this->data['log'] = array_slice( $this->data['log'], -500 );
		}
	}

	/**
	 * Add a warning shown in the report.
	 *
	 * @param string $message Message.
	 */
	public function warn( $message ) {
		if ( count( $this->data['warnings'] ) < 200 ) {
			$this->data['warnings'][] = $message;
		}
		$this->log( 'AVERTISSEMENT : ' . $message );
	}

	/**
	 * Delete the package and all its files.
	 */
	public function delete() {
		WPMIG_Plugin::rrmdir( rtrim( $this->work_dir(), '/' ) );
		@unlink( $this->archive_path() ); // phpcs:ignore
		@unlink( $this->installer_path() ); // phpcs:ignore
		@unlink( WPMIG_Plugin::storage_dir() . $this->data['id'] . '.json' ); // phpcs:ignore
		@unlink( WPMIG_Plugin::storage_dir() . $this->data['id'] . '.lock' ); // phpcs:ignore
	}

	/**
	 * Start the build once the scan has been reviewed.
	 *
	 * @throws WPMIG_Exception When the package is not ready.
	 */
	public function start_build() {
		if ( 'scanned' !== $this->data['status'] ) {
			throw new WPMIG_Exception( 'Le package n\'est pas prêt à être construit.' );
		}
		$this->data['status']  = 'building';
		$this->data['phase']   = 'dump';
		$this->data['message'] = 'Export de la base de données…';
		$this->log( 'Construction démarrée.' );
		$this->save();
	}

	/**
	 * Run the state machine for $budget seconds (0 = until the end of the current stage).
	 *
	 * @param float $budget Seconds.
	 * @return array Public state.
	 */
	public function step( $budget ) {
		// A previous request may still be running (proxy timeout + retry): never run two steps at once.
		$lock = @fopen( WPMIG_Plugin::storage_dir() . $this->data['id'] . '.lock', 'c' ); // phpcs:ignore
		if ( $lock && ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			fclose( $lock );
			$state         = $this->public_state();
			$state['busy'] = true;
			return $state;
		}
		// Reload the state saved by the previous request.
		$fresh = self::load( $this->data['id'] );
		if ( $fresh ) {
			$this->data = $fresh->data;
		}
		$deadline = $budget > 0 ? microtime( true ) + $budget : 0;
		try {
			while ( in_array( $this->data['status'], array( 'scanning', 'building' ), true ) ) {
				switch ( $this->data['phase'] ) {
					case 'scan':
						$scanner = new WPMIG_Scanner( $this );
						if ( $scanner->run( $deadline ) ) {
							$this->data['phase'] = 'scan_db';
						}
						break;

					case 'scan_db':
						WPMIG_DB_Exporter::scan( $this );
						$this->build_report();
						$this->data['status']   = 'scanned';
						$this->data['phase']    = 'scanned';
						$this->data['progress'] = 100;
						$this->data['message']  = 'Analyse terminée.';
						$this->log( 'Analyse terminée.' );
						break;

					case 'dump':
						$exporter = new WPMIG_DB_Exporter( $this );
						if ( $exporter->run( $deadline ) ) {
							$this->data['phase'] = 'archive';
						}
						break;

					case 'archive':
						$archiver = new WPMIG_Archiver( $this );
						if ( $archiver->run( $deadline ) ) {
							$this->data['phase'] = 'installer';
						}
						break;

					case 'installer':
						WPMIG_Installer_Builder::build( $this );
						$this->finish();
						break;

					default:
						throw new WPMIG_Exception( 'Phase inconnue : ' . $this->data['phase'] );
				}
				$this->save();
				if ( $deadline && microtime( true ) >= $deadline ) {
					break;
				}
			}
		} catch ( Exception $e ) {
			$this->fail( $e->getMessage() );
		}
		if ( $lock ) {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
		return $this->public_state();
	}

	/**
	 * Mark the package as failed.
	 *
	 * @param string $message Error.
	 */
	public function fail( $message ) {
		$this->data['status'] = 'error';
		$this->data['error']  = $message;
		$this->log( 'ERREUR : ' . $message );
		$this->save();
	}

	/**
	 * Build completed.
	 */
	private function finish() {
		$this->data['status']   = 'complete';
		$this->data['phase']    = 'complete';
		$this->data['progress'] = 100;
		$this->data['message']  = 'Package prêt.';
		$this->data['sizes']    = array(
			'archive'   => (int) @filesize( $this->archive_path() ), // phpcs:ignore
			'installer' => (int) @filesize( $this->installer_path() ), // phpcs:ignore
		);
		$this->data['sizes']['archive_h'] = size_format( $this->data['sizes']['archive'], 1 );
		// Temporary files are no longer needed.
		WPMIG_Plugin::rrmdir( rtrim( $this->work_dir(), '/' ) );
		$this->data['scan']['queue'] = array();
		$this->log( sprintf( 'Package terminé : archive de %s.', size_format( $this->data['sizes']['archive'], 1 ) ) );
		// Saved first, so that the new package counts among those to keep.
		$this->save();
		WPMIG_Cleanup::after_build( $this );
	}

	/**
	 * Build the pre-build report (system checks, files, database).
	 */
	private function build_report() {
		global $wpdb;
		$scan   = $this->data['scan'];
		$db     = isset( $this->data['report']['db'] ) ? $this->data['report']['db'] : array();
		$checks = array();

		$checks[] = array(
			'label'  => 'Version de PHP',
			'value'  => PHP_VERSION,
			'status' => version_compare( PHP_VERSION, '5.6', '>=' ) ? 'ok' : 'error',
		);
		$checks[] = array(
			'label'  => 'Version de WordPress',
			'value'  => get_bloginfo( 'version' ),
			'status' => 'ok',
		);
		$checks[] = array(
			'label'  => 'Serveur de base de données',
			'value'  => $wpdb->get_var( 'SELECT VERSION()' ),
			'status' => 'ok',
		);
		$checks[] = array(
			'label'  => 'Compression (zlib)',
			'value'  => function_exists( 'gzdeflate' ) ? 'disponible' : 'absente : archive non compressée',
			'status' => function_exists( 'gzdeflate' ) ? 'ok' : 'warning',
		);
		$max_exec = (int) ini_get( 'max_execution_time' );
		$checks[] = array(
			'label'  => 'max_execution_time',
			'value'  => $max_exec ? $max_exec . ' s (traitement découpé en étapes)' : 'illimité',
			'status' => 'ok',
		);
		$free     = function_exists( 'disk_free_space' ) ? @disk_free_space( WPMIG_Plugin::storage_dir() ) : false; // phpcs:ignore
		$needed   = ( isset( $scan['size'] ) ? $scan['size'] : 0 ) + ( isset( $db['size'] ) ? $db['size'] : 0 );
		$checks[] = array(
			'label'  => 'Espace disque libre',
			'value'  => false === $free ? 'inconnu' : size_format( $free, 1 ) . ' (besoin estimé : ' . size_format( $needed, 1 ) . ')',
			'status' => ( false !== $free && $free < $needed ) ? 'error' : 'ok',
		);
		if ( PHP_INT_SIZE < 8 && $needed > 2000000000 ) {
			$checks[] = array(
				'label'  => 'PHP 32 bits',
				'value'  => 'Les archives de plus de 2 Go ne sont pas gérées par un PHP 32 bits.',
				'status' => 'error',
			);
		}
		$home    = untrailingslashit( get_option( 'home' ) );
		$siteurl = untrailingslashit( get_option( 'siteurl' ) );
		if ( $home !== $siteurl ) {
			$checks[] = array(
				'label'  => 'WordPress dans un sous-dossier',
				'value'  => 'home (' . $home . ') ≠ siteurl (' . $siteurl . '). Vérifiez les URL proposées par l\'installeur.',
				'status' => 'warning',
			);
		}
		if ( WPMIG_Plugin::content_relocated() ) {
			$checks[] = array(
				'label'  => 'wp-content déplacé',
				'value'  => 'WP_CONTENT_DIR est en dehors de WordPress : il sera replacé dans wp-content/ sur la destination.',
				'status' => 'warning',
			);
		}
		if ( ! WPMIG_Plugin::wp_config_path() || ! is_readable( WPMIG_Plugin::wp_config_path() ) ) {
			$checks[] = array(
				'label'  => 'wp-config.php',
				'value'  => 'Illisible : l\'installeur en générera un nouveau (les constantes personnalisées seront perdues).',
				'status' => 'warning',
			);
		}
		$this->data['report']['checks'] = $checks;
	}

	/**
	 * State returned to the browser.
	 *
	 * @return array
	 */
	public function public_state() {
		$d = $this->data;
		return array(
			'id'       => $d['id'],
			'name'     => $d['name'],
			'status'   => $d['status'],
			'phase'    => $d['phase'],
			'progress' => (int) $d['progress'],
			'message'  => $d['message'],
			'error'    => $d['error'],
			'warnings' => array_slice( $d['warnings'], 0, 100 ),
			'report'   => 'scanned' === $d['status'] || 'complete' === $d['status'] ? $this->report_for_display() : null,
			'sizes'    => $d['sizes'],
			'secured'  => ! empty( $d['options']['password_hash'] ),
			'log'      => array_slice( $d['log'], -30 ),
		);
	}

	/**
	 * Report formatted for display.
	 *
	 * @return array
	 */
	private function report_for_display() {
		$scan = $this->data['scan'];
		$db   = isset( $this->data['report']['db'] ) ? $this->data['report']['db'] : array();
		$big  = array();
		foreach ( isset( $scan['large'] ) ? $scan['large'] : array() as $item ) {
			$big[] = array(
				'path' => $item[0],
				'size' => size_format( $item[1], 1 ),
			);
		}
		$tables = array();
		foreach ( isset( $db['tables'] ) ? $db['tables'] : array() as $t ) {
			$tables[] = array(
				'name'  => $t['name'],
				'rows'  => number_format_i18n( $t['rows'] ),
				'size'  => size_format( $t['size'], 1 ),
				'warn'  => ! empty( $t['warn'] ) ? $t['warn'] : '',
			);
		}
		return array(
			'checks'     => isset( $this->data['report']['checks'] ) ? $this->data['report']['checks'] : array(),
			'files'      => array(
				'count'      => number_format_i18n( isset( $scan['count'] ) ? $scan['count'] : 0 ),
				'dirs'       => number_format_i18n( isset( $scan['dirs'] ) ? $scan['dirs'] : 0 ),
				'size'       => size_format( isset( $scan['size'] ) ? $scan['size'] : 0, 1 ),
				'large'      => $big,
				'unreadable' => isset( $scan['unreadable'] ) ? array_slice( $scan['unreadable'], 0, 50 ) : array(),
				'excluded'   => isset( $scan['excluded'] ) ? array_slice( $scan['excluded'], 0, 100 ) : array(),
				'db_only'    => ! empty( $this->data['options']['db_only'] ),
			),
			'db'         => array(
				'count'  => count( $tables ),
				'rows'   => number_format_i18n( isset( $db['rows'] ) ? $db['rows'] : 0 ),
				'size'   => size_format( isset( $db['size'] ) ? $db['size'] : 0, 1 ),
				'tables' => $tables,
			),
		);
	}
}
