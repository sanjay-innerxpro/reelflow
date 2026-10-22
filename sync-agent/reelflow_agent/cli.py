"""Command line interface: ``python -m reelflow_agent <command>``."""

from __future__ import annotations

import argparse
import logging
import os
import signal
import sys
import threading
import time
from datetime import datetime
from typing import Sequence

from . import __version__
from .agent import EXIT_AUTH, EXIT_CONFIG, EXIT_ERROR, EXIT_OK, EXIT_REVOKED, Agent
from .api import ApiClient, ApiError, AuthError, DeviceRevokedError
from .config import Config, ConfigError, load_config
from .logging_setup import redact, setup_logging
from .media import INSTALL_HINT, MediaToolkit
from .scanner import FileFilter, FolderUnavailable, scan_folder
from .state import ALL_STATUSES, MEDIA_STATUSES, InstanceLock, InstanceLockError, StateDB
from .watcher import WATCHDOG_AVAILABLE

log = logging.getLogger("reelflow_agent.cli")


def _print(msg: str = "") -> None:
    print(redact(msg), flush=True)


def _err(msg: str) -> None:
    print(redact(msg), file=sys.stderr, flush=True)


def _fmt_size(n: int) -> str:
    size = float(n)
    for unit in ("B", "KB", "MB", "GB", "TB"):
        if size < 1024 or unit == "TB":
            return f"{size:.0f} {unit}" if unit == "B" else f"{size:.1f} {unit}"
        size /= 1024
    return f"{n} B"


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="reelflow_agent",
        description="ReelFlow local folder sync agent: uploads finished video files from watched folders.",
    )
    parser.add_argument("--version", action="version", version=f"%(prog)s {__version__}")
    parser.add_argument("--config", metavar="PATH", help="path to config.toml (default: ./config.toml or next to the agent)")
    parser.add_argument("--log-level", metavar="LEVEL", help="override log_level (DEBUG, INFO, WARNING, ERROR)")
    sub = parser.add_subparsers(dest="command", metavar="<command>")
    sub.required = True

    sub.add_parser("run", help="watch folders and upload continuously (long-running)")
    p_scan = sub.add_parser("scan", help="rescan all folders, upload pending files once, then exit")
    p_scan.add_argument("--dry-run", action="store_true", help="only list what would be uploaded; no uploads, no state changes")
    p_scan.add_argument("--no-wait", action="store_true", help="do not wait for files that are still being copied")
    p_status = sub.add_parser("status", help="show local sync state")
    p_status.add_argument("--limit", type=int, default=10, help="number of recent problems to show (default 10)")
    p_retry = sub.add_parser("retry-failed", help="reset failed files to pending so they are retried")
    p_retry.add_argument("--include-rejected", action="store_true", help="also retry rejected and skipped files")
    sub.add_parser("check", help="validate config and test the server connection")
    return parser


def _load(args: argparse.Namespace, *, require_secrets: bool, require_folders: bool = True) -> Config:
    cfg = load_config(args.config, require_secrets=require_secrets, require_folders=require_folders)
    if args.log_level:
        level = args.log_level.upper()
        if level not in ("DEBUG", "INFO", "WARNING", "ERROR", "CRITICAL"):
            raise ConfigError("--log-level must be DEBUG, INFO, WARNING, ERROR or CRITICAL")
        cfg.log_level = level
    return cfg


def _make_api(cfg: Config) -> ApiClient:
    return ApiClient(cfg.server_url, cfg.device_token, timeout=cfg.request_timeout_seconds, verify_tls=cfg.verify_tls)


