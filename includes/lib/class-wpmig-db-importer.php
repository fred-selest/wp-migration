<?php
/**
 * Resumable SQL importer (mysqli) with on-the-fly search & replace, table
 * renaming and MySQL / MariaDB compatibility fixes.
 *
 * No WordPress dependency (used by the standalone installer).
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPMIG_INSTALLER' ) ) {
	exit;
}

if ( ! class_exists( 'WPMIG_DB_Importer' ) ) {

	/**
	 * SQL importer.
	 */
	class WPMIG_DB_Importer {

		/**
		 * Connection.
		 *
		 * @var mysqli
		 */
		private $db;

		/**
		 * Table name map: source name => destination name.
		 *
		 * @var array
		 */
		private $tables;

		/**
		 * Replacer.
		 *
		 * @var WPMIG_Replacer|null
		 */
		private $replacer;

		/**
		 * SQL-escaped search strings (fast pre-filter on raw statements).
		 *
		 * @var array
		 */
		private $needles = array();

		/**
		 * Columns never modified by the search & replace: array( 'source_table' => array( 'col', ... ) ).
		 *
		 * @var array
		 */
		private $skip_columns;

		/**
		 * Server info.
		 *
		 * @var array
		 */
		private $server = array();

		/**
		 * Non fatal errors (limited list).
		 *
		 * @var array
		 */
		public $errors = array();

		/**
		 * Number of failed queries.
		 *
		 * @var int
		 */
		public $error_count = 0;

		/**
		 * Number of executed statements.
		 *
		 * @var int
		 */
		public $query_count = 0;

		/**
		 * Compatibility changes applied to CREATE TABLE statements.
		 *
		 * @var array
		 */
		public $notices = array();

		/**
		 * Constructor.
		 *
		 * @param mysqli              $db           Connection.
		 * @param array               $tables       Source table => destination table.
		 * @param WPMIG_Replacer|null $replacer     Search & replace.
		 * @param array               $skip_columns Columns to skip.
		 */
		public function __construct( $db, array $tables, $replacer = null, array $skip_columns = array() ) {
			$this->db           = $db;
			$this->tables       = $tables;
			$this->replacer     = ( $replacer && $replacer->has_pairs() ) ? $replacer : null;
			$this->skip_columns = $skip_columns;
			if ( $this->replacer ) {
				foreach ( $this->replacer->get_map() as $search => $unused ) {
					$this->needles[] = WPMIG_SQL::escape( $search );
				}
			}
			$this->detect_server();
		}

		/**
		 * Run a query, returns true on success.
		 *
		 * @param string $sql SQL.
		 * @return bool|mysqli_result
		 */
		private function query( $sql ) {
			try {
				return $this->db->query( $sql );
			} catch ( Exception $e ) {
				// PHP 8.1+ mysqli throws by default.
				return false;
			}
		}

		/**
		 * Collect server capabilities (charsets, collations, engines, packet size).
		 */
		private function detect_server() {
			$this->server = array(
				'version'     => '',
				'mariadb'     => false,
				'charsets'    => array(),
				'collations'  => array(),
				'engines'     => array(),
				'max_packet'  => 1048576,
				'default_col' => array(),
			);
			$res = $this->query( 'SELECT VERSION() AS v, @@max_allowed_packet AS p' );
			if ( $res ) {
				$row                          = $res->fetch_assoc();
				$this->server['version']      = $row['v'];
				$this->server['mariadb']      = false !== stripos( $row['v'], 'mariadb' );
				$this->server['max_packet']   = max( 65536, (int) $row['p'] );
			}
			$res = $this->query( 'SHOW CHARACTER SET' );
			if ( $res ) {
				while ( $row = $res->fetch_assoc() ) {
					$cs                                       = strtolower( $row['Charset'] );
					$this->server['charsets'][ $cs ]          = true;
					$this->server['default_col'][ $cs ]       = strtolower( $row['Default collation'] );
				}
			}
			$res = $this->query( 'SHOW COLLATION' );
			if ( $res ) {
				while ( $row = $res->fetch_assoc() ) {
					$this->server['collations'][ strtolower( $row['Collation'] ) ] = true;
				}
			}
			$res = $this->query( 'SHOW ENGINES' );
			if ( $res ) {
				while ( $row = $res->fetch_assoc() ) {
					if ( in_array( strtoupper( $row['Support'] ), array( 'YES', 'DEFAULT' ), true ) ) {
						$this->server['engines'][ strtolower( $row['Engine'] ) ] = true;
					}
				}
			}
		}

		/**
		 * Server info.
		 *
		 * @return array
		 */
		public function get_server() {
			return $this->server;
		}

		/**
		 * Prepare the session for the import.
		 *
		 * @param string $charset Connection charset used by the dump.
		 */
		public function init_session( $charset ) {
			$charset = $this->supported_charset( $charset ? $charset : 'utf8mb4' );
			$this->db->set_charset( $charset );
			$this->query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
			$this->query( 'SET SESSION foreign_key_checks = 0' );
			$this->query( 'SET SESSION unique_checks = 0' );
			$this->query( "SET SESSION time_zone = '+00:00'" );
			$this->query( 'SET SESSION innodb_strict_mode = 0' );
		}

		/**
		 * Map a charset to one supported by the server.
		 *
		 * @param string $charset Charset.
		 * @return string
		 */
		public function supported_charset( $charset ) {
			$charset = strtolower( $charset );
			if ( 'utf8mb3' === $charset ) {
				$charset = isset( $this->server['charsets']['utf8mb3'] ) ? 'utf8mb3' : 'utf8';
			}
			if ( empty( $this->server['charsets'] ) || isset( $this->server['charsets'][ $charset ] ) ) {
				return $charset;
			}
			if ( 'utf8mb4' === $charset || 'utf8' === $charset ) {
				return isset( $this->server['charsets']['utf8'] ) ? 'utf8' : 'utf8mb3';
			}
			return 'utf8';
		}

		/**
		 * Map a collation to one supported by the server.
		 *
		 * @param string $collation Collation.
		 * @return string
		 */
		public function supported_collation( $collation ) {
			$collation = strtolower( $collation );
			if ( empty( $this->server['collations'] ) || isset( $this->server['collations'][ $collation ] ) ) {
				return $collation;
			}
			$parts   = explode( '_', $collation, 2 );
			$charset = $parts[0];
			$suffix  = isset( $parts[1] ) ? $parts[1] : 'general_ci';
			// MariaDB 10.10+ lists charset independent collations (uca1400_ai_ci) without the charset prefix.
			if ( isset( $this->server['collations'][ $suffix ] ) && isset( $this->server['charsets'][ $charset ] ) ) {
				return $collation;
			}
			// utf8mb3_xxx <=> utf8_xxx.
			if ( 'utf8mb3' === $charset && isset( $this->server['collations'][ 'utf8_' . $suffix ] ) ) {
				return 'utf8_' . $suffix;
			}
			if ( 'utf8' === $charset && isset( $this->server['collations'][ 'utf8mb3_' . $suffix ] ) ) {
				return 'utf8mb3_' . $suffix;
			}
			$new_charset = $this->supported_charset( $charset );
			if ( $new_charset !== $charset && isset( $this->server['collations'][ $new_charset . '_' . $suffix ] ) ) {
				return $new_charset . '_' . $suffix;
			}
			$candidates = array( $new_charset . '_unicode_520_ci', $new_charset . '_unicode_ci', $new_charset . '_general_ci' );
			if ( false !== strpos( $suffix, '_bin' ) || 'bin' === $suffix ) {
				array_unshift( $candidates, $new_charset . '_bin' );
			}
			foreach ( $candidates as $candidate ) {
				if ( isset( $this->server['collations'][ $candidate ] ) ) {
					return $candidate;
				}
			}
			return isset( $this->server['default_col'][ $new_charset ] ) ? $this->server['default_col'][ $new_charset ] : 'utf8_general_ci';
		}

		/**
		 * Rename tables and fix incompatibilities in a CREATE TABLE statement.
		 *
		 * @param string $sql Statement.
		 * @return string
		 */
		public function fix_create( $sql ) {
			$sql = $this->rename_identifiers( $sql );
			$old = $sql;

			// Collations.
			$self = $this;
			$sql  = preg_replace_callback(
				'/\b(COLLATE)(\s*=\s*|\s+)`?([a-z0-9_]+)`?/i',
				function ( $m ) use ( $self ) {
					return $m[1] . $m[2] . $self->supported_collation( $m[3] );
				},
				$sql
			);
			// Charsets.
			$sql = preg_replace_callback(
				'/\b(CHARSET|CHARACTER SET)(\s*=\s*|\s+)`?([a-z0-9_]+)`?/i',
				function ( $m ) use ( $self ) {
					return $m[1] . $m[2] . $self->supported_charset( $m[3] );
				},
				$sql
			);
			// Storage engines.
			$engines = $this->server['engines'];
			$sql     = preg_replace_callback(
				'/\bENGINE\s*=\s*`?([a-z0-9_]+)`?/i',
				function ( $m ) use ( $engines ) {
					if ( empty( $engines ) || isset( $engines[ strtolower( $m[1] ) ] ) ) {
						return $m[0];
					}
					return 'ENGINE=' . ( isset( $engines['innodb'] ) ? 'InnoDB' : 'MyISAM' );
				},
				$sql
			);
			// MariaDB (Aria) specific table options, unknown to MySQL.
			if ( ! $this->server['mariadb'] ) {
				$sql = preg_replace( '/\s+(PAGE_CHECKSUM|TRANSACTIONAL|PAGE_COMPRESSED|PAGE_COMPRESSION_LEVEL|IETF_QUOTES)\s*=\s*\'?\w+\'?/i', '', $sql );
			}
			// MariaDB 10.2+ writes current_timestamp(), older MySQL expects CURRENT_TIMESTAMP.
			$sql = preg_replace( '/\bcurrent_timestamp\(\)/i', 'CURRENT_TIMESTAMP', $sql );

			if ( $old !== $sql ) {
				$this->notices[] = 'Compatibilité : définition de table adaptée au serveur de destination.';
				$this->notices   = array_values( array_unique( $this->notices ) );
			}
			return $sql;
		}

		/**
		 * Replace known table names in backticked identifiers.
		 *
		 * @param string $sql SQL.
		 * @return string
		 */
		public function rename_identifiers( $sql ) {
			$tables = $this->tables;
			return preg_replace_callback(
				'/`((?:[^`]|``)+)`/',
				function ( $m ) use ( $tables ) {
					$name = str_replace( '``', '`', $m[1] );
					return isset( $tables[ $name ] ) ? WPMIG_SQL::quote_id( $tables[ $name ] ) : $m[0];
				},
				$sql
			);
		}

		/**
		 * Rename the table in the head of an INSERT statement (fast, no full parse).
		 *
		 * @param string $sql SQL.
		 * @return string
		 */
		private function rename_insert( $sql ) {
			if ( preg_match( '/^INSERT\s+(?:IGNORE\s+)?INTO\s+`((?:[^`]|``)+)`/i', $sql, $m ) ) {
				$name = str_replace( '``', '`', $m[1] );
				if ( isset( $this->tables[ $name ] ) ) {
					$pos = strpos( $sql, $m[0] ) + strlen( $m[0] ) - strlen( $m[1] ) - 2;
					return substr( $sql, 0, $pos ) . WPMIG_SQL::quote_id( $this->tables[ $name ] ) . substr( $sql, $pos + strlen( $m[1] ) + 2 );
				}
			}
			return $sql;
		}

		/**
		 * Does the raw statement need the search & replace?
		 *
		 * @param string $sql SQL.
		 * @return bool
		 */
		private function needs_replace( $sql ) {
			foreach ( $this->needles as $needle ) {
				if ( false !== strpos( $sql, $needle ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Import a dump file from $offset until $deadline.
		 *
		 * @param string $file     Dump.
		 * @param int    $offset   Start offset.
		 * @param float  $deadline Timestamp (microtime) to stop at; 0 = no limit.
		 * @return array array( 'offset' => int, 'done' => bool, 'size' => int )
		 * @throws WPMIG_Exception On fatal error.
		 */
		public function import( $file, $offset, $deadline ) {
			$fh = @fopen( $file, 'rb' );
			if ( ! $fh ) {
				throw new WPMIG_Exception( 'Fichier SQL introuvable : ' . $file );
			}
			$size = filesize( $file );
			fseek( $fh, $offset );
			$done = false;
			$ran  = false;
			while ( true ) {
				// At least one statement per call: a slow connection must not stall the import.
				if ( $ran && $deadline && microtime( true ) >= $deadline ) {
					break;
				}
				$line = fgets( $fh );
				if ( false === $line ) {
					$done = true;
					break;
				}
				$sql = rtrim( $line, "\r\n" );
				if ( '' === trim( $sql ) || 0 === strpos( $sql, '--' ) || 0 === strpos( $sql, '/*' ) ) {
					$offset = ftell( $fh );
					continue;
				}
				if ( ';' !== substr( $sql, -1 ) ) {
					fclose( $fh );
					throw new WPMIG_Exception( 'Fichier SQL incomplet ou corrompu à l\'octet ' . $offset . '.' );
				}
				$this->execute_statement( $sql );
				$offset = ftell( $fh );
				$ran    = true;
			}
			fclose( $fh );
			return array(
				'offset' => $offset,
				'done'   => $done,
				'size'   => $size,
			);
		}

		/**
		 * Execute one statement of the dump.
		 *
		 * @param string $sql Statement.
		 * @throws WPMIG_Exception On fatal error.
		 */
		public function execute_statement( $sql ) {
			$upper = strtoupper( substr( $sql, 0, 14 ) );
			if ( 0 === strpos( $upper, 'INSERT' ) ) {
				$this->execute_insert( $sql );
				return;
			}
			if ( 0 === strpos( $upper, 'CREATE TABLE' ) ) {
				$this->execute_create( $this->fix_create( $sql ) );
				return;
			}
			if ( 0 === strpos( $upper, 'DROP TABLE' ) ) {
				$this->run( $this->rename_identifiers( $sql ), true );
				return;
			}
			// SET / LOCK / UNLOCK / session statements of the dump are ignored: the session is prepared by init_session().
		}

		/**
		 * CREATE TABLE with retries for common incompatibilities.
		 *
		 * @param string $sql Statement.
		 * @throws WPMIG_Exception When the table can't be created.
		 */
		private function execute_create( $sql ) {
			if ( $this->run( $sql, false ) ) {
				return;
			}
			$errno = $this->db->errno;
			// 1071: key too long, 1118: row size too large => try the DYNAMIC row format.
			if ( in_array( $errno, array( 1071, 1118 ), true ) ) {
				$retry = preg_replace( '/\s+ROW_FORMAT\s*=\s*\w+/i', '', rtrim( $sql, ';' ) ) . ' ROW_FORMAT=DYNAMIC;';
				if ( $this->run( $retry, false ) ) {
					$this->notices[] = 'Compatibilité : ROW_FORMAT=DYNAMIC appliqué à une table.';
					return;
				}
			}
			throw new WPMIG_Exception( 'Impossible de créer une table : ' . $this->db->error . ' — ' . substr( $sql, 0, 300 ) );
		}

		/**
		 * INSERT with search & replace and splitting (max_allowed_packet).
		 *
		 * @param string $sql Statement.
		 */
		private function execute_insert( $sql ) {
			$replace = $this->replacer && $this->needs_replace( $sql );
			$too_big = strlen( $sql ) > $this->server['max_packet'] - 1024;
			if ( ! $replace && ! $too_big ) {
				$this->run( $this->rename_insert( $sql ), true );
				return;
			}
			$parsed = WPMIG_SQL::parse_insert( $sql );
			if ( null === $parsed ) {
				$this->add_error( 'Requête non analysable, importée sans remplacement.', $sql );
				$this->run( $this->rename_insert( $sql ), true );
				return;
			}
			$table = $parsed['table'];
			if ( $replace ) {
				$skip = array();
				if ( isset( $this->skip_columns[ $table ] ) && $parsed['columns'] ) {
					foreach ( $parsed['columns'] as $i => $col ) {
						if ( in_array( $col, $this->skip_columns[ $table ], true ) ) {
							$skip[ $i ] = true;
						}
					}
				}
				foreach ( $parsed['rows'] as $r => $row ) {
					foreach ( $row as $i => $value ) {
						if ( 's' === $value[0] && ! isset( $skip[ $i ] ) ) {
							$parsed['rows'][ $r ][ $i ][1] = $this->replacer->replace( $value[1] );
						}
					}
				}
			}
			$head  = $this->rename_insert( $parsed['head'] );
			$limit = $this->server['max_packet'] - 1024;
			$batch = '';
			foreach ( $parsed['rows'] as $row ) {
				$tuple = WPMIG_SQL::build_row( $row );
				if ( '' !== $batch && strlen( $head ) + strlen( $batch ) + strlen( $tuple ) + 2 > $limit ) {
					$this->run( $head . $batch . ';', true );
					$batch = '';
				}
				$batch .= ( '' === $batch ? '' : ',' ) . $tuple;
			}
			if ( '' !== $batch ) {
				$this->run( $head . $batch . ';', true );
			}
		}

		/**
		 * Execute a query and record errors.
		 *
		 * @param string $sql       SQL.
		 * @param bool   $log_error Record the error.
		 * @return bool
		 * @throws WPMIG_Exception When the connection is lost.
		 */
		private function run( $sql, $log_error ) {
			$this->query_count++;
			if ( $this->query( $sql ) ) {
				return true;
			}
			$errno = $this->db->errno;
			if ( in_array( $errno, array( 2006, 2013 ), true ) ) {
				throw new WPMIG_Exception( 'Connexion MySQL perdue (' . $this->db->error . '). Vérifiez max_allowed_packet / wait_timeout.' );
			}
			if ( $log_error ) {
				$this->add_error( $this->db->error, $sql );
			}
			return false;
		}

		/**
		 * Record a non fatal error.
		 *
		 * @param string $message Message.
		 * @param string $sql     SQL.
		 */
		private function add_error( $message, $sql ) {
			$this->error_count++;
			if ( count( $this->errors ) < 50 ) {
				$this->errors[] = $message . ' — ' . substr( $sql, 0, 200 );
			}
		}
	}
}
