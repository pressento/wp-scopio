<?php
/**
 * CIDR matching service for IPv4 and IPv6 addresses.
 *
 * @package Pressento\Scopio
 */

declare( strict_types=1 );

namespace Pressento\Scopio\Visibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CidrMatcher — checks whether a given IP address falls within a CIDR range.
 *
 * Supports both IPv4 (e.g. 192.168.1.0/24) and IPv6 (e.g. 2001:db8::/32).
 * Malformed CIDRs or IPs are handled gracefully — they never throw.
 */
class CidrMatcher {

	/**
	 * Return true if $ip falls within $cidr.
	 *
	 * Accepts:
	 *   - Standard CIDR notation: 192.168.1.0/24, 10.0.0.1/32, 2001:db8::/32
	 *   - Bare IPs (treated as /32 for IPv4, /128 for IPv6)
	 *
	 * @param string $ip   The IP address to test.
	 * @param string $cidr The CIDR range (or bare IP) to test against.
	 * @return bool True if $ip is within $cidr, false otherwise.
	 */
	public function matches( string $ip, string $cidr ): bool {
		$ip   = trim( $ip );
		$cidr = trim( $cidr );

		if ( '' === $ip || '' === $cidr ) {
			return false;
		}

		// Normalise: if no prefix length, treat as a single host.
		if ( strpos( $cidr, '/' ) === false ) {
			// Bare IP — must match exactly.
			$cidr .= $this->is_ipv6( $cidr ) ? '/128' : '/32';
		}

		[ $range, $prefix ] = explode( '/', $cidr, 2 );

		if ( ! is_numeric( $prefix ) ) {
			return false;
		}
		$prefix = (int) $prefix;

		// Detect address family — must match.
		$ip_is_v6    = $this->is_ipv6( $ip );
		$range_is_v6 = $this->is_ipv6( $range );

		if ( $ip_is_v6 !== $range_is_v6 ) {
			return false;
		}

		return $ip_is_v6
			? $this->ipv6_in_cidr( $ip, $range, $prefix )
			: $this->ipv4_in_cidr( $ip, $range, $prefix );
	}

	/**
	 * Return true if any CIDR in $cidrs matches $ip.
	 *
	 * @param string   $ip    The IP address to test.
	 * @param string[] $cidrs Array of CIDR strings.
	 * @return bool
	 */
	public function matches_any( string $ip, array $cidrs ): bool {
		foreach ( $cidrs as $cidr ) {
			if ( $this->matches( $ip, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Return true if the string looks like an IPv6 address.
	 */
	private function is_ipv6( string $addr ): bool {
		return strpos( $addr, ':' ) !== false;
	}

	/**
	 * IPv4 CIDR check using bitwise integer arithmetic.
	 */
	private function ipv4_in_cidr( string $ip, string $range, int $prefix ): bool {
		if ( $prefix < 0 || $prefix > 32 ) {
			return false;
		}
		$ip_long    = ip2long( $ip );
		$range_long = ip2long( $range );

		if ( false === $ip_long || false === $range_long ) {
			return false;
		}

		if ( 0 === $prefix ) {
			return true; // 0.0.0.0/0 matches everything.
		}

		$mask = (int) ( -1 << ( 32 - $prefix ) );
		return ( $ip_long & $mask ) === ( $range_long & $mask );
	}

	/**
	 * IPv6 CIDR check using inet_pton binary representation.
	 */
	private function ipv6_in_cidr( string $ip, string $range, int $prefix ): bool {
		if ( $prefix < 0 || $prefix > 128 ) {
			return false;
		}

		$ip_bin    = @inet_pton( $ip );
		$range_bin = @inet_pton( $range );

		if ( false === $ip_bin || false === $range_bin ) {
			return false;
		}

		// Convert binary strings to arrays of 8-bit integers.
		$ip_bytes    = array_values( unpack( 'C16', $ip_bin ) ?: [] );
		$range_bytes = array_values( unpack( 'C16', $range_bin ) ?: [] );

		if ( count( $ip_bytes ) !== 16 || count( $range_bytes ) !== 16 ) {
			return false;
		}

		$full_bytes  = (int) ( $prefix / 8 );
		$remainder   = $prefix % 8;

		// Compare full bytes.
		for ( $i = 0; $i < $full_bytes; $i++ ) {
			if ( $ip_bytes[ $i ] !== $range_bytes[ $i ] ) {
				return false;
			}
		}

		// Compare the partial byte, if any.
		if ( $remainder > 0 && $full_bytes < 16 ) {
			$mask = 0xFF & ( 0xFF << ( 8 - $remainder ) );
			if ( ( $ip_bytes[ $full_bytes ] & $mask ) !== ( $range_bytes[ $full_bytes ] & $mask ) ) {
				return false;
			}
		}

		return true;
	}
}
