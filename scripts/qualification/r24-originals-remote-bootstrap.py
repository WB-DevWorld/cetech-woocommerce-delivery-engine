#!/usr/bin/env python3
"""Fetch authenticated originals for the separately pinned R24 semantic auditor.

No WordPress/database bootstrap, replay, subprocess, repository mutation or
semantic acceptance occurs here. The supplied SQL witness describes Root's
already-completed MCP fetch; this program only makes a byte-identical REST copy.
"""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import sys
import urllib.error
import urllib.parse
import urllib.request
import zipfile

API = "https://api.github.com"
MAX_JSON = 16 * 1024 * 1024
MAX_ARCHIVE = 128 * 1024 * 1024
MAX_ARCHIVES_TOTAL = 512 * 1024 * 1024
MAX_MEMBER = 128 * 1024 * 1024
MAX_ZIP_TOTAL = 256 * 1024 * 1024
MAX_ALL_UNCOMPRESSED = 512 * 1024 * 1024
MAX_SQL_LOG = 64 * 1024 * 1024
SQL_JOB = "PHP 8.5 MariaDB Geography/Operations/Rule Lifecycle/Migrations"
MCP_LOG_TOOL = "mcp__codex_apps__github_fetch_workflow_job_logs"
STATE = {"stage": "arguments"}

class Refused(ValueError):
    pass

def need(value, message):
    if not value:
        raise Refused(message)

def integer(value, maximum=9223372036854775807):
    return type(value) is int and 0 < value <= maximum

def digest(value):
    return hashlib.sha256(value).hexdigest()

def pairs(items):
    result = {}
    for key, value in items:
        need(key not in result, "Duplicate JSON key")
        result[key] = value
    return result

def bad_constant(value):
    raise Refused("Non-JSON numeric constant")

def decode(raw):
    return json.loads(raw.decode("utf-8"), object_pairs_hook=pairs,
                      parse_constant=bad_constant)

def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"),
                       ensure_ascii=False, allow_nan=False) + "\n").encode("utf-8")

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, newurl):
        return None

def opener():
    # Do not inherit a cookie/auth handler or an ambient proxy configuration.
    return urllib.request.build_opener(
        urllib.request.ProxyHandler({}), NoRedirect()
    )

def request_once(url, headers):
    try:
        response = opener().open(
            urllib.request.Request(url, headers=headers, method="GET"), timeout=60
        )
        need(response.status == 200, "Unexpected HTTP status")
        need(response.headers.get("Content-Encoding", "identity").lower()
             in ("", "identity"), "Encoded HTTP body refused")
        return response, None
    except urllib.error.HTTPError as error:
        try:
            need(error.code in (301, 302, 303, 307, 308),
                 "HTTP request refused")
            location = error.headers.get("Location")
            need(type(location) is str and 0 < len(location) <= 16384,
                 "Redirect location refused")
            return None, urllib.parse.urljoin(url, location)
        finally:
            error.close()

def blob_url(url):
    parsed = urllib.parse.urlsplit(url)
    need(parsed.scheme == "https" and parsed.username is None
         and parsed.password is None and parsed.fragment == ""
         and parsed.port in (None, 443), "Unsafe blob URL")
    host = parsed.hostname or ""
    azure = re.fullmatch(r"[a-z0-9-]+\.blob\.core\.windows\.net", host)
    s3 = re.fullmatch(
        r"[a-z0-9.-]+\.s3(?:[.-][a-z0-9-]+)?\.amazonaws\.com", host
    )
    actions = re.fullmatch(r"[a-z0-9.-]+\.actions\.githubusercontent\.com", host)
    need(bool(azure or s3 or actions), "Unapproved blob host")
    return url

def api_headers(token, accept="application/vnd.github+json"):
    return {"Authorization": "Bearer " + token,
            "Accept": accept,
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "cetech-r24-original-audit-bootstrap"}

def get_json(url, token):
    need(url.startswith(API + "/repos/"), "Non-GitHub API URL")
    response, redirect = request_once(url, api_headers(token))
    need(redirect is None, "API JSON redirect refused")
    with response:
        need('rel="next"' not in response.headers.get("Link", ""),
             "Unexpected API pagination")
        raw = response.read(MAX_JSON + 1)
    need(0 < len(raw) <= MAX_JSON, "API JSON size refused")
    return decode(raw)

