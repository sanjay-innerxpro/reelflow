"""Local sync state (SQLite, stdlib sqlite3) and a single-instance lock."""

from __future__ import annotations

import json
import os
import sqlite3
import threading
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable

from .scanner import make_path_key

PENDING = "pending"
HASHING = "hashing"
UPLOADING = "uploading"
DONE = "done"
FAILED = "failed"
REJECTED = "rejected"
SKIPPED = "skipped"
ALL_STATUSES = (PENDING, HASHING, UPLOADING, DONE, FAILED, REJECTED, SKIPPED)
ACTIVE_STATUSES = (PENDING, HASHING, UPLOADING)

SCHEMA_VERSION = 1
_SCHEMA = """
CREATE TABLE IF NOT EXISTS files (
    path_key         TEXT PRIMARY KEY,
    label            TEXT NOT NULL,
    relative_path    TEXT NOT NULL,
    size             INTEGER NOT NULL,
    mtime_ns         INTEGER NOT NULL,
    sha256           TEXT,
    status           TEXT NOT NULL,
    video_id         TEXT,
    session_id       TEXT,
    stale_session_id TEXT,
    server_status    TEXT,
    attempts         INTEGER NOT NULL DEFAULT 0,
    last_error       TEXT,
    next_retry_at    REAL NOT NULL DEFAULT 0,
    created_at       REAL NOT NULL,
    updated_at       REAL NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_files_status ON files(status, next_retry_at);
CREATE INDEX IF NOT EXISTS idx_files_sha ON files(sha256);
CREATE INDEX IF NOT EXISTS idx_files_label ON files(label);
CREATE TABLE IF NOT EXISTS meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
"""

_UPDATABLE = {
    "size", "mtime_ns", "sha256", "status", "video_id", "session_id", "stale_session_id",
    "server_status", "attempts", "last_error", "next_retry_at",
}


@dataclass(frozen=True)
class FileRecord:
    path_key: str
    label: str
    relative_path: str
    size: int
    mtime_ns: int
    sha256: str | None
    status: str
    video_id: str | None
    session_id: str | None
    stale_session_id: str | None
    server_status: str | None
    attempts: int
    last_error: str | None
    next_retry_at: float
    created_at: float
    updated_at: float

    @classmethod
    def from_row(cls, row: sqlite3.Row) -> "FileRecord":
        return cls(**{k: row[k] for k in row.keys()})


