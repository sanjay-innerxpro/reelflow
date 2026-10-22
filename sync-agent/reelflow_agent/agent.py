"""The sync engine: scanning, stability tracking, heartbeats and upload dispatch."""

from __future__ import annotations

import logging
import math
import os
import platform
import queue
import random
import threading
import time
from concurrent.futures import Future, ThreadPoolExecutor
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Callable

from . import __version__
from .api import (
    ApiClient,
    ApiError,
    AuthError,
    ClientError,
    DeviceRevokedError,
    NetworkError,
    TransientError,
    backoff_delay,
)
from .config import Config, FolderConfig
from .logging_setup import redact
from .scanner import FileFilter, FolderUnavailable, scan_folder, stat_candidate
from .stability import StabilityTracker, is_readable
from .state import ACTIVE_STATUSES, FAILED, StateDB
from .uploader import ServerConfig, Uploader
from .watcher import WATCHDOG_AVAILABLE, FolderWatcher

log = logging.getLogger("reelflow_agent.agent")

EXIT_OK = 0
EXIT_ERROR = 1
EXIT_CONFIG = 2
EXIT_REVOKED = 3
EXIT_AUTH = 4

NETWORK_PAUSE_SECONDS = 30.0
MAX_AUTH_BACKOFF = 900.0


def platform_string() -> str:
    system = platform.system() or "unknown"
    return f"{system} {platform.release()} ({platform.machine()}); Python {platform.python_version()}"[:120]


@dataclass
class ScanSummary:
    exit_code: int = EXIT_OK
    found: int = 0
    still_copying: int = 0
    processed: dict[str, int] = field(default_factory=dict)
    unavailable_folders: list[str] = field(default_factory=list)
    message: str = ""


