"""Agent-side media (MEDIA_PROCESSING=agent): local FFmpeg metadata, thumbnail and AI frames."""

import hashlib
import json
import logging
import os
import sqlite3
import subprocess
import tempfile
import time
import unittest
from pathlib import Path

from reelflow_agent.api import ClientError
from reelflow_agent.media import (
    MediaError,
    MediaSettings,
    MediaToolkit,
    VideoMetadata,
)
from reelflow_agent.multipart import FilePart, encode_multipart
from reelflow_agent.state import DONE, MEDIA_FAILED, MEDIA_PENDING, MEDIA_SENT, StateDB
from reelflow_agent.uploader import ServerConfig

from fake_server import FakeSyncServer, parse_multipart
from helpers import ServerTestCase, TempDirTestCase, payload

LANDSCAPE_PROBE = {
    "streams": [
        {"index": 0, "codec_type": "video", "codec_name": "h264", "width": 1920, "height": 1080,
         "avg_frame_rate": "30000/1001", "r_frame_rate": "30000/1001", "duration": "12.012",
         "disposition": {"default": 1, "attached_pic": 0}},
        {"index": 1, "codec_type": "audio", "codec_name": "aac", "channels": 2},
    ],
    "format": {"duration": "12.000000", "format_name": "mov,mp4,m4a,3gp,3g2,mj2", "bit_rate": "2500000"},
}

# Phone portrait clip: cover art first, landscape pixels + rotation, no usable avg_frame_rate.
ROTATED_PROBE = {
    "streams": [
        {"index": 0, "codec_type": "video", "codec_name": "mjpeg", "width": 600, "height": 600,
         "disposition": {"attached_pic": 1}},
        {"index": 1, "codec_type": "video", "codec_name": "hevc", "width": 1920, "height": 1080,
         "avg_frame_rate": "0/0", "r_frame_rate": "60/1", "tags": {"rotate": "90"},
         "side_data_list": [{"side_data_type": "Display Matrix", "rotation": -90}]},
        {"index": 2, "codec_type": "audio", "codec_name": "opus"},
    ],
    "format": {"duration": "8.5", "format_name": "mov,mp4,m4a,3gp,3g2,mj2"},
}

AUDIO_ONLY_PROBE = {
    "streams": [
        {"codec_type": "video", "codec_name": "png", "width": 500, "height": 500, "disposition": {"attached_pic": 1}},
        {"codec_type": "audio", "codec_name": "mp3"},
    ],
    "format": {"duration": "200.0"},
}


def fake_jpeg(tag: bytes) -> bytes:
    # Includes CRLF and "--" so multipart framing is exercised.
    return b"\xff\xd8\xff\xe0\x00\x10JFIF\x00" + tag + b"\r\n--\r\n\r\n" + payload(300) + b"\xff\xd9"


class FakeRunner:
    """Stands in for subprocess: canned ffprobe JSON, ffmpeg writes small fake JPEGs."""

    def __init__(self, probe=None, *, fail_ffmpeg: int = 0):
        self.probe = LANDSCAPE_PROBE if probe is None else probe
        self.calls: list[list[str]] = []
        self.outputs: list[str] = []
        self.fail_ffmpeg = fail_ffmpeg

    def __call__(self, args, timeout):
        args = [str(a) for a in args]
        self.calls.append(args)
        name = os.path.basename(args[0])
        if "-version" in args:
            return f"{name} version 9.9-fake\n".encode()
        if "ffprobe" in name:
            return json.dumps(self.probe).encode()
        if self.fail_ffmpeg:
            self.fail_ffmpeg -= 1
            raise MediaError(f"ffmpeg failed (exit 1): {args[-1]}: Invalid data")
        out = args[-1]
        self.outputs.append(out)
        Path(out).write_bytes(fake_jpeg(os.path.basename(out).encode()))
        return b""

    def ffmpeg_calls(self):
        return [c for c in self.calls if "ffmpeg" in os.path.basename(c[0]) and "-version" not in c]

    def seek_args(self):
        return [c[c.index("-ss") + 1] for c in self.ffmpeg_calls()]


class ListHandler(logging.Handler):
    def __init__(self):
        super().__init__(logging.DEBUG)
        self.records: list[logging.LogRecord] = []

    def emit(self, record):
        self.records.append(record)

    def messages(self, level=logging.DEBUG):
        return [r.getMessage() for r in self.records if r.levelno >= level]


