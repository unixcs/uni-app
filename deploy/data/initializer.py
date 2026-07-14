#!/usr/bin/env python3
"""Generate and validate a sanitized YoShop production initializer package."""

from __future__ import annotations

import argparse
import contextlib
import dataclasses
import datetime as dt
import hashlib
import html
import json
import os
import pathlib
import re
import secrets
import shutil
import stat
import subprocess
import sys
import tempfile
import urllib.parse
from collections.abc import Iterable, Iterator, Mapping, Sequence
from typing import Any

TOOL_ROOT = pathlib.Path(__file__).resolve().parent
DEFAULT_POLICY = TOOL_ROOT / "policy.json"
IDENTIFIER_RE = re.compile(r"^[A-Za-z0-9_$]+$")
TABLE_PREFIX_RE = re.compile(r"^[A-Za-z0-9_$]*$")
UPLOAD_REFERENCE_RE = re.compile(
    r"(?i)(?<![A-Za-z0-9_.-])(?:https?://[^/\s\"'<>]+)?/?uploads/"
    r"([^\s\"'<>?#)\]}]+?)/?(?=[\s\"'<>?#)\]}]|$)"
)
PRIVATE_KEY_RE = re.compile(br"-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----", re.IGNORECASE)
ENV_SECRET_ASSIGNMENT_RE = re.compile(
    br"(?im)^\s*(?:DB_?PASSWORD|PASSWORD|SECRET|APP_?KEY|PRIVATE_?KEY|ACCESS_?KEY|TOKEN)\s*=\s*[^\s#]+"
)
REQUIRED_PACKAGE_FILES = {
    "schema.sql",
    "data.sql",
    "uploads-manifest.json",
    "generation-report.json",
    "SHA256SUMS",
}


class InitializerError(RuntimeError):
    """An expected, safely reportable initializer failure."""


@dataclasses.dataclass(frozen=True)
class ConnectionOptions:
    database: str
    defaults_file: pathlib.Path | None
    host: str | None
    port: int
    user: str | None
    password: str


@dataclasses.dataclass(frozen=True)
class UploadRecord:
    file_id: int
    storage: str
    file_path: str
    file_size: int
    file_name: str
    is_delete: int


@dataclasses.dataclass(frozen=True)
class SelectedUpload:
    record: UploadRecord
    sources: tuple[str, ...]


def utc_now() -> str:
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat()


def quote_identifier(value: str) -> str:
    if not IDENTIFIER_RE.fullmatch(value):
        raise InitializerError(f"Unsafe SQL identifier: {value!r}")
    return f"`{value}`"


def table_name(prefix: str, logical_name: str) -> str:
    if not TABLE_PREFIX_RE.fullmatch(prefix):
        raise InitializerError("Table prefix may contain only letters, numbers, _, and $.")
    quote_identifier(logical_name)
    return prefix + logical_name


def read_json(path: pathlib.Path) -> Any:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise InitializerError(f"Unable to read valid JSON from {path}: {exc}") from exc


def write_json(path: pathlib.Path, value: Any) -> None:
    payload = json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    atomic_write(path, payload.encode("utf-8"), mode=0o600)


def atomic_write(path: pathlib.Path, payload: bytes, mode: int = 0o600) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temp = path.with_name(f".{path.name}.tmp-{secrets.token_hex(4)}")
    try:
        with temp.open("wb") as stream:
            stream.write(payload)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temp, mode)
        os.replace(temp, path)
    finally:
        with contextlib.suppress(FileNotFoundError):
            temp.unlink()


def decode_hex_text(raw: str, source: str) -> str:
    try:
        return bytes.fromhex(raw).decode("utf-8")
    except (ValueError, UnicodeDecodeError) as exc:
        raise InitializerError(f"{source} is not valid UTF-8 text.") from exc


def decode_page_json(raw: str, source: str = "page JSON") -> Any:
    decoded = html.unescape(raw)
    try:
        return json.loads(decoded)
    except json.JSONDecodeError as exc:
        raise InitializerError(
            f"{source} is invalid JSON at line {exc.lineno}, column {exc.colno}."
        ) from exc


def iter_strings(value: Any) -> Iterator[str]:
    if isinstance(value, str):
        yield value
    elif isinstance(value, Mapping):
        for child in value.values():
            yield from iter_strings(child)
    elif isinstance(value, list):
        for child in value:
            yield from iter_strings(child)


def normalize_upload_path(raw: str, source: str = "upload reference") -> str:
    value = html.unescape(raw).replace("\\/", "/").strip()
    for _ in range(3):
        decoded = urllib.parse.unquote(value)
        if decoded == value:
            break
        value = decoded
    value = value.replace("\\", "/")
    if not value or "\x00" in value or value.startswith("/"):
        raise InitializerError(f"{source} contains an unsafe upload path.")
    parts = value.split("/")
    if any(part in {"", ".", ".."} for part in parts):
        raise InitializerError(f"{source} contains path traversal or empty segments.")
    normalized = pathlib.PurePosixPath(*parts).as_posix()
    if normalized.startswith("../") or normalized == "..":
        raise InitializerError(f"{source} contains path traversal.")
    return normalized


