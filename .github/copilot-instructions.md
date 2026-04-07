# GitHub Copilot Instructions

## Project Overview

This is a **template repository** for WordPress plugin development within the Pressento plugin series.
It provides a complete, Docker-based local development and CI/CD scaffold that requires no local PHP, Composer, or WP-CLI installation.

Key capabilities baked into the template:
- Docker Compose local stack (WordPress + MariaDB + phpMyAdmin + Mailpit)
- PHPUnit integration-test infrastructure (runs fully inside Docker)
- GitHub Actions workflows for automated testing and versioned plugin releases

---

## Placeholder Naming Convention

The template uses `plugin-name` as a generic placeholder for the plugin slug.
When assisting with code in a repository derived from this template, **infer the actual plugin slug** from:
1. The directory name under `plugins/` (e.g. `plugins/my-awesome-plugin/`)
2. The main PHP file name (e.g. `plugins/my-awesome-plugin/my-awesome-plugin.php`)
3. The `Text Domain` header inside the main PHP file

Always substitute the real slug for `plugin-name` in any code, path, or configuration you generate.
The corresponding PHP constant prefix follows the pattern `PLUGIN_NAME_` → `MY_AWESOME_PLUGIN_`.

---

## Repository Structure

```
.
├── .env                          # Default environment variables (copy to .env.local to override)
├── .github/
│   ├── copilot-instructions.md   # This file
│   └── workflows/
│       ├── test.yml              # CI: PHPUnit on push/PR to main
│       └── release.yml           # CD: build zip + GitHub Release on v* tag
├── Dockerfile                    # WordPress dev image (debug PHP config, Mailpit SMTP)
├── Dockerfile.test               # PHP 8.2 CLI image with PHPUnit 9 + polyfills
├── docker-compose.yml            # Local dev stack
├── docker-compose.test.yml       # Test-only stack
├── wp-setup.sh                   # WP-CLI bootstrap (runs once on first `docker compose up`)
├── plugins/
│   └── plugin-name/              # Main plugin directory — rename to your slug
│       ├── plugin-name.php       # Plugin entry point
│       └── languages/            # .po / .mo translation files
├── tests/
│   ├── bootstrap.php             # PHPUnit bootstrap (loads WP core + plugin)
│   ├── phpunit.xml               # PHPUnit configuration
│   ├── test-plugin-name.php      # Sample test — rename/replace
│   └── bin/
│       ├── install-wp-tests.sh   # Downloads WP core + SVN test suite
│       └── run-tests.sh          # Docker entrypoint for phpunit service
├── themes/                       # Mount point for custom themes
└── uploads/                      # Mount point for media uploads (runtime only)
```

---

## PHP & WordPress Coding Conventions

