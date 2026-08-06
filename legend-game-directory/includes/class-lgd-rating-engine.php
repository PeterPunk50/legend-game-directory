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

	/**
	 * Map a normalized provider record onto the 0–100 rating criteria using only signals
	 * that are actually present. Criteria with no supporting evidence are omitted, so the
	 * rating engine treats them as missing (lowering coverage/confidence) rather than guessing.
	 */
	public static function derive_facts( $data ) {
		$f = array();

		// Player sentiment from a store review summary (e.g. Steam "Very Positive").
		$sentiment = isset( $data['steam_sentiment'] ) ? strtolower( trim( (string) $data['steam_sentiment'] ) ) : '';
		$sentiment_map = array(
			'overwhelmingly positive' => 97, 'very positive' => 90, 'positive' => 82, 'mostly positive' => 72,
			'mixed' => 50, 'mostly negative' => 32, 'negative' => 22, 'very negative' => 12, 'overwhelmingly negative' => 5,
		);
		if ( isset( $sentiment_map[ $sentiment ] ) ) { $f['player_sentiment'] = $sentiment_map[ $sentiment ]; }

		// Review consensus only from an actual external critic score (Tier 2), never invented.
		if ( isset( $data['external_critic_score'] ) && is_numeric( $data['external_critic_score'] ) ) {
			$f['review_consensus'] = min( 100, max( 0, (float) $data['external_critic_score'] ) );
		}

		// Value & monetization from the disclosed pricing model.
		$free_type = isset( $data['free_type'] ) ? $data['free_type'] : '';
		$value_map = array( 'Open Source' => 95, 'Permanently Free' => 90, 'Temporarily Free' => 82, 'Free to Play' => 78, 'Free Demo' => 68, 'Freemium' => 60 );
		if ( isset( $value_map[ $free_type ] ) ) {
			$value = $value_map[ $free_type ];
			if ( ! empty( $data['advertising'] ) && preg_match( '/aggressive|heavy|intrusive/i', (string) $data['advertising'] ) ) { $value -= 15; }
			if ( ! empty( $data['in_app_purchases'] ) && preg_match( '/pay.?to.?win|aggressive|heavy/i', (string) $data['in_app_purchases'] ) ) { $value -= 15; }
			$f['value_monetization'] = max( 0, $value );
		}

		// Update activity from the most recent known date.
		$date = ! empty( $data['last_update_date'] ) ? $data['last_update_date'] : ( ! empty( $data['release_date'] ) ? $data['release_date'] : '' );
		$ts = $date ? strtotime( (string) $date ) : false;
		if ( $ts ) {
			$months = ( time() - $ts ) / ( 30 * DAY_IN_SECONDS );
			$f['update_activity'] = $months <= 3 ? 95 : ( $months <= 6 ? 85 : ( $months <= 12 ? 70 : ( $months <= 24 ? 50 : ( $months <= 48 ? 35 : 20 ) ) ) );
		}

		// Platform support from breadth of supported platforms.
		$platforms = isset( $data['platforms'] ) && is_array( $data['platforms'] ) ? count( array_filter( $data['platforms'] ) ) : 0;
		if ( $platforms > 0 ) { $f['platform_support'] = min( 100, 45 + $platforms * 15 ); }

		// Accessibility from controller support and language breadth.
		$accessibility = null;
		if ( ! empty( $data['controller_support'] ) ) { $accessibility = ( 'full' === strtolower( (string) $data['controller_support'] ) ) ? 85 : 68; }
		$langs = isset( $data['supported_languages'] ) && is_array( $data['supported_languages'] ) ? count( array_filter( $data['supported_languages'] ) ) : 0;
		if ( $langs > 0 ) { $lang_score = min( 100, 45 + $langs * 5 ); $accessibility = null === $accessibility ? $lang_score : (int) round( ( $accessibility + $lang_score ) / 2 ); }
		if ( null !== $accessibility ) { $f['accessibility'] = $accessibility; }

		// Safety & transparency from a recognized legitimate storefront and absence of safety flags.
		if ( ! empty( $data['source_url'] ) ) {
			$host = strtolower( (string) wp_parse_url( $data['source_url'], PHP_URL_HOST ) );
			$trusted = array( 'steampowered.com', 'apple.com', 'itunes.apple.com', 'play.google.com', 'itch.io' );
			foreach ( $trusted as $t ) {
				if ( $host === $t || substr( $host, -strlen( '.' . $t ) ) === '.' . $t ) { $f['safety_transparency'] = empty( $data['safety_notes'] ) ? 88 : 45; break; }
			}
		}

		// Technical stability is inferred conservatively from player sentiment when available (stability complaints surface there).
		if ( isset( $f['player_sentiment'] ) ) { $f['technical_stability'] = max( 0, $f['player_sentiment'] - 5 ); }

		return $f;
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
