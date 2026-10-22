"""Configuration loading: TOML file for settings, environment / .env for secrets."""

from __future__ import annotations

import ipaddress
import os
import re
import sys
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Mapping
from urllib.parse import urlsplit

from .envfile import EnvFileError, insecure_permissions, load_env_file

try:  # Python 3.11+
    import tomllib as _toml
except ModuleNotFoundError:  # pragma: no cover - Python 3.10
    try:
        import tomli as _toml  # type: ignore[no-redef]
    except ModuleNotFoundError:
        _toml = None  # type: ignore[assignment]

ENV_SERVER_URL = "REELFLOW_SERVER_URL"
ENV_DEVICE_TOKEN = "REELFLOW_DEVICE_TOKEN"
ENV_CONFIG_PATH = "REELFLOW_AGENT_CONFIG"
TOKEN_PREFIX = "rfd_"

AGENT_DIR = Path(__file__).resolve().parent.parent  # the sync-agent/ directory

DEFAULT_EXTENSIONS = ("mp4", "mov", "webm", "m4v")
DEFAULT_IGNORE_PATTERNS = (".*", "*.part", "*.crdownload", "*.tmp", "~$*")
MAX_FOLDERS = 50  # server accepts at most 50 folders in a heartbeat

_LABEL_RE = re.compile(r"^[\w][\w .\-]{0,119}$", re.UNICODE)
_EXT_RE = re.compile(r"^[a-z0-9]{1,16}$")
_LOG_LEVELS = ("DEBUG", "INFO", "WARNING", "ERROR", "CRITICAL")


class ConfigError(Exception):
    """Invalid or missing configuration."""


@dataclass(frozen=True)
class FolderConfig:
    label: str
    path: Path


@dataclass
class Config:
    folders: list[FolderConfig]
    server_url: str = ""
    device_token: str = field(default="", repr=False)
    extensions: tuple[str, ...] = DEFAULT_EXTENSIONS
    ignore_patterns: tuple[str, ...] = DEFAULT_IGNORE_PATTERNS
    stability_seconds: float = 10.0
    scan_interval_seconds: float = 60.0
    heartbeat_interval_seconds: float = 60.0
    max_concurrent_uploads: int = 1
    max_retries: int = 8
    retry_backoff_base_seconds: float = 2.0
    retry_backoff_max_seconds: float = 300.0
    state_dir: Path = field(default_factory=lambda: default_state_dir())
    log_dir: Path | None = None
    log_level: str = "INFO"
    log_max_bytes: int = 5 * 1024 * 1024
    log_backup_count: int = 5
    request_timeout_seconds: float = 120.0
    verify_tls: bool = True
    use_watchdog: bool = True
    completion_poll_attempts: int = 3
    completion_poll_interval_seconds: float = 2.0
    ffmpeg_path: str | None = None
    ffprobe_path: str | None = None
    ffmpeg_timeout_seconds: float = 300.0
    config_path: Path | None = None
    warnings: list[str] = field(default_factory=list)

    @property
    def effective_log_dir(self) -> Path:
        return self.log_dir if self.log_dir is not None else self.state_dir / "logs"

    @property
    def db_path(self) -> Path:
        return self.state_dir / "state.sqlite3"

    def folder(self, label: str) -> FolderConfig | None:
        for f in self.folders:
            if f.label == label:
                return f
        return None

    def __repr__(self) -> str:  # never show the token
        return f"Config(folders={self.folders!r}, server_url={self.server_url!r}, state_dir={self.state_dir!r})"


def default_state_dir() -> Path:
    """Platform-appropriate directory for the state DB and logs."""
    if os.name == "nt":
        base = os.environ.get("LOCALAPPDATA") or str(Path.home() / "AppData" / "Local")
        return Path(base) / "ReelFlowAgent"
    if sys.platform == "darwin":
        return Path.home() / "Library" / "Application Support" / "ReelFlowAgent"
    base = os.environ.get("XDG_STATE_HOME") or str(Path.home() / ".local" / "state")
    return Path(base) / "reelflow-agent"


def default_config_path() -> Path:
    """$REELFLOW_AGENT_CONFIG, else ./config.toml, else <agent dir>/config.toml."""
    env = os.environ.get(ENV_CONFIG_PATH)
    if env:
        return Path(env).expanduser()
    cwd = Path.cwd() / "config.toml"
    if cwd.is_file():
        return cwd
    return AGENT_DIR / "config.toml"


