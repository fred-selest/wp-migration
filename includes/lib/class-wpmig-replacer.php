<?php
/**
 * Serialization-safe search & replace.
 *
 * - Serialized PHP values are parsed (never unserialize()d, so no object
 *   injection and no dependency on the classes of the source site) and every
 *   string length is recomputed after replacement, including nested
 *   (double-serialized) values.
 * - Replacements are done in a single pass (no chained replacements) and only
 *   on "word" boundaries: http://old.com never matches http://old.company.fr.
 * - JSON-escaped (http:\/\/...) and URL-encoded (http%3A%2F%2F...) variants
 *   are generated automatically by build_url_pairs().
 *
 * No WordPress dependency (shared with the standalone installer).
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPMIG_INSTALLER' ) ) {
	exit;
}

if ( ! class_exists( 'WPMIG_Replacer' ) ) {

	/**
	 * Search & replace engine.
	 */
	class WPMIG_Replacer {

		/**
		 * Search => replace map.
		 *
		 * @var array
		 */
		private $map = array();

		/**
		 * Compiled regex.
		 *
		 * @var string
		 */
		private $regex = '';

		/**
		 * Number of serialized values that could not be parsed.
		 *
		 * @var int
		 */
		public $broken_serialized = 0;

		/**
		 * Max nesting of serialized structures.
		 *
		 * @var int
		 */
		private $depth = 0;

		/**
		 * Constructor.
		 *
		 * @param array $pairs search => replace.
		 */
		public function __construct( array $pairs ) {
			foreach ( $pairs as $search => $replace ) {
				$search = (string) $search;
				if ( strlen( $search ) < 3 || $search === (string) $replace ) {
					continue;
				}
				$this->map[ $search ] = (string) $replace;
			}
			if ( ! $this->map ) {
				return;
			}
			$keys = array_keys( $this->map );
			usort( $keys, array( __CLASS__, 'sort_by_length' ) );
			$quoted = array();
			foreach ( $keys as $key ) {
				$quoted[] = preg_quote( $key, '/' );
			}
			// Not preceded / followed by a character that would continue a host name or a path segment.
			$this->regex = '/(?<![A-Za-z0-9_.\-])(?:' . implode( '|', $quoted ) . ')(?![A-Za-z0-9_\-]|\.[A-Za-z0-9])/S';
		}

		/**
		 * Longest first.
		 *
		 * @param string $a A.
		 * @param string $b B.
		 * @return int
		 */
		public static function sort_by_length( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		}

		/**
		 * Anything to do?
		 *
		 * @return bool
		 */
		public function has_pairs() {
			return ! empty( $this->map );
		}

		/**
		 * Search strings.
		 *
		 * @return array
		 */
		public function get_map() {
			return $this->map;
		}

		/**
		 * Quick test: does the string contain at least one search string?
		 *
		 * @param string $value Value.
		 * @return bool
		 */
		public function contains( $value ) {
			foreach ( $this->map as $search => $unused ) {
				if ( false !== strpos( $value, $search ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Replace in any value (serialized or not).
		 *
		 * @param string $value Value.
		 * @return string
		 */
		public function replace( $value ) {
			if ( ! $this->map || ! is_string( $value ) || strlen( $value ) < 3 || ! $this->contains( $value ) ) {
				return $value;
			}
			if ( self::looks_serialized( $value ) ) {
				$pos = 0;
				$out = $this->parse_value( $value, $pos );
				if ( null !== $out && strlen( $value ) === $pos ) {
					return $out;
				}
				$this->broken_serialized++;
			}
			return $this->replace_plain( $value );
		}

		/**
		 * Plain (non serialized) replacement.
		 *
		 * @param string $value Value.
		 * @return string
		 */
		public function replace_plain( $value ) {
			$map    = $this->map;
			$result = preg_replace_callback(
				$this->regex,
				function ( $m ) use ( $map ) {
					return $map[ $m[0] ];
				},
				$value
			);
			return null === $result ? $value : $result;
		}

		/**
		 * Heuristic check for serialized data (same idea as WordPress' is_serialized()).
		 *
		 * @param string $value Value.
		 * @return bool
		 */
		public static function looks_serialized( $value ) {
			if ( 'N;' === $value ) {
				return true;
			}
			if ( strlen( $value ) < 4 || ':' !== $value[1] ) {
				return false;
			}
			$last = substr( $value, -1 );
			if ( ';' !== $last && '}' !== $last ) {
				return false;
			}
			return false !== strpos( 'aOsibdCE', $value[0] );
		}

		/**
		 * Parse one serialized value starting at $pos and return it with replacements
		 * applied (string lengths fixed). Returns null when the data is not valid.
		 *
		 * @param string $s   Serialized data.
		 * @param int    $pos Position (by reference).
		 * @return string|null
		 */
		private function parse_value( $s, &$pos ) {
			$len = strlen( $s );
			if ( $pos >= $len ) {
				return null;
			}
			$type = $s[ $pos ];
			switch ( $type ) {
				case 'N':
					if ( ';' !== substr( $s, $pos + 1, 1 ) ) {
						return null;
					}
					$pos += 2;
					return 'N;';

				case 'b':
				case 'i':
				case 'd':
				case 'r':
				case 'R':
					if ( ':' !== substr( $s, $pos + 1, 1 ) ) {
						return null;
					}
					$end = strpos( $s, ';', $pos );
					if ( false === $end ) {
						return null;
					}
					$out = substr( $s, $pos, $end - $pos + 1 );
					$pos = $end + 1;
					return $out;

				case 's':
					if ( ! preg_match( '/\Gs:(\d+):"/', $s, $m, 0, $pos ) ) {
						return null;
					}
					$n     = (int) $m[1];
					$start = $pos + strlen( $m[0] );
					if ( $start + $n + 2 > $len || '";' !== substr( $s, $start + $n, 2 ) ) {
						return null;
					}
					$str = (string) substr( $s, $start, $n );
					$pos = $start + $n + 2;
					if ( $this->depth < 32 ) {
						$this->depth++;
						$str = $this->replace( $str );
						$this->depth--;
					}
					return 's:' . strlen( $str ) . ':"' . $str . '";';

				case 'a':
					if ( ! preg_match( '/\Ga:(\d+):\{/', $s, $m, 0, $pos ) ) {
						return null;
					}
					$pos += strlen( $m[0] );
					$out  = $m[0];
					$body = $this->parse_members( $s, $pos, (int) $m[1] );
					if ( null === $body ) {
						return null;
					}
					return $out . $body;

				case 'O':
					if ( ! preg_match( '/\GO:(\d+):"/', $s, $m, 0, $pos ) ) {
						return null;
					}
					$cstart = $pos + strlen( $m[0] );
					$clen   = (int) $m[1];
					if ( ! preg_match( '/\G":(\d+):\{/', $s, $m2, 0, $cstart + $clen ) ) {
						return null;
					}
					$out  = substr( $s, $pos, $cstart + $clen - $pos ) . $m2[0];
					$pos  = $cstart + $clen + strlen( $m2[0] );
					$body = $this->parse_members( $s, $pos, (int) $m2[1] );
					if ( null === $body ) {
						return null;
					}
					return $out . $body;

				case 'C':
					// Custom serialization (Serializable): opaque payload, copied as is.
					if ( ! preg_match( '/\GC:(\d+):"/', $s, $m, 0, $pos ) ) {
						return null;
					}
					$cstart = $pos + strlen( $m[0] );
					$clen   = (int) $m[1];
					if ( ! preg_match( '/\G":(\d+):\{/', $s, $m2, 0, $cstart + $clen ) ) {
						return null;
					}
					$dstart = $cstart + $clen + strlen( $m2[0] );
					$dlen   = (int) $m2[1];
					if ( '}' !== substr( $s, $dstart + $dlen, 1 ) ) {
						return null;
					}
					$out = substr( $s, $pos, $dstart + $dlen + 1 - $pos );
					$pos = $dstart + $dlen + 1;
					return $out;

				case 'E':
					// Enum (PHP 8.1+): copied as is.
					if ( ! preg_match( '/\GE:(\d+):"/', $s, $m, 0, $pos ) ) {
						return null;
					}
					$end = $pos + strlen( $m[0] ) + (int) $m[1];
					if ( '";' !== substr( $s, $end, 2 ) ) {
						return null;
					}
					$out = substr( $s, $pos, $end + 2 - $pos );
					$pos = $end + 2;
					return $out;
			}
			return null;
		}

		/**
		 * Parse $count key/value pairs followed by "}".
		 *
		 * @param string $s     Data.
		 * @param int    $pos   Position (by reference).
		 * @param int    $count Number of pairs.
		 * @return string|null
		 */
		private function parse_members( $s, &$pos, $count ) {
			$out = '';
			for ( $i = 0; $i < $count; $i++ ) {
				// Keys are never modified.
				$key_start = $pos;
				$key       = $this->parse_key( $s, $pos );
				if ( null === $key ) {
					return null;
				}
				$out  .= substr( $s, $key_start, $pos - $key_start );
				$value = $this->parse_value( $s, $pos );
				if ( null === $value ) {
					return null;
				}
				$out .= $value;
			}
			if ( '}' !== substr( $s, $pos, 1 ) ) {
				return null;
			}
			$pos++;
			return $out . '}';
		}

		/**
		 * Skip over an array key (i:..; or s:..:"..";).
		 *
		 * @param string $s   Data.
		 * @param int    $pos Position (by reference).
		 * @return bool|null
		 */
		private function parse_key( $s, &$pos ) {
			if ( preg_match( '/\Gi:-?\d+;/', $s, $m, 0, $pos ) ) {
				$pos += strlen( $m[0] );
				return true;
			}
			if ( preg_match( '/\Gs:(\d+):"/', $s, $m, 0, $pos ) ) {
				$end = $pos + strlen( $m[0] ) + (int) $m[1];
				if ( '";' !== substr( $s, $end, 2 ) ) {
					return null;
				}
				$pos = $end + 2;
				return true;
			}
			return null;
		}

		/**
		 * Build the replacement pairs for an URL change, including scheme variants,
		 * protocol-relative, JSON-escaped and URL-encoded forms.
		 *
		 * @param string $old Old URL.
		 * @param string $new New URL.
		 * @return array
		 */
		public static function build_url_pairs( $old, $new ) {
			$old   = rtrim( trim( $old ), '/' );
			$new   = rtrim( trim( $new ), '/' );
			$pairs = array();
			if ( '' === $old || '' === $new || $old === $new ) {
				return $pairs;
			}
			$old_rel = preg_replace( '#^[a-z][a-z0-9+.\-]*:#i', '', $old ); // "//old.com/path".
			$new_rel = preg_replace( '#^[a-z][a-z0-9+.\-]*:#i', '', $new );
			if ( 0 !== strpos( $old_rel, '//' ) || 0 !== strpos( $new_rel, '//' ) ) {
				$pairs[ $old ] = $new;
				return $pairs;
			}
			$plain = array(
				'http:' . $old_rel  => $new,
				'https:' . $old_rel => $new,
				$old_rel            => $new_rel,
			);
			foreach ( $plain as $search => $replace ) {
				$pairs[ $search ]                                              = $replace;
				$pairs[ str_replace( '/', '\\/', $search ) ]                   = str_replace( '/', '\\/', $replace );
				$pairs[ rawurlencode( $search ) ]                              = rawurlencode( $replace );
				$pairs[ str_replace( '%2F', '%2f', rawurlencode( $search ) ) ] = str_replace( '%2F', '%2f', rawurlencode( $replace ) );
			}
			return $pairs;
		}

		/**
		 * Build the replacement pairs for a filesystem path change.
		 *
		 * @param string $old Old absolute path.
		 * @param string $new New absolute path.
		 * @return array
		 */
		public static function build_path_pairs( $old, $new ) {
			$old   = rtrim( str_replace( '\\', '/', $old ), '/' );
			$new   = rtrim( str_replace( '\\', '/', $new ), '/' );
			$pairs = array();
			if ( strlen( $old ) < 4 || $old === $new ) {
				return $pairs;
			}
			$pairs[ $old ]                             = $new;
			$pairs[ str_replace( '/', '\\/', $old ) ] = str_replace( '/', '\\/', $new );
			if ( preg_match( '#^[a-zA-Z]:/#', $old ) ) {
				// Windows paths.
				$pairs[ str_replace( '/', '\\', $old ) ]   = str_replace( '/', DIRECTORY_SEPARATOR, $new );
				$pairs[ str_replace( '/', '\\\\', $old ) ] = str_replace( '/', '\\\\', $new );
			}
			return $pairs;
		}
	}
}
