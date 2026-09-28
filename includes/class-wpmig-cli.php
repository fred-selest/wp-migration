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
	 * : Comma separated tables whose data is left out (logs, caches): they are recreated empty.
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
	 * : Installer password (a random one is generated and displayed when omitted).
	 *
	 * [--no-password]
	 * : Do not protect the installer (not recommended).
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
		$no_password = isset( $assoc_args['password'] ) && false === $assoc_args['password'];
		if ( '' === $options['password'] && ! $no_password ) {
			$options['password'] = self::generate_password();
		}
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
		if ( '' !== $options['password'] ) {
			WP_CLI::log( 'Mot de passe de l\'installeur : ' . $options['password'] );
		} else {
			WP_CLI::warning( 'Installeur sans mot de passe : ne le laissez pas en ligne sans surveillance.' );
		}
	}

	/**
	 * Random installer password (no ambiguous characters).
	 *
	 * @return string
	 */
	private static function generate_password() {
		$chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$out   = '';
		for ( $i = 0; $i < 16; $i++ ) {
			$out .= $chars[ function_exists( 'random_int' ) ? random_int( 0, strlen( $chars ) - 1 ) : wp_rand( 0, strlen( $chars ) - 1 ) ];
			if ( 3 === $i || 7 === $i || 11 === $i ) {
				$out .= '-';
			}
		}
		return $out;
	}

	/**
	 * Create (or revoke) the direct transfer link of a package.
	 *
	 * The installer downloads the archive from this link, straight from this site.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Package id.
	 *
	 * [--hours=<hours>]
	 * : Link lifetime in hours.
	 * ---
	 * default: 24
	 * ---
	 *
	 * [--revoke]
	 * : Revoke the current link.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migration transfer-link 20260928_120000_0123456789ab
	 *
	 * @subcommand transfer-link
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function transfer_link( $args, $assoc_args ) {
		$package = WPMIG_Package::load( $args[0] );
		if ( ! $package ) {
			WP_CLI::error( 'Package introuvable.' );
		}
		if ( isset( $assoc_args['revoke'] ) ) {
			WPMIG_Transfer::revoke( $package );
			WP_CLI::success( 'Lien révoqué.' );
			return;
		}
		try {
			$link = WPMIG_Transfer::create( $package, isset( $assoc_args['hours'] ) ? (int) $assoc_args['hours'] : 0 );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		if ( $link['insecure'] ) {
			WP_CLI::warning( 'Le site n\'est pas en HTTPS : l\'archive transitera en clair.' );
		}
		WP_CLI::log( 'Lien de transfert (valable jusqu\'au ' . $link['expires_h'] . ') :' );
		WP_CLI::log( $link['url'] );
		WP_CLI::log( '' );
		WP_CLI::log( 'Sur le nouveau serveur :' );
		WP_CLI::log( "  curl -o installer.php '" . $link['installer_url'] . "'" );
		WP_CLI::log( "  php installer.php --source-url='" . $link['url'] . "' --url=https://nouveau-site.fr --db-name=... --db-user=... --db-pass=..." );
	}

	/**
	 * Delete old packages according to the cleanup settings.
	 *
	 * Also removes abandoned builds (older than 24 hours) and orphan files. Packages
	 * with an active transfer link are never deleted.
	 *
	 * ## OPTIONS
	 *
	 * [--keep=<number>]
	 * : Completed packages to keep (0 = no limit). Default: setting.
	 *
	 * [--days=<number>]
	 * : Delete packages older than this (0 = no limit). Default: setting.
	 *
	 * [--dry-run]
	 * : Only list what would be deleted.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migration cleanup --dry-run
	 *     wp migration cleanup --keep=2 --days=0
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function cleanup( $args, $assoc_args ) {
		$dry    = isset( $assoc_args['dry-run'] );
		$result = WPMIG_Cleanup::run(
			$dry,
			isset( $assoc_args['keep'] ) ? (int) $assoc_args['keep'] : null,
			isset( $assoc_args['days'] ) ? (int) $assoc_args['days'] : null
		);
		foreach ( $result['items'] as $item ) {
			WP_CLI::log( sprintf( '%s %s — %s (%s)', $dry ? 'À supprimer :' : 'Supprimé :', $item['label'], $item['reason'], wpmig_size( $item['size'] ) ) );
		}
		$summary = sprintf( '%d élément(s), %s', $result['count'], wpmig_size( $result['bytes'] ) );
		WP_CLI::success( $dry ? 'Simulation : ' . $summary . ' seraient supprimés.' : 'Nettoyage : ' . $summary . ' libérés.' );
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
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		if ( 'ids' === $format ) {
			WP_CLI::log( implode( ' ', wp_list_pluck( $items, 'id' ) ) );
			return;
		}
		WP_CLI\Utils\format_items( $format, $items, array( 'id', 'name', 'status', 'size', 'archive' ) );
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