class MediaTestCase(ServerTestCase):
    def setUp(self):
        super().setUp()
        self.media_tmp = self.root / "media-tmp"
        self.media_tmp.mkdir()
        self.runner = FakeRunner()
        self.logs = ListHandler()
        logger = logging.getLogger("reelflow_agent")
        self._old_level = logger.level
        logger.setLevel(logging.DEBUG)
        logger.addHandler(self.logs)

    def tearDown(self):
        logger = logging.getLogger("reelflow_agent")
        logger.removeHandler(self.logs)
        logger.setLevel(self._old_level)
        super().tearDown()

    def toolkit(self, runner=None, *, present=True) -> MediaToolkit:
        return MediaToolkit(
            "ffmpeg" if present else None,
            "ffprobe" if present else None,
            runner=runner or self.runner,
            which=(lambda name: f"/opt/fake/bin/{name}") if present else (lambda name: None),
            search_common=False,
            temp_root=str(self.media_tmp),
        )

    def agent(self, toolkit=None):
        return self.make_agent(media_toolkit=toolkit or self.toolkit())

    def media_requests(self):
        return self.server.requests_matching("POST", r"^/api/sync/videos/\d+/media$")

    def assert_no_local_paths(self, raw: bytes):
        for p in (self.root, self.watch, self.media_tmp):
            self.assertNotIn(str(p).encode(), raw)


