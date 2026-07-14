#!/usr/bin/env python3
"""Deterministic local build and guarded Tencent production deployment."""
from __future__ import annotations

import argparse
import contextlib
import datetime as dt
import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
from typing import Any, Iterable, Iterator, Sequence

ROOT = Path(__file__).resolve().parents[1]
DEPLOY_DIR = ROOT / "deploy"
CONFIG_PATH = DEPLOY_DIR / "config.json"
OUT_DIR = DEPLOY_DIR / "out"
REPORT_DIR = DEPLOY_DIR / "reports"
FORBIDDEN_TRACKED = (
    "yoshop2.0-uniapp/.vite/",
    "yoshop2.0-uniapp/unpackage/",
    "yoshop2.0/public/admin/",
    "yoshop2.0/public/store/",
    "yoshop2.0/public/assets/",
    "yoshop2.0/public/uploads/",
    "yoshop2.0/public/index.html",
    "yoshop2.0/public/config.js",
)
TEXT_SUFFIXES = {
    ".css", ".html", ".js", ".json", ".mjs", ".php", ".txt", ".xml",
    ".yaml", ".yml", ".md", ".ini", ".conf", ".sql",
}
SECRET_SUFFIXES = {".key", ".p12", ".pfx", ".jks", ".keystore"}
PRIVATE_KEY_MARKER = re.compile(rb"(?m)^-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----\r?\n[A-Za-z0-9+/=\r\n]{64,}^-----END (?:RSA |EC |OPENSSH )?PRIVATE KEY-----$")
RELEASE_ID_PATTERN = re.compile(r"[0-9]{14}-[0-9a-f]{12}")
DEPLOY_CONFIRMATION = "DEPLOY-wx.gxwqb.cn"
PREPARE_CONFIRMATION = "PREPARE-wx.gxwqb.cn"
ACTIVATE_CONFIRMATION = "ACTIVATE-wx.gxwqb.cn"
ROLLBACK_CONFIRMATION = "ROLLBACK-wx.gxwqb.cn"


class DeployError(RuntimeError):
    pass


def load_config(path: Path = CONFIG_PATH) -> dict[str, Any]:
    data = json.loads(path.read_text(encoding="utf-8"))
    required = {
        "production_domain", "development_domain", "ssh_host", "ssh_user",
        "ssh_port", "remote_root", "remote_release_command", "health_url",
    }
    missing = sorted(required - data.keys())
    if missing:
        raise DeployError(f"Missing config keys: {', '.join(missing)}")
    return data


def run(args: Sequence[str], *, cwd: Path = ROOT, env: dict[str, str] | None = None,
        capture: bool = False, check: bool = True) -> subprocess.CompletedProcess[str]:
    printable = " ".join(str(item) for item in args)
    print(f"+ {printable}", file=sys.stderr)
    merged = os.environ.copy()
    if env:
        merged.update(env)
    return subprocess.run(
        [str(item) for item in args], cwd=cwd, env=merged, check=check,
        text=True, stdout=subprocess.PIPE if capture else sys.stderr,
        stderr=subprocess.PIPE if capture else None,
    )


def git(*args: str) -> str:
    return run(("git", *args), capture=True).stdout.strip()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def path_is_forbidden(path: str) -> bool:
    if path == "yoshop2.0/public/uploads/.gitignore":
        return False
    return any(path == prefix or path.startswith(prefix) for prefix in FORBIDDEN_TRACKED)


def preflight(*, fetch: bool = False, require_clean: bool = True) -> dict[str, Any]:
    if fetch:
        run(("git", "fetch", "--quiet", "origin", "main"))
    branch = git("branch", "--show-current")
    head = git("rev-parse", "HEAD")
    origin = git("rev-parse", "refs/remotes/origin/main")
    status = git("status", "--porcelain=v1", "--untracked-files=normal")
    tracked = git("ls-tree", "-r", "--name-only", "HEAD").splitlines()
    forbidden = sorted(path for path in tracked if path_is_forbidden(path))
    problems: list[str] = []
    if branch != "main":
        problems.append(f"current branch is {branch!r}, expected 'main'")
    if require_clean and status:
        problems.append("working tree/index is not clean")
    if head != origin:
        problems.append("HEAD does not equal origin/main; commit and push first")
    if forbidden:
        problems.append(f"HEAD still tracks {len(forbidden)} generated/private paths")
    result = {
        "ok": not problems,
        "branch": branch,
        "head": head,
        "origin_main": origin,
        "dirty": bool(status),
        "forbidden_tracked": forbidden,
        "problems": problems,
    }
    if problems:
        raise DeployError("Preflight refused:\n- " + "\n- ".join(problems))
    return result


