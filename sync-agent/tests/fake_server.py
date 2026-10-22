"""In-process fake of the ReelFlow sync API (mirrors app/Http/Controllers/Api/SyncController.php)."""

from __future__ import annotations

import hashlib
import json
import math
import re
import threading
import uuid
from dataclasses import dataclass, field
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Callable

TOKEN = "rfd_testtoken_0123456789abcdef"


@dataclass
class Injection:
    method: str
    pattern: re.Pattern
    status: int
    body: dict[str, Any] | None
    headers: dict[str, str]
    times: int


@dataclass
class RecordedRequest:
    method: str
    path: str
    headers: dict[str, str]
    body: bytes
    status: int = 0


@dataclass
class Session:
    session_id: str
    video_id: int
    chunk_size: int
    total_chunks: int
    total_size: int
    status: str = "open"
    chunks: dict[int, bytes] = field(default_factory=dict)
    error: str | None = None
    device_token: str = TOKEN

    def payload(self) -> dict[str, Any]:
        return {
            "session_id": self.session_id,
            "status": self.status,
            "video_id": self.video_id,
            "chunk_size": self.chunk_size,
            "total_chunks": self.total_chunks,
            "received_chunks": sorted(self.chunks),
            "expires_at": "2099-01-01T00:00:00+00:00",
            "error": self.error,
        }

    def expected_size(self, idx: int) -> int:
        if idx == self.total_chunks - 1:
            return self.total_size - self.chunk_size * (self.total_chunks - 1)
        return self.chunk_size


@dataclass
class Video:
    id: int
    checksum: str
    size: int
    filename: str
    status: str = "discovered"
    uploaded: bool = False
    processing_error: str | None = None
    sources: list[tuple[str, str]] = field(default_factory=list)
    content: bytes | None = None


def _sanitize_relative(path: str) -> str | None:
    path = path.replace("\\", "/").strip()
    if not path or path.startswith("/") or re.match(r"^[A-Za-z]:", path) or "\x00" in path:
        return None
    parts = []
    for seg in path.split("/"):
        if seg in ("", "."):
            continue
        if seg == "..":
            return None
        parts.append(seg)
    return "/".join(parts) or None


