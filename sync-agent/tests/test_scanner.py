import hashlib
import os
import threading
import unittest

from reelflow_agent.config import DEFAULT_EXTENSIONS, DEFAULT_IGNORE_PATTERNS, FolderConfig
from reelflow_agent.hasher import HashCancelled, sha256_file
from reelflow_agent.scanner import (
    FileFilter,
    FolderUnavailable,
    PathOutsideRoot,
    relative_posix_path,
    resolve_candidate_path,
    scan_folder,
    validate_relative_path,
)
from reelflow_agent.stability import StabilityTracker, is_readable

from helpers import TempDirTestCase, payload


class FilterTests(unittest.TestCase):
    def setUp(self):
        self.f = FileFilter(DEFAULT_EXTENSIONS, DEFAULT_IGNORE_PATTERNS)

    def test_extensions_case_insensitive(self):
        for name in ("a.mp4", "B.MOV", "c.WebM", "d.m4v", "dir/e.Mp4"):
            with self.subTest(name):
                self.assertTrue(self.f.accepts(name))
        for name in ("a.mkv", "noext", "mp4", "a.mp4.txt"):
            with self.subTest(name):
                self.assertFalse(self.f.accepts(name))

    def test_ignore_patterns(self):
        for name in (".hidden.mp4", "a.mp4.part", "a.part", "x.crdownload", "y.tmp", "~$lock.mp4",
                     ".git/a.mp4", "sub/.cache/b.mp4"):
            with self.subTest(name):
                self.assertFalse(self.f.accepts(name))

    def test_custom_path_pattern(self):
        f = FileFilter(["mp4"], ["drafts/*", "*.TMP"])
        self.assertFalse(f.accepts("drafts/a.mp4"))
        self.assertTrue(f.accepts("final/a.mp4"))
        self.assertFalse(f.accepts("b.tmp"))


class PathTests(TempDirTestCase):
    def test_relative_posix_path(self):
        p = self.watch / "a" / "b" / "clip.mp4"
        self.assertEqual(relative_posix_path(self.watch, p), "a/b/clip.mp4")
        rel = relative_posix_path(str(self.watch) + os.sep, str(p))
        self.assertFalse(rel.startswith("/"))
        self.assertNotIn("\\", rel)
        self.assertNotIn(str(self.root), rel)

    def test_outside_root_rejected(self):
        with self.assertRaises(PathOutsideRoot):
            relative_posix_path(self.watch, self.root / "other.mp4")
        with self.assertRaises(PathOutsideRoot):
            relative_posix_path(self.watch, self.watch)
        for bad in ("/etc/passwd", "../x.mp4", "a/../../x.mp4", "C:/x.mp4", "", "a//b.mp4"):
            with self.subTest(bad), self.assertRaises(PathOutsideRoot):
                validate_relative_path(bad)
        with self.assertRaises(PathOutsideRoot):
            resolve_candidate_path(self.watch, "../secret.mp4")

    @unittest.skipUnless(hasattr(os, "symlink"), "symlinks unsupported")
    def test_symlink_escape_blocked(self):
        outside = self.root / "outside"
        outside.mkdir()
        self.write("secret.mp4", b"secret", base=outside)
        self.write("inside.mp4", b"inside")
        try:
            os.symlink(outside / "secret.mp4", self.watch / "link.mp4")
            os.symlink(outside, self.watch / "linkdir", target_is_directory=True)
            os.symlink(self.watch / "inside.mp4", self.watch / "internal.mp4")
        except OSError as e:  # e.g. Windows without developer mode
            self.skipTest(f"cannot create symlinks: {e}")
        folder = FolderConfig("Main", self.watch)
        found = sorted(c.relative_path for c in scan_folder(folder, FileFilter(["mp4"], [])))
        self.assertEqual(found, ["inside.mp4", "internal.mp4"])
        with self.assertRaises(PathOutsideRoot):
            resolve_candidate_path(self.watch, "link.mp4")
        with self.assertRaises(PathOutsideRoot):
            resolve_candidate_path(self.watch, "linkdir/secret.mp4")

    def test_scan_folder(self):
        self.write("a.mp4", b"1")
        self.write("Sub Dir/b.MOV", b"22")
        self.write("Sub Dir/c.txt", b"3")
        self.write("d.mp4.part", b"4")
        self.write(".hidden/e.mp4", b"5")
        folder = FolderConfig("Main", self.watch)
        cands = {c.relative_path: c for c in scan_folder(folder, FileFilter(DEFAULT_EXTENSIONS, DEFAULT_IGNORE_PATTERNS))}
        self.assertEqual(set(cands), {"a.mp4", "Sub Dir/b.MOV"})
        self.assertEqual(cands["Sub Dir/b.MOV"].size, 2)
        self.assertEqual(cands["Sub Dir/b.MOV"].path_key, "Main:Sub Dir/b.MOV")

    def test_missing_folder(self):
        with self.assertRaises(FolderUnavailable):
            list(scan_folder(FolderConfig("X", self.root / "missing"), FileFilter(["mp4"], [])))


class FakeClock:
    def __init__(self):
        self.now = 1000.0

    def __call__(self):
        return self.now

    def advance(self, s):
        self.now += s


class StabilityTests(TempDirTestCase):
    def test_growing_file_is_not_stable(self):
        clock = FakeClock()
        t = StabilityTracker(10, clock)
        path = self.write("grow.mp4", b"a")
        for i in range(5):
            with open(path, "ab") as fh:
                fh.write(b"more")
            st = os.stat(path)
            self.assertFalse(t.observe("k", st.st_size, st.st_mtime_ns))
            clock.advance(6)

    def test_stable_after_unchanged_window(self):
        clock = FakeClock()
        t = StabilityTracker(10, clock)
        self.assertFalse(t.observe("k", 100, 1))
        clock.advance(9.9)
        self.assertFalse(t.observe("k", 100, 1))
        clock.advance(0.1)
        self.assertTrue(t.observe("k", 100, 1))
        # A change restarts the window.
        self.assertFalse(t.observe("k", 101, 2))
        clock.advance(10)
        self.assertTrue(t.observe("k", 101, 2))
        t.forget("k")
        self.assertNotIn("k", t)

    def test_zero_window(self):
        self.assertTrue(StabilityTracker(0, FakeClock()).observe("k", 1, 1))

    def test_is_readable(self):
        self.assertTrue(is_readable(str(self.write("r.mp4", b"x"))))
        self.assertFalse(is_readable(str(self.watch / "missing.mp4")))


class HasherTests(TempDirTestCase):
    def test_streamed_hash_matches(self):
        data = payload(5 * 1024 * 1024 + 123)
        path = self.write("big.mp4", data)
        self.assertEqual(sha256_file(str(path), block_size=64 * 1024), hashlib.sha256(data).hexdigest())

    def test_cancel(self):
        path = self.write("big.mp4", payload(1024 * 1024))
        stop = threading.Event()
        stop.set()
        with self.assertRaises(HashCancelled):
            sha256_file(str(path), block_size=1024, should_stop=stop.is_set)


if __name__ == "__main__":
    unittest.main()
