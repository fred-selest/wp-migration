<?php
/**
 * Resumable file scanner: builds the list of files to archive.
 *
 * List file format (one entry per line): type \t root \t size \t escaped relative path
 *   type: f (file) | d (directory), root: r (ABSPATH) | c (relocated WP_CONTENT_DIR).
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File scanner.
 */
class WPMIG_Scanner {

	const LARGE_FILE = 52428800;

	/**
	 * Package.
	 *
	 * @var WPMIG_Package
	 */
	private $package;

	/**
	 * Constructor.
	 *
	 * @param WPMIG_Package $package Package.
	 */
	public function __construct( WPMIG_Package $package ) {
		$this->package = $package;
	}

	/**
	 * Path of the list file.
	 *
	 * @param WPMIG_Package $package Package.
	 * @return string
	 */
	public static function list_file( WPMIG_Package $package ) {
		return $package->work_dir() . 'files.txt';
	}

	/**
	 * Encode a path for the list file.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function encode_path( $path ) {
		return addcslashes( $path, "\\\t\n\r" );
	}

	/**
	 * Decode a line of the list file.
	 *
	 * @param string $line Line.
	 * @return array|null array( type, root, size, path ).
	 */
	public static function decode_line( $line ) {
		$parts = explode( "\t", rtrim( $line, "\r\n" ), 4 );
		if ( 4 !== count( $parts ) ) {
			return null;
		}
		$parts[2] = (float) $parts[2];
		$parts[3] = stripcslashes( $parts[3] );
		return $parts;
	}

	/**
	 * Scan roots.
	 *
	 * @return array root key => absolute path.
	 */
	public static function roots() {
		$roots = array( 'r' => WPMIG_Plugin::normalize( ABSPATH ) );
		if ( WPMIG_Plugin::content_relocated() ) {
			$roots['c'] = WPMIG_Plugin::normalize( WP_CONTENT_DIR );
		}
		return $roots;
	}

	/**
	 * Path of an entry inside the archive.
	 *
	 * @param string $root Root key.
	 * @param string $rel  Relative path.
	 * @return string
	 */
	public static function archive_path( $root, $rel ) {
		return 'c' === $root ? 'wp-content' . ( '' === $rel ? '' : '/' . $rel ) : $rel;
	}

	/**
	 * Excluded paths (archive paths) and the reason.
	 *
	 * @return array
	 */
	private function exclusions() {
		$opts     = $this->package->data['options'];
		$excluded = array();
		foreach ( WPMIG_Plugin::default_excluded_dirs() as $dir ) {
			$excluded[ $dir ] = 'cache / sauvegarde';
		}
		if ( ! empty( $opts['exclude_host_files'] ) ) {
			foreach ( WPMIG_Plugin::host_specific_paths() as $path ) {
				$excluded[ $path ] = 'spécifique à l\'hébergeur';
			}
		}
		foreach ( $opts['exclude_dirs'] as $dir ) {
			$excluded[ $dir ] = 'exclusion personnalisée';
		}
		if ( ! empty( $opts['exclude_uploads'] ) ) {
			$uploads = wp_upload_dir( null, false );
			$rel     = $this->to_archive_path( $uploads['basedir'] );
			if ( $rel ) {
				$excluded[ $rel ] = 'médias exclus';
			}
		}
		// Our own storage, whatever its location.
		$storage = $this->to_archive_path( WPMIG_Plugin::storage_dir() );
		if ( $storage ) {
			$excluded[ $storage ] = 'packages WP Migration';
		}
		// wp-config.php is stored in the package manifest and rewritten by the installer.
		$excluded['wp-config.php'] = 'régénéré par l\'installeur';
		if ( WPMIG_Plugin::content_relocated() ) {
			// A stale wp-content folder inside ABSPATH would collide with the relocated one.
			$excluded['wp-content'] = 'wp-content déplacé';
		}
		return $excluded;
	}

	/**
	 * Convert an absolute path to an archive path (false if outside the roots).
	 *
	 * @param string $abs Absolute path.
	 * @return string|false
	 */
	private function to_archive_path( $abs ) {
		$abs = WPMIG_Plugin::normalize( $abs );
		foreach ( self::roots() as $key => $root ) {
			if ( 0 === strpos( $abs . '/', $root . '/' ) ) {
				return self::archive_path( $key, ltrim( substr( $abs, strlen( $root ) ), '/' ) );
			}
		}
		return false;
	}

