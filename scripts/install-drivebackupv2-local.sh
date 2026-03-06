#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 ]]; then
    echo "Usage: $0 /path/to/server/root [jar_path]"
    echo "Example: $0 /var/lib/pterodactyl/volumes/<uuid>"
    exit 1
fi

SERVER_ROOT="$1"
JAR_SOURCE="${2:-/root/download/DriveBackupV2-v1.8.1.jar}"
TARGET_DIR="${SERVER_ROOT%/}/plugins"
TARGET_JAR="${TARGET_DIR}/DriveBackupV2.jar"

if [[ ! -d "$SERVER_ROOT" ]]; then
    echo "Server root not found: $SERVER_ROOT"
    exit 1
fi

if [[ ! -f "$JAR_SOURCE" ]]; then
    echo "Jar source not found: $JAR_SOURCE"
    exit 1
fi

mkdir -p "$TARGET_DIR"
install -m 0644 "$JAR_SOURCE" "$TARGET_JAR"

echo "Installed: $TARGET_JAR"
echo "Next: restart the Minecraft server, then run '/drivebackup linkaccount googledrive'."
