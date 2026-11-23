<?php
/**
 * AJAX endpointy (wc-ajax): bcp_add, bcp_popup, bcp_update.
 *
 * Bezpečnost:
 *  - každý endpoint ověřuje Security::is_valid_request() (POST + vlastní hlavička + same-origin);
 *  - přidání do košíku provádí výhradně WooCommerce (WC_Form_Handler) se všemi svými validacemi;
 *    neplatný požadavek je zastaven DŘÍV, než WooCommerce stihne zboží přidat;
 *  - vstupy jsou striktně validované (absint, regex na klíč košíku);
 *  - odpovědi mají no-cache hlavičky; endpointy mění jen košík aktuálního návštěvníka.
 *
 * @package Bombora\CartPopup
 */

namespace Bombora\CartPopup;

defined( 'ABSPATH' ) || exit;

final class Ajax {

	/** @var bool */
	private static $is_add_request = false;

	/** @var bool */
	private static $add_request_valid = false;

	/** @var array{product:int,variation:int,key:string}|null */
	private static $added = null;

	public static function init(): void {
		// Musí proběhnout před WC_Form_Handler::add_to_cart_action (wp_loaded, priorita 20).
		add_action( 'wp_loaded', array( __CLASS__, 'guard_add_request' ), 1 );

		add_action( 'wc_ajax_bcp_add', array( __CLASS__, 'handle_add' ) );
		add_action( 'wc_ajax_bcp_popup', array( __CLASS__, 'handle_popup' ) );
		add_action( 'wc_ajax_bcp_update', array( __CLASS__, 'handle_update' ) );
	}

	private static function current_endpoint(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jen identifikace endpointu, ověření v Security.
		return isset( $_GET['wc-ajax'] ) ? sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ) : '';
	}

	/**
	 * Ochrana přidání do košíku: neplatný požadavek → odstraníme add-to-cart, WooCommerce nic nepřidá.
	 */
	public static function guard_add_request(): void {
		if ( 'bcp_add' !== self::current_endpoint() ) {
			return;
		}
		self::$is_add_request    = true;
		self::$add_request_valid = Security::is_valid_request();

		if ( ! self::$add_request_valid ) {
			unset( $_REQUEST['add-to-cart'], $_POST['add-to-cart'], $_GET['add-to-cart'] );
			return;
		}

		// Žádné přesměrování z WC_Form_Handler během AJAXu.
		add_filter( 'woocommerce_add_to_cart_redirect', '__return_false', PHP_INT_MAX );
		add_filter( 'pre_option_woocommerce_cart_redirect_after_add', array( __CLASS__, 'return_no' ), PHP_INT_MAX );

		// Zachytit hlavní přidaný produkt (priorita 1 = před WPC bundle, který přidává podřízené položky).
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'capture_added' ), 1, 6 );
	}

	public static function return_no(): string {
		return 'no';
	}

	/**
	 * @param string              $key            Klíč položky.
	 * @param int                 $product_id     Produkt.
	 * @param int                 $quantity       Množství.
	 * @param int                 $variation_id   Varianta.
	 * @param array<mixed>        $variation      Atributy varianty.
	 * @param array<string,mixed> $cart_item_data Data položky.
	 */
	public static function capture_added( $key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ): void {
		if ( null !== self::$added ) {
			return;
		}
		if ( is_array( $cart_item_data ) && Cart::is_child_item( $cart_item_data ) ) {
			return;
		}
		self::$added = array(
			'product'   => (int) $product_id,
			'variation' => (int) $variation_id,
			'key'       => (string) $key,
		);
	}

	public static function handle_add(): void {
		if ( ! self::$is_add_request || ! self::$add_request_valid ) {
			self::deny();
		}

		if ( null === self::$added ) {
			$message = Cart::pop_error_notice();
			self::respond(
				array(
					'ok'      => false,
					'message' => '' !== $message ? $message : __( 'The product could not be added to your cart.', 'bombora-cart-popup' ),
				)
			);
		}

		wc_clear_notices(); // Úspěšnou hlášku „přidáno do košíku“ nechceme zobrazit na další stránce.
		WC()->cart->calculate_totals();

		$key = Cart::find_key( self::$added['product'], self::$added['variation'], self::$added['key'] );

		self::respond(
			array(
				'ok'      => true,
				'html'    => Render::popup( $key ),
				'related' => Render::related( self::$added['product'] ),
			) + self::cart_payload()
		);
	}

	/**
	 * Popup po přidání přes standardní AJAX tlačítko WooCommerce (výpisy produktů).
	 */
	public static function handle_popup(): void {
		if ( ! Security::is_valid_request() ) {
			self::deny();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- ověřeno v Security::is_valid_request().
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		WC()->cart->calculate_totals();
		$key        = $product_id ? Cart::find_key( $product_id ) : '';

		self::respond(
			array(
				'ok'      => true,
				'html'    => Render::popup( $key ),
				'related' => $product_id ? Render::related( $product_id ) : '',
			) + self::cart_payload()
		);
	}

	public static function handle_update(): void {
		if ( ! Security::is_valid_request() ) {
			self::deny();
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- ověřeno v Security::is_valid_request().
		$key = isset( $_POST['key'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['key'] ) ) ) : '';
		$qty = isset( $_POST['qty'] ) ? (int) wp_unslash( $_POST['qty'] ) : -1;
		// phpcs:enable

		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) || $qty < 0 || $qty > 9999 ) {
			self::respond(
				array(
					'ok'      => false,
					'message' => __( 'Something went wrong. Please try again.', 'bombora-cart-popup' ),
				),
				400
			);
		}

		$result = Cart::set_quantity( $key, $qty );
		$notice = is_wp_error( $result ) ? $result->get_error_message() : '';
		wc_clear_notices();

		WC()->cart->calculate_totals();

		$focus = isset( WC()->cart->get_cart()[ $key ] ) ? $key : '';

		self::respond(
			array(
				'ok'   => ! is_wp_error( $result ),
				'html' => Render::popup( $focus, $notice ),
			) + self::cart_payload()
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function cart_payload(): array {
		return array(
			'fragments' => Cart::fragments(),
			'cart_hash' => WC()->cart ? WC()->cart->get_cart_hash() : '',
			'count'     => WC()->cart ? (int) WC()->cart->get_cart_contents_count() : 0,
		);
	}

	/**
	 * @param array<string,mixed> $data   Data odpovědi.
	 * @param int                 $status HTTP status.
	 * @return never
	 */
	private static function respond( array $data, int $status = 200 ) {
		wc_nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		wp_send_json( $data, $status );
	}

	/**
	 * @return never
	 */
	private static function deny() {
		self::respond(
			array(
				'ok'      => false,
				'message' => __( 'Request blocked for security reasons. Please reload the page.', 'bombora-cart-popup' ),
			),
			403
		);
	}
}