class Agent:
    def __init__(
        self,
        config: Config,
        db: StateDB,
        api: ApiClient | None,
        *,
        stop_event: threading.Event | None = None,
        clock: Callable[[], float] = time.monotonic,
        wall_clock: Callable[[], float] = time.time,
        rng: Callable[[], float] = random.random,
    ):
        self.config = config
        self.db = db
        self.api = api
        self.stop_event = stop_event or threading.Event()
        self.clock = clock
        self.wall_clock = wall_clock
        self.rng = rng
        self.filter = FileFilter(config.extensions, config.ignore_patterns)
        self.tracker = StabilityTracker(config.stability_seconds, clock)
        self._tracked: dict[str, tuple[FolderConfig, str]] = {}
        self._tracked_lock = threading.Lock()
        self._events: "queue.SimpleQueue[str]" = queue.SimpleQueue()
        self._rescan = threading.Event()
        self.wake = threading.Event()
        self._inflight: dict[str, Future] = {}
        self._unavailable_logged: set[str] = set()
        self.server_config = ServerConfig.from_payload(db.meta_get("server_config"))
        self.device: dict[str, Any] | None = None
        self.heartbeat_ok = False
        self.auth_failed = False
        self.paused_until = 0.0
        self.exit_code = EXIT_OK
        self.uploader = (
            Uploader(
                api,
                db,
                config,
                stop_event=self.stop_event,
                server_config=self.server_config,
                on_requeue=self._requeue,
                rng=rng,
                clock=wall_clock,
            )
            if api is not None
            else None
        )

    # -- discovery --------------------------------------------------------

    def _track(self, folder: FolderConfig, key: str, abs_path: str, size: int, mtime_ns: int) -> None:
        with self._tracked_lock:
            if key not in self._tracked:
                log.debug("watching %s until it stops changing", key)
            self._tracked[key] = (folder, abs_path)
        self.tracker.observe(key, size, mtime_ns)

    def _forget(self, key: str) -> None:
        with self._tracked_lock:
            self._tracked.pop(key, None)
        self.tracker.forget(key)

    @property
    def tracked_count(self) -> int:
        with self._tracked_lock:
            return len(self._tracked)

    def _requeue(self, label: str, relative_path: str) -> None:
        folder = self.config.folder(label)
        if folder is None:
            return
        self._events.put(os.path.join(str(folder.path), *relative_path.split("/")))
        self.wake.set()

    def on_fs_event(self, path: str) -> None:
        """Called from the watchdog thread."""
        self._events.put(path)
        self.wake.set()

    def on_fs_rescan(self) -> None:
        self._rescan.set()
        self.wake.set()

    def _folder_for(self, path: str) -> FolderConfig | None:
        abs_path = os.path.normcase(os.path.abspath(path))
        for folder in self.config.folders:
            root = os.path.normcase(os.path.abspath(folder.path))
            if abs_path.startswith(root.rstrip(os.sep) + os.sep):
                return folder
        return None

    def drain_events(self) -> int:
        n = 0
        while True:
            try:
                path = self._events.get_nowait()
            except queue.Empty:
                return n
            folder = self._folder_for(path)
            if folder is None:
                continue
            cand = stat_candidate(folder, path, self.filter)
            if cand is None or cand.path_key in self._inflight:
                continue
            if not self.db.matches(cand.path_key, cand.size, cand.mtime_ns):
                self._track(folder, cand.path_key, cand.abs_path, cand.size, cand.mtime_ns)
                n += 1

    def full_scan(self) -> ScanSummary:
        """Walk every watched folder; queue new/changed files for stability checks."""
        summary = ScanSummary()
        for folder in self.config.folders:
            present: set[str] = set()
            try:
                for cand in scan_folder(folder, self.filter):
                    present.add(cand.path_key)
                    summary.found += 1
                    if cand.path_key in self._inflight:
                        continue
                    if not self.db.matches(cand.path_key, cand.size, cand.mtime_ns):
                        self._track(folder, cand.path_key, cand.abs_path, cand.size, cand.mtime_ns)
            except FolderUnavailable:
                summary.unavailable_folders.append(folder.label)
                if folder.label not in self._unavailable_logged:
                    log.warning("watched folder %r is not available (missing or unmounted?): %s",
                                folder.label, folder.path)
                    self._unavailable_logged.add(folder.label)
                continue
            self._unavailable_logged.discard(folder.label)
            removed = self.db.prune_missing(folder.label, present | set(self._inflight))
            for rec in removed:
                log.info("%s no longer exists locally; forgotten", rec.path_key)
                if rec.session_id and self.uploader is not None and self.can_upload():
                    self.uploader._abort_quiet(rec.session_id, "local file was removed")
        log.debug("scan: %d candidate files, %d waiting to become stable", summary.found, self.tracked_count)
        return summary

    def check_tracked(self) -> int:
        """Re-stat files being copied; move stable ones into the upload queue."""
        with self._tracked_lock:
            items = list(self._tracked.items())
        ready = 0
        for key, (folder, abs_path) in items:
            if key in self._inflight:
                continue
            cand = stat_candidate(folder, abs_path, self.filter)
            if cand is None:
                self._forget(key)
                continue
            if self.db.matches(key, cand.size, cand.mtime_ns):
                self._forget(key)
                continue
            if self.tracker.observe(key, cand.size, cand.mtime_ns) and is_readable(abs_path):
                rec, changed = self.db.record_stable(cand.label, cand.relative_path, cand.size, cand.mtime_ns)
                self._forget(key)
                if changed:
                    ready += 1
                    log.info("ready to upload: %s (%d bytes)", key, cand.size)
        return ready

    # -- server -----------------------------------------------------------

    def can_upload(self) -> bool:
        return (
            self.uploader is not None
            and self.heartbeat_ok
            and not self.auth_failed
            and self.clock() >= self.paused_until
            and not self.stop_event.is_set()
        )

    def sanitize_for_server(self, text: str | None) -> str | None:
        """Make an error message safe to send: no absolute local paths, no secrets."""
        if not text:
            return None
        for folder in self.config.folders:
            text = text.replace(str(folder.path), f"<{folder.label}>")
        home = str(Path.home())
        if len(home) > 1:
            text = text.replace(home, "~")
        return redact(text)[:2000]

    def heartbeat_body(self) -> dict[str, Any]:
        counts = self.db.counts()
        by_label = self.db.counts_by_label()
        return {
            "agent_version": __version__,
            "platform": platform_string(),
            "folders": [{"label": f.label, "files": by_label.get(f.label, 0)} for f in self.config.folders],
            "pending": sum(counts[s] for s in ACTIVE_STATUSES),
            "failed": counts[FAILED],
            "last_error": self.sanitize_for_server(self.db.last_error()),
        }

    def heartbeat(self) -> dict[str, Any]:
        """Send a heartbeat and apply the server's upload limits. Raises ApiError."""
        assert self.api is not None
        resp = self.api.heartbeat(self.heartbeat_body())
        cfg = ServerConfig.from_payload(resp.get("config"))
        if cfg is not None:
            if cfg != self.server_config:
                log.info("server limits: extensions=%s max_size=%s chunk=%s",
                         ",".join(sorted(cfg.allowed_extensions or [])) or "any",
                         cfg.max_file_size_bytes, cfg.chunk_size_bytes)
                unsupported = [e for e in self.config.extensions if cfg.allowed_extensions and e not in cfg.allowed_extensions]
                if unsupported:
                    log.warning("server does not accept extension(s) %s; such files will be skipped",
                                ", ".join(unsupported))
            if self.server_config is not None and cfg != self.server_config:
                n = self.db.requeue_skipped()
                if n:
                    log.info("server limits changed; re-checking %d skipped file(s)", n)
            self.server_config = cfg
            if self.uploader is not None:
                self.uploader.server_config = cfg
            self.db.meta_set("server_config", cfg.to_payload())
        device = resp.get("device") if isinstance(resp.get("device"), dict) else None
        if device and device != self.device:
            log.info("connected as device %r (#%s)", device.get("name"), device.get("id"))
        self.device = device
        self.db.meta_set("last_heartbeat", {"at": self.wall_clock(), "device": device})
        if self.auth_failed:
            log.info("device token accepted again; resuming uploads")
        self.heartbeat_ok = True
        self.auth_failed = False
        return resp

    def verify_done(self, limit: int = 20) -> None:
        """Ask the server about uploads that finished but were not yet confirmed."""
        if self.uploader is None or not self.can_upload():
            return
        for rec in self.db.awaiting_server(limit):
            try:
                video = self.api.get_video(rec.video_id).get("video") or {}  # type: ignore[union-attr]
            except ClientError as e:
                if e.status == 404:
                    self.db.update(rec.path_key, server_status="gone")
                continue
            except TransientError:
                return
            self.uploader.apply_video_status(rec.path_key, video)

    # -- dispatch ---------------------------------------------------------

    def _handle_worker_error(self, key: str, err: BaseException) -> None:
        if isinstance(err, DeviceRevokedError):
            log.critical("this device has been revoked on the server; stopping (%s)", err)
            self.exit_code = EXIT_REVOKED
            self.stop_event.set()
        elif isinstance(err, AuthError):
            if not self.auth_failed:
                log.error("server rejected the device token (401). Uploads are paused; check REELFLOW_DEVICE_TOKEN.")
            self.auth_failed = True
        elif isinstance(err, NetworkError):
            log.warning("server unreachable (%s); pausing uploads for %.0fs", err, NETWORK_PAUSE_SECONDS)
            self.paused_until = self.clock() + NETWORK_PAUSE_SECONDS
        else:
            log.error("unexpected error for %s: %r", key, err)

    def reap(self) -> None:
        for key, fut in list(self._inflight.items()):
            if not fut.done():
                continue
            del self._inflight[key]
            err = fut.exception()
            if err is not None:
                self._handle_worker_error(key, err)

    def dispatch(self, executor: ThreadPoolExecutor) -> int:
        if not self.can_upload():
            return 0
        free = self.config.max_concurrent_uploads - len(self._inflight)
        if free <= 0:
            return 0
        submitted = 0
        for rec in self.db.due(self.wall_clock(), limit=free, exclude=self._inflight.keys()):
            self._inflight[rec.path_key] = executor.submit(self.uploader.process, rec)  # type: ignore[union-attr]
            submitted += 1
        return submitted

    # -- modes ------------------------------------------------------------

    def run(self) -> int:
        """Long-running mode: watch + periodic scan + heartbeat + uploads."""
        cfg = self.config
        reset = self.db.reset_interrupted()
        if reset:
            log.info("resuming %d interrupted file(s) from the previous run", reset)
        self.db.prune_labels([f.label for f in cfg.folders])

        watcher = None
        if cfg.use_watchdog:
            watcher = FolderWatcher(cfg.folders, self.on_fs_event, self.on_fs_rescan)
            if watcher.start():
                log.info("instant change detection enabled (watchdog)")
            else:
                if not WATCHDOG_AVAILABLE:
                    log.info("watchdog not installed; scanning every %.0fs", cfg.scan_interval_seconds)
                watcher = None
        log.info("ReelFlow agent %s started; watching %d folder(s)", __version__, len(cfg.folders))

        next_hb = 0.0
        hb_failures = 0
        next_scan = 0.0
        executor = ThreadPoolExecutor(max_workers=cfg.max_concurrent_uploads, thread_name_prefix="upload")
        try:
            while not self.stop_event.is_set():
                now = self.clock()
                if now >= next_hb:
                    try:
                        self.heartbeat()
                        hb_failures = 0
                        next_hb = now + cfg.heartbeat_interval_seconds
                    except DeviceRevokedError as e:
                        self._handle_worker_error("heartbeat", e)
                        break
                    except AuthError as e:
                        hb_failures += 1
                        if not self.auth_failed:
                            log.error("server rejected the device token (401): %s. Uploads are paused until a valid "
                                      "token is configured; heartbeats continue with backoff.", e)
                        self.auth_failed = True
                        next_hb = now + backoff_delay(hb_failures, cfg.heartbeat_interval_seconds / 2,
                                                      max(MAX_AUTH_BACKOFF, cfg.heartbeat_interval_seconds), self.rng)
                    except ApiError as e:
                        hb_failures += 1
                        delay = backoff_delay(hb_failures - 1, min(5.0, cfg.heartbeat_interval_seconds),
                                              cfg.heartbeat_interval_seconds, self.rng)
                        log.warning("heartbeat failed: %s (next try in %.0fs)", e, delay)
                        next_hb = now + delay
                        if isinstance(e, NetworkError):
                            self.paused_until = max(self.paused_until, now + min(delay, NETWORK_PAUSE_SECONDS))

                if self._rescan.is_set() or now >= next_scan:
                    self._rescan.clear()
                    self.full_scan()
                    self.verify_done()
                    next_scan = now + cfg.scan_interval_seconds

                self.drain_events()
                self.check_tracked()
                self.reap()
                if self.stop_event.is_set():
                    break
                self.dispatch(executor)

                timeout = 1.0
                self.wake.wait(timeout)
                self.wake.clear()
        finally:
            if self._inflight:
                log.info("shutting down: finishing current chunk(s) and saving state...")
            executor.shutdown(wait=True, cancel_futures=True)
            self.reap()
            if watcher is not None:
                watcher.stop()
            log.info("ReelFlow agent stopped")
        return self.exit_code

    def collect_stable(self, wait: bool = True, max_wait: float | None = None) -> ScanSummary:
        """Full scan, then wait (up to a limit) for copying files to settle."""
        summary = self.full_scan()
        self.check_tracked()
        if wait and self.tracked_count:
            limit = max_wait if max_wait is not None else self.config.stability_seconds * 3 + 30
            deadline = self.clock() + limit
            log.info("waiting up to %.0fs for %d file(s) still being copied...", limit, self.tracked_count)
            step = min(1.0, max(0.05, self.config.stability_seconds / 10))
            while self.tracked_count and not self.stop_event.is_set() and self.clock() < deadline:
                self.stop_event.wait(step)
                self.check_tracked()
        summary.still_copying = self.tracked_count
        return summary

    def scan_once(self, *, wait: bool = True, max_wait: float | None = None) -> ScanSummary:
        """One-off mode: rescan, upload everything pending once, then return."""
        assert self.uploader is not None
        self.db.reset_interrupted()
        self.db.prune_labels([f.label for f in self.config.folders])
        try:
            self.heartbeat()
        except DeviceRevokedError as e:
            return ScanSummary(exit_code=EXIT_REVOKED, message=f"device revoked: {e}")
        except AuthError as e:
            return ScanSummary(exit_code=EXIT_AUTH, message=f"device token rejected: {e}")
        except ApiError as e:
            return ScanSummary(exit_code=EXIT_ERROR, message=f"cannot reach server: {e}")

        summary = self.collect_stable(wait=wait, max_wait=max_wait)
        attempted: set[str] = set()
        processed: dict[str, int] = {}
        workers = self.config.max_concurrent_uploads
        with ThreadPoolExecutor(max_workers=workers, thread_name_prefix="upload") as executor:
            while not self.stop_event.is_set():
                batch = self.db.due(math.inf, limit=workers, exclude=attempted)
                if not batch:
                    break
                futures = {}
                for rec in batch:
                    attempted.add(rec.path_key)
                    futures[rec.path_key] = executor.submit(self.uploader.process, rec)
                for key, fut in futures.items():
                    err = fut.exception()
                    if err is None:
                        result = fut.result()
                        processed[result] = processed.get(result, 0) + 1
                        continue
                    self._handle_worker_error(key, err)
                    if isinstance(err, DeviceRevokedError):
                        summary.exit_code = EXIT_REVOKED
                    elif isinstance(err, AuthError):
                        summary.exit_code = EXIT_AUTH
                    else:
                        summary.exit_code = EXIT_ERROR
                    summary.message = str(err)
                if summary.exit_code != EXIT_OK:
                    break
        summary.processed = processed
        if summary.exit_code == EXIT_OK:
            self.verify_done()
            try:
                self.heartbeat()  # report the new counts
            except ApiError:
                pass
        return summary
