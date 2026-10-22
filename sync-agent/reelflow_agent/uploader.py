"""Per-file pipeline: verify -> hash -> register -> upload chunks -> complete -> confirm."""

from __future__ import annotations

import hashlib
import logging
import math
import os
import random
import threading
import time
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import PurePosixPath
from typing import Any, Callable, Iterator

from .api import (
    ApiClient,
    ApiError,
    AuthError,
    ClientError,
    DeviceRevokedError,
    NetworkError,
    OperationCancelled,
    TransientError,
    backoff_delay,
    call_with_retries,
)
from .config import Config
from .hasher import HashCancelled, sha256_file
from .media import (
    DEFAULT_AI_FRAMES,
    DEFAULT_FRAME_WIDTH,
    DEFAULT_THUMBNAIL_WIDTH,
    MAX_FRAMES,
    MediaCancelled,
    MediaError,
    MediaSettings,
    MediaToolkit,
    MediaToolMissing,
)
from .scanner import PathOutsideRoot, resolve_candidate_path
from .state import (
    DONE,
    FAILED,
    HASHING,
    MEDIA_FAILED,
    MEDIA_PENDING,
    MEDIA_SENT,
    PENDING,
    REJECTED,
    SKIPPED,
    UPLOADING,
    FileRecord,
    StateDB,
)

log = logging.getLogger("reelflow_agent.uploader")

REMOVED = "removed"
REQUEUED = "requeued"
WAITING = "waiting"

# Server validation errors: retrying the same file will never succeed.
REJECT_CODES = {
    "invalid_extension", "invalid_size", "invalid_path", "validation_failed", "checksum_conflict",
}
CHUNK_RETRY_CODES = {"chunk_checksum_mismatch", "chunk_size_mismatch", "missing_checksum"}
SESSION_LOST_CODES = {"session_closed", "not_found", "upload_incomplete"}
FINAL_SERVER_STATUSES = {"uploaded", "processing", "ready"}
CHUNK_RETRIES = 3
# Server-side processing errors (matched case-insensitively in processing_error)
# that re-uploading the same bytes cannot fix. Anything else is retried.
PERMANENT_SERVER_ERRORS = ("not a video",)
# When FFmpeg is missing, look for it again this often (seconds) for pending media.
MEDIA_TOOL_RETRY_SECONDS = 300.0
MEDIA_AGENT = "agent"
MEDIA_SERVER = "server"


@dataclass(frozen=True)
class ServerConfig:
    chunk_size_bytes: int | None = None
    allowed_extensions: frozenset[str] | None = None
    max_file_size_bytes: int | None = None
    # MEDIA_PROCESSING on the server. Older servers do not send it -> "server" (nothing to do).
    media_processing: str = MEDIA_SERVER
    ai_frames: int = DEFAULT_AI_FRAMES
    frame_width: int = DEFAULT_FRAME_WIDTH
    thumbnail_width: int = DEFAULT_THUMBNAIL_WIDTH

    @property
    def agent_media(self) -> bool:
        return self.media_processing == MEDIA_AGENT

    def media_settings(self) -> MediaSettings:
        return MediaSettings(self.ai_frames, self.frame_width, self.thumbnail_width)

    @classmethod
    def from_payload(cls, payload: Any) -> "ServerConfig | None":
        if not isinstance(payload, dict):
            return None

        def _int(v: Any) -> int | None:
            return int(v) if isinstance(v, (int, float)) and not isinstance(v, bool) and v > 0 else None

        exts = payload.get("allowed_extensions")
        allowed = (
            frozenset(str(e).lower().lstrip(".") for e in exts if str(e).strip())
            if isinstance(exts, list) and exts
            else None
        )
        mode = str(payload.get("media_processing") or "").strip().lower()
        return cls(
            _int(payload.get("chunk_size_bytes")),
            allowed,
            _int(payload.get("max_file_size_bytes")),
            media_processing=MEDIA_AGENT if mode == MEDIA_AGENT else MEDIA_SERVER,
            ai_frames=min(MAX_FRAMES, _int(payload.get("ai_frames")) or DEFAULT_AI_FRAMES),
            frame_width=min(4096, _int(payload.get("frame_width")) or DEFAULT_FRAME_WIDTH),
            thumbnail_width=min(4096, _int(payload.get("thumbnail_width")) or DEFAULT_THUMBNAIL_WIDTH),
        )

    def to_payload(self) -> dict[str, Any]:
        return {
            "chunk_size_bytes": self.chunk_size_bytes,
            "allowed_extensions": sorted(self.allowed_extensions) if self.allowed_extensions else None,
            "max_file_size_bytes": self.max_file_size_bytes,
            "media_processing": self.media_processing,
            "ai_frames": self.ai_frames,
            "frame_width": self.frame_width,
            "thumbnail_width": self.thumbnail_width,
        }

    def rejection_reason(self, filename: str, size: int) -> str | None:
        ext = filename.rpartition(".")[2].lower() if "." in filename else ""
        if self.allowed_extensions is not None and ext not in self.allowed_extensions:
            return f"extension .{ext} is not accepted by the server"
        if self.max_file_size_bytes is not None and size > self.max_file_size_bytes:
            return f"file is larger than the server limit ({size} > {self.max_file_size_bytes} bytes)"
        return None


