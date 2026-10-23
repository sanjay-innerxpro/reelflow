#!/usr/bin/env bash
# Deploy a new release (layout created by deploy/install-server.sh):
#   /var/www/reelflow/releases/<timestamp>   code
#   /var/www/reelflow/shared/.env, shared/storage
#   /var/www/reelflow/current -> releases/<timestamp>
#
#   sudo bash /var/www/reelflow/current/deploy/deploy.sh [branch]
#
# Rollback: sudo bash deploy.sh --rollback   (switches to the previous release)
set -euo pipefail
BASE=/var/www/reelflow
SHARED="$BASE/shared"
REPO="${REPO:-$(git -C "$BASE/current" remote get-url origin 2>/dev/null || echo https://github.com/sanjay-innerxpro/reelflow.git)}"
export COMPOSER_ALLOW_SUPERUSER=1
artisan() { sudo -u www-data php "$BASE/current/artisan" "$@"; }

[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }

if [ "${1:-}" = "--rollback" ]; then
  PREVIOUS="$(ls -1dt "$BASE"/releases/* | sed -n 2p)"
  [ -n "$PREVIOUS" ] || { echo "No previous release."; exit 1; }
  echo "Rolling back to $PREVIOUS (database migrations are NOT reverted)."
  ln -sfn "$PREVIOUS" "$BASE/current"
  artisan optimize
  systemctl reload php8.3-fpm
  artisan horizon:terminate
  exit 0
fi

BRANCH="${1:-main}"
RELEASE="$BASE/releases/$(date -u +%Y%m%d%H%M%S)"

git clone --depth 1 --branch "$BRANCH" "$REPO" "$RELEASE"
cd "$RELEASE"
ln -sfn "$SHARED/.env" .env
rm -rf storage && ln -s "$SHARED/storage" storage
composer install --no-dev --optimize-autoloader --no-interaction --quiet
npm ci --silent && npm run build --silent && rm -rf node_modules
chown -R www-data:www-data bootstrap/cache

# Backup before migrating so a bad migration can be undone.
sudo -u www-data "$RELEASE/deploy/backup.sh" || echo "WARNING: pre-deploy backup failed"

ln -sfn "$RELEASE" "$BASE/current"
artisan migrate --force
artisan reels:import-credentials --once
artisan optimize
systemctl reload php8.3-fpm
# Horizon lets running jobs finish (stopwaitsecs) and Supervisor restarts it.
artisan horizon:terminate

ls -1dt "$BASE"/releases/* | tail -n +6 | xargs -r rm -rf
artisan reels:doctor || true
echo "Deployed $BRANCH -> $RELEASE"
