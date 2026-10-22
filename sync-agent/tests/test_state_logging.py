import io
import logging
import os
import unittest
from unittest import mock

from reelflow_agent import uploader as uploader_mod
from reelflow_agent.logging_setup import RedactingFormatter, redact, setup_logging
from reelflow_agent.state import DONE, FAILED, PENDING, REJECTED, UPLOADING, InstanceLock, InstanceLockError, StateDB

from helpers import ServerTestCase, TempDirTestCase, payload


class StateTests(TempDirTestCase):
    def test_persistence_and_unchanged_files_keep_hash(self):
        db_path = self.root / "s" / "state.sqlite3"
        with StateDB(db_path) as db:
            rec, changed = db.record_stable("Main", "a.mp4", 10, 111)
            self.assertTrue(changed)
            self.assertEqual(rec.status, PENDING)
            db.update(rec.path_key, sha256="ab" * 32, status=DONE, video_id="5")
        with StateDB(db_path) as db:  # reopen = restart
            rec, changed = db.record_stable("Main", "a.mp4", 10, 111)
            self.assertFalse(changed)
            self.assertEqual(rec.sha256, "ab" * 32)
            self.assertEqual(rec.status, DONE)
            self.assertTrue(db.matches("Main:a.mp4", 10, 111))
            # changed file -> reset
            rec, changed = db.record_stable("Main", "a.mp4", 11, 222)
            self.assertTrue(changed)
            self.assertIsNone(rec.sha256)
            self.assertEqual(rec.status, PENDING)

    def test_changed_file_remembers_open_session(self):
        with StateDB(self.root / "s.db") as db:
            rec, _ = db.record_stable("Main", "a.mp4", 10, 1)
            db.update(rec.path_key, status=UPLOADING, session_id="sess-1", sha256="cd" * 32)
            rec, _ = db.record_stable("Main", "a.mp4", 20, 2)
            self.assertEqual(rec.stale_session_id, "sess-1")
            self.assertIsNone(rec.session_id)

    def test_reset_interrupted_retry_and_counts(self):
        with StateDB(self.root / "s.db") as db:
            for i, status in enumerate((UPLOADING, FAILED, REJECTED, DONE)):
                rec, _ = db.record_stable("Main", f"{i}.mp4", 1, 1)
                db.update(rec.path_key, status=status, session_id="s" if status == UPLOADING else None,
                          last_error="boom" if status in (FAILED, REJECTED) else None)
            self.assertEqual(db.reset_interrupted(), 1)
            self.assertEqual(db.get("Main:0.mp4").status, PENDING)
            self.assertEqual(db.get("Main:0.mp4").session_id, "s")  # resume info kept
            counts = db.counts()
            self.assertEqual((counts[PENDING], counts[FAILED], counts[REJECTED], counts[DONE]), (1, 1, 1, 1))
            self.assertEqual(db.retry_failed(), 1)
            self.assertEqual(db.retry_failed(include_rejected=True), 1)
            self.assertEqual(db.counts()[PENDING], 3)
            self.assertEqual(db.prune_missing("Main", {"Main:3.mp4"}).__len__(), 3)
            self.assertEqual(db.counts_by_label(), {"Main": 1})

    def test_due_respects_retry_time(self):
        with StateDB(self.root / "s.db", clock=lambda: 100.0) as db:
            a, _ = db.record_stable("Main", "a.mp4", 1, 1)
            b, _ = db.record_stable("Main", "b.mp4", 1, 1)
            db.update(b.path_key, next_retry_at=500)
            self.assertEqual([r.path_key for r in db.due(100)], ["Main:a.mp4"])
            self.assertEqual(len(db.due(600)), 2)
            self.assertEqual(db.due(600, exclude={"Main:a.mp4"})[0].path_key, "Main:b.mp4")

    def test_instance_lock(self):
        with InstanceLock(self.root / "st"):
            with self.assertRaises(InstanceLockError):
                InstanceLock(self.root / "st").acquire()
        InstanceLock(self.root / "st").acquire().release()


class NoRehashTests(ServerTestCase):
    def test_unchanged_files_not_rehashed_across_restarts(self):
        self.write("a.mp4", payload(3000))
        with mock.patch.object(uploader_mod, "sha256_file", wraps=uploader_mod.sha256_file) as spy:
            summary = self.make_agent().scan_once()
            self.assertEqual(summary.processed, {DONE: 1})
            self.assertEqual(spy.call_count, 1)
            # "Restart": new DB connection + new agent.
            self.db.close()
            self.db = StateDB(self.config.db_path)
            summary = self.make_agent().scan_once()
            self.assertEqual(summary.processed, {})
            self.assertEqual(spy.call_count, 1)
            # Hash computed but upload interrupted -> resume without re-hashing.
            self.write("b.mp4", payload(3000, seed=2))
            agent = self.make_agent()
            agent.heartbeat()
            agent.collect_stable()
            rec = self.db.get("Main:b.mp4")
            self.db.update(rec.path_key, sha256=__import__("hashlib").sha256(payload(3000, seed=2)).hexdigest(),
                           status=UPLOADING)
            self.db.close()
            self.db = StateDB(self.config.db_path)
            self.assertEqual(self.make_agent().scan_once().processed, {DONE: 1})
            self.assertEqual(spy.call_count, 1)


class RedactionTests(TempDirTestCase):
    def test_redact_function(self):
        self.assertEqual(redact("token=rfd_AbC123-_xyz end"), "token=rfd_*** end")
        self.assertEqual(redact("Authorization: Bearer abc.def"), "Authorization: Bearer ***")

    def test_logger_never_writes_token(self):
        token = "rfd_SuperSecretToken123"
        stream = io.StringIO()
        handler = logging.StreamHandler(stream)
        handler.setFormatter(RedactingFormatter("%(message)s"))
        logger = logging.getLogger("reelflow_agent.test_redact")
        logger.addHandler(handler)
        logger.setLevel(logging.DEBUG)
        try:
            logger.info("using token %s", token)
            logger.warning("header was %r", {"Authorization": f"Bearer {token}"})
            try:
                raise RuntimeError(f"failed with {token}")
            except RuntimeError:
                logger.exception("boom")
        finally:
            logger.removeHandler(handler)
        out = stream.getvalue()
        self.assertNotIn("SuperSecretToken123", out)
        self.assertIn("rfd_***", out)

    def test_rotating_file_handler_redacts(self):
        log_dir = self.root / "logs"
        logger = setup_logging("DEBUG", log_dir, console=False)
        try:
            logging.getLogger("reelflow_agent.x").error("token rfd_FileSecret999 leaked?")
            for h in logger.handlers:
                h.flush()
            text = (log_dir / "agent.log").read_text()
            self.assertNotIn("rfd_FileSecret999", text)
            self.assertIn("rfd_***", text)
            if os.name == "posix":
                self.assertEqual(os.stat(log_dir).st_mode & 0o077, 0)
        finally:
            for h in list(logger.handlers):
                logger.removeHandler(h)
                h.close()
            logger.addHandler(logging.NullHandler())


if __name__ == "__main__":
    unittest.main()