def _expand_path(value: str, base: Path) -> Path:
    p = Path(os.path.expandvars(os.path.expanduser(value)))
    if not p.is_absolute():
        p = base / p
    return Path(os.path.abspath(p))


def normalize_server_url(url: str) -> str:
    """Validate and normalise the server base URL (scheme://host[:port][/prefix])."""
    url = (url or "").strip().rstrip("/")
    for suffix in ("/api/sync", "/api"):
        if url.endswith(suffix):
            url = url[: -len(suffix)]
    parts = urlsplit(url)
    if parts.scheme not in ("https", "http") or not parts.hostname:
        raise ConfigError(f"{ENV_SERVER_URL} must be an absolute https:// URL")
    if parts.query or parts.fragment or parts.username or parts.password:
        raise ConfigError(f"{ENV_SERVER_URL} must not contain credentials, a query string or a fragment")
    if parts.scheme == "http" and not _is_loopback(parts.hostname):
        raise ConfigError(
            f"{ENV_SERVER_URL} must use https:// (plain http is only allowed for localhost testing)"
        )
    return url


def _is_loopback(host: str) -> bool:
    if host == "localhost":
        return True
    try:
        return ipaddress.ip_address(host).is_loopback
    except ValueError:
        return False


def validate_token(token: str) -> str:
    token = (token or "").strip()
    if not token:
        raise ConfigError(f"{ENV_DEVICE_TOKEN} is not set (put it in the environment or in .env)")
    if not token.startswith(TOKEN_PREFIX) or len(token) < 12 or re.search(r"\s", token):
        raise ConfigError(f"{ENV_DEVICE_TOKEN} does not look like a device token (expected rfd_...)")
    return token


def _num(data: Mapping[str, Any], key: str, default: float, *, minimum: float, maximum: float, integer: bool = False):
    value = data.get(key, default)
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise ConfigError(f"{key} must be a number")
    if integer and not float(value).is_integer():
        raise ConfigError(f"{key} must be an integer")
    if not (minimum <= value <= maximum):
        raise ConfigError(f"{key} must be between {minimum:g} and {maximum:g}")
    return int(value) if integer else float(value)


def _bool(data: Mapping[str, Any], key: str, default: bool) -> bool:
    value = data.get(key, default)
    if not isinstance(value, bool):
        raise ConfigError(f"{key} must be true or false")
    return value


def _str_list(data: Mapping[str, Any], key: str, default: tuple[str, ...]) -> list[str]:
    value = data.get(key, list(default))
    if not isinstance(value, list) or not all(isinstance(v, str) for v in value):
        raise ConfigError(f"{key} must be a list of strings")
    return value


_KNOWN_KEYS = {
    "folders", "extensions", "ignore_patterns", "stability_seconds", "scan_interval_seconds",
    "heartbeat_interval_seconds", "max_concurrent_uploads", "max_retries", "retry_backoff_base_seconds",
    "retry_backoff_max_seconds", "state_dir", "log_dir", "log_level", "log_max_bytes", "log_backup_count",
    "request_timeout_seconds", "verify_tls", "use_watchdog", "completion_poll_attempts",
    "completion_poll_interval_seconds", "ffmpeg_path", "ffprobe_path", "ffmpeg_timeout_seconds",
}


def _tool_path(data: Mapping[str, Any], key: str, base: Path) -> str | None:
    """Optional ffmpeg/ffprobe location: a bare command name (looked up on PATH) or a file path."""
    value = data.get(key)
    if value is None:
        return None
    if not isinstance(value, str) or "\x00" in value:
        raise ConfigError(f"{key} must be a string (path to the executable, or a command name on PATH)")
    value = value.strip()
    if not value:
        return None
    expanded = os.path.expandvars(os.path.expanduser(value))
    if os.path.isabs(expanded) or "/" in expanded or "\\" in expanded:
        return str(_expand_path(expanded, base))
    return expanded


