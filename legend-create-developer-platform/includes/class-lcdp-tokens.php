<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Legend Points and Token system.
 *
 * Points are earned through participation. Every 1000 points auto-converts
 * to 1 Legend Token stored in the token_ledger. When a user holds 5 tokens
 * they can redeem them for 6 months of Developer Starter membership.
 *
 * Points are NEVER awarded for positive feedback, wishlists or Steam reviews.
 */
class LCDP_Tokens {

	const POINTS_PER_TOKEN   = 1000;
	const TOKENS_FOR_6_MONTH = 5;
	const MEMBERSHIP_MONTHS  = 6;
	const MEMBERSHIP_PLAN    = 'developer_starter';

	// Activity definitions: key => array( points, description, max_per_day )
	// max_per_day = 0 means one-time only. -1 = unlimited repeats.
	public static function activity_config() {
		return array(
			'register_verify'         => array( 25,  'Account registered and email verified',    0  ),
			'complete_profile'        => array( 100, 'Profile completed (80%+)',                 0  ),
			'submit_game_approved'    => array( 200, 'Game submission approved',                 -1 ),
			'comment_game_guide'      => array( 10,  'Comment on game or guide',                 5  ),
			'submit_game_review'      => array( 75,  'Game review approved',                     -1 ),
			'complete_playtest'       => array( 150, 'Playtest submission accepted',             -1 ),
			'bug_p0_p1_accepted'      => array( 100, 'Critical or major bug report accepted',    -1 ),
			'bug_p2_accepted'         => array( 50,  'Significant bug report accepted',          -1 ),
			'bug_p3_p4_accepted'      => array( 20,  'Minor bug or suggestion accepted',         -1 ),
			'expert_review_accepted'  => array( 300, 'Expert review accepted',                   -1 ),
			'developer_campaign_paid' => array( 150, 'Campaign commissioned and paid',           -1 ),
			'developer_campaign_done' => array( 100, 'Campaign completed',                       -1 ),
			'tester_reliability_bonus'=> array( 25,  'Monthly reliability bonus maintained',     0  ),
			'specialist_certified'    => array( 200, 'Specialist tester category certified',     -1 ),
			'refer_developer'         => array( 500, 'Referred a developer who signed up',       -1 ),
			'first_playtest_bonus'    => array( 50,  'First accepted playtest bonus',             0  ),
			'perfect_submission'      => array( 50,  'Perfect submission (5-star rating)',        -1 ),
			'expert_profile_verified' => array( 200, 'Expert profile verified by staff',         0  ),
			'admin_award'             => array( 0,   'Manual award by administrator',             -1 ),
		);
	}

	public function __construct() {
		add_action( 'lcdp_game_approved',          array( $this, 'on_game_approved' ), 10, 1 );
		add_action( 'lcdp_tester_submission_ok',   array( $this, 'on_playtest_done' ), 10, 2 );
		add_action( 'lcdp_bug_report_accepted',    array( $this, 'on_bug_accepted' ), 10, 2 );
		add_action( 'lcdp_expert_review_accepted', array( $this, 'on_expert_review' ), 10, 1 );
		add_action( 'lcdp_campaign_paid',          array( $this, 'on_campaign_paid' ), 10, 2 );
		add_action( 'lcdp_campaign_completed',     array( $this, 'on_campaign_completed' ), 10, 2 );
		add_action( 'comment_post',                array( $this, 'on_comment' ), 10, 2 );
		add_action( 'lcdp_profile_80pct',          array( $this, 'on_profile_complete' ), 10, 1 );
		add_action( 'user_register',               array( $this, 'on_register' ), 10, 1 );
	}

	// --- Award methods (all route through award_points) ---

	public function on_register( $user_id ) {
		$this->award_points( $user_id, 'register_verify', 0, 'user', $user_id );
	}

	public function on_game_approved( $game_post_id ) {
		$user_id = get_post_field( 'post_author', $game_post_id );
		if ( $user_id ) {
			$this->award_points( $user_id, 'submit_game_approved', $game_post_id, 'lcdp_game', $game_post_id );
		}
	}

	public function on_playtest_done( $submission_id, $tester_user_id ) {
		// First playtest bonus
		$history = $this->get_activity_count( $tester_user_id, 'complete_playtest' );
		if ( 0 === $history ) {
			$this->award_points( $tester_user_id, 'first_playtest_bonus', $submission_id, 'submission', $submission_id );
		}
		$this->award_points( $tester_user_id, 'complete_playtest', $submission_id, 'submission', $submission_id );
	}

