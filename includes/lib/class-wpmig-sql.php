<?php
/**
 * SQL helpers: escaping and parsing of the INSERT statements written by the exporter.
 *
 * Dump format rules (guaranteed by WPMIG_DB_Exporter):
 *  - one statement per line, terminated by ";";
 *  - string literals are escaped like mysql_real_escape_string() (no raw newline);
 *  - binary values are written as 0x... hexadecimal literals.
 *
 * No WordPress dependency (shared with the standalone installer).
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPMIG_INSTALLER' ) ) {
	exit;
}

if ( ! class_exists( 'WPMIG_SQL' ) ) {

	/**
	 * SQL helpers.
	 */
	class WPMIG_SQL {

		/**
		 * Escape a string for a single-quoted SQL literal.
		 *
		 * @param string $value Value.
		 * @return string
		 */
		public static function escape( $value ) {
			return strtr(
				(string) $value,
				array(
					'\\'   => '\\\\',
					"\0"   => '\\0',
					"\n"   => '\\n',
					"\r"   => '\\r',
					"'"    => "\\'",
					'"'    => '\\"',
					"\x1a" => '\\Z',
				)
			);
		}

		/**
		 * Unescape the content of a single-quoted SQL literal.
		 *
		 * @param string $value Escaped content (without the quotes).
		 * @return string
		 */
		public static function unescape( $value ) {
			if ( false === strpos( $value, '\\' ) && false === strpos( $value, "''" ) ) {
				return $value;
			}
			return preg_replace_callback(
				"/\\\\(.)|''/s",
				array( __CLASS__, 'unescape_cb' ),
				$value
			);
		}

		/**
		 * Callback for unescape().
		 *
		 * @param array $m Match.
		 * @return string
		 */
		public static function unescape_cb( $m ) {
			if ( "''" === $m[0] ) {
				return "'";
			}
			switch ( $m[1] ) {
				case '0':
					return "\0";
				case 'n':
					return "\n";
				case 'r':
					return "\r";
				case 't':
					return "\t";
				case 'b':
					return "\x08";
				case 'Z':
					return "\x1a";
			}
			return $m[1];
		}

		/**
		 * Parse an "INSERT INTO `t` (`a`,`b`) VALUES (...),(...);" statement.
		 *
		 * @param string $sql Statement.
		 * @return array|null array( 'head' => ..., 'table' => ..., 'columns' => array, 'rows' => array ) or null.
		 *                    Each value is array( 0 => type ('s' string, 'r' raw), 1 => value ).
		 */
		public static function parse_insert( $sql ) {
			if ( ! preg_match( '/^(INSERT\s+(?:IGNORE\s+)?INTO\s+`((?:[^`]|``)+)`\s*(?:\(([^)]*)\))?\s*VALUES\s*)/i', $sql, $m ) ) {
				return null;
			}
			$columns = array();
			if ( isset( $m[3] ) && '' !== $m[3] ) {
				foreach ( explode( ',', $m[3] ) as $col ) {
					$columns[] = str_replace( '``', '`', trim( trim( $col ), '`' ) );
				}
			}
			$pos  = strlen( $m[1] );
			$len  = strlen( $sql );
			$rows = array();
			while ( $pos < $len ) {
				if ( '(' !== $sql[ $pos ] ) {
					return null;
				}
				$pos++;
				$row = array();
				while ( true ) {
					if ( $pos >= $len ) {
						return null;
					}
					$c = $sql[ $pos ];
					if ( "'" === $c ) {
						$start = ++$pos;
						while ( true ) {
							$pos += strcspn( $sql, "\\'", $pos );
							if ( $pos >= $len ) {
								return null;
							}
							if ( '\\' === $sql[ $pos ] ) {
								$pos += 2;
								continue;
							}
							if ( $pos + 1 < $len && "'" === $sql[ $pos + 1 ] ) {
								$pos += 2; // Doubled quote.
								continue;
							}
							break;
						}
						$row[] = array( 's', self::unescape( substr( $sql, $start, $pos - $start ) ) );
						$pos++;
					} else {
						$end = $pos + strcspn( $sql, ',)', $pos );
						if ( $end >= $len ) {
							return null;
						}
						$row[] = array( 'r', trim( substr( $sql, $pos, $end - $pos ) ) );
						$pos   = $end;
					}
					if ( $pos >= $len ) {
						return null;
					}
					if ( ',' === $sql[ $pos ] ) {
						$pos++;
						continue;
					}
					if ( ')' === $sql[ $pos ] ) {
						$pos++;
						break;
					}
					return null;
				}
				$rows[] = $row;
				if ( $pos < $len && ',' === $sql[ $pos ] ) {
					$pos++;
					continue;
				}
				$rest = trim( substr( $sql, $pos ) );
				if ( ';' === $rest || '' === $rest ) {
					break;
				}
				return null;
			}
			return array(
				'head'    => $m[1],
				'table'   => str_replace( '``', '`', $m[2] ),
				'columns' => $columns,
				'rows'    => $rows,
			);
		}

		/**
		 * Build a SQL tuple from a parsed row.
		 *
		 * @param array $row Values.
		 * @return string
		 */
		public static function build_row( array $row ) {
			$out = array();
			foreach ( $row as $value ) {
				$out[] = 's' === $value[0] ? "'" . self::escape( $value[1] ) . "'" : $value[1];
			}
			return '(' . implode( ',', $out ) . ')';
		}

		/**
		 * Quote an identifier.
		 *
		 * @param string $name Identifier.
		 * @return string
		 */
		public static function quote_id( $name ) {
			return '`' . str_replace( '`', '``', $name ) . '`';
		}
	}
}
