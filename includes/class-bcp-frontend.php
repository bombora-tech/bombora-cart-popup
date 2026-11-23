<?php
/**
 * Frontend: načtení 1× CSS + 1× JS (bez jQuery závislosti, defer), konfigurace bez osobních dat
 * – bezpečné pro full-page cache.
 *
 * @package Bombora\CartPopup
 */

namespace Bombora\CartPopup;

defined( 'ABSPATH' ) || exit;

final class Frontend {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
	}

	private static function should_load(): bool {
		if ( ! Settings::get( 'enabled' ) || is_admin() ) {
			return false;
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return false;
		}
		return (bool) apply_filters( 'bcp_should_load', true );
	}

	/**
	 * Verze assetu = verze pluginu + čas změny souboru (spolehlivé obejití cache prohlížeče/CDN).
	 */
	private static function asset_version( string $relative ): string {
		$mtime = @filemtime( BCP_DIR . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return BCP_VERSION . ( $mtime ? '.' . $mtime : '' );
	}

	public static function enqueue(): void {
		if ( ! self::should_load() ) {
			return;
		}

		$min = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		$css = "assets/css/popup{$min}.css";
		$js  = "assets/js/popup{$min}.js";
		wp_enqueue_style( 'bcp', BCP_URL . $css, array(), self::asset_version( $css ) );

		$accent = sanitize_hex_color( (string) Settings::get( 'accent' ) );
		$vars   = sprintf(
			'.bcp{--bcp-accent:%s;--bcp-radius:%dpx;--bcp-max:%dpx}',
			$accent ? $accent : '#6da305',
			(int) Settings::get( 'radius' ),
			(int) Settings::get( 'max_width' )
		);
		wp_add_inline_style( 'bcp', $vars );

		wp_enqueue_script(
			'bcp',
			BCP_URL . $js,
			array(),
			self::asset_version( $js ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$config = array(
			'endpoint' => \WC_AJAX::get_endpoint( '%%endpoint%%' ),
			'single'   => (bool) Settings::get( 'on_single' ),
			'loop'     => (bool) Settings::get( 'on_loop' ),
			'mobile'   => (bool) Settings::get( 'on_mobile' ),
			'currency' => array(
				'decimals' => wc_get_price_decimals(),
				'dec'      => wc_get_price_decimal_separator(),
				'thou'     => wc_get_price_thousand_separator(),
				'format'   => html_entity_decode( get_woocommerce_price_format(), ENT_QUOTES, 'UTF-8' ),
				'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			),
			'i18n'     => array(
				'close'  => __( 'Close', 'bombora-cart-popup' ),
				'error'  => __( 'Something went wrong. Please try again.', 'bombora-cart-popup' ),
				'dialog' => __( 'Shopping cart', 'bombora-cart-popup' ),
			),
		);

		wp_add_inline_script( 'bcp', 'window.bcpConfig=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}
}
