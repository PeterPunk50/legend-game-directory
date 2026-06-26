<?php
/**
 * Plugin Name: LegendCreate Community
 * Plugin URI: https://legendcreate.com/gamingsite
 * Description: Members area, squads, game testing, reputation, and referrals for the Legend Create gaming community. Extends the Legend Game Directory and uses Paid Memberships Pro for billing.
 * Version: 0.9.0
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Author: Legend Create
 * License: GPL-2.0-or-later
 * Text Domain: legendcreate-community
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LCC_VERSION', '0.9.0' );
define( 'LCC_FILE', __FILE__ );
define( 'LCC_PATH', plugin_dir_path( __FILE__ ) );
define( 'LCC_URL', plugin_dir_url( __FILE__ ) );

require_once LCC_PATH . 'includes/class-lcc-plugin.php';

register_activation_hook( __FILE__, array( 'LCC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LCC_Plugin', 'deactivate' ) );

LCC_Plugin::instance()->boot();
