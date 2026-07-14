#!/usr/bin/env bash
set -euo pipefail
IFS=$'\n\t'
umask 077
export LC_ALL=C

readonly DEFAULT_BASE_URL='https://wx.gxwqb.cn'
readonly EXPECTED_CHECKS=10
readonly CONNECT_TIMEOUT=2
readonly REQUEST_TIMEOUT=5
readonly CALLBACK_TIMEOUT=3

BASE_URL="$DEFAULT_BASE_URL"
HTTP_URL=''
UPLOAD_PATH=''
CA_CERT=''
NON_PRODUCTION_TEST=0
BASE_URL_SET=0
HTTP_URL_SET=0
CA_CERT_SET=0
PASSED_CHECKS=0
TARGET_LABEL='production_b'
TMP_DIR=''

usage() {
  cat <<'USAGE'
Usage:
  production-smoke.sh --upload-path /uploads/<referenced-image>
  production-smoke.sh --non-production-test \
    --base-url https://127.0.0.1:<https-port> \
    --http-url http://127.0.0.1:<http-port> \
    --cacert <test-ca.pem> \
    --upload-path /uploads/<referenced-image>

The default and only production target is https://wx.gxwqb.cn. Any other target,
including wx.oiob.cn, requires --non-production-test. All probes are GET requests;
the callback probe is unsigned, has no query or body, and validates only HTTP 200
route reachability. Response bodies are never printed.
USAGE
}

summary() {
  local result="$1"
  printf 'SMOKE_SUMMARY result=%s passed=%d expected=%d target=%s\n' \
    "$result" "$PASSED_CHECKS" "$EXPECTED_CHECKS" "$TARGET_LABEL"
}

configuration_error() {
  local reason="$1"
  printf 'SMOKE_CHECK name=configuration result=fail reason=%s\n' "$reason"
  summary fail
  exit 2
}

