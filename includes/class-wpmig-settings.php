<?php
/**
 * Settings taken from another site: a few options (payment gateway, shop
 * settings, language plugin, widgets...) are read on the source site through the
 * synchronization link, compared with the local ones, then written here.
 *
 * The values are never unserialized (no object injection): they are copied as
 * stored, and the addresses of the source are replaced with the serialization-safe
 * replacer. The old value of every option is written to a journal before the
 * change, so that the operation can be undone (an option edited since is kept).
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings pulled from another site.
 */
class WPMIG_Settings {

	const HISTORY   = 'wpmig_settings_history';
	const KEEP      = 5;
	const MAX_VALUE = 1048576;
	const MAX_NAMES = 50;
	const MAX_LIST  = 300;

	/* ------------------------------------------------------------------ */
	/* Rules                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Why an option is never copied (empty when it can be).
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	public static function protected_reason( $name ) {
		$name = (string) $name;
		if ( '' === $name || strlen( $name ) > 191 || preg_match( '/[\x00-\x1f]/', $name ) ) {
			return 'nom invalide';
		}
		if ( 0 === strpos( $name, 'wpmig_' ) ) {
			return 'réglage de WP Migration';
		}
		$exact = array(
			'siteurl', 'home', 'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet', 'current_theme',
			'cron', 'rewrite_rules', 'recently_activated', 'upload_path', 'upload_url_path', 'uninstall_plugins',
			'fresh_site', 'auto_updater.lock', 'core_updater.lock', 'recovery_keys', 'wp_force_deactivated_plugins',
		);
		if ( in_array( $name, $exact, true ) ) {
			return 'propre à chaque site (adresse, thème, extensions, planification)';
		}
		if ( preg_match( '/^_(site_)?transient_|^_wc_session|user_roles$/', $name ) ) {
			return 'donnée temporaire ou de session';
		}
		if ( preg_match( '/(^|_)(db_)?version$/', $name ) ) {
			return 'numéro de version géré par l\'extension';
		}
		return '';
	}

	/**
	 * Does a name (or key) look like it holds a secret?
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function secret_name( $name ) {
		return (bool) preg_match( '/pass|secret|token|key|salt|signature|hmac|api|credential|private/i', (string) $name );
	}

	/**
	 * A warning when the value probably refers to contents by their number.
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	public static function references_note( $name ) {
		if ( preg_match( '/(_ids?|page_on_front|page_for_posts|nav_menu|theme_mods_|sidebars_widgets|polylang)$|^(nav_menu_options|wp_page_for_privacy_policy)$/', $name ) ) {
			return 'Ce réglage désigne peut-être des pages, menus ou termes par leur numéro : vérifiez qu\'ils existent sur ce site.';
		}
		return '';
	}

	/**
	 * Autoload flag as understood by every WordPress version.
	 *
	 * @param string $flag Flag.
	 * @return string yes|no.
	 */
	public static function autoload( $flag ) {
		return in_array( (string) $flag, array( 'no', 'off', 'auto-off' ), true ) ? 'no' : 'yes';
	}

