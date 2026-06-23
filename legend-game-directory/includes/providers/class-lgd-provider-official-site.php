<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Provider_Official_Site implements LGD_Provider_Interface {
	public function validate_configuration() {
		$settings = LGD_Security::settings();
		return empty( $settings['official_site_enabled'] ) ? new WP_Error( 'lgd_official_disabled', __( 'Official-site metadata is disabled.', 'legend-game-directory' ) ) : true;
	}
	public function search_games( $query = '', $args = array() ) {
		unset( $query );
		$urls = isset( $args['urls'] ) ? array_slice( (array) $args['urls'], 0, 25 ) : array();
		$games = array();
		foreach ( $urls as $url ) { $game = $this->get_game( $url ); if ( ! is_wp_error( $game ) ) { $games[] = $game; } }
		return $games;
	}
	public function get_game( $external_id ) {
		$valid = $this->validate_configuration();
		if ( is_wp_error( $valid ) ) { return $valid; }
		$url = esc_url_raw( $external_id );
		$response = LGD_Security::safe_remote_get( $url );
		if ( is_wp_error( $response ) ) { return $response; }
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( false === stripos( $content_type, 'text/html' ) ) { return new WP_Error( 'lgd_official_mime', __( 'Official-site provider accepts HTML structured metadata only.', 'legend-game-directory' ) ); }
		$html = wp_remote_retrieve_body( $response );
		preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches );
		foreach ( isset( $matches[1] ) ? $matches[1] : array() as $json ) {
			$decoded = json_decode( html_entity_decode( $json, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
			$nodes = isset( $decoded['@graph'] ) ? $decoded['@graph'] : array( $decoded );
			foreach ( (array) $nodes as $node ) {
				$type = isset( $node['@type'] ) ? (array) $node['@type'] : array();
				if ( array_intersect( array( 'VideoGame', 'SoftwareApplication', 'MobileApplication', 'Game' ), $type ) ) { $node['_lgd_source_url'] = $url; return $this->normalize_game( $node ); }
			}
		}
		return new WP_Error( 'lgd_official_no_schema', __( 'No supported public game structured metadata was found.', 'legend-game-directory' ) );
	}
	public function normalize_game( $data ) {
		if ( empty( $data['name'] ) || empty( $data['_lgd_source_url'] ) ) { return new WP_Error( 'lgd_official_incomplete', __( 'Structured metadata is incomplete.', 'legend-game-directory' ) ); }
		$author = isset( $data['author']['name'] ) ? $data['author']['name'] : ( isset( $data['publisher']['name'] ) ? $data['publisher']['name'] : '' );
		$systems = isset( $data['operatingSystem'] ) ? LGD_Security::sanitize_string_list( $data['operatingSystem'] ) : array();
		$is_mobile = (bool) preg_grep( '/android|ios/i', $systems );
		$price = isset( $data['offers']['price'] ) && is_numeric( $data['offers']['price'] ) ? (float) $data['offers']['price'] : null;
		return array(
			'external_id' => hash( 'sha256', $data['_lgd_source_url'] ), 'title' => sanitize_text_field( $data['name'] ), 'source_url' => esc_url_raw( $data['_lgd_source_url'] ),
			'description' => sanitize_textarea_field( isset( $data['description'] ) ? $data['description'] : '' ), 'developer' => sanitize_text_field( $author ),
			'publisher' => sanitize_text_field( isset( $data['publisher']['name'] ) ? $data['publisher']['name'] : '' ),
			'release_date' => sanitize_text_field( isset( $data['datePublished'] ) ? $data['datePublished'] : '' ), 'platforms' => $systems,
			'genres' => isset( $data['genre'] ) ? LGD_Security::sanitize_string_list( $data['genre'] ) : array(), 'is_free' => 0.0 === $price,
			'free_type' => 0.0 === $price ? 'Permanently Free' : '', 'is_indie' => false, 'indie_confidence' => 0, 'is_mobile' => $is_mobile,
			'current_price' => $price, 'currency' => sanitize_text_field( isset( $data['offers']['priceCurrency'] ) ? $data['offers']['priceCurrency'] : '' ),
			'age_rating' => sanitize_text_field( isset( $data['contentRating'] ) ? $data['contentRating'] : '' ), 'confidence' => 65,
			'retrieved_at' => current_time( 'mysql', true ), 'raw' => $data,
		);
	}
	public function get_source_name() { return 'Approved official website structured metadata'; }
	public function get_source_url() { return ''; }
	public function get_rate_limit() { return 20; }
	public function health_check() { $valid = $this->validate_configuration(); return is_wp_error( $valid ) ? $valid : array( 'ok' => true, 'message' => __( 'Official-site structured metadata is enabled for approved domains.', 'legend-game-directory' ) ); }
}