class StateDB:
    """Thread-safe wrapper around a single SQLite connection."""

    def __init__(self, path: str | os.PathLike[str], *, clock=time.time):
        self.path = Path(path)
        self.clock = clock
        self.path.parent.mkdir(parents=True, exist_ok=True)
        try:
            os.chmod(self.path.parent, 0o700)
        except OSError:
            pass
        self._lock = threading.RLock()
        self._conn = sqlite3.connect(str(self.path), timeout=30, check_same_thread=False, isolation_level=None)
        self._conn.row_factory = sqlite3.Row
        with self._lock:
            self._conn.execute("PRAGMA journal_mode=WAL")
            self._conn.execute("PRAGMA synchronous=NORMAL")
            self._conn.executescript(_SCHEMA)
            self._conn.execute(f"PRAGMA user_version={SCHEMA_VERSION}")

    def close(self) -> None:
        with self._lock:
            self._conn.close()

    def __enter__(self) -> "StateDB":
        return self

    def __exit__(self, *exc: Any) -> None:
        self.close()

    # -- basic access -----------------------------------------------------

    def _one(self, sql: str, params: Iterable[Any] = ()) -> FileRecord | None:
        with self._lock:
            row = self._conn.execute(sql, tuple(params)).fetchone()
        return FileRecord.from_row(row) if row else None

    def _many(self, sql: str, params: Iterable[Any] = ()) -> list[FileRecord]:
        with self._lock:
            rows = self._conn.execute(sql, tuple(params)).fetchall()
        return [FileRecord.from_row(r) for r in rows]

    def get(self, path_key: str) -> FileRecord | None:
        return self._one("SELECT * FROM files WHERE path_key = ?", (path_key,))

    def all(self) -> list[FileRecord]:
        return self._many("SELECT * FROM files ORDER BY path_key")

    def matches(self, path_key: str, size: int, mtime_ns: int) -> bool:
        """True if we already know this file with the same size and mtime."""
        with self._lock:
            row = self._conn.execute(
                "SELECT 1 FROM files WHERE path_key = ? AND size = ? AND mtime_ns = ?", (path_key, size, mtime_ns)
            ).fetchone()
        return row is not None

    def update(self, path_key: str, **fields: Any) -> None:
        bad = set(fields) - _UPDATABLE
        if bad:
            raise ValueError(f"cannot update column(s): {', '.join(sorted(bad))}")
        if not fields:
            return
        fields["updated_at"] = self.clock()
        cols = ", ".join(f"{k} = ?" for k in fields)
        with self._lock:
            self._conn.execute(f"UPDATE files SET {cols} WHERE path_key = ?", (*fields.values(), path_key))

    def delete(self, path_key: str) -> None:
        with self._lock:
            self._conn.execute("DELETE FROM files WHERE path_key = ?", (path_key,))

    # -- lifecycle --------------------------------------------------------

    def record_stable(self, label: str, relative_path: str, size: int, mtime_ns: int) -> tuple[FileRecord, bool]:
        """Record a file that has finished copying.

        Returns (record, changed). Unchanged files (same size+mtime) keep their
        hash and status so nothing is re-hashed across restarts. Changed files
        are reset to pending; an open server session is remembered for abort.
        """
        key = make_path_key(label, relative_path)
        now = self.clock()
        with self._lock:
            existing = self.get(key)
            if existing is None:
                self._conn.execute(
                    "INSERT INTO files (path_key, label, relative_path, size, mtime_ns, status, created_at, updated_at)"
                    " VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    (key, label, relative_path, size, mtime_ns, PENDING, now, now),
                )
                return self.get(key), True  # type: ignore[return-value]
            if existing.size == size and existing.mtime_ns == mtime_ns:
                return existing, False
            stale = existing.session_id or existing.stale_session_id
            self._conn.execute(
                "UPDATE files SET size = ?, mtime_ns = ?, sha256 = NULL, status = ?, video_id = NULL,"
                " session_id = NULL, stale_session_id = ?, server_status = NULL, attempts = 0, last_error = NULL,"
                " next_retry_at = 0, updated_at = ? WHERE path_key = ?",
                (size, mtime_ns, PENDING, stale, now, key),
            )
            return self.get(key), True  # type: ignore[return-value]

    def reset_interrupted(self) -> int:
        """After a crash/restart, put files that were mid-hash/mid-upload back to pending.

        ``session_id`` is kept so the upload resumes where it stopped.
        """
        with self._lock:
            cur = self._conn.execute(
                "UPDATE files SET status = ?, next_retry_at = 0, updated_at = ? WHERE status IN (?, ?)",
                (PENDING, self.clock(), HASHING, UPLOADING),
            )
            return cur.rowcount

    def due(self, now: float | None = None, *, limit: int = 10, exclude: Iterable[str] = ()) -> list[FileRecord]:
        """Pending files whose retry time has come, oldest first."""
        now = self.clock() if now is None else now
        excluded = set(exclude)
        out: list[FileRecord] = []
        for rec in self._many(
            "SELECT * FROM files WHERE status = ? AND next_retry_at <= ? ORDER BY next_retry_at, created_at",
            (PENDING, now),
        ):
            if rec.path_key in excluded:
                continue
            out.append(rec)
            if len(out) >= limit:
                break
        return out

    def next_due_time(self) -> float | None:
        with self._lock:
            row = self._conn.execute("SELECT MIN(next_retry_at) FROM files WHERE status = ?", (PENDING,)).fetchone()
        return row[0] if row and row[0] is not None else None

    def awaiting_server(self, limit: int = 20) -> list[FileRecord]:
        """Files whose upload completed but whose server-side result is not confirmed yet."""
        return self._many(
            "SELECT * FROM files WHERE status = ? AND video_id IS NOT NULL"
            " AND (server_status IS NULL OR server_status NOT IN ('uploaded', 'processing', 'ready', 'cancelled'))"
            " ORDER BY updated_at LIMIT ?",
            (DONE, limit),
        )

    def keys_for_label(self, label: str) -> list[str]:
        with self._lock:
            rows = self._conn.execute("SELECT path_key FROM files WHERE label = ?", (label,)).fetchall()
        return [r[0] for r in rows]

    def prune_missing(self, label: str, present_keys: set[str]) -> list[FileRecord]:
        """Forget rows of ``label`` whose file is gone. Returns the removed records."""
        removed = []
        with self._lock:
            for key in self.keys_for_label(label):
                if key not in present_keys:
                    rec = self.get(key)
                    if rec is not None:
                        removed.append(rec)
                    self.delete(key)
        return removed

    def prune_labels(self, keep_labels: Iterable[str]) -> int:
        """Forget files of folders that are no longer configured."""
        keep = list(keep_labels)
        with self._lock:
            if keep:
                marks = ",".join("?" * len(keep))
                cur = self._conn.execute(f"DELETE FROM files WHERE label NOT IN ({marks})", keep)
            else:
                cur = self._conn.execute("DELETE FROM files")
            return cur.rowcount

    def retry_failed(self, include_rejected: bool = False) -> int:
        statuses = [FAILED] + ([REJECTED, SKIPPED] if include_rejected else [])
        marks = ",".join("?" * len(statuses))
        with self._lock:
            cur = self._conn.execute(
                f"UPDATE files SET status = ?, attempts = 0, next_retry_at = 0, last_error = NULL, updated_at = ?"
                f" WHERE status IN ({marks})",
                (PENDING, self.clock(), *statuses),
            )
            return cur.rowcount

    def requeue_skipped(self) -> int:
        """Re-evaluate skipped files (e.g. after the server changed its limits)."""
        with self._lock:
            cur = self._conn.execute(
                "UPDATE files SET status = ?, next_retry_at = 0, last_error = NULL, updated_at = ? WHERE status = ?",
                (PENDING, self.clock(), SKIPPED),
            )
            return cur.rowcount

    # -- reporting --------------------------------------------------------

    def counts(self) -> dict[str, int]:
        result = {s: 0 for s in ALL_STATUSES}
        with self._lock:
            for status, n in self._conn.execute("SELECT status, COUNT(*) FROM files GROUP BY status"):
                result[status] = n
        return result

    def counts_by_label(self) -> dict[str, int]:
        with self._lock:
            return {label: n for label, n in self._conn.execute("SELECT label, COUNT(*) FROM files GROUP BY label")}

    def recent_problems(self, limit: int = 10) -> list[FileRecord]:
        return self._many(
            "SELECT * FROM files WHERE last_error IS NOT NULL OR status IN (?, ?, ?)"
            " ORDER BY updated_at DESC LIMIT ?",
            (FAILED, REJECTED, SKIPPED, limit),
        )

    def last_error(self) -> str | None:
        with self._lock:
            row = self._conn.execute(
                "SELECT label, relative_path, last_error FROM files WHERE last_error IS NOT NULL"
                " AND status IN (?, ?, ?) ORDER BY updated_at DESC LIMIT 1",
                (PENDING, FAILED, REJECTED),
            ).fetchone()
        if not row:
            return None
        return f"{row[0]}/{row[1]}: {row[2]}"

    # -- meta key/value ---------------------------------------------------

    def meta_get(self, key: str, default: Any = None) -> Any:
        with self._lock:
            row = self._conn.execute("SELECT value FROM meta WHERE key = ?", (key,)).fetchone()
        if not row:
            return default
        try:
            return json.loads(row[0])
        except ValueError:
            return default

    def meta_set(self, key: str, value: Any) -> None:
        with self._lock:
            self._conn.execute(
                "INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                (key, json.dumps(value)),
            )


