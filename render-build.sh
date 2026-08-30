#!/usr/bin/env bash
# Exit on error
set -o errexit

echo "==> Running Render Build Script for FASRE Backend..."

composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

php artisan config:cache
php artisan route:cache
php artisan view:cache
