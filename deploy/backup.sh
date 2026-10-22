#!/usr/bin/env bash
# Nightly MySQL backup with 14-day retention. Credentials come from
# ~/.my.cnf of the user running it (chmod 600), never from this script.
#   [client]
#   user=reelflow_backup
#   password=...
set -euo pipefail
DB="${DB_DATABASE:-reelflow}"
DIR="${BACKUP_DIR:-/var/backups/reelflow}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
mkdir -p "$DIR"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
mysqldump --single-transaction --quick --routines --triggers "$DB" | gzip -9 > "$DIR/$DB-$STAMP.sql.gz"
find "$DIR" -name "$DB-*.sql.gz" -mtime +"$KEEP_DAYS" -delete
echo "$(date -u) backup ok: $DIR/$DB-$STAMP.sql.gz"
# Optional off-site copy (bucket separate from the video bucket):
# aws s3 cp "$DIR/$DB-$STAMP.sql.gz" "s3://my-backups/reelflow/" --only-show-errors
