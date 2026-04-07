<?php
/**
 * Main plugin class — wires all components together.
 *
 * @package Pressento\Scopio
 */

declare( strict_types=1 );

namespace Pressento\Scopio;

use Pressento\Scopio\Admin\GroupTermMeta;
use Pressento\Scopio\Admin\PostVisibilityMetaBox;
use Pressento\Scopio\Admin\SettingsPage;
use Pressento\Scopio\Frontend\QueryFilter;
use Pressento\Scopio\Frontend\RestVisibility;
use Pressento\Scopio\Frontend\SingularGuard;
use Pressento\Scopio\Frontend\SitemapVisibility;
use Pressento\Scopio\Integration\WpLinguaBridge;
use Pressento\Scopio\Taxonomy\GroupTaxonomy;
use Pressento\Scopio\Visibility\CidrMatcher;
use Pressento\Scopio\Visibility\ClientIpResolver;
use Pressento\Scopio\Visibility\GroupRepository;
use Pressento\Scopio\Visibility\VisibilityService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin singleton — created once, boots all subsystems.
 */
final class Plugin {

	/** @var Plugin|null Singleton instance. */
	private static ?Plugin $instance = null;

	/** @var VisibilityService */
	private VisibilityService $visibility_service;

	/**
	 * Private constructor — use Plugin::get_instance().
	 */
	private function __construct() {}

	/**
	 * Return the singleton instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot all plugin subsystems.
	 *
	 * Called once on the `plugins_loaded` action.
	 */
	public function boot(): void {
		$this->load_textdomain();
		$this->build_services();
		$this->register_hooks();
	}

	/**
	 * Return the shared VisibilityService instance.
	 */
	public function get_visibility_service(): VisibilityService {
		return $this->visibility_service;
	}

	// -------------------------------------------------------------------------
	// Internal setup
	// -------------------------------------------------------------------------

	private function load_textdomain(): void {
		load_plugin_textdomain(
			'wp-scopio',
			false,
			dirname( plugin_basename( SCOPIO_DIR . 'wp-scopio.php' ) ) . '/languages'
		);
	}

	private function build_services(): void {
		$cidr_matcher  = new CidrMatcher();
		$ip_resolver   = new ClientIpResolver();
		$group_repo    = new GroupRepository( $cidr_matcher );

		$this->visibility_service = new VisibilityService(
			$ip_resolver,
			$group_repo,
			$cidr_matcher
		);
	}

	private function register_hooks(): void {
		// Taxonomy.
		( new GroupTaxonomy() )->register();

		// Admin.
		if ( is_admin() ) {
			( new GroupTermMeta() )->register();
			( new PostVisibilityMetaBox( $this->visibility_service ) )->register();
			( new SettingsPage() )->register();
		}

		// Front-end enforcement.
		( new SingularGuard( $this->visibility_service ) )->register();
		( new QueryFilter( $this->visibility_service ) )->register();
		( new RestVisibility( $this->visibility_service ) )->register();
		( new SitemapVisibility( $this->visibility_service ) )->register();

		// Optional integrations.
		WpLinguaBridge::maybe_register( $this->visibility_service );
	}
}