def extract_upload_paths(text: str, source: str = "content") -> set[str]:
    canonical = html.unescape(text).replace("\\/", "/")
    paths: set[str] = set()
    for match in UPLOAD_REFERENCE_RE.finditer(canonical):
        paths.add(normalize_upload_path(match.group(1), source))
    return paths


def extract_page_upload_paths(raw: str, source: str = "page JSON") -> set[str]:
    page = decode_page_json(raw, source)
    paths: set[str] = set()
    for value in iter_strings(page):
        paths.update(extract_upload_paths(value, source))
    return paths


def sha256_file(path: pathlib.Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def safe_relative_path(raw: str, source: str = "package path") -> pathlib.PurePosixPath:
    value = raw.replace("\\", "/")
    path = pathlib.PurePosixPath(value)
    if path.is_absolute() or not path.parts or any(part in {"", ".", ".."} for part in path.parts):
        raise InitializerError(f"{source} is unsafe.")
    return path


def ensure_path_below(root: pathlib.Path, relative: str) -> pathlib.Path:
    rel = safe_relative_path(relative, "upload file path")
    root_resolved = root.resolve(strict=True)
    candidate = root_resolved.joinpath(*rel.parts)
    try:
        candidate_resolved = candidate.resolve(strict=True)
    except FileNotFoundError as exc:
        raise InitializerError(f"Referenced upload file does not exist: {rel.as_posix()}") from exc
    try:
        candidate_resolved.relative_to(root_resolved)
    except ValueError as exc:
        raise InitializerError(f"Referenced upload escapes the uploads root: {rel.as_posix()}") from exc
    if candidate.is_symlink() or not candidate_resolved.is_file():
        raise InitializerError(f"Referenced upload is not a regular non-symlink file: {rel.as_posix()}")
    return candidate_resolved


def select_upload_records(
    records: Iterable[UploadRecord],
    direct_sources: Mapping[int, set[str]],
    content_sources: Mapping[str, set[str]],
) -> list[SelectedUpload]:
    by_id: dict[int, UploadRecord] = {}
    by_path: dict[str, UploadRecord] = {}
    for record in records:
        normalized = normalize_upload_path(record.file_path, f"upload_file {record.file_id}")
        if record.file_id in by_id:
            raise InitializerError(f"Duplicate upload file ID {record.file_id}.")
        if normalized in by_path:
            raise InitializerError(f"Duplicate upload file path {normalized}.")
        if normalized != record.file_path:
            record = dataclasses.replace(record, file_path=normalized)
        by_id[record.file_id] = record
        by_path[normalized] = record

    selected_sources: dict[int, set[str]] = {}
    for file_id, sources in direct_sources.items():
        record = by_id.get(file_id)
        if record is None:
            raise InitializerError(f"Safe direct relation references missing upload file ID {file_id}.")
        selected_sources.setdefault(file_id, set()).update(sources)
    for file_path, sources in content_sources.items():
        normalized = normalize_upload_path(file_path, "safe content upload reference")
        record = by_path.get(normalized)
        if record is None:
            raise InitializerError(f"Safe content references upload not present in upload_file: {normalized}")
        selected_sources.setdefault(record.file_id, set()).update(sources)

    selected: list[SelectedUpload] = []
    for file_id in sorted(selected_sources):
        record = by_id[file_id]
        if record.is_delete:
            raise InitializerError(f"Referenced upload file ID {file_id} is marked deleted.")
        if record.storage.lower() != "local":
            raise InitializerError(f"Referenced upload file ID {file_id} is not local storage.")
        selected.append(SelectedUpload(record, tuple(sorted(selected_sources[file_id]))))
    return selected


class MysqlClient:
    def __init__(self, options: ConnectionOptions):
        self.options = options
        self._temporary_defaults: pathlib.Path | None = None
        self._defaults_secrets: list[bytes] = []

    def __enter__(self) -> "MysqlClient":
        if self.options.defaults_file is not None:
            path = self.options.defaults_file.expanduser().resolve(strict=True)
            mode = stat.S_IMODE(path.stat().st_mode)
            if mode & 0o077:
                raise InitializerError("MySQL defaults file must not be group/world accessible.")
            self._defaults_file = path
            try:
                for raw_line in path.read_bytes().splitlines():
                    match = re.match(br"(?i)^\s*password\s*=\s*(.*?)\s*$", raw_line)
                    if match:
                        value = match.group(1).strip().strip(b"\"'")
                        if value:
                            self._defaults_secrets.append(value)
            except OSError as exc:
                raise InitializerError("Unable to inspect the restricted MySQL defaults file.") from exc
        else:
            if not self.options.host or not self.options.user:
                raise InitializerError(
                    "Provide --mysql-defaults-file, or explicit --host and --user parameters."
                )
            fd, raw_path = tempfile.mkstemp(prefix="yoshop-mysql-", suffix=".cnf")
            os.close(fd)
            path = pathlib.Path(raw_path)
            lines = [
                "[client]",
                f"host={self.options.host}",
                f"port={self.options.port}",
                f"user={self.options.user}",
            ]
            if self.options.password:
                lines.append(f"password={self.options.password}")
            atomic_write(path, ("\n".join(lines) + "\n").encode(), mode=0o600)
            self._temporary_defaults = path
            self._defaults_file = path
        return self

    def __exit__(self, *_: object) -> None:
        if self._temporary_defaults:
            with contextlib.suppress(FileNotFoundError):
                self._temporary_defaults.unlink()

    @property
    def secret_values(self) -> list[bytes]:
        values = list(self._defaults_secrets)
        if self.options.password:
            values.append(self.options.password.encode())
        return values

    def _base(self, tool: str) -> list[str]:
        return [tool, f"--defaults-extra-file={self._defaults_file}"]

    def _safe_error(self, tool: str, stderr: bytes) -> InitializerError:
        text = stderr.decode("utf-8", errors="replace")
        secrets_to_hide = [self.options.password, self.options.user or "", self.options.host or ""]
        for value in secrets_to_hide:
            if value:
                text = text.replace(value, "<suppressed>")
        last_line = next((line.strip() for line in reversed(text.splitlines()) if line.strip()), "unknown error")
        return InitializerError(f"{tool} failed: {last_line}")

    def run(
        self,
        tool: str,
        arguments: Sequence[str],
        *,
        database: str | None = None,
        input_bytes: bytes | None = None,
    ) -> bytes:
        command = self._base(tool)
        if database:
            quote_identifier(database)
            command.extend(["--database", database])
        command.extend(arguments)
        try:
            proc = subprocess.run(command, input=input_bytes, capture_output=True, check=False)
        except FileNotFoundError as exc:
            raise InitializerError(f"Required tool is unavailable: {tool}") from exc
        if proc.returncode:
            raise self._safe_error(tool, proc.stderr)
        return proc.stdout

    def query(self, sql: str, *, database: str | None = None) -> list[list[str]]:
        output = self.run(
            "mysql",
            ["--batch", "--raw", "--skip-column-names", "--execute", sql],
            database=database,
        )
        text = output.decode("utf-8", errors="strict")
        return [line.split("\t") for line in text.splitlines() if line]

    def dump_schema(self, database: str) -> bytes:
        return self.run(
            "mysqldump",
            [
                "--no-data",
                "--routines",
                "--events",
                "--triggers",
                "--single-transaction",
                "--skip-lock-tables",
                "--skip-add-locks",
                "--no-tablespaces",
                "--skip-comments",
                "--skip-dump-date",
                database,
            ],
        )

    def dump_table_data(self, database: str, table: str, where: str | None = None) -> bytes:
        arguments = [
            "--no-create-info",
            "--skip-triggers",
            "--single-transaction",
            "--skip-lock-tables",
            "--skip-add-locks",
            "--skip-disable-keys",
            "--complete-insert",
            "--hex-blob",
            "--skip-comments",
            "--skip-dump-date",
        ]
        if where is not None:
            arguments.append(f"--where={where}")
        arguments.extend([database, table])
        return self.run("mysqldump", arguments)


def inspect_schema(client: MysqlClient, database: str, prefix: str, policy: Mapping[str, Any]) -> set[str]:
    rows = client.query(
        "SELECT TABLE_NAME FROM information_schema.TABLES "
        "WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME",
        database=database,
    )
    actual_tables = {row[0] for row in rows}
    required_logical = {
        item["name"] for item in policy["safe_data_tables"]
    } | set(policy["required_empty_tables"]) | {"upload_file", "store_setting"}
    required_logical |= {item["table"] for item in policy["direct_upload_relations"]}
    required_logical |= {item["table"] for item in policy["safe_content_sources"]}
    missing = sorted(table_name(prefix, item) for item in required_logical if table_name(prefix, item) not in actual_tables)
    if missing:
        raise InitializerError("Required source tables are missing: " + ", ".join(missing))
    classified = {table_name(prefix, item) for item in required_logical}
    unclassified = sorted(
        name for name in actual_tables if name.startswith(prefix) and name not in classified
    )
    if unclassified:
        raise InitializerError(
            "Source schema contains unclassified tables; audit policy.json before continuing: "
            + ", ".join(unclassified)
        )

    expected_columns: dict[str, set[str]] = {}
    for item in policy["direct_upload_relations"]:
        expected_columns.setdefault(table_name(prefix, item["table"]), set()).add(item["column"])
    for item in policy["safe_content_sources"]:
        expected_columns.setdefault(table_name(prefix, item["table"]), set()).update(
            [item["primary_key"], item["column"]]
        )
    expected_columns[table_name(prefix, "upload_file")] = {
        "file_id", "storage", "file_path", "file_size", "file_name", "is_delete"
    }
    for physical_table, columns in expected_columns.items():
        rows = client.query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
            f"WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{physical_table}'",
            database=database,
        )
        actual_columns = {row[0] for row in rows}
        missing_columns = sorted(columns - actual_columns)
        if missing_columns:
            raise InitializerError(
                f"Required columns are missing from {physical_table}: {', '.join(missing_columns)}"
            )
    return actual_tables


