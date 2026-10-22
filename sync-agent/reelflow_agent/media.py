"""Local FFmpeg processing for servers running with MEDIA_PROCESSING=agent.

Shared hosting usually has no FFmpeg, so the agent probes each video on this
computer and uploads the metadata, a thumbnail and the AI caption frames.
Parsing and FFmpeg arguments mirror the server's
app/Services/Media/VideoMetadata.php and FfmpegMediaToolkit.php exactly.
"""

from __future__ import annotations

import glob
import json
import logging
import os
import shutil
import subprocess
import tempfile
import threading
from dataclasses import dataclass
from typing import Any, Callable, Mapping

from .config import AGENT_DIR, Config
from .multipart import FilePart, encode_multipart

log = logging.getLogger("reelflow_agent.media")

MAX_FRAMES = 12                    # server: frames => max:12
MAX_IMAGE_BYTES = 2048 * 1024      # server: reelflow.media.agent_max_upload_kb
DEFAULT_AI_FRAMES = 6
DEFAULT_FRAME_WIDTH = 512
DEFAULT_THUMBNAIL_WIDTH = 480
DEFAULT_TIMEOUT = 300.0
MAX_DIMENSION = 16384
MAX_DURATION = 86400.0

INSTALL_HINT = (
    "Install FFmpeg on this computer - Ubuntu/Debian: `sudo apt install ffmpeg`; "
    "Windows: `winget install Gyan.FFmpeg`, or download a build from https://www.gyan.dev/ffmpeg/builds/, "
    "unzip it and set ffmpeg_path / ffprobe_path in config.toml to its bin\\ffmpeg.exe and bin\\ffprobe.exe"
)

Runner = Callable[[list, float], bytes]


class MediaError(Exception):
    """FFmpeg/FFprobe could not produce the media. ``permanent``: retrying cannot help."""

    def __init__(self, message: str, *, permanent: bool = False):
        super().__init__(message)
        self.permanent = permanent


class MediaToolMissing(MediaError):
    """ffmpeg or ffprobe is not installed / not found."""


class MediaCancelled(MediaError):
    """Shutdown was requested while generating media."""


# -- metadata (VideoMetadata::fromFfprobe) ----------------------------------


def _php_int(value: Any) -> int:
    """Approximates PHP's (int) cast for ffprobe values ("90", "-90", 90.0)."""
    if isinstance(value, bool):
        return int(value)
    if isinstance(value, (int, float)):
        return int(value)
    try:
        return int(float(str(value).strip()))
    except (TypeError, ValueError):
        return 0


def _float_or_none(value: Any) -> float | None:
    if value is None or isinstance(value, bool):
        return None
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def _parse_rate(rate: Any) -> float | None:
    if not rate or rate == "0/0":
        return None
    rate = str(rate)
    try:
        if "/" in rate:
            num, den = (float(x) for x in rate.split("/", 1))
            return round(num / den, 3) if den > 0 else None
        return round(float(rate), 3)
    except ValueError:
        return None