def parse_config_data(
    data: Mapping[str, Any],
    *,
    base_dir: Path,
    env: Mapping[str, str],
    require_secrets: bool = True,
    require_folders: bool = True,
) -> Config:
    """Build a validated Config from parsed TOML data and environment values."""
    unknown = sorted(set(data) - _KNOWN_KEYS)
    if unknown:
        raise ConfigError(f"unknown config option(s): {', '.join(unknown)}")

    warnings: list[str] = []

    raw_folders = data.get("folders", [])
    if not isinstance(raw_folders, list):
        raise ConfigError("folders must be an array of tables: [[folders]] label = ... path = ...")
    folders: list[FolderConfig] = []
    seen_labels: set[str] = set()
    for i, entry in enumerate(raw_folders, start=1):
        if not isinstance(entry, dict):
            raise ConfigError(f"folders entry #{i} must be a table with label and path")
        extra = set(entry) - {"label", "path"}
        if extra:
            raise ConfigError(
                f"folders entry #{i}: unknown key(s) {', '.join(sorted(extra))} "
                "(global options must come before the first [[folders]] block)"
            )
        label = entry.get("label")
        path = entry.get("path")
        if not isinstance(label, str) or not _LABEL_RE.match(label.strip()) or label != label.strip():
            raise ConfigError(
                f"folders entry #{i}: label must be 1-120 characters of letters, digits, space, '_', '-' or '.'"
            )
        if label.casefold() in seen_labels:
            raise ConfigError(f"duplicate folder label {label!r}")
        seen_labels.add(label.casefold())
        if not isinstance(path, str) or not path.strip():
            raise ConfigError(f"folders entry #{i} ({label}): path is required")
        folders.append(FolderConfig(label=label, path=_expand_path(path.strip(), base_dir)))

    if require_folders and not folders:
        raise ConfigError("no folders configured; add at least one [[folders]] entry")
    if len(folders) > MAX_FOLDERS:
        raise ConfigError(f"at most {MAX_FOLDERS} folders are supported")

    # Overlapping folders would register the same file twice under different labels.
    resolved = [(f, Path(os.path.realpath(f.path))) for f in folders]
    for i, (fa, pa) in enumerate(resolved):
        for fb, pb in resolved[i + 1 :]:
            if pa == pb or pa in pb.parents or pb in pa.parents:
                raise ConfigError(f"folders {fa.label!r} and {fb.label!r} overlap; watched folders must not be nested")

    extensions: list[str] = []
    for ext in _str_list(data, "extensions", DEFAULT_EXTENSIONS):
        norm = ext.strip().lstrip(".").lower()
        if not _EXT_RE.match(norm):
            raise ConfigError(f"invalid extension {ext!r}")
        if norm not in extensions:
            extensions.append(norm)
    if not extensions:
        raise ConfigError("extensions must not be empty")

    ignore_patterns = [p for p in _str_list(data, "ignore_patterns", DEFAULT_IGNORE_PATTERNS) if p.strip()]

    log_level = data.get("log_level", "INFO")
    if not isinstance(log_level, str) or log_level.upper() not in _LOG_LEVELS:
        raise ConfigError(f"log_level must be one of {', '.join(_LOG_LEVELS)}")

    state_dir_raw = data.get("state_dir")
    if state_dir_raw is not None and (not isinstance(state_dir_raw, str) or not state_dir_raw.strip()):
        raise ConfigError("state_dir must be a non-empty string")
    state_dir = _expand_path(state_dir_raw, base_dir) if state_dir_raw else default_state_dir()
    log_dir_raw = data.get("log_dir")
    if log_dir_raw is not None and (not isinstance(log_dir_raw, str) or not log_dir_raw.strip()):
        raise ConfigError("log_dir must be a non-empty string")
    log_dir = _expand_path(log_dir_raw, base_dir) if log_dir_raw else None

    server_url = env.get(ENV_SERVER_URL, "").strip()
    token = env.get(ENV_DEVICE_TOKEN, "").strip()
    if require_secrets:
        if not server_url:
            raise ConfigError(f"{ENV_SERVER_URL} is not set (put it in the environment or in .env)")
        server_url = normalize_server_url(server_url)
        token = validate_token(token)
    elif server_url:
        try:
            server_url = normalize_server_url(server_url)
        except ConfigError as e:
            warnings.append(str(e))

    verify_tls = _bool(data, "verify_tls", True)
    if not verify_tls:
        warnings.append("verify_tls = false: TLS certificates are NOT verified (testing only!)")

    cfg = Config(
        folders=folders,
        server_url=server_url,
        device_token=token,
        extensions=tuple(extensions),
        ignore_patterns=tuple(ignore_patterns),
        stability_seconds=_num(data, "stability_seconds", 10, minimum=0, maximum=86400),
        scan_interval_seconds=_num(data, "scan_interval_seconds", 60, minimum=1, maximum=86400),
        heartbeat_interval_seconds=_num(data, "heartbeat_interval_seconds", 60, minimum=1, maximum=86400),
        max_concurrent_uploads=_num(data, "max_concurrent_uploads", 1, minimum=1, maximum=8, integer=True),
        max_retries=_num(data, "max_retries", 8, minimum=0, maximum=100, integer=True),
        retry_backoff_base_seconds=_num(data, "retry_backoff_base_seconds", 2, minimum=0, maximum=3600),
        retry_backoff_max_seconds=_num(data, "retry_backoff_max_seconds", 300, minimum=0, maximum=86400),
        state_dir=state_dir,
        log_dir=log_dir,
        log_level=log_level.upper(),
        log_max_bytes=_num(data, "log_max_bytes", 5 * 1024 * 1024, minimum=10_000, maximum=1 << 31, integer=True),
        log_backup_count=_num(data, "log_backup_count", 5, minimum=0, maximum=100, integer=True),
        request_timeout_seconds=_num(data, "request_timeout_seconds", 120, minimum=1, maximum=3600),
        verify_tls=verify_tls,
        use_watchdog=_bool(data, "use_watchdog", True),
        completion_poll_attempts=_num(data, "completion_poll_attempts", 3, minimum=0, maximum=100, integer=True),
        completion_poll_interval_seconds=_num(data, "completion_poll_interval_seconds", 2, minimum=0, maximum=600),
        ffmpeg_path=_tool_path(data, "ffmpeg_path", base_dir),
        ffprobe_path=_tool_path(data, "ffprobe_path", base_dir),
        ffmpeg_timeout_seconds=_num(data, "ffmpeg_timeout_seconds", 300, minimum=5, maximum=7200),
        warnings=warnings,
    )
    return cfg


