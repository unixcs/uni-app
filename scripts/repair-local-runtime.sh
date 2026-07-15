#!/usr/bin/env bash
set -euo pipefail
IFS=$'\n\t'

usage() {
  cat <<'USAGE'
Usage: scripts/repair-local-runtime.sh [--clear-cache]

Repair the WSL development runtime used by local Nginx/PHP-FPM.

  no flag        Preserve runtime contents and repair ownership/permissions.
  --clear-cache  Remove only regenerable ThinkPHP cache/temp/schema data first.

The script is local-only: it requires a Git working tree, refuses runtime
symlinks (used by production releases), and never touches uploads or .env.
USAGE
}

clear_cache=0
for arg in "$@"; do
  case "$arg" in
    --clear-cache) clear_cache=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $arg" >&2; usage >&2; exit 2 ;;
  esac
done

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(cd -- "${script_dir}/.." && pwd -P)"
runtime="$repo_root/yoshop2.0/runtime"

if [[ ! -d "$repo_root/.git" || ! -f "$repo_root/yoshop2.0/think" ]]; then
  echo "Refusing runtime repair: $repo_root is not a YoShop development working tree." >&2
  exit 1
fi
if [[ -L "$runtime" ]]; then
  echo "Refusing runtime repair: $runtime is a symlink (production-style layout)." >&2
  exit 1
fi
if ! getent passwd www-data >/dev/null; then
  echo 'Refusing runtime repair: local PHP-FPM user www-data does not exist.' >&2
  exit 1
fi
if (( EUID != 0 )); then
  if command -v sudo >/dev/null 2>&1; then
    exec sudo -- "$0" "$@"
  fi
  echo 'Runtime repair requires root; rerun with sudo.' >&2
  exit 1
fi

if (( clear_cache )); then
  rm -rf -- "$runtime/cache" "$runtime/temp" "$runtime/schema"
fi

# PHP-FPM and the local Workerman service both run as www-data. Runtime is
# generated state, so that service account—not the source-code owner—owns it.
install -d -o www-data -g www-data -m 2770 \
  "$runtime" "$runtime/cache" "$runtime/log" "$runtime/temp" "$runtime/schema"
chown -R www-data:www-data "$runtime"

# ThinkPHP stores file-cache entries below one of 256 hash buckets. Creating
# them up front avoids a first-request race and makes permission drift obvious.
for ((i = 0; i < 256; i++)); do
  printf -v bucket '%02x' "$i"
  install -d -o www-data -g www-data -m 2770 "$runtime/cache/$bucket"
done

# Root is commonly used in this WSL checkout. Default ACLs ensure files or
# directories created later by root still remain writable by PHP-FPM.
if command -v setfacl >/dev/null 2>&1; then
  workerman_runtime="$runtime/workerman"
  while IFS= read -r -d '' directory; do
    setfacl -m u:www-data:rwx,m:rwx "$directory"
    setfacl -d -m u::rwx,u:www-data:rwx,g::---,m::rwx,o::--- "$directory"
  done < <(find "$runtime" -path "$workerman_runtime" -prune -o -type d -print0)
  # Timer deliberately keeps its PID/log directory private (0700/0600). It is
  # already owned by www-data and must not inherit the shared cache ACL.
  if [[ -d "$workerman_runtime" ]]; then
    setfacl -Rb "$workerman_runtime"
  fi
else
  echo 'Warning: setfacl is unavailable; current permissions are repaired, but root-created descendants may need this script again.' >&2
fi

# Verify with the real PHP-FPM identity, not merely with root.
probe_dir="$runtime/cache/ff/runtime-permission-probe"
probe_file="$probe_dir/probe.php"
runuser -u www-data -- mkdir -p "$probe_dir"
printf first | runuser -u www-data -- tee "$probe_file" >/dev/null
printf second | runuser -u www-data -- tee "$probe_file" >/dev/null
[[ "$(cat "$probe_file")" == second ]]
rm -rf -- "$probe_dir"

printf 'Local runtime ready: %s (owner www-data:www-data, PHP-FPM write test passed)\n' "$runtime"
