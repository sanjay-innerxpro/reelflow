"""Minimal ``.env`` parser (no python-dotenv dependency).

Supported syntax::

    # comment
    KEY=value
    export KEY=value
    KEY="double quoted with \\n escapes"   # trailing comment
    KEY='single quoted, literal'
    KEY=value # trailing comment (needs whitespace before '#')
"""

from __future__ import annotations

import os
import re
import stat
from pathlib import Path

_KEY_RE = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*$")
_ESCAPES = {"n": "\n", "r": "\r", "t": "\t", '"': '"', "\\": "\\", "$": "$"}


class EnvFileError(ValueError):
    """Raised for malformed .env content."""


def _parse_double_quoted(value: str, lineno: int) -> str:
    out: list[str] = []
    i = 1
    while i < len(value):
        ch = value[i]
        if ch == "\\" and i + 1 < len(value):
            nxt = value[i + 1]
            out.append(_ESCAPES.get(nxt, "\\" + nxt))
            i += 2
            continue
        if ch == '"':
            rest = value[i + 1 :].strip()
            if rest and not rest.startswith("#"):
                raise EnvFileError(f"line {lineno}: unexpected text after closing quote")
            return "".join(out)
        out.append(ch)
        i += 1
    raise EnvFileError(f"line {lineno}: unterminated double-quoted value")


def _parse_single_quoted(value: str, lineno: int) -> str:
    end = value.find("'", 1)
    if end == -1:
        raise EnvFileError(f"line {lineno}: unterminated single-quoted value")
    rest = value[end + 1 :].strip()
    if rest and not rest.startswith("#"):
        raise EnvFileError(f"line {lineno}: unexpected text after closing quote")
    return value[1:end]


def parse_env(text: str) -> dict[str, str]:
    """Parse .env text into a dict. Later keys override earlier ones."""
    result: dict[str, str] = {}
    if text.startswith("﻿"):  # BOM written by Windows Notepad
        text = text[1:]
    for lineno, raw in enumerate(text.splitlines(), start=1):
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        if line.startswith("export ") or line.startswith("export\t"):
            line = line[7:].lstrip()
        if "=" not in line:
            raise EnvFileError(f"line {lineno}: expected KEY=VALUE")
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()
        if not _KEY_RE.match(key):
            raise EnvFileError(f"line {lineno}: invalid variable name {key!r}")
        if value.startswith('"'):
            value = _parse_double_quoted(value, lineno)
        elif value.startswith("'"):
            value = _parse_single_quoted(value, lineno)
        else:
            value = re.split(r"\s+#", value, maxsplit=1)[0].rstrip()
        result[key] = value
    return result


def load_env_file(path: str | os.PathLike[str]) -> dict[str, str]:
    """Read and parse a .env file. Missing file -> empty dict."""
    p = Path(path)
    if not p.is_file():
        return {}
    return parse_env(p.read_text(encoding="utf-8"))


def insecure_permissions(path: str | os.PathLike[str]) -> bool:
    """True if a POSIX file is readable/writable by group or others."""
    if os.name != "posix":
        return False
    try:
        mode = os.stat(path).st_mode
    except OSError:
        return False
    return bool(mode & (stat.S_IRWXG | stat.S_IRWXO))
