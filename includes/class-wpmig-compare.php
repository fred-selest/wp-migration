<?php
/**
 * Read-only comparison of two sites: WordPress, PHP and database versions, theme,
 * plugins (version, active or not), key settings, languages, menus and content
 * counts. The other site is read through the synchronization link; nothing is
 * written on either side.
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site comparison.
 */
class WPMIG_Compare {

	/**
	 * Options shown in the comparison: option => label.
	 *
	 * @return array
	 */
	public static function option_labels() {
		return array(
			'permalink_structure'        => 'Structure des permaliens',
			'blog_public'                => 'Visibilité pour les moteurs de recherche',
			'show_on_front'              => 'Page d\'accueil : affichage',
			'page_on_front'              => 'Page d\'accueil : numéro',
			'page_for_posts'             => 'Page des articles : numéro',
			'posts_per_page'             => 'Articles par page',
			'default_role'               => 'Rôle par défaut',
			'users_can_register'         => 'Inscriptions ouvertes',
			'WPLANG'                     => 'Langue du site',
			'timezone_string'            => 'Fuseau horaire',
			'date_format'                => 'Format de date',
			'time_format'                => 'Format de l\'heure',
			'default_comment_status'     => 'Commentaires ouverts par défaut',
			'woocommerce_currency'       => 'WooCommerce : devise',
			'woocommerce_calc_taxes'     => 'WooCommerce : calcul des taxes',
			'woocommerce_prices_include_tax' => 'WooCommerce : prix TTC',
			'woocommerce_default_country' => 'WooCommerce : pays de la boutique',
			'woocommerce_manage_stock'   => 'WooCommerce : gestion du stock',
			'woocommerce_enable_guest_checkout' => 'WooCommerce : commande sans compte',
			'woocommerce_shop_page_id'   => 'WooCommerce : page boutique (numéro)',
			'woocommerce_cart_page_id'   => 'WooCommerce : page panier (numéro)',
			'woocommerce_checkout_page_id' => 'WooCommerce : page commande (numéro)',
		);
	}

