"""HTTP client for the ReelFlow sync API (stdlib urllib, outbound only).

Proxies: urllib honours HTTPS_PROXY / HTTP_PROXY / NO_PROXY automatically.
Redirects are *not* followed so the bearer token is never forwarded to
another host; a redirect is reported as a configuration error instead.
"""

from __future__ import annotations

import email.utils
import http.client
import json
import logging
import platform
import random
import socket
import ssl
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from typing import Any, Callable, Iterable

from . import __version__
from .config import normalize_server_url
from .logging_setup import redact, register_secret

log = logging.getLogger("reelflow_agent.api")

USER_AGENT = f"ReelFlowAgent/{__version__} (Python {platform.python_version()}; {platform.system()})"
MAX_RETRY_AFTER = 3600.0
LOOKUP_BATCH = 500


class ApiError(Exception):
    """Base class for API failures."""

    def __init__(self, status: int, code: str, message: str, *, retry_after: float | None = None, payload: Any = None):
        super().__init__(f"{code}: {message}" if message and message != code else code)
        self.status = status
        self.code = code
        self.message = message
        self.retry_after = retry_after
        self.payload = payload


class TransientError(ApiError):
    """429, 5xx or network problems: safe to retry later."""


class NetworkError(TransientError):
    """The server could not be reached at all (DNS, refused, timeout, TLS)."""


class AuthError(ApiError):
    """401: the device token is wrong or missing."""


class DeviceRevokedError(ApiError):
    """403 device_revoked: the device was revoked from the dashboard."""


class ClientError(ApiError):
    """Other 4xx responses (validation, conflicts, not found, ...)."""


class OperationCancelled(Exception):
    """A retry wait was interrupted by shutdown."""


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: D401
        return None  # urllib then raises HTTPError with the 3xx status


def parse_retry_after(value: str | None, now: float | None = None) -> float | None:
    """Retry-After as seconds (accepts delta-seconds or an HTTP date)."""
    if not value:
        return None
    value = value.strip()
    try:
        return max(0.0, float(value))
    except ValueError:
        pass
    try:
        dt = email.utils.parsedate_to_datetime(value)
    except (TypeError, ValueError):
        return None
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    now = time.time() if now is None else now
    return max(0.0, dt.timestamp() - now)


def backoff_delay(attempt: int, base: float, cap: float, rng: Callable[[], float] = random.random) -> float:
    """Exponential backoff with 50-100% jitter: base * 2**attempt, capped."""
    raw = min(cap, base * (2 ** min(attempt, 30)))
    return raw * (0.5 + rng() / 2)


def call_with_retries(
    fn: Callable[[], Any],
    *,
    max_retries: int,
    base_delay: float,
    max_delay: float,
    stop_event: threading.Event | None = None,
    what: str = "request",
    rng: Callable[[], float] = random.random,
    retry_on: tuple[type[BaseException], ...] = (TransientError,),
) -> Any:
    """Call ``fn`` and retry transient failures with backoff, honouring Retry-After."""
    attempt = 0
    while True:
        if stop_event is not None and stop_event.is_set():
            raise OperationCancelled()
        try:
            return fn()
        except retry_on as e:
            if attempt >= max_retries:
                raise
            delay = backoff_delay(attempt, base_delay, max_delay, rng)
            retry_after = getattr(e, "retry_after", None)
            if retry_after is not None:
                delay = max(delay, min(retry_after, MAX_RETRY_AFTER))
            attempt += 1
            log.warning("%s failed (%s); retry %d/%d in %.1fs", what, e, attempt, max_retries, delay)
            if stop_event is not None:
                if stop_event.wait(delay):
                    raise OperationCancelled() from None
            elif delay > 0:
                time.sleep(delay)


