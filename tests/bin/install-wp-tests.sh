#!/usr/bin/env bash
set -euo pipefail

# -------------------------------------------------------
# Download and install the WordPress test suite.
#
# Adapted from the official wp-cli scaffold approach:
#   wp scaffold plugin-tests
#
# Environment variables expected:
#   WP_DB_HOST, WP_DB_NAME, WP_DB_USER, WP_DB_PASSWORD
#   WP_TESTS_DIR  – where the test library is installed
#   WP_CORE_DIR   – where WordPress core is installed
# -------------------------------------------------------

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"
WP_VERSION="${WP_VERSION:-latest}"

DB_HOST="${WP_DB_HOST:-test-db}"
DB_NAME="${WP_DB_NAME:-wordpress_test}"
DB_USER="${WP_DB_USER:-wptest}"
DB_PASS="${WP_DB_PASSWORD:-wptest}"

download() {
  if [ "$2" = "-" ]; then
    # Output to stdout
    if command -v curl > /dev/null; then
      curl -sL "$1"
    elif command -v wget > /dev/null; then
      wget -nv -O - "$1"
    fi
  else
    if command -v curl > /dev/null; then
      curl -sL "$1" > "$2"
    elif command -v wget > /dev/null; then
      wget -nv -O "$2" "$1"
    fi
  fi
}

# Resolve actual version tag
if [ "$WP_VERSION" == "latest" ]; then
  WP_VERSION=$(download https://api.wordpress.org/core/version-check/1.7/ - | grep -oP '"version"\s*:\s*"\K[^"]+' | head -1)
fi

if [ -z "$WP_VERSION" ]; then
  echo "❌ Failed to detect WordPress version from API. Falling back to 6.7."
  WP_VERSION="6.7"
fi

WP_TESTS_TAG="tags/$WP_VERSION"

# Download WordPress core
if [ ! -d "$WP_CORE_DIR" ]; then
  mkdir -p "$WP_CORE_DIR"
  download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" /tmp/wordpress.tar.gz
  tar --strip-components=1 -zxf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
  rm /tmp/wordpress.tar.gz
fi

# Download WordPress test suite
if [ ! -d "$WP_TESTS_DIR" ]; then
  mkdir -p "$WP_TESTS_DIR"
  svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
  svn co --quiet "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
fi

# Generate wp-tests-config.php
if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
  cat > "$WP_TESTS_DIR/wp-tests-config.php" <<PHP
<?php
define( 'ABSPATH', '${WP_CORE_DIR}/' );
define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', '${DB_HOST}' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

\$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
PHP
fi

echo "✅ WordPress test suite installed (WP $WP_VERSION)"
