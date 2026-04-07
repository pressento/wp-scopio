<?php
/**
 * Scopio plugin tests.
 *
 * @package Pressento\Scopio
 */

/**
 * Tests for Scopio plugin bootstrap, taxonomy, CIDR matching,
 * and visibility service.
 */
class Test_Scopio extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	/**
	 * Verify the plugin constants are defined after bootstrap.
	 */
	public function test_constants_are_defined(): void {
		$this->assertTrue( defined( 'SCOPIO_VERSION' ) );
		$this->assertTrue( defined( 'SCOPIO_DIR' ) );
		$this->assertTrue( defined( 'SCOPIO_URL' ) );
	}

	// -------------------------------------------------------------------------
	// Taxonomy
	// -------------------------------------------------------------------------

	/**
	 * The scopio_group taxonomy must be registered.
	 */
	public function test_scopio_group_taxonomy_registered(): void {
		$this->assertTrue( taxonomy_exists( 'scopio_group' ) );
	}

	/**
	 * The scopio_group taxonomy must not be publicly queryable.
	 */
	public function test_scopio_group_taxonomy_not_public(): void {
		$taxonomy = get_taxonomy( 'scopio_group' );
		$this->assertNotFalse( $taxonomy );
		$this->assertFalse( $taxonomy->public );
		$this->assertFalse( $taxonomy->publicly_queryable );
		$this->assertFalse( $taxonomy->rewrite );
		$this->assertFalse( $taxonomy->query_var );
	}

	// -------------------------------------------------------------------------
	// CidrMatcher — IPv4
	// -------------------------------------------------------------------------

	private function cidr(): \Pressento\Scopio\Visibility\CidrMatcher {
		return new \Pressento\Scopio\Visibility\CidrMatcher();
	}

	public function test_ipv4_exact_match(): void {
		$this->assertTrue( $this->cidr()->matches( '192.168.1.1', '192.168.1.1/32' ) );
	}

	public function test_ipv4_in_subnet(): void {
		$this->assertTrue( $this->cidr()->matches( '192.168.1.100', '192.168.1.0/24' ) );
	}

	public function test_ipv4_outside_subnet(): void {
		$this->assertFalse( $this->cidr()->matches( '192.168.2.1', '192.168.1.0/24' ) );
	}

	public function test_ipv4_slash_zero_matches_all(): void {
		$this->assertTrue( $this->cidr()->matches( '1.2.3.4', '0.0.0.0/0' ) );
	}

	public function test_ipv4_bare_ip_exact(): void {
		$this->assertTrue( $this->cidr()->matches( '10.0.0.1', '10.0.0.1' ) );
		$this->assertFalse( $this->cidr()->matches( '10.0.0.2', '10.0.0.1' ) );
	}

	public function test_ipv4_invalid_cidr_returns_false(): void {
		$this->assertFalse( $this->cidr()->matches( '10.0.0.1', 'not-a-cidr' ) );
	}

	public function test_ipv4_invalid_ip_returns_false(): void {
		$this->assertFalse( $this->cidr()->matches( 'not-an-ip', '10.0.0.0/8' ) );
	}

	public function test_cidr_matches_any(): void {
		$matcher = $this->cidr();
		$cidrs   = [ '10.0.0.0/8', '172.16.0.0/12' ];
		$this->assertTrue( $matcher->matches_any( '10.5.5.5', $cidrs ) );
		$this->assertTrue( $matcher->matches_any( '172.20.1.1', $cidrs ) );
		$this->assertFalse( $matcher->matches_any( '8.8.8.8', $cidrs ) );
	}

	// -------------------------------------------------------------------------
	// CidrMatcher — IPv6
	// -------------------------------------------------------------------------

	public function test_ipv6_exact_match(): void {
		$this->assertTrue( $this->cidr()->matches( '2001:db8::1', '2001:db8::1/128' ) );
	}

	public function test_ipv6_in_subnet(): void {
		$this->assertTrue( $this->cidr()->matches( '2001:db8::1', '2001:db8::/32' ) );
	}

	public function test_ipv6_outside_subnet(): void {
		$this->assertFalse( $this->cidr()->matches( '2001:db9::1', '2001:db8::/32' ) );
	}

	public function test_ipv6_loopback(): void {
		$this->assertTrue( $this->cidr()->matches( '::1', '::1/128' ) );
	}

	public function test_ipv6_bare_ip_exact(): void {
		$this->assertTrue( $this->cidr()->matches( '::1', '::1' ) );
		$this->assertFalse( $this->cidr()->matches( '::2', '::1' ) );
	}

	// -------------------------------------------------------------------------
	// VisibilityService
	// -------------------------------------------------------------------------

	/**
	 * A post with no scopio_group terms must be visible.
	 */
	public function test_post_with_no_groups_is_public(): void {
		$post_id = self::factory()->post->create();

		$visible = scopio_can_view_post( $post_id, '1.2.3.4' );
		$this->assertTrue( $visible );
	}

	/**
	 * A post assigned to a group is restricted when the visitor IP does not match.
	 */
	public function test_restricted_post_hidden_for_non_matching_ip(): void {
		// Create a group with a specific CIDR.
		$term = wp_insert_term( 'Internal Network', 'scopio_group', [ 'slug' => 'internal' ] );
		$this->assertFalse( is_wp_error( $term ) );
		$term_id = $term['term_id'];
		update_term_meta( $term_id, 'scopio_cidrs', [ '192.168.0.0/16' ] );

		// Create a post and assign the group.
		$post_id = self::factory()->post->create();
		wp_set_post_terms( $post_id, [ $term_id ], 'scopio_group' );

		// External IP should not see the post.
		$this->assertFalse( scopio_can_view_post( $post_id, '8.8.8.8' ) );
	}

	/**
	 * A post assigned to a group is visible when the visitor IP matches.
	 */
	public function test_restricted_post_visible_for_matching_ip(): void {
		// Use a fresh term slug to avoid cross-test caching.
		$term = wp_insert_term( 'Intranet', 'scopio_group', [ 'slug' => 'intranet' ] );
		$this->assertFalse( is_wp_error( $term ) );
		$term_id = $term['term_id'];
		update_term_meta( $term_id, 'scopio_cidrs', [ '10.0.0.0/8' ] );

		$post_id = self::factory()->post->create();
		wp_set_post_terms( $post_id, [ $term_id ], 'scopio_group' );

		// Matching IP should see the post.
		$this->assertTrue( scopio_can_view_post( $post_id, '10.1.2.3' ) );
	}

	/**
	 * A post assigned to a group with no valid CIDRs must be hidden.
	 */
	public function test_post_with_empty_cidrs_is_hidden(): void {
		$term = wp_insert_term( 'Empty Group', 'scopio_group', [ 'slug' => 'empty-group' ] );
		$this->assertFalse( is_wp_error( $term ) );
		$term_id = $term['term_id'];
		update_term_meta( $term_id, 'scopio_cidrs', [] );

		$post_id = self::factory()->post->create();
		wp_set_post_terms( $post_id, [ $term_id ], 'scopio_group' );

		// No matching CIDRs → post is restricted; visitor should not see it.
		$this->assertFalse( scopio_can_view_post( $post_id, '10.0.0.1' ) );
	}

	/**
	 * filter_visible_post_ids must return only visible posts.
	 */
	public function test_filter_visible_post_ids(): void {
		$public_id     = self::factory()->post->create();

		$term = wp_insert_term( 'Private Net', 'scopio_group', [ 'slug' => 'private-net' ] );
		$this->assertFalse( is_wp_error( $term ) );
		$term_id = $term['term_id'];
		update_term_meta( $term_id, 'scopio_cidrs', [ '172.16.0.0/12' ] );

		$restricted_id = self::factory()->post->create();
		wp_set_post_terms( $restricted_id, [ $term_id ], 'scopio_group' );

		// External visitor.
		$visible = scopio_filter_visible_post_ids( [ $public_id, $restricted_id ], '8.8.8.8' );
		$this->assertContains( $public_id, $visible );
		$this->assertNotContains( $restricted_id, $visible );

		// Internal visitor.
		$visible = scopio_filter_visible_post_ids( [ $public_id, $restricted_id ], '172.16.0.1' );
		$this->assertContains( $public_id, $visible );
		$this->assertContains( $restricted_id, $visible );
	}

	/**
	 * scopio_get_matching_group_slugs must return the matching group slug.
	 */
	public function test_get_matching_group_slugs(): void {
		$term = wp_insert_term( 'Corp', 'scopio_group', [ 'slug' => 'corp-net' ] );
		$this->assertFalse( is_wp_error( $term ) );
		$term_id = $term['term_id'];
		update_term_meta( $term_id, 'scopio_cidrs', [ '203.0.113.0/24' ] );

		$slugs = scopio_get_matching_group_slugs( '203.0.113.5' );
		$this->assertContains( 'corp-net', $slugs );

		$slugs = scopio_get_matching_group_slugs( '8.8.8.8' );
		$this->assertNotContains( 'corp-net', $slugs );
	}

	// -------------------------------------------------------------------------
	// Teardown — flush the GroupRepository cache between tests.
	// -------------------------------------------------------------------------

	public function tearDown(): void {
		// Flush the in-memory group cache so CIDRs updated in one test
		// do not bleed into the next test.
		\Pressento\Scopio\Plugin::get_instance()
			->get_visibility_service();
		// Access the repo via reflection to flush its cache.
		$plugin  = \Pressento\Scopio\Plugin::get_instance();
		$service = $plugin->get_visibility_service();
		$ref     = new ReflectionObject( $service );
		$prop    = $ref->getProperty( 'group_repo' );
		$prop->setAccessible( true );
		/** @var \Pressento\Scopio\Visibility\GroupRepository $repo */
		$repo = $prop->getValue( $service );
		$repo->flush_cache();

		parent::tearDown();
	}
}