class _SignalHandler:
    """First SIGINT/SIGTERM: graceful stop. Second: force exit."""

    def __init__(self, stop_event: threading.Event, wake: threading.Event | None = None):
        self.stop_event = stop_event
        self.wake = wake
        self._previous: dict[int, object] = {}

    def __call__(self, signum: int, frame: object) -> None:
        if self.stop_event.is_set():
            log.warning("second interrupt: exiting immediately")
            os._exit(130)
        log.info("received signal %s; finishing current chunk and stopping (press Ctrl+C again to force)", signum)
        self.stop_event.set()
        if self.wake is not None:
            self.wake.set()

    def install(self) -> "_SignalHandler":
        if threading.current_thread() is not threading.main_thread():
            return self
        for name in ("SIGINT", "SIGTERM", "SIGBREAK", "SIGHUP"):
            sig = getattr(signal, name, None)
            if sig is not None:
                try:
                    self._previous[sig] = signal.signal(sig, self)
                except (OSError, ValueError):
                    pass
        return self

    def restore(self) -> None:
        for sig, handler in self._previous.items():
            try:
                signal.signal(sig, handler)  # type: ignore[arg-type]
            except (OSError, ValueError):
                pass


def _log_warnings(cfg: Config) -> None:
    for w in cfg.warnings:
        log.warning("%s", w)


def cmd_run(args: argparse.Namespace) -> int:
    cfg = _load(args, require_secrets=True)
    setup_logging(cfg.log_level, cfg.effective_log_dir, max_bytes=cfg.log_max_bytes, backup_count=cfg.log_backup_count)
    _log_warnings(cfg)
    with InstanceLock(cfg.state_dir), StateDB(cfg.db_path) as db:
        agent = Agent(cfg, db, _make_api(cfg))
        handler = _SignalHandler(agent.stop_event, agent.wake).install()
        try:
            return agent.run()
        finally:
            handler.restore()


def cmd_scan(args: argparse.Namespace) -> int:
    if args.dry_run:
        return _scan_dry_run(args)
    cfg = _load(args, require_secrets=True)
    setup_logging(cfg.log_level, cfg.effective_log_dir, max_bytes=cfg.log_max_bytes, backup_count=cfg.log_backup_count)
    _log_warnings(cfg)
    with InstanceLock(cfg.state_dir), StateDB(cfg.db_path) as db:
        agent = Agent(cfg, db, _make_api(cfg))
        handler = _SignalHandler(agent.stop_event, agent.wake).install()
        try:
            summary = agent.scan_once(wait=not args.no_wait)
        finally:
            handler.restore()
        if summary.message:
            _err(f"error: {summary.message}")
        parts = ", ".join(f"{k}: {v}" for k, v in sorted(summary.processed.items())) or "nothing to upload"
        _print(f"Scan finished. {summary.found} video file(s) found; {parts}.")
        if summary.media:
            media = ", ".join(f"{k}: {v}" for k, v in sorted(summary.media.items()))
            _print(f"Video media (local FFmpeg): {media}.")
            if agent.media_tools_ok is False:
                _print(f"FFmpeg is missing. {INSTALL_HINT}.")
        if summary.still_copying:
            _print(f"{summary.still_copying} file(s) are still being copied and will be picked up next time.")
        if summary.unavailable_folders:
            _print(f"Unavailable folders: {', '.join(summary.unavailable_folders)}")
        return summary.exit_code


def _scan_dry_run(args: argparse.Namespace) -> int:
    cfg = _load(args, require_secrets=False)
    setup_logging(cfg.log_level, None, console_level="WARNING")
    _log_warnings(cfg)
    flt = FileFilter(cfg.extensions, cfg.ignore_patterns)
    db = StateDB(cfg.db_path) if cfg.db_path.exists() else None
    total = would_upload = 0
    try:
        for folder in cfg.folders:
            _print(f"[{folder.label}] {folder.path}")
            try:
                cands = list(scan_folder(folder, flt))
            except FolderUnavailable:
                _print("  (folder not available)")
                continue
            for cand in cands:
                total += 1
                rec = db.get(cand.path_key) if db else None
                if rec is None:
                    action = "new -> would upload"
                    would_upload += 1
                elif rec.size != cand.size or rec.mtime_ns != cand.mtime_ns:
                    action = "changed -> would re-upload"
                    would_upload += 1
                elif rec.status in ("pending", "hashing", "uploading"):
                    action = f"{rec.status} -> would upload"
                    would_upload += 1
                else:
                    action = rec.status
                _print(f"  {cand.relative_path}  ({_fmt_size(cand.size)})  {action}")
    finally:
        if db:
            db.close()
    _print(f"Dry run: {total} video file(s) found, {would_upload} would be uploaded. Nothing was changed.")
    return EXIT_OK


