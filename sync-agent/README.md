# ReelFlow Sync Agent

A small Python program that runs on your computer (Ubuntu or Windows), watches
one or more folders for new video files and uploads them to your ReelFlow
server, where they are queued for auto-publishing.

- Watches folders recursively (instantly with the optional `watchdog` package,
  otherwise by rescanning every `scan_interval_seconds`).
- Waits until a file has finished copying (size and modification time unchanged
  for `stability_seconds`, and the file can be opened for reading).
- Computes a SHA-256 checksum, registers the file with the server and uploads it
  in checksummed chunks. Interrupted uploads resume where they stopped, even
  after a restart.
- Content the server already has is never uploaded twice. If the same video is
  in two folders, both locations are registered but the bytes go up once.
- Keeps local state in SQLite, so unchanged files are not hashed again after a
  restart. Writes rotating log files.
- On shared hosting without FFmpeg (`MEDIA_PROCESSING=agent`), creates video
  metadata, thumbnails and AI frames with FFmpeg on your computer
  ([details](#shared-hosting-media_processingagent)).

The core uses only the Python standard library (Python 3.10 or newer).

## Security model

- **Outbound only.** The agent never opens a listening socket and cannot be
  controlled remotely. It makes HTTPS requests to your server and nothing else.
  Plain `http://` is refused unless the server is `localhost`/`127.0.0.1`
  (testing only). Redirects are not followed, so the token is never forwarded
  to another host.
- **No local paths leave your machine.** The server only receives the folder
  *label* you choose (for example `Phone Videos`) and the path *relative to that
  folder* (for example `2026/trip/clip.mp4`). Symlinks and Windows junctions
  that point outside a watched folder are ignored. Error messages sent with the
  heartbeat have local folder paths replaced by their labels.
- **Revocable device token.** The agent authenticates with a device token
  (`rfd_...`) created on the dashboard's **Sync Devices** page. The token can
  only register and upload videos for your account; it gives no dashboard
  access. Revoke it there at any time. A revoked agent stops by itself (exit
  code 3).
- **Token storage.** Keep the token in `.env` next to the agent, readable only
  by you: `chmod 600 .env` on Linux (the agent warns if it is readable by
  others; `run-agent.sh` fixes it). On Windows keep the agent folder inside
  your user profile. The token is never written to logs: anything that looks
  like `rfd_...` or a bearer credential is replaced by `rfd_***`.

## Install on Ubuntu

```bash
sudo apt install python3 python3-venv
cd ~/reelflow/sync-agent              # wherever you put this folder
python3 -m venv .venv
.venv/bin/pip install watchdog        # optional: instant change detection

cp config.example.toml config.toml    # then edit the [[folders]] entries
cp .env.example .env                  # then paste the server URL and token
chmod 600 .env

./run-agent.sh check                  # validate config + test the connection
./run-agent.sh scan --dry-run         # list what would be uploaded
./run-agent.sh                        # run in the foreground (Ctrl+C to stop)
```

### Run in the background (systemd user service)

```bash
mkdir -p ~/.config/systemd/user
cp deploy/reelflow-agent.service ~/.config/systemd/user/
# Edit WorkingDirectory/ExecStart if the agent is not in ~/reelflow/sync-agent
systemctl --user daemon-reload
systemctl --user enable --now reelflow-agent
loginctl enable-linger "$USER"        # optional: keep running while logged out
journalctl --user -u reelflow-agent -f
```

The unit restarts the agent on failure but not after a configuration error
(exit 2) or a revoked device (exit 3).

## Install on Windows

1. Install Python 3.11+ from <https://www.python.org/downloads/windows/>
   (tick **Add python.exe to PATH**; the `py` launcher is installed too).
2. Copy the `sync-agent` folder somewhere in your profile, for example
   `C:\Users\you\ReelFlow\sync-agent`, then in **Command Prompt**:

   ```bat
   cd %USERPROFILE%\ReelFlow\sync-agent
   py -3 -m venv .venv
   .venv\Scripts\pip install watchdog
   copy config.example.toml config.toml
   copy .env.example .env
   notepad config.toml
   notepad .env
   run-agent.bat check
   run-agent.bat
   ```

   In `config.toml`, write Windows paths with single quotes
   (`path = 'D:\Videos\ReelFlow'`) or forward slashes
   (`path = "D:/Videos/ReelFlow"`).

### Start automatically at logon (Task Scheduler)

```bat
schtasks /Create /TN "ReelFlow Sync Agent" /SC ONLOGON /RL LIMITED /F ^
  /TR "\"%USERPROFILE%\ReelFlow\sync-agent\.venv\Scripts\pythonw.exe\" -m reelflow_agent --config \"%USERPROFILE%\ReelFlow\sync-agent\config.toml\" run"
```

`pythonw.exe` runs without a console window. Logs are in
`%LOCALAPPDATA%\ReelFlowAgent\logs\agent.log`. In Task Scheduler you can also
open the task's **Settings** tab and enable *If the task fails, restart every
1 minute*. To stop it: `schtasks /End /TN "ReelFlow Sync Agent"`. (`/End` terminates the process immediately; that is safe, because
progress is saved after every chunk and the upload resumes on the next start.)

Because the task runs from a different working directory, pass `--config`
explicitly as above (the `.env` next to the agent is found automatically).

Alternatively, [NSSM](https://nssm.cc/) can install it as a Windows service:
`nssm install ReelFlowAgent "C:\...\.venv\Scripts\python.exe" -m reelflow_agent --config "C:\...\config.toml" run`.
A service runs as a different account, so set `state_dir` explicitly and make
sure that account can read the watched folders.

## Configuration reference

`config.toml` (see `config.example.toml`). Global options must come **before**
the first `[[folders]]` block, because in TOML every key after `[[folders]]`
belongs to that folder. Unknown options are reported as errors so typos do not
go unnoticed.

| Option | Default | Meaning |
|---|---|---|
| `[[folders]]` `label`, `path` | (required) | Watched folders. Label: 1–120 letters, digits, spaces, `_ - .`; unique. Path: `~` and env vars expanded; relative paths are relative to the config file. Folders must not be nested. |
| `extensions` | `["mp4","mov","webm","m4v"]` | Case-insensitive. The server's list (from the heartbeat) is enforced as well. |
| `ignore_patterns` | `[".*","*.part","*.crdownload","*.tmp","~$*"]` | Globs matched against every path component; patterns containing `/` match the whole relative path. |
| `stability_seconds` | `10` | How long size and modification time must stay unchanged. |
| `scan_interval_seconds` | `60` | Full rescan interval (the detection delay when `watchdog` is not installed). |
| `heartbeat_interval_seconds` | `60` | How often the agent reports to the server. |
| `max_concurrent_uploads` | `1` | 1–8 files at a time. |
| `max_retries` | `8` | Retries per request (429/5xx/network) and failed attempts per file before it is marked `failed`. |
| `retry_backoff_base_seconds` / `retry_backoff_max_seconds` | `2` / `300` | Exponential backoff with jitter. `Retry-After` is honoured. |
| `state_dir` | `~/.local/state/reelflow-agent` (Linux), `%LOCALAPPDATA%\ReelFlowAgent` (Windows) | SQLite state, lock file and logs. |
| `log_dir` | `<state_dir>/logs` | Rotating `agent.log` (`log_max_bytes` 5 MB × `log_backup_count` 5). |
| `log_level` | `INFO` | `DEBUG`, `INFO`, `WARNING`, `ERROR`. |
| `request_timeout_seconds` | `120` | Per request, including sending one whole chunk. |
| `verify_tls` | `true` | Only disable for a local test server. |
| `use_watchdog` | `true` | Use `watchdog` when installed. |
| `completion_poll_attempts` / `completion_poll_interval_seconds` | `3` / `2` | After an upload completes, how often to ask whether the server accepted it. Unconfirmed uploads are checked again at every scan. |
| `ffmpeg_path` / `ffprobe_path` | found on `PATH` | FFmpeg executables, only needed when the server uses `MEDIA_PROCESSING=agent` (see below). A bare name is looked up on `PATH`; a path may be relative to the config file. If only `ffmpeg_path` is set, `ffprobe` next to it is used. |
| `ffmpeg_timeout_seconds` | `300` | Time limit for each `ffmpeg`/`ffprobe` call. |

Environment variables (or `.env` next to the agent / next to `config.toml`;
real environment variables win):

| Variable | Meaning |
|---|---|
| `REELFLOW_SERVER_URL` | Base URL of the ReelFlow app, e.g. `https://reelflow.example.com` |
| `REELFLOW_DEVICE_TOKEN` | Device token from **Sync Devices** (`rfd_...`) |
| `REELFLOW_AGENT_CONFIG` | Optional default for `--config` |
| `HTTPS_PROXY` / `NO_PROXY` | Standard proxy settings (honoured automatically) |

## Commands

```
python -m reelflow_agent [--config PATH] [--log-level LEVEL] <command>
```

| Command | What it does |
|---|---|
| `run` | Long-running: watch, rescan periodically, send heartbeats, upload. Ctrl+C / SIGTERM finishes the current chunk, saves state and exits. |
| `scan [--dry-run] [--no-wait]` | Rescan everything, upload all pending files once, then exit. `--dry-run` only lists files and changes nothing (no network). `--no-wait` does not wait for files that are still being copied. |
| `status [--limit N]` | Counts per state, per folder, video media counts (agent media mode), and recent problems. Works offline. |
| `retry-failed [--include-rejected]` | Put `failed` (and optionally `rejected`/`skipped`) files back in the queue; also retries failed video media. |
| `check` | Validate the config, check the folders and state dir, find FFmpeg, send a heartbeat and print the result, including whether the server requires agent media (exit 1 if it does and FFmpeg is missing). |

`run-agent.sh` / `run-agent.bat` use `.venv` if present and default to `run`.

Exit codes: `0` ok, `1` error (e.g. server unreachable), `2` configuration
error, `3` device revoked, `4` token rejected (401), `130` interrupted.

### File states

| State | Meaning |
|---|---|
| `pending` | Stable and queued (possibly waiting for a retry time) |
| `hashing` / `uploading` | In progress (reset to `pending` after a crash; uploads resume) |
| `done` | Server has the file |
| `failed` | Gave up after `max_retries` failed attempts; see `status`, then `retry-failed` |
| `rejected` | The server refused the file (invalid path/extension/size, or not a real video); not retried |
| `skipped` | Not accepted by the server's limits (extension, max size) or empty. Re-checked automatically if the server's limits change |

## How it works

1. **Discover**: new or changed files (by size + modification time compared to
   the state DB) are watched until they are stable. Files that are unchanged
   since the last run are not touched or re-hashed.
2. **Hash**: SHA-256 is streamed in 1 MB blocks. The file is checked again
   afterwards; if it changed, it goes back to the stability check.
3. **Register**: `POST /api/sync/videos` with `filename`, `folder_label`,
   `relative_path`, `size`, `checksum_sha256`. If the server already has the
   content (`upload: null`), the file is done.
4. **Upload**: chunks the server does not have yet (`received_chunks`) are read
   from disk and sent with `X-Chunk-Sha256`. Size and modification time are
   checked before every chunk; if the file changed, the session is aborted and
   the file is queued again once it is stable.
5. **Complete**: if the server reports missing chunks they are re-sent. The
   agent then asks for the video status; if assembly failed (checksum mismatch)
   the file is re-hashed and uploaded again, up to `max_retries` times.

A heartbeat is sent at startup and every `heartbeat_interval_seconds` with file
counts and the last error, and returns the server's limits (chunk size, allowed
extensions, maximum file size).

## Shared hosting (MEDIA_PROCESSING=agent)

On shared hosting (for example Hostinger Business) the server cannot run
FFmpeg. There the app is configured with `MEDIA_PROCESSING=agent`, and the
agent does the media work on **the computer it runs on**:

- **FFmpeg must be installed on the computer running the agent** (both
  `ffmpeg` and `ffprobe`):
  - Ubuntu/Debian: `sudo apt install ffmpeg`
  - Windows: `winget install Gyan.FFmpeg`, or download a build from
    <https://www.gyan.dev/ffmpeg/builds/>, unzip it (for example to
    `C:\ffmpeg`) and set `ffmpeg_path = 'C:\ffmpeg\bin\ffmpeg.exe'` and
    `ffprobe_path = 'C:\ffmpeg\bin\ffprobe.exe'` in `config.toml`. The agent
    also looks in the usual winget, Chocolatey, Scoop and `C:\ffmpeg\bin`
    locations and in an `ffmpeg\bin` folder next to the agent.
- Run `check`: it shows where FFmpeg was found and whether the server
  requires agent media (`Media: server requires agent media ...`).

The server announces the mode in the heartbeat (`media_processing`, plus
`ai_frames`, `frame_width`, `thumbnail_width`). Older servers that do not send
it are treated as `server` mode: nothing changes. In agent mode, for every file
the server marks with `needs_media` (on registration, in status checks, and via
a periodic lookup that also covers videos uploaded before the server switched
modes) the agent:

1. runs `ffprobe` and builds the metadata (width/height after rotation,
   duration, frame rate, codecs, audio), exactly like the server would;
2. extracts a thumbnail (`thumbnail_width` wide, at `min(1 s, duration/2)`) and
   `ai_frames` caption frames (`frame_width` wide, from the middle of equal
   segments) as JPEGs into a private temporary folder that is always deleted;
3. sends them with `POST /api/sync/videos/{id}/media` (multipart). Only the
   JSON metadata and images are sent, never local paths.

This happens right after a file is registered/uploaded and never blocks or
fails the upload itself; the media is tracked separately (`status` shows
`pending` / `sent` / `failed`). Network errors and 5xx responses are retried
with the normal backoff; a 422 (validation) response marks the media
`failed` (use `retry-failed` after fixing the cause). Duplicate files the
server already has get their media without the bytes being uploaded again.

If FFmpeg is missing, the agent logs one clear error (also reported to the
dashboard in the heartbeat), keeps uploading videos, keeps their media
pending and sends it automatically once FFmpeg is found (no restart needed if
it is installed to one of the searched locations).

## Troubleshooting

- **`401` / "token rejected"**: the token is wrong, mistyped or was replaced.
  Create a new token on **Sync Devices**, put it in `.env` and restart. While
  the token is rejected, `run` keeps sending heartbeats with increasing delay
  and uploads nothing, so it recovers by itself once the token is valid.
- **`403 device_revoked`**: the device was revoked on the dashboard. The agent
  exits with code 3 (systemd will not restart it). Create a new device and token.
- **A file never uploads / stays "copying"**: it must stay unchanged for
  `stability_seconds` and be readable. Programs that keep a file open and keep
  writing to it (screen recorders, some cameras over USB/MTP) delay this until
  they finish. Partial downloads (`*.part`, `*.crdownload`) are ignored by
  default. Run with `--log-level DEBUG` to see which files are being watched.
- **Large files**: memory use is constant (streamed hashing; one chunk in
  memory per upload). Files above the server's `max_file_size_bytes` are marked
  `skipped`. On slow connections raise `request_timeout_seconds` so one chunk
  (8 MB by default on the server) can be sent within the timeout. Interrupted
  uploads resume from the last confirmed chunk (until the server-side session
  expires, 48 h by default).
- **Proxy**: set `HTTPS_PROXY=http://proxy.example:3128` (and `NO_PROXY` if
  needed) in the environment; Python's `urllib` uses it automatically.
- **TLS errors**: check the system clock and certificates. On Windows, installing
  the latest Python usually fixes outdated certificate stores. Do not set
  `verify_tls = false` against a real server.
- **"another reelflow agent is already running"**: only one `run`/`scan` may use
  a state directory at a time. Stop the service before running `scan` by hand.
- **Unmounted drive**: a missing watched folder is logged and skipped; its
  known files are kept until the folder is back.
- **Status and logs**: `python -m reelflow_agent status`; logs in
  `<state_dir>/logs/agent.log`.

## Development

```bash
cd sync-agent
python3 -m unittest discover -s tests -v
```

The tests need no network and no third-party packages: they start a fake
ReelFlow API (`tests/fake_server.py`) on a random `127.0.0.1` port, implementing
the same contract as `app/Http/Controllers/Api/SyncController.php`, including
failure injection (429/5xx, validation errors, revoked device, corrupted
assembly) and the multipart media endpoint. FFmpeg is faked in the tests. To
also run the real-FFmpeg integration test (it creates a tiny test video with
`lavfi testsrc`):

```bash
REELFLOW_TEST_FFMPEG=/usr/bin/ffmpeg REELFLOW_TEST_FFPROBE=/usr/bin/ffprobe \
  python3 -m unittest discover -s tests -v
```
