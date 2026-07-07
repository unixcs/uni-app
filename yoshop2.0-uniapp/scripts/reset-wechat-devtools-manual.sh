#!/usr/bin/env bash
set -euo pipefail

WINDOWS_CMD="${WECHAT_WINDOWS_CMD:-/mnt/c/Windows/System32/cmd.exe}"
SCRIPT_WIN="${WECHAT_RESET_DEVTOOLS_SCRIPT_WIN:-D:\Program\0\home\0\yoshop1\yoshop2.0-uniapp\scripts\reset-wechat-devtools-manual.cmd}"
WINDOWS_DRIVE_ROOT="${WECHAT_WINDOWS_DRIVE_ROOT:-/mnt/c}"
PORT_CACHE_FILE="${WECHAT_DEVTOOLS_PORT_CACHE_FILE:-/tmp/wechat-devtools-port-yoshop2.0-mp-weixin.txt}"

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

echo "[wechat-devtools-reset] invoking Windows helper: $SCRIPT_WIN"
cd "$WINDOWS_DRIVE_ROOT"
cmd_line=""
for var_name in WECHAT_DEVTOOLS_PORT WECHAT_DEVTOOLS_BRIDGE_PORT; do
  if [[ -n "${!var_name:-}" ]]; then
    cmd_line+="set \"${var_name}=$(escape_for_cmd_set "${!var_name}")\" && "
  fi
done
cmd_line+="call $SCRIPT_WIN"
output="$("$WINDOWS_CMD" /c "$cmd_line")"
printf '%s\n' "$output"

detected_port="$(printf '%s\n' "$output" | sed -n 's/^CODEX_IDE_PORT:\([0-9][0-9]*\)$/\1/p' | tail -n 1)"
if [[ -z "$detected_port" ]]; then
  detected_port="$(printf '%s\n' "$output" | sed -n 's/.*listening on http:\/\/127\.0\.0\.1:\([0-9][0-9]*\).*/\1/p' | tail -n 1)"
fi
if [[ -n "$detected_port" ]]; then
  printf '%s\n' "$detected_port" >"$PORT_CACHE_FILE"
fi