def download_response(api_url, token):
    # Only this fixed GitHub request receives Authorization.
    response, redirect = request_once(api_url, api_headers(token, "*/*"))
    if response is not None:
        return response
    url = blob_url(redirect)
    for unused in range(3):
        # Fresh headers: NEVER copy API Authorization to a redirect target.
        response, redirect = request_once(
            url, {"Accept": "application/octet-stream",
                  "User-Agent": "cetech-r24-original-audit-bootstrap"}
        )
        if response is not None:
            return response
        url = blob_url(redirect)
    raise Refused("Too many blob redirects")

def write_new(path, raw):
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL |
                         os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, "wb") as stream:
        os.fchmod(stream.fileno(), 0o600)
        need(stream.write(raw) == len(raw), "Incomplete private write")
        stream.flush()
        os.fsync(stream.fileno())

def download_exact(api_url, token, path, expected_bytes, expected_sha, maximum):
    need(integer(expected_bytes, maximum)
         and re.fullmatch(r"[a-f0-9]{64}", expected_sha), "Download bounds refused")
    temporary = path.with_name("." + path.name + ".partial")
    identity = None
    try:
        response = download_response(api_url, token)
        with response:
            length = response.headers.get("Content-Length")
            if length is not None:
                need(length.isdigit() and int(length) == expected_bytes,
                     "HTTP length differs")
            descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL |
                                 os.O_NOFOLLOW, 0o600)
            with os.fdopen(descriptor, "wb") as stream:
                os.fchmod(stream.fileno(), 0o600)
                created = os.fstat(stream.fileno())
                identity = (created.st_dev, created.st_ino)
                count = 0
                sha = hashlib.sha256()
                while True:
                    chunk = response.read(min(1024 * 1024, expected_bytes - count + 1))
                    if not chunk:
                        break
                    count += len(chunk)
                    need(count <= expected_bytes, "Download overflow")
                    sha.update(chunk)
                    need(stream.write(chunk) == len(chunk), "Incomplete download write")
                need(count == expected_bytes and sha.hexdigest() == expected_sha,
                     "Download bytes or digest differ")
                stream.flush()
                os.fsync(stream.fileno())
        # Atomic no-overwrite publication inside the new owned directory.
        os.link(temporary, path, follow_symlinks=False)
        temporary.unlink()
    finally:
        if identity is not None:
            try:
                current = temporary.stat(follow_symlinks=False)
                if (current.st_dev, current.st_ino) == identity:
                    temporary.unlink()
            except FileNotFoundError:
                pass

def zip_manifest(path):
    names = set()
    total = 0
    with zipfile.ZipFile(path) as archive:
        members = archive.infolist()
        need(0 < len(members) <= 20000, "ZIP member count refused")
        for member in members:
            name = member.filename
            parts = name.rstrip("/").split("/")
            need(name and "\x00" not in name and "\\" not in name
                 and not name.startswith("/") and not re.match(r"^[A-Za-z]:", name)
                 and all(part not in ("", ".", "..") for part in parts)
                 and name not in names, "Unsafe or duplicate ZIP member")
            names.add(name)
            kind = stat.S_IFMT(member.external_attr >> 16)
            need(kind in (0, stat.S_IFREG, stat.S_IFDIR)
                 and not (member.flag_bits & 1)
                 and member.compress_type in (zipfile.ZIP_STORED, zipfile.ZIP_DEFLATED),
                 "Unsupported ZIP member")
            need(0 <= member.file_size <= MAX_MEMBER, "ZIP member size refused")
            total += member.file_size
            need(total <= MAX_ZIP_TOTAL, "ZIP expansion refused")
    return total

def crc_check(path):
    with zipfile.ZipFile(path) as archive:
        for member in archive.infolist():
            count = 0
            with archive.open(member, "r") as stream:
                while True:
                    chunk = stream.read(1024 * 1024)
                    if not chunk:
                        break
                    count += len(chunk)
                    need(count <= member.file_size, "ZIP member overflow")
            # zipfile checks CRC when the member reaches EOF.
            need(count == member.file_size, "ZIP member length differs")

