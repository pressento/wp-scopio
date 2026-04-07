<?php
/**
 * Optional integration bridge for WP Lingua.
 *
 * @package Pressento\Scopio
 */

declare( strict_types=1 );

namespace Pressento\Scopio\Integration;

use Pressento\Scopio\Visibility\VisibilityService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WpLinguaBridge — optional, non-invasive integration layer for the sibling
 * wp-lingua plugin.
 *
 * This class is activated only when a clearly identifiable WP Lingua class or
 * function is present. It does not create any hard dependency on WP Lingua
 * internals and will silently do nothing when WP Lingua is absent.
 *
 * Intent: multilingual switchers and translation APIs can inadvertently leak
 * the existence of posts that Scopio would otherwise hide. This bridge exposes
 * Scopio's visibility decisions via well-documented filters so WP Lingua (or
 * any other sibling plugin) can consume them without re-implementing CIDR logic.
 *
 * WP Lingua integration authors should hook into:
 *  - scopio/can_view_post      — to suppress individual post visibility
 *  - scopio/filter_visible_posts — to filter post-ID arrays
 *  - scopio/matching_group_slugs — to read the visitor's matched groups
 *
 * This class currently sets up filter documentation hooks and a lightweight
 * compatibility check; extend it when WP Lingua exposes a stable integration API.
 */
class WpLinguaBridge {

	/** @var VisibilityService */
	private VisibilityService $visibility;

	/**
	 * @param VisibilityService $visibility_service Shared visibility service.
	 */
	public function __construct( VisibilityService $visibility_service ) {
		$this->visibility = $visibility_service;
	}

	/**
	 * Register the bridge only when WP Lingua appears to be active.
	 *
	 * Detection is intentionally lenient: any of the following signals indicate
	 * that WP Lingua is present:
	 *  - A class whose name starts with `WpLingua\` is declared.
	 *  - A function `wp_lingua_get_post_translations` exists.
	 *  - A constant `WP_LINGUA_VERSION` is defined.
	 *
	 * @param VisibilityService $visibility_service Shared visibility service.
	 */
	public static function maybe_register( VisibilityService $visibility_service ): void {
		if ( ! self::is_wp_lingua_active() ) {
			return;
		}

		$bridge = new self( $visibility_service );
		$bridge->register();
	}

	/**
	 * Register integration hooks.
	 */
	public function register(): void {
		// Allow WP Lingua to be notified when Scopio is ready.
		do_action( 'scopio/wp_lingua_bridge_ready', $this->visibility );

		/**
		 * Expose a helper filter that WP Lingua can use to check post visibility
		 * before returning translation links or alternate hreflang URLs.
		 *
		 * Example usage in WP Lingua:
		 *   $is_visible = apply_filters( 'scopio/can_view_post', true, $post_id, null );
		 */
		add_filter( 'scopio/wp_lingua_post_is_visible', [ $this, 'post_is_visible_for_lingua' ], 10, 2 );
	}

	/**
	 * Compatibility filter: return whether a post is visible to the current visitor.
	 *
	 * WP Lingua can call:
	 *   apply_filters( 'scopio/wp_lingua_post_is_visible', true, $post_id )
	 *
	 * @param bool $default  Default visibility (passed from WP Lingua).
	 * @param int  $post_id  Post ID to check.
	 * @return bool
	 */
	public function post_is_visible_for_lingua( bool $default, int $post_id ): bool {
		return $this->visibility->can_view_post( $post_id );
	}

	// -------------------------------------------------------------------------
	// Detection
	// -------------------------------------------------------------------------

	/**
	 * Return true when WP Lingua appears to be active.
	 */
	private static function is_wp_lingua_active(): bool {
		if ( defined( 'WP_LINGUA_VERSION' ) ) {
			return true;
		}
		if ( function_exists( 'wp_lingua_get_post_translations' ) ) {
			return true;
		}
		// Check for namespaced class (without autoloading).
		foreach ( get_declared_classes() as $class ) {
			if ( str_starts_with( $class, 'WpLingua\\' ) ) {
				return true;
			}
		}
		return false;
	}
}
