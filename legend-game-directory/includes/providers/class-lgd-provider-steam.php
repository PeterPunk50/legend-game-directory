<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Provider_Steam implements LGD_Provider_Interface {
	public function validate_configuration() {
		$settings = LGD_Security::settings();
		if ( empty( $settings['steam_enabled'] ) ) { return new WP_Error( 'lgd_steam_disabled', __( 'Steam is disabled.', 'legend-game-directory' ) ); }
		if ( empty( $settings['steam_terms_accepted'] ) ) { return new WP_Error( 'lgd_steam_terms', __( 'An administrator must record acceptance of the Steam API terms for this application.', 'legend-game-directory' ) ); }
		return true;
	}

	public function search_games( $query = '', $args = array() ) {
		unset( $query );
		$valid = $this->validate_configuration();
		if ( is_wp_error( $valid ) ) { return $valid; }
		$app_ids = isset( $args['app_ids'] ) ? array_slice( array_unique( array_map( 'absint', (array) $args['app_ids'] ) ), 0, 50 ) : array();
		if ( empty( $app_ids ) ) { return new WP_Error( 'lgd_steam_ids_required', __( 'Steam discovery requires administrator-approved App IDs; global Store scraping is intentionally unsupported.', 'legend-game-directory' ) ); }
		$games = array();
		foreach ( $app_ids as $app_id ) { $game = $this->get_game( $app_id ); if ( ! is_wp_error( $game ) ) { $games[] = $game; } }
		return $games;
	}

	public function get_game( $external_id ) {
		$valid = $this->validate_configuration();
		if ( is_wp_error( $valid ) ) { return $valid; }
		$app_id = absint( $external_id );
		if ( ! $app_id ) { return new WP_Error( 'lgd_invalid_steam_id', __( 'Invalid Steam App ID.', 'legend-game-directory' ) ); }
		$url = add_query_arg( array( 'appids' => $app_id, 'l' => 'english', 'cc' => 'US' ), 'https://store.steampowered.com/api/appdetails' );
		$response = LGD_Security::safe_remote_get( $url, array( 'steampowered.com' ) );
		if ( is_wp_error( $response ) ) { return $response; }
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $decoded[ $app_id ]['success'] ) || empty( $decoded[ $app_id ]['data'] ) ) { return new WP_Error( 'lgd_steam_missing', __( 'Steam returned no game data.', 'legend-game-directory' ) ); }
		$data = $decoded[ $app_id ]['data'];
		$reviews_url = add_query_arg( array( 'json' => 1, 'language' => 'all', 'purchase_type' => 'all', 'num_per_page' => 0 ), 'https://store.steampowered.com/appreviews/' . $app_id );
		$reviews = LGD_Security::safe_remote_get( $reviews_url, array( 'steampowered.com' ) );
		if ( ! is_wp_error( $reviews ) ) {
			$review_data = json_decode( wp_remote_retrieve_body( $reviews ), true );
			if ( ! empty( $review_data['query_summary'] ) ) { $data['_lgd_review_summary'] = $review_data['query_summary']; }
		}
		$data['_lgd_app_id'] = $app_id;
		return $this->normalize_game( $data );
	}

	public function normalize_game( $data ) {
		$app_id = absint( isset( $data['_lgd_app_id'] ) ? $data['_lgd_app_id'] : 0 );
		$genres = array();
		foreach ( isset( $data['genres'] ) ? (array) $data['genres'] : array() as $genre ) { if ( ! empty( $genre['description'] ) ) { $genres[] = sanitize_text_field( $genre['description'] ); } }
		$platforms = array();
		foreach ( array( 'windows' => 'Windows', 'mac' => 'macOS', 'linux' => 'Linux' ) as $key => $label ) { if ( ! empty( $data['platforms'][ $key ] ) ) { $platforms[] = $label; } }
		$is_indie = in_array( 'Indie', $genres, true );
		$price = isset( $data['price_overview']['final'] ) ? (float) $data['price_overview']['final'] / 100 : ( ! empty( $data['is_free'] ) ? 0 : null );
		$original = isset( $data['price_overview']['initial'] ) ? (float) $data['price_overview']['initial'] / 100 : $price;
		$summary = isset( $data['_lgd_review_summary'] ) ? $data['_lgd_review_summary'] : array();
		$screens = array();
		foreach ( array_slice( isset( $data['screenshots'] ) ? (array) $data['screenshots'] : array(), 0, 12 ) as $screen ) { if ( ! empty( $screen['path_full'] ) ) { $screens[] = esc_url_raw( $screen['path_full'] ); } }
		return array(
			'external_id' => (string) $app_id, 'title' => sanitize_text_field( $data['name'] ), 'source_url' => 'https://store.steampowered.com/app/' . $app_id . '/',
			'description' => wp_strip_all_tags( isset( $data['short_description'] ) ? $data['short_description'] : '' ),
			'developer' => sanitize_text_field( implode( ', ', isset( $data['developers'] ) ? (array) $data['developers'] : array() ) ),
			'publisher' => sanitize_text_field( implode( ', ', isset( $data['publishers'] ) ? (array) $data['publishers'] : array() ) ),
			'release_date' => sanitize_text_field( isset( $data['release_date']['date'] ) ? $data['release_date']['date'] : '' ),
			'platforms' => $platforms, 'genres' => $genres, 'is_free' => ! empty( $data['is_free'] ),
			'free_type' => ! empty( $data['is_free'] ) ? 'Free to Play' : '', 'is_indie' => $is_indie, 'indie_confidence' => $is_indie ? 75 : 0,
			'is_mobile' => false, 'current_price' => $price, 'original_price' => $original,
			'currency' => sanitize_text_field( isset( $data['price_overview']['currency'] ) ? $data['price_overview']['currency'] : '' ),
			'steam_sentiment' => sanitize_text_field( isset( $summary['review_score_desc'] ) ? $summary['review_score_desc'] : '' ),
			'steam_review_count' => absint( isset( $summary['total_reviews'] ) ? $summary['total_reviews'] : 0 ),
			'screenshots' => $screens, 'requirements' => isset( $data['pc_requirements'] ) && is_array( $data['pc_requirements'] ) ? map_deep( $data['pc_requirements'], 'wp_strip_all_tags' ) : array(),
			'controller_support' => sanitize_text_field( isset( $data['controller_support'] ) ? $data['controller_support'] : '' ),
			'supported_languages' => LGD_Security::sanitize_string_list( wp_strip_all_tags( isset( $data['supported_languages'] ) ? str_replace( '<br>', ',', $data['supported_languages'] ) : '' ) ),
			'confidence' => 80, 'retrieved_at' => current_time( 'mysql', true ), 'raw' => $data,
		);
	}

	public function get_source_name() { return 'Steam'; }
	public function get_source_url() { return 'https://store.steampowered.com/'; }
	public function get_rate_limit() { return 60; }
	public function health_check() { $valid = $this->validate_configuration(); return is_wp_error( $valid ) ? $valid : array( 'ok' => true, 'message' => __( 'Steam is configured for approved App IDs.', 'legend-game-directory' ) ); }
}
