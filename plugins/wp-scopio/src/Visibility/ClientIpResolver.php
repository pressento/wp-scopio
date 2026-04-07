<?php
/**
 * Trusted-proxy-aware client IP resolver.
 *
 * @package Pressento\Scopio
 */

declare( strict_types=1 );

namespace Pressento\Scopio\Visibility;

use Pressento\Scopio\Admin\SettingsPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ClientIpResolver — resolves the real client IP address.
 *
 * Default behavior: use REMOTE_ADDR only.
 *
 * When trusted proxy mode is enabled and the immediate request comes from a
 * trusted proxy CIDR, the resolver inspects candidate forwarding headers in
 * priority order and extracts the original client IP conservatively.
 *
 * Supported filters:
 *   - scopio/trusted_proxy_mode   (bool)
 *   - scopio/trusted_proxy_cidrs  (string[])
 *   - scopio/trusted_ip_headers   (string[])
 *   - scopio/client_ip            (string, after resolution)
 */
class ClientIpResolver {

	/** @var CidrMatcher */
	private CidrMatcher $cidr;

	/**
	 * @param CidrMatcher|null $cidr_matcher Optional; created internally if omitted.
	 */
	public function __construct( ?CidrMatcher $cidr_matcher = null ) {
		$this->cidr = $cidr_matcher ?? new CidrMatcher();
	}

	/**
	 * Resolve and return the best available client IP.
	 *
	 * @return string IP address string.
	 */
	public function resolve(): string {
		$remote_addr = $this->get_remote_addr();
		$resolved    = $this->do_resolve( $remote_addr );

		/**
		 * Filter the final resolved client IP.
		 *
		 * Sibling plugins can override the IP at this point.
		 *
		 * @param string $resolved    Resolved IP.
		 * @param string $remote_addr Raw REMOTE_ADDR.
		 */
		return (string) apply_filters( 'scopio/client_ip', $resolved, $remote_addr );
	}

	// -------------------------------------------------------------------------
	// Internal resolution logic
	// -------------------------------------------------------------------------

	/**
	 * Core resolution — no filter applied here so it is testable.
	 */
	private function do_resolve( string $remote_addr ): string {
		if ( ! $this->is_trusted_proxy_mode_enabled() ) {
			return $remote_addr;
		}

		$trusted_cidrs = $this->get_trusted_proxy_cidrs();
		if ( empty( $trusted_cidrs ) ) {
			return $remote_addr;
		}

		if ( ! $this->cidr->matches_any( $remote_addr, $trusted_cidrs ) ) {
			// Request is NOT from a trusted proxy — use REMOTE_ADDR as-is.
			return $remote_addr;
		}

		// Request IS from a trusted proxy — inspect forwarding headers.
		$headers = $this->get_trusted_ip_headers();
		foreach ( $headers as $header ) {
			$ip = $this->extract_ip_from_header( $header, $trusted_cidrs );
			if ( null !== $ip ) {
				return $ip;
			}
		}

		return $remote_addr;
	}

	/**
	 * Extract the original client IP from a given forwarding header.
	 *
	 * Returns null when the header is missing, empty, or yields no usable IP.
	 *
	 * @param string   $header_name   HTTP header name.
	 * @param string[] $trusted_cidrs Trusted proxy CIDRs (used to strip proxy hops).
	 * @return string|null
	 */
	private function extract_ip_from_header( string $header_name, array $trusted_cidrs ): ?string {
		$value = $this->get_server_header( $header_name );
		if ( null === $value || '' === $value ) {
			return null;
		}

		$normalized = strtolower( $header_name );

		if ( 'forwarded' === $normalized ) {
			return $this->parse_forwarded_header( $value, $trusted_cidrs );
		}

		// X-Forwarded-For, X-Real-IP, and similar comma/space-delimited lists.
		return $this->parse_forwarded_for_value( $value, $trusted_cidrs );
	}

	/**
	 * Parse the RFC 7239 Forwarded header.
	 *
	 * Format: Forwarded: for=192.0.2.60;proto=http, for=198.51.100.17
	 *
	 * @param string   $value         Header value.
	 * @param string[] $trusted_cidrs Trusted proxy CIDRs.
	 * @return string|null
	 */
	private function parse_forwarded_header( string $value, array $trusted_cidrs ): ?string {
		// Split into individual directives.
		$parts = preg_split( '/,/', $value ) ?: [];
		$ips   = [];

		foreach ( $parts as $part ) {
			// Extract for= parameter.
			if ( preg_match( '/\bfor\s*=\s*"?([^";,\s]+)"?/i', $part, $m ) ) {
				$ip = $this->normalize_ip( $m[1] );
				if ( null !== $ip ) {
					$ips[] = $ip;
				}
			}
		}

		return $this->leftmost_non_proxy_ip( $ips, $trusted_cidrs );
	}

