# wp-pressento-template

Pressento WordPress Plugin Series Template

A ready-to-use template repository for WordPress plugin development, featuring:

- 🐳 **Docker Compose** local development environment (WordPress + MariaDB + phpMyAdmin + Mailpit)
- 🧪 **PHPUnit** test infrastructure using Docker Compose (no local PHP/Composer required)
- 🚀 **GitHub Actions** CI/CD workflows for automated testing and plugin release

---

## Getting Started

### 1. Use this template

Click **"Use this template"** on GitHub to create your new plugin repository.

### 2. Rename the placeholder

Replace every occurrence of `plugin-name` with your actual plugin slug (e.g. `my-awesome-plugin`):

- `plugins/plugin-name/` → rename directory
- `plugins/plugin-name/plugin-name.php` → rename file and update plugin headers
- `docker-compose.test.yml` → update volume mount path
- `tests/bootstrap.php` → update `_manually_load_plugin` path
- `tests/phpunit.xml` → update testsuite name
- `.env` → update `WP_ACTIVATE_PLUGINS`
- `.github/workflows/release.yml` → update zip name and artifact name

### 3. Start the local development environment

```bash
cp .env .env.local   # optional: override default values
docker compose up -d
```

WordPress will be available at <http://localhost:8080>.  
phpMyAdmin at <http://localhost:8081>.  
Mailpit (email catcher) at <http://localhost:8025>.

### 4. Run PHPUnit tests

```bash
docker compose -f docker-compose.test.yml up --build --abort-on-container-exit
docker compose -f docker-compose.test.yml down -v
```

---

## Project Structure

```
.
├── .env                        # Local environment variables
├── .github/
│   └── workflows/
│       ├── test.yml            # CI: run PHPUnit on push/PR
│       └── release.yml         # CD: build & release zip on tag push
├── Dockerfile                  # WordPress dev image
├── Dockerfile.test             # PHP CLI image for PHPUnit
├── docker-compose.yml          # Local development stack
├── docker-compose.test.yml     # Test-only stack
├── wp-setup.sh                 # WP-CLI setup script (runs once)
├── plugins/
│   └── plugin-name/            # ← rename to your plugin slug
│       ├── plugin-name.php     # Main plugin file
│       └── languages/          # .po / .mo translation files
├── tests/
│   ├── bootstrap.php           # PHPUnit bootstrap (loads WP + plugin)
│   ├── phpunit.xml             # PHPUnit configuration
│   ├── test-plugin-name.php    # Sample test – rename/replace
│   └── bin/
│       ├── install-wp-tests.sh # Downloads WP core + test suite
│       └── run-tests.sh        # Entrypoint for phpunit container
├── themes/                     # Mount point for custom themes
└── uploads/                    # Mount point for media uploads
```

---

## CI/CD Workflows

| Workflow | Trigger | Description |
|---|---|---|
| `test.yml` | push / PR to `main` | Builds Docker test image and runs PHPUnit |
| `release.yml` | push tag `v*` | Compiles `.mo` files, zips plugin, creates GitHub Release |

---

## Requirements

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (or Docker Engine + Compose plugin)
- No local PHP, Composer, or WP-CLI installation required
