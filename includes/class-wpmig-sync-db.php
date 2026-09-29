<?php
/**
 * Database writes of the synchronization, with an undo journal: every change is
 * made of row deletions and insertions, each one recorded (full deleted row,
 * identity of the inserted row) so that a synchronization can be undone.
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Journaled database access.
 */
class WPMIG_Sync_DB {

	/**
	 * Entries of the current object (committed with it).
	 *
	 * @var array
	 */
	private $pending = array();

	/**
	 * Journal file: one line of entries per committed object, written at once
	 * (a request killed by the server loses nothing that was committed).
	 *
	 * @var string
	 */
	private $file = '';

	/**
	 * Primary key columns by table.
	 *
	 * @var array
	 */
	private static $keys = array();

	/**
	 * Auto-increment column by table.
	 *
	 * @var array
	 */
	private static $auto = array();

	/**
	 * Table name.
	 *
	 * @param string $suffix Table without prefix.
	 * @return string
	 */
	public static function t( $suffix ) {
		global $wpdb;
		return $wpdb->prefix . $suffix;
	}

	/**
	 * Does a table exist?
	 *
	 * @param string $suffix Table without prefix.
	 * @return bool
	 */
	public static function has_table( $suffix ) {
		global $wpdb;
		static $cache = array();
		if ( ! isset( $cache[ $suffix ] ) ) {
			$cache[ $suffix ] = self::t( $suffix ) === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::t( $suffix ) ) ) );
		}
		return $cache[ $suffix ];
	}

	/**
	 * Run a query.
	 *
	 * @param string $sql Query.
	 * @return int|bool
	 * @throws WPMIG_Exception On SQL error.
	 */
	public static function exec( $sql ) {
		global $wpdb;
		// Binary values are legitimate here: skip the charset check of wpdb.
		$wpdb->check_current_query = false;
		$shown                     = $wpdb->suppress_errors( true );
		$res                       = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->suppress_errors( $shown );
		if ( false === $res ) {
			throw new WPMIG_Exception( 'Erreur SQL : ' . $wpdb->last_error );
		}
		return $res;
	}

	/**
	 * Quote a value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function quote( $value ) {
		if ( null === $value ) {
			return 'NULL';
		}
		return "'" . esc_sql( (string) $value ) . "'";
	}

	/**
	 * WHERE clause from column => value pairs.
	 *
	 * @param array $cond Conditions.
	 * @return string
	 */
	public static function where( array $cond ) {
		$parts = array();
		foreach ( $cond as $col => $value ) {
			$parts[] = WPMIG_SQL::quote_id( $col ) . ( null === $value ? ' IS NULL' : ' = ' . self::quote( $value ) );
		}
		return $parts ? implode( ' AND ', $parts ) : '1=0';
	}

	/**
	 * Rows of a table.
	 *
	 * @param string       $suffix Table without prefix.
	 * @param string|array $where  SQL condition or column => value pairs.
	 * @return array
	 */
	public static function select( $suffix, $where ) {
		global $wpdb;
		$where = is_array( $where ) ? self::where( $where ) : $where;
		return (array) $wpdb->get_results( 'SELECT * FROM ' . WPMIG_SQL::quote_id( self::t( $suffix ) ) . ' WHERE ' . $where, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Primary key columns.
	 *
	 * @param string $suffix Table without prefix.
	 * @return array
	 */
	public static function key( $suffix ) {
		global $wpdb;
		if ( ! isset( self::$keys[ $suffix ] ) ) {
			$cols = array();
			$auto = null;
			foreach ( (array) $wpdb->get_results( 'SHOW COLUMNS FROM ' . WPMIG_SQL::quote_id( self::t( $suffix ) ), ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL
				if ( 'PRI' === $c['Key'] ) {
					$cols[] = $c['Field'];
				}
				if ( false !== stripos( $c['Extra'], 'auto_increment' ) ) {
					$auto = $c['Field'];
				}
			}
			self::$keys[ $suffix ] = $cols;
			self::$auto[ $suffix ] = $auto;
		}
		return self::$keys[ $suffix ];
	}

	/**
	 * Delete rows (recorded in the journal).
	 *
	 * @param string       $suffix Table without prefix.
	 * @param string|array $where  SQL condition or column => value pairs.
	 * @return array Deleted rows.
	 */
	public function delete( $suffix, $where ) {
		$where = is_array( $where ) ? self::where( $where ) : $where;
		$rows  = self::select( $suffix, $where );
		if ( ! $rows ) {
			return array();
		}
		foreach ( $rows as $row ) {
			$this->pending[] = array( 'd', $suffix, WPMIG_Sync_Source::encode_row( $row ) );
		}
		self::exec( 'DELETE FROM ' . WPMIG_SQL::quote_id( self::t( $suffix ) ) . ' WHERE ' . $where );
		return $rows;
	}

	/**
	 * Insert a row (recorded in the journal).
	 *
	 * @param string $suffix Table without prefix.
	 * @param array  $row    Row.
	 * @return int Insert id (auto-increment column), or 0.
	 */
	public function insert( $suffix, array $row ) {
		global $wpdb;
		self::key( $suffix );
		$cols = array();
		$vals = array();
		foreach ( $row as $col => $value ) {
			$cols[] = WPMIG_SQL::quote_id( $col );
			$vals[] = self::quote( $value );
		}
		self::exec( 'INSERT INTO ' . WPMIG_SQL::quote_id( self::t( $suffix ) ) . ' (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ')' );
		$id   = 0;
		$auto = self::$auto[ $suffix ];
		if ( $auto ) {
			$id           = isset( $row[ $auto ] ) ? (int) $row[ $auto ] : (int) $wpdb->insert_id;
			$row[ $auto ] = $id;
		}
		// Identity of the row: its primary key, or all its columns.
		$identity = array();
		foreach ( self::$keys[ $suffix ] ? self::$keys[ $suffix ] : array_keys( $row ) as $col ) {
			$identity[ $col ] = isset( $row[ $col ] ) ? $row[ $col ] : null;
		}
		$this->pending[] = array( 'i', $suffix, WPMIG_Sync_Source::encode_row( $identity ) );
		return $id;
	}

	/**
	 * Replace rows by a transformation of them (delete + insert, recorded).
	 *
	 * @param string   $suffix Table without prefix.
	 * @param string   $where  SQL condition.
	 * @param callable $change Receives a row, returns the new row or null to keep it.
	 * @return int Rows changed.
	 */
	public function change( $suffix, $where, $change ) {
		$count = 0;
		foreach ( self::select( $suffix, $where ) as $row ) {
			$new = call_user_func( $change, $row );
			if ( null === $new || $new === $row ) {
				continue;
			}
			$ident = array();
			foreach ( self::key( $suffix ) ? self::key( $suffix ) : array_keys( $row ) as $col ) {
				$ident[ $col ] = $row[ $col ];
			}
			$this->delete( $suffix, $ident );
			$this->insert( $suffix, $new );
			$count++;
		}
		return $count;
	}

	/**
	 * Journal file of the next changes.
	 *
	 * @param string $file Path.
	 */
	public function journal( $file ) {
		$this->file = $file;
		if ( ! is_file( $file ) ) {
			file_put_contents( $file, "<?php exit; ?>\n" );
		}
	}

	/**
	 * Append entries to the journal.
	 *
	 * @param array $entries Entries.
	 * @throws WPMIG_Exception On write error.
	 */
	private function append( array $entries ) {
		if ( ! $entries ) {
			return;
		}
		if ( '' === $this->file || false === file_put_contents( $this->file, wp_json_encode( $entries ) . "\n", FILE_APPEND ) ) {
			throw new WPMIG_Exception( 'Impossible d\'écrire le journal d\'annulation (espace disque ?).' );
		}
	}

	/**
	 * A file created by the synchronization.
	 *
	 * @param string $path Absolute path.
	 */
	public function file_created( $path ) {
		$this->append( array( array( 'f', '', $path ) ) );
	}

	/**
	 * Start an object.
	 */
	public function begin() {
		$this->pending = array();
		self::exec( 'START TRANSACTION' );
	}

	/**
	 * Keep the changes of the object.
	 */
	public function commit() {
		self::exec( 'COMMIT' );
		$this->append( $this->pending );
		$this->pending = array();
	}

	/**
	 * Cancel the changes of the object.
	 */
	public function rollback() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		$this->pending = array();
	}

	/**
	 * Undo a journal file.
	 *
	 * @param string $file Path.
	 * @return int Entries undone.
	 * @throws WPMIG_Exception On error.
	 */
	public static function undo( $file ) {
		$lines   = (array) @file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore
		$entries = array();
		foreach ( array_slice( $lines, 1 ) as $line ) {
			$batch = json_decode( $line, true );
			if ( ! is_array( $batch ) ) {
				throw new WPMIG_Exception( 'Journal d\'annulation illisible : ' . basename( $file ) );
			}
			$entries = array_merge( $entries, $batch );
		}
		self::exec( 'START TRANSACTION' );
		try {
			foreach ( array_reverse( $entries ) as $e ) {
				list( $op, $suffix, $data ) = $e;
				if ( 'f' === $op ) {
					@unlink( $data ); // phpcs:ignore
					continue;
				}
				$data = self::decode_row( $data );
				if ( 'i' === $op ) {
					self::exec( 'DELETE FROM ' . WPMIG_SQL::quote_id( self::t( $suffix ) ) . ' WHERE ' . self::where( $data ) );
				} else {
					$cols = array();
					$vals = array();
					foreach ( $data as $col => $value ) {
						$cols[] = WPMIG_SQL::quote_id( $col );
						$vals[] = self::quote( $value );
					}
					// REPLACE: a row rebuilt meanwhile (statistics of WooCommerce...) gives way to the saved one.
					self::exec( 'REPLACE INTO ' . WPMIG_SQL::quote_id( self::t( $suffix ) ) . ' (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ')' );
				}
			}
			self::exec( 'COMMIT' );
		} catch ( Exception $ex ) {
			self::exec( 'ROLLBACK' );
			throw $ex;
		}
		return count( $entries );
	}

	/**
	 * Decode the base64 values of a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function decode_row( array $row ) {
		foreach ( $row as $k => $v ) {
			if ( is_array( $v ) && isset( $v['b64'] ) ) {
				$row[ $k ] = base64_decode( $v['b64'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
		}
		return $row;
	}
}