class AgentMediaFlowTests(MediaTestCase):
    def test_media_sent_after_upload_in_agent_mode(self):
        self.server.media_processing = "agent"
        data = payload(5000)
        self.write("Trip/clip.mp4", data)
        summary = self.agent().scan_once()
        self.assertEqual(summary.exit_code, 0, summary.message)
        self.assertEqual(summary.processed, {DONE: 1})

        rec = self.db.get("Main:Trip/clip.mp4")
        self.assertEqual(rec.status, DONE)
        self.assertEqual(rec.media_status, MEDIA_SENT)
        self.assertIsNone(rec.media_error)
        video = self.server.videos[int(rec.video_id)]
        self.assertEqual(video.content, data)
        self.assertTrue(video.media_received)
        self.assertEqual(video.metadata, {"width": 1920, "height": 1080, "duration": 12.0, "frame_rate": 29.97,
                                          "video_codec": "h264", "audio_codec": "aac", "has_audio": True})
        self.assertEqual(len(video.frames), 6)
        self.assertTrue(video.thumbnail.startswith(b"\xff\xd8"))

        # Bytes first, then media; exactly one media request.
        paths = [(r.method, r.path) for r in self.server.requests]
        complete_idx = next(i for i, (m, p) in enumerate(paths) if p.endswith("/complete"))
        media_idx = next(i for i, (m, p) in enumerate(paths) if p.endswith("/media"))
        self.assertLess(complete_idx, media_idx)
        self.assertEqual(len(self.media_requests()), 1)

        req = self.media_requests()[0]
        self.assertTrue(req.headers["Content-Type"].startswith("multipart/form-data; boundary="))
        self.assertEqual(req.headers["Authorization"], f"Bearer {self.server.token}")
        self.assert_no_local_paths(req.body)
        post = self.server.media_posts[0]
        self.assertEqual(post.files["thumbnail"][0].filename, "thumbnail.jpg")
        self.assertEqual(post.files["thumbnail"][0].content_type, "image/jpeg")
        self.assertEqual([f.filename for f in post.files["frames[]"]], [f"frame_{i:02d}.jpg" for i in range(6)])

        # Exact ffprobe/ffmpeg arguments (same as FfmpegMediaToolkit.php).
        abs_path = os.path.abspath(self.watch / "Trip" / "clip.mp4")
        self.assertEqual(self.runner.calls[0], ["/opt/fake/bin/ffprobe", "-v", "error", "-print_format", "json",
                                                "-show_format", "-show_streams", abs_path])
        thumb = self.runner.ffmpeg_calls()[0]
        self.assertEqual(thumb[:9], ["/opt/fake/bin/ffmpeg", "-y", "-v", "error", "-ss", "1", "-i", abs_path,
                                     "-frames:v"])
        self.assertEqual(thumb[9:14], ["1", "-vf", "scale=480:-2", "-q:v", "3"])
        frames = self.runner.ffmpeg_calls()[1:]
        self.assertEqual([c[c.index("-ss") + 1] for c in frames], ["1.00", "3.00", "5.00", "7.00", "9.00", "11.00"])
        for c in frames:
            self.assertEqual(c[c.index("-vf") + 1], "scale=512:-2")
            self.assertEqual(c[c.index("-q:v") + 1], "5")
        # Temp files were written in a private temp dir and removed afterwards.
        self.assertTrue(all(o.startswith(str(self.media_tmp)) for o in self.runner.outputs))
        self.assertEqual(os.listdir(self.media_tmp), [])
        self.assertIsNone(self.server.heartbeats[-1]["last_error"])

    def test_rotated_portrait_uses_server_frame_settings(self):
        self.server.media_processing = "agent"
        self.server.ai_frames, self.server.frame_width, self.server.thumbnail_width = 3, 320, 240
        self.runner.probe = ROTATED_PROBE
        self.write("portrait.mov", payload(1500))
        self.agent().scan_once()
        video = self.server.by_checksum[hashlib.sha256(payload(1500)).hexdigest()]
        self.assertEqual(video.metadata, {"width": 1080, "height": 1920, "duration": 8.5, "frame_rate": None,
                                          "video_codec": "hevc", "audio_codec": "opus", "has_audio": True})
        self.assertEqual(len(video.frames), 3)
        self.assertEqual(self.runner.seek_args(), ["1", "1.42", "4.25", "7.08"])
        self.assertEqual(self.runner.ffmpeg_calls()[0][self.runner.ffmpeg_calls()[0].index("-vf") + 1], "scale=240:-2")
        self.assertEqual(self.runner.ffmpeg_calls()[1][self.runner.ffmpeg_calls()[1].index("-vf") + 1], "scale=320:-2")

    def test_not_sent_in_server_mode(self):
        self.server.media_processing = "server"
        self.write("clip.mp4", payload(2000))
        agent = self.agent()
        summary = agent.scan_once()
        self.assertEqual(summary.processed, {DONE: 1})
        self.assertFalse(agent.media_required)
        self.assertEqual(self.media_requests(), [])
        self.assertEqual(self.runner.calls, [])
        self.assertIsNone(self.db.get("Main:clip.mp4").media_status)

    def test_not_sent_by_old_server_without_media_fields(self):
        self.server.media_processing = None
        self.write("clip.mp4", payload(2000))
        agent = self.agent()
        summary = agent.scan_once()
        self.assertEqual(summary.processed, {DONE: 1})
        self.assertNotIn("media_processing", self.server.requests_matching("POST", "heartbeat")[0].body.decode())
        self.assertEqual(agent.server_config.media_processing, "server")
        self.assertEqual(self.media_requests(), [])
        self.assertEqual(self.runner.calls, [])
        self.assertIsNone(self.db.get("Main:clip.mp4").media_status)

    def test_duplicate_gets_media_without_reuploading_bytes(self):
        self.server.media_processing = "agent"
        data = payload(3000)
        self.write("old.mp4", data)
        video = self.server.preload(data, uploaded=True)
        summary = self.agent().scan_once()
        self.assertEqual(summary.processed, {DONE: 1})
        self.assertEqual(self.server.chunk_puts(ok_only=False), [])
        self.assertEqual(len(self.media_requests()), 1)
        self.assertTrue(video.media_received)
        self.assertEqual(self.db.get("Main:old.mp4").media_status, MEDIA_SENT)

    def test_two_local_copies_send_media_once(self):
        self.server.media_processing = "agent"
        self.write("a.mp4", payload(2500))
        self.write("copy/b.mp4", payload(2500))
        self.agent().scan_once()
        self.assertEqual(sorted(self.server.chunk_puts()), [0, 1, 2])
        self.assertEqual(len(self.media_requests()), 1)

    def test_server_switched_to_agent_mode_sweeps_earlier_uploads(self):
        self.write("clip.mp4", payload(2000))
        self.agent().scan_once()  # server mode: no media
        self.assertEqual(self.media_requests(), [])
        registers = len(self.server.requests_matching("POST", r"/api/sync/videos$"))
        chunks = self.server.chunk_puts()

        self.server.media_processing = "agent"
        summary = self.agent().scan_once()
        self.assertEqual(summary.processed, {})
        self.assertEqual(summary.media, {MEDIA_SENT: 1})
        self.assertTrue(self.server.requests_matching("POST", r"/api/sync/videos/lookup$"))
        self.assertEqual(len(self.media_requests()), 1)
        self.assertEqual(len(self.server.requests_matching("POST", r"/api/sync/videos$")), registers)
        self.assertEqual(self.server.chunk_puts(), chunks)
        self.assertEqual(self.db.get("Main:clip.mp4").media_status, MEDIA_SENT)

        # Back to server mode: nothing more is sent.
        self.server.media_processing = "server"
        self.agent().scan_once()
        self.assertEqual(len(self.media_requests()), 1)

    def test_ffmpeg_missing_upload_continues_media_pending(self):
        self.server.media_processing = "agent"
        self.write("a.mp4", payload(2000))
        self.write("b.mp4", payload(2100, seed=3))
        summary = self.agent(self.toolkit(present=False)).scan_once()
        self.assertEqual(summary.exit_code, 0, summary.message)
        self.assertEqual(summary.processed, {DONE: 2})
        self.assertEqual(summary.media, {MEDIA_PENDING: 2})
        self.assertEqual(self.media_requests(), [])
        for key in ("Main:a.mp4", "Main:b.mp4"):
            rec = self.db.get(key)
            self.assertEqual(rec.status, DONE)
            self.assertEqual(rec.media_status, MEDIA_PENDING)
            self.assertEqual(rec.media_attempts, 0)  # an environment problem does not burn retries
            self.assertIn("sudo apt install ffmpeg", rec.media_error)
            self.assertEqual(self.server.videos[int(rec.video_id)].uploaded, True)
        errors = [m for m in self.logs.messages(logging.ERROR) if "FFmpeg" in m]
        self.assertEqual(len(errors), 1, errors)  # one clear message, not one per file
        for hint in ("sudo apt install ffmpeg", "winget install Gyan.FFmpeg", "https://www.gyan.dev/ffmpeg/builds/",
                     "ffmpeg_path", "ffprobe_path"):
            self.assertIn(hint, errors[0])
        self.assertIn("FFmpeg", self.server.heartbeats[-1]["last_error"])  # visible on the dashboard

        # FFmpeg installed later: the pending media is sent, bytes are not uploaded again.
        chunks = self.server.chunk_puts()
        summary = self.agent().scan_once()
        self.assertEqual(summary.media, {MEDIA_SENT: 2})
        self.assertEqual(len(self.media_requests()), 2)
        self.assertEqual(self.server.chunk_puts(), chunks)
        self.assertEqual(self.db.media_counts(), {MEDIA_PENDING: 0, MEDIA_SENT: 2, MEDIA_FAILED: 0})

    def test_run_loop_dispatches_pending_media(self):
        self.server.media_processing = "agent"
        self.write("clip.mp4", payload(2000))
        self.agent(self.toolkit(present=False)).scan_once()
        self.assertEqual(self.db.get("Main:clip.mp4").media_status, MEDIA_PENDING)
        self.db.update("Main:clip.mp4", media_next_retry_at=0)
        agent = self.agent()
        agent.heartbeat()
        from concurrent.futures import ThreadPoolExecutor
        with ThreadPoolExecutor(1) as ex:
            self.assertEqual(agent.dispatch(ex), 1)
            for fut in list(agent._inflight.values()):
                fut.result(10)
        agent.reap()
        self.assertEqual(self.db.get("Main:clip.mp4").media_status, MEDIA_SENT)

    def test_transient_500_is_retried(self):
        self.server.media_processing = "agent"
        self.server.inject("POST", r"/media$", 500, times=2)
        self.write("clip.mp4", payload(2000))
        self.agent().scan_once()
        rec = self.db.get("Main:clip.mp4")
        self.assertEqual(rec.media_status, MEDIA_SENT)
        self.assertEqual(rec.media_attempts, 0)
        self.assertEqual([r.status for r in self.media_requests()], [500, 500, 200])

    def test_exhausted_500_schedules_media_retry(self):
        self.server.media_processing = "agent"
        self.server.inject("POST", r"/media$", 503, times=100)
        self.write("clip.mp4", payload(2000))
        agent = self.agent()
        agent.heartbeat()
        agent.collect_stable()
        rec = self.db.get("Main:clip.mp4")
        self.assertEqual(agent.uploader.process(rec), DONE)  # the upload itself is not affected
        rec = self.db.get("Main:clip.mp4")
        self.assertEqual(rec.status, DONE)
        self.assertEqual(rec.media_status, MEDIA_PENDING)
        self.assertEqual(rec.media_attempts, 1)
        self.assertGreater(rec.media_next_retry_at, time.time() - 1)
        self.assertEqual(self.db.media_due(time.time() - 10), [])  # waits for its backoff
        self.server.injections.clear()
        self.assertEqual(agent.uploader.process_media(rec), MEDIA_SENT)

    def test_retries_exhausted_marks_failed(self):
        self.server.media_processing = "agent"
        self.server.inject("POST", r"/media$", 500, times=1000)
        self.write("clip.mp4", payload(2000))
        agent = self.agent()
        agent.scan_once()
        for _ in range(self.config.max_retries + 2):
            rec = self.db.get("Main:clip.mp4")
            agent.uploader.process_media(rec)
        rec = self.db.get("Main:clip.mp4")
        self.assertEqual(rec.media_status, MEDIA_FAILED)
        self.assertEqual(rec.media_attempts, self.config.max_retries + 1)

    def test_validation_error_marks_failed_not_retried(self):
        self.server.media_processing = "agent"
        self.server.inject("POST", r"/media$", 422, {
            "message": "The width field must be an integer between 1 and 16384.",
            "errors": {"metadata.width": ["The width field must be an integer between 1 and 16384."]},
        })
        self.write("clip.mp4", payload(2000))
        summary = self.agent().scan_once()
        self.assertEqual(summary.processed, {DONE: 1})
        rec = self.db.get("Main:clip.mp4")
        self.assertEqual(rec.status, DONE)
        self.assertEqual(rec.media_status, MEDIA_FAILED)
        self.assertIn("metadata.width", rec.media_error)
        self.assertEqual(len(self.media_requests()), 1)
        # Neither later scans nor the server still reporting needs_media retry it.
        self.agent().scan_once()
        self.agent().scan_once()
        self.assertEqual(len(self.media_requests()), 1)
        self.assertEqual(self.db.get("Main:clip.mp4").media_status, MEDIA_FAILED)
        self.assertIn("metadata.width", self.server.heartbeats[-1]["last_error"])
        # `retry-failed` puts it back in the queue.
        self.assertEqual(self.db.retry_failed_media(), 1)
        self.assertEqual(self.agent().scan_once().media, {MEDIA_SENT: 1})

    def test_no_video_stream_fails_permanently(self):
        self.server.media_processing = "agent"
        self.runner.probe = AUDIO_ONLY_PROBE
        self.write("song.mp4", payload(2000))
        summary = self.agent().scan_once()
        self.assertEqual(summary.processed, {DONE: 1})
        rec = self.db.get("Main:song.mp4")
        self.assertEqual(rec.media_status, MEDIA_FAILED)
        self.assertEqual(rec.media_attempts, 1)
        self.assertIn("No playable video stream", rec.media_error)
        self.assertEqual(self.media_requests(), [])
        self.assertEqual(os.listdir(self.media_tmp), [])

    def test_ffmpeg_error_is_scrubbed_and_retried(self):
        self.server.media_processing = "agent"
        self.runner.fail_ffmpeg = 1  # thumbnail fails once
        self.write("clip.mp4", payload(2000))
        self.agent().scan_once()
        rec = self.db.get("Main:clip.mp4")
        # Inline attempt failed (pending with backoff), the scan's media pass did not retry it immediately.
        self.assertEqual(rec.media_status, MEDIA_PENDING)
        self.assertEqual(rec.media_attempts, 1)
        self.assertIn("<tmp>", rec.media_error)
        self.assertNotIn(str(self.media_tmp), rec.media_error)
        self.assertEqual(self.agent().scan_once().media, {MEDIA_SENT: 1})

    def test_changed_file_resets_media_state(self):
        self.server.media_processing = "agent"
        self.write("clip.mp4", payload(2000))
        self.agent().scan_once()
        self.assertEqual(self.db.get("Main:clip.mp4").media_status, MEDIA_SENT)
        rec, changed = self.db.record_stable("Main", "clip.mp4", 2001, 1)
        self.assertTrue(changed)
        self.assertIsNone(rec.media_status)


