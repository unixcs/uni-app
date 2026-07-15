#!/usr/bin/env bash
set -euo pipefail
IFS=$'\n\t'

usage() {
  cat <<'USAGE'
Usage: scripts/cleanup-local.sh [--apply] [--deep]

Safe local workspace cleanup for YoShop.

  no flag   Dry-run only; prints what would be removed.
  --apply   Remove regenerable caches, logs, runtime data and build output.
  --deep    With --apply, also remove dependency directories. Reinstalling
            dependencies is required afterwards. This never removes PHP vendor.

Protected in every mode: source files, .git, .env files, uploads, payment data,
PHP vendor, database files and the deployed public/ build used by local Nginx.
USAGE
}

apply=0
deep=0
for arg in "$@"; do
  case "$arg" in
    --apply) apply=1 ;;
    --deep) deep=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $arg" >&2; usage >&2; exit 2 ;;
  esac
done

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(cd -- "${script_dir}/.." && pwd -P)"
cd "$repo_root"

if [[ ! -d .git || ! -f yoshop2.0/think || ! -f yoshop2.0-uniapp/package.json ]]; then
  echo "Refusing cleanup: $repo_root is not the expected YoShop repository." >&2
  exit 1
fi

# Exact, regenerable directories only. Deliberately excludes public/, uploads/,
# data/, vendor/ and every .env path so local PHP/Nginx keeps working.
safe_targets=(
  ".cache"
  ".nyc_output"
  "coverage"
  "runtime"
  "tmp"
  "yoshop2.0/runtime"
  "yoshop2.0-admin/dist"
  "yoshop2.0-admin/node_modules/.cache"
  "yoshop2.0-store/dist"
  "yoshop2.0-store/node_modules/.cache"
  "yoshop2.0-uniapp/.vite"
  "yoshop2.0-uniapp/dist"
  "yoshop2.0-uniapp/unpackage"
)

deep_targets=(
  "node_modules"
  ".opencode/node_modules"
  "yoshop2.0-admin/node_modules"
  "yoshop2.0-store/node_modules"
  "yoshop2.0-uniapp/node_modules"
)

protected_prefixes=(
  "$repo_root/.git"
  "$repo_root/yoshop2.0/public"
  "$repo_root/yoshop2.0/data"
  "$repo_root/yoshop2.0/vendor"
)

human_size() {
  du -sh -- "$1" 2>/dev/null | awk '{print $1}' || printf '?'
}

validate_target() {
  local relative="$1" absolute parent_real protected
  [[ "$relative" != /* && "$relative" != *".."* ]] || {
    echo "Refusing unsafe target: $relative" >&2
    return 1
  }
  absolute="$repo_root/$relative"
  if ! parent_real="$(cd -- "$(dirname -- "$absolute")" 2>/dev/null && pwd -P)"; then
    parent_real=""
  fi
  [[ -n "$parent_real" && "$parent_real" == "$repo_root"* ]] || {
    echo "Refusing target outside repository: $relative" >&2
    return 1
  }
  [[ ! -L "$absolute" ]] || {
    echo "Refusing symlink target: $relative" >&2
    return 1
  }
  for protected in "${protected_prefixes[@]}"; do
    [[ "$absolute" != "$protected" && "$absolute" != "$protected/"* ]] || {
      echo "Refusing protected target: $relative" >&2
      return 1
    }
  done
}

remove_target() {
  local relative="$1" size
  [[ -e "$relative" ]] || return 0
  validate_target "$relative"
  size="$(human_size "$relative")"
  if (( apply )); then
    printf 'REMOVE  %-8s %s\n' "$size" "$relative"
    rm -rf -- "$relative"
  else
    printf 'DRY-RUN %-8s %s\n' "$size" "$relative"
  fi
}

printf 'YoShop cleanup root: %s\n' "$repo_root"
if (( apply )); then
  [[ -x "$script_dir/repair-local-runtime.sh" ]] || {
    echo 'Refusing cleanup: runtime repair helper is missing or not executable.' >&2
    exit 1
  }
  echo 'Mode: APPLY'
else
  echo 'Mode: DRY-RUN (nothing will be deleted)'
fi

for target in "${safe_targets[@]}"; do
  remove_target "$target"
done

# Remove individual cache/log/temp files outside the protected application
# data tree. NUL delimiters keep paths with spaces safe.
while IFS= read -r -d '' file; do
  relative="${file#./}"
  validate_target "$relative"
  size="$(human_size "$relative")"
  if (( apply )); then
    printf 'REMOVE  %-8s %s\n' "$size" "$relative"
    rm -f -- "$relative"
  else
    printf 'DRY-RUN %-8s %s\n' "$size" "$relative"
  fi
done < <(find . -xdev \
  -path './.git' -prune -o \
  -path './yoshop2.0/public' -prune -o \
  -path './yoshop2.0/runtime' -prune -o \
  -path './yoshop2.0/data' -prune -o \
  -path './yoshop2.0/vendor' -prune -o \
  -path '*/node_modules' -prune -o \
  -type f \( -name '*.log' -o -name '*.tmp' -o -name '*.temp' -o -name '.eslintcache' \) \
  -print0)

if (( deep )); then
  for target in "${deep_targets[@]}"; do
    remove_target "$target"
  done
fi

if (( apply )); then
  # runtime is generated and was removed above. Recreate it with the actual
  # PHP-FPM identity so local admin/API requests keep working after cleanup.
  "$script_dir/repair-local-runtime.sh"
  echo 'Cleanup complete. Dependencies, private data and local public build were preserved.'
else
  echo 'Dry-run complete. Re-run with --apply after reviewing this list.'
fi
