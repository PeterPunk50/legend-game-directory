<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Provider_Google_Play implements LGD_Provider_Interface {
	public function validate_configuration() { return new WP_Error( 'lgd_google_catalog_unavailable', __( 'The Google Play Developer API manages the authenticated publisher\'s own apps and is not enabled as a third-party catalog. Configure a separately licensed provider through the extension hook.', 'legend-game-directory' ) ); }
	public function search_games( $query = '', $args = array() ) { unset( $query, $args ); return $this->validate_configuration(); }
	public function get_game( $external_id ) { unset( $external_id ); return $this->validate_configuration(); }
	public function normalize_game( $data ) { unset( $data ); return $this->validate_configuration(); }
	public function get_source_name() { return 'Google Play provider structure'; }
	public function get_source_url() { return 'https://developers.google.com/android-publisher'; }
	public function get_rate_limit() { return 0; }
	public function health_check() { return $this->validate_configuration(); }
}
