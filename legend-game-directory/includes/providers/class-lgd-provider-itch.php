<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Provider_Itch implements LGD_Provider_Interface {
	private $cache = array();
	public function validate_configuration() {
		$settings = LGD_Security::settings();
		if ( empty( $settings['itch_enabled'] ) ) { return new WP_Error( 'lgd_itch_disabled', __( 'itch.io is disabled.', 'legend-game-directory' ) ); }
		$key = getenv( 'ITCH_IO_API_KEY' );
		if ( ! $key && defined( 'ITCH_IO_API_KEY' ) ) { $key = ITCH_IO_API_KEY; }
		return $key ? true : new WP_Error( 'lgd_itch_key_missing', __( 'ITCH_IO_API_KEY must be set outside WordPress options.', 'legend-game-directory' ) );
	}
	private function key() { $key = getenv( 'ITCH_IO_API_KEY' ); return $key ? $key : ( defined( 'ITCH_IO_API_KEY' ) ? ITCH_IO_API_KEY : '' ); }
	public function search_games( $query = '', $args = array() ) {
		unset( $args );
		$valid = $this->validate_configuration();
		if ( is_wp_error( $valid ) ) { return $valid; }
		$response = LGD_Security::safe_remote_get( 'https://api.itch.io/profile/games', array( 'api.itch.io' ), array( 'headers' => array( 'Authorization' => 'Bearer ' . $this->key() ) ) );
		if ( is_wp_error( $response ) ) { return $response; }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$games = array();
		foreach ( isset( $data['games'] ) ? (array) $data['games'] : array() as $item ) {
			$this->cache[ (string) $item['id'] ] = $item;
			if ( '' === $query || false !== stripos( isset( $item['title'] ) ? $item['title'] : '', $query ) ) { $games[] = $this->normalize_game( $item ); }
		}
		return array_values( array_filter( $games, function( $game ) { return ! is_wp_error( $game ); } ) );
	}
	public function get_game( $external_id ) {
		if ( isset( $this->cache[ (string) $external_id ] ) ) { return $this->normalize_game( $this->cache[ (string) $external_id ] ); }
		$games = $this->search_games();
		if ( is_wp_error( $games ) ) { return $games; }
		foreach ( $games as $game ) { if ( (string) $game['external_id'] === (string) $external_id ) { return $game; } }
		return new WP_Error( 'lgd_itch_missing', __( 'The game is not in the authenticated itch.io account.', 'legend-game-directory' ) );
	}
	public function normalize_game( $item ) {
		if ( empty( $item['id'] ) || empty( $item['title'] ) || empty( $item['url'] ) ) { return new WP_Error( 'lgd_itch_incomplete', __( 'itch.io result is incomplete.', 'legend-game-directory' ) ); }
		$classification = strtolower( isset( $item['classification'] ) ? $item['classification'] : 'game' );
		if ( 'game' !== $classification ) { return new WP_Error( 'lgd_itch_not_game', __( 'The itch.io item is not a game.', 'legend-game-directory' ) ); }
		return array(
			'external_id' => (string) absint( $item['id'] ), 'title' => sanitize_text_field( $item['title'] ), 'source_url' => esc_url_raw( $item['url'] ),
			'description' => sanitize_textarea_field( isset( $item['short_text'] ) ? $item['short_text'] : '' ), 'developer' => '', 'publisher' => '',
			'platforms' => array(), 'genres' => array(), 'is_free' => empty( $item['price'] ), 'free_type' => empty( $item['price'] ) ? 'Permanently Free' : '',
			'is_indie' => true, 'indie_confidence' => 80, 'is_mobile' => false, 'current_price' => isset( $item['price'] ) ? (float) $item['price'] / 100 : 0,
			'currency' => sanitize_text_field( isset( $item['currency'] ) ? $item['currency'] : '' ), 'screenshots' => array_filter( array( isset( $item['cover_url'] ) ? esc_url_raw( $item['cover_url'] ) : '' ) ),
			'confidence' => 75, 'retrieved_at' => current_time( 'mysql', true ), 'raw' => $item,
		);
	}
	public function get_source_name() { return 'itch.io authenticated account API'; }
	public function get_source_url() { return 'https://itch.io/docs/api/serverside'; }
	public function get_rate_limit() { return 30; }
	public function health_check() { $valid = $this->validate_configuration(); return is_wp_error( $valid ) ? $valid : array( 'ok' => true, 'message' => __( 'itch.io account API is configured.', 'legend-game-directory' ) ); }
}