	public function on_bug_accepted( $bug_id, $severity ) {
		global $wpdb;
		$bug = $wpdb->get_row( $wpdb->prepare(
			'SELECT tester_user_id, severity FROM ' . LCDP_Database::table('bug_reports') . ' WHERE id=%d', $bug_id
		) );
		if ( ! $bug ) { return; }
		$activity = in_array( $bug->severity, array( 'P0', 'P1' ), true )
			? 'bug_p0_p1_accepted'
			: ( 'P2' === $bug->severity ? 'bug_p2_accepted' : 'bug_p3_p4_accepted' );
		$this->award_points( $bug->tester_user_id, $activity, $bug_id, 'bug_report', $bug_id );
	}

	public function on_expert_review( $assignment_id ) {
		$user_id = get_post_meta( $assignment_id, '_lcdp_expert_user_id', true );
		if ( $user_id ) {
			$this->award_points( $user_id, 'expert_review_accepted', $assignment_id, 'assignment', $assignment_id );
		}
	}

	public function on_campaign_paid( $campaign_id, $developer_user_id ) {
		$this->award_points( $developer_user_id, 'developer_campaign_paid', $campaign_id, 'campaign', $campaign_id );
	}

	public function on_campaign_completed( $campaign_id, $developer_user_id ) {
		$this->award_points( $developer_user_id, 'developer_campaign_done', $campaign_id, 'campaign', $campaign_id );
	}

	public function on_comment( $comment_id, $comment_approved ) {
		if ( 1 !== (int) $comment_approved ) { return; }
		$comment = get_comment( $comment_id );
		if ( ! $comment || ! $comment->user_id ) { return; }
		$post = get_post( $comment->comment_post_ID );
		if ( ! $post || ! in_array( $post->post_type, array( 'game', 'game_guide', 'lcdp_game' ), true ) ) { return; }
		$this->award_points( $comment->user_id, 'comment_game_guide', $comment_id, 'comment', $comment_id );
	}

	public function on_profile_complete( $user_id ) {
		if ( ! $this->get_activity_count( $user_id, 'complete_profile' ) ) {
			$this->award_points( $user_id, 'complete_profile', 0, 'user', $user_id );
		}
	}

	// --- Core award logic ---