	/**
	 * Run the scan until $deadline.
	 *
	 * @param float $deadline Microtime or 0.
	 * @return bool True when finished.
	 * @throws WPMIG_Exception On error.
	 */
	public function run( $deadline ) {
		$data = &$this->package->data;
		$scan = &$data['scan'];
		$list = self::list_file( $this->package );
		if ( empty( $scan ) ) {
			$scan = array(
				'queue'      => array(),
				'count'      => 0,
				'dirs'       => 0,
				'size'       => 0,
				'large'      => array(),
				'unreadable' => array(),
				'excluded'   => array(),
				'offset'     => 0,
			);
			if ( empty( $data['options']['db_only'] ) ) {
				foreach ( self::roots() as $key => $root ) {
					$scan['queue'][] = array( $key, '' );
				}
			}
			file_put_contents( $list, '' );
		}
		$exclusions = $this->exclusions();
		$opts       = $data['options'];
		$exts       = array_flip( $opts['exclude_ext'] );
		$roots      = self::roots();
		$vcs        = ! empty( $opts['exclude_vcs'] ) ? array_flip( array( '.git', '.svn', '.hg', 'node_modules' ) ) : array();
		$skip_names = array_flip( array( 'error_log', 'php_errorlog', '.DS_Store', 'Thumbs.db' ) );

		$fh = fopen( $list, 'c+b' );
		if ( ! $fh ) {
			throw new WPMIG_Exception( 'Impossible d\'écrire la liste des fichiers.' );
		}
		ftruncate( $fh, $scan['offset'] );
		fseek( $fh, 0, SEEK_END );

		$root_excluded_files = array_flip( array( 'installer.php' ) );

		while ( ! empty( $scan['queue'] ) ) {
			if ( $deadline && microtime( true ) >= $deadline ) {
				break;
			}
			list( $key, $rel ) = array_pop( $scan['queue'] );
			$dir   = $roots[ $key ] . ( '' === $rel ? '' : '/' . $rel );
			$items = @scandir( $dir ); // phpcs:ignore
			if ( false === $items ) {
				$scan['unreadable'][] = self::archive_path( $key, $rel ) . '/';
				continue;
			}
			$buffer = '';
			foreach ( $items as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$child_rel = '' === $rel ? $name : $rel . '/' . $name;
				$abs       = $dir . '/' . $name;
				$apath     = self::archive_path( $key, $child_rel );
				if ( isset( $exclusions[ $apath ] ) ) {
					if ( count( $scan['excluded'] ) < 200 ) {
						$scan['excluded'][] = $apath . ' (' . $exclusions[ $apath ] . ')';
					}
					continue;
				}
				$is_link = is_link( $abs );
				if ( is_dir( $abs ) ) {
					if ( isset( $vcs[ $name ] ) ) {
						continue;
					}
					if ( $is_link ) {
						$this->package->warn( 'Lien symbolique vers un dossier ignoré : ' . $apath );
						continue;
					}
					if ( '' === $rel && 'r' === $key && 0 === strpos( $name, 'wpmig-installer-data' ) ) {
						continue;
					}
					$scan['queue'][] = array( $key, $child_rel );
					$scan['dirs']++;
					$buffer .= "d\t" . $key . "\t0\t" . self::encode_path( $child_rel ) . "\n";
					continue;
				}
				if ( ! is_file( $abs ) ) {
					continue; // Broken link, socket, fifo...
				}
				if ( isset( $skip_names[ $name ] ) ) {
					continue;
				}
				if ( '' === $rel && 'r' === $key && ( isset( $root_excluded_files[ $name ] ) || preg_match( '/(_installer\.php|\.wpmig)$/', $name ) ) ) {
					continue;
				}
				$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
				if ( '' !== $ext && isset( $exts[ $ext ] ) ) {
					continue;
				}
				if ( ! is_readable( $abs ) ) {
					$scan['unreadable'][] = $apath;
					continue;
				}
				$size = (float) sprintf( '%u', @filesize( $abs ) ); // phpcs:ignore
				$scan['count']++;
				$scan['size'] += $size;
				if ( $size >= self::LARGE_FILE ) {
					$scan['large'][] = array( $apath, $size );
				}
				$buffer .= "f\t" . $key . "\t" . $size . "\t" . self::encode_path( $child_rel ) . "\n";
			}
			if ( '' !== $buffer && false === fwrite( $fh, $buffer ) ) {
				fclose( $fh );
				throw new WPMIG_Exception( 'Erreur d\'écriture de la liste des fichiers (espace disque ?).' );
			}
			fflush( $fh );
			$scan['offset'] = ftell( $fh );
			$data['message'] = sprintf( 'Analyse des fichiers… %s fichiers (%s)', number_format_i18n( $scan['count'] ), size_format( $scan['size'], 1 ) );
			$this->package->maybe_save();
		}
		fclose( $fh );

		if ( ! empty( $scan['queue'] ) ) {
			return false;
		}
		usort(
			$scan['large'],
			function ( $a, $b ) {
				return $b[1] > $a[1] ? 1 : ( $b[1] < $a[1] ? -1 : 0 );
			}
		);
		$scan['large'] = array_slice( $scan['large'], 0, 20 );
		foreach ( array_slice( $scan['unreadable'], 0, 50 ) as $path ) {
			$this->package->warn( 'Illisible (ignoré) : ' . $path );
		}
		$this->package->log( sprintf( 'Fichiers : %d fichiers, %d dossiers, %s.', $scan['count'], $scan['dirs'], size_format( $scan['size'], 1 ) ) );
		return true;
	}
}
