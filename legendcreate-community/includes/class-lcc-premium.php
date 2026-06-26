<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Premium layer — WooCommerce products (paid via Fygaro) that grant timed Premium.
 *
 * Creates two virtual products (Monthly 30d, Annual 365d) and maps them to membership
 * days via the lcc_premium_products option, which LCC_Memberships reads on payment.
 * Prices are PLACEHOLDERS — set the real prices in WooCommerce > Products.
 *
 * Shortcodes: [lcc_premium] (tier comparison + buy), [lcc_premium_content]…[/lcc_premium_content] (gate).
 */
final class LCC_Premium {

	public function __construct() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_setup_products' ) );
		add_shortcode( 'lcc_premium', array( $this, 'premium_shortcode' ) );
		add_shortcode( 'lcc_premium_content', array( $this, 'gate_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets() {
		if ( is_singular() && get_post() && ( has_shortcode( get_post()->post_content, 'lcc_premium' ) || has_shortcode( get_post()->post_content, 'lcc_premium_content' ) ) ) {
			wp_enqueue_style( 'lcc-community', LCC_URL . 'assets/css/community.css', array(), LCC_VERSION );
		}
	}

	// ── Product setup ────────────────────────────────────────────────────────────

	public static function maybe_setup_products() {
		if ( ! function_exists( 'wc_get_product' ) || ! class_exists( 'WC_Product_Simple' ) ) { return; }
		if ( get_option( 'lcc_products_ready' ) ) { return; }

		$monthly = self::ensure_product( __( 'Legend Premium — Monthly', 'legendcreate-community' ), 5.00, 30, 'lcc_product_monthly' );
		$annual  = self::ensure_product( __( 'Legend Premium — Annual', 'legendcreate-community' ), 50.00, 365, 'lcc_product_annual' );

		if ( $monthly && $annual ) {
			update_option( 'lcc_premium_products', array( $monthly => 30, $annual => 365 ) );
			update_option( 'lcc_products_ready', 1 );
		}
	}

	private static function ensure_product( $name, $price, $days, $option ) {
		$existing = (int) get_option( $option, 0 );
		if ( $existing && 'product' === get_post_type( $existing ) ) { return $existing; }

		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_regular_price( (string) $price );
		$product->set_price( (string) $price );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_description( __( 'Timed Legend Premium membership. Set your real price in WooCommerce > Products.', 'legendcreate-community' ) );
		$id = $product->save();

		if ( $id ) {
			update_option( $option, $id );
			update_post_meta( $id, '_lcc_premium_days', $days );
		}
		return $id;
	}

	public static function product_id( $which ) {
		return (int) get_option( 'monthly' === $which ? 'lcc_product_monthly' : 'lcc_product_annual', 0 );
	}

	private static function buy_url( $product_id ) {
		if ( ! $product_id || ! function_exists( 'wc_get_checkout_url' ) ) { return home_url( '/' ); }
		if ( ! is_user_logged_in() ) {
			// Require an account so the order is linked to a user we can grant Premium to.
			$join = (int) get_option( 'lcc_page_register', 0 );
			return wp_login_url( add_query_arg( 'add-to-cart', $product_id, wc_get_checkout_url() ) );
		}
		return add_query_arg( 'add-to-cart', $product_id, wc_get_checkout_url() );
	}

	private static function price_html( $product_id ) {
		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) { return ''; }
		$p = wc_get_product( $product_id );
		if ( ! $p ) { return ''; }
		$price = $p->get_price();
		return ( '' === $price || null === $price ) ? esc_html__( 'Set price in WooCommerce', 'legendcreate-community' ) : wp_kses_post( wc_price( $price ) );
	}

	// ── Shortcodes ───────────────────────────────────────────────────────────────