	/**
	 * Parse an X-Forwarded-For style value (comma-separated IP list).
	 *
	 * @param string   $value         Header value.
	 * @param string[] $trusted_cidrs Trusted proxy CIDRs.
	 * @return string|null
	 */
	private function parse_forwarded_for_value( string $value, array $trusted_cidrs ): ?string {
		$parts = preg_split( '/[,\s]+/', $value ) ?: [];
		$ips   = [];

		foreach ( $parts as $part ) {
			$ip = $this->normalize_ip( trim( $part ) );
			if ( null !== $ip ) {
				$ips[] = $ip;
			}
		}

		return $this->leftmost_non_proxy_ip( $ips, $trusted_cidrs );
	}

	/**
	 * Given an ordered list of IPs (leftmost = original client), return the
	 * leftmost IP that is NOT a trusted proxy.
	 *
	 * If all IPs are trusted proxies (unusual but possible in layered setups)
	 * return the leftmost IP.
	 *
	 * @param string[] $ips           Ordered IP list.
	 * @param string[] $trusted_cidrs Trusted proxy CIDRs.
	 * @return string|null Null if list is empty.
	 */
	private function leftmost_non_proxy_ip( array $ips, array $trusted_cidrs ): ?string {
		if ( empty( $ips ) ) {
			return null;
		}

		foreach ( $ips as $ip ) {
			if ( ! $this->cidr->matches_any( $ip, $trusted_cidrs ) ) {
				return $ip;
			}
		}

		// All were trusted proxies — return leftmost anyway.
		return $ips[0];
	}

	/**
	 * Normalize an IP string from a forwarding header.
	 *
	 * Strips IPv6 brackets, port numbers, and validates the result.
	 *
	 * @param string $raw Raw token from a forwarding header.
	 * @return string|null Normalized IP or null if invalid.
	 */
	private function normalize_ip( string $raw ): ?string {
		$raw = trim( $raw );

		// Strip IPv6 brackets: [::1] → ::1, [::1]:port → ::1
		if ( str_starts_with( $raw, '[' ) ) {
			$raw = preg_replace( '/^\[([^\]]+)\](:\d+)?$/', '$1', $raw ) ?? $raw;
		} elseif ( strpos( $raw, ':' ) !== false && substr_count( $raw, ':' ) === 1 ) {
			// IPv4:port — strip the port.
			[ $raw ] = explode( ':', $raw, 2 );
		}

		$raw = trim( $raw );

		if ( filter_var( $raw, FILTER_VALIDATE_IP ) !== false ) {
			return $raw;
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Config helpers
	// -------------------------------------------------------------------------

	private function is_trusted_proxy_mode_enabled(): bool {
		$opts    = get_option( SettingsPage::OPTION_KEY, [] );
		$enabled = ! empty( $opts['enable_trusted_proxy_mode'] );

		/**
		 * Filter whether trusted proxy mode is active.
		 *
		 * @param bool $enabled
		 */
		return (bool) apply_filters( 'scopio/trusted_proxy_mode', $enabled );
	}

	/**
	 * @return string[]
	 */
	private function get_trusted_proxy_cidrs(): array {
		$opts  = get_option( SettingsPage::OPTION_KEY, [] );
		$cidrs = isset( $opts['trusted_proxy_cidrs'] ) && is_array( $opts['trusted_proxy_cidrs'] )
			? $opts['trusted_proxy_cidrs']
			: [];

		/**
		 * Filter the list of trusted proxy CIDRs.
		 *
		 * @param string[] $cidrs
		 */
		return (array) apply_filters( 'scopio/trusted_proxy_cidrs', $cidrs );
	}

	/**
	 * @return string[]
	 */
	private function get_trusted_ip_headers(): array {
		$opts    = get_option( SettingsPage::OPTION_KEY, [] );
		$headers = isset( $opts['trusted_ip_headers'] ) && is_array( $opts['trusted_ip_headers'] )
			? $opts['trusted_ip_headers']
			: [ 'Forwarded', 'X-Forwarded-For', 'X-Real-IP' ];

		/**
		 * Filter the candidate forwarding header names in priority order.
		 *
		 * @param string[] $headers
		 */
		return (array) apply_filters( 'scopio/trusted_ip_headers', $headers );
	}

	/**
	 * Return the REMOTE_ADDR value.
	 */
	private function get_remote_addr(): string {
		$addr = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
		// Validate to guard against unexpected server configurations.
		return filter_var( $addr, FILTER_VALIDATE_IP ) !== false ? $addr : '127.0.0.1';
	}

	/**
	 * Return a SERVER header value by canonical header name.
	 *
	 * Converts "X-Forwarded-For" → HTTP_X_FORWARDED_FOR, etc.
	 *
	 * @param string $header_name HTTP header name (mixed case, hyphenated).
	 * @return string|null
	 */
	private function get_server_header( string $header_name ): ?string {
		$normalized = strtolower( $header_name );

		if ( 'forwarded' === $normalized ) {
			$key = 'HTTP_FORWARDED';
		} else {
			$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $header_name ) );
		}

		if ( isset( $_SERVER[ $key ] ) ) {
			return (string) $_SERVER[ $key ];
		}
		return null;
	}
}
