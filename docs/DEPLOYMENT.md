# Deploying ReelFlow on an Ubuntu VPS

Target: Ubuntu 24.04 LTS. Commands run as a sudo user. Replace `reels.example.com` with your domain (for example a free `yourname.duckdns.org`).

## 0. Free hosting

Everything below can run at **zero cost**:

1. **Server: Oracle Cloud Always Free.** Sign up at <https://www.oracle.com/cloud/free/>; a card is needed for identity verification, but Always Free resources are not billed. Create a VM with the **Ampere A1 (Arm)** "Always Free-eligible" shape and an Ubuntu 24.04 image. Oracle reportedly reduced the free Arm allowance in 2026 (to about 2 OCPU / 12 GB RAM), so check the current limits on Oracle's *Always Free Resources* page; that is still plenty for ReelFlow.
   - Keep the account on the free tier (don't upgrade to Pay As You Go), only create resources marked *Always Free-eligible*, and set a budget alert of $1.
   - Oracle may reclaim Always Free VMs that stay almost idle for a long time. ReelFlow's scheduler and workers keep the VM lightly active; check your email for any reclaim warning from Oracle.
   - In the VCN security list, allow inbound TCP 80 and 443. Ubuntu images on OCI also ship iptables rules: `sudo iptables -I INPUT 6 -p tcp -m multiport --dports 80,443 -j ACCEPT && sudo netfilter-persistent save`.
   - Alternative: the Google Cloud free-tier `e2-micro` (1 GB RAM, US regions only). Add 2 GB of swap, set `HORIZON_GENERAL_PROCESSES=1` and `HORIZON_PUBLISHING_PROCESSES=1`, and use Gemini or Groq rather than Ollama.
2. **Domain: DuckDNS.** Sign in at <https://www.duckdns.org>, create `yourname.duckdns.org` and point it at the VM's public IP. Use it as `APP_URL`.
3. **HTTPS: Let's Encrypt** via certbot (section 6). It renews automatically.
4. **AI: Gemini free tier** (`AI_PROVIDER=gemini`, key from <https://aistudio.google.com/apikey>). Alternatively, install Ollama on the VM (`curl -fsSL https://ollama.com/install.sh | sh && ollama pull qwen3-vl:8b`, then `AI_PROVIDER=ollama`, `AI_MAX_FRAMES=3`, `AI_TIMEOUT=600`), which is fully self-hosted with no external limits.
5. **Email: Gmail App Password** (README §13).
6. **Swap** (recommended on any small VM):
   ```bash
   sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile
   echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
   ```

On Oracle's Arm VM, every package below (PHP, MySQL, Redis, FFmpeg, Node) is available for arm64.

## 1. Packages

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server redis-server ffmpeg supervisor git unzip curl certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-redis php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip \
  php8.3-bcmath php8.3-intl php8.3-gd
# Composer
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
# Node.js 20+ (build step only)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
ffmpeg -version && ffprobe -version
```

Required PHP extensions: `bcmath ctype curl dom fileinfo intl mbstring openssl pcntl pdo_mysql posix tokenizer xml zip`. `redis` is optional (otherwise set `REDIS_CLIENT=predis`). `pcntl` and `posix` are part of PHP CLI on Ubuntu and are needed by Horizon.

PHP-FPM: raise the upload limits in `/etc/php/8.3/fpm/php.ini` to cover the sync chunk size:

```
upload_max_filesize = 20M
post_max_size = 20M
max_execution_time = 300
memory_limit = 512M
```

## 2. MySQL

```bash
sudo mysql
```
```sql
CREATE DATABASE reelflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'reelflow'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON reelflow.* TO 'reelflow'@'localhost';
CREATE USER 'reelflow_backup'@'localhost' IDENTIFIED BY 'another-random-password';
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, PROCESS ON *.* TO 'reelflow_backup'@'localhost';
FLUSH PRIVILEGES;
```

MySQL listens on localhost by default; keep it that way.

## 3. Redis

In `/etc/redis/redis.conf`, set `bind 127.0.0.1 -::1`, `requirepass <password>` (and put it in `REDIS_PASSWORD`), and `maxmemory-policy noeviction`. Queues must never be evicted.

```bash
sudo systemctl restart redis-server
```

## 4. Application layout

```
/var/www/reelflow/
  releases/<timestamp>/   code
  shared/.env             configuration (mode 640, owner deploy:www-data)
  shared/storage/         logs, local private videos (if not using S3), tmp
  current -> releases/<timestamp>
```

```bash
sudo mkdir -p /var/www/reelflow/{releases,shared}
sudo chown -R $USER:www-data /var/www/reelflow
git clone <repo> /var/www/reelflow/releases/initial
cp -r /var/www/reelflow/releases/initial/storage /var/www/reelflow/shared/storage
cp /var/www/reelflow/releases/initial/.env.example /var/www/reelflow/shared/.env
chmod 640 /var/www/reelflow/shared/.env
sudo chown -R www-data:www-data /var/www/reelflow/shared/storage
sudo chmod -R ug+rwX /var/www/reelflow/shared/storage
```

Edit `shared/.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://reels.example.com`, database, Redis, `VIDEO_STORAGE_DISK=videos` (free, server disk), mail (Gmail App Password), Meta, Google and AI (`AI_PROVIDER=gemini` with a free AI Studio key). Then:

```bash
cd /var/www/reelflow/releases/initial
ln -s ../../shared/.env .env && rm -rf storage && ln -s ../../shared/storage storage
composer install --no-dev --optimize-autoloader
php artisan key:generate          # once; back up APP_KEY securely
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
ln -sfn /var/www/reelflow/releases/initial /var/www/reelflow/current
php artisan reels:create-user you@example.com --name="Admin"
```

## 5. Object storage (optional)

The free default stores videos on the server's disk (`VIDEO_STORAGE_DISK=videos`), so you can skip this section. Only use a bucket if you need more space. Backblaze B2 and Cloudflare R2 have free tiers of about 10 GB and bill beyond that.

Create a **private** bucket (block all public access, enable default encryption), plus an access key limited to that bucket:

```json
{ "Version": "2012-10-17", "Statement": [
  { "Effect": "Allow", "Action": ["s3:GetObject","s3:PutObject","s3:DeleteObject"], "Resource": "arn:aws:s3:::my-reelflow-videos/*" },
  { "Effect": "Allow", "Action": ["s3:ListBucket"], "Resource": "arn:aws:s3:::my-reelflow-videos" } ] }
```

For R2, Wasabi, Spaces or MinIO, set `AWS_ENDPOINT` (and `AWS_USE_PATH_STYLE_ENDPOINT=true` for MinIO). Check it with:

```bash
php artisan tinker --execute="Storage::disk('videos_s3')->put('healthcheck.txt','ok'); echo Storage::disk('videos_s3')->temporaryUrl('healthcheck.txt', now()->addMinute()); Storage::disk('videos_s3')->delete('healthcheck.txt');"
```

## 6. Web server and HTTPS

```bash
sudo cp /var/www/reelflow/current/deploy/nginx-reelflow.conf /etc/nginx/sites-available/reelflow
sudo sed -i 's/reels.example.com/YOUR_DOMAIN/g' /etc/nginx/sites-available/reelflow
sudo ln -s /etc/nginx/sites-available/reelflow /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo certbot --nginx -d YOUR_DOMAIN       # obtains and auto-renews the certificate
sudo nginx -t && sudo systemctl reload nginx
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```

If certbot runs before the HTTPS block can load, temporarily comment out the `ssl_certificate` lines, run certbot, then restore them.

## 7. Queue workers

**Supervisor (recommended):**

```bash
sudo cp /var/www/reelflow/current/deploy/supervisor-horizon.conf /etc/supervisor/conf.d/reelflow-horizon.conf
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```

**Or systemd:** `deploy/reelflow-horizon.service`.

**Without Horizon:** `deploy/supervisor-queue-fallback.conf` runs plain `queue:work` processes with the same queues and timeouts.

Monitoring is at `https://YOUR_DOMAIN/horizon` (sign-in required), plus the dashboard's *Queue health* card and *Failed jobs* page. Horizon emails `REELFLOW_ADMIN_EMAIL` when queue waits exceed the thresholds in `config/horizon.php`.

## 8. Scheduler (cron)

```bash
sudo crontab -u www-data -e
# add the lines from deploy/crontab.txt
```

Check it with `php artisan schedule:list`. `reels:dispatch` must run every minute.

## 9. Backups and restore

```bash
sudo -u www-data bash -c 'printf "[client]\nuser=reelflow_backup\npassword=...\n" > ~/.my.cnf && chmod 600 ~/.my.cnf'
sudo mkdir -p /var/backups/reelflow && sudo chown www-data /var/backups/reelflow
sudo -u www-data /var/www/reelflow/current/deploy/backup.sh     # test once
```

Restore:

```bash
php artisan down
gunzip -c /var/backups/reelflow/reelflow-YYYYMMDDTHHMMSSZ.sql.gz | mysql -u root -p reelflow
php artisan up
```

Also back up `shared/.env` (it contains `APP_KEY`, without which the stored tokens cannot be decrypted) and enable versioning or replication on the video bucket if originals matter to you.

## 10. Log rotation

Laravel's `daily` channel keeps `LOG_DAILY_DAYS` files. For Horizon and worker logs:

```bash
sudo cp /var/www/reelflow/current/deploy/logrotate-reelflow /etc/logrotate.d/reelflow
```

## 11. Deploying updates and rolling back

```bash
REPO=git@github.com:you/reelflow.git /var/www/reelflow/current/deploy/deploy.sh main
```

The script clones into a new release, installs dependencies, migrates, caches config, switches the `current` symlink, reloads PHP-FPM and gracefully restarts Horizon. Running uploads are allowed to finish (`stopwaitsecs=3700`).

Rollback:

```bash
ls -1dt /var/www/reelflow/releases/*         # find the previous release
ln -sfn /var/www/reelflow/releases/<previous> /var/www/reelflow/current
sudo systemctl reload php8.3-fpm && php /var/www/reelflow/current/artisan horizon:terminate
```

If the bad release ran migrations, run `php artisan migrate:rollback --step=N` from the bad release **before** switching back, or restore the pre-deploy backup.

## 12. Post-deploy checklist

- [ ] `https://YOUR_DOMAIN/up` returns 200
- [ ] Sign in works and `/horizon` shows both supervisors running
- [ ] `php artisan schedule:list` lists tasks, and cron is installed for `www-data`
- [ ] Email settings → *Validate SMTP connection* succeeds, and the test email arrives
- [ ] AI settings show the provider as configured (if used)
- [ ] Meta and Google redirect URIs match `APP_URL` exactly
- [ ] A sync device is registered and `python3 -m reelflow_agent check` succeeds from your computer
- [ ] A test upload becomes `ready` with a thumbnail and correct duration and resolution
