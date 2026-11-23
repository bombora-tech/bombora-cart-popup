<?php
/**
 * Doporučené produkty do popupu (cross-sell → upsell → stejná kategorie), WPML-aware, s cache.
 *
 * @package Bombora\CartPopup
 */

namespace Bombora\CartPopup;

defined( 'ABSPATH' ) || exit;

final class Related {

	private const POOL_SIZE = 12;
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * @return \WC_Product[]
	 */
	public static function get( int $product_id ): array {
		$count  = (int) Settings::get( 'related_count' );
		$source = (string) Settings::get( 'related_source' );
		if ( $count <= 0 || 'none' === $source || $product_id <= 0 ) {
			return array();
		}

		$pool = self::pool( $product_id, $source );
		if ( empty( $pool ) ) {
			return array();
		}

		// Vyřadit, co už je v košíku (porovnání přes výchozí jazyk).
		$in_cart = array();
		foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $item ) {
			$in_cart[ Cart::normalize_id( (int) $item['product_id'] ) ] = true;
		}

		shuffle( $pool );
		$out = array();
		foreach ( $pool as $id ) {
			if ( isset( $in_cart[ Cart::normalize_id( $id ) ] ) ) {
				continue;
			}
			$p = wc_get_product( $id );
			if ( ! $p || ! self::is_eligible( $p ) ) {
				continue;
			}
			$out[] = $p;
			if ( count( $out ) >= $count ) {
				break;
			}
		}
		return $out;
	}

	private static function is_eligible( \WC_Product $p ): bool {
		return 'publish' === $p->get_status()
			&& $p->is_visible()
			&& $p->is_purchasable()
			&& $p->is_in_stock()
			&& 'hidden' !== $p->get_catalog_visibility()
			&& ! post_password_required( $p->get_id() );
	}

	/**
	 * Pool kandidátů (ID v aktuálním jazyce) – cachováno per produkt + jazyk.
	 *
	 * @return int[]
	 */
	private static function pool( int $product_id, string $source ): array {
		$lang      = (string) apply_filters( 'wpml_current_language', '' );
		$cache_key = 'bcp_rel_' . md5( $product_id . '|' . $lang . '|' . $source . '|' . BCP_VERSION );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array();
		}
		$base_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$base    = $product->get_parent_id() ? wc_get_product( $base_id ) : $product;
		if ( ! $base ) {
			return array();
		}

		$ids = array();
		if ( in_array( $source, array( 'smart', 'cross' ), true ) ) {
			$ids = array_merge( $ids, $base->get_cross_sell_ids() );
			if ( 'smart' === $source ) {
				$ids = array_merge( $ids, $base->get_upsell_ids() );
			}
		}

		// Přeložit na aktuální jazyk (cross-selly mohou být uložené v jiném jazyce).
		$ids = array_filter(
			array_map(
				static function ( $id ) {
					// Bez WPML vrací filtr původní ID; s WPML vrací null, pokud překlad neexistuje.
					return (int) apply_filters( 'wpml_object_id', (int) $id, 'product', false );
				},
				$ids
			)
		);

		if ( count( $ids ) < self::POOL_SIZE && in_array( $source, array( 'smart', 'category' ), true ) ) {
			$cat_slugs = array();
			foreach ( $base->get_category_ids() as $term_id ) {
				$term = get_term( (int) $term_id, 'product_cat' );
				if ( $term instanceof \WP_Term ) {
					$cat_slugs[] = $term->slug;
				}
			}
			if ( ! empty( $cat_slugs ) ) {
				$extra = wc_get_products(
					array(
						'status'       => 'publish',
						'limit'        => self::POOL_SIZE * 2,
						'category'     => $cat_slugs,
						'stock_status' => 'instock',
						'visibility'   => 'catalog',
						'exclude'      => array_merge( array( $base_id ), $ids ),
						'orderby'      => 'popularity',
						'return'       => 'ids',
					)
				);
				$ids = array_merge( $ids, array_map( 'intval', (array) $extra ) );
			}
		}

		$ids = array_values( array_unique( array_diff( array_map( 'intval', $ids ), array( $base_id ) ) ) );
		$ids = array_slice( $ids, 0, self::POOL_SIZE * 2 );
		$ids = (array) apply_filters( 'bcp_related_pool', $ids, $base_id );

		set_transient( $cache_key, $ids, self::CACHE_TTL );
		return $ids;
	}
}
