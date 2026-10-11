<?php
/**
 * Backups on an S3 compatible storage (Amazon S3, Infomaniak Swiss Backup / Public Cloud,
 * Scaleway, OVH, Wasabi, MinIO, Backblaze B2...). No SDK: requests are signed with
 * AWS Signature Version 4 and sent with the WordPress HTTP API. Large archives go up
 * in parts (multipart upload), a few parts per request, and resume after an interruption.
 *
 * Two sources: "own" (the settings of this site, the requests are signed here) or "ssm"
 * (storage provided by SSM: the secret key stays in SSM, which signs every single request
 * as a presigned URL through the SSM Connector plugin; this site only sends the request).
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * S3 storage.
 */
class WPMIG_S3 {

	const OPTION  = 'wpmig_s3';
	const STATE   = 'wpmig_s3_state';
	const HISTORY = 'wpmig_s3_history';

	/**
	 * Size of a part (S3 minimum: 5 MiB, except for the last one).
	 */
	const PART = 8388608;

	/**
	 * Files up to this size go up in one request.
	 */
	const SINGLE = 8388608;

	/**
	 * A job without progress for this long is dropped.
	 */
	const STALE = 604800;

	/**
	 * Message when the storage comes from SSM but the connector is not there.
	 */
	const MSG_NO_CONNECTOR = 'Le connecteur SSM n\'est pas installé ou n\'est pas configuré (adresse de SSM et jeton) : le stockage fourni par SSM est indisponible.';

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Settings as saved (the access keys can come from constants of wp-config.php).
	 * Whatever the source, this is what the form shows and what is saved back.
	 *
	 * @return array
	 */
	public static function local_settings() {
		$saved = get_option( self::OPTION );
		$s     = array_merge(
			array(
				'source'     => 'auto',
				'endpoint'   => '',
				'region'     => 'us-east-1',
				'bucket'     => '',
				'prefix'     => 'wp-migration',
				'access_key' => '',
				'secret_key' => '',
				'vhost'      => false,
				'keep'       => 5,
			),
			is_array( $saved ) ? $saved : array()
		);
		if ( defined( 'WPMIG_S3_ACCESS_KEY' ) && '' !== WPMIG_S3_ACCESS_KEY ) {
			$s['access_key'] = (string) WPMIG_S3_ACCESS_KEY;
		}
		if ( defined( 'WPMIG_S3_SECRET_KEY' ) && '' !== WPMIG_S3_SECRET_KEY ) {
			$s['secret_key'] = (string) WPMIG_S3_SECRET_KEY;
		}
		return $s;
	}

	/**
	 * Effective settings. With the storage provided by SSM there is no folder of our own
	 * (the keys are relative, SSM adds its own base) and no key is ever read.
	 *
	 * @return array
	 */
	public static function settings() {
		$s = self::local_settings();
		if ( 'ssm' === self::resolve_source( $s['source'], $s ) ) {
			$s['prefix']     = '';
			$s['access_key'] = '';
			$s['secret_key'] = '';
		}
		return $s;
	}

	/**
	 * Are the local settings complete?
	 *
	 * @param array $s Local settings.
	 * @return bool
	 */
	private static function local_complete( array $s ) {
		return '' !== $s['endpoint'] && '' !== $s['bucket'] && '' !== $s['access_key'] && '' !== $s['secret_key'];
	}

	/**
	 * Is the SSM Connector installed and configured?
	 *
	 * @return bool
	 */
	public static function ssm_available() {
		return (bool) apply_filters( 'ssm_connector_storage_available', false );
	}

	/**
	 * Source that a choice (auto, ssm, own) gives with the given local settings.
	 *
	 * @param string $choice auto, ssm or own.
	 * @param array  $s      Local settings.
	 * @return string own or ssm.
	 */
	public static function resolve_source( $choice, array $s ) {
		if ( 'ssm' === $choice || 'own' === $choice ) {
			return $choice;
		}
		if ( self::local_complete( $s ) ) {
			return 'own';
		}
		return self::ssm_available() ? 'ssm' : 'own';
	}

	/**
	 * Effective source: "own" (settings of this site) or "ssm" (storage provided by SSM).
	 *
	 * @return string
	 */
	public static function source() {
		$s = self::local_settings();
		return self::resolve_source( $s['source'], $s );
	}

	/**
	 * Are the settings complete (for the effective source)?
	 *
	 * @return bool
	 */
	public static function configured() {
		if ( 'ssm' === self::source() ) {
			return self::ssm_available();
		}
		return self::local_complete( self::local_settings() );
	}

