#!/usr/bin/env bash
set -euo pipefail

WINDOWS_CMD="${WECHAT_WINDOWS_CMD:-/mnt/c/Windows/System32/cmd.exe}"
SCRIPT_WIN="${WECHAT_UPLOAD_DEVTOOLS_SCRIPT_WIN:-D:\Program\0\home\0\yoshop1\yoshop2.0-uniapp\scripts\upload-wechat-experience.cmd}"
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

echo "[wechat-devtools-upload] invoking Windows helper: $SCRIPT_WIN"
cd "$WINDOWS_DRIVE_ROOT"
cmd_line=""
if [[ -z "${WECHAT_DEVTOOLS_PORT:-}" && -f "$PORT_CACHE_FILE" ]]; then
  WECHAT_DEVTOOLS_PORT="$(tr -d '\r\n' <"$PORT_CACHE_FILE")"
  export WECHAT_DEVTOOLS_PORT
fi
for var_name in WECHAT_DEVTOOLS_PORT WECHAT_DEVTOOLS_BRIDGE_PORT WECHAT_UPLOAD_VERSION WECHAT_UPLOAD_DESC WECHAT_UPLOAD_INFO_OUTPUT; do
  if [[ -n "${!var_name:-}" ]]; then
    cmd_line+="set \"${var_name}=$(escape_for_cmd_set "${!var_name}")\" && "
  fi
done
cmd_line+="call $SCRIPT_WIN"
"$WINDOWS_CMD" /c "$cmd_line"
