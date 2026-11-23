<?php
/**
 * Automatické aktualizace z GitHub Releases.
 *
 * Bezpečnost:
 *  - pouze HTTPS, ověřování certifikátu (výchozí WP), povolené hostitele přesměrování;
 *  - každý ZIP se před instalací ověří proti SHA-256 z release assetu *.sha256 (hash_equals);
 *  - verze musí odpovídat SemVer X.Y.Z, draft/prerelease se ignorují;
 *  - token pro privátní repo jen z konstanty v wp-config.php (nikdy v DB, nikdy se nevypisuje);
 *  - hlavička „Update URI“ brání přepsání pluginem stejného jména z wordpress.org.
 *
 * @package Bombora\CartPopup
 */

namespace Bombora\CartPopup;

defined( 'ABSPATH' ) || exit;

final class Updater {

	private const SLUG      = 'bombora-cart-popup';
	private const ASSET     = 'bombora-cart-popup.zip';
	private const CACHE     = 'bcp_gh_release';
	private const ERROR     = 'bcp_gh_error';
	private const TTL       = 6 * HOUR_IN_SECONDS;
	private const ERROR_TTL = HOUR_IN_SECONDS;
	private const MAX_ZIP   = 10485760; // 10 MB.

	/** Povolení hostitelé pro stažení (API + CDN přesměrování GitHubu). */
	private const HOSTS = array( 'api.github.com', 'github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com', 'github-releases.githubusercontent.com' );

