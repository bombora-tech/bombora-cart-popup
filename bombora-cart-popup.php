<?php
/**
 * Plugin Name:       Bombora Cart Popup
 * Plugin URI:        https://github.com/bombora-tech/bombora-cart-popup
 * Description:       Rychlý a bezpečný pop-up košík pro WooCommerce: zobrazí přidaný produkt, změnu množství s okamžitým přepočtem, shortcode (např. WPify lišta dopravy zdarma) a doporučené produkty. Kompatibilní s WPML/WCML a WPC Product Bundles.
 * Version:           1.3.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Bombora group s.r.o.
 * Author URI:        https://www.zgruzie.cz/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bombora-cart-popup
 * Domain Path:       /languages
 * Update URI:        https://github.com/bombora-tech/bombora-cart-popup
 * WC requires at least: 8.0
 * WC tested up to:   11.3
 *
 * @package Bombora\CartPopup
 */

defined( 'ABSPATH' ) || exit;

define( 'BCP_VERSION', '1.3.0' );
define( 'BCP_FILE', __FILE__ );
define( 'BCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'BCP_URL', plugin_dir_url( __FILE__ ) );
define( 'BCP_BASENAME', plugin_basename( __FILE__ ) );

require_once BCP_DIR . 'includes/class-bcp-settings.php';
require_once BCP_DIR . 'includes/class-bcp-security.php';
require_once BCP_DIR . 'includes/class-bcp-cart.php';
require_once BCP_DIR . 'includes/class-bcp-related.php';
require_once BCP_DIR . 'includes/class-bcp-render.php';
require_once BCP_DIR . 'includes/class-bcp-ajax.php';
require_once BCP_DIR . 'includes/class-bcp-frontend.php';
require_once BCP_DIR . 'includes/class-bcp-admin.php';

// HPOS / Cart & Checkout blocks – plugin se objednávek ani checkoutu nedotýká.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BCP_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BCP_FILE, true );
		}
	}
);

// Aktualizace spravuje Bombora Manager (bombora-tech/bombora-manager).

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'bombora-cart-popup', false, dirname( BCP_BASENAME ) . '/languages' );
	},
	0
);

add_action(
	'plugins_loaded',
	static function () {
		if ( is_admin() ) {
			Bombora\CartPopup\Admin::init();
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		Bombora\CartPopup\Ajax::init();
		Bombora\CartPopup\Frontend::init();
	},
	20
);
