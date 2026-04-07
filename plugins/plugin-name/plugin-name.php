<?php
/**
 * Plugin Name:       Plugin Name
 * Plugin URI:        https://github.com/your-org/plugin-name
 * Description:       A WordPress plugin.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Your Name
 * Author URI:        https://github.com/your-org
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       plugin-name
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PLUGIN_NAME_VERSION', '0.1.0' );
define( 'PLUGIN_NAME_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLUGIN_NAME_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load plugin textdomain for translations.
 */
function plugin_name_load_textdomain(): void {
	load_plugin_textdomain( 'plugin-name', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'plugin_name_load_textdomain' );