class FakeServerMediaEndpointTests(MediaTestCase):
    def post(self, video_id, fields, files):
        body, ctype = encode_multipart(fields, files)
        return self.api.post_media(video_id, body, ctype)

    def test_endpoint_validation(self):
        self.server.media_processing = "agent"
        video = self.server.preload(payload(100), uploaded=True)
        video.sources.append(("Main", "x.mp4"))
        meta = json.dumps({"width": 0, "height": 10, "duration": 1})
        with self.assertRaises(ClientError) as ctx:
            self.post(video.id, [("metadata", meta)], [FilePart("frames[]", "f.jpg", b"not a jpeg", "image/jpeg")])
        self.assertEqual(ctx.exception.status, 422)
        errors = ctx.exception.payload["errors"]
        self.assertIn("metadata.width", errors)
        self.assertIn("thumbnail", errors)
        self.assertIn("frames.0", errors)
        with self.assertRaises(ClientError) as ctx:
            self.post(999, [("metadata", "{}")], [])
        self.assertEqual(ctx.exception.status, 404)
        ok = self.post(video.id, [("metadata", json.dumps({"width": 4, "height": 2, "duration": 3.5}))],
                       [FilePart("thumbnail", "thumbnail.jpg", fake_jpeg(b"t"), "image/jpeg")])
        self.assertEqual(ok["video"], {"id": video.id, "status": "uploaded", "needs_media": False, "frames": 0})


