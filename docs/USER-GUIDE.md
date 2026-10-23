# ReelFlow user guide

For people using ReelFlow day to day. It assumes someone has already installed it and
sent you an invitation — if you are setting the server up, read the [README](../README.md)
instead.

The same guide is in the app under **User guide** in the sidebar, with links to every page.

---

## The flow

```
1 Add videos  →  2 Processed  →  3 Captions written  →  4 You review  →  5 Scheduled  →  6 Published
   sync agent      duration,        AI writes one          edit and          posting        Facebook,
   browser         thumbnail,       caption per            approve           rules pick     Instagram,
   Drive           checks           platform                                 the slots      YouTube
```

Everything after step 1 happens on the server. Once a video is uploaded you can switch
your computer off.

Your sign-in belongs to one **workspace**: your videos, accounts, rules and settings.
Nobody else sees them, and you see nobody else's.

---

## 1 · Get your videos in

Three ways, and you can mix them:

| Way | Good for | Where |
|---|---|---|
| **Upload page** | A few files, or a ZIP of up to 20 videos (200 MB) | *Upload videos* |
| **Sync agent** | A folder on your computer that you keep adding to — it uploads on its own | *Sync devices* |
| **Google Drive** | A shared Drive folder that someone else fills | *Google Drive import* |

Large uploads are sent in chunks, so a dropped connection resumes instead of starting over.
Duplicates of a video already in the library are skipped.

Each workspace has a storage limit — the sidebar shows how much is left. Files are deleted
automatically once every enabled account has posted them, so space frees itself.

## 2 · Processing

The server reads each file's duration, resolution and audio, and makes a thumbnail.

- A file no platform will accept (not a vertical short, too long, unreadable) is marked
  **failed**, with the reason on the video page. Nothing is published.
- Everything else becomes **ready**.

## 3 · Captions

Frames are sampled across the video and sent to the AI model together with your language,
tone, audience and brand context from **AI settings**. It returns:

- a caption and hashtags for **Facebook**,
- a caption and hashtags for **Instagram**,
- a title, description, hashtags and tags for **YouTube**.

Captions are trimmed to each platform's limits automatically, and the trim is noted on the
video.

- **Needs review** means the model was not confident about what the video shows. Nothing is
  published until you look at it.
- If captions fail entirely the video stays usable — write them yourself. Publishing never
  requires AI.
- **Generate again** on the video page runs it once more. Earlier versions are kept.

## 4 · Review and approve

Open a video to edit any caption. Your edits are yours: a later generation never overwrites
them.

If **require approval** is on, a post is only scheduled once you approve its caption. With it
off, anything valid goes out on its own.

## 5 · Rules and scheduling

Each connected account has a posting rule:

- how many posts a day (weekdays and weekends separately),
- the hours of the day posts may go out,
- the exact slot times,
- the minimum gap between posts,
- which weekdays are active.

ReelFlow puts each video in the next free slot per account, so one video can reach Facebook,
Instagram and YouTube at different times. The **calendar** shows what is queued.

Nothing goes out while **publishing is paused**, and the same video is never posted twice to
the same account.

## 6 · Publishing and after

Posts go out through the official Meta and YouTube APIs — no passwords, no browser
automation.

- A post that fails for a temporary reason (a timeout, a rate limit) is retried with growing
  gaps.
- One that fails for good appears under **Failed posts** with the platform's own message, and
  you get an email.
- Views and engagement are collected every few hours where the platform's API provides them,
  and shown under **Analytics**. `n/a` means the platform does not give that number — it does
  not mean zero.

---

## When something looks wrong

| What you see | What it means | What to do |
|---|---|---|
| Video stuck on **processing** | A worker has not picked it up yet, or FFmpeg could not read it | Wait a few minutes, then check the activity log |
| Captions say **needs review** | The model was not confident | Open the video, fix the caption, approve it |
| Captions **failed** | Usually a free-tier rate limit, or an unusable answer from the model | **Generate again** later, or write the captions yourself |
| **Account needs reconnecting** | The platform's token expired or permissions changed | Reconnect it under *Connected accounts*; queued posts resume |
| Nothing is being published | Publishing is paused, the daily limit is reached, or captions await approval | Check the dashboard banner and your posting rules |
| **Out of storage** | The workspace hit its limit; new uploads are refused | Delete videos you no longer need, or ask your administrator to raise the limit |

Emails tell you about failures, accounts needing attention, an offline sync agent, and a
daily summary. Choose which ones you get under **Email settings**.

---

## For administrators

- **Overview** (`/admin`) — caption and publishing health across every workspace: generations
  per day by outcome, why generations failed, publishing per platform, storage per workspace,
  and the latest failed generations including what the model actually returned.
- **Users** — invite people (each gets their own empty workspace), set storage, account and
  daily AI limits, suspend and resume.
- **Failed jobs** — background work that gave up, with a retry button.

If many caption generations fail at once, open **Overview** first: the failure reasons there
separate a provider quota problem (wait, or raise the limit) from a model problem (the answer
was unusable — try another model) from a configuration problem (key rejected, endpoint
unreachable).
