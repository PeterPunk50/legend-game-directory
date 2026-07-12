<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LCDP_Developer {

	// Service packages with pricing
	public static function service_packages() {
		return array(
			'free_listing'      => array( 'name' => 'Free Game Listing',             'price' => 0,    'type' => 'one_time' ),
			'starter_playtest'  => array( 'name' => 'Starter Playtest',              'price' => 99,   'type' => 'one_time' ),
			'targeted_playtest' => array( 'name' => 'Targeted Playtest Campaign',    'price' => 249,  'type' => 'one_time' ),
			'steam_review'      => array( 'name' => 'Steam Store Page Review',       'price' => 149,  'type' => 'one_time' ),
			'guide_package'     => array( 'name' => 'Guide & Game Profile Package',  'price' => 225,  'type' => 'one_time' ),
			'launch_essentials' => array( 'name' => 'Launch Essentials',             'price' => 399,  'type' => 'one_time' ),
			'complete_launch'   => array( 'name' => 'Complete Launch Campaign',      'price' => 649,  'type' => 'one_time' ),
			'custom_qa'         => array( 'name' => 'Custom Research & QA Campaign', 'price' => 750,  'type' => 'custom' ),
			'founding_pilot'    => array( 'name' => 'Founding Developer Pilot',      'price' => 99,   'type' => 'one_time', 'pilot' => true ),
		);
	}

	// Membership plans
	public static function membership_plans() {
		return array(
			'developer_starter' => array(
				'name'          => 'Developer Starter',
				'price_monthly' => 29,
				'discount'      => 5,
				'max_games'     => 1,
				'features'      => array(
					'Enhanced game profile',
					'Developer profile',
					'1 monthly game update',
					'Player follow & interest tracking',
					'Community feedback inbox',
					'Basic analytics',
					'5% discount on Legend Create services',
					'Guide & playtest request forms',
				),
			),
			'developer_growth' => array(
				'name'          => 'Developer Growth',
				'price_monthly' => 59,
				'discount'      => 10,
				'max_games'     => 3,
				'features'      => array(
					'Everything in Starter',
					'Up to 3 active games',
					'Tester interest waitlists',
					'Audience-match estimates',
					'Monthly analytics report',
					'Developer update posts',
					'1 minor guide update every 3 months',
					'Priority campaign scheduling',
					'10% discount on platform fees',
				),
			),
			'developer_pro' => array(
				'name'          => 'Developer Pro',
				'price_monthly' => 99,
				'discount'      => 15,
				'max_games'     => 5,
				'features'      => array(
					'Everything in Growth',
					'Up to 5 active games',
					'Priority tester recruitment',
					'Advanced audience analytics',
					'Quarterly Steam-page mini-review',
					'Featured-directory rotation eligibility',
					'Campaign history dashboard',
					'Expert-review request access',
					'Priority support',
					'15% discount on platform fees',
				),
			),
			'studio_partner' => array(
				'name'          => 'Studio Partner',
				'price_monthly' => 199,
				'discount'      => 20,
				'max_games'     => 15,
				'features'      => array(
					'Up to 15 games',
					'Multiple staff accounts',
					'Shared studio dashboard',
					'White-label report exports',
					'Priority expert matching',
					'Quarterly strategy call',
					'Custom campaign quotations',
					'20% discount on platform fees',
				),
			),
		);
	}

	// Add-ons
	public static function addons() {
		return array(
			'extra_tester'         => array( 'name' => 'Additional Tester',              'price_min' => 10,  'price_max' => 15  ),
			'specialist_tester'    => array( 'name' => 'Specialist Tester',              'price_min' => 20,  'price_max' => 35  ),
			'extra_cohort'         => array( 'name' => 'Additional Testing Cohort',      'price'     => 49,                     ),
			'extra_platform'       => array( 'name' => 'Additional Platform',            'price'     => 49,                     ),
			'expert_call_30'       => array( 'name' => '30-Minute Expert Call',          'price'     => 75,                     ),
			'level_design_review'  => array( 'name' => 'Level Design Review',            'price_min' => 125                     ),
			'tech_art_review'      => array( 'name' => 'Technical Art Review',           'price_min' => 125                     ),
			'extra_guide'          => array( 'name' => 'Additional Guide',               'price_min' => 125, 'price_max' => 225  ),
			'guide_minor_update'   => array( 'name' => 'Minor Guide Update',             'price'     => 35,                     ),
			'guide_major_update'   => array( 'name' => 'Major Patch Guide Update',       'price_min' => 75,  'price_max' => 125  ),
			'sponsored_7day'       => array( 'name' => 'Sponsored Directory Placement (7 days)', 'price' => 75,                 ),
			'sponsored_article'    => array( 'name' => 'Sponsored Feature Article',      'price'     => 150,                    ),
			'sponsored_spotlight'  => array( 'name' => 'Sponsored Launch Spotlight',     'price'     => 250,                    ),
			'rush_delivery'        => array( 'name' => 'Rush Delivery (25% surcharge)',  'price_pct' => 25,                     ),
			'custom_video'         => array( 'name' => 'Custom Video Editing',           'price_min' => 0, 'custom_quote' => true ),
		);
	}

	public function __construct() {
		add_action( 'wp_ajax_lcdp_save_developer_profile', array( $this, 'ajax_save_profile' ) );
		add_action( 'wp_ajax_lcdp_submit_game',            array( $this, 'ajax_submit_game' ) );
		add_action( 'lcdp_membership_redeemed',            array( $this, 'on_membership_redeemed' ), 10, 2 );
	}

	public static function get_profile( $user_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . LCDP_Database::table('developer_profiles') . ' WHERE user_id=%d',
			$user_id
		) );
	}

	public static function create_or_update_profile( $user_id, $data ) {
		global $wpdb;
		$existing = self::get_profile( $user_id );
		$now      = current_time('mysql');
		$clean    = array(
			'studio_name'    => sanitize_text_field( $data['studio_name'] ?? '' ),
			'studio_website' => esc_url_raw( $data['studio_website'] ?? '' ),
			'bio'            => sanitize_textarea_field( $data['bio'] ?? '' ),
			'updated_at'     => $now,
		);
		if ( $existing ) {
			$wpdb->update( LCDP_Database::table('developer_profiles'), $clean, array( 'user_id' => $user_id ) );
		} else {
			$clean['user_id']    = absint( $user_id );
			$clean['created_at'] = $now;
			$wpdb->insert( LCDP_Database::table('developer_profiles'), $clean );
			// Add role
			$user = new WP_User( $user_id );
			$user->add_role( 'lcdp_developer' );
			LCDP_Consent::record( $user_id, 'account_creation', true, 'developer_registration' );
		}
		return true;
	}

	public function ajax_save_profile() {
		LCDP_Security::ajax_check( 'lcdp_developer_nonce', 'lcdp_manage_developer_profile' );
		$user_id = get_current_user_id();
		$result  = self::create_or_update_profile( $user_id, $_POST );
		wp_send_json_success( array( 'message' => 'Profile saved.' ) );
	}

	public function ajax_submit_game() {
		LCDP_Security::ajax_check( 'lcdp_game_nonce', 'lcdp_submit_game' );
		$user_id = get_current_user_id();
		$data = LCDP_Security::sanitize_form( $_POST, array(
			'game_title'       => 'text',
			'game_description' => 'textarea',
			'studio_name'      => 'text',
			'genre'            => 'text',
			'platforms'        => 'array',
			'dev_stage'        => 'text',
			'trailer_url'      => 'url',
			'steam_url'        => 'url',
			'demo_url'         => 'url',
			'official_url'     => 'url',
		) );

		if ( empty( $data['game_title'] ) ) {
			wp_send_json_error( array( 'message' => 'Game title is required.' ) );
		}

		$post_id = wp_insert_post( array(
			'post_type'    => 'lcdp_game',
			'post_title'   => $data['game_title'],
			'post_content' => $data['game_description'],
			'post_status'  => 'pending',
			'post_author'  => $user_id,
		) );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		$meta_fields = array( 'trailer_url', 'steam_url', 'demo_url', 'official_url', 'dev_stage' );
		foreach ( $meta_fields as $field ) {
			if ( ! empty( $data[ $field ] ) ) {
				update_post_meta( $post_id, '_lcdp_game_' . $field, $data[ $field ] );
			}
		}
		if ( ! empty( $data['platforms'] ) ) {
			wp_set_object_terms( $post_id, $data['platforms'], 'lcdp_platform' );
		}
		if ( ! empty( $data['genre'] ) ) {
			wp_set_object_terms( $post_id, array( $data['genre'] ), 'lcdp_genre' );
		}
		if ( ! empty( $data['dev_stage'] ) ) {
			wp_set_object_terms( $post_id, array( $data['dev_stage'] ), 'lcdp_dev_stage' );
		}

		update_post_meta( $post_id, '_lcdp_developer_user_id', $user_id );
		LCDP_Security::audit( 'game_submitted', "Game '{$data['game_title']}' submitted for review", 'lcdp_game', $post_id );
		do_action( 'lcdp_game_submitted', $post_id, $user_id );

		wp_send_json_success( array(
			'message' => 'Game submitted for review. We will review it within 2-3 business days.',
			'post_id' => $post_id,
		) );
	}

	// When game post status changes to publish → fire approved hook
	public static function maybe_fire_game_approved( $new_status, $old_status, $post ) {
		if ( 'publish' === $new_status && 'publish' !== $old_status && 'lcdp_game' === $post->post_type ) {
			do_action( 'lcdp_game_approved', $post->ID );
			LCDP_Email::send_developer_notification( get_post_meta( $post->ID, '_lcdp_developer_user_id', true ), 'game_approved', $post->ID );
		}
	}

	public function on_membership_redeemed( $user_id, $expiry ) {
		LCDP_Email::send_developer_notification( $user_id, 'membership_token_redeemed', 0 );
	}

	// Calculate effective service price after membership discount
	public static function calculate_price( $package_key, $user_id ) {
		$packages = self::service_packages();
		if ( ! isset( $packages[ $package_key ] ) ) { return false; }
		$base = $packages[ $package_key ]['price'];
		$profile = self::get_profile( $user_id );
		$discount = 0;
		if ( $profile ) {
			$plans = self::membership_plans();
			$plan  = $plans[ $profile->membership_plan ] ?? null;
			if ( $plan && strtotime( $profile->membership_expires ) > time() ) {
				$discount = $plan['discount'];
			}
		}
		// Discounts apply to platform fee only (not tester rewards etc)
		$platform_portion = $base * 0.60; // approximate platform portion
		$discounted       = $platform_portion * ( 1 - $discount / 100 );
		$remainder        = $base - $platform_portion;
		$total            = round( $discounted + $remainder, 2 );
		return array(
			'base'          => $base,
			'discount_pct'  => $discount,
			'final'         => $total,
			'savings'       => round( $base - $total, 2 ),
		);
	}
}

add_action( 'transition_post_status', array( 'LCDP_Developer', 'maybe_fire_game_approved' ), 10, 3 );
