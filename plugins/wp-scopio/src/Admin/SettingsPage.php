<?php
/**
 * Scopio settings / help page under Settings > Scopio.
 *
 * @package Pressento\Scopio
 */

declare( strict_types=1 );

namespace Pressento\Scopio\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SettingsPage — registers the "Settings > Scopio" page.
 *
 * v1 is primarily a help/documentation page. Trusted proxy configuration
 * is stored in WordPress options and read by ClientIpResolver.
 */
class SettingsPage {

	/** Option name for plugin settings. */
	public const OPTION_KEY = 'scopio_settings';

	/**
	 * Register admin menu and settings hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu',  [ $this, 'add_menu_page' ] );
		add_action( 'admin_init',  [ $this, 'register_settings' ] );
	}

	/**
	 * Add the settings page to the Settings menu.
	 */
	public function add_menu_page(): void {
		add_options_page(
			__( 'Scopio', 'wp-scopio' ),
			__( 'Scopio', 'wp-scopio' ),
			'manage_options',
			'scopio-settings',
			[ $this, 'render' ]
		);
	}

	/**
	 * Register settings fields.
	 */
	public function register_settings(): void {
		register_setting(
			'scopio_settings_group',
			self::OPTION_KEY,
			[ $this, 'sanitize_settings' ]
		);

		add_settings_section(
			'scopio_proxy_section',
			__( 'Trusted Reverse Proxy', 'wp-scopio' ),
			[ $this, 'render_proxy_section_description' ],
			'scopio-settings'
		);

		add_settings_field(
			'enable_trusted_proxy_mode',
			__( 'Enable Trusted Proxy Mode', 'wp-scopio' ),
			[ $this, 'render_field_trusted_proxy_enabled' ],
			'scopio-settings',
			'scopio_proxy_section'
		);

		add_settings_field(
			'trusted_proxy_cidrs',
			__( 'Trusted Proxy CIDRs', 'wp-scopio' ),
			[ $this, 'render_field_proxy_cidrs' ],
			'scopio-settings',
			'scopio_proxy_section'
		);

		add_settings_field(
			'trusted_ip_headers',
			__( 'Trusted IP Headers', 'wp-scopio' ),
			[ $this, 'render_field_ip_headers' ],
			'scopio-settings',
			'scopio_proxy_section'
		);
	}

