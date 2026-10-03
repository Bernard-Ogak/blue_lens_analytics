<?php
/**
 * Updates from GitHub Releases.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Offers new GitHub releases through the normal WordPress update screens.
 *
 * The plugin header's "Update URI" stops WordPress from asking WordPress.org about this plugin and
 * makes core call the update_plugins_github.com filter instead. Only the release version and the
 * attached blue-lens-analytics.zip are read; no site data is sent to GitHub.
 */
final class GitHubUpdater implements Hookable {

	public const REPO  = 'Bernard-Ogak/blue_lens_analytics';
	public const ASSET = 'blue-lens-analytics.zip';
	public const CACHE = 'blue_lens_github_release';

	private const CACHE_SECONDS       = 12 * 3600;
	private const RETRY_AFTER_FAILURE = 3600;

	/**
	 * Attaches hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'update_plugins_github.com', [ $this, 'check' ], 10, 3 );
		add_filter( 'plugins_api', [ $this, 'details' ], 20, 3 );
	}

	/**
	 * Tells WordPress about the latest release (core compares the versions).
	 *
	 * @param array<string, mixed>|false $update      Update data from earlier callbacks.
	 * @param array<string, mixed>       $plugin_data Plugin headers.
	 * @param string                     $plugin_file Plugin basename.
	 * @return array<string, mixed>|false
	 */
	public function check( mixed $update, array $plugin_data, string $plugin_file ): mixed {
		if ( plugin_basename( BLA_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = $this->latest();
		if ( null === $release ) {
			return $update;
		}

		return [
			'id'      => (string) ( $plugin_data['UpdateURI'] ?? 'https://github.com/' . self::REPO ),
			'slug'    => dirname( $plugin_file ),
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['package'],
		];
	}

	/**
	 * Fills the "View details" window for this plugin.
	 *
	 * @param false|object|array<mixed> $result Result from earlier callbacks.
	 * @param string                    $action plugins_api action.
	 * @param object                    $args   Request arguments.
	 * @return false|object|array<mixed>
	 */
	public function details( mixed $result, string $action, object $args ): mixed {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || dirname( plugin_basename( BLA_FILE ) ) !== $args->slug ) {
			return $result;
		}

		$release = $this->latest();
		if ( null === $release ) {
			return $result;
		}

		return (object) [
			'name'          => 'Blue Lens Analytics',
			'slug'          => $args->slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://www.creativebay.co.ke">Bernard Ogak (Creative Bay)</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $release['package'],
			'last_updated'  => $release['published'],
			'sections'      => [
				'changelog' => '' !== $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : '',
			],
		];
	}

	/**
	 * The latest published release with the plugin ZIP attached, cached for 12 hours.
	 *
	 * @return array{version: string, url: string, package: string, notes: string, published: string}|null
	 */
	public function latest(): ?array {
		/**
		 * Whether to check GitHub for new releases.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'blue_lens_github_updates', true ) ) {
			return null;
		}

		// "Check again" on Dashboard → Updates skips the cache.
		$force  = isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cached = $force ? false : get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return isset( $cached['version'] ) ? $cached : null;
		}

		$release = $this->fetch();
		set_site_transient( self::CACHE, $release ?? [ 'failed' => time() ], null === $release ? self::RETRY_AFTER_FAILURE : self::CACHE_SECONDS );

		return $release;
	}

	/**
	 * Reads the latest release from the GitHub API.
	 *
	 * @return array{version: string, url: string, package: string, notes: string, published: string}|null
	 */
	private function fetch(): ?array {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'BlueLensAnalytics/' . BLA_VERSION,
				],
			]
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$version = ltrim( (string) ( $data['tag_name'] ?? '' ), 'vV' );
		if ( 1 !== preg_match( '/^\d+(\.\d+){1,3}$/', $version ) ) {
			return null;
		}

		foreach ( (array) ( $data['assets'] ?? [] ) as $asset ) {
			if ( is_array( $asset ) && self::ASSET === ( $asset['name'] ?? '' ) && is_string( $asset['browser_download_url'] ?? null ) && str_starts_with( $asset['browser_download_url'], 'https://github.com/' ) ) {
				return [
					'version'   => $version,
					'url'       => esc_url_raw( (string) ( $data['html_url'] ?? 'https://github.com/' . self::REPO . '/releases' ) ),
					'package'   => esc_url_raw( $asset['browser_download_url'] ),
					'notes'     => (string) ( $data['body'] ?? '' ),
					'published' => (string) ( $data['published_at'] ?? '' ),
				];
			}
		}

		return null;
	}
}
