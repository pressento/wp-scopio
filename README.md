# Scopio for WordPress

**CIDR group-based content visibility for WordPress.**

Scopio lets you restrict posts and pages to specific IP address ranges using an allowlist model built on standard CIDR notation. No geolocation databases, no role-based rules — just IP ranges and taxonomy terms.

---

## Table of Contents

- [What Scopio Is](#what-scopio-is)
- [Why It Exists](#why-it-exists)
- [Visibility Model](#visibility-model)
- [How Scopio Groups Work](#how-scopio-groups-work)
- [CIDR Configuration](#cidr-configuration)
- [Example Use Cases](#example-use-cases)
- [Client IP Resolution](#client-ip-resolution)
- [Trusted Reverse Proxy Support](#trusted-reverse-proxy-support)
  - [NGINX Example](#nginx-example)
  - [Caddy Example](#caddy-example)
  - [YARP Example](#yarp-example)
- [Reverse Proxy Caveats](#reverse-proxy-caveats)
- [Supported Post Types](#supported-post-types)
- [Non-Goals (v1)](#non-goals-v1)
- [Extension Points and Public API](#extension-points-and-public-api)
- [WP Lingua Integration](#wp-lingua-integration)
- [Why v1 Avoids Mixed Allow/Deny Semantics](#why-v1-avoids-mixed-allowdeny-semantics)
- [Development Setup](#development-setup)
- [Running Tests](#running-tests)

---

## What Scopio Is

Scopio is a WordPress plugin that restricts access to individual posts and pages based on the visitor's IP address. You define **Scopio Groups** — each group is a named collection of one or more IPv4 or IPv6 CIDR ranges. You then assign those groups to posts via a sidebar metabox.

A visitor can see a restricted post only if their IP falls within at least one CIDR range of at least one group assigned to that post. Unauthorized visitors receive a 404 response, not a 403 — so the existence of the content is not disclosed.

The restriction is enforced everywhere WordPress serves content: singular pages, listing queries, REST API responses, XML sitemaps, feeds, and Query Loop blocks.

---

## Why It Exists

Internal intranets, staging environments, partner portals, and employee-only documentation all need content that should not be publicly accessible. WordPress's built-in visibility controls are either binary (public/private/password) or role-based (requires login). Neither is ideal when the access criterion is network location.

Scopio fills that gap by enforcing visibility at the IP layer, which is:

- **Transparent to the visitor** — restricted content is invisible rather than producing a login prompt.
- **Spoofing-resistant** — when used without trusted proxy mode, decisions are made using the raw TCP connection IP (`REMOTE_ADDR`), which cannot be forged by the client.
- **Zero-friction for authorized visitors** — no login, no password prompt; access is automatic.

---

## Visibility Model

Scopio uses a strict **allowlist** model:

| Post state | Visitor IP | Outcome |
|---|---|---|
| No groups assigned | Any | Public — always visible |
| Groups assigned | Matches ≥1 assigned group | Visible |
| Groups assigned | Matches no group | Hidden (404) |
| Groups assigned, all groups empty (no CIDRs) | Any | Hidden (404) |

Key consequences:

- **Admins and editors** who have `edit_post` capability for a given post are never blocked by Scopio, even on the front end.
- **REST API** collection endpoints and singular endpoints apply the same policy. Privileged REST users (those who can `edit_posts`) bypass the filter.
- **Sitemaps, feeds, archives, and search** results all exclude restricted posts for unauthorized visitors.

---

## How Scopio Groups Work

Scopio Groups are a private WordPress taxonomy (`scopio_group`) registered by the plugin. Each term in the taxonomy represents a named group.

To manage groups:

1. Go to **Posts → Scopio Groups** (or **Pages → Scopio Groups**) in the wp-admin sidebar.
2. Click **Add New Scopio Group** and give it a name (e.g. "Corporate Intranet").
3. In the **CIDR Ranges** textarea, enter one CIDR range per line.
4. Click **Add New Scopio Group**.

To assign groups to a post:

1. Open the post or page in the editor.
2. In the **Scopio Visibility** sidebar panel (block editor) or metabox (classic editor), tick one or more groups.
3. Save or publish the post.

A post with no groups checked remains public. A post with one or more groups checked is restricted to visitors whose IP matches any of those groups.

---

## CIDR Configuration

Each Scopio Group stores a list of CIDR ranges as term meta. The textarea accepts one entry per line. Both IPv4 and IPv6 notation are supported:

```
# IPv4 examples
192.168.1.0/24       # entire /24 subnet
10.0.0.0/8           # Class A private range
203.0.113.42/32      # single host (/32 is assumed for bare IPs)
0.0.0.0/0            # all IPv4 addresses (use with caution)

# IPv6 examples
2001:db8::/32
fe80::/10            # link-local
::1/128              # loopback
```

Malformed entries are silently ignored — they never cause errors; they simply never match.

---

## Example Use Cases

### 1. Employee-only news section

Create a group `employees` with your corporate office IP ranges:

```
203.0.113.0/24
198.51.100.0/24
```

Assign that group to every post in the "Company News" category. Employees at a registered office IP see the posts; visitors outside those ranges do not.

### 2. Staging environment previews

Create a group `qa-team` containing your QA team's VPN exit IP:

```
192.0.2.10/32
```

Assign all staging preview posts to `qa-team`. Only QA team members can read them.

### 3. Regional partner portal

Create groups for each region (`region-eu`, `region-apac`) with each partner's known IP ranges. Assign posts to the appropriate regional groups. Partners see only their own content.

### 4. Mixed public and restricted content

Leave most posts unassigned (public). Assign only your internal documentation posts to a group. Regular visitors see the public site; internal users see everything.

---

## Client IP Resolution

Scopio always starts from `REMOTE_ADDR` — the IP address of the actual TCP connection to the web server. This is the most reliable source: it cannot be forged by the client.

**If you have no reverse proxy in front of WordPress**, this is all you need and you should leave trusted proxy mode disabled.

**If WordPress sits behind a reverse proxy** (NGINX, Caddy, YARP, HAProxy, Traefik, a CDN, etc.), `REMOTE_ADDR` will be the proxy's IP, not the visitor's real IP. In this case you need to enable **Trusted Proxy Mode** and configure your proxy's CIDR ranges.

---

## Trusted Reverse Proxy Support

Go to **Settings → Scopio → Trusted Proxy** to configure:

| Setting | Description |
|---|---|
| Enable Trusted Proxy Mode | Master switch. Off = always use `REMOTE_ADDR`. |
| Trusted Proxy CIDRs | One IP or CIDR per line. Only requests from these addresses will have their forwarding headers trusted. |
| Trusted IP Headers | Forwarding header names in priority order. Default: `Forwarded`, `X-Forwarded-For`, `X-Real-IP`. |

When trusted proxy mode is active and the request's `REMOTE_ADDR` falls within a trusted CIDR, Scopio reads the forwarding headers in order and extracts the **leftmost non-proxy IP** — the original client IP.

### NGINX Example

Configure NGINX to set `X-Forwarded-For` (it does this by default via `proxy_set_header`):

```nginx
server {
    listen 80;

    location / {
        proxy_pass         http://wordpress:80;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
    }
}
```

In Scopio settings:
- Enable trusted proxy mode
- Add your NGINX server IP or Docker network CIDR (e.g. `172.17.0.0/16`)
- Set header priority: `X-Forwarded-For`

### Caddy Example

Caddy automatically sets `X-Forwarded-For` when acting as a reverse proxy:

```caddyfile
example.com {
    reverse_proxy wordpress:80
}
```

In Scopio settings:
- Enable trusted proxy mode
- Add Caddy's egress IP or CIDR
- Header priority: `X-Forwarded-For`

### YARP Example

YARP (Yet Another Reverse Proxy, .NET) forwards the `X-Forwarded-For` header by default:

```json
{
  "ReverseProxy": {
    "Routes": {
      "wordpress-route": {
        "ClusterId": "wordpress-cluster",
        "Match": { "Path": "{**catch-all}" },
        "Transforms": [
          { "X-Forwarded": "Set" }
        ]
      }
    },
    "Clusters": {
      "wordpress-cluster": {
        "Destinations": {
          "wp": { "Address": "http://wordpress:80/" }
        }
      }
    }
  }
}
```

In Scopio settings:
- Enable trusted proxy mode
- Add YARP server IP or CIDR
- Header priority: `Forwarded` (YARP sets the RFC 7239 `Forwarded` header when `X-Forwarded: Set` is configured)

---

## Reverse Proxy Caveats

- **Only enable trusted proxy mode if you fully control the proxy** and you are certain that it strips or overwrites forwarding headers supplied by untrusted clients. A misconfigured proxy lets clients forge their IP.
- **CDNs (Cloudflare, Fastly, Akamai, etc.)** present their egress IPs as `REMOTE_ADDR`. To use trusted proxy mode with a CDN, you must add all CDN egress CIDRs to the trusted list. CDN egress ranges change over time; keep them up to date or use the CDN's own IP header verification mechanism instead.
- **Layered proxies** (e.g. CDN → WAF → load balancer → WordPress) may produce multi-hop `X-Forwarded-For` headers. Scopio extracts the leftmost non-proxy IP, so you must include the CIDRs of all intermediate layers in the trusted list for the extraction to work correctly.
- **When in doubt, leave trusted proxy mode disabled.** Scopio will then use `REMOTE_ADDR`, which will be your proxy's IP. Audience restrictions will apply to the proxy egress IP rather than the real visitor — not what you want for audience filtering, but it is always safe and cannot be spoofed.

---

## Supported Post Types

By default Scopio applies to `post` and `page`. To extend it to custom post types, use the `scopio/supported_post_types` filter:

```php
add_filter( 'scopio/supported_post_types', function ( array $post_types ): array {
    $post_types[] = 'product';
    $post_types[] = 'my_cpt';
    return $post_types;
} );
```

This filter controls:
- Which post types have the Scopio Visibility metabox.
- Which post types are covered by `scopio_group` taxonomy registration.
- Which REST API post-type endpoints are filtered.
- Which sitemap post-type queries are filtered.

---

## Non-Goals (v1)

The following are explicitly out of scope for v1:

- **No denylist / blacklist mode.** There is no mechanism to block a specific IP while allowing all others. The model is allowlist-only.
- **No mixed allow + deny policy rules.** You cannot say "allow 10.0.0.0/8 except 10.1.2.3". Rules are additive: a post is visible if the IP matches any assigned group.
- **No country-based geolocation.** Scopio does not query GeoIP databases. CIDR ranges must be provided manually.
- **No role-based or per-user access control.** There is no intersection of "logged-in user role" and "IP range". User identity is not considered (except that editors/admins bypass restrictions on posts they can edit).
- **No WAF or firewall functionality.** Scopio does not block requests at the server level; it controls what content WordPress renders in the response.

These non-goals exist to keep the plugin small, auditable, and correct. Each of these capabilities could be added by a separate plugin that hooks into Scopio's public API.

---

## Extension Points and Public API

Scopio exposes a public PHP API that sibling or third-party plugins can use:

```php
// Get the resolved client IP (respects trusted proxy mode).
$ip = scopio_get_client_ip();

// Get slugs of all Scopio groups whose CIDRs match an IP.
$slugs = scopio_get_matching_group_slugs( '10.0.0.5' );
$slugs = scopio_get_matching_group_slugs(); // uses current visitor IP

// Check whether a post is visible to a given IP.
$visible = scopio_can_view_post( $post_id, '10.0.0.5' );
$visible = scopio_can_view_post( $post_id ); // uses current visitor IP

// Filter an array of post IDs to only visible ones.
$ids = scopio_filter_visible_post_ids( [ 1, 2, 3 ], '10.0.0.5' );
$ids = scopio_filter_visible_post_ids( [ 1, 2, 3 ] ); // uses current visitor IP
```

### WordPress Filters

| Filter | Description |
|---|---|
| `scopio/supported_post_types` | `string[]` — post types covered by Scopio. |
| `scopio/client_ip` | `string` — override the final resolved client IP. |
| `scopio/trusted_proxy_mode` | `bool` — override whether trusted proxy mode is active. |
| `scopio/trusted_proxy_cidrs` | `string[]` — override trusted proxy CIDR list. |
| `scopio/trusted_ip_headers` | `string[]` — override forwarding header priority list. |
| `scopio/matching_group_slugs` | `string[]` — override or augment matching group slugs for an IP. |
| `scopio/can_view_post` | `bool` — override the visibility decision for a single post. |
| `scopio/filter_visible_posts` | `int[]` — override the result of a batch visibility filter. |

### WordPress Actions

| Action | Description |
|---|---|
| `scopio/wp_lingua_bridge_ready` | Fired when the WP Lingua bridge initializes. Receives the `VisibilityService` instance. |

---

## WP Lingua Integration

When the sibling **WP Lingua** plugin is active, Scopio automatically activates a compatibility bridge. The bridge:

1. Fires `scopio/wp_lingua_bridge_ready` with the `VisibilityService` so WP Lingua can subscribe.
2. Registers the `scopio/wp_lingua_post_is_visible` filter, which WP Lingua can call to check whether a post should appear in translation switchers or hreflang maps.

Example (from WP Lingua's perspective):

```php
// Check before generating alternate hreflang links:
$is_visible = apply_filters( 'scopio/wp_lingua_post_is_visible', true, $translation_post_id );
if ( ! $is_visible ) {
    // Omit this translation from the hreflang output.
}
```

The bridge is non-invasive: it activates only when WP Lingua is detected, and it creates no hard dependency on WP Lingua internals.

**Why this matters:** Without the bridge, a multilingual plugin might include alternate language links to posts that Scopio hides. Visitors following those links would receive a 404. The bridge prevents that by letting WP Lingua ask Scopio whether each translation is visible before rendering links.

---

## Why v1 Avoids Mixed Allow/Deny Semantics

It might seem useful to say "allow 10.0.0.0/8 but deny 10.1.2.3". However, combining allow and deny rules in a single policy creates significant complexity:

- **Rule evaluation order matters.** "Allow A, deny B" behaves differently from "deny B, allow A" when A and B overlap.
- **Default action ambiguity.** When no rule matches, is the default allow or deny?
- **Unintended exposure.** A misconfigured deny rule in an otherwise restrictive policy can accidentally expose content.

Scopio v1 avoids this by supporting only the allowlist model: a post is either public (no groups) or restricted (groups assigned). A restricted post is visible only to IPs that match at least one group. This is easy to reason about, easy to audit, and hard to misconfigure. Mixed allow/deny semantics can be added in a future version or by a separate policy plugin that hooks into `scopio/can_view_post`.

---

## Development Setup

This repository uses Docker Compose for local development. No local PHP, Composer, or WP-CLI installation is required.

```bash
# Clone the repository
git clone https://github.com/pressento/wp-scopio
cd wp-scopio

# Optional: override environment defaults
cp .env .env.local

# Start the full stack (WordPress + MariaDB + phpMyAdmin + Mailpit)
docker compose up -d
```

| Service | URL |
|---|---|
| WordPress | http://localhost:8080 |
| phpMyAdmin | http://localhost:8081 |
| Mailpit (email) | http://localhost:8025 |

Default credentials: `admin` / `admin`.

---

## Running Tests

```bash
# Build the test image and run PHPUnit
docker compose -f docker-compose.test.yml up --build --abort-on-container-exit

# Tear down test containers and volumes
docker compose -f docker-compose.test.yml down -v
```

Tests live in `tests/test-scopio.php` and extend `WP_UnitTestCase`. The suite covers:

- Plugin bootstrap (constants defined)
- Taxonomy registration and configuration
- `CidrMatcher` — IPv4 and IPv6 matching, edge cases, invalid inputs
- `VisibilityService` — public posts, restricted posts, empty CIDR groups, batch filtering, group slug matching

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).

