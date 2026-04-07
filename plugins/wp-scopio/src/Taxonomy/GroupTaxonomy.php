<?php
/**
 * Registers the scopio_group private custom taxonomy.
 *
 * @package Pressento\Scopio
 */

declare( strict_types=1 );

namespace Pressento\Scopio\Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GroupTaxonomy — registers the `scopio_group` private taxonomy.
 *
 * The taxonomy is intentionally non-public: no rewrite rules, no public
 * query_var, not shown in the front-end nav. It is manageable in wp-admin
 * and available for posts and pages by default, extensible via a filter.
 */
class GroupTaxonomy {

	/** Taxonomy slug constant. */
	public const SLUG = 'scopio_group';

	/**
	 * Wire up the registration hook.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_taxonomy' ] );
	}

	/**
	 * Register the `scopio_group` taxonomy.
	 */
	public function register_taxonomy(): void {
		/**
		 * Filter the post types that support Scopio group assignment.
		 *
		 * @param string[] $post_types Default: [ 'post', 'page' ].
		 */
		$post_types = (array) apply_filters( 'scopio/supported_post_types', [ 'post', 'page' ] );

		$labels = [
			'name'              => _x( 'Scopio Groups', 'taxonomy general name', 'wp-scopio' ),
			'singular_name'     => _x( 'Scopio Group', 'taxonomy singular name', 'wp-scopio' ),
			'search_items'      => __( 'Search Scopio Groups', 'wp-scopio' ),
			'all_items'         => __( 'All Scopio Groups', 'wp-scopio' ),
			'edit_item'         => __( 'Edit Scopio Group', 'wp-scopio' ),
			'update_item'       => __( 'Update Scopio Group', 'wp-scopio' ),
			'add_new_item'      => __( 'Add New Scopio Group', 'wp-scopio' ),
			'new_item_name'     => __( 'New Scopio Group Name', 'wp-scopio' ),
			'menu_name'         => __( 'Scopio Groups', 'wp-scopio' ),
			'not_found'         => __( 'No Scopio Groups found.', 'wp-scopio' ),
			'back_to_items'     => __( '← Back to Scopio Groups', 'wp-scopio' ),
		];

		register_taxonomy(
			self::SLUG,
			$post_types,
			[
				'labels'             => $labels,
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_nav_menus'  => false,
				'show_in_rest'       => false,
				'show_tagcloud'      => false,
				'show_in_quick_edit' => false,
				'show_admin_column'  => true,
				'hierarchical'       => false,
				'query_var'          => false,
				'rewrite'            => false,
				'meta_box_cb'        => false, // We provide our own metabox.
				'capabilities'       => [
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'edit_posts',
				],
			]
		);
	}
}
