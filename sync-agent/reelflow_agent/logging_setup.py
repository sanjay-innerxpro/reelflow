"""Logging configuration with secret redaction and rotating log files."""

from __future__ import annotations

import logging
import logging.handlers
import os
import re
import sys
from pathlib import Path

LOGGER_NAME = "reelflow_agent"

# Device tokens look like rfd_<random>. Also catch any bearer credential.
_TOKEN_RE = re.compile(r"rfd_[A-Za-z0-9_\-.~+/=]+")
_BEARER_RE = re.compile(r"(?i)(bearer\s+)[^\s'\",;]+")
_extra_secrets: set[str] = set()


def register_secret(secret: str | None) -> None:
    """Redact this exact string from all log output (in addition to rfd_ tokens)."""
    if secret and len(secret) >= 6:
        _extra_secrets.add(secret)


def redact(text: str) -> str:
    """Remove device tokens / bearer credentials from text."""
    if not text:
        return text
    for secret in _extra_secrets:
        if secret in text:
            text = text.replace(secret, "rfd_***")
    text = _TOKEN_RE.sub("rfd_***", text)
    text = _BEARER_RE.sub(r"\1***", text)
    return text


class RedactingFormatter(logging.Formatter):
    """Formatter that redacts the fully formatted record, including tracebacks."""

    def format(self, record: logging.LogRecord) -> str:
        return redact(super().format(record))


def get_logger(name: str | None = None) -> logging.Logger:
    return logging.getLogger(LOGGER_NAME if not name else f"{LOGGER_NAME}.{name}")


def setup_logging(
    level: str = "INFO",
    log_dir: str | os.PathLike[str] | None = None,
    *,
    console: bool = True,
    console_level: str | None = None,
    max_bytes: int = 5 * 1024 * 1024,
    backup_count: int = 5,
) -> logging.Logger:
    """Configure the package logger. Safe to call more than once."""
    logger = logging.getLogger(LOGGER_NAME)
    for handler in list(logger.handlers):
        logger.removeHandler(handler)
        handler.close()
    logger.setLevel(logging.DEBUG)
    logger.propagate = False

    fmt = RedactingFormatter("%(asctime)s %(levelname)-7s [%(threadName)s] %(name)s: %(message)s")
    file_level = getattr(logging, level.upper(), logging.INFO)

    if console and sys.stderr is not None:  # None under pythonw.exe
        stream = logging.StreamHandler(sys.stderr)
        stream.setLevel(getattr(logging, (console_level or level).upper(), logging.INFO))
        stream.setFormatter(RedactingFormatter("%(asctime)s %(levelname)-7s %(message)s", "%H:%M:%S"))
        logger.addHandler(stream)

    if log_dir is not None:
        path = Path(log_dir)
        path.mkdir(parents=True, exist_ok=True)
        try:
            os.chmod(path, 0o700)
        except OSError:
            pass
        handler = logging.handlers.RotatingFileHandler(
            path / "agent.log", maxBytes=max_bytes, backupCount=backup_count, encoding="utf-8"
        )
        handler.setLevel(file_level)
        handler.setFormatter(fmt)
        logger.addHandler(handler)

    if not logger.handlers:
        logger.addHandler(logging.NullHandler())
    return logger
