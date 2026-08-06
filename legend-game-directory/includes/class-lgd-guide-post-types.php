<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Guide_Post_Types {

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_all' ) );
		add_action( 'init', array( __CLASS__, 'register_meta' ), 20 );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_archive' ) );
	}

	public static function filter_archive( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( 'game_guide' ) ) { return; }

		// guide_type is a registered taxonomy — WP handles ?guide_type=slug natively.
		// We handle the three custom params WP doesn't know about.

		$game_id = isset( $_GET['guide_game'] ) ? (int) wp_unslash( $_GET['guide_game'] ) : 0;
		if ( $game_id > 0 ) {
			$meta   = (array) $query->get( 'meta_query' );
			$meta[] = array( 'key' => '_lgd_guide_game_id', 'value' => $game_id, 'type' => 'NUMERIC' );
			$query->set( 'meta_query', $meta );
		}

		if ( ! empty( $_GET['guide_has_video'] ) ) {
			$meta   = (array) $query->get( 'meta_query' );
			$meta[] = array( 'key' => '_lgd_guide_video_url', 'value' => '', 'compare' => '!=' );
			$query->set( 'meta_query', $meta );
		}

		$sort = isset( $_GET['guide_sort'] ) ? sanitize_key( wp_unslash( $_GET['guide_sort'] ) ) : '';
		switch ( $sort ) {
			case 'score':
				$query->set( 'meta_key', '_lgd_guide_score' );
				$query->set( 'orderby', 'meta_value_num' );
				$query->set( 'order', 'DESC' );
				break;
			case 'az':
				$query->set( 'orderby', 'title' );
				$query->set( 'order', 'ASC' );
				break;
			case 'video':
				$query->set( 'meta_key', '_lgd_guide_video_url' );
				$query->set( 'orderby', 'meta_value' );
				$query->set( 'order', 'DESC' );
				break;
			default:
				$query->set( 'orderby', 'date' );
				$query->set( 'order', 'DESC' );
		}

		$query->set( 'posts_per_page', 12 );
	}

	public static function register_all() {
		register_post_type( 'game_guide', array(
			'labels' => array(
				'name'               => __( 'Game Guides', 'legend-game-directory' ),
				'singular_name'      => __( 'Game Guide', 'legend-game-directory' ),
				'add_new_item'       => __( 'Add Guide', 'legend-game-directory' ),
				'edit_item'          => __( 'Edit Guide', 'legend-game-directory' ),
				'menu_name'          => __( 'Guides', 'legend-game-directory' ),
				'search_items'       => __( 'Search Guides', 'legend-game-directory' ),
				'not_found'          => __( 'No guides found.', 'legend-game-directory' ),
				'not_found_in_trash' => __( 'No guides in trash.', 'legend-game-directory' ),
			),
			'public'            => true,
			'show_in_rest'      => true,
			'has_archive'       => 'guides',
			'rewrite'           => array( 'slug' => 'guides', 'with_front' => false ),
			'menu_icon'         => 'dashicons-book-alt',
			'menu_position'     => 6,
			'supports'          => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'custom-fields', 'comments' ),
			'map_meta_cap'      => true,
			'capability_type'   => array( 'guide', 'guides' ),
			'show_in_nav_menus' => true,
		) );

		register_taxonomy( 'guide_type', array( 'game_guide' ), array(
			'label'             => __( 'Guide Types', 'legend-game-directory' ),
			'public'            => true,
			'hierarchical'      => false,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => array( 'slug' => 'guide-type', 'with_front' => false ),
		) );
	}

	public static function meta_fields() {
		return array(
			'_lgd_guide_game_id'          => 'integer',
			'_lgd_guide_game_name'        => 'string',
			'_lgd_guide_game_slug'        => 'string',
			'_lgd_guide_difficulty'       => 'string',
			'_lgd_guide_reading_time'     => 'integer',
			'_lgd_guide_platform'         => 'string',
			'_lgd_guide_affiliate_url'    => 'string',
			'_lgd_guide_affiliate_label'  => 'string',
			'_lgd_guide_key_points'       => 'array',
			'_lgd_guide_seo_title'        => 'string',
			'_lgd_guide_meta_description' => 'string',
			'_lgd_guide_ai_generated'     => 'boolean',
			'_lgd_guide_ai_generated_at'  => 'string',
			'_lgd_guide_last_updated'     => 'string',
			// Indexed/external guide fields (aggregator mode).
			'_lgd_guide_is_external'      => 'boolean',
			'_lgd_guide_source_url'       => 'string',
			'_lgd_guide_source_site'      => 'string',
			'_lgd_guide_source_author'    => 'string',
			'_lgd_guide_source_date'      => 'string',
			'_lgd_guide_video_url'        => 'string',
			'_lgd_guide_image_from_video' => 'boolean',
			'_lgd_guide_score'            => 'integer',
			'_lgd_guide_imported_at'      => 'string',
		);
	}

	public static function register_meta() {
		foreach ( self::meta_fields() as $key => $type ) {
			$show = true;
			if ( 'array' === $type ) {
				$show = array( 'schema' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) );
			}
			register_post_meta( 'game_guide', $key, array(
				'single'            => true,
				'type'              => $type,
				'show_in_rest'      => $show,
				'auth_callback'     => function() { return current_user_can( 'edit_guides' ); },
				'sanitize_callback' => array( 'LGD_Post_Types', 'sanitize_meta' ),
			) );
		}
	}

	public static function seed_guide_types() {
		$types = array( 'Walkthrough', 'Beginner Guide', 'Tips & Tricks', 'Strategy', 'FAQ', 'Review' );
		foreach ( $types as $type ) {
			if ( ! term_exists( $type, 'guide_type' ) ) { wp_insert_term( $type, 'guide_type' ); }
		}
	}

	public static function add_capabilities() {
		$role = get_role( 'administrator' );
		if ( ! $role ) { return; }
		foreach ( array( 'edit_guide', 'read_guide', 'delete_guide', 'edit_guides', 'edit_others_guides', 'publish_guides', 'read_private_guides', 'delete_guides' ) as $cap ) {
			$role->add_cap( $cap );
		}
	}
}