def cmd_status(args: argparse.Namespace) -> int:
    cfg = _load(args, require_secrets=False, require_folders=False)
    setup_logging(cfg.log_level, None, console_level="WARNING")
    if not cfg.db_path.exists():
        _print(f"No state yet ({cfg.db_path} does not exist). Run `scan` or `run` first.")
        return EXIT_OK
    with StateDB(cfg.db_path) as db:
        counts = db.counts()
        by_label = db.counts_by_label()
        _print(f"State: {cfg.db_path}")
        hb = db.meta_get("last_heartbeat")
        if hb:
            when = datetime.fromtimestamp(hb.get("at", 0)).strftime("%Y-%m-%d %H:%M:%S")
            dev = hb.get("device") or {}
            _print(f"Last successful heartbeat: {when} (device {dev.get('name', '?')!r})")
        _print("")
        _print(f"{'Status':<12}{'Files':>8}")
        _print("-" * 20)
        for status in ALL_STATUSES:
            _print(f"{status:<12}{counts[status]:>8}")
        _print("-" * 20)
        _print(f"{'total':<12}{sum(counts.values()):>8}")
        if by_label:
            _print("")
            _print("Per folder: " + ", ".join(f"{k}={v}" for k, v in sorted(by_label.items())))
        server_cfg = db.meta_get("server_config") or {}
        media_counts = db.media_counts()
        if server_cfg.get("media_processing") == "agent" or any(media_counts.values()):
            _print("")
            mode = server_cfg.get("media_processing") or "server"
            _print(f"Video media (thumbnail/metadata/AI frames via local FFmpeg; server mode: {mode}):")
            _print("  " + ", ".join(f"{s}={media_counts[s]}" for s in MEDIA_STATUSES))
            media_problems = db.media_problems(max(0, args.limit))
            for rec in media_problems:
                when = datetime.fromtimestamp(rec.updated_at).strftime("%Y-%m-%d %H:%M")
                _print(f"  [media {rec.media_status}] {rec.label}/{rec.relative_path} ({when}, attempts "
                       f"{rec.media_attempts}): {rec.media_error or '-'}")
        problems = db.recent_problems(max(0, args.limit))
        if problems:
            _print("")
            _print("Recent problems:")
            for rec in problems:
                when = datetime.fromtimestamp(rec.updated_at).strftime("%Y-%m-%d %H:%M")
                _print(f"  [{rec.status}] {rec.label}/{rec.relative_path} ({when}, attempts {rec.attempts}): "
                       f"{rec.last_error or '-'}")
    return EXIT_OK


def cmd_retry_failed(args: argparse.Namespace) -> int:
    cfg = _load(args, require_secrets=False, require_folders=False)
    setup_logging(cfg.log_level, None, console_level="WARNING")
    if not cfg.db_path.exists():
        _print("No state yet; nothing to retry.")
        return EXIT_OK
    with StateDB(cfg.db_path) as db:
        n = db.retry_failed(include_rejected=args.include_rejected)
        m = db.retry_failed_media()
    _print(f"{n} file(s) reset to pending. They will be uploaded by the running agent or the next `scan`.")
    if m:
        _print(f"{m} file(s) with failed video media (thumbnail/frames) will be retried.")
    return EXIT_OK


