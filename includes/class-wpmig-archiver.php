<?php
/**
 * Resumable archive builder: manifest + SQL dump + files.
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Archive builder.
 */
class WPMIG_Archiver {

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
	 * Package manifest (read by the installer).
	 *
	 * @return array
	 */
	public function manifest() {
		global $wpdb, $wp_version, $required_php_version, $required_mysql_version, $wp_db_version;
		$data    = $this->package->data;
		$uploads = wp_upload_dir( null, false );
		$config  = WPMIG_Plugin::wp_config_path();
		$tables  = array();
		foreach ( $data['report']['db']['tables'] as $t ) {
			$tables[] = array(
				'name' => $t['name'],
				'rows' => $t['rows'],
			);
		}
		return array(
			'format'      => 1,
			'generator'   => 'WP Migration ' . WPMIG_VERSION,
			'package'     => $data['id'],
			'name'        => $data['name'],
			'created'     => gmdate( 'Y-m-d H:i:s' ),
			'db_only'     => ! empty( $data['options']['db_only'] ),
			'site'        => array(
				'home'                => untrailingslashit( get_option( 'home' ) ),
				'siteurl'             => untrailingslashit( get_option( 'siteurl' ) ),
				'abspath'             => WPMIG_Plugin::normalize( ABSPATH ),
				'content_dir'         => WPMIG_Plugin::normalize( WP_CONTENT_DIR ),
				'content_url'         => untrailingslashit( content_url() ),
				'content_rel'         => WPMIG_Plugin::content_rel(),
				'content_relocated'   => WPMIG_Plugin::content_relocated(),
				'uploads_dir'         => WPMIG_Plugin::normalize( $uploads['basedir'] ),
				'uploads_url'         => untrailingslashit( $uploads['baseurl'] ),
				'blogname'            => get_option( 'blogname' ),
				'admin_email'         => get_option( 'admin_email' ),
				'permalink_structure' => get_option( 'permalink_structure' ),
				'locale'              => get_locale(),
				'wp_version'          => $wp_version,
				'wp_db_version'       => $wp_db_version,
				'required_php'        => isset( $required_php_version ) ? $required_php_version : '5.6',
				'required_mysql'      => isset( $required_mysql_version ) ? $required_mysql_version : '5.0',
				'php_version'         => PHP_VERSION,
				'db_version'          => $wpdb->get_var( 'SELECT VERSION()' ),
				'server'              => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
				'table_prefix'        => $wpdb->prefix,
				'db_charset'          => ! empty( $wpdb->charset ) ? $wpdb->charset : 'utf8',
				'db_collate'          => $wpdb->collate,
				'multisite'           => is_multisite(),
			),
			'tables'      => $tables,
			'wp_config'   => ( $config && is_readable( $config ) ) ? base64_encode( (string) file_get_contents( $config ) ) : '', // phpcs:ignore
			'stats'       => array(
				'files' => isset( $data['scan']['count'] ) ? $data['scan']['count'] : 0,
				'size'  => isset( $data['scan']['size'] ) ? $data['scan']['size'] : 0,
			),
		);
	}

