<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Post_Types {
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_all' ) );
		add_action( 'init', array( __CLASS__, 'register_meta' ), 20 );
	}

	public static function register_all() {
		register_post_type( 'game', array(
			'labels' => array( 'name' => __( 'Games', 'legend-game-directory' ), 'singular_name' => __( 'Game', 'legend-game-directory' ), 'add_new_item' => __( 'Add Game', 'legend-game-directory' ), 'edit_item' => __( 'Edit Game', 'legend-game-directory' ) ),
			'public' => true, 'show_in_rest' => true, 'has_archive' => 'games',
			'rewrite' => array( 'slug' => 'games', 'with_front' => false ), 'menu_icon' => 'dashicons-games',
			'supports' => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'custom-fields' ),
			'map_meta_cap' => true, 'capability_type' => array( 'game', 'games' ),
		) );
		self::taxonomy( 'game_type', __( 'Game Types', 'legend-game-directory' ), 'game-type' );
		self::taxonomy( 'game_platform', __( 'Platforms', 'legend-game-directory' ), 'platform' );
		self::taxonomy( 'game_genre', __( 'Genres', 'legend-game-directory' ), 'genre' );
		self::taxonomy( 'game_pricing', __( 'Pricing Types', 'legend-game-directory' ), 'pricing-type' );
	}

	private static function taxonomy( $name, $label, $slug ) {
		register_taxonomy( $name, array( 'game' ), array(
			'label' => $label, 'public' => true, 'hierarchical' => true, 'show_in_rest' => true,
			'show_admin_column' => true, 'rewrite' => array( 'slug' => $slug, 'with_front' => false ),
		) );
	}

	public static function meta_fields() {
		return array(
			'_lgd_short_description' => 'string', '_lgd_developer' => 'string', '_lgd_publisher' => 'string',
			'_lgd_release_date' => 'string', '_lgd_last_update_date' => 'string', '_lgd_official_website' => 'string',
			'_lgd_steam_url' => 'string', '_lgd_google_play_url' => 'string', '_lgd_apple_app_store_url' => 'string',
			'_lgd_itch_url' => 'string', '_lgd_other_store_urls' => 'array', '_lgd_android_app_id' => 'string',
			'_lgd_ios_app_id' => 'string', '_lgd_operating_systems' => 'array', '_lgd_tags' => 'array',
			'_lgd_free_type' => 'string', '_lgd_is_indie' => 'boolean', '_lgd_indie_confidence' => 'number',
			'_lgd_is_mobile' => 'boolean', '_lgd_current_price' => 'number', '_lgd_original_price' => 'number',
			'_lgd_currency' => 'string', '_lgd_temporary_free_start' => 'string', '_lgd_temporary_free_end' => 'string',
			'_lgd_in_app_purchases' => 'string', '_lgd_advertising' => 'string', '_lgd_multiplayer' => 'string',
			'_lgd_online_requirement' => 'string', '_lgd_offline_support' => 'string', '_lgd_controller_support' => 'string',
			'_lgd_age_rating' => 'string', '_lgd_supported_languages' => 'array', '_lgd_system_requirements' => 'object',
			'_lgd_official_screenshots' => 'array', '_lgd_ai_artwork' => 'array', '_lgd_trailer_url' => 'string',
			'_lgd_automated_score' => 'number', '_lgd_editorial_score' => 'number', '_lgd_community_score' => 'number',
			'_lgd_external_critic_score' => 'number', '_lgd_external_user_score' => 'number', '_lgd_steam_sentiment' => 'string',
			'_lgd_steam_review_count' => 'integer', '_lgd_source_count' => 'integer', '_lgd_confidence' => 'number',
			'_lgd_verification_status' => 'string', '_lgd_last_verified' => 'string', '_lgd_sponsorship_status' => 'string',
			'_lgd_affiliate_status' => 'string', '_lgd_best_for' => 'string', '_lgd_pros' => 'array', '_lgd_cons' => 'array',
			'_lgd_review_consensus' => 'string', '_lgd_monetization_notes' => 'array', '_lgd_safety_notes' => 'array',
			'_lgd_score_breakdown' => 'object', '_lgd_missing_data' => 'array', '_lgd_provider_ids' => 'object',
			'_lgd_mandatory_review_flags' => 'array', '_lgd_ai_prompt' => 'string', '_lgd_ai_generated_at' => 'string',
			'_lgd_seo_title' => 'string', '_lgd_meta_description' => 'string',
			// Monetization Grade (v0.1.12).
			'_lgd_monetization_grade' => 'string', '_lgd_monetization_grade_override' => 'string',
			'_lgd_monetization_grade_reason' => 'string',
			// Granular verification dates (v0.1.12).
			'_lgd_verified_source_check' => 'string', '_lgd_verified_price_check' => 'string',
			'_lgd_verified_platform_check' => 'string', '_lgd_verified_monetization_check' => 'string',
			'_lgd_verified_editorial_review' => 'string',
		);
	}

	public static function register_meta() {
		foreach ( self::meta_fields() as $key => $type ) {
			$show = true;
			if ( 'array' === $type ) { $show = array( 'schema' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ); }
			if ( 'object' === $type ) { $show = array( 'schema' => array( 'type' => 'object', 'additionalProperties' => true ) ); }
			register_post_meta( 'game', $key, array(
				'single' => true, 'type' => $type, 'show_in_rest' => $show,
				'auth_callback' => function() { return current_user_can( 'edit_games' ); },
				'sanitize_callback' => array( __CLASS__, 'sanitize_meta' ),
			) );
		}
	}

	public static function sanitize_meta( $value, $key = '', $object_type = '' ) {
		unset( $object_type );
		$fields = self::meta_fields();
		$type = isset( $fields[ $key ] ) ? $fields[ $key ] : 'string';
		if ( 'boolean' === $type ) { return (bool) $value; }
		if ( 'number' === $type ) { return is_numeric( $value ) ? (float) $value : null; }
		if ( 'integer' === $type ) { return absint( $value ); }
		if ( 'array' === $type || 'object' === $type ) { return is_array( $value ) ? map_deep( $value, 'sanitize_text_field' ) : array(); }
		if ( false !== strpos( $key, '_url' ) || false !== strpos( $key, '_website' ) ) { return esc_url_raw( $value ); }
		return sanitize_textarea_field( $value );
	}

	public static function seed_terms() {
		$sets = array(
			'game_type' => array( 'Free Games', 'Indie Games', 'Mobile Games' ),
			'game_platform' => array( 'Android', 'iOS', 'Windows', 'macOS', 'Linux', 'Browser', 'Steam Deck' ),
			'game_genre' => array( 'Action', 'Adventure', 'RPG', 'Strategy', 'Simulation', 'Puzzle', 'Racing', 'Sports', 'Horror', 'Survival', 'Sandbox', 'Casual', 'Card', 'Roguelike', 'Platformer', 'MMO', 'Multiplayer', 'Educational' ),
			'game_pricing' => array( 'Permanently Free', 'Free to Play', 'Freemium', 'Free Demo', 'Temporarily Free', 'Open Source', 'Paid Indie' ),
		);
		foreach ( $sets as $taxonomy => $terms ) {
			foreach ( $terms as $term ) { if ( ! term_exists( $term, $taxonomy ) ) { wp_insert_term( $term, $taxonomy ); } }
		}
	}
}
