#!/usr/bin/env bash
set -euo pipefail

echo "🧪 WordPress Plugin – Test Runner"
echo "==================================="

# Install the WP test suite
bash /app/tests/bin/install-wp-tests.sh

# Wait for the database to be reachable (belt-and-suspenders)
echo "⏳ Verifying database connection..."
until mysqladmin ping -h "$WP_DB_HOST" -u "$WP_DB_USER" -p"$WP_DB_PASSWORD" --silent 2>/dev/null; do
  sleep 1
done
echo "✅ Database is ready"

# Run PHPUnit
cd /app
exec phpunit --configuration tests/phpunit.xml "$@"
