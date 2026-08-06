<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LCDP_Ajax {

	public function __construct() {
		// Token redemption
		add_action( 'wp_ajax_lcdp_redeem_membership',  array( $this, 'redeem_membership' ) );
		// Developer registration
		add_action( 'wp_ajax_lcdp_developer_register', array( $this, 'developer_register' ) );
		// Wallet
		add_action( 'wp_ajax_lcdp_get_wallet',         array( $this, 'get_wallet' ) );
		// Privacy / data export
		add_action( 'wp_ajax_lcdp_export_data',        array( $this, 'export_data' ) );
		// Note: feedback/bug/campaign AJAX handlers are registered in their own class constructors
	}

	public function redeem_membership() {
		LCDP_Security::ajax_check('lcdp_frontend_nonce','read');
		$user_id = get_current_user_id();
		$result  = LCDP_Tokens::redeem_membership($user_id);
		if ($result) {
			wp_send_json_success(array('message' => 'Congratulations! Your 6-month Developer Starter membership is now active.'));
		} else {
			wp_send_json_error(array('message' => 'You do not have enough tokens to redeem. Earn 5 tokens to unlock 6 months free.'));
		}
	}

	public function developer_register() {
		LCDP_Security::ajax_check('lcdp_developer_nonce','read');
		if (!is_user_logged_in()) {
			wp_send_json_error(array('message' => 'You must be logged in to register as a developer.'));
		}
		$user_id = get_current_user_id();
		$data    = LCDP_Security::sanitize_form($_POST, array(
			'studio_name'    => 'text',
			'studio_website' => 'url',
			'bio'            => 'textarea',
		));
		LCDP_Developer::create_or_update_profile($user_id, $data);
		LCDP_Tokens::award_points($user_id,'register_verify',0,'user',$user_id);
		LCDP_Consent::record($user_id,'account_creation',true,'developer_register_form');
		if (!empty($_POST['consent_marketing'])) {
			LCDP_Consent::record($user_id,'marketing',true,'developer_register_form');
		}
		wp_send_json_success(array('message' => 'Developer profile created. You can now submit your game.'));
	}

	public function get_wallet() {
		LCDP_Security::ajax_check('lcdp_frontend_nonce','read');
		$wallet = LCDP_Tokens::get_wallet(get_current_user_id());
		wp_send_json_success($wallet);
	}

	public function export_data() {
		LCDP_Security::ajax_check('lcdp_privacy_nonce','read');
		$user_id = get_current_user_id();
		global $wpdb;
		$export = array(
			'developer_profile' => LCDP_Developer::get_profile($user_id),
			'tester_profile'    => LCDP_Tester::get_profile($user_id),
			'consent_records'   => LCDP_Consent::get_user_consents($user_id),
			'token_history'     => LCDP_Tokens::get_history($user_id, 200),
		);
		wp_send_json_success($export);
	}
}
