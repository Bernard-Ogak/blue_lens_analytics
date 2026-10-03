<?php
/**
 * Local GeoIP database download and storage.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Tracking\Geo;

use BlueLens\Analytics\Core\Hookable;
use BlueLens\Analytics\Core\Jobs;
use BlueLens\Analytics\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads a City database in MMDB format with the client's own credentials:
 * - MaxMind GeoLite2-City (free account ID + license key, weekly updates)
 * - DB-IP IP to City Lite (no key; CC BY 4.0, attribution required; monthly)
 *
 * Files live in uploads/blue-lens/geo/ and are swapped atomically after validation.
 */
final class GeoDatabase implements Hookable {

	public const UPDATE_HOOK = 'blue_lens_geoip_update';
	public const META_OPTION = 'blue_lens_geo_meta';

	private const FILE = 'city.mmdb';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Attaches hooks.
	 */
	public function register_hooks(): void {
		add_action( self::UPDATE_HOOK, [ $this, 'run_update' ] );
		add_action( 'blue_lens_settings_updated', [ $this, 'on_settings_updated' ] );
		add_filter(
			'blue_lens_recurring_jobs',
			static function ( array $jobs ): array {
				$jobs[ self::UPDATE_HOOK ] = WEEK_IN_SECONDS;
				return $jobs;
			}
		);
	}

	/**
	 * Absolute path of the active database file.
	 */
	public function path(): string {
		return $this->directory() . self::FILE;
	}

	/**
	 * Whether a usable database is installed and a reader is available.
	 */
	public function is_available(): bool {
		return 'none' !== $this->settings->get( 'geoip_provider' )
			&& class_exists( '\MaxMind\Db\Reader' )
			&& is_readable( $this->path() );
	}

	/**
	 * Status for the admin screen.
	 *
	 * @return array{provider: string, updated_at: string, size: int, error: string}
	 */
	public function meta(): array {
		$meta = get_option( self::META_OPTION, [] );
		$meta = is_array( $meta ) ? $meta : [];

		return [
			'provider'   => (string) ( $meta['provider'] ?? '' ),
			'updated_at' => (string) ( $meta['updated_at'] ?? '' ),
			'size'       => (int) ( $meta['size'] ?? 0 ),
			'error'      => (string) ( $meta['error'] ?? '' ),
		];
	}

	/**
	 * Queues an update when provider settings change.
	 *
	 * @param array<string, mixed> $settings Saved settings.
	 */
	public function on_settings_updated( array $settings ): void {
		$meta = $this->meta();

		if ( 'none' !== $settings['geoip_provider'] && ( $meta['provider'] !== $settings['geoip_provider'] || '' !== $meta['error'] ) ) {
			Jobs::enqueue( self::UPDATE_HOOK );
		}
	}

	/**
	 * Job callback.
	 */
	public function run_update(): void {
		$this->update();
	}

