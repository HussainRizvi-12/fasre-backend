#!/bin/bash
set -euo pipefail

cd "${FASRE_APP_ROOT:-/home/site/wwwroot}"

# Startup builds the protected production cache from Azure App Settings.
# Do not process jobs with template/default settings before it is ready.
until [ -f bootstrap/cache/config.php ]; do
    sleep 5
done

exec php artisan queue:work database --sleep=3 --tries=3 --timeout=60 --max-time=3600 --no-interaction
