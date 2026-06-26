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

		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
	}

	/**
	 * Warn admins if Paid Memberships Pro (billing/levels) is not active.
	 * The plugin still loads — membership checks degrade gracefully — but billing
	 * and access tiers require PMP.
	 */
	public function dependency_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) { return; }
		if ( LCC_Roles::pmpro_active() ) { return; }
		echo '<div class="notice notice-warning"><p><strong>LegendCreate Community:</strong> '
			. esc_html__( 'Paid Memberships Pro is not active. Install and activate it to enable membership levels, checkout, and premium access.', 'legendcreate-community' )
			. '</p></div>';
	}

	public static function activate() {
		self::load_files();
		LCC_Activator::install();
	}

	public static function deactivate() {
		// Roles and data are intentionally preserved on deactivation.
		// Full teardown happens only on uninstall (uninstall.php), if added later.
	}
}