def witness(raw, repo, run_id, head):
    need(0 < len(raw) <= 16384, "Witness size refused")
    value = decode(raw)
    need(type(value) is dict and set(value) == {
        "format", "repository", "run_id", "run_attempt", "head_sha",
        "job", "fetch", "snapshots", "log"
    }, "Witness shape differs")
    # The separately pinned semantic auditor enforces its exact format identifier.
    need(type(value["format"]) is str and 0 < len(value["format"]) <= 128
         and value["repository"] == repo and value["run_id"] == run_id
         and value["run_attempt"] == 1 and value["head_sha"] == head,
         "Witness run identity differs")
    job = value["job"]
    need(type(job) is dict and set(job) == {
        "id", "name", "parent_run_id", "parent_run_attempt", "parent_head_sha"
    } and integer(job["id"]) and job["name"] == SQL_JOB
         and job["parent_run_id"] == run_id and job["parent_run_attempt"] == 1
         and job["parent_head_sha"] == head, "Witness job identity differs")
    need(value["fetch"] == {
        "tool": MCP_LOG_TOOL, "inputs": {"repo_full_name": repo, "job_id": job["id"]}
    }, "Witness MCP origin differs")
    snapshots = value["snapshots"]
    need(type(snapshots) is dict and set(snapshots) == {
        "run_json_sha256", "jobs_json_sha256"
    } and all(type(item) is str and re.fullmatch(r"[a-f0-9]{64}", item)
              for item in snapshots.values()), "Witness snapshots differ")
    log = value["log"]
    need(type(log) is dict and set(log) == {"filename", "sha256", "bytes"}
         and log["filename"] == "sql-original.log"
         and integer(log["bytes"], MAX_SQL_LOG)
         and type(log["sha256"]) is str
         and re.fullmatch(r"[a-f0-9]{64}", log["sha256"]), "Witness log differs")
    return value