	/**
	 * Run until $deadline.
	 *
	 * @param float $deadline Microtime or 0.
	 * @return bool True when finished.
	 * @throws WPMIG_Exception On error.
	 */
	public function run( $deadline ) {
		$data     = &$this->package->data;
		$state    = &$data['archive'];
		$compress = ! empty( $data['options']['compress'] );
		$archive  = $this->package->archive_path();

		if ( empty( $state ) ) {
			$state = array(
				'offset'      => 0,
				'stage'       => 'manifest',
				'list_offset' => 0,
				'current'     => null,
				'count'       => 0,
				'bytes'       => 0,
			);
			@unlink( $archive ); // phpcs:ignore
		}

		$writer = new WPMIG_Archive_Writer( $archive, $state['offset'] );
		$roots  = WPMIG_Scanner::roots();
		$total  = max( 1, isset( $data['scan']['size'] ) ? $data['scan']['size'] : 1 );

		if ( 'manifest' === $state['stage'] ) {
			$writer->add_string( WPMIG_Archive::META_DIR . '/manifest.json', wp_json_encode( $this->manifest() ), $compress );
			$state['offset']  = $writer->tell();
			$state['stage']   = 'files';
			$state['current'] = array(
				'src'  => WPMIG_DB_Exporter::dump_file( $this->package ),
				'path' => WPMIG_Archive::META_DIR . '/database.sql',
				'pos'  => 0,
				'head' => false,
			);
			$this->package->save();
		}

		$list = @fopen( WPMIG_Scanner::list_file( $this->package ), 'rb' ); // phpcs:ignore
		if ( ! $list && empty( $data['options']['db_only'] ) ) {
			throw new WPMIG_Exception( 'Liste des fichiers introuvable.' );
		}

		while ( 'files' === $state['stage'] ) {
			if ( $deadline && microtime( true ) >= $deadline ) {
				break;
			}
			if ( null === $state['current'] ) {
				// Next line of the list.
				$line = false;
				if ( $list ) {
					fseek( $list, $state['list_offset'] );
					$line = fgets( $list );
				}
				if ( false === $line ) {
					$state['stage'] = 'trailer';
					break;
				}
				$state['list_offset'] = ftell( $list );
				$entry                = WPMIG_Scanner::decode_line( $line );
				if ( ! $entry || ! isset( $roots[ $entry[1] ] ) ) {
					continue;
				}
				$src   = $roots[ $entry[1] ] . '/' . $entry[3];
				$apath = WPMIG_Scanner::archive_path( $entry[1], $entry[3] );
				if ( 'd' === $entry[0] ) {
					$writer->begin_entry( $apath, 'd', (int) @filemtime( $src ), 0755 ); // phpcs:ignore
					$state['offset'] = $writer->tell();
					continue;
				}
				$state['current'] = array(
					'src'  => $src,
					'path' => $apath,
					'pos'  => 0,
					'head' => false,
				);
			}
			$this->archive_current( $writer, $state, $deadline, $compress );
			$data['progress'] = 30 + (int) ( 68 * min( 1, $state['bytes'] / $total ) );
			$data['message']  = sprintf( 'Création de l\'archive… %s fichiers, %s', number_format_i18n( $state['count'] ), size_format( $state['bytes'], 1 ) );
			$this->package->maybe_save();
		}
		if ( $list ) {
			fclose( $list );
		}

		if ( 'trailer' === $state['stage'] ) {
			$writer->finish(
				array(
					'package' => $data['id'],
					'files'   => $state['count'],
					'bytes'   => $state['bytes'],
					'created' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			$state['offset'] = $writer->tell();
			$state['stage']  = 'done';
			$this->package->log( sprintf( 'Archive terminée : %d fichiers, %s.', $state['count'], size_format( $state['offset'], 1 ) ) );
		}
		$writer->close();
		return 'done' === $state['stage'];
	}

	/**
	 * Add (part of) the current file to the archive.
	 *
	 * @param WPMIG_Archive_Writer $writer   Writer.
	 * @param array                $state    Archive state (by reference).
	 * @param float                $deadline Microtime or 0.
	 * @param bool                 $compress Compression enabled.
	 */
	private function archive_current( WPMIG_Archive_Writer $writer, array &$state, $deadline, $compress ) {
		$cur = &$state['current'];
		$fh  = @fopen( $cur['src'], 'rb' ); // phpcs:ignore
		if ( ! $fh ) {
			if ( $cur['head'] ) {
				$writer->end_entry();
				$this->package->warn( 'Fichier devenu illisible pendant l\'archivage : ' . $cur['path'] );
			} else {
				$this->package->warn( 'Fichier illisible, ignoré : ' . $cur['path'] );
			}
			$state['offset']  = $writer->tell();
			$state['current'] = null;
			return;
		}
		if ( ! $cur['head'] ) {
			$writer->begin_entry( $cur['path'], 'f', (int) @filemtime( $cur['src'] ), (int) @fileperms( $cur['src'] ) ); // phpcs:ignore
			$cur['head'] = true;
		}
		if ( $cur['pos'] > 0 ) {
			fseek( $fh, $cur['pos'] );
		}
		$compress = $compress && WPMIG_Archive::should_compress( $cur['path'] );
		while ( true ) {
			$data = fread( $fh, WPMIG_Archive::BLOCK_SIZE );
			if ( false === $data || '' === $data ) {
				break;
			}
			$writer->write_block( $data, $compress );
			$cur['pos']      += strlen( $data );
			$state['bytes']  += strlen( $data );
			$state['offset']  = $writer->tell();
			if ( $deadline && microtime( true ) >= $deadline ) {
				fclose( $fh );
				return; // Resume this file on the next request.
			}
		}
		fclose( $fh );
		$writer->end_entry();
		$state['offset']  = $writer->tell();
		$state['current'] = null;
		$state['count']++;
	}
}