	/**
	 * Save the settings (an empty secret key keeps the current one).
	 *
	 * @param array $in Raw values.
	 * @return array
	 * @throws WPMIG_Exception When invalid.
	 */
	public static function save_settings( array $in ) {
		$old      = self::local_settings();
		$source   = isset( $in['source'] ) ? (string) $in['source'] : $old['source'];
		if ( ! in_array( $source, array( 'auto', 'ssm', 'own' ), true ) ) {
			throw new WPMIG_Exception( 'Source du stockage invalide (auto, ssm ou own).' );
		}
		if ( 'ssm' === $source && $source !== $old['source'] && ! self::ssm_available() ) {
			throw new WPMIG_Exception( 'Le connecteur SSM n\'est pas installé ou n\'est pas configuré : le stockage fourni par SSM n\'est pas disponible.' );
		}
		$endpoint = isset( $in['endpoint'] ) ? trim( (string) $in['endpoint'] ) : '';
		if ( '' !== $endpoint && ! preg_match( '#^https?://[^\s/]+$#i', rtrim( $endpoint, '/' ) ) ) {
			throw new WPMIG_Exception( 'L\'adresse du service doit être de la forme https://s3.exemple.fr (sans chemin).' );
		}
		$bucket = isset( $in['bucket'] ) ? trim( (string) $in['bucket'] ) : '';
		if ( '' !== $bucket && ! preg_match( '/^[a-z0-9][a-z0-9.\-_]{1,62}$/i', $bucket ) ) {
			throw new WPMIG_Exception( 'Nom de bucket invalide (lettres, chiffres, points et tirets).' );
		}
		$region = isset( $in['region'] ) ? trim( (string) $in['region'] ) : '';
		$prefix = isset( $in['prefix'] ) ? trim( (string) $in['prefix'], " /\t\r\n" ) : '';
		if ( preg_match( '/[\x00-\x1f\\\\]|\.\./', $prefix ) ) {
			throw new WPMIG_Exception( 'Dossier invalide dans le bucket.' );
		}
		$s = array(
			'source'     => $source,
			'endpoint'   => rtrim( $endpoint, '/' ),
			'region'     => '' !== $region ? preg_replace( '/[^a-z0-9\-_]/i', '', $region ) : 'us-east-1',
			'bucket'     => $bucket,
			'prefix'     => $prefix,
			'access_key' => isset( $in['access_key'] ) ? trim( (string) $in['access_key'] ) : '',
			'secret_key' => isset( $in['secret_key'] ) && '' !== (string) $in['secret_key'] ? trim( (string) $in['secret_key'] ) : $old['secret_key'],
			'vhost'      => ! empty( $in['vhost'] ),
			'keep'       => isset( $in['keep'] ) ? max( 0, min( 1000, (int) $in['keep'] ) ) : 5,
		);
		// Keys defined in wp-config.php stay there.
		if ( defined( 'WPMIG_S3_ACCESS_KEY' ) ) {
			$s['access_key'] = '';
		}
		if ( defined( 'WPMIG_S3_SECRET_KEY' ) ) {
			$s['secret_key'] = '';
		}
		update_option( self::OPTION, $s, false );
		return self::settings();
	}

	/* ------------------------------------------------------------------ */
	/* Signature V4                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Encode a path: every segment is percent-encoded, slashes are kept.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function encode_path( $path ) {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}

	/**
	 * Canonical query string.
	 *
	 * @param array $query name => value.
	 * @return string
	 */
	public static function canonical_query( array $query ) {
		$pairs = array();
		foreach ( $query as $name => $value ) {
			$pairs[ rawurlencode( (string) $name ) ] = rawurlencode( (string) $value );
		}
		ksort( $pairs );
		$out = array();
		foreach ( $pairs as $name => $value ) {
			$out[] = $name . '=' . $value;
		}
		return implode( '&', $out );
	}

	/**
	 * Signing key.
	 *
	 * @param string $secret  Secret key.
	 * @param string $date    Y-m-d without dashes (20130524).
	 * @param string $region  Region.
	 * @return string Binary.
	 */
	private static function signing_key( $secret, $date, $region ) {
		$k = hash_hmac( 'sha256', $date, 'AWS4' . $secret, true );
		$k = hash_hmac( 'sha256', $region, $k, true );
		$k = hash_hmac( 'sha256', 's3', $k, true );
		return hash_hmac( 'sha256', 'aws4_request', $k, true );
	}

	/**
	 * Signature of a request with its headers (Authorization header).
	 *
	 * @param string $method       HTTP method.
	 * @param string $path         Encoded path (/bucket/key).
	 * @param array  $query        Query parameters.
	 * @param array  $headers      Headers to sign (name => value, host included).
	 * @param string $payload_hash SHA-256 of the body (hex).
	 * @param string $access       Access key.
	 * @param string $secret       Secret key.
	 * @param string $region       Region.
	 * @param string $stamp        Time (Ymd\THis\Z).
	 * @return array authorization, signed_headers.
	 */
	public static function sign( $method, $path, array $query, array $headers, $payload_hash, $access, $secret, $region, $stamp ) {
		$lower = array();
		foreach ( $headers as $name => $value ) {
			$lower[ strtolower( $name ) ] = trim( preg_replace( '/\s+/', ' ', (string) $value ) );
		}
		ksort( $lower );
		$canonical_headers = '';
		foreach ( $lower as $name => $value ) {
			$canonical_headers .= $name . ':' . $value . "\n";
		}
		$signed    = implode( ';', array_keys( $lower ) );
		$canonical = $method . "\n" . $path . "\n" . self::canonical_query( $query ) . "\n" . $canonical_headers . "\n" . $signed . "\n" . $payload_hash;
		$date      = substr( $stamp, 0, 8 );
		$scope     = $date . '/' . $region . '/s3/aws4_request';
		$to_sign   = "AWS4-HMAC-SHA256\n" . $stamp . "\n" . $scope . "\n" . hash( 'sha256', $canonical );
		$signature = hash_hmac( 'sha256', $to_sign, self::signing_key( $secret, $date, $region ) );
		return array(
			'authorization'  => 'AWS4-HMAC-SHA256 Credential=' . $access . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $signature,
			'signed_headers' => $signed,
			'signature'      => $signature,
		);
	}

