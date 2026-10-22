#!/usr/bin/env bash
# Zero-downtime-ish release deploy:
#   /var/www/reelflow/releases/<timestamp>  (code)
#   /var/www/reelflow/shared/.env, shared/storage  (persistent)
#   /var/www/reelflow/current -> releases/<timestamp>
# Usage: deploy/deploy.sh <git-ref>
set -euo pipefail
REF="${1:-main}"
BASE=/var/www/reelflow
REPO="${REPO:-git@github.com:you/reelflow.git}"
RELEASE="$BASE/releases/$(date -u +%Y%m%d%H%M%S)"

git clone --depth 1 --branch "$REF" "$REPO" "$RELEASE"
cd "$RELEASE"
ln -s "$BASE/shared/.env" .env
rm -rf storage && ln -s "$BASE/shared/storage" storage

composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build && rm -rf node_modules

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

ln -sfn "$RELEASE" "$BASE/current"
sudo systemctl reload php8.3-fpm
# Horizon finishes running jobs (stopwaitsecs) and is restarted by Supervisor.
php "$BASE/current/artisan" horizon:terminate

# Keep the five most recent releases.
ls -1dt "$BASE"/releases/* | tail -n +6 | xargs -r rm -rf
echo "Deployed $REF to $RELEASE"
