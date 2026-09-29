<?php
/**
 * Consistency checks of a site after a migration: menu locations, permalinks,
 * WPML / Polylang (translation links, default language, language domains).
 *
 * The checks never change anything. They work on plain data collected by
 * collect() (shared by the installer, which has no WordPress, and by the plugin)
 * and evaluate() turns that data into a list of findings. Serialized options are
 * read by parse(), which never instantiates objects.
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPMIG_INSTALLER' ) ) {
	exit;
}

if ( ! class_exists( 'WPMIG_Consistency' ) ) {

	/**
	 * Consistency checks.
	 */
	class WPMIG_Consistency {

		/**
		 * Read a serialized value without instantiating anything.
		 *
		 * @param string|null $s Serialized data.
		 * @return mixed Value, or null when the data is empty, invalid or holds objects.
		 */
		public static function parse( $s ) {
			if ( ! is_string( $s ) || '' === $s ) {
				return null;
			}
			$pos = 0;
			$ok  = true;
			$out = self::read( $s, $pos, 0, $ok );
			return ( $ok && $pos === strlen( $s ) ) ? $out : null;
		}

		/**
		 * Read one value.
		 *
		 * @param string $s     Data.
		 * @param int    $pos   Position (by reference).
		 * @param int    $depth Nesting.
		 * @param bool   $ok    Set to false on a read error (by reference).
		 * @return mixed
		 */
		private static function read( $s, &$pos, $depth, &$ok ) {
			if ( $depth > 24 || $pos >= strlen( $s ) ) {
				$ok = false;
				return null;
			}
			$type = $s[ $pos ];
			if ( 'N' === $type && ';' === substr( $s, $pos + 1, 1 ) ) {
				$pos += 2;
				return null;
			}
			if ( ( 'b' === $type || 'i' === $type || 'd' === $type ) && preg_match( '/\G[bid]:(-?[0-9.eE+\-]+|INF|NAN);/', $s, $m, 0, $pos ) ) {
				$pos += strlen( $m[0] );
				if ( 'b' === $type ) {
					return '1' === $m[1];
				}
				return 'i' === $type ? (int) $m[1] : (float) $m[1];
			}
			if ( 's' === $type && preg_match( '/\Gs:(\d+):"/', $s, $m, 0, $pos ) ) {
				$start = $pos + strlen( $m[0] );
				$len   = (int) $m[1];
				if ( '";' !== substr( $s, $start + $len, 2 ) ) {
					$ok = false;
					return null;
				}
				$pos = $start + $len + 2;
				return (string) substr( $s, $start, $len );
			}
			if ( 'a' === $type && preg_match( '/\Ga:(\d+):\{/', $s, $m, 0, $pos ) ) {
				$pos  += strlen( $m[0] );
				$count = (int) $m[1];
				$out   = array();
				for ( $i = 0; $i < $count; $i++ ) {
					$key = self::read( $s, $pos, $depth + 1, $ok );
					if ( ! $ok || ! ( is_int( $key ) || is_string( $key ) ) ) {
						$ok = false;
						return null;
					}
					$out[ $key ] = self::read( $s, $pos, $depth + 1, $ok );
					if ( ! $ok ) {
						return null;
					}
				}
				if ( '}' !== substr( $s, $pos, 1 ) ) {
					$ok = false;
					return null;
				}
				++$pos;
				return $out;
			}
			// Objects and anything else: not read.
			$ok = false;
			return null;
		}

		/**
		 * Registrable part of a host name (example.com, example.co.uk).
		 *
		 * @param string $host Host.
		 * @return string
		 */
		private static function base_host( $host ) {
			$labels = explode( '.', strtolower( trim( $host, '. ' ) ) );
			$n      = count( $labels );
			if ( $n <= 2 ) {
				return implode( '.', $labels );
			}
			$take = ( strlen( $labels[ $n - 1 ] ) === 2 && in_array( $labels[ $n - 2 ], array( 'co', 'com', 'org', 'net', 'gov', 'ac' ), true ) ) ? 3 : 2;
			return implode( '.', array_slice( $labels, -$take ) );
		}

		/**
		 * Host name of an address or of a bare host name.
		 *
		 * @param string $value Address.
		 * @return string
		 */
		private static function host_of( $value ) {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return '';
			}
			$host = parse_url( false === strpos( $value, '//' ) ? '//' . $value : $value, PHP_URL_HOST );
			return is_string( $host ) ? strtolower( $host ) : '';
		}

		/**
		 * Hosts among the given values that still belong to the old site.
		 *
		 * @param array  $hosts    lang => address or host.
		 * @param string $old_host Host of the source site.
		 * @param string $new_host Host of this site.
		 * @return array lang => host.
		 */
		private static function stale_hosts( array $hosts, $old_host, $new_host ) {
			$stale = array();
			$strip = function ( $h ) {
				return 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : $h;
			};
			foreach ( $hosts as $lang => $value ) {
				$host = self::host_of( $value );
				if ( '' === $host || $strip( $host ) === $strip( strtolower( $new_host ) ) ) {
					continue;
				}
				if ( '' !== $old_host && ( $strip( $host ) === $strip( strtolower( $old_host ) ) || self::base_host( $host ) === self::base_host( $old_host ) ) ) {
					$stale[ $lang ] = $host;
				}
			}
			return $stale;
		}

		/**
		 * Collect the data of the checks from the database.
		 *
		 * @param string   $prefix   Table prefix.
		 * @param callable $query    function ( $sql ): list of rows (associative arrays), empty on error.
		 * @param callable $escape   function ( $string ): string escaped for a quoted SQL literal.
		 * @param string   $old_home Address of the source site ('' when unknown).
		 * @param string   $new_home Address of this site.
		 * @param array    $source   Profile of the source (see profile()), or empty.
		 * @return array
		 */
		public static function collect( $prefix, $query, $escape, $old_home, $new_home, array $source = array() ) {
			$t   = function ( $name ) use ( $prefix ) {
				return '`' . str_replace( '`', '``', $prefix . $name ) . '`';
			};
			$opt = array();
			foreach ( (array) call_user_func( $query, 'SELECT option_name, option_value FROM ' . $t( 'options' ) . " WHERE option_name IN ('stylesheet', 'permalink_structure', 'rewrite_rules', 'polylang', 'icl_sitepress_settings')" ) as $row ) {
				$opt[ $row['option_name'] ] = $row['option_value'];
			}
			$stylesheet = isset( $opt['stylesheet'] ) ? (string) $opt['stylesheet'] : '';
			$mods       = null;
			if ( '' !== $stylesheet ) {
				$rows = call_user_func( $query, 'SELECT option_value FROM ' . $t( 'options' ) . " WHERE option_name = '" . call_user_func( $escape, 'theme_mods_' . $stylesheet ) . "'" );
				$mods = $rows ? $rows[0]['option_value'] : null;
			}
			$menus = array();
			foreach ( (array) call_user_func( $query, 'SELECT tt.term_id FROM ' . $t( 'term_taxonomy' ) . " tt WHERE tt.taxonomy = 'nav_menu'" ) as $row ) {
				$menus[] = (int) $row['term_id'];
			}
			$languages = array();
			foreach ( (array) call_user_func( $query, 'SELECT t.slug FROM ' . $t( 'terms' ) . ' t JOIN ' . $t( 'term_taxonomy' ) . " tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'language'" ) as $row ) {
				$languages[] = $row['slug'];
			}
			$icl = null;
			$has = call_user_func( $query, "SHOW TABLES LIKE '" . call_user_func( $escape, str_replace( array( '_', '%' ), array( '\\_', '\\%' ), $prefix . 'icl_translations' ) ) . "'" );
			if ( $has ) {
				$c   = call_user_func( $query, 'SELECT COUNT(*) AS total, SUM(element_id IS NULL) AS empty_links FROM ' . $t( 'icl_translations' ) );
				$o   = call_user_func( $query, 'SELECT COUNT(*) AS orphans FROM ' . $t( 'icl_translations' ) . ' i LEFT JOIN ' . $t( 'posts' ) . " p ON p.ID = i.element_id WHERE i.element_type LIKE 'post\\_%' AND i.element_id IS NOT NULL AND p.ID IS NULL" );
				$icl = array(
					'total'  => $c ? (int) $c[0]['total'] : 0,
					'null'   => $c ? (int) $c[0]['empty_links'] : 0,
					'orphan' => $o ? (int) $o[0]['orphans'] : 0,
				);
			}
			return array(
				'old_host'           => self::host_of( $old_home ),
				'new_host'           => self::host_of( $new_home ),
				'permalinks'         => isset( $opt['permalink_structure'] ) ? (string) $opt['permalink_structure'] : '',
				'rewrite_rules'      => isset( $opt['rewrite_rules'] ) && '' !== $opt['rewrite_rules'] && 'a:0:{}' !== $opt['rewrite_rules'],
				'stylesheet'         => $stylesheet,
				'theme_mods'         => $mods,
				'menus'              => $menus,
				'polylang'           => isset( $opt['polylang'] ) ? $opt['polylang'] : null,
				'polylang_languages' => $languages,
				'wpml_settings'      => isset( $opt['icl_sitepress_settings'] ) ? $opt['icl_sitepress_settings'] : null,
				'icl'                => $icl,
				'source'             => $source ? $source : null,
			);
		}

		/**
		 * Figures of the source site kept in the package for the comparison.
		 *
		 * @param array $data Collected data.
		 * @return array|null Null without WPML.
		 */
		public static function profile( array $data ) {
			if ( ! $data['icl'] ) {
				return null;
			}
			return array(
				'icl_total'  => $data['icl']['total'],
				'icl_null'   => $data['icl']['null'],
				'icl_orphan' => $data['icl']['orphan'],
			);
		}

		/**
		 * Turn collected data into findings.
		 *
		 * @param array $d Data from collect().
		 * @return array List of array( id, label, status ok|warning|info, message, details ).
		 */
		public static function evaluate( array $d ) {
			$items = array();
			$add   = function ( $id, $label, $status, $message, array $details = array() ) use ( &$items ) {
				$items[] = array(
					'id'      => $id,
					'label'   => $label,
					'status'  => $status,
					'message' => $message,
					'details' => $details,
				);
			};

			// Permalinks.
			if ( '' !== $d['permalinks'] ) {
				if ( $d['rewrite_rules'] ) {
					$add( 'permalinks', 'Permaliens', 'ok', 'Règles de réécriture enregistrées.' );
				} else {
					$add( 'permalinks', 'Permaliens', 'info', 'Les règles de réécriture sont régénérées à la première visite du site. Si des pages (notamment traduites, /fr/…) répondent en 404 : Réglages → Permaliens → Enregistrer.' );
				}
			}

			$mods = self::parse( $d['theme_mods'] );
			$pll  = self::parse( $d['polylang'] );
			$wpml = self::parse( $d['wpml_settings'] );
			if ( ! is_array( $pll ) ) {
				$pll = array();
			}
			if ( ! is_array( $wpml ) ) {
				$wpml = array();
			}

			// Menu locations.
			$bad   = array();
			$count = 0;
			$check = function ( $where, $id ) use ( $d, &$bad, &$count ) {
				if ( is_numeric( $id ) && (int) $id > 0 ) {
					++$count;
					if ( ! in_array( (int) $id, $d['menus'], true ) ) {
						$bad[] = sprintf( '%s : le menu n° %d n\'existe pas.', $where, (int) $id );
					}
				}
			};
			if ( is_array( $mods ) && isset( $mods['nav_menu_locations'] ) && is_array( $mods['nav_menu_locations'] ) ) {
				foreach ( $mods['nav_menu_locations'] as $location => $id ) {
					$check( 'Emplacement « ' . $location . ' »', $id );
				}
			}
			if ( isset( $pll['nav_menus'][ $d['stylesheet'] ] ) && is_array( $pll['nav_menus'][ $d['stylesheet'] ] ) ) {
				foreach ( $pll['nav_menus'][ $d['stylesheet'] ] as $location => $langs ) {
					foreach ( (array) $langs as $lang => $id ) {
						$check( 'Emplacement « ' . $location . ' » (' . $lang . ')', $id );
					}
				}
			}
			if ( $bad ) {
				$add( 'menus', 'Menus', 'warning', sprintf( '%d emplacement(s) de menu pointent vers un menu absent : le menu du thème « %s » ne s\'affichera pas. Réglages du thème → Menus (ou Apparence → Menus).', count( $bad ), $d['stylesheet'] ), $bad );
			} elseif ( $count ) {
				$add( 'menus', 'Menus', 'ok', sprintf( '%d association(s) emplacement → menu, toutes vers un menu existant.', $count ) );
			} elseif ( is_array( $mods ) ) {
				$add( 'menus', 'Menus', 'info', 'Aucun menu n\'est associé à un emplacement du thème « ' . $d['stylesheet'] . ' ».' );
			}

			// Multilingual plugins.
			$has_pll  = ! empty( $pll ) || ! empty( $d['polylang_languages'] );
			$has_wpml = null !== $d['icl'] || ! empty( $wpml );
			if ( $has_pll && $has_wpml && ! empty( $d['polylang_languages'] ) ) {
				$add( 'multilingual', 'Langues', 'info', 'Polylang et des données WPML sont présents. Si vous êtes passé de WPML à Polylang, les tables WPML (*_icl_*) sont obsolètes ; vérifiez les menus et les langues de chaque contenu.' );
			}
			$stale_check = function ( $hosts, $name ) use ( $d, $add ) {
				$stale = self::stale_hosts( $hosts, $d['old_host'], $d['new_host'] );
				if ( $stale ) {
					$lines = array();
					foreach ( $stale as $lang => $host ) {
						$lines[] = $lang . ' : ' . $host;
					}
					$add( 'domains', 'Domaines de langue', 'warning', sprintf( '%s : %d langue(s) pointent encore vers l\'ancien site (adresse de ce site : %s). À corriger dans les réglages des langues.', $name, count( $stale ), $d['new_host'] ), $lines );
				} elseif ( $hosts ) {
					$add( 'domains', 'Domaines de langue', 'ok', sprintf( '%s : les domaines de langue ne pointent pas vers l\'ancien site.', $name ) );
				}
			};

			if ( $has_wpml ) {
				if ( $d['icl'] ) {
					$i   = $d['icl'];
					$src = is_array( $d['source'] ) ? $d['source'] : null;
					$det = array();
					if ( $i['null'] ) {
						$det[] = sprintf( '%d ligne(s) de traduction sans contenu associé (element_id vide).', $i['null'] );
					}
					if ( $i['orphan'] ) {
						$det[] = sprintf( '%d ligne(s) qui pointent vers un contenu absent.', $i['orphan'] );
					}
					if ( $src && isset( $src['icl_null'] ) ) {
						if ( (int) $src['icl_null'] === $i['null'] && (int) $src['icl_orphan'] === $i['orphan'] && (int) $src['icl_total'] === $i['total'] ) {
							$add( 'wpml_links', 'WPML : traductions', 'ok', sprintf( '%d lien(s) de traduction, identiques à ceux du site d\'origine%s.', $i['total'], $det ? ' (dont ' . ( $i['null'] + $i['orphan'] ) . ' déjà sans contenu associé sur l\'origine : traductions en attente ou supprimées dans WPML)' : '' ) );
						} else {
							$add(
								'wpml_links',
								'WPML : traductions',
								'warning',
								sprintf( 'Liens de traduction différents de l\'origine : %d ligne(s) sans contenu (origine : %d), %d vers un contenu absent (origine : %d), %d au total (origine : %d).', $i['null'], (int) $src['icl_null'], $i['orphan'], (int) $src['icl_orphan'], $i['total'], (int) $src['icl_total'] ),
								$det
							);
						}
					} elseif ( $det ) {
						$add( 'wpml_links', 'WPML : traductions', 'info', sprintf( '%d lien(s) de traduction, dont des lignes sans contenu associé. C\'est normal pour des traductions en attente dans WPML ; comparez avec le site d\'origine (SELECT COUNT(*) … WHERE element_id IS NULL).', $i['total'] ), $det );
					} else {
						$add( 'wpml_links', 'WPML : traductions', 'ok', sprintf( '%d lien(s) de traduction, tous vers un contenu existant.', $i['total'] ) );
					}
				}
				if ( $wpml ) {
					if ( empty( $wpml['default_language'] ) ) {
						$add( 'wpml_default', 'WPML : langue par défaut', 'warning', 'Aucune langue par défaut n\'est définie dans les réglages de WPML.' );
					}
					if ( isset( $wpml['language_negotiation_type'] ) && 2 === (int) $wpml['language_negotiation_type'] && ! empty( $wpml['language_domains'] ) && is_array( $wpml['language_domains'] ) ) {
						$stale_check( $wpml['language_domains'], 'WPML' );
					}
					if ( ! empty( $wpml['site_key'] ) && '' !== $d['old_host'] && $d['old_host'] !== $d['new_host'] ) {
						$add( 'wpml_key', 'WPML : clé de site', 'info', 'La clé de site WPML est liée au domaine : à réactiver pour ' . $d['new_host'] . ' (compte wpml.org → Sites) pour recevoir les mises à jour.' );
					}
				}
			}
			if ( $has_pll ) {
				$languages = $d['polylang_languages'];
				$default   = isset( $pll['default_lang'] ) ? (string) $pll['default_lang'] : '';
				if ( '' === $default ) {
					$add( 'pll_default', 'Polylang : langue par défaut', 'warning', 'Aucune langue par défaut n\'est définie dans les réglages de Polylang (Langues → Réglages).' );
				} elseif ( $languages && ! in_array( $default, $languages, true ) ) {
					$add( 'pll_default', 'Polylang : langue par défaut', 'warning', sprintf( 'La langue par défaut « %s » ne fait pas partie des langues (%s).', $default, implode( ', ', $languages ) ) );
				} elseif ( $languages ) {
					$add( 'pll_default', 'Polylang : langues', 'ok', sprintf( 'Langues : %s (par défaut : %s).', implode( ', ', $languages ), $default ) );
				}
				if ( isset( $pll['force_lang'] ) && 3 === (int) $pll['force_lang'] && ! empty( $pll['domains'] ) && is_array( $pll['domains'] ) ) {
					$stale_check( $pll['domains'], 'Polylang' );
				}
			}
			return $items;
		}

		/**
		 * Number of findings by status.
		 *
		 * @param array $items Findings.
		 * @return array ok, warning, info.
		 */
		public static function counts( array $items ) {
			$c = array(
				'ok'      => 0,
				'warning' => 0,
				'info'    => 0,
			);
			foreach ( $items as $item ) {
				if ( isset( $c[ $item['status'] ] ) ) {
					++$c[ $item['status'] ];
				}
			}
			return $c;
		}
	}
}
