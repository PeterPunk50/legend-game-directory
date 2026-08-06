<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LCDP_Plugin {
	private static $instance;
	private $booted = false;

	public static function instance() {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	private static function files() {
		return array(
			'includes/class-lcdp-database.php',
			'includes/class-lcdp-security.php',
			'includes/class-lcdp-post-types.php',
			'includes/class-lcdp-roles.php',
			'includes/class-lcdp-tokens.php',
			'includes/class-lcdp-ratings.php',
			'includes/class-lcdp-consent.php',
			'includes/class-lcdp-developer.php',
			'includes/class-lcdp-tester.php',
			'includes/class-lcdp-campaign.php',
			'includes/class-lcdp-submissions.php',
			'includes/class-lcdp-woocommerce.php',
			'includes/class-lcdp-email.php',
			'includes/class-lcdp-admin.php',
			'includes/class-lcdp-frontend.php',
			'includes/class-lcdp-ajax.php',
		);
	}

	private static function load_files() {
		foreach ( self::files() as $f ) { require_once LCDP_PATH . $f; }
	}

	public function boot() {
		if ( $this->booted ) { return; }
		$this->booted = true;
		self::load_files();

		new LCDP_Post_Types();
		new LCDP_Tokens();
		new LCDP_Ratings();
		new LCDP_Consent();
		new LCDP_Developer();
		new LCDP_Tester();
		new LCDP_Campaign();
		new LCDP_Submissions();
		new LCDP_WooCommerce();
		new LCDP_Email();
		new LCDP_Admin();
		new LCDP_Frontend();
		new LCDP_Ajax();

		add_action( 'admin_init', array( 'LCDP_Database', 'maybe_upgrade' ) );
		add_action( 'init',       array( $this, 'load_textdomain' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( LCDP_SLUG, false, dirname( plugin_basename( LCDP_FILE ) ) . '/languages' );
	}

	public static function activate() {
		self::load_files();
		LCDP_Database::install();
		LCDP_Post_Types::register_all();
		LCDP_Roles::install();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}
}