- **PHP version**: 8.0 minimum (`Requires PHP: 8.0` in plugin header). Use typed properties, union types, named arguments, and `match` expressions freely.
- **WordPress coding style**: Follow the [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/). Key rules:
  - Tabs for indentation (not spaces).
  - Single space inside parentheses for control structures: `if ( $x )`, `foreach ( $items as $item )`.
  - Yoda conditions: `if ( 'value' === $var )`.
  - Function and variable names use `snake_case`; class names use `PascalCase`.
  - All functions and classes in a plugin must be namespaced or prefixed with the plugin slug to avoid collisions (e.g. `plugin_name_` prefix or `PluginName\` namespace).
- **No `?>` closing tag** at the end of PHP files.
- **Early exit guard** at the top of every PHP file: `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
- **`add_action` / `add_filter`**: Always pass `$priority` and `$accepted_args` explicitly when they differ from the defaults (`10`, `1`).
- **Sanitization & escaping**: Always sanitize input (`sanitize_text_field`, `absint`, `wp_kses_post`, etc.) and escape output (`esc_html`, `esc_url`, `esc_attr`, `wp_kses_post`).
- **Internationalization**: Wrap all user-facing strings with `__()`, `_e()`, `esc_html__()`, etc., using the plugin's text domain.
- **DocBlocks**: Add PHPDoc blocks for all functions and classes. Include `@param`, `@return`, and `@since` tags.

---

## Docker Compose Workflow

### Local development

```bash
# Start the full stack (WordPress + DB + phpMyAdmin + Mailpit)
docker compose up -d

# Tail WordPress logs
docker compose logs -f wordpress

# Run a WP-CLI command
docker compose run --rm wpcli plugin list

# Stop and remove containers (data is preserved in named volumes)
docker compose down
```

- WordPress: http://localhost:8080  
- phpMyAdmin: http://localhost:8081  
- Mailpit (email catcher): http://localhost:8025  

The `setup` service runs `wp-setup.sh` automatically on first boot and installs WordPress via WP-CLI.
Subsequent `docker compose up` calls skip setup because `wp core is-installed` returns true.

### Test environment

```bash
# Build test image and run PHPUnit (exits when tests finish)
docker compose -f docker-compose.test.yml up --build --abort-on-container-exit

# Tear down test containers and volumes
docker compose -f docker-compose.test.yml down -v
```

The `phpunit` service mounts `./plugins/plugin-name` to `/app/plugin-name` and `./tests` to `/app/tests`.
The entrypoint script (`tests/bin/run-tests.sh`) downloads the WP test suite on first run, then executes PHPUnit.

---

## Writing Tests

- Test files live in `tests/` and must be named `test-{feature}.php` to be discovered by `phpunit.xml`.
- All test classes extend `WP_UnitTestCase` (provided by the WordPress test suite).
- Use `WP_UnitTestCase::setUp()` / `tearDown()` for per-test setup; use `setUpBeforeClass()` for class-level setup.
- Prefer factory helpers for creating posts, users, terms, etc.:
  ```php
  $post_id = self::factory()->post->create( [ 'post_title' => 'Hello' ] );
  $user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
  ```
- For REST API tests, extend `WP_REST_TestCase` and call `rest_get_server()` to dispatch requests.
- Test method names must start with `test_` and describe the behaviour under test (e.g. `test_returns_404_for_unknown_post`).
- Keep each test focused on a single behaviour. Do not share mutable state between test methods.

### Example test skeleton

```php
<?php
class Test_My_Feature extends WP_UnitTestCase {

    public function test_something_specific(): void {
        // Arrange
        $post_id = self::factory()->post->create();

        // Act
        $result = my_plugin_do_something( $post_id );

        // Assert
        $this->assertSame( 'expected', $result );
    }
}
```

---

## CI/CD Workflows

### `test.yml` — Automated testing

Runs on every push and pull request targeting `main`.
Executes `docker compose -f docker-compose.test.yml up --build --abort-on-container-exit`.
The workflow exits non-zero if any PHPUnit test fails.

### `release.yml` — Plugin release

Triggered by pushing a tag matching `v*` (e.g. `git tag v1.2.3 && git push --tags`).

Steps:
1. Compiles all `.po` translation files to `.mo` using `msgfmt`.
2. Creates a zip archive of `plugins/plugin-name/` (excluding dot-files and `node_modules`).
3. Uploads the zip as a workflow artifact.
4. Creates a GitHub Release with auto-generated release notes and attaches the zip.

> When adding new languages, place `.po` files in `plugins/plugin-name/languages/` using the WordPress locale format (e.g. `plugin-name-ko_KR.po`).

---

## Environment Variables (`.env`)

| Variable | Default | Description |
|---|---|---|
| `MYSQL_ROOT_PASSWORD` | `rootpass` | MariaDB root password |
| `MYSQL_DATABASE` | `wordpress` | WordPress database name |
| `MYSQL_USER` / `MYSQL_PASSWORD` | `wpuser` / `wppass` | DB credentials |
| `WP_PORT` | `8080` | Host port for WordPress |
| `WP_URL` | `http://localhost:8080` | WordPress site URL |
| `WP_LOCALE` | `en_US` | WordPress locale |
| `WP_TIMEZONE` | `UTC` | WordPress timezone string |
| `WP_ACTIVATE_PLUGINS` | `plugin-name` | Comma-separated plugin slugs to activate on setup |
| `WP_LANGUAGE_PACKS` | *(empty)* | Comma-separated extra language packs to install |
| `PMA_PORT` | `8081` | Host port for phpMyAdmin |
| `MAILPIT_UI_PORT` | `8025` | Host port for Mailpit web UI |
| `MAILPIT_SMTP_PORT` | `1025` | Host port for Mailpit SMTP |

Copy `.env` to `.env.local` to override values without modifying the tracked file.

---

## Common Patterns

### Registering hooks from a class

```php
class Plugin_Name {
    public function __construct() {
        add_action( 'init', [ $this, 'init' ] );
        add_filter( 'the_content', [ $this, 'filter_content' ] );
    }

    public function init(): void { /* ... */ }

    public function filter_content( string $content ): string {
        return $content;
    }
}

new Plugin_Name();
```

### Registering a custom REST endpoint

```php
add_action( 'rest_api_init', function (): void {
    register_rest_route( 'plugin-name/v1', '/items/(?P<id>\d+)', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'plugin_name_get_item',
        'permission_callback' => '__return_true',
        'args'                => [
            'id' => [
                'validate_callback' => fn( $v ) => is_numeric( $v ),
                'sanitize_callback' => 'absint',
            ],
        ],
    ] );
} );
```

### Enqueuing assets

```php
add_action( 'wp_enqueue_scripts', function (): void {
    wp_enqueue_style(
        'plugin-name-style',
        PLUGIN_NAME_URL . 'assets/css/plugin-name.css',
        [],
        PLUGIN_NAME_VERSION
    );
    wp_enqueue_script(
        'plugin-name-script',
        PLUGIN_NAME_URL . 'assets/js/plugin-name.js',
        [ 'jquery' ],
        PLUGIN_NAME_VERSION,
        true
    );
} );
```