class MultipartTests(unittest.TestCase):
    def test_round_trip(self):
        blob = b"\r\n--\r\n\r\nContent-Disposition: x\r\n" + bytes(range(256))
        fields = [("metadata", json.dumps({"video_codec": "h264", "note": "über \"quoted\""})), ("empty", "")]
        files = [FilePart("thumbnail", "thumbnail.jpg", blob, "image/jpeg"),
                 FilePart("frames[]", "frame_00.jpg", b"", "image/jpeg"),
                 FilePart("frames[]", 'we"ird.jpg', b"x" * 10, "image/jpeg")]
        body, ctype = encode_multipart(fields, files)
        parsed_fields, parsed_files = parse_multipart(body, ctype)
        self.assertEqual(parsed_fields, {"metadata": [fields[0][1]], "empty": [""]})
        self.assertEqual(parsed_files["thumbnail"][0].content, blob)
        self.assertEqual(parsed_files["thumbnail"][0].content_type, "image/jpeg")
        self.assertEqual([f.content for f in parsed_files["frames[]"]], [b"", b"x" * 10])
        self.assertEqual(parsed_files["frames[]"][1].filename, "we%22ird.jpg")

    def test_boundary_never_occurs_in_content(self):
        body, ctype = encode_multipart([("a", "--fixedboundary")], [], boundary="fixedboundary")
        self.assertNotIn("boundary=fixedboundary", ctype)
        self.assertEqual(parse_multipart(body, ctype)[0], {"a": ["--fixedboundary"]})


