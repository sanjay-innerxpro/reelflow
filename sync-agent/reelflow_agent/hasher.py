"""Streaming SHA-256 hashing."""

from __future__ import annotations

import hashlib
from typing import Callable

BLOCK_SIZE = 1024 * 1024


class HashCancelled(Exception):
    """Hashing was interrupted by a shutdown request."""


def sha256_file(path: str, *, block_size: int = BLOCK_SIZE, should_stop: Callable[[], bool] | None = None) -> str:
    """Hex SHA-256 of a file, read in blocks so memory use stays constant."""
    digest = hashlib.sha256()
    buf = bytearray(block_size)
    view = memoryview(buf)
    with open(path, "rb", buffering=0) as fh:
        while True:
            if should_stop is not None and should_stop():
                raise HashCancelled()
            n = fh.readinto(buf)
            if not n:
                break
            digest.update(view[:n])
    return digest.hexdigest()


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()