	/**
	 * Downloads and installs the configured database.
	 *
	 * @return true|\WP_Error
	 */
	public function update(): bool|\WP_Error {
		$provider = (string) $this->settings->get( 'geoip_provider' );

		if ( 'none' === $provider ) {
			return true;
		}

		if ( ! class_exists( '\MaxMind\Db\Reader' ) ) {
			return $this->fail( $provider, __( 'The MMDB reader library is missing. Run "composer install" in the plugin folder.', 'blue-lens-analytics' ) );
		}

		$dir = $this->prepare_directory();
		if ( is_wp_error( $dir ) ) {
			return $this->fail( $provider, $dir->get_error_message() );
		}

		$staged = $dir . 'city.mmdb.new';
		$result = 'maxmind' === $provider ? $this->download_maxmind( $staged ) : $this->download_dbip( $staged );

		if ( is_wp_error( $result ) ) {
			wp_delete_file( $staged );
			return $this->fail( $provider, $result->get_error_message() );
		}

		if ( ! $this->validate( $staged ) ) {
			wp_delete_file( $staged );
			return $this->fail( $provider, __( 'The downloaded file is not a valid City database.', 'blue-lens-analytics' ) );
		}

		if ( ! rename( $staged, $this->path() ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic swap within one directory.
			wp_delete_file( $staged );
			return $this->fail( $provider, __( 'Could not move the database into place.', 'blue-lens-analytics' ) );
		}

		update_option(
			self::META_OPTION,
			[
				'provider'   => $provider,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				'size'       => (int) filesize( $this->path() ),
				'error'      => '',
			],
			false
		);

		return true;
	}

	/**
	 * MaxMind GeoLite2-City (tar.gz, HTTP basic auth).
	 *
	 * @param string $dest Destination file.
	 * @return true|\WP_Error
	 */
	private function download_maxmind( string $dest ): bool|\WP_Error {
		$account = (string) $this->settings->get( 'maxmind_account_id' );
		$key     = (string) $this->settings->get( 'maxmind_license_key' );

		if ( '' === $account || '' === $key ) {
			return new \WP_Error( 'bla_geo_credentials', __( 'Enter your MaxMind account ID and license key.', 'blue-lens-analytics' ) );
		}

		$archive = $this->download(
			'https://download.maxmind.com/geoip/databases/GeoLite2-City/download?suffix=tar.gz',
			[ 'Authorization' => 'Basic ' . base64_encode( $account . ':' . $key ) ] // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP basic auth.
		);
		if ( is_wp_error( $archive ) ) {
			return $archive;
		}

		$result = self::extract_mmdb_from_tar_gz( $archive, $dest );
		wp_delete_file( $archive );

		return $result;
	}

	/**
	 * DB-IP City Lite (mmdb.gz). The current month's file may not be published yet; falls back one month.
	 *
	 * @param string $dest Destination file.
	 * @return true|\WP_Error
	 */
	private function download_dbip( string $dest ): bool|\WP_Error {
		$error = new \WP_Error( 'bla_geo_download', __( 'DB-IP download failed.', 'blue-lens-analytics' ) );

		foreach ( [ 0, 1 ] as $months_back ) {
			$month   = gmdate( 'Y-m', (int) strtotime( "first day of -{$months_back} month" ) );
			$archive = $this->download( "https://download.db-ip.com/free/dbip-city-lite-{$month}.mmdb.gz" );

			if ( is_wp_error( $archive ) ) {
				$error = $archive;
				continue;
			}

			$result = self::gunzip( $archive, $dest );
			wp_delete_file( $archive );

			return $result;
		}

		return $error;
	}

	/**
	 * Streams a URL to a temporary file.
	 *
	 * @param string                $url     URL.
	 * @param array<string, string> $headers Request headers.
	 * @return string|\WP_Error Temporary file path.
	 */
	private function download( string $url, array $headers = [] ): string|\WP_Error {
		$tmp = $this->directory() . 'download-' . wp_generate_password( 8, false ) . '.tmp';

		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $tmp,
				'headers'  => $headers,
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			wp_delete_file( $tmp );
			return new \WP_Error(
				'bla_geo_http',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'Download failed with HTTP status %d. Check the credentials.', 'blue-lens-analytics' ), $code )
			);
		}

		return $tmp;
	}

	/**
	 * Extracts the first .mmdb member of a .tar.gz, streaming (no full decompression in memory).
	 *
	 * @param string $archive Path to .tar.gz.
	 * @param string $dest    Destination file.
	 * @return true|\WP_Error
	 */
	public static function extract_mmdb_from_tar_gz( string $archive, string $dest ): bool|\WP_Error {
		$in = gzopen( $archive, 'rb' );
		if ( false === $in ) {
			return new \WP_Error( 'bla_geo_extract', __( 'Could not open the downloaded archive.', 'blue-lens-analytics' ) );
		}

		try {
			while ( true ) {
				$header = self::read_exact( $in, 512 );
				if ( null === $header || '' === trim( $header, "\0" ) ) {
					break;
				}

				$name   = rtrim( substr( $header, 0, 100 ), "\0" );
				$prefix = rtrim( substr( $header, 345, 155 ), "\0" );
				$size   = (int) octdec( trim( substr( $header, 124, 12 ), "\0 " ) );
				$padded = (int) ( ceil( $size / 512 ) * 512 );
				$full   = '' !== $prefix ? $prefix . '/' . $name : $name;

				if ( str_ends_with( $full, '.mmdb' ) ) {
					return self::copy_stream( $in, $dest, $size );
				}

				// Skip this member's data blocks.
				while ( $padded > 0 ) {
					$chunk = gzread( $in, min( $padded, 1048576 ) );
					if ( false === $chunk || '' === $chunk ) {
						break 2;
					}
					$padded -= strlen( $chunk );
				}
			}
		} finally {
			gzclose( $in );
		}

		return new \WP_Error( 'bla_geo_extract', __( 'No .mmdb file found in the archive.', 'blue-lens-analytics' ) );
	}

	/**
	 * Decompresses a .gz file to $dest in chunks.
	 *
	 * @param string $archive Path to .gz.
	 * @param string $dest    Destination file.
	 * @return true|\WP_Error
	 */
	public static function gunzip( string $archive, string $dest ): bool|\WP_Error {
		$in = gzopen( $archive, 'rb' );
		if ( false === $in ) {
			return new \WP_Error( 'bla_geo_extract', __( 'Could not open the downloaded archive.', 'blue-lens-analytics' ) );
		}

		try {
			return self::copy_stream( $in, $dest, PHP_INT_MAX );
		} finally {
			gzclose( $in );
		}
	}

	/**
	 * Copies up to $length bytes from a gz stream to a file.
	 *
	 * @param resource $in     gz stream.
	 * @param string   $dest   Destination.
	 * @param int      $length Bytes to copy (PHP_INT_MAX for all).
	 * @return true|\WP_Error
	 */
	private static function copy_stream( $in, string $dest, int $length ): bool|\WP_Error {
		$out = fopen( $dest, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming large file.
		if ( false === $out ) {
			return new \WP_Error( 'bla_geo_write', __( 'Could not write the database file.', 'blue-lens-analytics' ) );
		}

		$remaining = $length;
		while ( $remaining > 0 && ! gzeof( $in ) ) {
			$chunk = gzread( $in, (int) min( $remaining, 1048576 ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			fwrite( $out, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			$remaining -= strlen( $chunk );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return true;
	}

	/**
	 * Reads exactly $length bytes from a gz stream.
	 *
	 * @param resource $in     gz stream.
	 * @param int      $length Bytes.
	 */
	private static function read_exact( $in, int $length ): ?string {
		$data = '';
		while ( strlen( $data ) < $length ) {
			$chunk = gzread( $in, $length - strlen( $data ) );
			if ( false === $chunk || '' === $chunk ) {
				return null;
			}
			$data .= $chunk;
		}

		return $data;
	}

	/**
	 * Checks the file opens as a City database.
	 *
	 * @param string $file Path.
	 */
	private function validate( string $file ): bool {
		try {
			$reader = new \MaxMind\Db\Reader( $file );
			$type   = (string) $reader->metadata()->databaseType;
			$reader->close();

			return str_contains( strtolower( $type ), 'city' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Records a failure.
	 *
	 * @param string $provider Provider.
	 * @param string $message  Error message.
	 */
	private function fail( string $provider, string $message ): \WP_Error {
		$meta             = $this->meta();
		$meta['provider'] = $provider;
		$meta['error']    = $message;
		update_option( self::META_OPTION, $meta, false );

		return new \WP_Error( 'bla_geo_update_failed', $message );
	}

	/**
	 * Storage directory with trailing slash.
	 */
	private function directory(): string {
		$uploads = wp_upload_dir( null, false );

		return trailingslashit( $uploads['basedir'] ) . 'blue-lens/geo/';
	}

	/**
	 * Creates the directory and blocks direct web access where the server honours it.
	 *
	 * @return string|\WP_Error Directory path.
	 */
	private function prepare_directory(): string|\WP_Error {
		$dir = $this->directory();

		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'bla_geo_dir', __( 'Could not create the uploads/blue-lens/geo directory.', 'blue-lens-analytics' ) );
		}

		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $dir;
	}
}