def _summarize_validation(payload: dict[str, Any]) -> str:
    errors = payload.get("errors")
    if isinstance(errors, dict):
        bits = []
        for field, msgs in list(errors.items())[:5]:
            msg = msgs[0] if isinstance(msgs, list) and msgs else msgs
            bits.append(f"{field}: {msg}")
        return "; ".join(bits)
    return ""


class ApiClient:
    def __init__(
        self,
        server_url: str,
        token: str,
        *,
        timeout: float = 120.0,
        verify_tls: bool = True,
    ):
        self.base_url = normalize_server_url(server_url)
        self._token = token
        register_secret(token)
        self.timeout = timeout
        if verify_tls:
            ctx = ssl.create_default_context()
        else:
            ctx = ssl.create_default_context()
            ctx.check_hostname = False
            ctx.verify_mode = ssl.CERT_NONE
        # build_opener keeps the default ProxyHandler (HTTPS_PROXY etc.).
        self._opener = urllib.request.build_opener(urllib.request.HTTPSHandler(context=ctx), _NoRedirect())

    def __repr__(self) -> str:
        return f"ApiClient({self.base_url!r})"

    # -- transport --------------------------------------------------------

    def request(
        self,
        method: str,
        path: str,
        *,
        json_body: Any = None,
        data: bytes | None = None,
        headers: dict[str, str] | None = None,
    ) -> tuple[int, dict[str, Any]]:
        url = self.base_url + path
        hdrs = {
            "Authorization": f"Bearer {self._token}",
            "Accept": "application/json",
            "User-Agent": USER_AGENT,
        }
        body: bytes | None
        if json_body is not None:
            body = json.dumps(json_body, separators=(",", ":")).encode("utf-8")
            hdrs["Content-Type"] = "application/json"
        elif data is not None:
            body = data
            hdrs["Content-Type"] = "application/octet-stream"
        else:
            body = b"" if method in ("POST", "PUT") else None
        if headers:
            hdrs.update(headers)
        req = urllib.request.Request(url, data=body, method=method, headers=hdrs)
        what = f"{method} {path}"
        try:
            with self._opener.open(req, timeout=self.timeout) as resp:
                status = resp.status
                raw = resp.read()
        except urllib.error.HTTPError as e:
            try:
                raw = e.read()
            except Exception:  # noqa: BLE001
                raw = b""
            finally:
                e.close()
            raise self._error_from(e.code, raw, e.headers, what) from None
        except ssl.SSLCertVerificationError as e:
            raise NetworkError(0, "tls_error", f"{what}: TLS certificate verification failed ({e.verify_message})") from None
        except urllib.error.URLError as e:
            raise NetworkError(0, "network_error", f"{what}: {getattr(e, 'reason', e)}") from None
        except (socket.timeout, TimeoutError) as e:
            raise NetworkError(0, "timeout", f"{what}: timed out") from None
        except (http.client.HTTPException, ConnectionError, OSError) as e:
            raise NetworkError(0, "network_error", f"{what}: {e.__class__.__name__}: {e}") from None
        return status, self._decode(raw, status, what)

    @staticmethod
    def _decode(raw: bytes, status: int, what: str) -> dict[str, Any]:
        if not raw.strip():
            return {}
        try:
            payload = json.loads(raw.decode("utf-8"))
        except (UnicodeDecodeError, ValueError):
            raise TransientError(status, "invalid_response", f"{what}: server did not return JSON") from None
        if not isinstance(payload, dict):
            raise TransientError(status, "invalid_response", f"{what}: unexpected JSON shape")
        return payload

    def _error_from(self, status: int, raw: bytes, headers: Any, what: str) -> ApiError:
        payload: Any = None
        try:
            payload = json.loads(raw.decode("utf-8")) if raw else None
        except (UnicodeDecodeError, ValueError):
            payload = None
        code = ""
        message = ""
        if isinstance(payload, dict):
            code = str(payload.get("error") or "")
            message = str(payload.get("message") or "")
            detail = _summarize_validation(payload)
            if detail:
                message = f"{message} ({detail})" if message else detail
        if not message:
            snippet = raw[:200].decode("utf-8", "replace").strip() if raw else ""
            message = f"HTTP {status}" + (f": {snippet}" if snippet and not snippet.startswith("<") else "")
        message = redact(f"{what}: {message}")[:1000]
        retry_after = parse_retry_after(headers.get("Retry-After") if headers is not None else None)

        if 300 <= status < 400:
            location = headers.get("Location", "?") if headers is not None else "?"
            return ClientError(
                status,
                "unexpected_redirect",
                f"{what}: server redirected to {location}; set REELFLOW_SERVER_URL to the final https:// URL",
            )
        if status == 401:
            return AuthError(status, code or "unauthenticated", message, payload=payload)
        if status == 403 and code == "device_revoked":
            return DeviceRevokedError(status, code, message, payload=payload)
        if status == 429:
            return TransientError(status, code or "rate_limited", message, retry_after=retry_after, payload=payload)
        if status >= 500:
            return TransientError(status, code or "server_error", message, retry_after=retry_after, payload=payload)
        if status == 422 and not code:
            code = "validation_failed"
        if status == 404 and not code:
            code = "not_found"
        return ClientError(status, code or f"http_{status}", message, payload=payload)

    # -- endpoints --------------------------------------------------------

    @staticmethod
    def _seg(value: Any) -> str:
        return urllib.parse.quote(str(value), safe="")

    def heartbeat(self, body: dict[str, Any]) -> dict[str, Any]:
        return self.request("POST", "/api/sync/heartbeat", json_body=body)[1]

    def lookup(self, checksums: Iterable[str]) -> dict[str, dict[str, Any]]:
        items = list(dict.fromkeys(c.lower() for c in checksums))
        found: dict[str, dict[str, Any]] = {}
        for i in range(0, len(items), LOOKUP_BATCH):
            batch = items[i : i + LOOKUP_BATCH]
            payload = self.request("POST", "/api/sync/videos/lookup", json_body={"checksums": batch})[1]
            videos = payload.get("videos") or {}
            if isinstance(videos, dict):
                found.update(videos)
        return found

    def register_video(
        self, *, filename: str, folder_label: str, relative_path: str, size: int, checksum_sha256: str
    ) -> tuple[int, dict[str, Any]]:
        return self.request(
            "POST",
            "/api/sync/videos",
            json_body={
                "filename": filename,
                "folder_label": folder_label,
                "relative_path": relative_path,
                "size": size,
                "checksum_sha256": checksum_sha256,
            },
        )

    def get_upload(self, session_id: str) -> dict[str, Any]:
        return self.request("GET", f"/api/sync/uploads/{self._seg(session_id)}")[1]

    def put_chunk(self, session_id: str, index: int, data: bytes, sha256_hex: str) -> dict[str, Any]:
        return self.request(
            "PUT",
            f"/api/sync/uploads/{self._seg(session_id)}/chunks/{int(index)}",
            data=data,
            headers={"X-Chunk-Sha256": sha256_hex},
        )[1]

    def complete_upload(self, session_id: str) -> dict[str, Any]:
        return self.request("POST", f"/api/sync/uploads/{self._seg(session_id)}/complete")[1]

    def abort_upload(self, session_id: str, reason: str) -> dict[str, Any]:
        return self.request(
            "POST", f"/api/sync/uploads/{self._seg(session_id)}/abort", json_body={"reason": reason[:2000]}
        )[1]

    def get_video(self, video_id: Any) -> dict[str, Any]:
        return self.request("GET", f"/api/sync/videos/{self._seg(video_id)}")[1]


def server_time_skew(server_time: str | None) -> float | None:
    """Seconds the local clock is ahead of the server (None if unknown)."""
    if not server_time:
        return None
    try:
        dt = datetime.fromisoformat(server_time.replace("Z", "+00:00"))
    except ValueError:
        return None
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return time.time() - dt.timestamp()
