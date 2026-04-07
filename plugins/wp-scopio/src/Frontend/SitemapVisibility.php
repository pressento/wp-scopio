<?php
/**
 * Filters restricted posts out of the WordPress XML sitemap.
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
 * SitemapVisibility — hooks into `wp_sitemaps_posts_query_args` so that
 * restricted posts are excluded from public XML sitemaps.
 */
class SitemapVisibility {

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
		add_filter( 'wp_sitemaps_posts_query_args', [ $this, 'filter_sitemap_query' ], 10, 2 );
	}

	/**
	 * Attach Scopio filtering args to sitemap post queries.
	 *
	 * Sitemap requests are always anonymous (no logged-in user context),
	 * so we apply the same query-time exclusion as front-end listing queries.
	 *
	 * @param array<string, mixed> $args      WP_Query args for the sitemap query.
	 * @param string               $post_type Post type being queried.
	 * @return array<string, mixed>
	 */
	public function filter_sitemap_query( array $args, string $post_type ): array {
		/** @var string[] $supported */
		$supported = (array) apply_filters( 'scopio/supported_post_types', [ 'post', 'page' ] );

		if ( ! in_array( $post_type, $supported, true ) ) {
			return $args;
		}

		$ip         = $this->visibility->get_client_ip();
		$extra_args = $this->visibility->build_query_args( $ip );

		return array_merge( $args, $extra_args );
	}
}
