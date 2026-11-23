<?php
/**
 * Vykreslení obsahu popupu. Veškerý výstup je escapovaný (esc_* / wp_kses_post).
 *
 * @package Bombora\CartPopup
 */

namespace Bombora\CartPopup;

defined( 'ABSPATH' ) || exit;

final class Render {

	/**
	 * Hlavní část popupu (bez doporučení – ta se při změně množství nepřekreslují).
	 *
	 * @param string $focus_key Klíč právě přidané/změněné položky.
	 * @param string $notice    Volitelná chybová hláška (prostý text).
	 */
	public static function popup( string $focus_key, string $notice = '' ): string {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return '';
		}
		$items = $cart->get_cart();
		$mode  = (string) Settings::get( 'show_mode' );

		$visible = array();
		foreach ( $items as $key => $item ) {
			if ( Cart::is_child_item( $item ) ) {
				continue;
			}
			if ( ! apply_filters( 'woocommerce_cart_item_visible', true, $item, $key ) ) {
				continue;
			}
			if ( 'added' === $mode && '' !== $focus_key && $key !== $focus_key ) {
				continue;
			}
			$visible[ $key ] = $item;
		}

		ob_start();
		?>
		<div class="bcp-head">
			<h2 class="bcp-title" id="bcp-title"><?php echo esc_html( Settings::text( 'txt_heading' ) ); ?></h2>
		</div>
		<div class="bcp-notice" role="alert"<?php echo '' === $notice ? ' hidden' : ''; ?>><?php echo esc_html( $notice ); ?></div>
		<?php if ( $cart->is_empty() ) : ?>
			<p class="bcp-empty"><?php esc_html_e( 'Your cart is empty.', 'bombora-cart-popup' ); ?></p>
		<?php else : ?>
			<ul class="bcp-items">
				<?php
				foreach ( $visible as $key => $item ) {
					echo self::item( (string) $key, $item, $key === $focus_key ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapováno v item().
				}
				?>
			</ul>
			<?php if ( Settings::get( 'show_subtotal' ) ) : ?>
				<p class="bcp-summary">
					<span><?php esc_html_e( 'Subtotal', 'bombora-cart-popup' ); ?></span>
					<strong class="bcp-subtotal"><?php echo wp_kses_post( $cart->get_cart_subtotal() ); ?></strong>
				</p>
			<?php endif; ?>
		<?php endif; ?>
		<div class="bcp-footer">
			<?php
			$shortcode = (string) Settings::get( 'shortcode' );
			if ( '' !== $shortcode ) {
				// Admin (manage_options) smí zadat jen registrované shortcody – žádné volné HTML (Settings::sanitize_shortcodes).
				// Výstup shortcodu generuje kód nainstalovaného pluginu (např. WPify vč. <style>), proto se nefiltruje
				// přes wp_kses_post – stejně jako WordPress nefiltruje výstup shortcodů v obsahu stránek.
				echo '<div class="bcp-extra">' . do_shortcode( $shortcode ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>
			<div class="bcp-actions">
				<?php if ( Settings::get( 'show_continue' ) ) : ?>
					<button type="button" class="bcp-btn bcp-btn--ghost" data-bcp-close><?php echo esc_html( Settings::text( 'txt_continue' ) ); ?></button>
				<?php endif; ?>
				<a class="bcp-btn" href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php echo esc_html( Settings::text( 'txt_view_cart' ) ); ?></a>
				<?php if ( Settings::get( 'show_checkout' ) && ! $cart->is_empty() ) : ?>
					<a class="bcp-btn" href="<?php echo esc_url( wc_get_checkout_url() ); ?>"><?php echo esc_html( Settings::text( 'txt_checkout' ) ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,mixed> $item Položka košíku.
	 */
	private static function item( string $key, array $item, bool $focus ): string {
		$product = apply_filters( 'woocommerce_cart_item_product', $item['data'] ?? null, $item, $key );
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}
		$qty       = (int) $item['quantity'];
		$permalink = (string) apply_filters( 'woocommerce_cart_item_permalink', $product->is_visible() ? $product->get_permalink( $item ) : '', $item, $key );
		$is_bundle = ! empty( $item['woosb_ids'] );
		$fixed_qty = $product->is_sold_individually();
		$max       = (int) $product->get_max_purchase_quantity();
		$min       = max( 1, (int) $product->get_min_purchase_quantity() );

		// Jednotková cena pro okamžitý přepočet v JS (u bundlů počítá cenu plugin bundle → jen ze serveru).
		$unit = '';
		if ( ! $is_bundle ) {
			$unit = WC()->cart->display_prices_including_tax()
				? wc_get_price_including_tax( $product )
				: wc_get_price_excluding_tax( $product );
			$unit = wc_format_decimal( $unit, wc_get_price_decimals() );
		}

		$thumb = apply_filters( 'woocommerce_cart_item_thumbnail', $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ), $item, $key );
		$name  = $product->get_name();
		$price = apply_filters( 'woocommerce_cart_item_price', WC()->cart->get_product_price( $product ), $item, $key );
		$sub   = apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $product, $qty ), $item, $key );

		ob_start();
		?>
		<li class="bcp-item<?php echo $focus ? ' is-focus' : ''; ?>" data-key="<?php echo esc_attr( $key ); ?>"<?php echo '' !== $unit ? ' data-unit="' . esc_attr( $unit ) . '"' : ''; ?>>
			<?php if ( $permalink ) : ?>
				<a class="bcp-thumb" href="<?php echo esc_url( $permalink ); ?>" tabindex="-1"><?php echo wp_kses_post( $thumb ); ?></a>
			<?php else : ?>
				<span class="bcp-thumb"><?php echo wp_kses_post( $thumb ); ?></span>
			<?php endif; ?>
			<div class="bcp-info">
				<?php if ( $permalink ) : ?>
					<a class="bcp-name" href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $name ); ?></a>
				<?php else : ?>
					<span class="bcp-name"><?php echo esc_html( $name ); ?></span>
				<?php endif; ?>
				<span class="bcp-unit"><?php echo wp_kses_post( $price ); ?></span>
			</div>
			<div class="bcp-qty">
				<?php if ( $fixed_qty ) : ?>
					<span class="bcp-qty__fixed">1&times;</span>
				<?php else : ?>
					<button type="button" class="bcp-qty__btn" data-step="-1" aria-label="<?php esc_attr_e( 'Decrease quantity', 'bombora-cart-popup' ); ?>">&minus;</button>
					<input class="bcp-qty__input" type="number" inputmode="numeric" step="1" min="0"<?php echo $max > 0 ? ' max="' . esc_attr( (string) $max ) . '"' : ''; ?> data-min="<?php echo esc_attr( (string) $min ); ?>" value="<?php echo esc_attr( (string) $qty ); ?>" aria-label="<?php esc_attr_e( 'Quantity', 'bombora-cart-popup' ); ?>">
					<button type="button" class="bcp-qty__btn" data-step="1" aria-label="<?php esc_attr_e( 'Increase quantity', 'bombora-cart-popup' ); ?>">+</button>
				<?php endif; ?>
			</div>
			<div class="bcp-line"><?php echo wp_kses_post( $sub ); ?></div>
			<?php if ( Settings::get( 'show_remove' ) ) : ?>
				<button type="button" class="bcp-remove" aria-label="<?php esc_attr_e( 'Remove', 'bombora-cart-popup' ); ?>">&times;</button>
			<?php endif; ?>
		</li>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Lze produkt přidat jedním klikem bez výběru možností?
	 * Jednoduchý produkt, nebo WPC bundle s pevnými položkami (bez variant a volitelných položek) –
	 * stejné pravidlo, jaké používá WPC Product Bundles pro tlačítko ve výpisu.
	 */
	public static function can_add_directly( \WC_Product $p ): bool {
		if ( ! $p->supports( 'ajax_add_to_cart' ) || ! $p->is_purchasable() || ! $p->is_in_stock() ) {
			return false;
		}
		if ( $p->is_type( 'simple' ) ) {
			return true;
		}
		if ( $p->is_type( 'woosb' ) ) {
			$variables = method_exists( $p, 'has_variables' ) ? (bool) $p->has_variables() : true;
			$optional  = method_exists( $p, 'has_optional' ) ? (bool) $p->has_optional() : true;
			return ! $variables && ! $optional;
		}
		return false;
	}

