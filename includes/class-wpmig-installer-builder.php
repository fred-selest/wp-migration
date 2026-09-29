<?php
/**
 * Generates the standalone installer.php of a package: the installer template
 * plus the shared library (archive, replacer, SQL, importer), in a single file.
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installer builder.
 */
class WPMIG_Installer_Builder {

	/**
	 * Library files embedded in the installer.
	 *
	 * @return array
	 */
	public static function library_files() {
		return array(
			'includes/lib/class-wpmig-archive.php',
			'includes/lib/class-wpmig-replacer.php',
			'includes/lib/class-wpmig-consistency.php',
			'includes/lib/class-wpmig-sql.php',
			'includes/lib/class-wpmig-db-importer.php',
		);
	}

	/**
	 * Build the installer source code.
	 *
	 * @param string $plugin_dir Plugin directory (with trailing slash).
	 * @param array  $config     Installer configuration.
	 * @return string
	 * @throws WPMIG_Exception When the template is missing.
	 */
	public static function render( $plugin_dir, array $config ) {
		$template = @file_get_contents( $plugin_dir . 'installer/installer.php.tpl' ); // phpcs:ignore
		if ( ! $template ) {
			throw new WPMIG_Exception( 'Modèle de l\'installeur introuvable.' );
		}
		$lib = '';
		foreach ( self::library_files() as $file ) {
			$code = file_get_contents( $plugin_dir . $file ); // phpcs:ignore
			$code = preg_replace( '/^<\?php\s*/', '', $code );
			$lib .= "\n// ---- " . basename( $file ) . " ----\n" . $code . "\n";
		}
		$config_code = var_export( $config, true ); // phpcs:ignore
		$out         = str_replace( '/*WPMIG_CONFIG*/array()', $config_code, $template );
		$out         = str_replace( '/*WPMIG_LIB*/', $lib, $out );
		return $out;
	}

	/**
	 * Write the installer of a package.
	 *
	 * @param WPMIG_Package $package Package.
	 * @throws WPMIG_Exception On error.
	 */
	public static function build( WPMIG_Package $package ) {
		$data   = $package->data;
		$opts   = $data['options'];
		$hash   = isset( $opts['password_hash'] ) ? (string) $opts['password_hash'] : '';
		$config = array(
			'package'       => $data['id'],
			'name'          => $data['name'],
			'archive'       => $data['files']['archive'],
			'created'       => gmdate( 'Y-m-d H:i:s', $data['created'] ),
			'source_url'    => untrailingslashit( get_option( 'home' ) ),
			'version'       => WPMIG_VERSION,
			'password_salt' => '' !== $hash ? (string) $opts['password_salt'] : '',
			'password_hash' => $hash,
		);
		$code = self::render( WPMIG_DIR, $config );
		if ( false === file_put_contents( $package->installer_path(), $code ) ) { // phpcs:ignore
			throw new WPMIG_Exception( 'Impossible d\'écrire l\'installeur.' );
		}
		$package->log( 'Installeur généré' . ( '' !== $hash ? ' (protégé par mot de passe).' : '.' ) );
	}
}
