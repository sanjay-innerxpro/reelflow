"""Shared test fixtures."""

from __future__ import annotations

import logging
import os
import tempfile
import unittest
from pathlib import Path

from reelflow_agent.agent import Agent
from reelflow_agent.api import ApiClient
from reelflow_agent.config import Config, FolderConfig
from reelflow_agent.state import StateDB
from reelflow_agent.uploader import Uploader

from fake_server import TOKEN, FakeSyncServer

# Keep test output quiet; individual tests attach their own handlers when needed.
_pkg_logger = logging.getLogger("reelflow_agent")
_pkg_logger.propagate = False
if not _pkg_logger.handlers:
    _pkg_logger.addHandler(logging.NullHandler())


def payload(size: int, seed: int = 1) -> bytes:
    """Deterministic pseudo-random bytes."""
    out = bytearray()
    x = seed
    while len(out) < size:
        x = (x * 6364136223846793005 + 1442695040888963407) & 0xFFFFFFFFFFFFFFFF
        out += x.to_bytes(8, "little")
    return bytes(out[:size])


class TempDirTestCase(unittest.TestCase):
    def setUp(self) -> None:
        super().setUp()
        self._tmp = tempfile.TemporaryDirectory(prefix="rfagent-")
        self.root = Path(self._tmp.name)
        self.watch = self.root / "videos"
        self.watch.mkdir()

    def tearDown(self) -> None:
        self._tmp.cleanup()
        super().tearDown()

    def write(self, rel: str, data: bytes, base: Path | None = None) -> Path:
        path = (base or self.watch) / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        return path


class ServerTestCase(TempDirTestCase):
    chunk_size = 1024

    def setUp(self) -> None:
        super().setUp()
        self.server = FakeSyncServer(chunk_size=self.chunk_size).start()
        self.config = self.make_config()
        self.db = StateDB(self.config.db_path)
        self.api = ApiClient(self.server.url, TOKEN, timeout=10)

    def tearDown(self) -> None:
        self.db.close()
        self.server.stop()
        super().tearDown()

    def make_config(self, **overrides) -> Config:
        values = dict(
            folders=[FolderConfig("Main", self.watch)],
            server_url=self.server.url,
            device_token=TOKEN,
            stability_seconds=0,
            max_retries=3,
            retry_backoff_base_seconds=0.001,
            retry_backoff_max_seconds=0.01,
            completion_poll_attempts=2,
            completion_poll_interval_seconds=0,
            state_dir=self.root / "state",
            use_watchdog=False,
        )
        values.update(overrides)
        return Config(**values)

    def make_agent(self, api: ApiClient | None = None, **kw) -> Agent:
        return Agent(self.config, self.db, api or self.api, **kw)

    def make_uploader(self, api: ApiClient | None = None, **kw) -> Uploader:
        agent = self.make_agent(api)
        agent.heartbeat()
        return agent.uploader

    def stable_record(self, rel: str):
        path = self.watch / rel
        st = os.stat(path)
        rec, _ = self.db.record_stable("Main", rel, st.st_size, st.st_mtime_ns)
        return rec
