<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Rating_Engine {
	public static function default_weights() {
		return array(
			'review_consensus' => 20, 'player_sentiment' => 15, 'value_monetization' => 15,
			'update_activity' => 10, 'platform_support' => 10, 'accessibility' => 10,
			'technical_stability' => 10, 'safety_transparency' => 10,
		);
	}

	public static function calculate( $facts, $source_confidence = 0 ) {
		$settings = LGD_Security::settings();
		$weights = isset( $settings['weights'] ) && is_array( $settings['weights'] ) ? $settings['weights'] : self::default_weights();
		$total_weight = 0;
		$observed_weight = 0;
		$weighted_score = 0;
		$breakdown = array();
		$missing = array();
		foreach ( self::default_weights() as $criterion => $default_weight ) {
			$weight = isset( $weights[ $criterion ] ) && is_numeric( $weights[ $criterion ] ) ? max( 0, (float) $weights[ $criterion ] ) : $default_weight;
			$total_weight += $weight;
			if ( ! isset( $facts[ $criterion ] ) || ! is_numeric( $facts[ $criterion ] ) ) {
				$breakdown[ $criterion ] = array( 'score' => null, 'weight' => $weight, 'points' => null, 'status' => 'missing' );
				$missing[] = $criterion;
				continue;
			}
			$value = min( 100, max( 0, (float) $facts[ $criterion ] ) );
			$points = $weight * $value / 100;
			$observed_weight += $weight;
			$weighted_score += $points;
			$breakdown[ $criterion ] = array( 'score' => round( $value, 1 ), 'weight' => $weight, 'points' => round( $points, 2 ), 'status' => 'observed' );
		}
		$score = $observed_weight > 0 ? round( $weighted_score / $observed_weight * 100, 1 ) : null;
		$coverage = $total_weight > 0 ? $observed_weight / $total_weight * 100 : 0;
		$confidence = round( min( $coverage, max( 0, (float) $source_confidence ) ), 1 );
		return array( 'score' => $score, 'confidence' => $confidence, 'coverage' => round( $coverage, 1 ), 'breakdown' => $breakdown, 'missing' => $missing );
	}

	public static function save( $game_id, $result, $is_override = false ) {
		global $wpdb;
		$game_id = absint( $game_id );
		if ( ! $game_id || ! is_array( $result ) ) { return false; }
		update_post_meta( $game_id, '_lgd_automated_score', $result['score'] );
		update_post_meta( $game_id, '_lgd_confidence', $result['confidence'] );
		update_post_meta( $game_id, '_lgd_score_breakdown', $result['breakdown'] );
		update_post_meta( $game_id, '_lgd_missing_data', $result['missing'] );
		$wpdb->insert( LGD_Database::table( 'score_history' ), array(
			'game_id' => $game_id, 'score_type' => 'automated', 'score' => $result['score'],
			'breakdown' => wp_json_encode( $result['breakdown'] ), 'confidence' => $result['confidence'],
			'is_override' => $is_override ? 1 : 0, 'user_id' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ),
		), array( '%d', '%s', '%f', '%s', '%f', '%d', '%d', '%s' ) );
		return true;
	}

	public static function override( $game_id, $score, $reason ) {
		if ( ! current_user_can( 'lgd_override_scores' ) ) { return new WP_Error( 'lgd_forbidden', __( 'You cannot override scores.', 'legend-game-directory' ) ); }
		if ( ! is_numeric( $score ) || $score < 0 || $score > 100 || '' === trim( $reason ) ) { return new WP_Error( 'lgd_invalid_override', __( 'A 0–100 score and reason are required.', 'legend-game-directory' ) ); }
		$current = get_post_meta( $game_id, '_lgd_score_breakdown', true );
		$result = array( 'score' => (float) $score, 'confidence' => (float) get_post_meta( $game_id, '_lgd_confidence', true ), 'breakdown' => is_array( $current ) ? $current : array(), 'missing' => (array) get_post_meta( $game_id, '_lgd_missing_data', true ) );
		self::save( $game_id, $result, true );
		LGD_Logger::log( 'score_override', $reason, array( 'score' => $score ), 'warning', 'game', $game_id );
		return true;
	}

	public static function category_criteria() {
		return array(
			'free' => array( 'genuinely_free', 'advertising_pressure', 'pay_to_win', 'purchase_disclosure', 'subscription_required', 'offer_expiry', 'legitimate_source', 'registration_required' ),
			'indie' => array( 'originality', 'gameplay_depth', 'stability', 'developer_updates', 'value', 'community_reception', 'accessibility', 'platform_support' ),
			'mobile' => array( 'touch_controls', 'performance', 'battery_impact', 'advertising_level', 'iap_pressure', 'offline_availability', 'device_compatibility', 'update_frequency', 'accessibility', 'controller_support' ),
		);
	}
}