	/* ------------------------------------------------------------------ */
	/* Source side                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Options of this site that can be copied.
	 *
	 * @param string $q Part of the name.
	 * @return array total, options [name, size, autoload].
	 */
	public static function source_list( $q ) {
		global $wpdb;
		$q    = trim( (string) $q );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS size, autoload FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT 5000", // phpcs:ignore WordPress.DB.PreparedSQL
				'%' . $wpdb->esc_like( $q ) . '%'
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( '' === self::protected_reason( $row['option_name'] ) ) {
				$out[] = array(
					'name'     => $row['option_name'],
					'size'     => (int) $row['size'],
					'autoload' => self::autoload( $row['autoload'] ),
				);
			}
		}
		return array(
			'total'   => count( $out ),
			'options' => array_slice( $out, 0, self::MAX_LIST ),
		);
	}

	/**
	 * Raw values of options of this site.
	 *
	 * @param array $names Names.
	 * @return array
	 */
	public static function source_get( array $names ) {
		global $wpdb;
		$out = array();
		foreach ( array_slice( array_unique( $names ), 0, self::MAX_NAMES ) as $name ) {
			$name = (string) $name;
			if ( '' !== self::protected_reason( $name ) ) {
				continue;
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( ! $row ) {
				continue;
			}
			if ( strlen( $row['option_value'] ) > self::MAX_VALUE ) {
				$out[] = array( 'name' => $name, 'too_big' => strlen( $row['option_value'] ) );
				continue;
			}
			$out[] = array(
				'name'     => $name,
				'value'    => $row['option_value'],
				'autoload' => self::autoload( $row['autoload'] ),
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Destination side                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Local state of an option (raw, never unserialized).
	 *
	 * @param string $name Name.
	 * @return array|null value, autoload.
	 */
	private static function local( $name ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ? $row : null;
	}

	/**
	 * Request to the source about its options.
	 *
	 * @param string $link   Link.
	 * @param array  $params Parameters.
	 * @return array
	 * @throws WPMIG_Exception On error.
	 */
	private static function fetch( $link, array $params ) {
		try {
			$data = WPMIG_Sync::call( $link, array_merge( array( 'op' => 'options' ), $params ) );
		} catch ( WPMIG_Exception $e ) {
			if ( false !== strpos( $e->getMessage(), 'Opération inconnue' ) ) {
				throw new WPMIG_Exception( 'Le site d\'origine ne sait pas fournir ses réglages : il doit avoir WP Migration 1.9.0 ou plus récent.' );
			}
			throw $e;
		}
		return $data;
	}

	/**
	 * Options of the source (list), with their local state.
	 *
	 * @param string $link Synchronization link.
	 * @param string $q    Part of the name.
	 * @return array
	 */
	public static function remote_list( $link, $q ) {
		$link = WPMIG_Sync::check_link( $link );
		$data = self::fetch( $link, array( 'q' => (string) $q ) );
		if ( ! isset( $data['options'] ) || ! is_array( $data['options'] ) ) {
			throw new WPMIG_Exception( 'Le site d\'origine ne sait pas fournir ses réglages : il doit avoir WP Migration 1.9.0 ou plus récent.' );
		}
		$list = array();
		foreach ( $data['options'] as $o ) {
			if ( ! isset( $o['name'] ) || '' !== self::protected_reason( $o['name'] ) ) {
				continue;
			}
			$o['local'] = self::local( $o['name'] ) ? 1 : 0;
			$list[]     = $o;
		}
		return array(
			'total'   => isset( $data['total'] ) ? (int) $data['total'] : count( $list ),
			'options' => $list,
		);
	}

	/**
	 * Replacer turning the addresses of the source into the ones of this site.
	 *
	 * @param array $info Information of the source.
	 * @return WPMIG_Replacer|null
	 */
	public static function adapt_replacer( array $info ) {
		$pairs = array();
		if ( ! empty( $info['home'] ) && untrailingslashit( $info['home'] ) !== untrailingslashit( home_url() ) ) {
			$pairs += WPMIG_Replacer::build_url_pairs( $info['home'], untrailingslashit( home_url() ) );
		}
		if ( ! empty( $info['siteurl'] ) && untrailingslashit( $info['siteurl'] ) !== untrailingslashit( site_url() ) && $info['siteurl'] !== $info['home'] ) {
			$pairs += WPMIG_Replacer::build_url_pairs( $info['siteurl'], untrailingslashit( site_url() ) );
		}
		if ( ! empty( $info['abspath'] ) && WPMIG_Plugin::normalize( ABSPATH ) !== $info['abspath'] ) {
			$pairs += WPMIG_Replacer::build_path_pairs( $info['abspath'], WPMIG_Plugin::normalize( ABSPATH ) );
		}
		return $pairs ? new WPMIG_Replacer( $pairs ) : null;
	}

	/**
	 * Compare the options of the source with the local ones.
	 *
	 * @param string $link  Link.
	 * @param array  $names Names.
	 * @param bool   $adapt Replace the addresses of the source.
	 * @return array Items.
	 * @throws WPMIG_Exception On error.
	 */
	public static function plan( $link, array $names, $adapt ) {
		$link  = WPMIG_Sync::check_link( $link );
		$names = array_slice( array_values( array_unique( array_map( 'strval', $names ) ) ), 0, self::MAX_NAMES );
		if ( ! $names ) {
			throw new WPMIG_Exception( 'Choisissez au moins un réglage.' );
		}
		$info     = WPMIG_Sync::call( $link, array( 'op' => 'info' ) );
		$data     = self::fetch( $link, array( 'names' => implode( ',', $names ) ) );
		$replacer = $adapt ? self::adapt_replacer( $info ) : null;
		$remote   = array();
		foreach ( isset( $data['values'] ) && is_array( $data['values'] ) ? $data['values'] : array() as $v ) {
			if ( isset( $v['name'] ) ) {
				$remote[ $v['name'] ] = $v;
			}
		}
		$items = array();
		foreach ( $names as $name ) {
			$item = array(
				'name'     => $name,
				'status'   => 'missing',
				'note'     => '',
				'changes'  => array(),
				'new'      => null,
				'autoload' => 'yes',
				'old_size' => 0,
				'new_size' => 0,
				'secret'   => self::secret_name( $name ),
				'rewritten' => 0,
			);
			$reason = self::protected_reason( $name );
			if ( '' !== $reason ) {
				$item['status'] = 'protected';
				$item['note']   = 'Jamais copié : ' . $reason . '.';
			} elseif ( ! isset( $remote[ $name ] ) ) {
				$item['note'] = 'Absent du site d\'origine.';
			} elseif ( isset( $remote[ $name ]['too_big'] ) ) {
				$item['status'] = 'too_big';
				$item['note']   = sprintf( 'Trop volumineux (%s) pour être copié ainsi.', size_format( (int) $remote[ $name ]['too_big'] ) );
			} else {
				$new   = (string) $remote[ $name ]['value'];
				$moved = 0;
				if ( $replacer ) {
					$replacer->count = 0;
					$after           = $replacer->replace( $new );
					$moved           = $after !== $new ? 1 : 0;
					$new             = $after;
				}
				$local               = self::local( $name );
				$item['new']         = $new;
				$item['autoload']    = self::autoload( $remote[ $name ]['autoload'] );
				$item['new_size']    = strlen( $new );
				$item['old_size']    = $local ? strlen( $local['option_value'] ) : 0;
				$item['status']      = ! $local ? 'new' : ( $local['option_value'] === $new ? 'same' : 'different' );
				$item['changes']     = 'different' === $item['status'] ? self::changes( $name, $local['option_value'], $new ) : array();
				$item['note']        = self::references_note( $name );
				$item['rewritten']   = $moved;
			}
			$items[] = $item;
		}
		return array(
			'source' => isset( $info['home'] ) ? (string) $info['home'] : '',
			'items'  => $items,
		);
	}

	/**
	 * Public version of a plan (no values: the browser only needs a summary).
	 *
	 * @param array $plan Plan.
	 * @return array
	 */
	public static function public_plan( array $plan ) {
		foreach ( $plan['items'] as &$item ) {
			unset( $item['new'] );
		}
		unset( $item );
		return $plan;
	}

	/**
	 * Differences between two values, readable and without secrets.
	 *
	 * @param string $name Option name.
	 * @param string $old  Local value.
	 * @param string $new  New value.
	 * @return array Lines.
	 */
	public static function changes( $name, $old, $new ) {
		$a     = WPMIG_Consistency::parse( $old );
		$b     = WPMIG_Consistency::parse( $new );
		$lines = array();
		if ( is_array( $a ) && is_array( $b ) ) {
			$fa = array();
			$fb = array();
			self::flatten( $a, '', $fa, 0 );
			self::flatten( $b, '', $fb, 0 );
			$keys = array_unique( array_merge( array_keys( $fb ), array_keys( $fa ) ) );
			foreach ( $keys as $key ) {
				$key = (string) $key;
				$in_a = array_key_exists( $key, $fa );
				$in_b = array_key_exists( $key, $fb );
				if ( $in_a && $in_b && $fa[ $key ] === $fb[ $key ] ) {
					continue;
				}
				$secret  = self::secret_name( $name ) || self::secret_name( $key );
				$lines[] = $key . ' : ' . ( $in_a ? self::show( $fa[ $key ], $secret ) : '(absent)' ) . ' → ' . ( $in_b ? self::show( $fb[ $key ], $secret ) : '(supprimé)' );
				if ( count( $lines ) >= 30 ) {
					$lines[] = '… et d\'autres différences';
					break;
				}
			}
			return $lines;
		}
		$secret = self::secret_name( $name );
		if ( strlen( $old ) <= 200 && strlen( $new ) <= 200 ) {
			return array( self::show( $old, $secret ) . ' → ' . self::show( $new, $secret ) );
		}
		return array( sprintf( '%s → %s', size_format( strlen( $old ) ), size_format( strlen( $new ) ) ) );
	}

	/**
	 * Flatten an array into path => scalar.
	 *
	 * @param mixed  $value Value.
	 * @param string $path  Path.
	 * @param array  $out   Result (by reference).
	 * @param int    $depth Depth.
	 */
	private static function flatten( $value, $path, array &$out, $depth ) {
		if ( is_array( $value ) && $depth < 8 ) {
			if ( ! $value ) {
				$out[ $path ] = '[]';
			}
			foreach ( $value as $k => $v ) {
				self::flatten( $v, '' === $path ? (string) $k : $path . '.' . $k, $out, $depth + 1 );
			}
			return;
		}
		$out[ $path ] = is_scalar( $value ) || null === $value ? $value : '[…]';
	}

	/**
	 * Display a scalar.
	 *
	 * @param mixed $v      Value.
	 * @param bool  $secret Hide it.
	 * @return string
	 */
	private static function show( $v, $secret ) {
		if ( null === $v ) {
			return 'null';
		}
		if ( is_bool( $v ) ) {
			return $v ? 'true' : 'false';
		}
		$v = (string) $v;
		if ( '' === $v ) {
			return '(vide)';
		}
		if ( $secret ) {
			return '••••••';
		}
		return '« ' . ( function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $v, 0, 70, '…' ) : substr( $v, 0, 70 ) ) . ' »';
	}

	/* ------------------------------------------------------------------ */
	/* History                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * History of the operations (newest first).
	 *
	 * @return array
	 */
	public static function history() {
		$h = get_option( self::HISTORY );
		return is_array( $h ) ? $h : array();
	}

	/**
	 * Journal file of an operation.
	 *
	 * @param string $id Id.
	 * @return string
	 */
	private static function journal_file( $id ) {
		return WPMIG_Plugin::storage_dir() . 'settings-' . preg_replace( '/[^a-f0-9_]/', '', $id ) . '.php';
	}

	/**
	 * Write an option as is, then forget the caches.
	 *
	 * @param string $name     Name.
	 * @param string $value    Raw value.
	 * @param string $autoload Autoload.
	 */
	private static function write( $name, $value, $autoload ) {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)", // phpcs:ignore WordPress.DB.PreparedSQL
				$name,
				$value,
				$autoload
			)
		);
		self::forget( $name );
	}

	/**
	 * Delete an option.
	 *
	 * @param string $name Name.
	 */
	private static function remove( $name ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		self::forget( $name );
	}

	/**
	 * Clear the object cache entries of an option.
	 *
	 * @param string $name Name.
	 */
	private static function forget( $name ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Copy the chosen options.
	 *
	 * @param string $link  Link.
	 * @param array  $names Names.
	 * @param bool   $adapt Replace the addresses of the source.
	 * @return array Summary.
	 * @throws WPMIG_Exception On error.
	 */
	public static function apply( $link, array $names, $adapt ) {
		$plan    = self::plan( $link, $names, $adapt );
		$entries = array();
		$todo    = array();
		foreach ( $plan['items'] as $item ) {
			if ( ! in_array( $item['status'], array( 'new', 'different' ), true ) ) {
				continue;
			}
			$local     = self::local( $item['name'] );
			$entries[] = array(
				'name'     => $item['name'],
				'existed'  => $local ? 1 : 0,
				'old'      => $local ? base64_encode( $local['option_value'] ) : '', // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				'autoload' => $local ? $local['autoload'] : 'yes',
				'hash'     => md5( $item['new'] ),
			);
			$todo[]    = $item;
		}
		if ( ! $todo ) {
			return array( 'id' => '', 'changed' => 0, 'new' => 0, 'same' => count( $plan['items'] ), 'source' => $plan['source'] );
		}
		$id = gmdate( 'Ymd_His' ) . '_' . WPMIG_Package::random_hex( 6 );
		// The journal comes first: an interrupted copy can still be undone.
		$written = @file_put_contents( self::journal_file( $id ), "<?php exit; ?>\n" . wp_json_encode( $entries ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $written ) {
			throw new WPMIG_Exception( 'Impossible d\'écrire le journal d\'annulation dans le dossier de stockage : rien n\'a été modifié.' );
		}
		$new = 0;
		foreach ( $todo as $i => $item ) {
			self::write( $item['name'], $item['new'], $item['autoload'] );
			if ( ! $entries[ $i ]['existed'] ) {
				$new++;
			}
		}
		do_action( 'wpmig_settings_applied', wp_list_pluck( $todo, 'name' ) );
		$history = self::history();
		array_unshift(
			$history,
			array(
				'id'       => $id,
				'finished' => time(),
				'source'   => $plan['source'],
				'count'    => count( $todo ),
				'new'      => $new,
				'names'    => array_slice( wp_list_pluck( $todo, 'name' ), 0, 12 ),
				'status'   => 'done',
			)
		);
		foreach ( array_slice( $history, self::KEEP ) as $h ) {
			@unlink( self::journal_file( $h['id'] ) ); // phpcs:ignore
		}
		update_option( self::HISTORY, array_slice( $history, 0, 10 ), false );
		return array(
			'id'      => $id,
			'changed' => count( $todo ),
			'new'     => $new,
			'same'    => count( $plan['items'] ) - count( $todo ),
			'source'  => $plan['source'],
		);
	}

	/**
	 * Undo an operation.
	 *
	 * @param string $id Id (the last one when empty).
	 * @return array restored, kept.
	 * @throws WPMIG_Exception When it cannot be undone.
	 */
	public static function undo( $id = '' ) {
		$history = self::history();
		$index   = null;
		foreach ( $history as $i => $h ) {
			if ( 'done' === $h['status'] && ( '' === $id || $h['id'] === $id ) ) {
				$index = $i;
				break;
			}
		}
		if ( null === $index || ! is_file( self::journal_file( $history[ $index ]['id'] ) ) ) {
			throw new WPMIG_Exception( 'Cette opération ne peut plus être annulée (journal absent ou déjà annulée).' );
		}
		$raw     = (string) file_get_contents( self::journal_file( $history[ $index ]['id'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$entries = json_decode( substr( $raw, (int) strpos( $raw, "\n" ) + 1 ), true );
		if ( ! is_array( $entries ) ) {
			throw new WPMIG_Exception( 'Journal d\'annulation illisible.' );
		}
		$restored = 0;
		$kept     = array();
		foreach ( $entries as $e ) {
			$local = self::local( $e['name'] );
			// Edited since the copy: left alone.
			if ( ! $local || md5( $local['option_value'] ) !== $e['hash'] ) {
				$kept[] = $e['name'];
				continue;
			}
			if ( $e['existed'] ) {
				self::write( $e['name'], (string) base64_decode( $e['old'] ), self::autoload( $e['autoload'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			} else {
				self::remove( $e['name'] );
			}
			$restored++;
		}
		$history[ $index ]['status']   = 'undone';
		$history[ $index ]['restored'] = $restored;
		$history[ $index ]['kept']     = count( $kept );
		update_option( self::HISTORY, $history, false );
		@unlink( self::journal_file( $history[ $index ]['id'] ) ); // phpcs:ignore
		return array(
			'restored' => $restored,
			'kept'     => $kept,
		);
	}
}