def execute(args):
    STATE["stage"] = "arguments"
    need(re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", args.repo)
         and integer(args.run_id) and re.fullmatch(r"[a-f0-9]{40}", args.head),
         "Arguments refused")
    token = os.environ.get("GITHUB_TOKEN", "")
    need(re.fullmatch(r"[!-~]{1,4096}", token), "Token missing or malformed")
    raw_witness = os.environ.get("R24_SQL_WITNESS_JSON", "").encode("utf-8")
    origin = witness(raw_witness, args.repo, args.run_id, args.head)
    base = API + "/repos/" + args.repo
    STATE["stage"] = "final_snapshots"
    run = get_json(base + "/actions/runs/" + str(args.run_id), token)
    jobs = get_json(base + "/actions/runs/" + str(args.run_id) +
                    "/jobs?per_page=100&page=1", token)
    need(run.get("id") == args.run_id and run.get("run_attempt") == 1
         and run.get("head_sha") == args.head and run.get("status") == "completed"
         and run.get("conclusion") == "success"
         and run.get("repository", {}).get("full_name") == args.repo
         and run.get("head_repository", {}).get("full_name") == args.repo,
         "Final run identity or result differs")
    rows = jobs.get("jobs")
    need(jobs.get("total_count") == 8 and type(rows) is list and len(rows) == 8
         and len({row.get("id") for row in rows}) == 8
         and len({row.get("name") for row in rows}) == 8, "Eight-job closure differs")
    for row in rows:
        need(integer(row.get("id")) and row.get("run_id") == args.run_id
             and row.get("run_attempt") == 1 and row.get("head_sha") == args.head
             and row.get("status") == "completed" and row.get("conclusion") == "success",
             "Job identity or result differs")
    sql = [row for row in rows if row["id"] == origin["job"]["id"]]
    need(len(sql) == 1 and sql[0]["name"] == SQL_JOB, "SQL job differs")
    run_raw, jobs_raw = canonical(run), canonical(jobs)
    need(digest(run_raw) == origin["snapshots"]["run_json_sha256"]
         and digest(jobs_raw) == origin["snapshots"]["jobs_json_sha256"],
         "Root MCP snapshot hashes differ")

    STATE["stage"] = "artifact_metadata"
    artifacts = get_json(base + "/actions/runs/" + str(args.run_id) +
                         "/artifacts?per_page=100&page=1", token)
    items = artifacts.get("artifacts")
    need(artifacts.get("total_count") == 28 and type(items) is list
         and len(items) == 28 and len({item.get("id") for item in items}) == 28
         and len({item.get("name") for item in items}) == 28,
         "Twenty-eight-artifact closure differs")
    total = 0
    now = datetime.now(timezone.utc)
    for item in items:
        artifact_id = item.get("id")
        need(integer(artifact_id) and type(item.get("name")) is str
             and 0 < len(item["name"]) <= 200 and item.get("expired") is False
             and integer(item.get("size_in_bytes"), MAX_ARCHIVE)
             and re.fullmatch(r"sha256:[a-f0-9]{64}", item.get("digest", "")),
             "Artifact metadata refused")
        owner = item.get("workflow_run", {})
        need(owner.get("id") == args.run_id and owner.get("head_sha") == args.head
             and owner.get("repository_id") == run["repository"]["id"]
             and owner.get("head_repository_id") == run["head_repository"]["id"],
             "Artifact run/head/repository differs")
        need(item.get("archive_download_url") ==
             base + "/actions/artifacts/" + str(artifact_id) + "/zip",
             "Artifact endpoint differs")
        expiry = datetime.fromisoformat(item["expires_at"].replace("Z", "+00:00"))
        need(expiry.tzinfo is not None and expiry > now, "Artifact expired")
        total += item["size_in_bytes"]
    need(total <= MAX_ARCHIVES_TOTAL, "Total artifact bytes refused")

    STATE["stage"] = "owned_directory"
    runner_temp = Path(os.environ["RUNNER_TEMP"]).resolve(strict=True)
    target = Path(args.directory)
    need(target.is_absolute() and target.parent.resolve(strict=True) == runner_temp
         and not target.exists() and not target.is_symlink(),
         "Fresh RUNNER_TEMP child required")
    target.mkdir(mode=0o700)
    os.chmod(target, 0o700)
    originals = target / "originals"
    originals.mkdir(mode=0o700)
    write_new(target / "run.json", run_raw)
    write_new(target / "jobs.json", jobs_raw)
    write_new(target / "artifacts.json", canonical(artifacts))

    STATE["stage"] = "artifact_downloads"
    def fetch(item):
        path = originals / (str(item["id"]) + ".zip")
        download_exact(item["archive_download_url"], token, path,
                       item["size_in_bytes"], item["digest"][7:], MAX_ARCHIVE)
        return path
    with ThreadPoolExecutor(max_workers=4) as pool:
        paths = list(pool.map(fetch, items))
    STATE["stage"] = "archive_structure"
    expansion = sum(zip_manifest(path) for path in paths)
    need(expansion <= MAX_ALL_UNCOMPRESSED, "Total ZIP expansion refused")
    STATE["stage"] = "archive_crc"
    with ThreadPoolExecutor(max_workers=4) as pool:
        list(pool.map(crc_check, paths))

    STATE["stage"] = "sql_verified_copy"
    log = origin["log"]
    download_exact(base + "/actions/jobs/" + str(origin["job"]["id"]) + "/logs",
                   token, target / log["filename"], log["bytes"], log["sha256"],
                   MAX_SQL_LOG)
    # Preserve the Root MCP witness bytes exactly. No REST fetch is mislabeled.
    write_new(target / "sql-log-witness.json", raw_witness)
    STATE["stage"] = "complete"
    print(json.dumps({"bootstrap": "OK", "run_id": args.run_id,
                      "head_sha": args.head, "artifacts": 28,
                      "artifact_bytes": total, "uncompressed_bytes": expansion,
                      "sql_copy_sha256": log["sha256"],
                      "semantic_audit": "NOT_RUN"}, sort_keys=True))

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo", required=True)
    parser.add_argument("--run-id", type=int, required=True)
    parser.add_argument("--head", required=True)
    parser.add_argument("--directory", required=True)
    args = parser.parse_args()
    try:
        execute(args)
        return 0
    except Exception as error:
        # Never echo signed URLs, Authorization, SQL body, or arbitrary errors.
        print("r24_remote_bootstrap_error=" + STATE["stage"] + ":" +
              type(error).__name__, file=sys.stderr)
        return 1

if __name__ == "__main__":
    raise SystemExit(main())
