#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/root/apps/HBuilderX"
STATE_DIR="/root/.local/share/HBuilder X"
CACHE_DIR="/root/.cache/HBuilder X"

if [ -d "$STATE_DIR" ]; then
  mv "$STATE_DIR" "${STATE_DIR}.bak.$(date +%s)" 2>/dev/null || true
fi
if [ -d "$CACHE_DIR" ]; then
  mv "$CACHE_DIR" "${CACHE_DIR}.bak.$(date +%s)" 2>/dev/null || true
fi

mkdir -p "$STATE_DIR" "$CACHE_DIR"

export DISPLAY="${DISPLAY:-:0}"
export WAYLAND_DISPLAY="${WAYLAND_DISPLAY:-wayland-0}"
export XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/0}"
export XDG_SESSION_TYPE="x11"
export QT_QPA_PLATFORM="xcb"
export DBUS_SESSION_BUS_ADDRESS="${DBUS_SESSION_BUS_ADDRESS:-unix:path=/run/user/0/bus}"
export PULSE_SERVER="${PULSE_SERVER:-unix:/mnt/wslg/PulseServer}"

exec "$APP_DIR/HBuilderX"
