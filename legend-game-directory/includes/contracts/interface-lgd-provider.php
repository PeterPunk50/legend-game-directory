<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface LGD_Provider_Interface {
	public function validate_configuration();
	public function search_games( $query = '', $args = array() );
	public function get_game( $external_id );
	public function normalize_game( $data );
	public function get_source_name();
	public function get_source_url();
	public function get_rate_limit();
	public function health_check();
}
