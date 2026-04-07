<?php
/**
 * Core visibility decision service.
 *
 * @package Pressento\Scopio
 */

declare( strict_types=1 );

namespace Pressento\Scopio\Visibility;

use Pressento\Scopio\Taxonomy\GroupTaxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * VisibilityService — the single source of truth for all visibility decisions.
 *
 * Exposes a public API that both internal subsystems and sibling plugins should
 * use instead of re-implementing CIDR or taxonomy logic.
 *
 * Policy (v1 allowlist model):
 *  - No groups assigned → public.
 *  - Groups assigned → restricted.
 *  - Restricted is visible only when the visitor IP matches ≥1 assigned group.
 *  - If assigned groups have no valid CIDRs, the post is NOT public.
 *  - Admins who can edit a post bypass the front-end restriction.
 */
class VisibilityService {

	/** @var ClientIpResolver */
	private ClientIpResolver $ip_resolver;

	/** @var GroupRepository */
	private GroupRepository $group_repo;

	/** @var CidrMatcher */
	private CidrMatcher $cidr;

	/** Cached resolved IP for this request. */
	private ?string $resolved_ip = null;

	/**
	 * @param ClientIpResolver $ip_resolver  IP resolution service.
	 * @param GroupRepository  $group_repo   Group and CIDR data service.
	 * @param CidrMatcher      $cidr_matcher CIDR matching service.
	 */
	public function __construct(
		ClientIpResolver $ip_resolver,
		GroupRepository $group_repo,
		CidrMatcher $cidr_matcher
	) {
		$this->ip_resolver = $ip_resolver;
		$this->group_repo  = $group_repo;
		$this->cidr        = $cidr_matcher;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Return the resolved client IP (cached per request).
	 *
	 * @return string
	 */
	public function get_client_ip(): string {
		if ( null === $this->resolved_ip ) {
			$this->resolved_ip = $this->ip_resolver->resolve();
		}
		return $this->resolved_ip;
	}

	/**
	 * Return the slugs of all Scopio groups whose CIDRs match the given IP.
	 *
	 * @param string|null $ip IP to check; null uses the current client IP.
	 * @return string[]
	 */
	public function get_matching_group_slugs( ?string $ip = null ): array {
		$ip = $ip ?? $this->get_client_ip();

		$slugs = $this->group_repo->get_matching_slugs( $ip );

		/**
		 * Filter the matching group slugs for the given IP.
		 *
		 * @param string[] $slugs Matching group slugs.
		 * @param string   $ip    Resolved client IP.
		 */
		return (array) apply_filters( 'scopio/matching_group_slugs', $slugs, $ip );
	}

	/**
	 * Return true if the given post is visible to the visitor at the given IP.
	 *
	 * @param int         $post_id Post ID.
	 * @param string|null $ip      IP to check; null uses the current client IP.
	 * @return bool
	 */
	public function can_view_post( int $post_id, ?string $ip = null ): bool {
		$ip = $ip ?? $this->get_client_ip();

		$decision = $this->evaluate_visibility( $post_id, $ip );

		/**
		 * Filter the visibility decision for a single post.
		 *
		 * @param bool   $decision Whether the post is visible.
		 * @param int    $post_id  Post ID.
		 * @param string $ip       Resolved client IP.
		 */
		return (bool) apply_filters( 'scopio/can_view_post', $decision, $post_id, $ip );
	}

	/**
	 * Filter an array of post IDs to only those visible to the given IP.
	 *
	 * @param int[]       $post_ids Array of post IDs.
	 * @param string|null $ip       IP to check; null uses the current client IP.
	 * @return int[]
	 */
	public function filter_visible_post_ids( array $post_ids, ?string $ip = null ): array {
		$ip = $ip ?? $this->get_client_ip();

		$visible = array_values( array_filter(
			$post_ids,
			fn( int $id ) => $this->can_view_post( $id, $ip )
		) );

		/**
		 * Filter the result of a post-ID visibility filter.
		 *
		 * @param int[]  $visible  Visible post IDs.
		 * @param int[]  $post_ids Input post IDs.
		 * @param string $ip       Resolved client IP.
		 */
		return (array) apply_filters( 'scopio/filter_visible_posts', $visible, $post_ids, $ip );
	}

	/**
	 * Return true when the current user has edit capabilities for the post.
	 *
	 * Used by frontend guards so admin users are not locked out.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function current_user_can_edit( int $post_id ): bool {
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Build a taxonomy query that restricts results to posts visible to $ip.
	 *
	 * Returns an array suitable for use as a `tax_query` array or null when
	 * no restriction is needed (visitor matches all groups or no groups exist).
	 *
	 * Strategy: include posts that either:
	 *  a) have NO scopio_group terms (public posts), OR
	 *  b) have at least one scopio_group term that the visitor IP matches.
	 *
	 * We implement this as a two-part OR tax_query combined with a custom
	 * meta/taxonomy post exclusion for truly restricted posts.
	 *
	 * Because WordPress does not support "posts with NO terms in taxonomy X"
	 * natively in tax_query, we use a `posts_where` + `posts_join` approach
	 * via query args: `scopio_filter_ip` on the query.
	 *
	 * @param string $ip Client IP.
	 * @return array<string, mixed> Additional WP_Query arguments.
	 */
	public function build_query_args( string $ip ): array {
		$matching_ids = $this->group_repo->get_matching_term_ids( $ip );

		// We pass a custom query var that QueryFilter picks up via posts_where.
		return [
			'scopio_filter_ip'          => $ip,
			'scopio_matching_term_ids'  => $matching_ids,
		];
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	/**
	 * Core visibility evaluation (no filters applied).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $ip      Resolved client IP.
	 * @return bool
	 */
	private function evaluate_visibility( int $post_id, string $ip ): bool {
		$post_group_slugs = $this->group_repo->get_post_group_slugs( $post_id );

		// No groups assigned → public.
		if ( empty( $post_group_slugs ) ) {
			return true;
		}

		// Groups assigned → check CIDR match.
		$matching_slugs = $this->group_repo->get_matching_slugs( $ip );

		// Intersect post groups with visitor's matching groups.
		return ! empty( array_intersect( $post_group_slugs, $matching_slugs ) );
	}
}
