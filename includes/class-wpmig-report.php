<?php
/**
 * Migration report: written by the installer into the options of the new site
 * at the end of the installation, so that it survives the removal of the
 * installation files.
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration report.
 */
class WPMIG_Report {

	const OPTION = 'wpmig_report';

	/**
	 * Report of the migration that created this site.
	 *
	 * @return array|null
	 */
	public static function get() {
		$raw  = get_option( self::OPTION );
		$data = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( ! is_array( $data ) || empty( $data['checks'] ) || ! is_array( $data['checks'] ) ) {
			return null;
		}
		return array_merge(
			array(
				'package'      => array(),
				'source'       => array(),
				'destination'  => array(),
				'options'      => array(),
				'transfer'     => null,
				'tables'       => array(),
				'replacements' => array(),
				'excluded'     => array(),
				'warnings'     => array(),
				'notices'      => array(),
				'consistency'  => array(),
				'log'          => '',
				'started'      => 0,
				'finished'     => 0,
				'mode'         => 'web',
			),
			$data
		);
	}

	/**
	 * Data of the consistency checks read from this site's database.
	 *
	 * @param string $old_home Address of the source site ('' when unknown).
	 * @param array  $source   Figures of the source kept in the package.
	 * @return array
	 */
	public static function consistency_data( $old_home = '', array $source = array() ) {
		global $wpdb;
		$query = function ( $sql ) use ( $wpdb ) {
			$shown = $wpdb->suppress_errors( true );
			$rows  = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->suppress_errors( $shown );
			return is_array( $rows ) ? $rows : array();
		};
		return WPMIG_Consistency::collect( $wpdb->prefix, $query, 'esc_sql', $old_home, home_url(), $source );
	}

	/**
	 * Consistency checks of this site now, compared with the source of the migration when known.
	 *
	 * @return array Findings.
	 */
	public static function consistency_now() {
		$report = self::get();
		$source = $report && ! empty( $report['multilingual_source'] ) && is_array( $report['multilingual_source'] ) ? $report['multilingual_source'] : array();
		$old    = $report && ! empty( $report['source']['home'] ) ? $report['source']['home'] : '';
		return WPMIG_Consistency::evaluate( self::consistency_data( $old, $source ) );
	}

