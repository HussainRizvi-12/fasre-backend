#!/bin/bash
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

# Create storage symlink
php artisan storage:link --force 2>/dev/null || true

# Clear stale caches
echo "==> Clearing stale caches..."
rm -f /home/site/wwwroot/bootstrap/cache/config.php /home/site/wwwroot/bootstrap/cache/routes*.php /home/site/wwwroot/bootstrap/cache/packages.php /home/site/wwwroot/bootstrap/cache/services.php 2>/dev/null || true
php artisan optimize:clear 2>/dev/null || true

# Run optimization caches
echo "==> Caching config, routes, and views..."
php artisan config:cache 2>/dev/null || true
php artisan route:cache 2>/dev/null || true
php artisan view:cache 2>/dev/null || true

echo "==> Laravel is ready on Azure App Service."
