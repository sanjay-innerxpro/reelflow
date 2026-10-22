"""ReelFlow local folder sync agent.

Watches local folders for finished video files and uploads them to a ReelFlow
server in resumable, checksummed chunks. Outbound HTTPS only.
"""

__version__ = "1.0.0"
APP_NAME = "reelflow-agent"
