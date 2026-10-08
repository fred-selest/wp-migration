<?php
/**
 * Content synchronization, destination side: brings into this site (typically a
 * development copy) the content created or modified on the source site since the
 * copy was made: orders, customers, products, coupons, posts and pages, media,
 * comments. Nothing is written on the source.
 *
 * Identifiers: an object keeps its id whenever possible (order numbers, payment
 * references). When the id is taken here by a revision, an automatic draft or a
 * test order, that object is moved to a free id; when it is taken by content
 * created here, the incoming object gets a free id (orders excepted: the local
 * content is moved and its references are updated). The correspondences are kept
 * for the next synchronizations.
 *
 * Conflicts: orders, customers, coupons and comments come from the source.
 * Products, posts, pages and media modified on both sides keep the local version
 * (products still receive the stock from the source), unless "force" is set.
 *
 * Every change is journaled and can be undone.
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronization engine.
 */
class WPMIG_Sync {

	const STATE   = 'wpmig_sync_state';
	const HISTORY = 'wpmig_sync_history';
	const MAP     = 'wpmig_sync_map';
	const MARGIN  = 600;
	const BATCH   = 25;

	/**
	 * Ids given here are kept this far above the highest id of the source, so that
	 * the next objects of the source keep their own id.
	 */
	const GAP = 1000;

	/**
	 * Kinds, in the order they are applied (dependencies first).
	 */
	const KINDS = array( 'media', 'customers', 'products', 'coupons', 'posts', 'orders', 'comments' );

	/**
	 * Product meta that always come from the source (sales happen there).
	 */
	const STOCK_KEYS = array( '_stock', '_stock_status', 'total_sales', '_wc_average_rating', '_wc_rating_count', '_wc_review_count' );

	/**
	 * Post types that can be moved to another id without any risk.
	 */
	const DISPOSABLE = array( 'revision', 'oembed_cache', 'customize_changeset' );

	/**
	 * Order post types.
	 */
	const ORDER_TYPES = array( 'shop_order', 'shop_order_refund', 'shop_order_placehold' );

	/**
	 * Current state.
	 *
	 * @var array
	 */
	private $state;

	/**
	 * Journal.
	 *
	 * @var WPMIG_Sync_DB
	 */
	private $db;

	/**
	 * Map cache.
	 *
	 * @var array
	 */
	private $map = array();

	/**
	 * URL / path replacer.
	 *
	 * @var WPMIG_Replacer|null
	 */
	private $replacer;

	/**
	 * Labels.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'media'     => 'Médias',
			'customers' => 'Clients',
			'products'  => 'Produits',
			'coupons'   => 'Codes promo',
			'posts'     => 'Articles et pages',
			'orders'    => 'Commandes',
			'comments'  => 'Commentaires et avis',
		);
	}

	/**
	 * Action labels.
	 *
	 * @return array
	 */
	public static function action_labels() {
		return array(
			'insert'  => 'à ajouter',
			'update'  => 'à mettre à jour',
			'stock'   => 'stock seulement (modifiés ici)',
			'keep'    => 'conservés (modifiés ici)',
			'same'    => 'déjà à jour',
			'skip'    => 'ignorés (supprimés ici)',
		);
	}

	/**
	 * Action labels once imported.
	 *
	 * @return array
	 */
	public static function done_labels() {
		return array_merge(
			self::action_labels(),
			array(
				'insert' => 'ajoutés',
				'update' => 'mis à jour',
			)
		);
	}

	/**
	 * Constructor.
	 *
	 * @param array $state State.
	 */
	private function __construct( array $state ) {
		$this->state = $state;
		$this->db    = new WPMIG_Sync_DB();
	}

	/**
	 * Current synchronization (or null).
	 *
	 * @return WPMIG_Sync|null
	 */
	public static function current() {
		$state = get_option( self::STATE );
		return is_array( $state ) && ! empty( $state['id'] ) ? new self( $state ) : null;
	}

	/**
	 * State.
	 *
	 * @return array
	 */
	public function state() {
		return $this->state;
	}

	/**
	 * Save the state.
	 */
	private function save() {
		update_option( self::STATE, $this->state, false );
	}

	/**
	 * Working directory.
	 *
	 * @return string
	 */
	private function dir() {
		$dir = WPMIG_Plugin::storage_dir() . 'sync-' . $this->state['id'] . '/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * History of the synchronizations (newest first).
	 *
	 * @return array
	 */
	public static function history() {
		$h = get_option( self::HISTORY );
		return is_array( $h ) ? $h : array();
	}

	/* ------------------------------------------------------------------ */
	/* Source                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Validate a synchronization link.
	 *
	 * @param string $link Link.
	 * @return string
	 * @throws WPMIG_Exception When invalid.
	 */
	public static function check_link( $link ) {
		$link  = trim( (string) $link );
		$parts = wp_parse_url( $link );
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}
		if ( empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || empty( $parts['host'] )
			|| ! isset( $query['action'], $query['key'] ) || WPMIG_Sync_Source::ACTION !== $query['action'] || ! preg_match( '/^[a-f0-9]{32}$/', $query['key'] ) ) {
			throw new WPMIG_Exception( 'Ce n\'est pas un lien de synchronisation WP Migration (créé sur le site d\'origine avec « Autoriser la synchronisation »).' );
		}
		return $link;
	}