def collect_direct_upload_sources(
    client: MysqlClient, database: str, prefix: str, policy: Mapping[str, Any]
) -> dict[int, set[str]]:
    result: dict[int, set[str]] = {}
    for item in policy["direct_upload_relations"]:
        physical = table_name(prefix, item["table"])
        column = quote_identifier(item["column"])
        rows = client.query(
            f"SELECT DISTINCT {column} FROM {quote_identifier(physical)} "
            f"WHERE {column} IS NOT NULL AND {column} <> 0 ORDER BY {column}",
            database=database,
        )
        for row in rows:
            try:
                file_id = int(row[0])
            except ValueError as exc:
                raise InitializerError(f"Non-integer upload relation in {physical}.{item['column']}.") from exc
            result.setdefault(file_id, set()).add(f"direct:{physical}.{item['column']}")
    return result


def collect_content_upload_sources(
    client: MysqlClient, database: str, prefix: str, policy: Mapping[str, Any]
) -> tuple[dict[str, set[str]], int]:
    result: dict[str, set[str]] = {}
    parsed_json_rows = 0
    for item in policy["safe_content_sources"]:
        physical = table_name(prefix, item["table"])
        pk = quote_identifier(item["primary_key"])
        column = quote_identifier(item["column"])
        rows = client.query(
            f"SELECT CAST({pk} AS CHAR), HEX({column}) FROM {quote_identifier(physical)} ORDER BY {pk}",
            database=database,
        )
        for row in rows:
            if len(row) != 2:
                raise InitializerError(f"Unexpected content query shape for {physical}.{item['column']}.")
            source = f"{physical}.{item['column']}[{row[0]}]"
            raw = decode_hex_text(row[1], source)
            if item["format"] == "json":
                paths = extract_page_upload_paths(raw, source)
                parsed_json_rows += 1
            elif item["format"] == "text":
                paths = extract_upload_paths(raw, source)
            else:
                raise InitializerError(f"Unsupported safe content format: {item['format']}")
            for path in paths:
                result.setdefault(path, set()).add(f"content:{source}")
    return result, parsed_json_rows


