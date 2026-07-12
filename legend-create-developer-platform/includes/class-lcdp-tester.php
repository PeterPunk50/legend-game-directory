<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LCDP_Tester {

	// Application status flow
	public static function statuses() {
		return array(
			'application_received'  => 'Application Received',
			'email_verification'    => 'Email Verification Required',
			'profile_incomplete'    => 'Profile Incomplete',
			'sample_task_required'  => 'Sample Task Required',
			'human_review'          => 'Human Review',
			'approved'              => 'Approved',
			'specialist_approved'   => 'Specialist Approved',
			'paused'                => 'Paused',
			'rejected'              => 'Rejected',
			'suspended'             => 'Suspended',
		);
	}

	// Tester categories
	public static function categories() {
		return array(
			'fortnite_build'        => 'Fortnite Build',
			'fortnite_zero_build'   => 'Fortnite Zero Build',
			'apex_legends'          => 'Apex Legends',
			'cod_multiplayer'       => 'Call of Duty Multiplayer',
			'warzone'               => 'Warzone',
			'cs2'                   => 'Counter-Strike 2',
			'valorant'              => 'Valorant',
			'mobile_shooters'       => 'Mobile Shooters',
			'controller_specialist' => 'Controller Specialist',
			'keyboard_mouse'        => 'Keyboard & Mouse Specialist',
			'low_end_pc'            => 'Low-End PC',
			'high_end_pc'           => 'High-End PC',
			'new_player_usability'  => 'New Player Usability',
			'competitive_balance'   => 'Competitive Balance',
			'coop'                  => 'Co-op',
			'accessibility'         => 'Accessibility',
			'strategy'              => 'Strategy',
			'survival'              => 'Survival',
			'rpg'                   => 'RPG',
			'simulation'            => 'Simulation',
		);
	}

	public function __construct() {
		add_action( 'wp_ajax_lcdp_save_tester_profile',     array( $this, 'ajax_save_profile' ) );
		add_action( 'wp_ajax_lcdp_tester_apply',            array( $this, 'ajax_apply' ) );
		add_action( 'wp_ajax_lcdp_submit_sample_task',      array( $this, 'ajax_sample_task' ) );
	}

	public static function get_profile( $user_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . LCDP_Database::table('tester_profiles') . ' WHERE user_id=%d',
			$user_id
		) );
	}

	public function ajax_save_profile() {
		LCDP_Security::ajax_check( 'lcdp_tester_nonce', 'lcdp_manage_tester_profile' );
		$user_id = get_current_user_id();
		$data    = $_POST;

		// Age must be confirmed — 18+
		if ( empty( $data['age_confirmed'] ) ) {
			wp_send_json_error( array( 'message' => 'You must confirm you are 18 or over to join the Playtest Crew.' ) );
		}

		$platforms   = is_array( $data['platforms'] ?? null )   ? array_map('sanitize_text_field', $data['platforms'])   : array();
		$genres      = is_array( $data['genres'] ?? null )      ? array_map('sanitize_text_field', $data['genres'])      : array();
		$categories  = is_array( $data['categories'] ?? null )  ? array_map('sanitize_text_field', $data['categories'])  : array();
		$availability= is_array( $data['availability'] ?? null )? array_map('sanitize_text_field', $data['availability']): array();

		$row = array(
			'age_confirmed'       => 1,
			'country'             => strtoupper( sanitize_text_field( $data['country'] ?? '' ) ),
			'timezone'            => sanitize_text_field( $data['timezone'] ?? '' ),
			'languages'           => sanitize_text_field( $data['languages'] ?? '' ),
			'platforms'           => wp_json_encode( $platforms ),
			'pc_specs'            => wp_json_encode( array(
				'cpu' => sanitize_text_field( $data['pc_cpu'] ?? '' ),
				'gpu' => sanitize_text_field( $data['pc_gpu'] ?? '' ),
				'ram' => sanitize_text_field( $data['pc_ram'] ?? '' ),
				'os'  => sanitize_text_field( $data['pc_os']  ?? '' ),
			) ),
			'mobile_device'       => sanitize_text_field( $data['mobile_device'] ?? '' ),
			'has_controller'      => empty( $data['has_controller'] ) ? 0 : 1,
			'has_keyboard_mouse'  => empty( $data['has_keyboard_mouse'] ) ? 0 : 1,
			'internet_category'   => sanitize_text_field( $data['internet_category'] ?? '' ),
			'genres'              => wp_json_encode( $genres ),
			'competitive_casual'  => sanitize_text_field( $data['competitive_casual'] ?? '' ),
			'ranked_experience'   => sanitize_text_field( $data['ranked_experience'] ?? '' ),
			'multiplayer_pref'    => sanitize_text_field( $data['multiplayer_pref'] ?? '' ),
			'availability'        => wp_json_encode( $availability ),
			'voice_chat'          => empty( $data['voice_chat'] ) ? 0 : 1,
			'recording_willing'   => empty( $data['recording_willing'] ) ? 0 : 1,
			'nda_willing'         => empty( $data['nda_willing'] ) ? 0 : 1,
			'payment_method'      => sanitize_text_field( $data['payment_method'] ?? '' ),
			'accessibility_interest' => empty( $data['accessibility_interest'] ) ? 0 : 1,
			'categories'          => wp_json_encode( $categories ),
			'updated_at'          => current_time('mysql'),
		);

		global $wpdb;
		$existing = self::get_profile( $user_id );
		if ( $existing ) {
			$wpdb->update( LCDP_Database::table('tester_profiles'), $row, array( 'user_id' => $user_id ) );
		} else {
			$row['user_id']    = $user_id;
			$row['created_at'] = current_time('mysql');
			$row['status']     = 'application_received';
			$wpdb->insert( LCDP_Database::table('tester_profiles'), $row );
			$user = new WP_User( $user_id );
			$user->add_role( 'lcdp_tester' );
			LCDP_Consent::record( $user_id, 'account_creation', true, 'tester_registration' );
			do_action( 'lcdp_tester_applied', $user_id );
			LCDP_Email::send_tester_notification( $user_id, 'application_received' );
		}

		// AI flag pass — runs asynchronously via action (does NOT auto-reject)
		do_action( 'lcdp_ai_flag_tester', $user_id );

		// Check profile completeness for points
		$completeness = self::profile_completeness( $user_id );
		if ( $completeness >= 80 ) {
			do_action( 'lcdp_profile_80pct', $user_id );
		}

		wp_send_json_success( array(
			'message'      => 'Profile saved. Your application is under review.',
			'completeness' => $completeness,
		) );
	}

	// Human reviewer updates status — AI CANNOT auto-reject
	public static function update_status( $tester_user_id, $new_status, $reviewer_id, $notes = '' ) {
		global $wpdb;
		$allowed = array_keys( self::statuses() );
		if ( ! in_array( $new_status, $allowed, true ) ) { return false; }

		$wpdb->update( LCDP_Database::table('tester_profiles'), array(
			'status'        => $new_status,
			'reviewer_id'   => absint( $reviewer_id ),
			'review_notes'  => sanitize_textarea_field( $notes ),
			'reviewed_at'   => current_time('mysql'),
			'updated_at'    => current_time('mysql'),
		), array( 'user_id' => $tester_user_id ) );

		LCDP_Security::audit( 'tester_status_updated', "Tester {$tester_user_id} status → {$new_status}", 'tester', $tester_user_id );

		if ( 'approved' === $new_status || 'specialist_approved' === $new_status ) {
			LCDP_Email::send_tester_notification( $tester_user_id, 'application_approved' );
		} elseif ( 'rejected' === $new_status ) {
			LCDP_Email::send_tester_notification( $tester_user_id, 'application_rejected' );
		}
		return true;
	}

	// Flag by AI — records flags but does NOT change status. Human must review.
	public static function ai_flag( $tester_user_id, $flags ) {
		global $wpdb;
		$wpdb->update( LCDP_Database::table('tester_profiles'), array(
			'ai_flags'   => wp_json_encode( $flags ),
			'updated_at' => current_time('mysql'),
		), array( 'user_id' => $tester_user_id ) );
		// Move to human_review if not already approved/rejected
		$profile = self::get_profile( $tester_user_id );
		if ( $profile && in_array( $profile->status, array( 'application_received', 'profile_incomplete' ), true ) ) {
			$wpdb->update( LCDP_Database::table('tester_profiles'),
				array( 'status' => 'human_review', 'updated_at' => current_time('mysql') ),
				array( 'user_id' => $tester_user_id )
			);
		}
	}

	public function ajax_apply() {
		LCDP_Security::ajax_check( 'lcdp_tester_nonce', 'lcdp_apply_campaigns' );
		$user_id     = get_current_user_id();
		$campaign_id = absint( $_POST['campaign_id'] ?? 0 );
		if ( ! $campaign_id ) { wp_send_json_error( array( 'message' => 'Invalid campaign.' ) ); }
		$profile = self::get_profile( $user_id );
		if ( ! $profile || ! in_array( $profile->status, array( 'approved', 'specialist_approved' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Your profile must be approved before applying to campaigns.' ) );
		}
		global $wpdb;
		$existing = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . LCDP_Database::table('campaign_applications') . ' WHERE campaign_id=%d AND tester_user_id=%d',
			$campaign_id, $user_id
		) );
		if ( $existing ) { wp_send_json_error( array( 'message' => 'You have already applied.' ) ); }

		$match = self::calculate_match_score( $user_id, $campaign_id );
		$wpdb->insert( LCDP_Database::table('campaign_applications'), array(
			'campaign_id'       => $campaign_id,
			'tester_user_id'    => $user_id,
			'match_score'       => $match['score'],
			'match_breakdown'   => wp_json_encode( $match['breakdown'] ),
			'status'            => 'pending',
			'applied_at'        => current_time('mysql'),
		), array( '%d','%d','%f','%s','%s','%s' ) );

		do_action( 'lcdp_tester_applied_campaign', $user_id, $campaign_id );
		wp_send_json_success( array( 'message' => 'Application received. Our team will review matches shortly.' ) );
	}

	public function ajax_sample_task() {
		LCDP_Security::ajax_check( 'lcdp_tester_nonce', 'lcdp_manage_tester_profile' );
		$user_id = get_current_user_id();
		$text    = sanitize_textarea_field( $_POST['sample_feedback'] ?? '' );
		if ( strlen( $text ) < 50 ) {
			wp_send_json_error( array( 'message' => 'Please provide more detailed feedback (at least 50 characters).' ) );
		}
		update_user_meta( $user_id, '_lcdp_sample_task_response', $text );
		update_user_meta( $user_id, '_lcdp_sample_task_submitted', current_time('mysql') );
		global $wpdb;
		$wpdb->update( LCDP_Database::table('tester_profiles'),
			array( 'status' => 'human_review', 'updated_at' => current_time('mysql') ),
			array( 'user_id' => $user_id )
		);
		wp_send_json_success( array( 'message' => 'Sample task submitted. A reviewer will check your application.' ) );
	}

	// Simple matching score (Phase 1 — manual assignment follows)
	public static function calculate_match_score( $user_id, $campaign_id ) {
		global $wpdb;
		$campaign = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . LCDP_Database::table('campaigns') . ' WHERE id=%d', $campaign_id
		) );
		$tester   = self::get_profile( $user_id );
		if ( ! $campaign || ! $tester ) { return array( 'score' => 0, 'breakdown' => array() ); }

		$score      = 0;
		$breakdown  = array();
		$c_platforms = json_decode( $campaign->platforms ?: '[]', true );
		$t_platforms = json_decode( $tester->platforms ?: '[]', true );
		$t_genres    = json_decode( $tester->genres ?: '[]', true );
		$t_cats      = json_decode( $tester->categories ?: '[]', true );

		// Genre/category fit — 30%
		$genre_match = in_array( strtolower($campaign->genre), array_map('strtolower', $t_genres), true ) ? 30 : 0;
		$score      += $genre_match;
		$breakdown['genre_fit'] = $genre_match;

		// Platform — 20%
		$platform_overlap = count( array_intersect( $c_platforms, $t_platforms ) );
		$plat_score       = $platform_overlap > 0 ? 20 : 0;
		$score           += $plat_score;
		$breakdown['platform_fit'] = $plat_score;

		// Reliability — 10%
		$reliability = min( 10, round( $tester->reliability_score / 10 ) );
		$score      += $reliability;
		$breakdown['reliability'] = $reliability;

		// Availability — 15% (simplified: if tester has any availability set)
		$avail_score = ! empty( $tester->availability ) ? 15 : 0;
		$score      += $avail_score;
		$breakdown['availability'] = $avail_score;

		// NDA willingness if required — 10%
		$nda_score = $campaign->nda_required ? ( $tester->nda_willing ? 10 : 0 ) : 10;
		$score    += $nda_score;
		$breakdown['nda'] = $nda_score;

		// Recording if required — 5%
		$rec_score = $campaign->recording_permission ? ( $tester->recording_willing ? 5 : 0 ) : 5;
		$score    += $rec_score;
		$breakdown['recording'] = $rec_score;

		return array( 'score' => min( 100, $score ), 'breakdown' => $breakdown );
	}

	// Calculate profile completeness percentage
	public static function profile_completeness( $user_id ) {
		$profile = self::get_profile( $user_id );
		if ( ! $profile ) { return 0; }
		$fields = array(
			'country', 'timezone', 'languages', 'platforms', 'genres',
			'internet_category', 'payment_method', 'competitive_casual',
		);
		$filled = 0;
		foreach ( $fields as $field ) {
			$val = $profile->$field ?? '';
			if ( ! empty( $val ) && '[]' !== $val && 'null' !== $val ) { $filled++; }
		}
		return round( ( $filled / count($fields) ) * 100 );
	}
}
