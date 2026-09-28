<?php
/**
 * WP-CLI commands.
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and manages migration packages (archive + installer.php).
 */
class WPMIG_CLI {

	/**
	 * Build a package (archive + installer.php).
	 *
	 * ## OPTIONS
	 *
	 * [--name=<name>]
	 * : Package name (default: site host).
	 *
	 * [--db-only]
	 * : Only export the database.
	 *
	 * [--exclude-dirs=<dirs>]
	 * : Comma separated directories to exclude, relative to the WordPress root.
	 *
	 * [--exclude-ext=<ext>]
	 * : Comma separated file extensions to exclude.
	 *
	 * [--exclude-tables=<tables>]
	 * : Comma separated tables to exclude.
	 *
	 * [--exclude-uploads]
	 * : Do not include the media library.
	 *
	 * [--include-host-files]
	 * : Keep hosting specific must-use plugins and cache drop-ins.
	 *
	 * [--skip-revisions]
	 * : Do not export post revisions.
	 *
	 * [--no-compress]
	 * : Store files without compression.
	 *
	 * [--password=<password>]
	 * : Protect the installer with a password.
	 *
	 * [--dir=<dir>]
	 * : Copy the archive and installer.php to this directory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migration build --password=secret --dir=/tmp/migration
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function build( $args, $assoc_args ) {
		if ( is_multisite() ) {
			WP_CLI::error( 'Les installations multisite ne sont pas prises en charge.' );
		}
		WPMIG_Plugin::raise_limits();
		$get     = function ( $key, $default = '' ) use ( $assoc_args ) {
			return isset( $assoc_args[ $key ] ) ? $assoc_args[ $key ] : $default;
		};
		$options = array(
			'name'               => $get( 'name' ),
			'db_only'            => isset( $assoc_args['db-only'] ),
			'exclude_dirs'       => $get( 'exclude-dirs' ),
			'exclude_ext'        => $get( 'exclude-ext' ),
			'exclude_tables'     => $get( 'exclude-tables' ),
			'exclude_uploads'    => isset( $assoc_args['exclude-uploads'] ),
			'exclude_host_files' => ! isset( $assoc_args['include-host-files'] ),
			'skip_revisions'     => isset( $assoc_args['skip-revisions'] ),
			'compress'           => ! ( isset( $assoc_args['compress'] ) && false === $assoc_args['compress'] ) && ! isset( $assoc_args['no-compress'] ),
			'password'           => $get( 'password' ),
		);
		try {
			$package = WPMIG_Package::create( $options );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		WP_CLI::log( 'Package ' . $package->data['id'] );
		$last = '';
		while ( in_array( $package->data['status'], array( 'scanning', 'scanned', 'building' ), true ) ) {
			$state = $package->step( 5 );
			if ( $state['message'] !== $last ) {
				WP_CLI::log( sprintf( '[%3d%%] %s', $state['progress'], $state['message'] ) );
				$last = $state['message'];
			}
			if ( 'scanned' === $package->data['status'] ) {
				foreach ( $package->data['report']['checks'] as $check ) {
					if ( 'error' === $check['status'] ) {
						WP_CLI::error( $check['label'] . ' : ' . $check['value'] );
					}
				}
				$package->start_build();
			}
		}
		foreach ( $package->data['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}
		if ( 'complete' !== $package->data['status'] ) {
			WP_CLI::error( $package->data['error'] ? $package->data['error'] : 'Échec de la construction.' );
		}
		$archive   = $package->archive_path();
		$installer = $package->installer_path();
		if ( ! empty( $assoc_args['dir'] ) ) {
			$dir = rtrim( $assoc_args['dir'], '/' );
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				WP_CLI::error( 'Dossier inaccessible : ' . $dir );
			}
			copy( $archive, $dir . '/' . basename( $archive ) );
			copy( $installer, $dir . '/installer.php' );
			$archive   = $dir . '/' . basename( $archive );
			$installer = $dir . '/installer.php';
		}
		WP_CLI::success( 'Package prêt (' . size_format( filesize( $archive ), 1 ) . ')' );
		WP_CLI::log( 'Archive     : ' . $archive );
		WP_CLI::log( 'Installeur  : ' . $installer );
	}

	/**
	 * List the packages.
	 *
	 * [--format=<format>]
	 * : table, json, csv, ids.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function list_( $args, $assoc_args ) {
		$items = array();
		foreach ( WPMIG_Package::all() as $package ) {
			$d       = $package->data;
			$items[] = array(
				'id'      => $d['id'],
				'name'    => $d['name'],
				'status'  => $d['status'],
				'size'    => ! empty( $d['sizes']['archive'] ) ? size_format( $d['sizes']['archive'], 1 ) : '',
				'archive' => 'complete' === $d['status'] ? $package->archive_path() : '',
			);
		}
		WP_CLI\Utils\format_items( isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table', $items, array( 'id', 'name', 'status', 'size', 'archive' ) );
	}

	/**
	 * Delete a package.
	 *
	 * <id>
	 * : Package id.
	 *
	 * @param array $args Arguments.
	 */
	public function delete( $args ) {
		$package = WPMIG_Package::load( $args[0] );
		if ( ! $package ) {
			WP_CLI::error( 'Package introuvable.' );
		}
		$package->delete();
		WP_CLI::success( 'Package supprimé.' );
	}
}
