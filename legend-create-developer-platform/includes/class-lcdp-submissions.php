<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LCDP_Submissions {

	public function __construct() {
		add_action( 'wp_ajax_lcdp_submit_feedback', array( $this, 'ajax_feedback' ) );
		add_action( 'wp_ajax_lcdp_submit_bug',      array( $this, 'ajax_bug' ) );
		add_action( 'wp_ajax_lcdp_review_submission',array( $this, 'ajax_review' ) );
	}

	public function ajax_feedback() {
		LCDP_Security::ajax_check( 'lcdp_submission_nonce', 'lcdp_submit_feedback' );
		$user_id     = get_current_user_id();
		$campaign_id = absint( $_POST['campaign_id'] ?? 0 );

		// Verify assignment
		global $wpdb;
		$assigned = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM " . LCDP_Database::table('campaign_applications') .
			" WHERE campaign_id=%d AND tester_user_id=%d AND status='assigned'",
			$campaign_id, $user_id
		) );
		if ( ! $assigned ) {
			wp_send_json_error( array( 'message' => 'You are not assigned to this campaign.' ) );
		}

		$data = LCDP_Security::sanitize_form( $_POST, array(
			'campaign_id'       => 'int',
			'session_duration'  => 'int',
			'confusion_points'  => 'textarea',
			'positive_notes'    => 'textarea',
			'negative_notes'    => 'textarea',
			'overall_feedback'  => 'textarea',
			'similar_games'     => 'text',
			'stopped_reason'    => 'text',
		) );
		$would_play = isset( $_POST['would_play_again'] ) ? (int)(bool)$_POST['would_play_again'] : null;

		// Rating fields 1-10
		$rating_fields = array(
			'first_impression','controls_rating','tutorial_rating','visual_rating',
			'performance_rating','difficulty_rating','combat_rating','sound_rating',
			'originality_rating','play_again_rating','recommend_rating',
		);
		$ratings = array();
		foreach ( $rating_fields as $f ) {
			$ratings[ $f ] = max( 0, min( 10, (int)( $_POST[$f] ?? 0 ) ) );
		}

		$tasks = is_array( $_POST['tasks_completed'] ?? null )
			? wp_json_encode( array_map('sanitize_text_field', $_POST['tasks_completed']) )
			: '[]';

		$row = array_merge( $ratings, array(
			'campaign_id'       => $campaign_id,
			'tester_user_id'    => $user_id,
			'session_duration_minutes' => max( 0, $data['session_duration'] ),
			'tasks_completed'   => $tasks,
			'confusion_points'  => $data['confusion_points'],
			'positive_notes'    => $data['positive_notes'],
			'negative_notes'    => $data['negative_notes'],
			'overall_feedback'  => $data['overall_feedback'],
			'similar_games_mentioned' => $data['similar_games'],
			'stopped_reason'    => $data['stopped_reason'],
			'would_play_again'  => $would_play,
			'status'            => 'submitted',
			'submitted_at'      => current_time('mysql'),
		) );

		// Upsert — one submission per tester per campaign
		$existing = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . LCDP_Database::table('tester_submissions') .
			' WHERE campaign_id=%d AND tester_user_id=%d', $campaign_id, $user_id
		) );
		if ( $existing ) {
			unset( $row['submitted_at'] );
			$wpdb->update( LCDP_Database::table('tester_submissions'), $row, array('id' => $existing) );
			$sub_id = $existing;
		} else {
			$wpdb->insert( LCDP_Database::table('tester_submissions'), $row );
			$sub_id = $wpdb->insert_id;
		}

		LCDP_Security::audit( 'feedback_submitted', "Tester {$user_id} submitted feedback for campaign {$campaign_id}", 'campaign', $campaign_id );
		LCDP_Email::send_tester_notification( $user_id, 'submission_received', $campaign_id );
		do_action( 'lcdp_feedback_submitted', $sub_id, $user_id, $campaign_id );
		wp_send_json_success( array( 'message' => 'Feedback submitted. Thank you.' ) );
	}

	public function ajax_bug() {
		LCDP_Security::ajax_check( 'lcdp_submission_nonce', 'lcdp_submit_bug_reports' );
		$user_id     = get_current_user_id();
		$campaign_id = absint( $_POST['campaign_id'] ?? 0 );
		global $wpdb;
		$assigned = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM " . LCDP_Database::table('campaign_applications') .
			" WHERE campaign_id=%d AND tester_user_id=%d AND status='assigned'",
			$campaign_id, $user_id
		) );
		if ( ! $assigned ) {
			wp_send_json_error( array( 'message' => 'You are not assigned to this campaign.' ) );
		}

		$data = LCDP_Security::sanitize_form( $_POST, array(
			'campaign_id'        => 'int',
			'title'              => 'text',
			'build_version'      => 'text',
			'platform'           => 'text',
			'device'             => 'text',
			'severity'           => 'text',
			'frequency'          => 'text',
			'steps_to_reproduce' => 'textarea',
			'expected_result'    => 'textarea',
			'actual_result'      => 'textarea',
			'video_url'          => 'url',
			'log_excerpt'        => 'textarea',
			'workaround'         => 'textarea',
		) );

		if ( empty( $data['title'] ) ) {
			wp_send_json_error( array( 'message' => 'Bug title is required.' ) );
		}

		$severity = in_array( $data['severity'], array('P0','P1','P2','P3','P4'), true ) ? $data['severity'] : 'P3';

		// Screenshot URLs — validate file attachments separately if needed
		$screenshot_urls = is_array( $_POST['screenshot_urls'] ?? null )
			? wp_json_encode( array_map( 'esc_url_raw', $_POST['screenshot_urls'] ) )
			: '[]';

		$wpdb->insert( LCDP_Database::table('bug_reports'), array(
			'campaign_id'        => $campaign_id,
			'tester_user_id'     => $user_id,
			'title'              => $data['title'],
			'build_version'      => $data['build_version'],
			'platform'           => $data['platform'],
			'device'             => $data['device'],
			'severity'           => $severity,
			'priority'           => $severity,
			'frequency'          => $data['frequency'],
			'steps_to_reproduce' => $data['steps_to_reproduce'],
			'expected_result'    => $data['expected_result'],
			'actual_result'      => $data['actual_result'],
			'screenshot_urls'    => $screenshot_urls,
			'video_url'          => $data['video_url'],
			'log_excerpt'        => $data['log_excerpt'],
			'workaround'         => $data['workaround'],
			'status'             => 'new',
			'submitted_at'       => current_time('mysql'),
			'updated_at'         => current_time('mysql'),
		) );

		$bug_id = $wpdb->insert_id;
		LCDP_Security::audit( 'bug_submitted', "Bug '{$data['title']}' (campaign {$campaign_id})", 'campaign', $campaign_id );
		do_action( 'lcdp_bug_submitted', $bug_id, $user_id, $campaign_id );
		wp_send_json_success( array( 'bug_id' => $bug_id, 'message' => 'Bug report submitted.' ) );
	}

	// Coordinator reviews a submission — approve, request revision, or reject with reason
	public function ajax_review() {
		LCDP_Security::ajax_check( 'lcdp_admin_nonce', 'lcdp_review_submissions' );
		$sub_id   = absint( $_POST['submission_id'] ?? 0 );
		$action   = sanitize_key( $_POST['review_action'] ?? '' );
		$notes    = sanitize_textarea_field( $_POST['notes'] ?? '' );
		$reward   = (float)( $_POST['reward_amount'] ?? 0 );
		$allowed  = array( 'approve', 'revision_required', 'reject' );
		if ( ! $sub_id || ! in_array( $action, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid parameters.' ) );
		}
		global $wpdb;
		$sub = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . LCDP_Database::table('tester_submissions') . ' WHERE id=%d', $sub_id
		) );
		if ( ! $sub ) { wp_send_json_error( array( 'message' => 'Submission not found.' ) ); }

		$new_status = array(
			'approve'          => 'under_review',
			'revision_required'=> 'revision_required',
			'reject'           => 'rejected',
		)[ $action ];

		$wpdb->update( LCDP_Database::table('tester_submissions'), array(
			'status'          => $new_status,
			'reviewer_notes'  => $notes,
			'reward_amount'   => max( 0, $reward ),
			'reviewed_at'     => current_time('mysql'),
		), array( 'id' => $sub_id ) );

		if ( 'approve' === $action ) {
			do_action( 'lcdp_tester_submission_ok', $sub_id, $sub->tester_user_id );
			LCDP_Email::send_tester_notification( $sub->tester_user_id, 'submission_approved', $sub->campaign_id );
		} elseif ( 'revision_required' === $action ) {
			LCDP_Email::send_tester_notification( $sub->tester_user_id, 'revision_required', $sub->campaign_id );
		} elseif ( 'reject' === $action ) {
			LCDP_Email::send_tester_notification( $sub->tester_user_id, 'submission_rejected', $sub->campaign_id );
		}

		wp_send_json_success( array( 'message' => 'Submission updated.' ) );
	}
}
