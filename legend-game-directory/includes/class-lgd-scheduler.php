<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Scheduler {
	private static $events = array(
		'lgd_source_health' => array( 'lgd_hourly', 3600 ),
		'lgd_temporary_offers' => array( 'lgd_four_hours', 14400 ),
		'lgd_steam_discovery' => array( 'lgd_six_hours', 21600 ),
		'lgd_mobile_updates' => array( 'daily', 86400 ),
		'lgd_review_refresh' => array( 'daily', 86400 ),
		'lgd_price_refresh' => array( 'lgd_twelve_hours', 43200 ),
		'lgd_broken_links' => array( 'weekly', 604800 ),
		'lgd_full_verification' => array( 'lgd_monthly', 2592000 ),
		'lgd_daily_digest' => array( 'daily', 86400 ),
	);

	public function __construct() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'init', array( __CLASS__, 'schedule_all' ), 50 );
		add_action( 'lgd_source_health', array( __CLASS__, 'source_health' ) );
		add_action( 'lgd_temporary_offers', array( __CLASS__, 'temporary_offers' ) );
		add_action( 'lgd_steam_discovery', array( __CLASS__, 'steam_discovery' ) );
		add_action( 'lgd_mobile_updates', array( __CLASS__, 'mobile_updates' ) );
		add_action( 'lgd_review_refresh', array( __CLASS__, 'review_refresh' ) );
		add_action( 'lgd_price_refresh', array( __CLASS__, 'price_refresh' ) );
		add_action( 'lgd_broken_links', array( __CLASS__, 'broken_links' ) );
		add_action( 'lgd_full_verification', array( __CLASS__, 'full_verification' ) );
		add_action( 'lgd_daily_digest', array( __CLASS__, 'daily_digest' ) );
	}

	public static function cron_schedules( $schedules ) {
		$schedules['lgd_hourly'] = array( 'interval' => 3600, 'display' => __( 'Legend hourly', 'legend-game-directory' ) );
		$schedules['lgd_four_hours'] = array( 'interval' => 14400, 'display' => __( 'Legend every four hours', 'legend-game-directory' ) );
		$schedules['lgd_six_hours'] = array( 'interval' => 21600, 'display' => __( 'Legend every six hours', 'legend-game-directory' ) );
		$schedules['lgd_twelve_hours'] = array( 'interval' => 43200, 'display' => __( 'Legend every twelve hours', 'legend-game-directory' ) );
		$schedules['lgd_monthly'] = array( 'interval' => 2592000, 'display' => __( 'Legend monthly', 'legend-game-directory' ) );
		return $schedules;
	}

	public static function schedule_all() {
		foreach ( self::$events as $hook => $config ) {
			if ( function_exists( 'as_schedule_recurring_action' ) ) {
				if ( ! as_has_scheduled_action( $hook, array(), 'legend-game-directory' ) ) { as_schedule_recurring_action( time() + 300, $config[1], $hook, array(), 'legend-game-directory', true ); }
			} elseif ( ! wp_next_scheduled( $hook ) ) { wp_schedule_event( time() + 300, $config[0], $hook ); }
		}
	}

	public static function unschedule_all() {
		foreach ( self::$events as $hook => $unused ) {
			unset( $unused );
			if ( function_exists( 'as_unschedule_all_actions' ) ) { as_unschedule_all_actions( $hook, array(), 'legend-game-directory' ); }
			wp_clear_scheduled_hook( $hook );
		}
	}

	private static function run_locked( $name, $callback ) {
		$lock = 'lgd_lock_' . sanitize_key( $name );
		if ( get_transient( $lock ) ) { LGD_Logger::log( 'job_skipped', 'Job skipped because a lock is active.', array( 'job' => $name ), 'warning' ); return; }
		set_transient( $lock, time(), 30 * MINUTE_IN_SECONDS );
		try { call_user_func( $callback ); LGD_Logger::log( 'job_complete', 'Scheduled job completed.', array( 'job' => $name ) ); }
		catch ( Throwable $e ) { LGD_Logger::log( 'job_failed', 'Scheduled job failed.', array( 'job' => $name, 'error' => $e->getMessage() ), 'error' ); }
		delete_transient( $lock );
	}

	public static function source_health() { self::run_locked( 'source_health', function() { update_option( 'lgd_provider_health', array( 'checked_at' => current_time( 'mysql', true ), 'providers' => LGD_Provider_Registry::health() ), false ); } ); }

	public static function steam_discovery() {
		self::run_locked( 'steam_discovery', function() {
			$settings = LGD_Security::settings(); $ids = isset( $settings['steam_app_ids'] ) ? LGD_Security::sanitize_string_list( $settings['steam_app_ids'] ) : array();
			if ( $ids ) { LGD_Importer::import_search( 'steam', '', array( 'app_ids' => $ids ) ); }
		} );
	}

	public static function mobile_updates() {
		self::run_locked( 'mobile_updates', function() {
			$settings = LGD_Security::settings(); $terms = isset( $settings['mobile_search_terms'] ) ? LGD_Security::sanitize_string_list( $settings['mobile_search_terms'] ) : array( 'indie game', 'free game' );
			foreach ( array_slice( $terms, 0, 5 ) as $term ) { LGD_Importer::import_search( 'apple', $term, array( 'limit' => 10 ) ); }
		} );
	}

	public static function temporary_offers() { self::run_locked( 'temporary_offers', function() { self::refresh_games( array( 'game_pricing' => 'temporarily-free' ), 50 ); } ); }
	public static function review_refresh() { self::run_locked( 'review_refresh', function() { self::refresh_games( array(), 50 ); } ); }
	public static function price_refresh() { self::run_locked( 'price_refresh', function() { self::refresh_games( array(), 100 ); } ); }
	public static function full_verification() { self::run_locked( 'full_verification', function() { self::refresh_games( array(), 250 ); } ); }

	private static function refresh_games( $query_args, $limit ) {
		$args = array_merge( array( 'post_type' => 'game', 'post_status' => array( 'publish', 'pending', 'draft' ), 'posts_per_page' => $limit, 'fields' => 'ids', 'orderby' => 'modified', 'order' => 'ASC' ), $query_args );
		foreach ( get_posts( $args ) as $game_id ) {
			$ids = (array) get_post_meta( $game_id, '_lgd_provider_ids', true );
			foreach ( $ids as $provider => $external_id ) { if ( in_array( $provider, array( 'steam', 'apple', 'itch' ), true ) ) { LGD_Importer::import_external_id( $provider, $external_id ); break; } }
		}
	}

	public static function broken_links() {
		self::run_locked( 'broken_links', function() {
			global $wpdb; $rows = $wpdb->get_results( 'SELECT id,game_id,source_url FROM ' . LGD_Database::table( 'sources' ) . " WHERE status='active' ORDER BY retrieved_at ASC LIMIT 100", ARRAY_A );
			foreach ( $rows as $row ) {
				$response = LGD_Security::safe_remote_get( $row['source_url'], null, array( 'limit_response_size' => 2048 ) );
				if ( is_wp_error( $response ) ) { $wpdb->update( LGD_Database::table( 'sources' ), array( 'status' => 'broken' ), array( 'id' => $row['id'] ), array( '%s' ), array( '%d' ) ); update_post_meta( $row['game_id'], '_lgd_mandatory_review_flags', array_unique( array_merge( (array) get_post_meta( $row['game_id'], '_lgd_mandatory_review_flags', true ), array( 'broken_source' ) ) ) ); }
			}
		} );
	}

	public static function daily_digest() {
		self::run_locked( 'daily_digest', function() {
			$pending = wp_count_posts( 'game' ); $health = get_option( 'lgd_provider_health', array() );
			$subject = sprintf( __( '[%s] Daily game directory digest', 'legend-game-directory' ), wp_specialchars_decode( get_bloginfo( 'name' ) ) );
			$message = sprintf( __( "Pending games: %d\nProvider health checked: %s\nDashboard: %s", 'legend-game-directory' ), isset( $pending->pending ) ? $pending->pending : 0, isset( $health['checked_at'] ) ? $health['checked_at'] : __( 'not yet', 'legend-game-directory' ), admin_url( 'edit.php?post_type=game&page=lgd-dashboard' ) );
			wp_mail( get_option( 'admin_email' ), $subject, $message );
		} );
	}
}