@dataclass(frozen=True)
class VideoMetadata:
    width: int | None
    height: int | None
    duration: float | None
    frame_rate: float | None
    video_codec: str | None
    audio_codec: str | None
    has_video: bool
    has_audio: bool
    format_name: str | None = None
    bit_rate: int | None = None

    @classmethod
    def from_ffprobe(cls, probe: Mapping[str, Any]) -> "VideoMetadata":
        """Parse ``ffprobe -print_format json -show_format -show_streams`` output."""
        streams = [s for s in (probe.get("streams") or []) if isinstance(s, dict)]
        fmt = probe.get("format") if isinstance(probe.get("format"), dict) else {}

        def is_cover(s: Mapping[str, Any]) -> bool:
            disp = s.get("disposition") if isinstance(s.get("disposition"), dict) else {}
            return disp.get("attached_pic") == 1

        video = next((s for s in streams if s.get("codec_type") == "video" and not is_cover(s)), None)
        audio = next((s for s in streams if s.get("codec_type") == "audio"), None)
        v: Mapping[str, Any] = video or {}

        width = _php_int(v["width"]) if v.get("width") is not None else None
        height = _php_int(v["height"]) if v.get("height") is not None else None

        # Phones store portrait video as landscape pixels plus a rotation flag.
        tags = v.get("tags") if isinstance(v.get("tags"), dict) else {}
        rotation = _php_int(tags.get("rotate", 0))
        for side in v.get("side_data_list") or []:
            if isinstance(side, dict) and side.get("rotation") is not None:
                rotation = _php_int(side["rotation"])
        if width and height and abs(rotation) % 180 == 90:
            width, height = height, width

        duration_raw = fmt.get("duration")
        if duration_raw is None:
            duration_raw = v.get("duration")
        duration = _float_or_none(duration_raw)

        rate = v.get("avg_frame_rate")
        if rate is None:
            rate = v.get("r_frame_rate")

        bit_rate = _float_or_none(fmt.get("bit_rate"))
        return cls(
            width=width,
            height=height,
            duration=round(duration, 3) if duration is not None else None,
            frame_rate=_parse_rate(rate),
            video_codec=v.get("codec_name") if video is not None else None,
            audio_codec=(audio or {}).get("codec_name"),
            has_video=video is not None,
            has_audio=audio is not None,
            format_name=fmt.get("format_name"),
            bit_rate=int(bit_rate) if bit_rate is not None else None,
        )

    def to_payload(self) -> dict[str, Any]:
        """The ``metadata`` JSON for POST /api/sync/videos/{id}/media."""
        if not self.has_video or not self.width or not self.height:
            raise MediaError("No playable video stream was found.", permanent=True)
        if not (1 <= self.width <= MAX_DIMENSION and 1 <= self.height <= MAX_DIMENSION):
            raise MediaError(f"Unsupported video size {self.width}x{self.height}.", permanent=True)
        if self.duration is None or self.duration < 0:
            raise MediaError("Could not determine the video duration.", permanent=True)
        if self.duration > MAX_DURATION:
            raise MediaError(f"Video is too long ({self.duration:.0f} s).", permanent=True)
        frame_rate = self.frame_rate if self.frame_rate is not None and 0 <= self.frame_rate <= 1000 else None
        return {
            "width": int(self.width),
            "height": int(self.height),
            "duration": float(self.duration),
            "frame_rate": frame_rate,
            "video_codec": str(self.video_codec)[:40] if self.video_codec else None,
            "audio_codec": str(self.audio_codec)[:40] if self.audio_codec else None,
            "has_audio": bool(self.has_audio),
        }


# -- settings / result --------------------------------------------------------


@dataclass(frozen=True)
class MediaSettings:
    ai_frames: int = DEFAULT_AI_FRAMES
    frame_width: int = DEFAULT_FRAME_WIDTH
    thumbnail_width: int = DEFAULT_THUMBNAIL_WIDTH


@dataclass
class MediaBundle:
    metadata: dict[str, Any]
    thumbnail: bytes
    frames: list[bytes]

    def encode(self) -> tuple[bytes, str]:
        """multipart/form-data body + Content-Type. File names are generic, never local paths."""
        files = [FilePart("thumbnail", "thumbnail.jpg", self.thumbnail, "image/jpeg")]
        files += [FilePart("frames[]", f"frame_{i:02d}.jpg", data, "image/jpeg") for i, data in enumerate(self.frames)]
        meta = json.dumps(self.metadata, separators=(",", ":"))
        return encode_multipart([("metadata", meta)], files)


# -- running the tools ----------------------------------------------------------