def load_upload_records(client: MysqlClient, database: str, prefix: str) -> list[UploadRecord]:
    physical = table_name(prefix, "upload_file")
    rows = client.query(
        "SELECT file_id,HEX(storage),HEX(file_path),file_size,HEX(file_name),is_delete "
        f"FROM {quote_identifier(physical)} ORDER BY file_id",
        database=database,
    )
    records: list[UploadRecord] = []
    for row in rows:
        if len(row) != 6:
            raise InitializerError("Unexpected upload_file query shape.")
        try:
            records.append(
                UploadRecord(
                    file_id=int(row[0]),
                    storage=decode_hex_text(row[1], f"upload_file {row[0]} storage"),
                    file_path=decode_hex_text(row[2], f"upload_file {row[0]} path"),
                    file_size=int(row[3]),
                    file_name=decode_hex_text(row[4], f"upload_file {row[0]} name"),
                    is_delete=int(row[5]),
                )
            )
        except ValueError as exc:
            raise InitializerError("Invalid numeric upload_file metadata.") from exc
    return records


def copy_selected_uploads(
    uploads_root: pathlib.Path, package_root: pathlib.Path, selected: Sequence[SelectedUpload]
) -> list[dict[str, Any]]:
    manifest_files: list[dict[str, Any]] = []
    for item in selected:
        record = item.record
        source = ensure_path_below(uploads_root, record.file_path)
        actual_size = source.stat().st_size
        package_relative = pathlib.PurePosixPath("uploads", *pathlib.PurePosixPath(record.file_path).parts)
        destination = package_root.joinpath(*package_relative.parts)
        destination.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        shutil.copyfile(source, destination)
        os.chmod(destination, 0o600)
        digest = sha256_file(destination)
        manifest_files.append(
            {
                "database_file_size": record.file_size,
                "database_size_matches": record.file_size == actual_size,
                "file_id": record.file_id,
                "file_name": record.file_name,
                "file_path": record.file_path,
                "package_path": package_relative.as_posix(),
                "sha256": digest,
                "size": actual_size,
                "sources": list(item.sources),
            }
        )
    return manifest_files


def package_regular_files(package_root: pathlib.Path) -> list[pathlib.Path]:
    files: list[pathlib.Path] = []
    for path in package_root.rglob("*"):
        if path.is_symlink():
            raise InitializerError(f"Package contains a symlink: {path.relative_to(package_root)}")
        if path.is_file() and path.name != "SHA256SUMS":
            files.append(path)
    return sorted(files, key=lambda path: path.relative_to(package_root).as_posix())


def write_checksums(package_root: pathlib.Path) -> None:
    lines = [
        f"{sha256_file(path)}  {path.relative_to(package_root).as_posix()}"
        for path in package_regular_files(package_root)
    ]
    atomic_write(package_root / "SHA256SUMS", ("\n".join(lines) + "\n").encode("utf-8"))


