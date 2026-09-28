<?php
/**
 * WPMIG archive format (.wpmig): a simple, streamable and resumable archive format.
 *
 * This file has no WordPress dependency: it is shared by the plugin and by the
 * standalone installer.
 *
 * Layout:
 *   entry  := "WMF1" type(1 byte: 'f' file | 'd' dir) pathlen(N) mtime(N) perms(N) path blocks*
 *   blocks := ( storedlen(N) flag(1 byte: 0 raw | 1 deflate) crc32-of-raw-data(N) data )* terminator
 *   terminator := storedlen = 0, flag = 0, crc = 0
 *   trailer := "WMFE" json jsonlen(N) "WMFZ"
 *
 * Every block carries its own CRC32 so corruption (bad FTP transfer, truncated
 * upload...) is detected, and an entry can be written or read across several
 * HTTP requests without keeping any hashing state.
 *
 * @package WPMigration
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPMIG_INSTALLER' ) ) {
	exit;
}

if ( ! class_exists( 'WPMIG_Exception' ) ) {
	/**
	 * Exception thrown by the migration engine.
	 */
	class WPMIG_Exception extends Exception {
	}
}

if ( ! class_exists( 'WPMIG_Archive' ) ) {

	/**
	 * Archive constants and helpers.
	 */
	class WPMIG_Archive {
		const ENTRY_MAGIC   = 'WMF1';
		const TRAILER_MAGIC = 'WMFE';
		const TRAILER_END   = 'WMFZ';
		const BLOCK_SIZE    = 1048576;
		const FLAG_RAW      = 0;
		const FLAG_DEFLATE  = 1;

		/**
		 * Reserved folder for package metadata inside the archive.
		 */
		const META_DIR = '__wpmig__';

		/**
		 * File extensions that are already compressed: storing them raw saves CPU.
		 *
		 * @var array
		 */
		public static $no_compress = array(
			'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'ico', 'mp3', 'mp4', 'm4a', 'm4v', 'mov', 'avi',
			'mkv', 'webm', 'ogg', 'ogv', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'woff', 'woff2', 'pdf',
			'wpmig', 'jar', 'phar', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'flac', 'wav', 'wmv', 'br',
		);

		/**
		 * Should a file with this path be compressed?
		 *
		 * @param string $path File path.
		 * @return bool
		 */
		public static function should_compress( $path ) {
			if ( ! function_exists( 'gzdeflate' ) ) {
				return false;
			}
			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			return ! in_array( $ext, self::$no_compress, true );
		}

		/**
		 * Check that a relative path from an archive is safe to extract.
		 *
		 * @param string $path Relative path.
		 * @return bool
		 */
		public static function is_safe_path( $path ) {
			if ( '' === $path || false !== strpos( $path, "\0" ) ) {
				return false;
			}
			if ( '/' === $path[0] || '\\' === $path[0] || preg_match( '#^[a-zA-Z]:#', $path ) ) {
				return false;
			}
			foreach ( preg_split( '#[/\\\\]#', $path ) as $part ) {
				if ( '..' === $part ) {
					return false;
				}
			}
			return true;
		}

		/**
		 * Read the trailer at the end of an archive. Returns null when the archive is
		 * incomplete (interrupted build or truncated transfer).
		 *
		 * @param string $file Archive path.
		 * @return array|null
		 */
		public static function read_trailer( $file ) {
			$size = @filesize( $file );
			if ( ! $size || $size < 12 ) {
				return null;
			}
			$fh = @fopen( $file, 'rb' );
			if ( ! $fh ) {
				return null;
			}
			fseek( $fh, -8, SEEK_END );
			$tail = fread( $fh, 8 );
			if ( strlen( $tail ) !== 8 || substr( $tail, 4 ) !== self::TRAILER_END ) {
				fclose( $fh );
				return null;
			}
			$len = unpack( 'N', substr( $tail, 0, 4 ) );
			$len = $len[1];
			if ( $len <= 0 || $len > 10485760 || $len + 12 > $size ) {
				fclose( $fh );
				return null;
			}
			fseek( $fh, -( $len + 12 ), SEEK_END );
			$data = fread( $fh, $len + 4 );
			fclose( $fh );
			if ( substr( $data, 0, 4 ) !== self::TRAILER_MAGIC ) {
				return null;
			}
			$json = json_decode( substr( $data, 4 ), true );
			return is_array( $json ) ? $json : null;
		}
	}
}

