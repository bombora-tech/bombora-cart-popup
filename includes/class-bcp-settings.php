<?php
/**
 * Nastavení pluginu – jediný zdroj pravdy pro výchozí hodnoty a sanitizaci.
 *
 * @package Bombora\CartPopup
 */

namespace Bombora\CartPopup;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'bcp_settings';

	/** Texty, které lze přepsat v nastavení (a přeložit přes WPML → admin texts). */
	public const TEXT_KEYS = array( 'txt_heading', 'txt_related', 'txt_view_cart', 'txt_checkout', 'txt_continue' );

	/** @var array<string,mixed>|null */
	private static $cache = null;

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'          => 1,
			'on_single'        => 1,
			'on_loop'          => 1,
			'on_mobile'        => 1,
			'show_mode'        => 'added', // added | cart.
			'show_subtotal'    => 1,
			'show_remove'      => 1,
			'show_continue'    => 1,
			'show_checkout'    => 0,
			'shortcode'        => '',
			'related_count'    => 3,
			'related_source'   => 'smart', // smart | cross | category | none.
			'accent'           => '#6da305',
			'radius'           => 8,
			'max_width'        => 760,
			'txt_heading'      => '',
			'txt_related'      => '',
			'txt_view_cart'    => '',
			'txt_checkout'     => '',
			'txt_continue'     => '',
		);
	}

	/**
	 * Uložená nastavení sloučená s výchozími. Texty prochází WPML (admin texts) přes get_option.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * @param string $key Klíč nastavení.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Text pro frontend: vlastní hodnota z nastavení, jinak přeložený výchozí text.
	 */
	public static function text( string $key ): string {
		$custom = (string) self::get( $key );
		if ( '' !== trim( $custom ) ) {
			return $custom;
		}
		return self::default_text( $key );
	}

	/**
	 * Vestavěný (přeložený) text.
	 */
	public static function default_text( string $key ): string {
		switch ( $key ) {
			case 'txt_heading':
				return __( 'Added to your cart', 'bombora-cart-popup' );
			case 'txt_related':
				return __( 'Customers also bought', 'bombora-cart-popup' );
			case 'txt_view_cart':
				return __( 'View cart', 'bombora-cart-popup' );
			case 'txt_checkout':
				return __( 'Checkout', 'bombora-cart-popup' );
			case 'txt_continue':
				return __( 'Continue shopping', 'bombora-cart-popup' );
		}
		return '';
	}

	/**
	 * Sanitizace celého pole nastavení (register_setting → sanitize_callback).
	 * Bezpečnost: whitelist klíčů, striktní typy, žádné HTML.
	 *
	 * @param mixed $input Surová data z formuláře.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$out      = array();

		foreach ( array( 'enabled', 'on_single', 'on_loop', 'on_mobile', 'show_subtotal', 'show_remove', 'show_continue', 'show_checkout' ) as $bool ) {
			$out[ $bool ] = empty( $input[ $bool ] ) ? 0 : 1;
		}

		$out['show_mode'] = in_array( $input['show_mode'] ?? '', array( 'added', 'cart' ), true ) ? $input['show_mode'] : $defaults['show_mode'];

		$out['related_source'] = in_array( $input['related_source'] ?? '', array( 'smart', 'cross', 'category', 'none' ), true ) ? $input['related_source'] : $defaults['related_source'];
		$out['related_count']  = max( 0, min( 8, absint( $input['related_count'] ?? $defaults['related_count'] ) ) );

		$accent        = sanitize_hex_color( (string) ( $input['accent'] ?? '' ) );
		$out['accent'] = $accent ? $accent : $defaults['accent'];

		$out['radius']    = max( 0, min( 30, absint( $input['radius'] ?? $defaults['radius'] ) ) );
		$out['max_width'] = max( 420, min( 1200, absint( $input['max_width'] ?? $defaults['max_width'] ) ) );

		$out['shortcode'] = self::sanitize_shortcodes( (string) ( $input['shortcode'] ?? '' ) );

		foreach ( self::TEXT_KEYS as $key ) {
			$out[ $key ] = sanitize_text_field( (string) ( $input[ $key ] ?? "" ) );
			$out[ $key ] = mb_substr( $out[ $key ], 0, 120 );
		}

		self::flush();
		return $out;
	}

	/**
	 * Povolí pouze samotné shortcody – žádný volný HTML/JS.
	 * Každý shortcode musí být registrovaný; neznámé se zahodí.
	 */
	public static function sanitize_shortcodes( string $raw ): string {
		// Data z options.php jsou již unslashed.
		if ( ! preg_match_all( '/\[([a-zA-Z0-9_\-]{1,64})((?:\s+[a-zA-Z0-9_\-]{1,64}=(?:"[^"\[\]<>]{0,200}"|\'[^\'\[\]<>]{0,200}\'|[a-zA-Z0-9_\-\.]{1,64}))*)\s*\/?\]/', $raw, $matches, PREG_SET_ORDER ) ) {
			return '';
		}
		$kept = array();
		foreach ( array_slice( $matches, 0, 3 ) as $m ) {
			if ( shortcode_exists( $m[1] ) ) {
				$kept[] = $m[0];
			}
		}
		return implode( ' ', $kept );
	}
}
