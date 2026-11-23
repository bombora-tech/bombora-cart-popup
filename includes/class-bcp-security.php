<?php
/**
 * Ochrana AJAX endpointů proti CSRF kompatibilní s full-page cache.
 *
 * Proč ne nonce: stránky jsou cachované (WP Fastest Cache / Perfmatters), nonce zapečené
 * v HTML by po 12–24 h expirovalo a nepřihlášeným zákazníkům by popup přestal fungovat.
 *
 * Místo toho:
 *  1) povinná vlastní hlavička X-Bombora-Cart – cizí web ji nemůže poslat bez CORS preflightu,
 *     který WordPress pro wc-ajax nepovoluje;
 *  2) pokud prohlížeč pošle Origin / Sec-Fetch-Site / Referer, musí odpovídat vlastní doméně;
 *  3) pouze metoda POST.
 * Endpointy navíc pracují výhradně s košíkem (session) aktuálního návštěvníka.
 *
 * @package Bombora\CartPopup
 */

namespace Bombora\CartPopup;

defined( 'ABSPATH' ) || exit;

final class Security {

	public const HEADER = 'HTTP_X_BOMBORA_CART';

	public static function is_valid_request(): bool {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return false;
		}

		if ( '1' !== (string) ( $_SERVER[ self::HEADER ] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return false;
		}

		$allowed = self::allowed_hosts();

		$sec_fetch_site = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '' ) ) );
		if ( '' !== $sec_fetch_site && ! in_array( $sec_fetch_site, array( 'same-origin', 'none' ), true ) ) {
			return false;
		}

		$origin = (string) ( $_SERVER['HTTP_ORIGIN'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 'null' === $origin ) {
			return false; // Sandboxované iframy / neprůhledný původ – nikdy nepovolit.
		}
		if ( '' !== $origin ) {
			$host = wp_parse_url( $origin, PHP_URL_HOST );
			return is_string( $host ) && in_array( strtolower( $host ), $allowed, true );
		}

		$referer = (string) ( $_SERVER['HTTP_REFERER'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $referer ) {
			$host = wp_parse_url( $referer, PHP_URL_HOST );
			return is_string( $host ) && in_array( strtolower( $host ), $allowed, true );
		}

		// Bez Origin i Referer (privacy prohlížeče) – stačí vlastní hlavička, kterou cross-site poslat nelze.
		return true;
	}

	/**
	 * Hostitelé vlastního webu (home, site a domény jazyků WPML).
	 *
	 * @return string[]
	 */
	public static function allowed_hosts(): array {
		static $hosts = null;
		if ( null !== $hosts ) {
			return $hosts;
		}
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$h = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $h ) ) {
				$hosts[] = strtolower( $h );
			}
		}
		$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( is_array( $languages ) ) {
			foreach ( $languages as $lang ) {
				if ( ! empty( $lang['url'] ) ) {
					$h = wp_parse_url( $lang['url'], PHP_URL_HOST );
					if ( is_string( $h ) ) {
						$hosts[] = strtolower( $h );
					}
				}
			}
		}
		$hosts = array_values( array_unique( (array) apply_filters( 'bcp_allowed_hosts', $hosts ) ) );
		return $hosts;
	}
}
