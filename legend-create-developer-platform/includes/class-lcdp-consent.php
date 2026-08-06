<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LCDP_Consent {
	const POLICY_VERSION = '1.0';

	// Consent types and their human labels
	public static function types() {
		return array(
			'account_creation'   => 'Create an account on Legend Create',
			'playtest_invites'   => 'Receive relevant playtest invitations',
			'marketing'          => 'Receive marketing and newsletters',
			'share_feedback'     => 'Share anonymised testing feedback with participating developers',
			'campaign_nda'       => 'Agree to campaign confidentiality terms',
			'recording'          => 'Allow gameplay recording for assigned campaigns',
		);
	}

	public function __construct() {
		add_action( 'wp_ajax_lcdp_withdraw_consent',        array( $this, 'ajax_withdraw' ) );
		add_action( 'wp_ajax_nopriv_lcdp_unsubscribe',      array( $this, 'ajax_unsubscribe' ) );
		add_action( 'wp_ajax_lcdp_data_export_request',     array( $this, 'ajax_data_export' ) );
		add_action( 'wp_ajax_lcdp_account_delete_request',  array( $this, 'ajax_delete_request' ) );
	}

	// Record consent granted
	public static function record( $user_id, $type, $granted = true, $source = 'form' ) {
		global $wpdb;
		$types  = self::types();
		$text   = isset( $types[ $type ] ) ? $types[ $type ] : $type;
		$wpdb->insert( LCDP_Database::table('consent_records'), array(
			'user_id'        => absint( $user_id ),
			'consent_type'   => sanitize_key( $type ),
			'consent_text'   => sanitize_text_field( $text ),
			'policy_version' => self::POLICY_VERSION,
			'source'         => sanitize_text_field( $source ),
			'ip_address'     => LCDP_Security::client_ip(),
			'granted'        => $granted ? 1 : 0,
			'granted_at'     => current_time('mysql'),
		), array( '%d','%s','%s','%s','%s','%s','%d','%s' ) );
	}

	// Withdraw a consent type
	public static function withdraw( $user_id, $type ) {
		global $wpdb;
		$wpdb->update(
			LCDP_Database::table('consent_records'),
			array( 'withdrawn_at' => current_time('mysql'), 'granted' => 0 ),
			array( 'user_id' => absint($user_id), 'consent_type' => sanitize_key($type), 'withdrawn_at' => null ),
			array( '%s', '%d' ),
			array( '%d', '%s', '%s' )
		);
		// Add to suppression list for marketing/playtest_invites
		if ( in_array( $type, array( 'marketing', 'playtest_invites' ), true ) ) {
			$email = get_userdata( $user_id )->user_email ?? '';
			if ( $email ) {
				self::suppress_email( $email, $type === 'marketing' ? 'marketing' : 'playtest' );
			}
		}
		LCDP_Security::audit( 'consent_withdrawn', "User {$user_id} withdrew consent: {$type}", 'user', $user_id );
	}

	public static function has_consent( $user_id, $type ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . LCDP_Database::table('consent_records') .
			" WHERE user_id=%d AND consent_type=%s AND granted=1 AND withdrawn_at IS NULL ORDER BY granted_at DESC LIMIT 1",
			$user_id, $type
		) );
	}

	public static function suppress_email( $email, $type = 'marketing', $reason = '', $source = 'opt_out' ) {
		global $wpdb;
		$wpdb->replace( LCDP_Database::table('suppression_list'), array(
			'email'            => sanitize_email( $email ),
			'suppression_type' => sanitize_key( $type ),
			'reason'           => sanitize_text_field( $reason ),
			'source'           => sanitize_text_field( $source ),
			'created_at'       => current_time('mysql'),
		), array( '%s','%s','%s','%s','%s' ) );
	}

	public static function is_suppressed( $email, $type = 'marketing' ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . LCDP_Database::table('suppression_list') . ' WHERE email=%s AND suppression_type=%s',
			sanitize_email( $email ), sanitize_key( $type )
		) );
	}

	// AJAX: withdraw a single consent
	public function ajax_withdraw() {
		LCDP_Security::ajax_check( 'lcdp_consent_nonce', 'read' );
		$user_id = get_current_user_id();
		$type    = sanitize_key( $_POST['consent_type'] ?? '' );
		if ( ! $type || ! isset( self::types()[ $type ] ) ) {
			wp_send_json_error( array( 'message' => 'Invalid consent type.' ) );
		}
		self::withdraw( $user_id, $type );
		wp_send_json_success( array( 'message' => 'Consent withdrawn.' ) );
	}

	// AJAX: unsubscribe via email link (no login required — uses token)
	public function ajax_unsubscribe() {
		$token = sanitize_text_field( $_GET['token'] ?? $_POST['token'] ?? '' );
		$email = sanitize_email( $_GET['email'] ?? $_POST['email'] ?? '' );
		if ( ! $email ) { wp_send_json_error( array( 'message' => 'Missing parameters.' ) ); }
		// Verify token (HMAC of email + secret)
		$expected = substr( hash_hmac( 'sha256', $email, wp_salt('auth') ), 0, 16 );
		if ( ! hash_equals( $expected, $token ) ) {
			wp_send_json_error( array( 'message' => 'Invalid unsubscribe link.' ) );
		}
		self::suppress_email( $email, 'marketing', 'unsubscribe_link', 'email_footer' );
		wp_send_json_success( array( 'message' => 'Unsubscribed from marketing emails.' ) );
	}

	// Generate an unsubscribe URL for email footers
	public static function unsubscribe_url( $email ) {
		$token = substr( hash_hmac( 'sha256', $email, wp_salt('auth') ), 0, 16 );
		return add_query_arg( array(
			'action' => 'lcdp_unsubscribe',
			'email'  => rawurlencode( $email ),
			'token'  => $token,
		), admin_url('admin-ajax.php') );
	}

	// AJAX: data export request (GDPR)
	public function ajax_data_export() {
		LCDP_Security::ajax_check( 'lcdp_privacy_nonce', 'read' );
		$user_id = get_current_user_id();
		update_user_meta( $user_id, '_lcdp_data_export_requested', current_time('mysql') );
		LCDP_Security::audit( 'data_export_requested', "User {$user_id} requested data export", 'user', $user_id );
		do_action( 'lcdp_data_export_requested', $user_id );
		wp_send_json_success( array( 'message' => 'Data export request received. We will email you within 30 days.' ) );
	}

	// AJAX: account deletion request
	public function ajax_delete_request() {
		LCDP_Security::ajax_check( 'lcdp_privacy_nonce', 'read' );
		$user_id = get_current_user_id();
		update_user_meta( $user_id, '_lcdp_deletion_requested', current_time('mysql') );
		LCDP_Security::audit( 'deletion_requested', "User {$user_id} requested account deletion", 'user', $user_id, 'warning' );
		do_action( 'lcdp_deletion_requested', $user_id );
		wp_send_json_success( array( 'message' => 'Deletion request received. We will action this within 30 days.' ) );
	}

	public static function get_user_consents( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT consent_type, granted, granted_at, withdrawn_at
			 FROM ' . LCDP_Database::table('consent_records') .
			' WHERE user_id=%d ORDER BY granted_at DESC',
			$user_id
		) );
	}
}
