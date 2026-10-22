#!/usr/bin/env sh
# Run the ReelFlow sync agent. Usage: ./run-agent.sh [command] [options]
# With no arguments it runs the long-running "run" command.
set -eu
cd "$(dirname "$0")"
if [ -x ".venv/bin/python" ]; then
    PY=".venv/bin/python"
else
    PY="$(command -v python3 || command -v python)"
fi
[ -f .env ] && chmod 600 .env 2>/dev/null || true
if [ "$#" -eq 0 ]; then
    set -- run
fi
exec "$PY" -m reelflow_agent "$@"
