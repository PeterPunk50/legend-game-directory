<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LCDP_Security {

	// Verify nonce + capability. Dies on failure.
	public static function check( $nonce_value, $nonce_action, $capability = 'read' ) {
		if ( ! check_ajax_referer( $nonce_action, false, false ) &&
		     ! wp_verify_nonce( $nonce_value, $nonce_action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'legend-create-developer-platform' ), 403 );
		}
		if ( $capability && ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'legend-create-developer-platform' ), 403 );
		}
	}

	// JSON AJAX helper: verify nonce, return error JSON on failure
	public static function ajax_check( $nonce_action, $capability = 'read' ) {
		if ( ! check_ajax_referer( $nonce_action, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 403 );
		}
		if ( $capability && ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
	}

	// Rate limit: max $limit calls per $window seconds for a given key+user
	public static function rate_limit( $key, $limit = 10, $window = 3600 ) {
		$user_id  = get_current_user_id();
		$trans_key = 'lcdp_rl_' . md5( $key . '_' . $user_id );
		$count = (int) get_transient( $trans_key );
		if ( $count >= $limit ) { return false; }
		set_transient( $trans_key, $count + 1, $window );
		return true;
	}

	// Sanitize a form field array
	public static function sanitize_form( $data, $rules ) {
		$clean = array();
		foreach ( $rules as $field => $type ) {
			$raw = isset( $data[ $field ] ) ? $data[ $field ] : '';
			switch ( $type ) {
				case 'text':    $clean[ $field ] = sanitize_text_field( $raw ); break;
				case 'textarea':$clean[ $field ] = sanitize_textarea_field( $raw ); break;
				case 'email':   $clean[ $field ] = sanitize_email( $raw ); break;
				case 'url':     $clean[ $field ] = esc_url_raw( $raw ); break;
				case 'int':     $clean[ $field ] = (int) $raw; break;
				case 'float':   $clean[ $field ] = (float) $raw; break;
				case 'bool':    $clean[ $field ] = (bool) $raw; break;
				case 'slug':    $clean[ $field ] = sanitize_title( $raw ); break;
				case 'array':
					$clean[ $field ] = is_array( $raw )
						? array_map( 'sanitize_text_field', $raw )
						: array();
					break;
				default:        $clean[ $field ] = sanitize_text_field( $raw );
			}
		}
		return $clean;
	}

	// Generate a cryptographically secure download token
	public static function generate_token( $length = 32 ) {
		return bin2hex( random_bytes( $length ) );
	}

	// Verify a signed download token stored in report meta
	public static function verify_download_token( $report_id, $token ) {
		global $wpdb;
		$stored = $wpdb->get_var( $wpdb->prepare(
			'SELECT download_token FROM ' . LCDP_Database::table('developer_reports') . ' WHERE id=%d',
			$report_id
		) );
		return $stored && hash_equals( $stored, $token );
	}

	// Log an audit event
	public static function audit( $event, $message, $object_type = '', $object_id = 0, $level = 'info' ) {
		global $wpdb;
		$wpdb->insert( LCDP_Database::table('audit_log'), array(
			'event'       => sanitize_key( $event ),
			'object_type' => sanitize_key( $object_type ),
			'object_id'   => absint( $object_id ),
			'user_id'     => get_current_user_id(),
			'level'       => sanitize_key( $level ),
			'message'     => sanitize_textarea_field( $message ),
			'ip_address'  => self::client_ip(),
			'created_at'  => current_time( 'mysql' ),
		), array( '%s','%s','%d','%d','%s','%s','%s','%s' ) );
	}

	public static function client_ip() {
		$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $keys as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				return sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) );
			}
		}
		return '';
	}
}
