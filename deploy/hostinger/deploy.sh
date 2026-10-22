#!/usr/bin/env bash
# =============================================================================
# Deploy ReelFlow to Hostinger shared hosting (Business plan) over SSH.
# Run on YOUR computer from the project root. Builds assets locally (no Node
# needed on Hostinger), uploads a release and activates it.
#
#   SSH_HOST=1.2.3.4 SSH_USER=u123456789 \
#   APP_DIR=/home/u123456789/domains/example.com/reelflow \
#   WEB_ROOT=/home/u123456789/domains/reels.example.com/public_html \
#   bash deploy/hostinger/deploy.sh
#
# SSH_PORT defaults to 65002 (Hostinger). WEB_ROOT is the document root of
# the (sub)domain that should serve ReelFlow; it becomes a symlink to the
# app's public/ folder. An existing non-empty WEB_ROOT is never deleted:
# it is renamed to <WEB_ROOT>.backup-<time> only if it contains nothing but
# Hostinger's placeholder files, otherwise the deploy stops.
# =============================================================================
set -euo pipefail

SSH_HOST="${SSH_HOST:?Set SSH_HOST (hPanel → Advanced → SSH Access)}"
SSH_USER="${SSH_USER:?Set SSH_USER (e.g. u123456789)}"
SSH_PORT="${SSH_PORT:-65002}"
APP_DIR="${APP_DIR:?Set APP_DIR, e.g. /home/u123/domains/example.com/reelflow (outside public_html)}"
WEB_ROOT="${WEB_ROOT:?Set WEB_ROOT, the document root of the ReelFlow (sub)domain}"
KEEP_RELEASES="${KEEP_RELEASES:-3}"

cd "$(git rev-parse --show-toplevel)"
SSH=(ssh -p "$SSH_PORT" -o ServerAliveInterval=30 "$SSH_USER@$SSH_HOST")
STAMP="$(date -u +%Y%m%d%H%M%S)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if [ -n "$(git status --porcelain)" ]; then
  echo "WARNING: uncommitted changes are NOT deployed (deploying $(git rev-parse --short HEAD))."
fi

echo "==> Building assets locally"
npm ci --silent
npm run build >/dev/null

echo "==> Packaging $(git rev-parse --short HEAD)"
git archive --format=tar HEAD > "$TMP/release.tar"
tar -rf "$TMP/release.tar" public/build
gzip -9 "$TMP/release.tar"

echo "==> Uploading"
"${SSH[@]}" "mkdir -p '$APP_DIR/releases'"
scp -P "$SSH_PORT" -q "$TMP/release.tar.gz" "$SSH_USER@$SSH_HOST:$APP_DIR/release-$STAMP.tar.gz"

echo "==> Activating on the server"
"${SSH[@]}" bash -s -- "$APP_DIR" "$WEB_ROOT" "$STAMP" "$KEEP_RELEASES" <<'REMOTE'
set -euo pipefail
APP_DIR="$1"; WEB_ROOT="$2"; STAMP="$3"; KEEP="$4"
RELEASE="$APP_DIR/releases/$STAMP"
SHARED="$APP_DIR/shared"

# PHP 8.2+ CLI (Hostinger keeps several versions under /opt/alt).
PHP=""
for candidate in php /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php /opt/alt/php82/usr/bin/php; do
  if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' 2>/dev/null; then
    PHP="$(command -v "$candidate")"; break
  fi
done
[ -n "$PHP" ] || { echo "No PHP >= 8.2 CLI found. Set the PHP version to 8.3 in hPanel."; exit 1; }
echo "PHP: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"

COMPOSER="composer"
if ! command -v composer >/dev/null 2>&1; then
  [ -f "$APP_DIR/composer.phar" ] || curl -sS https://getcomposer.org/installer | "$PHP" -- --install-dir="$APP_DIR" --quiet
  COMPOSER="$PHP $APP_DIR/composer.phar"
fi

mkdir -p "$RELEASE"
tar -xzf "$APP_DIR/release-$STAMP.tar.gz" -C "$RELEASE"
rm -f "$APP_DIR/release-$STAMP.tar.gz"

FIRST_RUN=no
if [ ! -d "$SHARED/storage" ]; then
  mkdir -p "$SHARED"
  cp -r "$RELEASE/storage" "$SHARED/storage"
  FIRST_RUN=yes
fi
if [ ! -f "$SHARED/.env" ]; then
  cp "$RELEASE/.env.hostinger.example" "$SHARED/.env"
  chmod 600 "$SHARED/.env"
fi
chmod -R u+rwX "$SHARED/storage"

cd "$RELEASE"
rm -rf storage && ln -s "$SHARED/storage" storage
ln -sfn "$SHARED/.env" .env

$COMPOSER install --no-dev --optimize-autoloader --no-interaction --quiet
grep -qE '^APP_KEY=base64:' "$SHARED/.env" || "$PHP" artisan key:generate --force

if grep -qE '^DB_(PASSWORD=change-me|DATABASE=u000000000_)' "$SHARED/.env"; then
  echo
  echo "STOP: fill in DB_DATABASE, DB_USERNAME, DB_PASSWORD and APP_URL in $SHARED/.env"
  echo "(hPanel → Databases → MySQL Databases), then run the deploy again."
  echo "The new release is uploaded but NOT activated."
  exit 2
fi

"$PHP" artisan migrate --force
ln -sfn "$RELEASE" "$APP_DIR/current"
"$PHP" "$APP_DIR/current/artisan" optimize >/dev/null

# Point the (sub)domain's document root at current/public.
if [ -L "$WEB_ROOT" ]; then
  ln -sfn "$APP_DIR/current/public" "$WEB_ROOT"
elif [ -e "$WEB_ROOT" ]; then
  leftovers="$(find "$WEB_ROOT" -mindepth 1 -maxdepth 1 ! -name default.php ! -name index.html ! -name .htaccess ! -name '.well-known' | head -1)"
  if [ -n "$leftovers" ]; then
    echo "STOP: $WEB_ROOT contains other files ($leftovers)."
    echo "Use a new, empty subdomain for ReelFlow so no existing site is touched."
    exit 3
  fi
  mv "$WEB_ROOT" "$WEB_ROOT.backup-$STAMP"
  ln -s "$APP_DIR/current/public" "$WEB_ROOT"
  echo "Moved Hostinger placeholder files to $WEB_ROOT.backup-$STAMP"
else
  mkdir -p "$(dirname "$WEB_ROOT")"
  ln -s "$APP_DIR/current/public" "$WEB_ROOT"
fi

# Keep the newest releases only.
ls -1dt "$APP_DIR"/releases/* | tail -n +"$((KEEP + 1))" | xargs -r rm -rf

echo
"$PHP" "$APP_DIR/current/artisan" reels:doctor || true
echo
echo "Deployed release $STAMP (first run: $FIRST_RUN)."
echo "Cron job for hPanel → Advanced → Cron Jobs (every minute):"
echo "  $PHP $APP_DIR/current/artisan schedule:run"
REMOTE