class UploadStopped(Exception):
    """Shutdown requested; the current chunk was finished and state persisted."""


class FileChanged(Exception):
    """The local file changed since it was recorded/hashed."""


class FileGone(Exception):
    """The local file was deleted or moved away."""


class KeyedLocks:
    """One lock per key (here: checksum) so identical content is uploaded only once."""

    def __init__(self) -> None:
        self._guard = threading.Lock()
        self._locks: dict[str, list[Any]] = {}

    @contextmanager
    def hold(self, key: str) -> Iterator[None]:
        with self._guard:
            entry = self._locks.setdefault(key, [threading.Lock(), 0])
            entry[1] += 1
        entry[0].acquire()
        try:
            yield
        finally:
            entry[0].release()
            with self._guard:
                entry[1] -= 1
                if entry[1] == 0:
                    self._locks.pop(key, None)


def describe_os_error(err: OSError) -> str:
    """OSError text without the (absolute) filename."""
    return f"{err.__class__.__name__}: {err.strerror or 'I/O error'}"


class Uploader:
    def __init__(
        self,
        api: ApiClient,
        db: StateDB,
        config: Config,
        *,
        stop_event: threading.Event | None = None,
        server_config: ServerConfig | None = None,
        on_requeue: Callable[[str, str], None] | None = None,
        rng: Callable[[], float] = random.random,
        clock: Callable[[], float] = time.time,
        media_toolkit: MediaToolkit | None = None,
    ):
        self.api = api
        self.db = db
        self.config = config
        self.stop_event = stop_event or threading.Event()
        self.server_config = server_config
        self.on_requeue = on_requeue
        self.rng = rng
        self.clock = clock
        self.media = media_toolkit or MediaToolkit.from_config(config)
        self._checksum_locks = KeyedLocks()

    # -- helpers ----------------------------------------------------------

    def _retry(self, fn: Callable[[], Any], what: str) -> Any:
        cfg = self.config
        return call_with_retries(
            fn,
            max_retries=cfg.max_retries,
            base_delay=cfg.retry_backoff_base_seconds,
            max_delay=cfg.retry_backoff_max_seconds,
            stop_event=self.stop_event,
            what=what,
            rng=self.rng,
        )

    def _abort_quiet(self, session_id: str | None, reason: str) -> None:
        if not session_id:
            return
        try:
            self.api.abort_upload(session_id, reason)
            log.info("aborted upload session %s (%s)", session_id, reason)
        except ApiError as e:
            log.debug("could not abort session %s: %s", session_id, e)

    @staticmethod
    def _ensure_unchanged(path: str, rec: FileRecord, fh: Any = None) -> None:
        try:
            st = os.stat(path)
        except FileNotFoundError:
            raise FileGone() from None
        if st.st_size != rec.size or st.st_mtime_ns != rec.mtime_ns:
            raise FileChanged()
        if fh is not None:
            fst = os.fstat(fh.fileno())
            if fst.st_size != rec.size:
                raise FileChanged()
            if os.name == "posix" and (fst.st_ino, fst.st_dev) != (st.st_ino, st.st_dev):
                raise FileChanged()  # file was replaced (e.g. atomic rename) under us

    def fail(self, key: str, message: str, *, reset_hash: bool = False, count: bool = True) -> str:
        """Record a failed attempt; schedule a retry with backoff or give up after max_retries."""
        rec = self.db.get(key)
        if rec is None:
            return REMOVED
        attempts = rec.attempts + (1 if count else 0)
        fields: dict[str, Any] = {"attempts": attempts, "last_error": message[:2000]}
        if reset_hash:
            fields.update(sha256=None, session_id=None, server_status=None)
        if attempts > self.config.max_retries:
            fields.update(status=FAILED, next_retry_at=0)
            log.error("%s failed permanently after %d attempts: %s", key, attempts, message)
            status = FAILED
        else:
            base = self.config.retry_backoff_base_seconds * 15
            cap = max(self.config.retry_backoff_max_seconds * 12, base)
            delay = backoff_delay(max(attempts - 1, 0), base, cap, self.rng)
            fields.update(status=PENDING, next_retry_at=self.clock() + delay)
            log.warning("%s: %s (attempt %d/%d, next try in %.0fs)", key, message, attempts,
                        self.config.max_retries + 1, delay)
            status = PENDING
        self.db.update(key, **fields)
        return status

    def _reject(self, key: str, message: str) -> str:
        log.error("%s rejected: %s", key, message)
        self.db.update(key, status=REJECTED, last_error=message[:2000], session_id=None, next_retry_at=0)
        return REJECTED

    # -- public -----------------------------------------------------------

    def process(self, rec: FileRecord) -> str:
        """Run one attempt for a pending file. Returns the resulting status.

        AuthError, DeviceRevokedError and NetworkError are re-raised (after the
        record has been put back to pending without using up an attempt) so the
        caller can pause uploads.
        """
        key = rec.path_key
        try:
            return self._process(rec)
        except (UploadStopped, OperationCancelled, HashCancelled):
            self.db.update(key, status=PENDING, next_retry_at=0)
            log.info("%s: paused for shutdown; will resume", key)
            return PENDING
        except FileGone:
            cur = self.db.get(key)
            self._abort_quiet(cur.session_id if cur else None, "local file was removed")
            self.db.delete(key)
            log.info("%s disappeared before upload finished; forgetting it", key)
            return REMOVED
        except FileChanged:
            cur = self.db.get(key)
            self._abort_quiet(cur.session_id if cur else None, "local file changed during upload")
            self.db.delete(key)
            log.warning("%s changed on disk; it will be re-checked once stable and uploaded again", key)
            if self.on_requeue:
                self.on_requeue(rec.label, rec.relative_path)
            return REQUEUED
        except (AuthError, DeviceRevokedError) as e:
            self.db.update(key, status=PENDING, next_retry_at=0, last_error=str(e)[:2000])
            raise
        except NetworkError as e:
            self.db.update(key, status=PENDING, next_retry_at=self.clock() + 30, last_error=str(e)[:2000])
            raise
        except PathOutsideRoot as e:
            return self._reject(key, f"unsafe path: {e}")
        except ClientError as e:
            if e.code in REJECT_CODES:
                return self._reject(key, str(e))
            if e.code in SESSION_LOST_CODES:
                self.db.update(key, session_id=None)
            return self.fail(key, str(e))
        except TransientError as e:
            return self.fail(key, str(e))
        except OSError as e:
            return self.fail(key, f"cannot read file: {describe_os_error(e)}")
        except Exception as e:  # noqa: BLE001 - never let one file kill the agent
            log.exception("unexpected error while processing %s", key)
            return self.fail(key, f"internal error: {e.__class__.__name__}: {e}")

    def _process(self, rec: FileRecord) -> str:
        key = rec.path_key
        folder = self.config.folder(rec.label)
        if folder is None:
            self.db.update(key, status=SKIPPED, last_error="folder is no longer configured")
            return SKIPPED
        path = resolve_candidate_path(folder.path, rec.relative_path)
        self._ensure_unchanged(path, rec)
        filename = PurePosixPath(rec.relative_path).name

        reason = "file is empty" if rec.size <= 0 else None
        if reason is None and self.server_config is not None:
            reason = self.server_config.rejection_reason(filename, rec.size)
        if reason:
            log.warning("skipping %s: %s", key, reason)
            self.db.update(key, status=SKIPPED, last_error=reason)
            return SKIPPED

        if rec.stale_session_id:
            self._abort_quiet(rec.stale_session_id, "local file changed")
            self.db.update(key, stale_session_id=None)

        sha = rec.sha256
        if not sha:
            self.db.update(key, status=HASHING)
            started = time.monotonic()
            sha = sha256_file(path, should_stop=self.stop_event.is_set)
            self._ensure_unchanged(path, rec)
            self.db.update(key, sha256=sha)
            log.info("hashed %s (%s bytes) in %.1fs", key, rec.size, time.monotonic() - started)

        with self._checksum_locks.hold(sha):
            if self.stop_event.is_set():
                raise UploadStopped()
            return self._upload(rec, path, sha, filename)

    def _upload(self, rec: FileRecord, path: str, sha: str, filename: str) -> str:
        key = rec.path_key
        self.db.update(key, status=UPLOADING)
        self._ensure_unchanged(path, rec)  # re-verify right before registering

        status_code, resp = self._retry(
            lambda: self.api.register_video(
                filename=filename,
                folder_label=rec.label,
                relative_path=rec.relative_path,
                size=rec.size,
                checksum_sha256=sha,
            ),
            f"register {key}",
        )
        video = resp.get("video") if isinstance(resp.get("video"), dict) else {}
        video_id = video.get("id")
        if video_id is None:
            raise TransientError(status_code, "invalid_response", "register response has no video id")
        upload = resp.get("upload")

        if not upload:
            self.db.update(
                key, status=DONE, video_id=str(video_id), session_id=None, last_error=None, next_retry_at=0,
                server_status="uploaded" if video.get("uploaded") else (video.get("status") or None),
            )
            log.info("%s already on server (video #%s); nothing to upload", key, video_id)
            self.note_needs_media(key, video)
            self.media_after_upload(key)
            return DONE

        session_id = str(upload.get("session_id") or "")
        if not session_id:
            raise TransientError(status_code, "invalid_response", "register response has no session id")
        if rec.session_id and rec.session_id != session_id:
            log.info("%s: server opened a new upload session", key)
        self.db.update(key, video_id=str(video_id), session_id=session_id)
        self.note_needs_media(key, video)

        final = self._send_and_complete(rec, path, upload)
        self.db.update(
            key, status=DONE, session_id=None, last_error=None, next_retry_at=0,
            server_status=str(final.get("status") or "assembling"),
        )
        log.info("uploaded %s (video #%s); server is assembling it", key, video_id)
        self.media_after_upload(key)
        return self._poll_result(key, video_id)

    def _send_and_complete(self, rec: FileRecord, path: str, upload: dict[str, Any]) -> dict[str, Any]:
        session_id = str(upload["session_id"])
        for _ in range(3):
            state = upload.get("status")
            if state in ("assembling", "completed"):
                return upload
            if state in ("failed", "expired"):
                raise ClientError(409, "session_closed", f"upload session is {state}")
            self._send_missing_chunks(rec, path, upload)
            try:
                resp = self._retry(lambda: self.api.complete_upload(session_id), f"complete {rec.path_key}")
                return resp.get("upload") or {"status": "assembling"}
            except ClientError as e:
                if e.code != "upload_incomplete":
                    raise
                log.warning("%s: server reports missing chunks; re-sending them", rec.path_key)
                upload = self._retry(lambda: self.api.get_upload(session_id), "get upload session").get("upload") or {}
                upload.setdefault("session_id", session_id)
        raise ClientError(409, "upload_incomplete", "server still reports missing chunks after re-sending")

    def _send_missing_chunks(self, rec: FileRecord, path: str, upload: dict[str, Any]) -> None:
        session_id = str(upload["session_id"])
        chunk_size = int(upload.get("chunk_size") or 0)
        total = int(upload.get("total_chunks") or 0)
        if chunk_size <= 0 or total != max(1, math.ceil(rec.size / chunk_size)):
            raise ClientError(409, "session_closed", "upload session layout does not match the local file")
        received = {int(i) for i in (upload.get("received_chunks") or [])}
        missing = [i for i in range(total) if i not in received]
        if received and missing:
            log.info("resuming %s: %d of %d chunks already on server", rec.path_key, total - len(missing), total)
        step = max(1, len(missing) // 10)
        with open(path, "rb") as fh:
            for n, idx in enumerate(missing, start=1):
                if self.stop_event.is_set():
                    raise UploadStopped()
                offset = idx * chunk_size
                length = min(chunk_size, rec.size - offset)
                self._put_chunk(rec, path, fh, session_id, idx, offset, length)
                if n % step == 0 or n == len(missing):
                    log.info("%s: %d/%d chunks sent", rec.path_key, total - len(missing) + n, total)
        self._ensure_unchanged(path, rec)

    def _put_chunk(self, rec: FileRecord, path: str, fh: Any, session_id: str, idx: int, offset: int, length: int) -> None:
        for attempt in range(CHUNK_RETRIES):
            self._ensure_unchanged(path, rec, fh)
            fh.seek(offset)
            data = fh.read(length)
            if len(data) != length:
                raise FileChanged()
            digest = hashlib.sha256(data).hexdigest()
            try:
                self._retry(lambda: self.api.put_chunk(session_id, idx, data, digest), f"chunk {idx} of {rec.path_key}")
                return
            except ClientError as e:
                if e.code in CHUNK_RETRY_CODES and attempt < CHUNK_RETRIES - 1:
                    log.warning("%s: chunk %d rejected (%s); re-reading and re-sending", rec.path_key, idx, e.code)
                    continue
                raise

    def _poll_result(self, key: str, video_id: Any) -> str:
        for _ in range(self.config.completion_poll_attempts):
            if self.stop_event.wait(self.config.completion_poll_interval_seconds):
                break
            try:
                video = self.api.get_video(video_id).get("video") or {}
            except (TransientError, ClientError) as e:
                log.debug("status check for video #%s failed: %s", video_id, e)
                continue
            outcome = self.apply_video_status(key, video)
            if outcome != WAITING:
                return outcome
        return DONE

    def apply_video_status(self, key: str, video: dict[str, Any]) -> str:
        """Update a finished record from the server's view of the video."""
        self.note_needs_media(key, video)
        status = str(video.get("status") or "")
        if video.get("uploaded"):
            self.db.update(key, server_status="uploaded")
            log.info("%s confirmed by server (status %s)", key, status or "uploaded")
            return DONE
        if status == "failed":
            error = str(video.get("processing_error") or "server could not process the upload")
            if any(marker in error.lower() for marker in PERMANENT_SERVER_ERRORS):
                # Re-uploading identical bytes cannot fix this (e.g. not a real video file).
                return self._reject(key, f"server: {error}")
            return self.fail(key, f"server: {error}", reset_hash=True)
        if status == "cancelled":
            self.db.update(key, server_status="cancelled")
            return DONE
        self.db.update(key, server_status=status or None)
        return WAITING

    # -- agent-side media (MEDIA_PROCESSING=agent) ---------------------------

    @property
    def media_enabled(self) -> bool:
        return self.server_config is not None and self.server_config.agent_media

    def note_needs_media(self, key: str, video: dict[str, Any]) -> None:
        """Track the server's ``needs_media`` flag (absent on older servers: ignored)."""
        if not isinstance(video, dict) or "needs_media" not in video:
            return
        rec = self.db.get(key)
        if rec is None:
            return
        if video.get("needs_media") and self.media_enabled:
            # A failed (rejected) media upload is only retried via `retry-failed`.
            if rec.media_status not in (MEDIA_PENDING, MEDIA_FAILED):
                self.db.update(key, media_status=MEDIA_PENDING, media_attempts=0, media_error=None,
                               media_next_retry_at=0)
        elif not video.get("needs_media") and rec.media_status in (MEDIA_PENDING, MEDIA_FAILED):
            self.db.update(key, media_status=None, media_attempts=0, media_error=None, media_next_retry_at=0)

    def media_after_upload(self, key: str) -> None:
        """First media attempt right after register/upload. Never affects the upload's own result."""
        rec = self.db.get(key)
        if rec is None or rec.media_status != MEDIA_PENDING or self.stop_event.is_set():
            return
        try:
            self.process_media(rec)
        except (AuthError, DeviceRevokedError, NetworkError) as e:
            log.debug("%s: media deferred (%s)", key, e)  # state already updated; retried later

    def fail_media(self, key: str, message: str, *, permanent: bool = False) -> str:
        rec = self.db.get(key)
        if rec is None:
            return REMOVED
        attempts = rec.media_attempts + 1
        fields: dict[str, Any] = {"media_attempts": attempts, "media_error": message[:2000]}
        if permanent or attempts > self.config.max_retries:
            fields.update(media_status=MEDIA_FAILED, media_next_retry_at=0)
            log.error("%s: media could not be sent%s: %s", key,
                      "" if permanent else f" after {attempts} attempts", message)
            status = MEDIA_FAILED
        else:
            base = self.config.retry_backoff_base_seconds * 15
            cap = max(self.config.retry_backoff_max_seconds * 12, base)
            delay = backoff_delay(max(attempts - 1, 0), base, cap, self.rng)
            fields.update(media_status=MEDIA_PENDING, media_next_retry_at=self.clock() + delay)
            log.warning("%s: media: %s (attempt %d/%d, next try in %.0fs)", key, message, attempts,
                        self.config.max_retries + 1, delay)
            status = MEDIA_PENDING
        self.db.update(key, **fields)
        return status

    def _defer_media(self, key: str, message: str | None, delay: float) -> str:
        self.db.update(key, media_status=MEDIA_PENDING, media_next_retry_at=self.clock() + delay,
                       **({"media_error": message[:2000]} if message else {}))
        return MEDIA_PENDING

    def process_media(self, rec: FileRecord) -> str:
        """Generate metadata/thumbnail/frames with local FFmpeg and upload them.

        Returns the resulting media status. Like ``process``, AuthError,
        DeviceRevokedError and NetworkError are re-raised so the caller can pause.
        """
        key = rec.path_key
        rec = self.db.get(key)  # type: ignore[assignment]
        if rec is None:
            return REMOVED
        if rec.media_status != MEDIA_PENDING or not rec.video_id:
            return rec.media_status or ""
        if not self.media_enabled:
            return MEDIA_PENDING
        try:
            return self._process_media(rec)
        except (MediaCancelled, OperationCancelled):
            return MEDIA_PENDING
        except MediaToolMissing as e:
            self.media.report_missing(str(e), log)
            return self._defer_media(key, str(e), MEDIA_TOOL_RETRY_SECONDS)
        except (FileGone, FileChanged):
            # The scanner forgets / re-records the file (which resets its media state).
            return self._defer_media(key, None, MEDIA_TOOL_RETRY_SECONDS)
        except MediaError as e:
            return self.fail_media(key, str(e), permanent=e.permanent)
        except (AuthError, DeviceRevokedError) as e:
            self._defer_media(key, str(e), 0)
            raise
        except NetworkError as e:
            self._defer_media(key, str(e), 30)
            raise
        except PathOutsideRoot as e:
            return self.fail_media(key, f"unsafe path: {e}", permanent=True)
        except ClientError as e:
            if e.status in (404, 422):  # validation error / video not linked to this device
                return self.fail_media(key, str(e), permanent=True)
            return self.fail_media(key, str(e))
        except TransientError as e:
            return self.fail_media(key, str(e))
        except OSError as e:
            return self.fail_media(key, f"cannot read file: {describe_os_error(e)}")
        except Exception as e:  # noqa: BLE001 - never let one file kill the agent
            log.exception("unexpected error while creating media for %s", key)
            return self.fail_media(key, f"internal error: {e.__class__.__name__}: {e}")

    def _process_media(self, rec: FileRecord) -> str:
        key = rec.path_key
        folder = self.config.folder(rec.label)
        if folder is None:
            raise FileGone()
        path = resolve_candidate_path(folder.path, rec.relative_path)
        self._ensure_unchanged(path, rec)
        settings = self.server_config.media_settings()  # type: ignore[union-attr]
        started = time.monotonic()
        bundle = self.media.generate(path, settings, should_stop=self.stop_event.is_set)
        self._ensure_unchanged(path, rec)
        body, content_type = bundle.encode()
        resp = self._retry(lambda: self.api.post_media(rec.video_id, body, content_type), f"media {key}")
        video = resp.get("video") if isinstance(resp.get("video"), dict) else {}
        self.db.update(key, media_status=MEDIA_SENT, media_error=None, media_next_retry_at=0)
        log.info("%s: sent metadata (%sx%s, %.1fs), thumbnail and %d frame(s) to video #%s in %.1fs",
                 key, bundle.metadata["width"], bundle.metadata["height"], bundle.metadata["duration"],
                 len(bundle.frames), rec.video_id, time.monotonic() - started)
        if video.get("needs_media"):
            log.warning("%s: server still reports missing media for video #%s", key, rec.video_id)
        return MEDIA_SENT
