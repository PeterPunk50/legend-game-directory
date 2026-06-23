<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Security {
	const MAX_RESPONSE_BYTES = 1048576;

	public static function settings() {
		return wp_parse_args( get_option( 'lgd_settings', array() ), LGD_Database::defaults() );
	}

	public static function host_allowed( $host, $allowlist = null ) {
		$host = strtolower( trim( (string) $host, '.' ) );
		if ( '' === $host || 'localhost' === $host ) { return false; }
		$settings = self::settings();
		foreach ( (array) $settings['blocked_domains'] as $domain ) {
			$domain = strtolower( trim( $domain, ". \t\n\r\0\x0B" ) );
			if ( $domain && ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) ) { return false; }
		}
		$allowlist = null === $allowlist ? (array) $settings['approved_domains'] : (array) $allowlist;
		foreach ( $allowlist as $domain ) {
			$domain = strtolower( trim( $domain, ". \t\n\r\0\x0B" ) );
			if ( $domain && ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) ) { return true; }
		}
		return false;
	}

	public static function validate_public_url( $url, $allowlist = null ) {
		$url = esc_url_raw( $url, array( 'https' ) );
		if ( ! $url || ! wp_http_validate_url( $url ) ) { return new WP_Error( 'lgd_invalid_url', __( 'The source URL is invalid or unsafe.', 'legend-game-directory' ) ); }
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! self::host_allowed( $host, $allowlist ) ) { return new WP_Error( 'lgd_domain_not_allowed', __( 'The source domain is not approved.', 'legend-game-directory' ) ); }
		$ips = gethostbynamel( $host );
		if ( false === $ips || empty( $ips ) ) { return new WP_Error( 'lgd_dns_failed', __( 'The source host could not be resolved.', 'legend-game-directory' ) ); }
		foreach ( $ips as $ip ) {
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return new WP_Error( 'lgd_private_ip', __( 'Private and reserved network addresses are blocked.', 'legend-game-directory' ) );
			}
		}
		return $url;
	}

	public static function safe_remote_get( $url, $allowlist = null, $args = array() ) {
		$valid = self::validate_public_url( $url, $allowlist );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$defaults = array(
			'timeout' => 15, 'redirection' => 2, 'reject_unsafe_urls' => true,
			'limit_response_size' => self::MAX_RESPONSE_BYTES,
			'headers' => array( 'User-Agent' => 'LegendGameDirectory/' . LGD_VERSION . '; ' . home_url( '/' ) ),
		);
		$response = wp_safe_remote_get( $valid, array_replace_recursive( $defaults, $args ) );
		if ( is_wp_error( $response ) ) { return $response; }
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) { return new WP_Error( 'lgd_remote_status', sprintf( __( 'The source returned HTTP %d.', 'legend-game-directory' ), $code ) ); }
		if ( strlen( wp_remote_retrieve_body( $response ) ) >= self::MAX_RESPONSE_BYTES ) { return new WP_Error( 'lgd_response_too_large', __( 'The source response exceeded the safety limit.', 'legend-game-directory' ) ); }
		return $response;
	}

	public static function rate_limit( $bucket, $limit, $window ) {
		$key = 'lgd_rl_' . md5( (string) $bucket );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) { return false; }
		set_transient( $key, $count + 1, $window );
		return true;
	}

	public static function sanitize_string_list( $value ) {
		$value = is_array( $value ) ? $value : preg_split( '/[,\r\n]+/', (string) $value );
		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $value ) ) ) );
	}
}
