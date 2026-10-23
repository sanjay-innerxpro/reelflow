#!/usr/bin/env bash
# =============================================================================
# ReelFlow one-shot installer for a fresh Ubuntu 24.04 server (x86_64 or Arm,
# e.g. Oracle Cloud Always Free). Everything installed is free software.
#
#   sudo DOMAIN=yourname.duckdns.org EMAIL=you@example.com bash install-server.sh
#
# Optional: REPO=<git url> (default: the public GitHub repo), BRANCH=main,
#           ADMIN_EMAIL=<dashboard login email> (default: EMAIL)
#
# Safe to re-run: existing database users, passwords and .env values are kept.
# Platform/AI/SMTP credentials are left empty — add them later to
# /var/www/reelflow/shared/.env (see the summary printed at the end).
# =============================================================================
set -euo pipefail

DOMAIN="${DOMAIN:?Set DOMAIN, e.g. DOMAIN=yourname.duckdns.org}"
EMAIL="${EMAIL:?Set EMAIL (used for the HTTPS certificate and notifications)}"
REPO="${REPO:-https://github.com/sanjay-innerxpro/reelflow.git}"
BRANCH="${BRANCH:-main}"
ADMIN_EMAIL="${ADMIN_EMAIL:-$EMAIL}"
BASE=/var/www/reelflow
SHARED="$BASE/shared"
PHP=8.3
export DEBIAN_FRONTEND=noninteractive COMPOSER_ALLOW_SUPERUSER=1

[ "$(id -u)" -eq 0 ] || { echo "Run with sudo."; exit 1; }
. /etc/os-release
[ "${VERSION_ID:-}" = "24.04" ] || echo "WARNING: tested for Ubuntu 24.04, found ${PRETTY_NAME:-unknown}. Continuing."

log() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
artisan() { sudo -u www-data php "$BASE/current/artisan" "$@"; }
set_env() { # set_env KEY VALUE  (only when KEY is empty or a placeholder)
  local key="$1" value="$2" file="$SHARED/.env" current
  current="$(grep -E "^${key}=" "$file" | head -1 | cut -d= -f2- || true)"
  if [ -z "$current" ] || [[ "$current" == *"change-me"* ]] || [[ "$current" == *"example.com"* ]] || [ "$current" = "null" ]; then
    if grep -qE "^${key}=" "$file"; then
      sed -i "s|^${key}=.*|${key}=${value}|" "$file"
    else
      echo "${key}=${value}" >> "$file"
    fi
  fi
}

log "Packages"
apt-get update -q
apt-get install -y -q nginx mysql-server redis-server ffmpeg supervisor git unzip curl ca-certificates \
  certbot python3-certbot-nginx openssl \
  php$PHP-fpm php$PHP-cli php$PHP-mysql php$PHP-redis php$PHP-mbstring php$PHP-xml php$PHP-curl \
  php$PHP-zip php$PHP-bcmath php$PHP-intl php$PHP-gd
if ! command -v composer >/dev/null; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
if ! command -v node >/dev/null || [ "$(node -p 'process.versions.node.split(".")[0]')" -lt 20 ]; then
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
  apt-get install -y -q nodejs
fi

log "Swap (small free VMs)"
if [ "$(free -m | awk '/Mem:/ {print $2}')" -lt 3000 ] && ! swapon --show | grep -q .; then
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
  grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

log "PHP limits"
cat > /etc/php/$PHP/fpm/conf.d/99-reelflow.ini <<'INI'
upload_max_filesize = 20M
post_max_size = 20M
max_execution_time = 300
memory_limit = 512M
expose_php = Off
INI
systemctl restart php$PHP-fpm

log "Directories"
mkdir -p "$BASE/releases" "$SHARED" /var/backups/reelflow
RELEASE="$BASE/releases/$(date -u +%Y%m%d%H%M%S)"
git clone --depth 1 --branch "$BRANCH" "$REPO" "$RELEASE"
if [ ! -d "$SHARED/storage" ]; then
  cp -r "$RELEASE/storage" "$SHARED/storage"
