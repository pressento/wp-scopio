<?php
/**
 * Protects singular post/page access for restricted posts.
 *
 * @package Pressento\Scopio
 */

declare( strict_types=1 );

namespace Pressento\Scopio\Frontend;

use Pressento\Scopio\Visibility\VisibilityService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SingularGuard — intercepts singular template requests and redirects
 * unauthorized visitors to a 404 page instead of exposing restricted content.
 *
 * A 404 response is preferred over 403 so that the existence of the restricted
 * post is not disclosed to unauthorized visitors.
 */
class SingularGuard {

	/** @var VisibilityService */
	private VisibilityService $visibility;

	/**
	 * @param VisibilityService $visibility_service Shared visibility service.
	 */
	public function __construct( VisibilityService $visibility_service ) {
		$this->visibility = $visibility_service;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'template_redirect', [ $this, 'guard' ] );
	}

	/**
	 * Check singular access; trigger 404 if the visitor is not authorized.
	 */
	public function guard(): void {
		if ( is_admin() ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! ( $post instanceof \WP_Post ) ) {
			return;
		}

		// Users who can edit this post are never blocked.
		if ( $this->visibility->current_user_can_edit( $post->ID ) ) {
			return;
		}

		if ( $this->visibility->can_view_post( $post->ID ) ) {
			return;
		}

		// Not authorized — behave as if the post does not exist.
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();

		// Load the theme 404 template.
		get_template_part( '404' );
		exit;
	}
}