class MetadataParsingTests(unittest.TestCase):
    def test_landscape(self):
        meta = VideoMetadata.from_ffprobe(LANDSCAPE_PROBE)
        self.assertEqual((meta.width, meta.height, meta.duration, meta.frame_rate), (1920, 1080, 12.0, 29.97))
        self.assertEqual((meta.video_codec, meta.audio_codec, meta.has_audio, meta.bit_rate), ("h264", "aac", True, 2500000))

    def test_rotation_swaps_dimensions_and_skips_cover_art(self):
        meta = VideoMetadata.from_ffprobe(ROTATED_PROBE)
        self.assertEqual((meta.width, meta.height), (1080, 1920))
        self.assertEqual(meta.video_codec, "hevc")
        self.assertIsNone(meta.frame_rate)  # "0/0" (PHP ?? does not fall through to r_frame_rate)

    def test_rotation_variants(self):
        def probe(rotation_tag=None, side=None):
            stream = {"codec_type": "video", "codec_name": "h264", "width": 640, "height": 360, "r_frame_rate": "25/1"}
            if rotation_tag is not None:
                stream["tags"] = {"rotate": rotation_tag}
            if side is not None:
                stream["side_data_list"] = [{"side_data_type": "Display Matrix", "rotation": side}]
            return VideoMetadata.from_ffprobe({"streams": [stream], "format": {}})

        self.assertEqual((probe("270").width, probe("270").height), (360, 640))
        self.assertEqual((probe("180").width, probe("180").height), (640, 360))
        self.assertEqual((probe(side=90).width, probe(side=90).height), (360, 640))
        self.assertEqual((probe("90", side=0).width, probe("90", side=0).height), (640, 360))  # side data wins
        self.assertEqual(probe().frame_rate, 25.0)
        self.assertIsNone(probe().duration)
        with self.assertRaises(MediaError) as ctx:
            probe().to_payload()
        self.assertTrue(ctx.exception.permanent)

    def test_no_video_stream(self):
        meta = VideoMetadata.from_ffprobe(AUDIO_ONLY_PROBE)
        self.assertFalse(meta.has_video)
        with self.assertRaises(MediaError) as ctx:
            meta.to_payload()
        self.assertTrue(ctx.exception.permanent)

    def test_duration_falls_back_to_stream(self):
        probe = {"streams": [{"codec_type": "video", "width": 2, "height": 2, "duration": "4.12345"}]}
        self.assertEqual(VideoMetadata.from_ffprobe(probe).duration, 4.123)

    def test_payload_truncates_codec_names(self):
        meta = VideoMetadata(10, 10, 1.0, 2000.0, "x" * 50, None, True, False)
        payload = meta.to_payload()
        self.assertEqual(len(payload["video_codec"]), 40)
        self.assertIsNone(payload["frame_rate"])  # outside the server's 0..1000 range