	/**
	 * Profile of this site.
	 *
	 * @return array
	 */
	public static function profile() {
		global $wpdb, $wp_version;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$theme = wp_get_theme();
		$site  = array(
			'Adresse'             => untrailingslashit( get_option( 'home' ) ),
			'WordPress'           => $wp_version,
			'PHP'                 => PHP_VERSION,
			'Base de données'     => $wpdb->db_version() . ( property_exists( $wpdb, 'is_mariadb' ) && $wpdb->is_mariadb ? ' (MariaDB)' : '' ),
			'Langue (locale)'     => get_locale(),
			'Jeu de caractères'   => $wpdb->charset,
			'Préfixe des tables'  => $wpdb->prefix,
		);
		$active  = (array) get_option( 'active_plugins', array() );
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[ $file ] = array(
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => in_array( $file, $active, true ) ? 1 : 0,
			);
		}
		$mu = array();
		if ( function_exists( 'get_mu_plugins' ) ) {
			foreach ( get_mu_plugins() as $file => $data ) {
				$mu[ $file ] = array( 'name' => $data['Name'], 'version' => $data['Version'], 'active' => 1 );
			}
		}
		$settings = array();
		foreach ( self::option_labels() as $option => $label ) {
			$value = get_option( $option, null );
			if ( null === $value || is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$settings[ $option ] = array( 'label' => $label, 'value' => (string) $value );
		}
		// Payment gateways switched on.
		$gateways = array();
		foreach ( (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce\\_%\\_settings'" ) as $name ) { // phpcs:ignore WordPress.DB
			$value = WPMIG_Consistency::parse( (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ) ); // phpcs:ignore WordPress.DB
			if ( is_array( $value ) && isset( $value['enabled'] ) && 'yes' === $value['enabled'] && ! preg_match( '/^woocommerce_(email|[a-z_]*_email|shipping|tax)/', $name ) ) {
				$gateways[] = $name;
			}
		}
		sort( $gateways );
		$languages = self::languages();
		$locations = get_nav_menu_locations();
		$menus     = array(
			'count'     => count( (array) wp_get_nav_menus() ),
			'locations' => count( array_filter( (array) $locations ) ),
		);
		return array(
			'site'      => $site,
			'theme'     => array(
				'stylesheet' => $theme->get_stylesheet(),
				'template'   => $theme->get_template(),
				'name'       => (string) $theme->get( 'Name' ),
				'version'    => (string) $theme->get( 'Version' ),
			),
			'plugins'   => $plugins,
			'mu'        => $mu,
			'settings'  => $settings,
			'gateways'  => $gateways,
			'languages' => $languages,
			'menus'     => $menus,
			'counts'    => self::counts(),
		);
	}

	/**
	 * Multilingual plugin and languages.
	 *
	 * @return array
	 */
	private static function languages() {
		global $wpdb;
		$out = array( 'plugin' => '', 'default' => '', 'active' => array() );
		$wpml = WPMIG_Consistency::parse( (string) get_option( 'icl_sitepress_settings', '' ) );
		if ( is_array( $wpml ) ) {
			$out['plugin']  = 'WPML';
			$out['default'] = isset( $wpml['default_language'] ) ? (string) $wpml['default_language'] : '';
			if ( WPMIG_Sync_Source::has_table( 'icl_languages' ) ) {
				$out['active'] = array_map( 'strval', (array) $wpdb->get_col( "SELECT code FROM {$wpdb->prefix}icl_languages WHERE active = 1 ORDER BY code" ) ); // phpcs:ignore WordPress.DB
			}
			return $out;
		}
		$pll = WPMIG_Consistency::parse( (string) get_option( 'polylang', '' ) );
		if ( is_array( $pll ) ) {
			$out['plugin']  = 'Polylang';
			$out['default'] = isset( $pll['default_lang'] ) ? (string) $pll['default_lang'] : '';
			$codes          = $wpdb->get_col( "SELECT t.slug FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'language' ORDER BY t.slug" ); // phpcs:ignore WordPress.DB
			$out['active']  = array_map( 'strval', (array) $codes );
		}
		return $out;
	}

	/**
	 * Content counts.
	 *
	 * @return array label => number.
	 */
	private static function counts() {
		global $wpdb;
		$out   = array();
		$types = array(
			'post'             => 'Articles publiés',
			'page'             => 'Pages publiées',
			'product'          => 'Produits publiés',
			'product_variation' => 'Variations de produits',
			'attachment'       => 'Médias',
			'shop_coupon'      => 'Codes promo',
		);
		foreach ( $types as $type => $label ) {
			$status = 'attachment' === $type || 'product_variation' === $type || 'shop_coupon' === $type ? '' : "AND post_status = 'publish'";
			$n      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s $status", $type ) ); // phpcs:ignore WordPress.DB
			if ( $n || in_array( $type, array( 'post', 'page' ), true ) ) {
				$out[ $label ] = $n;
			}
		}
		if ( WPMIG_Sync_Source::hpos() ) {
			$out['Commandes'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order'" ); // phpcs:ignore WordPress.DB
		} else {
			$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order'" ); // phpcs:ignore WordPress.DB
			if ( $n ) {
				$out['Commandes'] = $n;
			}
		}
		$out['Utilisateurs'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB
		$out['Commentaires'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" ); // phpcs:ignore WordPress.DB
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Comparison                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Profile of the other site, through its synchronization link.
	 *
	 * @param string $link Link.
	 * @return array
	 * @throws WPMIG_Exception On error.
	 */
	public static function remote( $link ) {
		$link = WPMIG_Sync::check_link( $link );
		try {
			$data = WPMIG_Sync::call( $link, array( 'op' => 'profile' ) );
		} catch ( WPMIG_Exception $e ) {
			if ( false !== strpos( $e->getMessage(), 'Opération inconnue' ) ) {
				throw new WPMIG_Exception( 'Le site d\'origine ne sait pas se décrire : il doit avoir WP Migration 1.10.0 ou plus récent.' );
			}
			throw $e;
		}
		if ( empty( $data['profile'] ) || ! is_array( $data['profile'] ) ) {
			throw new WPMIG_Exception( 'Réponse inattendue du site d\'origine.' );
		}
		return $data['profile'];
	}

	/**
	 * Compare this site with another one.
	 *
	 * @param string $link Link of the other site.
	 * @return array
	 */
	public static function run( $link ) {
		$there = self::remote( $link );
		return self::diff( self::profile(), $there );
	}

	/**
	 * Compare two profiles ("here" is this site, "there" the other one).
	 *
	 * @param array $here  Profile of this site.
	 * @param array $there Profile of the other site.
	 * @return array sections [ title, rows [ label, here, there, status, option ] ], summary.
	 */
	public static function diff( array $here, array $there ) {
		$sections = array();

		$rows = array();
		foreach ( self::keys( $here, $there, 'site' ) as $key ) {
			$a = isset( $here['site'][ $key ] ) ? (string) $here['site'][ $key ] : '';
			$b = isset( $there['site'][ $key ] ) ? (string) $there['site'][ $key ] : '';
			// Address and table prefix may differ between a site and its copy: informative only.
			$info   = in_array( $key, array( 'Adresse', 'Préfixe des tables' ), true );
			$rows[] = self::row( $key, $a, $b, $info );
		}
		$sections['site'] = array( 'title' => 'Environnement', 'rows' => $rows );

		$ta = isset( $here['theme'] ) ? $here['theme'] : array();
		$tb = isset( $there['theme'] ) ? $there['theme'] : array();
		$rows = array(
			self::row( 'Thème actif', self::theme_text( $ta ), self::theme_text( $tb ) ),
		);
		$sections['theme'] = array( 'title' => 'Thème', 'rows' => $rows );

		$rows = array();
		foreach ( array( 'plugins' => '', 'mu' => ' (mu-plugin)' ) as $group => $suffix ) {
			$pa = isset( $here[ $group ] ) ? $here[ $group ] : array();
			$pb = isset( $there[ $group ] ) ? $there[ $group ] : array();
			$files = array_unique( array_merge( array_keys( $pa ), array_keys( $pb ) ) );
			sort( $files );
			foreach ( $files as $file ) {
				$rows[] = self::row(
					( isset( $pa[ $file ] ) ? $pa[ $file ]['name'] : $pb[ $file ]['name'] ) . $suffix,
					isset( $pa[ $file ] ) ? self::plugin_text( $pa[ $file ] ) : '',
					isset( $pb[ $file ] ) ? self::plugin_text( $pb[ $file ] ) : ''
				);
			}
		}
		$sections['plugins'] = array( 'title' => 'Extensions', 'rows' => $rows );

		$rows = array();
		$sa   = isset( $here['settings'] ) ? $here['settings'] : array();
		$sb   = isset( $there['settings'] ) ? $there['settings'] : array();
		foreach ( array_unique( array_merge( array_keys( $sa ), array_keys( $sb ) ) ) as $option ) {
			$row           = self::row(
				isset( $sa[ $option ] ) ? $sa[ $option ]['label'] : $sb[ $option ]['label'],
				isset( $sa[ $option ] ) ? $sa[ $option ]['value'] : '',
				isset( $sb[ $option ] ) ? $sb[ $option ]['value'] : ''
			);
			$row['option'] = $option;
			$rows[]        = $row;
		}
		$ga   = isset( $here['gateways'] ) ? $here['gateways'] : array();
		$gb   = isset( $there['gateways'] ) ? $there['gateways'] : array();
		foreach ( array_unique( array_merge( $ga, $gb ) ) as $name ) {
			$row           = self::row( 'Moyen de paiement actif', in_array( $name, $ga, true ) ? $name : '', in_array( $name, $gb, true ) ? $name : '' );
			$row['option'] = $name;
			$rows[]        = $row;
		}
		$sections['settings'] = array( 'title' => 'Réglages', 'rows' => $rows );

		$la   = isset( $here['languages'] ) ? $here['languages'] : array();
		$lb   = isset( $there['languages'] ) ? $there['languages'] : array();
		$rows = array(
			self::row( 'Extension de langues', isset( $la['plugin'] ) ? $la['plugin'] : '', isset( $lb['plugin'] ) ? $lb['plugin'] : '' ),
			self::row( 'Langue par défaut', isset( $la['default'] ) ? $la['default'] : '', isset( $lb['default'] ) ? $lb['default'] : '' ),
			self::row( 'Langues actives', isset( $la['active'] ) ? implode( ', ', $la['active'] ) : '', isset( $lb['active'] ) ? implode( ', ', $lb['active'] ) : '' ),
		);
		$ma   = isset( $here['menus'] ) ? $here['menus'] : array( 'count' => 0, 'locations' => 0 );
		$mb   = isset( $there['menus'] ) ? $there['menus'] : array( 'count' => 0, 'locations' => 0 );
		$rows[] = self::row( 'Menus', (string) $ma['count'], (string) $mb['count'] );
		$rows[] = self::row( 'Emplacements de menu utilisés', (string) $ma['locations'], (string) $mb['locations'] );
		$sections['languages'] = array( 'title' => 'Langues et menus', 'rows' => $rows );

		$rows = array();
		$ca   = isset( $here['counts'] ) ? $here['counts'] : array();
		$cb   = isset( $there['counts'] ) ? $there['counts'] : array();
		foreach ( array_unique( array_merge( array_keys( $ca ), array_keys( $cb ) ) ) as $label ) {
			// Counts of a copy in progress are expected to differ.
			$rows[] = self::row( $label, isset( $ca[ $label ] ) ? (string) $ca[ $label ] : '0', isset( $cb[ $label ] ) ? (string) $cb[ $label ] : '0', true );
		}
		$sections['counts'] = array( 'title' => 'Contenus', 'rows' => $rows );

		$summary = array( 'same' => 0, 'diff' => 0, 'only_here' => 0, 'only_there' => 0, 'info' => 0 );
		foreach ( $sections as $section ) {
			foreach ( $section['rows'] as $row ) {
				$summary[ $row['status'] ]++;
			}
		}
		return array(
			'source'   => isset( $there['site']['Adresse'] ) ? $there['site']['Adresse'] : '',
			'sections' => array_values( $sections ),
			'summary'  => $summary,
		);
	}

	/**
	 * Keys of two lists, in order.
	 *
	 * @param array  $a   First profile.
	 * @param array  $b   Second profile.
	 * @param string $key Section.
	 * @return array
	 */
	private static function keys( array $a, array $b, $key ) {
		return array_values( array_unique( array_merge( isset( $a[ $key ] ) ? array_keys( $a[ $key ] ) : array(), isset( $b[ $key ] ) ? array_keys( $b[ $key ] ) : array() ) ) );
	}

	/**
	 * One row.
	 *
	 * @param string $label Label.
	 * @param string $here  Value here.
	 * @param string $there Value there.
	 * @param bool   $info  A difference is only informative.
	 * @return array
	 */
	private static function row( $label, $here, $there, $info = false ) {
		if ( $here === $there ) {
			$status = 'same';
		} elseif ( $info ) {
			$status = 'info';
		} elseif ( '' === $here ) {
			$status = 'only_there';
		} elseif ( '' === $there ) {
			$status = 'only_here';
		} else {
			$status = 'diff';
		}
		return array( 'label' => $label, 'here' => $here, 'there' => $there, 'status' => $status );
	}

	/**
	 * Theme summary.
	 *
	 * @param array $t Theme.
	 * @return string
	 */
	private static function theme_text( array $t ) {
		if ( empty( $t['stylesheet'] ) ) {
			return '';
		}
		$text = ( ! empty( $t['name'] ) ? $t['name'] : $t['stylesheet'] ) . ' ' . ( isset( $t['version'] ) ? $t['version'] : '' );
		if ( ! empty( $t['template'] ) && $t['template'] !== $t['stylesheet'] ) {
			$text .= ' (enfant de ' . $t['template'] . ')';
		}
		return trim( $text );
	}

	/**
	 * Plugin summary.
	 *
	 * @param array $p Plugin.
	 * @return string
	 */
	private static function plugin_text( array $p ) {
		return trim( $p['version'] . ' — ' . ( ! empty( $p['active'] ) ? 'actif' : 'inactif' ) );
	}
}