if ( ! class_exists( 'WPMIG_Archive_Writer' ) ) {

	/**
	 * Resumable archive writer.
	 */
	class WPMIG_Archive_Writer {

		/**
		 * File handle.
		 *
		 * @var resource
		 */
		private $fh;

		/**
		 * Open the archive and truncate it at $offset (last committed position), so an
		 * interrupted request never leaves a partial block behind.
		 *
		 * @param string $file   Archive path.
		 * @param int    $offset Committed size.
		 * @throws WPMIG_Exception When the file can't be opened.
		 */
		public function __construct( $file, $offset = 0 ) {
			$this->fh = @fopen( $file, 'c+b' );
			if ( ! $this->fh ) {
				throw new WPMIG_Exception( 'Impossible d\'ouvrir l\'archive en écriture : ' . $file );
			}
			ftruncate( $this->fh, $offset );
			fseek( $this->fh, 0, SEEK_END );
		}

		/**
		 * Write raw bytes.
		 *
		 * @param string $data Bytes.
		 * @throws WPMIG_Exception On disk full / write error.
		 */
		private function write( $data ) {
			$len     = strlen( $data );
			$written = 0;
			while ( $written < $len ) {
				$res = fwrite( $this->fh, 0 === $written ? $data : substr( $data, $written ) );
				if ( false === $res || 0 === $res ) {
					throw new WPMIG_Exception( 'Erreur d\'écriture de l\'archive (espace disque insuffisant ?).' );
				}
				$written += $res;
			}
		}

		/**
		 * Start a file or directory entry.
		 *
		 * @param string $path  Relative path (forward slashes).
		 * @param string $type  'f' or 'd'.
		 * @param int    $mtime Modification time.
		 * @param int    $perms Permissions.
		 */
		public function begin_entry( $path, $type = 'f', $mtime = 0, $perms = 0 ) {
			$this->write( WPMIG_Archive::ENTRY_MAGIC . $type . pack( 'NNN', strlen( $path ), (int) $mtime, (int) $perms & 0xFFF ) . $path );
		}

		/**
		 * Write a data block of the current file entry.
		 *
		 * @param string $data     Raw data (should be <= BLOCK_SIZE).
		 * @param bool   $compress Try to deflate the block.
		 */
		public function write_block( $data, $compress ) {
			if ( '' === $data ) {
				return;
			}
			$flag   = WPMIG_Archive::FLAG_RAW;
			$stored = $data;
			if ( $compress ) {
				$deflated = gzdeflate( $data, 6 );
				if ( false !== $deflated && strlen( $deflated ) < strlen( $data ) ) {
					$stored = $deflated;
					$flag   = WPMIG_Archive::FLAG_DEFLATE;
				}
			}
			$this->write( pack( 'N', strlen( $stored ) ) . chr( $flag ) . pack( 'N', crc32( $data ) ) . $stored );
		}

		/**
		 * Terminate the current file entry.
		 */
		public function end_entry() {
			$this->write( pack( 'N', 0 ) . chr( 0 ) . pack( 'N', 0 ) );
		}

		/**
		 * Add a complete file entry from a string.
		 *
		 * @param string $path     Relative path.
		 * @param string $data     Contents.
		 * @param bool   $compress Compress.
		 */
		public function add_string( $path, $data, $compress = true ) {
			$this->begin_entry( $path, 'f', time(), 0644 );
			$len = strlen( $data );
			for ( $i = 0; $i < $len; $i += WPMIG_Archive::BLOCK_SIZE ) {
				$this->write_block( substr( $data, $i, WPMIG_Archive::BLOCK_SIZE ), $compress && function_exists( 'gzdeflate' ) );
			}
			$this->end_entry();
		}

		/**
		 * Write the trailer that marks the archive as complete.
		 *
		 * @param array $meta Trailer data.
		 */
		public function finish( array $meta ) {
			$json = json_encode( $meta );
			$this->write( WPMIG_Archive::TRAILER_MAGIC . $json . pack( 'N', strlen( $json ) ) . WPMIG_Archive::TRAILER_END );
		}

		/**
		 * Current (committed) size.
		 *
		 * @return int
		 */
		public function tell() {
			fflush( $this->fh );
			return ftell( $this->fh );
		}

		/**
		 * Close.
		 */
		public function close() {
			if ( $this->fh ) {
				fflush( $this->fh );
				fclose( $this->fh );
				$this->fh = null;
			}
		}
	}
}

