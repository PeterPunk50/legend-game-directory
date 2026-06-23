<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Provider_Apple implements LGD_Provider_Interface {
	public function validate_configuration() {
		$settings = LGD_Security::settings();
		return empty( $settings['apple_enabled'] ) ? new WP_Error( 'lgd_apple_disabled', __( 'Apple Search is disabled.', 'legend-game-directory' ) ) : true;
	}

	public function search_games( $query = '', $args = array() ) {
		$valid = $this->validate_configuration();
		if ( is_wp_error( $valid ) ) { return $valid; }
		$query = sanitize_text_field( $query );
		if ( '' === $query ) { return array(); }
		$limit = min( 25, max( 1, isset( $args['limit'] ) ? absint( $args['limit'] ) : 10 ) );
		$country = ! empty( $args['country'] ) ? strtoupper( substr( sanitize_text_field( $args['country'] ), 0, 2 ) ) : 'US';
		$url = add_query_arg( array( 'term' => $query, 'country' => $country, 'media' => 'software', 'entity' => 'software', 'limit' => $limit ), 'https://itunes.apple.com/search' );
		$response = LGD_Security::safe_remote_get( $url, array( 'itunes.apple.com', 'apple.com' ) );
		if ( is_wp_error( $response ) ) { return $response; }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$games = array();
		foreach ( isset( $data['results'] ) ? (array) $data['results'] : array() as $item ) {
			$normalized = $this->normalize_game( $item );
			if ( ! is_wp_error( $normalized ) ) { $games[] = $normalized; }
		}
		return $games;
	}

	public function get_game( $external_id ) {
		$app_id = preg_replace( '/\D+/', '', (string) $external_id );
		if ( ! $app_id ) { return new WP_Error( 'lgd_invalid_apple_id', __( 'Invalid Apple application ID.', 'legend-game-directory' ) ); }
		$url = add_query_arg( array( 'id' => $app_id, 'entity' => 'software' ), 'https://itunes.apple.com/lookup' );
		$response = LGD_Security::safe_remote_get( $url, array( 'itunes.apple.com', 'apple.com' ) );
		if ( is_wp_error( $response ) ) { return $response; }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return empty( $data['results'][0] ) ? new WP_Error( 'lgd_apple_missing', __( 'Apple returned no application data.', 'legend-game-directory' ) ) : $this->normalize_game( $data['results'][0] );
	}

	public function normalize_game( $item ) {
		if ( empty( $item['trackId'] ) || empty( $item['trackName'] ) || empty( $item['trackViewUrl'] ) ) { return new WP_Error( 'lgd_apple_incomplete', __( 'Apple result is incomplete.', 'legend-game-directory' ) ); }
		$genres = array_filter( array_merge( isset( $item['genres'] ) ? (array) $item['genres'] : array(), isset( $item['primaryGenreName'] ) ? array( $item['primaryGenreName'] ) : array() ) );
		$is_game = false;
		foreach ( $genres as $genre ) { if ( false !== stripos( $genre, 'game' ) ) { $is_game = true; break; } }
		if ( ! $is_game ) { return new WP_Error( 'lgd_apple_not_game', __( 'The Apple result is not categorized as a game.', 'legend-game-directory' ) ); }
		$price = isset( $item['price'] ) ? (float) $item['price'] : null;
		$screens = array_merge( isset( $item['screenshotUrls'] ) ? (array) $item['screenshotUrls'] : array(), isset( $item['ipadScreenshotUrls'] ) ? (array) $item['ipadScreenshotUrls'] : array() );
		return array(
			'external_id' => (string) absint( $item['trackId'] ), 'title' => sanitize_text_field( $item['trackName'] ),
			'source_url' => esc_url_raw( $item['trackViewUrl'] ), 'description' => sanitize_textarea_field( isset( $item['description'] ) ? $item['description'] : '' ),
			'developer' => sanitize_text_field( isset( $item['sellerName'] ) ? $item['sellerName'] : ( isset( $item['artistName'] ) ? $item['artistName'] : '' ) ),
			'publisher' => sanitize_text_field( isset( $item['artistName'] ) ? $item['artistName'] : '' ),
			'release_date' => sanitize_text_field( isset( $item['releaseDate'] ) ? $item['releaseDate'] : '' ),
			'last_update_date' => sanitize_text_field( isset( $item['currentVersionReleaseDate'] ) ? $item['currentVersionReleaseDate'] : '' ),
			'platforms' => array( 'iOS' ), 'genres' => LGD_Security::sanitize_string_list( $genres ), 'is_free' => 0.0 === $price,
			'free_type' => 0.0 === $price ? 'Freemium' : '', 'is_indie' => false, 'indie_confidence' => 0, 'is_mobile' => true,
			'current_price' => $price, 'original_price' => $price, 'currency' => sanitize_text_field( isset( $item['currency'] ) ? $item['currency'] : '' ),
			'ios_app_id' => (string) absint( $item['trackId'] ), 'age_rating' => sanitize_text_field( isset( $item['contentAdvisoryRating'] ) ? $item['contentAdvisoryRating'] : '' ),
			'supported_languages' => LGD_Security::sanitize_string_list( isset( $item['languageCodesISO2A'] ) ? $item['languageCodesISO2A'] : array() ),
			'screenshots' => array_values( array_filter( array_map( 'esc_url_raw', array_slice( $screens, 0, 12 ) ) ) ),
			'confidence' => 85, 'retrieved_at' => current_time( 'mysql', true ), 'raw' => $item,
		);
	}

	public function get_source_name() { return 'Apple Search API'; }
	public function get_source_url() { return 'https://developer.apple.com/library/archive/documentation/AudioVideo/Conceptual/iTuneSearchAPI/'; }
	public function get_rate_limit() { return 20; }
	public function health_check() { $valid = $this->validate_configuration(); return is_wp_error( $valid ) ? $valid : array( 'ok' => true, 'message' => __( 'Apple Search API is enabled. Promotional assets must remain linked to the App Store.', 'legend-game-directory' ) ); }
}
