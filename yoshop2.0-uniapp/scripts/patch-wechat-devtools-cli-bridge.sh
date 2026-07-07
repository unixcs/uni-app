#!/usr/bin/env bash
set -euo pipefail

WINDOWS_CMD="${WECHAT_WINDOWS_CMD:-/mnt/c/Windows/System32/cmd.exe}"
SCRIPT_WIN="${WECHAT_PATCH_DEVTOOLS_SCRIPT_WIN:-D:\Program\0\home\0\yoshop1\yoshop2.0-uniapp\scripts\patch-wechat-devtools-cli-bridge.cmd}"
WINDOWS_DRIVE_ROOT="${WECHAT_WINDOWS_DRIVE_ROOT:-/mnt/c}"

escape_for_cmd_set() {
  printf '%s' "${1//\"/\"\"}"
}

escape_for_cmd_arg() {
  printf '%s' "${1//\"/\"\"}"
}

if [[ ! -x "$WINDOWS_CMD" ]]; then
  echo "Windows cmd.exe not found or not executable: $WINDOWS_CMD" >&2
  exit 1
fi

if [[ ! -d "$WINDOWS_DRIVE_ROOT" ]]; then
  echo "Windows drive root not found: $WINDOWS_DRIVE_ROOT" >&2
  exit 1
fi

echo "[wechat-devtools-bridge-patch] invoking Windows helper: $SCRIPT_WIN"
cd "$WINDOWS_DRIVE_ROOT"
cmd_line=""
if [[ -n "${WECHAT_DEVTOOLS_BRIDGE_PORT:-}" ]]; then
  cmd_line+="set \"WECHAT_DEVTOOLS_BRIDGE_PORT=$(escape_for_cmd_set "$WECHAT_DEVTOOLS_BRIDGE_PORT")\" && "
fi
cmd_line+="call $SCRIPT_WIN"
if [[ -n "${WECHAT_DEVTOOLS_BRIDGE_PORT:-}" ]]; then
  cmd_line+=" $(escape_for_cmd_arg "$WECHAT_DEVTOOLS_BRIDGE_PORT")"
fi
"$WINDOWS_CMD" /c "$cmd_line"
