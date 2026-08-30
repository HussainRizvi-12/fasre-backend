#!/bin/sh
set -e

echo "==> Preparing FASRE Laravel production container..."

# Create storage directory structure if missing
mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs
chmod -R 775 storage bootstrap/cache

# Create storage link if not exists
php artisan storage:link --force || true

# Run database migrations and seeding
if [ "$RUN_MIGRATIONS" != "false" ]; then
    echo "==> Running database migrations & seeders..."
    php artisan migrate --force --seed --seeder=DemoSeeder || php artisan migrate --force
fi

# Cache Laravel configuration & routes for production speed
echo "==> Caching configuration, routes, and views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Determine port (Render assigns $PORT, fallback to 8000)
PORT_NUM=${PORT:-8000}
echo "==> Starting web server on 0.0.0.0:${PORT_NUM}..."

# Execute PHP web server
exec php artisan serve --host=0.0.0.0 --port="${PORT_NUM}"
