<?php
/**
 * Scheduled backups: a backup of the site is created every day, week or month at
 * a chosen hour, by WP-Cron. The build is the usual time-budgeted state machine,
 * advanced by a chain of short cron events (or by the browser, or by WP-CLI).
 * Only a salted hash of the installer password is stored. Old backups are removed
 * by the automatic cleanup.
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scheduled backups.
 */
class WPMIG_Schedule {

	const OPTION        = 'wpmig_schedule';
	const STATE         = 'wpmig_schedule_state';
	const HOOK          = 'wpmig_scheduled_backup';
	const CONTINUE_HOOK = 'wpmig_scheduled_continue';

	/**
	 * A run without progress for this long is considered abandoned.
	 */
	const STALE = 21600;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_scheduled' ) );
		add_action( self::CONTINUE_HOOK, array( __CLASS__, 'continue_scheduled' ) );
		// Also covers a cleared cron table.
		$settings = self::settings();
		if ( $settings['enabled'] && ! wp_next_scheduled( self::HOOK ) ) {
			self::reschedule();
		}
	}

	/**
	 * Plugin deactivation.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Types of backup: key => label.
	 *
	 * @return array
	 */
	public static function types() {
		return array(
			'full'      => 'Complète (fichiers et base de données)',
			'nouploads' => 'Fichiers sans la médiathèque, et base de données',
			'db'        => 'Base de données seulement',
		);
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION );
		return array_merge(
			array(
				'enabled'   => false,
				'frequency' => 'daily',
				'hour'      => 3,
				'weekday'   => 1,
				'type'      => 'full',
				's3'        => false,
				'notify'    => 'failure',
				'email'     => '',
				'salt'      => '',
				'hash'      => '',
			),
			is_array( $saved ) ? $saved : array()
		);
	}

	/**
	 * Save the settings.
	 *
	 * @param array $input Raw values (password: empty keeps the current one).
	 * @return array Saved settings.
	 * @throws WPMIG_Exception When the settings are not valid.
	 */
	public static function save_settings( array $input ) {
		$old      = self::settings();
		$settings = array(
			'enabled'   => ! empty( $input['enabled'] ),
			'frequency' => isset( $input['frequency'] ) && in_array( $input['frequency'], array( 'daily', 'weekly', 'monthly' ), true ) ? $input['frequency'] : 'daily',
			'hour'      => isset( $input['hour'] ) ? max( 0, min( 23, (int) $input['hour'] ) ) : 3,
			'weekday'   => isset( $input['weekday'] ) ? max( 0, min( 6, (int) $input['weekday'] ) ) : 1,
			'type'      => isset( $input['type'] ) && isset( self::types()[ $input['type'] ] ) ? $input['type'] : 'full',
			's3'        => ! empty( $input['s3'] ),
			'notify'    => isset( $input['notify'] ) && in_array( $input['notify'], array( 'never', 'failure', 'always' ), true ) ? $input['notify'] : 'failure',
			'email'     => isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '',
			'salt'      => $old['salt'],
			'hash'      => $old['hash'],
		);
		if ( isset( $input['email'] ) && '' !== trim( (string) $input['email'] ) && '' === $settings['email'] ) {
			throw new WPMIG_Exception( 'L\'adresse e-mail n\'est pas valide.' );
		}
		$password = isset( $input['password'] ) ? (string) $input['password'] : '';
		if ( '' !== $password ) {
			if ( strlen( $password ) < 8 ) {
				throw new WPMIG_Exception( 'Le mot de passe de l\'installeur doit compter au moins 8 caractères.' );
			}
			$settings['salt'] = WPMIG_Package::random_hex( 16 );
			$settings['hash'] = hash( 'sha256', $settings['salt'] . $password );
		}
		if ( $settings['enabled'] && '' === $settings['hash'] ) {
			throw new WPMIG_Exception( 'Choisissez un mot de passe d\'au moins 8 caractères pour l\'installeur des sauvegardes planifiées : il protège les sauvegardes déposées sur un serveur.' );
		}
		update_option( self::OPTION, $settings, false );
		self::reschedule();
		return $settings;
	}

	/* ------------------------------------------------------------------ */
	/* Schedule                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Time zone of the site.
	 *
	 * @return DateTimeZone
	 */
	private static function timezone() {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}
		$name = (string) get_option( 'timezone_string' );
		if ( '' !== $name ) {
			return new DateTimeZone( $name );
		}
		$offset = (float) get_option( 'gmt_offset' );
		$sign   = $offset < 0 ? '-' : '+';
		return new DateTimeZone( sprintf( '%s%02d:%02d', $sign, floor( abs( $offset ) ), ( abs( $offset ) - floor( abs( $offset ) ) ) * 60 ) );
	}

	/**
	 * Next run after a moment.
	 *
	 * @param array             $settings Settings (frequency, hour, weekday).
	 * @param int               $from     Timestamp.
	 * @param DateTimeZone|null $tz       Time zone (the site's by default).
	 * @return int Timestamp.
	 */
	public static function next_run( array $settings, $from, $tz = null ) {
		$tz  = $tz ? $tz : self::timezone();
		$now = new DateTime( '@' . (int) $from );
		$now->setTimezone( $tz );
		$t = clone $now;
		$t->setTime( (int) $settings['hour'], 0, 0 );
		switch ( $settings['frequency'] ) {
			case 'weekly':
				$diff = ( (int) $settings['weekday'] - (int) $t->format( 'w' ) + 7 ) % 7;
				if ( $diff ) {
					$t->modify( '+' . $diff . ' day' );
				}
				if ( $t <= $now ) {
					$t->modify( '+7 day' );
				}
				break;
			case 'monthly':
				$t->setDate( (int) $t->format( 'Y' ), (int) $t->format( 'n' ), 1 );
				if ( $t <= $now ) {
					$t->modify( 'first day of next month' );
				}
				break;
			default:
				if ( $t <= $now ) {
					$t->modify( '+1 day' );
				}
		}
		return $t->getTimestamp();
	}

	/**
	 * (Re)schedule the next run.
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		$settings = self::settings();
		if ( $settings['enabled'] && '' !== $settings['hash'] ) {
			wp_schedule_single_event( self::next_run( $settings, time() ), self::HOOK );
		}
	}

	/**
	 * Date of the next run (0 when none).
	 *
	 * @return int
	 */
	public static function next_scheduled() {
		$next = wp_next_scheduled( self::HOOK );
		return $next ? (int) $next : 0;
	}

	/* ------------------------------------------------------------------ */
	/* State                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * State: id (backup in progress), started, last (result of the last run).
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::STATE );
		return array_merge( array( 'id' => '', 'started' => 0, 'last' => array(), 's3' => 0 ), is_array( $state ) ? $state : array() );
	}

	/**
	 * Save the state.
	 *
	 * @param array $state State.
	 */
	private static function save_state( array $state ) {
		update_option( self::STATE, $state, false );
	}

	/**
	 * Remember the end of a run and warn the administrator.
	 *
	 * @param array $state  State.
	 * @param bool  $ok     Success.
	 * @param string $text  Size or error message.
	 * @param string $id    Backup id.
	 */
	private static function finish( array $state, $ok, $text, $id ) {
		$state['last'] = array(
			'time'     => time(),
			'ok'       => $ok ? 1 : 0,
			'text'     => $text,
			'id'       => $id,
			'duration' => $state['started'] ? time() - (int) $state['started'] : 0,
		);
		$state['id']      = '';
		$state['started'] = 0;
		$state['s3']      = 0;
		self::save_state( $state );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
		self::notify( $state['last'] );
	}

	/**
	 * E-mail about the result of a run.
	 *
	 * @param array $last Result.
	 */
	private static function notify( array $last ) {
		$settings = self::settings();
		if ( 'never' === $settings['notify'] || ( $last['ok'] && 'always' !== $settings['notify'] ) ) {
			return;
		}
		$to   = '' !== $settings['email'] ? $settings['email'] : get_option( 'admin_email' );
		$site = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$url  = add_query_arg( array( 'page' => 'wp-migration', 'tab' => 'backups' ), admin_url( 'admin.php' ) );
		$body = $last['ok']
			? sprintf( "La sauvegarde planifiée de %s s'est terminée (%s).\n\nSauvegardes : %s\n", $site, $last['text'], $url )
			: sprintf( "La sauvegarde planifiée de %s a échoué :\n%s\n\nSauvegardes : %s\n", $site, $last['text'], $url );
		wp_mail( $to, sprintf( '[%s] Sauvegarde planifiée %s', $site, $last['ok'] ? 'terminée' : 'en échec' ), $body );
	}

	/* ------------------------------------------------------------------ */
	/* Run                                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Cron event: time for a backup.
	 */
	public static function run_scheduled() {
		// The next occurrence is planned first: a failure never stops the schedule.
		self::reschedule();
		$settings = self::settings();
		if ( ! $settings['enabled'] ) {
			return;
		}
		try {
			self::start( $settings );
			self::continue_scheduled();
		} catch ( Exception $e ) {
			self::finish( self::state(), false, $e->getMessage(), '' );
		}
	}

	/**
	 * Cron event: next step of the backup in progress.
	 */
	public static function continue_scheduled() {
		try {
			WPMIG_Plugin::raise_limits();
			$res = self::advance();
		} catch ( Exception $e ) {
			self::finish( self::state(), false, $e->getMessage(), self::state()['id'] );
			return;
		}
		if ( 'running' === $res['status'] ) {
			if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
				wp_schedule_single_event( time() + 5, self::CONTINUE_HOOK );
			}
			if ( function_exists( 'spawn_cron' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
				spawn_cron();
			}
		}
	}

	/**
	 * Start a backup (nothing happens while another one is in progress).
	 *
	 * @param array|null $settings Settings.
	 * @return bool Started.
	 * @throws WPMIG_Exception When it cannot be created.
	 */
	public static function start( $settings = null ) {
		$settings = $settings ? $settings : self::settings();
		$state    = self::state();
		if ( '' !== $state['id'] ) {
			if ( time() - (int) $state['started'] < self::STALE ) {
				return false;
			}
			self::finish( $state, false, 'Sauvegarde abandonnée (sans progression depuis plus de 6 heures).', $state['id'] );
			$state = self::state();
		}
		if ( '' === $settings['hash'] ) {
			throw new WPMIG_Exception( 'Aucun mot de passe d\'installeur défini pour les sauvegardes planifiées.' );
		}
		$options = array( 'name' => 'auto' );
		if ( 'db' === $settings['type'] ) {
			$options['db_only'] = true;
		} elseif ( 'nouploads' === $settings['type'] ) {
			$options['exclude_uploads'] = true;
		}
		$package          = WPMIG_Package::create( $options, array( 'salt' => $settings['salt'], 'hash' => $settings['hash'] ) );
		$state['id']      = $package->data['id'];
		$state['started'] = time();
		self::save_state( $state );
		return true;
	}

	/**
	 * Advance the backup in progress by one time-budgeted step.
	 *
	 * @param float $budget Seconds (default: the usual budget).
	 * @return array status (running|complete|error|none), progress, message, id.
	 */
	public static function advance( $budget = 0 ) {
		$state = self::state();
		if ( '' === $state['id'] ) {
			return array( 'status' => 'none', 'progress' => 0, 'message' => '', 'id' => '' );
		}
		$package = WPMIG_Package::load( $state['id'] );
		if ( ! $package ) {
			self::finish( $state, false, 'Sauvegarde introuvable.', $state['id'] );
			return array( 'status' => 'error', 'progress' => 0, 'message' => 'Sauvegarde introuvable.', 'id' => $state['id'] );
		}
		$budget = $budget > 0 ? $budget : WPMIG_Plugin::time_budget();
		$public = $package->step( $budget );
		if ( 'scanned' === $package->data['status'] ) {
			foreach ( $package->data['report']['checks'] as $check ) {
				if ( 'error' === $check['status'] ) {
					$message = $check['label'] . ' : ' . $check['value'];
					self::finish( $state, false, $message, $state['id'] );
					return array( 'status' => 'error', 'progress' => 0, 'message' => $message, 'id' => $state['id'] );
				}
			}
			$package->start_build();
			$public = $package->step( $budget );
		}
		$status = $package->data['status'];
		if ( 'complete' === $status ) {
			$size   = ! empty( $package->data['sizes']['archive'] ) ? size_format( $package->data['sizes']['archive'], 1 ) : '';
			$remote = '';
			$cfg    = self::settings();
			if ( $cfg['s3'] && WPMIG_S3::configured() ) {
				// The backup now goes to S3, a few parts per step, before the run is over.
				if ( empty( $state['s3'] ) ) {
					WPMIG_S3::start( $state['id'] );
					$state['s3'] = 1;
					self::save_state( $state );
				}
				$up = WPMIG_S3::step( $budget );
				if ( 'running' === $up['status'] ) {
					return array( 'status' => 'running', 'progress' => $up['progress'], 'message' => $up['message'], 'id' => $state['id'] );
				}
				if ( 'done' !== $up['status'] ) {
					$message = 'Sauvegarde créée (' . $size . ') mais envoi vers S3 en échec : ' . $up['error'];
					self::finish( $state, false, $message, $state['id'] );
					return array( 'status' => 'error', 'progress' => 0, 'message' => $message, 'id' => $state['id'] );
				}
				$remote = ' — envoyée sur S3';
			}
			self::finish( $state, true, $size . $remote, $state['id'] );
			return array( 'status' => 'complete', 'progress' => 100, 'message' => 'Sauvegarde prête (' . $size . ')' . $remote . '.', 'id' => $state['id'] );
		}
		if ( 'error' === $status ) {
			$message = $package->data['error'] ? $package->data['error'] : 'Échec de la construction.';
			self::finish( $state, false, $message, $state['id'] );
			return array( 'status' => 'error', 'progress' => 0, 'message' => $message, 'id' => $state['id'] );
		}
		return array(
			'status'   => 'running',
			'progress' => isset( $public['progress'] ) ? (int) $public['progress'] : 0,
			'message'  => isset( $public['message'] ) ? (string) $public['message'] : '',
			'id'       => $state['id'],
		);
	}

	/**
	 * Run a whole backup now, step after step (WP-CLI).
	 *
	 * @param callable|null $progress Called with each state.
	 * @return array Last state.
	 * @throws WPMIG_Exception When it cannot be created.
	 */
	public static function run_now( $progress = null ) {
		WPMIG_Plugin::raise_limits();
		self::start();
		do {
			$res = self::advance( 5 );
			if ( $progress ) {
				call_user_func( $progress, $res );
			}
		} while ( 'running' === $res['status'] );
		return $res;
	}
}