@contextlib.contextmanager
def temporary_api_url(config_path: Path, target_url: str) -> Iterator[None]:
    original = config_path.read_text(encoding="utf-8")
    pattern = re.compile(r'(apiUrl:\s*["\'])(.*?)(["\'])')
    if not pattern.search(original):
        raise DeployError(f"Cannot locate apiUrl in {config_path}")
    normalized = target_url.rstrip("/") + "/"
    config_path.write_text(pattern.sub(rf"\g<1>{normalized}\g<3>", original, count=1), encoding="utf-8")
    try:
        yield
    finally:
        config_path.write_text(original, encoding="utf-8")


def build_frontends(config: dict[str, Any]) -> None:
    node_env = {"NODE_OPTIONS": "--openssl-legacy-provider"}
    admin = ROOT / "yoshop2.0-admin"
    store = ROOT / "yoshop2.0-store"
    uniapp = ROOT / "yoshop2.0-uniapp"
    for directory in (admin / "dist", store / "dist", uniapp / "dist"):
        shutil.rmtree(directory, ignore_errors=True)
    run((str(admin / "node_modules/.bin/vue-cli-service"), "build"), cwd=admin, env=node_env)
    run((str(store / "node_modules/.bin/vue-cli-service"), "build"), cwd=store, env=node_env)
    with temporary_api_url(uniapp / "config.js", config["production_domain"] + "/index.php?s=/api/"):
        run(("npm", "run", "build:h5"), cwd=uniapp)


def extract_git_tree(destination: Path) -> None:
    archive = subprocess.Popen(
        ["git", "archive", "--format=tar", "HEAD", "yoshop2.0"],
        cwd=ROOT, stdout=subprocess.PIPE,
    )
    assert archive.stdout is not None
    extract = subprocess.run(("tar", "-xf", "-", "-C", str(destination)), stdin=archive.stdout)
    archive.stdout.close()
    archive_code = archive.wait()
    if archive_code or extract.returncode:
        raise DeployError("Failed to extract committed backend tree")


def copy_tree(source: Path, destination: Path) -> None:
    if not source.is_dir():
        raise DeployError(f"Required build output is missing: {source}")
    shutil.rmtree(destination, ignore_errors=True)
    shutil.copytree(source, destination, symlinks=True)


def scan_secrets(root: Path) -> list[str]:
    findings: list[str] = []
    for path in sorted(root.rglob("*")):
        if not path.is_file():
            continue
        relative = path.relative_to(root).as_posix()
        name = path.name.lower()
        suffix = path.suffix.lower()
        if name == ".env" or (name.startswith(".env.") and name not in {".env.example", ".env.tpl"}):
            findings.append(f"environment file: {relative}")
        if suffix in SECRET_SUFFIXES:
            findings.append(f"secret-key extension: {relative}")
        if path.stat().st_size <= 8 * 1024 * 1024 and PRIVATE_KEY_MARKER.search(path.read_bytes()):
            findings.append(f"private-key content: {relative}")
    return findings


def scan_web_domains(public: Path, production: str, development: str) -> list[str]:
    findings: list[str] = []
    expected = production.rstrip("/")
    forbidden = development.rstrip("/")
    expected_seen = False
    for path in sorted(public.rglob("*")):
        if not path.is_file() or path.suffix.lower() not in TEXT_SUFFIXES:
            continue
        try:
            text = path.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        relative = path.relative_to(public).as_posix()
        if expected in text:
            expected_seen = True
        if forbidden in text:
            findings.append(f"development domain in public artifact: {relative}")
    if not expected_seen:
        findings.append("production domain is absent from all public artifacts")
    config_js = public / "config.js"
    if not config_js.is_file() or expected not in config_js.read_text(encoding="utf-8", errors="ignore"):
        findings.append("H5 public/config.js does not contain production domain")
    return findings


