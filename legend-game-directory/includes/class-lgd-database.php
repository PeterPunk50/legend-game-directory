<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Database {
	const VERSION = '2';

	public static function table( $suffix ) {
		global $wpdb;
		return $wpdb->prefix . 'lgd_' . preg_replace( '/[^a-z_]/', '', $suffix );
	}

	private static function schema() {
		// One column/key per line and two spaces after PRIMARY KEY: dbDelta() parses on newlines and is whitespace-sensitive.
		return array(
			'sources' => array(
				'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
				'game_id bigint(20) unsigned NOT NULL DEFAULT 0',
				'provider varchar(64) NOT NULL',
				'external_id varchar(191) NOT NULL',
				'source_url text NOT NULL',
				'retrieved_at datetime NOT NULL',
				"source_hash char(64) NOT NULL DEFAULT ''",
				'facts longtext NULL',
				'confidence decimal(5,2) NOT NULL DEFAULT 0',
				"status varchar(32) NOT NULL DEFAULT 'active'",
				'PRIMARY KEY  (id)',
				'UNIQUE KEY provider_external (provider,external_id)',
				'KEY game_id (game_id)',
				'KEY retrieved_at (retrieved_at)',
			),
			'score_history' => array(
				'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
				'game_id bigint(20) unsigned NOT NULL',
				'score_type varchar(32) NOT NULL',
				'score decimal(6,2) NULL',
				'breakdown longtext NULL',
				'confidence decimal(5,2) NOT NULL DEFAULT 0',
				'is_override tinyint(1) NOT NULL DEFAULT 0',
				'user_id bigint(20) unsigned NOT NULL DEFAULT 0',
				'created_at datetime NOT NULL',
				'PRIMARY KEY  (id)',
				'KEY game_type_date (game_id,score_type,created_at)',
			),
			'reviews' => array(
				'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
				'game_id bigint(20) unsigned NOT NULL',
				'user_id bigint(20) unsigned NOT NULL',
				'rating decimal(3,1) NOT NULL',
				'review_text text NULL',
				"status varchar(24) NOT NULL DEFAULT 'pending'",
				'moderation_flags longtext NULL',
				'created_at datetime NOT NULL',
				'updated_at datetime NOT NULL',
				'PRIMARY KEY  (id)',
				'UNIQUE KEY game_user (game_id,user_id)',
				'KEY game_status (game_id,status)',
				'KEY user_id (user_id)',
			),
			'review_history' => array(
				'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
				'review_id bigint(20) unsigned NOT NULL',
				'user_id bigint(20) unsigned NOT NULL',
				'old_rating decimal(3,1) NULL',
				'old_text text NULL',
				'changed_at datetime NOT NULL',
				'PRIMARY KEY  (id)',
				'KEY review_id (review_id)',
			),
			'audit_log' => array(
				'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
				'event varchar(96) NOT NULL',
				"object_type varchar(32) NOT NULL DEFAULT ''",
				'object_id bigint(20) unsigned NOT NULL DEFAULT 0',
				'user_id bigint(20) unsigned NOT NULL DEFAULT 0',
				"level varchar(16) NOT NULL DEFAULT 'info'",
				'message text NOT NULL',
				'context longtext NULL',
				'created_at datetime NOT NULL',
				'PRIMARY KEY  (id)',
				'KEY event_date (event,created_at)',
				'KEY object_lookup (object_type,object_id)',
			),
			'subscribers' => array(
				'id bigint(20) unsigned NOT NULL AUTO_INCREMENT',
				'email varchar(191) NOT NULL',
				"token_hash varchar(255) NOT NULL DEFAULT ''",
				"status varchar(24) NOT NULL DEFAULT 'pending'",
				"preferences varchar(191) NOT NULL DEFAULT 'free,highly_rated'",
				'created_at datetime NOT NULL',
				'confirmed_at datetime NULL',
				'PRIMARY KEY  (id)',
				'UNIQUE KEY email (email)',
				'KEY status (status)',
			),
		);
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		foreach ( self::schema() as $suffix => $lines ) {
			$sql = 'CREATE TABLE ' . self::table( $suffix ) . " (\n\t" . implode( ",\n\t", $lines ) . "\n) $charset;";
			dbDelta( $sql );
		}
		update_option( 'lgd_db_version', self::VERSION, false );
		add_option( 'lgd_settings', self::defaults(), '', false );
	}

	public static function maybe_upgrade() {
		if ( (string) get_option( 'lgd_db_version' ) !== self::VERSION ) { self::install(); }
	}

	public static function defaults() {
		$weights = class_exists( 'LGD_Rating_Engine' ) ? LGD_Rating_Engine::default_weights() : array(
			'review_consensus' => 20, 'player_sentiment' => 15, 'value_monetization' => 15,
			'update_activity' => 10, 'platform_support' => 10, 'accessibility' => 10,
			'technical_stability' => 10, 'safety_transparency' => 10,
		);
		return array(
			'publication_mode' => 'review_everything', 'min_publish_confidence' => 85,
			'min_score_confidence' => 60, 'enable_ai' => false, 'enable_ai_web_search' => false,
			'enable_ai_images' => false, 'ai_provider' => 'openai', 'ai_model' => '',
			'ai_daily_request_limit' => 50, 'ai_monthly_cost_limit' => 25,
			'ai_estimated_input_rate' => 0, 'ai_estimated_output_rate' => 0,
			'approved_domains' => array( 'steampowered.com', 'steamgames.com', 'apple.com', 'itunes.apple.com', 'itch.io' ),
			'blocked_domains' => array(), 'steam_enabled' => false, 'steam_terms_accepted' => false,
			'apple_enabled' => true, 'google_play_enabled' => false, 'itch_enabled' => false,
			'official_site_enabled' => true, 'review_auto_approve' => false,
			'review_require_verified' => true, 'data_retention_days' => 365, 'weights' => $weights,
		);
	}
}