class ToolkitTests(TempDirTestCase):
    def test_frame_times(self):
        self.assertEqual(MediaToolkit.frame_times(10, 4), [1.25, 3.75, 6.25, 8.75])
        self.assertEqual(MediaToolkit.frame_times(0, 3), [0.0, 0.0, 0.0])
        self.assertEqual(MediaToolkit.frame_times(10, 0), [5.0])

    def test_frames_clamped_to_server_max(self):
        runner = FakeRunner()
        kit = MediaToolkit("ffmpeg", "ffprobe", runner=runner, which=lambda n: "/x/" + n, search_common=False,
                           temp_root=str(self.root))
        video = self.write("v.mp4", b"data")
        bundle = kit.generate(str(video), MediaSettings(ai_frames=40, frame_width=100, thumbnail_width=50))
        self.assertEqual(len(bundle.frames), 12)
        self.assertEqual(sorted(os.listdir(self.root)), ["videos"])

    def test_failed_frame_is_skipped(self):
        class FailSecondFrame(FakeRunner):
            def __call__(self, args, timeout):
                if str(args[-1]).endswith("frame_1.jpg"):
                    raise MediaError("ffmpeg failed (exit 1): seek error")
                return super().__call__(args, timeout)

        kit = MediaToolkit("ffmpeg", "ffprobe", runner=FailSecondFrame(), which=lambda n: "/x/" + n,
                           search_common=False, temp_root=str(self.root))
        bundle = kit.generate(str(self.write("v.mp4", b"data")), MediaSettings(ai_frames=4))
        self.assertEqual(len(bundle.frames), 3)

    def test_locate_explicit_paths_and_sibling(self):
        bindir = self.root / "bin"
        bindir.mkdir()
        exe = ".exe" if os.name == "nt" else ""
        for name in ("ffmpeg", "ffprobe"):
            p = bindir / (name + exe)
            p.write_text("#!/bin/sh\n")
            p.chmod(0o755)
        kit = MediaToolkit(str(bindir / ("ffmpeg" + exe)), None, which=lambda n: None, search_common=False)
        self.assertEqual(kit.locate(), (str(bindir / ("ffmpeg" + exe)), str(bindir / ("ffprobe" + exe))))
        kit = MediaToolkit(str(self.root / "nope" / "ffmpeg"), None, which=lambda n: None, search_common=False)
        ffmpeg, ffprobe = kit.locate()
        self.assertIsNone(ffmpeg)
        self.assertIn("ffmpeg_path", kit.missing_message())
        kit = MediaToolkit(None, None, which=lambda n: None, search_common=False)
        self.assertEqual(kit.locate(), (None, None))
        self.assertIn("not found on PATH", kit.missing_message())

    def test_server_config_parsing(self):
        cfg = ServerConfig.from_payload({"media_processing": "agent", "ai_frames": 30, "frame_width": 640})
        self.assertTrue(cfg.agent_media)
        self.assertEqual((cfg.ai_frames, cfg.frame_width, cfg.thumbnail_width), (12, 640, 480))
        self.assertEqual(ServerConfig.from_payload(cfg.to_payload()), cfg)
        old = ServerConfig.from_payload({"chunk_size_bytes": 5})
        self.assertFalse(old.agent_media)
        self.assertEqual(old.media_processing, "server")


class StateMigrationTests(TempDirTestCase):
    V1_SCHEMA = """
    CREATE TABLE files (
        path_key TEXT PRIMARY KEY, label TEXT NOT NULL, relative_path TEXT NOT NULL, size INTEGER NOT NULL,
        mtime_ns INTEGER NOT NULL, sha256 TEXT, status TEXT NOT NULL, video_id TEXT, session_id TEXT,
        stale_session_id TEXT, server_status TEXT, attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT,
        next_retry_at REAL NOT NULL DEFAULT 0, created_at REAL NOT NULL, updated_at REAL NOT NULL
    );
    CREATE INDEX idx_files_status ON files(status, next_retry_at);
    CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
    INSERT INTO files (path_key, label, relative_path, size, mtime_ns, sha256, status, video_id, created_at, updated_at)
        VALUES ('Main:a.mp4', 'Main', 'a.mp4', 10, 1, 'ab', 'done', '5', 1, 1);
    PRAGMA user_version = 1;
    """

    def test_old_state_db_is_migrated(self):
        path = self.root / "state.sqlite3"
        conn = sqlite3.connect(path)
        conn.executescript(self.V1_SCHEMA)
        conn.close()
        with StateDB(path) as db:
            rec = db.get("Main:a.mp4")
            self.assertEqual((rec.status, rec.video_id, rec.sha256), (DONE, "5", "ab"))
            self.assertIsNone(rec.media_status)
            self.assertEqual(rec.media_attempts, 0)
            db.update(rec.path_key, media_status=MEDIA_PENDING, media_error="x")
            self.assertEqual(len(db.media_due(10**12)), 1)
            self.assertEqual(db.media_counts()[MEDIA_PENDING], 1)
        with StateDB(path) as db:  # re-opening is a no-op
            self.assertEqual(db.get("Main:a.mp4").media_status, MEDIA_PENDING)
        conn = sqlite3.connect(path)
        try:
            self.assertEqual(conn.execute("PRAGMA user_version").fetchone()[0], 2)
            indexes = {r[1] for r in conn.execute("PRAGMA index_list(files)")}
            self.assertIn("idx_files_media", indexes)
        finally:
            conn.close()


