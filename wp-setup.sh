#!/bin/bash
set -euo pipefail

# Ensure wp-content is writable
echo "🔧 Fixing wp-content permissions..."
chmod -R 777 /var/www/html/wp-content 2>/dev/null || true

# Wait for WordPress files to be ready (populated by the wordpress container)
echo "⏳ Waiting for WordPress files..."
until [ -f /var/www/html/wp-includes/version.php ]; do
  sleep 2
done

# Wait for database to be fully ready
echo "⏳ Waiting for database connection..."
until wp db check --allow-root --quiet 2>/dev/null; do
  sleep 2
done

# Run install only if not already installed
if ! wp core is-installed --allow-root 2>/dev/null; then
  echo "🚀 Installing WordPress..."
  wp core install --allow-root \
    --url="${WP_URL}" \
    --title="${WP_TITLE}" \
    --admin_user="${WP_ADMIN_USER}" \
    --admin_password="${WP_ADMIN_PASSWORD}" \
    --admin_email="${WP_ADMIN_EMAIL}" \
    --skip-email

  # Set language
  if [ -n "${WP_LOCALE:-}" ] && [ "${WP_LOCALE}" != "en_US" ]; then
    echo "🌐 Installing language: ${WP_LOCALE}"
    wp language core install --allow-root "${WP_LOCALE}"
    wp site switch-language --allow-root "${WP_LOCALE}"
  fi

  # Install additional language packs
  if [ -n "${WP_LANGUAGE_PACKS:-}" ]; then
    IFS=',' read -ra LANGS <<< "${WP_LANGUAGE_PACKS}"
    for lang in "${LANGS[@]}"; do
      lang=$(echo "$lang" | xargs)
      if [ -z "$lang" ]; then
        continue
      fi
      if wp language core is-installed --allow-root "$lang" 2>/dev/null; then
        echo "🌐 Language pack already installed: $lang"
      else
        echo "🌐 Installing language pack: $lang"
        wp language core install --allow-root "$lang" || echo "⚠️  Failed to install: $lang (skipping)"
      fi
    done
  fi

  # Set timezone
  if [ -n "${WP_TIMEZONE:-}" ]; then
    wp option update --allow-root timezone_string "${WP_TIMEZONE}"
  fi

  # Set permalink structure
  wp rewrite structure --allow-root '/%postname%/' --hard

  # Activate plugins if specified
  if [ -n "${WP_ACTIVATE_PLUGINS:-}" ]; then
    IFS=',' read -ra PLUGINS <<< "${WP_ACTIVATE_PLUGINS}"
    for plugin in "${PLUGINS[@]}"; do
      plugin=$(echo "$plugin" | xargs)  # trim whitespace
      if wp plugin is-installed --allow-root "$plugin" 2>/dev/null; then
        echo "🔌 Activating plugin: $plugin"
        wp plugin activate --allow-root "$plugin"
      else
        echo "⚠️  Plugin not found: $plugin (skipping)"
      fi
    done
  fi

  echo "✅ WordPress setup complete!"
  echo "   URL:   ${WP_URL}"
  echo "   Admin: ${WP_ADMIN_USER} / ${WP_ADMIN_PASSWORD}"
else
  echo "✅ WordPress is already installed. Skipping setup."
fi