def run_tool(args: list, timeout: float) -> bytes:
    """Run ffmpeg/ffprobe without a shell or console window; return stdout."""
    name = os.path.basename(str(args[0]))
    kwargs: dict[str, Any] = {}
    if os.name == "nt":
        kwargs["creationflags"] = getattr(subprocess, "CREATE_NO_WINDOW", 0)
    try:
        proc = subprocess.run(
            [str(a) for a in args],
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=timeout,
            check=False,
            **kwargs,
        )
    except FileNotFoundError:
        raise MediaToolMissing(f"{name} was not found") from None
    except PermissionError:
        raise MediaToolMissing(f"{name} is not executable") from None
    except subprocess.TimeoutExpired:
        raise MediaError(f"{name} timed out after {timeout:.0f}s") from None
    except OSError as e:
        raise MediaError(f"could not run {name}: {e.strerror or e}") from None
    if proc.returncode != 0:
        err = proc.stderr.decode("utf-8", "replace").strip()
        raise MediaError(f"{name} failed (exit {proc.returncode}): {err[-500:] or 'no error output'}")
    return proc.stdout


def _php_float(value: float) -> str:
    """PHP's (string) of a float: 1.0 -> "1", 0.5 -> "0.5"."""
    text = repr(float(value))
    return text[:-2] if text.endswith(".0") else text


def _exe(name: str) -> str:
    return name + ".exe" if os.name == "nt" else name


def _is_executable(path: str) -> bool:
    return os.path.isfile(path) and os.access(path, os.X_OK)


def common_locations(name: str) -> list[str]:
    """Places FFmpeg is often installed but which may not be on the agent's PATH."""
    exe = _exe(name)
    out = [str(AGENT_DIR / "ffmpeg" / "bin" / exe), str(AGENT_DIR / "ffmpeg" / exe)]
    if os.name == "nt":
        local = os.environ.get("LOCALAPPDATA", "")
        if local:
            out.append(os.path.join(local, "Microsoft", "WinGet", "Links", exe))
            out += sorted(glob.glob(os.path.join(local, "Microsoft", "WinGet", "Packages", "Gyan.FFmpeg*", "*", "bin", exe)))
        for base in (os.environ.get("ProgramFiles"), os.environ.get("ProgramFiles(x86)"), "C:\\"):
            if base:
                out.append(os.path.join(base, "ffmpeg", "bin", exe))
        program_data = os.environ.get("ProgramData")
        if program_data:
            out.append(os.path.join(program_data, "chocolatey", "bin", exe))
        out.append(os.path.join(os.path.expanduser("~"), "scoop", "shims", exe))
    else:
        out += [f"/usr/bin/{exe}", f"/usr/local/bin/{exe}", f"/opt/homebrew/bin/{exe}", f"/snap/bin/{exe}"]
    return out