fi
[ -f "$SHARED/.env" ] || cp "$RELEASE/.env.example" "$SHARED/.env"
chown -R www-data:www-data "$SHARED/storage" /var/backups/reelflow
chown root:www-data "$SHARED/.env" && chmod 640 "$SHARED/.env"

log "MySQL"
DB_PASS="$(grep -E '^DB_PASSWORD=' "$SHARED/.env" | cut -d= -f2- || true)"
if [ -z "$DB_PASS" ] || [ "$DB_PASS" = "change-me" ]; then
  DB_PASS="$(openssl rand -hex 24)"
  sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" "$SHARED/.env"
fi
BACKUP_PASS="$(openssl rand -hex 24)"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS reelflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'reelflow'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER 'reelflow'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON reelflow.* TO 'reelflow'@'localhost';
CREATE USER IF NOT EXISTS 'reelflow_backup'@'localhost' IDENTIFIED BY '${BACKUP_PASS}';
ALTER USER 'reelflow_backup'@'localhost' IDENTIFIED BY '${BACKUP_PASS}';
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, PROCESS ON *.* TO 'reelflow_backup'@'localhost';
FLUSH PRIVILEGES;
SQL
WWW_HOME="$(getent passwd www-data | cut -d: -f6)"
printf '[client]\nuser=reelflow_backup\npassword=%s\n' "$BACKUP_PASS" > "$WWW_HOME/.my.cnf"
chown www-data:www-data "$WWW_HOME/.my.cnf" && chmod 600 "$WWW_HOME/.my.cnf"

log "Redis"
REDIS_PASS="$(grep -E '^REDIS_PASSWORD=' "$SHARED/.env" | cut -d= -f2- || true)"
if [ -z "$REDIS_PASS" ] || [ "$REDIS_PASS" = "null" ]; then
  REDIS_PASS="$(openssl rand -hex 24)"
  sed -i "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=${REDIS_PASS}|" "$SHARED/.env"
fi
sed -i -E "s|^#? *requirepass .*|requirepass ${REDIS_PASS}|" /etc/redis/redis.conf
grep -q "^requirepass" /etc/redis/redis.conf || echo "requirepass ${REDIS_PASS}" >> /etc/redis/redis.conf
sed -i -E "s|^#? *maxmemory-policy .*|maxmemory-policy noeviction|" /etc/redis/redis.conf
sed -i -E "s|^bind .*|bind 127.0.0.1 -::1|" /etc/redis/redis.conf
systemctl restart redis-server

log "Application .env"
set_env APP_ENV production
sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" "$SHARED/.env"
sed -i "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" "$SHARED/.env"
set_env DB_CONNECTION mysql
set_env DB_USERNAME reelflow
set_env DB_DATABASE reelflow
set_env REELFLOW_ADMIN_EMAIL "$ADMIN_EMAIL"
set_env FFMPEG_PATH "$(command -v ffmpeg)"
set_env FFPROBE_PATH "$(command -v ffprobe)"
if [ "$(free -m | awk '/Mem:/ {print $2}')" -lt 3000 ]; then
  sed -i "s|^HORIZON_GENERAL_PROCESSES=.*|HORIZON_GENERAL_PROCESSES=1|; s|^HORIZON_PUBLISHING_PROCESSES=.*|HORIZON_PUBLISHING_PROCESSES=1|" "$SHARED/.env"
fi
# Mail stays on "log" until SMTP credentials are added, so nothing fails meanwhile.
if ! grep -qE '^MAIL_PASSWORD=.+' "$SHARED/.env"; then
  sed -i "s|^MAIL_MAILER=.*|MAIL_MAILER=log|" "$SHARED/.env"
fi

log "Build release"
cd "$RELEASE"
ln -sfn "$SHARED/.env" .env
rm -rf storage && ln -s "$SHARED/storage" storage
composer install --no-dev --optimize-autoloader --no-interaction --quiet
npm ci --silent && npm run build --silent && rm -rf node_modules
chown -R www-data:www-data bootstrap/cache
grep -qE '^APP_KEY=base64:' "$SHARED/.env" || php artisan key:generate --force
ln -sfn "$RELEASE" "$BASE/current"
artisan migrate --force
artisan reels:import-credentials --once
artisan optimize

