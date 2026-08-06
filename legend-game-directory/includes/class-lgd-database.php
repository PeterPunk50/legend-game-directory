<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Database {
	// 4: IGDB provider — approved_domains gains api.igdb.com, id.twitch.tv and
	//    images.igdb.com, backfilled onto existing installs by install().
	// 5: RAWG provider — same treatment for api.rawg.io and media.rawg.io. The
	//    backfill is idempotent and adds only what is missing, so a site already
	//    on 4 gains just the two RAWG hosts.
	const VERSION = '5';

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
				"source_type varchar(64) NOT NULL DEFAULT ''",
				"trust_level varchar(16) NOT NULL DEFAULT 'medium'",
				'fields_supported text NULL',
				'notes text NULL',
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
		self::backfill_approved_domains();
	}

	/**
	 * Teach an EXISTING install about hosts a new provider needs.
	 *
	 * defaults() only reaches a site through wp_parse_args(), which fills in
	 * missing keys and never merges inside one. An install that already has
	 * lgd_settings therefore keeps its old approved_domains list forever, and a
	 * new provider's host would be refused by LGD_Security::host_allowed() with
	 * a "domain is not approved" error that looks nothing like the real cause.
	 *
	 * Deliberately NARROW: it adds only the specific hosts named here, only when
	 * they are absent, and it removes nothing. A blanket merge of defaults()
	 * would resurrect any domain an administrator had deliberately deleted, and
	 * this has no way to tell that apart from one they never had.
	 */
	private static function backfill_approved_domains() {
		$new = array( 'api.igdb.com', 'id.twitch.tv', 'images.igdb.com', 'api.rawg.io', 'media.rawg.io' );

		$settings = get_option( 'lgd_settings', array() );
		if ( ! is_array( $settings ) ) { return; }
		$current = isset( $settings['approved_domains'] ) && is_array( $settings['approved_domains'] )
			? $settings['approved_domains'] : array();

		$missing = array_diff( $new, $current );
		if ( empty( $missing ) ) { return; }

		$settings['approved_domains'] = array_values( array_unique( array_merge( $current, $missing ) ) );
		update_option( 'lgd_settings', $settings, false );

		if ( class_exists( 'LGD_Logger' ) ) {
			LGD_Logger::log( 'settings', 'Approved domains extended for a new provider: ' . implode( ', ', $missing ) );
		}
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
			// api.igdb.com is the API and id.twitch.tv mints its token; images.igdb.com
			// is the CDN the artwork fetcher sideloads covers and screenshots from.
			// media.rawg.io is RAWG's equivalent image CDN.
			'approved_domains' => array( 'steampowered.com', 'steamgames.com', 'apple.com', 'itunes.apple.com', 'itch.io', 'api.igdb.com', 'id.twitch.tv', 'images.igdb.com', 'api.rawg.io', 'media.rawg.io' ),
			'blocked_domains' => array(), 'steam_enabled' => false, 'steam_terms_accepted' => false,
			'apple_enabled' => true, 'google_play_enabled' => false, 'itch_enabled' => false,
			// Both off until their credential is in wp-config. Each provider fails
			// closed on an absent key, so flipping either flag alone changes nothing.
			// IGDB is dormant rather than deleted: it is written and deployed, and
			// is one constant away if the Twitch account is ever cleared (its 2FA
			// enrolment would not complete for a Barbados number, which is why RAWG
			// is the live path).
			'igdb_enabled' => false,
			'rawg_enabled' => false,
			'official_site_enabled' => true, 'review_auto_approve' => false,
			'review_require_verified' => true, 'data_retention_days' => 365, 'weights' => $weights,
		);
	}
}