if ( ! class_exists( 'WPMIG_Archive_Reader' ) ) {

	/**
	 * Resumable archive reader.
	 */
	class WPMIG_Archive_Reader {

		/**
		 * File handle.
		 *
		 * @var resource
		 */
		private $fh;

		/**
		 * Archive path.
		 *
		 * @var string
		 */
		private $file;

		/**
		 * Open an archive.
		 *
		 * @param string $file   Archive path.
		 * @param int    $offset Offset to start reading from.
		 * @throws WPMIG_Exception When the file can't be opened.
		 */
		public function __construct( $file, $offset = 0 ) {
			$this->file = $file;
			$this->fh   = @fopen( $file, 'rb' );
			if ( ! $this->fh ) {
				throw new WPMIG_Exception( 'Impossible d\'ouvrir l\'archive : ' . $file );
			}
			if ( $offset > 0 && 0 !== fseek( $this->fh, $offset ) ) {
				throw new WPMIG_Exception( 'Positionnement impossible dans l\'archive (archive > 2 Go sur un PHP 32 bits ?).' );
			}
		}

		/**
		 * Read exactly $len bytes.
		 *
		 * @param int $len Length.
		 * @return string
		 * @throws WPMIG_Exception On truncated archive.
		 */
		private function read( $len ) {
			$data = '';
			while ( strlen( $data ) < $len ) {
				$chunk = fread( $this->fh, $len - strlen( $data ) );
				if ( false === $chunk || '' === $chunk ) {
					throw new WPMIG_Exception( 'Archive tronquée ou corrompue (fin de fichier inattendue à l\'octet ' . ftell( $this->fh ) . ').' );
				}
				$data .= $chunk;
			}
			return $data;
		}

		/**
		 * Read the next entry header.
		 *
		 * @return array|null Entry (type, path, mtime, perms) or null at the end of the archive.
		 * @throws WPMIG_Exception On corrupted archive.
		 */
		public function next_entry() {
			$offset = ftell( $this->fh );
			$magic  = $this->read( 4 );
			if ( WPMIG_Archive::TRAILER_MAGIC === $magic ) {
				return null;
			}
			if ( WPMIG_Archive::ENTRY_MAGIC !== $magic ) {
				throw new WPMIG_Exception( 'Archive corrompue : en-tête invalide à l\'octet ' . $offset . '.' );
			}
			$type = $this->read( 1 );
			$head = unpack( 'Nlen/Nmtime/Nperms', $this->read( 12 ) );
			if ( $head['len'] <= 0 || $head['len'] > 65535 ) {
				throw new WPMIG_Exception( 'Archive corrompue : longueur de chemin invalide à l\'octet ' . $offset . '.' );
			}
			return array(
				'type'  => $type,
				'path'  => $this->read( $head['len'] ),
				'mtime' => $head['mtime'],
				'perms' => $head['perms'],
			);
		}

		/**
		 * Read the next block of the current file entry.
		 *
		 * @return string|false Data, or false at the end of the entry.
		 * @throws WPMIG_Exception On CRC mismatch.
		 */
		public function next_block() {
			$head = unpack( 'Nlen/Cflag/Ncrc', $this->read( 9 ) );
			if ( 0 === $head['len'] ) {
				return false;
			}
			if ( $head['len'] > WPMIG_Archive::BLOCK_SIZE * 2 ) {
				throw new WPMIG_Exception( 'Archive corrompue : bloc de taille invalide.' );
			}
			$data = $this->read( $head['len'] );
			if ( WPMIG_Archive::FLAG_DEFLATE === $head['flag'] ) {
				if ( ! function_exists( 'gzinflate' ) ) {
					throw new WPMIG_Exception( 'L\'extension PHP zlib est requise pour décompresser cette archive.' );
				}
				$data = @gzinflate( $data );
				if ( false === $data ) {
					throw new WPMIG_Exception( 'Archive corrompue : bloc compressé illisible.' );
				}
			}
			if ( pack( 'N', crc32( $data ) ) !== pack( 'N', $head['crc'] ) ) {
				throw new WPMIG_Exception( 'Archive corrompue : somme de contrôle CRC invalide (transfert FTP en mode ASCII ou incomplet ?).' );
			}
			return $data;
		}

		/**
		 * Read a whole (small) file entry into memory.
		 *
		 * @return string
		 */
		public function read_all_blocks() {
			$data = '';
			while ( false !== ( $block = $this->next_block() ) ) {
				$data .= $block;
			}
			return $data;
		}

		/**
		 * Current offset.
		 *
		 * @return int
		 */
		public function tell() {
			return ftell( $this->fh );
		}

		/**
		 * Close.
		 */
		public function close() {
			if ( $this->fh ) {
				fclose( $this->fh );
				$this->fh = null;
			}
		}
	}
}