class MediaToolkit:
    """Locates and runs ffmpeg/ffprobe. ``runner`` and ``which`` are injectable for tests."""

    def __init__(
        self,
        ffmpeg_path: str | None = None,
        ffprobe_path: str | None = None,
        *,
        timeout: float = DEFAULT_TIMEOUT,
        runner: Runner | None = None,
        which: Callable[[str], str | None] = shutil.which,
        search_common: bool = True,
        temp_root: str | None = None,
    ):
        self.ffmpeg_path = ffmpeg_path or None
        self.ffprobe_path = ffprobe_path or None
        self.timeout = timeout
        self.runner: Runner = runner or run_tool
        self.which = which
        self.search_common = search_common
        self.temp_root = temp_root
        self._missing_reported = False
        self._report_lock = threading.Lock()

    @classmethod
    def from_config(cls, config: Config) -> "MediaToolkit":
        return cls(config.ffmpeg_path, config.ffprobe_path, timeout=config.ffmpeg_timeout_seconds)

    # -- locating ---------------------------------------------------------

    def _resolve(self, configured: str | None, name: str, sibling_of: str | None) -> str | None:
        if configured:
            if os.path.isabs(configured) or os.path.dirname(configured):
                for cand in (configured, configured + ".exe" if os.name == "nt" else None):
                    if cand and _is_executable(cand):
                        return cand
                return None
            return self.which(configured)
        if sibling_of:  # e.g. only ffmpeg_path set: use the ffprobe next to it
            cand = os.path.join(os.path.dirname(sibling_of), _exe(name))
            if _is_executable(cand):
                return cand
        found = self.which(name)
        if found:
            return found
        if self.search_common:
            for cand in common_locations(name):
                if _is_executable(cand):
                    return cand
        return None

    def _explicit(self, configured: str | None) -> str | None:
        if configured and (os.path.isabs(configured) or os.path.dirname(configured)):
            return configured
        return None

    def locate(self) -> tuple[str | None, str | None]:
        """(ffmpeg, ffprobe) executable paths, None for each one that is missing."""
        ffmpeg = self._resolve(self.ffmpeg_path, "ffmpeg", self._explicit(self.ffprobe_path))
        ffprobe = self._resolve(self.ffprobe_path, "ffprobe", self._explicit(self.ffmpeg_path))
        return ffmpeg, ffprobe

    def missing_message(self, ffmpeg: str | None = None, ffprobe: str | None = None) -> str:
        if ffmpeg is None and ffprobe is None:
            ffmpeg, ffprobe = self.locate()
        missing = [n for n, p in (("ffmpeg", ffmpeg), ("ffprobe", ffprobe)) if not p]
        configured = [f"{k} = {v!r}" for k, v in (("ffmpeg_path", self.ffmpeg_path), ("ffprobe_path", self.ffprobe_path)) if v]
        where = f" (configured: {', '.join(configured)})" if configured else " (not found on PATH)"
        return (
            f"FFmpeg is required on this computer: the server runs with MEDIA_PROCESSING=agent, so this agent must "
            f"create the thumbnail, metadata and AI frames of each video. Missing: {' and '.join(missing) or 'none'}"
            f"{where}. {INSTALL_HINT}. Videos keep uploading; their media is sent automatically once FFmpeg is found."
        )

    def require(self) -> tuple[str, str]:
        ffmpeg, ffprobe = self.locate()
        if not ffmpeg or not ffprobe:
            raise MediaToolMissing(self.missing_message(ffmpeg, ffprobe))
        return ffmpeg, ffprobe

    def check_available(self, logger: logging.Logger | None = None) -> bool:
        """True when both tools are found. Logs one clear error per missing->found transition."""
        ffmpeg, ffprobe = self.locate()
        ok = bool(ffmpeg and ffprobe)
        with self._report_lock:
            if ok:
                if self._missing_reported:
                    (logger or log).info("FFmpeg found (%s, %s); sending pending video media", ffmpeg, ffprobe)
                self._missing_reported = False
            elif not self._missing_reported:
                self._missing_reported = True
                (logger or log).error("%s", self.missing_message(ffmpeg, ffprobe))
        return ok

    def report_missing(self, message: str, logger: logging.Logger | None = None) -> None:
        with self._report_lock:
            if not self._missing_reported:
                self._missing_reported = True
                (logger or log).error("%s", message)

    def version(self, path: str) -> str | None:
        try:
            out = self.runner([path, "-version"], min(self.timeout, 30.0))
        except MediaError:
            return None
        first = out.decode("utf-8", "replace").strip().splitlines()
        return first[0][:120] if first else None

    # -- FfmpegMediaToolkit equivalents ---------------------------------------

    def probe(self, path: str, ffprobe: str | None = None) -> VideoMetadata:
        ffprobe = ffprobe or self.require()[1]
        out = self.runner(
            [ffprobe, "-v", "error", "-print_format", "json", "-show_format", "-show_streams", path], self.timeout
        )
        try:
            data = json.loads(out.decode("utf-8", "replace"))
        except ValueError:
            data = None
        if not isinstance(data, dict):
            raise MediaError("ffprobe returned unreadable output.")
        return VideoMetadata.from_ffprobe(data)

    def thumbnail(self, path: str, output: str, at_second: float, width: int = DEFAULT_THUMBNAIL_WIDTH,
                  ffmpeg: str | None = None) -> None:
        ffmpeg = ffmpeg or self.require()[0]
        self.runner(
            [ffmpeg, "-y", "-v", "error", "-ss", _php_float(max(0.0, at_second)), "-i", path,
             "-frames:v", "1", "-vf", f"scale={int(width)}:-2", "-q:v", "3", output],
            self.timeout,
        )
        if not os.path.isfile(output) or os.path.getsize(output) == 0:
            raise MediaError("ffmpeg did not produce a thumbnail.")

    @staticmethod
    def frame_times(duration: float, count: int) -> list[float]:
        """Middle of each of ``count`` equal segments (avoids black first/last frames)."""
        count = max(1, count)
        return [(duration / count) * (i + 0.5) if duration > 0 else 0.0 for i in range(count)]

    def frames(self, path: str, duration: float, count: int, width: int, output_dir: str, *,
               ffmpeg: str | None = None, should_stop: Callable[[], bool] | None = None) -> list[str]:
        ffmpeg = ffmpeg or self.require()[0]
        paths = []
        for i, at in enumerate(self.frame_times(duration, count)):
            if should_stop is not None and should_stop():
                raise MediaCancelled("stopped")
            out = os.path.join(output_dir, f"frame_{i}.jpg")
            try:
                self.runner(
                    [ffmpeg, "-y", "-v", "error", "-ss", f"{at:.2f}", "-i", path,
                     "-frames:v", "1", "-vf", f"scale={int(width)}:-2", "-q:v", "5", out],
                    self.timeout,
                )
            except (MediaToolMissing, MediaCancelled):
                raise
            except MediaError as e:
                log.warning("frame %d at %.2fs could not be extracted: %s", i, at, e)
                continue
            if os.path.isfile(out) and os.path.getsize(out) > 0:
                paths.append(out)
        return paths

    # -- whole pipeline ----------------------------------------------------------

    def generate(self, path: str, settings: MediaSettings, *,
                 should_stop: Callable[[], bool] | None = None) -> MediaBundle:
        """Probe ``path`` and extract the thumbnail + frames in a temporary directory (always removed)."""
        ffmpeg, ffprobe = self.require()
        path = os.path.abspath(path)
        with tempfile.TemporaryDirectory(prefix="reelflow-media-", dir=self.temp_root) as tmp:
            try:
                meta = self.probe(path, ffprobe).to_payload()
                if should_stop is not None and should_stop():
                    raise MediaCancelled("stopped")
                duration = float(meta["duration"])
                thumb_path = os.path.join(tmp, "thumbnail.jpg")
                self.thumbnail(path, thumb_path, min(1.0, duration / 2), settings.thumbnail_width, ffmpeg)
                count = max(1, min(MAX_FRAMES, int(settings.ai_frames)))
                frame_paths = self.frames(path, duration, count, settings.frame_width, tmp,
                                          ffmpeg=ffmpeg, should_stop=should_stop)
                thumbnail = _read_jpeg(thumb_path)
                if thumbnail is None:
                    raise MediaError("ffmpeg did not produce a valid JPEG thumbnail.")
                if len(thumbnail) > MAX_IMAGE_BYTES:
                    raise MediaError("thumbnail is larger than the server's 2 MB limit.", permanent=True)
                frames = []
                for fp in frame_paths:
                    data = _read_jpeg(fp)
                    if data is None:
                        log.warning("skipping invalid frame image %s", os.path.basename(fp))
                    elif len(data) > MAX_IMAGE_BYTES:
                        log.warning("skipping frame %s: larger than 2 MB", os.path.basename(fp))
                    else:
                        frames.append(data)
            except MediaError as e:
                # Never keep local paths in messages (they end up in state and heartbeats).
                text = str(e).replace(tmp, "<tmp>").replace(path, "<video>")
                raise type(e)(text, permanent=e.permanent) from None
        return MediaBundle(meta, thumbnail, frames)


def _read_jpeg(path: str) -> bytes | None:
    try:
        with open(path, "rb") as fh:
            data = fh.read(MAX_IMAGE_BYTES + 1)
    except OSError:
        return None
    return data if data.startswith(b"\xff\xd8") else None