pass_check() {
  local name="$1"
  shift
  PASSED_CHECKS=$((PASSED_CHECKS + 1))
  printf 'SMOKE_CHECK name=%s result=pass' "$name"
  if (($#)); then
    printf ' %s' "$@"
  fi
  printf '\n'
}

fail_check() {
  local name="$1" reason="$2"
  shift 2
  printf 'SMOKE_CHECK name=%s result=fail reason=%s' "$name" "$reason"
  if (($#)); then
    printf ' %s' "$@"
  fi
  printf '\n'
  summary fail
  exit 1
}

valid_https_origin() {
  [[ "$1" =~ ^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?/?$ ]]
}

valid_http_origin() {
  [[ "$1" =~ ^http://[A-Za-z0-9.-]+(:[0-9]{1,5})?/?$ ]]
}

origin_host() {
  local authority="${1#*://}"
  authority="${authority%/}"
  printf '%s' "${authority%%:*}"
}

valid_origin_port() {
  local authority="${1#*://}" port
  authority="${authority%/}"
  [[ "$authority" == *:* ]] || return 0
  port="${authority##*:}"
  ((10#$port >= 1 && 10#$port <= 65535))
}

valid_upload_path() {
  local path="$1" segment
  local -a segments=()
  [[ "$path" =~ ^/uploads/[A-Za-z0-9._/-]+$ ]] || return 1
  [[ "$path" != */ && "$path" != *//* && "$path" != *\\* ]] || return 1
  IFS='/' read -r -a segments <<<"$path"
  for segment in "${segments[@]}"; do
    [[ "$segment" != '.' && "$segment" != '..' ]] || return 1
  done
}

safe_http_status() {
  if [[ "${HTTP_CODE:-}" =~ ^[0-9]{3}$ ]]; then
    printf 'http_status=%s' "$HTTP_CODE"
  else
    printf 'http_status=unavailable'
  fi
}

nonempty_download() {
  [[ "${SIZE_DOWNLOAD:-}" =~ ^[0-9]+$ && "$SIZE_DOWNLOAD" != 0 ]]
}

# Populates HTTP_CODE, CONTENT_TYPE, REDIRECT_URL, SSL_VERIFY_RESULT and
# SIZE_DOWNLOAD. Curl diagnostics and bodies stay suppressed;
# callers emit only stable, allowlisted result fields.
request_metadata() {
  local url="$1" scheme="$2" body_file="$3" header_file="$4" max_time="$5"
  shift 5
  local metadata
  local -a curl_args=(
    --silent
    --request GET
    --connect-timeout "$CONNECT_TIMEOUT"
    --max-time "$max_time"
    --proto "=$scheme"
    --user-agent 'yoshop-production-smoke/1.0'
    --output "$body_file"
    --dump-header "$header_file"
    --write-out $'%{http_code}\n%{content_type}\n%{redirect_url}\n%{ssl_verify_result}\n%{size_download}'
  )
  if [[ "$scheme" == https ]]; then
    curl_args+=(--tlsv1.2)
    if [[ -n "$CA_CERT" ]]; then
      curl_args+=(--cacert "$CA_CERT")
    fi
  fi
  if (($#)); then
    curl_args+=("$@")
  fi

  # --disable must be curl's first option so ~/.curlrc cannot enable verbose or
  # insecure behavior and accidentally weaken the certificate/body contract.
  if ! metadata="$(curl --disable "${curl_args[@]}" "$url" 2>/dev/null)"; then
    return 1
  fi

  local -a fields=()
  mapfile -t fields <<<"$metadata"
  HTTP_CODE="${fields[0]:-}"
  CONTENT_TYPE="${fields[1]:-}"
  REDIRECT_URL="${fields[2]:-}"
  SSL_VERIFY_RESULT="${fields[3]:-}"
  SIZE_DOWNLOAD="${fields[4]:-}"
  [[ "$HTTP_CODE" =~ ^[0-9]{3}$ ]]
}

while (($#)); do
  case "$1" in
    --base-url)
      (($# >= 2)) || configuration_error missing_option_value
      ((BASE_URL_SET == 0)) || configuration_error duplicate_option
      BASE_URL="$2"
      BASE_URL_SET=1
      shift 2
      ;;
    --http-url)
      (($# >= 2)) || configuration_error missing_option_value
      ((HTTP_URL_SET == 0)) || configuration_error duplicate_option
      HTTP_URL="$2"
      HTTP_URL_SET=1
      shift 2
      ;;
    --upload-path)
      (($# >= 2)) || configuration_error missing_option_value
      [[ -z "$UPLOAD_PATH" ]] || configuration_error duplicate_option
      UPLOAD_PATH="$2"
      shift 2
      ;;
    --cacert)
      (($# >= 2)) || configuration_error missing_option_value
      ((CA_CERT_SET == 0)) || configuration_error duplicate_option
      CA_CERT="$2"
      CA_CERT_SET=1
      shift 2
      ;;
    --non-production-test)
      ((NON_PRODUCTION_TEST == 0)) || configuration_error duplicate_option
      NON_PRODUCTION_TEST=1
      TARGET_LABEL='non_production_test'
      shift
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      configuration_error unknown_option
      ;;
  esac
done

valid_https_origin "$BASE_URL" || configuration_error invalid_base_url
valid_origin_port "$BASE_URL" || configuration_error invalid_base_url
BASE_URL="${BASE_URL%/}"

if ((NON_PRODUCTION_TEST)); then
  ((BASE_URL_SET == 1)) || configuration_error test_mode_requires_explicit_target
  [[ "$BASE_URL" != "$DEFAULT_BASE_URL" ]] || configuration_error test_mode_forbids_production_target
else
  [[ "$BASE_URL" == "$DEFAULT_BASE_URL" ]] || configuration_error non_production_mode_required
  ((HTTP_URL_SET == 0)) || configuration_error production_http_override_forbidden
  ((CA_CERT_SET == 0)) || configuration_error production_cacert_override_forbidden
fi

if [[ -z "$HTTP_URL" ]]; then
  HTTP_URL="http://${BASE_URL#https://}"
fi
valid_http_origin "$HTTP_URL" || configuration_error invalid_http_url
valid_origin_port "$HTTP_URL" || configuration_error invalid_http_url
HTTP_URL="${HTTP_URL%/}"
[[ "$(origin_host "$BASE_URL")" == "$(origin_host "$HTTP_URL")" ]] || \
  configuration_error redirect_hosts_must_match

[[ -n "$UPLOAD_PATH" ]] || configuration_error upload_path_required
valid_upload_path "$UPLOAD_PATH" || configuration_error invalid_upload_path
if [[ -n "$CA_CERT" ]]; then
  [[ -f "$CA_CERT" && -r "$CA_CERT" ]] || configuration_error invalid_cacert
fi

command -v curl >/dev/null 2>&1 || configuration_error curl_unavailable
command -v python3 >/dev/null 2>&1 || configuration_error python3_unavailable

TMP_DIR="$(mktemp -d)" || configuration_error temporary_directory_failed
trap 'rm -rf -- "$TMP_DIR"' EXIT

# HTTP is probed without following redirects so the permanent B -> HTTPS policy
# is checked independently from TLS and application responses.
if ! request_metadata "$HTTP_URL/" http /dev/null /dev/null "$REQUEST_TIMEOUT"; then
  fail_check http_redirect transport_failure
fi
if [[ "$HTTP_CODE" != 301 && "$HTTP_CODE" != 308 ]]; then
  fail_check http_redirect unexpected_status "$(safe_http_status)"
fi
if [[ "$REDIRECT_URL" != "$BASE_URL/" ]]; then
  fail_check http_redirect unexpected_location "$(safe_http_status)"
fi
pass_check http_redirect "$(safe_http_status)"

# A normal verified GET checks trust chain, expiry, hostname and TLS transport.
# No --insecure path exists; a custom CA is test-mode-only.
if ! request_metadata "$BASE_URL/" https /dev/null /dev/null "$REQUEST_TIMEOUT"; then
  fail_check tls_certificate transport_or_certificate_failure
fi
if [[ "$SSL_VERIFY_RESULT" != 0 ]]; then
  fail_check tls_certificate certificate_verification_failed
fi
pass_check tls_certificate verify=ok

if ! request_metadata "$BASE_URL/healthz" https \
  "$TMP_DIR/health.body" "$TMP_DIR/health.headers" "$REQUEST_TIMEOUT"; then
  fail_check healthz transport_failure
fi
[[ "$HTTP_CODE" == 200 ]] || fail_check healthz unexpected_status "$(safe_http_status)"
[[ "$CONTENT_TYPE" == text/plain* ]] || fail_check healthz unexpected_content_type "$(safe_http_status)"
cmp -s "$TMP_DIR/health.body" <(printf 'ok\n') || fail_check healthz unexpected_body "$(safe_http_status)"
if ! tr -d '\r' <"$TMP_DIR/health.headers" | grep -Eiq '^cache-control:.*no-store'; then
  fail_check healthz cache_policy_missing "$(safe_http_status)"
fi
pass_check healthz "$(safe_http_status)" cache=no-store

check_shell() {
  local name="$1" url="$2" body_file="$TMP_DIR/$1.body"
  if ! request_metadata "$url" https "$body_file" /dev/null "$REQUEST_TIMEOUT"; then
    fail_check "$name" transport_failure
  fi
  [[ "$HTTP_CODE" == 200 ]] || fail_check "$name" unexpected_status "$(safe_http_status)"
  [[ "$CONTENT_TYPE" == text/html* ]] || fail_check "$name" unexpected_content_type "$(safe_http_status)"
  nonempty_download || fail_check "$name" empty_response "$(safe_http_status)"
  grep -Eiq "<(div|uni-app)[^>]*id=[\"']app[\"']" "$body_file" || \
    fail_check "$name" app_shell_marker_missing "$(safe_http_status)"
  grep -Eiq '<script([[:space:]>])' "$body_file" || \
    fail_check "$name" app_shell_script_missing "$(safe_http_status)"
  pass_check "$name" "$(safe_http_status)"
}

check_shell h5_root "$BASE_URL/"

if ! request_metadata "$BASE_URL/index.php?s=/api/" https /dev/null /dev/null "$REQUEST_TIMEOUT"; then
  fail_check api_entry transport_failure
fi
[[ "$HTTP_CODE" == 200 ]] || fail_check api_entry unexpected_status "$(safe_http_status)"
nonempty_download || fail_check api_entry empty_response "$(safe_http_status)"
pass_check api_entry "$(safe_http_status)"

if ! request_metadata "$BASE_URL/index.php?s=/api/page/detail" https \
  "$TMP_DIR/page-detail.body" /dev/null "$REQUEST_TIMEOUT"; then
  fail_check page_detail transport_failure
fi
[[ "$HTTP_CODE" == 200 ]] || fail_check page_detail unexpected_status "$(safe_http_status)"
[[ "$CONTENT_TYPE" == application/json* ]] || \
  fail_check page_detail unexpected_content_type "$(safe_http_status)"
if ! python3 - "$TMP_DIR/page-detail.body" >/dev/null 2>&1 <<'PY'
import json
import sys

try:
    with open(sys.argv[1], "rb") as stream:
        payload = json.load(stream)
    page_data = payload["data"]["pageData"]
    valid = (
        payload.get("status") == 200
        and isinstance(page_data, dict)
        and isinstance(page_data.get("page"), dict)
        and isinstance(page_data.get("items"), list)
    )
except (KeyError, OSError, TypeError, ValueError):
    valid = False
raise SystemExit(0 if valid else 1)
PY
then
  fail_check page_detail invalid_response_contract "$(safe_http_status)"
fi
pass_check page_detail "$(safe_http_status)" contract=page_data

check_shell admin_shell "$BASE_URL/admin/"
check_shell store_shell "$BASE_URL/store/"

if ! request_metadata "$BASE_URL$UPLOAD_PATH" https /dev/null /dev/null "$REQUEST_TIMEOUT" \
  --range 0-0; then
  fail_check referenced_upload transport_failure
fi
if [[ "$HTTP_CODE" != 200 && "$HTTP_CODE" != 206 ]]; then
  fail_check referenced_upload unexpected_status "$(safe_http_status)"
fi
[[ "$CONTENT_TYPE" == image/* ]] || \
  fail_check referenced_upload unexpected_content_type "$(safe_http_status)"
nonempty_download || fail_check referenced_upload empty_response "$(safe_http_status)"
pass_check referenced_upload "$(safe_http_status)"

# This intentionally sends no signature, query string, request body or valid
# payment callback. The controller's missing-auth path is expected to return
# HTTP 200; its body is ignored because this check must not make a business
# decision or mutate callback state.
if ! request_metadata "$BASE_URL/notify/virtualPayment" https /dev/null /dev/null \
  "$CALLBACK_TIMEOUT"; then
  fail_check callback_reachability transport_failure
fi
[[ "$HTTP_CODE" == 200 ]] || \
  fail_check callback_reachability unexpected_status "$(safe_http_status)"
pass_check callback_reachability "$(safe_http_status)" method=GET signed=false

summary pass
