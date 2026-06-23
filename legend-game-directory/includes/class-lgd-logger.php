<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Logger {
	public static function log( $event, $message, $context = array(), $level = 'info', $object_type = '', $object_id = 0 ) {
		global $wpdb;
		$wpdb->insert(
			LGD_Database::table( 'audit_log' ),
			array(
				'event' => sanitize_key( $event ), 'object_type' => sanitize_key( $object_type ),
				'object_id' => absint( $object_id ), 'user_id' => get_current_user_id(),
				'level' => in_array( $level, array( 'debug', 'info', 'warning', 'error' ), true ) ? $level : 'info',
				'message' => sanitize_textarea_field( $message ), 'context' => wp_json_encode( self::redact( $context ) ),
				'created_at' => current_time( 'mysql', true ),
			), array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	private static function redact( $value, $key = '' ) {
		if ( preg_match( '/key|secret|token|password|authorization/i', (string) $key ) ) { return '<redacted>'; }
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $child_key => $child_value ) { $out[ $child_key ] = self::redact( $child_value, $child_key ); }
			return $out;
		}
		if ( is_object( $value ) ) { return self::redact( (array) $value ); }
		return is_scalar( $value ) || null === $value ? $value : '<unsupported>';
	}
}
