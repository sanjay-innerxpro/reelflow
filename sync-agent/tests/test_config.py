import os
import textwrap
import unittest
from pathlib import Path
from unittest import mock

from reelflow_agent import config as config_mod
from reelflow_agent.config import ConfigError, load_config, normalize_server_url
from reelflow_agent.envfile import EnvFileError, insecure_permissions, parse_env

from helpers import TempDirTestCase

TOKEN = "rfd_abcdefghijklmnop"


class EnvFileTests(unittest.TestCase):
    def test_basic_parsing(self):
        env = parse_env(textwrap.dedent("""\
            # comment
            A=1
            export B = two words
            C="quoted # not a comment\\nnext"   # trailing
            D='single $literal'
            E=value # comment
            EMPTY=
            F=x=y
        """))
        self.assertEqual(env["A"], "1")
        self.assertEqual(env["B"], "two words")
        self.assertEqual(env["C"], "quoted # not a comment\nnext")
        self.assertEqual(env["D"], "single $literal")
        self.assertEqual(env["E"], "value")
        self.assertEqual(env["EMPTY"], "")
        self.assertEqual(env["F"], "x=y")

    def test_bom_and_crlf(self):
        env = parse_env("﻿REELFLOW_DEVICE_TOKEN=rfd_x123\r\nX=1\r\n")
        self.assertEqual(env, {"REELFLOW_DEVICE_TOKEN": "rfd_x123", "X": "1"})

    def test_errors(self):
        for bad in ("NOEQUALS", "1BAD=x", 'A="unterminated', "A='x' junk"):
            with self.subTest(bad=bad), self.assertRaises(EnvFileError):
                parse_env(bad)


