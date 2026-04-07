<?php
/**
 * Front-end query filtering for main queries, Query Loop blocks, and feeds.
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
 * QueryFilter — hooks into WordPress query machinery to exclude restricted
 * posts from public listings.
 *
 * Strategy: attach `posts_where` and `posts_join` filters that add a subquery
 * ensuring only posts with no scopio_group terms, or posts with a matching
 * scopio_group term, are returned.
 *
 * This is applied to:
 *  - Main front-end query (home, archives, search, feeds, …)
 *  - Query Loop block queries (query_loop_block_query_vars)
 *  - wp_sitemaps post queries (handled in SitemapVisibility)
 */
class QueryFilter {

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
		add_action( 'pre_get_posts',                     [ $this, 'filter_main_query' ] );
		add_filter( 'query_loop_block_query_vars',       [ $this, 'filter_block_query_vars' ], 10, 1 );
		add_filter( 'posts_join_paged',                  [ $this, 'posts_join' ], 10, 2 );
		add_filter( 'posts_where_paged',                 [ $this, 'posts_where' ], 10, 2 );
		add_filter( 'posts_groupby',                     [ $this, 'posts_groupby' ], 10, 2 );
	}

	// -------------------------------------------------------------------------
	// pre_get_posts — main query
	// -------------------------------------------------------------------------

	/**
	 * Attach visibility args to eligible front-end main queries.
	 *
	 * @param \WP_Query $query The current WP_Query object.
	 */
	public function filter_main_query( \WP_Query $query ): void {
		if ( ! $this->should_filter_query( $query ) ) {
			return;
		}

		$ip   = $this->visibility->get_client_ip();
		$args = $this->visibility->build_query_args( $ip );

		$query->set( 'scopio_filter_ip', $args['scopio_filter_ip'] );
		$query->set( 'scopio_matching_term_ids', $args['scopio_matching_term_ids'] );
	}

	// -------------------------------------------------------------------------
	// Query Loop block
	// -------------------------------------------------------------------------

	/**
	 * Apply Scopio filtering to Query Loop block queries.
	 *
	 * @param array<string, mixed> $query_vars Query variables.
	 * @return array<string, mixed>
	 */
	public function filter_block_query_vars( array $query_vars ): array {
		if ( is_admin() ) {
			return $query_vars;
		}

		$ip   = $this->visibility->get_client_ip();
		$args = $this->visibility->build_query_args( $ip );

		$query_vars['scopio_filter_ip']         = $args['scopio_filter_ip'];
		$query_vars['scopio_matching_term_ids'] = $args['scopio_matching_term_ids'];

		return $query_vars;
	}

	// -------------------------------------------------------------------------
	// SQL clause filters
	// -------------------------------------------------------------------------

	/**
	 * Add a JOIN clause for scopio group taxonomy terms.
	 *
	 * @param string    $join  Current JOIN clause.
	 * @param \WP_Query $query Current query.
	 * @return string
	 */
	public function posts_join( string $join, \WP_Query $query ): string {
		if ( ! $this->is_scopio_filtered_query( $query ) ) {
			return $join;
		}

		global $wpdb;

		$join .= " LEFT JOIN {$wpdb->term_relationships} AS scopio_tr
					ON ( {$wpdb->posts}.ID = scopio_tr.object_id )
				  LEFT JOIN {$wpdb->term_taxonomy} AS scopio_tt
					ON ( scopio_tr.term_taxonomy_id = scopio_tt.term_taxonomy_id
						AND scopio_tt.taxonomy = '" . esc_sql( GroupTaxonomy::SLUG ) . "' ) ";

		return $join;
	}

	/**
	 * Add a WHERE clause that enforces Scopio visibility.
	 *
	 * The clause allows posts that:
	 *  (a) have no scopio_group term assigned, OR
	 *  (b) have at least one scopio_group term whose term_id is in the
	 *      visitor's matching set.
	 *
	 * @param string    $where Current WHERE clause.
	 * @param \WP_Query $query Current query.
	 * @return string
	 */
	public function posts_where( string $where, \WP_Query $query ): string {
		if ( ! $this->is_scopio_filtered_query( $query ) ) {
			return $where;
		}

		global $wpdb;

		$matching_ids = (array) $query->get( 'scopio_matching_term_ids' );
		$matching_ids = array_map( 'intval', $matching_ids );

		if ( ! empty( $matching_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $matching_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$in_clause    = $wpdb->prepare( "scopio_tt.term_id IN ( {$placeholders} )", ...$matching_ids );
			$where       .= " AND ( scopio_tt.term_taxonomy_id IS NULL OR ( {$in_clause} ) ) ";
		} else {
			// Visitor matches no groups — show only posts with no groups.
			$where .= ' AND scopio_tt.term_taxonomy_id IS NULL ';
		}

		return $where;
	}

	/**
	 * Add GROUP BY to prevent duplicate posts from the JOIN.
	 *
	 * @param string    $groupby Current GROUP BY clause.
	 * @param \WP_Query $query   Current query.
	 * @return string
	 */
	public function posts_groupby( string $groupby, \WP_Query $query ): string {
		if ( ! $this->is_scopio_filtered_query( $query ) ) {
			return $groupby;
		}

		global $wpdb;
		$groupby = "{$wpdb->posts}.ID";
		return $groupby;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Determine whether a WP_Query should have Scopio filtering applied.
	 */
	private function should_filter_query( \WP_Query $query ): bool {
		// Never touch admin queries.
		if ( is_admin() ) {
			return false;
		}
		// Only apply to the main query (archives, search, home, feed).
		if ( ! $query->is_main_query() ) {
			return false;
		}
		// Not singular — singulars are handled by SingularGuard.
		if ( $query->is_singular() ) {
			return false;
		}
		return true;
	}

	/**
	 * Return true if this query has the scopio_filter_ip query var set.
	 */
	private function is_scopio_filtered_query( \WP_Query $query ): bool {
		return '' !== (string) $query->get( 'scopio_filter_ip', '' );
	}
}
