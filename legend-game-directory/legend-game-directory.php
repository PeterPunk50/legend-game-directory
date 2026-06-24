<?php
/**
 * Plugin Name: Legend Game Directory
 * Plugin URI: https://legendcreate.com/gamingsite
 * Description: Automated discovery, transparent ratings, comparisons, and moderated reviews for free, indie, and mobile games.
 * Version: 0.1.15
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Author: Legend Create
 * License: GPL-2.0-or-later
 * Text Domain: legend-game-directory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LGD_VERSION', '0.1.15' );
define( 'LGD_FILE', __FILE__ );
define( 'LGD_PATH', plugin_dir_path( __FILE__ ) );
define( 'LGD_URL', plugin_dir_url( __FILE__ ) );

require_once LGD_PATH . 'includes/class-lgd-plugin.php';

register_activation_hook( __FILE__, array( 'LGD_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LGD_Plugin', 'deactivate' ) );

LGD_Plugin::instance()->boot();