	public static function related( int $product_id ): string {
		$products = Related::get( $product_id );
		if ( empty( $products ) ) {
			return '';
		}
		ob_start();
		?>
		<section class="bcp-related" aria-labelledby="bcp-rel-title">
			<h3 class="bcp-related__title" id="bcp-rel-title"><?php echo esc_html( Settings::text( 'txt_related' ) ); ?></h3>
			<ul class="bcp-rel">
				<?php foreach ( $products as $p ) : ?>
					<li class="bcp-rel__item">
						<a class="bcp-rel__link" href="<?php echo esc_url( $p->get_permalink() ); ?>">
							<?php echo wp_kses_post( $p->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy' ) ) ); ?>
							<span class="bcp-rel__name"><?php echo esc_html( $p->get_name() ); ?></span>
						</a>
						<span class="bcp-rel__price"><?php echo wp_kses_post( $p->get_price_html() ); ?></span>
						<?php if ( self::can_add_directly( $p ) ) : ?>
							<button type="button" class="bcp-btn bcp-rel__add" data-product="<?php echo esc_attr( (string) $p->get_id() ); ?>"><?php echo esc_html( $p->add_to_cart_text() ); ?></button>
						<?php else : ?>
							<a class="bcp-btn bcp-rel__add" href="<?php echo esc_url( $p->get_permalink() ); ?>"><?php echo esc_html( $p->add_to_cart_text() ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