	/**
	 * Location of an object: host, path, base URL.
	 *
	 * @param array  $s   Settings.
	 * @param string $key Key ('' for the bucket).
	 * @return array host, path (encoded), url.
	 */
	private static function target( array $s, $key ) {
		$parts  = parse_url( $s['endpoint'] );
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( ! empty( $s['vhost'] ) ) {
			$host = $s['bucket'] . '.' . $host;
			$path = '/' . self::encode_path( $key );
		} else {
			$path = '/' . rawurlencode( $s['bucket'] ) . ( '' !== $key ? '/' . self::encode_path( $key ) : '' );
		}
		return array(
			'host' => $host,
			'path' => $path,
			'url'  => $scheme . '://' . $host . $path,
		);
	}

	/**
	 * Presigned URL (GET by default), valid for a while, to download without the keys.
	 *
	 * @param string $key     Object key.
	 * @param int    $expires Seconds (max 7 days).
	 * @param string $stamp   Time (tests).
	 * @return string
	 */
	public static function presign( $key, $expires = 86400, $stamp = '' ) {
		if ( 'ssm' === self::source() ) {
			// SSM signs it: the secret key is not here.
			$signed = self::ssm_sign( 'GET', $key, array(), $expires );
			return $signed['url'];
		}
		$s      = self::settings();
		$stamp  = '' !== $stamp ? $stamp : gmdate( 'Ymd\THis\Z' );
		$t      = self::target( $s, $key );
		$scope  = substr( $stamp, 0, 8 ) . '/' . $s['region'] . '/s3/aws4_request';
		$query  = array(
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $s['access_key'] . '/' . $scope,
			'X-Amz-Date'          => $stamp,
			'X-Amz-Expires'       => (string) max( 1, min( 604800, (int) $expires ) ),
			'X-Amz-SignedHeaders' => 'host',
		);
		$sig    = self::sign_query( 'GET', $t['path'], $query, $t['host'], $s['secret_key'], $s['region'], $stamp );
		return $t['url'] . '?' . self::canonical_query( $query ) . '&X-Amz-Signature=' . $sig;
	}

	/**
	 * Signature of a query-signed request (host is the only signed header).
	 *
	 * @param string $method Method.
	 * @param string $path   Encoded path.
	 * @param array  $query  Query parameters (without the signature).
	 * @param string $host   Host header.
	 * @param string $secret Secret key.
	 * @param string $region Region.
	 * @param string $stamp  Time.
	 * @return string Hex signature.
	 */
	public static function sign_query( $method, $path, array $query, $host, $secret, $region, $stamp ) {
		$canonical = $method . "\n" . $path . "\n" . self::canonical_query( $query ) . "\nhost:" . $host . "\n\nhost\nUNSIGNED-PAYLOAD";
		$date      = substr( $stamp, 0, 8 );
		$scope     = $date . '/' . $region . '/s3/aws4_request';
		$to_sign   = "AWS4-HMAC-SHA256\n" . $stamp . "\n" . $scope . "\n" . hash( 'sha256', $canonical );
		return hash_hmac( 'sha256', $to_sign, self::signing_key( $secret, $date, $region ) );
	}

	/* ------------------------------------------------------------------ */
	/* Requests                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Send a request (signed here with our own keys, or signed by SSM).
	 *
	 * This is the only place where a request leaves: every operation goes through it.
	 *
	 * @param string $method  Method.
	 * @param string $key     Object key ('' for the bucket).
	 * @param array  $query   Query parameters.
	 * @param string $body    Body.
	 * @param array  $extra   Extra headers to send and sign (content-md5...).
	 * @param int    $timeout Seconds.
	 * @return array code, headers (lowercase), body, base (SSM: folder of this site, with the final slash).
	 * @throws WPMIG_Exception On network error or S3 error.
	 */
	private static function request( $method, $key, array $query = array(), $body = '', array $extra = array(), $timeout = 120 ) {
		if ( 'ssm' === self::source() ) {
			return self::request_ssm( $method, $key, $query, $body, $extra, $timeout );
		}
		$s = self::settings();
		if ( ! self::configured() ) {
			throw new WPMIG_Exception( 'Le stockage S3 n\'est pas configuré.' );
		}
		$t       = self::target( $s, $key );
		$stamp   = gmdate( 'Ymd\THis\Z' );
		$hash    = hash( 'sha256', $body );
		// WordPress would send a form content type: the object keeps the one we sign.
		if ( 'PUT' === $method && ! isset( $extra['content-type'] ) ) {
			$extra['content-type'] = 'application/octet-stream';
		}
		$headers = array_merge(
			$extra,
			array(
				'host'                 => $t['host'],
				'x-amz-content-sha256' => $hash,
				'x-amz-date'           => $stamp,
			)
		);
		$signed  = self::sign( $method, $t['path'], $query, $headers, $hash, $s['access_key'], $s['secret_key'], $s['region'], $stamp );
		unset( $headers['host'] );
		$headers['Authorization'] = $signed['authorization'];
		$url                      = $t['url'] . ( $query ? '?' . self::canonical_query( $query ) : '' );
		return self::send( $method, $url, $headers, $body, $timeout, false );
	}

