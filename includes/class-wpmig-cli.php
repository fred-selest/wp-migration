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
		WP_CLI::log( 'Sauvegarde ' . $package->data['id'] );
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
		WP_CLI::success( 'Sauvegarde prête (' . size_format( filesize( $archive ), 1 ) . ')' );
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
			WP_CLI::error( 'Sauvegarde introuvable.' );
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
	 * Show the report of the migration that created this site.
	 *
	 * Written by the installer at the end of the installation: archive checksums,
	 * files extracted, rows exported by the source and imported for each table,
	 * SQL errors, replacements and the full installation log.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : text or json.
	 * ---
	 * default: text
	 * ---
	 *
	 * [--delete]
	 * : Delete the report.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migration report
	 *     wp migration report > rapport-migration.txt
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function report( $args, $assoc_args ) {
		$report = WPMIG_Report::get();
		if ( ! $report ) {
			WP_CLI::error( 'Aucun rapport de migration : ce site n\'a pas été installé avec l\'installeur WP Migration 1.3.0 ou plus récent, ou le rapport a été supprimé.' );
		}
		if ( isset( $assoc_args['delete'] ) ) {
			WPMIG_Report::delete();
			WP_CLI::success( 'Rapport de migration supprimé.' );
			return;
		}
		if ( isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ) {
			WP_CLI::line( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		WP_CLI::line( rtrim( WPMIG_Report::to_text( $report ) ) );
		if ( empty( $report['checks']['ok'] ) ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * On the source site: create the link that lets another site synchronize its content from this one.
	 *
	 * ## OPTIONS
	 *
	 * [--hours=<hours>]
	 * : Lifetime of the link. Default: 24.
	 *
	 * [--revoke]
	 * : Revoke the link.
	 *
	 * @subcommand sync-link
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function sync_link( $args, $assoc_args ) {
		if ( isset( $assoc_args['revoke'] ) ) {
			WPMIG_Sync_Source::revoke();
			WP_CLI::success( 'Lien de synchronisation révoqué.' );
			return;
		}
		$link = WPMIG_Sync_Source::create( isset( $assoc_args['hours'] ) ? (int) $assoc_args['hours'] : 24 );
		WP_CLI::log( $link['url'] );
		WP_CLI::success( 'Lien de synchronisation valable jusqu\'au ' . $link['expires_h'] . '. Sur le site à mettre à jour : wp migration sync \'<lien>\'' );
	}

	/**
	 * Bring into this site the content created or modified on another site since this one was copied from it.
	 *
	 * Orders, customers, coupons and comments come from the source. Products, posts, pages
	 * and media modified on both sides keep the version of this site (products receive the
	 * stock of the source), unless --force. Order numbers are kept. Can be undone with
	 * wp migration sync-undo.
	 *
	 * ## OPTIONS
	 *
	 * <link>
	 * : Synchronization link created on the source site (wp migration sync-link).
	 *
	 * [--types=<types>]
	 * : Comma separated: orders, customers, products, coupons, posts, media, comments. Default: all.
	 *
	 * [--since=<date>]
	 * : Date of the copy, local time "YYYY-MM-DD HH:MM". Default: from the migration report or the last synchronization.
	 *
	 * [--force]
	 * : Also replace the products, posts, pages and media modified on this site.
	 *
	 * [--dry-run]
	 * : Only analyze.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migration sync 'https://www.example.com/wp-admin/admin-ajax.php?action=wpmig_sync&key=...' --dry-run
	 *     wp migration sync '...' --types=orders,customers,products --yes
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function sync( $args, $assoc_args ) {
		$kinds = isset( $assoc_args['types'] ) ? array_map( 'trim', explode( ',', $assoc_args['types'] ) ) : WPMIG_Sync::KINDS;
		try {
			$sync = WPMIG_Sync::start( $args[0], $kinds, isset( $assoc_args['since'] ) ? $assoc_args['since'] : '', isset( $assoc_args['force'] ) );
			$state = $sync->run();
			if ( 'error' === $state['status'] ) {
				WP_CLI::error( $state['error'] );
			}
			WP_CLI::log( sprintf( 'Site d\'origine : %s — contenus créés ou modifiés depuis : %s', $state['source'], $state['threshold_h'] ) );
			$this->sync_summary( $state );
			if ( isset( $assoc_args['dry-run'] ) || ! $sync->state()['lines'] ) {
				WPMIG_Sync::dismiss();
				WP_CLI::success( isset( $assoc_args['dry-run'] ) ? 'Analyse terminée, rien n\'a été modifié.' : 'Rien à synchroniser.' );
				return;
			}
			WP_CLI::confirm( 'Importer ces contenus sur ce site ?', $assoc_args );
			$sync->confirm();
			$state = $sync->run(
				function ( $s ) {
					WP_CLI::log( '  ' . $s['message'] );
				}
			);
			if ( 'error' === $state['status'] ) {
				WP_CLI::error( $state['error'] );
			}
			$this->sync_summary( $state );
			WP_CLI::success( 'Synchronisation terminée. Annulation possible : wp migration sync-undo' );
		} catch ( WPMIG_Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Undo the last synchronization.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @subcommand sync-undo
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function sync_undo( $args, $assoc_args ) {
		$sync = WPMIG_Sync::current();
		if ( ! $sync || 'done' !== $sync->state()['status'] ) {
			WP_CLI::error( 'Aucune synchronisation terminée à annuler.' );
		}
		WP_CLI::confirm( 'Annuler la synchronisation du ' . wpmig_date( $sync->state()['started'] ) . ' ?', $assoc_args );
		try {
			$sync->undo();
			$state = $sync->run();
		} catch ( WPMIG_Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		if ( 'error' === $state['status'] ) {
			WP_CLI::error( $state['error'] );
		}
		WP_CLI::success( $state['message'] );
	}

	/**
	 * Print counts and notes of a synchronization.
	 *
	 * @param array $state Public state.
	 */
	private function sync_summary( array $state ) {
		$labels  = WPMIG_Sync::labels();
		$actions = 'done' === $state['status'] ? WPMIG_Sync::done_labels() : WPMIG_Sync::action_labels();
		foreach ( $state['counts'] as $kind => $counts ) {
			$parts = array();
			foreach ( $counts as $action => $n ) {
				$parts[] = $n . ' ' . ( isset( $actions[ $action ] ) ? $actions[ $action ] : $action );
			}
			WP_CLI::log( sprintf( '  %-22s %s', ( isset( $labels[ $kind ] ) ? $labels[ $kind ] : $kind ) . ' :', implode( ', ', $parts ) ) );
		}
		foreach ( $state['notes'] as $note ) {
			WP_CLI::log( '  - ' . $note[2] );
		}
		foreach ( $state['warnings'] as $w ) {
			WP_CLI::warning( $w );
		}
		if ( ! empty( $state['files']['total'] ) ) {
			WP_CLI::log( sprintf( '  Fichiers des médias : %d téléchargé(s), %d déjà présent(s), %d en échec', $state['files']['downloaded'], $state['files']['total'] - $state['files']['downloaded'] - $state['files']['failed'], $state['files']['failed'] ) );
		}
	}

	/**
	 * Consistency checks of this site: menu locations, permalinks, WPML / Polylang (translation links, default language, language domains).
	 *
	 * Nothing is changed. When this site was created by a migration, the figures of the
	 * source site are used for the comparison, and --save keeps the result in the migration report.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * [--save]
	 * : Keep the result in the migration report.
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function check( $args, $assoc_args ) {
		$items = isset( $assoc_args['save'] ) ? WPMIG_Report::recheck() : null;
		if ( null === $items ) {
			$items = WPMIG_Report::consistency_now();
		}
		if ( 'json' === ( isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table' ) ) {
			WP_CLI::print_value( $items, array( 'format' => 'json' ) );
			return;
		}
		$symbols = array(
			'ok'      => '[OK]',
			'warning' => '[!!]',
			'info'    => '[i] ',
		);
		foreach ( $items as $item ) {
			WP_CLI::log( sprintf( '%s %-26s %s', $symbols[ $item['status'] ], $item['label'], $item['message'] ) );
			foreach ( $item['details'] as $detail ) {
				WP_CLI::log( '       - ' . $detail );
			}
		}
		$counts = WPMIG_Consistency::counts( $items );
		if ( $counts['warning'] ) {
			WP_CLI::halt( 1 );
		}
		WP_CLI::success( $items ? 'Rien à signaler.' : 'Aucun contrôle applicable à ce site.' );
	}

	/**
	 * Search and replace in the database, serialization-safe, with an analysis first and an undo.
	 *
	 * Works in every text column of the site tables (posts, options, meta, orders...),
	 * including serialized PHP data and JSON. Setting names, meta keys, passwords and post
	 * guids are never changed (unless --guid). Can be undone with wp migration replace-undo.
	 *
	 * ## OPTIONS
	 *
	 * <search>
	 * : Text, URL, path or (with --regex) pattern such as "/old(\d+)/i".
	 *
	 * <replace>
	 * : Replacement. Use "$1"... for the captured groups of a pattern.
	 *
	 * [--mode=<mode>]
	 * : "url" (whole words, http/https/JSON/encoded variants), "text" (every occurrence), "regex", or "auto" (url when the search looks like an address, domain or path, otherwise text).
	 * ---
	 * default: url
	 * ---
	 *
	 * [--regex]
	 * : Same as --mode=regex.
	 *
	 * [--ignore-case]
	 * : Case-insensitive.
	 *
	 * [--no-www]
	 * : URL mode: do not process the variant with / without "www.".
	 *
	 * [--no-variants]
	 * : Text mode: do not process the JSON-escaped and URL-encoded forms.
	 *
	 * [--guid]
	 * : Also change the guid of posts.
	 *
	 * [--tables=<tables>]
	 * : Comma separated table names. Default: all the tables of the site.
	 *
	 * [--dry-run]
	 * : Only analyze.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migration replace 'https://www.old.fr' 'https://www.new.fr' --dry-run
	 *     wp migration replace 'https://www.old.fr' 'https://www.new.fr' --yes
	 *     wp migration replace 'Ancienne société' 'Nouvelle société' --mode=text --tables=wp_posts,wp_postmeta
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function replace( $args, $assoc_args ) {
		$mode = isset( $assoc_args['regex'] ) ? 'regex' : ( isset( $assoc_args['mode'] ) ? $assoc_args['mode'] : 'url' );
		try {
			$search = WPMIG_Search::start(
				array(
					'search'      => $args[0],
					'replace'     => $args[1],
					'mode'        => $mode,
					'ignore_case' => isset( $assoc_args['ignore-case'] ),
					'www'         => ! isset( $assoc_args['no-www'] ),
					'variants'    => ! isset( $assoc_args['no-variants'] ),
					'guid'        => isset( $assoc_args['guid'] ),
					'tables'      => isset( $assoc_args['tables'] ) ? array_map( 'trim', explode( ',', $assoc_args['tables'] ) ) : array(),
				)
			);
			$state = $search->run();
			$this->replace_summary( $state );
			if ( isset( $assoc_args['dry-run'] ) || ! $state['totals']['changed'] ) {
				$search->dismiss();
				WP_CLI::success( isset( $assoc_args['dry-run'] ) ? 'Analyse terminée, rien n\'a été modifié.' : 'Rien à remplacer.' );
				return;
			}
			WP_CLI::confirm( 'Remplacer maintenant dans la base de données ?', $assoc_args );
			$search->confirm();
			$state = $search->run();
			$this->replace_summary( $state );
			WP_CLI::success( 'Remplacement terminé (annulation possible : wp migration replace-undo ' . $state['id'] . '). Videz les caches du site.' );
		} catch ( WPMIG_Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Undo a search and replace (the last one by default).
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Identifier printed by wp migration replace.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @subcommand replace-undo
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function replace_undo( $args, $assoc_args ) {
		$id = isset( $args[0] ) ? $args[0] : '';
		if ( '' === $id ) {
			foreach ( WPMIG_Search::history() as $h ) {
				if ( 'done' === $h['status'] ) {
					$id = $h['id'];
					break;
				}
			}
		}
		WP_CLI::confirm( 'Annuler le remplacement ' . $id . ' ?', $assoc_args );
		try {
			$state = WPMIG_Search::undo( $id )->run();
		} catch ( WPMIG_Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( sprintf( '%d valeur(s) restaurée(s), %d laissée(s) telle(s) quelle(s) (modifiées depuis).', $state['undo']['restored'], $state['undo']['kept'] ) );
	}

	/**
	 * Take settings (options) from another site through its synchronization link.
	 *
	 * Without --names or --filter, lists the options available on the source.
	 * The copy is compared with the local values first; nothing is written with
	 * --dry-run.
	 *
	 * ## OPTIONS
	 *
	 * <link>
	 * : Synchronization link created on the source site.
	 *
	 * [--filter=<text>]
	 * : Part of the option name (listing, or selection with --all).
	 *
	 * [--names=<names>]
	 * : Comma separated option names to copy.
	 *
	 * [--all]
	 * : Copy every option matching --filter.
	 *
	 * [--no-adapt]
	 * : Do not replace the addresses of the source with the ones of this site.
	 *
	 * [--dry-run]
	 * : Only compare.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migration settings 'https://www.old.fr/wp-admin/admin-ajax.php?action=wpmig_sync&key=…' --filter=monetico
	 *     wp migration settings '<link>' --names=woocommerce_monetico_settings --dry-run
	 *     wp migration settings '<link>' --filter=monetico --all --yes
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function settings( $args, $assoc_args ) {
		try {
			$names = isset( $assoc_args['names'] ) ? array_filter( array_map( 'trim', explode( ',', $assoc_args['names'] ) ), 'strlen' ) : array();
			if ( ! $names ) {
				$list = WPMIG_Settings::remote_list( $args[0], isset( $assoc_args['filter'] ) ? $assoc_args['filter'] : '' );
				if ( empty( $assoc_args['all'] ) ) {
					WP_CLI::log( sprintf( '%d réglage(s) sur le site d\'origine :', $list['total'] ) );
					foreach ( $list['options'] as $o ) {
						WP_CLI::log( sprintf( '  %-60s %8d o  %s', $o['name'], $o['size'], $o['local'] ? 'existe ici' : 'absent ici' ) );
					}
					WP_CLI::success( 'Choisissez avec --names=… ou --filter=… --all.' );
					return;
				}
				$names = wp_list_pluck( $list['options'], 'name' );
			}
			$plan = WPMIG_Settings::plan( $args[0], $names, ! isset( $assoc_args['no-adapt'] ) );
			$todo = 0;
			WP_CLI::log( 'Origine : ' . $plan['source'] );
			foreach ( $plan['items'] as $item ) {
				WP_CLI::log( sprintf( '  %-60s %s%s', $item['name'], $item['status'], $item['note'] ? ' — ' . $item['note'] : '' ) );
				foreach ( $item['changes'] as $line ) {
					WP_CLI::log( '      ' . $line );
				}
				if ( in_array( $item['status'], array( 'new', 'different' ), true ) ) {
					$todo++;
				}
			}
			if ( isset( $assoc_args['dry-run'] ) || ! $todo ) {
				WP_CLI::success( $todo ? sprintf( '%d réglage(s) seraient copié(s), rien n\'a été modifié.', $todo ) : 'Rien à copier.' );
				return;
			}
			WP_CLI::confirm( sprintf( 'Copier %d réglage(s) sur ce site ?', $todo ), $assoc_args );
			$res = WPMIG_Settings::apply( $args[0], $names, ! isset( $assoc_args['no-adapt'] ) );
			WP_CLI::success( sprintf( '%d réglage(s) copié(s) (annulation possible : wp migration settings-undo %s). Videz les caches du site.', $res['changed'], $res['id'] ) );
		} catch ( WPMIG_Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Undo a copy of settings (the last one by default).
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Identifier printed by wp migration settings.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @subcommand settings-undo
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function settings_undo( $args, $assoc_args ) {
		$id = isset( $args[0] ) ? $args[0] : '';
		WP_CLI::confirm( 'Annuler la copie de réglages ' . ( $id ? $id : 'la plus récente' ) . ' ?', $assoc_args );
		try {
			$res = WPMIG_Settings::undo( $id );
		} catch ( WPMIG_Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( sprintf( '%d réglage(s) remis en état, %d laissé(s) tel(s) quel(s) (modifiés depuis).', $res['restored'], count( $res['kept'] ) ) );
	}

	/**
	 * Compare this site with another one (read only), through its synchronization link.
	 *
	 * ## OPTIONS
	 *
	 * <link>
	 * : Synchronization link created on the other site.
	 *
	 * [--all]
	 * : Also list the identical lines.
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migration compare 'https://www.old.fr/wp-admin/admin-ajax.php?action=wpmig_sync&key=…'
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function compare( $args, $assoc_args ) {
		try {
			$res = WPMIG_Compare::run( $args[0] );
		} catch ( WPMIG_Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		if ( isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ) {
			WP_CLI::line( wp_json_encode( $res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$names = array(
			'same'       => '=',
			'diff'       => 'diff',
			'only_here'  => 'ici seul',
			'only_there' => 'la-bas seul',
			'info'       => 'info',
		);
		WP_CLI::log( 'Autre site : ' . $res['source'] );
		foreach ( $res['sections'] as $section ) {
			$lines = array();
			foreach ( $section['rows'] as $row ) {
				if ( 'same' === $row['status'] && ! isset( $assoc_args['all'] ) ) {
					continue;
				}
				$lines[] = sprintf( '  %-12s %-40s  ici : %s | la-bas : %s', $names[ $row['status'] ], $row['label'], '' === $row['here'] ? '(absent)' : $row['here'], '' === $row['there'] ? '(absent)' : $row['there'] );
			}
			if ( $lines ) {
				WP_CLI::log( "\n" . $section['title'] );
				foreach ( $lines as $line ) {
					WP_CLI::log( $line );
				}
			}
		}
		$s = $res['summary'];
		WP_CLI::success( sprintf( '%d différence(s) à examiner, %d indicative(s), %d identique(s).', $s['diff'] + $s['only_here'] + $s['only_there'], $s['info'], $s['same'] ) );
	}

	/**
	 * Prepare the restoration of a backup of this site: places its installer and
	 * archive in the WordPress root and prints the address of the installer.
	 *
	 * Nothing is changed until the installer is run (browser, or in SSH:
	 * php <installer> --url=... --db-name=... --db-user=... --db-pass=...).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup identifier (see wp migration list).
	 *
	 * [--cancel]
	 * : Remove the installer and archive placed in the root.
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Options.
	 */
	public function restore( $args, $assoc_args ) {
		try {
			if ( isset( $assoc_args['cancel'] ) ) {
				WPMIG_Restore::cancel( $args[0] );
				WP_CLI::success( 'Préparation annulée : l\'installeur et l\'archive ont été retirés de la racine du site.' );
				return;
			}
			$res = WPMIG_Restore::prepare( $args[0] );
			WP_CLI::log( 'Installeur : ' . $res['url'] );
			WP_CLI::success( 'Restauration préparée (' . ( 'link' === $res['mode'] ? 'archive liée, non dupliquée' : 'archive copiée' ) . '). Ouvrez l\'installeur et saisissez le mot de passe de la sauvegarde ; annulation : wp migration restore ' . $args[0] . ' --cancel' );
		} catch ( WPMIG_Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Print the result of a search and replace.
	 *
	 * @param array $state Public state.
	 */
	private function replace_summary( array $state ) {
		$t = $state['totals'];
		foreach ( $state['tables'] as $table ) {
			WP_CLI::log( sprintf( '  %-40s %6d ligne(s), %6d occurrence(s) (%s)', $table['name'], $table['rows'], $table['matches'], implode( ', ', $table['cols'] ) ) );
		}
		foreach ( $state['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}
		foreach ( $state['skipped'] as $skipped ) {
			WP_CLI::warning( 'Table ignorée : ' . $skipped );
		}
		WP_CLI::log( sprintf( '%d occurrence(s) dans %d ligne(s) de %d table(s).', $t['matches'], $t['changed'], $t['tables'] ) );
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
			WP_CLI::error( 'Sauvegarde introuvable.' );
		}
		$package->delete();
		WP_CLI::success( 'Sauvegarde supprimée.' );
	}
}