FFMPEG = os.environ.get("REELFLOW_TEST_FFMPEG")
FFPROBE = os.environ.get("REELFLOW_TEST_FFPROBE")


def jpeg_size(data: bytes) -> tuple[int, int]:
    """(width, height) from a JPEG's SOF marker."""
    i = 2
    while i < len(data):
        if data[i] != 0xFF:
            i += 1
            continue
        marker = data[i + 1]
        if marker in (0xC0, 0xC1, 0xC2):
            h = int.from_bytes(data[i + 5 : i + 7], "big")
            w = int.from_bytes(data[i + 7 : i + 9], "big")
            return w, h
        if marker in (0xD8, 0x01) or 0xD0 <= marker <= 0xD7:
            i += 2
            continue
        i += 2 + int.from_bytes(data[i + 2 : i + 4], "big")
    raise AssertionError("no SOF marker")


@unittest.skipUnless(FFMPEG and FFPROBE, "set REELFLOW_TEST_FFMPEG and REELFLOW_TEST_FFPROBE to run with real FFmpeg")
class RealFfmpegIntegrationTests(MediaTestCase):
    def make_video(self, rel: str, *, rotate: bool = False) -> Path:
        out = self.watch / rel
        out.parent.mkdir(parents=True, exist_ok=True)
        subprocess.run([FFMPEG, "-y", "-v", "error", "-f", "lavfi", "-i", "testsrc=duration=3:size=320x240:rate=25",
                        "-f", "lavfi", "-i", "sine=frequency=440:duration=3", "-c:v", "mpeg4", "-c:a", "aac",
                        "-shortest", str(out)], check=True, timeout=120)
        if rotate:
            tmp = out.with_name("rot-" + out.name)
            res = subprocess.run([FFMPEG, "-y", "-v", "error", "-display_rotation", "90", "-i", str(out), "-c", "copy",
                                  str(tmp)], timeout=120)
            if res.returncode != 0:
                self.skipTest("this FFmpeg cannot write a display rotation")
            os.replace(tmp, out)
        return out

    def real_toolkit(self):
        return MediaToolkit(FFMPEG, FFPROBE, temp_root=str(self.media_tmp), timeout=120)

    def test_real_pipeline(self):
        self.server.media_processing = "agent"
        self.make_video("real.mp4")
        self.make_video("portrait.mp4", rotate=True)
        summary = self.agent(self.real_toolkit()).scan_once()
        self.assertEqual(summary.exit_code, 0, summary.message)
        self.assertEqual(summary.processed, {DONE: 2})
        self.assertEqual(self.db.media_counts()[MEDIA_SENT], 2, self.db.media_problems())

        for rel, size in (("real.mp4", (320, 240)), ("portrait.mp4", (240, 320))):
            video = self.server.videos[int(self.db.get(f"Main:{rel}").video_id)]
            meta = video.metadata
            self.assertEqual((meta["width"], meta["height"]), size)
            self.assertAlmostEqual(meta["duration"], 3.0, delta=0.2)
            self.assertAlmostEqual(meta["frame_rate"], 25.0, delta=0.01)
            self.assertEqual(meta["video_codec"], "mpeg4")
            self.assertEqual(meta["audio_codec"], "aac")
            self.assertTrue(meta["has_audio"])
            self.assertTrue(video.thumbnail.startswith(b"\xff\xd8") and video.thumbnail.rstrip(b"\x00").endswith(b"\xff\xd9"))
            self.assertEqual(jpeg_size(video.thumbnail)[0], 480)
            self.assertEqual(len(video.frames), 6)
            for frame in video.frames:
                self.assertTrue(frame.startswith(b"\xff\xd8"))
                self.assertEqual(jpeg_size(frame)[0], 512)
        self.assertEqual(os.listdir(self.media_tmp), [])

    def test_real_toolkit_reports_version(self):
        kit = self.real_toolkit()
        ffmpeg, ffprobe = kit.locate()
        self.assertEqual((ffmpeg, ffprobe), (FFMPEG, FFPROBE))
        self.assertIn("version", kit.version(ffmpeg))


if __name__ == "__main__":
    unittest.main()
