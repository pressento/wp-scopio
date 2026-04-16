<?php
/**
 * Plugin Name:       Scopio for WordPress
 * Plugin URI:        https://github.com/pressento/wp-scopio
 * Description:       CIDR group-based content visibility for WordPress.
 * Version:           0.5.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Pressento
 * Author URI:        https://github.com/pressento
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-scopio
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCOPIO_VERSION', '0.5.0' );
define( 'SCOPIO_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCOPIO_URL', plugin_dir_url( __FILE__ ) );

// Autoloader for src/ classes.
spl_autoload_register( function ( string $class ): void {
	if ( strpos( $class, 'Pressento\\Scopio\\' ) !== 0 ) {
		return;
	}
	$relative = substr( $class, strlen( 'Pressento\\Scopio\\' ) );
	$file     = SCOPIO_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Boot the plugin.
add_action( 'plugins_loaded', function (): void {
	\Pressento\Scopio\Plugin::get_instance()->boot();
} );

// -------------------------------------------------------------------------
// Public helper API
// -------------------------------------------------------------------------

/**
 * Return the resolved client IP address.
 *
 * Respects trusted-proxy settings if configured.
 *
 * @return string IP address string.
 */
function scopio_get_client_ip(): string {
	return \Pressento\Scopio\Plugin::get_instance()->get_visibility_service()->get_client_ip();
}

/**
 * Return the slugs of all Scopio groups whose CIDR ranges match the given IP.
 *
 * @param string|null $ip IP to check; null uses the current client IP.
 * @return string[] Array of group taxonomy term slugs.
 */
function scopio_get_matching_group_slugs( ?string $ip = null ): array {
	return \Pressento\Scopio\Plugin::get_instance()->get_visibility_service()->get_matching_group_slugs( $ip );
}

/**
 * Return true if the given post is visible to the visitor at the given IP.
 *
 * @param int         $post_id Post ID to check.
 * @param string|null $ip      IP to check; null uses the current client IP.
 * @return bool
 */
function scopio_can_view_post( int $post_id, ?string $ip = null ): bool {
	return \Pressento\Scopio\Plugin::get_instance()->get_visibility_service()->can_view_post( $post_id, $ip );
}

/**
 * Filter an array of post IDs to only those visible to the visitor at the given IP.
 *
 * @param int[]       $post_ids Array of post IDs.
 * @param string|null $ip       IP to check; null uses the current client IP.
 * @return int[]
 */
function scopio_filter_visible_post_ids( array $post_ids, ?string $ip = null ): array {
	return \Pressento\Scopio\Plugin::get_instance()->get_visibility_service()->filter_visible_post_ids( $post_ids, $ip );
}
