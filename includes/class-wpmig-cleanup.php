<?php
/**
 * Automatic cleanup of old packages: keeps the storage directory (and the disk
 * of the hosting) under control, and removes abandoned builds, whose working
 * directory contains a full SQL dump of the site.
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Package cleanup.
 */
class WPMIG_Cleanup {

	const HOOK   = 'wpmig_daily_cleanup';
	const OPTION = 'wpmig_settings';
	const LAST   = 'wpmig_last_cleanup';

	/**
	 * An unfinished build older than this is considered abandoned.
	 */
	const STALE = 86400;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_scheduled' ) );
		// Also covers sites updated from a version without the scheduled event.
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Plugin deactivation.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Settings.
	 *
	 * @return array keep (completed packages to keep, 0 = no limit), days (max age, 0 = no limit).
	 */
	public static function settings() {
		$saved = get_option( self::OPTION );
		return array_merge(
			array(
				'keep' => 5,
				'days' => 30,
			),
			is_array( $saved ) ? array_intersect_key( $saved, array_flip( array( 'keep', 'days' ) ) ) : array()
		);
	}

	/**
	 * Save the settings.
	 *
	 * @param array $input Raw values.
	 * @return array Saved settings.
	 */
	public static function save_settings( array $input ) {
		$settings = array(
			'keep' => isset( $input['keep'] ) ? max( 0, min( 1000, (int) $input['keep'] ) ) : 5,
			'days' => isset( $input['days'] ) ? max( 0, min( 3650, (int) $input['days'] ) ) : 30,
		);
		update_option( self::OPTION, $settings, false );
		return $settings;
	}

	/**
	 * Is a package build currently running (its lock is held)?
	 *
	 * @param string $id Package id.
	 * @return bool
	 */
	private static function is_locked( $id ) {
		$lock = @fopen( WPMIG_Plugin::storage_dir() . $id . '.lock', 'c' ); // phpcs:ignore
		if ( ! $lock ) {
			return false;
		}
		$free = flock( $lock, LOCK_EX | LOCK_NB );
		if ( $free ) {
			flock( $lock, LOCK_UN );
		}
		fclose( $lock ); // phpcs:ignore
		return ! $free;
	}