def cmd_check(args: argparse.Namespace) -> int:
    cfg = _load(args, require_secrets=True)
    setup_logging(cfg.log_level, None, console_level="WARNING")
    ok = True
    _print(f"Config:        {cfg.config_path}")
    _print(f"Server:        {cfg.server_url}")
    _print(f"Token:         rfd_*** (set)")
    _print(f"State dir:     {cfg.state_dir}")
    _print(f"Watchdog:      {'available' if WATCHDOG_AVAILABLE else 'not installed (periodic scans only)'}")
    for w in cfg.warnings:
        _print(f"WARNING:       {w}")
    for folder in cfg.folders:
        exists = os.path.isdir(folder.path)
        ok = ok and exists
        _print(f"Folder [{folder.label}]: {folder.path} {'OK' if exists else 'MISSING'}")
    try:
        cfg.state_dir.mkdir(parents=True, exist_ok=True)
        probe = cfg.state_dir / ".write-test"
        probe.write_text("ok")
        probe.unlink()
    except OSError as e:
        ok = False
        _print(f"State dir is not writable: {e.strerror}")

    toolkit = MediaToolkit.from_config(cfg)
    ffmpeg, ffprobe = toolkit.locate()
    for name, found in (("FFmpeg", ffmpeg), ("FFprobe", ffprobe)):
        version = toolkit.version(found) if found else None
        detail = f"{found}" + (f" ({version})" if version else "") if found else "NOT FOUND"
        _print(f"{name + ':':<15}{detail}")

    api = _make_api(cfg)
    db_path = cfg.db_path
    try:
        with StateDB(db_path) as db:
            agent = Agent(cfg, db, api, media_toolkit=toolkit)
            started = time.monotonic()
            resp = agent.heartbeat(check_media=False)  # FFmpeg status is printed below
            elapsed = (time.monotonic() - started) * 1000
    except DeviceRevokedError as e:
        _print(f"Connection:    FAILED - device revoked ({e}). Create a new device token in the dashboard.")
        return EXIT_REVOKED
    except AuthError as e:
        _print(f"Connection:    FAILED - token rejected (401: {e}). Check REELFLOW_DEVICE_TOKEN.")
        return EXIT_AUTH
    except ApiError as e:
        _print(f"Connection:    FAILED - {e}")
        return EXIT_ERROR
    device = resp.get("device") or {}
    scfg = resp.get("config") or {}
    _print(f"Connection:    OK in {elapsed:.0f} ms - device {device.get('name')!r} (#{device.get('id')})")
    _print(f"Server limits: extensions={scfg.get('allowed_extensions')} "
           f"max_file_size={scfg.get('max_file_size_bytes')} chunk_size={scfg.get('chunk_size_bytes')}")
    if scfg.get("media_processing") == "agent":
        _print(f"Media:         server requires agent media (MEDIA_PROCESSING=agent): this computer creates "
               f"metadata, thumbnails and {scfg.get('ai_frames', 6)} AI frame(s) per video with FFmpeg")
        if not (ffmpeg and ffprobe):
            ok = False
            _print(f"ERROR:         {toolkit.missing_message(ffmpeg, ffprobe)}")
    else:
        mode = scfg.get("media_processing") or "server (not reported by this server version)"
        _print(f"Media:         processed on the server ({mode}); FFmpeg is not needed on this computer")
    return EXIT_OK if ok else EXIT_ERROR


COMMANDS = {
    "run": cmd_run,
    "scan": cmd_scan,
    "status": cmd_status,
    "retry-failed": cmd_retry_failed,
    "check": cmd_check,
}


def main(argv: Sequence[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    try:
        return COMMANDS[args.command](args)
    except ConfigError as e:
        _err(f"configuration error: {e}")
        return EXIT_CONFIG
    except InstanceLockError as e:
        _err(f"error: {e}")
        return EXIT_ERROR
    except KeyboardInterrupt:
        _err("interrupted")
        return 130