class ConfigTests(TempDirTestCase):
    def write_config(self, body: str, env_text: str | None = None) -> Path:
        cfg_dir = self.root / "cfg"
        cfg_dir.mkdir(exist_ok=True)
        path = cfg_dir / "config.toml"
        path.write_text(textwrap.dedent(body))
        if env_text is not None:
            (cfg_dir / ".env").write_text(env_text)
        return path

    def load(self, path, environ=None, **kw):
        # Isolate from a real .env next to the agent.
        with mock.patch.object(config_mod, "AGENT_DIR", self.root / "no-agent-dir"):
            return load_config(path, environ=environ or {}, **kw)

    def test_full_config_with_env_file(self):
        path = self.write_config(f"""
            extensions = [".MP4", "mov"]
            stability_seconds = 5
            max_concurrent_uploads = 2
            state_dir = "state"
            log_level = "debug"
            [[folders]]
            label = "Phone Videos"
            path = "{self.watch.as_posix()}"
            [[folders]]
            label = "Other"
            path = "rel/dir"
        """, env_text=f"REELFLOW_SERVER_URL=https://reelflow.example.com/\nREELFLOW_DEVICE_TOKEN={TOKEN}\n")
        os.chmod(path.parent / ".env", 0o600)
        cfg = self.load(path)
        self.assertEqual(cfg.server_url, "https://reelflow.example.com")
        self.assertEqual(cfg.device_token, TOKEN)
        self.assertEqual(cfg.extensions, ("mp4", "mov"))
        self.assertEqual(cfg.stability_seconds, 5.0)
        self.assertEqual(cfg.max_concurrent_uploads, 2)
        self.assertEqual(cfg.log_level, "DEBUG")
        self.assertEqual(cfg.state_dir, path.parent / "state")
        self.assertEqual(cfg.folders[0].label, "Phone Videos")
        self.assertEqual(cfg.folders[1].path, path.parent / "rel" / "dir")
        self.assertNotIn(TOKEN, repr(cfg))
        self.assertEqual(cfg.warnings, [])

    def test_defaults(self):
        path = self.write_config(f'[[folders]]\nlabel = "A"\npath = "{self.watch.as_posix()}"\n')
        cfg = self.load(path, {"REELFLOW_SERVER_URL": "https://x.test", "REELFLOW_DEVICE_TOKEN": TOKEN})
        self.assertEqual(cfg.extensions, ("mp4", "mov", "webm", "m4v"))
        self.assertIn("*.part", cfg.ignore_patterns)
        self.assertEqual(cfg.stability_seconds, 10)
        self.assertEqual(cfg.scan_interval_seconds, 60)
        self.assertEqual(cfg.heartbeat_interval_seconds, 60)
        self.assertEqual(cfg.max_concurrent_uploads, 1)
        self.assertEqual(cfg.max_retries, 8)
        self.assertEqual(cfg.request_timeout_seconds, 120)
        self.assertTrue(cfg.verify_tls)
        self.assertTrue(str(cfg.state_dir).endswith(("reelflow-agent", "ReelFlowAgent")))

    def test_ffmpeg_options(self):
        env = {"REELFLOW_SERVER_URL": "https://x.test", "REELFLOW_DEVICE_TOKEN": TOKEN}
        folder = f'[[folders]]\nlabel = "A"\npath = "{self.watch.as_posix()}"\n'
        cfg = self.load(self.write_config(folder), env)
        self.assertIsNone(cfg.ffmpeg_path)
        self.assertIsNone(cfg.ffprobe_path)
        self.assertEqual(cfg.ffmpeg_timeout_seconds, 300)
        path = self.write_config('ffmpeg_path = "tools/ffmpeg"\nffprobe_path = "ffprobe"\n'
                                 'ffmpeg_timeout_seconds = 60\n' + folder)
        cfg = self.load(path, env)
        self.assertEqual(cfg.ffmpeg_path, str(path.parent / "tools" / "ffmpeg"))  # relative to config.toml
        self.assertEqual(cfg.ffprobe_path, "ffprobe")  # bare name: looked up on PATH
        self.assertEqual(cfg.ffmpeg_timeout_seconds, 60)
        for bad in ("ffmpeg_path = 5\n", "ffmpeg_timeout_seconds = 0\n"):
            with self.subTest(bad), self.assertRaises(ConfigError):
                self.load(self.write_config(bad + folder), env)

    def test_environment_overrides_env_file(self):
        path = self.write_config(f'[[folders]]\nlabel = "A"\npath = "{self.watch.as_posix()}"\n',
                                 env_text="REELFLOW_SERVER_URL=https://file.test\nREELFLOW_DEVICE_TOKEN=rfd_fromfile123\n")
        cfg = self.load(path, {"REELFLOW_DEVICE_TOKEN": TOKEN})
        self.assertEqual(cfg.server_url, "https://file.test")
        self.assertEqual(cfg.device_token, TOKEN)

    @unittest.skipUnless(os.name == "posix", "POSIX permissions")
    def test_insecure_env_file_warns(self):
        path = self.write_config(f'[[folders]]\nlabel = "A"\npath = "{self.watch.as_posix()}"\n',
                                 env_text=f"REELFLOW_SERVER_URL=https://x.test\nREELFLOW_DEVICE_TOKEN={TOKEN}\n")
        os.chmod(path.parent / ".env", 0o644)
        self.assertTrue(insecure_permissions(path.parent / ".env"))
        cfg = self.load(path)
        self.assertTrue(any("chmod 600" in w for w in cfg.warnings))

    def test_validation_errors(self):
        good_folder = f'[[folders]]\nlabel = "A"\npath = "{self.watch.as_posix()}"\n'
        env = {"REELFLOW_SERVER_URL": "https://x.test", "REELFLOW_DEVICE_TOKEN": TOKEN}
        cases = {
            "no folders": ("", env),
            "unknown key": ("stabilty_seconds = 3\n" + good_folder, env),
            "bad number": ("max_retries = -1\n" + good_folder, env),
            "bad type": ('stability_seconds = "10"\n' + good_folder, env),
            "dup label": (good_folder + f'[[folders]]\nlabel = "a"\npath = "{(self.root / "o").as_posix()}"\n', env),
            "nested": (good_folder + f'[[folders]]\nlabel = "B"\npath = "{(self.watch / "sub").as_posix()}"\n', env),
            "bad label": ('[[folders]]\nlabel = "a:b"\npath = "/x"\n', env),
            "bad ext": ('extensions = ["m p4"]\n' + good_folder, env),
            "bad toml": ("this is = = not toml", env),
            "no token": (good_folder, {"REELFLOW_SERVER_URL": "https://x.test"}),
            "bad token": (good_folder, {"REELFLOW_SERVER_URL": "https://x.test", "REELFLOW_DEVICE_TOKEN": "abc123456789"}),
            "http remote": (good_folder, {"REELFLOW_SERVER_URL": "http://x.test", "REELFLOW_DEVICE_TOKEN": TOKEN}),
        }
        for name, (body, environ) in cases.items():
            with self.subTest(name):
                path = self.write_config(body)
                with self.assertRaises(ConfigError):
                    self.load(path, environ)

    def test_missing_file(self):
        with self.assertRaises(ConfigError) as ctx:
            self.load(self.root / "nope.toml")
        self.assertIn("config.example.toml", str(ctx.exception))

    def test_secrets_optional_for_local_commands(self):
        path = self.write_config("")
        cfg = self.load(path, require_secrets=False, require_folders=False)
        self.assertEqual(cfg.folders, [])

    def test_normalize_server_url(self):
        self.assertEqual(normalize_server_url("https://a.test/api/sync/"), "https://a.test")
        self.assertEqual(normalize_server_url("https://a.test/reelflow"), "https://a.test/reelflow")
        self.assertEqual(normalize_server_url("http://127.0.0.1:8000"), "http://127.0.0.1:8000")
        self.assertEqual(normalize_server_url("http://localhost"), "http://localhost")
        for bad in ("ftp://a.test", "a.test", "https://user:pw@a.test", "https://a.test/?x=1", "http://10.0.0.1"):
            with self.subTest(bad), self.assertRaises(ConfigError):
                normalize_server_url(bad)


if __name__ == "__main__":
    unittest.main()
