# ReelFlow — automated short-video publishing

ReelFlow takes videos from folders on your computer, uploads them to private cloud storage, writes captions with AI, schedules them and publishes them to:

- **Facebook Pages** as Reels
- **Instagram Professional accounts** as Reels
- **YouTube channels** as Shorts

Publishing uses only the official Meta Graph API and YouTube Data API v3. It never logs in with passwords or drives a browser. ReelFlow emails you when posts fail, when an account needs reconnecting, when the sync agent goes offline, and once a day with a summary.

Once a video has been uploaded, publishing runs entirely on the server. Your computer can be switched off.

**ReelFlow is designed to run at zero cost.** Every default is free. See [Zero-cost setup](#zero-cost-setup).

---

## Zero-cost setup

| Need | Free choice (default) | Notes |
|---|---|---|
| Software | Laravel, MySQL/MariaDB, Redis, FFmpeg, Horizon, Python agent | All open source |
| Server that runs while your PC is off | **Hosting you already have**: Hostinger Business or similar shared hosting ([docs/HOSTINGER.md](docs/HOSTINGER.md)), or **Oracle Cloud Always Free** (needs a card for verification) | See [docs/DEPLOYMENT.md → Free hosting](docs/DEPLOYMENT.md#0-free-hosting). Signing up needs a card for identity checks; stay inside the Always Free shapes and set a budget alert. |
| Domain + HTTPS (Meta requires HTTPS callbacks) | **DuckDNS** subdomain + **Let's Encrypt** | Both free |
| Video storage | **Server disk** (`VIDEO_STORAGE_DISK=videos`) | `VIDEO_CLEANUP_AFTER_DAYS` frees space once every enabled account has posted a video. Free-tier buckets (Backblaze B2, Cloudflare R2, about 10 GB) also work but bill beyond the free tier. |
| AI captions | **Google Gemini free tier** (`AI_PROVIDER=gemini`), or **Groq free tier**, or **self-hosted Ollama** (no key, no limits, slower on CPU) | One request per video, far below free daily limits. Free tiers may use prompts to improve the provider's products. Use Ollama if that matters. `none` = write captions yourself. |
| Audio transcription (optional) | Groq free-tier Whisper, or a self-hosted Whisper server | Off by default |
| Email alerts | **Gmail SMTP with an App Password** (or Brevo's free plan) | ~500 mails/day free; ReelFlow sends a few per day |
| Facebook / Instagram publishing | Meta Graph API | Free. App Review and business verification are free. |
| YouTube publishing | YouTube Data API v3 | Free within the daily quota. The API audit is free. |
| Privacy policy / data-deletion pages (required by Meta and Google) | Served by ReelFlow at `/privacy`, `/terms`, `/data-deletion` | Set `REELFLOW_OPERATOR_NAME` |

Paid options still exist in the code (`AI_PROVIDER=openai|anthropic`, S3 beyond the free tiers, paid SMTP), but they are never the default and are labelled **paid** in the dashboard. Free tiers have limits that providers change without notice. Check the provider's console if a limit error appears; ReelFlow retries rate-limit (429) errors automatically.

---

## Contents

0. [Zero-cost setup](#zero-cost-setup)
1. [Architecture](#1-architecture)
2. [Prerequisites](#2-prerequisites)
3. [Installation (development)](#3-installation-development)
4. [Environment setup](#4-environment-setup)
5. [Database and migrations](#5-database-and-migrations)
6. [Local sync agent](#6-local-sync-agent)
7. [AI provider configuration](#7-ai-provider-configuration)
8. [Meta developer app (Facebook and Instagram)](#8-meta-developer-app-facebook--instagram)
9. [Google Cloud and the YouTube API](#9-google-cloud--youtube-api)
10. [OAuth callbacks](#10-oauth-callbacks)
11. [Queues and scheduler](#11-queues-and-scheduler)
12. [Storage](#12-storage)
13. [Email](#13-email)
14. [How publishing works (reliability)](#14-how-publishing-works-reliability)
15. [Testing](#15-testing)
16. [Deployment](#16-deployment)
17. [Troubleshooting](#17-troubleshooting)
18. [Security](#18-security)
19. [Platform approvals, quotas and limitations](#19-platform-approvals-quotas-and-limitations)

---

## 1. Architecture

```
 Your computer                              Cloud server (Ubuntu)
┌────────────────────┐   HTTPS + device   ┌───────────────────────────────────────────────┐
│ sync-agent (Python)│ ── token, chunked ─▶│ Laravel 12 app                                │
│  watches folders   │   resumable upload  │  /api/sync/*  → upload sessions → assemble     │
│  SHA-256, state DB │                     │  Video library · Content Studio · Calendar    │
└────────────────────┘                     │  Posting rules · Accounts · Analytics · Logs  │
                                           │                                               │
                                           │ Redis queues (Horizon)                        │
                                           │  ingest → media (FFmpeg) → ai (captions)      │
                                           │  publish-facebook / -instagram / -youtube     │
                                           │  notifications (SMTP)                         │
                                           │                                               │
                                           │ Scheduler (cron, every minute)                │
                                           │  reels:plan   fill posting slots              │
                                           │  reels:dispatch  claim due posts → queues     │
                                           │  health checks, metrics, daily summary        │
                                           └──────┬───────────────┬─────────────┬──────────┘
                                                  │               │             │
                                         MySQL/MariaDB   Private S3 bucket   Meta / Google APIs
```

| Layer | Where |
|---|---|
| Domain enums and state machines | `app/Enums`, `app/Models/Concerns/HasStateMachine.php` |
| Sync API and chunked uploads | `app/Http/Controllers/Api/SyncController.php`, `app/Services/Sync` |
| Storage abstraction | `app/Services/Storage/VideoStorage.php` |
| FFmpeg metadata, thumbnails, frames | `app/Services/Media` |
| AI captions | `app/Services/Ai` (provider interface, prompt, schema validator) |
| Platform adapters | `app/Services/Publishing/Publishers/*` implementing `SocialPublisherInterface` |
| Publishing orchestration | `app/Services/Publishing/PublishingService.php` |
| Scheduling | `app/Services/Scheduling` (slot calculator, planner, dispatcher) |
| OAuth | `app/Services/Platforms` |
| Notifications | `app/Notifications`, `app/Services/Notifications` |
| Sync agent | `sync-agent/` (see `sync-agent/README.md`) |

**Video lifecycle:** `discovered → uploading → uploaded → processing → ready` (or `failed`).
**Post lifecycle:** `scheduled → publishing → [processing] → published`, with `retrying`, `failed` and `cancelled` branches. Invalid transitions are refused, and every status change is written to the audit log.

---

## 2. Prerequisites

| Component | Version |
|---|---|
| PHP | 8.2 or newer (8.3 recommended in production). Extensions: `bcmath ctype curl dom fileinfo intl mbstring openssl pcntl pdo_mysql posix tokenizer xml zip` (`redis` optional) |
| Composer | 2.x |
| Node.js | 20+ (only to build CSS/JS) |
| MySQL / MariaDB | MySQL 8.0+ or MariaDB 10.4+ |
| Redis | 6+ (queues, cache, Horizon) |
| FFmpeg + FFprobe | 4.4+ (`sudo apt install ffmpeg`) |
| Python | 3.10+ on the computer running the sync agent |

Docker is not required.

---

## 3. Installation (development)

```bash
git clone <repo> reelflow && cd reelflow
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
# edit .env: database, Redis, mail (MAIL_MAILER=log for dev), VIDEO_STORAGE_DISK=videos
php artisan migrate
php artisan reels:create-user you@example.com --name="Your Name" --admin   # asks for a password (12+ chars)

# three terminals:
php artisan serve
php artisan horizon          # queue workers
php artisan schedule:work    # scheduler (cron in production)
```

Open http://127.0.0.1:8000 and sign in. There is no public registration: the admin invites users under **Admin → Users** (each gets a private workspace with its own limits), or creates them with `reels:create-user`.

---

## 4. Environment setup

Every setting is documented inline in [`.env.example`](.env.example). These are the most important groups:

- **App**: `APP_URL` must be the public HTTPS URL, because OAuth callbacks and signed media URLs are built from it. Keep `APP_TIMEZONE=UTC` and set the display timezone with `REELFLOW_TIMEZONE` (default `Asia/Kolkata`).
- **Queues**: `QUEUE_CONNECTION=redis` for short jobs, and `QUEUE_LONG_CONNECTION=redis-long` for publishing uploads that can run up to an hour.
- **Storage**: `VIDEO_STORAGE_DISK=videos` (the server's own disk, free). `videos_s3` plus the `AWS_*` values is only needed for a bucket.
- **AI**: `AI_PROVIDER`, `AI_MODEL`, `AI_API_KEY` and the optional cost and budget limits.
- **Meta / Google**: app credentials and redirect URIs.
- **SMTP**: the `MAIL_*` values.

Runtime preferences live in the dashboard and are stored in the database, not in `.env`. These include notification recipients and toggles, AI language/audience/tone, approval mode, cross-posting and the global pause.

---

## 5. Database and migrations

```bash
php artisan migrate            # create tables
php artisan migrate:status
```

| Table | Purpose |
|---|---|
| `videos`, `video_sources` | Library: one row per unique file (unique SHA-256), plus every local path where it was found |
| `upload_sessions`, `upload_chunks` | Resumable chunked uploads from the agent |
| `sync_devices` | Agent credentials (HMAC-SHA256 hash only), last seen, errors |
| `social_accounts` | Pages, Instagram accounts and channels with encrypted tokens, scopes and status |
| `posting_rules` | One per account: limits, windows, slots, weekdays, retry and reuse |
| `ai_generations`, `ai_contents` | AI runs (model, prompt version, tokens, cost) and per-platform captions with history |
| `scheduled_posts` | One row per video × account (× deliberate reuse number), stage, remote IDs, URLs, errors |
| `publish_attempts` | Each attempt: HTTP status, sanitised response metadata, error category, retry decision |
| `post_metrics` | Periodic metric snapshots (NULL = not available) |
| `activity_logs`, `notification_logs`, `app_settings` | Audit trail, email history, runtime settings |

All times are stored in UTC, and the database session timezone is forced to `+00:00`. `scheduled_posts` has a unique key on (`video_id`, `social_account_id`, `reuse_sequence`), so the same video can't be scheduled twice on one account unless reuse is explicitly allowed.

---

## 6. Local sync agent

The agent lives in [`sync-agent/`](sync-agent/README.md) and is pure Python with no required dependencies. It watches folders, waits until files have finished copying, hashes them and uploads them in resumable 8 MB chunks.

1. In the dashboard, go to **Sync devices**, choose **Register device**, and copy the token (it is shown once).
2. On your computer:

```bash
cd sync-agent
cp config.example.toml config.toml     # set [[folders]] label + path
cp .env.example .env                   # REELFLOW_SERVER_URL + REELFLOW_DEVICE_TOKEN
chmod 600 .env
python3 -m reelflow_agent check        # validates config, tests the connection
python3 -m reelflow_agent run          # long-running watcher (or: scan, status, retry-failed)
```

Ubuntu (systemd user service) and Windows (Task Scheduler) setup is covered in `sync-agent/README.md`.

What the server receives: the folder **label** and the **path relative to that folder**, never an absolute local path. A video keeps its folder structure as its library folder, for example `Cooking/recipes`. Duplicate files are uploaded once, and each copy is recorded as a source. Revoking a device in the dashboard stops it immediately.

**In the browser: Upload videos.** Drag a ZIP (up to 20 videos, 200 MB) or video files onto **Upload videos**. Files are sent in 4 MB chunks with automatic retries, then the server unpacks the ZIP. It skips non-video files, hidden/macOS metadata, password-protected entries and suspiciously compressed entries (zip bombs), and adds each video like any other upload (deduplicated, counted against the storage limit, captioned automatically).

**Without a computer: Google Drive import.** Under **Setup → Google Drive import**, add a Drive folder that is shared as *Anyone with the link*. Every 5 minutes the server checks it and downloads new videos itself (`reels:import-drive`). Each file is downloaded once, and content that is already in the library is detected by checksum. This requires FFmpeg on the server (`MEDIA_PROCESSING=server`). On Hostinger, see [docs/HOSTINGER.md](docs/HOSTINGER.md#5b-no-computer-at-all-ffmpeg-on-the-server--google-drive-import).

---

## 7. AI provider configuration

The business logic depends only on `App\Services\Ai\AiVisionProvider`. Built-in providers:

| `AI_PROVIDER` | Notes |
|---|---|
| `gemini` **(free, default)** | Google AI Studio free tier through Google's OpenAI-compatible endpoint. Get a key at <https://aistudio.google.com/apikey>. The default model alias is `gemini-flash-latest`; override it with `AI_MODEL` if AI Studio lists a different free model. |
| `groq` **(free)** | Groq free tier. Key from <https://console.groq.com>. Set `AI_MODEL` to a vision-capable model listed in the console. |
| `ollama` **(free, self-hosted)** | Runs on your server with no key and no quota: `curl -fsSL https://ollama.com/install.sh \| sh && ollama pull qwen3-vl:8b`. On a CPU-only VM, set `AI_MAX_FRAMES=3` and `AI_TIMEOUT=600`; a caption can take a few minutes. |
| `compatible` (free or paid) | Any other OpenAI-compatible endpoint, such as OpenRouter's free models: set `AI_BASE_URL` and `AI_MODEL`. |
| `none` (free) | AI disabled. Videos become ready and you write captions in the Content Studio. |
| `openai` *(paid)* | OpenAI API. |
| `anthropic` *(paid)* | Uses the official Anthropic PHP SDK (default model `claude-opus-5-5`), with structured outputs and server-side refusal fallback. |

The OpenAI-compatible providers request `json_schema` output where supported. If an endpoint rejects it, ReelFlow automatically falls back to `json_object`, then to plain instructions, and every response is still validated against the schema. Override this with `AI_JSON_MODE`.

Optional transcription: set `TRANSCRIPTION_PROVIDER=groq` (free-tier Whisper) or `compatible` (your own Whisper server), then enable **Transcribe audio** under AI settings.

How it works:

1. FFmpeg samples `AI_MAX_FRAMES` frames evenly across the video.
2. The versioned system prompt (`resources/prompts/caption_system_v1.txt`, or your custom prompt from AI settings) and the measured facts (duration, resolution, audio) are sent with the frames. The file name, folder and transcript are passed as untrusted data inside delimiters.
3. The response is validated against a strict schema (`CaptionPrompt::schema()` and `CaptionValidator`). Hashtags are normalised, the Instagram hashtag count is capped (`INSTAGRAM_MAX_HASHTAGS`, default 5), YouTube titles are trimmed to 100 characters with `<` and `>` removed, and YouTube tags are kept under 500 characters.
4. Results below `AI_LOW_CONFIDENCE_THRESHOLD` are marked `low_confidence` and are never auto-published. Review and approve them first.
5. Each run records provider, model, prompt version, tokens, duration and estimated cost (when `AI_*_COST_PER_MTOK` are set). `AI_DAILY_BUDGET_USD` stops generation for the rest of the day once the budget is spent.
6. Regenerating creates new caption rows and keeps the old ones as history. Retries reuse the same generation ID, so they never duplicate records.

AI improves relevance and consistency; it does not promise reach.

---

## 8. Meta developer app (Facebook and Instagram)

1. Create an app at <https://developers.facebook.com/apps> of type **Business**, and add the **Facebook Login for Business** product.
2. Under **Valid OAuth Redirect URIs**, add `https://YOUR_DOMAIN/oauth/meta/callback`.
3. Put the app ID and secret into `META_APP_ID` and `META_APP_SECRET`, and set `META_REDIRECT_URI`.
4. ReelFlow requests these permissions (`config/reelflow.php → meta.scopes`):

   | Permission | Why |
   |---|---|
   | `pages_show_list`, `pages_read_engagement` | List your Pages and read basic Page data |
   | `pages_manage_posts` | Publish Reels to Pages |
   | `business_management` | Pages owned through a Business portfolio |
   | `instagram_basic`, `instagram_content_publish` | Find the linked Instagram account and publish Reels |
   | `instagram_manage_insights`, `read_insights` | Metrics (optional) |

5. In **App settings → Basic**, set the Privacy Policy URL to `https://YOUR_DOMAIN/privacy`, the Terms URL to `https://YOUR_DOMAIN/terms`, and the User data deletion URL to `https://YOUR_DOMAIN/data-deletion`. ReelFlow serves these pages.
6. While the app is in **Development mode**, only people with a role on the app (admin, developer or tester) can connect. To use other accounts, complete **Business verification** and **App Review** for Advanced Access to each permission above. Meta reviewers expect a screencast of the connect-and-publish flow.
7. Instagram must be a **Business or Creator** account linked to a Facebook Page you manage.
8. In ReelFlow, open **Connected accounts → Connect Facebook / Instagram**, choose the Pages and Instagram accounts, then enable their posting rules.

Facebook Reels use the `/{page-id}/video_reels` start → upload → finish flow with Page tokens. Instagram Reels use a media container (resumable upload by default, so no public video URL is needed), poll `status_code` until `FINISHED`, then call `media_publish`. The Graph API version is set by `META_GRAPH_VERSION`.

---

## 9. Google Cloud and the YouTube API

1. In <https://console.cloud.google.com>, create a project and enable **YouTube Data API v3**.
2. Configure the **OAuth consent screen**: External, with scopes `youtube.upload` and `youtube.readonly`. Use `https://YOUR_DOMAIN/privacy` and `https://YOUR_DOMAIN/terms` as the privacy-policy and terms URLs; ReelFlow serves these pages. While it is in **Testing**, add your Google account as a test user. In Testing mode, Google refresh tokens expire after 7 days, so for unattended use switch the app to **In production**. For your own channel you don't need Google's verification: an unverified production app can be used by up to 100 accounts, and you click through the "Google hasn't verified this app" screen once when connecting. Verification (also free) is only needed for wider use.
3. Create an **OAuth client ID** of type Web application, with the authorised redirect URI `https://YOUR_DOMAIN/oauth/google/callback`.
4. Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` and `GOOGLE_REDIRECT_URI`.
5. **API audit:** videos uploaded through projects that have not passed YouTube's API compliance audit are locked to **private**. Request the audit or a quota extension through Google's *YouTube API Services – Audit and Quota Extension* form.
6. In ReelFlow, open **Connected accounts → Connect YouTube**. ReelFlow uses authorisation code with PKCE and offline access, and refreshes tokens automatically.

Uploads use the resumable protocol. The session URL is stored (encrypted), so an interrupted upload resumes from the byte YouTube confirmed instead of creating a second video.

---

## 10. OAuth callbacks

| Provider | Callback URL | Route |
|---|---|---|
| Meta | `{APP_URL}/oauth/meta/callback` | `accounts.meta.callback` |
| Google | `{APP_URL}/oauth/google/callback` | `accounts.google.callback` |

The `state` value is random, single-use, tied to your session and expires after 15 minutes. A mismatch is rejected before any token exchange. Tokens are encrypted with `APP_KEY`, so **back up `APP_KEY`**: losing it means reconnecting every account.

---

## 11. Queues and scheduler

| Queue | Connection | Jobs |
|---|---|---|
| `ingest` | `redis-long` | assemble chunks, verify SHA-256, store privately |
| `media` | `redis` | FFprobe metadata, thumbnail, format validation |
| `ai` | `redis` | caption generation |
| `publish-facebook`, `publish-instagram`, `publish-youtube` | `redis-long` | one publishing run per claimed post |
| `notifications` | `redis` | emails |

`php artisan horizon` runs two supervisors defined in `config/horizon.php`, and the Horizon dashboard is at `/horizon` (sign-in required). Long queue waits trigger Horizon email alerts to `REELFLOW_ADMIN_EMAIL`.

**Fallback without Horizon:** use `deploy/supervisor-queue-fallback.conf` (plain `queue:work`). Without Redis, set both queue connections to `database`. The behaviour is the same; there's just no Horizon dashboard.

Scheduled tasks (`routes/console.php`; `php artisan schedule:list`):

| Task | When |
|---|---|
| `reels:dispatch` | every minute: atomically claims due posts, recovers crashed claims, queues jobs |
| `reels:plan` | every 15 minutes: fills posting slots `SCHEDULE_PLANNING_DAYS` ahead |
| `reels:check-devices` | every 10 minutes: emails once when an agent is offline |
| `reels:check-accounts` | daily: verifies tokens and flags revoked accounts |
| `reels:sync-metrics` | every 6 hours |
| `reels:daily-summary` | at the time set under Email settings (display timezone) |
| `reels:cleanup` | daily: expired upload sessions and temp files; originals only if `VIDEO_CLEANUP_AFTER_DAYS` is set |

The ReelFlow tasks use `onOneServer()` (a Redis cache lock). Dispatch, planning and metrics also use `withoutOverlapping()`, so a slow run is never doubled.

**Posting rules** (per account): enable or disable, posts per day, slot times (the default example is 08:30, 11:00, 13:30, 16:30 and 20:00 Asia/Kolkata), posting window, minimum gap, active weekdays, weekend limit and slots, max posts per rolling period, video order, folder filter, retry behaviour, reuse, and YouTube visibility. Global switches: post each video to every enabled account, approval mode, and pause all publishing.

---

## 12. Storage

- Video files are **never** in `public/`. The default `videos` disk is a private directory on the server, which is free. `videos_s3` is a private bucket on any S3-compatible service. Backblaze B2 and Cloudflare R2 have free tiers of about 10 GB; AWS S3, Wasabi and Spaces are paid.
- To keep a free server's disk from filling up, `VIDEO_CLEANUP_AFTER_DAYS` (default 14 in `.env.example`) deletes an original only after **every** enabled account it is eligible for has published or cancelled it, and only if the video isn't marked reusable.
- The key is the checksum (`originals/ab/cd/<sha256>.mp4`). Metadata lives in `videos`.
- Dashboard previews and thumbnails are served through short-lived signed URLs (`SIGNED_URL_TTL_MINUTES`). A fresh URL is generated per request, so an expired one is never reused.
- Before a publish starts, the file's existence in storage is verified.
- Instagram `video_url` mode hands Meta a 3-hour signed URL. It must be reachable from the internet, so the default `resumable` mode avoids that requirement.
- Bucket settings: block all public access, enable server-side encryption, and give the IAM user only `s3:GetObject`, `s3:PutObject`, `s3:DeleteObject` and `s3:ListBucket` on that bucket.
- Originals are deleted only when `VIDEO_CLEANUP_AFTER_DAYS` is set, the video is not marked reusable, and **every** post of it is published or cancelled. Run `php artisan reels:cleanup --dry-run` to preview.

---

## 13. Email

Configure SMTP with `MAIL_*`. The free option is Gmail: turn on 2-Step Verification, create an **App Password** at <https://myaccount.google.com/apppasswords>, then use `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=587`, your Gmail address as `MAIL_USERNAME` and `MAIL_FROM_ADDRESS`, and the 16-character app password as `MAIL_PASSWORD`. Brevo's free plan (`smtp-relay.brevo.com`) also works. Under **Email settings**:

- recipients and per-type toggles (success, failure, account problems, agent offline, repeated upload failures, daily summary)
- **Validate SMTP connection** connects and authenticates without sending anything
- **Send test email** sends synchronously and shows any SMTP error directly

Emails contain the platform, file name, status, scheduled and actual times, error summary, post URL and a dashboard link. They never contain tokens or raw API responses. Delivery is logged in `notification_logs`.

---

## 14. How publishing works (reliability)

Each post moves through **stages** that are persisted immediately: `pending → prepared → uploaded → processed → publish_requested → published → confirmed`.

- Remote IDs (Facebook video ID, Instagram container ID, YouTube session URL and video ID) are saved the moment they are received.
- `publish_requested` is written **before** the call that makes a post public. If the call times out or the worker dies, the next run calls `reconcile()` instead of publishing again:
  - **Facebook:** reads `status.publishing_phase`. `not_started` means it is safe to finish; `complete` or `in_progress` means it is treated as published.
  - **Instagram:** reads the container `status_code` (`PUBLISHED` or `FINISHED`). A container can only be published once.
  - **YouTube:** queries the resumable session, which reports bytes received or the created video.
  - If the outcome cannot be determined, the post becomes **failed (manual check required)** and is never retried automatically. The post page lets you mark it published (with the URL) or confirm it is not live and publish again.
- Dispatch is concurrency-safe: a conditional `UPDATE … WHERE claimed_until < now` claims each post for exactly one worker, plus a per-post Redis lock. Claims left by crashed workers are recovered with their stage intact.
- Errors are classified as network, rate limit, upload interrupted, media processing, invalid video, token expired, missing permission, account ineligible, quota exhausted, invalid metadata, ambiguous or unknown. Transient errors retry with exponential backoff, ±20% jitter and platform Retry-After hints, up to the rule's max attempts. YouTube quota errors pause the channel until midnight Pacific time. Authorisation errors pause the account, notify you, and are not retried.
- A success on one platform never changes another platform's post.

---

## 15. Testing

```bash
php artisan test                       # 149 tests (SQLite in memory)
# against MySQL/MariaDB (create the reelflow_test DB first):
DB_CONNECTION=mariadb DB_DATABASE=reelflow_test DB_USERNAME=root DB_PASSWORD= php vendor/bin/phpunit

cd sync-agent && python3 -m unittest discover -s tests   # 62 agent tests
```

External APIs are faked in tests (`Http::fake`, a mocked PSR-18 transport for the Anthropic SDK, and a fake FFmpeg toolkit). No test makes a real post.

**Integration testing with real accounts** (manual, deliberate):

1. Use a test Facebook Page, a test Instagram professional account and an unlisted YouTube channel, with your account added as a tester or test user.
2. Set the YouTube rule's visibility to `private` or `unlisted`.
3. Upload one short vertical clip with the agent, approve its captions, then use **Schedule manually → now** for one account at a time.
4. Watch the post page (attempts, stages, remote IDs), confirm the URL opens, then delete the test post on the platform.

---

## 16. Deployment

**Shared hosting (Hostinger Business etc.):** see **[docs/HOSTINGER.md](docs/HOSTINGER.md)**. Run `deploy/hostinger/deploy.sh` from your computer with `MEDIA_PROCESSING=agent` (FFmpeg runs in the sync agent) and `REELFLOW_QUEUE_MODE=cron` (workers are started by the every-minute cron; no Redis or Supervisor needed).

**Quick install (fresh Ubuntu 24.04 VM, e.g. Oracle Always Free):**

```bash
curl -fsSLO https://raw.githubusercontent.com/sanjay-innerxpro/reelflow/main/deploy/install-server.sh
sudo DOMAIN=yourname.duckdns.org EMAIL=you@example.com bash install-server.sh
```

The installer sets up PHP 8.3, MySQL, Redis, FFmpeg, nginx with a Let's Encrypt certificate, Horizon under Supervisor, cron, nightly backups and log rotation. It generates random database and Redis passwords, builds and migrates the app, and prints the next steps. API credentials (Meta, Google, AI, SMTP) can be added to `/var/www/reelflow/shared/.env` later; until then those features show as "to do" and nothing fails. Updates use `sudo bash /var/www/reelflow/current/deploy/deploy.sh`, which takes a backup before migrating; roll back with `--rollback`.

**Readiness and monitoring:**

- `php artisan reels:doctor` checks the database, Redis, the scheduler and worker heartbeats, HTTPS, debug mode, FFmpeg, storage space, email, AI, the Meta and Google apps, accounts, devices and failed jobs. The same checklist appears on the dashboard until everything is green.
- `GET /health` returns 200 when the database, cache, scheduler and workers are alive and 503 otherwise, without revealing any configuration. Point a free uptime monitor (e.g. UptimeRobot) at it to get an email if cron or the workers stop.
- `php artisan reels:heartbeat` queues the worker heartbeat immediately, instead of waiting for the 5-minute cycle.
- CI: `.github/workflows/ci.yml` runs on every push (free for public repositories). It runs the PHP tests on SQLite and MySQL 8, code style, the asset build, the agent tests and ShellCheck.

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for the full Ubuntu VPS guide: packages, MySQL, Redis, FFmpeg, nginx and HTTPS, Supervisor or systemd, cron, backups, log rotation, deploy and rollback. Ready-to-edit files are in [`deploy/`](deploy).

Shared hosting works in the adapted modes above, as long as it offers SSH, a per-minute cron, PHP 8.2+ and MySQL. The free path is an **Oracle Cloud Always Free** VM; see [Free hosting](docs/DEPLOYMENT.md#0-free-hosting). A small VM easily handles dozens of posts per day. On 1 GB RAM machines, set `HORIZON_GENERAL_PROCESSES=1` and `HORIZON_PUBLISHING_PROCESSES=1`, add swap, and use Gemini or Groq rather than Ollama.

---

## 17. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Account shows **expired** or **revoked**; email "Action needed" | The token expired, the password changed, or the app was removed (Meta error 190), or a Google refresh token was revoked (`invalid_grant`). Use **Reconnect**, then **Retry failed** on the account. For Google apps in *Testing* mode, refresh tokens expire after 7 days, so publish the consent screen. |
| `Permission … was not granted` / Meta code 10 or 200 | The permission was declined at connect time or lacks App Review. Reconnect and allow every permission; check the app's access level. |
| `Reels can only be published to Facebook Pages` / role error | Your Page role lacks *Create content* (`CREATE_CONTENT` task). |
| Instagram "not a professional account" / not listed | Convert to a Business or Creator account and link it to the Page in Meta Business Suite. |
| Instagram `2207026` / invalid video | Unsupported codec or format. Re-encode to H.264/AAC MP4, 9:16, ≤ 15 min. |
| Instagram container `EXPIRED` / `2207020` | The container was not published within 24 hours. ReelFlow starts a new container automatically. |
| **Rate limit** (Meta codes 4, 17, 32, 613; HTTP 429) | Retries automatically after the platform's suggested wait. Lower posts per day if it persists. |
| **Quota exhausted** (YouTube `quotaExceeded` / `uploadLimitExceeded`, Instagram `2207042`) | YouTube pauses until midnight PT, Instagram retries hourly. Request a YouTube quota extension for higher volume. |
| YouTube videos stay **private** | The project hasn't passed the YouTube API audit (see §9). |
| Upload timeouts | Raise `PUBLISH_HTTP_TIMEOUT` / `request_timeout_seconds` (agent); check `client_max_body_size` in nginx (≥ chunk size + 1 MB). Interrupted uploads resume automatically. |
| Post stuck in **processing** | The platform is still processing it. After `PUBLISH_PROCESSING_TIMEOUT_MINUTES` the post is retried (Instagram gets a new container), or marked for manual review if it may already be live. |
| **Manual check required** | The publish outcome was ambiguous. Look on the platform, then use the buttons on the post page. |
| Video **failed** "Duration … outside range" or "No playable video stream" | Check `VIDEO_MIN_DURATION` / `VIDEO_MAX_DURATION`; the file may be corrupt. |
| Video **failed** "Could not run ffprobe" | Install FFmpeg or set `FFMPEG_PATH` / `FFPROBE_PATH`. |
| Nothing gets scheduled | The rule is disabled, the account isn't connected, no videos are `ready` with publishable captions (low confidence or approval mode), the video format is ineligible for that platform (see warnings on the video page), or all videos are already posted and reuse is off. |
| Nothing publishes at the scheduled time | Cron isn't running `schedule:run`, Horizon is stopped (dashboard → Queue health), **Pause all publishing** is on, or the account is paused. |
| Sync agent 401 / 403 | Wrong token, or the device was revoked. Register a new device. |
| Emails not arriving | **Validate SMTP connection**, check `notification_logs` on the Email page, and check SPF/DKIM for `MAIL_FROM_ADDRESS`. |

Logs: `storage/logs/laravel-*.log` (secrets redacted), **System logs** (audit trail) and **Failed jobs** in the dashboard, and `/horizon`.

---

## 18. Security

- **Authentication:** session login only, with no registration. Login is rate limited (5/min per account and IP), and Horizon requires sign-in. All browser forms are CSRF protected.
- **Authorisation:** policies on videos, accounts, devices and posts.
- **OAuth:** single-use `state` and PKCE (Google). Only the scopes listed above are requested.
- **Tokens:** access and refresh tokens and YouTube session URLs are encrypted at rest and hidden from serialisation. They never reach the browser, emails or logs.
- **Sync devices:** random 48-character tokens stored as HMAC-SHA256 hashes and shown once. Devices are rate limited (600 requests/min), can only use `/api/sync/*`, are limited to their own upload sessions, and can be revoked instantly.
- **Uploads:** extension and size limits, relative-path validation (no absolute paths or `..`), per-chunk and whole-file SHA-256 verification, and file-type sniffing.
- **Storage:** private disks, short-lived signed URLs, and no public listing.
- **Logs:** a Monolog processor redacts tokens, keys and URL signatures. Stored API response metadata is sanitised.
- **Audit:** every status change and admin action is recorded in `activity_logs`.
- **Production:** `APP_DEBUG=false`, HTTPS only (`SESSION_SECURE_COOKIE=true`, HSTS), `SESSION_ENCRYPT=true`, `.env` owned by the deploy user with mode 640, and database and Redis bound to localhost.
- **Backups:** nightly `deploy/backup.sh` (mysqldump with 14-day retention), plus a copy of `.env` (`APP_KEY`) stored somewhere safe. Restore instructions are in `docs/DEPLOYMENT.md`.
- **Disconnecting an account** deletes its tokens, revokes Google access, and disables its rule. For Meta, also remove the app under Facebook Settings → Business Integrations.

---

## 19. Platform approvals, quotas and limitations

- **Meta:** App Review and Business verification are needed to use accounts outside your app roles. Instagram API publishing has a rolling 24-hour limit; ReelFlow reads the live value from `content_publishing_limit` before each post. Facebook Reels must be 3–90 seconds, vertical and at least 540×960 (checked before upload).
- **YouTube:** the default 10,000 units/day quota allows only a limited number of uploads. Unaudited projects upload as private. Shorts classification is decided by YouTube from the video's format (vertical or square, within the Shorts length limit); it is not guaranteed by the API.
- **Analytics:** only metrics the APIs expose for your permissions are shown. Missing values display as "n/a", never 0. YouTube watch time needs the separate YouTube Analytics API and is not collected.
- **Platform rules change:** limits live in `app/Services/Media/PlatformRequirements.php` and `config/reelflow.php` so they're easy to update.
- **External accounts still required (all free):** a Meta developer app (App Review), a Google Cloud project with an OAuth client (verification and API audit), a Gemini or Groq key (or Ollama on the server), a Gmail App Password, a DuckDNS subdomain, and an Oracle Cloud account. Without them the related features stay inactive and show a clear message; nothing reports false success.
- **Free tiers change:** free AI model names and daily limits, and the Always Free VM shapes, are set by the providers and change over time. If a free AI tier is withdrawn, switch `AI_PROVIDER` to `ollama` (fully self-hosted) or `none`.
