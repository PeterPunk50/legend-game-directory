<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LCDP_Database {
	const VERSION = '1';

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'lcdp_' . preg_replace( '/[^a-z_]/', '', $name );
	}

	private static function schema() {
		$charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		return array(

			// Developer profile metadata (supplementing the CPT)
			'developer_profiles' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				studio_name varchar(200) NOT NULL DEFAULT '',
				studio_website varchar(500) NOT NULL DEFAULT '',
				bio longtext NULL,
				membership_plan varchar(64) NOT NULL DEFAULT 'none',
				membership_expires datetime NULL,
				discount_rate tinyint(3) unsigned NOT NULL DEFAULT 0,
				active_game_count tinyint(3) unsigned NOT NULL DEFAULT 0,
				total_campaigns smallint(5) unsigned NOT NULL DEFAULT 0,
				verified tinyint(1) NOT NULL DEFAULT 0,
				status varchar(32) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_id (user_id),
				KEY membership_plan (membership_plan),
				KEY status (status)",

			// Tester profiles (private — capability-protected)
			'tester_profiles' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				age_confirmed tinyint(1) NOT NULL DEFAULT 0,
				country varchar(4) NOT NULL DEFAULT '',
				timezone varchar(64) NOT NULL DEFAULT '',
				languages varchar(500) NOT NULL DEFAULT '',
				platforms longtext NULL,
				pc_specs longtext NULL,
				mobile_device varchar(200) NOT NULL DEFAULT '',
				has_controller tinyint(1) NOT NULL DEFAULT 0,
				has_keyboard_mouse tinyint(1) NOT NULL DEFAULT 0,
				internet_category varchar(32) NOT NULL DEFAULT '',
				genres longtext NULL,
				competitive_casual varchar(16) NOT NULL DEFAULT '',
				ranked_experience varchar(16) NOT NULL DEFAULT '',
				multiplayer_pref varchar(16) NOT NULL DEFAULT '',
				availability longtext NULL,
				voice_chat tinyint(1) NOT NULL DEFAULT 0,
				recording_willing tinyint(1) NOT NULL DEFAULT 0,
				nda_willing tinyint(1) NOT NULL DEFAULT 0,
				payment_method varchar(64) NOT NULL DEFAULT '',
				accessibility_interest tinyint(1) NOT NULL DEFAULT 0,
				categories longtext NULL,
				reliability_score decimal(4,2) NOT NULL DEFAULT 100.00,
				total_completed smallint(5) unsigned NOT NULL DEFAULT 0,
				total_rejected tinyint(3) unsigned NOT NULL DEFAULT 0,
				status varchar(32) NOT NULL DEFAULT 'application_received',
				ai_flags longtext NULL,
				review_notes text NULL,
				reviewer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reviewed_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_id (user_id),
				KEY status (status),
				KEY reliability_score (reliability_score)",

			// Playtest campaigns
			'campaigns' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				developer_user_id bigint(20) unsigned NOT NULL,
				game_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				title varchar(300) NOT NULL DEFAULT '',
				service_package varchar(64) NOT NULL DEFAULT '',
				woo_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				build_version varchar(64) NOT NULL DEFAULT '',
				development_stage varchar(32) NOT NULL DEFAULT '',
				platforms longtext NULL,
				genre varchar(64) NOT NULL DEFAULT '',
				similar_games varchar(500) NOT NULL DEFAULT '',
				test_goals longtext NULL,
				tester_requirements longtext NULL,
				tester_count tinyint(3) unsigned NOT NULL DEFAULT 5,
				session_duration smallint(5) unsigned NOT NULL DEFAULT 30,
				build_delivery varchar(64) NOT NULL DEFAULT '',
				min_requirements longtext NULL,
				known_issues longtext NULL,
				nda_required tinyint(1) NOT NULL DEFAULT 0,
				recording_permission tinyint(1) NOT NULL DEFAULT 0,
				start_date date NULL,
				end_date date NULL,
				budget decimal(10,2) NOT NULL DEFAULT 0.00,
				tester_reward_budget decimal(10,2) NOT NULL DEFAULT 0.00,
				expert_fee decimal(10,2) NOT NULL DEFAULT 0.00,
				platform_fee decimal(10,2) NOT NULL DEFAULT 0.00,
				status varchar(48) NOT NULL DEFAULT 'developer_enquiry',
				coordinator_id bigint(20) unsigned NOT NULL DEFAULT 0,
				mission_brief longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY developer_user_id (developer_user_id),
				KEY status (status),
				KEY woo_order_id (woo_order_id)",

			// Tester applications to campaigns
			'campaign_applications' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				campaign_id bigint(20) unsigned NOT NULL,
				tester_user_id bigint(20) unsigned NOT NULL,
				match_score decimal(5,2) NOT NULL DEFAULT 0.00,
				match_breakdown longtext NULL,
				conflict_flags longtext NULL,
				status varchar(32) NOT NULL DEFAULT 'pending',
				coordinator_notes text NULL,
				applied_at datetime NOT NULL,
				reviewed_at datetime NULL,
				assigned_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY campaign_tester (campaign_id,tester_user_id),
				KEY campaign_id (campaign_id),
				KEY tester_user_id (tester_user_id),
				KEY status (status)",

			// Tester feedback submissions
			'tester_submissions' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				campaign_id bigint(20) unsigned NOT NULL,
				tester_user_id bigint(20) unsigned NOT NULL,
				session_duration_minutes smallint(5) unsigned NOT NULL DEFAULT 0,
				first_impression tinyint(2) unsigned NOT NULL DEFAULT 0,
				controls_rating tinyint(2) unsigned NOT NULL DEFAULT 0,
				tutorial_rating tinyint(2) unsigned NOT NULL DEFAULT 0,
				visual_rating tinyint(2) unsigned NOT NULL DEFAULT 0,
				performance_rating tinyint(2) unsigned NOT NULL DEFAULT 0,
				difficulty_rating tinyint(2) unsigned NOT NULL DEFAULT 0,
				combat_rating tinyint(2) unsigned NOT NULL DEFAULT 0,
				sound_rating tinyint(2) unsigned NOT NULL DEFAULT 0,
				originality_rating tinyint(2) unsigned NOT NULL DEFAULT 0,
				play_again_rating tinyint(2) unsigned NOT NULL DEFAULT 0,
				recommend_rating tinyint(2) unsigned NOT NULL DEFAULT 0,
				tasks_completed longtext NULL,
				confusion_points longtext NULL,
				positive_notes longtext NULL,
				negative_notes longtext NULL,
				overall_feedback longtext NULL,
				similar_games_mentioned varchar(500) NOT NULL DEFAULT '',
				would_play_again tinyint(1) NULL,
				stopped_reason varchar(500) NOT NULL DEFAULT '',
				status varchar(32) NOT NULL DEFAULT 'submitted',
				reviewer_notes text NULL,
				reward_amount decimal(8,2) NOT NULL DEFAULT 0.00,
				reward_status varchar(32) NOT NULL DEFAULT 'not_assigned',
				submitted_at datetime NOT NULL,
				reviewed_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY campaign_tester (campaign_id,tester_user_id),
				KEY campaign_id (campaign_id),
				KEY status (status)",

			// Bug reports
			'bug_reports' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				campaign_id bigint(20) unsigned NOT NULL,
				tester_user_id bigint(20) unsigned NOT NULL,
				submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
				title varchar(300) NOT NULL DEFAULT '',
				build_version varchar(64) NOT NULL DEFAULT '',
				platform varchar(64) NOT NULL DEFAULT '',
				device varchar(200) NOT NULL DEFAULT '',
				severity varchar(4) NOT NULL DEFAULT 'P3',
				frequency varchar(32) NOT NULL DEFAULT '',
				steps_to_reproduce longtext NULL,
				expected_result text NULL,
				actual_result text NULL,
				screenshot_urls longtext NULL,
				video_url varchar(500) NOT NULL DEFAULT '',
				log_excerpt longtext NULL,
				workaround text NULL,
				status varchar(32) NOT NULL DEFAULT 'new',
				priority varchar(4) NOT NULL DEFAULT 'P3',
				coordinator_notes text NULL,
				points_awarded smallint(5) unsigned NOT NULL DEFAULT 0,
				submitted_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY campaign_id (campaign_id),
				KEY tester_user_id (tester_user_id),
				KEY severity (severity),
				KEY status (status)",

			// Developer reports
			'developer_reports' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				campaign_id bigint(20) unsigned NOT NULL,
				developer_user_id bigint(20) unsigned NOT NULL,
				report_data longtext NULL,
				ai_draft longtext NULL,
				final_content longtext NULL,
				status varchar(32) NOT NULL DEFAULT 'pending',
				author_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
				approved_at datetime NULL,
				sent_at datetime NULL,
				download_token varchar(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY campaign_id (campaign_id),
				KEY developer_user_id (developer_user_id),
				KEY status (status)",

			// Consent and privacy records
			'consent_records' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				consent_type varchar(64) NOT NULL,
				consent_text longtext NOT NULL,
				policy_version varchar(16) NOT NULL DEFAULT '',
				source varchar(64) NOT NULL DEFAULT '',
				ip_address varchar(45) NOT NULL DEFAULT '',
				granted tinyint(1) NOT NULL DEFAULT 1,
				granted_at datetime NOT NULL,
				withdrawn_at datetime NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY consent_type (consent_type),
				KEY granted_at (granted_at)",

			// Points / token ledger
			'token_ledger' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				activity_type varchar(64) NOT NULL,
				points int(11) NOT NULL DEFAULT 0,
				tokens_converted smallint(5) unsigned NOT NULL DEFAULT 0,
				balance_after int(11) NOT NULL DEFAULT 0,
				tokens_balance smallint(5) unsigned NOT NULL DEFAULT 0,
				reference_type varchar(32) NOT NULL DEFAULT '',
				reference_id bigint(20) unsigned NOT NULL DEFAULT 0,
				description varchar(300) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY activity_type (activity_type),
				KEY created_at (created_at)",

			// Universal ratings (all entity types)
			'ratings' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				entity_type varchar(32) NOT NULL,
				entity_id bigint(20) unsigned NOT NULL,
				rater_user_id bigint(20) unsigned NOT NULL,
				rating decimal(3,1) NOT NULL,
				review_text text NULL,
				status varchar(24) NOT NULL DEFAULT 'approved',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY entity_rater (entity_type,entity_id,rater_user_id),
				KEY entity_type_id (entity_type,entity_id),
				KEY status (status)",

			// Email suppression list
			'suppression_list' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				email varchar(191) NOT NULL,
				suppression_type varchar(32) NOT NULL DEFAULT 'marketing',
				reason varchar(200) NOT NULL DEFAULT '',
				source varchar(64) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY email_type (email,suppression_type),
				KEY email (email)",

			// Developer lead discovery (Phase 3 — table created now, UI later)
			'developer_leads' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				studio_name varchar(300) NOT NULL DEFAULT '',
				game_title varchar(300) NOT NULL DEFAULT '',
				game_page_url varchar(500) NOT NULL DEFAULT '',
				development_stage varchar(32) NOT NULL DEFAULT '',
				genre varchar(64) NOT NULL DEFAULT '',
				platforms varchar(300) NOT NULL DEFAULT '',
				similar_games varchar(500) NOT NULL DEFAULT '',
				demo_available tinyint(1) NOT NULL DEFAULT 0,
				steam_url varchar(500) NOT NULL DEFAULT '',
				official_website varchar(500) NOT NULL DEFAULT '',
				public_email varchar(191) NOT NULL DEFAULT '',
				public_contact_name varchar(200) NOT NULL DEFAULT '',
				source_urls longtext NULL,
				last_checked datetime NULL,
				ai_score tinyint(3) unsigned NOT NULL DEFAULT 0,
				ai_score_breakdown longtext NULL,
				ai_service_recommendation varchar(100) NOT NULL DEFAULT '',
				human_verified tinyint(1) NOT NULL DEFAULT 0,
				verifier_id bigint(20) unsigned NOT NULL DEFAULT 0,
				outreach_status varchar(32) NOT NULL DEFAULT 'uncontacted',
				opted_out tinyint(1) NOT NULL DEFAULT 0,
				opted_out_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY ai_score (ai_score),
				KEY outreach_status (outreach_status),
				KEY human_verified (human_verified)",

			// Outreach records (Phase 3 — table created now)
			'outreach_records' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
				developer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				to_email varchar(191) NOT NULL DEFAULT '',
				subject varchar(300) NOT NULL DEFAULT '',
				body longtext NULL,
				sequence_step tinyint(2) unsigned NOT NULL DEFAULT 1,
				ai_drafted tinyint(1) NOT NULL DEFAULT 0,
				approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
				approved_at datetime NULL,
				sent_at datetime NULL,
				status varchar(32) NOT NULL DEFAULT 'draft',
				response_received tinyint(1) NOT NULL DEFAULT 0,
				response_at datetime NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY lead_id (lead_id),
				KEY status (status)",

			// Audit log
			'audit_log' => "
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event varchar(96) NOT NULL,
				object_type varchar(32) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				level varchar(16) NOT NULL DEFAULT 'info',
				message text NOT NULL,
				context longtext NULL,
				ip_address varchar(45) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY event (event),
				KEY user_id (user_id),
				KEY object_type_id (object_type,object_id),
				KEY created_at (created_at)",
		);
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		foreach ( self::schema() as $suffix => $cols ) {
			$table = self::table( $suffix );
			$sql   = "CREATE TABLE {$table} ( {$cols} ) {$charset};";
			dbDelta( $sql );
		}
		update_option( 'lcdp_db_version', self::VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'lcdp_db_version' ) !== self::VERSION ) {
			self::install();
		}
	}
}
