<?php
/**
 * Restore a backup of this site from the administration: the installer of the
 * backup and its archive are placed in the WordPress root (the archive is linked
 * rather than copied when the server allows it), then the installer, which
 * already knows how to replace a site, is opened in the browser. The database
 * access of this site is reused by the installer.
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restoration of a backup.
 */
class WPMIG_Restore {

	/**
	 * Root of the site.
	 *
	 * @return string
	 */
	private static function root() {
		return rtrim( WPMIG_Plugin::normalize( ABSPATH ), '/' );
	}

	/**
	 * Names of the files placed in the root.
	 *
	 * @param WPMIG_Package $package Backup.
	 * @return array installer, archive.
	 */
	public static function names( WPMIG_Package $package ) {
		return array(
			'installer' => basename( $package->data['files']['installer'] ),
			'archive'   => basename( $package->data['files']['archive'] ),
		);
	}

	/**
	 * Can this backup be restored (complete, files present)?
	 *
	 * @param WPMIG_Package $package Backup.
	 * @return bool
	 */
	public static function available( WPMIG_Package $package ) {
		return 'complete' === $package->data['status'] && is_file( $package->archive_path() ) && is_file( $package->installer_path() );
	}

	/**
	 * Is the restoration prepared (installer in the root)?
	 *
	 * @param WPMIG_Package $package Backup.
	 * @return string URL of the installer, or ''.
	 */
	public static function prepared( WPMIG_Package $package ) {
		$names = self::names( $package );
		return is_file( self::root() . '/' . $names['installer'] ) ? site_url( '/' . $names['installer'] ) : '';
	}

	/**
	 * Place the installer and the archive in the root.
	 *
	 * @param string $id Backup id.
	 * @return array url, mode (link|copy), size.
	 * @throws WPMIG_Exception When the restoration cannot be prepared.
	 */
	public static function prepare( $id ) {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			throw new WPMIG_Exception( 'Restauration impossible : les modifications de fichiers sont désactivées sur ce site (DISALLOW_FILE_MODS).' );
		}
		$package = WPMIG_Package::load( $id );
		if ( ! $package || ! self::available( $package ) ) {
			throw new WPMIG_Exception( 'Cette sauvegarde n\'est pas complète ou ses fichiers sont introuvables.' );
		}
		$root = self::root();
		if ( ! is_writable( $root ) ) {
			throw new WPMIG_Exception( 'Le dossier du site (' . $root . ') n\'est pas accessible en écriture : la restauration ne peut pas être préparée.' );
		}
		$names     = self::names( $package );
		$installer = $root . '/' . $names['installer'];
		$archive   = $root . '/' . $names['archive'];
		$size      = (int) filesize( $package->archive_path() );

		if ( false === @copy( $package->installer_path(), $installer ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			throw new WPMIG_Exception( 'Impossible d\'écrire ' . $names['installer'] . ' à la racine du site : vérifiez les permissions.' );
		}
		$mode = 'copy';
		$kept = false;
		if ( is_link( $archive ) || file_exists( $archive ) ) {
			// Left by an earlier preparation: reuse it only when it is the same file.
			if ( is_link( $archive ) ? realpath( $archive ) === realpath( $package->archive_path() ) : ( fileinode( $archive ) === fileinode( $package->archive_path() ) || filesize( $archive ) === $size ) ) {
				$kept = true;
				$mode = is_link( $archive ) || fileinode( $archive ) === fileinode( $package->archive_path() ) ? 'link' : 'copy';
			} else {
				@unlink( $archive ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		if ( ! $kept ) {
			if ( @link( $package->archive_path(), $archive ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$mode = 'link';
			} elseif ( function_exists( 'symlink' ) && @symlink( $package->archive_path(), $archive ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$mode = 'link';
			} else {
				$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $root ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( false !== $free && $free < $size * 1.1 ) {
					@unlink( $installer ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					throw new WPMIG_Exception( sprintf( 'Espace disque insuffisant pour placer l\'archive (%s) à côté de l\'installeur.', size_format( $size ) ) );
				}
				if ( ! @copy( $package->archive_path(), $archive ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					@unlink( $installer ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					@unlink( $archive ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					throw new WPMIG_Exception( 'Impossible de placer l\'archive à la racine du site : vérifiez les permissions et l\'espace disque.' );
				}
			}
		}
		return array(
			'url'  => site_url( '/' . $names['installer'] ),
			'mode' => $mode,
			'size' => $size,
		);
	}

	/**
	 * Remove what prepare() placed in the root (the backup itself is untouched).
	 *
	 * @param string $id Backup id.
	 * @throws WPMIG_Exception When the backup is unknown.
	 */
	public static function cancel( $id ) {
		$package = WPMIG_Package::load( $id );
		if ( ! $package ) {
			throw new WPMIG_Exception( 'Sauvegarde introuvable.' );
		}
		$root  = self::root();
		$names = self::names( $package );
		foreach ( $names as $name ) {
			$file = $root . '/' . $name;
			if ( is_link( $file ) || is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		$data = $root . '/wpmig-installer-data-' . preg_replace( '/[^a-z0-9_]/i', '', $package->data['id'] );
		if ( is_dir( $data ) ) {
			WPMIG_Plugin::rrmdir( $data );
		}
	}
}
