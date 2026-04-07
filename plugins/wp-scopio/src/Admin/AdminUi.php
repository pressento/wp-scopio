<?php
/**
 * Shared admin UI helpers for Scopio.
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
 * Shared admin UI helpers for tooltips and plugin action links.
 */
class AdminUi {

	/**
	 * Register admin-only hooks.
	 */
	public function register(): void {
		add_filter(
			'plugin_action_links_' . plugin_basename( SCOPIO_DIR . 'wp-scopio.php' ),
			[ $this, 'add_plugin_action_links' ]
		);

		add_action( 'admin_head', [ $this, 'render_tooltip_styles' ] );
		add_action( 'admin_footer', [ $this, 'render_tooltip_scripts' ] );
	}

	/**
	 * Return the Scopio Groups management URL.
	 */
	public static function get_groups_admin_url(): string {
		return admin_url( 'edit-tags.php?taxonomy=' . GroupTaxonomy::SLUG );
	}

	/**
	 * Return the Scopio settings URL.
	 *
	 * @param string $tab Active settings tab.
	 */
	public static function get_settings_page_url( string $tab = 'proxy' ): string {
		return (string) add_query_arg(
			[
				'page' => 'scopio-settings',
				'tab'  => $tab,
			],
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Return tooltip button markup for inline admin help.
	 *
	 * @param string   $tooltip_title Tooltip heading.
	 * @param string[] $tooltip_lines Tooltip lines.
	 * @param string   $aria_label    Accessible label for the toggle button.
	 */
	public static function get_help_tooltip_html( string $tooltip_title, array $tooltip_lines, string $aria_label ): string {
		$tooltip_id = wp_unique_id( 'scopio-tooltip-' );

		ob_start();
		?>
		<span class="scopio-help-tooltip" data-scopio-tooltip>
			<button
				type="button"
				class="scopio-help-tooltip__toggle"
				aria-expanded="false"
				aria-controls="<?php echo esc_attr( $tooltip_id ); ?>"
				aria-label="<?php echo esc_attr( $aria_label ); ?>"
			>
				<span class="scopio-help-tooltip__icon" aria-hidden="true">i</span>
			</button>
			<span class="scopio-help-tooltip__bubble" id="<?php echo esc_attr( $tooltip_id ); ?>" role="tooltip">
				<span class="scopio-help-tooltip__title"><?php echo esc_html( $tooltip_title ); ?></span>
				<?php foreach ( $tooltip_lines as $tooltip_line ) : ?>
					<span class="scopio-help-tooltip__line"><?php echo esc_html( $tooltip_line ); ?></span>
				<?php endforeach; ?>
			</span>
		</span>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Add quick links to the plugin row.
	 *
	 * @param string[] $actions Existing plugin row actions.
	 * @return string[]
	 */
	public function add_plugin_action_links( array $actions ): array {
		$scopio_actions = [
			'scopio-groups' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::get_groups_admin_url() ),
				esc_html__( 'Scopio Groups', 'wp-scopio' )
			),
			'settings'      => sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::get_settings_page_url() ),
				esc_html__( 'Settings', 'wp-scopio' )
			),
		];

		return array_merge( $scopio_actions, $actions );
	}

	/**
	 * Output tooltip styles on supported admin screens.
	 */
	public function render_tooltip_styles(): void {
		if ( ! $this->should_load_tooltip_assets() ) {
			return;
		}
		?>
		<style>
			.scopio-field-heading {
				display: inline-flex;
				align-items: center;
				gap: 6px;
				margin-bottom: 8px;
			}

			.scopio-field-heading label {
				margin: 0;
			}

			.scopio-help-tooltip {
				position: relative;
				display: inline-flex;
				align-items: center;
				vertical-align: middle;
			}

			.scopio-help-tooltip__toggle {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 20px;
				height: 20px;
				padding: 0;
				border: 1px solid #8c8f94;
				border-radius: 999px;
				background: #fff;
				color: #1d2327;
				cursor: pointer;
				font: inherit;
				line-height: 1;
			}

			.scopio-help-tooltip__toggle:hover,
			.scopio-help-tooltip__toggle:focus {
				border-color: #2271b1;
				color: #2271b1;
				outline: none;
			}

			.scopio-help-tooltip__icon {
				font-size: 12px;
				font-style: normal;
				font-weight: 700;
			}

			.scopio-help-tooltip__bubble {
				position: absolute;
				z-index: 1000;
				left: 0;
				bottom: calc( 100% + 10px );
				display: flex;
				flex-direction: column;
				gap: 4px;
				min-width: 220px;
				max-width: min( 320px, calc( 100vw - 40px ) );
				padding: 10px 12px;
				border-radius: 8px;
				background: #1d2327;
				box-shadow: 0 8px 24px rgba( 0, 0, 0, 0.2 );
				color: #f6f7f7;
				opacity: 0;
				pointer-events: none;
				transform: translateY( 4px );
				transition: opacity 120ms ease, transform 120ms ease;
				visibility: hidden;
			}

			.scopio-help-tooltip__bubble::after {
				content: '';
				position: absolute;
				left: 10px;
				top: 100%;
				border: 6px solid transparent;
				border-top-color: #1d2327;
			}

			.scopio-help-tooltip:hover .scopio-help-tooltip__bubble,
			.scopio-help-tooltip:focus-within .scopio-help-tooltip__bubble,
			.scopio-help-tooltip.is-open .scopio-help-tooltip__bubble {
				opacity: 1;
				pointer-events: auto;
				transform: translateY( 0 );
				visibility: visible;
			}

			.scopio-help-tooltip__title {
				font-size: 12px;
				font-weight: 600;
			}

			.scopio-help-tooltip__line {
				font-family: Consolas, Monaco, monospace;
				font-size: 12px;
				white-space: nowrap;
			}
		</style>
		<?php
	}

	/**
	 * Output tooltip behavior on supported admin screens.
	 */
	public function render_tooltip_scripts(): void {
		if ( ! $this->should_load_tooltip_assets() ) {
			return;
		}
		?>
		<script>
			(function() {
				const selector = '[data-scopio-tooltip]';

				function setOpenState(wrapper, isOpen) {
					if (!wrapper) {
						return;
					}

					wrapper.classList.toggle('is-open', isOpen);

					const button = wrapper.querySelector('.scopio-help-tooltip__toggle');
					if (button) {
						button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
					}
				}

				function closeAll(exceptWrapper) {
					document.querySelectorAll(selector + '.is-open').forEach(function(wrapper) {
						if (wrapper !== exceptWrapper) {
							setOpenState(wrapper, false);
						}
					});
				}

				document.addEventListener('click', function(event) {
					const wrapper = event.target.closest(selector);

					if (!wrapper) {
						closeAll(null);
						return;
					}

					const toggle = event.target.closest('.scopio-help-tooltip__toggle');
					if (!toggle) {
						return;
					}

					const isOpen = wrapper.classList.contains('is-open');

					closeAll(wrapper);
					setOpenState(wrapper, !isOpen);
					event.preventDefault();
				});

				document.addEventListener('keydown', function(event) {
					if ('Escape' !== event.key) {
						return;
					}

					closeAll(null);
				});
			})();
		</script>
		<?php
	}

	/**
	 * Determine whether tooltip assets should load for the current screen.
	 */
	private function should_load_tooltip_assets(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( null === $screen ) {
			return false;
		}

		if ( 'settings_page_scopio-settings' === $screen->id ) {
			return true;
		}

		return in_array( $screen->base, [ 'edit-tags', 'term' ], true ) && GroupTaxonomy::SLUG === $screen->taxonomy;
	}
}