def build_manifest(stage: Path, release_id: str, commit: str) -> dict[str, Any]:
    files = []
    for path in sorted(stage.rglob("*")):
        if path.is_file() and not path.is_symlink():
            files.append({
                "path": path.relative_to(stage).as_posix(),
                "size": path.stat().st_size,
                "sha256": sha256_file(path),
            })
    return {
        "schema": 1,
        "release_id": release_id,
        "git_commit": commit,
        "file_count": len(files),
        "files": files,
    }


def reproducible_tar(source: Path, destination: Path, mtime: int) -> None:
    with destination.open("wb") as raw:
        with gzip.GzipFile(filename="", mode="wb", fileobj=raw, mtime=0) as zipped:
            with tarfile.open(fileobj=zipped, mode="w") as archive:
                for path in sorted(source.rglob("*")):
                    relative = path.relative_to(source)
                    info = archive.gettarinfo(str(path), arcname=relative.as_posix())
                    info.uid = info.gid = 0
                    info.uname = info.gname = "root"
                    info.mtime = mtime
                    if path.is_file() and not path.is_symlink():
                        with path.open("rb") as handle:
                            archive.addfile(info, handle)
                    else:
                        archive.addfile(info)


def write_report(command: str, payload: dict[str, Any]) -> Path:
    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    stamp = dt.datetime.now(dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    path = REPORT_DIR / f"{stamp}-{command}.json"
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return path


def build_release(config: dict[str, Any], *, fetch: bool = False) -> dict[str, Any]:
    state = preflight(fetch=fetch)
    build_frontends(config)
    commit = state["head"]
    commit_time = int(git("show", "-s", "--format=%ct", commit))
    release_id = dt.datetime.fromtimestamp(commit_time, dt.timezone.utc).strftime("%Y%m%d%H%M%S") + f"-{commit[:12]}"
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    package = OUT_DIR / f"yoshop-{release_id}.tar.gz"
    with tempfile.TemporaryDirectory(prefix="yoshop-release-") as temporary:
        stage = Path(temporary) / "stage"
        stage.mkdir()
        extract_git_tree(stage)
        backend = stage / "yoshop2.0"
        for private in (backend / ".env", backend / "runtime", backend / "public/uploads", backend / "data/payment"):
            if private.is_dir():
                shutil.rmtree(private)
            elif private.exists():
                private.unlink()
        public = backend / "public"
        for generated in (public / "admin", public / "store", public / "assets"):
            shutil.rmtree(generated, ignore_errors=True)
        for generated_file in (public / "index.html", public / "config.js"):
            generated_file.unlink(missing_ok=True)
        copy_tree(ROOT / "yoshop2.0-admin/dist", public / "admin")
        copy_tree(ROOT / "yoshop2.0-store/dist", public / "store")
        h5 = ROOT / "yoshop2.0-uniapp/dist/build/h5"
        copy_tree(h5 / "assets", public / "assets")
        shutil.copy2(h5 / "index.html", public / "index.html")
        shutil.copy2(h5 / "config.js", public / "config.js")

        # Composer runs locally in the staged release; production never downloads dependencies.
        run((
            "composer", "install", "--working-dir", str(backend), "--no-dev",
            "--prefer-dist", "--no-interaction", "--optimize-autoloader",
        ))
        migrations = stage / "migrations"
        migrations.mkdir()
        source_migrations = DEPLOY_DIR / "migrations"
        if source_migrations.is_dir():
            for sql in sorted(source_migrations.glob("*.sql")):
                shutil.copy2(sql, migrations / sql.name)

        secrets = scan_secrets(stage)
        domains = scan_web_domains(public, config["production_domain"], config["development_domain"])
        if secrets or domains:
            raise DeployError("Release scan refused:\n- " + "\n- ".join(secrets + domains))
        manifest = build_manifest(stage, release_id, commit)
        (stage / "release-manifest.json").write_text(
            json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
        )
        reproducible_tar(stage, package, commit_time)

    digest = sha256_file(package)
    checksum = package.with_suffix(package.suffix + ".sha256")
    checksum.write_text(f"{digest}  {package.name}\n", encoding="ascii")
    result = {
        "ok": True,
        "release_id": release_id,
        "commit": commit,
        "package": str(package),
        "sha256": digest,
        "bytes": package.stat().st_size,
        "file_count": manifest["file_count"],
    }
    result["report"] = str(write_report("build", result))
    return result


def ssh_target(config: dict[str, Any]) -> str:
    return f"{config['ssh_user']}@{config['ssh_host']}"


def ssh_base(config: dict[str, Any]) -> list[str]:
    return ["ssh", "-p", str(config["ssh_port"]), "-o", "BatchMode=yes", ssh_target(config)]


def require_confirmation(*, dry_run: bool, confirmation: str, expected: str) -> None:
    if not dry_run and confirmation != expected:
        raise DeployError(
            f"Production authorization missing; pass --confirm-production {expected}"
        )


def release_package_metadata(package: Path) -> tuple[str, str, Path]:
    if not package.is_file():
        raise DeployError(f"Package not found: {package}")
    checksum_path = package.with_suffix(package.suffix + ".sha256")
    if not checksum_path.is_file():
        raise DeployError(f"Checksum not found: {checksum_path}")
    digest = sha256_file(package)
    try:
        expected_digest = checksum_path.read_text(encoding="ascii").split()[0]
    except (OSError, IndexError, UnicodeError) as exc:
        raise DeployError(f"Invalid checksum file: {checksum_path}") from exc
    if digest != expected_digest:
        raise DeployError("Local release checksum mismatch")
    match = re.fullmatch(rf"yoshop-({RELEASE_ID_PATTERN.pattern})\.tar\.gz", package.name)
    if not match:
        raise DeployError("Package filename does not contain a valid release id")
    return match.group(1), digest, checksum_path


def parse_remote_payload(stdout: str, action: str) -> dict[str, Any]:
    try:
        payload = json.loads(stdout)
    except json.JSONDecodeError as exc:
        raise DeployError(f"Remote {action} returned non-JSON stdout") from exc
    if not isinstance(payload, dict) or not isinstance(payload.get("ok"), bool):
        raise DeployError(f"Remote {action} returned an invalid JSON status object")
    return payload


def transfer_release(
    config: dict[str, Any],
    package: Path,
    *,
    remote_action_name: str,
    report_name: str,
    dry_run: bool,
    confirmation: str,
    expected_confirmation: str,
) -> dict[str, Any]:
    # The authorization check deliberately precedes filesystem and network work.
    require_confirmation(
        dry_run=dry_run, confirmation=confirmation, expected=expected_confirmation
    )
    release_id, digest, checksum_path = release_package_metadata(package)
    remote_incoming = config["remote_root"].rstrip("/") + "/incoming/"
    rsync = [
        "rsync", "-az", "--protect-args", "-e",
        f"ssh -p {config['ssh_port']} -o BatchMode=yes",
        str(package), str(checksum_path), f"{ssh_target(config)}:{remote_incoming}",
    ]
    remote = ssh_base(config) + [
        "sudo", config["remote_release_command"], remote_action_name, release_id,
        package.name, digest,
    ]
    if dry_run:
        return {
            "ok": True, "dry_run": True, "release_id": release_id,
            "sha256": digest, "rsync": rsync, "remote": remote,
        }
    run(rsync)
    completed = run(remote, capture=True)
    remote_status = parse_remote_payload(completed.stdout, remote_action_name)
    result = {
        "ok": remote_status["ok"],
        "dry_run": False,
        "release_id": release_id,
        "sha256": digest,
        "remote_status": remote_status,
    }
    result["report"] = str(write_report(report_name, result))
    return result


def deploy_release(
    config: dict[str, Any], package: Path, *, dry_run: bool, confirmation: str
) -> dict[str, Any]:
    """Routine compatibility path: upload, prepare, activate, and health-check."""
    return transfer_release(
        config, package, remote_action_name="install", report_name="deploy",
        dry_run=dry_run, confirmation=confirmation,
        expected_confirmation=DEPLOY_CONFIRMATION,
    )


def prepare_candidate(
    config: dict[str, Any], package: Path, *, dry_run: bool, confirmation: str
) -> dict[str, Any]:
    """Upload and verify an immutable candidate without switching or restarting."""
    return transfer_release(
        config, package, remote_action_name="prepare", report_name="prepare",
        dry_run=dry_run, confirmation=confirmation,
        expected_confirmation=PREPARE_CONFIRMATION,
    )


def run_remote_status(
    config: dict[str, Any], action: str, *arguments: str
) -> dict[str, Any]:
    completed = run(
        ssh_base(config)
        + ["sudo", config["remote_release_command"], action, *arguments],
        capture=True,
    )
    return parse_remote_payload(completed.stdout, action)


def activate_candidate(
    config: dict[str, Any], release_id: str, *, dry_run: bool, confirmation: str
) -> dict[str, Any]:
    require_confirmation(
        dry_run=dry_run, confirmation=confirmation, expected=ACTIVATE_CONFIRMATION
    )
    if not RELEASE_ID_PATTERN.fullmatch(release_id):
        raise DeployError("Invalid release id")
    remote = ssh_base(config) + [
        "sudo", config["remote_release_command"], "activate", release_id,
    ]
    if dry_run:
        return {"ok": True, "dry_run": True, "release_id": release_id, "remote": remote}
    payload = run_remote_status(config, "activate", release_id)
    result = {
        "ok": payload["ok"], "dry_run": False, "release_id": release_id,
        "remote_status": payload,
    }
    result["report"] = str(write_report("activate", result))
    return result


def remote_action(
    config: dict[str, Any], action: str, *, confirmation: str = ""
) -> dict[str, Any]:
    if action == "rollback":
        require_confirmation(
            dry_run=False, confirmation=confirmation, expected=ROLLBACK_CONFIRMATION
        )
    payload = run_remote_status(config, action)
    result = dict(payload)
    result["report"] = str(write_report(action, result))
    return result


def parser() -> argparse.ArgumentParser:
    root = argparse.ArgumentParser(description=__doc__)
    sub = root.add_subparsers(dest="command", required=True)
    check = sub.add_parser("preflight", help="verify clean, pushed main and tracking policy")
    check.add_argument("--fetch", action="store_true")
    build = sub.add_parser("build", help="build and package a production release locally")
    build.add_argument("--fetch", action="store_true")
    deploy = sub.add_parser("deploy", help="routine upload, prepare, activate, and health check")
    deploy.add_argument("package", type=Path)
    deploy.add_argument("--dry-run", action="store_true")
    deploy.add_argument("--confirm-production", default="")
    prepare = sub.add_parser("prepare", help="upload and verify without switching current")
    prepare.add_argument("package", type=Path)
    prepare.add_argument("--dry-run", action="store_true")
    prepare.add_argument("--confirm-production", default="")
    activate = sub.add_parser("activate", help="activate one prepared release with health checks")
    activate.add_argument("release_id")
    activate.add_argument("--dry-run", action="store_true")
    activate.add_argument("--confirm-production", default="")
    release = sub.add_parser("release", help="preflight, build and deploy in one command")
    release.add_argument("--fetch", action="store_true")
    release.add_argument("--dry-run", action="store_true")
    release.add_argument("--confirm-production", default="")
    sub.add_parser("status", help="show remote release status")
    rollback = sub.add_parser("rollback", help="atomically return to previous code release")
    rollback.add_argument("--confirm-production", default="")
    return root


def main(argv: Sequence[str] | None = None) -> int:
    args = parser().parse_args(argv)
    try:
        config = load_config()
        if args.command == "preflight":
            result = preflight(fetch=args.fetch)
        elif args.command == "build":
            result = build_release(config, fetch=args.fetch)
        elif args.command == "deploy":
            result = deploy_release(config, args.package.resolve(), dry_run=args.dry_run,
                                    confirmation=args.confirm_production)
        elif args.command == "prepare":
            result = prepare_candidate(
                config, args.package.resolve(), dry_run=args.dry_run,
                confirmation=args.confirm_production,
            )
        elif args.command == "activate":
            result = activate_candidate(
                config, args.release_id, dry_run=args.dry_run,
                confirmation=args.confirm_production,
            )
        elif args.command == "release":
            built = build_release(config, fetch=args.fetch)
            result = deploy_release(config, Path(built["package"]), dry_run=args.dry_run,
                                    confirmation=args.confirm_production)
            result["build"] = built
        elif args.command == "status":
            result = remote_action(config, "status")
        elif args.command == "rollback":
            result = remote_action(config, "rollback", confirmation=args.confirm_production)
        else:
            raise DeployError(f"Unknown command: {args.command}")
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0
    except (DeployError, subprocess.CalledProcessError, OSError) as exc:
        print(json.dumps({"ok": False, "error": str(exc)}, ensure_ascii=False, indent=2), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