def verify_checksums(package_root: pathlib.Path) -> int:
    checksum_path = package_root / "SHA256SUMS"
    try:
        lines = checksum_path.read_text(encoding="utf-8").splitlines()
    except OSError as exc:
        raise InitializerError("SHA256SUMS is missing or unreadable.") from exc
    if not lines:
        raise InitializerError("SHA256SUMS is empty.")
    seen: set[str] = set()
    for line in lines:
        match = re.fullmatch(r"([0-9a-f]{64})  (.+)", line)
        if not match:
            raise InitializerError("SHA256SUMS contains an invalid line.")
        expected, raw_relative = match.groups()
        relative = safe_relative_path(raw_relative, "checksum path").as_posix()
        if relative == "SHA256SUMS" or relative in seen:
            raise InitializerError("SHA256SUMS contains a duplicate or self-reference.")
        seen.add(relative)
        path = package_root.joinpath(*pathlib.PurePosixPath(relative).parts)
        if path.is_symlink() or not path.is_file():
            raise InitializerError(f"Checksummed file is missing or unsafe: {relative}")
        if sha256_file(path) != expected:
            raise InitializerError(f"Checksum mismatch: {relative}")
    actual = {
        path.relative_to(package_root).as_posix() for path in package_regular_files(package_root)
    }
    if actual != seen:
        missing = sorted(actual - seen)
        stale = sorted(seen - actual)
        details = []
        if missing:
            details.append("missing entries: " + ", ".join(missing))
        if stale:
            details.append("stale entries: " + ", ".join(stale))
        raise InitializerError("SHA256SUMS does not cover exactly the package files (" + "; ".join(details) + ").")
    return len(seen)


def scan_public_artifacts(
    package_root: pathlib.Path,
    forbidden_domains: Iterable[str],
    secret_values: Iterable[bytes],
) -> None:
    domain_needles = [(domain, domain.lower().encode()) for domain in forbidden_domains if domain]
    exact_secrets = [value for value in secret_values if len(value) >= 6]
    for path in package_regular_files(package_root):
        payload = path.read_bytes()
        lowered = payload.lower()
        for label, needle in domain_needles:
            if needle in lowered:
                raise InitializerError(
                    f"Forbidden development domain detected in public artifact {path.relative_to(package_root)} ({label})."
                )
        if PRIVATE_KEY_RE.search(payload):
            raise InitializerError(f"Private key material detected in {path.relative_to(package_root)}.")
        if ENV_SECRET_ASSIGNMENT_RE.search(payload):
            raise InitializerError(f"Secret-like environment assignment detected in {path.relative_to(package_root)}.")
        for secret_value in exact_secrets:
            if secret_value in payload:
                raise InitializerError(
                    f"A configured secret value was detected in {path.relative_to(package_root)}."
                )


def assert_no_private_inserts(data_sql: str, prefix: str, policy: Mapping[str, Any]) -> None:
    inserted_tables = {
        match.group(1)
        for match in re.finditer(r"(?i)INSERT\s+INTO\s+`([^`]+)`", data_sql)
    }
    allowed_logical = {item["name"] for item in policy["safe_data_tables"]} | {"upload_file"}
    allowed = {table_name(prefix, name) for name in allowed_logical}
    unexpected = sorted(inserted_tables - allowed)
    if unexpected:
        raise InitializerError(
            "Public data SQL inserts non-allowlisted tables: " + ", ".join(unexpected)
        )
    if re.search(r"(?im)^\s*(?:REPLACE\s+INTO|LOAD\s+DATA|UPDATE\s+`|DELETE\s+FROM)", data_sql):
        raise InitializerError("Public data SQL contains a non-allowlisted data mutation statement.")


def configured_secret_values(names: Sequence[str], connection: MysqlClient) -> list[bytes]:
    values = list(connection.secret_values)
    for name in names:
        value = os.environ.get(name, "")
        if value:
            values.append(value.encode())
    return values


def count_tables(
    client: MysqlClient, database: str, physical_tables: Sequence[str]
) -> dict[str, int]:
    counts: dict[str, int] = {}
    for physical in physical_tables:
        rows = client.query(f"SELECT COUNT(*) FROM {quote_identifier(physical)}", database=database)
        if len(rows) != 1 or len(rows[0]) != 1:
            raise InitializerError(f"Unable to count restored table {physical}.")
        counts[physical] = int(rows[0][0])
    return counts


def reject_git_output(output: pathlib.Path) -> None:
    resolved = output.expanduser().resolve(strict=False)
    probe = resolved.parent
    while not probe.exists() and probe != probe.parent:
        probe = probe.parent
    try:
        proc = subprocess.run(
            ["git", "-C", str(probe), "rev-parse", "--show-toplevel"],
            capture_output=True,
            text=True,
            check=False,
        )
    except FileNotFoundError:
        return
    if proc.returncode == 0:
        git_root = pathlib.Path(proc.stdout.strip()).resolve()
        try:
            resolved.relative_to(git_root)
        except ValueError:
            return
        raise InitializerError("Generated packages must be written outside the Git worktree.")


