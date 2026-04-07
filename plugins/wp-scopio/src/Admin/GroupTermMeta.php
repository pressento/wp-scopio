<?php
/**
 * Admin UI for managing CIDR ranges on Scopio Group taxonomy terms.
 *
 * @package Pressento\Scopio
 */

declare( strict_types=1 );

namespace Pressento\Scopio\Admin;

use Pressento\Scopio\Taxonomy\GroupTaxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GroupTermMeta — adds CIDR textarea to the add/edit term screens and
 * saves the data as term meta (`scopio_cidrs`).
 */
class GroupTermMeta {

	/** Term meta key for CIDR storage. */
	public const META_KEY = 'scopio_cidrs';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		$taxonomy = GroupTaxonomy::SLUG;

		add_action( "{$taxonomy}_add_form_fields",  [ $this, 'render_add_field' ] );
		add_action( "{$taxonomy}_edit_form_fields", [ $this, 'render_edit_field' ] );
		add_action( "created_{$taxonomy}",          [ $this, 'save_term_meta' ] );
		add_action( "edited_{$taxonomy}",           [ $this, 'save_term_meta' ] );
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Render the CIDR field on the "Add new term" screen.
	 */
	public function render_add_field(): void {
		?>
		<div class="form-field">
			<div class="scopio-field-heading">
				<label for="scopio-cidrs"><?php esc_html_e( 'CIDR Ranges', 'wp-scopio' ); ?></label>
				<?php echo AdminUi::get_help_tooltip_html( __( 'CIDR examples', 'wp-scopio' ), [ '192.168.1.0/24', '10.0.0.0/8', '2001:db8::/32' ], __( 'Show example CIDR ranges', 'wp-scopio' ) ); ?>
			</div>
			<textarea
				id="scopio-cidrs"
				name="scopio_cidrs"
				rows="5"
				style="width:100%;font-family:monospace;"
			></textarea>
			<?php wp_nonce_field( 'scopio_save_term_meta', 'scopio_term_meta_nonce' ); ?>
			<p class="description">
				<?php esc_html_e( 'Enter one CIDR range per line. IPv4 and IPv6 are supported. Use the info icon for example formats. Visitors whose IP matches any CIDR in any group assigned to a post will be able to see that post.', 'wp-scopio' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the CIDR field on the "Edit term" screen.
	 *
	 * @param \WP_Term $term Term being edited.
	 */
	public function render_edit_field( \WP_Term $term ): void {
		$raw_cidrs = get_term_meta( $term->term_id, self::META_KEY, true );
		$cidrs     = is_array( $raw_cidrs ) ? $raw_cidrs : [];
		$value     = esc_textarea( implode( "\n", $cidrs ) );
		?>
		<tr class="form-field">
			<th scope="row">
				<div class="scopio-field-heading">
					<label for="scopio-cidrs"><?php esc_html_e( 'CIDR Ranges', 'wp-scopio' ); ?></label>
					<?php echo AdminUi::get_help_tooltip_html( __( 'CIDR examples', 'wp-scopio' ), [ '192.168.1.0/24', '10.0.0.0/8', '2001:db8::/32' ], __( 'Show example CIDR ranges', 'wp-scopio' ) ); ?>
				</div>
			</th>
			<td>
				<textarea
					id="scopio-cidrs"
					name="scopio_cidrs"
					rows="5"
					style="width:100%;font-family:monospace;"
				><?php echo $value; // Already escaped above. ?></textarea>
				<?php wp_nonce_field( 'scopio_save_term_meta', 'scopio_term_meta_nonce' ); ?>
				<p class="description">
					<?php esc_html_e( 'Enter one CIDR range per line. IPv4 and IPv6 are supported. Malformed entries are silently ignored. Use the info icon for example formats.', 'wp-scopio' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	// -------------------------------------------------------------------------
	// Save
	// -------------------------------------------------------------------------

	/**
	 * Persist CIDR data when a term is created or edited.
	 *
	 * @param int $term_id Term being saved.
	 */
	public function save_term_meta( int $term_id ): void {
		if ( ! isset( $_POST['scopio_term_meta_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( $_POST['scopio_term_meta_nonce'] ), 'scopio_save_term_meta' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$raw   = isset( $_POST['scopio_cidrs'] ) ? sanitize_textarea_field( wp_unslash( $_POST['scopio_cidrs'] ) ) : '';
		$lines = array_filter(
			array_map( 'trim', explode( "\n", $raw ) ),
			fn( string $line ): bool => '' !== $line
		);

		update_term_meta( $term_id, self::META_KEY, array_values( $lines ) );
	}
}
