#!/usr/bin/env bash
# =============================================================================
# Install a free static FFmpeg build (https://johnvansickle.com/ffmpeg/) into
# ~/bin on Hostinger shared hosting, so the server can process videos itself
# (MEDIA_PROCESSING=server) and import from Google Drive without a computer.
# Run on YOUR computer:
#
#   SSH_HOST=1.2.3.4 SSH_USER=u123456789 bash deploy/hostinger/install-ffmpeg.sh
#
# Then set in <APP_DIR>/shared/.env the lines it prints and run artisan optimize.
# =============================================================================
set -euo pipefail

SSH_HOST="${SSH_HOST:?Set SSH_HOST}"
SSH_USER="${SSH_USER:?Set SSH_USER}"
SSH_PORT="${SSH_PORT:-65002}"

ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" bash -s <<'REMOTE'
set -euo pipefail
mkdir -p "$HOME/bin"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
curl -fsSL -m 600 -o "$TMP/ff.tar.xz" https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz
# Hostinger has no xz binary; Python's tarfile reads .tar.xz natively.
python3 - "$TMP/ff.tar.xz" "$HOME/bin" <<'PY'
import sys, tarfile
with tarfile.open(sys.argv[1]) as tar:
    for member in tar.getmembers():
        if member.isfile() and member.name.rsplit("/", 1)[-1] in ("ffmpeg", "ffprobe"):
            member.name = member.name.rsplit("/", 1)[-1]
            tar.extract(member, path=sys.argv[2])
PY
chmod 755 "$HOME/bin/ffmpeg" "$HOME/bin/ffprobe"
"$HOME/bin/ffmpeg" -version | head -1
echo
echo "Set these in <APP_DIR>/shared/.env, then run: php artisan optimize"
echo "  MEDIA_PROCESSING=server"
echo "  FFMPEG_PATH=$HOME/bin/ffmpeg"
echo "  FFPROBE_PATH=$HOME/bin/ffprobe"
REMOTE
