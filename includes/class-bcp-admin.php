<?php
/**
 * Administrace: menu „Bombora“ → „Pop-up košík“. Settings API (nonce + capability řeší WordPress),
 * sanitizace v Settings::sanitize(). Vše jen pro manage_options.
 *
 * @package Bombora\CartPopup
 */

namespace Bombora\CartPopup;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public const CAP       = 'manage_options';
	public const PARENT    = 'bombora';
	public const PAGE      = 'bombora-cart-popup';
	public const GROUP     = 'bcp_settings_group';
	private const CHECK_ACTION = 'bcp_check_updates';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_' . self::CHECK_ACTION, array( __CLASS__, 'check_updates' ) );
		add_filter( 'plugin_action_links_' . BCP_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	public static function menu(): void {
		global $admin_page_hooks;

		// Sdílené menu „Bombora“ pro všechny firemní pluginy – vytvoří ho první, kdo přijde.
		if ( empty( $admin_page_hooks[ self::PARENT ] ) ) {
			add_menu_page(
				'Bombora',
				'Bombora',
				self::CAP,
				self::PARENT,
				array( __CLASS__, 'render_overview' ),
				'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M3 3h8.5a4 4 0 0 1 2.6 7A4.5 4.5 0 0 1 11.5 17H3V3zm3 3v3h5a1.5 1.5 0 0 0 0-3H6zm0 6v2.5h5.5a1.25 1.25 0 0 0 0-2.5H6z"/></svg>' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				56
			);
			add_submenu_page( self::PARENT, 'Bombora', __( 'Overview', 'bombora-cart-popup' ), self::CAP, self::PARENT, array( __CLASS__, 'render_overview' ) );
		}

		add_submenu_page(
			self::PARENT,
			__( 'Cart popup', 'bombora-cart-popup' ),
			__( 'Cart popup', 'bombora-cart-popup' ),
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * @param string $hook Aktuální admin stránka.
	 */
	public static function assets( $hook ): void {
		if ( ! is_string( $hook ) || ( false === strpos( $hook, self::PAGE ) && 'toplevel_page_' . self::PARENT !== $hook ) ) {
			return;
		}
		wp_enqueue_style( 'bcp-admin', BCP_URL . 'assets/css/admin.css', array(), BCP_VERSION . '.' . (int) @filemtime( BCP_DIR . 'assets/css/admin.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * @param string[] $links Odkazy u pluginu.
	 * @return string[]
	 */
	public static function action_links( $links ): array {
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'bombora-cart-popup' ) . '</a>' );
		return $links;
	}

	public static function check_updates(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bombora-cart-popup' ), 403 );
		}
		check_admin_referer( self::CHECK_ACTION );
		Updater::clear_cache();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'bcp-checked' => '1' ), admin_url( 'admin.php' ) ) . '#bcp-updates' );
		exit;
	}

	public static function render_overview(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$plugins = (array) apply_filters(
			'bombora_admin_plugins',
			array(
				array(
					'name' => 'Bombora Cart Popup',
					'desc' => __( 'Fast, secure add-to-cart popup for WooCommerce.', 'bombora-cart-popup' ),
					'url'  => admin_url( 'admin.php?page=' . self::PAGE ),
					'ver'  => BCP_VERSION,
				),
			)
		);
		?>
		<div class="wrap bcp-admin">
			<header class="bcp-admin__head">
				<h1>Bombora</h1>
				<p><?php esc_html_e( 'In-house plugins of Bombora group s.r.o.', 'bombora-cart-popup' ); ?></p>
			</header>
			<div class="bcp-cards">
				<?php foreach ( $plugins as $p ) : ?>
					<a class="bcp-card bcp-card--link" href="<?php echo esc_url( (string) ( $p['url'] ?? '' ) ); ?>">
						<h2><?php echo esc_html( (string) ( $p['name'] ?? '' ) ); ?> <span class="bcp-badge"><?php echo esc_html( (string) ( $p['ver'] ?? '' ) ); ?></span></h2>
						<p><?php echo esc_html( (string) ( $p['desc'] ?? '' ) ); ?></p>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s    = Settings::all();
		$name = Settings::OPTION;
		?>
		<div class="wrap bcp-admin">
			<header class="bcp-admin__head">
				<h1><?php esc_html_e( 'Cart popup', 'bombora-cart-popup' ); ?> <span class="bcp-badge">v<?php echo esc_html( BCP_VERSION ); ?></span></h1>
				<p><?php esc_html_e( 'Shows the added product, lets the customer change the quantity with instant recalculation and suggests products to add.', 'bombora-cart-popup' ); ?></p>
			</header>

			<?php settings_errors(); ?>

			<form method="post" action="options.php" class="bcp-form">
				<?php settings_fields( self::GROUP ); ?>

				<div class="bcp-cards">
					<section class="bcp-card">
						<h2><?php esc_html_e( 'General', 'bombora-cart-popup' ); ?></h2>
						<?php
						self::toggle( $name, 'enabled', $s, __( 'Enable popup', 'bombora-cart-popup' ) );
						self::toggle( $name, 'on_single', $s, __( 'Product page (add-to-cart form)', 'bombora-cart-popup' ), __( 'Includes WPC bundles and variable products.', 'bombora-cart-popup' ) );
						self::toggle( $name, 'on_loop', $s, __( 'Product listings (AJAX buttons)', 'bombora-cart-popup' ) );
						self::toggle( $name, 'on_mobile', $s, __( 'Show on mobile', 'bombora-cart-popup' ) );
						?>
						<div class="bcp-field">
							<label for="bcp-show_mode"><?php esc_html_e( 'Products shown in popup', 'bombora-cart-popup' ); ?></label>
							<select id="bcp-show_mode" name="<?php echo esc_attr( $name ); ?>[show_mode]">
								<option value="added" <?php selected( $s['show_mode'], 'added' ); ?>><?php esc_html_e( 'Only the added product', 'bombora-cart-popup' ); ?></option>
								<option value="cart" <?php selected( $s['show_mode'], 'cart' ); ?>><?php esc_html_e( 'Whole cart', 'bombora-cart-popup' ); ?></option>
							</select>
						</div>
					</section>

					<section class="bcp-card">
						<h2><?php esc_html_e( 'Content', 'bombora-cart-popup' ); ?></h2>
						<?php
						self::toggle( $name, 'show_subtotal', $s, __( 'Show cart subtotal', 'bombora-cart-popup' ) );
						self::toggle( $name, 'show_remove', $s, __( 'Allow removing the product', 'bombora-cart-popup' ) );
						self::toggle( $name, 'show_continue', $s, __( '“Continue shopping” button', 'bombora-cart-popup' ) );
						self::toggle( $name, 'show_checkout', $s, __( '“Checkout” button', 'bombora-cart-popup' ) );
						?>
						<div class="bcp-field">
							<label for="bcp-shortcode"><?php esc_html_e( 'Shortcode below the cart', 'bombora-cart-popup' ); ?></label>
							<input type="text" class="regular-text code" id="bcp-shortcode" name="<?php echo esc_attr( $name ); ?>[shortcode]" value="<?php echo esc_attr( (string) $s['shortcode'] ); ?>" placeholder="[wpify_woo_free_shipping_notice]">
							<p class="description"><?php esc_html_e( 'Only registered shortcodes are allowed (max. 3), no HTML. E.g. the WPify free shipping bar.', 'bombora-cart-popup' ); ?></p>
						</div>
					</section>

					<section class="bcp-card">
						<h2><?php esc_html_e( 'Suggested products', 'bombora-cart-popup' ); ?></h2>
						<div class="bcp-field">
							<label for="bcp-related_source"><?php esc_html_e( 'Source', 'bombora-cart-popup' ); ?></label>
							<select id="bcp-related_source" name="<?php echo esc_attr( $name ); ?>[related_source]">
								<option value="smart" <?php selected( $s['related_source'], 'smart' ); ?>><?php esc_html_e( 'Cross-sells → upsells → same category', 'bombora-cart-popup' ); ?></option>
								<option value="cross" <?php selected( $s['related_source'], 'cross' ); ?>><?php esc_html_e( 'Cross-sells only', 'bombora-cart-popup' ); ?></option>
								<option value="category" <?php selected( $s['related_source'], 'category' ); ?>><?php esc_html_e( 'Same category only', 'bombora-cart-popup' ); ?></option>
								<option value="none" <?php selected( $s['related_source'], 'none' ); ?>><?php esc_html_e( 'Do not show', 'bombora-cart-popup' ); ?></option>
							</select>
						</div>
						<div class="bcp-field">
							<label for="bcp-related_count"><?php esc_html_e( 'Number of products', 'bombora-cart-popup' ); ?></label>
							<input type="number" min="0" max="8" step="1" id="bcp-related_count" name="<?php echo esc_attr( $name ); ?>[related_count]" value="<?php echo esc_attr( (string) $s['related_count'] ); ?>">
							<p class="description"><?php esc_html_e( 'Products already in the cart, out of stock or hidden are skipped. Results are cached for 6 hours per language.', 'bombora-cart-popup' ); ?></p>
						</div>
					</section>

					<section class="bcp-card">
						<h2><?php esc_html_e( 'Appearance', 'bombora-cart-popup' ); ?></h2>
						<div class="bcp-field">
							<label for="bcp-accent"><?php esc_html_e( 'Button colour', 'bombora-cart-popup' ); ?></label>
							<input type="color" id="bcp-accent" name="<?php echo esc_attr( $name ); ?>[accent]" value="<?php echo esc_attr( (string) $s['accent'] ); ?>">
						</div>
						<div class="bcp-field">
							<label for="bcp-radius"><?php esc_html_e( 'Corner radius (px)', 'bombora-cart-popup' ); ?></label>
							<input type="number" min="0" max="30" id="bcp-radius" name="<?php echo esc_attr( $name ); ?>[radius]" value="<?php echo esc_attr( (string) $s['radius'] ); ?>">
						</div>
						<div class="bcp-field">
							<label for="bcp-max_width"><?php esc_html_e( 'Maximum width (px)', 'bombora-cart-popup' ); ?></label>
							<input type="number" min="420" max="1200" step="10" id="bcp-max_width" name="<?php echo esc_attr( $name ); ?>[max_width]" value="<?php echo esc_attr( (string) $s['max_width'] ); ?>">
						</div>
					</section>

					<section class="bcp-card bcp-card--wide">
						<h2><?php esc_html_e( 'Texts', 'bombora-cart-popup' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Leave empty to use the built-in translation for each language (EN, NL, CS, SK, DE, FR). Custom texts can be translated in WPML → String Translation (domain admin_texts_bcp_settings).', 'bombora-cart-popup' ); ?></p>
						<div class="bcp-grid">
							<?php
							$labels = array(
								'txt_heading'   => __( 'Heading', 'bombora-cart-popup' ),
								'txt_related'   => __( 'Suggested products heading', 'bombora-cart-popup' ),
								'txt_view_cart' => __( '“View cart” button', 'bombora-cart-popup' ),
								'txt_continue'  => __( '“Continue shopping” button', 'bombora-cart-popup' ),
								'txt_checkout'  => __( '“Checkout” button', 'bombora-cart-popup' ),
							);
							foreach ( $labels as $key => $label ) :
								$placeholder = Settings::default_text( $key );
								?>
								<div class="bcp-field">
									<label for="bcp-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
									<input type="text" maxlength="120" id="bcp-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( (string) $s[ $key ] ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>">
								</div>
							<?php endforeach; ?>
						</div>
					</section>
				</div>

				<?php submit_button( __( 'Save settings', 'bombora-cart-popup' ) ); ?>
			</form>

			<?php self::render_updates(); ?>
		</div>
		<?php
	}

	private static function render_updates(): void {
		$info    = Updater::latest( false );
		$latest  = is_array( $info ) ? (string) $info['version'] : '';
		$checked = isset( $_GET['bcp-checked'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jen zobrazení hlášky.
		?>
		<section class="bcp-card bcp-card--wide" id="bcp-updates">
			<h2><?php esc_html_e( 'Updates', 'bombora-cart-popup' ); ?></h2>
			<table class="bcp-kv">
				<tr><th><?php esc_html_e( 'Installed version', 'bombora-cart-popup' ); ?></th><td><code><?php echo esc_html( BCP_VERSION ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Latest release', 'bombora-cart-popup' ); ?></th><td>
					<?php if ( '' !== $latest ) : ?>
						<code><?php echo esc_html( $latest ); ?></code>
						<?php if ( version_compare( $latest, BCP_VERSION, '>' ) ) : ?>
							<span class="bcp-badge bcp-badge--warn"><?php esc_html_e( 'Update available', 'bombora-cart-popup' ); ?></span>
						<?php else : ?>
							<span class="bcp-badge bcp-badge--ok"><?php esc_html_e( 'Up to date', 'bombora-cart-popup' ); ?></span>
						<?php endif; ?>
					<?php else : ?>
						<span class="bcp-badge bcp-badge--warn"><?php esc_html_e( 'Could not be checked', 'bombora-cart-popup' ); ?></span>
						<?php $err = Updater::last_error(); ?>
						<?php if ( '' !== $err ) : ?>
							<span class="description"><?php echo esc_html( $err ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
				</td></tr>
				<tr><th><?php esc_html_e( 'Repository', 'bombora-cart-popup' ); ?></th><td><code><?php echo esc_html( Updater::repo() ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Access token', 'bombora-cart-popup' ); ?></th><td><?php echo Updater::has_token() ? esc_html__( 'Set in wp-config.php (BOMBORA_GITHUB_TOKEN)', 'bombora-cart-popup' ) : esc_html__( 'Not set – only needed for a private repository', 'bombora-cart-popup' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Integrity check', 'bombora-cart-popup' ); ?></th><td><?php esc_html_e( 'Every update ZIP is verified with SHA-256 before installation.', 'bombora-cart-popup' ); ?></td></tr>
			</table>
			<?php if ( current_user_can( 'update_plugins' ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::CHECK_ACTION ); ?>">
					<?php wp_nonce_field( self::CHECK_ACTION ); ?>
					<?php submit_button( __( 'Check for updates now', 'bombora-cart-popup' ), 'secondary', 'submit', false ); ?>
					<?php if ( $checked ) : ?>
						<span class="bcp-checked"><?php esc_html_e( 'Checked.', 'bombora-cart-popup' ); ?></span>
					<?php endif; ?>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Přepínač (checkbox) s hidden 0, aby se odškrtnutí uložilo.
	 *
	 * @param string              $name  Název option.
	 * @param string              $key   Klíč.
	 * @param array<string,mixed> $s     Nastavení.
	 * @param string              $label Popisek.
	 * @param string              $help  Nápověda.
	 */
	private static function toggle( string $name, string $key, array $s, string $label, string $help = '' ): void {
		$id = 'bcp-' . $key;
		?>
		<div class="bcp-field bcp-field--toggle">
			<input type="hidden" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>" value="0">
			<label class="bcp-switch" for="<?php echo esc_attr( $id ); ?>">
				<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?>>
				<span class="bcp-switch__ui" aria-hidden="true"></span>
				<span class="bcp-switch__label"><?php echo esc_html( $label ); ?></span>
			</label>
			<?php if ( '' !== $help ) : ?>
				<p class="description"><?php echo esc_html( $help ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
