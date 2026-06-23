<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Provider_Registry {
	private static $providers = array();

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_defaults' ), 30 );
	}

	public static function register_defaults() {
		if ( ! empty( self::$providers ) ) { return; }
		self::register( 'manual', new LGD_Provider_Manual() );
		self::register( 'steam', new LGD_Provider_Steam() );
		self::register( 'apple', new LGD_Provider_Apple() );
		self::register( 'google_play', new LGD_Provider_Google_Play() );
		self::register( 'itch', new LGD_Provider_Itch() );
		self::register( 'external_score', new LGD_Provider_External_Score() );
		self::register( 'official_site', new LGD_Provider_Official_Site() );
		do_action( 'lgd_register_providers' );
	}

	public static function register( $id, LGD_Provider_Interface $provider ) { self::$providers[ sanitize_key( $id ) ] = $provider; }
	public static function get( $id ) { self::register_defaults(); return isset( self::$providers[ $id ] ) ? self::$providers[ $id ] : null; }
	public static function all() { self::register_defaults(); return self::$providers; }

	public static function health() {
		$result = array();
		foreach ( self::all() as $id => $provider ) {
			$check = $provider->health_check();
			$result[ $id ] = is_wp_error( $check ) ? array( 'ok' => false, 'message' => $check->get_error_message() ) : $check;
		}
		return $result;
	}
}