	/**
	 * Sanitize incoming settings values.
	 *
	 * @param mixed $input Raw POST data.
	 * @return array<string, mixed> Sanitized settings.
	 */
	public function sanitize_settings( mixed $input ): array {
		$clean = [];

		$clean['enable_trusted_proxy_mode'] = ! empty( $input['enable_trusted_proxy_mode'] );

		$raw_cidrs = isset( $input['trusted_proxy_cidrs'] ) ? sanitize_textarea_field( wp_unslash( (string) $input['trusted_proxy_cidrs'] ) ) : '';
		$clean['trusted_proxy_cidrs'] = array_values( array_filter(
			array_map( 'trim', explode( "\n", $raw_cidrs ) ),
			fn( string $v ): bool => '' !== $v
		) );

		$default_headers = [ 'Forwarded', 'X-Forwarded-For', 'X-Real-IP' ];
		if ( isset( $input['trusted_ip_headers'] ) && is_string( $input['trusted_ip_headers'] ) ) {
			$headers_raw  = sanitize_textarea_field( wp_unslash( $input['trusted_ip_headers'] ) );
			$header_lines = array_values( array_filter(
				array_map( 'trim', explode( "\n", $headers_raw ) ),
				fn( string $v ): bool => '' !== $v
			) );
			$clean['trusted_ip_headers'] = ! empty( $header_lines ) ? $header_lines : $default_headers;
		} else {
			$clean['trusted_ip_headers'] = $default_headers;
		}

		return $clean;
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	/** @internal */
	public function render_proxy_section_description(): void {
		echo '<p>' . esc_html__( 'Configure these settings only if WordPress runs behind a trusted reverse proxy (NGINX, Caddy, YARP, HAProxy, Traefik, etc.).', 'wp-scopio' ) . '</p>';
	}

	/** @internal */
	public function render_field_trusted_proxy_enabled(): void {
		$opts    = $this->get_options();
		$enabled = ! empty( $opts['enable_trusted_proxy_mode'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_trusted_proxy_mode]" value="1" <?php checked( $enabled ); ?>>
			<?php esc_html_e( 'Trust forwarding headers from configured proxy CIDRs', 'wp-scopio' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'When enabled, Scopio will inspect forwarding headers (e.g. X-Forwarded-For) only when the immediate request comes from a trusted proxy CIDR listed below.', 'wp-scopio' ); ?></p>
		<?php
	}

	/** @internal */
	public function render_field_proxy_cidrs(): void {
		$opts  = $this->get_options();
		$cidrs = isset( $opts['trusted_proxy_cidrs'] ) && is_array( $opts['trusted_proxy_cidrs'] )
			? $opts['trusted_proxy_cidrs']
			: [];
		?>
		<textarea
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[trusted_proxy_cidrs]"
			rows="4"
			style="width:25em;font-family:monospace;"
			placeholder="127.0.0.1&#10;10.0.0.0/8&#10;172.16.0.0/12"
		><?php echo esc_textarea( implode( "\n", $cidrs ) ); ?></textarea>
		<p class="description"><?php esc_html_e( 'One IP or CIDR per line. Only requests originating from these addresses will have their forwarding headers trusted.', 'wp-scopio' ); ?></p>
		<?php
	}

	/** @internal */
	public function render_field_ip_headers(): void {
		$opts    = $this->get_options();
		$default = [ 'Forwarded', 'X-Forwarded-For', 'X-Real-IP' ];
		$headers = isset( $opts['trusted_ip_headers'] ) && is_array( $opts['trusted_ip_headers'] )
			? $opts['trusted_ip_headers']
			: $default;
		?>
		<textarea
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[trusted_ip_headers]"
			rows="4"
			style="width:25em;font-family:monospace;"
		><?php echo esc_textarea( implode( "\n", $headers ) ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Candidate forwarding headers in priority order, one per line. Scopio will use the first header that yields a valid IP when trusted proxy mode is active.', 'wp-scopio' ); ?></p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Main page render
	// -------------------------------------------------------------------------

	/**
	 * Render the full settings page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Scopio Settings', 'wp-scopio' ); ?></h1>

			<?php $current_tab = sanitize_key( $_GET['tab'] ?? 'proxy' ); ?>

			<h2 class="nav-tab-wrapper">
				<a href="?page=scopio-settings&tab=proxy" class="nav-tab <?php echo ( 'proxy' === $current_tab || '' === $current_tab ) ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Trusted Proxy', 'wp-scopio' ); ?>
				</a>
				<a href="?page=scopio-settings&tab=help" class="nav-tab <?php echo ( 'help' === $current_tab ) ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'How It Works', 'wp-scopio' ); ?>
				</a>
			</h2>

			<?php
			if ( 'help' === $current_tab ) {
				$this->render_help_tab();
			} else {
				$this->render_proxy_tab();
			}
			?>
		</div>
		<?php
	}

	private function render_proxy_tab(): void {
		?>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'scopio_settings_group' );
			do_settings_sections( 'scopio-settings' );
			submit_button();
			?>
		</form>
		<?php
	}

	private function render_help_tab(): void {
		?>
		<div style="max-width:780px;">
			<h2><?php esc_html_e( 'Visibility Model', 'wp-scopio' ); ?></h2>
			<p><?php esc_html_e( 'Scopio uses an allowlist model. Every post or page is either public or restricted:', 'wp-scopio' ); ?></p>
			<ul style="list-style:disc;padding-left:1.5em;">
				<li><?php esc_html_e( 'A post with no Scopio Groups assigned is public and visible to everyone.', 'wp-scopio' ); ?></li>
				<li><?php esc_html_e( 'A post assigned to one or more Scopio Groups is restricted. Only visitors whose IP matches at least one of those groups can see it.', 'wp-scopio' ); ?></li>
				<li><?php esc_html_e( 'Restricted posts do not appear in listings, search results, feeds, sitemaps, or REST responses for visitors outside the allowed groups.', 'wp-scopio' ); ?></li>
				<li><?php esc_html_e( 'Restricted posts return a 404-style response to unauthorized visitors — their existence is not disclosed.', 'wp-scopio' ); ?></li>
			</ul>

			<h2><?php esc_html_e( 'How Scopio Groups Work', 'wp-scopio' ); ?></h2>
			<p><?php esc_html_e( 'Each Scopio Group is a taxonomy term under Scopio Groups. Each group stores one or more CIDR ranges (e.g. 192.168.1.0/24) in term meta. Go to Posts > Scopio Groups to create groups.', 'wp-scopio' ); ?></p>

			<h2><?php esc_html_e( 'Client IP Resolution', 'wp-scopio' ); ?></h2>
			<p><?php esc_html_e( 'By default Scopio uses REMOTE_ADDR — the IP of the actual TCP connection — to identify the visitor. This is always available and cannot be spoofed by the client.', 'wp-scopio' ); ?></p>
			<p><?php esc_html_e( 'If WordPress sits behind a reverse proxy (NGINX, Caddy, YARP, HAProxy, Traefik, …), REMOTE_ADDR will be the proxy IP, not the real visitor IP. Enable Trusted Proxy Mode above and list your proxy CIDRs to allow Scopio to read forwarding headers safely.', 'wp-scopio' ); ?></p>

			<h2><?php esc_html_e( 'Reverse Proxy Caveats', 'wp-scopio' ); ?></h2>
			<ul style="list-style:disc;padding-left:1.5em;">
				<li><?php esc_html_e( 'Only enable trusted proxy mode if you fully control the proxy configuration and trust that it strips or overwrites forwarding headers from untrusted clients.', 'wp-scopio' ); ?></li>
				<li><?php esc_html_e( 'CDNs (Cloudflare, Fastly, etc.) may use IP ranges outside your control. If you use a CDN in front of WordPress, add its published CIDR ranges to the trusted proxy list and verify the headers it forwards.', 'wp-scopio' ); ?></li>
				<li><?php esc_html_e( 'When in doubt, use REMOTE_ADDR mode (trusted proxy mode disabled). Audience restrictions will then apply to the proxy/CDN egress IP, which may not be what you want — but it is always safe.', 'wp-scopio' ); ?></li>
			</ul>

			<h2><?php esc_html_e( 'Non-Goals (v1)', 'wp-scopio' ); ?></h2>
			<ul style="list-style:disc;padding-left:1.5em;">
				<li><?php esc_html_e( 'No denylist or blacklist mode.', 'wp-scopio' ); ?></li>
				<li><?php esc_html_e( 'No mixed allow + deny policy rules.', 'wp-scopio' ); ?></li>
				<li><?php esc_html_e( 'No country-based geolocation.', 'wp-scopio' ); ?></li>
				<li><?php esc_html_e( 'No role-based or per-user access control.', 'wp-scopio' ); ?></li>
				<li><?php esc_html_e( 'No WAF or firewall functionality.', 'wp-scopio' ); ?></li>
			</ul>

			<h2><?php esc_html_e( 'Public API for Sibling Plugins', 'wp-scopio' ); ?></h2>
			<ul style="list-style:disc;padding-left:1.5em;">
				<li><code>scopio_get_client_ip()</code></li>
				<li><code>scopio_get_matching_group_slugs( ?string $ip )</code></li>
				<li><code>scopio_can_view_post( int $post_id, ?string $ip )</code></li>
				<li><code>scopio_filter_visible_post_ids( array $post_ids, ?string $ip )</code></li>
			</ul>
			<p><?php esc_html_e( 'Sibling plugins should use these helpers instead of re-implementing CIDR logic.', 'wp-scopio' ); ?></p>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Return current option values with defaults.
	 *
	 * @return array<string, mixed>
	 */
	private function get_options(): array {
		$defaults = [
			'enable_trusted_proxy_mode' => false,
			'trusted_proxy_cidrs'       => [],
			'trusted_ip_headers'        => [ 'Forwarded', 'X-Forwarded-For', 'X-Real-IP' ],
		];
		$stored = get_option( self::OPTION_KEY, [] );
		return array_merge( $defaults, is_array( $stored ) ? $stored : [] );
	}
}
