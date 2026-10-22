"""Optional instant change notifications via the third-party ``watchdog`` package.

Without watchdog the agent simply relies on its periodic scans.
"""

from __future__ import annotations

import logging
import os
from typing import Callable, Iterable

from .config import FolderConfig

log = logging.getLogger("reelflow_agent.watcher")

try:  # optional dependency
    from watchdog.events import FileSystemEventHandler
    from watchdog.observers import Observer

    WATCHDOG_AVAILABLE = True
except Exception:  # noqa: BLE001 - ImportError or a broken install
    FileSystemEventHandler = object  # type: ignore[assignment,misc]
    Observer = None  # type: ignore[assignment]
    WATCHDOG_AVAILABLE = False

_IGNORED_EVENTS = {"opened", "closed_no_write"}


class _Handler(FileSystemEventHandler):  # type: ignore[misc,valid-type]
    def __init__(self, on_path: Callable[[str], None], on_rescan: Callable[[], None]):
        super().__init__()
        self._on_path = on_path
        self._on_rescan = on_rescan

    def on_any_event(self, event) -> None:  # noqa: ANN001
        try:
            if event.event_type in _IGNORED_EVENTS:
                return
            if event.is_directory:
                if event.event_type in ("created", "moved", "deleted"):
                    self._on_rescan()
                return
            self._on_path(os.fsdecode(event.src_path))
            dest = getattr(event, "dest_path", None)
            if dest:
                self._on_path(os.fsdecode(dest))
        except Exception:  # noqa: BLE001 - never kill the observer thread
            log.debug("error handling filesystem event", exc_info=True)


class FolderWatcher:
    def __init__(self, folders: Iterable[FolderConfig], on_path: Callable[[str], None], on_rescan: Callable[[], None]):
        self.folders = list(folders)
        self.on_path = on_path
        self.on_rescan = on_rescan
        self._observer = None

    def start(self) -> bool:
        """Start watching; returns False if watchdog is unavailable or failed to start."""
        if not WATCHDOG_AVAILABLE:
            return False
        try:
            observer = Observer()
            handler = _Handler(self.on_path, self.on_rescan)
            scheduled = 0
            for folder in self.folders:
                if os.path.isdir(folder.path):
                    observer.schedule(handler, str(folder.path), recursive=True)
                    scheduled += 1
            if not scheduled:
                return False
            observer.daemon = True
            observer.start()
        except Exception as e:  # noqa: BLE001 - e.g. inotify watch limit reached
            log.warning("filesystem watcher unavailable (%s); falling back to periodic scans", e)
            return False
        self._observer = observer
        return True

    def stop(self) -> None:
        if self._observer is not None:
            try:
                self._observer.stop()
                self._observer.join(timeout=5)
            except Exception:  # noqa: BLE001
                pass
            self._observer = None