def generate_package(args: argparse.Namespace) -> dict[str, Any]:
    policy_path = pathlib.Path(args.policy).expanduser().resolve(strict=True)
    policy = read_json(policy_path)
    output = pathlib.Path(args.output).expanduser()
    reject_git_output(output)
    if output.exists() or output.is_symlink():
        raise InitializerError("Output path already exists; refusing to overwrite it.")
    uploads_root = pathlib.Path(args.uploads_root).expanduser().resolve(strict=True)
    if not uploads_root.is_dir():
        raise InitializerError("Uploads root is not a directory.")

    options = connection_options_from_args(args)
    temp_root = output.parent.resolve() / f".{output.name}.tmp-{secrets.token_hex(6)}"
    if temp_root.exists():
        raise InitializerError("Unexpected temporary output collision.")
    temp_root.mkdir(parents=True, mode=0o700)
    try:
        with MysqlClient(options) as client:
            inspect_schema(client, options.database, args.table_prefix, policy)
            direct_sources = collect_direct_upload_sources(
                client, options.database, args.table_prefix, policy
            )
            content_sources, page_json_count = collect_content_upload_sources(
                client, options.database, args.table_prefix, policy
            )
            selected = select_upload_records(
                load_upload_records(client, options.database, args.table_prefix),
                direct_sources,
                content_sources,
            )

            schema = client.dump_schema(options.database)
            atomic_write(temp_root / "schema.sql", schema)

            data_parts = [b"-- Sanitized allowlisted initializer data.\n"]
            source_counts: dict[str, int] = {}
            for item in policy["safe_data_tables"]:
                physical = table_name(args.table_prefix, item["name"])
                rows = client.query(
                    f"SELECT COUNT(*) FROM {quote_identifier(physical)}"
                    + (f" WHERE {item['where']}" if item.get("where") else ""),
                    database=options.database,
                )
                source_counts[physical] = int(rows[0][0])
                data_parts.append(f"\n-- allowlisted table: {physical}\n".encode())
                data_parts.append(
                    client.dump_table_data(
                        options.database, physical, item.get("where")
                    )
                )
            upload_physical = table_name(args.table_prefix, "upload_file")
            upload_ids = [item.record.file_id for item in selected]
            upload_where = "file_id IN (" + ",".join(str(value) for value in upload_ids) + ")" if upload_ids else "0=1"
            source_counts[upload_physical] = len(upload_ids)
            data_parts.append(f"\n-- referenced uploads only: {upload_physical}\n".encode())
            data_parts.append(client.dump_table_data(options.database, upload_physical, upload_where))
            atomic_write(temp_root / "data.sql", b"".join(data_parts))

            goods_physical = table_name(args.table_prefix, "goods")
            goods_count = source_counts.get(goods_physical, 0)
            if args.expected_goods_count is not None and goods_count != args.expected_goods_count:
                raise InitializerError(
                    f"Expected {args.expected_goods_count} goods rows, found {goods_count}."
                )

            manifest_files = copy_selected_uploads(uploads_root, temp_root, selected)
            manifest = {
                "format_version": 1,
                "files": manifest_files,
                "referenced_file_count": len(manifest_files),
                "uploads_layout": "uploads/<upload_file.file_path>",
            }
            write_json(temp_root / "uploads-manifest.json", manifest)
            report = {
                "format_version": 1,
                "generated_at": utc_now(),
                "expected_goods_count": args.expected_goods_count,
                "page_json_rows_parsed": page_json_count,
                "policy_sha256": sha256_file(policy_path),
                "referenced_content_path_count": len(content_sources),
                "referenced_direct_file_id_count": len(direct_sources),
                "referenced_upload_count": len(selected),
                "upload_database_size_mismatch_count": sum(
                    1 for item in manifest_files if not item["database_size_matches"]
                ),
                "source_table_counts": source_counts,
                "status": "generated_not_restore_validated",
            }
            write_json(temp_root / "generation-report.json", report)
            assert_no_private_inserts(
                (temp_root / "data.sql").read_text(encoding="utf-8"), args.table_prefix, policy
            )
            scan_public_artifacts(
                temp_root,
                list(policy["forbidden_public_domains"]) + list(args.forbid_domain),
                configured_secret_values(args.secret_env, client),
            )
            write_checksums(temp_root)
            os.chmod(temp_root, 0o700)
        output.parent.mkdir(parents=True, exist_ok=True)
        os.replace(temp_root, output)
        return report
    except BaseException:
        shutil.rmtree(temp_root, ignore_errors=True)
        raise


def validate_manifest(package_root: pathlib.Path) -> tuple[dict[str, Any], list[int]]:
    manifest = read_json(package_root / "uploads-manifest.json")
    if manifest.get("format_version") != 1 or not isinstance(manifest.get("files"), list):
        raise InitializerError("Unsupported or invalid uploads manifest.")
    ids: list[int] = []
    seen_paths: set[str] = set()
    for item in manifest["files"]:
        if not isinstance(item, dict):
            raise InitializerError("Upload manifest entries must be objects.")
        try:
            file_id = int(item["file_id"])
            file_path = normalize_upload_path(str(item["file_path"]), "manifest file_path")
            package_path = safe_relative_path(str(item["package_path"]), "manifest package_path").as_posix()
            size = int(item["size"])
            expected_hash = str(item["sha256"])
        except (KeyError, TypeError, ValueError) as exc:
            raise InitializerError("Upload manifest entry is missing valid required fields.") from exc
        expected_package_path = pathlib.PurePosixPath("uploads", *pathlib.PurePosixPath(file_path).parts).as_posix()
        if package_path != expected_package_path:
            raise InitializerError(f"Manifest package path does not match file_path for ID {file_id}.")
        if package_path in seen_paths or file_id in ids:
            raise InitializerError("Upload manifest contains duplicate IDs or paths.")
        seen_paths.add(package_path)
        ids.append(file_id)
        path = package_root.joinpath(*pathlib.PurePosixPath(package_path).parts)
        if path.is_symlink() or not path.is_file():
            raise InitializerError(f"Manifest upload is missing or unsafe: {package_path}")
        if path.stat().st_size != size or sha256_file(path) != expected_hash:
            raise InitializerError(f"Manifest upload size/checksum mismatch: {package_path}")
    if manifest.get("referenced_file_count") != len(ids):
        raise InitializerError("Manifest referenced_file_count is incorrect.")
    return manifest, sorted(ids)