log "nginx"
cat > /etc/nginx/sites-available/reelflow <<NGINX
server {
    listen 80;
    server_name ${DOMAIN};
    root ${BASE}/current/public;
    index index.php;
    client_max_body_size 20M;
    client_body_timeout 300s;
    charset utf-8;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }
    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php${PHP}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
NGINX
ln -sfn /etc/nginx/sites-available/reelflow /etc/nginx/sites-enabled/reelflow
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

log "Firewall (ports 80/443)"
if command -v ufw >/dev/null && ufw status | grep -q active; then
  ufw allow 'Nginx Full' >/dev/null
fi
if command -v netfilter-persistent >/dev/null; then # Oracle Cloud Ubuntu images
  for port in 80 443; do
    iptables -C INPUT -p tcp --dport $port -j ACCEPT 2>/dev/null || iptables -I INPUT 5 -p tcp --dport $port -j ACCEPT
  done
  netfilter-persistent save >/dev/null
fi

log "HTTPS (Let's Encrypt)"
if certbot --nginx -d "$DOMAIN" -m "$EMAIL" --agree-tos --non-interactive --redirect; then
  HTTPS_OK=yes
else
  HTTPS_OK=no
  echo "Certificate not issued yet (does ${DOMAIN} point to this server, and are ports 80/443 open in the cloud firewall?)."
  echo "Re-run later: sudo certbot --nginx -d ${DOMAIN} -m ${EMAIL} --agree-tos --redirect"
fi

log "Workers, scheduler, backups, log rotation"
sed "s|/var/www/reelflow/shared/storage/logs|${SHARED}/storage/logs|" "$BASE/current/deploy/supervisor-horizon.conf" > /etc/supervisor/conf.d/reelflow-horizon.conf
supervisorctl reread >/dev/null && supervisorctl update >/dev/null
supervisorctl restart reelflow-horizon >/dev/null || true
cat > /etc/cron.d/reelflow <<CRON
* * * * * www-data cd ${BASE}/current && php artisan schedule:run >> /dev/null 2>&1
15 2 * * * www-data ${BASE}/current/deploy/backup.sh >> ${SHARED}/storage/logs/backup.log 2>&1
CRON
chmod 644 /etc/cron.d/reelflow
cp "$BASE/current/deploy/logrotate-reelflow" /etc/logrotate.d/reelflow

log "Waiting for the first scheduler/worker heartbeat"
sleep 10 && artisan reels:heartbeat >/dev/null
sleep 65
artisan reels:doctor || true

cat <<SUMMARY

=============================================================================
 ReelFlow is installed.
   URL:        https://${DOMAIN}   (HTTPS issued: ${HTTPS_OK})
   Health:     https://${DOMAIN}/health   (add to a free uptime monitor)
   Config:     ${SHARED}/.env

 Next steps:
 1. Create your login:
      sudo -u www-data php ${BASE}/current/artisan reels:create-user ${ADMIN_EMAIL}
 2. Add credentials to ${SHARED}/.env when you have them:
      AI_API_KEY (Gemini free key)          MAIL_* (Gmail App Password, then MAIL_MAILER=smtp)
      META_APP_ID / META_APP_SECRET         GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET
      META_REDIRECT_URI=https://${DOMAIN}/oauth/meta/callback
      GOOGLE_REDIRECT_URI=https://${DOMAIN}/oauth/google/callback
    then apply them:
      sudo -u www-data php ${BASE}/current/artisan optimize
      sudo supervisorctl restart reelflow-horizon
 3. Check readiness at any time:
      sudo -u www-data php ${BASE}/current/artisan reels:doctor
 4. Back up ${SHARED}/.env somewhere safe (APP_KEY decrypts stored tokens).
=============================================================================
SUMMARY
