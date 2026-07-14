#!/usr/bin/env bash
set -euo pipefail
IFS=$'\n\t'

usage() {
  cat <<'USAGE'
Usage: scripts/rewrite-git-history.sh /absolute/path/to/repo.git --apply

Destructively removes YoShop generated builds and uploads from every ref in an
ISOLATED BARE CLONE. It refuses to run against the development working tree.
The script creates a pre-filter bundle beside the bare repository, then runs
fsck and verifies that forbidden paths no longer exist.

Never point this at /opt/yoshop/.git. Push rewritten history only after explicit
review and authorization, using --force-with-lease rather than plain --force.
USAGE
}

[[ $# -eq 2 && "$2" == "--apply" ]] || { usage >&2; exit 2; }
repo="$1"
[[ "$repo" == /* ]] || { echo 'Repository path must be absolute.' >&2; exit 2; }
repo="${repo%/}"
[[ "$repo" != "/opt/yoshop/.git" ]] || {
  echo 'Refusing to rewrite the active development repository.' >&2
  exit 1
}
[[ -d "$repo" ]] || { echo "Bare repository not found: $repo" >&2; exit 1; }
[[ "$(git -C "$repo" rev-parse --is-bare-repository)" == true ]] || {
  echo 'Refusing to run: target is not a bare repository.' >&2
  exit 1
}
command -v git-filter-repo >/dev/null 2>&1 || {
  echo 'git-filter-repo is required (Ubuntu package: git-filter-repo).' >&2
  exit 1
}
git -C "$repo" show-ref --verify --quiet refs/heads/main || {
  echo 'Target bare repository has no refs/heads/main.' >&2
  exit 1
}

stamp="$(date +%Y%m%d-%H%M%S)"
bundle="${repo%.git}-before-filter-${stamp}.bundle"
git -C "$repo" bundle create "$bundle" --all
git bundle verify "$bundle" >/dev/null
old_head="$(git -C "$repo" rev-parse refs/heads/main)"
old_commits="$(git -C "$repo" rev-list --count refs/heads/main)"
old_bytes="$(du -sb "$repo" | awk '{print $1}')"

# A filename callback is used instead of a broad uploads/ path inversion so the
# current uploads/.gitignore sentinel survives while every historical upload is
# removed. The callback receives byte paths from git-filter-repo.
git -C "$repo" filter-repo --force --filename-callback '
blocked_dirs = (
    b"yoshop2.0-uniapp/.vite/",
    b"yoshop2.0-uniapp/unpackage/",
    b"yoshop2.0/public/admin/",
    b"yoshop2.0/public/store/",
    b"yoshop2.0/public/assets/",
)
blocked_files = {
    b"yoshop2.0/public/index.html",
    b"yoshop2.0/public/config.js",
}
uploads_prefix = b"yoshop2.0/public/uploads/"
uploads_sentinel = uploads_prefix + b".gitignore"
if filename.startswith(blocked_dirs) or filename in blocked_files:
    return None
if filename.startswith(uploads_prefix) and filename != uploads_sentinel:
    return None
return filename
'

# git-filter-repo 2.38 may leave local refs/replace mappings from old to new
# commits. They are useful for debugging but keep rewrite-only refs alive and
# could be pushed accidentally with --mirror. The final backup bundle already
# preserves the pre-filter graph, so remove those mappings before pruning.
mapfile -t replace_refs < <(git -C "$repo" for-each-ref --format='%(refname)' refs/replace/)
for ref in "${replace_refs[@]}"; do
  git -C "$repo" update-ref -d "$ref"
done

if git -C "$repo" for-each-ref --format='%(refname)' refs/replace/ | grep -q .; then
  echo 'Verification failed: rewrite-only replace refs remain.' >&2
  exit 1
fi

git -C "$repo" reflog expire --expire=now --all
git -C "$repo" gc --prune=now --aggressive
git -C "$repo" fsck --full --strict

remaining_paths="$(git -C "$repo" log --all --name-only --pretty=format: | sed '/^$/d')"
if printf '%s\n' "$remaining_paths" | grep -Eq \
  '^(yoshop2\.0-uniapp/(\.vite|unpackage)/|yoshop2\.0/public/(admin|store|assets)/|yoshop2\.0/public/(index\.html|config\.js)$)'; then
  echo 'Verification failed: a forbidden generated path remains.' >&2
  exit 1
fi
if printf '%s\n' "$remaining_paths" | grep '^yoshop2\.0/public/uploads/' | \
  grep -Fvx 'yoshop2.0/public/uploads/.gitignore' >/dev/null; then
  echo 'Verification failed: a historical upload remains.' >&2
  exit 1
fi
git -C "$repo" cat-file -e 'refs/heads/main:yoshop2.0/public/uploads/.gitignore' || {
  echo 'Verification failed: the uploads sentinel is missing from main.' >&2
  exit 1
}

new_head="$(git -C "$repo" rev-parse refs/heads/main)"
new_commits="$(git -C "$repo" rev-list --count refs/heads/main)"
new_bytes="$(du -sb "$repo" | awk '{print $1}')"
cat <<REPORT
History rewrite passed.
Backup bundle:      $bundle
Old main:           $old_head ($old_commits commits, $old_bytes bytes)
New main:           $new_head ($new_commits commits, $new_bytes bytes)

Review the rewritten log and tree before any push. The generated-only deploy
commit may be pruned as empty; valid source commits must remain.
REPORT