	public static function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'pre_download' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ), 10, 0 );
	}

	public static function repo(): string {
		$repo = defined( 'BOMBORA_CART_POPUP_REPO' ) ? (string) BOMBORA_CART_POPUP_REPO : BCP_DEFAULT_REPO;
		return preg_match( '#^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$#', $repo ) ? $repo : BCP_DEFAULT_REPO;
	}

	public static function has_token(): bool {
		return defined( 'BOMBORA_GITHUB_TOKEN' ) && is_string( BOMBORA_GITHUB_TOKEN ) && '' !== BOMBORA_GITHUB_TOKEN;
	}

	public static function clear_cache(): void {
		delete_site_transient( self::CACHE );
		delete_site_transient( self::ERROR );
	}

	public static function last_error(): string {
		$e = get_site_transient( self::ERROR );
		return is_string( $e ) ? $e : '';
	}

	/**
	 * @return array<string,string>
	 */
	private static function headers( string $accept ): array {
		$h = array(
			'Accept'               => $accept,
			'User-Agent'           => 'Bombora-Cart-Popup/' . BCP_VERSION,
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		if ( self::has_token() ) {
			$h['Authorization'] = 'Bearer ' . BOMBORA_GITHUB_TOKEN;
		}
		return $h;
	}

	private static function host_allowed( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( $parts['host'] );
		return in_array( $host, self::HOSTS, true ) || (bool) preg_match( '/\.githubusercontent\.com$/', $host );
	}

	/**
	 * Nejnovější release (cache 6 h). Při chybě null + uložená chybová hláška (cache 1 h).
	 *
	 * @param bool $allow_remote Povolit dotaz na GitHub (false = jen z cache).
	 * @return array<string,string>|null
	 */
	public static function latest( bool $allow_remote = true ): ?array {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return empty( $cached ) ? null : $cached;
		}
		if ( ! $allow_remote ) {
			return null;
		}

		$url  = 'https://api.github.com/repos/' . self::repo() . '/releases/latest';
		$resp = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => self::headers( 'application/vnd.github+json' ),
			)
		);

		$fail = static function ( string $msg ) {
			set_site_transient( self::CACHE, array(), self::ERROR_TTL );
			set_site_transient( self::ERROR, $msg, self::ERROR_TTL );
			return null;
		};

		if ( is_wp_error( $resp ) ) {
			return $fail( $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			return $fail( sprintf( 'GitHub API HTTP %d', $code ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $data ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return $fail( 'Invalid release data' );
		}

		$version = ltrim( (string) ( $data['tag_name'] ?? '' ), 'vV' );
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return $fail( 'Invalid version tag' );
		}

		$zip = '';
		$sha = '';
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			$name = (string) ( $asset['name'] ?? '' );
			$api  = (string) ( $asset['url'] ?? '' );
			if ( self::ASSET === $name && self::host_allowed( $api ) ) {
				$zip = $api;
			} elseif ( self::ASSET . '.sha256' === $name && self::host_allowed( $api ) ) {
				$sha = $api;
			}
		}
		if ( '' === $zip || '' === $sha ) {
			return $fail( 'Release is missing ' . self::ASSET . ' or its .sha256' );
		}

		$info = array(
			'version'   => $version,
			'zip'       => $zip,
			'sha'       => $sha,
			'notes'     => substr( (string) ( $data['body'] ?? '' ), 0, 20000 ),
			'html_url'  => self::host_allowed( (string) ( $data['html_url'] ?? '' ) ) ? (string) $data['html_url'] : '',
			'published' => (string) ( $data['published_at'] ?? '' ),
		);
		set_site_transient( self::CACHE, $info, self::TTL );
		delete_site_transient( self::ERROR );
		return $info;
	}

	/**
	 * @param mixed $transient Transient update_plugins.
	 * @return mixed
	 */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$info = self::latest();
		if ( ! $info ) {
			return $transient;
		}
		$item = (object) array(
			'id'           => self::repo(),
			'slug'         => self::SLUG,
			'plugin'       => BCP_BASENAME,
			'new_version'  => $info['version'],
			'url'          => $info['html_url'],
			'package'      => $info['zip'],
			'requires_php' => '8.0',
			'icons'        => array(),
			'banners'      => array(),
		);
		if ( version_compare( $info['version'], BCP_VERSION, '>' ) ) {
			$transient->response[ BCP_BASENAME ] = $item;
		} else {
			if ( isset( $transient->response[ BCP_BASENAME ] ) ) {
				unset( $transient->response[ BCP_BASENAME ] );
			}
			$transient->no_update[ BCP_BASENAME ] = $item;
		}
		return $transient;
	}

	/**
	 * Okno „Zobrazit podrobnosti verze“.
	 *
	 * @param mixed  $result Výsledek.
	 * @param string $action Akce.
	 * @param object $args   Argumenty.
	 * @return mixed
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$info = self::latest();
		if ( ! $info ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Bombora Cart Popup',
			'slug'          => self::SLUG,
			'version'       => $info['version'],
			'author'        => 'Bombora group s.r.o.',
			'requires'      => '6.5',
			'requires_php'  => '8.0',
			'last_updated'  => $info['published'],
			'homepage'      => $info['html_url'],
			'download_link' => $info['zip'],
			'sections'      => array(
				'changelog' => '<pre>' . esc_html( $info['notes'] ) . '</pre>',
			),
		);
	}

	/**
	 * Stáhne ZIP sami (kvůli autorizaci u privátního repa) a ověří SHA-256. Cizí balíčky nechá být.
	 *
	 * @param mixed  $reply      false = nikdo zatím nestahoval.
	 * @param string $package    URL balíčku.
	 * @param object $upgrader   Upgrader.
	 * @param array  $hook_extra Extra data.
	 * @return mixed
	 */
	public static function pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( false !== $reply || ! is_string( $package ) ) {
			return $reply;
		}
		$info = self::latest();
		if ( ! $info || ! hash_equals( $info['zip'], $package ) ) {
			return $reply; // Není náš balíček.
		}

		$sha_body = self::fetch_asset( $info['sha'], null );
		if ( is_wp_error( $sha_body ) ) {
			return $sha_body;
		}
		if ( ! preg_match( '/\b([a-f0-9]{64})\b/i', (string) $sha_body, $m ) ) {
			return new \WP_Error( 'bcp_sha_missing', 'Bombora Cart Popup: invalid SHA-256 file in release.' );
		}
		$expected = strtolower( $m[1] );

		$tmp = wp_tempnam( self::ASSET );
		if ( ! $tmp ) {
			return new \WP_Error( 'bcp_tmp', 'Bombora Cart Popup: could not create a temporary file.' );
		}
		$ok = self::fetch_asset( $info['zip'], $tmp );
		if ( is_wp_error( $ok ) ) {
			wp_delete_file( $tmp );
			return $ok;
		}

		$size = (int) filesize( $tmp );
		if ( $size <= 0 || $size > self::MAX_ZIP ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'bcp_size', 'Bombora Cart Popup: unexpected package size.' );
		}

		$actual = hash_file( 'sha256', $tmp );
		if ( ! is_string( $actual ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'bcp_sha_mismatch', 'Bombora Cart Popup: SHA-256 checksum mismatch – update aborted.' );
		}

		return $tmp;
	}

	/**
	 * Stáhne release asset přes API (Accept: octet-stream). Přesměrování na CDN se následuje ručně
	 * BEZ autorizační hlavičky a jen na povolené hostitele.
	 *
	 * @param string      $api_url URL assetu v API.
	 * @param string|null $file    Cílový soubor (null = vrátit tělo).
	 * @return string|true|\WP_Error
	 */
	private static function fetch_asset( string $api_url, ?string $file ) {
		if ( ! self::host_allowed( $api_url ) ) {
			return new \WP_Error( 'bcp_host', 'Bombora Cart Popup: disallowed download host.' );
		}
		$args = array(
			'timeout'     => 60,
			'redirection' => 0,
			'headers'     => self::headers( 'application/octet-stream' ),
		);
		if ( null !== $file ) {
			$args['stream']   = true;
			$args['filename'] = $file;
		}
		$resp = wp_remote_get( $api_url, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );

		if ( $code >= 300 && $code < 400 ) {
			$location = (string) wp_remote_retrieve_header( $resp, 'location' );
			if ( ! self::host_allowed( $location ) ) {
				return new \WP_Error( 'bcp_redirect', 'Bombora Cart Popup: disallowed redirect host.' );
			}
			$args2 = array(
				'timeout'     => 60,
				'redirection' => 2,
				'headers'     => array( 'User-Agent' => 'Bombora-Cart-Popup/' . BCP_VERSION ),
			);
			if ( null !== $file ) {
				$args2['stream']   = true;
				$args2['filename'] = $file;
			}
			$resp = wp_remote_get( $location, $args2 );
			if ( is_wp_error( $resp ) ) {
				return $resp;
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
		}

		if ( 200 !== $code ) {
			return new \WP_Error( 'bcp_http', sprintf( 'Bombora Cart Popup: download failed (HTTP %d).', $code ) );
		}
		return null === $file ? (string) wp_remote_retrieve_body( $resp ) : true;
	}
}
