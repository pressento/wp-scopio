<?php
/**
 * Metabox for assigning Scopio Groups to posts and pages.
 *
 * @package Pressento\Scopio
 */

declare( strict_types=1 );

namespace Pressento\Scopio\Admin;

use Pressento\Scopio\Taxonomy\GroupTaxonomy;
use Pressento\Scopio\Visibility\VisibilityService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PostVisibilityMetaBox — renders a simple checklist of Scopio Groups in the
 * classic editor and block editor sidebar, and saves term assignments.
 */
class PostVisibilityMetaBox {

	/** @var VisibilityService */
	private VisibilityService $visibility;

	/**
	 * @param VisibilityService $visibility_service Shared visibility service.
	 */
	public function __construct( VisibilityService $visibility_service ) {
		$this->visibility = $visibility_service;
	}

	/**
	 * Register metabox hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post',      [ $this, 'save_post' ], 10, 2 );
	}

	/**
	 * Register the metabox for each supported post type.
	 */
	public function add_meta_boxes(): void {
		/** @var string[] $post_types */
		$post_types = (array) apply_filters( 'scopio/supported_post_types', [ 'post', 'page' ] );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'scopio-visibility',
				__( 'Scopio Visibility', 'wp-scopio' ),
				[ $this, 'render' ],
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the metabox contents.
	 *
	 * @param \WP_Post $post Post being edited.
	 */
	public function render( \WP_Post $post ): void {
		wp_nonce_field( 'scopio_save_visibility', 'scopio_visibility_nonce' );

		$all_groups      = get_terms( [ 'taxonomy' => GroupTaxonomy::SLUG, 'hide_empty' => false ] );
		$assigned_groups = wp_get_post_terms( $post->ID, GroupTaxonomy::SLUG, [ 'fields' => 'ids' ] );

		if ( is_wp_error( $assigned_groups ) ) {
			$assigned_groups = [];
		}

		$assigned_ids = array_map( 'intval', (array) $assigned_groups );

		?>
		<div class="scopio-metabox">
			<p style="margin-top:0;color:#555;font-size:12px;">
				<?php esc_html_e( 'Leave all unchecked to keep this post public. Select one or more groups to restrict visibility to matching CIDR audiences only.', 'wp-scopio' ); ?>
			</p>

			<?php if ( is_wp_error( $all_groups ) || empty( $all_groups ) ) : ?>
				<p style="color:#888;font-style:italic;font-size:12px;">
					<?php
					printf(
						/* translators: %s: URL to Scopio Groups admin screen. */
						wp_kses(
							__( 'No Scopio Groups defined yet. <a href="%s">Add groups here</a>.', 'wp-scopio' ),
							[ 'a' => [ 'href' => [] ] ]
						),
						esc_url( AdminUi::get_groups_admin_url() )
					);
					?>
				</p>
			<?php else : ?>
				<ul style="margin:0;padding:0;list-style:none;">
					<?php foreach ( $all_groups as $group ) : ?>
						<?php
						/** @var \WP_Term $group */
						$checked = in_array( $group->term_id, $assigned_ids, true );
						?>
						<li style="margin-bottom:4px;">
							<label>
								<input
									type="checkbox"
									name="scopio_groups[]"
									value="<?php echo esc_attr( (string) $group->term_id ); ?>"
									<?php checked( $checked ); ?>
								>
								<?php echo esc_html( $group->name ); ?>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p style="margin-bottom:0;color:#555;font-size:11px;border-top:1px solid #e5e5e5;padding-top:8px;margin-top:8px;">
				<?php
				esc_html_e(
					'Restricted posts are invisible to visitors whose IP does not match any assigned group. Admins can always edit restricted posts.',
					'wp-scopio'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Save Scopio Group assignments when the post is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function save_post( int $post_id, \WP_Post $post ): void {
		// Bail on autosave, revisions, and trash.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['scopio_visibility_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( $_POST['scopio_visibility_nonce'] ), 'scopio_save_visibility' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		/** @var string[] $post_types */
		$post_types = (array) apply_filters( 'scopio/supported_post_types', [ 'post', 'page' ] );
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}

		$raw_ids  = isset( $_POST['scopio_groups'] ) ? (array) $_POST['scopio_groups'] : [];
		$term_ids = array_map( 'absint', $raw_ids );
		$term_ids = array_filter( $term_ids );

		wp_set_post_terms( $post_id, $term_ids, GroupTaxonomy::SLUG );
	}
}