	/**
	 * Request to the source.
	 *
	 * @param string $link   Link.
	 * @param array  $params Parameters.
	 * @return array
	 * @throws WPMIG_Exception On error.
	 */
	public static function call( $link, array $params ) {
		$res = wp_remote_post(
			$link,
			array(
				'timeout'    => 60,
				'body'       => $params,
				'user-agent' => 'WP-Migration/' . WPMIG_VERSION . ' (sync)',
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new WPMIG_Exception( 'Site d\'origine injoignable : ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $data ) ) {
			$msg = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : 'réponse inattendue (HTTP ' . $code . ')';
			if ( 400 === $code || 404 === $code && ! is_array( $data ) ) {
				$msg .= '. Le site d\'origine doit avoir WP Migration 1.5.0 ou plus récent.';
			}
			throw new WPMIG_Exception( 'Site d\'origine : ' . $msg );
		}
		return $data;
	}

	/**
	 * Request to the source of the current synchronization.
	 *
	 * @param array $params Parameters.
	 * @return array
	 */
	private function source( array $params ) {
		return self::call( $this->state['link'], $params );
	}

	/* ------------------------------------------------------------------ */
	/* Start                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Default date of the copy for a source: last synchronization, or the
	 * migration report of this site.
	 *
	 * @param string     $home Home URL of the source.
	 * @param array|null $info Information of the source (its packages).
	 * @return string GMT date or ''.
	 */
	public static function default_threshold( $home, $info = null ) {
		foreach ( self::history() as $h ) {
			if ( isset( $h['source'] ) && untrailingslashit( $h['source'] ) === untrailingslashit( $home ) && 'done' === $h['status'] && ! empty( $h['source_time'] ) ) {
				return $h['source_time'];
			}
		}
		$report = class_exists( 'WPMIG_Report' ) ? WPMIG_Report::get() : null;
		if ( $report && isset( $report['source']['home'] ) && untrailingslashit( $report['source']['home'] ) === untrailingslashit( $home ) ) {
			if ( ! empty( $report['package']['dump_started'] ) ) {
				return $report['package']['dump_started'];
			}
			if ( ! empty( $report['package']['created'] ) ) {
				return $report['package']['created'];
			}
		}
		// Package this site was installed from, among the packages of the source.
		$ids = array();
		if ( $report && ! empty( $report['package']['id'] ) ) {
			$ids[] = $report['package']['id'];
		}
		$flag = json_decode( (string) get_option( 'wpmig_installed' ), true );
		if ( is_array( $flag ) && ! empty( $flag['package'] ) ) {
			$ids[] = $flag['package'];
		}
		foreach ( ( is_array( $info ) && ! empty( $info['packages'] ) ) ? $info['packages'] : array() as $p ) {
			if ( in_array( $p['id'], $ids, true ) ) {
				return $p['start'];
			}
		}
		return '';
	}

	/**
	 * Information for the form: source site, default date, its packages.
	 *
	 * @param string $link Synchronization link.
	 * @return array
	 */
	public static function probe( $link ) {
		$info     = self::call( self::check_link( $link ), array( 'op' => 'info' ) );
		$default  = self::default_threshold( $info['home'], $info );
		$local    = function ( $gmt ) {
			return get_date_from_gmt( $gmt, 'Y-m-d\TH:i' );
		};
		$packages = array();
		foreach ( isset( $info['packages'] ) ? $info['packages'] : array() as $p ) {
			$packages[] = array(
				'label' => sprintf( '« %s » du %s', $p['name'], wpmig_date( strtotime( $p['start'] . ' UTC' ) ) ),
				'value' => $local( $p['start'] ),
			);
		}
		return array(
			'source'    => $info['home'],
			'default'   => $default ? $local( $default ) : '',
			'default_h' => $default ? wpmig_date( strtotime( $default . ' UTC' ) ) : '',
			'packages'  => $packages,
		);
	}

	/**
	 * Date of the copy of this site from a source (reference for "modified here").
	 *
	 * @param string $home      Home URL of the source.
	 * @param string $threshold Date of the synchronization being started.
	 * @return string GMT date.
	 */
	private static function copy_date( $home, $threshold ) {
		$copy = $threshold;
		foreach ( self::history() as $h ) {
			if ( isset( $h['source'], $h['copy'] ) && untrailingslashit( $h['source'] ) === untrailingslashit( $home ) && 'undone' !== $h['status'] && $h['copy'] < $copy ) {
				$copy = $h['copy'];
			}
		}
		return $copy;
	}

	/**
	 * Start a synchronization: analysis first, nothing is changed until confirmed.
	 *
	 * @param string $link      Synchronization link.
	 * @param array  $kinds     Kinds.
	 * @param string $threshold Date of the copy (local time 'Y-m-d H:i' or GMT 'Y-m-d H:i:s'), '' for automatic.
	 * @param bool   $force     Also replace the content modified here.
	 * @return WPMIG_Sync
	 * @throws WPMIG_Exception On error.
	 */
	public static function start( $link, array $kinds, $threshold = '', $force = false ) {
		$current = self::current();
		if ( $current && in_array( $current->state['status'], array( 'analyzing', 'importing', 'files', 'finalizing', 'undoing' ), true ) ) {
			throw new WPMIG_Exception( 'Une synchronisation est déjà en cours.' );
		}
		$link  = self::check_link( $link );
		$kinds = array_values( array_intersect( self::KINDS, $kinds ) );
		if ( ! $kinds ) {
			throw new WPMIG_Exception( 'Choisissez au moins un type de contenu.' );
		}
		$info = self::call( $link, array( 'op' => 'info' ) );
		if ( empty( $info['home'] ) ) {
			throw new WPMIG_Exception( 'Réponse inattendue du site d\'origine.' );
		}
		if ( untrailingslashit( $info['home'] ) === untrailingslashit( home_url() ) ) {
			throw new WPMIG_Exception( 'Ce lien a été créé sur ce site : créez-le sur le site d\'origine.' );
		}
		// Customers follow their orders.
		if ( in_array( 'orders', $kinds, true ) && ! in_array( 'customers', $kinds, true ) ) {
			$kinds[] = 'customers';
			$kinds   = array_values( array_intersect( self::KINDS, $kinds ) );
		}
		$wc_kinds = array_intersect( array( 'orders', 'products', 'coupons' ), $kinds );
		if ( $wc_kinds && ( empty( $info['woocommerce'] ) || ! class_exists( 'WooCommerce' ) ) ) {
			throw new WPMIG_Exception( 'WooCommerce doit être actif sur les deux sites pour synchroniser les commandes, produits et codes promo.' );
		}
		if ( in_array( 'orders', $kinds, true ) && (bool) $info['hpos'] !== self::hpos() ) {
			throw new WPMIG_Exception( 'Les deux sites n\'utilisent pas le même stockage des commandes WooCommerce (tables HPOS d\'un côté, articles de l\'autre) : alignez le réglage WooCommerce → Réglages → Avancé → Fonctionnalités.' );
		}

		$threshold = trim( (string) $threshold );
		if ( '' === $threshold ) {
			$threshold = self::default_threshold( $info['home'], $info );
			if ( '' === $threshold ) {
				$list = array();
				foreach ( isset( $info['packages'] ) ? $info['packages'] : array() as $p ) {
					$list[] = sprintf( '« %s » : %s', $p['name'], get_date_from_gmt( $p['start'], 'Y-m-d H:i' ) );
				}
				throw new WPMIG_Exception( 'Précisez la date de la copie (quand ce site a été copié depuis le site d\'origine) : aucun rapport de migration ni synchronisation précédente ne l\'indique.' . ( $list ? ' Sauvegardes du site d\'origine : ' . implode( ' ; ', $list ) . '.' : '' ) );
			}
		} elseif ( preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(:\d{2})?$/', $threshold, $m ) ) {
			// Local time of this site → GMT.
			$threshold = get_gmt_from_date( $m[1] . ' ' . $m[2] . ':00' );
		} else {
			throw new WPMIG_Exception( 'Date invalide : utilisez le format AAAA-MM-JJ HH:MM.' );
		}

		self::create_map_table();
		// Only the last synchronization can be undone: older journals go.
		foreach ( (array) glob( WPMIG_Plugin::storage_dir() . 'sync-*', GLOB_ONLYDIR ) as $old ) {
			WPMIG_Plugin::rrmdir( $old );
		}
		$sync = new self(
			array(
				'id'        => gmdate( 'Ymd_His' ) . '_' . WPMIG_Package::random_hex( 6 ),
				'status'    => 'analyzing',
				'link'      => $link,
				'info'      => $info,
				'threshold' => $threshold,
				'copy'      => self::copy_date( $info['home'], $threshold ),
				// A margin catches the changes made while the copy was being made.
				'since'     => gmdate( 'Y-m-d H:i:s', strtotime( $threshold . ' UTC' ) - self::MARGIN ),
				'kinds'     => $kinds,
				'force'     => (bool) $force,
				'started'   => time(),
				'cursor'    => array( 0, 0 ),
				'counts'    => array(),
				'notes'     => array(),
				'warnings'  => array(),
				'line'      => 0,
				'lines'     => 0,
				'chunk'     => 0,
				'files'     => array(
					'total'      => 0,
					'done'       => 0,
					'downloaded' => 0,
					'failed'     => 0,
				),
				'touched'   => array(
					'products' => array(),
					'orders'   => array(),
					'users'    => array(),
					'tt'       => array(),
					'posts'    => array(),
				),
				'message'   => 'Analyse du site d\'origine…',
			)
		);
		$sync->dir();
		file_put_contents( $sync->dir() . 'plan.php', "<?php exit; ?>\n" );
		file_put_contents( $sync->dir() . 'files.php', "<?php exit; ?>\n" );
		if ( ! empty( $info['wpml'] ) && ! self::has( 'icl_translations' ) ) {
			$sync->state['warnings'][] = 'WPML est utilisé sur le site d\'origine mais pas sur ce site : les liens entre traductions ne seront pas repris.';
		}
		$sync->save();
		return $sync;
	}

	/**
	 * Forget the current synchronization (after display of its result).
	 */
	public static function dismiss() {
		$current = self::current();
		if ( $current && in_array( $current->state['status'], array( 'analyzing', 'importing', 'files', 'finalizing', 'undoing' ), true ) ) {
			throw new WPMIG_Exception( 'Synchronisation en cours.' );
		}
		if ( $current && 'ready' === $current->state['status'] ) {
			WPMIG_Plugin::rrmdir( rtrim( $current->dir(), '/' ) );
		}
		delete_option( self::STATE );
	}

	/**
	 * Are orders stored in the WooCommerce tables here?
	 *
	 * @return bool
	 */
	private static function hpos() {
		return 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' ) && self::has( 'wc_orders' );
	}

	/**
	 * Does a table exist here?
	 *
	 * @param string $suffix Table.
	 * @return bool
	 */
	private static function has( $suffix ) {
		return WPMIG_Sync_DB::has_table( $suffix );
	}

	/**
	 * Correspondence table.
	 */
	private static function create_map_table() {
		global $wpdb;
		$wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . WPMIG_SQL::quote_id( $wpdb->prefix . self::MAP ) . " (
			source CHAR(12) NOT NULL,
			kind VARCHAR(20) NOT NULL,
			source_id BIGINT UNSIGNED NOT NULL,
			target_id BIGINT UNSIGNED NOT NULL,
			modified VARCHAR(64) NOT NULL DEFAULT '',
			PRIMARY KEY (source, kind, source_id),
			KEY target (source, kind, target_id)
		) " . $wpdb->get_charset_collate() ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/* ------------------------------------------------------------------ */
	/* Steps                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Run for one time slice.
	 *
	 * @param float $deadline Microtime or 0.
	 * @return array Public state.
	 */
	public function step( $deadline ) {
		$lock = @fopen( WPMIG_Plugin::storage_dir() . 'sync.lock', 'c' ); // phpcs:ignore
		if ( $lock && ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			fclose( $lock ); // phpcs:ignore
			return $this->public_state();
		}
		$fresh = self::current();
		if ( $fresh ) {
			$this->state = $fresh->state;
		}
		WPMIG_Plugin::raise_limits();
		if ( in_array( $this->state['status'], array( 'importing', 'files', 'finalizing' ), true ) ) {
			// One journal file per request, written as the changes are committed.
			$this->db->journal( $this->dir() . sprintf( 'undo-%05d.php', ++$this->state['chunk'] ) );
		}
		try {
			switch ( $this->state['status'] ) {
				case 'analyzing':
					$this->analyze( $deadline );
					break;
				case 'importing':
					$this->import( $deadline );
					break;
				case 'files':
					$this->files( $deadline );
					break;
				case 'finalizing':
					$this->finalize();
					break;
				case 'undoing':
					$this->undo_step( $deadline );
					break;
			}
		} catch ( Exception $e ) {
			$this->state['status'] = 'error';
			$this->state['error']  = $e->getMessage();
		}
		$this->save();
		if ( $lock ) {
			flock( $lock, LOCK_UN );
			fclose( $lock ); // phpcs:ignore
		}
		return $this->public_state();
	}

	/**
	 * Run to the end (WP-CLI).
	 *
	 * @param callable|null $progress Receives the public state after each step.
	 * @return array Public state.
	 */
	public function run( $progress = null ) {
		do {
			$state = $this->step( 0 );
			if ( $progress ) {
				call_user_func( $progress, $state );
			}
		} while ( in_array( $this->state['status'], array( 'analyzing', 'importing', 'files', 'finalizing', 'undoing' ), true ) );
		return $this->public_state();
	}

	/**
	 * State for the interface.
	 *
	 * @return array
	 */
	public function public_state() {
		$s = $this->state;
		return array(
			'id'        => $s['id'],
			'status'    => $s['status'],
			'message'   => isset( $s['message'] ) ? $s['message'] : '',
			'error'     => isset( $s['error'] ) ? $s['error'] : '',
			'source'    => $s['info']['home'],
			'threshold' => $s['threshold'],
			'threshold_h' => wpmig_date( strtotime( $s['threshold'] . ' UTC' ) ),
			'kinds'     => $s['kinds'],
			'force'     => $s['force'],
			'counts'    => array_merge( array_intersect_key( array_fill_keys( self::KINDS, array() ), $s['counts'] ), $s['counts'] ),
			'notes'     => array_slice( $s['notes'], 0, 300 ),
			'warnings'  => $s['warnings'],
			'lines'     => (int) $s['lines'],
			'progress'  => $s['lines'] ? (int) ( 100 * $s['line'] / $s['lines'] ) : 0,
			'files'     => $s['files'],
		);
	}

	/**
	 * Count an action.
	 *
	 * @param string $kind   Kind.
	 * @param string $action Action.
	 */
	private function count( $kind, $action ) {
		if ( ! isset( $this->state['counts'][ $kind ][ $action ] ) ) {
			$this->state['counts'][ $kind ][ $action ] = 0;
		}
		$this->state['counts'][ $kind ][ $action ]++;
	}

	/**
	 * Remember a noteworthy decision.
	 *
	 * @param string $kind   Kind.
	 * @param int    $sid    Source id.
	 * @param string $text   Text.
	 * @param string $action Action decided.
	 */
	private function note( $kind, $sid, $text, $action = '' ) {
		if ( count( $this->state['notes'] ) < 1000 ) {
			$this->state['notes'][] = array( $kind, (int) $sid, $text, $action );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Analysis                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Analysis step.
	 *
	 * @param float $deadline Microtime or 0.
	 */
	private function analyze( $deadline ) {
		$plan = fopen( $this->dir() . 'plan.php', 'ab' );
		while ( $this->state['cursor'][0] < count( $this->state['kinds'] ) ) {
			$kind = $this->state['kinds'][ $this->state['cursor'][0] ];
			$page = $this->source(
				array(
					'op'    => 'scan',
					'kind'  => $kind,
					'since' => $this->state['since'],
					'after' => $this->state['cursor'][1],
				)
			);
			$lines = '';
			foreach ( (array) $page['items'] as $item ) {
				list( $sid, $fp, $created, $modified ) = $item;
				$r = $this->resolve( $kind, (int) $sid, (string) $fp, (string) $created, (string) $modified );
				$this->count( $kind, $r['action'] );
				if ( '' !== $r['note'] ) {
					$this->note( $kind, $sid, $r['note'], $r['action'] );
				}
				if ( in_array( $r['action'], array( 'insert', 'update', 'stock' ), true ) ) {
					$lines .= wp_json_encode( array( $kind, (int) $sid ) ) . "\n";
					$this->state['lines']++;
				}
			}
			fwrite( $plan, $lines );
			$this->state['cursor'][1] = (int) $page['next'];
			if ( ! $page['next'] ) {
				$this->state['cursor'] = array( $this->state['cursor'][0] + 1, 0 );
			}
			$this->state['message'] = 'Analyse : ' . self::labels()[ $kind ] . '…';
			if ( $deadline && microtime( true ) >= $deadline ) {
				break;
			}
		}
		fclose( $plan );
		if ( $this->state['cursor'][0] >= count( $this->state['kinds'] ) ) {
			$this->state['status']  = 'ready';
			$this->state['message'] = $this->state['lines'] ? 'Analyse terminée : vérifiez puis lancez l\'import.' : 'Rien à synchroniser : ce site est à jour.';
		}
	}

	/**
	 * Entity of a kind.
	 *
	 * @param string $kind Kind.
	 * @return string post, user or comment.
	 */
	private static function entity( $kind ) {
		if ( 'customers' === $kind ) {
			return 'user';
		}
		return 'comments' === $kind ? 'comment' : 'post';
	}

	/**
	 * Key of the source in the correspondence table.
	 *
	 * @return string
	 */
	private function source_key() {
		return substr( md5( untrailingslashit( $this->state['info']['home'] ) ), 0, 12 );
	}

	/**
	 * Target id of a source id (correspondence table), or null.
	 *
	 * @param string $entity Entity.
	 * @param int    $sid    Source id.
	 * @return array|null array( target_id, modified ).
	 */
	private function map_get( $entity, $sid ) {
		global $wpdb;
		$key = $entity . ':' . $sid;
		if ( ! array_key_exists( $key, $this->map ) ) {
			$row               = $wpdb->get_row( $wpdb->prepare( 'SELECT target_id, modified FROM ' . WPMIG_SQL::quote_id( $wpdb->prefix . self::MAP ) . ' WHERE source = %s AND kind = %s AND source_id = %d', $this->source_key(), $entity, $sid ), ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL
			$this->map[ $key ] = $row ? array( (int) $row[0], (string) $row[1] ) : null;
		}
		return $this->map[ $key ];
	}

	/**
	 * Record a correspondence (journaled).
	 *
	 * @param string $entity   Entity.
	 * @param int    $sid      Source id.
	 * @param int    $tid      Target id.
	 * @param string $modified Source modification date.
	 */
	private function map_set( $entity, $sid, $tid, $modified = '' ) {
		$this->db->delete(
			self::MAP,
			array(
				'source'    => $this->source_key(),
				'kind'      => $entity,
				'source_id' => $sid,
			)
		);
		$this->db->insert(
			self::MAP,
			array(
				'source'    => $this->source_key(),
				'kind'      => $entity,
				'source_id' => $sid,
				'target_id' => $tid,
				'modified'  => (string) $modified,
			)
		);
		$this->map[ $entity . ':' . $sid ] = array( (int) $tid, (string) $modified );
	}

	/**
	 * Target id of a referenced source id (same id when not remapped).
	 *
	 * @param string $entity Entity.
	 * @param mixed  $sid    Source id.
	 * @return mixed
	 */
	private function ref( $entity, $sid ) {
		if ( ! is_numeric( $sid ) || (int) $sid <= 0 ) {
			return $sid;
		}
		$m = $this->map_get( $entity, (int) $sid );
		return $m ? (string) $m[0] : $sid;
	}

	/**
	 * Local post row used to identify an object.
	 *
	 * @param int $id Post id.
	 * @return array|null
	 */
	private static function local_post( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_type, post_status, post_title, post_date_gmt, post_modified_gmt FROM $wpdb->posts WHERE ID = %d", $id ), ARRAY_A );
		if ( $row && in_array( $row['post_type'], self::ORDER_TYPES, true ) && self::hpos() ) {
			$o = $wpdb->get_row( $wpdb->prepare( 'SELECT type, date_created_gmt, date_updated_gmt FROM ' . WPMIG_Sync_DB::t( 'wc_orders' ) . ' WHERE id = %d', $id ), ARRAY_A );
			if ( $o ) {
				$row['post_type']         = $o['type'];
				$row['post_date_gmt']     = $o['date_created_gmt'];
				$row['post_modified_gmt'] = $o['date_updated_gmt'];
			}
		}
		return $row;
	}

	/**
	 * Short description of a local post.
	 *
	 * @param array $row Row.
	 * @return string
	 */
	private static function describe( array $row ) {
		$obj   = get_post_type_object( $row['post_type'] );
		$type  = $obj ? $obj->labels->singular_name : $row['post_type'];
		$title = in_array( $row['post_type'], self::ORDER_TYPES, true ) ? 'n° ' . $row['ID'] : ( '' !== $row['post_title'] ? '« ' . wp_html_excerpt( $row['post_title'], 60, '…' ) . ' »' : '#' . $row['ID'] );
		return $type . ' ' . $title;
	}

	/**
	 * Decide what to do with an object of the source.
	 *
	 * @param string $kind     Kind.
	 * @param int    $sid      Source id.
	 * @param string $fp       Identity of the source object.
	 * @param string $created  Creation date (GMT).
	 * @param string $modified Modification date (GMT).
	 * @return array action (insert, update, stock, keep, same, skip), tid (0 = new id), relocate (local id to move away), note.
	 */
	private function resolve( $kind, $sid, $fp, $created, $modified ) {
		global $wpdb;
		$entity = self::entity( $kind );
		$res    = array(
			'action'   => 'insert',
			'tid'      => $sid,
			'relocate' => 0,
			'note'     => '',
		);
		$mapped = $this->map_get( $entity, $sid );
		$tid    = $mapped ? $mapped[0] : $sid;

		if ( 'user' === $entity ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, user_login FROM $wpdb->users WHERE ID = %d", $tid ), ARRAY_A );
			if ( ! $row || ( ! $mapped && $row['user_login'] !== $fp ) ) {
				// Same login under another id: the same person.
				$other = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->users WHERE user_login = %s", $fp ) );
				if ( $other ) {
					$row = array(
						'ID'         => $other,
						'user_login' => $fp,
					);
					$tid = (int) $other;
				} elseif ( $row ) {
					$res['tid']  = 0;
					$res['note'] = sprintf( 'Nouvel identifiant : le n° %d est déjà utilisé ici par le compte « %s ».', $sid, $row['user_login'] );
					return $res;
				}
			}
			if ( ! $row ) {
				if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $wpdb->users WHERE ID = %d", $sid ) ) ) {
					$res['tid'] = 0;
				}
				return $res;
			}
			$res['tid'] = $tid;
			if ( user_can( $tid, 'manage_options' ) ) {
				$res['action'] = 'keep';
				$res['note']   = sprintf( 'Compte « %s » : administrateur de ce site, non modifié.', $row['user_login'] );
				return $res;
			}
			$res['action'] = ( $mapped && $mapped[1] === $modified ) ? 'same' : 'update';
			return $res;
		}

		if ( 'comment' === $entity ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT comment_ID, comment_date_gmt, comment_author_email FROM $wpdb->comments WHERE comment_ID = %d", $tid ), ARRAY_A );
			if ( $row && ( $mapped || $row['comment_date_gmt'] . '|' . strtolower( $row['comment_author_email'] ) === $fp ) ) {
				$res['tid']    = $tid;
				$res['action'] = ( $mapped && $mapped[1] === $modified ) ? 'same' : 'update';
				return $res;
			}
			$taken      = $row || $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $wpdb->comments WHERE comment_ID = %d", $sid ) );
			$res['tid'] = $taken ? 0 : $sid;
			return $res;
		}

		// Posts, products, coupons, media, orders.
		$is_order = 'orders' === $kind;
		$row      = self::local_post( $tid );
		$shared   = $row && ( $mapped || $row['post_type'] . '|' . $row['post_date_gmt'] === $fp );
		if ( $row && ! $shared ) {
			if ( in_array( $row['post_type'], self::DISPOSABLE, true ) || 'auto-draft' === $row['post_status'] ) {
				$res['relocate'] = $tid;
				return $res;
			}
			if ( in_array( $row['post_type'], self::ORDER_TYPES, true ) ) {
				$res['relocate'] = $tid;
				$res['note']     = sprintf( 'La commande n° %d créée sur ce site (test) passe à un nouveau numéro pour laisser le sien à la commande du site d\'origine.', $tid );
				return $res;
			}
			if ( $is_order ) {
				// Order numbers are known to customers and payment services: the local content moves.
				$res['relocate'] = $tid;
				$res['note']     = sprintf( '%s créé(e) sur ce site change d\'identifiant (n° %d → nouveau) pour laisser son numéro à la commande n° %d ; ses références connues (menus, pages d\'accueil, blocs, images à la une) sont mises à jour.', self::describe( $row ), $tid, $sid );
				return $res;
			}
			$res['tid']  = 0;
			$res['note'] = sprintf( 'Nouvel identifiant : le n° %d est déjà utilisé ici par %s.', $sid, self::describe( $row ) );
			return $res;
		}
		if ( ! $row ) {
			if ( $mapped || $created < $this->state['threshold'] ) {
				// It existed when this site was copied: it has been deleted here.
				if ( $is_order ) {
					$res['note'] = sprintf( 'Commande n° %d supprimée sur ce site : recréée.', $sid );
					$res['tid']  = $mapped ? 0 : $sid;
					return $res;
				}
				$res['action'] = 'skip';
				$res['note']   = sprintf( 'N° %d supprimé sur ce site : ignoré.', $sid );
			}
			return $res;
		}

		// The same object on both sides.
		$res['tid'] = $tid;
		// "L|": the local version was kept by a previous synchronization.
		$kept_before = $mapped && 0 === strpos( $mapped[1], 'L|' );
		if ( $mapped && ( $kept_before ? substr( $mapped[1], 2 ) : $mapped[1] ) === $modified ) {
			$res['action'] = 'same';
			return $res;
		}
		if ( $is_order || 'coupons' === $kind || $this->state['force'] ) {
			$res['action'] = 'update';
			return $res;
		}
		// Modified here since the copy, and not by a synchronization (which writes the date of the source).
		$synced       = $mapped ? strtok( $mapped[1], '|' ) : '';
		$changed_here = $kept_before || ( $row['post_modified_gmt'] > $this->state['copy'] && $row['post_modified_gmt'] !== $synced );
		if ( ! $changed_here ) {
			$res['action'] = 'update';
			return $res;
		}
		if ( 'products' === $kind ) {
			$res['action'] = 'stock';
			$res['note']   = sprintf( '%s modifié(e) sur ce site : version conservée, stock et ventes repris du site d\'origine.', self::describe( $row ) );
			return $res;
		}
		$res['action'] = 'keep';
		$res['note']   = sprintf( '%s modifié(e) sur ce site : version conservée.', self::describe( $row ) );
		return $res;
	}

	/* ------------------------------------------------------------------ */
	/* Import                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Confirm the import after the analysis.
	 *
	 * @throws WPMIG_Exception When not ready.
	 */
	public function confirm() {
		if ( 'ready' !== $this->state['status'] ) {
			throw new WPMIG_Exception( 'L\'analyse n\'est pas terminée.' );
		}
		global $wpdb;
		$max  = $this->state['info']['max'];
		$next = array(
			'post'       => max( (int) $wpdb->get_var( "SELECT MAX(ID) FROM $wpdb->posts" ), (int) $max['posts'] ),
			'user'       => max( (int) $wpdb->get_var( "SELECT MAX(ID) FROM $wpdb->users" ), (int) $max['users'] ),
			'comment'    => max( (int) $wpdb->get_var( "SELECT MAX(comment_ID) FROM $wpdb->comments" ), (int) $max['comments'] ),
			'order_item' => self::has( 'woocommerce_order_items' ) ? max( (int) $wpdb->get_var( 'SELECT MAX(order_item_id) FROM ' . WPMIG_Sync_DB::t( 'woocommerce_order_items' ) ), (int) $max['order_items'] ) : 0,
		);
		if ( self::has( 'wc_orders' ) ) {
			$next['post'] = max( $next['post'], (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . WPMIG_Sync_DB::t( 'wc_orders' ) ) );
		}
		$gap = self::gap();
		foreach ( array( 'posts' => 'post', 'users' => 'user', 'comments' => 'comment', 'order_items' => 'order_item' ) as $source_key => $entity ) {
			$next[ $entity ] = max( $next[ $entity ], (int) $max[ $source_key ] + $gap );
		}
		$this->state['next'] = $next;
		// Decisions without import (kept, up to date, skipped) stay in the result; the others are counted again while imported.
		$counts = array();
		foreach ( $this->state['counts'] as $kind => $by_action ) {
			foreach ( $by_action as $action => $n ) {
				if ( in_array( $action, array( 'keep', 'same', 'skip' ), true ) ) {
					$counts[ $kind ][ $action ] = $n;
				}
			}
		}
		$notes = array();
		foreach ( $this->state['notes'] as $note ) {
			if ( isset( $note[3] ) && in_array( $note[3], array( 'keep', 'same', 'skip' ), true ) ) {
				$notes[] = $note;
			}
		}
		$this->state['counts']  = $counts;
		$this->state['notes']   = $notes;
		$this->state['status']  = 'importing';
		$this->state['message'] = 'Import…';
		$this->save();
	}

	/**
	 * Reserved gap above the ids of the source.
	 *
	 * @return int
	 */
	private static function gap() {
		return max( 0, (int) apply_filters( 'wpmig_sync_id_gap', self::GAP ) );
	}

	/**
	 * A free id, above everything used here and on the source.
	 *
	 * @param string $entity post, user, comment or order_item.
	 * @return int
	 */
	private function alloc( $entity ) {
		global $wpdb;
		$check = array(
			'post'       => "SELECT 1 FROM $wpdb->posts WHERE ID = %d",
			'user'       => "SELECT 1 FROM $wpdb->users WHERE ID = %d",
			'comment'    => "SELECT 1 FROM $wpdb->comments WHERE comment_ID = %d",
			'order_item' => 'SELECT 1 FROM ' . WPMIG_Sync_DB::t( 'woocommerce_order_items' ) . ' WHERE order_item_id = %d',
		);
		do {
			$id = ++$this->state['next'][ $entity ];
		} while ( $wpdb->get_var( $wpdb->prepare( $check[ $entity ], $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $id;
	}

	/**
	 * Import step.
	 *
	 * @param float $deadline Microtime or 0.
	 */
	private function import( $deadline ) {
		$file = new SplFileObject( $this->dir() . 'plan.php' );
		$file->seek( $this->state['line'] + 1 );
		$this->prepare_replacer();
		$files = fopen( $this->dir() . 'files.php', 'ab' );

		while ( ! $file->eof() ) {
			// Next batch: consecutive lines of the same kind.
			$batch = array();
			$kind  = null;
			$pos   = $file->key();
			while ( ! $file->eof() && count( $batch ) < self::BATCH ) {
				$line = json_decode( (string) $file->current(), true );
				if ( ! is_array( $line ) ) {
					$file->next();
					continue;
				}
				if ( null !== $kind && $line[0] !== $kind ) {
					break;
				}
				$kind    = $line[0];
				$batch[] = (int) $line[1];
				$file->next();
			}
			if ( ! $batch ) {
				break;
			}
			$res  = $this->source(
				array(
					'op'   => 'fetch',
					'kind' => $kind,
					'ids'  => implode( ',', $batch ),
				)
			);
			$done = 0;
			foreach ( (array) $res['objects'] as $obj ) {
				$this->db->begin();
				try {
					$this->apply( $kind, $obj, $files );
					$this->db->commit();
				} catch ( Exception $e ) {
					$this->db->rollback();
					$this->state['warnings'][] = sprintf( '%s n° %d non importé(e) : %s', self::labels()[ $kind ], $obj['id'], $e->getMessage() );
				}
				$done++;
				$this->state['line'] = $pos + $done - 1;
				if ( $deadline && microtime( true ) >= $deadline ) {
					break 2;
				}
			}
			// Objects gone from the source meanwhile.
			$this->state['line'] = $pos + count( $batch ) - 1;
		}
		fclose( $files );

		$this->state['message'] = sprintf( 'Import… %d / %d', $this->state['line'], $this->state['lines'] );
		if ( $this->state['line'] >= $this->state['lines'] ) {
			$this->state['files']['total'] = max( 0, count( file( $this->dir() . 'files.php' ) ) - 1 );
			$this->state['status']         = 'files';
			$this->state['message']        = 'Téléchargement des fichiers des médias…';
		}
	}

	/**
	 * URL and path replacement from the source site to this site.
	 */
	private function prepare_replacer() {
		$info  = $this->state['info'];
		$pairs = array();
		if ( untrailingslashit( $info['home'] ) !== untrailingslashit( home_url() ) ) {
			$pairs += WPMIG_Replacer::build_url_pairs( $info['home'], untrailingslashit( home_url() ) );
		}
		if ( untrailingslashit( $info['siteurl'] ) !== untrailingslashit( site_url() ) && $info['siteurl'] !== $info['home'] ) {
			$pairs += WPMIG_Replacer::build_url_pairs( $info['siteurl'], untrailingslashit( site_url() ) );
		}
		if ( WPMIG_Plugin::normalize( ABSPATH ) !== $info['abspath'] ) {
			$pairs += WPMIG_Replacer::build_path_pairs( $info['abspath'], WPMIG_Plugin::normalize( ABSPATH ) );
		}
		$this->replacer = $pairs ? new WPMIG_Replacer( $pairs ) : null;
	}

	/**
	 * Prepare a source row: decode, replace URLs.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private function row( array $row ) {
		$row = WPMIG_Sync_DB::decode_row( $row );
		if ( $this->replacer ) {
			foreach ( $row as $k => $v ) {
				if ( is_string( $v ) && '' !== $v && ! is_numeric( $v ) ) {
					$row[ $k ] = $this->replacer->replace( $v );
				}
			}
		}
		return $row;
	}

	/**
	 * Apply an object.
	 *
	 * @param string   $kind  Kind.
	 * @param array    $obj   Object from the source.
	 * @param resource $files Queue of files to download.
	 */
	private function apply( $kind, array $obj, $files ) {
		$first = self::first_row( $obj );
		$r     = $this->resolve( $kind, (int) $obj['id'], (string) $obj['fp'], $first['created'], (string) $obj['modified'] );
		$this->count( $kind, $r['action'] );
		if ( '' !== $r['note'] ) {
			$this->note( $kind, $obj['id'], ucfirst( $first['label'] ) . ' — ' . $r['note'], $r['action'] );
		}
		if ( in_array( $r['action'], array( 'keep', 'same', 'skip' ), true ) ) {
			return;
		}
		$entity = self::entity( $kind );
		if ( $r['relocate'] ) {
			$new = $this->alloc( 'post' );
			$this->relocate_post( $r['relocate'], $new );
		}
		$tid = $r['tid'] ? $r['tid'] : $this->alloc( $entity );
		if ( 'user' === $entity ) {
			$this->apply_user( $obj, $tid );
		} elseif ( 'comment' === $entity ) {
			$this->apply_comment( $obj, $tid );
		} elseif ( 'orders' === $kind ) {
			$this->apply_order( $obj, $tid );
		} elseif ( 'stock' === $r['action'] ) {
			$this->apply_stock( $obj, $tid, $files );
		} else {
			$this->apply_post( $kind, $obj, $tid, $files );
		}
	}

	/**
	 * Creation date and label of an object.
	 *
	 * @param array $obj Object.
	 * @return array created, label.
	 */
	private static function first_row( array $obj ) {
		$rows = $obj['rows'];
		if ( ! empty( $rows['wc_orders'][0] ) ) {
			return array(
				'created' => (string) $rows['wc_orders'][0]['date_created_gmt'],
				'label'   => 'commande n° ' . $obj['id'],
			);
		}
		if ( ! empty( $rows['posts'][0] ) ) {
			$p = $rows['posts'][0];
			return array(
				'created' => (string) $p['post_date_gmt'],
				'label'   => in_array( $p['post_type'], self::ORDER_TYPES, true ) ? 'commande n° ' . $obj['id'] : ( is_string( $p['post_title'] ) && '' !== $p['post_title'] ? '« ' . wp_html_excerpt( $p['post_title'], 60, '…' ) . ' »' : 'n° ' . $obj['id'] ),
			);
		}
		if ( ! empty( $rows['users'][0] ) ) {
			return array(
				'created' => (string) $rows['users'][0]['user_registered'],
				'label'   => 'compte « ' . $rows['users'][0]['user_login'] . ' »',
			);
		}
		$c = $rows['comments'][0];
		return array(
			'created' => (string) $c['comment_date_gmt'],
			'label'   => 'commentaire de ' . $c['comment_author'],
		);
	}

	/**
	 * Unserialize a list of ids, never an object.
	 *
	 * @param string $value Serialized value.
	 * @return mixed
	 */
	private static function unserialize( $value ) {
		if ( ! is_string( $value ) || preg_match( '/(^|[;{}])[OC]:\d+:/', $value ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- no object can be created (checked above, and allowed_classes on PHP 7+).
		return PHP_VERSION_ID >= 70000 ? @unserialize( $value, array( 'allowed_classes' => false ) ) : @unserialize( $value );
	}

	/**
	 * Remap the ids referenced by a post meta.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	private function meta_refs( $key, $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		if ( in_array( $key, array( '_thumbnail_id', '_product_id', '_variation_id', '_menu_item_object_id' ), true ) ) {
			return $this->ref( 'post', $value );
		}
		if ( in_array( $key, array( '_customer_user' ), true ) ) {
			return $this->ref( 'user', $value );
		}
		if ( in_array( $key, array( '_product_image_gallery', 'product_ids', 'exclude_product_ids' ), true ) ) {
			$ids = array();
			foreach ( explode( ',', $value ) as $id ) {
				$ids[] = $this->ref( 'post', trim( $id ) );
			}
			return implode( ',', $ids );
		}
		if ( in_array( $key, array( '_children', '_upsell_ids', '_crosssell_ids' ), true ) && is_serialized( $value ) ) {
			$list = self::unserialize( $value );
			if ( is_array( $list ) ) {
				foreach ( $list as $i => $id ) {
					$list[ $i ] = (int) $this->ref( 'post', $id );
				}
				return serialize( $list ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
		}
		return $value;
	}

	/**
	 * Write a post-like object (post, page, product, variation, coupon, media).
	 *
	 * @param string   $kind   Kind.
	 * @param array    $obj    Object.
	 * @param int      $tid    Target id.
	 * @param resource $files  File queue.
	 * @param int      $parent Target id of the parent (variations).
	 */
	private function apply_post( $kind, array $obj, $tid, $files, $parent = 0 ) {
		global $wpdb;
		$sid  = (int) $obj['id'];
		$post = $this->row( $obj['rows']['posts'][0] );
		$type = $post['post_type'];

		$this->db->delete( 'postmeta', array( 'post_id' => $tid ) );
		foreach ( $this->db->delete( 'term_relationships', array( 'object_id' => $tid ) ) as $rel ) {
			$this->state['touched']['tt'][ (int) $rel['term_taxonomy_id'] ] = '';
		}
		$this->db->delete( 'posts', array( 'ID' => $tid ) );

		$post['ID']          = $tid;
		$post['post_parent'] = $parent ? $parent : $this->ref( 'post', $post['post_parent'] );
		$post['post_author'] = $this->ref( 'user', $post['post_author'] );
		$this->db->insert( 'posts', $post );
		foreach ( $obj['rows']['postmeta'] as $meta ) {
			$meta            = $this->row( $meta );
			$meta['post_id'] = $tid;
			$meta['meta_value'] = $this->meta_refs( $meta['meta_key'], $meta['meta_value'] );
			$this->db->insert( 'postmeta', $meta );
		}
		$this->terms( $obj, $tid );
		$this->translations( $obj, $tid, $type );
		$this->map_set( 'post', $sid, $tid, $obj['modified'] );

		if ( ! empty( $obj['attributes'] ) && self::has( 'woocommerce_attribute_taxonomies' ) ) {
			foreach ( $obj['attributes'] as $attr ) {
				$attr = WPMIG_Sync_DB::decode_row( $attr );
				if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT attribute_id FROM ' . WPMIG_Sync_DB::t( 'woocommerce_attribute_taxonomies' ) . ' WHERE attribute_name = %s', $attr['attribute_name'] ) ) ) {
					$this->db->insert( 'woocommerce_attribute_taxonomies', $attr );
					delete_transient( 'wc_attribute_taxonomies' );
				}
			}
		}
		if ( ! empty( $obj['files'] ) ) {
			foreach ( $obj['files'] as $path ) {
				fwrite( $files, wp_json_encode( (string) $path ) . "\n" );
			}
		}
		if ( in_array( $type, array( 'product', 'product_variation' ), true ) ) {
			$this->state['touched']['products'][] = $tid;
		}
		if ( ! empty( $obj['children'] ) ) {
			foreach ( $obj['children'] as $child ) {
				$first = self::first_row( $child );
				$r     = $this->resolve( $kind, (int) $child['id'], (string) $child['fp'], $first['created'], (string) $child['modified'] );
				if ( 'skip' === $r['action'] ) {
					continue;
				}
				if ( in_array( $r['action'], array( 'stock', 'keep' ), true ) ) {
					// Variation modified here.
					$this->stock_only( $child, $r['tid'] );
					continue;
				}
				if ( $r['relocate'] ) {
					$this->relocate_post( $r['relocate'], $this->alloc( 'post' ) );
				}
				$this->apply_post( $kind, $child, $r['tid'] ? $r['tid'] : $this->alloc( 'post' ), $files, $tid );
			}
		}
	}

	/**
	 * Product modified on both sides: stock and sales from the source only.
	 *
	 * @param array    $obj   Product.
	 * @param int      $tid   Target id.
	 * @param resource $files File queue.
	 */
	private function apply_stock( array $obj, $tid, $files ) {
		$list = array_merge( array( $obj ), isset( $obj['children'] ) ? $obj['children'] : array() );
		// The local version stays the reference for the next synchronizations.
		$this->map_set( 'post', (int) $obj['id'], $tid, 'L|' . $obj['modified'] );
		foreach ( $list as $i => $item ) {
			$target = $i ? null : $tid;
			if ( $i ) {
				$first = self::first_row( $item );
				$r     = $this->resolve( 'products', (int) $item['id'], (string) $item['fp'], $first['created'], (string) $item['modified'] );
				if ( 'insert' === $r['action'] ) {
					// A variation added on the source.
					if ( $r['relocate'] ) {
						$this->relocate_post( $r['relocate'], $this->alloc( 'post' ) );
					}
					$this->apply_post( 'products', $item, $r['tid'] ? $r['tid'] : $this->alloc( 'post' ), $files, $tid );
					continue;
				}
				if ( 'skip' === $r['action'] ) {
					continue;
				}
				$target = $r['tid'];
			}
			$this->stock_only( $item, $target );
		}
	}

	/**
	 * Stock and sales of a product or variation from the source.
	 *
	 * @param array $item   Product or variation.
	 * @param int   $target Target id.
	 */
	private function stock_only( array $item, $target ) {
		$in = array();
		foreach ( $item['rows']['postmeta'] as $meta ) {
			if ( in_array( $meta['meta_key'], self::STOCK_KEYS, true ) ) {
				$in[] = WPMIG_Sync_DB::decode_row( $meta );
			}
		}
		$keys = array_map( array( 'WPMIG_Sync_DB', 'quote' ), self::STOCK_KEYS );
		$this->db->delete( 'postmeta', 'post_id = ' . (int) $target . ' AND meta_key IN (' . implode( ',', $keys ) . ')' );
		foreach ( $in as $meta ) {
			$meta['post_id'] = $target;
			$this->db->insert( 'postmeta', $meta );
		}
		$this->state['touched']['products'][] = (int) $target;
	}

	/**
	 * Terms of an object: create the missing ones, write the relationships.
	 *
	 * @param array $obj Object.
	 * @param int   $tid Target id.
	 */
	private function terms( array $obj, $tid ) {
		global $wpdb;
		if ( empty( $obj['relationships'] ) ) {
			return;
		}
		$find = function ( $taxonomy, $slug ) use ( $wpdb ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT tt.term_taxonomy_id, t.term_id FROM $wpdb->term_taxonomy tt JOIN $wpdb->terms t ON t.term_id = tt.term_id WHERE tt.taxonomy = %s AND t.slug = %s", $taxonomy, $slug ), ARRAY_A );
		};
		foreach ( isset( $obj['terms'] ) ? $obj['terms'] : array() as $def ) {
			if ( $find( $def['taxonomy'], $def['slug'] ) ) {
				continue;
			}
			$parent  = $def['parent'] ? $find( $def['taxonomy'], $def['parent'] ) : null;
			$term_id = $this->db->insert(
				'terms',
				array(
					'name'       => $this->row( array( 'n' => $def['name'] ) )['n'],
					'slug'       => $def['slug'],
					'term_group' => (int) $def['term_group'],
				)
			);
			$this->db->insert(
				'term_taxonomy',
				array(
					'term_id'     => $term_id,
					'taxonomy'    => $def['taxonomy'],
					'description' => $this->row( array( 'd' => $def['description'] ) )['d'],
					'parent'      => $parent ? (int) $parent['term_id'] : 0,
					'count'       => 0,
				)
			);
			foreach ( $def['meta'] as $meta ) {
				$meta            = $this->row( $meta );
				$meta['term_id'] = $term_id;
				$this->db->insert( 'termmeta', $meta );
			}
			$this->note( 'terms', $term_id, sprintf( 'Terme « %s » (%s) créé.', $def['name'], $def['taxonomy'] ) );
		}
		foreach ( $obj['relationships'] as $rel ) {
			list( $taxonomy, $slug, $order ) = $rel;
			$term = $find( $taxonomy, $slug );
			if ( ! $term ) {
				continue;
			}
			$this->db->insert(
				'term_relationships',
				array(
					'object_id'        => $tid,
					'term_taxonomy_id' => (int) $term['term_taxonomy_id'],
					'term_order'       => (int) $order,
				)
			);
			$this->state['touched']['tt'][ (int) $term['term_taxonomy_id'] ] = $taxonomy;
		}
	}

	/**
	 * WPML: language of a post and link with its translations.
	 *
	 * @param array  $obj  Object.
	 * @param int    $tid  Target id.
	 * @param string $type Post type.
	 */
	private function translations( array $obj, $tid, $type ) {
		global $wpdb;
		if ( empty( $obj['rows']['icl_translations'] ) || ! self::has( 'icl_translations' ) ) {
			return;
		}
		$table = WPMIG_Sync_DB::t( 'icl_translations' );
		$this->db->delete(
			'icl_translations',
			array(
				'element_id'   => $tid,
				'element_type' => 'post_' . $type,
			)
		);
		foreach ( $obj['rows']['icl_translations'] as $t ) {
			$t    = WPMIG_Sync_DB::decode_row( $t );
			$trid = (int) $t['trid'];
			$m    = $this->map_get( 'trid', $trid );
			if ( $m ) {
				$trid = $m[0];
			} else {
				// The group number is free, or used here by translations of this very object.
				$members = $wpdb->get_results( $wpdb->prepare( "SELECT element_id, element_type FROM $table WHERE trid = %d", $trid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
				$foreign = false;
				foreach ( $members as $member ) {
					$source_member = $wpdb->get_var( $wpdb->prepare( 'SELECT source_id FROM ' . WPMIG_SQL::quote_id( $wpdb->prefix . self::MAP ) . " WHERE source = %s AND kind = 'post' AND target_id = %d", $this->source_key(), $member['element_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
					$created_here  = $wpdb->get_var( $wpdb->prepare( "SELECT post_date_gmt > %s FROM $wpdb->posts WHERE ID = %d", $this->state['threshold'], $member['element_id'] ) );
					if ( ! $source_member && $created_here ) {
						$foreign = true;
						break;
					}
				}
				if ( $foreign ) {
					$new = 1 + (int) $wpdb->get_var( "SELECT MAX(trid) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL
					$this->map_set( 'trid', $trid, $new );
					$trid = $new;
				}
			}
			$this->db->insert(
				'icl_translations',
				array(
					'element_type'         => 'post_' . $type,
					'element_id'           => $tid,
					'trid'                 => $trid,
					'language_code'        => $t['language_code'],
					'source_language_code' => $t['source_language_code'],
				)
			);
		}
	}

	/**
	 * Write an order or a refund with its items, notes and permissions.
	 *
	 * @param array $obj Order.
	 * @param int   $tid Target id.
	 */
	private function apply_order( array $obj, $tid ) {
		global $wpdb;
		$sid = (int) $obj['id'];

		// Previous version of the order here.
		$items_table = WPMIG_Sync_DB::t( 'woocommerce_order_items' );
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT order_item_id FROM $items_table WHERE order_id = %d", $tid ) ) as $item_id ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$this->db->delete( 'woocommerce_order_itemmeta', array( 'order_item_id' => $item_id ) );
		}
		$this->db->delete( 'woocommerce_order_items', array( 'order_id' => $tid ) );
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM $wpdb->comments WHERE comment_post_ID = %d", $tid ) ) as $cid ) {
			$this->db->delete( 'commentmeta', array( 'comment_id' => $cid ) );
		}
		$this->db->delete( 'comments', array( 'comment_post_ID' => $tid ) );
		$this->db->delete( 'postmeta', array( 'post_id' => $tid ) );
		$this->db->delete( 'posts', array( 'ID' => $tid ) );
		foreach ( array( 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data', 'woocommerce_downloadable_product_permissions', 'wc_order_stats', 'wc_order_product_lookup', 'wc_order_tax_lookup', 'wc_order_coupon_lookup' ) as $table ) {
			if ( self::has( $table ) ) {
				$this->db->delete( $table, array( 'order_id' => $tid ) );
			}
		}
		if ( self::has( 'wc_orders' ) ) {
			$this->db->delete( 'wc_orders', array( 'id' => $tid ) );
		}

		// New version.
		$rows = $obj['rows'];
		foreach ( $rows['posts'] as $post ) {
			$post                = $this->row( $post );
			$post['ID']          = $tid;
			$post['post_parent'] = $this->ref( 'post', $post['post_parent'] );
			$post['post_author'] = $this->ref( 'user', $post['post_author'] );
			$this->db->insert( 'posts', $post );
		}
		foreach ( $rows['postmeta'] as $meta ) {
			$meta               = $this->row( $meta );
			$meta['post_id']    = $tid;
			$meta['meta_value'] = $this->meta_refs( $meta['meta_key'], $meta['meta_value'] );
			$this->db->insert( 'postmeta', $meta );
		}
		if ( ! empty( $rows['wc_orders'] ) && self::has( 'wc_orders' ) ) {
			$order                    = $this->row( $rows['wc_orders'][0] );
			$order['id']              = $tid;
			$order['parent_order_id'] = $this->ref( 'post', $order['parent_order_id'] );
			$order['customer_id']     = $this->ref( 'user', $order['customer_id'] );
			$this->db->insert( 'wc_orders', $order );
		}
		foreach ( array( 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data', 'woocommerce_downloadable_product_permissions' ) as $table ) {
			if ( empty( $rows[ $table ] ) || ! self::has( $table ) ) {
				continue;
			}
			foreach ( $rows[ $table ] as $r ) {
				$r             = $this->row( $r );
				$r['order_id'] = $tid;
				if ( 'wc_orders_meta' === $table ) {
					$r['meta_value'] = $this->meta_refs( $r['meta_key'], $r['meta_value'] );
				}
				if ( 'woocommerce_downloadable_product_permissions' === $table ) {
					$r['product_id'] = $this->ref( 'post', $r['product_id'] );
					$r['user_id']    = $this->ref( 'user', $r['user_id'] );
				}
				$this->db->insert( $table, $r );
			}
		}
		foreach ( $obj['items'] as $item ) {
			$row  = $this->row( $item['row'] );
			$iid  = (int) $row['order_item_id'];
			$m    = $this->map_get( 'order_item', $iid );
			$cand = $m ? $m[0] : $iid;
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $items_table WHERE order_item_id = %d", $cand ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$cand = $this->alloc( 'order_item' );
			}
			if ( $cand !== $iid ) {
				$this->map_set( 'order_item', $iid, $cand );
			}
			$row['order_item_id'] = $cand;
			$row['order_id']      = $tid;
			$this->db->insert( 'woocommerce_order_items', $row );
			foreach ( $item['meta'] as $meta ) {
				$meta                  = $this->row( $meta );
				$meta['order_item_id'] = $cand;
				if ( '_refunded_item_id' === $meta['meta_key'] ) {
					$meta['meta_value'] = $this->ref( 'order_item', $meta['meta_value'] );
				} else {
					$meta['meta_value'] = $this->meta_refs( $meta['meta_key'], $meta['meta_value'] );
				}
				$this->db->insert( 'woocommerce_order_itemmeta', $meta );
			}
		}
		foreach ( $obj['notes'] as $note ) {
			$this->insert_comment( $note['row'], $note['meta'], $tid );
		}
		$this->map_set( 'post', $sid, $tid, $obj['modified'] );
		$this->state['touched']['orders'][] = $tid;
	}

	/**
	 * Insert a comment (order note, review...).
	 *
	 * @param array $row     Comment row from the source.
	 * @param array $meta    Its meta rows.
	 * @param int   $post_id Target post id.
	 * @param int   $tid     Target comment id (0: same as the source when free).
	 */
	private function insert_comment( array $row, array $meta, $post_id, $tid = 0 ) {
		global $wpdb;
		$row = $this->row( $row );
		$cid = (int) $row['comment_ID'];
		if ( ! $tid ) {
			$m   = $this->map_get( 'comment', $cid );
			$tid = $m ? $m[0] : $cid;
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $wpdb->comments WHERE comment_ID = %d", $tid ) ) ) {
				$tid = $this->alloc( 'comment' );
			}
		}
		if ( $tid !== $cid ) {
			$this->map_set( 'comment', $cid, $tid, $row['comment_date_gmt'] );
		}
		$row['comment_ID']      = $tid;
		$row['comment_post_ID'] = $post_id;
		$row['user_id']         = $this->ref( 'user', $row['user_id'] );
		$row['comment_parent']  = $this->ref( 'comment', $row['comment_parent'] );
		$this->db->insert( 'comments', $row );
		foreach ( $meta as $m ) {
			$m               = $this->row( $m );
			$m['comment_id'] = $tid;
			$this->db->insert( 'commentmeta', $m );
		}
		return $tid;
	}

	/**
	 * Write a comment or review.
	 *
	 * @param array $obj Comment.
	 * @param int   $tid Target id.
	 */
	private function apply_comment( array $obj, $tid ) {
		global $wpdb;
		$row  = $obj['rows']['comments'][0];
		$post = (int) $this->ref( 'post', $row['comment_post_ID'] );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $wpdb->posts WHERE ID = %d", $post ) ) ) {
			throw new WPMIG_Exception( 'le contenu commenté n\'existe pas sur ce site' );
		}
		$this->db->delete( 'commentmeta', array( 'comment_id' => $tid ) );
		$this->db->delete( 'comments', array( 'comment_ID' => $tid ) );
		$this->insert_comment( $row, $obj['rows']['commentmeta'], $post, $tid );
		$this->map_set( 'comment', (int) $obj['id'], $tid, $obj['modified'] );
		$this->state['touched']['posts'][] = $post;
	}

	/**
	 * Write a customer account.
	 *
	 * @param array $obj User.
	 * @param int   $tid Target id.
	 */
	private function apply_user( array $obj, $tid ) {
		$old = $this->state['info']['prefix'];
		$new = $GLOBALS['wpdb']->prefix;
		$this->db->delete( 'usermeta', array( 'user_id' => $tid ) );
		$this->db->delete( 'users', array( 'ID' => $tid ) );
		$user       = $this->row( $obj['rows']['users'][0] );
		$user['ID'] = $tid;
		$this->db->insert( 'users', $user );
		foreach ( $obj['rows']['usermeta'] as $meta ) {
			$meta            = $this->row( $meta );
			$meta['user_id'] = $tid;
			if ( $old !== $new && 0 === strpos( $meta['meta_key'], $old ) ) {
				$meta['meta_key'] = $new . substr( $meta['meta_key'], strlen( $old ) );
			}
			$this->db->insert( 'usermeta', $meta );
		}
		$this->map_set( 'user', (int) $obj['id'], $tid, $obj['modified'] );
		$this->state['touched']['users'][] = $tid;
		clean_user_cache( $tid );
	}

	/* ------------------------------------------------------------------ */
	/* Moving local content                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Move a local post (revision, draft, test order, or content created here)
	 * to another id, with its data and the known references to it.
	 *
	 * @param int $old Current id.
	 * @param int $new New id.
	 */
	private function relocate_post( $old, $new ) {
		global $wpdb;
		$row = self::local_post( $old );
		if ( ! $row ) {
			return;
		}
		$set = function ( $col, $value ) {
			return function ( $r ) use ( $col, $value ) {
				$r[ $col ] = $value;
				return $r;
			};
		};
		$this->db->change( 'posts', 'ID = ' . (int) $old, $set( 'ID', $new ) );
		// An object brought by a previous synchronization keeps its correspondence.
		$this->db->change( self::MAP, "kind = 'post' AND target_id = " . (int) $old, $set( 'target_id', $new ) );
		$this->map = array();
		$this->db->change( 'posts', 'post_parent = ' . (int) $old, $set( 'post_parent', $new ) );
		$this->db->change( 'postmeta', 'post_id = ' . (int) $old, $set( 'post_id', $new ) );
		$this->db->change( 'term_relationships', 'object_id = ' . (int) $old, $set( 'object_id', $new ) );
		$this->db->change( 'comments', 'comment_post_ID = ' . (int) $old, $set( 'comment_post_ID', $new ) );
		if ( self::has( 'icl_translations' ) ) {
			$this->db->change( 'icl_translations', "element_type LIKE 'post\\_%' AND element_id = " . (int) $old, $set( 'element_id', $new ) );
		}

		$is_order = in_array( $row['post_type'], self::ORDER_TYPES, true );
		if ( $is_order || self::has( 'woocommerce_order_items' ) ) {
			foreach ( array( 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data', 'woocommerce_order_items', 'woocommerce_downloadable_product_permissions', 'wc_order_stats', 'wc_order_product_lookup', 'wc_order_tax_lookup', 'wc_order_coupon_lookup' ) as $table ) {
				if ( self::has( $table ) ) {
					$this->db->change( $table, 'order_id = ' . (int) $old, $set( 'order_id', $new ) );
				}
			}
			if ( self::has( 'wc_orders' ) ) {
				$this->db->change( 'wc_orders', 'id = ' . (int) $old, $set( 'id', $new ) );
				$this->db->change( 'wc_orders', 'parent_order_id = ' . (int) $old, $set( 'parent_order_id', $new ) );
			}
			if ( self::has( 'wc_order_stats' ) ) {
				$this->db->change( 'wc_order_stats', 'parent_id = ' . (int) $old, $set( 'parent_id', $new ) );
			}
		}
		if ( in_array( $row['post_type'], self::DISPOSABLE, true ) || $is_order ) {
			return;
		}

		// References to content created here.
		$o = (string) (int) $old;
		$n = (string) (int) $new;
		foreach ( array( '_thumbnail_id', '_menu_item_object_id' ) as $key ) {
			$this->db->change(
				'postmeta',
				'meta_key = ' . WPMIG_Sync_DB::quote( $key ) . ' AND meta_value = ' . WPMIG_Sync_DB::quote( $o ),
				function ( $r ) use ( $key, $n, $wpdb ) {
					if ( '_menu_item_object_id' === $key && 'post_type' !== get_post_meta( $r['post_id'], '_menu_item_type', true ) ) {
						return null;
					}
					$r['meta_value'] = $n;
					return $r;
				}
			);
		}
		foreach ( array( '_product_image_gallery', '_children', '_upsell_ids', '_crosssell_ids', 'product_ids', 'exclude_product_ids' ) as $key ) {
			$this->db->change(
				'postmeta',
				'meta_key = ' . WPMIG_Sync_DB::quote( $key ) . " AND meta_value LIKE '%" . esc_sql( $o ) . "%'",
				function ( $r ) use ( $o, $n ) {
					$v = $r['meta_value'];
					if ( is_serialized( $v ) ) {
						$list = self::unserialize( $v );
						if ( ! is_array( $list ) ) {
							return null;
						}
						foreach ( $list as $i => $id ) {
							if ( (string) $id === $o ) {
								$list[ $i ] = (int) $n;
							}
						}
						$r['meta_value'] = serialize( $list ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
					} else {
						$r['meta_value'] = implode( ',', array_map( function ( $id ) use ( $o, $n ) { return trim( $id ) === $o ? $n : $id; }, explode( ',', $v ) ) ); // phpcs:ignore
					}
					return $r;
				}
			);
		}
		if ( self::has( 'woocommerce_order_itemmeta' ) ) {
			$this->db->change(
				'woocommerce_order_itemmeta',
				"meta_key IN ('_product_id', '_variation_id') AND meta_value = " . WPMIG_Sync_DB::quote( $o ),
				$set( 'meta_value', $n )
			);
		}
		$options = array( 'page_on_front', 'page_for_posts', 'wp_page_for_privacy_policy', 'woocommerce_shop_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id', 'woocommerce_terms_page_id' );
		$quoted  = array_map( array( 'WPMIG_Sync_DB', 'quote' ), $options );
		$this->db->change( 'options', 'option_name IN (' . implode( ',', $quoted ) . ') AND option_value = ' . WPMIG_Sync_DB::quote( $o ), $set( 'option_value', $n ) );
		$this->db->change(
			'options',
			"option_name = 'sticky_posts'",
			function ( $r ) use ( $o, $n ) {
				$list = self::unserialize( $r['option_value'] );
				if ( ! is_array( $list ) || ! in_array( (int) $o, array_map( 'intval', $list ), true ) ) {
					return null;
				}
				foreach ( $list as $i => $id ) {
					if ( (int) $id === (int) $o ) {
						$list[ $i ] = (int) $n;
					}
				}
				$r['option_value'] = serialize( $list ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				return $r;
			}
		);
		// Blocks: reusable blocks and navigation menus ("ref"), navigation links and images ("id").
		$like = function ( $s ) {
			return "'%" . esc_sql( $s ) . "%'";
		};
		$this->db->change(
			'posts',
			'post_content LIKE ' . $like( '"ref":' . $o ) . ' OR post_content LIKE ' . $like( '"id":' . $o ) . ' OR post_content LIKE ' . $like( 'wp-image-' . $o ),
			function ( $r ) use ( $o, $n, $row ) {
				$content = preg_replace_callback(
					'/<!-- wp:([a-z0-9\/-]+) (\{.*?\}) (\/)?-->/s',
					function ( $m ) use ( $o, $n, $row ) {
						$attrs = json_decode( $m[2], true );
						if ( ! is_array( $attrs ) ) {
							return $m[0];
						}
						$block   = $m[1];
						$changed = false;
						if ( isset( $attrs['ref'] ) && (string) $attrs['ref'] === $o && in_array( $block, array( 'block', 'navigation' ), true ) ) {
							$attrs['ref'] = (int) $n;
							$changed      = true;
						}
						if ( isset( $attrs['id'] ) && (string) $attrs['id'] === $o ) {
							$link  = in_array( $block, array( 'navigation-link', 'navigation-submenu' ), true ) && isset( $attrs['kind'] ) && 'post-type' === $attrs['kind'];
							$media = 'attachment' === $row['post_type'] && in_array( $block, array( 'image', 'cover', 'media-text', 'video', 'audio', 'file' ), true );
							if ( $link || $media ) {
								$attrs['id'] = (int) $n;
								$changed     = true;
							}
						}
						$json = function_exists( 'serialize_block_attributes' ) ? serialize_block_attributes( $attrs ) : wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
						return $changed ? '<!-- wp:' . $block . ' ' . $json . ' ' . ( isset( $m[3] ) ? $m[3] : '' ) . '-->' : $m[0];
					},
					$r['post_content']
				);
				if ( 'attachment' === $row['post_type'] ) {
					$content = preg_replace( '/\bwp-image-' . preg_quote( $o, '/' ) . '\b/', 'wp-image-' . $n, $content );
				}
				if ( $content === $r['post_content'] ) {
					return null;
				}
				$r['post_content'] = $content;
				return $r;
			}
		);
	}

	/* ------------------------------------------------------------------ */
	/* Files, end                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Download the files of the media.
	 *
	 * @param float $deadline Microtime or 0.
	 */
	private function files( $deadline ) {
		$uploads = wp_upload_dir( null, false );
		$base    = WPMIG_Plugin::normalize( $uploads['basedir'] );
		$file    = new SplFileObject( $this->dir() . 'files.php' );
		$file->seek( $this->state['files']['done'] + 1 );
		while ( ! $file->eof() ) {
			$path = json_decode( (string) $file->current(), true );
			$file->next();
			$this->state['files']['done']++;
			if ( ! is_string( $path ) || '' === $path || ! WPMIG_Archive::is_safe_path( $path ) || preg_match( '/\.(php\d?|phtml|phar)$/i', $path ) ) {
				continue;
			}
			$dest = $base . '/' . $path;
			if ( file_exists( $dest ) ) {
				// Already here (same name): kept.
				continue;
			}
			wp_mkdir_p( dirname( $dest ) );
			$tmp = $dest . '.wpmig-part';
			$res = wp_remote_post(
				$this->state['link'],
				array(
					'timeout'  => 120,
					'stream'   => true,
					'filename' => $tmp,
					'body'     => array(
						'op'   => 'file',
						'path' => $path,
					),
				)
			);
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) || ! @rename( $tmp, $dest ) ) { // phpcs:ignore
				@unlink( $tmp ); // phpcs:ignore
				$this->state['files']['failed']++;
				if ( $this->state['files']['failed'] <= 20 ) {
					$this->state['warnings'][] = 'Fichier non téléchargé : ' . $path;
				}
			} else {
				$this->db->file_created( $dest );
				$this->state['files']['downloaded']++;
			}
			if ( $deadline && microtime( true ) >= $deadline ) {
				break;
			}
		}

		$this->state['message'] = sprintf( 'Fichiers des médias… %d / %d', $this->state['files']['done'], $this->state['files']['total'] );
		if ( $file->eof() || $this->state['files']['done'] >= $this->state['files']['total'] ) {
			$this->state['files']['done'] = $this->state['files']['total'];
			$this->state['status']  = 'finalizing';
			$this->state['message'] = 'Finalisation…';
		}
	}

	/**
	 * Counters, caches and WooCommerce tables.
	 */
	private function finalize() {
		$t = $this->state['touched'];
		$this->recount( $t['tt'] );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			foreach ( array_unique( $t['products'] ) as $id ) {
				wc_delete_product_transients( $id );
			}
		}
		if ( $t['products'] && function_exists( 'wc_update_product_lookup_tables' ) ) {
			wc_update_product_lookup_tables();
		}
		if ( $t['posts'] ) {
			// Direct and journaled: wp_update_comment_count_now() would let other plugins save the posts.
			global $wpdb;
			$this->db->begin();
			foreach ( array_unique( $t['posts'] ) as $id ) {
				$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_post_ID = %d AND comment_approved = '1'", $id ) );
				$this->db->change(
					'posts',
					'ID = ' . (int) $id,
					function ( $r ) use ( $count ) {
						$r['comment_count'] = (string) $count;
						return $r;
					}
				);
			}
			$this->db->commit();

			delete_transient( 'wc_count_comments' );
		}
		wp_cache_flush();
		$this->analytics();
		$this->reserve_ids();
		$this->state['status']   = 'done';
		$this->state['finished'] = time();
		$this->state['message']  = 'Synchronisation terminée.';
		$this->remember();
	}

	/**
	 * Statistics of WooCommerce (Analytics) for the orders and customers touched.
	 */
	private function analytics() {
		global $wpdb;
		$t = $this->state['touched'];
		foreach ( array_unique( $t['orders'] ) as $id ) {
			if ( ! wc_get_order( $id ) ) {
				// Gone (undo): its statistics too.
				foreach ( array( 'wc_order_stats', 'wc_order_product_lookup', 'wc_order_tax_lookup', 'wc_order_coupon_lookup' ) as $table ) {
					if ( self::has( $table ) ) {
						$wpdb->delete( WPMIG_Sync_DB::t( $table ), array( 'order_id' => $id ) );
					}
				}
				continue;
			}
			if ( class_exists( 'Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler' ) ) {
				try {
					\Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler::import( $id );
				} catch ( Exception $e ) {
					$this->state['warnings'][] = 'Statistiques WooCommerce de la commande n° ' . $id . ' : ' . $e->getMessage();
				}
			}
		}
		foreach ( array_unique( $t['users'] ) as $id ) {
			if ( ! get_userdata( $id ) ) {
				if ( self::has( 'wc_customer_lookup' ) ) {
					$wpdb->delete( WPMIG_Sync_DB::t( 'wc_customer_lookup' ), array( 'user_id' => $id ) );
				}
				continue;
			}
			if ( class_exists( 'Automattic\WooCommerce\Internal\Admin\Schedulers\CustomersScheduler' ) ) {
				try {
					\Automattic\WooCommerce\Internal\Admin\Schedulers\CustomersScheduler::import( $id );
				} catch ( Exception $e ) {
					unset( $e );
				}
			}
		}
		wp_cache_flush();
	}

	/**
	 * Content created here from now on gets ids above the reserved gap, away from
	 * the ids the source will use.
	 */
	private function reserve_ids() {
		global $wpdb;
		$gap    = self::gap();
		$max    = $this->state['info']['max'];
		$tables = array(
			'posts'                   => $max['posts'],
			'users'                   => $max['users'],
			'comments'                => $max['comments'],
			'woocommerce_order_items' => $max['order_items'],
			'wc_orders'               => $max['posts'],
		);
		foreach ( $tables as $suffix => $source_max ) {
			if ( ! $gap || ! self::has( $suffix ) ) {
				continue;
			}
			$table   = WPMIG_Sync_DB::t( $suffix );
			$current = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			$wanted  = (int) $source_max + $gap + 1;
			if ( $current && $current < $wanted ) {
				$wpdb->query( 'ALTER TABLE ' . WPMIG_SQL::quote_id( $table ) . ' AUTO_INCREMENT = ' . $wanted ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
		}
	}

	/**
	 * Recount terms.
	 *
	 * @param array $tt term_taxonomy_id => taxonomy.
	 */
	private function recount( array $tt ) {
		global $wpdb;
		$by_tax = array();
		foreach ( $tt as $id => $taxonomy ) {
			if ( '' === $taxonomy ) {
				$taxonomy = (string) $wpdb->get_var( $wpdb->prepare( "SELECT taxonomy FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = %d", $id ) );
			}
			if ( '' !== $taxonomy ) {
				$by_tax[ $taxonomy ][] = (int) $id;
			}
		}
		foreach ( $by_tax as $taxonomy => $ids ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				wp_update_term_count_now( $ids, $taxonomy );
			} else {
				foreach ( $ids as $id ) {
					$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->term_taxonomy SET count = (SELECT COUNT(*) FROM $wpdb->term_relationships WHERE term_taxonomy_id = %d) WHERE term_taxonomy_id = %d", $id, $id ) );
				}
			}
		}
	}

	/**
	 * Keep the result in the history.
	 */
	private function remember() {
		$s       = $this->state;
		$history = self::history();
		array_unshift(
			$history,
			array(
				'id'          => $s['id'],
				'status'      => $s['status'],
				'source'      => $s['info']['home'],
				'source_time' => $s['info']['time'],
				'threshold'   => $s['threshold'],
				'copy'        => $s['copy'],
				'kinds'       => $s['kinds'],
				'force'       => $s['force'],
				'started'     => $s['started'],
				'finished'    => isset( $s['finished'] ) ? $s['finished'] : time(),
				'counts'      => $s['counts'],
				'notes'       => array_slice( $s['notes'], 0, 300 ),
				'warnings'    => array_slice( $s['warnings'], 0, 100 ),
				'files'       => $s['files'],
			)
		);
		update_option( self::HISTORY, array_slice( $history, 0, 10 ), false );
	}

	/* ------------------------------------------------------------------ */
	/* Undo                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Undo the last synchronization.
	 *
	 * @throws WPMIG_Exception When impossible.
	 */
	public function undo() {
		if ( 'done' !== $this->state['status'] ) {
			throw new WPMIG_Exception( 'Seule une synchronisation terminée peut être annulée.' );
		}
		$this->state['undo']    = $this->state['chunk'];
		$this->state['status']  = 'undoing';
		$this->state['message'] = 'Annulation…';
		$this->save();
	}

	/**
	 * Undo step: journal files in reverse order.
	 *
	 * @param float $deadline Microtime or 0.
	 */
	private function undo_step( $deadline ) {
		while ( $this->state['undo'] > 0 ) {
			$file = $this->dir() . sprintf( 'undo-%05d.php', $this->state['undo'] );
			if ( is_file( $file ) ) {
				WPMIG_Sync_DB::undo( $file );
				@unlink( $file ); // phpcs:ignore
			}
			$this->state['undo']--;
			$this->state['message'] = 'Annulation… ' . $this->state['undo'] . ' étape(s) restante(s)';
			if ( $deadline && microtime( true ) >= $deadline ) {
				return;
			}
		}
		$this->recount( $this->state['touched']['tt'] );
		if ( $this->state['touched']['products'] && function_exists( 'wc_update_product_lookup_tables' ) ) {
			wc_update_product_lookup_tables();
		}
		delete_transient( 'wc_count_comments' );
		wp_cache_flush();
		if ( function_exists( 'wc_get_order' ) ) {
			$this->analytics();
		}
		$this->state['status']  = 'undone';
		$this->state['message'] = 'Synchronisation annulée : ce site est revenu à son état précédent.';
		$history                = self::history();
		foreach ( $history as $i => $h ) {
			if ( $h['id'] === $this->state['id'] ) {
				$history[ $i ]['status'] = 'undone';
			}
		}
		update_option( self::HISTORY, $history, false );
	}
}
