# Running ReelFlow on Hostinger shared hosting (Business plan)

This uses hosting you already pay for, so it adds **no extra cost**. Shared hosting has no FFmpeg, Redis or long-running background processes, so ReelFlow runs in two adapted modes:

| Setting | What it does |
|---|---|
| `MEDIA_PROCESSING=agent` | The **sync agent on your computer** runs FFmpeg (free) to read video details and create the thumbnail and AI frames, and uploads them with the video. The server never needs FFmpeg. |
| `REELFLOW_QUEUE_MODE=cron` | Queues and cache live in MySQL. The every-minute cron job starts the queue workers, which exit when there is no work. No Supervisor or Horizon is needed. |

Publishing still happens on Hostinger at the scheduled times while your computer is off. Your computer is only needed to sync new videos.

## 1. Prepare in hPanel (one time)

1. **Subdomain**: Domains → *your domain* → Subdomains → create e.g. `reels.yourdomain.com`. Use a **new subdomain** so no existing website is touched. Note its document root folder (shown in hPanel, e.g. `/home/u123456789/domains/reels.yourdomain.com/public_html`).
2. **SSL**: Security → SSL → make sure the subdomain has the free certificate (it is usually automatic).
3. **PHP version**: Advanced → PHP Configuration → choose **PHP 8.3** for the subdomain. Make sure `intl`, `bcmath`, `fileinfo`, `pdo_mysql`, `zip` and `mbstring` are enabled (they are by default).
4. **Database**: Databases → MySQL Databases → create a database and user (e.g. `u123456789_reelflow`). Keep the password.
5. **Mailbox** for notifications: Emails → create e.g. `reelflow@yourdomain.com`. Hostinger's SMTP is `smtp.hostinger.com`, port 465, SSL.
6. **SSH**: Advanced → SSH Access → enable it. Note the **IP**, **port** (65002) and **username** (`u123456789`). Under *SSH keys*, add the public key of the computer you deploy from (`~/.ssh/id_ed25519.pub`).

## 2. Deploy (from your computer)

```bash
cd reelflow   # the project folder
SSH_HOST=<ip> SSH_USER=u123456789 \
APP_DIR=/home/u123456789/domains/reels.yourdomain.com/reelflow \
WEB_ROOT=/home/u123456789/domains/reels.yourdomain.com/public_html \
bash deploy/hostinger/deploy.sh
```

- **First run:** it uploads the app, creates `APP_DIR/shared/.env` from `.env.hostinger.example`, generates `APP_KEY`, and **stops** so you can add the database details.
- Edit the file over SSH (`nano APP_DIR/shared/.env`) and set `APP_URL=https://reels.yourdomain.com` and the three `DB_*` values. Then run the same deploy command again.
- **Second run:** it migrates the database, activates the release, and points the subdomain's `public_html` at the app. Hostinger's placeholder files are moved to a backup folder; a folder with other content is never touched.
- It finishes by printing `reels:doctor` and the cron command.

Later updates use the same command. It builds the CSS/JS on your computer, uploads a new release, migrates, and keeps the three newest releases. To roll back, re-point the `current` link over SSH: `ln -sfn APP_DIR/releases/<previous> APP_DIR/current`.

## 3. Cron job (hPanel → Advanced → Cron Jobs)

Add a **Custom** cron job that runs **every minute** (`* * * * *`). Use the command printed by the deploy, for example:

```
/opt/alt/php83/usr/bin/php /home/u123456789/domains/reels.yourdomain.com/reelflow/current/artisan schedule:run
```

Use the exact PHP path the deploy printed. Plain `php` on Hostinger may be a different version, which is the most common reason Laravel cron jobs "don't run" there. After 2–3 minutes, `reels:doctor` shows the scheduler and both worker lanes as ✔.

## 4. Create your login

```bash
ssh -p 65002 u123456789@<ip>
/opt/alt/php83/usr/bin/php ~/domains/reels.yourdomain.com/reelflow/current/artisan reels:create-user you@yourdomain.com --admin
```

## 5. Sync agent on your computer

Install FFmpeg on the computer that runs the agent (it's free):
- Ubuntu: `sudo apt install ffmpeg`
- Windows: `winget install Gyan.FFmpeg`

Then follow `sync-agent/README.md` with `REELFLOW_SERVER_URL=https://reels.yourdomain.com`. The agent sees `media_processing: agent` in the server's settings and sends metadata, the thumbnail and AI frames automatically.

## 5b. No computer at all: FFmpeg on the server + Google Drive import

Hostinger allows running a static FFmpeg build from your home folder (free):

```bash
SSH_HOST=<ip> SSH_USER=u123456789 bash deploy/hostinger/install-ffmpeg.sh
```

Set the printed lines in `APP_DIR/shared/.env` (`MEDIA_PROCESSING=server`, `FFMPEG_PATH`, `FFPROBE_PATH`) and run `artisan optimize`. The server now creates metadata, thumbnails and AI frames itself, and the sync agent becomes optional.

Then open **Setup → Google Drive import** and add a Drive folder link. Share the folder in Drive as **Anyone with the link → Viewer**. Every 5 minutes the server checks the folder and downloads up to `DRIVE_IMPORT_BATCH` new videos directly from Google, which is fast (the download goes from Google to Hostinger without passing through your internet connection). Each file is downloaded once. A video that is already in the library, for example one uploaded earlier by the agent, is recognised by its checksum and not added twice. Command line: `artisan reels:drive-add <link> --name="Reels"` and `artisan reels:import-drive`.

## 6. Add credentials when ready

Edit `APP_DIR/shared/.env`:

| Feature | Variables |
|---|---|
| Email | `MAIL_MAILER=smtp`, `MAIL_USERNAME` / `MAIL_FROM_ADDRESS` = your mailbox, `MAIL_PASSWORD` |
| AI captions (free) | `AI_API_KEY` from <https://aistudio.google.com/apikey> |
| Facebook / Instagram | `META_APP_ID`, `META_APP_SECRET`, `META_REDIRECT_URI=https://reels.yourdomain.com/oauth/meta/callback` |
| YouTube | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI=https://reels.yourdomain.com/oauth/google/callback` |

Then apply the changes:

```bash
/opt/alt/php83/usr/bin/php ~/domains/reels.yourdomain.com/reelflow/current/artisan optimize
```

Config is cached, so edits to `.env` only take effect after `optimize`. The dashboard's Setup checklist turns green as each item is added.

## Limits to know

- **Disk:** videos are stored on your hosting disk. The default `VIDEO_CLEANUP_AFTER_DAYS=14` deletes originals once every enabled account has published them. Check usage in hPanel.
- **Long uploads:** a publishing upload runs inside the cron-started worker. Hostinger may stop very long-running processes. If that happens mid-upload, ReelFlow resumes from the saved stage on the next run (YouTube resumes from the last byte), and never posts twice.
- **Throughput:** one publishing worker runs at a time, which is plenty for 5–15 posts a day.
- **Backups:** hPanel → Files → Backups includes the database and files. Also keep a copy of `APP_DIR/shared/.env`, because `APP_KEY` decrypts the stored platform tokens.
- Moving to a VPS later is straightforward: `deploy/install-server.sh` plus `MEDIA_PROCESSING=server` and `REELFLOW_QUEUE_MODE=horizon`.