	/**
	 * Award points for an activity.
	 * Handles daily caps, auto-converts points to tokens, triggers membership when threshold met.
	 */
	public static function award_points( $user_id, $activity_type, $points_override = 0, $ref_type = '', $ref_id = 0 ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( ! $user_id ) { return false; }

		$config = self::activity_config();
		if ( ! isset( $config[ $activity_type ] ) ) { return false; }
		list( $base_points, $description, $max_per_day ) = $config[ $activity_type ];

		$points = $points_override > 0 ? $points_override : $base_points;
		if ( ! $points ) { return false; }

		// Daily cap check
		if ( $max_per_day > 0 ) {
			$today = current_time('Y-m-d');
			$count_today = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM ' . LCDP_Database::table('token_ledger') .
				' WHERE user_id=%d AND activity_type=%s AND DATE(created_at)=%s',
				$user_id, $activity_type, $today
			) );
			if ( $count_today >= $max_per_day ) { return false; }
		}

		// One-time check
		if ( 0 === $max_per_day ) {
			$existing = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM ' . LCDP_Database::table('token_ledger') .
				' WHERE user_id=%d AND activity_type=%s',
				$user_id, $activity_type
			) );
			if ( $existing > 0 ) { return false; }
		}

		// Get current balance
		$last = $wpdb->get_row( $wpdb->prepare(
			'SELECT balance_after, tokens_balance FROM ' . LCDP_Database::table('token_ledger') .
			' WHERE user_id=%d ORDER BY id DESC LIMIT 1',
			$user_id
		) );
		$current_points = $last ? (int) $last->balance_after : 0;
		$current_tokens = $last ? (int) $last->tokens_balance : 0;

		$new_points       = $current_points + $points;
		$tokens_converted = 0;

		// Auto-convert every POINTS_PER_TOKEN points into 1 token
		while ( $new_points >= self::POINTS_PER_TOKEN ) {
			$new_points -= self::POINTS_PER_TOKEN;
			$current_tokens++;
			$tokens_converted++;
		}

		$wpdb->insert( LCDP_Database::table('token_ledger'), array(
			'user_id'           => $user_id,
			'activity_type'     => sanitize_key( $activity_type ),
			'points'            => $points,
			'tokens_converted'  => $tokens_converted,
			'balance_after'     => $new_points,
			'tokens_balance'    => $current_tokens,
			'reference_type'    => sanitize_key( $ref_type ),
			'reference_id'      => absint( $ref_id ),
			'description'       => sanitize_text_field( $description ),
			'created_at'        => current_time('mysql'),
		), array( '%d','%s','%d','%d','%d','%d','%s','%d','%s','%s' ) );

		if ( $tokens_converted ) {
			self::check_membership_threshold( $user_id, $current_tokens );
		}

		do_action( 'lcdp_points_awarded', $user_id, $activity_type, $points, $current_tokens );
		return $points;
	}

	// Check if user has earned the free membership
	private static function check_membership_threshold( $user_id, $token_balance ) {
		if ( $token_balance < self::TOKENS_FOR_6_MONTH ) { return; }
		// Only reward once until they redeem
		$redeemed = get_user_meta( $user_id, '_lcdp_token_membership_notified', true );
		if ( ! $redeemed ) {
			update_user_meta( $user_id, '_lcdp_token_membership_notified', '1' );
			update_user_meta( $user_id, '_lcdp_token_membership_ready', '1' );
			do_action( 'lcdp_membership_reward_ready', $user_id );
		}
	}

	// Redeem 5 tokens for 6 months Developer Starter membership
	public static function redeem_membership( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( ! $user_id ) { return false; }
		if ( ! get_user_meta( $user_id, '_lcdp_token_membership_ready', true ) ) { return false; }

		$last = $wpdb->get_row( $wpdb->prepare(
			'SELECT balance_after, tokens_balance FROM ' . LCDP_Database::table('token_ledger') .
			' WHERE user_id=%d ORDER BY id DESC LIMIT 1',
			$user_id
		) );
		$tokens = $last ? (int) $last->tokens_balance : 0;
		if ( $tokens < self::TOKENS_FOR_6_MONTH ) { return false; }

		// Deduct tokens
		$new_tokens = $tokens - self::TOKENS_FOR_6_MONTH;
		$wpdb->insert( LCDP_Database::table('token_ledger'), array(
			'user_id'           => $user_id,
			'activity_type'     => 'membership_redemption',
			'points'            => 0,
			'tokens_converted'  => 0,
			'balance_after'     => $last->balance_after,
			'tokens_balance'    => $new_tokens,
			'reference_type'    => 'membership',
			'reference_id'      => 0,
			'description'       => '6-month Developer Starter membership redeemed',
			'created_at'        => current_time('mysql'),
		), array( '%d','%s','%d','%d','%d','%d','%s','%d','%s','%s' ) );

		// Grant membership
		$expiry = date( 'Y-m-d H:i:s', strtotime( '+' . self::MEMBERSHIP_MONTHS . ' months' ) );
		global $wpdb2;
		$wpdb->update(
			LCDP_Database::table('developer_profiles'),
			array( 'membership_plan' => self::MEMBERSHIP_PLAN, 'membership_expires' => $expiry ),
			array( 'user_id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		delete_user_meta( $user_id, '_lcdp_token_membership_ready' );
		delete_user_meta( $user_id, '_lcdp_token_membership_notified' );
		update_user_meta( $user_id, '_lcdp_last_membership_redemption', current_time('mysql') );
		do_action( 'lcdp_membership_redeemed', $user_id, $expiry );
		LCDP_Security::audit( 'membership_redeemed', "User {$user_id} redeemed 5 tokens for 6-month membership", 'user', $user_id );
		return true;
	}

	// Get user wallet summary
	public static function get_wallet( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$last = $wpdb->get_row( $wpdb->prepare(
			'SELECT balance_after, tokens_balance FROM ' . LCDP_Database::table('token_ledger') .
			' WHERE user_id=%d ORDER BY id DESC LIMIT 1',
			$user_id
		) );
		$total_points_earned = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT SUM(points) FROM ' . LCDP_Database::table('token_ledger') . ' WHERE user_id=%d AND points > 0',
			$user_id
		) );
		return array(
			'points_remaining'  => $last ? (int) $last->balance_after : 0,
			'tokens'            => $last ? (int) $last->tokens_balance : 0,
			'total_earned'      => $total_points_earned ?: 0,
			'points_to_token'   => self::POINTS_PER_TOKEN,
			'tokens_needed'     => self::TOKENS_FOR_6_MONTH,
			'membership_ready'  => (bool) get_user_meta( $user_id, '_lcdp_token_membership_ready', true ),
			'progress_pct'      => $last
				? min( 100, round( ($last->tokens_balance / self::TOKENS_FOR_6_MONTH) * 100 ) )
				: 0,
		);
	}

	// Get transaction history
	public static function get_history( $user_id, $limit = 20 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . LCDP_Database::table('token_ledger') .
			' WHERE user_id=%d ORDER BY id DESC LIMIT %d',
			$user_id, $limit
		) );
	}

	private function get_activity_count( $user_id, $activity_type ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . LCDP_Database::table('token_ledger') . ' WHERE user_id=%d AND activity_type=%s',
			$user_id, $activity_type
		) );
	}
}
