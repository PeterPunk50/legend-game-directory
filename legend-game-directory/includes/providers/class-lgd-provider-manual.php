<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Provider_Manual implements LGD_Provider_Interface {
	public function validate_configuration() { return true; }
	public function search_games( $query = '', $args = array() ) { unset( $query, $args ); return array(); }
	public function get_game( $external_id ) { return new WP_Error( 'lgd_manual_requires_data', __( 'Manual records must be supplied by an administrator.', 'legend-game-directory' ) ); }
	public function normalize_game( $data ) {
		if ( empty( $data['title'] ) || empty( $data['source_url'] ) ) { return new WP_Error( 'lgd_manual_incomplete', __( 'A title and source URL are required.', 'legend-game-directory' ) ); }
		$free_types = array( 'Permanently Free', 'Free to Play', 'Freemium', 'Free Demo', 'Temporarily Free', 'Open Source', 'Paid Indie' );
		$free_type = isset( $data['free_type'] ) && in_array( $data['free_type'], $free_types, true ) ? $data['free_type'] : '';
		return array(
			'external_id' => ! empty( $data['external_id'] ) ? sanitize_text_field( $data['external_id'] ) : hash( 'sha256', $data['source_url'] ),
			'title' => sanitize_text_field( $data['title'] ), 'source_url' => esc_url_raw( $data['source_url'] ),
			'description' => isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '',
			'developer' => isset( $data['developer'] ) ? sanitize_text_field( $data['developer'] ) : '',
			'publisher' => isset( $data['publisher'] ) ? sanitize_text_field( $data['publisher'] ) : '',
			'release_date' => isset( $data['release_date'] ) ? sanitize_text_field( $data['release_date'] ) : '',
			'platforms' => isset( $data['platforms'] ) ? LGD_Security::sanitize_string_list( $data['platforms'] ) : array(),
			'genres' => isset( $data['genres'] ) ? LGD_Security::sanitize_string_list( $data['genres'] ) : array(),
			'is_free' => ! empty( $data['is_free'] ), 'free_type' => $free_type,
			'is_indie' => ! empty( $data['is_indie'] ), 'indie_confidence' => isset( $data['indie_confidence'] ) ? min( 100, max( 0, (float) $data['indie_confidence'] ) ) : ( ! empty( $data['is_indie'] ) ? 50 : 0 ),
			'is_mobile' => ! empty( $data['is_mobile'] ),
			'current_price' => isset( $data['current_price'] ) && is_numeric( $data['current_price'] ) ? (float) $data['current_price'] : null,
			'original_price' => isset( $data['original_price'] ) && is_numeric( $data['original_price'] ) ? (float) $data['original_price'] : null,
			'currency' => isset( $data['currency'] ) ? sanitize_text_field( $data['currency'] ) : '',
			'android_app_id' => isset( $data['android_app_id'] ) ? sanitize_text_field( $data['android_app_id'] ) : '',
			'ios_app_id' => isset( $data['ios_app_id'] ) ? sanitize_text_field( $data['ios_app_id'] ) : '',
			'confidence' => isset( $data['confidence'] ) ? min( 100, max( 0, (float) $data['confidence'] ) ) : 25,
			'retrieved_at' => current_time( 'mysql', true ), 'raw' => $data,
		);
	}
	public function get_source_name() { return 'Manual administrator entry'; }
	public function get_source_url() { return admin_url( 'edit.php?post_type=game&page=lgd-settings' ); }
	public function get_rate_limit() { return 0; }
	public function health_check() { return array( 'ok' => true, 'message' => __( 'Manual entry is available.', 'legend-game-directory' ) ); }
}
