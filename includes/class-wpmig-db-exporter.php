<?php
/**
 * Resumable database exporter (uses $wpdb, so it also works with db.php drop-ins).
 *
 * Output rules (relied upon by the importer):
 *  - one statement per line;
 *  - explicit column list in INSERT statements;
 *  - binary columns written as hexadecimal literals;
 *  - generated (virtual) columns are skipped.
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database exporter.
 */
class WPMIG_DB_Exporter {

	const STATEMENT_SIZE = 524288;

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
	 * Dump file path.
	 *
	 * @param WPMIG_Package $package Package.
	 * @return string
	 */
	public static function dump_file( WPMIG_Package $package ) {
		return $package->work_dir() . 'database.sql';
	}

	/**
	 * Tables of this site (base tables only), with statistics.
	 *
	 * @return array
	 */
	public static function site_tables() {
		global $wpdb;
		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$status = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A );
		$tables = array();
		foreach ( (array) $status as $row ) {
			if ( empty( $row['Engine'] ) && isset( $row['Comment'] ) && 'VIEW' === strtoupper( $row['Comment'] ) ) {
				continue;
			}
			$tables[ $row['Name'] ] = array(
				'name'   => $row['Name'],
				'rows'   => (int) $row['Rows'],
				'size'   => (float) $row['Data_length'] + (float) $row['Index_length'],
				'engine' => $row['Engine'],
			);
		}
		return $tables;
	}

	/**
	 * Is this a log / cache table, whose data can be left out safely?
	 * Never true for WordPress or WooCommerce data tables.
	 *
	 * @param string $name Table name.
	 * @return bool
	 */
	public static function is_log_table( $name ) {
		global $wpdb;
		$suffix = strtolower( 0 === strpos( $name, $wpdb->prefix ) ? substr( $name, strlen( $wpdb->prefix ) ) : $name );
		$known  = array(
			'actionscheduler_logs', 'wfhits', 'wflogins', 'wfblockediplog', 'wfcrawlers', 'wffilechanges', 'wfknownfilelist', 'wfstatus',
			'redirection_404', 'redirection_logs', 'wsal_occurrences', 'wsal_metadata', 'simple_history', 'simple_history_contexts',
			'itsec_logs', 'itsec_temp', 'wpml_mails', 'umbrella_log', 'umbrella_backup', 'umbrella_task_backup', 'woocommerce_sessions',
			'woof_query_cache', 'wpmailsmtp_debug_events', 'mail_log', 'email_log', 'cerber_log', 'cerber_traffic', 'aiowps_audit_log',
			'aiowps_events', 'aiowps_failed_logins', 'aiowps_login_activity', 'statistics_visit', 'statistics_visitor', 'statistics_pages',
			'statistics_useronline', 'statistics_exclusions', 'statistics_search', 'burst_statistics', 'burst_sessions', 'litespeed_url',
			'litespeed_url_file', 'yoast_seo_links_log',
		);
		// Consent / privacy registers may have to be kept (GDPR): never suggested.
		if ( preg_match( '/gdpr|consent|privacy|cookie/', $suffix ) ) {
			return false;
		}
		if ( in_array( $suffix, $known, true ) ) {
			return true;
		}
		return (bool) preg_match( '/(^|_)(logs?|logging|debug_events|audit_log|hits|traffic|404s?|sessions|cache)$/', $suffix );
	}

	/**
	 * Pre-build database analysis.
	 *
	 * @param WPMIG_Package $package Package.
	 */
	public static function scan( WPMIG_Package $package ) {
		global $wpdb;
		$exclude = array_flip( $package->data['options']['exclude_tables'] );
		$tables  = array();
		$rows    = 0;
		$size    = 0;
		foreach ( self::site_tables() as $name => $t ) {
			$t['warn']           = '';
			$t['structure_only'] = isset( $exclude[ $name ] );
			if ( $t['structure_only'] ) {
				// The table is recreated empty on the destination: plugins keep working.
				$package->log( 'Données exclues (structure conservée) : ' . $name );
				$tables[] = $t;
				continue;
			}
			if ( $t['size'] > 5242880 && self::is_log_table( $name ) ) {
				$t['warn'] = 'Journal ou cache : ses données peuvent être exclues (la table sera recréée vide).';
			}
			$tables[] = $t;
			$rows    += $t['rows'];
			$size    += $t['size'];
		}
		foreach ( (array) $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N ) as $row ) {
			if ( isset( $row[1] ) && 'VIEW' === strtoupper( $row[1] ) && 0 === strpos( $row[0], $wpdb->prefix ) ) {
				$package->warn( 'Vue SQL non exportée : ' . $row[0] );
			}
		}
		$package->data['report']['db'] = array(
			'tables' => $tables,
			'rows'   => $rows,
			'size'   => $size,
		);
		$package->log( sprintf( 'Base de données : %d tables, ~%d lignes, %s.', count( $tables ), $rows, size_format( $size, 1 ) ) );
	}

	/**
	 * Run the export until $deadline.
	 *
	 * @param float $deadline Microtime or 0.
	 * @return bool True when finished.
	 * @throws WPMIG_Exception On error.
	 */
	public function run( $deadline ) {
		global $wpdb;
		$data  = &$this->package->data;
		$state = &$data['dump'];
		$file  = self::dump_file( $this->package );

		$wpdb->suppress_errors( true );
		$wpdb->query( "SET SESSION time_zone = '+00:00'" );
		$wpdb->query( 'SET SESSION sql_quote_show_create = 1' );

		if ( empty( $state ) ) {
			$tables  = array();
			$no_data = array();
			foreach ( $data['report']['db']['tables'] as $t ) {
				$tables[] = $t['name'];
				if ( ! empty( $t['structure_only'] ) ) {
					$no_data[] = $t['name'];
				}
			}
			$state = array(
				'tables'  => $tables,
				'no_data' => $no_data,
				'index'   => 0,
				'offset'  => 0,
				'table'   => null,
				'rows'    => 0,
				'written' => 0,
			);
			$header  = "-- WP Migration " . WPMIG_VERSION . " SQL dump\n";
			$header .= '-- Source : ' . home_url() . "\n";
			$header .= '-- Date : ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
			$header .= "-- Server : " . $wpdb->get_var( 'SELECT VERSION()' ) . "\n\n";
			$header .= '/*!40101 SET NAMES ' . $this->charset() . " */;\n";
			$header .= "/*!40014 SET FOREIGN_KEY_CHECKS=0 */;\n";
			$header .= "/*!40101 SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n";
			$header .= "/*!40103 SET TIME_ZONE='+00:00' */;\n\n";
			if ( false === file_put_contents( $file, $header ) ) {
				throw new WPMIG_Exception( 'Impossible d\'écrire le fichier SQL.' );
			}
			$state['offset'] = strlen( $header );
			$this->package->log( 'Export SQL : ' . count( $tables ) . ' tables.' );
		}

		$fh = fopen( $file, 'c+b' );
		if ( ! $fh ) {
			throw new WPMIG_Exception( 'Impossible d\'ouvrir le fichier SQL.' );
		}
		ftruncate( $fh, $state['offset'] );
		fseek( $fh, 0, SEEK_END );

		$total = max( 1, count( $state['tables'] ) );
		while ( $state['index'] < count( $state['tables'] ) ) {
			if ( $deadline && microtime( true ) >= $deadline ) {
				break;
			}
			$name = $state['tables'][ $state['index'] ];
			if ( null === $state['table'] ) {
				$state['table'] = $this->start_table( $fh, $name );
			}
			$finished = ( isset( $state['no_data'] ) && in_array( $name, $state['no_data'], true ) ) || $this->export_rows( $fh, $state['table'] );
			fflush( $fh );
			$state['offset'] = ftell( $fh );
			if ( $finished ) {
				$state['rows']           += $state['table']['done'];
				$state['counts'][ $name ] = (int) $state['table']['done'];
				$state['table'] = null;
				$state['index']++;
			}
			$data['progress'] = (int) ( 30 * $state['index'] / $total );
			$data['message']  = sprintf( 'Export de la base de données… table %d / %d (%s)', min( $state['index'] + 1, $total ), $total, $name );
			$this->package->maybe_save();
		}

		if ( $state['index'] < count( $state['tables'] ) ) {
			fclose( $fh );
			return false;
		}
		fwrite( $fh, "\n-- Fin du dump\n" );
		fflush( $fh );
		$state['offset'] = ftell( $fh );
		fclose( $fh );
		$this->package->log( sprintf( 'Export SQL terminé : %d lignes, %s.', $state['rows'], size_format( $state['offset'], 1 ) ) );
		return true;
	}

	/**
	 * Connection charset used for the dump.
	 *
	 * @return string
	 */
	private function charset() {
		global $wpdb;
		return ! empty( $wpdb->charset ) ? $wpdb->charset : 'utf8';
	}

	/**
	 * Write DROP / CREATE and prepare the table state.
	 *
	 * @param resource $fh   Dump handle.
	 * @param string   $name Table.
	 * @return array Table state.
	 * @throws WPMIG_Exception On error.
	 */
	private function start_table( $fh, $name ) {
		global $wpdb;
		$qname  = WPMIG_SQL::quote_id( $name );
		$create = $wpdb->get_row( 'SHOW CREATE TABLE ' . $qname, ARRAY_N ); // phpcs:ignore
		if ( empty( $create[1] ) ) {
			throw new WPMIG_Exception( 'Impossible de lire la structure de la table ' . $name . ' : ' . $wpdb->last_error );
		}
		// SHOW CREATE TABLE escapes newlines inside literals: raw newlines are only formatting.
		$sql = preg_replace( '/\s*\n\s*/', ' ', $create[1] );
		// AUTO_INCREMENT counters are not needed (and trigger warnings on some servers).
		$sql = preg_replace( '/\sAUTO_INCREMENT=\d+/i', '', $sql );
		$out = "\n-- Table " . $name . "\nDROP TABLE IF EXISTS " . $qname . ";\n" . $sql . ";\n";
		if ( false === fwrite( $fh, $out ) ) {
			throw new WPMIG_Exception( 'Erreur d\'écriture du fichier SQL (espace disque ?).' );
		}

		$columns = $wpdb->get_results( 'SHOW COLUMNS FROM ' . $qname, ARRAY_A ); // phpcs:ignore
		$cols    = array();
		$binary  = array();
		$bits    = array();
		$pk      = array();
		$pk_int  = false;
		foreach ( (array) $columns as $col ) {
			$extra = isset( $col['Extra'] ) ? strtoupper( $col['Extra'] ) : '';
			if ( false !== strpos( $extra, 'GENERATED' ) ) {
				continue;
			}
			$type   = strtolower( $col['Type'] );
			$cols[] = $col['Field'];
			if ( 0 === strpos( $type, 'bit' ) ) {
				// BIT values are read as integers (mysqlnd returns them as decimal strings).
				$bits[] = count( $cols ) - 1;
			} elseif ( preg_match( '/^(tiny|medium|long)?blob|^(var)?binary|geometry|point|linestring|polygon/', $type ) ) {
				$binary[] = count( $cols ) - 1;
			}
			if ( 'PRI' === $col['Key'] ) {
				$pk[]   = $col['Field'];
				$pk_int = (bool) preg_match( '/^(tiny|small|medium|big)?int/', $type );
			}
		}
		if ( ! $cols ) {
			throw new WPMIG_Exception( 'Impossible de lire les colonnes de la table ' . $name . '.' );
		}

		// Batch size adapted to the average row length (keeps memory usage low).
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $name ), ARRAY_A );
		$avg    = ! empty( $status['Avg_row_length'] ) ? (int) $status['Avg_row_length'] : 1024;
		$limit  = (int) max( 10, min( 2000, 4194304 / max( 1, $avg ) ) );

		return array(
			'name'   => $name,
			'cols'   => $cols,
			'binary' => $binary,
			'bits'   => $bits,
			'pk'     => ( 1 === count( $pk ) && $pk_int ) ? $pk[0] : null,
			'order'  => $pk,
			'where'  => $this->row_filter( $name ),
			'last'   => null,
			'offset' => 0,
			'limit'  => $limit,
			'done'   => 0,
		);
	}

	/**
	 * Optional row filters (transients, spam, revisions).
	 *
	 * @param string $name Table.
	 * @return string SQL condition or ''.
	 */
	private function row_filter( $name ) {
		global $wpdb;
		$opts = $this->package->data['options'];
		if ( $name === $wpdb->options ) {
			// Our own post-install flag and migration report must never travel with a package.
			$where = "option_name NOT IN ('wpmig_installed', 'wpmig_report')";
			if ( ! empty( $opts['skip_transients'] ) ) {
				$where .= " AND option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'";
			}
			return $where;
		}
		if ( $name === $wpdb->comments && ! empty( $opts['skip_spam'] ) ) {
			return "comment_approved NOT IN ('spam', 'trash')";
		}
		if ( $name === $wpdb->posts && ! empty( $opts['skip_revisions'] ) ) {
			return "post_type <> 'revision'";
		}
		return '';
	}

	/**
	 * Export one batch of rows.
	 *
	 * @param resource $fh    Dump handle.
	 * @param array    $table Table state (by reference).
	 * @return bool True when the table is finished.
	 * @throws WPMIG_Exception On error.
	 */
	private function export_rows( $fh, array &$table ) {
		global $wpdb;
		$select  = array();
		$columns = array();
		$bits    = array_flip( isset( $table['bits'] ) ? $table['bits'] : array() );
		foreach ( $table['cols'] as $i => $col ) {
			$columns[] = WPMIG_SQL::quote_id( $col );
			$select[]  = isset( $bits[ $i ] ) ? 'CAST(' . WPMIG_SQL::quote_id( $col ) . ' AS UNSIGNED)' : WPMIG_SQL::quote_id( $col );
		}
		$sql   = 'SELECT ' . implode( ',', $select ) . ' FROM ' . WPMIG_SQL::quote_id( $table['name'] );
		$where = array();
		if ( '' !== $table['where'] ) {
			$where[] = '(' . $table['where'] . ')';
		}
		if ( $table['pk'] ) {
			if ( null !== $table['last'] ) {
				$where[] = WPMIG_SQL::quote_id( $table['pk'] ) . ' > ' . $this->number( $table['last'] );
			}
			$sql .= $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
			$sql .= ' ORDER BY ' . WPMIG_SQL::quote_id( $table['pk'] ) . ' ASC LIMIT ' . (int) $table['limit'];
		} else {
			$sql .= $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
			if ( $table['order'] ) {
				$sql .= ' ORDER BY ' . implode( ',', array_map( array( 'WPMIG_SQL', 'quote_id' ), $table['order'] ) );
			}
			$sql .= ' LIMIT ' . (int) $table['offset'] . ', ' . (int) $table['limit'];
		}

		$rows = $wpdb->get_results( $sql, ARRAY_N ); // phpcs:ignore
		if ( $wpdb->last_error ) {
			throw new WPMIG_Exception( 'Erreur SQL lors de l\'export de ' . $table['name'] . ' : ' . $wpdb->last_error );
		}
		$wpdb->flush();
		if ( ! $rows ) {
			return true;
		}

		$head   = 'INSERT INTO ' . WPMIG_SQL::quote_id( $table['name'] ) . ' (' . implode( ',', $columns ) . ') VALUES ';
		$binary = array_flip( $table['binary'] );
		$pk_idx = $table['pk'] ? array_search( $table['pk'], $table['cols'], true ) : false;
		$batch  = '';
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $row as $i => $value ) {
				if ( null === $value ) {
					$values[] = 'NULL';
				} elseif ( isset( $bits[ $i ] ) ) {
					$values[] = $this->number( $value );
				} elseif ( isset( $binary[ $i ] ) ) {
					$values[] = '' === $value ? "''" : '0x' . bin2hex( $value );
				} else {
					$values[] = "'" . WPMIG_SQL::escape( $value ) . "'";
				}
			}
			$tuple = '(' . implode( ',', $values ) . ')';
			if ( '' !== $batch && strlen( $batch ) + strlen( $tuple ) > self::STATEMENT_SIZE ) {
				$this->write( $fh, $head . $batch . ";\n" );
				$batch = '';
			}
			$batch .= ( '' === $batch ? '' : ',' ) . $tuple;
			if ( false !== $pk_idx ) {
				$table['last'] = $row[ $pk_idx ];
			}
		}
		if ( '' !== $batch ) {
			$this->write( $fh, $head . $batch . ";\n" );
		}
		$count           = count( $rows );
		$table['done']  += $count;
		$table['offset'] += $count;
		return $count < $table['limit'];
	}

	/**
	 * Sanitize a numeric key value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function number( $value ) {
		return preg_match( '/^-?\d+$/', (string) $value ) ? (string) $value : '0';
	}

	/**
	 * Write to the dump.
	 *
	 * @param resource $fh   Handle.
	 * @param string   $data Data.
	 * @throws WPMIG_Exception On write error.
	 */
	private function write( $fh, $data ) {
		if ( false === fwrite( $fh, $data ) ) {
			throw new WPMIG_Exception( 'Erreur d\'écriture du fichier SQL (espace disque ?).' );
		}
	}
}