def read_toml(path: Path) -> dict[str, Any]:
    if _toml is None:
        raise ConfigError("TOML support missing: use Python 3.11+ or `pip install tomli` on Python 3.10")
    try:
        with open(path, "rb") as fh:
            return _toml.load(fh)
    except FileNotFoundError:
        raise ConfigError(f"config file not found: {path} (copy config.example.toml to config.toml)") from None
    except _toml.TOMLDecodeError as e:
        raise ConfigError(f"invalid TOML in {path}: {e}") from None
    except OSError as e:
        raise ConfigError(f"cannot read config file {path}: {e.strerror}") from None


def collect_env(config_dir: Path, environ: Mapping[str, str] | None = None) -> tuple[dict[str, str], list[str]]:
    """Merge .env files (agent dir, then config dir) with the process environment (wins)."""
    environ = os.environ if environ is None else environ
    merged: dict[str, str] = {}
    warnings: list[str] = []
    seen: set[Path] = set()
    for directory in (AGENT_DIR, config_dir):
        env_path = (directory / ".env").resolve()
        if env_path in seen or not env_path.is_file():
            continue
        seen.add(env_path)
        try:
            merged.update(load_env_file(env_path))
        except EnvFileError as e:
            raise ConfigError(f"{env_path}: {e}") from None
        except OSError as e:
            raise ConfigError(f"cannot read {env_path}: {e.strerror}") from None
        if insecure_permissions(env_path):
            warnings.append(f"{env_path} is readable by other users; run: chmod 600 {env_path}")
    for key in (ENV_SERVER_URL, ENV_DEVICE_TOKEN):
        if environ.get(key):
            merged[key] = environ[key]
    return merged, warnings


def load_config(
    path: str | os.PathLike[str] | None = None,
    *,
    environ: Mapping[str, str] | None = None,
    require_secrets: bool = True,
    require_folders: bool = True,
) -> Config:
    """Load config.toml + secrets. Raises ConfigError with a human readable message."""
    config_path = Path(path).expanduser() if path else default_config_path()
    config_path = Path(os.path.abspath(config_path))
    data = read_toml(config_path)
    env, env_warnings = collect_env(config_path.parent, environ)
    cfg = parse_config_data(
        data,
        base_dir=config_path.parent,
        env=env,
        require_secrets=require_secrets,
        require_folders=require_folders,
    )
    cfg.config_path = config_path
    cfg.warnings.extend(env_warnings)
    return cfg
