<?php
/**
 * Práce s košíkem: dohledání položek (WPML-safe), změna množství s validací, fragmenty.
 *
 * @package Bombora\CartPopup
 */

namespace Bombora\CartPopup;

defined( 'ABSPATH' ) || exit;

final class Cart {

	/**
	 * Převede ID produktu na ID ve výchozím jazyce WPML (pro porovnání napříč jazyky).
	 * Bez WPML vrací původní ID.
	 */
	public static function normalize_id( int $id ): int {
		if ( $id <= 0 ) {
			return 0;
		}
		$default = apply_filters( 'wpml_default_language', null );
		if ( ! $default ) {
			return $id;
		}
		$type = 'product_variation' === get_post_type( $id ) ? 'product_variation' : 'product';
		return (int) apply_filters( 'wpml_object_id', $id, $type, true, $default );
	}

	/**
	 * Je položka „podřízená“ (součást bundlu apod.) – v popupu se nezobrazuje samostatně.
	 *
	 * @param array<string,mixed> $item Položka košíku.
	 */
	public static function is_child_item( array $item ): bool {
		foreach ( array( 'woosb_parent_id', 'woosb_parent_key', 'bundled_by', 'composite_parent', 'mnm_container' ) as $k ) {
			if ( ! empty( $item[ $k ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Najde klíč položky košíku podle produktu (WCML může klíč v jiném jazyce přepočítat).
	 */
	public static function find_key( int $product_id, int $variation_id = 0, string $preferred_key = '' ): string {
		if ( ! WC()->cart ) {
			return '';
		}
		$cart = WC()->cart->get_cart();
		if ( '' !== $preferred_key && isset( $cart[ $preferred_key ] ) ) {
			return $preferred_key;
		}
		$want_p = self::normalize_id( $product_id );
		$want_v = self::normalize_id( $variation_id );
		foreach ( array_reverse( $cart, true ) as $key => $item ) {
			if ( self::is_child_item( $item ) ) {
				continue;
			}
			if ( self::normalize_id( (int) $item['product_id'] ) === $want_p
				&& self::normalize_id( (int) ( $item['variation_id'] ?? 0 ) ) === $want_v ) {
				return (string) $key;
			}
		}
		return '';
	}

	/**
	 * Změní množství položky. Validace: existence klíče, min/max, sklad, sold individually,
	 * filtr woocommerce_update_cart_validation (používají ho např. WPC bundly).
	 *
	 * @return true|\WP_Error
	 */
	public static function set_quantity( string $key, int $qty ) {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return new \WP_Error( 'bcp_no_cart', __( 'Something went wrong. Please try again.', 'bombora-cart-popup' ) );
		}
		$items = $cart->get_cart();
		if ( ! isset( $items[ $key ] ) || self::is_child_item( $items[ $key ] ) ) {
			return new \WP_Error( 'bcp_bad_key', __( 'This product is no longer in your cart.', 'bombora-cart-popup' ) );
		}
		$item    = $items[ $key ];
		$product = $item['data'] ?? null;

		if ( $qty <= 0 ) {
			$cart->remove_cart_item( $key );
			return true;
		}

		if ( $product instanceof \WC_Product ) {
			if ( $product->is_sold_individually() ) {
				$qty = 1;
			}
			$max = (int) $product->get_max_purchase_quantity();
			if ( $max > 0 && $qty > $max ) {
				$qty = $max;
			}
			$min = (int) $product->get_min_purchase_quantity();
			if ( $qty < $min ) {
				$qty = $min;
			}
			if ( $product->managing_stock() && ! $product->has_enough_stock( $qty ) ) {
				return new \WP_Error(
					'bcp_stock',
					sprintf(
						/* translators: %d: available stock quantity */
						__( 'Only %d in stock.', 'bombora-cart-popup' ),
						(int) $product->get_stock_quantity()
					)
				);
			}
		}

		$qty = min( $qty, 9999 );

		$passed = apply_filters( 'woocommerce_update_cart_validation', true, $key, $item, $qty );
		if ( ! $passed ) {
			return new \WP_Error( 'bcp_invalid', self::pop_error_notice() ?: __( 'Something went wrong. Please try again.', 'bombora-cart-popup' ) );
		}

		$cart->set_quantity( $key, $qty, true );
		return true;
	}

	/**
	 * Vybere první chybovou hlášku WooCommerce (bez HTML) a vyčistí všechny notice.
	 */
	public static function pop_error_notice(): string {
		$message = '';
		if ( function_exists( 'wc_get_notices' ) ) {
			$errors = wc_get_notices( 'error' );
			if ( ! empty( $errors ) ) {
				$first   = reset( $errors );
				$message = is_array( $first ) ? (string) ( $first['notice'] ?? '' ) : (string) $first;
				$message = trim( wp_strip_all_tags( html_entity_decode( $message, ENT_QUOTES, 'UTF-8' ) ) );
			}
			wc_clear_notices();
		}
		return $message;
	}

	/**
	 * Fragmenty jako WooCommerce (mini-cart, počítadla, WPify lišta…), aby se aktualizovala hlavička webu.
	 *
	 * @return array<string,string>
	 */
	public static function fragments(): array {
		ob_start();
		woocommerce_mini_cart();
		$mini = ob_get_clean();

		$fragments = apply_filters(
			'woocommerce_add_to_cart_fragments',
			array( 'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini . '</div>' )
		);
		return is_array( $fragments ) ? array_map( 'strval', $fragments ) : array();
	}
}
