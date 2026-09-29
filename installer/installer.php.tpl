<?php
/**
 * WP Migration — installeur autonome.
 *
 * Déposez ce fichier et l'archive .wpmig correspondante dans le dossier du nouveau
 * site (vide ou existant), puis ouvrez https://votre-domaine/installer.php
 * (ou en ligne de commande : php installer.php --help).
 *
 * Ce fichier est généré par l'extension WP Migration : il contient sa propre copie
 * du moteur d'extraction, d'import SQL et de remplacement d'URL, et ne dépend pas
 * de WordPress.
 *
 * @package WPMigration
 */

define( 'WPMIG_INSTALLER', '1.5.1' );

@ini_set( 'display_errors', '0' ); // phpcs:ignore
error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING );

$wpmig_config = /*WPMIG_CONFIG*/array();

/*WPMIG_LIB*/

/**
 * wp-config.php editor (tokenizer based, regex fallback).
 */
class WPMIG_Config_Editor {

	/**
	 * PHP code.
	 *
	 * @var string
	 */
	private $code;

	/**
	 * Constructor.
	 *
	 * @param string $code wp-config.php contents.
	 */
	public function __construct( $code ) {
		$this->code = (string) $code;
	}

	/**
	 * Current code.
	 *
	 * @return string
	 */
	public function code() {
		return $this->code;
	}

	/**
	 * Locate the define() calls of a constant.
	 *
	 * @param string $name Constant.
	 * @return array List of array( start, length, value_code ).
	 */
	public function find_defines( $name ) {
		$found = array();
		if ( function_exists( 'token_get_all' ) ) {
			$tokens  = @token_get_all( $this->code );
			$offsets = array();
			$pos     = 0;
			foreach ( $tokens as $i => $t ) {
				$offsets[ $i ] = $pos;
				$pos          += strlen( is_array( $t ) ? $t[1] : $t );
			}
			$count = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				$t = $tokens[ $i ];
				if ( ! is_array( $t ) || T_STRING !== $t[0] || 'define' !== strtolower( $t[1] ) ) {
					continue;
				}
				$prev = $this->prev_token( $tokens, $i );
				if ( null !== $prev && is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
					continue;
				}
				$j = $this->next_index( $tokens, $i );
				if ( null === $j || '(' !== $tokens[ $j ] ) {
					continue;
				}
				$k = $this->next_index( $tokens, $j );
				if ( null === $k || ! is_array( $tokens[ $k ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $k ][0] || self::unquote( $tokens[ $k ][1] ) !== $name ) {
					continue;
				}
				// Find the comma, then the matching closing parenthesis and the semicolon.
				$depth       = 1;
				$value_start = null;
				$end         = null;
				for ( $m = $j + 1; $m < $count; $m++ ) {
					$tok = $tokens[ $m ];
					if ( '(' === $tok || '[' === $tok || '{' === $tok || ( is_array( $tok ) && in_array( $tok[1], array( '{$', '${' ), true ) ) ) {
						$depth++;
					} elseif ( ')' === $tok || ']' === $tok || '}' === $tok ) {
						$depth--;
						if ( 0 === $depth ) {
							$end = $m;
							break;
						}
					} elseif ( ',' === $tok && 1 === $depth && null === $value_start ) {
						$value_start = $m + 1;
					}
				}
				if ( null === $end || null === $value_start ) {
					continue;
				}
				$semi = $this->next_index( $tokens, $end );
				$last = ( null !== $semi && ';' === $tokens[ $semi ] ) ? $semi : $end;
				$vcode = '';
				for ( $m = $value_start; $m < $end; $m++ ) {
					$vcode .= is_array( $tokens[ $m ] ) ? $tokens[ $m ][1] : $tokens[ $m ];
				}
				$start    = $offsets[ $i ];
				$stop     = $offsets[ $last ] + strlen( is_array( $tokens[ $last ] ) ? $tokens[ $last ][1] : $tokens[ $last ] );
				$found[]  = array( $start, $stop - $start, trim( $vcode ) );
			}
			return $found;
		}
		$re = '/define\s*\(\s*([\'"])' . preg_quote( $name, '/' ) . '\1\s*,\s*((?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|[^;\'"])*?)\)\s*;/s';
		if ( preg_match_all( $re, $this->code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $m ) {
				$found[] = array( $m[0][1], strlen( $m[0][0] ), trim( $m[2][0] ) );
			}
		}
		return $found;
	}

	/**
	 * Index of the next significant token.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      Index.
	 * @return int|null
	 */
	private function next_index( $tokens, $i ) {
		$count = count( $tokens );
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $j;
		}
		return null;
	}

	/**
	 * Previous significant token.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      Index.
	 * @return mixed
	 */
	private function prev_token( $tokens, $i ) {
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $tokens[ $j ];
		}
		return null;
	}

	/**
	 * Unquote a PHP string literal.
	 *
	 * @param string $literal Literal.
	 * @return string|null Null when it is not a plain literal.
	 */
	public static function unquote( $literal ) {
		$literal = trim( $literal );
		if ( strlen( $literal ) < 2 ) {
			return null;
		}
		$q = $literal[0];
		if ( ( "'" !== $q && '"' !== $q ) || substr( $literal, -1 ) !== $q ) {
			return null;
		}
		$inner = substr( $literal, 1, -1 );
		if ( "'" === $q ) {
			if ( preg_match( "/(?<!\\\\)'/", str_replace( '\\\\', '', $inner ) ) ) {
				return null; // Concatenation of several literals.
			}
			return preg_replace( "/\\\\([\\\\'])/", '$1', $inner );
		}
		if ( preg_match( '/(?<!\\\\)[$"]/', str_replace( '\\\\', '', $inner ) ) ) {
			return null; // Interpolation or concatenation.
		}
		return stripcslashes( $inner );
	}

	/**
	 * Literal value of a constant (null if absent or not a literal string).
	 *
	 * @param string $name Constant.
	 * @return string|null
	 */
	public function get_define( $name ) {
		$found = $this->find_defines( $name );
		if ( ! $found ) {
			return null;
		}
		$last = end( $found );
		return self::unquote( $last[2] );
	}

	/**
	 * Raw value code of a constant.
	 *
	 * @param string $name Constant.
	 * @return string|null
	 */
	public function get_define_code( $name ) {
		$found = $this->find_defines( $name );
		if ( ! $found ) {
			return null;
		}
		$last = end( $found );
		return $last[2];
	}

	/**
	 * Set (or add) a constant. $value is PHP code.
	 *
	 * @param string $name  Constant.
	 * @param string $value PHP code of the value.
	 */
	public function set_define( $name, $value ) {
		$found = $this->find_defines( $name );
		$code  = "define( '" . $name . "', " . $value . ' );';
		if ( $found ) {
			foreach ( array_reverse( $found ) as $f ) {
				$this->code = substr( $this->code, 0, $f[0] ) . $code . substr( $this->code, $f[0] + $f[1] );
			}
			return;
		}
		$this->insert( $code . "\n" );
	}

	/**
	 * Remove a constant.
	 *
	 * @param string $name Constant.
	 * @return bool Whether it was defined.
	 */
	public function remove_define( $name ) {
		$found = $this->find_defines( $name );
		foreach ( array_reverse( $found ) as $f ) {
			$this->code = substr( $this->code, 0, $f[0] ) . '/* ' . $name . ' supprimé par WP Migration */' . substr( $this->code, $f[0] + $f[1] );
		}
		return ! empty( $found );
	}

	/**
	 * Insert code before the "stop editing" marker / wp-settings.php include.
	 *
	 * @param string $code Code.
	 */
	private function insert( $code ) {
		$markers = array(
			'/\/\*\s*That\'s all, stop editing/i',
			'/\/\*\s*C\'est tout, ne touchez pas/i',
			'/\/\*\*\s*Absolute path to the WordPress directory/i',
			'/if\s*\(\s*!\s*defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)\s*\)/i',
			'/require_once\s*\(?\s*ABSPATH\s*\.\s*[\'"]wp-settings\.php/i',
		);
		foreach ( $markers as $re ) {
			if ( preg_match( $re, $this->code, $m, PREG_OFFSET_CAPTURE ) ) {
				$this->code = substr( $this->code, 0, $m[0][1] ) . $code . "\n" . substr( $this->code, $m[0][1] );
				return;
			}
		}
		if ( preg_match( '/\?>\s*$/', $this->code, $m, PREG_OFFSET_CAPTURE ) ) {
			$this->code = substr( $this->code, 0, $m[0][1] ) . $code . substr( $this->code, $m[0][1] );
			return;
		}
		$this->code .= "\n" . $code;
	}

	/**
	 * Table prefix.
	 *
	 * @return string|null
	 */
	public function get_prefix() {
		if ( preg_match( '/\$table_prefix\s*=\s*([\'"])([A-Za-z0-9_]*)\1\s*;/', $this->code, $m ) ) {
			return $m[2];
		}
		return null;
	}

	/**
	 * Set the table prefix.
	 *
	 * @param string $prefix Prefix.
	 */
	public function set_prefix( $prefix ) {
		$line  = '$table_prefix = ' . var_export( $prefix, true ) . ';';
		$count = 0;
		$this->code = preg_replace_callback(
			'/\$table_prefix\s*=\s*[^;]*;/',
			function () use ( $line ) {
				return $line;
			},
			$this->code,
			-1,
			$count
		);
		if ( ! $count ) {
			$this->insert( $line . "\n" );
		}
	}

	/**
	 * Replace the authentication keys and salts.
	 */
	public function regenerate_salts() {
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $key ) {
			$this->set_define( $key, var_export( WPMIG_Installer::random_string( 64 ), true ) );
		}
	}

	/**
	 * Apply a text transformation to the code.
	 *
	 * @param callable $callback Callback.
	 */
	public function transform( $callback ) {
		$this->code = call_user_func( $callback, $this->code );
	}

	/**
	 * Check the PHP syntax (PHP 7+).
	 *
	 * @return string|true Error message or true.
	 */
	public function lint() {
		if ( ! defined( 'TOKEN_PARSE' ) || ! function_exists( 'token_get_all' ) ) {
			return true;
		}
		try {
			token_get_all( $this->code, TOKEN_PARSE );
		} catch ( ParseError $e ) {
			return $e->getMessage() . ' (ligne ' . $e->getLine() . ')';
		}
		return true;
	}

	/**
	 * Minimal wp-config.php.
	 *
	 * @return string
	 */
	public static function skeleton() {
		$code  = "<?php\n/**\n * wp-config.php généré par WP Migration.\n */\n\n";
		$code .= "define( 'DB_NAME', '' );\ndefine( 'DB_USER', '' );\ndefine( 'DB_PASSWORD', '' );\ndefine( 'DB_HOST', 'localhost' );\n";
		$code .= "define( 'DB_CHARSET', 'utf8mb4' );\ndefine( 'DB_COLLATE', '' );\n\n";
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $key ) {
			$code .= "define( '" . $key . "', " . var_export( WPMIG_Installer::random_string( 64 ), true ) . " );\n";
		}
		$code .= "\n\$table_prefix = 'wp_';\n\ndefine( 'WP_DEBUG', false );\n\n/* That's all, stop editing! Happy publishing. */\n\n";
		$code .= "if ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\n\nrequire_once ABSPATH . 'wp-settings.php';\n";
		return $code;
	}
}

/**
 * Installer.
 */
class WPMIG_Installer {

	/**
	 * Package configuration (embedded at build time).
	 *
	 * @var array
	 */
	private $config;

	/**
	 * This file.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Installation directory.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Working directory.
	 *
	 * @var string
	 */
	private $data_dir;

	/**
	 * Installation state.
	 *
	 * @var array
	 */
	private $state = array();

	/**
	 * Manifest cache.
	 *
	 * @var array|null
	 */
	private $manifest = null;

	/**
	 * Running from the command line.
	 *
	 * @var bool
	 */
	private $cli = false;

	/**
	 * Constructor.
	 *
	 * @param array  $config Configuration.
	 * @param string $file   Installer file.
	 */
	public function __construct( array $config, $file ) {
		$this->config   = $config;
		$this->file     = $file;
		$this->root     = rtrim( str_replace( '\\', '/', dirname( $file ) ), '/' );
		$this->data_dir = $this->root . '/wpmig-installer-data-' . ( isset( $config['package'] ) ? preg_replace( '/[^a-z0-9_]/i', '', $config['package'] ) : 'x' );
		$this->cli      = ( 'cli' === PHP_SAPI );
	}

	/* ------------------------------------------------------------------ */
	/* Utilities                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Random string.
	 *
	 * @param int  $length Length.
	 * @param bool $simple Only lowercase letters and digits.
	 * @return string
	 */
	public static function random_string( $length, $simple = false ) {
		$chars = $simple ? 'abcdefghijklmnopqrstuvwxyz0123456789' : 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#%^&*()-_[]{}<>~+=,.;:/?|';
		$max   = strlen( $chars ) - 1;
		$out   = '';
		for ( $i = 0; $i < $length; $i++ ) {
			if ( function_exists( 'random_int' ) ) {
				$n = random_int( 0, $max );
			} else {
				$n = mt_rand( 0, $max ); // phpcs:ignore
			}
			$out .= $chars[ $n ];
		}
		return $out;
	}