def validate_restored_database(
    client: MysqlClient,
    database: str,
    package_root: pathlib.Path,
    prefix: str,
    policy: Mapping[str, Any],
    manifest_ids: Sequence[int],
    expected_goods_count: int | None,
) -> dict[str, Any]:
    restored_table_rows = client.query(
        "SELECT TABLE_NAME FROM information_schema.TABLES "
        "WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME",
        database=database,
    )
    restored_tables = {row[0] for row in restored_table_rows}
    classified_logical = (
        {item["name"] for item in policy["safe_data_tables"]}
        | set(policy["required_empty_tables"])
        | {"upload_file"}
    )
    classified_tables = {table_name(prefix, name) for name in classified_logical}
    unclassified = sorted(
        name for name in restored_tables if name.startswith(prefix) and name not in classified_tables
    )
    if unclassified:
        raise InitializerError(
            "Restored schema contains unclassified tables: " + ", ".join(unclassified)
        )

    required_empty = [table_name(prefix, name) for name in policy["required_empty_tables"]]
    empty_counts = count_tables(client, database, required_empty)
    nonempty = {name: count for name, count in empty_counts.items() if count != 0}
    if nonempty:
        raise InitializerError(
            "Forbidden restored tables are non-empty: "
            + ", ".join(f"{name}={count}" for name, count in sorted(nonempty.items()))
        )

    goods_physical = table_name(prefix, "goods")
    goods_count = count_tables(client, database, [goods_physical])[goods_physical]
    if expected_goods_count is not None and goods_count != expected_goods_count:
        raise InitializerError(
            f"Restored goods count mismatch: expected {expected_goods_count}, found {goods_count}."
        )

    page_physical = table_name(prefix, "page")
    page_rows = client.query(
        f"SELECT CAST(page_id AS CHAR),HEX(page_data) FROM {quote_identifier(page_physical)} ORDER BY page_id",
        database=database,
    )
    for row in page_rows:
        decode_page_json(decode_hex_text(row[1], f"restored {page_physical}[{row[0]}]"), f"restored {page_physical}[{row[0]}]")

    upload_physical = table_name(prefix, "upload_file")
    restored_upload_rows = client.query(
        f"SELECT file_id FROM {quote_identifier(upload_physical)} ORDER BY file_id",
        database=database,
    )
    restored_upload_ids = [int(row[0]) for row in restored_upload_rows]
    if restored_upload_ids != list(manifest_ids):
        raise InitializerError("Restored upload_file IDs do not exactly match the manifest.")

    setting_physical = table_name(prefix, "store_setting")
    setting_rows = client.query(
        f"SELECT DISTINCT `key` FROM {quote_identifier(setting_physical)} ORDER BY `key`",
        database=database,
    )
    setting_keys = [row[0] for row in setting_rows]
    allowed = set(policy["allowed_store_setting_keys"])
    unexpected = sorted(set(setting_keys) - allowed)
    if unexpected:
        raise InitializerError("Private/unapproved store setting keys were restored: " + ", ".join(unexpected))

    return {
        "empty_table_counts": empty_counts,
        "goods_count": goods_count,
        "page_json_rows_parsed": len(page_rows),
        "restored_setting_keys": setting_keys,
        "restored_upload_count": len(restored_upload_ids),
    }