class FakeSyncServer:
    def __init__(
        self,
        *,
        token: str = TOKEN,
        chunk_size: int = 1024,
        allowed_extensions: tuple[str, ...] = ("mp4", "mov", "webm", "m4v"),
        max_file_size: int = 10 * 1024 * 1024,
    ):
        self.token = token
        self.chunk_size = chunk_size
        self.allowed_extensions = list(allowed_extensions)
        self.max_file_size = max_file_size
        self.lock = threading.RLock()
        self.videos: dict[int, Video] = {}
        self.by_checksum: dict[str, Video] = {}
        self.sessions: dict[str, Session] = {}
        self.requests: list[RecordedRequest] = []
        self.injections: list[Injection] = []
        self.revoked = False
        self.corrupt_assembly = False
        self.on_chunk: Callable[[Session, int], None] | None = None
        self.heartbeats: list[dict[str, Any]] = []
        self._next_video_id = 1
        self._httpd: ThreadingHTTPServer | None = None
        self._thread: threading.Thread | None = None

    # -- lifecycle --------------------------------------------------------

    def start(self) -> "FakeSyncServer":
        server = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args: Any) -> None:  # silence
                pass

            def _handle(self) -> None:
                server._dispatch(self)

            do_GET = do_POST = do_PUT = do_DELETE = _handle

        self._httpd = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self._httpd.daemon_threads = True
        self._thread = threading.Thread(target=self._httpd.serve_forever, kwargs={"poll_interval": 0.05}, daemon=True)
        self._thread.start()
        return self

    def stop(self) -> None:
        if self._httpd:
            self._httpd.shutdown()
            self._httpd.server_close()

    @property
    def url(self) -> str:
        assert self._httpd is not None
        return f"http://127.0.0.1:{self._httpd.server_address[1]}"

    # -- test helpers -----------------------------------------------------

    def inject(self, method: str, path_regex: str, status: int, body: dict[str, Any] | None = None,
               headers: dict[str, str] | None = None, times: int = 1) -> None:
        with self.lock:
            self.injections.append(Injection(method, re.compile(path_regex), status, body, headers or {}, times))

    def requests_matching(self, method: str, path_regex: str) -> list[RecordedRequest]:
        rx = re.compile(path_regex)
        with self.lock:
            return [r for r in self.requests if r.method == method and rx.search(r.path)]

    def chunk_puts(self, ok_only: bool = True) -> list[int]:
        return [
            int(r.path.rsplit("/", 1)[1])
            for r in self.requests_matching("PUT", r"/chunks/\d+$")
            if not ok_only or r.status == 200
        ]

    def preload(self, content: bytes, filename: str = "pre.mp4", *, uploaded: bool = False,
                chunks: tuple[int, ...] = ()) -> Video:
        """Create a video (and an open session with some chunks) as if registered earlier."""
        checksum = hashlib.sha256(content).hexdigest()
        with self.lock:
            video = self._new_video(checksum, len(content), filename)
            if uploaded:
                video.uploaded, video.status, video.content = True, "uploaded", content
            else:
                video.status = "uploading"
                sess = self._new_session(video)
                for idx in chunks:
                    sess.chunks[idx] = content[idx * sess.chunk_size : (idx + 1) * sess.chunk_size]
            return video

    def session_for(self, video: Video) -> Session | None:
        with self.lock:
            for s in self.sessions.values():
                if s.video_id == video.id:
                    return s
        return None

    # -- internals --------------------------------------------------------

    def _new_video(self, checksum: str, size: int, filename: str) -> Video:
        video = Video(self._next_video_id, checksum, size, filename)
        self._next_video_id += 1
        self.videos[video.id] = video
        self.by_checksum[checksum] = video
        return video

    def _new_session(self, video: Video) -> Session:
        sess = Session(str(uuid.uuid4()), video.id, self.chunk_size, max(1, math.ceil(video.size / self.chunk_size)),
                       video.size)
        self.sessions[sess.session_id] = sess
        return sess

    def _send(self, h: BaseHTTPRequestHandler, rec: RecordedRequest, status: int, body: Any,
              headers: dict[str, str] | None = None) -> None:
        rec.status = status
        raw = json.dumps(body).encode()
        h.send_response(status)
        h.send_header("Content-Type", "application/json")
        h.send_header("Content-Length", str(len(raw)))
        for k, v in (headers or {}).items():
            h.send_header(k, v)
        h.end_headers()
        h.wfile.write(raw)

    def _dispatch(self, h: BaseHTTPRequestHandler) -> None:
        length = int(h.headers.get("Content-Length") or 0)
        body = h.rfile.read(length) if length else b""
        path = h.path.split("?", 1)[0]
        rec = RecordedRequest(h.command, path, {k: v for k, v in h.headers.items()}, body)
        with self.lock:
            self.requests.append(rec)
            for inj in self.injections:
                if inj.times > 0 and inj.method == h.command and inj.pattern.search(path):
                    inj.times -= 1
                    return self._send(h, rec, inj.status, inj.body or {"error": "injected", "message": "injected"},
                                      inj.headers)
        auth = h.headers.get("Authorization", "")
        if auth != f"Bearer {self.token}":
            return self._send(h, rec, 401, {"error": "unauthenticated", "message": "Unknown device token."})
        if self.revoked:
            return self._send(h, rec, 403, {"error": "device_revoked", "message": "This device has been revoked."})
        try:
            data = json.loads(body) if body and h.headers.get("Content-Type") == "application/json" else {}
        except ValueError:
            return self._send(h, rec, 400, {"error": "bad_json", "message": "bad json"})

        routes = [
            ("POST", r"^/api/sync/heartbeat$", self._heartbeat),
            ("POST", r"^/api/sync/videos/lookup$", self._lookup),
            ("POST", r"^/api/sync/videos$", self._register),
            ("GET", r"^/api/sync/videos/(\d+)$", self._show_video),
            ("GET", r"^/api/sync/uploads/([0-9a-f-]{36})$", self._show_upload),
            ("PUT", r"^/api/sync/uploads/([0-9a-f-]{36})/chunks/(\d+)$", self._chunk),
            ("POST", r"^/api/sync/uploads/([0-9a-f-]{36})/complete$", self._complete),
            ("POST", r"^/api/sync/uploads/([0-9a-f-]{36})/abort$", self._abort),
        ]
        for method, pattern, fn in routes:
            m = re.match(pattern, path)
            if m and method == h.command:
                with self.lock:
                    status, payload = fn(data, body, h.headers, *m.groups())
                if fn == self._chunk and status == 200 and self.on_chunk:
                    # Runs before the response so tests are deterministic.
                    self.on_chunk(self.sessions[m.group(1)], int(m.group(2)))
                self._send(h, rec, status, payload)
                return
        self._send(h, rec, 404, {"message": "Not Found"})

    # -- endpoints --------------------------------------------------------

    def _heartbeat(self, data, body, headers):
        self.heartbeats.append(data)
        return 200, {
            "server_time": "2026-10-22T10:00:00+00:00",
            "device": {"id": 7, "name": "Test PC"},
            "config": {
                "chunk_size_bytes": self.chunk_size,
                "allowed_extensions": self.allowed_extensions,
                "max_file_size_bytes": self.max_file_size,
            },
        }

    def _lookup(self, data, body, headers):
        out = {}
        for cs in data.get("checksums", []):
            v = self.by_checksum.get(cs.lower())
            if v:
                out[v.checksum] = {"video_id": v.id, "status": v.status, "uploaded": v.uploaded}
        return 200, {"videos": out}

    def _register(self, data, body, headers):
        for f in ("filename", "folder_label", "relative_path", "size", "checksum_sha256"):
            if f not in data:
                return 422, {"message": f"The {f} field is required.", "errors": {f: ["required"]}}
        if not re.fullmatch(r"[0-9a-fA-F]{64}", str(data["checksum_sha256"])):
            return 422, {"message": "bad checksum", "errors": {"checksum_sha256": ["invalid"]}}
        if "/" in data["filename"] or "\\" in data["filename"]:
            return 422, {"message": "bad filename", "errors": {"filename": ["invalid"]}}
        rel = _sanitize_relative(data["relative_path"])
        if rel is None:
            return 422, {"error": "invalid_path", "message": "relative_path must be relative."}
        ext = data["filename"].rpartition(".")[2].lower()
        if ext not in self.allowed_extensions:
            return 422, {"error": "invalid_extension", "message": f"File extension .{ext} is not allowed."}
        if not (0 < data["size"] <= self.max_file_size):
            return 422, {"error": "invalid_size", "message": "File size is outside the allowed range."}
        checksum = data["checksum_sha256"].lower()
        video = self.by_checksum.get(checksum)
        duplicate = video is not None
        if video and video.size != data["size"]:
            return 409, {"error": "checksum_conflict", "message": "different size"}
        if not video:
            video = self._new_video(checksum, data["size"], data["filename"])
        video.sources.append((data["folder_label"], rel))
        session = None
        if video.status in ("discovered", "uploading", "failed") and not video.uploaded:
            session = next((s for s in self.sessions.values()
                            if s.video_id == video.id and s.status in ("open", "assembling")), None)
            if session is None:
                session = self._new_session(video)
            video.status, video.processing_error = "uploading", None
        payload = {
            "video": {"id": video.id, "status": video.status, "uploaded": video.uploaded, "duplicate": duplicate},
            "upload": session.payload() if session else None,
        }
        return (200 if duplicate else 201), payload

    def _show_video(self, data, body, headers, vid):
        v = self.videos.get(int(vid))
        if not v:
            return 404, {"message": "Not Found"}
        return 200, {"video": {"id": v.id, "status": v.status, "uploaded": v.uploaded,
                               "processing_error": v.processing_error}}

    def _show_upload(self, data, body, headers, sid):
        s = self.sessions.get(sid)
        if not s:
            return 404, {"message": "Not Found"}
        return 200, {"upload": s.payload()}

    def _chunk(self, data, body, headers, sid, idx):
        s = self.sessions.get(sid)
        if not s:
            return 404, {"message": "Not Found"}
        sha = headers.get("X-Chunk-Sha256", "")
        if not re.fullmatch(r"[0-9a-fA-F]{64}", sha):
            return 422, {"error": "missing_checksum", "message": "X-Chunk-Sha256 header is required."}
        if s.status != "open":
            return 409, {"error": "session_closed", "message": "Upload session is not open."}
        idx = int(idx)
        if idx < 0 or idx >= s.total_chunks:
            return 422, {"error": "invalid_chunk", "message": "Chunk index out of range."}
        if headers.get("Content-Type") != "application/octet-stream":
            return 422, {"error": "bad_content_type", "message": "octet-stream required"}
        if len(body) != s.expected_size(idx):
            return 422, {"error": "chunk_size_mismatch", "message": "size mismatch"}
        if hashlib.sha256(body).hexdigest() != sha.lower():
            return 422, {"error": "chunk_checksum_mismatch", "message": "Chunk checksum mismatch."}
        s.chunks[idx] = body
        return 200, {"chunk_index": idx, "received_count": len(s.chunks), "total_chunks": s.total_chunks}

    def _complete(self, data, body, headers, sid):
        s = self.sessions.get(sid)
        if not s:
            return 404, {"message": "Not Found"}
        if s.status in ("assembling", "completed"):
            return 202, {"upload": s.payload()}
        if s.status != "open":
            return 409, {"error": "session_closed", "message": "Upload session is not open."}
        if len(s.chunks) != s.total_chunks:
            return 409, {"error": "upload_incomplete",
                         "message": f"Upload incomplete: {len(s.chunks)} of {s.total_chunks} chunks received."}
        s.status = "assembling"
        payload = {"upload": s.payload()}
        # "Run the assembly job" synchronously; the video endpoint shows the result.
        video = self.videos[s.video_id]
        content = b"".join(s.chunks[i] for i in range(s.total_chunks))
        if self.corrupt_assembly:
            content = content + b"x"
        if hashlib.sha256(content).hexdigest() == video.checksum and len(content) == video.size:
            video.status, video.uploaded, video.content = "uploaded", True, content
            s.status = "completed"
        else:
            video.status, video.processing_error = "failed", "Uploaded file did not match the registered checksum."
            s.status, s.error = "failed", "Checksum or size mismatch after assembly."
        return 202, payload

    def _abort(self, data, body, headers, sid):
        s = self.sessions.get(sid)
        if not s:
            return 404, {"message": "Not Found"}
        if not data.get("reason"):
            return 422, {"message": "reason required", "errors": {"reason": ["required"]}}
        if s.status == "open":
            s.status, s.error = "failed", data["reason"]
            s.chunks.clear()
            v = self.videos[s.video_id]
            v.status, v.processing_error = "failed", "Agent reported: " + data["reason"]
        return 200, {"upload": s.payload()}
