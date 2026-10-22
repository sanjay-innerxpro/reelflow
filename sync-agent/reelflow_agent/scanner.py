"""Folder scanning, filtering and safe relative-path computation.

Only ``(folder_label, relative_path)`` pairs ever leave this machine; absolute
paths are used locally for I/O only. Symlinks (and Windows junctions) that
resolve outside the watched root are never followed.
"""

from __future__ import annotations

import fnmatch
import logging
import os
import re
import stat
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from typing import Callable, Iterable, Iterator

from .config import FolderConfig

log = logging.getLogger("reelflow_agent.scanner")

_DRIVE_RE = re.compile(r"^[A-Za-z]:")
_WIN_HIDDEN = 0x2 | 0x4  # FILE_ATTRIBUTE_HIDDEN | FILE_ATTRIBUTE_SYSTEM


class PathOutsideRoot(ValueError):
    """A path resolves outside its watched folder (or is otherwise unsafe)."""


class FolderUnavailable(OSError):
    """A watched folder does not exist or is not a directory (e.g. unmounted drive)."""


def make_path_key(label: str, relative_path: str) -> str:
    return f"{label}:{relative_path}"


@dataclass(frozen=True)
class Candidate:
    label: str
    relative_path: str
    abs_path: str
    size: int
    mtime_ns: int

    @property
    def path_key(self) -> str:
        return make_path_key(self.label, self.relative_path)

    @property
    def filename(self) -> str:
        return PurePosixPath(self.relative_path).name


class FileFilter:
    """Decides which files are candidate videos (extension + ignore globs)."""

    def __init__(self, extensions: Iterable[str], ignore_patterns: Iterable[str]):
        self.extensions = frozenset(e.lower().lstrip(".") for e in extensions)
        patterns = [p.strip() for p in ignore_patterns if p and p.strip()]
        # Patterns with a "/" match the whole relative path, others match any component.
        self._name_patterns = [p.lower() for p in patterns if "/" not in p]
        self._path_patterns = [p.lower().strip("/") for p in patterns if "/" in p]

    def is_ignored_name(self, name: str) -> bool:
        lowered = name.lower()
        return any(fnmatch.fnmatchcase(lowered, p) for p in self._name_patterns)

    def has_allowed_extension(self, name: str) -> bool:
        _, dot, ext = name.rpartition(".")
        return bool(dot) and ext.lower() in self.extensions

    def accepts(self, relative_path: str) -> bool:
        """``relative_path`` is POSIX-style relative to the watched folder."""
        parts = relative_path.split("/")
        if any(self.is_ignored_name(p) for p in parts):
            return False
        lowered = relative_path.lower()
        if any(fnmatch.fnmatchcase(lowered, p) for p in self._path_patterns):
            return False
        return self.has_allowed_extension(parts[-1])


def validate_relative_path(rel: str) -> str:
    """Ensure ``rel`` is a clean POSIX relative path; return it unchanged."""
    if not rel or "\x00" in rel or rel.startswith("/") or _DRIVE_RE.match(rel) or "\\" in rel and os.name == "nt":
        raise PathOutsideRoot("unsafe relative path")
    parts = rel.split("/")
    if any(p in ("", ".", "..") for p in parts):
        raise PathOutsideRoot("unsafe relative path")
    if PurePosixPath(rel).is_absolute():
        raise PathOutsideRoot("unsafe relative path")
    return rel


def relative_posix_path(root: str | os.PathLike[str], path: str | os.PathLike[str]) -> str:
    """Path of ``path`` relative to ``root`` with POSIX separators. Lexical check only."""
    root_abs = os.path.abspath(root)
    path_abs = os.path.abspath(path)
    try:
        rel = os.path.relpath(path_abs, root_abs)
    except ValueError:  # different drive on Windows
        raise PathOutsideRoot("path is on a different drive") from None
    if rel == os.curdir or rel == os.pardir or rel.startswith(os.pardir + os.sep) or os.path.isabs(rel):
        raise PathOutsideRoot("path is outside the watched folder")
    return validate_relative_path(Path(rel).as_posix())


def _normcase_real(path: str | os.PathLike[str]) -> str:
    return os.path.normcase(os.path.realpath(path))


def is_within_root(root_real: str, path: str | os.PathLike[str]) -> bool:
    """True if ``path`` (after resolving symlinks/junctions) lies inside ``root_real``."""
    real = _normcase_real(path)
    try:
        return os.path.commonpath([root_real, real]) == root_real and real != root_real
    except ValueError:
        return False


def resolve_candidate_path(folder_root: str | os.PathLike[str], relative_path: str) -> str:
    """Absolute local path for (folder, relative_path), refusing symlink escapes."""
    validate_relative_path(relative_path)
    root_abs = os.path.abspath(folder_root)
    full = os.path.join(root_abs, *relative_path.split("/"))
    if not is_within_root(_normcase_real(root_abs), full):
        raise PathOutsideRoot("path resolves outside the watched folder")
    return full


def _is_hidden_windows(st: os.stat_result) -> bool:
    attrs = getattr(st, "st_file_attributes", 0)
    return bool(attrs & _WIN_HIDDEN)


def stat_candidate(
    folder: FolderConfig,
    abs_path: str,
    file_filter: FileFilter,
    root_real: str | None = None,
) -> Candidate | None:
    """Build a Candidate for one path, or None if it is not an acceptable video file."""
    try:
        rel = relative_posix_path(folder.path, abs_path)
    except PathOutsideRoot:
        return None
    if not file_filter.accepts(rel):
        return None
    root_real = root_real or _normcase_real(folder.path)
    if not is_within_root(root_real, abs_path):
        log.debug("skipping %s:%s - link points outside the watched folder", folder.label, rel)
        return None
    try:
        st = os.stat(abs_path)
    except OSError:
        return None
    if not stat.S_ISREG(st.st_mode) or _is_hidden_windows(st):
        return None
    return Candidate(folder.label, rel, abs_path, st.st_size, st.st_mtime_ns)


def scan_folder(
    folder: FolderConfig,
    file_filter: FileFilter,
    *,
    on_error: Callable[[OSError], None] | None = None,
) -> Iterator[Candidate]:
    """Recursively yield candidate files in a watched folder (symlinked dirs are not followed)."""
    root = os.path.abspath(folder.path)
    if not os.path.isdir(root):
        raise FolderUnavailable(f"watched folder {folder.label!r} is not available")
    root_real = _normcase_real(root)

    def _onerror(err: OSError) -> None:
        if on_error:
            on_error(err)
        else:
            log.warning("cannot read a directory in %s: %s", folder.label, err.strerror or err)

    for dirpath, dirnames, filenames in os.walk(root, followlinks=False, onerror=_onerror):
        kept = []
        for d in sorted(dirnames):
            if file_filter.is_ignored_name(d):
                continue
            full = os.path.join(dirpath, d)
            if not is_within_root(root_real, full):  # e.g. Windows junction pointing elsewhere
                continue
            kept.append(d)
        dirnames[:] = kept
        for name in sorted(filenames):
            if file_filter.is_ignored_name(name) or not file_filter.has_allowed_extension(name):
                continue
            cand = stat_candidate(folder, os.path.join(dirpath, name), file_filter, root_real)
            if cand is not None:
                yield cand
