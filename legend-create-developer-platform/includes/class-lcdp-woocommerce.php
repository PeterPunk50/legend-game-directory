<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * WooCommerce integration — creates service products, handles memberships.
 * Requires WooCommerce to be active. Membership products are set to manual
 * recurring for pilot (no subscription plugin needed).
 */
class LCDP_WooCommerce {

	public function __construct() {
		add_action( 'init', array( $this, 'maybe_create_products' ), 30 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_service_purchase' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_processed' ), 10, 3 );
		add_filter( 'woocommerce_order_item_name', array( $this, 'filter_order_item_name' ), 10, 2 );
	}

	// Product meta key used to identify Legend Create service products
	const META_KEY = '_lcdp_service_key';

	public function maybe_create_products() {
		if ( ! class_exists('WooCommerce') ) { return; }
		if ( get_option('lcdp_products_created') ) { return; }
		$this->create_all_products();
		update_option( 'lcdp_products_created', '1' );
	}

	private function create_all_products() {
		$packages = LCDP_Developer::service_packages();
		foreach ( $packages as $key => $pkg ) {
			if ( 'custom_qa' === $key ) { continue; } // custom quote only
			$this->create_product( $key, $pkg['name'], $pkg['price'], $pkg['type'] );
		}
		// Membership products
		$plans = LCDP_Developer::membership_plans();
		foreach ( $plans as $key => $plan ) {
			if ( 'studio_partner' === $key ) { continue; } // Phase 2
			$this->create_product(
				'membership_' . $key,
				$plan['name'] . ' Membership',
				$plan['price_monthly'],
				'membership'
			);
		}
	}

	private function create_product( $service_key, $name, $price, $type ) {
		// Check if already exists
		$existing = $this->get_product_by_service_key( $service_key );
		if ( $existing ) { return $existing; }

		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( $price );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' ); // sold via page, not shop
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_description( $this->get_product_description( $service_key ) );
		$product->save();

		update_post_meta( $product->get_id(), self::META_KEY, $service_key );
		update_post_meta( $product->get_id(), '_lcdp_service_type', $type );

		return $product->get_id();
	}

	public static function get_product_by_service_key( $service_key ) {
		$posts = get_posts( array(
			'post_type'   => 'product',
			'meta_key'    => self::META_KEY,
			'meta_value'  => sanitize_key( $service_key ),
			'numberposts' => 1,
			'fields'      => 'ids',
		) );
		return $posts ? $posts[0] : null;
	}

	public static function get_add_to_cart_url( $service_key ) {
		$product_id = self::get_product_by_service_key( $service_key );
		if ( ! $product_id ) { return get_permalink( wc_get_page_id('shop') ); }
		return wc_get_checkout_url() . '?add-to-cart=' . $product_id;
	}

	// Apply membership discount at cart
	public function validate_service_purchase( $passed, $product_id, $quantity ) {
		$service_key = get_post_meta( $product_id, self::META_KEY, true );
		if ( ! $service_key ) { return $passed; }
		$user_id = get_current_user_id();
		if ( ! $user_id ) { return $passed; }
		// Any custom validation rules here
		return $passed;
	}

	// Apply membership discount via price filter
	public static function get_discounted_price( $price, $product_id, $user_id ) {
		$service_key = get_post_meta( $product_id, self::META_KEY, true );
		if ( ! $service_key ) { return $price; }
		$service_type = get_post_meta( $product_id, '_lcdp_service_type', true );
		if ( 'membership' === $service_type ) { return $price; } // no discount on memberships
		$profile = LCDP_Developer::get_profile( $user_id );
		if ( ! $profile ) { return $price; }
		$plans   = LCDP_Developer::membership_plans();
		$plan    = $plans[ $profile->membership_plan ] ?? null;
		if ( ! $plan || ! strtotime( $profile->membership_expires ) || strtotime( $profile->membership_expires ) < time() ) {
			return $price;
		}
		// Discount applies to 60% platform portion only
		$platform_portion = $price * 0.60;
		$other            = $price * 0.40;
		$discounted       = $platform_portion * ( 1 - $plan['discount'] / 100 );
		return round( $discounted + $other, 2 );
	}

	// When order is placed, save developer metadata on order
	public function on_order_processed( $order_id, $posted_data, $order ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) { return; }
		foreach ( $order->get_items() as $item ) {
			$product_id  = $item->get_product_id();
			$service_key = get_post_meta( $product_id, self::META_KEY, true );
			if ( $service_key ) {
				$order->update_meta_data( '_lcdp_service_key', $service_key );
				$order->update_meta_data( '_lcdp_buyer_user_id', $user_id );
				$order->save();
				LCDP_Security::audit( 'service_ordered', "User {$user_id} ordered service: {$service_key}", 'order', $order_id );
				do_action( 'lcdp_service_ordered', $order_id, $service_key, $user_id );
			}
		}
	}

	public function filter_order_item_name( $name, $item ) {
		$product_id  = $item->get_product_id();
		$service_key = get_post_meta( $product_id, self::META_KEY, true );
		if ( $service_key && false !== strpos( $service_key, 'membership_' ) ) {
			$name .= ' <small style="display:block;font-size:0.8em;color:#888">Billed monthly. Manual renewal during pilot.</small>';
		}
		return $name;
	}

	// Public static alias used by frontend shortcode
	public static function get_product_description_safe( $key ) {
		$descriptions = array(
			'free_listing'      => 'Free game listing — basic profile, screenshots and developer links.',
			'starter_playtest'  => '5 matched testers, structured mission, basic bug reports, 3–5 page report. Delivered in 5–7 business days.',
			'targeted_playtest' => '10–15 testers across 2–3 cohorts, custom mission, cohort comparison, 8–12 page report, 30-min developer call.',
			'steam_review'      => 'Full Steam page review: description, capsule art, screenshots, trailer, tags, plus 5 player first-impression tests.',
			'guide_package'     => 'Enhanced permanent game profile plus one original 1,200–1,800 word guide with verified facts and social promotion.',
			'launch_essentials' => '10 targeted testers, playtest report, Steam page audit, enhanced listing, sponsored feature, developer call.',
			'complete_launch'   => '20 matched testers, 2 cohorts, full report, Steam audit, beginner guide, sponsored feature, 3 social posts, launch-week placement.',
			'founding_pilot'    => 'Limited pilot: 5 matched testers, structured mission, basic feedback, permanent game profile, developer call.',
			'membership_developer_starter' => 'Developer Starter membership ($29/month) — enhanced game profile, analytics, 5% service discount.',
			'membership_developer_growth'  => 'Developer Growth membership ($59/month) — up to 3 games, audience matching, 10% service discount.',
			'membership_developer_pro'     => 'Developer Pro membership ($99/month) — up to 5 games, priority recruitment, 15% service discount.',
		);
		return $descriptions[ $key ] ?? '';
	}

	private function get_product_description( $key ) {
		$descriptions = array(
			'free_listing'      => 'Free game listing on Legend Create. Basic profile, screenshots, and developer links.',
			'starter_playtest'  => '5 matched testers, structured test mission, basic bug reports, 3-5 page developer report. Delivered in 5-7 business days.',
			'targeted_playtest' => '10-15 matched testers across 2-3 cohorts, custom mission, cohort comparison, 8-12 page report, 30-min developer call.',
			'steam_review'      => 'Full Steam store page review: description, capsule art, screenshots, trailer, tags, plus 5 player first-impression tests.',
			'guide_package'     => 'Enhanced permanent game profile plus one original 1,200-1,800 word guide with verified facts and social promotion.',
			'launch_essentials' => '10 targeted testers, basic playtest report, Steam page audit, enhanced game listing, sponsored feature, developer call.',
			'complete_launch'   => '20 matched testers, 2 cohorts, full playtest report, Steam audit, beginner guide, sponsored feature, 3 social posts, launch-week placement.',
			'founding_pilot'    => 'Limited pilot offer: 5 matched testers, structured mission, basic feedback, permanent game profile, developer call.',
			'membership_developer_starter' => 'Developer Starter membership ($29/month). Enhanced game profile, developer profile, analytics, 5% service discount.',
			'membership_developer_growth'  => 'Developer Growth membership ($59/month). Up to 3 games, audience matching, monthly reports, 10% service discount.',
			'membership_developer_pro'     => 'Developer Pro membership ($99/month). Up to 5 games, priority recruitment, advanced analytics, 15% service discount.',
		);
		return $descriptions[ $key ] ?? '';
	}
}
