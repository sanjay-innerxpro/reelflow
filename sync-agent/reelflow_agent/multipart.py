"""Minimal multipart/form-data encoder (RFC 7578), standard library only.

Parts are kept in memory: the agent only sends small JPEGs and a JSON string.
"""

from __future__ import annotations

import secrets
from dataclasses import dataclass
from typing import Iterable


@dataclass(frozen=True)
class FilePart:
    name: str
    filename: str
    content: bytes
    content_type: str = "application/octet-stream"


def _quote(value: str) -> str:
    """Escape a header parameter the way browsers do (HTML form encoding)."""
    return value.replace("\\", "\\\\").replace('"', "%22").replace("\r", "%0D").replace("\n", "%0A")


def _safe_header_value(value: str) -> str:
    if "\r" in value or "\n" in value:
        raise ValueError("header values must not contain line breaks")
    return value


def new_boundary() -> str:
    return "----ReelFlowAgent" + secrets.token_hex(16)


def encode_multipart(
    fields: Iterable[tuple[str, str]] = (),
    files: Iterable[FilePart] = (),
    *,
    boundary: str | None = None,
) -> tuple[bytes, str]:
    """Return ``(body, content_type_header)`` for the given text fields and files.

    A fresh random boundary is chosen (again) if it happens to occur in any part.
    """
    fields = [(str(n), str(v)) for n, v in fields]
    files = list(files)
    payloads = [v.encode("utf-8") for _, v in fields] + [f.content for f in files]
    for _ in range(10):
        boundary = boundary or new_boundary()
        marker = b"--" + boundary.encode("ascii")
        if not any(marker in p for p in payloads):
            break
        boundary = None
    else:  # pragma: no cover - astronomically unlikely
        raise ValueError("could not find a multipart boundary that is not part of the content")

    sep = b"--" + boundary.encode("ascii")
    out = bytearray()
    for name, value in fields:
        out += sep + b"\r\n"
        out += f'Content-Disposition: form-data; name="{_quote(name)}"\r\n\r\n'.encode("utf-8")
        out += value.encode("utf-8") + b"\r\n"
    for part in files:
        out += sep + b"\r\n"
        out += (
            f'Content-Disposition: form-data; name="{_quote(part.name)}"; filename="{_quote(part.filename)}"\r\n'
            f"Content-Type: {_safe_header_value(part.content_type)}\r\n\r\n"
        ).encode("utf-8")
        out += part.content + b"\r\n"
    out += sep + b"--\r\n"
    return bytes(out), f"multipart/form-data; boundary={boundary}"
