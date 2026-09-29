<?php
/**
 * Search & replace in the database, with an analysis before the change and an
 * undo journal.
 *
 * Every text column of the site tables is read by pages of primary keys; the
 * replacement is serialization-safe (see WPMIG_Replacer). The old value of every
 * changed column is written to a journal before the row is updated, so that the
 * operation can be undone (a value edited since is left alone).
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search & replace.
 */
class WPMIG_Search {

	const STATE   = 'wpmig_search_state';
	const HISTORY = 'wpmig_search_history';

	/**
	 * Rows read at once.
	 */
	const BATCH = 100;

	/**
	 * Examples kept for the display.
	 */
	const SAMPLES = 40;

	/**
	 * Journals kept for undo.
	 */
	const KEEP = 5;

	/**
	 * State.
	 *
	 * @var array
	 */
	private $state;

	/**
	 * Replacer of the operation.
	 *
	 * @var WPMIG_Replacer|null
	 */
	private $replacer;

	/**
	 * Table definitions of this request.
	 *
	 * @var array
	 */
	private $infos = array();

	/**
	 * Columns never changed: identifiers and secrets (suffix => columns).
	 *
	 * @var array
	 */
	private static $protected = array(
		'options'     => array( 'option_name' ),
		'postmeta'    => array( 'meta_key' ),
		'usermeta'    => array( 'meta_key' ),
		'commentmeta' => array( 'meta_key' ),
		'termmeta'    => array( 'meta_key' ),
		'users'       => array( 'user_pass', 'user_activation_key' ),
	);

	/**
	 * Modes.
	 *
	 * @return array
	 */
	public static function modes() {
		return array(
			'text'  => 'Texte (toutes les occurrences)',
			'url'   => 'URL, domaine ou chemin (mots entiers)',
			'regex' => 'Expression régulière',
		);
	}

	/**
	 * Type of search that fits a text: an address, a domain or a path is searched
	 * as a whole word with its variants, anything else as plain text.
	 *
	 * @param string $search Search.
	 * @return string "url" or "text".
	 */
	public static function detect( $search ) {
		$s = trim( $search );
		if ( preg_match( '#^(https?:)?//\S#i', $s ) ) {
			return 'url';
		}
		if ( strlen( $s ) >= 4 && preg_match( '#^/[^\s/]\S*$#', $s ) && ! preg_match( '#[()\[\]\\*+?|^$]#', $s ) ) {
			return 'url';
		}
		if ( preg_match( '#^([a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9-]+)*\.[a-z]{2,}|localhost|\d{1,3}(\.\d{1,3}){3})(:\d+)?(/\S*)?$#i', $s ) ) {
			return 'url';
		}
		return 'text';
	}

	/**
	 * Constructor.
	 *
	 * @param array $state State.
	 */
	private function __construct( array $state ) {
		$this->state = $state;
	}

	/**
	 * Current operation (or null).
	 *
	 * @return WPMIG_Search|null
	 */
	public static function current() {
		$state = get_option( self::STATE );
		return is_array( $state ) && ! empty( $state['id'] ) ? new self( $state ) : null;
	}

	/**
	 * History of the replacements (newest first).
	 *
	 * @return array
	 */
	public static function history() {
		$h = get_option( self::HISTORY );
		return is_array( $h ) ? $h : array();
	}

	/**
	 * Is an operation running (not finished, not waiting for a decision)?
	 *
	 * @return bool
	 */
	public function busy() {
		return in_array( $this->state['status'], array( 'analyzing', 'applying', 'undoing' ), true );
	}

	/**
	 * Save the state.
	 */
	private function save() {
		update_option( self::STATE, $this->state, false );
	}