	/**
	 * Run the consistency checks again and keep the result in the report.
	 *
	 * @return array|null Findings, null without a report.
	 */
	public static function recheck() {
		$report = self::get();
		if ( ! $report ) {
			return null;
		}
		$items = self::consistency_now();
		$raw   = get_option( self::OPTION );
		$data  = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( is_array( $data ) ) {
			$data['consistency']         = $items;
			$data['consistency_checked'] = time();
			update_option( self::OPTION, wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR ), false );
		}
		return $items;
	}

	/**
	 * Delete the report.
	 */
	public static function delete() {
		delete_option( self::OPTION );
	}

	/**
	 * Tables of the report with their status.
	 *
	 * @param array $report Report.
	 * @return array List of array( 'source', 'name', 'exported', 'imported', 'status', 'label' ).
	 *               status: ok | empty (data excluded on purpose) | diff | missing | unknown.
	 */
	public static function tables( array $report ) {
		$old  = isset( $report['source']['prefix'] ) ? (string) $report['source']['prefix'] : '';
		$new  = isset( $report['destination']['prefix'] ) ? (string) $report['destination']['prefix'] : $old;
		$list = array();
		foreach ( (array) $report['tables'] as $row ) {
			if ( ! is_array( $row ) || count( $row ) < 4 ) {
				continue;
			}
			list( $source, $exported, $imported, $structure_only ) = $row;
			$name = ( '' !== $old && 0 === strpos( $source, $old ) ) ? $new . substr( $source, strlen( $old ) ) : $source;
			if ( null === $imported ) {
				$status = 'missing';
				$label  = 'Absente de la destination';
			} elseif ( null === $exported ) {
				$status = 'unknown';
				$label  = 'Non comparée (sauvegarde d\'une version antérieure)';
			} elseif ( (int) $exported !== (int) $imported ) {
				$status = 'diff';
				$label  = sprintf( 'Écart de %+d ligne(s)', (int) $imported - (int) $exported );
			} elseif ( $structure_only ) {
				$status = 'empty';
				$label  = 'Structure seule (données exclues)';
			} else {
				$status = 'ok';
				$label  = 'Identique';
			}
			$list[] = array(
				'source'   => $source,
				'name'     => $name,
				'exported' => $exported,
				'imported' => $imported,
				'status'   => $status,
				'label'    => $label,
			);
		}
		return $list;
	}

	/**
	 * Human readable duration.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		if ( $seconds < 60 ) {
			return $seconds . ' s';
		}
		if ( $seconds < 3600 ) {
			return sprintf( '%d min %02d s', floor( $seconds / 60 ), $seconds % 60 );
		}
		return sprintf( '%d h %02d min', floor( $seconds / 3600 ), floor( ( $seconds % 3600 ) / 60 ) );
	}

	/**
	 * Main checks as rows.
	 *
	 * @param array $report Report.
	 * @return array List of array( label, value, ok ).
	 */
	public static function checks( array $report ) {
		$c    = array_merge(
			array(
				'verified'       => false,
				'files_expected' => null,
				'files'          => 0,
				'files_failed'   => 0,
				'bytes'          => 0,
				'tables'         => 0,
				'tables_bad'     => 0,
				'rows_exported'  => 0,
				'rows_imported'  => 0,
				'sql_queries'    => 0,
				'sql_errors'     => 0,
				'broken'         => 0,
			),
			$report['checks']
		);
		$skip = ! empty( $report['options']['skip_files'] );
		$rows = array(
			array( 'Intégrité de l\'archive', $c['verified'] ? 'Sommes de contrôle (CRC32) de tous les blocs vérifiées avant l\'extraction' : 'Non vérifiée (vérification désactivée)', (bool) $c['verified'] ),
			array(
				'Fichiers',
				$skip
					? 'Non extraits (option « base de données uniquement »)'
					: sprintf( '%s extraits', number_format_i18n( $c['files'] ) ) . ( null !== $c['files_expected'] ? sprintf( ' sur %s dans l\'archive', number_format_i18n( $c['files_expected'] ) ) : '' ) . ( $c['files_failed'] ? sprintf( ', %s en échec', number_format_i18n( $c['files_failed'] ) ) : '' ) . sprintf( ' (%s)', wpmig_size( $c['bytes'] ) ),
				$skip || ( ! $c['files_failed'] && ( null === $c['files_expected'] || (int) $c['files'] === (int) $c['files_expected'] ) ),
			),
			array( 'Tables', sprintf( '%s / %s identiques à la source', number_format_i18n( $c['tables'] - $c['tables_bad'] ), number_format_i18n( $c['tables'] ) ), ! $c['tables_bad'] ),
			array( 'Lignes', sprintf( '%s importées pour %s exportées par le site d\'origine', number_format_i18n( $c['rows_imported'] ), number_format_i18n( $c['rows_exported'] ) ), ! $c['tables_bad'] ),
			array( 'Requêtes SQL', sprintf( '%s exécutées, %s en erreur', number_format_i18n( $c['sql_queries'] ), number_format_i18n( $c['sql_errors'] ) ), ! $c['sql_errors'] ),
		);
		if ( $c['broken'] ) {
			$rows[] = array( 'Données sérialisées', sprintf( '%s valeur(s) déjà corrompue(s) sur le site d\'origine : remplacement simple appliqué', number_format_i18n( $c['broken'] ) ), true );
		}
		return $rows;
	}

	/**
	 * Source / destination comparison.
	 *
	 * @param array $report Report.
	 * @return array List of array( label, source, destination ).
	 */
	public static function comparison( array $report ) {
		$s    = $report['source'];
		$d    = $report['destination'];
		$get  = function ( $a, $k ) {
			return isset( $a[ $k ] ) ? (string) $a[ $k ] : '';
		};
		$base = $get( $d, 'db_name' ) . ( '' !== $get( $d, 'db_host' ) ? ' @ ' . $get( $d, 'db_host' ) : '' );
		return array(
			array( 'Adresse du site', $get( $s, 'home' ), $get( $d, 'home' ) ),
			array( 'Adresse de WordPress', $get( $s, 'siteurl' ), $get( $d, 'siteurl' ) ),
			array( 'Dossier', $get( $s, 'path' ), $get( $d, 'path' ) ),
			array( 'WordPress', $get( $s, 'wp' ), $get( $d, 'wp' ) ),
			array( 'PHP', $get( $s, 'php' ), $get( $d, 'php' ) ),
			array( 'Serveur de base de données', $get( $s, 'db' ), $get( $d, 'db' ) ),
			array( 'Serveur web', $get( $s, 'server' ), $get( $d, 'server' ) ),
			array( 'Préfixe des tables', $get( $s, 'prefix' ), $get( $d, 'prefix' ) ),
			array( 'Base de données', '', $base ),
		);
	}

	/**
	 * General information.
	 *
	 * @param array $report Report.
	 * @return array List of array( label, value ).
	 */
	public static function summary( array $report ) {
		$p    = $report['package'];
		$rows = array(
			array( 'Terminée le', $report['finished'] ? wpmig_date( $report['finished'] ) : '' ),
			array( 'Durée de l\'installation', self::duration( $report['finished'] - $report['started'] ) ),
			array( 'Mode', 'cli' === $report['mode'] ? 'Ligne de commande (SSH)' : 'Navigateur' ),
			array(
				'Sauvegarde',
				( isset( $p['name'] ) ? $p['name'] : '' ) . ( isset( $p['id'] ) ? ' (' . $p['id'] . ')' : '' )
				. ( ! empty( $p['created'] ) ? ', créé le ' . wpmig_date( strtotime( $p['created'] . ' UTC' ) ) : '' )
				. ( ! empty( $p['archive_size'] ) ? ', archive de ' . wpmig_size( $p['archive_size'] ) : '' ),
			),
		);
		if ( is_array( $report['transfer'] ) ) {
			$rows[] = array( 'Transfert', sprintf( 'Direct de serveur à serveur : %s en %s', wpmig_size( $report['transfer']['size'] ), self::duration( $report['transfer']['seconds'] ) ) );
		} else {
			$rows[] = array( 'Transfert', 'Archive déposée sur le serveur' );
		}
		$o      = $report['options'];
		$opts   = array();
		$opts[] = isset( $o['db_action'] ) && 'empty' === $o['db_action'] ? 'base vidée avant l\'import' : 'tables existantes remplacées';
		if ( ! empty( $o['keep_guid'] ) ) {
			$opts[] = 'GUID conservés';
		}
		if ( ! empty( $o['new_salts'] ) ) {
			$opts[] = 'nouvelles clés de sécurité';
		}
		if ( isset( $o['www_variants'] ) && ! $o['www_variants'] ) {
			$opts[] = 'variantes www ignorées';
		}
		$rows[] = array( 'Options', implode( ', ', $opts ) );
		$rows[] = array( 'Versions', trim( ( isset( $report['generator'] ) ? $report['generator'] : '' ) . ' / installeur ' . ( isset( $report['installer'] ) ? $report['installer'] : '' ), ' /' ) );
		return $rows;
	}

	/**
	 * str_pad() counting characters instead of bytes.
	 *
	 * @param string $text   Text.
	 * @param int    $length Length.
	 * @param int    $type   STR_PAD_RIGHT or STR_PAD_LEFT.
	 * @return string
	 */
	private static function pad( $text, $length, $type = STR_PAD_RIGHT ) {
		$text = (string) $text;
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : (int) preg_match_all( '/./us', $text );
		$fill = str_repeat( ' ', max( 0, $length - $len ) );
		return STR_PAD_LEFT === $type ? $fill . $text : $text . $fill;
	}

	/**
	 * Plain text version (download, WP-CLI).
	 *
	 * @param array $report Report.
	 * @return string
	 */
	public static function to_text( array $report ) {
		$c     = $report['checks'];
		$lines = array();
		$title = function ( $text ) use ( &$lines ) {
			$lines[] = '';
			$lines[] = $text;
			$lines[] = str_repeat( '=', function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) );
		};
		$lines[] = 'RAPPORT DE MIGRATION — WP Migration';
		$lines[] = ( isset( $report['source']['home'] ) ? $report['source']['home'] : '' ) . ' → ' . ( isset( $report['destination']['home'] ) ? $report['destination']['home'] : '' );
		$lines[] = '';
		$lines[] = ! empty( $c['ok'] ) ? 'RÉSULTAT : OK — la copie est complète, aucune anomalie détectée.' : 'RÉSULTAT : ' . count( $c['issues'] ) . ' point(s) à vérifier.';
		foreach ( (array) $c['issues'] as $issue ) {
			$lines[] = '  ! ' . $issue;
		}

		$title( 'Informations' );
		foreach ( self::summary( $report ) as $row ) {
			$lines[] = self::pad( $row[0], 28 ) . $row[1];
		}

		$title( 'Contrôles' );
		foreach ( self::checks( $report ) as $row ) {
			$lines[] = ( $row[2] ? '[OK] ' : '[!!] ' ) . self::pad( $row[0], 26 ) . $row[1];
		}

		if ( ! empty( $report['consistency'] ) ) {
			$title( 'Cohérence du site' );
			$labels = array(
				'ok'      => '[OK] ',
				'warning' => '[!!] ',
				'info'    => '[i]  ',
			);
			foreach ( $report['consistency'] as $item ) {
				$lines[] = $labels[ $item['status'] ] . self::pad( $item['label'], 26 ) . $item['message'];
				foreach ( $item['details'] as $detail ) {
					$lines[] = self::pad( '', 31 ) . '- ' . $detail;
				}
			}
		}

		$title( 'Source / destination' );
		foreach ( self::comparison( $report ) as $row ) {
			if ( '' === $row[1] && '' === $row[2] ) {
				continue;
			}
			$lines[] = self::pad( $row[0], 28 ) . ( '' !== $row[1] ? $row[1] : '—' );
			$lines[] = self::pad( '', 28 ) . '→ ' . ( '' !== $row[2] ? $row[2] : '—' );
		}

		$tables = self::tables( $report );
		$title( 'Tables (' . count( $tables ) . ')' );
		$lines[] = self::pad( 'Table', 48 ) . self::pad( 'Exportées', 12, STR_PAD_LEFT ) . self::pad( 'Importées', 12, STR_PAD_LEFT ) . '  Statut';
		foreach ( $tables as $t ) {
			$lines[] = self::pad( $t['name'], 48 ) . self::pad( null === $t['exported'] ? '?' : (string) $t['exported'], 12, STR_PAD_LEFT ) . self::pad( null === $t['imported'] ? '—' : (string) $t['imported'], 12, STR_PAD_LEFT ) . '  ' . $t['label'];
		}

		if ( $report['replacements'] ) {
			$title( 'Remplacements' );
			foreach ( $report['replacements'] as $pair ) {
				$lines[] = $pair[0] . ' → ' . $pair[1];
			}
		}
		if ( $report['excluded'] ) {
			$title( 'Exclus de la sauvegarde par le site d\'origine' );
			foreach ( $report['excluded'] as $item ) {
				$lines[] = '- ' . $item;
			}
		}
		if ( $report['warnings'] ) {
			$title( 'Avertissements' );
			foreach ( $report['warnings'] as $item ) {
				$lines[] = '- ' . $item;
			}
		}
		if ( $report['notices'] ) {
			$title( 'Remarques' );
			foreach ( $report['notices'] as $item ) {
				$lines[] = '- ' . $item;
			}
		}
		if ( '' !== (string) $report['log'] ) {
			$title( 'Journal de l\'installation (heures UTC)' );
			$lines[] = rtrim( (string) $report['log'] );
		}
		return implode( "\n", $lines ) . "\n";
	}
}
