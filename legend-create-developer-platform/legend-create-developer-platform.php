<?php
/**
 * Plugin Name: Legend Create Developer Platform
 * Plugin URI:  https://legendcreate.com/gamingsite
 * Description: Developer services, playtesting, expert reviews, tester management, token rewards and AI-assisted reporting for Legend Create.
 * Version:     1.0.0
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Author:      Legend Create
 * License:     GPL-2.0-or-later
 * Text Domain: legend-create-developer-platform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LCDP_VERSION', '1.0.0' );
define( 'LCDP_FILE',    __FILE__ );
define( 'LCDP_PATH',    plugin_dir_path( __FILE__ ) );
define( 'LCDP_URL',     plugin_dir_url( __FILE__ ) );
define( 'LCDP_SLUG',    'legend-create-developer-platform' );

require_once LCDP_PATH . 'includes/class-lcdp-plugin.php';

register_activation_hook( __FILE__,   array( 'LCDP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LCDP_Plugin', 'deactivate' ) );

LCDP_Plugin::instance()->boot();
