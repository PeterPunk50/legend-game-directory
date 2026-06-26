<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Core loader / bootstrapper for LegendCreate Community.
 */
final class LCC_Plugin {
	private static $instance;
	private $booted = false;

	public static function instance() {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	private static function files() {
		return array(
			'includes/class-lcc-activator.php',
			'includes/class-lcc-roles.php',
			'includes/class-lcc-memberships.php',
			'includes/class-lcc-profiles.php',
			'includes/class-lcc-members.php',
			'includes/class-lcc-registration.php',
			'includes/class-lcc-squads.php',
			'includes/class-lcc-reputation.php',
			'includes/class-lcc-referrals.php',
			'includes/class-lcc-landing.php',
		);
	}

	private static function load_files() {
		foreach ( self::files() as $file ) { require_once LCC_PATH . $file; }
	}

	public function boot() {
		if ( $this->booted ) { return; }
		$this->booted = true;
		self::load_files();

		new LCC_Roles();
		new LCC_Memberships();
		new LCC_Profiles();
		new LCC_Members();
		new LCC_Registration();
		new LCC_Squads();
		new LCC_Reputation();
		new LCC_Referrals();
		new LCC_Landing();

		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
	}

	/**
	 * Warn admins if WooCommerce (checkout/orders for Fygaro payments) is not active.
	 * The plugin still loads — membership state degrades gracefully — but selling
	 * Premium requires WooCommerce plus the Fygaro gateway.
	 */
	public function dependency_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) { return; }
		if ( LCC_Roles::woocommerce_active() ) { return; }
		echo '<div class="notice notice-warning"><p><strong>LegendCreate Community:</strong> '
			. esc_html__( 'WooCommerce is not active. Install WooCommerce and the Fygaro payment gateway to sell Premium memberships.', 'legendcreate-community' )
			. '</p></div>';
	}

	public static function activate() {
		self::load_files();
		LCC_Activator::install();
		LCC_Squads::register_cpt();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		self::load_files();
		LCC_Memberships::unschedule();
		flush_rewrite_rules();
		// Roles and data are intentionally preserved on deactivation.
		// Full teardown happens only on uninstall (uninstall.php), if added later.
	}
}