	public function premium_shortcode() {
		$uid = get_current_user_id();
		if ( $uid && LCC_Memberships::is_premium( $uid ) ) {
			$until = LCC_Memberships::premium_until( $uid );
			$exp   = $until ? date_i18n( get_option( 'date_format' ), strtotime( $until . ' UTC' ) ) : '';
			return '<div class="lcc-shell"><div class="lcc-panel"><h2>' . esc_html__( 'You are a Legend Premium member', 'legendcreate-community' ) . '</h2>'
				. ( $exp ? '<p class="lcc-muted">' . esc_html( sprintf( __( 'Active until %s. Renew anytime below to extend.', 'legendcreate-community' ), $exp ) ) . '</p>' : '' )
				. $this->plans_html() . '</div></div>';
		}

		if ( ! function_exists( 'wc_get_checkout_url' ) ) {
			return '<div class="lcc-shell"><div class="lcc-panel lcc-muted">' . esc_html__( 'Premium checkout is being set up. Please check back soon.', 'legendcreate-community' ) . '</div></div>';
		}

		ob_start();
		echo '<div class="lcc-shell">';
		echo '<div class="lcc-premium-head"><h1>' . esc_html__( 'Legend Premium', 'legendcreate-community' ) . '</h1>';
		echo '<p class="lcc-landing-lead">' . esc_html__( 'Everything in the free membership, plus premium guides, priority testing, advanced squad tools, and an ad-reduced experience.', 'legendcreate-community' ) . '</p></div>';
		echo $this->compare_html();
		echo $this->plans_html();
		echo '<p class="lcc-disclaimer">' . esc_html__( 'Membership is a one-time payment for the period shown — no automatic recurring charges. We email a renewal link before it ends; you renew when you choose. Cancel simply by not renewing.', 'legendcreate-community' ) . '</p>';
		echo '</div>';
		return ob_get_clean();
	}

	private function plans_html() {
		$monthly = self::product_id( 'monthly' );
		$annual  = self::product_id( 'annual' );
		ob_start();
		echo '<div class="lcc-plan-grid">';
		echo '<div class="lcc-plan"><h3>' . esc_html__( 'Monthly', 'legendcreate-community' ) . '</h3>'
			. '<div class="lcc-plan-price">' . self::price_html( $monthly ) . '</div>'
			. '<p class="lcc-muted">' . esc_html__( '30 days of Premium', 'legendcreate-community' ) . '</p>'
			. '<a class="lcc-btn lcc-btn-lg" href="' . esc_url( self::buy_url( $monthly ) ) . '">' . esc_html__( 'Get Monthly', 'legendcreate-community' ) . '</a></div>';
		echo '<div class="lcc-plan lcc-plan-best"><span class="lcc-plan-tag">' . esc_html__( 'Best value', 'legendcreate-community' ) . '</span><h3>' . esc_html__( 'Annual', 'legendcreate-community' ) . '</h3>'
			. '<div class="lcc-plan-price">' . self::price_html( $annual ) . '</div>'
			. '<p class="lcc-muted">' . esc_html__( '365 days of Premium', 'legendcreate-community' ) . '</p>'
			. '<a class="lcc-btn lcc-btn-lg" href="' . esc_url( self::buy_url( $annual ) ) . '">' . esc_html__( 'Get Annual', 'legendcreate-community' ) . '</a></div>';
		echo '</div>';
		return ob_get_clean();
	}

	private function compare_html() {
		$rows = array(
			array( __( 'Profile, squads, ratings, points & badges', 'legendcreate-community' ), true, true ),
			array( __( 'Join public testing & community missions', 'legendcreate-community' ), true, true ),
			array( __( 'Full premium guides & strategy breakdowns', 'legendcreate-community' ), false, true ),
			array( __( 'Early access to new guides', 'legendcreate-community' ), false, true ),
			array( __( 'Priority / exclusive testing opportunities', 'legendcreate-community' ), false, true ),
			array( __( 'Ad-reduced experience & advanced filters', 'legendcreate-community' ), false, true ),
			array( __( 'Advanced squad tools & saved lists', 'legendcreate-community' ), false, true ),
			array( __( 'Monthly premium recommendations & reports', 'legendcreate-community' ), false, true ),
		);
		ob_start();
		echo '<table class="lcc-compare"><thead><tr><th></th><th>' . esc_html__( 'Free', 'legendcreate-community' ) . '</th><th>' . esc_html__( 'Premium', 'legendcreate-community' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( $r[0] ) . '</td><td>' . ( $r[1] ? '✓' : '—' ) . '</td><td>' . ( $r[2] ? '✓' : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		return ob_get_clean();
	}

	/** Enclosing gate: premium members see the content; others get a teaser + CTA. */
	public function gate_shortcode( $atts, $content = '' ) {
		if ( LCC_Memberships::is_premium() ) {
			return do_shortcode( $content );
		}
		$premium = (int) get_option( 'lcc_page_premium', 0 );
		$url     = $premium ? get_permalink( $premium ) : home_url( '/premium/' );
		return '<div class="lcc-premium-gate"><strong>' . esc_html__( 'Premium content', 'legendcreate-community' ) . '</strong>'
			. '<p>' . esc_html__( 'This is part of Legend Premium. Upgrade to unlock the full guide, strategy breakdowns, and early access.', 'legendcreate-community' ) . '</p>'
			. '<a class="lcc-btn" href="' . esc_url( $url ) . '">' . esc_html__( 'Unlock with Premium', 'legendcreate-community' ) . '</a></div>';
	}
}
