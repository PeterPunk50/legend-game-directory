<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Universal ratings for developers, testers, experts, games and campaigns.
 * Each entity_type+entity_id pair gets one rating per rater.
 * Entity types: developer, tester, expert, lcdp_game, campaign
 */
class LCDP_Ratings {

	public function __construct() {
		add_action( 'wp_ajax_lcdp_submit_rating', array( $this, 'ajax_submit' ) );
		add_action( 'lcdp_tester_submission_ok',   array( $this, 'on_submission_accepted' ), 20, 2 );
	}

	// Submit or update a rating via AJAX
	public function ajax_submit() {
		LCDP_Security::ajax_check( 'lcdp_rating_nonce', 'read' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) { wp_send_json_error( array( 'message' => 'Login required.' ), 401 ); }

		$data = LCDP_Security::sanitize_form( $_POST, array(
			'entity_type'  => 'slug',
			'entity_id'    => 'int',
			'rating'       => 'float',
			'review_text'  => 'textarea',
		) );

		$allowed_types = array( 'developer', 'tester', 'expert', 'lcdp_game', 'campaign' );
		if ( ! in_array( $data['entity_type'], $allowed_types, true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid entity type.' ) );
		}
		if ( $data['rating'] < 1 || $data['rating'] > 5 ) {
			wp_send_json_error( array( 'message' => 'Rating must be between 1 and 5.' ) );
		}

		// Prevent self-rating
		if ( 'developer' === $data['entity_type'] || 'tester' === $data['entity_type'] || 'expert' === $data['entity_type'] ) {
			$owner = self::get_entity_owner( $data['entity_type'], $data['entity_id'] );
			if ( $owner === $user_id ) {
				wp_send_json_error( array( 'message' => 'You cannot rate yourself.' ) );
			}
		}

		$result = self::save_rating( $user_id, $data['entity_type'], $data['entity_id'], $data['rating'], $data['review_text'] );
		if ( $result ) {
			$summary = self::get_summary( $data['entity_type'], $data['entity_id'] );
			// Award points for approved review
			LCDP_Tokens::award_points( $user_id, 'submit_game_review', 0, $data['entity_type'], $data['entity_id'] );
			wp_send_json_success( array( 'summary' => $summary ) );
		} else {
			wp_send_json_error( array( 'message' => 'Could not save rating.' ) );
		}
	}

	// Auto-rate tester after submission is accepted (coordinator rates tester quality)
	public function on_submission_accepted( $submission_id, $tester_user_id ) {
		// Coordinator ratings are separate workflow; this fires the hook only
		do_action( 'lcdp_prompt_coordinator_rating', $submission_id, $tester_user_id );
	}

	// --- Static helpers ---

	public static function save_rating( $rater_id, $entity_type, $entity_id, $rating, $review_text = '' ) {
		global $wpdb;
		$table = LCDP_Database::table('ratings');
		$now   = current_time('mysql');

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE entity_type=%s AND entity_id=%d AND rater_user_id=%d",
			$entity_type, $entity_id, $rater_id
		) );

		$row = array(
			'entity_type'   => sanitize_key( $entity_type ),
			'entity_id'     => absint( $entity_id ),
			'rater_user_id' => absint( $rater_id ),
			'rating'        => round( min( 5, max( 1, (float) $rating ) ), 1 ),
			'review_text'   => sanitize_textarea_field( $review_text ),
			'status'        => 'approved',
			'updated_at'    => $now,
		);
		$fmt = array( '%s','%d','%d','%f','%s','%s','%s' );

		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => $existing ), $fmt, array('%d') );
		} else {
			$row['created_at'] = $now;
			$fmt[]             = '%s';
			$wpdb->insert( $table, $row, $fmt );
		}
		return true;
	}

	public static function get_summary( $entity_type, $entity_id ) {
		global $wpdb;
		$result = $wpdb->get_row( $wpdb->prepare(
			'SELECT AVG(rating) as avg_rating, COUNT(*) as total
			 FROM ' . LCDP_Database::table('ratings') .
			" WHERE entity_type=%s AND entity_id=%d AND status='approved'",
			$entity_type, $entity_id
		) );
		if ( ! $result ) {
			return array( 'avg' => 0, 'count' => 0, 'stars' => '' );
		}
		$avg = round( (float) $result->avg_rating, 1 );
		return array(
			'avg'   => $avg,
			'count' => (int) $result->total,
			'stars' => self::stars_html( $avg ),
		);
	}

	public static function get_reviews( $entity_type, $entity_id, $limit = 10 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT r.*, u.display_name AS rater_name
			 FROM ' . LCDP_Database::table('ratings') . ' r
			 LEFT JOIN ' . $wpdb->users . ' u ON u.ID = r.rater_user_id
			 WHERE r.entity_type=%s AND r.entity_id=%d AND r.status=%s
			 ORDER BY r.created_at DESC LIMIT %d',
			$entity_type, $entity_id, 'approved', $limit
		) );
	}

	public static function stars_html( $avg ) {
		$full  = floor( $avg );
		$half  = ( $avg - $full ) >= 0.5 ? 1 : 0;
		$empty = 5 - $full - $half;
		$html  = '<span class="lcdp-stars" aria-label="' . esc_attr( $avg ) . ' out of 5 stars">';
		$html .= str_repeat( '<span class="lcdp-star lcdp-star--full">★</span>', $full );
		if ( $half ) { $html .= '<span class="lcdp-star lcdp-star--half">★</span>'; }
		$html .= str_repeat( '<span class="lcdp-star lcdp-star--empty">☆</span>', $empty );
		$html .= '</span>';
		return $html;
	}

	private static function get_entity_owner( $entity_type, $entity_id ) {
		global $wpdb;
		if ( 'developer' === $entity_type ) {
			return (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT user_id FROM ' . LCDP_Database::table('developer_profiles') . ' WHERE id=%d', $entity_id
			) );
		}
		if ( 'tester' === $entity_type ) {
			return (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT user_id FROM ' . LCDP_Database::table('tester_profiles') . ' WHERE id=%d', $entity_id
			) );
		}
		return 0;
	}
}
