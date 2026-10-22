import hashlib
import json
import os
import threading
import time
import unittest

from reelflow_agent.agent import EXIT_AUTH, EXIT_REVOKED
from reelflow_agent.api import ApiClient, AuthError, DeviceRevokedError, call_with_retries, parse_retry_after, TransientError
from reelflow_agent.state import DONE, FAILED, PENDING, REJECTED, SKIPPED
from reelflow_agent.uploader import REQUEUED

from helpers import ServerTestCase, payload


class UploadFlowTests(ServerTestCase):
    def assert_no_local_paths_sent(self):
        for req in self.server.requests:
            if req.headers.get("Content-Type") == "application/json":
                text = req.body.decode()
                self.assertNotIn(str(self.root), text)
                self.assertNotIn(str(self.watch), text)

    def test_full_upload_flow(self):
        data = payload(5000)  # 5 chunks of 1024 (last one partial)
        self.write("Trip/day 1.MP4", data)
        summary = self.make_agent().scan_once()
        self.assertEqual(summary.exit_code, 0, summary.message)
        self.assertEqual(summary.processed, {DONE: 1})

        rec = self.db.get("Main:Trip/day 1.MP4")
        self.assertEqual(rec.status, DONE)
        self.assertEqual(rec.server_status, "uploaded")
        self.assertEqual(rec.sha256, hashlib.sha256(data).hexdigest())
        video = self.server.videos[int(rec.video_id)]
        self.assertEqual(video.content, data)
        self.assertEqual(video.sources, [("Main", "Trip/day 1.MP4")])
        self.assertEqual(sorted(self.server.chunk_puts()), [0, 1, 2, 3, 4])

        register = self.server.requests_matching("POST", r"/api/sync/videos$")[0]
        body = json.loads(register.body)
        self.assertEqual(body, {"filename": "day 1.MP4", "folder_label": "Main", "relative_path": "Trip/day 1.MP4",
                                "size": 5000, "checksum_sha256": rec.sha256})
        put = self.server.requests_matching("PUT", r"/chunks/0$")[0]
        self.assertEqual(put.headers["Content-Type"], "application/octet-stream")
        self.assertEqual(put.headers["X-Chunk-Sha256"], hashlib.sha256(data[:1024]).hexdigest())
        self.assertEqual(put.headers["Authorization"], f"Bearer {self.server.token}")
        self.assertEqual(put.headers["Accept"], "application/json")
        self.assertTrue(self.server.requests_matching("POST", r"/complete$"))
        hb = self.server.heartbeats[-1]
        self.assertEqual(hb["folders"], [{"label": "Main", "files": 1}])
        self.assertEqual(hb["pending"], 0)
        self.assert_no_local_paths_sent()

    def test_resume_sends_only_missing_chunks(self):
        data = payload(4096 + 10)  # 5 chunks
        self.write("clip.mp4", data)
        self.server.preload(data, "clip.mp4", chunks=(0, 1, 3))
        uploader = self.make_uploader()
        self.assertEqual(uploader.process(self.stable_record("clip.mp4")), DONE)
        self.assertEqual(sorted(self.server.chunk_puts()), [2, 4])
        self.assertEqual(self.server.by_checksum[hashlib.sha256(data).hexdigest()].content, data)

    def test_interrupted_upload_resumes_after_restart(self):
        data = payload(6000)  # 6 chunks
        self.write("clip.mp4", data)
        uploader = self.make_uploader()
        stop = uploader.stop_event

        def stop_after_second(session, idx):
            if idx == 1:
                stop.set()

        self.server.on_chunk = stop_after_second
        self.assertEqual(uploader.process(self.stable_record("clip.mp4")), PENDING)
        rec = self.db.get("Main:clip.mp4")
        self.assertEqual(rec.status, PENDING)
        self.assertIsNotNone(rec.session_id)
        self.assertEqual(sorted(self.server.chunk_puts()), [0, 1])

        self.server.on_chunk = None
        self.server.requests.clear()
        uploader2 = self.make_uploader()  # fresh stop event, same DB
        self.assertEqual(uploader2.process(self.db.get("Main:clip.mp4")), DONE)
        self.assertEqual(sorted(self.server.chunk_puts()), [2, 3, 4, 5])
        self.assertEqual(self.server.by_checksum[rec.sha256].content, data)

    def test_upload_incomplete_on_complete_resends_missing(self):
        data = payload(3000)
        self.write("clip.mp4", data)
        uploader = self.make_uploader()
        dropped = {"done": False}

        def drop_chunk(session, idx):
            if idx == 2 and not dropped["done"]:
                dropped["done"] = True
                session.chunks.pop(1, None)  # server "lost" chunk 1

        self.server.on_chunk = drop_chunk
        self.assertEqual(uploader.process(self.stable_record("clip.mp4")), DONE)
        self.assertEqual(sorted(self.server.chunk_puts()), [0, 1, 1, 2])

    def test_duplicate_content_uploaded_once(self):
        data = payload(2500)
        self.write("a.mp4", data)
        self.write("copy/b.mp4", data)
        summary = self.make_agent().scan_once()
        self.assertEqual(summary.processed, {DONE: 2})
        self.assertEqual(len(self.server.requests_matching("POST", r"/api/sync/videos$")), 2)
        self.assertEqual(sorted(self.server.chunk_puts()), [0, 1, 2])
        video = self.server.by_checksum[hashlib.sha256(data).hexdigest()]
        self.assertEqual(sorted(video.sources), [("Main", "a.mp4"), ("Main", "copy/b.mp4")])

    def test_server_already_has_file(self):
        data = payload(2000)
        self.write("old.mp4", data)
        video = self.server.preload(data, uploaded=True)
        summary = self.make_agent().scan_once()
        self.assertEqual(summary.processed, {DONE: 1})
        self.assertEqual(self.server.chunk_puts(ok_only=False), [])
        rec = self.db.get("Main:old.mp4")
        self.assertEqual(rec.video_id, str(video.id))
        self.assertEqual(rec.server_status, "uploaded")

    def test_transient_errors_are_retried(self):
        self.write("clip.mp4", payload(3000))
        self.server.inject("POST", r"/api/sync/videos$", 429, {"error": "too_many"}, headers={"Retry-After": "0"})
        self.server.inject("PUT", r"/chunks/1$", 500, times=2)
        self.server.inject("POST", r"/complete$", 503)
        uploader = self.make_uploader()
        self.assertEqual(uploader.process(self.stable_record("clip.mp4")), DONE)
        self.assertEqual(sorted(self.server.chunk_puts()), [0, 1, 2])
        self.assertEqual(self.db.get("Main:clip.mp4").attempts, 0)

    def test_transient_errors_exhausted_schedules_retry_then_fails(self):
        self.write("clip.mp4", payload(1000))
        self.server.inject("POST", r"/api/sync/videos$", 500, times=100)
        uploader = self.make_uploader()
        rec = self.stable_record("clip.mp4")
        self.assertEqual(uploader.process(rec), PENDING)
        rec = self.db.get(rec.path_key)
        self.assertEqual(rec.attempts, 1)
        self.assertGreater(rec.next_retry_at, time.time() - 1)
        for _ in range(self.config.max_retries):
            status = uploader.process(self.db.get(rec.path_key))
        self.assertEqual(status, FAILED)
        self.assertEqual(self.db.get(rec.path_key).status, FAILED)

    def test_validation_error_rejected_not_retried(self):
        self.write("clip.mp4", payload(1000))
        self.server.inject("POST", r"/api/sync/videos$", 422,
                           {"error": "invalid_path", "message": "relative_path must be a relative path"})
        agent = self.make_agent()
        summary = agent.scan_once()
        self.assertEqual(summary.processed, {REJECTED: 1})
        rec = self.db.get("Main:clip.mp4")
        self.assertEqual(rec.status, REJECTED)
        self.assertIn("invalid_path", rec.last_error)
        self.assertEqual(len(self.server.requests_matching("POST", r"/api/sync/videos$")), 1)
        self.assertEqual(self.db.due(float("inf")), [])
        self.make_agent().scan_once()
        self.assertEqual(len(self.server.requests_matching("POST", r"/api/sync/videos$")), 1)

    def test_server_limits_skip_files(self):
        self.server.max_file_size = 1500
        self.server.allowed_extensions = ["mp4", "mov"]
        self.config = self.make_config(extensions=("mp4", "webm"))
        self.write("big.mp4", payload(2000))
        self.write("ok.mp4", payload(1000))
        self.write("x.webm", payload(100))
        summary = self.make_agent().scan_once()
        self.assertEqual(summary.processed, {DONE: 1, SKIPPED: 2})
        self.assertEqual(self.db.get("Main:big.mp4").status, SKIPPED)
        self.assertIn("larger", self.db.get("Main:big.mp4").last_error)
        self.assertEqual(self.db.get("Main:x.webm").status, SKIPPED)
        # Server raises its limit -> skipped files are re-evaluated.
        self.server.max_file_size = 10_000
        summary = self.make_agent().scan_once()
        self.assertEqual(summary.processed, {DONE: 1, SKIPPED: 1})
        self.assertEqual(self.db.get("Main:big.mp4").status, DONE)

    def test_chunk_checksum_mismatch_retries_chunk(self):
        self.write("clip.mp4", payload(2048))
        self.server.inject("PUT", r"/chunks/1$", 422, {"error": "chunk_checksum_mismatch", "message": "bad"})
        uploader = self.make_uploader()
        self.assertEqual(uploader.process(self.stable_record("clip.mp4")), DONE)
        self.assertEqual(sorted(self.server.chunk_puts()), [0, 1])

    def test_unauthorized(self):
        self.write("clip.mp4", payload(1000))
        uploader = self.make_uploader()
        bad_api = ApiClient(self.server.url, "rfd_wrong_token_123", timeout=5)
        uploader.api = bad_api
        rec = self.stable_record("clip.mp4")
        with self.assertRaises(AuthError):
            uploader.process(rec)
        rec = self.db.get(rec.path_key)
        self.assertEqual(rec.status, PENDING)
        self.assertEqual(rec.attempts, 0)  # auth problems do not burn retries

        self.db.close()
        from reelflow_agent.state import StateDB
        self.db = StateDB(self.config.db_path)
        summary = self.make_agent(api=bad_api).scan_once()
        self.assertEqual(summary.exit_code, EXIT_AUTH)
        self.assertEqual(self.server.chunk_puts(ok_only=False), [])

    def test_device_revoked(self):
        self.server.revoked = True
        with self.assertRaises(DeviceRevokedError):
            self.api.heartbeat({})
        self.assertEqual(self.make_agent().scan_once().exit_code, EXIT_REVOKED)

    def test_file_modified_mid_upload_aborts_and_requeues(self):
        path = self.write("clip.mp4", payload(5000))
        agent = self.make_agent()
        agent.heartbeat()

        def modify(session, idx):
            if idx == 1:
                with open(path, "ab") as fh:
                    fh.write(b"appended while uploading")

        self.server.on_chunk = modify
        rec = self.stable_record("clip.mp4")
        self.assertEqual(agent.uploader.process(rec), REQUEUED)
        self.assertIsNone(self.db.get(rec.path_key))
        sessions = list(self.server.sessions.values())
        self.assertEqual(len(sessions), 1)
        self.assertEqual(sessions[0].status, "failed")  # aborted
        self.assertTrue(self.server.requests_matching("POST", r"/abort$"))
        self.assertEqual(agent.drain_events(), 1)  # requeued for a stability check

        self.server.on_chunk = None
        summary = agent.scan_once()
        self.assertEqual(summary.processed, {DONE: 1})
        new = self.db.get(rec.path_key)
        self.assertEqual(self.server.videos[int(new.video_id)].content, path.read_bytes())

    def test_file_changed_since_hash_is_requeued(self):
        path = self.write("clip.mp4", payload(1000))
        rec = self.stable_record("clip.mp4")
        path.write_bytes(payload(1200, seed=9))
        uploader = self.make_uploader()
        self.assertEqual(uploader.process(rec), REQUEUED)
        self.assertEqual(self.server.requests_matching("POST", r"/api/sync/videos$"), [])

    def test_server_checksum_failure_reregisters(self):
        data = payload(2000)
        self.write("clip.mp4", data)
        self.server.corrupt_assembly = True
        uploader = self.make_uploader()
        rec = self.stable_record("clip.mp4")
        self.assertEqual(uploader.process(rec), PENDING)
        after = self.db.get(rec.path_key)
        self.assertEqual(after.attempts, 1)
        self.assertIsNone(after.sha256)
        self.assertIn("checksum", after.last_error)
        self.server.corrupt_assembly = False
        self.assertEqual(uploader.process(after), DONE)
        self.assertEqual(self.server.by_checksum[hashlib.sha256(data).hexdigest()].content, data)

    def test_server_says_not_a_video_is_rejected(self):
        self.write("fake.mp4", payload(1500))
        uploader = self.make_uploader()
        rec = self.stable_record("fake.mp4")
        original = self.server._complete

        def complete_as_not_video(*args):
            status, body = original(*args)
            video = self.server.videos[body["upload"]["video_id"]]
            video.status, video.uploaded = "failed", False
            video.processing_error = "The uploaded file is not a video (text/plain)."
            return status, body

        self.server._complete = complete_as_not_video
        self.assertEqual(uploader.process(rec), REJECTED)
        self.assertIn("not a video", self.db.get(rec.path_key).last_error)

    def test_deleted_file_is_forgotten(self):
        path = self.write("clip.mp4", payload(1000))
        rec = self.stable_record("clip.mp4")
        os.remove(path)
        self.assertEqual(self.make_uploader().process(rec), "removed")
        self.assertIsNone(self.db.get(rec.path_key))

    def test_run_loop_uploads_and_stops_gracefully(self):
        self.config = self.make_config(scan_interval_seconds=1, heartbeat_interval_seconds=1)
        agent = self.make_agent()
        self.write("live.mp4", payload(3000))
        exit_codes = []
        t = threading.Thread(target=lambda: exit_codes.append(agent.run()), daemon=True)
        t.start()
        deadline = time.time() + 15
        while time.time() < deadline:
            rec = self.db.get("Main:live.mp4")
            if rec and rec.status == DONE:
                break
            time.sleep(0.05)
        agent.stop_event.set()
        agent.wake.set()
        t.join(10)
        self.assertFalse(t.is_alive())
        self.assertEqual(exit_codes, [0])
        self.assertEqual(self.db.get("Main:live.mp4").status, DONE)
        self.assertGreaterEqual(len(self.server.heartbeats), 1)

    def test_run_loop_exits_when_revoked(self):
        self.server.revoked = True
        agent = self.make_agent()
        self.assertEqual(agent.run(), EXIT_REVOKED)


class RetryHelperTests(unittest.TestCase):
    def test_retry_after_parsing(self):
        self.assertEqual(parse_retry_after("5"), 5.0)
        self.assertIsNone(parse_retry_after("soon"))
        self.assertAlmostEqual(parse_retry_after("Thu, 01 Jan 1970 00:01:40 GMT", now=40.0), 60.0)

    def test_honours_retry_after(self):
        calls = []
        sleeps = []
        stop = threading.Event()
        original_wait = stop.wait
        stop.wait = lambda d: sleeps.append(d) or False  # type: ignore[method-assign]

        def fn():
            calls.append(1)
            if len(calls) < 3:
                raise TransientError(429, "rate_limited", "slow down", retry_after=7)
            return "ok"

        self.assertEqual(call_with_retries(fn, max_retries=5, base_delay=0.01, max_delay=0.1, stop_event=stop), "ok")
        self.assertEqual(sleeps, [7, 7])
        stop.wait = original_wait  # type: ignore[method-assign]


if __name__ == "__main__":
    unittest.main()
