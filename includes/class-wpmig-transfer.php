<?php
/**
 * Direct server to server transfer: a secret, temporary and revocable link that
 * lets the installer download the archive straight from the source site
 * (HTTP Range requests, so the download is split and resumable).
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transfer links.
 */
class WPMIG_Transfer {

	const ACTION = 'wpmig_transfer';

	/**
	 * Hooks. The endpoint must work without being logged in: the request comes
	 * from the destination server.
	 */
	public static function init() {
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'serve' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'serve' ) );
	}

	/**
	 * Default link lifetime.
	 *
	 * @return int Hours.
	 */
	public static function default_hours() {
		return (int) apply_filters( 'wpmig_transfer_hours', 24 );
	}

	/**
	 * Create (or replace) the transfer link of a package.
	 *
	 * @param WPMIG_Package $package Package.
	 * @param int           $hours   Lifetime.
	 * @return array url, installer_url, expires.
	 * @throws WPMIG_Exception When the package is not ready.
	 */
	public static function create( WPMIG_Package $package, $hours = 0 ) {
		if ( 'complete' !== $package->data['status'] ) {
			throw new WPMIG_Exception( 'La sauvegarde n\'est pas terminée.' );
		}
		$hours = $hours > 0 ? (int) $hours : self::default_hours();
		$hours = max( 1, min( 24 * 30, $hours ) );
		$token = WPMIG_Package::random_hex( 32 );

		// Only a hash is stored: a leaked package file does not reveal the link.
		$package->data['transfer'] = array(
			'hash'    => hash( 'sha256', $token ),
			'created' => time(),
			'expires' => time() + $hours * HOUR_IN_SECONDS,
		);
		$package->log( 'Lien de transfert direct créé, valable ' . $hours . ' h.' );
		$package->save();
		return self::describe( $package, $token );
	}

	/**
	 * Public description of a link.
	 *
	 * @param WPMIG_Package $package Package.
	 * @param string        $token   Clear token.
	 * @return array
	 */
	private static function describe( WPMIG_Package $package, $token ) {
		$url = add_query_arg(
			array(
				'action' => self::ACTION,
				'id'     => $package->data['id'],
				'key'    => $token,
			),
			admin_url( 'admin-ajax.php' )
		);
		return array(
			'url'           => $url,
			'installer_url' => add_query_arg( 'file', 'installer', $url ),
			'expires'       => $package->data['transfer']['expires'],
			'expires_h'     => wpmig_date( $package->data['transfer']['expires'] ),
			'insecure'      => 0 !== strpos( $url, 'https://' ),
		);
	}

	/**
	 * Revoke the link of a package.
	 *
	 * @param WPMIG_Package $package Package.
	 */
	public static function revoke( WPMIG_Package $package ) {
		if ( ! empty( $package->data['transfer'] ) ) {
			unset( $package->data['transfer'] );
			$package->log( 'Lien de transfert direct révoqué.' );
			$package->save();
		}
	}

	/**
	 * Expiry of the active link (0 when there is none).
	 *
	 * @param WPMIG_Package $package Package.
	 * @return int
	 */
	public static function active_until( WPMIG_Package $package ) {
		$t = isset( $package->data['transfer'] ) ? $package->data['transfer'] : null;
		return ( $t && $t['expires'] > time() ) ? (int) $t['expires'] : 0;
	}

	/**
	 * Is this token valid for the package?
	 *
	 * @param WPMIG_Package $package Package.
	 * @param string        $token   Token.
	 * @return bool
	 */
	public static function check( WPMIG_Package $package, $token ) {
		$t = isset( $package->data['transfer'] ) ? $package->data['transfer'] : null;
		if ( ! $t || $t['expires'] <= time() || ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return false;
		}
		return hash_equals( $t['hash'], hash( 'sha256', $token ) );
	}

	/**
	 * Import on this site: fetch the installer of the package named by a transfer
	 * link (created on the source site) and place it in the WordPress root.
	 *
	 * @param string $link Transfer link.
	 * @return string URL of the installer, with the link to prefill.
	 * @throws WPMIG_Exception On error.
	 */
	public static function prepare_import( $link ) {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			throw new WPMIG_Exception( 'Import impossible : les modifications de fichiers sont désactivées sur ce site (DISALLOW_FILE_MODS).' );
		}
		$link = trim( (string) $link );
		if ( ! preg_match( '#^https?://[^\s]+$#i', $link ) ) {
			throw new WPMIG_Exception( 'Collez le lien de transfert complet (il commence par https://).' );
		}
		$query = (string) wp_parse_url( $link, PHP_URL_QUERY );
		parse_str( $query, $args );
		if ( empty( $args['action'] ) || self::ACTION !== $args['action'] || empty( $args['id'] ) || ! preg_match( '/^\d{8}_\d{6}_[a-f0-9]{12}$/', $args['id'] ) || empty( $args['key'] ) || ! preg_match( '/^[a-f0-9]{32}$/', $args['key'] ) ) {
			throw new WPMIG_Exception( 'Ce n\'est pas un lien de transfert WP Migration : créez-le sur le site d\'origine (WP Migration > Sauvegardes > Transfert direct).' );
		}
		if ( 0 === strpos( strtok( $link, '?' ), admin_url( 'admin-ajax.php' ) ) && WPMIG_Package::load( $args['id'] ) ) {
			throw new WPMIG_Exception( 'Ce lien pointe vers ce site-ci : collez le lien créé sur le site d\'origine.' );
		}
		$link     = remove_query_arg( 'file', $link );
		$response = wp_remote_get(
			add_query_arg( 'file', 'installer', $link ),
			array(
				'timeout'     => 60,
				'redirection' => 5,
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new WPMIG_Exception( 'Site d\'origine injoignable : ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 403 === $code ) {
			throw new WPMIG_Exception( 'Lien refusé par le site d\'origine : il est invalide, expiré ou révoqué.' );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		// Only ever write the installer of the package named by the link.
		if ( 200 !== $code || 0 !== strpos( $body, '<?php' ) || false === strpos( $body, "define( 'WPMIG_INSTALLER'" ) || false === strpos( $body, "'package' => '" . $args['id'] . "'" ) ) {
			throw new WPMIG_Exception( 'Réponse inattendue du site d\'origine (HTTP ' . $code . ') : l\'installeur n\'a pas pu être récupéré.' );
		}
		$name = $args['id'] . '_installer.php';
		$file = WPMIG_Plugin::normalize( ABSPATH ) . '/' . $name;
		if ( false === @file_put_contents( $file, $body ) ) { // phpcs:ignore
			throw new WPMIG_Exception( 'Impossible d\'écrire ' . $name . ' à la racine du site : vérifiez les permissions.' );
		}
		return add_query_arg( 'source_url', rawurlencode( $link ), site_url( '/' . $name ) );
	}

	/**
	 * Send an error and stop.
	 *
	 * @param int    $code    HTTP status.
	 * @param string $message Message.
	 */
	private static function fail( $code, $message ) {
		status_header( $code );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo esc_html( $message );
		exit;
	}

	/**
	 * Serve the archive (or installer.php) of a package, with Range support.
	 */
	public static function serve() {
		// phpcs:disable WordPress.Security.NonceVerification -- authenticated by the secret key.
		$id    = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$which = isset( $_GET['file'] ) && 'installer' === $_GET['file'] ? 'installer' : 'archive';
		// phpcs:enable

		$package = WPMIG_Package::load( $id );
		if ( ! $package || 'complete' !== $package->data['status'] || ! self::check( $package, $key ) ) {
			// Same answer in every case: nothing to learn by probing.
			self::fail( 403, 'Lien de transfert invalide, expiré ou révoqué.' );
		}
		$path = 'installer' === $which ? $package->installer_path() : $package->archive_path();
		$name = 'installer' === $which ? 'installer.php' : $package->data['files']['archive'];
		if ( ! is_file( $path ) ) {
			self::fail( 404, 'Fichier de la sauvegarde introuvable.' );
		}

		$size  = (float) sprintf( '%u', filesize( $path ) );
		$start = 0;
		$end   = $size - 1;
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) ) : '';
		$part  = false;
		if ( '' !== $range ) {
			if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $m ) || ( '' === $m[1] && '' === $m[2] ) ) {
				self::range_error( $size );
			}
			if ( '' === $m[1] ) {
				$start = max( 0, $size - (float) $m[2] );
			} else {
				$start = (float) $m[1];
				$end   = '' === $m[2] ? $size - 1 : min( (float) $m[2], $size - 1 );
			}
			if ( $start > $end || $start >= $size ) {
				self::range_error( $size );
			}
			$part = true;
		}

		if ( 0.0 === (float) $start ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '?';
			$package->log( 'Transfert direct : téléchargement de ' . $name . ' par ' . $ip . '.' );
			$package->save();
		}

		WPMIG_Plugin::raise_limits();
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		$length = $end - $start + 1;
		status_header( $part ? 206 : 200 );
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . sprintf( '%.0f', $length ) );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( $part ) {
			header( sprintf( 'Content-Range: bytes %.0f-%.0f/%.0f', $start, $end, $size ) );
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) {
			exit;
		}
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $start > 0 ) {
			fseek( $fh, (int) $start );
		}
		$left = $length;
		while ( $left > 0 && ! feof( $fh ) && ! connection_aborted() ) {
			$chunk = fread( $fh, (int) min( 1048576, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput
			flush();
			$left -= strlen( $chunk );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * 416 Range Not Satisfiable.
	 *
	 * @param float $size File size.
	 */
	private static function range_error( $size ) {
		header( sprintf( 'Content-Range: bytes */%.0f', $size ) );
		self::fail( 416, 'Plage demandée invalide.' );
	}
}