	/**
	 * Disk usage of a path.
	 *
	 * @param string $path File or directory.
	 * @return float
	 */
	private static function size_of( $path ) {
		if ( is_file( $path ) ) {
			return (float) sprintf( '%u', @filesize( $path ) ); // phpcs:ignore
		}
		$total = 0;
		if ( is_dir( $path ) ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				$total += (float) sprintf( '%u', $file->getSize() );
			}
		}
		return $total;
	}

	/**
	 * Size of a package (archive, installer, working directory).
	 *
	 * @param WPMIG_Package $package Package.
	 * @return float
	 */
	private static function package_size( WPMIG_Package $package ) {
		return self::size_of( $package->archive_path() ) + self::size_of( $package->installer_path() ) + self::size_of( rtrim( $package->work_dir(), '/' ) );
	}

	/**
	 * Total size of the storage directory.
	 *
	 * @return float
	 */
	public static function storage_size() {
		return self::size_of( rtrim( WPMIG_Plugin::storage_dir(), '/' ) );
	}

	/**
	 * What the cleanup would remove.
	 *
	 * @param int|null $keep Override of the "keep" setting.
	 * @param int|null $days Override of the "days" setting.
	 * @return array List of array( 'label', 'reason', 'size', 'package' => WPMIG_Package|null, 'path' => string|null ).
	 */
	public static function plan( $keep = null, $days = null ) {
		$settings = self::settings();
		$keep     = null === $keep ? (int) $settings['keep'] : max( 0, (int) $keep );
		$days     = null === $days ? (int) $settings['days'] : max( 0, (int) $days );
		$now      = time();
		$items    = array();
		$known    = array();
		$complete = 0;

		foreach ( WPMIG_Package::all() as $package ) {
			$d                  = $package->data;
			$known[ $d['id'] ] = true;
			$age                = $now - (int) $d['created'];
			$reason             = '';

			if ( 'complete' !== $d['status'] ) {
				if ( $age > self::STALE && ! self::is_locked( $d['id'] ) ) {
					$reason = 'error' === $d['status'] ? 'construction en échec' : 'construction abandonnée';
				}
			} else {
				$complete++;
				if ( $keep > 0 && $complete > $keep ) {
					$reason = sprintf( 'au-delà des %d dernières sauvegardes', $keep );
				} elseif ( $days > 0 && $age > $days * DAY_IN_SECONDS ) {
					$reason = sprintf( 'plus de %d jours', $days );
				}
				// Never break a migration in progress.
				if ( '' !== $reason && WPMIG_Transfer::active_until( $package ) ) {
					$reason = '';
				}
			}
			if ( '' !== $reason ) {
				$items[] = array(
					'label'   => $d['name'] . ' (' . $d['id'] . ')',
					'reason'  => $reason,
					'size'    => self::package_size( $package ),
					'package' => $package,
					'path'    => null,
				);
			}
		}

		// Files left without their package (interrupted deletion, manual copies...).
		$dir = WPMIG_Plugin::storage_dir();
		foreach ( (array) @scandir( $dir ) as $item ) { // phpcs:ignore
			if ( ! is_string( $item ) || '.' === $item[0] || in_array( $item, array( 'index.php', 'index.html', 'web.config' ), true ) ) {
				continue;
			}
			if ( ! preg_match( '/(\d{8}_\d{6}_[a-f0-9]{12})/', $item, $m ) || isset( $known[ $m[1] ] ) ) {
				continue;
			}
			$path = $dir . $item;
			if ( $now - (int) @filemtime( $path ) < self::STALE ) { // phpcs:ignore
				continue;
			}
			$items[] = array(
				'label'   => $item,
				'reason'  => 'fichier orphelin',
				'size'    => self::size_of( $path ),
				'package' => null,
				'path'    => $path,
			);
		}
		return $items;
	}

	/**
	 * Run the cleanup.
	 *
	 * @param bool     $dry_run Only report.
	 * @param int|null $keep    Override of the "keep" setting.
	 * @param int|null $days    Override of the "days" setting.
	 * @return array array( 'items' => list, 'count' => int, 'bytes' => float ).
	 */
	public static function run( $dry_run = false, $keep = null, $days = null ) {
		$items = self::plan( $keep, $days );
		$bytes = 0;
		foreach ( $items as $i => $item ) {
			$bytes += $item['size'];
			if ( ! $dry_run ) {
				if ( $item['package'] ) {
					$item['package']->delete();
				} else {
					WPMIG_Plugin::rrmdir( $item['path'] );
				}
			}
			unset( $items[ $i ]['package'], $items[ $i ]['path'] );
		}
		$result = array(
			'items' => array_values( $items ),
			'count' => count( $items ),
			'bytes' => $bytes,
		);
		if ( ! $dry_run ) {
			update_option(
				self::LAST,
				array(
					'time'  => time(),
					'count' => $result['count'],
					'bytes' => $bytes,
				),
				false
			);
		}
		return $result;
	}

	/**
	 * Daily event.
	 */
	public static function run_scheduled() {
		try {
			self::run();
		} catch ( Exception $e ) {
			// The storage directory may be unavailable: nothing to clean.
			return;
		}
	}

	/**
	 * After a successful build.
	 *
	 * @param WPMIG_Package $package The package that has just been built.
	 */
	public static function after_build( WPMIG_Package $package ) {
		try {
			$result = self::run();
		} catch ( Exception $e ) {
			$package->log( 'Nettoyage automatique impossible : ' . $e->getMessage() );
			return;
		}
		if ( $result['count'] ) {
			$package->log( sprintf( 'Nettoyage automatique : %d élément(s) supprimé(s), %s libérés.', $result['count'], wpmig_size( $result['bytes'] ) ) );
		}
	}
}
