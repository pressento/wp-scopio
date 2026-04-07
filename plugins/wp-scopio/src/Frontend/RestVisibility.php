<?php
/**
 * REST API visibility enforcement for public collection and singular endpoints.
 *
 * @package Pressento\Scopio
 */

declare( strict_types=1 );

namespace Pressento\Scopio\Frontend;

use Pressento\Scopio\Taxonomy\GroupTaxonomy;
use Pressento\Scopio\Visibility\VisibilityService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RestVisibility — prevents restricted posts from appearing in public REST API
 * responses for collection and singular routes.
 *
 * Approach:
 *  - For collections: hook `rest_{post_type}_query` to add the same scopio
 *    filtering args used by QueryFilter. The SQL clause filters are still
 *    active because WP_Query powers REST collection queries.
 *  - For singulars: hook `rest_prepare_{post_type}` and return a 404-style
 *    WP_Error when the visitor cannot view the post.
 */
class RestVisibility {

	/** @var VisibilityService */
	private VisibilityService $visibility;

	/**
	 * @param VisibilityService $visibility_service Shared visibility service.
	 */
	public function __construct( VisibilityService $visibility_service ) {
		$this->visibility = $visibility_service;
	}

	/**
	 * Register hooks for each supported post type.
	 */
	public function register(): void {
		/** @var string[] $post_types */
		$post_types = (array) apply_filters( 'scopio/supported_post_types', [ 'post', 'page' ] );

		foreach ( $post_types as $post_type ) {
			add_filter( "rest_{$post_type}_query",   [ $this, 'filter_collection_query' ], 10, 2 );
			add_filter( "rest_prepare_{$post_type}", [ $this, 'filter_singular_response' ], 10, 2 );
		}
	}

	// -------------------------------------------------------------------------
	// Collection filtering
	// -------------------------------------------------------------------------

	/**
	 * Attach Scopio filtering args to REST collection queries.
	 *
	 * @param array<string, mixed>   $args    WP_Query args.
	 * @param \WP_REST_Request $request REST request (unused).
	 * @return array<string, mixed>
	 */
	public function filter_collection_query( array $args, \WP_REST_Request $request ): array {
		if ( $this->is_privileged_rest_user() ) {
			return $args;
		}

		$ip         = $this->visibility->get_client_ip();
		$extra_args = $this->visibility->build_query_args( $ip );

		return array_merge( $args, $extra_args );
	}

	// -------------------------------------------------------------------------
	// Singular filtering
	// -------------------------------------------------------------------------

	/**
	 * Return a 404 WP_Error when the visitor cannot view the requested post.
	 *
	 * @param \WP_REST_Response $response Current REST response.
	 * @param \WP_Post          $post     The post.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function filter_singular_response( \WP_REST_Response $response, \WP_Post $post ): \WP_REST_Response|\WP_Error {
		if ( $this->is_privileged_rest_user() ) {
			return $response;
		}

		if ( $this->visibility->can_view_post( $post->ID ) ) {
			return $response;
		}

		return new \WP_Error(
			'rest_post_invalid_id',
			__( 'Invalid post ID.', 'wp-scopio' ),
			[ 'status' => 404 ]
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Return true when the current REST user has edit_posts capability.
	 *
	 * Privileged users (editors, admins) bypass REST visibility filtering so
	 * their admin and editorial workflows remain unaffected.
	 */
	private function is_privileged_rest_user(): bool {
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}
}