class InstanceLockError(RuntimeError):
    """Another agent process is already using this state directory."""


class InstanceLock:
    """Advisory lock file so two agents never share one state directory."""

    def __init__(self, state_dir: str | os.PathLike[str]):
        self.path = Path(state_dir) / "agent.lock"
        self._fh = None

    def acquire(self) -> "InstanceLock":
        self.path.parent.mkdir(parents=True, exist_ok=True)
        fh = open(self.path, "a+")
        try:
            if os.name == "nt":
                import msvcrt

                fh.seek(0)
                msvcrt.locking(fh.fileno(), msvcrt.LK_NBLCK, 1)
            else:
                import fcntl

                fcntl.flock(fh.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            fh.close()
            raise InstanceLockError(
                f"another reelflow agent is already running with state dir {self.path.parent}"
            ) from None
        fh.seek(0)
        fh.truncate()
        fh.write(str(os.getpid()))
        fh.flush()
        self._fh = fh
        return self

    def release(self) -> None:
        if self._fh is None:
            return
        try:
            if os.name == "nt":
                import msvcrt

                self._fh.seek(0)
                msvcrt.locking(self._fh.fileno(), msvcrt.LK_UNLCK, 1)
            else:
                import fcntl

                fcntl.flock(self._fh.fileno(), fcntl.LOCK_UN)
        except OSError:
            pass
        self._fh.close()
        self._fh = None

    def __enter__(self) -> "InstanceLock":
        return self.acquire()

    def __exit__(self, *exc: Any) -> None:
        self.release()