	/**
	 * Create the working directory.
	 *
	 * @throws WPMIG_Exception When not writable.
	 */
	private function ensure_data_dir() {
		if ( ! is_dir( $this->data_dir ) && ! @mkdir( $this->data_dir, 0755, true ) ) {
			throw new WPMIG_Exception( 'Impossible de créer le dossier de travail ' . $this->data_dir . ' : le dossier d\'installation doit être accessible en écriture.' );
		}
		$files = array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
		);
		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $this->data_dir . '/' . $name ) ) {
				@file_put_contents( $this->data_dir . '/' . $name, $content );
			}
		}
	}

	/**
	 * Load the state.
	 */
	private function load_state() {
		$file        = $this->data_dir . '/state.php';
		$this->state = array();
		if ( is_file( $file ) ) {
			// Stored as a PHP file starting with exit(), so it can't be read over HTTP.
			$raw  = (string) @file_get_contents( $file );
			$json = substr( $raw, strpos( $raw, "\n" ) + 1 );
			$data = json_decode( $json, true );
			if ( is_array( $data ) ) {
				$this->state = $data;
			}
		}
		$this->state = array_merge(
			array(
				'token'    => '',
				'status'   => 'new',
				'step'     => '',
				'params'   => array(),
				'progress' => 0,
				'message'  => '',
				'warnings' => array(),
				'notices'  => array(),
				'extract'  => array(),
				'db'       => array(),
				'result'   => array(),
			),
			$this->state
		);
	}

	/**
	 * Save the state.
	 */
	private function save_state() {
		$this->ensure_data_dir();
		$json = json_encode( $this->state );
		if ( false === $json ) {
			$this->state['warnings'] = array_map( array( __CLASS__, 'utf8' ), $this->state['warnings'] );
			$json                    = json_encode( $this->state );
		}
		$file = $this->data_dir . '/state.php';
		$tmp  = $file . '.tmp';
		$data = "<?php exit; ?>\n" . $json;
		if ( false === @file_put_contents( $tmp, $data ) || ! @rename( $tmp, $file ) ) {
			@file_put_contents( $file, $data );
		}
	}

	/**
	 * Make a string valid UTF-8.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function utf8( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
		}
		return preg_replace( '/[\x80-\xFF]/', '?', $value );
	}

	/**
	 * Append a line to the log.
	 *
	 * @param string $message Message.
	 */
	private function log( $message ) {
		if ( $this->cli ) {
			fwrite( STDOUT, '  ' . $message . "\n" );
		}
		if ( is_dir( $this->data_dir ) ) {
			@file_put_contents( $this->data_dir . '/install.log', gmdate( 'Y-m-d H:i:s' ) . ' ' . $message . "\n", FILE_APPEND );
		}
	}

	/**
	 * Add a warning.
	 *
	 * @param string $message Message.
	 */
	private function warn( $message ) {
		if ( count( $this->state['warnings'] ) < 200 ) {
			$this->state['warnings'][] = $message;
		}
		$this->log( 'AVERTISSEMENT : ' . $message );
	}

	/**
	 * Human readable size.
	 *
	 * @param float $bytes Bytes.
	 * @return string
	 */
	public static function size( $bytes ) {
		$units = array( 'o', 'Ko', 'Mo', 'Go', 'To' );
		$i     = 0;
		while ( $bytes >= 1024 && $i < 4 ) {
			$bytes /= 1024;
			$i++;
		}
		return number_format( $bytes, $i ? 1 : 0, ',', ' ' ) . ' ' . $units[ $i ];
	}

	/**
	 * Seconds of work per request.
	 *
	 * @return float
	 */
	private function budget() {
		$forced = getenv( 'WPMIG_BUDGET' );
		if ( false !== $forced && is_numeric( $forced ) ) {
			return (float) $forced;
		}
		if ( $this->cli ) {
			return 0;
		}
		$max = (int) ini_get( 'max_execution_time' );
		return $max > 0 ? min( 15, max( 3, $max * 0.4 ) ) : 15;
	}

	/**
	 * Raise PHP limits.
	 */
	private function raise_limits() {
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		@ignore_user_abort( true );
		$limit = ini_get( 'memory_limit' );
		if ( '-1' !== $limit && $this->to_bytes( $limit ) < 268435456 ) {
			@ini_set( 'memory_limit', '256M' );
		}
	}

	/**
	 * php.ini size to bytes.
	 *
	 * @param string $value Value.
	 * @return float
	 */
	private function to_bytes( $value ) {
		$value = trim( (string) $value );
		$num   = (float) $value;
		switch ( strtolower( substr( $value, -1 ) ) ) {
			case 'g':
				$num *= 1024;
				// Fall through.
			case 'm':
				$num *= 1024;
				// Fall through.
			case 'k':
				$num *= 1024;
		}
		return $num;
	}

	/**
	 * Recursive delete.
	 *
	 * @param string $dir Directory.
	 */
	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			@unlink( $dir );
			return;
		}
		foreach ( (array) @scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item || false === $item ) {
				continue;
			}
			$this->rrmdir( $dir . '/' . $item );
		}
		@rmdir( $dir );
	}

	/* ------------------------------------------------------------------ */
	/* Archive & manifest                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Locate the archive.
	 *
	 * @return string|null
	 */
	private function archive_path() {
		if ( ! empty( $this->config['archive'] ) && is_file( $this->root . '/' . $this->config['archive'] ) ) {
			return $this->root . '/' . $this->config['archive'];
		}
		$candidates = (array) glob( $this->root . '/*.wpmig' );
		foreach ( $candidates as $file ) {
			if ( ! empty( $this->config['package'] ) && false !== strpos( basename( $file ), $this->config['package'] ) ) {
				return $file;
			}
		}
		// Renamed archive: check the manifest of each candidate.
		foreach ( $candidates as $file ) {
			$manifest = $this->read_manifest_from( $file );
			if ( $manifest && ( empty( $this->config['package'] ) || $manifest['package'] === $this->config['package'] ) ) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * Read the manifest (first entry) of an archive.
	 *
	 * @param string $file Archive.
	 * @return array|null
	 */
	private function read_manifest_from( $file ) {
		try {
			$reader = new WPMIG_Archive_Reader( $file );
			$entry  = $reader->next_entry();
			if ( ! $entry || WPMIG_Archive::META_DIR . '/manifest.json' !== $entry['path'] ) {
				return null;
			}
			$data = json_decode( $reader->read_all_blocks(), true );
			$reader->close();
			return is_array( $data ) ? $data : null;
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Package manifest.
	 *
	 * @return array
	 * @throws WPMIG_Exception When the archive is missing.
	 */
	private function manifest() {
		if ( null !== $this->manifest ) {
			return $this->manifest;
		}
		$cache = $this->data_dir . '/manifest.php';
		if ( is_file( $cache ) ) {
			$raw  = (string) @file_get_contents( $cache );
			$data = json_decode( substr( $raw, strpos( $raw, "\n" ) + 1 ), true );
			if ( is_array( $data ) ) {
				$this->manifest = $data;
				return $data;
			}
		}
		$archive = $this->archive_path();
		if ( ! $archive ) {
			throw new WPMIG_Exception( 'Archive introuvable. Déposez le fichier ' . ( isset( $this->config['archive'] ) ? $this->config['archive'] : '*.wpmig' ) . ' dans le même dossier que l\'installeur.' );
		}
		$data = $this->read_manifest_from( $archive );
		if ( ! $data ) {
			throw new WPMIG_Exception( 'L\'archive ' . basename( $archive ) . ' est illisible ou corrompue.' );
		}
		$this->manifest = $data;
		if ( is_dir( $this->data_dir ) ) {
			@file_put_contents( $cache, "<?php exit; ?>\n" . json_encode( $data ) );
		}
		return $data;
	}

	/* ------------------------------------------------------------------ */
	/* Environment                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * URL of the installation directory, detected from the request.
	 *
	 * @return string
	 */
	private function detect_url() {
		if ( $this->cli || empty( $_SERVER['HTTP_HOST'] ) ) {
			return '';
		}
		$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( $_SERVER['HTTPS'] ) )
			|| ( isset( $_SERVER['SERVER_PORT'] ) && '443' === (string) $_SERVER['SERVER_PORT'] )
			|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) )
			|| ( isset( $_SERVER['HTTP_X_FORWARDED_SSL'] ) && 'on' === strtolower( $_SERVER['HTTP_X_FORWARDED_SSL'] ) )
			|| ( isset( $_SERVER['HTTP_CF_VISITOR'] ) && false !== strpos( $_SERVER['HTTP_CF_VISITOR'], 'https' ) );
		$host  = preg_replace( '/[^A-Za-z0-9.\-:\[\]]/', '', $_SERVER['HTTP_HOST'] );
		$path  = isset( $_SERVER['SCRIPT_NAME'] ) ? dirname( str_replace( '\\', '/', $_SERVER['SCRIPT_NAME'] ) ) : '';
		$path  = rtrim( str_replace( '\\', '/', $path ), '/.' );
		return ( $https ? 'https' : 'http' ) . '://' . $host . $path;
	}

	/**
	 * DB settings of an existing wp-config.php in the target directory.
	 *
	 * @return array
	 */
	private function existing_config() {
		$file = $this->root . '/wp-config.php';
		if ( ! is_file( $file ) ) {
			return array();
		}
		$editor = new WPMIG_Config_Editor( (string) @file_get_contents( $file ) );
		$out    = array();
		foreach ( array( 'DB_NAME' => 'db_name', 'DB_USER' => 'db_user', 'DB_PASSWORD' => 'db_pass', 'DB_HOST' => 'db_host' ) as $const => $key ) {
			$value = $editor->get_define( $const );
			if ( null !== $value ) {
				$out[ $key ] = $value;
			}
		}
		$prefix = $editor->get_prefix();
		if ( null !== $prefix ) {
			$out['db_prefix'] = $prefix;
		}
		return $out;
	}

	/**
	 * PHP version known to be supported by a WordPress version.
	 *
	 * @param string $wp WordPress version.
	 * @return string
	 */
	private function max_php_for_wp( $wp ) {
		$map = array(
			'6.7' => '8.4',
			'6.4' => '8.3',
			'6.3' => '8.2',
			'5.9' => '8.1',
			'5.6' => '8.0',
			'5.3' => '7.4',
			'5.0' => '7.3',
			'4.9' => '7.2',
		);
		foreach ( $map as $wp_min => $php ) {
			if ( version_compare( $wp, $wp_min, '>=' ) ) {
				return version_compare( $wp, '6.7', '>=' ) ? '99' : $php;
			}
		}
		return '7.1';
	}

	/**
	 * System checks.
	 *
	 * @return array
	 */
	private function checks() {
		$checks = array();
		$add    = function ( $label, $value, $status ) use ( &$checks ) {
			$checks[] = array(
				'label'  => $label,
				'value'  => $value,
				'status' => $status,
			);
		};
		$manifest = null;
		try {
			$manifest = $this->manifest();
		} catch ( Exception $e ) {
			$add( 'Archive', $e->getMessage(), 'error' );
		}
		$archive = $this->archive_path();
		if ( $archive ) {
			$trailer = WPMIG_Archive::read_trailer( $archive );
			if ( ! $trailer ) {
				$add( 'Archive', 'Archive incomplète : le transfert a probablement été interrompu. Renvoyez le fichier (en mode binaire si vous utilisez FTP).', 'error' );
			} else {
				$add( 'Archive', basename( $archive ) . ' — ' . self::size( filesize( $archive ) ) . ', ' . number_format( (float) $trailer['files'], 0, ',', ' ' ) . ' fichiers', 'ok' );
			}
			if ( PHP_INT_SIZE < 8 && filesize( $archive ) > 2000000000 ) {
				$add( 'PHP 32 bits', 'Ce serveur ne peut pas lire une archive de plus de 2 Go.', 'error' );
			}
		}

		$required = $manifest ? $manifest['site']['required_php'] : '5.6';
		$status   = version_compare( PHP_VERSION, $required, '>=' ) ? 'ok' : 'error';
		$value    = PHP_VERSION . ( 'ok' === $status ? '' : ' — WordPress ' . $manifest['site']['wp_version'] . ' exige PHP ' . $required . ' minimum.' );
		if ( $manifest && 'ok' === $status ) {
			$max = $this->max_php_for_wp( $manifest['site']['wp_version'] );
			if ( version_compare( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $max, '>' ) ) {
				$status = 'warning';
				$value .= ' — plus récent que la version de PHP prise en charge par WordPress ' . $manifest['site']['wp_version'] . ' (' . $max . ') : mettez WordPress à jour après la migration.';
			}
			if ( version_compare( PHP_VERSION, '7.0', '<' ) && version_compare( $manifest['site']['php_version'], '7.0', '>=' ) ) {
				$status = 'warning';
				$value .= ' — le site d\'origine utilisait PHP ' . $manifest['site']['php_version'] . '.';
			}
		}
		$add( 'Version de PHP', $value, $status );

		$add( 'Extension mysqli', extension_loaded( 'mysqli' ) ? 'disponible' : 'absente (obligatoire)', extension_loaded( 'mysqli' ) ? 'ok' : 'error' );
		$add( 'Extension zlib', function_exists( 'gzinflate' ) ? 'disponible' : 'absente (obligatoire pour décompresser l\'archive)', function_exists( 'gzinflate' ) ? 'ok' : 'error' );
		$add( 'Extension json', function_exists( 'json_decode' ) ? 'disponible' : 'absente', function_exists( 'json_decode' ) ? 'ok' : 'error' );
		$missing = array();
		foreach ( array( 'mbstring', 'curl', 'openssl', 'xml', 'zip' ) as $ext ) {
			if ( ! extension_loaded( $ext ) ) {
				$missing[] = $ext;
			}
		}
		if ( ! extension_loaded( 'gd' ) && ! extension_loaded( 'imagick' ) ) {
			$missing[] = 'gd/imagick';
		}
		$add( 'Extensions recommandées', $missing ? 'manquantes : ' . implode( ', ', $missing ) : 'toutes présentes', $missing ? 'warning' : 'ok' );

		$writable = is_writable( $this->root );
		if ( empty( $this->config['password_hash'] ) ) {
			$add( 'Protection', 'Installeur sans mot de passe : toute personne connaissant son adresse peut lancer l\'installation. Ne le laissez pas en ligne sans surveillance.', 'warning' );
		}
		$add( 'Dossier d\'installation', $this->root . ( $writable ? ' (accessible en écriture)' : ' — NON accessible en écriture' ), $writable ? 'ok' : 'error' );

		if ( $manifest ) {
			$needed = ( $manifest['db_only'] ? 0 : $manifest['stats']['size'] ) + ( $archive ? filesize( $archive ) * 0.3 : 0 );
			$free   = function_exists( 'disk_free_space' ) ? @disk_free_space( $this->root ) : false;
			if ( false !== $free ) {
				$add( 'Espace disque', self::size( $free ) . ' libres (besoin estimé : ' . self::size( $needed ) . ')', $free < $needed ? 'error' : 'ok' );
			}
		}
		if ( is_file( $this->root . '/wp-config.php' ) || is_file( $this->root . '/wp-settings.php' ) ) {
			$add( 'WordPress existant', 'Un WordPress est déjà présent dans ce dossier : ses fichiers seront écrasés (wp-config.php et .htaccess sont sauvegardés).', 'warning' );
		}
		$mem = ini_get( 'memory_limit' );
		$add( 'memory_limit', '-1' === $mem ? 'illimité' : $mem, ( '-1' !== $mem && $this->to_bytes( $mem ) < 67108864 ) ? 'warning' : 'ok' );
		$max = (int) ini_get( 'max_execution_time' );
		$add( 'max_execution_time', $max ? $max . ' s (traitement découpé en étapes courtes)' : 'illimité', 'ok' );
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : '';
		if ( false !== stripos( $server, 'nginx' ) ) {
			$add( 'Serveur web', 'nginx : les fichiers .htaccess sont ignorés. Pour les permaliens, la configuration doit contenir « try_files $uri $uri/ /index.php?$args; ».', 'warning' );
		} elseif ( $server ) {
			$add( 'Serveur web', $server, 'ok' );
		}
		return $checks;
	}

	/**
	 * Blocking errors in checks.
	 *
	 * @param array $checks Checks.
	 * @return bool
	 */
	private function has_blocking( array $checks ) {
		foreach ( $checks as $c ) {
			if ( 'error' === $c['status'] ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Database                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Connect to MySQL.
	 *
	 * @param array $p         Parameters.
	 * @param bool  $select_db Select the database.
	 * @return mysqli
	 * @throws WPMIG_Exception With a user friendly message.
	 */
	private function connect( array $p, $select_db = true ) {
		if ( ! class_exists( 'mysqli' ) ) {
			throw new WPMIG_Exception( 'L\'extension PHP mysqli est requise.' );
		}
		if ( function_exists( 'mysqli_report' ) ) {
			mysqli_report( MYSQLI_REPORT_OFF );
		}
		$host   = trim( $p['db_host'] );
		$port   = null;
		$socket = null;
		// Same syntaxes as WordPress: host, host:port, host:/path/to/socket, [ipv6]:port.
		if ( preg_match( '/^(\[[^\]]+\]|[^:]*)(?::(\d+))?(?::(\/.*))?$/', $host, $m ) ) {
			$host = trim( $m[1], '[]' );
			if ( ! empty( $m[2] ) ) {
				$port = (int) $m[2];
			}
			if ( ! empty( $m[3] ) ) {
				$socket = $m[3];
			}
		}
		if ( preg_match( '/^([^:]*):(\/.+)$/', trim( $p['db_host'] ), $m ) ) {
			$host   = $m[1];
			$socket = $m[2];
		}
		if ( '' === $host ) {
			$host = 'localhost';
		}
		$db = mysqli_init();
		if ( ! $db ) {
			throw new WPMIG_Exception( 'Initialisation de mysqli impossible.' );
		}
		@$db->options( MYSQLI_OPT_CONNECT_TIMEOUT, 10 );
		$ok = false;
		try {
			$ok = @$db->real_connect( $host, $p['db_user'], $p['db_pass'], $select_db ? $p['db_name'] : null, $port, $socket );
		} catch ( Exception $e ) {
			$ok = false;
		}
		if ( ! $ok ) {
			$errno = $db->connect_errno ? $db->connect_errno : mysqli_connect_errno();
			$error = $db->connect_error ? $db->connect_error : mysqli_connect_error();
			switch ( (int) $errno ) {
				case 1045:
					$msg = 'Accès refusé : identifiant ou mot de passe MySQL incorrect.';
					break;
				case 1044:
					$msg = 'L\'utilisateur « ' . $p['db_user'] . ' » n\'a pas accès à la base « ' . $p['db_name'] . ' ».';
					break;
				case 1049:
					$msg = 'La base de données « ' . $p['db_name'] . ' » n\'existe pas. Créez-la depuis le panneau de votre hébergeur (ou cochez « Créer la base »).';
					break;
				case 2002:
				case 2003:
				case 2005:
					$msg = 'Serveur MySQL injoignable (« ' . $p['db_host'] . ' »). Vérifiez l\'hôte indiqué par votre hébergeur (souvent « localhost »).';
					break;
				default:
					$msg = 'Connexion MySQL impossible : ' . $error;
			}
			$e = new WPMIG_Exception( $msg, (int) $errno );
			throw $e;
		}
		return $db;
	}

	/**
	 * Connect, creating the database if requested.
	 *
	 * @param array $p Parameters.
	 * @return mysqli
	 * @throws WPMIG_Exception On error.
	 */
	private function connect_or_create( array $p ) {
		try {
			return $this->connect( $p );
		} catch ( WPMIG_Exception $e ) {
			if ( 1049 !== $e->getCode() || empty( $p['db_create'] ) ) {
				throw $e;
			}
		}
		$db = $this->connect( $p, false );
		if ( ! $db->query( 'CREATE DATABASE IF NOT EXISTS ' . WPMIG_SQL::quote_id( $p['db_name'] ) . ' DEFAULT CHARACTER SET utf8mb4' ) && ! $db->query( 'CREATE DATABASE IF NOT EXISTS ' . WPMIG_SQL::quote_id( $p['db_name'] ) ) ) {
			throw new WPMIG_Exception( 'Impossible de créer la base « ' . $p['db_name'] . ' » : ' . $db->error . '. Créez-la depuis le panneau de votre hébergeur.' );
		}
		if ( ! $db->select_db( $p['db_name'] ) ) {
			throw new WPMIG_Exception( 'Base créée mais inaccessible : ' . $db->error );
		}
		$this->log( 'Base de données « ' . $p['db_name'] . ' » créée.' );
		return $db;
	}

	/**
	 * List the tables of the database.
	 *
	 * @param mysqli $db Connection.
	 * @return array name => type.
	 */
	private function list_tables( $db ) {
		$tables = array();
		$res    = $db->query( 'SHOW FULL TABLES' );
		if ( $res ) {
			while ( $row = $res->fetch_row() ) {
				$tables[ $row[0] ] = isset( $row[1] ) ? strtoupper( $row[1] ) : 'BASE TABLE';
			}
		}
		return $tables;
	}

	/**
	 * Test the database settings.
	 *
	 * @param array $p Parameters.
	 * @return array
	 */
	private function test_db( array $p ) {
		$result = array(
			'ok'       => false,
			'messages' => array(),
		);
		try {
			$p  = $this->sanitize_params( $p, false );
			$db = $this->connect_or_create( $p );
		} catch ( Exception $e ) {
			$result['messages'][] = array( 'error', $e->getMessage() );
			return $result;
		}
		$manifest = $this->manifest();
		$importer = new WPMIG_DB_Importer( $db, array() );
		$server   = $importer->get_server();
		$result['messages'][] = array( 'ok', 'Connexion réussie — ' . $server['version'] );

		$required = isset( $manifest['site']['required_mysql'] ) ? $manifest['site']['required_mysql'] : '5.0';
		if ( ! $server['mariadb'] && version_compare( preg_replace( '/[^0-9.].*$/', '', $server['version'] ), $required, '<' ) ) {
			$result['messages'][] = array( 'warning', 'MySQL ' . $server['version'] . ' est plus ancien que la version requise par WordPress (' . $required . ').' );
		}
		if ( empty( $server['charsets']['utf8mb4'] ) ) {
			$result['messages'][] = array( 'warning', 'Le serveur ne gère pas utf8mb4 : les émojis et certains caractères seront perdus.' );
		}
		if ( $server['max_packet'] < 1048576 ) {
			$result['messages'][] = array( 'warning', 'max_allowed_packet très faible (' . self::size( $server['max_packet'] ) . ') : les requêtes seront découpées.' );
		}

		// Privileges.
		$test = 'wpmig_test_' . self::random_string( 6, true );
		if ( ! $db->query( 'CREATE TABLE ' . WPMIG_SQL::quote_id( $test ) . ' (id INT NOT NULL PRIMARY KEY) ' ) ) {
			$result['messages'][] = array( 'error', 'Droits insuffisants : impossible de créer une table (' . $db->error . ').' );
			return $result;
		}
		$rename_ok = $db->query( 'RENAME TABLE ' . WPMIG_SQL::quote_id( $test ) . ' TO ' . WPMIG_SQL::quote_id( $test . 'b' ) );
		$db->query( 'DROP TABLE IF EXISTS ' . WPMIG_SQL::quote_id( $test ) );
		$db->query( 'DROP TABLE IF EXISTS ' . WPMIG_SQL::quote_id( $test . 'b' ) );
		if ( ! $rename_ok ) {
			$result['messages'][] = array( 'error', 'Droits insuffisants : impossible de renommer une table (ALTER / DROP requis).' );
			return $result;
		}

		$tables   = $this->list_tables( $db );
		$same     = 0;
		foreach ( $tables as $name => $type ) {
			if ( 0 === strpos( $name, $p['db_prefix'] ) ) {
				$same++;
			}
		}
		if ( 'empty' === $p['db_action'] && $tables ) {
			$result['messages'][] = array( 'warning', 'La base contient ' . count( $tables ) . ' table(s) : elles seront TOUTES supprimées.' );
		} elseif ( $same ) {
			$result['messages'][] = array( 'warning', $same . ' table(s) avec le préfixe « ' . $p['db_prefix'] . ' » existent déjà : celles du site importé les remplaceront.' );
		} else {
			$result['messages'][] = array( 'ok', 'Aucune table existante avec le préfixe « ' . $p['db_prefix'] . ' ».' );
		}
		foreach ( $manifest['tables'] as $t ) {
			$suffix = substr( $t['name'], strlen( $manifest['site']['table_prefix'] ) );
			if ( strlen( $p['db_prefix'] . $suffix ) > 64 ) {
				$result['messages'][] = array( 'error', 'Préfixe trop long : le nom de table « ' . $p['db_prefix'] . $suffix . ' » dépasse 64 caractères.' );
				return $result;
			}
		}
		$result['ok'] = true;
		return $result;
	}

	/* ------------------------------------------------------------------ */
	/* Parameters                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Validate the installation parameters.
	 *
	 * @param array $in   Raw parameters.
	 * @param bool  $full Validate everything (not only the DB part).
	 * @return array
	 * @throws WPMIG_Exception On invalid input.
	 */
	private function sanitize_params( array $in, $full = true ) {
		$manifest = $this->manifest();
		$get      = function ( $key, $default = '' ) use ( $in ) {
			return isset( $in[ $key ] ) && ! is_array( $in[ $key ] ) ? trim( (string) $in[ $key ] ) : $default;
		};
		$bool     = function ( $key ) use ( $in ) {
			return isset( $in[ $key ] ) && in_array( (string) $in[ $key ], array( '1', 'true', 'on', 'yes' ), true );
		};
		$p = array(
			'db_host'   => $get( 'db_host', 'localhost' ),
			'db_name'   => $get( 'db_name' ),
			'db_user'   => $get( 'db_user' ),
			'db_pass'   => isset( $in['db_pass'] ) ? (string) $in['db_pass'] : '',
			'db_prefix' => $get( 'db_prefix', $manifest['site']['table_prefix'] ),
			'db_action' => 'empty' === $get( 'db_action' ) ? 'empty' : 'replace',
			'db_create' => $bool( 'db_create' ),
		);
		if ( '' === $p['db_name'] || '' === $p['db_user'] ) {
			throw new WPMIG_Exception( 'Indiquez le nom de la base de données et l\'utilisateur MySQL.' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $p['db_prefix'] ) ) {
			throw new WPMIG_Exception( 'Préfixe de table invalide : lettres, chiffres et « _ » uniquement.' );
		}
		if ( ! $full ) {
			return $p;
		}
		$p['url_site'] = rtrim( $get( 'url_site', $this->detect_url() ), '/' );
		$p['url_home'] = rtrim( $get( 'url_home', $p['url_site'] ), '/' );
		foreach ( array( 'url_site', 'url_home' ) as $key ) {
			if ( ! preg_match( '#^https?://[^/\s]+(/[^\s]*)?$#i', $p[ $key ] ) ) {
				throw new WPMIG_Exception( 'URL invalide : « ' . $p[ $key ] . ' » (exemple : https://www.exemple.fr).' );
			}
		}
		$p['skip_files']  = $bool( 'skip_files' ) || ! empty( $manifest['db_only'] );
		$p['new_salts']   = $bool( 'new_salts' );
		$p['keep_guid']   = $bool( 'keep_guid' );
		$p['www_variants'] = $bool( 'www_variants' );
		$p['skip_verify']  = $bool( 'skip_verify' );
		$p['admin_user']  = $get( 'admin_user' );
		$p['admin_pass']  = isset( $in['admin_pass'] ) ? (string) $in['admin_pass'] : '';
		$p['admin_email'] = $get( 'admin_email' );
		if ( '' !== $p['admin_user'] ) {
			if ( ! preg_match( '/^[A-Za-z0-9_.@\- ]{1,60}$/', $p['admin_user'] ) ) {
				throw new WPMIG_Exception( 'Identifiant administrateur invalide.' );
			}
			if ( strlen( $p['admin_pass'] ) < 8 ) {
				throw new WPMIG_Exception( 'Le mot de passe administrateur doit contenir au moins 8 caractères.' );
			}
			if ( '' !== $p['admin_email'] && ! filter_var( $p['admin_email'], FILTER_VALIDATE_EMAIL ) ) {
				throw new WPMIG_Exception( 'Adresse e-mail administrateur invalide.' );
			}
		}
		$p['extra'] = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $get( 'extra_replace' ) ) as $line ) {
			$parts = explode( '=>', $line, 2 );
			if ( 2 === count( $parts ) && strlen( trim( $parts[0] ) ) >= 3 ) {
				$p['extra'][ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}
		return $p;
	}

	/**
	 * Search & replace pairs (old => new).
	 *
	 * @return array
	 */
	private function replacement_pairs() {
		$m     = $this->manifest();
		$p     = $this->state['params'];
		$site  = $m['site'];
		$pairs = array();

		$pairs += WPMIG_Replacer::build_url_pairs( $site['siteurl'], $p['url_site'] );
		$pairs += WPMIG_Replacer::build_url_pairs( $site['home'], $p['url_home'] );
		if ( ! empty( $p['www_variants'] ) ) {
			// Links written with / without "www." on the old site: the most common leftover after a move.
			foreach ( array( 'siteurl' => 'url_site', 'home' => 'url_home' ) as $old => $new ) {
				$variant = WPMIG_Replacer::www_variant( $site[ $old ] );
				if ( null !== $variant ) {
					$pairs += WPMIG_Replacer::build_url_pairs( $variant, $p[ $new ] );
				}
			}
		}
		if ( ! empty( $site['content_relocated'] ) || 0 !== strpos( $site['content_url'] . '/', $site['siteurl'] . '/' ) ) {
			$pairs += WPMIG_Replacer::build_url_pairs( $site['content_url'], $p['url_site'] . '/wp-content' );
		}
		$pairs += WPMIG_Replacer::build_path_pairs( $site['abspath'], $this->root );
		if ( ! empty( $site['content_relocated'] ) ) {
			$pairs += WPMIG_Replacer::build_path_pairs( $site['content_dir'], $this->root . '/wp-content' );
		}
		foreach ( $p['extra'] as $old => $new ) {
			$pairs[ $old ] = $new;
		}
		return $pairs;
	}

	/* ------------------------------------------------------------------ */
	/* Installation steps                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Start an installation.
	 *
	 * @param array $in Raw parameters.
	 * @throws WPMIG_Exception On invalid parameters.
	 */
	private function start( array $in ) {
		if ( 'running' === $this->state['status'] || 'complete' === $this->state['status'] ) {
			throw new WPMIG_Exception( 'Une installation est déjà en cours ou terminée.' );
		}
		$checks = $this->checks();
		if ( $this->has_blocking( $checks ) ) {
			throw new WPMIG_Exception( 'Des vérifications bloquantes ont échoué : corrigez-les avant de lancer l\'installation.' );
		}
		$params = $this->sanitize_params( $in );
		$test   = $this->test_db( $in );
		if ( ! $test['ok'] ) {
			$last = end( $test['messages'] );
			throw new WPMIG_Exception( $last[1] );
		}
		$this->state['params']   = $params;
		$this->state['status']   = 'running';
		$this->state['step']     = empty( $params['skip_verify'] ) ? 'verify' : 'extract';
		$this->state['started']  = time();
		$this->state['progress'] = 0;
		$this->state['message']  = empty( $params['skip_verify'] ) ? 'Vérification de l\'archive…' : 'Extraction de l\'archive…';
		$this->state['tmp_prefix'] = 'wpmt' . self::random_string( 4, true ) . '_';
		$this->log( 'Installation démarrée : ' . $this->manifest['site']['home'] . ' → ' . $params['url_home'] );
		$this->save_state();
	}

	/**
	 * Run the installation for one time slice.
	 *
	 * @return array Public state.
	 */
	private function step() {
		$budget   = $this->budget();
		$deadline = $budget ? microtime( true ) + $budget : 0;
		$this->raise_limits();
		while ( 'running' === $this->state['status'] ) {
			switch ( $this->state['step'] ) {
				case 'verify':
					if ( $this->step_verify( $deadline ) ) {
						$this->state['step']    = 'extract';
						$this->state['message'] = 'Extraction de l\'archive…';
					}
					break;
				case 'extract':
					if ( $this->step_extract( $deadline ) ) {
						$this->state['step']    = 'database';
						$this->state['message'] = 'Import de la base de données…';
					}
					break;
				case 'database':
					if ( $this->step_database( $deadline ) ) {
						$this->state['step']    = 'db_check';
						$this->state['message'] = 'Contrôle des tables importées…';
					}
					break;
				case 'db_check':
					if ( $this->step_db_check( $deadline ) ) {
						$this->state['step']    = 'db_fix';
						$this->state['message'] = 'Mise à jour des réglages…';
					}
					break;
				case 'db_fix':
					$this->step_db_fix();
					$this->state['step']     = 'config';
					$this->state['progress'] = 92;
					$this->state['message']  = 'Écriture de wp-config.php…';
					break;
				case 'config':
					$this->step_config();
					$this->state['step']     = 'swap';
					$this->state['progress'] = 95;
					$this->state['message']  = 'Activation des nouvelles tables…';
					break;
				case 'swap':
					$this->step_swap();
					$this->state['step']     = 'finalize';
					$this->state['progress'] = 98;
					$this->state['message']  = 'Finalisation…';
					break;
				case 'finalize':
					$this->step_finalize();
					break;
				default:
					throw new WPMIG_Exception( 'Étape inconnue.' );
			}
			$this->save_state();
			if ( $deadline && microtime( true ) >= $deadline ) {
				break;
			}
		}
		return $this->public_state();
	}

	/**
	 * Full archive check (CRC32 of every block) before anything is modified.
	 *
	 * @param float $deadline Microtime or 0.
	 * @return bool Finished.
	 * @throws WPMIG_Exception On corrupted archive.
	 */
	private function step_verify( $deadline ) {
		$st = &$this->state['verify'];
		if ( empty( $st ) ) {
			$st = array(
				'offset'  => 0,
				'files'   => 0,
				'in_file' => false,
			);
		}
		$archive = $this->archive_path();
		if ( ! $archive ) {
			throw new WPMIG_Exception( 'Archive introuvable.' );
		}
		$size   = max( 1, filesize( $archive ) );
		$reader = new WPMIG_Archive_Reader( $archive, $st['offset'] );
		$first  = true;
		try {
			while ( true ) {
				// At least one unit of work per request, whatever the time left.
				if ( ! $first && $deadline && microtime( true ) >= $deadline ) {
					break;
				}
				$first = false;
				if ( ! $st['in_file'] ) {
					$entry = $reader->next_entry();
					if ( null === $entry ) {
						$reader->close();
						$this->log( 'Archive vérifiée : ' . $st['files'] . ' entrées, sommes de contrôle correctes.' );
						return true;
					}
					$st['files']++;
					$st['in_file'] = 'f' === $entry['type'];
					$st['offset']  = $reader->tell();
				}
				// Every block is checked against its CRC32 by the reader.
				while ( $st['in_file'] ) {
					if ( false === $reader->next_block() ) {
						$st['in_file'] = false;
					}
					$st['offset'] = $reader->tell();
					if ( $deadline && microtime( true ) >= $deadline ) {
						break;
					}
				}
			}
		} catch ( WPMIG_Exception $e ) {
			$reader->close();
			// Nothing has been modified yet: start from scratch once the archive is sent again.
			$st = array();
			$this->log( 'ERREUR : ' . $e->getMessage() );
			$this->state['status'] = 'new';
			$this->state['step']   = '';
			$this->save_state();
			throw new WPMIG_Exception( $e->getMessage() . ' Aucun fichier ni aucune table n\'a été modifié : renvoyez l\'archive (en mode binaire) puis relancez l\'installation.' );
		}
		$reader->close();
		$this->state['progress'] = (int) ( 10 * $st['offset'] / $size );
		$this->state['message']  = 'Vérification de l\'archive… ' . self::size( $st['offset'] ) . ' / ' . self::size( $size );
		return false;
	}

	/**
	 * Extraction step.
	 *
	 * @param float $deadline Microtime or 0.
	 * @return bool Finished.
	 * @throws WPMIG_Exception On error.
	 */
	private function step_extract( $deadline ) {
		$st = &$this->state['extract'];
		if ( empty( $st ) ) {
			$st = array(
				'offset' => 0,
				'cur'    => null,
				'files'  => 0,
				'bytes'  => 0,
				'failed' => 0,
			);
		}
		$archive = $this->archive_path();
		if ( ! $archive ) {
			throw new WPMIG_Exception( 'Archive introuvable.' );
		}
		$size       = max( 1, filesize( $archive ) );
		$reader     = new WPMIG_Archive_Reader( $archive, $st['offset'] );
		$skip_files = ! empty( $this->state['params']['skip_files'] );
		$self_names = array( basename( $this->file ) => true, basename( $archive ) => true, basename( $this->data_dir ) => true );
		$renamed    = array( '.htaccess' => true, '.user.ini' => true, 'php.ini' => true, 'wp-config.php' => true );
		$last_save  = microtime( true );
		$first      = true;

		while ( true ) {
			// At least one unit of work per request, whatever the time left.
			if ( ! $first && $deadline && microtime( true ) >= $deadline ) {
				break;
			}
			$first = false;
			if ( null === $st['cur'] ) {
				$entry = $reader->next_entry();
				if ( null === $entry ) {
					$reader->close();
					$this->log( 'Extraction terminée : ' . $st['files'] . ' fichiers.' );
					if ( $st['failed'] ) {
						$this->warn( $st['failed'] . ' fichier(s) n\'ont pas pu être écrits (permissions ?). Voir install.log.' );
					}
					return true;
				}
				$path = $entry['path'];
				$dest = null;
				if ( WPMIG_Archive::META_DIR . '/database.sql' === $path ) {
					$dest = $this->data_dir . '/database.sql';
				} elseif ( 0 === strpos( $path, WPMIG_Archive::META_DIR . '/' ) ) {
					$dest = null;
				} elseif ( $skip_files ) {
					if ( ! empty( $this->state['db']['sql_extracted'] ) ) {
						// The dump is the second entry: nothing else to extract.
						$reader->close();
						return true;
					}
					$dest = null;
				} elseif ( ! WPMIG_Archive::is_safe_path( $path ) ) {
					$this->warn( 'Chemin dangereux ignoré dans l\'archive : ' . $path );
					$dest = null;
				} else {
					$is_root = false === strpos( $path, '/' );
					if ( $is_root && isset( $self_names[ $path ] ) ) {
						$dest = null;
					} elseif ( $is_root && isset( $renamed[ $path ] ) && 'f' === $entry['type'] ) {
						// Server specific files of the old host (PHP handlers, auto_prepend_file...) are
						// kept aside: they are a classic cause of "500 Internal Server Error" after a move.
						$dest = $this->root . '/' . $path . '.wpmig-source';
						$this->state['notices'][] = $path . ' d\'origine conservé sous le nom ' . $path . '.wpmig-source';
					} else {
						$dest = $this->root . '/' . $path;
					}
				}
				if ( 'd' === $entry['type'] ) {
					if ( null !== $dest && ! is_dir( $dest ) && ! @mkdir( $dest, 0755, true ) ) {
						$this->log( 'Dossier non créé : ' . $path );
						$st['failed']++;
					}
					$st['offset'] = $reader->tell();
					continue;
				}
				$st['cur'] = array(
					'dest'    => $dest,
					'path'    => $path,
					'written' => 0,
					'mtime'   => $entry['mtime'],
				);
				if ( null !== $dest ) {
					$dir = dirname( $dest );
					if ( ! is_dir( $dir ) ) {
						@mkdir( $dir, 0755, true );
					}
					if ( is_dir( $dest ) || false === @file_put_contents( $dest, '' ) ) {
						$this->log( 'Écriture impossible : ' . $path );
						$st['failed']++;
						$st['cur']['dest'] = null;
					}
				}
			}

			// Copy the blocks of the current entry.
			$cur = &$st['cur'];
			$fh  = null;
			if ( null !== $cur['dest'] ) {
				$fh = @fopen( $cur['dest'], 'c+b' );
				if ( ! $fh ) {
					$this->log( 'Écriture impossible : ' . $cur['path'] );
					$st['failed']++;
					$cur['dest'] = null;
				} else {
					ftruncate( $fh, $cur['written'] );
					fseek( $fh, 0, SEEK_END );
				}
			}
			$finished = false;
			while ( true ) {
				$block = $reader->next_block();
				if ( false === $block ) {
					$finished = true;
					break;
				}
				if ( $fh ) {
					if ( false === fwrite( $fh, $block ) ) {
						fclose( $fh );
						throw new WPMIG_Exception( 'Erreur d\'écriture de ' . $cur['path'] . ' (espace disque insuffisant ?).' );
					}
					$cur['written'] += strlen( $block );
				}
				$st['bytes'] += strlen( $block );
				$st['offset'] = $reader->tell();
				if ( $deadline && microtime( true ) >= $deadline ) {
					break;
				}
			}
			if ( $fh ) {
				fclose( $fh );
			}
			$st['offset'] = $reader->tell();
			if ( $finished ) {
				if ( null !== $cur['dest'] ) {
					if ( $cur['mtime'] ) {
						@touch( $cur['dest'], $cur['mtime'] );
					}
					if ( $this->data_dir . '/database.sql' === $cur['dest'] ) {
						$this->state['db']['sql_extracted'] = true;
					} else {
						$st['files']++;
					}
				}
				unset( $cur );
				$st['cur'] = null;
			} else {
				unset( $cur );
			}
			$this->state['progress'] = 10 + (int) ( 35 * $st['offset'] / $size );
			$this->state['message']  = 'Extraction de l\'archive… ' . $st['files'] . ' fichiers (' . self::size( $st['bytes'] ) . ')';
			if ( microtime( true ) - $last_save > 2 ) {
				$this->save_state();
				$last_save = microtime( true );
			}
		}
		$reader->close();
		return false;
	}

	/**
	 * Source table => temporary table.
	 *
	 * @return array
	 */
	private function table_map() {
		$m      = $this->manifest();
		$prefix = $m['site']['table_prefix'];
		$map    = array();
		foreach ( $m['tables'] as $t ) {
			$map[ $t['name'] ] = $this->state['tmp_prefix'] . substr( $t['name'], strlen( $prefix ) );
		}
		return $map;
	}

	/**
	 * Database import step.
	 *
	 * @param float $deadline Microtime or 0.
	 * @return bool Finished.
	 * @throws WPMIG_Exception On error.
	 */
	private function step_database( $deadline ) {
		$m    = $this->manifest();
		$p    = $this->state['params'];
		$st   = &$this->state['db'];
		$file = $this->data_dir . '/database.sql';
		if ( ! is_file( $file ) ) {
			throw new WPMIG_Exception( 'Le fichier SQL n\'a pas été extrait de l\'archive.' );
		}
		if ( ! isset( $st['offset'] ) ) {
			$st['offset']  = 0;
			$st['errors']  = 0;
			$st['queries'] = 0;
			$this->log( 'Import SQL (préfixe temporaire ' . $this->state['tmp_prefix'] . ').' );
			$pairs = $this->replacement_pairs();
			foreach ( $pairs as $old => $new ) {
				if ( false === strpos( $old, '\\' ) && false === strpos( $old, '%' ) ) {
					$this->log( 'Remplacement : ' . $old . ' → ' . $new );
				}
			}
		}
		$db       = $this->connect( $p );
		$replacer = new WPMIG_Replacer( $this->replacement_pairs() );
		$skip     = array();
		if ( ! empty( $p['keep_guid'] ) ) {
			$skip[ $m['site']['table_prefix'] . 'posts' ] = array( 'guid' );
		}
		$importer = new WPMIG_DB_Importer( $db, $this->table_map(), $replacer, $skip );
		$importer->init_session( $m['site']['db_charset'] );
		$res = $importer->import( $file, $st['offset'], $deadline );

		$st['offset']   = $res['offset'];
		$st['errors']  += $importer->error_count;
		$st['queries'] += $importer->query_count;
		foreach ( $importer->errors as $error ) {
			$this->log( 'Erreur SQL : ' . $error );
			if ( count( $this->state['warnings'] ) < 30 ) {
				$this->state['warnings'][] = 'Erreur SQL : ' . $error;
			}
		}
		foreach ( $importer->notices as $notice ) {
			if ( ! in_array( $notice, $this->state['notices'], true ) ) {
				$this->state['notices'][] = $notice;
			}
		}
		if ( $replacer->broken_serialized ) {
			$st['broken'] = ( isset( $st['broken'] ) ? $st['broken'] : 0 ) + $replacer->broken_serialized;
		}
		$this->state['progress'] = 45 + (int) ( 45 * $res['offset'] / max( 1, $res['size'] ) );
		$this->state['message']  = 'Import de la base de données… ' . self::size( $res['offset'] ) . ' / ' . self::size( $res['size'] );
		$db->close();
		if ( $res['done'] ) {
			$this->log( 'Import SQL terminé : ' . $st['queries'] . ' requêtes, ' . $st['errors'] . ' erreur(s).' );
			if ( ! empty( $st['broken'] ) ) {
				$this->log( $st['broken'] . ' valeur(s) sérialisée(s) déjà corrompue(s) dans la source : remplacement simple appliqué.' );
			}
		}
		return $res['done'];
	}

	/**
	 * Count the rows of every imported table and compare them with the rows
	 * written to the dump by the source site (before any setting is changed).
	 *
	 * @param float $deadline Microtime or 0.
	 * @return bool Finished.
	 * @throws WPMIG_Exception On connection error.
	 */
	private function step_db_check( $deadline ) {
		$st = &$this->state['check'];
		if ( ! isset( $st['index'] ) ) {
			$st = array(
				'index'  => 0,
				'tables' => array(),
			);
		}
		$m    = $this->manifest();
		$info = array();
		foreach ( $m['tables'] as $t ) {
			$info[ $t['name'] ] = $t;
		}
		$map      = $this->table_map();
		$names    = array_keys( $map );
		$db       = $this->connect( $this->state['params'] );
		$existing = $this->list_tables( $db );
		$first = $st['index'];
		while ( $st['index'] < count( $names ) ) {
			if ( $st['index'] > $first && $deadline && microtime( true ) >= $deadline ) {
				break;
			}
			$name = $names[ $st['index'] ];
			$t    = $info[ $name ];
			// Row: source name, rows exported (null: package made by an older version), rows imported (null: missing), structure only.
			$row = array( $name, isset( $t['exported'] ) ? (int) $t['exported'] : null, null, empty( $t['structure_only'] ) ? 0 : 1 );
			if ( isset( $existing[ $map[ $name ] ] ) ) {
				$res = $db->query( 'SELECT COUNT(*) FROM ' . WPMIG_SQL::quote_id( $map[ $name ] ) );
				$r   = $res ? $res->fetch_row() : null;
				if ( $r ) {
					$row[2] = (int) $r[0];
				}
			}
			$st['tables'][] = $row;
			$st['index']++;
		}
		$db->close();
		$this->state['progress'] = 90 + (int) ( 2 * $st['index'] / max( 1, count( $names ) ) );
		if ( $st['index'] < count( $names ) ) {
			$this->state['message'] = 'Contrôle des tables importées… ' . $st['index'] . ' / ' . count( $names );
			return false;
		}
		$exported = 0;
		$imported = 0;
		$bad      = 0;
		$names    = array();
		foreach ( $st['tables'] as $row ) {
			if ( null === $row[2] ) {
				$bad++;
				$names[] = $row[0];
				$this->warn( 'Table absente après l\'import : ' . $row[0] . '.' );
				continue;
			}
			$imported += $row[2];
			if ( null === $row[1] ) {
				continue;
			}
			$exported += $row[1];
			if ( $row[1] !== $row[2] ) {
				$bad++;
				$names[] = $row[0];
				$this->warn( sprintf( 'Table %s : %d ligne(s) importée(s) pour %d exportée(s).', $row[0], $row[2], $row[1] ) );
			}
		}
		$st['exported'] = $exported;
		$st['imported'] = $imported;
		$st['bad']      = $bad;
		$st['bad_list'] = array_slice( $names, 0, 10 );
		$this->log(
			sprintf( 'Contrôle de la base : %d tables, %d lignes exportées, %d lignes importées', count( $st['tables'] ), $exported, $imported )
			. ( $bad ? ', ' . $bad . ' table(s) en écart.' : ', identiques.' )
		);
		return true;
	}

	/**
	 * Fix settings in the imported (temporary) tables.
	 *
	 * @throws WPMIG_Exception On error.
	 */
	private function step_db_fix() {
		$m      = $this->manifest();
		$p      = $this->state['params'];
		$db     = $this->connect( $p );
		$tmp    = $this->state['tmp_prefix'];
		$old    = $m['site']['table_prefix'];
		$new    = $p['db_prefix'];
		$tables = $this->list_tables( $db );
		$db->set_charset( 'utf8mb4' ) || $db->set_charset( 'utf8' );
		$db->query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
		$q = function ( $sql ) use ( $db ) {
			if ( ! $db->query( $sql ) ) {
				throw new WPMIG_Exception( 'Erreur SQL : ' . $db->error . ' — ' . substr( $sql, 0, 200 ) );
			}
		};
		$e = function ( $value ) use ( $db ) {
			return "'" . $db->real_escape_string( $value ) . "'";
		};
		$options  = WPMIG_SQL::quote_id( $tmp . 'options' );
		$usermeta = WPMIG_SQL::quote_id( $tmp . 'usermeta' );
		$users    = WPMIG_SQL::quote_id( $tmp . 'users' );
		if ( ! isset( $tables[ $tmp . 'options' ] ) ) {
			throw new WPMIG_Exception( 'La table options est absente de la sauvegarde : installation impossible.' );
		}

		// URLs (in case the search & replace could not update them, e.g. custom values).
		$q( 'UPDATE ' . $options . ' SET option_value = ' . $e( $p['url_site'] ) . " WHERE option_name = 'siteurl'" );
		$q( 'UPDATE ' . $options . ' SET option_value = ' . $e( $p['url_home'] ) . " WHERE option_name = 'home'" );

		// Table prefix change: keys that embed the prefix.
		if ( $old !== $new ) {
			$q( 'UPDATE ' . $options . ' SET option_name = ' . $e( $new . 'user_roles' ) . ' WHERE option_name = ' . $e( $old . 'user_roles' ) );
			if ( isset( $tables[ $tmp . 'usermeta' ] ) ) {
				$q(
					'UPDATE ' . $usermeta . ' SET meta_key = CONCAT(' . $e( $new ) . ', SUBSTRING(meta_key, ' . ( strlen( $old ) + 1 ) . '))'
					. ' WHERE LEFT(meta_key, ' . strlen( $old ) . ') = BINARY ' . $e( $old )
				);
			}
			$this->log( 'Préfixe de table modifié : ' . $old . ' → ' . $new );
		}

		// Rewrite rules are regenerated by WordPress, cached data is dropped.
		$q( 'DELETE FROM ' . $options . " WHERE option_name IN ('rewrite_rules', 'wpmig_installed', 'wpmig_report') OR option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" );

		// Administrator account.
		if ( '' !== $p['admin_user'] && isset( $tables[ $tmp . 'users' ] ) ) {
			$hash = function_exists( 'password_hash' ) ? password_hash( $p['admin_pass'], PASSWORD_BCRYPT ) : md5( $p['admin_pass'] );
			$res  = $db->query( 'SELECT ID FROM ' . $users . ' WHERE user_login = ' . $e( $p['admin_user'] ) );
			$row  = $res ? $res->fetch_row() : null;
			if ( $row ) {
				$id = (int) $row[0];
				$q( 'UPDATE ' . $users . ' SET user_pass = ' . $e( $hash ) . ( '' !== $p['admin_email'] ? ', user_email = ' . $e( $p['admin_email'] ) : '' ) . ' WHERE ID = ' . $id );
				$this->log( 'Mot de passe de l\'utilisateur « ' . $p['admin_user'] . ' » modifié.' );
			} else {
				$nicename = strtolower( preg_replace( '/[^A-Za-z0-9_\-]+/', '-', $p['admin_user'] ) );
				$q(
					'INSERT INTO ' . $users . ' (user_login, user_pass, user_nicename, user_email, user_url, user_registered, user_activation_key, user_status, display_name) VALUES ('
					. $e( $p['admin_user'] ) . ', ' . $e( $hash ) . ', ' . $e( $nicename ) . ', ' . $e( $p['admin_email'] ) . ", '', " . $e( gmdate( 'Y-m-d H:i:s' ) ) . ", '', 0, " . $e( $p['admin_user'] ) . ')'
				);
				$id = (int) $db->insert_id;
				$this->log( 'Administrateur « ' . $p['admin_user'] . ' » créé.' );
			}
			if ( isset( $tables[ $tmp . 'usermeta' ] ) ) {
				$q( 'DELETE FROM ' . $usermeta . ' WHERE user_id = ' . $id . ' AND meta_key IN (' . $e( $new . 'capabilities' ) . ', ' . $e( $new . 'user_level' ) . ')' );
				$q( 'INSERT INTO ' . $usermeta . ' (user_id, meta_key, meta_value) VALUES (' . $id . ', ' . $e( $new . 'capabilities' ) . ", 'a:1:{s:13:\"administrator\";b:1;}'), (" . $id . ', ' . $e( $new . 'user_level' ) . ", '10')" );
			}
		}

		// Post-installation flag, handled by the WP Migration plugin on the first admin page load.
		$flag = json_encode(
			array(
				'time'    => time(),
				'package' => $m['package'],
				'from'    => $m['site']['home'],
				'to'      => $p['url_home'],
			)
		);
		$q( 'INSERT INTO ' . $options . " (option_name, option_value, autoload) VALUES ('wpmig_installed', " . $e( $flag ) . ", 'yes')" );
		$db->close();
	}

	/**
	 * Write wp-config.php.
	 *
	 * @throws WPMIG_Exception On error.
	 */
	private function step_config() {
		$m        = $this->manifest();
		$p        = $this->state['params'];
		$source   = ! empty( $m['wp_config'] ) ? base64_decode( $m['wp_config'] ) : '';
		$generated = false;
		if ( '' === trim( (string) $source ) ) {
			$source    = WPMIG_Config_Editor::skeleton();
			$generated = true;
			$this->warn( 'wp-config.php d\'origine indisponible : un fichier neuf a été généré.' );
		}
		$config = new WPMIG_Config_Editor( $source );

		// Old paths and URLs (WP_TEMP_DIR, WPCACHEHOME, custom constants...).
		$replacer = new WPMIG_Replacer( $this->replacement_pairs() );
		$config->transform( array( $replacer, 'replace_plain' ) );

		$db       = $this->connect( $p );
		$importer = new WPMIG_DB_Importer( $db, array() );
		$charset  = $importer->supported_charset( $m['site']['db_charset'] ? $m['site']['db_charset'] : 'utf8mb4' );
		$db->close();

		$config->set_define( 'DB_NAME', var_export( $p['db_name'], true ) );
		$config->set_define( 'DB_USER', var_export( $p['db_user'], true ) );
		$config->set_define( 'DB_PASSWORD', var_export( $p['db_pass'], true ) );
		$config->set_define( 'DB_HOST', var_export( $p['db_host'], true ) );
		$current = $config->get_define( 'DB_CHARSET' );
		if ( null === $current || $importer->supported_charset( $current ) !== strtolower( $current ) ) {
			$config->set_define( 'DB_CHARSET', var_export( $charset, true ) );
		}
		$collate = $config->get_define( 'DB_COLLATE' );
		if ( null !== $collate && '' !== $collate && $importer->supported_collation( $collate ) !== strtolower( $collate ) ) {
			$config->set_define( 'DB_COLLATE', "''" );
		}
		$config->set_prefix( $p['db_prefix'] );

		foreach ( array( 'WP_HOME' => $p['url_home'], 'WP_SITEURL' => $p['url_site'] ) as $const => $url ) {
			$code = $config->get_define_code( $const );
			if ( null !== $code && null !== WPMIG_Config_Editor::unquote( $code ) ) {
				$config->set_define( $const, var_export( $url, true ) );
			}
		}
		// Constants that would point to the old server.
		$remove = array( 'COOKIE_DOMAIN', 'DOMAIN_CURRENT_SITE' );
		if ( ! empty( $m['site']['content_relocated'] ) ) {
			$remove = array_merge( $remove, array( 'WP_CONTENT_DIR', 'WP_CONTENT_URL', 'WP_PLUGIN_DIR', 'WP_PLUGIN_URL', 'PLUGINDIR', 'WPMU_PLUGIN_DIR', 'WPMU_PLUGIN_URL' ) );
		}
		foreach ( $remove as $const ) {
			if ( $config->remove_define( $const ) ) {
				$this->state['notices'][] = 'wp-config.php : constante ' . $const . ' retirée.';
			}
		}
		// No redirection loop when the new site has no certificate.
		if ( 0 === stripos( $p['url_site'], 'http://' ) ) {
			foreach ( array( 'FORCE_SSL_ADMIN', 'FORCE_SSL_LOGIN' ) as $const ) {
				$code = $config->get_define_code( $const );
				if ( null !== $code && 'false' !== strtolower( $code ) ) {
					$config->set_define( $const, 'false' );
					$this->state['notices'][] = 'wp-config.php : ' . $const . ' désactivé (le nouveau site est en http).';
				}
			}
		}
		if ( $p['new_salts'] || $generated ) {
			$config->regenerate_salts();
		}
		// Absolute includes that do not exist on this server.
		if ( preg_match_all( '/(?:require|include)(?:_once)?\s*\(?\s*[\'"](\/[^\'"]+)[\'"]/', $config->code(), $inc ) ) {
			foreach ( $inc[1] as $path ) {
				if ( ! file_exists( $path ) ) {
					$this->warn( 'wp-config.php inclut un fichier absent sur ce serveur : ' . $path . ' — à corriger manuellement si le site ne s\'affiche pas.' );
				}
			}
		}
		$lint = $config->lint();
		if ( true !== $lint ) {
			$this->warn( 'wp-config.php d\'origine invalide (' . $lint . ') : un fichier neuf a été généré.' );
			$config = new WPMIG_Config_Editor( WPMIG_Config_Editor::skeleton() );
			$config->set_define( 'DB_NAME', var_export( $p['db_name'], true ) );
			$config->set_define( 'DB_USER', var_export( $p['db_user'], true ) );
			$config->set_define( 'DB_PASSWORD', var_export( $p['db_pass'], true ) );
			$config->set_define( 'DB_HOST', var_export( $p['db_host'], true ) );
			$config->set_define( 'DB_CHARSET', var_export( $charset, true ) );
			$config->set_prefix( $p['db_prefix'] );
		}

		$target = $this->root . '/wp-config.php';
		if ( is_file( $target ) ) {
			// A .php file that stops at once: never served as text (it holds database credentials).
			$backup = $this->root . '/wp-config-sauvegarde-' . gmdate( 'Ymd-His' ) . '.php';
			$guard  = "<?php exit; // Sauvegarde WP Migration du wp-config.php remplacé le " . gmdate( 'Y-m-d H:i:s' ) . " UTC. ?>\n";
			if ( false !== @file_put_contents( $backup, $guard . (string) @file_get_contents( $target ) ) ) {
				@chmod( $backup, 0600 );
				$this->state['notices'][] = 'wp-config.php existant sauvegardé : ' . basename( $backup );
			}
		}
		if ( false === @file_put_contents( $target, $config->code() ) ) {
			throw new WPMIG_Exception( 'Impossible d\'écrire wp-config.php : vérifiez les permissions du dossier.' );
		}
		@chmod( $target, 0640 );
		if ( ! is_readable( $target ) ) {
			@chmod( $target, 0644 );
		}
		$this->log( 'wp-config.php écrit.' );
	}

	/**
	 * Replace the live tables by the imported ones (atomic RENAME).
	 *
	 * @throws WPMIG_Exception On error.
	 */
	private function step_swap() {
		$p      = $this->state['params'];
		$db     = $this->connect( $p );
		$tmp    = $this->state['tmp_prefix'];
		$tables = $this->list_tables( $db );
		$db->query( 'SET SESSION foreign_key_checks = 0' );

		if ( 'empty' === $p['db_action'] ) {
			foreach ( $tables as $name => $type ) {
				if ( 0 === strpos( $name, $tmp ) ) {
					continue;
				}
				$ok = $db->query( ( 'VIEW' === $type ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ' ) . WPMIG_SQL::quote_id( $name ) );
				if ( ! $ok ) {
					throw new WPMIG_Exception( 'Impossible de supprimer la table ' . $name . ' : ' . $db->error );
				}
			}
			$tables = $this->list_tables( $db );
			$this->log( 'Base vidée.' );
		}

		$backup  = 'wpmb' . self::random_string( 4, true ) . '_';
		$renames = array();
		$drops   = array();
		foreach ( $this->table_map() as $source => $temp ) {
			if ( ! isset( $tables[ $temp ] ) ) {
				continue;
			}
			$suffix = substr( $temp, strlen( $tmp ) );
			$final  = $p['db_prefix'] . $suffix;
			if ( isset( $tables[ $final ] ) ) {
				$renames[] = WPMIG_SQL::quote_id( $final ) . ' TO ' . WPMIG_SQL::quote_id( $backup . $suffix );
				$drops[]   = $backup . $suffix;
			}
			$renames[] = WPMIG_SQL::quote_id( $temp ) . ' TO ' . WPMIG_SQL::quote_id( $final );
		}
		if ( ! $renames ) {
			throw new WPMIG_Exception( 'Aucune table importée.' );
		}
		if ( ! $db->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) {
			throw new WPMIG_Exception( 'Impossible d\'activer les nouvelles tables : ' . $db->error );
		}
		foreach ( $drops as $name ) {
			$db->query( 'DROP TABLE IF EXISTS ' . WPMIG_SQL::quote_id( $name ) );
		}
		$this->log( count( $renames ) - count( $drops ) . ' tables activées, ' . count( $drops ) . ' anciennes tables remplacées.' );
		$db->close();
	}

	/**
	 * .htaccess, cleanup, summary.
	 */
	private function step_finalize() {
		$m    = $this->manifest();
		$p    = $this->state['params'];
		$path = (string) parse_url( $p['url_home'], PHP_URL_PATH );
		$base = rtrim( $path, '/' ) . '/';

		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : '';
		if ( false === stripos( $server, 'microsoft-iis' ) && rtrim( $p['url_home'], '/' ) === rtrim( $p['url_site'], '/' ) ) {
			$htaccess = $this->root . '/.htaccess';
			if ( is_file( $htaccess ) ) {
				@copy( $htaccess, $htaccess . '.wpmig-backup-' . gmdate( 'Ymd-His' ) );
			}
			$rules  = "# BEGIN WordPress\n";
			$rules .= "<IfModule mod_rewrite.c>\nRewriteEngine On\n";
			$rules .= "RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n";
			$rules .= 'RewriteBase ' . $base . "\n";
			$rules .= "RewriteRule ^index\\.php$ - [L]\n";
			$rules .= "RewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\n";
			$rules .= 'RewriteRule . ' . $base . "index.php [L]\n</IfModule>\n# END WordPress\n";
			if ( false !== @file_put_contents( $htaccess, $rules ) ) {
				$this->log( '.htaccess WordPress standard écrit (RewriteBase ' . $base . ').' );
			} else {
				$this->warn( 'Impossible d\'écrire .htaccess : enregistrez les réglages des permaliens après connexion.' );
			}
		}
		@unlink( $this->data_dir . '/database.sql' );
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset();
		}
		if ( ! empty( $this->state['db']['errors'] ) ) {
			$this->warn( $this->state['db']['errors'] . ' requête(s) SQL en erreur (détails dans ' . basename( $this->data_dir ) . '/install.log).' );
		}
		$this->state['status']   = 'complete';
		$this->state['step']     = 'done';
		$this->state['progress'] = 100;
		$this->state['finished'] = time();
		$this->state['message']  = 'Installation terminée.';
		$this->state['result']   = array(
			'home'  => $p['url_home'],
			'login' => $p['url_site'] . '/wp-login.php',
			'admin' => $p['url_site'] . '/wp-admin/',
			'time'  => $this->state['finished'] - $this->state['started'],
		);
		$this->log( 'Installation terminée en ' . $this->state['result']['time'] . ' s.' );

		// The report is kept in the database: it survives the removal of the installation files.
		$report                           = $this->build_report();
		$this->state['result']['checks'] = $report['checks'];
		$this->state['result']['report'] = $p['url_site'] . '/wp-admin/admin.php?page=wp-migration&view=report';
		$this->save_report( $report );
	}

	/**
	 * Checks and full report of the installation.
	 *
	 * @return array
	 */
	private function build_report() {
		$m       = $this->manifest();
		$p       = $this->state['params'];
		$ex      = array_merge( array( 'files' => 0, 'failed' => 0, 'bytes' => 0 ), $this->state['extract'] );
		$db      = array_merge( array( 'errors' => 0, 'queries' => 0, 'broken' => 0 ), $this->state['db'] );
		$check   = array_merge( array( 'tables' => array(), 'exported' => 0, 'imported' => 0, 'bad' => 0 ), isset( $this->state['check'] ) ? $this->state['check'] : array() );
		$archive = $this->archive_path();
		$trailer = $archive ? WPMIG_Archive::read_trailer( $archive ) : null;
		// The archive also holds the SQL dump, which is not a file of the site.
		$expected = $trailer && isset( $trailer['files'] ) ? max( 0, (int) $trailer['files'] - 1 ) : null;

		$issues = array();
		if ( ! empty( $p['skip_verify'] ) ) {
			$issues[] = 'Archive non vérifiée avant l\'extraction (vérification désactivée).';
		}
		if ( $ex['failed'] ) {
			$issues[] = sprintf( '%d fichier(s) ou dossier(s) n\'ont pas pu être écrits.', $ex['failed'] );
		} elseif ( empty( $p['skip_files'] ) && null !== $expected && (int) $ex['files'] !== $expected ) {
			$issues[] = sprintf( '%d fichier(s) extrait(s) sur %d dans l\'archive.', $ex['files'], $expected );
		}
		if ( $db['errors'] ) {
			$issues[] = sprintf( '%d requête(s) SQL en erreur.', $db['errors'] );
		}
		if ( $check['bad'] ) {
			$issues[] = sprintf( '%d table(s) absente(s) ou avec un nombre de lignes différent de la source', $check['bad'] )
				. ( ! empty( $check['bad_list'] ) ? ' : ' . implode( ', ', $check['bad_list'] ) . ( $check['bad'] > count( $check['bad_list'] ) ? '…' : '' ) : '' ) . '.';
		}

		$replacements = array();
		foreach ( $this->replacement_pairs() as $old => $new ) {
			if ( false === strpos( $old, '\\' ) && false === strpos( $old, '%' ) ) {
				$replacements[] = array( $old, $new );
			}
		}
		$site = $m['site'];
		return array(
			'format'       => 1,
			'installer'    => WPMIG_INSTALLER,
			'generator'    => isset( $m['generator'] ) ? $m['generator'] : '',
			'package'      => array(
				'id'           => $m['package'],
				'name'         => $m['name'],
				'created'      => $m['created'],
				'dump_started' => isset( $m['dump_started'] ) ? $m['dump_started'] : '',
				'archive_size' => $archive ? (float) sprintf( '%u', filesize( $archive ) ) : null,
			),
			'mode'         => $this->cli ? 'cli' : 'web',
			'started'      => $this->state['started'],
			'finished'     => $this->state['finished'],
			'source'       => array(
				'home'    => $site['home'],
				'siteurl' => $site['siteurl'],
				'path'    => $site['abspath'],
				'wp'      => $site['wp_version'],
				'php'     => $site['php_version'],
				'db'      => $site['db_version'],
				'server'  => $site['server'],
				'prefix'  => $site['table_prefix'],
			),
			'destination'  => array(
				'home'    => $p['url_home'],
				'siteurl' => $p['url_site'],
				'path'    => $this->root,
				'wp'      => $site['wp_version'],
				'php'     => PHP_VERSION,
				'db'      => '',
				'server'  => isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : ( $this->cli ? 'CLI' : '' ),
				'prefix'  => $p['db_prefix'],
				'db_name' => $p['db_name'],
				'db_host' => $p['db_host'],
			),
			'options'      => array(
				'db_action'    => $p['db_action'],
				'skip_files'   => ! empty( $p['skip_files'] ),
				'skip_verify'  => ! empty( $p['skip_verify'] ),
				'keep_guid'    => ! empty( $p['keep_guid'] ),
				'new_salts'    => ! empty( $p['new_salts'] ),
				'www_variants' => ! empty( $p['www_variants'] ),
			),
			'transfer'     => isset( $this->state['transfer'] ) ? $this->state['transfer'] : null,
			'checks'       => array(
				'ok'             => ! $issues,
				'issues'         => $issues,
				'verified'       => empty( $p['skip_verify'] ),
				'files_expected' => $expected,
				'files'          => (int) $ex['files'],
				'files_failed'   => (int) $ex['failed'],
				'bytes'          => (float) $ex['bytes'],
				'tables'         => count( $check['tables'] ),
				'tables_bad'     => (int) $check['bad'],
				'rows_exported'  => (int) $check['exported'],
				'rows_imported'  => (int) $check['imported'],
				'sql_queries'    => (int) $db['queries'],
				'sql_errors'     => (int) $db['errors'],
				'broken'         => (int) $db['broken'],
			),
			'tables'       => $check['tables'],
			'replacements' => $replacements,
			'excluded'     => isset( $m['excluded'] ) ? $m['excluded'] : array(),
			'warnings'     => array_values( $this->state['warnings'] ),
			'notices'      => array_values( array_unique( $this->state['notices'] ) ),
			'log'          => '',
		);
	}

	/**
	 * Checks summary as text lines (CLI).
	 *
	 * @param array $c Checks.
	 * @return array
	 */
	private static function checks_lines( array $c ) {
		$lines   = array( '' );
		$lines[] = $c['ok'] ? 'CONTRÔLES : OK, la copie est complète.' : 'CONTRÔLES : ' . count( $c['issues'] ) . ' point(s) à vérifier.';
		$lines[] = '  Archive   : ' . ( $c['verified'] ? 'sommes de contrôle vérifiées' : 'non vérifiée' );
		$lines[] = '  Fichiers  : ' . $c['files'] . ( null !== $c['files_expected'] ? ' / ' . $c['files_expected'] : '' ) . ' extraits, ' . $c['files_failed'] . ' en échec';
		$lines[] = '  Tables    : ' . ( $c['tables'] - $c['tables_bad'] ) . ' / ' . $c['tables'] . ' identiques à la source';
		$lines[] = '  Lignes    : ' . $c['rows_imported'] . ' importées / ' . $c['rows_exported'] . ' exportées';
		$lines[] = '  SQL       : ' . $c['sql_queries'] . ' requêtes, ' . $c['sql_errors'] . ' erreur(s)';
		foreach ( $c['issues'] as $issue ) {
			$lines[] = '  ! ' . $issue;
		}
		return $lines;
	}

	/**
	 * Store the report in the options of the new site (read by the WP Migration plugin).
	 *
	 * @param array $report Report.
	 */
	private function save_report( array $report ) {
		try {
			$db = $this->connect( $this->state['params'] );
		} catch ( Exception $e ) {
			$this->warn( 'Rapport de migration non enregistré : ' . $e->getMessage() );
			return;
		}
		$db->set_charset( 'utf8mb4' ) || $db->set_charset( 'utf8' );
		$res = $db->query( 'SELECT VERSION(), @@max_allowed_packet' );
		$row = $res ? $res->fetch_row() : null;
		$max = $row ? (int) $row[1] : 1048576;
		if ( $row ) {
			$report['destination']['db'] = $row[0];
		}
		$table = WPMIG_SQL::quote_id( $this->state['params']['db_prefix'] . 'options' );
		$db->query( 'DELETE FROM ' . $table . " WHERE option_name = 'wpmig_report'" );

		$log   = (string) @file_get_contents( $this->data_dir . '/install.log' );
		$limit = 524288;
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;
		$saved = false;
		while ( ! $saved ) {
			if ( strlen( $log ) > $limit ) {
				// Keep the beginning and the end: the summary lines are at the end.
				$head = (int) ( $limit * 0.3 );
				$tail = $limit - $head;
				$log  = substr( $log, 0, $head ) . "\n[… " . ( strlen( $log ) - $limit ) . " octets du journal omis …]\n" . substr( $log, -$tail );
			}
			$report['log'] = self::utf8( $log );
			$json          = json_encode( $report, $flags );
			$sql           = 'INSERT INTO ' . $table . " (option_name, option_value, autoload) VALUES ('wpmig_report', '" . $db->real_escape_string( (string) $json ) . "', 'no')";
			if ( strlen( $sql ) < $max - 1024 && $db->query( $sql ) ) {
				$saved = true;
			} elseif ( $limit < 4096 ) {
				break;
			} else {
				$limit = (int) ( $limit / 2 );
			}
		}
		$db->close();
		if ( $saved ) {
			$this->log( 'Rapport de migration enregistré dans le site (WP Migration → Rapport).' );
		} else {
			$this->warn( 'Rapport de migration non enregistré dans la base : consultez ' . basename( $this->data_dir ) . '/install.log avant de supprimer les fichiers d\'installation.' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Direct transfer from the source site                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Partial download file.
	 *
	 * @return string
	 */
	private function download_part() {
		return $this->data_dir . '/archive.part';
	}

	/**
	 * GET a byte range.
	 *
	 * @param string $url   URL.
	 * @param float  $start First byte.
	 * @param float  $end   Last byte.
	 * @return array array( 'status' => int, 'body' => string, 'total' => float|null ).
	 * @throws WPMIG_Exception On network error.
	 */
	private function http_range( $url, $start, $end ) {
		$range = sprintf( '%.0f-%.0f', $start, $end );
		if ( function_exists( 'curl_init' ) ) {
			$headers = array();
			$ch      = curl_init( $url );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_RANGE, $range );
			curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
			curl_setopt( $ch, CURLOPT_MAXREDIRS, 5 );
			curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 20 );
			curl_setopt( $ch, CURLOPT_TIMEOUT, 180 );
			curl_setopt( $ch, CURLOPT_USERAGENT, 'WP-Migration-Installer/' . WPMIG_INSTALLER );
			curl_setopt( $ch, CURLOPT_ENCODING, 'identity' );
			if ( defined( 'CURLOPT_PROTOCOLS' ) ) {
				curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
				curl_setopt( $ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
			}
			curl_setopt(
				$ch,
				CURLOPT_HEADERFUNCTION,
				function ( $ch, $line ) use ( &$headers ) {
					if ( preg_match( '/^HTTP\//', $line ) ) {
						$headers = array(); // New response after a redirection.
					}
					$headers[] = trim( $line );
					return strlen( $line );
				}
			);
			$body = curl_exec( $ch );
			if ( false === $body ) {
				$error = curl_error( $ch );
				if ( PHP_VERSION_ID < 80000 ) {
					curl_close( $ch );
				}
				throw new WPMIG_Exception( 'Téléchargement impossible : ' . $error );
			}
			$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			if ( PHP_VERSION_ID < 80000 ) {
				curl_close( $ch ); // No-op since PHP 8.0, deprecated in 8.5.
			}
		} elseif ( ini_get( 'allow_url_fopen' ) ) {
			$context = stream_context_create(
				array(
					'http' => array(
						'method'          => 'GET',
						'header'          => "Range: bytes=" . $range . "\r\nUser-Agent: WP-Migration-Installer/" . WPMIG_INSTALLER . "\r\n",
						'timeout'         => 180,
						'ignore_errors'   => true,
						'follow_location' => 1,
						'max_redirects'   => 5,
					),
				)
			);
			$body     = @file_get_contents( $url, false, $context );
			$response = function_exists( 'http_get_last_response_headers' ) ? http_get_last_response_headers() : ( isset( $http_response_header ) ? $http_response_header : array() );
			if ( false === $body || empty( $response ) ) {
				throw new WPMIG_Exception( 'Téléchargement impossible (vérifiez l\'adresse et que ce serveur peut accéder à Internet).' );
			}
			$headers = array();
			foreach ( $response as $line ) {
				if ( preg_match( '/^HTTP\//', $line ) ) {
					$headers = array();
				}
				$headers[] = $line;
			}
			$status = preg_match( '/^HTTP\/\S+\s+(\d+)/', $headers[0], $m ) ? (int) $m[1] : 0;
		} else {
			throw new WPMIG_Exception( 'Ce serveur ne peut pas télécharger de fichier (ni cURL ni allow_url_fopen) : envoyez l\'archive par FTP.' );
		}
		$total = null;
		foreach ( $headers as $line ) {
			if ( preg_match( '/^Content-Range:\s*bytes\s+\d+-\d+\/(\d+)/i', $line, $m ) ) {
				$total = (float) $m[1];
			}
		}
		return array(
			'status' => $status,
			'body'   => (string) $body,
			'total'  => $total,
		);
	}

	/**
	 * Explain a failed HTTP answer.
	 *
	 * @param array $res Response.
	 * @return string
	 */
	private function http_error( array $res ) {
		switch ( $res['status'] ) {
			case 403:
				return 'Lien refusé par le site d\'origine : il est invalide, expiré ou révoqué. Créez un nouveau lien depuis WP Migration > Packages > Transfert direct.';
			case 404:
				return 'Package introuvable sur le site d\'origine (supprimé ?).';
			case 200:
				return 'Le serveur d\'origine ne gère pas les téléchargements partiels : vérifiez que le lien est bien celui de WP Migration, ou envoyez l\'archive par FTP.';
		}
		$text = trim( preg_replace( '/\s+/', ' ', strip_tags( substr( $res['body'], 0, 2000 ) ) ) );
		return 'Réponse inattendue du site d\'origine (HTTP ' . $res['status'] . ')' . ( '' !== $text ? ' : ' . self::utf8( substr( $text, 0, 200 ) ) : '.' );
	}

	/**
	 * Start downloading the archive from a transfer link.
	 *
	 * @param string $url Link created on the source site.
	 * @throws WPMIG_Exception On invalid link.
	 */
	private function download_start( $url ) {
		if ( 'new' !== $this->state['status'] ) {
			throw new WPMIG_Exception( 'Une installation est déjà en cours ou terminée.' );
		}
		if ( $this->archive_path() ) {
			throw new WPMIG_Exception( 'L\'archive est déjà présente sur ce serveur.' );
		}
		$url = trim( $url );
		if ( ! preg_match( '#^https?://[^\s]+$#i', $url ) ) {
			throw new WPMIG_Exception( 'Collez le lien de transfert complet (il commence par https://).' );
		}
		// The installer link was pasted instead of the archive one.
		$url = preg_replace( '/([?&])file=installer(&|$)/', '$1', $url );
		$url = rtrim( $url, '&?' );
		// Links name their package: refuse another package before downloading anything.
		$query = (string) parse_url( $url, PHP_URL_QUERY );
		parse_str( $query, $args );
		if ( ! empty( $args['id'] ) && ! empty( $this->config['package'] ) && $args['id'] !== $this->config['package'] ) {
			throw new WPMIG_Exception( 'Ce lien correspond à un autre package (' . $args['id'] . ') que cet installeur (' . $this->config['package'] . ') : utilisez l\'installer.php du même package.' );
		}

		$res = $this->http_range( $url, 0, 15 );
		if ( 206 !== $res['status'] || null === $res['total'] ) {
			throw new WPMIG_Exception( $this->http_error( $res ) );
		}
		if ( WPMIG_Archive::ENTRY_MAGIC !== substr( $res['body'], 0, 4 ) ) {
			throw new WPMIG_Exception( 'Ce lien ne renvoie pas une archive WP Migration.' );
		}
		$size = $res['total'];
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $this->root ) : false;
		if ( false !== $free && $free < $size * 1.1 ) {
			throw new WPMIG_Exception( 'Espace disque insuffisant : ' . self::size( $free ) . ' libres pour une archive de ' . self::size( $size ) . ' (sans compter l\'extraction).' );
		}
		@unlink( $this->download_part() );
		$this->state['download'] = array(
			'url'     => $url,
			'size'    => $size,
			'offset'  => 0,
			'started' => time(),
		);
		$this->log( 'Transfert direct : archive de ' . self::size( $size ) . ' depuis ' . preg_replace( '/([?&]key=)[a-f0-9]+/i', '$1…', $url ) );
		$this->save_state();
	}

	/**
	 * Download the next chunks until $deadline.
	 *
	 * @param float $deadline Microtime or 0.
	 * @return bool True when the archive is complete and in place.
	 * @throws WPMIG_Exception On error.
	 */
	private function download_step( $deadline ) {
		$d = &$this->state['download'];
		if ( empty( $d['url'] ) ) {
			throw new WPMIG_Exception( 'Aucun téléchargement en cours.' );
		}
		$fh = @fopen( $this->download_part(), 'c+b' );
		if ( ! $fh ) {
			throw new WPMIG_Exception( 'Impossible d\'écrire l\'archive dans ' . $this->data_dir . '.' );
		}
		ftruncate( $fh, (int) $d['offset'] );
		fseek( $fh, 0, SEEK_END );
		$chunk = 8388608;
		while ( $d['offset'] < $d['size'] ) {
			$end = min( $d['size'], $d['offset'] + $chunk ) - 1;
			$res = $this->http_range( $d['url'], $d['offset'], $end );
			if ( 206 !== $res['status'] ) {
				fclose( $fh );
				throw new WPMIG_Exception( $this->http_error( $res ) );
			}
			if ( null !== $res['total'] && (float) $res['total'] !== (float) $d['size'] ) {
				fclose( $fh );
				unset( $this->state['download'] );
				$this->save_state();
				throw new WPMIG_Exception( 'L\'archive a changé sur le site d\'origine pendant le téléchargement : relancez-le.' );
			}
			$len = strlen( $res['body'] );
			if ( $len !== (int) ( $end - $d['offset'] + 1 ) ) {
				fclose( $fh );
				throw new WPMIG_Exception( 'Morceau incomplet reçu (' . $len . ' octets) : nouvelle tentative nécessaire.' );
			}
			if ( false === fwrite( $fh, $res['body'] ) ) {
				fclose( $fh );
				throw new WPMIG_Exception( 'Erreur d\'écriture de l\'archive (espace disque insuffisant ?).' );
			}
			fflush( $fh );
			$d['offset'] += $len;
			$this->save_state();
			if ( $deadline && microtime( true ) >= $deadline ) {
				break;
			}
		}
		fclose( $fh );
		if ( $d['offset'] < $d['size'] ) {
			return false;
		}

		// Complete: check it is the archive of this package before putting it in place.
		$part     = $this->download_part();
		$manifest = WPMIG_Archive::read_trailer( $part ) ? $this->read_manifest_from( $part ) : null;
		if ( ! $manifest ) {
			@unlink( $part );
			unset( $this->state['download'] );
			$this->save_state();
			throw new WPMIG_Exception( 'Archive téléchargée incomplète ou illisible : relancez le téléchargement.' );
		}
		if ( ! empty( $this->config['package'] ) && $manifest['package'] !== $this->config['package'] ) {
			@unlink( $part );
			unset( $this->state['download'] );
			$this->save_state();
			throw new WPMIG_Exception( 'Ce lien correspond à un autre package (' . $manifest['package'] . ') que cet installeur (' . $this->config['package'] . ') : utilisez l\'installer.php du même package.' );
		}
		$target = $this->root . '/' . ( ! empty( $this->config['archive'] ) ? $this->config['archive'] : $manifest['name'] . '_' . $manifest['package'] . '_archive.wpmig' );
		if ( ! @rename( $part, $target ) && ! ( @copy( $part, $target ) && @unlink( $part ) ) ) {
			throw new WPMIG_Exception( 'Impossible de placer l\'archive dans ' . $this->root . '.' );
		}
		$this->log( 'Transfert direct terminé en ' . ( time() - $d['started'] ) . ' s.' );
		$this->state['transfer'] = array(
			'size'    => $d['size'],
			'seconds' => time() - $d['started'],
		);
		unset( $this->state['download'] );
		$this->save_state();
		return true;
	}

	/**
	 * Download progress for the browser.
	 *
	 * @return array|null
	 */
	private function download_state() {
		if ( empty( $this->state['download']['url'] ) ) {
			return null;
		}
		$d = $this->state['download'];
		return array(
			'progress' => $d['size'] > 0 ? (int) floor( 100 * $d['offset'] / $d['size'] ) : 0,
			'message'  => 'Téléchargement de l\'archive… ' . self::size( $d['offset'] ) . ' / ' . self::size( $d['size'] ),
		);
	}

	/**
	 * Remove the installer, the archive and the working directory.
	 *
	 * @return array Result.
	 */
	private function cleanup() {
		$result  = isset( $this->state['result'] ) ? $this->state['result'] : array();
		$archive = $this->archive_path();
		if ( $archive ) {
			@unlink( $archive );
		}
		$this->rrmdir( $this->data_dir );
		@unlink( $this->file );
		$left = array();
		foreach ( array( $this->file, $archive, $this->data_dir ) as $path ) {
			if ( $path && file_exists( $path ) ) {
				$left[] = basename( $path );
			}
		}
		return array(
			'removed' => ! $left,
			'left'    => $left,
			'admin'   => isset( $result['admin'] ) ? $result['admin'] : '',
		);
	}

	/**
	 * State sent to the browser.
	 *
	 * @return array
	 */
	private function public_state() {
		return array(
			'status'   => $this->state['status'],
			'step'     => $this->state['step'],
			'progress' => (int) $this->state['progress'],
			'message'  => $this->state['message'],
			'warnings' => array_values( array_slice( $this->state['warnings'], 0, 100 ) ),
			'notices'  => array_values( array_unique( $this->state['notices'] ) ),
			'result'   => $this->state['result'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* HTTP                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Cookie name.
	 *
	 * @return string
	 */
	private function cookie_name() {
		return 'wpmig_' . substr( md5( isset( $this->config['package'] ) ? $this->config['package'] : '' ), 0, 10 );
	}

	/**
	 * Is the request authorized?
	 *
	 * @return bool
	 */
	private function authorized() {
		$token = isset( $_POST['token'] ) ? (string) $_POST['token'] : '';
		return '' !== $this->state['token'] && '' !== $token && hash_equals( $this->state['token'], $token );
	}

	/**
	 * Main entry point.
	 */
	public function dispatch() {
		if ( empty( $this->config['package'] ) ) {
			echo 'Ce fichier est le modèle de l\'installeur WP Migration : utilisez le fichier installer.php généré avec votre package.';
			return;
		}
		if ( $this->cli ) {
			exit( $this->cli_main() );
		}
		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) && isset( $_POST['wpmig_action'] ) ) {
			$this->ajax( (string) $_POST['wpmig_action'] );
			return;
		}
		$this->render_page();
	}

	/**
	 * Send a JSON response.
	 *
	 * @param array $data Data.
	 */
	private function json( array $data ) {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		$json = json_encode( $data );
		if ( false === $json && function_exists( 'json_last_error' ) ) {
			$json = json_encode( array( 'ok' => false, 'error' => 'Réponse invalide (encodage).' ) );
		}
		echo $json;
	}

	/**
	 * AJAX router.
	 *
	 * @param string $action Action.
	 */
	private function ajax( $action ) {
		ob_start();
		$self = $this;
		register_shutdown_function(
			function () use ( $self ) {
				$error = error_get_last();
				if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
					while ( ob_get_level() > 0 ) {
						ob_end_clean();
					}
					if ( ! headers_sent() ) {
						header( 'Content-Type: application/json; charset=utf-8' );
					}
					echo json_encode(
						array(
							'ok'    => false,
							'error' => 'Erreur PHP fatale : ' . WPMIG_Installer::utf8( $error['message'] ) . ' (' . basename( $error['file'] ) . ':' . $error['line'] . ')',
							'retry' => true,
						)
					);
				}
			}
		);
		try {
			$this->ensure_data_dir();
			$this->load_state();
			$post = $_POST;
			if ( function_exists( 'get_magic_quotes_gpc' ) && version_compare( PHP_VERSION, '7.4', '<' ) && @get_magic_quotes_gpc() ) {
				$post = array_map( 'stripslashes', $post );
			}
			if ( 'auth' === $action ) {
				$this->json( $this->ajax_auth( isset( $post['password'] ) ? (string) $post['password'] : '' ) );
				return;
			}
			if ( ! $this->authorized() ) {
				$this->json(
					array(
						'ok'    => false,
						'auth'  => true,
						'error' => 'Session expirée : rechargez la page.',
					)
				);
				return;
			}
			switch ( $action ) {
				case 'info':
					if ( ! $this->archive_path() ) {
						// No archive yet: offer the direct transfer from the source site.
						$this->json(
							array(
								'ok'       => true,
								'missing'  => true,
								'package'  => array(
									'name'    => isset( $this->config['name'] ) ? $this->config['name'] : '',
									'home'    => isset( $this->config['source_url'] ) ? $this->config['source_url'] : '',
									'created' => isset( $this->config['created'] ) ? $this->config['created'] : '',
									'archive' => isset( $this->config['archive'] ) ? $this->config['archive'] : '',
								),
								'download' => $this->download_state(),
								'can_http' => function_exists( 'curl_init' ) || (bool) ini_get( 'allow_url_fopen' ),
								'state'    => $this->public_state(),
							)
						);
						return;
					}
					$manifest = $this->manifest();
					$checks   = $this->checks();
					$defaults = array_merge(
						array(
							'db_host'   => 'localhost',
							'db_name'   => '',
							'db_user'   => '',
							'db_pass'   => '',
							'db_prefix' => $manifest['site']['table_prefix'],
						),
						$this->existing_config(),
						array(
							'url_site' => $this->detect_url(),
							'url_home' => $this->detect_url(),
						)
					);
					$this->json(
						array(
							'ok'       => true,
							'package'  => array(
								'name'       => $manifest['name'],
								'created'    => $manifest['created'],
								'home'       => $manifest['site']['home'],
								'siteurl'    => $manifest['site']['siteurl'],
								'blogname'   => $manifest['site']['blogname'],
								'wp_version' => $manifest['site']['wp_version'],
								'php'        => $manifest['site']['php_version'],
								'db'         => $manifest['site']['db_version'],
								'files'      => $manifest['stats']['files'],
								'size'       => self::size( $manifest['stats']['size'] ),
								'tables'     => count( $manifest['tables'] ),
								'db_only'    => ! empty( $manifest['db_only'] ),
							),
							'checks'   => $checks,
							'blocking' => $this->has_blocking( $checks ),
							'defaults' => $defaults,
							'state'    => $this->public_state(),
						)
					);
					return;

				case 'test_db':
					$this->json( array_merge( array( 'ok' => true ), array( 'test' => $this->test_db( $post ) ) ) );
					return;

				case 'start':
					$this->start( $post );
					$this->json(
						array(
							'ok'    => true,
							'state' => $this->public_state(),
						)
					);
					return;

				case 'step':
					// A previous request may still be running (proxy timeout + retry): never run two steps at once.
					$lock = @fopen( $this->data_dir . '/step.lock', 'c' );
					if ( $lock && ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
						fclose( $lock );
						$this->json(
							array(
								'ok'    => true,
								'state' => array_merge( $this->public_state(), array( 'busy' => true ) ),
							)
						);
						return;
					}
					$this->load_state();
					if ( 'running' !== $this->state['status'] ) {
						$this->json(
							array(
								'ok'    => true,
								'state' => $this->public_state(),
							)
						);
						return;
					}
					$this->json(
						array(
							'ok'    => true,
							'state' => $this->step(),
						)
					);
					return;

				case 'download_start':
					$this->download_start( isset( $post['source_url'] ) ? (string) $post['source_url'] : '' );
					$this->json(
						array(
							'ok'       => true,
							'download' => $this->download_state(),
						)
					);
					return;

				case 'download_step':
					$lock = @fopen( $this->data_dir . '/step.lock', 'c' );
					if ( $lock && ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
						fclose( $lock );
						$this->json(
							array(
								'ok'   => true,
								'busy' => true,
							)
						);
						return;
					}
					$this->load_state();
					$this->raise_limits();
					$budget = $this->budget();
					$done   = $this->download_step( $budget ? microtime( true ) + $budget : 0 );
					$this->json(
						array(
							'ok'       => true,
							'done'     => $done,
							'download' => $this->download_state(),
						)
					);
					return;

				case 'download_cancel':
					unset( $this->state['download'] );
					@unlink( $this->download_part() );
					$this->save_state();
					$this->json( array( 'ok' => true ) );
					return;

				case 'cleanup':
					if ( 'complete' !== $this->state['status'] ) {
						throw new WPMIG_Exception( 'L\'installation n\'est pas terminée.' );
					}
					$this->json(
						array(
							'ok'      => true,
							'cleanup' => $this->cleanup(),
						)
					);
					return;
			}
			throw new WPMIG_Exception( 'Action inconnue.' );
		} catch ( Exception $e ) {
			if ( 'running' === $this->state['status'] ) {
				$this->log( 'ERREUR : ' . $e->getMessage() );
			}
			$this->json(
				array(
					'ok'    => false,
					'error' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Authenticate the browser.
	 *
	 * @param string $password Password.
	 * @return array
	 */
	private function ajax_auth( $password ) {
		$needs_password = ! empty( $this->config['password_hash'] );
		$cookie         = isset( $_COOKIE[ $this->cookie_name() ] ) ? (string) $_COOKIE[ $this->cookie_name() ] : '';
		$has_cookie     = '' !== $this->state['token'] && '' !== $cookie && hash_equals( $this->state['token'], $cookie );

		if ( ! $has_cookie ) {
			if ( $needs_password ) {
				if ( '' === $password ) {
					return array(
						'ok'       => false,
						'password' => true,
					);
				}
				if ( ! hash_equals( $this->config['password_hash'], hash( 'sha256', $this->config['password_salt'] . $password ) ) ) {
					usleep( 500000 );
					return array(
						'ok'       => false,
						'password' => true,
						'error'    => 'Mot de passe incorrect.',
					);
				}
			} elseif ( 'new' !== $this->state['status'] && '' !== $this->state['token'] ) {
				return array(
					'ok'    => false,
					'error' => 'Une installation a déjà été lancée depuis un autre navigateur. Pour recommencer, supprimez le dossier ' . basename( $this->data_dir ) . ' sur le serveur.',
				);
			}
			if ( '' === $this->state['token'] || 'new' === $this->state['status'] ) {
				$this->state['token'] = self::random_string( 40, true );
				$this->save_state();
			}
		}
		$secure = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( $_SERVER['HTTPS'] ) );
		setcookie( $this->cookie_name(), $this->state['token'], 0, '', '', $secure, true );
		return array(
			'ok'    => true,
			'token' => $this->state['token'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* Command line                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * CLI entry point.
	 *
	 * @return int Exit code.
	 */
	private function cli_main() {
		$opts = getopt(
			'h',
			array( 'help', 'db-host:', 'db-name:', 'db-user:', 'db-pass:', 'db-prefix:', 'db-action:', 'db-create', 'url:', 'home-url:', 'skip-files', 'new-salts', 'keep-guid', 'no-www-variant', 'skip-verify', 'admin-user:', 'admin-pass:', 'admin-email:', 'replace:', 'source-url:', 'cleanup', 'check' )
		);
		$out = function ( $msg ) {
			fwrite( STDOUT, $msg . "\n" );
		};
		if ( isset( $opts['h'] ) || isset( $opts['help'] ) ) {
			$out( 'WP Migration — installeur (package ' . $this->config['package'] . ')' );
			$out( '' );
			$out( 'Usage : php ' . basename( $this->file ) . ' --url=https://nouveau-site.fr --db-name=base --db-user=utilisateur --db-pass=secret [options]' );
			$out( '' );
			$out( '  --db-host=localhost      Hôte MySQL (hôte:port ou hôte:/chemin/socket)' );
			$out( '  --db-prefix=wp_          Préfixe des tables (par défaut : celui du site d\'origine)' );
			$out( '  --db-action=replace      replace : remplace les tables du même préfixe ; empty : vide toute la base' );
			$out( '  --db-create              Crée la base si elle n\'existe pas' );
			$out( '  --home-url=URL           URL publique (home) si différente de --url' );
			$out( '  --skip-files             Importe uniquement la base de données' );
			$out( '  --new-salts              Régénère les clés de sécurité de wp-config.php' );
			$out( '  --keep-guid              Ne modifie pas la colonne guid des articles' );
			$out( '  --no-www-variant         Ne remplace pas la variante avec / sans « www. » de l\'ancienne adresse' );
			$out( '  --skip-verify            Ne vérifie pas toute l\'archive avant de l\'installer' );
			$out( '  --admin-user=, --admin-pass=, --admin-email=   Crée/réinitialise un administrateur' );
			$out( '  --replace="ancien=>nouveau"   Remplacement supplémentaire (répétable)' );
			$out( '  --source-url=LIEN        Télécharge d\'abord l\'archive depuis le site d\'origine (lien « Transfert direct »)' );
			$out( '  --check                  Affiche uniquement les vérifications' );
			$out( '  --cleanup                Supprime l\'installeur, l\'archive et les fichiers temporaires à la fin' );
			return 0;
		}
		try {
			$this->ensure_data_dir();
			$this->load_state();
			if ( ! $this->archive_path() && ( isset( $opts['source-url'] ) || ! empty( $this->state['download']['url'] ) ) ) {
				if ( empty( $this->state['download']['url'] ) ) {
					$this->download_start( is_array( $opts['source-url'] ) ? end( $opts['source-url'] ) : $opts['source-url'] );
				}
				$out( 'Transfert direct de l\'archive (' . self::size( $this->state['download']['size'] ) . ')…' );
				$last = -1;
				while ( ! $this->download_step( microtime( true ) + 5 ) ) {
					$dl = $this->download_state();
					if ( $dl['progress'] >= $last + 10 ) {
						$out( '  ' . $dl['message'] );
						$last = $dl['progress'];
					}
				}
				$out( '  Archive téléchargée et contrôlée.' );
			}
			$manifest = $this->manifest();
			$out( 'WP Migration — ' . $manifest['site']['home'] . ' (WordPress ' . $manifest['site']['wp_version'] . ', ' . $manifest['created'] . ' UTC)' );
			$checks = $this->checks();
			foreach ( $checks as $c ) {
				$out( sprintf( '  [%s] %s : %s', 'ok' === $c['status'] ? ' OK ' : ( 'error' === $c['status'] ? 'ERR ' : 'ATTN' ), $c['label'], $c['value'] ) );
			}
			if ( isset( $opts['check'] ) ) {
				return $this->has_blocking( $checks ) ? 1 : 0;
			}
			if ( 'complete' === $this->state['status'] ) {
				$out( 'Installation déjà terminée.' );
			} else {
				if ( 'new' === $this->state['status'] ) {
					$map = array(
						'db-host'     => 'db_host',
						'db-name'     => 'db_name',
						'db-user'     => 'db_user',
						'db-pass'     => 'db_pass',
						'db-prefix'   => 'db_prefix',
						'db-action'   => 'db_action',
						'url'         => 'url_site',
						'home-url'    => 'url_home',
						'admin-user'  => 'admin_user',
						'admin-pass'  => 'admin_pass',
						'admin-email' => 'admin_email',
					);
					$in = array();
					foreach ( $map as $opt => $key ) {
						if ( isset( $opts[ $opt ] ) ) {
							$in[ $key ] = is_array( $opts[ $opt ] ) ? end( $opts[ $opt ] ) : $opts[ $opt ];
						}
					}
					$in['www_variants'] = isset( $opts['no-www-variant'] ) ? '' : '1';
					foreach ( array( 'db-create' => 'db_create', 'skip-files' => 'skip_files', 'new-salts' => 'new_salts', 'keep-guid' => 'keep_guid', 'skip-verify' => 'skip_verify' ) as $opt => $key ) {
						if ( isset( $opts[ $opt ] ) ) {
							$in[ $key ] = '1';
						}
					}
					if ( empty( $in['url_site'] ) ) {
						throw new WPMIG_Exception( 'Précisez l\'URL du nouveau site avec --url=https://...' );
					}
					if ( isset( $opts['replace'] ) ) {
						$in['extra_replace'] = implode( "\n", (array) $opts['replace'] );
					}
					$out( 'Installation…' );
					$this->start( $in );
				} else {
					$out( 'Reprise de l\'installation en cours (étape ' . $this->state['step'] . ')…' );
				}
				$last = '';
				while ( 'running' === $this->state['status'] ) {
					$state = $this->step();
					if ( $state['step'] !== $last ) {
						$last = $state['step'];
					}
				}
				foreach ( $this->state['notices'] as $n ) {
					$out( '  - ' . $n );
				}
				foreach ( $this->state['warnings'] as $w ) {
					$out( '  ! ' . $w );
				}
				$out( 'Terminé : ' . $this->state['result']['home'] );
				if ( ! empty( $this->state['result']['checks'] ) ) {
					foreach ( self::checks_lines( $this->state['result']['checks'] ) as $line ) {
						$out( $line );
					}
					$out( 'Rapport complet : ' . $this->state['result']['report'] );
				}
			}
			if ( isset( $opts['cleanup'] ) ) {
				$res = $this->cleanup();
				$out( $res['removed'] ? 'Fichiers d\'installation supprimés.' : 'À supprimer manuellement : ' . implode( ', ', $res['left'] ) );
			}
			return 0;
		} catch ( Exception $e ) {
			fwrite( STDERR, 'ERREUR : ' . $e->getMessage() . "\n" );
			return 1;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Render the installer page.
	 */
	private function render_page() {
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: DENY' );
		$boot = array(
			'package'  => $this->config['name'],
			'source'   => $this->config['source_url'],
			'created'  => $this->config['created'],
			'password' => ! empty( $this->config['password_hash'] ),
			'version'  => WPMIG_INSTALLER,
		);
		?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>WP Migration — Installeur</title>
<style>
:root{--bg:#f0f2f5;--card:#fff;--text:#1d2327;--muted:#646970;--border:#dcdcde;--accent:#2271b1;--accent-h:#135e96;--ok:#00a32a;--warn:#dba617;--err:#d63638;--code:#f6f7f7}
@media (prefers-color-scheme:dark){:root{--bg:#16181c;--card:#1f2228;--text:#e6e6e6;--muted:#a0a5aa;--border:#33373e;--accent:#4f94d4;--accent-h:#72aee6;--code:#2a2e35}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif}
.wrap{max-width:860px;margin:0 auto;padding:24px 16px 60px}
header{display:flex;align-items:center;gap:12px;margin-bottom:20px}
header .logo{width:44px;height:44px;flex:none}header .logo svg{display:block}
header h1{font-size:20px;margin:0}
header small{color:var(--muted);display:block;font-size:13px}
.steps{display:flex;gap:6px;margin:0 0 18px;padding:0;list-style:none;flex-wrap:wrap}
.steps li{flex:1;min-width:120px;padding:8px 10px;border-radius:8px;background:var(--card);border:1px solid var(--border);font-size:13px;color:var(--muted)}
.steps li.active{border-color:var(--accent);color:var(--text);font-weight:600}
.steps li.done{color:var(--ok)}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px 22px;margin-bottom:16px}
.card h2{font-size:17px;margin:0 0 14px}
.card h3{font-size:15px;margin:18px 0 8px}
table{width:100%;border-collapse:collapse;font-size:14px}
td,th{padding:7px 6px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}
th{width:34%;color:var(--muted);font-weight:500}
.badge{display:inline-block;min-width:54px;text-align:center;padding:1px 8px;border-radius:20px;font-size:12px;font-weight:600;color:#fff}
.b-ok{background:var(--ok)}.b-warning{background:var(--warn)}.b-error{background:var(--err)}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 16px}
@media (max-width:640px){.grid{grid-template-columns:1fr}}
label{display:block;font-size:13px;font-weight:600;margin-bottom:4px}
label.inline{display:flex;gap:8px;align-items:flex-start;font-weight:400;font-size:14px;margin:8px 0}
input[type=text],input[type=password],input[type=email],input[type=url],textarea,select{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font:inherit;background:var(--card);color:var(--text)}
textarea{min-height:70px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px}
.hint{color:var(--muted);font-size:12.5px;margin-top:3px}
button,.button{display:inline-block;border:1px solid var(--accent);background:var(--card);color:var(--accent);padding:8px 16px;border-radius:6px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}
button.primary,.button.primary{background:var(--accent);color:#fff}
button.primary:hover,.button.primary:hover{background:var(--accent-h)}
button:disabled{opacity:.5;cursor:not-allowed}
button.danger{border-color:var(--err);color:var(--err)}
.actions{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;margin-top:18px}
.msg{padding:9px 12px;border-radius:6px;margin:6px 0;font-size:14px;border-left:4px solid}
.msg.ok{border-color:var(--ok);background:rgba(0,163,42,.08)}
.msg.warning{border-color:var(--warn);background:rgba(219,166,23,.10)}
.msg.error{border-color:var(--err);background:rgba(214,54,56,.08)}
.bar{height:14px;background:var(--code);border-radius:10px;overflow:hidden;border:1px solid var(--border)}
.bar span{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--accent),var(--accent-h));transition:width .4s}
.progress-label{display:flex;justify-content:space-between;margin:8px 0 0;font-size:14px;color:var(--muted)}
details{margin-top:12px}summary{cursor:pointer;font-weight:600}
code{background:var(--code);padding:1px 5px;border-radius:4px;font-size:13px}
ul.list{margin:6px 0;padding-left:20px}
.hidden{display:none}
.big{font-size:18px;font-weight:600}
table.checks{border-collapse:collapse;margin:10px 0;font-size:14px}
table.checks th{text-align:left;font-weight:600;padding:3px 16px 3px 0;color:var(--muted)}
table.checks td{padding:3px 0}
</style>
</head>
<body>
<div class="wrap">
	<header>
		<div class="logo"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="44" height="44" aria-hidden="true" focusable="false"> <defs> <linearGradient id="wpmig-bg" x1="0" y1="0" x2="0" y2="1"> <stop offset="0" stop-color="#fffaf0"/> <stop offset="1" stop-color="#fbe6c2"/> </linearGradient> <linearGradient id="wpmig-dough" gradientUnits="userSpaceOnUse" x1="0" y1="50" x2="0" y2="215"> <stop offset="0" stop-color="#dc8f3c"/> <stop offset="1" stop-color="#a3561b"/> </linearGradient> <path id="wpmig-belly" d="M45.5 138.3 L45.1 142.0 L45.0 145.9 L45.1 149.7 L45.4 153.5 L45.9 157.3 L46.6 160.9 L47.4 164.5 L48.4 168.0 L49.6 171.4 L50.9 174.7 L52.3 177.9 L53.9 181.0 L55.7 184.1 L57.6 187.0 L59.6 189.7 L61.7 192.4 L63.9 195.0 L66.2 197.4 L68.5 199.8 L71.0 202.0 L73.6 204.1 L76.2 206.1 L78.8 207.9 L81.5 209.7 L84.3 211.3 L87.1 212.8 L90.0 214.3 L92.8 215.6 L95.7 216.8 L98.6 217.8 L101.6 218.8 L104.5 219.7 L107.5 220.5 L110.4 221.1 L113.4 221.7 L116.3 222.2 L119.3 222.5 L122.2 222.8 L125.1 222.9 L128.0 223.0 L130.9 222.9 L133.8 222.8 L136.7 222.5 L139.7 222.2 L142.6 221.7 L145.6 221.1 L148.5 220.5 L151.5 219.7 L154.4 218.8 L157.4 217.8 L160.3 216.8 L163.2 215.6 L166.0 214.3 L168.9 212.8 L171.7 211.3 L174.5 209.7 L177.2 207.9 L179.8 206.1 L182.4 204.1 L185.0 202.0 L187.5 199.8 L189.8 197.4 L192.1 195.0 L194.3 192.4 L196.4 189.7 L198.4 187.0 L200.3 184.1 L202.1 181.0 L203.7 177.9 L205.1 174.7 L206.4 171.4 L207.6 168.0 L208.6 164.5 L209.4 160.9 L210.1 157.3 L210.6 153.5 L210.9 149.7 L211.0 145.9 L210.9 142.0 L210.5 138.3 L185.5 137.7 L185.0 140.8 L184.4 143.5 L183.8 146.1 L183.1 148.5 L182.3 150.8 L181.5 153.0 L180.6 155.1 L179.6 157.1 L178.5 158.9 L177.4 160.7 L176.3 162.4 L175.0 164.0 L173.8 165.6 L172.5 167.0 L171.1 168.4 L169.7 169.8 L168.2 171.1 L166.7 172.3 L165.1 173.4 L163.5 174.5 L161.9 175.6 L160.2 176.6 L158.5 177.5 L156.7 178.4 L155.0 179.2 L153.2 179.9 L151.4 180.7 L149.5 181.3 L147.7 181.9 L145.8 182.5 L144.0 183.0 L142.1 183.4 L140.3 183.8 L138.4 184.1 L136.6 184.4 L134.8 184.6 L133.1 184.8 L131.3 184.9 L129.6 185.0 L128.0 185.0 L126.4 185.0 L124.7 184.9 L122.9 184.8 L121.2 184.6 L119.4 184.4 L117.6 184.1 L115.7 183.8 L113.9 183.4 L112.0 183.0 L110.2 182.5 L108.3 181.9 L106.5 181.3 L104.6 180.7 L102.8 179.9 L101.0 179.2 L99.3 178.4 L97.5 177.5 L95.8 176.6 L94.1 175.6 L92.5 174.5 L90.9 173.4 L89.3 172.3 L87.8 171.1 L86.3 169.8 L84.9 168.4 L83.5 167.0 L82.2 165.6 L81.0 164.0 L79.7 162.4 L78.6 160.7 L77.5 158.9 L76.4 157.1 L75.4 155.1 L74.5 153.0 L73.7 150.8 L72.9 148.5 L72.2 146.1 L71.6 143.5 L71.0 140.8 L70.5 137.7 Z"/> <path id="wpmig-arm-l1" d="M58 162 L58 138 C46 102 60 62 92 62"/> <path id="wpmig-arm-l2" d="M92 62 C118 62 126 90 130 110 C136 138 152 168 176 202"/> <path id="wpmig-arm-r1" d="M198 162 L198 138 C210 102 196 62 164 62"/> <path id="wpmig-arm-r2" d="M164 62 C138 62 130 90 126 110 C120 138 104 168 80 202"/> </defs> <rect x="8" y="8" width="240" height="240" rx="56" fill="url(#wpmig-bg)"/> <path d="M58 190 A94 94 0 1 1 128 222" fill="none" stroke="#2271b1" stroke-width="15" stroke-linecap="round"/> <path d="M34 178 L64 214 L78 172 Z" fill="#2271b1" stroke="#2271b1" stroke-width="7" stroke-linejoin="round"/> <g transform="translate(128 132) scale(0.8) translate(-128 -132)" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path d="M45.5 138.3 L45.1 142.0 L45.0 145.9 L45.1 149.7 L45.4 153.5 L45.9 157.3 L46.6 160.9 L47.4 164.5 L48.4 168.0 L49.6 171.4 L50.9 174.7 L52.3 177.9 L53.9 181.0 L55.7 184.1 L57.6 187.0 L59.6 189.7 L61.7 192.4 L63.9 195.0 L66.2 197.4 L68.5 199.8 L71.0 202.0 L73.6 204.1 L76.2 206.1 L78.8 207.9 L81.5 209.7 L84.3 211.3 L87.1 212.8 L90.0 214.3 L92.8 215.6 L95.7 216.8 L98.6 217.8 L101.6 218.8 L104.5 219.7 L107.5 220.5 L110.4 221.1 L113.4 221.7 L116.3 222.2 L119.3 222.5 L122.2 222.8 L125.1 222.9 L128.0 223.0 L130.9 222.9 L133.8 222.8 L136.7 222.5 L139.7 222.2 L142.6 221.7 L145.6 221.1 L148.5 220.5 L151.5 219.7 L154.4 218.8 L157.4 217.8 L160.3 216.8 L163.2 215.6 L166.0 214.3 L168.9 212.8 L171.7 211.3 L174.5 209.7 L177.2 207.9 L179.8 206.1 L182.4 204.1 L185.0 202.0 L187.5 199.8 L189.8 197.4 L192.1 195.0 L194.3 192.4 L196.4 189.7 L198.4 187.0 L200.3 184.1 L202.1 181.0 L203.7 177.9 L205.1 174.7 L206.4 171.4 L207.6 168.0 L208.6 164.5 L209.4 160.9 L210.1 157.3 L210.6 153.5 L210.9 149.7 L211.0 145.9 L210.9 142.0 L210.5 138.3" stroke="#5e2e0d" stroke-width="9"/> <path d="M70.5 137.7 L71.0 140.8 L71.6 143.5 L72.2 146.1 L72.9 148.5 L73.7 150.8 L74.5 153.0 L75.4 155.1 L76.4 157.1 L77.5 158.9 L78.6 160.7 L79.7 162.4 L81.0 164.0 L82.2 165.6 L83.5 167.0 L84.9 168.4 L86.3 169.8 L87.8 171.1 L89.3 172.3 L90.9 173.4 L92.5 174.5 L94.1 175.6 L95.8 176.6 L97.5 177.5 L99.3 178.4 L101.0 179.2 L102.8 179.9 L104.6 180.7 L106.5 181.3 L108.3 181.9 L110.2 182.5 L112.0 183.0 L113.9 183.4 L115.7 183.8 L117.6 184.1 L119.4 184.4 L121.2 184.6 L122.9 184.8 L124.7 184.9 L126.4 185.0 L128.0 185.0 L129.6 185.0 L131.3 184.9 L133.1 184.8 L134.8 184.6 L136.6 184.4 L138.4 184.1 L140.3 183.8 L142.1 183.4 L144.0 183.0 L145.8 182.5 L147.7 181.9 L149.5 181.3 L151.4 180.7 L153.2 179.9 L155.0 179.2 L156.7 178.4 L158.5 177.5 L160.2 176.6 L161.9 175.6 L163.5 174.5 L165.1 173.4 L166.7 172.3 L168.2 171.1 L169.7 169.8 L171.1 168.4 L172.5 167.0 L173.8 165.6 L175.0 164.0 L176.3 162.4 L177.4 160.7 L178.5 158.9 L179.6 157.1 L180.6 155.1 L181.5 153.0 L182.3 150.8 L183.1 148.5 L183.8 146.1 L184.4 143.5 L185.0 140.8 L185.5 137.7" stroke="#5e2e0d" stroke-width="9"/> <use href="#wpmig-arm-l1" stroke="#5e2e0d" stroke-width="27"/> <use href="#wpmig-arm-r1" stroke="#5e2e0d" stroke-width="27"/> <use href="#wpmig-belly" fill="url(#wpmig-dough)" stroke="none"/> <path d="M86 182 C102 195 154 195 170 182" stroke="#f2b46c" stroke-width="6" opacity=".6"/> <use href="#wpmig-arm-l2" stroke="#5e2e0d" stroke-width="27"/> <use href="#wpmig-arm-l1" stroke="url(#wpmig-dough)" stroke-width="19"/> <use href="#wpmig-arm-l2" stroke="url(#wpmig-dough)" stroke-width="19"/> <use href="#wpmig-arm-r2" stroke="#5e2e0d" stroke-width="27"/> <use href="#wpmig-arm-r1" stroke="url(#wpmig-dough)" stroke-width="19"/> <use href="#wpmig-arm-r2" stroke="url(#wpmig-dough)" stroke-width="19"/> <path d="M56 118 C54 94 66 72 86 72" stroke="#f2b46c" stroke-width="5" opacity=".75"/> <path d="M200 118 C202 94 190 72 170 72" stroke="#f2b46c" stroke-width="5" opacity=".75"/> <g fill="#ffffff" stroke="#d9d4cc" stroke-width="1"> <rect x="100" y="193" width="10" height="8" rx="2" transform="rotate(-18 105 197)"/> <rect x="122" y="198" width="9" height="7" rx="2" transform="rotate(12 126 201)"/> <rect x="142" y="195" width="10" height="8" rx="2" transform="rotate(-8 147 199)"/> <rect x="67" y="164" width="8" height="6" rx="2" transform="rotate(30 71 167)"/> <rect x="183" y="160" width="8" height="6" rx="2" transform="rotate(-28 187 163)"/> <rect x="66" y="80" width="7" height="6" rx="2" transform="rotate(-25 69 83)"/> <rect x="182" y="80" width="7" height="6" rx="2" transform="rotate(25 185 83)"/> </g> </g> </svg></div>
		<div><h1>WP Migration — Installeur</h1><small id="subtitle"></small></div>
	</header>
	<ol class="steps" id="steps">
		<li data-step="1">1. Vérifications</li>
		<li data-step="2">2. Base de données &amp; URL</li>
		<li data-step="3">3. Installation</li>
		<li data-step="4">4. Terminé</li>
	</ol>
	<div id="app"><div class="card">Chargement…</div></div>
</div>
<script>
(function () {
	'use strict';
	var BOOT = <?php echo json_encode( $boot ); ?>;
	var token = '', info = null, lastTest = null, retries = 0;
	var app = document.getElementById('app');
	document.getElementById('subtitle').textContent = 'Package « ' + BOOT.package + ' » — ' + BOOT.source + ' — ' + BOOT.created + ' UTC';

	function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function setStep(n) {
		var items = document.querySelectorAll('#steps li');
		for (var i = 0; i < items.length; i++) {
			var s = +items[i].getAttribute('data-step');
			items[i].className = s === n ? 'active' : (s < n ? 'done' : '');
		}
	}
	function api(action, data) {
		var body = new FormData();
		body.append('wpmig_action', action);
		body.append('token', token);
		data = data || {};
		Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
		return fetch(window.location.pathname, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) {
				return r.text().then(function (t) {
					try { return JSON.parse(t); } catch (e) {
						var err = new Error('Réponse invalide du serveur (HTTP ' + r.status + ') : ' + t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300));
						err.retry = true; throw err;
					}
				});
			});
	}
	function msg(type, text) { return '<div class="msg ' + type + '">' + esc(text) + '</div>'; }
	function badge(s) { var l = { ok: 'OK', warning: 'Attention', error: 'Erreur' }[s] || s; return '<span class="badge b-' + s + '">' + l + '</span>'; }
	function formData(form) {
		var out = {}, els = form.elements;
		for (var i = 0; i < els.length; i++) {
			var el = els[i];
			if (!el.name) continue;
			if (el.type === 'checkbox') { out[el.name] = el.checked ? '1' : ''; }
			else if (el.type === 'radio') { if (el.checked) out[el.name] = el.value; }
			else { out[el.name] = el.value; }
		}
		return out;
	}

	function auth(password) {
		api('auth', { password: password || '' }).then(function (r) {
			if (r.ok) { token = r.token; loadInfo(); return; }
			if (r.password) { renderPassword(r.error); return; }
			app.innerHTML = '<div class="card">' + msg('error', r.error || 'Accès refusé.') + '</div>';
		}).catch(function (e) { app.innerHTML = '<div class="card">' + msg('error', e.message) + '</div>'; });
	}

	function renderPassword(error) {
		setStep(1);
		app.innerHTML = '<div class="card"><h2>Installeur protégé</h2>' + (error ? msg('error', error) : '') +
			'<form id="pw"><label for="password">Mot de passe de l\'installeur</label><input type="password" id="password" autocomplete="current-password" required>' +
			'<div class="actions"><button class="primary" type="submit">Continuer</button></div></form></div>';
		document.getElementById('pw').onsubmit = function (e) { e.preventDefault(); auth(document.getElementById('password').value); };
		document.getElementById('password').focus();
	}

	function loadInfo() {
		api('info').then(function (r) {
			if (!r.ok) { app.innerHTML = '<div class="card">' + msg('error', r.error) + '</div>'; return; }
			info = r;
			if (r.missing) { renderMissing(r); return; }
			if (r.state.status === 'running') { renderProgress(); run(); }
			else if (r.state.status === 'complete') { renderDone(r.state); }
			else { renderChecks(); }
		}).catch(function (e) { app.innerHTML = '<div class="card">' + msg('error', e.message) + '</div>'; });
	}

	function renderMissing(r) {
		setStep(1);
		var p = r.package;
		app.innerHTML =
			'<div class="card"><h2>Archive absente</h2>' +
			'<p>L\'archive <code>' + esc(p.archive) + '</code> n\'est pas dans ce dossier. Deux possibilités :</p>' +
			'<ul class="list"><li>l\'envoyer par FTP/SFTP (en mode binaire) à côté de <code>installer.php</code>, puis <a href="">recharger cette page</a> ;</li>' +
			'<li><strong>la récupérer directement depuis le site d\'origine</strong>, sans passer par votre ordinateur.</li></ul></div>' +
			'<div class="card"><h2>Transfert direct depuis ' + esc(p.home) + '</h2>' +
			(r.can_http ? '' : msg('error', 'Ce serveur ne peut pas télécharger de fichier (ni cURL ni allow_url_fopen) : utilisez le FTP.')) +
			'<p class="hint" style="margin-top:-6px">Sur le site d\'origine : <strong>WP Migration → Packages → Transfert direct</strong>, puis copiez le lien affiché.</p>' +
			'<form id="dlform"><label for="f_source_url">Lien de transfert</label>' +
			'<input type="url" id="f_source_url" required placeholder="' + esc(p.home) + '/wp-admin/admin-ajax.php?action=wpmig_transfer&amp;id=…&amp;key=…">' +
			'<div id="dlmsg"></div>' +
			'<div id="dlprogress" class="hidden"><div class="bar"><span id="dlbar"></span></div><div class="progress-label"><span id="dlstatus"></span><span id="dlpct"></span></div>' +
			'<p class="hint">Vous pouvez fermer cette page : le téléchargement reprendra où il s\'était arrêté.</p></div>' +
			'<div class="actions"><button type="button" id="dlcancel" class="hidden">Annuler</button><button class="primary" type="submit" id="dlgo"' + (r.can_http ? '' : ' disabled') + '>Récupérer l\'archive</button></div></form></div>';
		document.getElementById('dlform').onsubmit = function (e) {
			e.preventDefault();
			var go = document.getElementById('dlgo');
			go.disabled = true;
			document.getElementById('dlmsg').innerHTML = '';
			api('download_start', { source_url: document.getElementById('f_source_url').value }).then(function (res) {
				if (!res.ok) { go.disabled = false; document.getElementById('dlmsg').innerHTML = msg('error', res.error); return; }
				downloadLoop(res.download);
			}).catch(function (err) { go.disabled = false; document.getElementById('dlmsg').innerHTML = msg('error', err.message); });
		};
		document.getElementById('dlcancel').onclick = function () {
			api('download_cancel').then(function () { location.reload(); });
		};
		var fromUrl = new URLSearchParams(window.location.search).get('source_url');
		if (fromUrl) { document.getElementById('f_source_url').value = fromUrl; }
		if (r.download) { downloadLoop(r.download); }
	}

	function downloadLoop(d) {
		document.getElementById('dlprogress').className = '';
		document.getElementById('dlcancel').className = '';
		document.getElementById('dlgo').disabled = true;
		document.getElementById('f_source_url').disabled = true;
		function show(state) {
			document.getElementById('dlbar').style.width = state.progress + '%';
			document.getElementById('dlpct').textContent = state.progress + ' %';
			document.getElementById('dlstatus').textContent = state.message;
		}
		show(d);
		var tries = 0;
		(function next() {
			api('download_step').then(function (res) {
				if (!res.ok) { throw Object.assign(new Error(res.error), { fatal: !!res.error && res.error.indexOf('Morceau') < 0 && res.error.indexOf('impossible :') < 0 }); }
				tries = 0;
				document.getElementById('dlmsg').innerHTML = '';
				if (res.done) {
					document.getElementById('dlmsg').innerHTML = msg('ok', 'Archive téléchargée et contrôlée.');
					setTimeout(loadInfo, 800);
					return;
				}
				if (res.download) { show(res.download); }
				setTimeout(next, res.busy ? 3000 : 100);
			}).catch(function (err) {
				tries++;
				if (!err.fatal && tries <= 6) {
					document.getElementById('dlmsg').innerHTML = msg('warning', 'Problème temporaire (' + err.message + '). Nouvelle tentative ' + tries + '/6…');
					setTimeout(next, 2000 * tries);
					return;
				}
				document.getElementById('dlmsg').innerHTML = msg('error', err.message) + '<div class="actions"><button type="button" id="dlretry">Réessayer</button></div>';
				document.getElementById('dlretry').onclick = function () { tries = 0; document.getElementById('dlmsg').innerHTML = ''; next(); };
			});
		})();
	}

	function renderChecks() {
		setStep(1);
		var p = info.package, rows = '';
		info.checks.forEach(function (c) { rows += '<tr><th>' + esc(c.label) + '</th><td>' + badge(c.status) + ' ' + esc(c.value) + '</td></tr>'; });
		app.innerHTML =
			'<div class="card"><h2>Site à installer</h2><table>' +
			'<tr><th>Site d\'origine</th><td><strong>' + esc(p.blogname) + '</strong> — ' + esc(p.home) + '</td></tr>' +
			'<tr><th>Créé le</th><td>' + esc(p.created) + ' UTC</td></tr>' +
			'<tr><th>WordPress</th><td>' + esc(p.wp_version) + ' (PHP ' + esc(p.php) + ', ' + esc(p.db) + ')</td></tr>' +
			'<tr><th>Contenu</th><td>' + (p.db_only ? 'Base de données uniquement' : esc(Number(p.files).toLocaleString('fr-FR')) + ' fichiers (' + esc(p.size) + ')') + ', ' + esc(p.tables) + ' tables</td></tr>' +
			'</table></div>' +
			'<div class="card"><h2>Vérifications du serveur</h2><table>' + rows + '</table>' +
			(info.blocking ? msg('error', 'Corrigez les erreurs ci-dessus puis rechargez la page.') : '') +
			'<div class="actions"><button type="button" onclick="location.reload()">Relancer les vérifications</button><button class="primary" id="next" ' + (info.blocking ? 'disabled' : '') + '>Continuer</button></div></div>';
		document.getElementById('next').onclick = renderForm;
	}

	function field(name, label, value, type, hint, attrs) {
		return '<div><label for="f_' + name + '">' + label + '</label><input type="' + (type || 'text') + '" id="f_' + name + '" name="' + name + '" value="' + esc(value) + '" ' + (attrs || '') + '>' + (hint ? '<div class="hint">' + hint + '</div>' : '') + '</div>';
	}

	function renderForm() {
		setStep(2);
		var d = info.defaults, p = info.package;
		app.innerHTML =
			'<form id="form">' +
			'<div class="card"><h2>Base de données de destination</h2><div class="grid">' +
			field('db_host', 'Hôte', d.db_host, 'text', 'Souvent « localhost ». Formats acceptés : hôte:port, hôte:/chemin/socket.') +
			field('db_name', 'Nom de la base', d.db_name, 'text', '', 'required') +
			field('db_user', 'Utilisateur', d.db_user, 'text', '', 'required autocomplete="off"') +
			field('db_pass', 'Mot de passe', d.db_pass, 'password', '', 'autocomplete="new-password"') +
			field('db_prefix', 'Préfixe des tables', d.db_prefix, 'text', 'Préfixe d\'origine : <code>' + esc(info.defaults.db_prefix) + '</code>') +
			'<div><label>Tables existantes</label>' +
			'<label class="inline"><input type="radio" name="db_action" value="replace" checked> Remplacer uniquement les tables du site importé (même préfixe)</label>' +
			'<label class="inline"><input type="radio" name="db_action" value="empty"> Vider entièrement la base (supprime toutes les tables)</label>' +
			'<label class="inline"><input type="checkbox" name="db_create" value="1"> Créer la base si elle n\'existe pas</label></div>' +
			'</div><div id="dbtest"></div><div class="actions"><button type="button" id="test">Tester la connexion</button></div></div>' +
			'<div class="card"><h2>Nouvelle adresse du site</h2>' +
			'<p class="hint" style="margin-top:-6px">Ancienne adresse : <code>' + esc(p.home) + '</code>. Toutes les occurrences (y compris dans les données sérialisées et JSON) seront remplacées.</p><div class="grid">' +
			field('url_site', 'Adresse de WordPress (siteurl)', d.url_site, 'url', 'Détectée automatiquement d\'après l\'adresse de l\'installeur.', 'required') +
			field('url_home', 'Adresse du site (home)', d.url_home, 'url', 'Identique à la précédente dans la plupart des cas.', 'required') +
			'</div>' +
			'<details><summary>Options avancées</summary>' +
			(p.db_only ? '' : '<label class="inline"><input type="checkbox" name="skip_files" value="1"> Ne pas extraire les fichiers (importer uniquement la base de données)</label>') +
			'<label class="inline"><input type="checkbox" name="new_salts" value="1"> Régénérer les clés de sécurité (déconnecte tous les utilisateurs)</label>' +
			'<label class="inline"><input type="checkbox" name="www_variants" value="1" checked> Remplacer aussi l\'ancienne adresse avec / sans « www. »</label>' +
			'<label class="inline"><input type="checkbox" name="keep_guid" value="1"> Ne pas modifier la colonne « guid » des articles</label>' +
			'<label class="inline"><input type="checkbox" name="skip_verify" value="1"> Ne pas vérifier l\'intégrité complète de l\'archive avant l\'installation (plus rapide, déconseillé)</label>' +
			'<label for="f_extra">Remplacements supplémentaires (un par ligne : <code>ancien => nouveau</code>)</label><textarea id="f_extra" name="extra_replace" placeholder="contact@ancien-domaine.fr => contact@nouveau-domaine.fr"></textarea>' +
			'<h3>Compte administrateur (facultatif)</h3><p class="hint">Crée un administrateur, ou change le mot de passe s\'il existe déjà. Sinon, connectez-vous avec vos identifiants habituels du site d\'origine.</p><div class="grid">' +
			field('admin_user', 'Identifiant', '', 'text', '', 'autocomplete="off"') +
			field('admin_pass', 'Mot de passe (8 caractères min.)', '', 'password', '', 'autocomplete="new-password"') +
			field('admin_email', 'E-mail', '', 'email') +
			'</div></details></div>' +
			'<div class="card"><label class="inline"><input type="checkbox" id="confirm" required> J\'ai compris que les fichiers et les tables existants à cette adresse seront remplacés.</label>' +
			'<div id="starterr"></div><div class="actions"><button type="button" id="back">Retour</button><button class="primary" type="submit" id="go">Lancer l\'installation</button></div></div>' +
			'</form>';
		document.getElementById('back').onclick = renderChecks;
		document.getElementById('test').onclick = testDb;
		document.getElementById('form').onsubmit = function (e) { e.preventDefault(); startInstall(); };
	}

	function renderTest(t) {
		var html = '';
		t.messages.forEach(function (m) { html += msg(m[0], m[1]); });
		document.getElementById('dbtest').innerHTML = html;
	}

	function testDb() {
		var btn = document.getElementById('test');
		btn.disabled = true; btn.textContent = 'Test en cours…';
		return api('test_db', formData(document.getElementById('form'))).then(function (r) {
			btn.disabled = false; btn.textContent = 'Tester la connexion';
			if (!r.ok) { document.getElementById('dbtest').innerHTML = msg('error', r.error); return false; }
			lastTest = r.test; renderTest(r.test); return r.test.ok;
		}).catch(function (e) { btn.disabled = false; btn.textContent = 'Tester la connexion'; document.getElementById('dbtest').innerHTML = msg('error', e.message); return false; });
	}

	function startInstall() {
		var go = document.getElementById('go'), err = document.getElementById('starterr');
		err.innerHTML = '';
		go.disabled = true;
		testDb().then(function (ok) {
			if (!ok) { go.disabled = false; err.innerHTML = msg('error', 'Corrigez les paramètres de la base de données.'); return; }
			api('start', formData(document.getElementById('form'))).then(function (r) {
				if (!r.ok) { go.disabled = false; err.innerHTML = msg('error', r.error); return; }
				renderProgress(); run();
			}).catch(function (e) { go.disabled = false; err.innerHTML = msg('error', e.message); });
		});
	}

	function renderProgress() {
		setStep(3);
		app.innerHTML = '<div class="card"><h2>Installation en cours</h2><div class="bar"><span id="bar"></span></div>' +
			'<div class="progress-label"><span id="pmsg">Démarrage…</span><span id="ppct">0 %</span></div>' +
			'<div id="perr"></div><p class="hint">Ne fermez pas cette page. En cas de coupure, rechargez-la : l\'installation reprendra où elle s\'était arrêtée.</p></div>';
	}

	function updateProgress(s) {
		document.getElementById('bar').style.width = s.progress + '%';
		document.getElementById('ppct').textContent = s.progress + ' %';
		document.getElementById('pmsg').textContent = s.message;
	}

	function run() {
		api('step').then(function (r) {
			if (!r.ok) {
				if (r.auth) { location.reload(); return; }
				throw Object.assign(new Error(r.error), { retry: !!r.retry, fatal: !r.retry });
			}
			retries = 0;
			document.getElementById('perr').innerHTML = '';
			updateProgress(r.state);
			if (r.state.status === 'complete') { renderDone(r.state); return; }
			if (r.state.status === 'new') { location.reload(); return; }
			setTimeout(run, r.state.busy ? 3000 : 150);
		}).catch(function (e) {
			retries++;
			if (!e.fatal && retries <= 6) {
				document.getElementById('perr').innerHTML = msg('warning', 'Problème temporaire (' + e.message + '). Nouvelle tentative ' + retries + '/6…');
				setTimeout(run, 2000 * retries);
				return;
			}
			document.getElementById('perr').innerHTML = msg('error', e.message) +
				'<div class="actions"><button class="primary" id="retry">Réessayer</button></div>';
			document.getElementById('retry').onclick = function () { retries = 0; document.getElementById('perr').innerHTML = ''; run(); };
		});
	}

	function renderDone(s) {
		setStep(4);
		var res = s.result || {}, list = '';
		(s.notices || []).forEach(function (n) { list += '<li>' + esc(n) + '</li>'; });
		var warns = '';
		(s.warnings || []).forEach(function (w) { warns += msg('warning', w); });
		var checks = '';
		if (res.checks) {
			var c = res.checks, n = function (v) { return Number(v).toLocaleString('fr-FR'); };
			checks = '<table class="checks">' +
				'<tr><th>Archive</th><td>' + (c.verified ? '✔ sommes de contrôle vérifiées' : '⚠ non vérifiée') + '</td></tr>' +
				'<tr><th>Fichiers</th><td>' + (c.files_failed ? '⚠ ' : '✔ ') + n(c.files) + (c.files_expected !== null ? ' / ' + n(c.files_expected) : '') + ' extraits' + (c.files_failed ? ', ' + n(c.files_failed) + ' en échec' : '') + '</td></tr>' +
				'<tr><th>Tables</th><td>' + (c.tables_bad ? '⚠ ' : '✔ ') + n(c.tables - c.tables_bad) + ' / ' + n(c.tables) + ' identiques à la source</td></tr>' +
				'<tr><th>Lignes</th><td>' + (c.tables_bad ? '⚠ ' : '✔ ') + n(c.rows_imported) + ' importées / ' + n(c.rows_exported) + ' exportées</td></tr>' +
				'<tr><th>Requêtes SQL</th><td>' + (c.sql_errors ? '⚠ ' : '✔ ') + n(c.sql_queries) + ', ' + n(c.sql_errors) + ' erreur(s)</td></tr></table>';
			checks = (c.ok ? msg('ok', 'Contrôles réussis : la copie est complète.') : msg('warning', c.issues.join(' '))) + checks +
				'<p class="hint">Ce rapport, avec le journal complet, reste consultable après la suppression des fichiers d\'installation : <strong>WP Migration → Rapport de migration</strong> dans l\'administration du site.</p>';
		}
		app.innerHTML = '<div class="card"><h2>✅ Installation terminée</h2>' +
			'<p class="big">Le site est disponible à l\'adresse <a href="' + esc(res.home) + '" target="_blank" rel="noopener">' + esc(res.home) + '</a></p>' +
			'<p>Connectez-vous avec les identifiants du site d\'origine (ou le compte administrateur défini à l\'étape précédente).</p>' +
			checks + warns + (list ? '<details><summary>Détails</summary><ul class="list">' + list + '</ul></details>' : '') +
			'</div><div class="card"><h2>Sécurité : supprimez les fichiers d\'installation</h2>' +
			'<p>L\'installeur, l\'archive et le dossier de travail contiennent une copie complète du site et de la base de données. Supprimez-les dès que vous avez vérifié le site.</p>' +
			'<div id="cleanmsg"></div><div class="actions"><a class="button" href="' + esc(res.home) + '" target="_blank" rel="noopener">Voir le site</a>' +
			'<button class="primary" id="clean">Supprimer les fichiers et se connecter</button></div></div>';
		document.getElementById('clean').onclick = function () {
			this.disabled = true;
			api('cleanup').then(function (r) {
				if (!r.ok) { document.getElementById('cleanmsg').innerHTML = msg('error', r.error); return; }
				if (!r.cleanup.removed) { document.getElementById('cleanmsg').innerHTML = msg('warning', 'Supprimez manuellement : ' + r.cleanup.left.join(', ')); return; }
				window.location.href = res.login || res.home;
			}).catch(function (e) { document.getElementById('cleanmsg').innerHTML = msg('error', e.message); });
		};
	}

	auth('');
})();
</script>
</body>
</html>
<?php
	}
}

$wpmig_installer = new WPMIG_Installer( $wpmig_config, __FILE__ );
$wpmig_installer->dispatch();
