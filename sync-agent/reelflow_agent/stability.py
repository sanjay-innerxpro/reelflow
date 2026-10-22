"""Detect when a file has finished being copied.

A file is *stable* once its (size, mtime) signature has stayed unchanged for
``stability_seconds`` of observation and it can be opened for reading. The
clock is injectable so tests do not need to sleep.
"""

from __future__ import annotations

import threading
import time
from dataclasses import dataclass
from typing import Callable


@dataclass
class _Observation:
    size: int
    mtime_ns: int
    since: float


class StabilityTracker:
    def __init__(self, stability_seconds: float, clock: Callable[[], float] = time.monotonic):
        self.stability_seconds = float(stability_seconds)
        self.clock = clock
        self._obs: dict[str, _Observation] = {}
        self._lock = threading.Lock()

    def observe(self, key: str, size: int, mtime_ns: int) -> bool:
        """Record a stat sample; return True when the signature has been unchanged long enough."""
        now = self.clock()
        with self._lock:
            obs = self._obs.get(key)
            if obs is None or obs.size != size or obs.mtime_ns != mtime_ns:
                self._obs[key] = _Observation(size, mtime_ns, now)
                return self.stability_seconds <= 0
            return (now - obs.since) >= self.stability_seconds

    def forget(self, key: str) -> None:
        with self._lock:
            self._obs.pop(key, None)

    def __contains__(self, key: str) -> bool:
        with self._lock:
            return key in self._obs

    def __len__(self) -> int:
        with self._lock:
            return len(self._obs)

    def keys(self) -> list[str]:
        with self._lock:
            return list(self._obs)


def is_readable(path: str) -> bool:
    """True if the file can be opened and read (Windows keeps files locked while copying)."""
    try:
        with open(path, "rb") as fh:
            fh.read(1)
        return True
    except OSError:
        return False