	/**
	 * Send a request through the URL that SSM signed for this request only.
	 * Only the host is signed: no Authorization nor x-amz-* header is sent.
	 *
	 * @param string $method  Method.
	 * @param string $key     Relative key ('' for the bucket).
	 * @param array  $query   Query parameters.
	 * @param string $body    Body.
	 * @param array  $extra   Extra headers (only content-md5 and content-type are kept).
	 * @param int    $timeout Seconds.
	 * @return array code, headers, body, base.
	 * @throws WPMIG_Exception On error.
	 */
	private static function request_ssm( $method, $key, array $query, $body, array $extra, $timeout ) {
		if ( ! self::ssm_available() ) {
			throw new WPMIG_Exception( self::MSG_NO_CONNECTOR );
		}
		self::check_relative_key( $key );
		$signed  = self::ssm_sign( $method, $key, $query, 3600 );
		$headers = array();
		foreach ( $extra as $name => $value ) {
			$name = strtolower( $name );
			if ( 'content-md5' === $name ) {
				$headers['Content-MD5'] = $value;
			} elseif ( 'content-type' === $name ) {
				$headers['Content-Type'] = $value;
			}
		}
		// WordPress would send a form content type: the object keeps this one.
		if ( 'PUT' === $method && ! isset( $headers['Content-Type'] ) ) {
			$headers['Content-Type'] = 'application/octet-stream';
		}
		$out         = self::send( $method, $signed['url'], $headers, $body, $timeout, true );
		$out['base'] = $signed['base'];
		return $out;
	}

