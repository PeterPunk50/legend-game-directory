<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Provider_External_Score implements LGD_Provider_Interface {
	public function validate_configuration() { return true; }
	public function search_games( $query = '', $args = array() ) { unset( $query, $args ); return array(); }
	public function get_game( $external_id ) { unset( $external_id ); return new WP_Error( 'lgd_external_manual_only', __( 'External scores are manual or licensed-only.', 'legend-game-directory' ) ); }
	public function normalize_game( $data ) {
		if ( empty( $data['source_url'] ) || empty( $data['title'] ) ) { return new WP_Error( 'lgd_external_incomplete', __( 'External score entries require a title and source URL.', 'legend-game-directory' ) ); }
		foreach ( array( 'critic_score', 'user_score' ) as $key ) {
			if ( isset( $data[ $key ] ) && ( ! is_numeric( $data[ $key ] ) || $data[ $key ] < 0 || $data[ $key ] > 100 ) ) { return new WP_Error( 'lgd_external_score_range', __( 'External scores must use a documented 0–100 scale.', 'legend-game-directory' ) ); }
		}
		return array(
			'external_id' => ! empty( $data['external_id'] ) ? sanitize_text_field( $data['external_id'] ) : hash( 'sha256', $data['source_url'] ),
			'title' => sanitize_text_field( $data['title'] ), 'source_url' => esc_url_raw( $data['source_url'] ),
			'external_critic_score' => isset( $data['critic_score'] ) ? (float) $data['critic_score'] : null,
			'external_user_score' => isset( $data['user_score'] ) ? (float) $data['user_score'] : null,
			'critic_review_count' => isset( $data['critic_review_count'] ) ? absint( $data['critic_review_count'] ) : 0,
			'user_rating_count' => isset( $data['user_rating_count'] ) ? absint( $data['user_rating_count'] ) : 0,
			'platform' => isset( $data['platform'] ) ? sanitize_text_field( $data['platform'] ) : '',
			'confidence' => 70, 'retrieved_at' => current_time( 'mysql', true ), 'raw' => $data,
		);
	}
	public function get_source_name() { return 'Manual/licensed external score'; }
	public function get_source_url() { return ''; }
	public function get_rate_limit() { return 0; }
	public function health_check() { return array( 'ok' => true, 'message' => __( 'Manual external-score fallback is available. No Metacritic automation is included.', 'legend-game-directory' ) ); }
}
