#!/bin/bash
set -euo pipefail
echo "==> Configuring Nginx for Laravel on Azure App Service..."

# Copy custom Nginx configuration pointing to public/ directory
if [ -f /home/site/wwwroot/default ]; then
    cp /home/site/wwwroot/default /etc/nginx/sites-available/default 2>/dev/null || true
    cp /home/site/wwwroot/default /etc/nginx/sites-enabled/default 2>/dev/null || true
    service nginx reload 2>/dev/null || /etc/init.d/nginx reload 2>/dev/null || true
fi

echo "==> Setting up storage permissions..."
mkdir -p /home/site/wwwroot/storage/framework/sessions
mkdir -p /home/site/wwwroot/storage/framework/views
mkdir -p /home/site/wwwroot/storage/framework/cache
mkdir -p /home/site/wwwroot/storage/logs
chmod -R 775 /home/site/wwwroot/storage /home/site/wwwroot/bootstrap/cache 2>/dev/null || true

cd /home/site/wwwroot

# Ensure .env is initialized from .env.production if missing
if [ ! -f /home/site/wwwroot/.env ] && [ -f /home/site/wwwroot/.env.production ]; then
    echo "==> Initializing production .env from sanitized .env.production template..."
    cp /home/site/wwwroot/.env.production /home/site/wwwroot/.env
fi

# Create storage symlink
php artisan storage:link --force 2>/dev/null || true

# Clear stale caches
echo "==> Clearing stale caches..."
rm -f /home/site/wwwroot/bootstrap/cache/config.php /home/site/wwwroot/bootstrap/cache/routes*.php /home/site/wwwroot/bootstrap/cache/packages.php /home/site/wwwroot/bootstrap/cache/services.php 2>/dev/null || true
php artisan optimize:clear

# Migrate only after stale configuration is cleared. A failed migration must
# fail startup rather than announce a ready application with the wrong schema.
echo "==> Running database migrations..."
php artisan migrate --force

# Run optimization caches
echo "==> Caching config, routes, and views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Laravel is ready on Azure App Service."
