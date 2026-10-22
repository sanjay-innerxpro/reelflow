import contextlib
import io
import os
import textwrap
from unittest import mock

from reelflow_agent import cli
from reelflow_agent import config as config_mod
from reelflow_agent.state import StateDB

from fake_server import TOKEN
from helpers import ServerTestCase, payload


class CliTests(ServerTestCase):
    def setUp(self):
        super().setUp()
        self.cfg_path = self.root / "config.toml"
        self.cfg_path.write_text(textwrap.dedent(f"""
            stability_seconds = 0
            state_dir = "{(self.root / 'state').as_posix()}"
            completion_poll_attempts = 1
            completion_poll_interval_seconds = 0
            retry_backoff_base_seconds = 0.001
            use_watchdog = false
            [[folders]]
            label = "Main"
            path = "{self.watch.as_posix()}"
        """))
        self.env = {"REELFLOW_SERVER_URL": self.server.url, "REELFLOW_DEVICE_TOKEN": TOKEN}
        self._patches = [
            mock.patch.dict(os.environ, self.env),
            mock.patch.object(config_mod, "AGENT_DIR", self.root / "agent"),
        ]
        for p in self._patches:
            p.start()

    def tearDown(self):
        for p in self._patches:
            p.stop()
        import logging
        logger = logging.getLogger("reelflow_agent")
        for h in list(logger.handlers):
            logger.removeHandler(h)
            h.close()
        logger.addHandler(logging.NullHandler())
        super().tearDown()

    def run_cli(self, *argv):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = cli.main(["--config", str(self.cfg_path), *argv])
        return code, out.getvalue(), err.getvalue()

    def test_check_ok(self):
        code, out, err = self.run_cli("check")
        self.assertEqual(code, 0, out + err)
        self.assertIn("Connection:    OK", out)
        self.assertIn("Test PC", out)
        self.assertNotIn(TOKEN, out + err)

    def test_check_bad_token(self):
        with mock.patch.dict(os.environ, {"REELFLOW_DEVICE_TOKEN": "rfd_not_the_right_one"}):
            code, out, err = self.run_cli("check")
        self.assertEqual(code, 4)
        self.assertIn("token rejected", out)
        self.assertNotIn("rfd_not_the_right_one", out + err)

    def test_check_server_down(self):
        self.server.stop()
        with mock.patch.dict(os.environ, {"REELFLOW_SERVER_URL": "http://127.0.0.1:9"}):
            code, out, err = self.run_cli("check")
        self.assertEqual(code, 1)
        self.assertIn("FAILED", out)

    def test_config_error_exit_code(self):
        with mock.patch.dict(os.environ, {"REELFLOW_DEVICE_TOKEN": ""}):
            code, out, err = self.run_cli("check")
        self.assertEqual(code, 2)
        self.assertIn("REELFLOW_DEVICE_TOKEN", err)

    def test_scan_status_retry(self):
        self.write("a.mp4", payload(2000))
        self.write("b.mkv", payload(10))
        code, out, _ = self.run_cli("scan", "--dry-run")
        self.assertEqual(code, 0)
        self.assertIn("a.mp4", out)
        self.assertIn("1 would be uploaded", out)
        self.assertEqual(self.server.requests, [])

        code, out, err = self.run_cli("scan")
        self.assertEqual(code, 0, out + err)
        self.assertIn("done: 1", out)
        self.assertTrue((self.root / "state" / "logs" / "agent.log").exists())

        with StateDB(self.root / "state" / "state.sqlite3") as db:
            db.update("Main:a.mp4", status="failed", last_error="simulated")
        code, out, _ = self.run_cli("status")
        self.assertEqual(code, 0)
        self.assertIn("failed", out)
        self.assertIn("simulated", out)
        code, out, _ = self.run_cli("retry-failed")
        self.assertIn("1 file(s) reset", out)

    def test_help(self):
        out = io.StringIO()
        with contextlib.redirect_stdout(out), self.assertRaises(SystemExit) as ctx:
            cli.main(["--help"])
        self.assertEqual(ctx.exception.code, 0)
        for cmd in ("run", "scan", "status", "retry-failed", "check"):
            self.assertIn(cmd, out.getvalue())
