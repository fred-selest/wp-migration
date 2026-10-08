<?php
/**
 * Content synchronization, source side: a secret, temporary and revocable link
 * lets another site (typically a development copy of this one) read the content
 * created or modified here since a date: orders, customers, products, coupons,
 * posts and pages, media, comments. This side only reads.
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronization source.
 */
class WPMIG_Sync_Source {

	const ACTION = 'wpmig_sync';
	const OPTION = 'wpmig_sync_link';
	const PAGE   = 500;
	const BATCH  = 50;

	/**
	 * Hooks. The endpoint works without being logged in: the requests come from
	 * the other server.
	 */
	public static function init() {
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'serve' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'serve' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Link                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Create (or replace) the synchronization link.
	 *
	 * @param int $hours Lifetime.
	 * @return array url, expires, expires_h.
	 */
	public static function create( $hours = 24 ) {
		$hours = max( 1, min( 24 * 30, (int) $hours ) );
		$token = WPMIG_Package::random_hex( 32 );
		update_option(
			self::OPTION,
			array(
				'hash'    => hash( 'sha256', $token ),
				'created' => time(),
				'expires' => time() + $hours * HOUR_IN_SECONDS,
				'log'     => array(),
			),
			false
		);
		$link = self::link();
		return array(
			'url'       => add_query_arg( 'key', $token, self::endpoint() ),
			'expires'   => $link['expires'],
			'expires_h' => wpmig_date( $link['expires'] ),
			'insecure'  => 0 !== strpos( self::endpoint(), 'https://' ),
		);
	}

	/**
	 * Endpoint URL (without key).
	 *
	 * @return string
	 */
	public static function endpoint() {
		return add_query_arg( 'action', self::ACTION, admin_url( 'admin-ajax.php' ) );
	}

	/**
	 * Active link (null when none).
	 *
	 * @return array|null
	 */
	public static function link() {
		$link = get_option( self::OPTION );
		return ( is_array( $link ) && ! empty( $link['expires'] ) && $link['expires'] > time() ) ? $link : null;
	}

	/**
	 * Revoke the link.
	 */
	public static function revoke() {
		delete_option( self::OPTION );
	}

	/**
	 * Check a key.
	 *
	 * @param string $token Key.
	 * @return bool
	 */
	private static function check( $token ) {
		$link = self::link();
		if ( ! $link || ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return false;
		}
		return hash_equals( $link['hash'], hash( 'sha256', $token ) );
	}

	/* ------------------------------------------------------------------ */
	/* Endpoint                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Serve a request.
	 */
	public static function serve() {
		// phpcs:disable WordPress.Security.NonceVerification -- authenticated by the secret key.
		$req = array_merge( $_GET, $_POST );
		// phpcs:enable
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( ! self::check( isset( $req['key'] ) ? (string) $req['key'] : '' ) ) {
			// Same answer for every failure: nothing to learn from it.
			self::json( array( 'error' => 'Lien de synchronisation invalide, expiré ou révoqué.' ), 403 );
		}
		$op = isset( $req['op'] ) ? (string) $req['op'] : 'info';
		try {
			WPMIG_Plugin::raise_limits();
			switch ( $op ) {
				case 'info':
					self::log_access();
					self::json( self::info() );
					break;
				case 'scan':
					self::json(
						self::scan(
							isset( $req['kind'] ) ? (string) $req['kind'] : '',
							isset( $req['since'] ) ? (string) $req['since'] : '',
							isset( $req['after'] ) ? (int) $req['after'] : 0
						)
					);
					break;
				case 'fetch':
					$ids = isset( $req['ids'] ) ? array_slice( array_filter( array_map( 'intval', explode( ',', (string) $req['ids'] ) ) ), 0, self::BATCH ) : array();
					self::json( array( 'objects' => self::fetch( isset( $req['kind'] ) ? (string) $req['kind'] : '', $ids ) ) );
					break;
				case 'profile':
					self::json( array( 'profile' => WPMIG_Compare::profile() ) );
					break;
				case 'options':
					if ( isset( $req['names'] ) ) {
						self::json( array( 'values' => WPMIG_Settings::source_get( array_filter( explode( ',', (string) $req['names'] ), 'strlen' ) ) ) );
					}
					self::json( WPMIG_Settings::source_list( isset( $req['q'] ) ? (string) $req['q'] : '' ) );
					break;
				case 'file':
					self::file( isset( $req['path'] ) ? (string) $req['path'] : '' );
					break;
				default:
					self::json( array( 'error' => 'Opération inconnue.' ), 400 );
			}
		} catch ( Exception $e ) {
			self::json( array( 'error' => $e->getMessage() ), 500 );
		}
	}

	/**
	 * Send JSON and stop.
	 *
	 * @param array $data   Data.
	 * @param int   $status HTTP status.
	 */
	private static function json( $data, $status = 200 ) {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * Remember the last accesses (shown on the source site).
	 */
	private static function log_access() {
		$link = self::link();
		if ( ! $link ) {
			return;
		}
		$ip            = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$link['log'][] = array( time(), $ip );
		$link['log']   = array_slice( $link['log'], -10 );
		update_option( self::OPTION, $link, false );
	}

	/* ------------------------------------------------------------------ */
	/* Data                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Table name.
	 *
	 * @param string $suffix Table without prefix.
	 * @return string
	 */
	private static function t( $suffix ) {
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
	 * Are orders stored in the WooCommerce tables (HPOS)?
	 *
	 * @return bool
	 */
	public static function hpos() {
		return 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' ) && self::has_table( 'wc_orders' );
	}

	/**
	 * Site information.
	 *
	 * @return array
	 */
	public static function info() {
		global $wpdb, $wp_version;
		$uploads = wp_upload_dir( null, false );
		return array(
			'plugin'       => WPMIG_VERSION,
			'home'         => untrailingslashit( get_option( 'home' ) ),
			'siteurl'      => untrailingslashit( get_option( 'siteurl' ) ),
			'abspath'      => WPMIG_Plugin::normalize( ABSPATH ),
			'uploads_url'  => untrailingslashit( $uploads['baseurl'] ),
			'uploads_dir'  => WPMIG_Plugin::normalize( $uploads['basedir'] ),
			'prefix'       => $wpdb->prefix,
			'wp'           => $wp_version,
			'woocommerce'  => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'hpos'         => self::hpos(),
			'wpml'         => self::has_table( 'icl_translations' ),
			'time'         => gmdate( 'Y-m-d H:i:s' ),
			'packages'     => self::packages(),
			'max'          => array(
				'posts'       => (int) $wpdb->get_var( 'SELECT MAX(ID) FROM ' . $wpdb->posts ),
				'users'       => (int) $wpdb->get_var( 'SELECT MAX(ID) FROM ' . $wpdb->users ),
				'comments'    => (int) $wpdb->get_var( 'SELECT MAX(comment_ID) FROM ' . $wpdb->comments ),
				'order_items' => self::has_table( 'woocommerce_order_items' ) ? (int) $wpdb->get_var( 'SELECT MAX(order_item_id) FROM ' . self::t( 'woocommerce_order_items' ) ) : 0,
			),
		);
	}

	/**
	 * Packages of this site: the copy was made from one of them, its date is the
	 * reference of the synchronization.
	 *
	 * @return array List of array( id, name, start ): start = beginning of the database export (GMT).
	 */
	private static function packages() {
		$out = array();
		foreach ( WPMIG_Package::all() as $package ) {
			$d = $package->data;
			if ( 'complete' !== $d['status'] ) {
				continue;
			}
			$out[] = array(
				'id'    => $d['id'],
				'name'  => $d['name'],
				// Older packages: their creation (before the export), a safe lower bound.
				'start' => ! empty( $d['dump']['started'] ) ? $d['dump']['started'] : gmdate( 'Y-m-d H:i:s', (int) $d['created'] ),
			);
			if ( count( $out ) >= 10 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Orders created or modified since a date (SQL sub-query returning ids).
	 *
	 * @param string $since GMT date.
	 * @return string
	 */
	private static function orders_since_sql( $since ) {
		global $wpdb;
		if ( self::hpos() ) {
			return $wpdb->prepare( 'SELECT id FROM ' . self::t( 'wc_orders' ) . " WHERE type IN ('shop_order', 'shop_order_refund') AND (date_created_gmt >= %s OR date_updated_gmt >= %s)", $since, $since );
		}
		return $wpdb->prepare( 'SELECT ID FROM ' . $wpdb->posts . " WHERE post_type IN ('shop_order', 'shop_order_refund') AND (post_date_gmt >= %s OR post_modified_gmt >= %s)", $since, $since );
	}

	/**
	 * Objects created or modified since a date.
	 *
	 * @param string $kind  Kind.
	 * @param string $since GMT date (Y-m-d H:i:s).
	 * @param int    $after Last id of the previous page.
	 * @return array items (id, fingerprint, created, modified), next (0 when finished).
	 * @throws WPMIG_Exception On invalid parameters.
	 */
	public static function scan( $kind, $since, $after ) {
		global $wpdb;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since ) ) {
			throw new WPMIG_Exception( 'Date invalide.' );
		}
		$limit = self::PAGE;
		$wc    = self::has_table( 'woocommerce_order_items' );
		$p     = $wpdb->posts;
		$sql   = '';
		$post_cols = 'ID, post_type, post_date_gmt, post_modified_gmt';
		$changed   = $wpdb->prepare( '(post_date_gmt >= %s OR post_modified_gmt >= %s)', $since, $since );

		switch ( $kind ) {
			case 'media':
				$sql = "SELECT $post_cols FROM $p WHERE post_type = 'attachment' AND $changed";
				break;
			case 'posts':
				$sql = "SELECT $post_cols FROM $p WHERE post_type IN ('post', 'page') AND post_status <> 'auto-draft' AND $changed";
				break;
			case 'products':
				// Stock changes made by orders do not update the product dates: products sold since the date are included.
				$extra = $wpdb->prepare( "OR ID IN (SELECT post_parent FROM $p WHERE post_type = 'product_variation' AND post_modified_gmt >= %s)", $since );
				if ( $wc ) {
					$extra .= ' OR ID IN (SELECT CAST(im.meta_value AS UNSIGNED) FROM ' . self::t( 'woocommerce_order_itemmeta' ) . ' im JOIN ' . self::t( 'woocommerce_order_items' ) . " i ON i.order_item_id = im.order_item_id WHERE im.meta_key = '_product_id' AND i.order_id IN (" . self::orders_since_sql( $since ) . '))';
				}
				$sql = "SELECT $post_cols FROM $p WHERE post_type = 'product' AND post_status <> 'auto-draft' AND ($changed $extra)";
				break;
			case 'coupons':
				$extra = $wc ? ' OR LOWER(post_title) IN (SELECT LOWER(order_item_name) FROM ' . self::t( 'woocommerce_order_items' ) . " WHERE order_item_type = 'coupon' AND order_id IN (" . self::orders_since_sql( $since ) . '))' : '';
				$sql   = "SELECT $post_cols FROM $p WHERE post_type = 'shop_coupon' AND post_status <> 'auto-draft' AND ($changed $extra)";
				break;
			case 'orders':
				if ( ! $wc ) {
					return array( 'items' => array(), 'next' => 0 );
				}
				if ( self::hpos() ) {
					$sql = 'SELECT id AS ID, type AS post_type, date_created_gmt AS post_date_gmt, date_updated_gmt AS post_modified_gmt FROM ' . self::t( 'wc_orders' ) . ' WHERE id IN (' . self::orders_since_sql( $since ) . ')';
				} else {
					$sql = "SELECT $post_cols FROM $p WHERE ID IN (" . self::orders_since_sql( $since ) . ')';
				}
				break;
			case 'customers':
				$caps   = $wpdb->prefix . 'capabilities';
				$admins = $wpdb->prepare( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = %s AND meta_value LIKE %s", $caps, '%"administrator"%' );
				$extra  = $wpdb->prepare( " OR ID IN (SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'last_update' AND CAST(meta_value AS UNSIGNED) >= %d)", strtotime( $since . ' UTC' ) );
				if ( $wc ) {
					$extra .= self::hpos()
						? ' OR ID IN (SELECT customer_id FROM ' . self::t( 'wc_orders' ) . ' WHERE id IN (' . self::orders_since_sql( $since ) . '))'
						: " OR ID IN (SELECT CAST(meta_value AS UNSIGNED) FROM $wpdb->postmeta WHERE meta_key = '_customer_user' AND post_id IN (" . self::orders_since_sql( $since ) . '))';
				}
				// "Modified": registration and last update of the customer data by WooCommerce.
				$sql = "SELECT u.ID, 'user' AS post_type, u.user_registered AS post_date_gmt, CONCAT(u.user_registered, '|', COALESCE((SELECT meta_value FROM $wpdb->usermeta lu WHERE lu.user_id = u.ID AND lu.meta_key = 'last_update' LIMIT 1), '')) AS post_modified_gmt, u.user_login FROM $wpdb->users u WHERE (" . $wpdb->prepare( 'u.user_registered >= %s', $since ) . " $extra) AND u.ID NOT IN ($admins)";
				break;
			case 'comments':
				$sql = $wpdb->prepare( "SELECT comment_ID AS ID, comment_type AS post_type, comment_date_gmt AS post_date_gmt, comment_date_gmt AS post_modified_gmt, comment_author_email FROM $wpdb->comments WHERE comment_type NOT IN ('order_note', 'webhook_delivery', 'action_log') AND comment_date_gmt >= %s", $since );
				break;
			default:
				throw new WPMIG_Exception( 'Type de contenu inconnu.' );
		}

		$rows  = $wpdb->get_results( 'SELECT * FROM (' . $sql . ') x WHERE ID > ' . (int) $after . ' ORDER BY ID LIMIT ' . $limit, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$items = array();
		foreach ( (array) $rows as $r ) {
			$modified = (string) $r['post_modified_gmt'];
			if ( 'products' === $kind ) {
				$modified .= '|' . self::stock_signature( (int) $r['ID'] );
			}
			$items[] = array( (int) $r['ID'], self::fingerprint( $kind, $r ), (string) $r['post_date_gmt'], $modified );
		}
		return array(
			'items' => $items,
			'next'  => count( $items ) === $limit ? (int) $rows[ $limit - 1 ]['ID'] : 0,
		);
	}

	/**
	 * Signature of the stock and sales of a product and its variations: they change
	 * with the orders without changing the modification date of the product.
	 *
	 * @param int $id Product id.
	 * @return string
	 */
	public static function stock_signature( $id ) {
		global $wpdb;
		$keys = "'_stock', '_stock_status', 'total_sales', '_wc_average_rating', '_wc_rating_count', '_wc_review_count', '_price'";
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT CONCAT(m.post_id, ':', m.meta_key, '=', m.meta_value) FROM $wpdb->postmeta m WHERE m.meta_key IN ($keys) AND (m.post_id = %d OR m.post_id IN (SELECT ID FROM $wpdb->posts WHERE post_parent = %d AND post_type = 'product_variation')) ORDER BY m.post_id, m.meta_key", $id, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return substr( md5( implode( '|', (array) $rows ) ), 0, 12 );
	}

	/**
	 * Identity of an object (compared with the object with the same id on the other site).
	 *
	 * @param string $kind Kind.
	 * @param array  $r    Row (normalized columns).
	 * @return string
	 */
	public static function fingerprint( $kind, $r ) {
		if ( 'customers' === $kind ) {
			return (string) $r['user_login'];
		}
		if ( 'comments' === $kind ) {
			return $r['post_date_gmt'] . '|' . strtolower( (string) $r['comment_author_email'] );
		}
		return $r['post_type'] . '|' . $r['post_date_gmt'];
	}

	/**
	 * Full data of objects.
	 *
	 * @param string $kind Kind.
	 * @param array  $ids  Ids.
	 * @return array
	 * @throws WPMIG_Exception On unknown kind.
	 */
	public static function fetch( $kind, array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			switch ( $kind ) {
				case 'media':
				case 'posts':
				case 'coupons':
				case 'products':
					$obj = self::post_object( $id, 'products' === $kind );
					break;
				case 'orders':
					$obj = self::order_object( $id );
					break;
				case 'customers':
					$obj = self::user_object( $id );
					break;
				case 'comments':
					$obj = self::comment_object( $id );
					break;
				default:
					throw new WPMIG_Exception( 'Type de contenu inconnu.' );
			}
			if ( $obj ) {
				$out[] = $obj;
			}
		}
		return $out;
	}

	/**
	 * Rows of a table, without some columns, with values safe for JSON.
	 *
	 * @param string $sql  Query.
	 * @param array  $drop Columns to remove (auto-increment ids of meta rows).
	 * @return array
	 */
	private static function rows( $sql, array $drop = array() ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $rows as $i => $row ) {
			foreach ( $drop as $col ) {
				unset( $row[ $col ] );
			}
			$rows[ $i ] = self::encode_row( $row );
		}
		return $rows;
	}

	/**
	 * Binary values (invalid UTF-8) are sent in base64.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function encode_row( array $row ) {
		foreach ( $row as $k => $v ) {
			if ( is_string( $v ) && '' !== $v && ! preg_match( '//u', $v ) ) {
				$row[ $k ] = array( 'b64' => base64_encode( $v ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
		}
		return $row;
	}

	/**
	 * Terms of an object, with the definition of each term and of its ancestors.
	 *
	 * @param int $id Object id.
	 * @return array array( relationships => [taxonomy, slug, term_order], terms => [definitions] ).
	 */
	private static function terms_of( $id ) {
		global $wpdb;
		$rels  = $wpdb->get_results( $wpdb->prepare( "SELECT tt.taxonomy, t.slug, tr.term_order, t.term_id FROM $wpdb->term_relationships tr JOIN $wpdb->term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN $wpdb->terms t ON t.term_id = tt.term_id WHERE tr.object_id = %d", $id ), ARRAY_A );
		$defs  = array();
		$queue = array();
		$out   = array();
		foreach ( (array) $rels as $r ) {
			$out[]   = array( $r['taxonomy'], $r['slug'], (int) $r['term_order'] );
			$queue[] = (int) $r['term_id'];
		}
		$seen = array();
		while ( $queue ) {
			$term_id = array_shift( $queue );
			if ( isset( $seen[ $term_id ] ) ) {
				continue;
			}
			$seen[ $term_id ] = true;
			$t = $wpdb->get_row( $wpdb->prepare( "SELECT t.term_id, t.name, t.slug, t.term_group, tt.taxonomy, tt.description, tt.parent FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON tt.term_id = t.term_id WHERE t.term_id = %d", $term_id ), ARRAY_A );
			if ( ! $t ) {
				continue;
			}
			$parent = null;
			if ( (int) $t['parent'] ) {
				$parent  = $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM $wpdb->terms WHERE term_id = %d", $t['parent'] ) );
				$queue[] = (int) $t['parent'];
			}
			$defs[] = array(
				'taxonomy'    => $t['taxonomy'],
				'slug'        => $t['slug'],
				'name'        => $t['name'],
				'term_group'  => (int) $t['term_group'],
				'description' => $t['description'],
				'parent'      => $parent,
				'meta'        => self::rows( $wpdb->prepare( "SELECT meta_key, meta_value FROM $wpdb->termmeta WHERE term_id = %d", $term_id ) ),
			);
		}
		// Parents first.
		return array(
			'relationships' => $out,
			'terms'         => array_reverse( $defs ),
		);
	}

	/**
	 * Post, page, product (with its variations), coupon or attachment.
	 *
	 * @param int  $id            Post id.
	 * @param bool $with_children Include the variations.
	 * @return array|null
	 */
	private static function post_object( $id, $with_children = false ) {
		global $wpdb;
		$post = self::rows( $wpdb->prepare( "SELECT * FROM $wpdb->posts WHERE ID = %d", $id ) );
		if ( ! $post ) {
			return null;
		}
		$terms = self::terms_of( $id );
		$obj   = array(
			'id'       => (int) $id,
			'fp'       => self::fingerprint( 'posts', $post[0] ),
			'modified' => $post[0]['post_modified_gmt'],
			'rows'     => array(
				'posts'    => $post,
				'postmeta' => self::rows( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM $wpdb->postmeta WHERE post_id = %d", $id ) ),
			),
			'relationships' => $terms['relationships'],
			'terms'         => $terms['terms'],
		);
		if ( self::has_table( 'icl_translations' ) ) {
			$obj['rows']['icl_translations'] = self::rows( $wpdb->prepare( 'SELECT element_type, element_id, trid, language_code, source_language_code FROM ' . self::t( 'icl_translations' ) . ' WHERE element_id = %d AND element_type = %s', $id, 'post_' . $post[0]['post_type'] ) );
		}
		if ( 'attachment' === $post[0]['post_type'] ) {
			$obj['files'] = self::attachment_files( $id );
		}
		if ( $with_children ) {
			$obj['modified'] .= '|' . self::stock_signature( $id );
			$obj['children']  = array();
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_parent = %d AND post_type = 'product_variation' ORDER BY ID", $id ) ) as $child ) {
				$c = self::post_object( (int) $child );
				if ( $c ) {
					$obj['children'][] = $c;
				}
			}
			$obj['attributes'] = array();
			if ( self::has_table( 'woocommerce_attribute_taxonomies' ) ) {
				$names = array();
				foreach ( $obj['terms'] as $t ) {
					if ( 0 === strpos( $t['taxonomy'], 'pa_' ) ) {
						$names[ substr( $t['taxonomy'], 3 ) ] = true;
					}
				}
				foreach ( array_keys( $names ) as $name ) {
					$obj['attributes'] = array_merge( $obj['attributes'], self::rows( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'woocommerce_attribute_taxonomies' ) . ' WHERE attribute_name = %s', $name ), array( 'attribute_id' ) ) );
				}
			}
		}
		return $obj;
	}

	/**
	 * Files of an attachment, relative to the uploads folder.
	 *
	 * @param int $id Attachment id.
	 * @return array
	 */
	private static function attachment_files( $id ) {
		$file = get_post_meta( $id, '_wp_attached_file', true );
		if ( ! $file || preg_match( '#^(/|[a-z]+:)#i', $file ) ) {
			return array();
		}
		$files = array( $file );
		$dir   = dirname( $file );
		$dir   = '.' === $dir ? '' : $dir . '/';
		$meta  = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['original_image'] ) ) {
				$files[] = $dir . $meta['original_image'];
			}
			foreach ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array() as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . $size['file'];
				}
			}
		}
		return array_values( array_unique( $files ) );
	}

	/**
	 * Order or refund with its items, notes and download permissions.
	 *
	 * @param int $id Order id.
	 * @return array|null
	 */
	private static function order_object( $id ) {
		global $wpdb;
		$rows = array(
			'posts'    => self::rows( $wpdb->prepare( "SELECT * FROM $wpdb->posts WHERE ID = %d", $id ) ),
			'postmeta' => self::rows( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM $wpdb->postmeta WHERE post_id = %d", $id ) ),
		);
		if ( self::hpos() ) {
			$rows['wc_orders'] = self::rows( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'wc_orders' ) . ' WHERE id = %d', $id ) );
			if ( ! $rows['wc_orders'] ) {
				return null;
			}
			foreach ( array( 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data' ) as $table ) {
				if ( self::has_table( $table ) ) {
					$rows[ $table ] = self::rows( $wpdb->prepare( 'SELECT * FROM ' . self::t( $table ) . ' WHERE order_id = %d', $id ), array( 'id' ) );
				}
			}
			$fp = self::fingerprint(
				'orders',
				array(
					'post_type'     => $rows['wc_orders'][0]['type'],
					'post_date_gmt' => $rows['wc_orders'][0]['date_created_gmt'],
				)
			);
			$modified = $rows['wc_orders'][0]['date_updated_gmt'];
		} else {
			if ( ! $rows['posts'] ) {
				return null;
			}
			$fp       = self::fingerprint( 'orders', $rows['posts'][0] );
			$modified = $rows['posts'][0]['post_modified_gmt'];
		}
		if ( self::has_table( 'woocommerce_downloadable_product_permissions' ) ) {
			$rows['woocommerce_downloadable_product_permissions'] = self::rows( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'woocommerce_downloadable_product_permissions' ) . ' WHERE order_id = %d', $id ), array( 'permission_id' ) );
		}
		$items = array();
		foreach ( self::rows( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'woocommerce_order_items' ) . ' WHERE order_id = %d ORDER BY order_item_id', $id ) ) as $item ) {
			$items[] = array(
				'row'  => $item,
				'meta' => self::rows( $wpdb->prepare( 'SELECT order_item_id, meta_key, meta_value FROM ' . self::t( 'woocommerce_order_itemmeta' ) . ' WHERE order_item_id = %d', $item['order_item_id'] ) ),
			);
		}
		$notes = array();
		foreach ( self::rows( $wpdb->prepare( "SELECT * FROM $wpdb->comments WHERE comment_post_ID = %d ORDER BY comment_ID", $id ) ) as $note ) {
			$notes[] = array(
				'row'  => $note,
				'meta' => self::rows( $wpdb->prepare( "SELECT comment_id, meta_key, meta_value FROM $wpdb->commentmeta WHERE comment_id = %d", $note['comment_ID'] ) ),
			);
		}
		return array(
			'id'       => (int) $id,
			'fp'       => $fp,
			'modified' => $modified,
			'rows'     => $rows,
			'items'    => $items,
			'notes'    => $notes,
		);
	}

	/**
	 * Customer account.
	 *
	 * @param int $id User id.
	 * @return array|null
	 */
	private static function user_object( $id ) {
		global $wpdb;
		$user = self::rows( $wpdb->prepare( "SELECT * FROM $wpdb->users WHERE ID = %d", $id ) );
		if ( ! $user ) {
			return null;
		}
		return array(
			'id'       => (int) $id,
			'fp'       => $user[0]['user_login'],
			'modified' => $user[0]['user_registered'] . '|' . (string) get_user_meta( $id, 'last_update', true ),
			'rows'     => array(
				'users'    => $user,
				// Session tokens stay on this site.
				'usermeta' => self::rows( $wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM $wpdb->usermeta WHERE user_id = %d AND meta_key <> 'session_tokens'", $id ) ),
			),
		);
	}

	/**
	 * Comment or review.
	 *
	 * @param int $id Comment id.
	 * @return array|null
	 */
	private static function comment_object( $id ) {
		global $wpdb;
		$comment = self::rows( $wpdb->prepare( "SELECT * FROM $wpdb->comments WHERE comment_ID = %d", $id ) );
		if ( ! $comment ) {
			return null;
		}
		return array(
			'id'       => (int) $id,
			'fp'       => $comment[0]['comment_date_gmt'] . '|' . strtolower( $comment[0]['comment_author_email'] ),
			'modified' => $comment[0]['comment_date_gmt'],
			'rows'     => array(
				'comments'    => $comment,
				'commentmeta' => self::rows( $wpdb->prepare( "SELECT comment_id, meta_key, meta_value FROM $wpdb->commentmeta WHERE comment_id = %d", $id ) ),
			),
		);
	}

	/**
	 * Send a file of the uploads folder.
	 *
	 * @param string $path Path relative to the uploads folder.
	 * @throws WPMIG_Exception On invalid path.
	 */
	private static function file( $path ) {
		$uploads = wp_upload_dir( null, false );
		$base    = realpath( $uploads['basedir'] );
		$path    = ltrim( str_replace( '\\', '/', $path ), '/' );
		$real    = ( '' !== $path && WPMIG_Archive::is_safe_path( $path ) ) ? realpath( $uploads['basedir'] . '/' . $path ) : false;
		if ( ! $base || ! $real || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) || ! is_file( $real ) || preg_match( '/\.(php\d?|phtml|phar)$/i', $real ) ) {
			self::json( array( 'error' => 'Fichier introuvable.' ), 404 );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		status_header( 200 );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . sprintf( '%u', filesize( $real ) ) );
		readfile( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