def validate_package(args: argparse.Namespace) -> dict[str, Any]:
    policy_path = pathlib.Path(args.policy).expanduser().resolve(strict=True)
    policy = read_json(policy_path)
    package_root = pathlib.Path(args.package).expanduser().resolve(strict=True)
    if not package_root.is_dir():
        raise InitializerError("Package path is not a directory.")
    missing = sorted(name for name in REQUIRED_PACKAGE_FILES if not (package_root / name).is_file())
    if missing:
        raise InitializerError("Package is missing required files: " + ", ".join(missing))

    checksum_count = verify_checksums(package_root)
    manifest, manifest_ids = validate_manifest(package_root)
    generation_report = read_json(package_root / "generation-report.json")
    expected_goods_count = args.expected_goods_count
    if expected_goods_count is None:
        recorded = generation_report.get("expected_goods_count")
        expected_goods_count = int(recorded) if recorded is not None else None

    options = connection_options_from_args(args)
    temp_database = f"{args.temp_database_prefix}{secrets.token_hex(8)}"
    quote_identifier(temp_database)
    if temp_database == options.database:
        raise InitializerError("Temporary validation database collides with the source database name.")

    with MysqlClient(options) as client:
        secret_values = configured_secret_values(args.secret_env, client)
        assert_no_private_inserts(
            (package_root / "data.sql").read_text(encoding="utf-8"), args.table_prefix, policy
        )
        scan_public_artifacts(
            package_root,
            list(policy["forbidden_public_domains"]) + list(args.forbid_domain),
            secret_values,
        )
        created = False
        try:
            client.query(
                f"CREATE DATABASE {quote_identifier(temp_database)} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            )
            created = True
            client.run(
                "mysql", [], database=temp_database, input_bytes=(package_root / "schema.sql").read_bytes()
            )
            client.run(
                "mysql", [], database=temp_database, input_bytes=(package_root / "data.sql").read_bytes()
            )
            restored = validate_restored_database(
                client,
                temp_database,
                package_root,
                args.table_prefix,
                policy,
                manifest_ids,
                expected_goods_count,
            )
        finally:
            if created:
                try:
                    client.query(f"DROP DATABASE {quote_identifier(temp_database)}")
                except InitializerError as exc:
                    raise InitializerError(
                        "Validation cleanup failed; manually inspect the temporary database prefix without exposing credentials."
                    ) from exc

    final_checksum_count = len(package_regular_files(package_root))
    if not (package_root / "validation-report.json").is_file():
        final_checksum_count += 1
    report = {
        "checks": {
            "checksums_verified_before_restore": checksum_count,
            "checksum_entries_written": final_checksum_count,
            "forbidden_domains_and_secret_material": "passed",
            "forbidden_tables_empty": "passed",
            "manifest_files_exist": len(manifest["files"]),
            "page_json": "passed",
            "temporary_restore": "passed",
        },
        "expected_goods_count": expected_goods_count,
        "format_version": 1,
        "policy_sha256": sha256_file(policy_path),
        "restored": restored,
        "status": "passed",
        "validated_at": utc_now(),
    }
    write_json(package_root / "validation-report.json", report)
    write_checksums(package_root)
    return report


def connection_options_from_args(args: argparse.Namespace) -> ConnectionOptions:
    database = args.database or os.environ.get("YOSHOP_DB_DATABASE", "")
    if not database:
        raise InitializerError("Provide --database or YOSHOP_DB_DATABASE explicitly.")
    quote_identifier(database)
    defaults_raw = args.mysql_defaults_file or os.environ.get("YOSHOP_MYSQL_DEFAULTS_FILE")
    defaults_file = pathlib.Path(defaults_raw) if defaults_raw else None
    password_env = args.password_env
    password = os.environ.get(password_env, "") if password_env else ""
    host = args.host or os.environ.get("YOSHOP_DB_HOST")
    user = args.user or os.environ.get("YOSHOP_DB_USER")
    port = args.port or int(os.environ.get("YOSHOP_DB_PORT", "3306"))
    return ConnectionOptions(database, defaults_file, host, port, user, password)


def add_connection_arguments(parser: argparse.ArgumentParser) -> None:
    parser.add_argument("--database", help="Source/server database name; or YOSHOP_DB_DATABASE.")
    parser.add_argument(
        "--mysql-defaults-file",
        help="Mode-0600 MySQL [client] defaults file; or YOSHOP_MYSQL_DEFAULTS_FILE.",
    )
    parser.add_argument("--host", help="MySQL host; or YOSHOP_DB_HOST.")
    parser.add_argument("--port", type=int, help="MySQL port; or YOSHOP_DB_PORT (default 3306).")
    parser.add_argument("--user", help="MySQL user; or YOSHOP_DB_USER.")
    parser.add_argument(
        "--password-env",
        default="YOSHOP_DB_PASSWORD",
        help="Environment variable holding the password; the value is never printed or passed in argv.",
    )
    parser.add_argument(
        "--secret-env",
        action="append",
        default=[],
        metavar="NAME",
        help="Also reject the exact value of this environment variable in public artifacts (repeatable).",
    )


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)

    generate = subparsers.add_parser("generate", help="Generate a sanitized initializer package.")
    add_connection_arguments(generate)
    generate.add_argument("--output", required=True, help="New output directory outside the Git worktree.")
    generate.add_argument("--uploads-root", required=True, help="Local public/uploads directory.")
    generate.add_argument("--expected-goods-count", type=int)
    generate.add_argument("--policy", default=str(DEFAULT_POLICY))
    generate.add_argument("--table-prefix", default=os.environ.get("YOSHOP_DB_PREFIX", "yoshop_"))
    generate.add_argument("--forbid-domain", action="append", default=[])

    validate = subparsers.add_parser("validate", help="Restore into a random temporary DB and validate.")
    add_connection_arguments(validate)
    validate.add_argument("--package", required=True)
    validate.add_argument("--expected-goods-count", type=int)
    validate.add_argument("--policy", default=str(DEFAULT_POLICY))
    validate.add_argument("--table-prefix", default=os.environ.get("YOSHOP_DB_PREFIX", "yoshop_"))
    validate.add_argument("--temp-database-prefix", default="yoshop_init_validate_")
    validate.add_argument("--forbid-domain", action="append", default=[])
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    try:
        if args.command == "generate":
            report = generate_package(args)
            print(
                "Generated sanitized initializer package: "
                f"goods={report['source_table_counts'].get(args.table_prefix + 'goods', 0)}, "
                f"uploads={report['referenced_upload_count']}, "
                f"page_json={report['page_json_rows_parsed']}"
            )
        else:
            report = validate_package(args)
            print(
                "Validated initializer package in an isolated temporary database: "
                f"goods={report['restored']['goods_count']}, "
                f"uploads={report['restored']['restored_upload_count']}"
            )
        return 0
    except InitializerError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
