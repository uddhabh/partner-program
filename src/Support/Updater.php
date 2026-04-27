<?php
/**
 * GitHub-Releases-backed updater so WordPress shows "Update available" for this
 * plugin and applies updates from a release asset zip.
 *
 * Configure via constants in wp-config.php (optional):
 *   define( 'PARTNER_PROGRAM_GITHUB_REPO', 'uddhabh/partner-program' );
 *   define( 'PARTNER_PROGRAM_GITHUB_TOKEN', 'ghp_...' );  // only for private repos
 *
 * The release MUST attach a zip whose internal top-level folder is "partner-program/"
 * (the build script in the repo produces this). The auto-generated GitHub source
 * zip is NOT used because it nests under a versioned folder.
 *
 * @package PartnerProgram
 */

declare( strict_types = 1 );

namespace PartnerProgram\Support;

defined( 'ABSPATH' ) || exit;

final class Updater {

	private const TRANSIENT      = 'partner_program_gh_release';
	private const TRANSIENT_TTL  = 6 * HOUR_IN_SECONDS;
	private const DEFAULT_REPO   = 'uddhabh/partner-program';
	private const ASSET_FALLBACK = 'partner-program.zip';

	private string $basename;
	private string $slug;
	private string $repo;

	public static function register(): void {
		$instance = new self();
		add_filter( 'pre_set_site_transient_update_plugins', [ $instance, 'inject_update' ] );
		add_filter( 'plugins_api', [ $instance, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $instance, 'fix_source_dir' ], 10, 4 );
	}

	public function __construct() {
		$this->basename = PARTNER_PROGRAM_BASENAME;
		$this->slug     = dirname( $this->basename );
		$this->repo     = defined( 'PARTNER_PROGRAM_GITHUB_REPO' ) ? PARTNER_PROGRAM_GITHUB_REPO : self::DEFAULT_REPO;
		$this->repo     = (string) apply_filters( 'partner_program_github_repo', $this->repo );
	}

	/**
	 * @param mixed $transient
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		$release = $this->fetch_release();
		if ( ! $release ) {
			return $transient;
		}

		$remote_version = ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' );
		if ( '' === $remote_version ) {
			return $transient;
		}

		if ( ! version_compare( $remote_version, PARTNER_PROGRAM_VERSION, '>' ) ) {
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = [];
			}
			$transient->no_update[ $this->basename ] = (object) [
				'id'          => $this->basename,
				'slug'        => $this->slug,
				'plugin'      => $this->basename,
				'new_version' => PARTNER_PROGRAM_VERSION,
				'url'         => 'https://github.com/' . $this->repo,
				'package'     => '',
			];
			return $transient;
		}

		$package = $this->find_asset_url( $release );
		if ( ! $package ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}
		$transient->response[ $this->basename ] = (object) [
			'id'            => $this->basename,
			'slug'          => $this->slug,
			'plugin'        => $this->basename,
			'new_version'   => $remote_version,
			'url'           => 'https://github.com/' . $this->repo,
			'package'       => $package,
			'tested'        => '',
			'requires_php'  => '7.4',
			'compatibility' => new \stdClass(),
		];
		return $transient;
	}

	/**
	 * Powers the "View details" modal in Plugins screen.
	 *
	 * @param mixed  $result
	 * @param string $action
	 * @param object $args
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}
		$release = $this->fetch_release();
		if ( ! $release ) {
			return $result;
		}
		$remote_version = ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' );

		$body = (string) ( $release['body'] ?? '' );
		$changelog = '<pre style="white-space:pre-wrap;">' . esc_html( $body ) . '</pre>';

		$info = (object) [
			'name'           => 'Partner Program for WooCommerce',
			'slug'           => $this->slug,
			'version'        => $remote_version ?: PARTNER_PROGRAM_VERSION,
			'author'         => '<a href="https://beenacle.com">Beenacle</a>',
			'homepage'       => 'https://github.com/' . $this->repo,
			'requires'       => '6.2',
			'tested'         => '',
			'requires_php'   => '7.4',
			'last_updated'   => (string) ( $release['published_at'] ?? '' ),
			'sections'       => [
				'description' => 'White-label affiliate / partner program for WooCommerce by Beenacle.',
				'changelog'   => $changelog,
			],
			'download_link'  => $this->find_asset_url( $release ) ?: '',
		];
		return $info;
	}

	/**
	 * GitHub release-asset zips contain `partner-program/` as the top folder
	 * (we build them that way). If a future release ever ships a versioned
	 * folder, rename it on extract so WP doesn't end up with a duplicate.
	 *
	 * @param string $source
	 * @param string $remote_source
	 * @param object $upgrader
	 * @param array  $hook_extra
	 * @return string|\WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = [] ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}
		$expected = trailingslashit( dirname( $source ) ) . $this->slug;
		if ( trailingslashit( $source ) === trailingslashit( $expected ) ) {
			return $source;
		}
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( $source, $expected, true ) ) {
			return trailingslashit( $expected );
		}
		return $source;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function fetch_release(): ?array {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url  = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
		$args = [
			'timeout' => 10,
			'headers' => [
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'partner-program-updater',
			],
		];
		if ( defined( 'PARTNER_PROGRAM_GITHUB_TOKEN' ) && PARTNER_PROGRAM_GITHUB_TOKEN ) {
			$args['headers']['Authorization'] = 'Bearer ' . PARTNER_PROGRAM_GITHUB_TOKEN;
		}
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			set_site_transient( self::TRANSIENT, [], MINUTE_IN_SECONDS * 15 );
			return null;
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) ) {
			return null;
		}
		set_site_transient( self::TRANSIENT, $json, self::TRANSIENT_TTL );
		return $json;
	}

	private function find_asset_url( array $release ): ?string {
		$assets = $release['assets'] ?? [];
		if ( ! is_array( $assets ) ) {
			return null;
		}
		// Prefer an asset that ends in .zip and contains the slug; fall back to first .zip.
		$first_zip = null;
		foreach ( $assets as $asset ) {
			$name = (string) ( $asset['name'] ?? '' );
			$url  = (string) ( $asset['browser_download_url'] ?? '' );
			if ( '' === $url || '.zip' !== strtolower( substr( $name, -4 ) ) ) {
				continue;
			}
			if ( false !== strpos( $name, $this->slug ) ) {
				return $url;
			}
			$first_zip = $first_zip ?? $url;
		}
		return $first_zip;
	}
}
