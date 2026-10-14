#!/bin/bash
set -euo pipefail

cd "${FASRE_APP_ROOT:-/home/site/wwwroot}"

if [ ! -f bootstrap/cache/config.php ]; then
    echo "Production configuration is not ready; retry on the next scheduled run." >&2
    exit 1
fi

exec php artisan schedule:run --no-interaction