	/**
	 * Ask SSM (through the SSM Connector) for a presigned URL for one request.
	 *
	 * @param string $method  Method.
	 * @param string $key     Relative key ('' for the bucket).
	 * @param array  $query   Query parameters.
	 * @param int    $expires Seconds.
	 * @return array url, base (folder of this site in the bucket, '' or ending with a slash).
	 * @throws WPMIG_Exception When SSM refuses, is unreachable or the connector is absent.
	 */
	private static function ssm_sign( $method, $key, array $query, $expires ) {
		self::check_relative_key( $key );
		// PUT, POST, DELETE, HEAD and lists: 6 hours at most; the download of an object: 7 days.
		$max     = ( 'GET' === $method && '' !== $key ) ? 604800 : 21600;
		$expires = max( 1, min( $max, (int) $expires ) );
		$res     = apply_filters(
			'ssm_connector_storage_sign',
			null,
			array(
				'method'  => $method,
				'key'     => $key,
				'query'   => $query,
				'expires' => $expires,
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new WPMIG_Exception( self::scrub( (string) $res->get_error_message() ) );
		}
		if ( null === $res ) {
			throw new WPMIG_Exception( self::MSG_NO_CONNECTOR );
		}
		if ( ! is_array( $res ) || empty( $res['url'] ) || ! is_string( $res['url'] ) || ! preg_match( '#^https?://[^\s/]+#i', $res['url'] ) ) {
			throw new WPMIG_Exception( 'Réponse inattendue du connecteur SSM (adresse signée absente ou invalide).' );
		}
		if ( ! empty( $res['method'] ) && strtoupper( (string) $res['method'] ) !== $method ) {
			throw new WPMIG_Exception( 'Réponse inattendue du connecteur SSM (l\'adresse signée est pour une autre méthode que ' . $method . ').' );
		}
		$base = isset( $res['base'] ) && is_string( $res['base'] ) ? $res['base'] : null;
		if ( null !== $base && '' !== $base && '/' !== substr( $base, -1 ) ) {
			$base .= '/';
		}
		return array(
			'url'  => $res['url'],
			'base' => $base,
		);
	}

	/**
	 * Check a key against the rules of SSM: [A-Za-z0-9._-/], no "..", no "//", no leading slash, 400 characters at most.
	 *
	 * @param string $key Relative key ('' for the bucket).
	 * @throws WPMIG_Exception When the key is refused by the protocol.
	 */
	private static function check_relative_key( $key ) {
		if ( '' === $key ) {
			return;
		}
		if ( strlen( $key ) > 400 || ! preg_match( '#^[A-Za-z0-9._\-][A-Za-z0-9._\-/]*$#', $key ) || false !== strpos( $key, '..' ) || false !== strpos( $key, '//' ) ) {
			throw new WPMIG_Exception( 'Nom de fichier refusé par le stockage fourni par SSM (lettres, chiffres, points, tirets et barres obliques seulement, 400 caractères au plus).' );
		}
	}

	/**
	 * Remove the query of every URL in a text (it holds an identifier and a signature).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function scrub( $text ) {
		return preg_replace( '#(https?://[^\s"\'<>?]*)\?[^\s"\'<>]*#i', '$1?…', (string) $text );
	}

	/**
	 * Send the HTTP request and read the answer.
	 *
	 * @param string $method  Method.
	 * @param string $url     URL.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 * @param int    $timeout Seconds.
	 * @param bool   $ssm     The URL was signed by SSM: it never appears in a message.
	 * @return array code, headers (lowercase), body.
	 * @throws WPMIG_Exception On network error or S3 error.
	 */
	private static function send( $method, $url, array $headers, $body, $timeout, $ssm ) {
		$res = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => $timeout,
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'wpmig_s3_sslverify', true ),
				'user-agent'  => 'WP-Migration/' . WPMIG_VERSION . ' (s3)',
			)
		);
		if ( is_wp_error( $res ) ) {
			$message = (string) $res->get_error_message();
			if ( $ssm ) {
				$message = str_replace( $url, preg_replace( '/\?.*$/s', '', $url ) . '?…', $message );
				$message = self::scrub( $message );
			}
			throw new WPMIG_Exception( ( $ssm ? 'Stockage fourni par SSM injoignable : ' : 'Stockage S3 injoignable : ' ) . $message );
		}
		$code    = (int) wp_remote_retrieve_response_code( $res );
		$headers = array();
		foreach ( wp_remote_retrieve_headers( $res ) as $name => $value ) {
			$headers[ strtolower( $name ) ] = is_array( $value ) ? end( $value ) : (string) $value;
		}
		$out = array(
			'code'    => $code,
			'headers' => $headers,
			'body'    => (string) wp_remote_retrieve_body( $res ),
		);
		if ( $code >= 300 ) {
			throw new WPMIG_Exception( self::error_message( $code, $out['body'], $method ) );
		}
		return $out;
	}

	/**
	 * Message of an S3 error.
	 *
	 * @param int    $code   HTTP status.
	 * @param string $body   Body.
	 * @param string $method Method.
	 * @return string
	 */
	private static function error_message( $code, $body, $method ) {
		$text = '';
		if ( preg_match( '#<Code>([^<]*)</Code>#', $body, $c ) ) {
			$text = html_entity_decode( $c[1], ENT_QUOTES, 'UTF-8' );
			if ( preg_match( '#<Message>([^<]*)</Message>#', $body, $m ) ) {
				$text .= ' — ' . html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
			}
		}
		$hints = array(
			403 => 'Accès refusé : vérifiez la clé d\'accès, la clé secrète, la région et les droits sur le bucket.',
			404 => 'Introuvable : vérifiez le nom du bucket et l\'adresse du service.',
			301 => 'Mauvaise région ou mauvaise adresse de service pour ce bucket.',
		);
		if ( 'ssm' === self::source() ) {
			$hints = array(
				403 => 'Accès refusé : l\'adresse signée par SSM a été refusée ou a expiré (vérifiez l\'heure de ce serveur et le point de sauvegarde de ce site dans SSM).',
				404 => 'Introuvable : le point de sauvegarde ou le fichier n\'existe pas côté stockage.',
				301 => 'Le stockage a répondu par une redirection : vérifiez le point de sauvegarde dans SSM.',
			);
		}
		if ( '' === $text ) {
			$text = isset( $hints[ $code ] ) ? $hints[ $code ] : 'réponse HTTP ' . $code . ( 'HEAD' === $method ? ' (sans détail)' : '' );
		} elseif ( isset( $hints[ $code ] ) && 'SignatureDoesNotMatch' === strstr( $text, ' ', true ) ) {
			$text .= ' (' . $hints[ $code ] . ')';
		}
		return ( 'ssm' === self::source() ? 'Stockage fourni par SSM (HTTP ' : 'Stockage S3 (HTTP ' ) . $code . ') : ' . $text;
	}

	/**
	 * Try the connection: list, write, read back and delete a small object.
	 *
	 * @return array messages.
	 * @throws WPMIG_Exception On failure.
	 */
	public static function test() {
		if ( 'ssm' === self::source() ) {
			// No write here: the right to write is tested by the first upload. SSM answers for the list.
			$res = self::request( 'GET', '', array( 'list-type' => '2', 'max-keys' => '1' ), '', array(), 30 );
			if ( false === strpos( $res['body'], '<ListBucketResult' ) ) {
				throw new WPMIG_Exception( 'Réponse inattendue du stockage fourni par SSM à la liste des fichiers.' );
			}
			return array(
				'Stockage fourni par SSM accessible : adresse signée obtenue et liste des fichiers réussie.',
				'Les clés restent dans SSM' . ( ! empty( $res['base'] ) ? ' ; dossier de ce site côté stockage : « ' . $res['base'] . ' »' : '' ) . '.',
			);
		}
		$s   = self::settings();
		$key = self::key( $s, 'test-' . WPMIG_Package::random_hex( 6 ) . '.txt' );
		self::request( 'GET', '', array( 'list-type' => '2', 'max-keys' => '1' ), '', array(), 30 );
		$data = 'wp-migration ' . gmdate( 'c' );
		self::request( 'PUT', $key, array(), $data, array(), 30 );
		$back = self::request( 'GET', $key, array(), '', array(), 30 );
		self::request( 'DELETE', $key, array(), '', array(), 30 );
		if ( $back['body'] !== $data ) {
			throw new WPMIG_Exception( 'Le fichier de test relu ne correspond pas à celui envoyé.' );
		}
		return array(
			'Bucket « ' . $s['bucket'] . ' » accessible.',
			'Écriture, lecture et suppression réussies dans « ' . ( '' !== $s['prefix'] ? $s['prefix'] : '/' ) . ' ».',
		);
	}

	/**
	 * Full key of a file under the prefix.
	 *
	 * @param array  $s    Settings.
	 * @param string $name Relative name.
	 * @return string
	 */
	private static function key( array $s, $name ) {
		return ( '' !== $s['prefix'] ? $s['prefix'] . '/' : '' ) . ltrim( $name, '/' );
	}

	/**
	 * Objects under a prefix.
	 *
	 * @param string $prefix Key prefix ('' for the configured folder).
	 * @return array key, size, modified.
	 * @throws WPMIG_Exception On error.
	 */
	public static function list_objects( $prefix = '' ) {
		$s     = self::settings();
		$ssm   = 'ssm' === self::source();
		$base  = '' !== $prefix ? $prefix : ( '' !== $s['prefix'] ? $s['prefix'] . '/' : '' );
		$out   = array();
		$token = '';
		do {
			$query = array( 'list-type' => '2' );
			// SSM: the prefix is relative, SSM puts its own base in front of it.
			if ( ! $ssm || '' !== $base ) {
				$query['prefix'] = $base;
			}
			if ( '' !== $token ) {
				$query['continuation-token'] = $token;
			}
			$res   = self::request( 'GET', '', $query, '', array(), 60 );
			$token = '';
			$strip = '';
			if ( $ssm ) {
				// The answer holds the complete keys, base included: they are compared and used without it.
				if ( ! isset( $res['base'] ) || null === $res['base'] ) {
					throw new WPMIG_Exception( 'Réponse inattendue du connecteur SSM (dossier de base absent).' );
				}
				$strip = $res['base'];
			}
			if ( preg_match_all( '#<Contents>(.*?)</Contents>#s', $res['body'], $items ) ) {
				foreach ( $items[1] as $item ) {
					if ( preg_match( '#<Key>(.*?)</Key>#s', $item, $k ) ) {
						$key = html_entity_decode( $k[1], ENT_QUOTES, 'UTF-8' );
						if ( '' !== $strip ) {
							if ( 0 !== strpos( $key, $strip ) ) {
								continue;
							}
							$key = substr( $key, strlen( $strip ) );
						}
						$out[] = array(
							'key'      => $key,
							'size'     => preg_match( '#<Size>(\d+)</Size>#', $item, $z ) ? (int) $z[1] : 0,
							'modified' => preg_match( '#<LastModified>([^<]*)</LastModified>#', $item, $d ) ? $d[1] : '',
						);
					}
				}
			}
			if ( false !== strpos( $res['body'], '<IsTruncated>true</IsTruncated>' ) && preg_match( '#<NextContinuationToken>([^<]*)</NextContinuationToken>#', $res['body'], $n ) ) {
				$token = html_entity_decode( $n[1], ENT_QUOTES, 'UTF-8' );
			}
		} while ( '' !== $token && count( $out ) < 50000 );
		return $out;
	}

	/**
	 * Delete an object.
	 *
	 * @param string $key Key.
	 * @throws WPMIG_Exception On error.
	 */
	public static function delete_object( $key ) {
		self::request( 'DELETE', $key, array(), '', array(), 60 );
	}

	/* ------------------------------------------------------------------ */
	/* Upload job                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Current job.
	 *
	 * @return array|null
	 */
	public static function job() {
		$job = get_option( self::STATE );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Save the job.
	 *
	 * @param array|null $job Job (null forgets it).
	 */
	private static function save_job( $job ) {
		if ( null === $job ) {
			delete_option( self::STATE );
			return;
		}
		$job['touched'] = time();
		update_option( self::STATE, $job, false );
	}

	/**
	 * History of the uploads: package id => data.
	 *
	 * @return array
	 */
	public static function history() {
		$h = get_option( self::HISTORY );
		return is_array( $h ) ? $h : array();
	}

	/**
	 * Start the upload of a backup (archive and installer).
	 *
	 * @param string $id Backup id.
	 * @return array Public state.
	 * @throws WPMIG_Exception When it cannot start.
	 */
	public static function start( $id ) {
		if ( ! self::configured() ) {
			throw new WPMIG_Exception( 'ssm' === self::source() ? self::MSG_NO_CONNECTOR : 'Le stockage S3 n\'est pas configuré (Réglages → Stockage S3).' );
		}
		$job = self::job();
		if ( $job && 'running' === $job['status'] && time() - (int) $job['touched'] < self::STALE ) {
			if ( $job['package'] === $id ) {
				return self::public_state( $job );
			}
			throw new WPMIG_Exception( 'Un envoi est déjà en cours (sauvegarde ' . $job['package'] . ').' );
		}
		$package = WPMIG_Package::load( $id );
		if ( ! $package || 'complete' !== $package->data['status'] || ! is_file( $package->archive_path() ) || ! is_file( $package->installer_path() ) ) {
			throw new WPMIG_Exception( 'Cette sauvegarde n\'est pas complète ou ses fichiers sont introuvables.' );
		}
		$s     = self::settings();
		$files = array();
		foreach ( array( $package->installer_path(), $package->archive_path() ) as $path ) {
			$files[] = array(
				'path'   => $path,
				'key'    => self::key( $s, $package->data['id'] . '/' . basename( $path ) ),
				'size'   => (int) filesize( $path ),
				'sent'   => 0,
				'done'   => false,
				'upload' => '',
				'parts'  => array(),
			);
		}
		$job = array(
			'package' => $package->data['id'],
			'source'  => self::source(),
			'status'  => 'running',
			'started' => time(),
			'files'   => $files,
			'error'   => '',
			'retries' => 0,
		);
		self::save_job( $job );
		return self::public_state( $job );
	}

	/**
	 * State for the interface.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	public static function public_state( array $job ) {
		$total = 0;
		$sent  = 0;
		foreach ( $job['files'] as $f ) {
			$total += $f['size'];
			$sent  += $f['done'] ? $f['size'] : $f['sent'];
		}
		$current = '';
		foreach ( $job['files'] as $f ) {
			if ( ! $f['done'] ) {
				$current = basename( $f['path'] );
				break;
			}
		}
		return array(
			'package'  => $job['package'],
			'status'   => $job['status'],
			'progress' => $total ? (int) ( 100 * $sent / $total ) : 100,
			'sent'     => $sent,
			'total'    => $total,
			'message'  => 'running' === $job['status'] ? 'Envoi vers S3 : ' . $current . ' (' . size_format( $sent, 1 ) . ' / ' . size_format( $total, 1 ) . ')' : ( 'done' === $job['status'] ? 'Sauvegarde envoyée sur S3 (' . size_format( $total, 1 ) . ').' : $job['error'] ),
			'error'    => $job['error'],
		);
	}

	/**
	 * Upload for a while (time budget), then save the state.
	 *
	 * @param float $budget Seconds.
	 * @return array Public state.
	 */
	public static function step( $budget ) {
		$job = self::job();
		if ( ! $job ) {
			return array( 'package' => '', 'status' => 'none', 'progress' => 0, 'sent' => 0, 'total' => 0, 'message' => '', 'error' => '' );
		}
		if ( 'running' !== $job['status'] ) {
			return self::public_state( $job );
		}
		// The keys of a job depend on the source (own: with our folder, ssm: relative).
		if ( ( isset( $job['source'] ) ? $job['source'] : 'own' ) !== self::source() ) {
			$job['status'] = 'error';
			$job['error']  = 'La source du stockage a changé pendant l\'envoi : abandonnez cet envoi puis relancez-le.';
			self::save_job( $job );
			return self::public_state( $job );
		}
		WPMIG_Plugin::raise_limits();
		$deadline = microtime( true ) + max( 3, (float) $budget );
		try {
			while ( true ) {
				$index = null;
				foreach ( $job['files'] as $i => $f ) {
					if ( ! $f['done'] ) {
						$index = $i;
						break;
					}
				}
				if ( null === $index ) {
					$job['status'] = 'done';
					self::finish( $job );
					break;
				}
				self::upload_some( $job, $index, $deadline );
				$job['retries'] = 0;
				self::save_job( $job );
				if ( ! $job['files'][ $index ]['done'] && microtime( true ) >= $deadline ) {
					break;
				}
			}
		} catch ( WPMIG_Exception $e ) {
			// A network hiccup is retried a few times before giving up: the upload resumes where it was.
			$job['retries'] = (int) $job['retries'] + 1;
			if ( $job['retries'] >= 4 ) {
				$job['status'] = 'error';
				$job['error']  = $e->getMessage();
			}
		}
		self::save_job( $job );
		return self::public_state( $job );
	}

	/**
	 * Run the whole job (WP-CLI, scheduled backups).
	 *
	 * @param callable|null $progress Receives the public state.
	 * @return array Public state.
	 */
	public static function run( $progress = null ) {
		do {
			$state = self::step( 20 );
			if ( $progress ) {
				call_user_func( $progress, $state );
			}
			if ( 'running' === $state['status'] ) {
				usleep( 500000 );
			}
		} while ( 'running' === $state['status'] );
		return $state;
	}

	/**
	 * Forget the job and abort its multipart uploads.
	 */
	public static function cancel() {
		$job = self::job();
		if ( $job ) {
			foreach ( $job['files'] as $f ) {
				if ( ! $f['done'] && '' !== $f['upload'] ) {
					try {
						self::request( 'DELETE', $f['key'], array( 'uploadId' => $f['upload'] ), '', array(), 30 );
					} catch ( WPMIG_Exception $e ) {
						continue;
					}
				}
			}
		}
		self::save_job( null );
	}

	/**
	 * Upload the next parts of a file.
	 *
	 * @param array $job      Job (by reference: saved after every part, an interrupted upload resumes there).
	 * @param int   $index    File index.
	 * @param float $deadline Microtime.
	 * @throws WPMIG_Exception On error.
	 */
	private static function upload_some( array &$job, $index, $deadline ) {
		$file = &$job['files'][ $index ];
		if ( ! is_file( $file['path'] ) ) {
			throw new WPMIG_Exception( 'Fichier introuvable : ' . basename( $file['path'] ) );
		}
		$part_size = (int) apply_filters( 'wpmig_s3_part_size', self::PART );
		$part_size = max( 5242880, $part_size, (int) ceil( $file['size'] / 9000 ) );
		$fh        = fopen( $file['path'], 'rb' );
		if ( ! $fh ) {
			throw new WPMIG_Exception( 'Lecture impossible : ' . basename( $file['path'] ) );
		}
		try {
			if ( $file['size'] <= max( self::SINGLE, $part_size ) && '' === $file['upload'] ) {
				$data = (string) stream_get_contents( $fh );
				$md5  = md5( $data );
				$res  = self::request( 'PUT', $file['key'], array(), $data, array( 'content-md5' => base64_encode( md5( $data, true ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				$etag = isset( $res['headers']['etag'] ) ? trim( $res['headers']['etag'], '"' ) : '';
				if ( preg_match( '/^[a-f0-9]{32}$/', $etag ) && $etag !== $md5 ) {
					throw new WPMIG_Exception( 'Fichier ' . basename( $file['path'] ) . ' : l\'empreinte reçue par S3 ne correspond pas (transfert altéré).' );
				}
				$file['sent'] = $file['size'];
				$file['done'] = true;
				return;
			}
			if ( '' === $file['upload'] ) {
				$res = self::request( 'POST', $file['key'], array( 'uploads' => '' ), '', array( 'content-type' => 'application/octet-stream' ), 60 );
				if ( ! preg_match( '#<UploadId>([^<]+)</UploadId>#', $res['body'], $m ) ) {
					throw new WPMIG_Exception( 'Réponse inattendue du stockage S3 à l\'ouverture de l\'envoi par morceaux.' );
				}
				$file['upload'] = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
				$file['parts']  = array();
				$file['sent']   = 0;
				self::save_job( $job );
			}
			while ( $file['sent'] < $file['size'] ) {
				fseek( $fh, $file['sent'] );
				$data = (string) fread( $fh, $part_size );
				if ( '' === $data ) {
					throw new WPMIG_Exception( 'Lecture incomplète de ' . basename( $file['path'] ) . '.' );
				}
				$number = count( $file['parts'] ) + 1;
				$md5    = md5( $data );
				$res    = self::request(
					'PUT',
					$file['key'],
					array( 'partNumber' => (string) $number, 'uploadId' => $file['upload'] ),
					$data,
					array( 'content-md5' => base64_encode( md5( $data, true ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
					300
				);
				$etag   = isset( $res['headers']['etag'] ) ? trim( $res['headers']['etag'], '"' ) : '';
				if ( '' === $etag ) {
					throw new WPMIG_Exception( 'Le stockage S3 n\'a pas renvoyé l\'empreinte du morceau ' . $number . '.' );
				}
				$file['parts'][] = array( $number, $etag, $md5 );
				$file['sent']   += strlen( $data );
				unset( $data );
				self::save_job( $job );
				if ( microtime( true ) >= $deadline && $file['sent'] < $file['size'] ) {
					return;
				}
			}
			$xml = '<CompleteMultipartUpload>';
			$bin = '';
			foreach ( $file['parts'] as $p ) {
				$xml .= '<Part><PartNumber>' . (int) $p[0] . '</PartNumber><ETag>"' . $p[1] . '"</ETag></Part>';
				$bin .= pack( 'H*', $p[2] );
			}
			$xml .= '</CompleteMultipartUpload>';
			$res  = self::request( 'POST', $file['key'], array( 'uploadId' => $file['upload'] ), $xml, array( 'content-type' => 'application/xml' ), 300 );
			// A 200 can still carry an error document.
			if ( false !== strpos( $res['body'], '<Error>' ) ) {
				throw new WPMIG_Exception( self::error_message( 500, $res['body'], 'POST' ) );
			}
			// The final tag is the MD5 of the parts' MD5s, followed by their number: it proves the assembly.
			$expect = md5( $bin ) . '-' . count( $file['parts'] );
			if ( preg_match( '#<ETag>(?:&quot;|")?([a-f0-9]{32}-\d+)(?:&quot;|")?</ETag>#', $res['body'], $m ) && $m[1] !== $expect ) {
				throw new WPMIG_Exception( 'Fichier ' . basename( $file['path'] ) . ' : l\'empreinte finale reçue par S3 ne correspond pas (envoi altéré).' );
			}
			$file['done']   = true;
			$file['upload'] = '';
			$file['parts']  = array();
		} finally {
			fclose( $fh );
		}
	}

	/**
	 * End of a job: history, remote retention.
	 *
	 * @param array $job Job.
	 */
	private static function finish( array $job ) {
		$history                    = self::history();
		$history[ $job['package'] ] = array(
			'time'   => time(),
			'source' => isset( $job['source'] ) ? $job['source'] : 'own',
			'files'  => array_map(
				function ( $f ) {
					return array( 'key' => $f['key'], 'size' => $f['size'] );
				},
				$job['files']
			),
		);
		$history = array_slice( $history, -100, null, true );
		update_option( self::HISTORY, $history, false );
		try {
			self::prune();
		} catch ( WPMIG_Exception $e ) {
			// Retention is best effort: the backup itself is on S3.
			return;
		}
	}

	/**
	 * Keep the N most recent backups on S3, delete the older ones (only the ones
	 * written by this plugin: folders named like a backup id). Nothing is deleted when
	 * the storage is provided by SSM.
	 *
	 * @return int Number of backups deleted.
	 * @throws WPMIG_Exception On error.
	 */
	public static function prune() {
		// With the storage provided by SSM, SSM decides what is kept: nothing is deleted from here.
		if ( 'ssm' === self::source() ) {
			return 0;
		}
		$s = self::settings();
		if ( $s['keep'] < 1 ) {
			return 0;
		}
		$base    = '' !== $s['prefix'] ? $s['prefix'] . '/' : '';
		$folders = array();
		foreach ( self::list_objects() as $o ) {
			$rest = substr( $o['key'], strlen( $base ) );
			if ( preg_match( '#^(\d{8}_\d{6}_[a-f0-9]{12})/[^/]+$#', $rest, $m ) ) {
				$folders[ $m[1] ][] = $o['key'];
			}
		}
		krsort( $folders );
		$deleted = 0;
		foreach ( array_slice( $folders, $s['keep'], null, true ) as $id => $keys ) {
			foreach ( $keys as $key ) {
				self::delete_object( $key );
			}
			$deleted++;
		}
		return $deleted;
	}

	/**
	 * Download links (installer and archive) of a backup sent to S3.
	 *
	 * @param string $id    Backup id.
	 * @param int    $hours Validity.
	 * @return array installer, archive (URLs), expires.
	 * @throws WPMIG_Exception When the backup is not on S3.
	 */
	public static function links( $id, $hours = 24 ) {
		$history = self::history();
		if ( empty( $history[ $id ] ) ) {
			throw new WPMIG_Exception( 'Cette sauvegarde n\'a pas été envoyée sur S3.' );
		}
		$where = isset( $history[ $id ]['source'] ) ? $history[ $id ]['source'] : 'own';
		if ( self::source() !== $where ) {
			throw new WPMIG_Exception( 'Cette sauvegarde a été envoyée avec ' . ( 'ssm' === $where ? 'le stockage fourni par SSM' : 'les réglages S3 de ce site' ) . ' : choisissez cette source (Réglages → Stockage S3) pour obtenir ses liens.' );
		}
		$out = array( 'installer' => '', 'archive' => '' );
		foreach ( $history[ $id ]['files'] as $f ) {
			$out[ preg_match( '/\.php$/', $f['key'] ) ? 'installer' : 'archive' ] = self::presign( $f['key'], max( 1, min( 168, (int) $hours ) ) * 3600 );
		}
		$out['expires'] = time() + max( 1, min( 168, (int) $hours ) ) * 3600;
		return $out;
	}
}
