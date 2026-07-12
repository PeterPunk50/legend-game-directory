<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LCDP_Post_Types {
	public function __construct() {
		add_action( 'init', array( $this, 'register_all' ) );
		add_action( 'init', array( $this, 'seed_terms' ), 20 );
	}

	public static function register_all() {
		// Game Profile (public editorial) — stores approved game listings
		register_post_type( 'lcdp_game', array(
			'labels'       => self::labels( 'Game Profile', 'Game Profiles' ),
			'public'        => true,
			'show_in_menu'  => 'lcdp-platform',
			'supports'      => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'comments' ),
			'has_archive'   => 'games',
			'rewrite'       => array( 'slug' => 'games' ),
			'show_in_rest'  => true,
			'menu_icon'     => 'dashicons-games',
			'capability_type' => array( 'lcdp_game', 'lcdp_games' ),
			'map_meta_cap'  => true,
		) );

		// Developer Profile (public)
		register_post_type( 'lcdp_developer', array(
			'labels'       => self::labels( 'Developer Profile', 'Developer Profiles' ),
			'public'        => true,
			'show_in_menu'  => 'lcdp-platform',
			'supports'      => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
			'has_archive'   => 'developers',
			'rewrite'       => array( 'slug' => 'developer' ),
			'show_in_rest'  => true,
			'capability_type' => array( 'lcdp_developer', 'lcdp_developers' ),
			'map_meta_cap'  => true,
		) );

		// Expert Profile (public)
		register_post_type( 'lcdp_expert', array(
			'labels'       => self::labels( 'Expert Profile', 'Expert Profiles' ),
			'public'        => true,
			'show_in_menu'  => 'lcdp-platform',
			'supports'      => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
			'has_archive'   => 'experts',
			'rewrite'       => array( 'slug' => 'expert' ),
			'show_in_rest'  => false,
			'capability_type' => array( 'lcdp_expert', 'lcdp_experts' ),
			'map_meta_cap'  => true,
		) );

		// Sponsored Feature (public with editorial control)
		register_post_type( 'lcdp_sponsored', array(
			'labels'       => self::labels( 'Sponsored Feature', 'Sponsored Features' ),
			'public'        => true,
			'show_in_menu'  => 'lcdp-platform',
			'supports'      => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
			'has_archive'   => false,
			'rewrite'       => array( 'slug' => 'featured-game' ),
			'show_in_rest'  => false,
			'capability_type' => array( 'lcdp_sponsored', 'lcdp_sponsoreds' ),
			'map_meta_cap'  => true,
		) );

		// Campaign (private — admin/coordinator only)
		register_post_type( 'lcdp_campaign', array(
			'labels'       => self::labels( 'Campaign', 'Campaigns' ),
			'public'        => false,
			'show_ui'       => true,
			'show_in_menu'  => 'lcdp-platform',
			'supports'      => array( 'title', 'custom-fields' ),
			'show_in_rest'  => false,
			'capability_type' => array( 'lcdp_campaign', 'lcdp_campaigns' ),
			'map_meta_cap'  => true,
		) );

		// Taxonomies
		register_taxonomy( 'lcdp_genre', array( 'lcdp_game', 'lcdp_developer' ), array(
			'label'         => 'Genre',
			'public'         => true,
			'hierarchical'  => true,
			'rewrite'        => array( 'slug' => 'game-genre' ),
			'show_in_rest'  => true,
		) );

		register_taxonomy( 'lcdp_platform', array( 'lcdp_game' ), array(
			'label'         => 'Platform',
			'public'         => true,
			'hierarchical'  => false,
			'rewrite'        => array( 'slug' => 'game-platform' ),
			'show_in_rest'  => true,
		) );

		register_taxonomy( 'lcdp_dev_stage', array( 'lcdp_game' ), array(
			'label'         => 'Development Stage',
			'public'         => true,
			'hierarchical'  => false,
			'rewrite'        => array( 'slug' => 'dev-stage' ),
			'show_in_rest'  => true,
		) );

		register_taxonomy( 'lcdp_service_type', array( 'lcdp_campaign' ), array(
			'label'        => 'Service Type',
			'public'        => false,
			'show_ui'       => true,
			'hierarchical' => false,
		) );

		register_taxonomy( 'lcdp_expert_specialty', array( 'lcdp_expert' ), array(
			'label'        => 'Expert Specialty',
			'public'        => true,
			'hierarchical' => false,
			'rewrite'       => array( 'slug' => 'expert-specialty' ),
		) );
	}

	public static function seed_terms() {
		$genres = array(
			'Shooter', 'Battle Royale', 'MOBA', 'RPG', 'Strategy', 'Simulation',
			'Platformer', 'Puzzle', 'Survival', 'Horror', 'Sports', 'Racing',
			'Fighting', 'Indie', 'Action', 'Adventure', 'Co-op', 'Multiplayer',
		);
		foreach ( $genres as $g ) {
			if ( ! term_exists( $g, 'lcdp_genre' ) ) {
				wp_insert_term( $g, 'lcdp_genre' );
			}
		}

		$platforms = array( 'PC', 'Mac', 'Linux', 'PlayStation', 'Xbox', 'Nintendo Switch', 'iOS', 'Android', 'Steam Deck', 'Web' );
		foreach ( $platforms as $p ) {
			if ( ! term_exists( $p, 'lcdp_platform' ) ) {
				wp_insert_term( $p, 'lcdp_platform' );
			}
		}

		$stages = array( 'Pre-Alpha', 'Alpha', 'Beta', 'Early Access', 'Demo Available', 'Coming Soon', 'Released' );
		foreach ( $stages as $s ) {
			if ( ! term_exists( $s, 'lcdp_dev_stage' ) ) {
				wp_insert_term( $s, 'lcdp_dev_stage' );
			}
		}

		$specialties = array(
			'Gameplay Programmer', 'Level Designer', 'Technical Artist', 'Animator',
			'UI/UX Designer', 'QA Lead', 'Community Manager', 'Steam Marketing Specialist',
			'Accessibility Specialist', 'Multiplayer Designer', 'Narrative Designer', 'Producer',
		);
		foreach ( $specialties as $spec ) {
			if ( ! term_exists( $spec, 'lcdp_expert_specialty' ) ) {
				wp_insert_term( $spec, 'lcdp_expert_specialty' );
			}
		}

		$service_types = array( 'Starter Playtest', 'Targeted Playtest', 'Steam Page Review', 'Guide Package', 'Launch Essentials', 'Complete Launch Campaign', 'Custom Campaign', 'Founding Pilot' );
		foreach ( $service_types as $t ) {
			if ( ! term_exists( $t, 'lcdp_service_type' ) ) {
				wp_insert_term( $t, 'lcdp_service_type' );
			}
		}
	}

	private static function labels( $singular, $plural ) {
		return array(
			'name'          => $plural,
			'singular_name' => $singular,
			'add_new_item'  => 'Add New ' . $singular,
			'edit_item'     => 'Edit ' . $singular,
			'view_item'     => 'View ' . $singular,
			'search_items'  => 'Search ' . $plural,
			'not_found'     => 'No ' . strtolower( $plural ) . ' found.',
		);
	}
}