	/**
	 * Journal directory.
	 *
	 * @param string $id Operation id.
	 * @return string
	 */
	private static function dir( $id ) {
		$dir = WPMIG_Plugin::storage_dir() . 'search-' . preg_replace( '/[^a-f0-9]/', '', $id ) . '/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * Journal file.
	 *
	 * @param string $id Operation id.
	 * @return string
	 */
	private static function journal_file( $id ) {
		return self::dir( $id ) . 'undo.php';
	}

	/* ------------------------------------------------------------------ */
	/* Parameters                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Clean up the parameters of a request.
	 *
	 * @param array $p Parameters.
	 * @return array
	 * @throws WPMIG_Exception When invalid.
	 */
	public static function normalize( array $p ) {
		$modes  = self::modes();
		$search = isset( $p['search'] ) ? (string) $p['search'] : '';
		$mode   = isset( $p['mode'] ) && isset( $modes[ $p['mode'] ] ) ? $p['mode'] : 'text';
		if ( '' === $search ) {
			throw new WPMIG_Exception( 'Indiquez le texte à rechercher.' );
		}
		if ( isset( $p['mode'] ) && 'auto' === $p['mode'] ) {
			$mode = self::detect( $search );
		}
		$known  = WPMIG_DB_Exporter::site_tables();
		$tables = array();
		foreach ( isset( $p['tables'] ) ? (array) $p['tables'] : array() as $t ) {
			if ( isset( $known[ $t ] ) ) {
				$tables[] = $t;
			}
		}
		$out = array(
			'search'      => $search,
			'replace'     => isset( $p['replace'] ) ? (string) $p['replace'] : '',
			'mode'        => $mode,
			'ignore_case' => ! empty( $p['ignore_case'] ),
			'variants'    => ! isset( $p['variants'] ) || ! empty( $p['variants'] ),
			'www'         => ! isset( $p['www'] ) || ! empty( $p['www'] ),
			'guid'        => ! empty( $p['guid'] ),
			'tables'      => array_values( array_unique( $tables ) ),
		);
		self::replacer( $out ); // Validates the search.
		return $out;
	}

	/**
	 * Replacer of the parameters.
	 *
	 * @param array $p Normalized parameters.
	 * @return WPMIG_Replacer
	 * @throws WPMIG_Exception When the search is not usable.
	 */
	public static function replacer( array $p ) {
		$search  = $p['search'];
		$replace = $p['replace'];
		if ( 'regex' === $p['mode'] ) {
			$candidates = array();
			if ( preg_match( '/^([\/#~@!%])(.*)\1([a-zA-Z]*)$/s', $search, $m ) ) {
				$candidates[] = $search;
			}
			$candidates[] = '~' . str_replace( '~', '\\~', $search ) . '~';
			foreach ( $candidates as $pattern ) {
				if ( $p['ignore_case'] && ! preg_match( '/[a-zA-Z]*i[a-zA-Z]*$/', substr( $pattern, strrpos( $pattern, $pattern[0] ) ) ) ) {
					$pattern .= 'i';
				}
				$r = WPMIG_Replacer::from_regex( $pattern, $replace );
				if ( $r ) {
					return $r;
				}
			}
			throw new WPMIG_Exception( 'Expression régulière invalide. Écrivez-la avec ses délimiteurs, par exemple /motif/i.' );
		}
		$pairs = array( $search => $replace );
		$opts  = array(
			'ignore_case' => $p['ignore_case'],
			'boundary'    => false,
			'min'         => 1,
		);
		if ( 'url' === $p['mode'] ) {
			$opts['boundary'] = true;
			$opts['min']      = 3;
			$pairs            = array();
			if ( 0 === strpos( $search, '/' ) && 0 !== strpos( $search, '//' ) ) {
				$pairs = WPMIG_Replacer::build_path_pairs( $search, $replace );
			} else {
				$pairs = WPMIG_Replacer::build_url_pairs( $search, $replace );
				$www   = $p['www'] ? WPMIG_Replacer::www_variant( $search ) : null;
				if ( $www ) {
					$pairs += WPMIG_Replacer::build_url_pairs( $www, $replace );
				}
			}
		} elseif ( $p['variants'] ) {
			// The same text inside JSON ("http:\/\/...") and inside a URL ("http%3A%2F%2F...").
			$pairs[ str_replace( '/', '\\/', $search ) ]                   = str_replace( '/', '\\/', $replace );
			$pairs[ rawurlencode( $search ) ]                              = rawurlencode( $replace );
			$pairs[ str_replace( '%2F', '%2f', rawurlencode( $search ) ) ] = str_replace( '%2F', '%2f', rawurlencode( $replace ) );
		}
		$r = new WPMIG_Replacer( $pairs, $opts );
		if ( ! $r->has_pairs() ) {
			if ( 'url' === $p['mode'] ) {
				throw new WPMIG_Exception( 'Recherche trop courte ou identique au remplacement (3 caractères au minimum en mode URL).' );
			}
			throw new WPMIG_Exception( 'La recherche et le remplacement sont identiques.' );
		}
		return $r;
	}

	/* ------------------------------------------------------------------ */
	/* Life cycle                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Start an analysis.
	 *
	 * @param array $params Parameters.
	 * @return WPMIG_Search
	 * @throws WPMIG_Exception When impossible.
	 */
	public static function start( array $params ) {
		$current = self::current();
		if ( $current && $current->busy() ) {
			throw new WPMIG_Exception( 'Une opération de recherche / remplacement est déjà en cours.' );
		}
		$params = self::normalize( $params );
		$known  = WPMIG_DB_Exporter::site_tables();
		$names  = $params['tables'] ? $params['tables'] : array_keys( $known );
		// The settings come last: the address of the site changes the login cookies, so the session lasts as long as possible.
		$options = $GLOBALS['wpdb']->prefix . 'options';
		if ( in_array( $options, $names, true ) ) {
			$names   = array_merge( array_diff( $names, array( $options ) ), array( $options ) );
		}
		$total  = 0;
		foreach ( $names as $n ) {
			$total += $known[ $n ]['rows'];
		}
		$warnings = array();
		$probe    = self::replacer( $params );
		foreach ( array( 'siteurl', 'home' ) as $option ) {
			$value = (string) get_option( $option );
			if ( '' !== $value && $probe->replace( $value ) !== $value ) {
				$warnings[ $option ] = 'Ce remplacement change l\'adresse de ce site (réglage « ' . $option . ' » : ' . $value . ' devient ' . $probe->replace( $value ) . ') : une fois appliqué, le site ne répondra plus qu\'à la nouvelle adresse et vous serez déconnecté.';
			}
		}
		$self = new self(
			array(
				'id'      => WPMIG_Package::random_hex( 12 ),
				'warnings' => array_values( $warnings ),
				'status'  => 'analyzing',
				'params'  => $params,
				'queue'   => array_values( $names ),
				'pos'     => 0,
				'cursor'  => null,
				'rows'    => max( 1, $total ),
				'done_rows' => 0,
				'scanned' => 0,
				'tables'  => array(),
				'samples' => array(),
				'skipped' => array(),
				'started' => time(),
				'undo'    => array(),
			)
		);
		$self->save();
		return $self;
	}

	/**
	 * Start the replacement after the analysis.
	 *
	 * @throws WPMIG_Exception When there is nothing to confirm.
	 */
	public function confirm() {
		if ( 'analyzed' !== $this->state['status'] ) {
			throw new WPMIG_Exception( 'Aucune analyse à confirmer.' );
		}
		$this->state['analysis'] = $this->totals();
		$this->state['status']   = 'applying';
		$this->state['pos']      = 0;
		$this->state['cursor']   = null;
		$this->state['scanned']  = 0;
		$this->state['done_rows'] = 0;
		$this->state['tables']   = array();
		$this->state['samples']  = array();
		$this->save();
	}

	/**
	 * Forget the current operation.
	 */
	public function dismiss() {
		if ( $this->busy() ) {
			throw new WPMIG_Exception( 'Opération en cours : attendez sa fin.' );
		}
		delete_option( self::STATE );
	}

	/**
	 * Undo a replacement.
	 *
	 * @param string $id Operation id ('' for the current one).
	 * @return WPMIG_Search
	 * @throws WPMIG_Exception When impossible.
	 */
	public static function undo( $id = '' ) {
		$current = self::current();
		if ( $current && $current->busy() ) {
			throw new WPMIG_Exception( 'Une opération est déjà en cours.' );
		}
		if ( '' === $id && $current ) {
			$id = $current->state['id'];
		}
		$entry = null;
		foreach ( self::history() as $h ) {
			if ( $h['id'] === $id && 'done' === $h['status'] ) {
				$entry = $h;
			}
		}
		if ( ! $entry || ! is_file( self::journal_file( $id ) ) ) {
			throw new WPMIG_Exception( 'Cette opération ne peut plus être annulée (journal absent ou déjà annulée).' );
		}
		$self = new self(
			array(
				'id'      => $id,
				'status'  => 'undoing',
				'params'  => $entry['params'],
				'tables'  => array(),
				'samples' => array(),
				'skipped' => array(),
				'undo'    => array(
					'offset'   => 0,
					'restored' => 0,
					'kept'     => 0,
					'size'     => (int) filesize( self::journal_file( $id ) ),
				),
			)
		);
		$self->save();
		return $self;
	}

	/**
	 * Run until the deadline.
	 *
	 * @param float $budget Seconds.
	 * @return array Public state.
	 * @throws WPMIG_Exception On error.
	 */
	public function step( $budget ) {
		$deadline = microtime( true ) + $budget;
		try {
			if ( 'undoing' === $this->state['status'] ) {
				do {
					$this->undo_batch();
				} while ( 'undoing' === $this->state['status'] && microtime( true ) < $deadline );
			} elseif ( in_array( $this->state['status'], array( 'analyzing', 'applying' ), true ) ) {
				$this->replacer = self::replacer( $this->state['params'] );
				do {
					$this->batch( 'applying' === $this->state['status'] );
				} while ( in_array( $this->state['status'], array( 'analyzing', 'applying' ), true ) && microtime( true ) < $deadline );
			}
		} catch ( Exception $e ) {
			$this->save();
			throw $e;
		}
		$this->save();
		return $this->public_state();
	}

	/**
	 * Run to the end (command line).
	 *
	 * @param callable|null $progress Called with the public state after each step.
	 * @return array Public state.
	 * @throws WPMIG_Exception On error.
	 */
	public function run( $progress = null ) {
		do {
			$state = $this->step( 10 );
			if ( $progress ) {
				call_user_func( $progress, $state );
			}
		} while ( $this->busy() );
		return $state;
	}

	/* ------------------------------------------------------------------ */
	/* Scan                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Definition of a table: primary key, columns to search, filters.
	 *
	 * @param string $name Table name.
	 * @return array|null Null when there is nothing to do with this table.
	 */
	private function info( $name ) {
		global $wpdb;
		if ( array_key_exists( $name, $this->infos ) ) {
			return $this->infos[ $name ];
		}
		$suffix = 0 === strpos( $name, $wpdb->prefix ) ? substr( $name, strlen( $wpdb->prefix ) ) : $name;
		$pk     = array();
		$cols   = array();
		$skip   = isset( self::$protected[ $suffix ] ) ? self::$protected[ $suffix ] : array();
		if ( 'posts' === $suffix && empty( $this->state['params']['guid'] ) ) {
			$skip[] = 'guid';
		}
		foreach ( (array) $wpdb->get_results( 'SHOW COLUMNS FROM ' . WPMIG_SQL::quote_id( $name ), ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL
			if ( 'PRI' === $c['Key'] ) {
				$pk[] = $c['Field'];
			}
			if ( preg_match( '/^(char|varchar|tinytext|text|mediumtext|longtext|json)\b/i', $c['Type'] ) && ! in_array( $c['Field'], $skip, true ) ) {
				$cols[] = $c['Field'];
			}
		}
		$info = null;
		if ( ! $pk ) {
			if ( $cols ) {
				$this->state['skipped'][] = $name . ' (sans clé primaire)';
			}
		} elseif ( $cols ) {
			$cols = array_values( array_diff( $cols, $pk ) );
			if ( $cols ) {
				$info = array(
					'pk'     => $pk,
					'cols'   => $cols,
					'filter' => 'options' === $suffix ? "option_name NOT LIKE 'wpmig\\_%'" : '',
				);
			}
		}
		$this->infos[ $name ] = $info;
		return $info;
	}

	/**
	 * SQL condition that keeps the rows containing a search string (null: no filter).
	 *
	 * @param array $cols Columns.
	 * @return string|null
	 */
	private function prefilter( array $cols ) {
		$p = $this->state['params'];
		if ( 'regex' === $p['mode'] ) {
			return null;
		}
		$parts = array();
		foreach ( array_keys( $this->replacer->get_map() ) as $needle ) {
			if ( $p['ignore_case'] && preg_match( '/[^\x00-\x7F]/', $needle ) ) {
				return null;
			}
			$like = "'%" . esc_sql( $GLOBALS['wpdb']->esc_like( $needle ) ) . "%'";
			foreach ( $cols as $col ) {
				$col     = WPMIG_SQL::quote_id( $col );
				$parts[] = ( $p['ignore_case'] ? 'LOWER(' . $col . ')' : $col ) . ' LIKE ' . $like;
			}
		}
		return $parts ? '(' . implode( ' OR ', $parts ) . ')' : null;
	}

	/**
	 * Condition "after this primary key".
	 *
	 * @param array $pk     Columns.
	 * @param array $cursor Last values.
	 * @return string
	 */
	private static function after( array $pk, array $cursor ) {
		$cols = array();
		$vals = array();
		foreach ( $pk as $col ) {
			$v      = (string) $cursor[ $col ];
			$cols[] = WPMIG_SQL::quote_id( $col );
			$vals[] = preg_match( '/^-?\d{1,18}$/', $v ) ? $v : WPMIG_Sync_DB::quote( $v );
		}
		return '(' . implode( ',', $cols ) . ') > (' . implode( ',', $vals ) . ')';
	}

	/**
	 * Process one page of the current table.
	 *
	 * @param bool $apply Write the changes (otherwise only count them).
	 * @throws WPMIG_Exception On SQL error.
	 */
	private function batch( $apply ) {
		global $wpdb;
		$queue = $this->state['queue'];
		if ( $this->state['pos'] >= count( $queue ) ) {
			$this->finish( $apply );
			return;
		}
		$name = $queue[ $this->state['pos'] ];
		$info = $this->info( $name );
		if ( ! $info ) {
			$this->next_table();
			return;
		}
		$where = array();
		if ( $info['filter'] ) {
			$where[] = $info['filter'];
		}
		$pre = $this->prefilter( $info['cols'] );
		if ( $pre ) {
			$where[] = $pre;
		}
		if ( $this->state['cursor'] ) {
			$where[] = self::after( $info['pk'], $this->state['cursor'] );
		}
		$select = array();
		foreach ( array_merge( $info['pk'], $info['cols'] ) as $col ) {
			$select[] = WPMIG_SQL::quote_id( $col );
		}
		$order = array();
		foreach ( $info['pk'] as $col ) {
			$order[] = WPMIG_SQL::quote_id( $col );
		}
		$sql  = 'SELECT ' . implode( ',', $select ) . ' FROM ' . WPMIG_SQL::quote_id( $name ) . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . ' ORDER BY ' . implode( ',', $order ) . ' LIMIT ' . self::BATCH;
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( '' !== $wpdb->last_error ) {
			throw new WPMIG_Exception( 'Erreur SQL : ' . $wpdb->last_error );
		}
		if ( ! $rows ) {
			$this->next_table();
			return;
		}
		$stats = isset( $this->state['tables'][ $name ] ) ? $this->state['tables'][ $name ] : array(
			'rows'    => 0,
			'changed' => 0,
			'matches' => 0,
			'cols'    => array(),
		);
		$stats['rows'] += count( $rows );
		$journal        = '';
		$updates        = array();
		foreach ( $rows as $row ) {
			$key = array();
			foreach ( $info['pk'] as $col ) {
				$key[ $col ] = $row[ $col ];
			}
			$old = array();
			$new = array();
			foreach ( $info['cols'] as $col ) {
				$value = $row[ $col ];
				if ( null === $value || '' === $value ) {
					continue;
				}
				$before = $this->replacer->count;
				$out    = $this->replacer->replace( $value );
				if ( $out === $value ) {
					continue;
				}
				$old[ $col ]              = $value;
				$new[ $col ]              = $out;
				$stats['matches']        += max( 1, $this->replacer->count - $before );
				$stats['cols'][ $col ]    = ( isset( $stats['cols'][ $col ] ) ? $stats['cols'][ $col ] : 0 ) + 1;
				$this->sample( $name, $key, $col, $value, $out );
			}
			if ( ! $new ) {
				continue;
			}
			$stats['changed']++;
			if ( $apply ) {
				$hashes = array();
				foreach ( $new as $col => $out ) {
					$hashes[ $col ] = md5( $out );
				}
				$journal  .= wp_json_encode(
					array(
						't' => $name,
						'k' => WPMIG_Sync_Source::encode_row( $key ),
						'o' => WPMIG_Sync_Source::encode_row( $old ),
						'h' => $hashes,
					)
				) . "\n";
				$updates[] = array( $key, $new );
			}
		}
		if ( $apply && $updates ) {
			// The old values are safe before the rows change.
			$file = self::journal_file( $this->state['id'] );
			if ( ! is_file( $file ) ) {
				file_put_contents( $file, "<?php exit; ?>\n" );
			}
			if ( false === file_put_contents( $file, $journal, FILE_APPEND ) ) {
				throw new WPMIG_Exception( 'Impossible d\'écrire le journal d\'annulation (espace disque ?).' );
			}
			foreach ( $updates as $u ) {
				$set = array();
				foreach ( $u[1] as $col => $out ) {
					$set[] = WPMIG_SQL::quote_id( $col ) . ' = ' . WPMIG_Sync_DB::quote( $out );
				}
				WPMIG_Sync_DB::exec( 'UPDATE ' . WPMIG_SQL::quote_id( $name ) . ' SET ' . implode( ', ', $set ) . ' WHERE ' . WPMIG_Sync_DB::where( $u[0] ) );
			}
		}
		$last = end( $rows );
		$cur  = array();
		foreach ( $info['pk'] as $col ) {
			$cur[ $col ] = $last[ $col ];
		}
		$this->state['cursor']         = $cur;
		$this->state['tables'][ $name ] = $stats;
		$this->state['scanned'] += count( $rows );
		if ( count( $rows ) < self::BATCH ) {
			$this->next_table();
		}
	}

	/**
	 * Move on to the next table.
	 */
	private function next_table() {
		$known = WPMIG_DB_Exporter::site_tables();
		$name  = $this->state['queue'][ $this->state['pos'] ];
		// Progress is measured in table rows: a table is done as a whole.
		$this->state['done_rows'] += isset( $known[ $name ] ) ? $known[ $name ]['rows'] : 0;
		$this->state['pos']++;
		$this->state['cursor'] = null;
	}

	/**
	 * Keep an example.
	 *
	 * @param string $table  Table.
	 * @param array  $key    Primary key.
	 * @param string $col    Column.
	 * @param string $before Old value.
	 * @param string $after  New value.
	 */
	private function sample( $table, array $key, $col, $before, $after ) {
		$n = 0;
		foreach ( $this->state['samples'] as $s ) {
			if ( $s['table'] === $table ) {
				$n++;
			}
		}
		if ( count( $this->state['samples'] ) >= self::SAMPLES || $n >= 6 ) {
			return;
		}
		// The changed part, with a little context around it.
		$len = min( strlen( $before ), strlen( $after ) );
		$i   = 0;
		while ( $i < $len && $before[ $i ] === $after[ $i ] ) {
			$i++;
		}
		$k = 0;
		while ( $k < $len - $i && $before[ strlen( $before ) - 1 - $k ] === $after[ strlen( $after ) - 1 - $k ] ) {
			$k++;
		}
		$cut = function ( $str, $from, $length ) {
			$part = function_exists( 'mb_strcut' ) ? mb_strcut( $str, max( 0, $from ), $length, 'UTF-8' ) : substr( $str, max( 0, $from ), $length );
			return wp_check_invalid_utf8( $part, true );
		};
		$mid_before = substr( $before, $i, strlen( $before ) - $i - $k );
		$mid_after  = substr( $after, $i, strlen( $after ) - $i - $k );

		$id = array();
		foreach ( $key as $name => $value ) {
			$id[] = $name . '=' . $value;
		}
		$this->state['samples'][] = array(
			'table'  => $table,
			'row'    => implode( ', ', $id ),
			'column' => $col,
			'pre'    => ( $i > 40 ? '…' : '' ) . $cut( $before, $i - 40, min( 40, $i ) ),
			'before' => $cut( $mid_before, 0, 160 ) . ( strlen( $mid_before ) > 160 ? '…' : '' ),
			'after'  => $cut( $mid_after, 0, 160 ) . ( strlen( $mid_after ) > 160 ? '…' : '' ),
			'post'   => $cut( $before, strlen( $before ) - $k, 40 ) . ( $k > 40 ? '…' : '' ),
		);
	}

	/**
	 * End of the scan.
	 *
	 * @param bool $apply Was the replacement written?
	 */
	private function finish( $apply ) {
		if ( ! $apply ) {
			$this->state['status'] = 'analyzed';
			return;
		}
		$totals                  = $this->totals();
		$this->state['status']   = 'done';
		$this->state['finished'] = time();
		wp_cache_flush();
		$p       = $this->state['params'];
		$history = self::history();
		array_unshift(
			$history,
			array(
				'id'       => $this->state['id'],
				'finished' => time(),
				'params'   => $p,
				'changed'  => $totals['changed'],
				'matches'  => $totals['matches'],
				'tables'   => $totals['tables'],
				'status'   => 'done',
			)
		);
		// Old journals are removed.
		foreach ( array_slice( $history, self::KEEP ) as $i => $h ) {
			$dir = WPMIG_Plugin::storage_dir() . 'search-' . preg_replace( '/[^a-f0-9]/', '', $h['id'] ) . '/';
			if ( is_dir( $dir ) ) {
				WPMIG_Plugin::rrmdir( $dir );
			}
		}
		update_option( self::HISTORY, array_slice( $history, 0, 10 ), false );
	}

	/**
	 * Totals of the results.
	 *
	 * @return array
	 */
	private function totals() {
		$t = array(
			'changed' => 0,
			'matches' => 0,
			'tables'  => 0,
			'rows'    => 0,
		);
		foreach ( $this->state['tables'] as $s ) {
			$t['rows'] += $s['rows'];
			if ( $s['changed'] ) {
				$t['changed'] += $s['changed'];
				$t['matches'] += $s['matches'];
				$t['tables']++;
			}
		}
		return $t;
	}

	/* ------------------------------------------------------------------ */
	/* Undo                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Restore the next lines of the journal.
	 *
	 * @throws WPMIG_Exception On error.
	 */
	private function undo_batch() {
		global $wpdb;
		$file = self::journal_file( $this->state['id'] );
		$fh   = @fopen( $file, 'rb' ); // phpcs:ignore
		if ( ! $fh ) {
			throw new WPMIG_Exception( 'Journal d\'annulation illisible.' );
		}
		fseek( $fh, $this->state['undo']['offset'] );
		if ( 0 === $this->state['undo']['offset'] ) {
			fgets( $fh ); // Guard line of the journal.
		}
		$lines = 0;
		while ( $lines < 100 && false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore
			$lines++;
			$e = json_decode( $line, true );
			if ( ! is_array( $e ) || ! isset( $e['t'], $e['k'], $e['o'], $e['h'] ) ) {
				fclose( $fh );
				throw new WPMIG_Exception( 'Journal d\'annulation corrompu.' );
			}
			$key = WPMIG_Sync_DB::decode_row( $e['k'] );
			$old = WPMIG_Sync_DB::decode_row( $e['o'] );
			$cur = $wpdb->get_row( 'SELECT ' . implode( ',', array_map( array( 'WPMIG_SQL', 'quote_id' ), array_keys( $old ) ) ) . ' FROM ' . WPMIG_SQL::quote_id( $e['t'] ) . ' WHERE ' . WPMIG_Sync_DB::where( $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $old as $col => $value ) {
				if ( ! $cur || ! isset( $cur[ $col ] ) ) {
					$this->state['undo']['kept']++;
				} elseif ( $cur[ $col ] === $value ) {
					continue; // Never written (interrupted request).
				} elseif ( md5( $cur[ $col ] ) === $e['h'][ $col ] ) {
					WPMIG_Sync_DB::exec( 'UPDATE ' . WPMIG_SQL::quote_id( $e['t'] ) . ' SET ' . WPMIG_SQL::quote_id( $col ) . ' = ' . WPMIG_Sync_DB::quote( $value ) . ' WHERE ' . WPMIG_Sync_DB::where( $key ) );
					$this->state['undo']['restored']++;
				} else {
					// Edited since: the newer value is kept.
					$this->state['undo']['kept']++;
				}
			}
		}
		$this->state['undo']['offset'] = (int) ftell( $fh );
		$end                           = feof( $fh ) || false === fgets( $fh );
		fclose( $fh );
		if ( ! $end ) {
			return;
		}
		$this->state['status'] = 'undone';
		wp_cache_flush();
		$history = self::history();
		foreach ( $history as $i => $h ) {
			if ( $h['id'] === $this->state['id'] ) {
				$history[ $i ]['status'] = 'undone';
			}
		}
		update_option( self::HISTORY, $history, false );
		WPMIG_Plugin::rrmdir( self::dir( $this->state['id'] ) );
	}

	/* ------------------------------------------------------------------ */
	/* Display                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * State for the interface.
	 *
	 * @return array
	 */
	public function public_state() {
		$s      = $this->state;
		$tables = array();
		foreach ( $s['tables'] as $name => $t ) {
			if ( $t['changed'] ) {
				$tables[] = array(
					'name'    => $name,
					'rows'    => $t['changed'],
					'matches' => $t['matches'],
					'cols'    => array_keys( $t['cols'] ),
				);
			}
		}
		usort(
			$tables,
			function ( $a, $b ) {
				return $b['matches'] - $a['matches'];
			}
		);
		$pct   = 0;
		$table = '';
		if ( in_array( $s['status'], array( 'analyzing', 'applying' ), true ) ) {
			$pct   = min( 99, (int) floor( 100 * $s['done_rows'] / max( 1, $s['rows'] ) ) );
			$table = isset( $s['queue'][ $s['pos'] ] ) ? $s['queue'][ $s['pos'] ] : '';
		} elseif ( 'undoing' === $s['status'] ) {
			$pct = min( 99, (int) floor( 100 * $s['undo']['offset'] / max( 1, $s['undo']['size'] ) ) );
		}
		return array(
			'id'       => $s['id'],
			'status'   => $s['status'],
			'params'   => $s['params'],
			'progress' => $pct,
			'table'    => $table,
			'totals'   => $this->totals(),
			'tables'   => $tables,
			'samples'  => $s['samples'],
			'skipped'  => array_values( array_unique( $s['skipped'] ) ),
			'warnings' => isset( $s['warnings'] ) ? $s['warnings'] : array(),
			'undo'     => $s['undo'],
			'expected' => ! empty( $s['analysis'] ) ? $s['analysis'] : null,
		);
	}
}
