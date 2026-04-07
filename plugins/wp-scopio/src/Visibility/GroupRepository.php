<?php
/**
 * Loads Scopio group terms and their CIDR ranges.
 *
 * @package Pressento\Scopio
 */

declare( strict_types=1 );

namespace Pressento\Scopio\Visibility;

use Pressento\Scopio\Admin\GroupTermMeta;
use Pressento\Scopio\Taxonomy\GroupTaxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GroupRepository — fetches Scopio Group terms and their CIDR data.
 *
 * Keeps a per-request in-memory cache so repeated calls during a single
 * page load do not hit the database multiple times.
 */
class GroupRepository {

	/** @var CidrMatcher */
	private CidrMatcher $cidr;

	/**
	 * Per-request cache: term_slug → string[] CIDRs.
	 *
	 * @var array<string, string[]>|null
	 */
	private ?array $cache = null;

	/**
	 * @param CidrMatcher $cidr_matcher Used to validate CIDRs at load time.
	 */
	public function __construct( CidrMatcher $cidr_matcher ) {
		$this->cidr = $cidr_matcher;
	}

	/**
	 * Return a map of group slug → CIDR array for all groups.
	 *
	 * @return array<string, string[]>
	 */
	public function get_all_groups(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$this->cache = [];
		$terms = get_terms( [
			'taxonomy'   => GroupTaxonomy::SLUG,
			'hide_empty' => false,
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return $this->cache;
		}

		foreach ( $terms as $term ) {
			/** @var \WP_Term $term */
			$raw  = get_term_meta( $term->term_id, GroupTermMeta::META_KEY, true );
			$raw  = is_array( $raw ) ? $raw : [];

			// Only keep non-empty entries; we do not validate CIDR format here —
			// the CidrMatcher returns false on invalid CIDRs silently.
			$cidrs = array_values( array_filter(
				array_map( 'trim', $raw ),
				fn( string $v ): bool => '' !== $v
			) );

			$this->cache[ $term->slug ] = $cidrs;
		}

		return $this->cache;
	}

	/**
	 * Return the slugs of all groups that contain at least one CIDR
	 * matching the given IP address.
	 *
	 * @param string $ip IP address to match.
	 * @return string[] Matching group slugs.
	 */
	public function get_matching_slugs( string $ip ): array {
		$matching = [];
		foreach ( $this->get_all_groups() as $slug => $cidrs ) {
			if ( $this->cidr->matches_any( $ip, $cidrs ) ) {
				$matching[] = $slug;
			}
		}
		return $matching;
	}

	/**
	 * Return the term slugs assigned to a given post.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public function get_post_group_slugs( int $post_id ): array {
		$terms = wp_get_post_terms( $post_id, GroupTaxonomy::SLUG, [ 'fields' => 'slugs' ] );
		if ( is_wp_error( $terms ) ) {
			return [];
		}
		return (array) $terms;
	}

	/**
	 * Return the term IDs assigned to a given post.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	public function get_post_group_ids( int $post_id ): array {
		$terms = wp_get_post_terms( $post_id, GroupTaxonomy::SLUG, [ 'fields' => 'ids' ] );
		if ( is_wp_error( $terms ) ) {
			return [];
		}
		return array_map( 'intval', (array) $terms );
	}

	/**
	 * Return term IDs for groups that match the given IP.
	 *
	 * @param string $ip IP address to match.
	 * @return int[]
	 */
	public function get_matching_term_ids( string $ip ): array {
		$matching_slugs = $this->get_matching_slugs( $ip );
		if ( empty( $matching_slugs ) ) {
			return [];
		}

		$terms = get_terms( [
			'taxonomy'   => GroupTaxonomy::SLUG,
			'slug'       => $matching_slugs,
			'hide_empty' => false,
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return [];
		}

		return array_map( fn( \WP_Term $t ) => $t->term_id, (array) $terms );
	}

	/**
	 * Flush the in-memory cache. Useful in tests.
	 */
	public function flush_cache(): void {
		$this->cache = null;
	}
}
