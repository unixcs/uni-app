#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="/opt/yoshop"
PHP_APP_DIR="$ROOT_DIR/yoshop2.0"
RESET_HELPER="$ROOT_DIR/yoshop2.0-uniapp/scripts/reset-wechat-devtools-manual.sh"
UPLOAD_HELPER="$ROOT_DIR/yoshop2.0-uniapp/scripts/upload-wechat-experience.sh"

GOODS_ID="${WECHAT_VIRTUAL_GOODS_ID:-10001}"
TIMEOUT_SECONDS="${WECHAT_VIRTUAL_WATCH_TIMEOUT_SECONDS:-240}"
SETTLE_SECONDS="${WECHAT_VIRTUAL_WATCH_SETTLE_SECONDS:-75}"
EVIDENCE_DIR="${WECHAT_VIRTUAL_WATCH_EVIDENCE_DIR:-$PHP_APP_DIR/runtime/codex-evidence/virtual-payment-watch-live-operator}"
ATTEMPT_TRACE_FILE="${WECHAT_VIRTUAL_ATTEMPT_TRACE_FILE:-$PHP_APP_DIR/runtime/api/codex-evidence/virtual-payment-attempt-trace/$(date +%Y%m%d).jsonl}"
SUMMARY_DIR="${WECHAT_VIRTUAL_RETRY_SUMMARY_DIR:-$PHP_APP_DIR/runtime/codex-evidence/virtual-payment-retry-summary}"

TRACE_LINES_BEFORE=0
if [[ -f "$ATTEMPT_TRACE_FILE" ]]; then
  TRACE_LINES_BEFORE="$(wc -l < "$ATTEMPT_TRACE_FILE" | tr -d ' ')"
fi

echo "[virtual-payment-retry] Step 1/3: reset WeChat DevTools session"
"$RESET_HELPER"

if [[ -f /tmp/wechat-devtools-port-yoshop2.0-mp-weixin.txt ]]; then
  WECHAT_DEVTOOLS_PORT="$(tr -d '\r\n' </tmp/wechat-devtools-port-yoshop2.0-mp-weixin.txt)"
  export WECHAT_DEVTOOLS_PORT
fi

echo
echo "[virtual-payment-retry] Step 2/3: upload fresh experience build"
if [[ -n "${WECHAT_DEVTOOLS_PORT:-}" ]]; then
  echo "  Using IDE port : ${WECHAT_DEVTOOLS_PORT}"
fi
"$UPLOAD_HELPER"

echo
echo "[virtual-payment-retry] Step 3/3: waiting for the next Android retry"
echo "  Goods ID       : $GOODS_ID"
echo "  Timeout        : ${TIMEOUT_SECONDS}s"
echo "  Settle window  : ${SETTLE_SECONDS}s"
echo "  Evidence dir   : $EVIDENCE_DIR"
echo "  Entry trace    : $ATTEMPT_TRACE_FILE"
echo
echo "Now scan the EXPERIENCE QR with Android WeChat and complete the payment attempt."
echo "This terminal will capture the next real wechat_virtual trade and probe its remote status."
echo "If no trade appears, check the entry-trace file above to see whether cashier/orderInfo or cashier/orderPay was ever reached."
echo

set +e
runuser -u www-data -- bash -lc "cd '$PHP_APP_DIR' && php think virtual-payment:watch-live --goods-id='$GOODS_ID' --timeout-seconds='$TIMEOUT_SECONDS' --settle-seconds='$SETTLE_SECONDS' --probe-remote-query --evidence-dir '$EVIDENCE_DIR'"
WATCH_EXIT=$?
set -e

mkdir -p "$SUMMARY_DIR"
LATEST_WATCH_FILE="$(find "$EVIDENCE_DIR" -maxdepth 1 -type f -name 'watch-*.json' 2>/dev/null | sort | tail -n 1)"
SUMMARY_FILE="$SUMMARY_DIR/summary-$(date +%Y%m%d-%H%M%S).json"
TRACE_DELTA_FILE="$(mktemp)"
trap 'rm -f "$TRACE_DELTA_FILE"' EXIT

echo
echo "[virtual-payment-retry] Summary"
if [[ -n "$LATEST_WATCH_FILE" && -f "$LATEST_WATCH_FILE" ]]; then
  php -r '
    $file = $argv[1];
    $data = json_decode(file_get_contents($file), true) ?: [];
    $summary = (array)($data["summary"] ?? []);
    $trade = (array)($data["captured_trade"] ?? []);
    $watch = (array)($data["watch"] ?? []);
    $remote = (array)($data["remote_query_probe"] ?? []);
    echo "  Watch file     : {$file}\n";
    echo "  Summary        : " . (($summary["message"] ?? "") ?: "n/a") . "\n";
    if (!empty($watch)) {
      echo "  Baseline trade : " . (string)($watch["baseline_trade_id"] ?? "") . "\n";
    }
    if (!empty($trade)) {
      echo "  Captured trade : " . (string)($trade["trade_id"] ?? "") . " / " . (string)($trade["out_trade_no"] ?? "") . "\n";
    }
    if (!empty($remote)) {
      $status = $remote["result"]["order"]["status"] ?? null;
      echo "  Remote status  : " . ($status === null ? "n/a" : (string)$status) . "\n";
    }
  ' "$LATEST_WATCH_FILE"
else
  echo "  Watch file     : none"
fi

echo "  Entry trace    : $ATTEMPT_TRACE_FILE"
if [[ -f "$ATTEMPT_TRACE_FILE" ]]; then
  TRACE_LINES_AFTER="$(wc -l < "$ATTEMPT_TRACE_FILE" | tr -d ' ')"
  if (( TRACE_LINES_AFTER > TRACE_LINES_BEFORE )); then
    tail -n +"$((TRACE_LINES_BEFORE + 1))" "$ATTEMPT_TRACE_FILE" >"$TRACE_DELTA_FILE"
    echo "  Trace delta    :"
    cat "$TRACE_DELTA_FILE"
  else
    echo "  Trace delta    : none"
  fi
else
  echo "  Trace delta    : file not created"
fi

php -r '
  $watchExit = (int)$argv[1];
  $watchFile = $argv[2];
  $traceFile = $argv[3];
  $traceDeltaFile = $argv[4];
  $summaryFile = $argv[5];
  $summary = [
    "generated_at" => date("c"),
    "watch_exit" => $watchExit,
    "watch_file" => $watchFile,
    "trace_file" => $traceFile,
    "watch" => null,
    "trace_delta" => [],
  ];
  if ($watchFile !== "" && is_file($watchFile)) {
    $summary["watch"] = json_decode(file_get_contents($watchFile), true);
  }
  if (is_file($traceDeltaFile)) {
    foreach (file($traceDeltaFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      $decoded = json_decode($line, true);
      $summary["trace_delta"][] = is_array($decoded) ? $decoded : ["raw" => $line];
    }
  }
  file_put_contents($summaryFile, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
' "$WATCH_EXIT" "${LATEST_WATCH_FILE:-}" "$ATTEMPT_TRACE_FILE" "$TRACE_DELTA_FILE" "$SUMMARY_FILE"
echo "  Retry summary  : $SUMMARY_FILE"

exit "$WATCH_EXIT"
