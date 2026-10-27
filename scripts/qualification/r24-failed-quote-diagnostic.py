#!/usr/bin/env python3
"""Diagnose three authenticated originals from a failed run; never certify a candidate."""
from __future__ import annotations
import argparse
import ast
import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import zipfile

REPO = "WB-DevWorld/cetech-woocommerce-delivery-engine"
REPO_ID = 1329689664
RUN = 40419092642
HEAD = "67c3ecc44cee0f5aa53a0090e49575ada46f7539"
BOOTSTRAP_SHA = "d1fbdf4e4613bddb583285ac75288094d2638f48dfdd33b9550a330d6bf847af"
API = "https://api.github.com/repos/" + REPO
ITEMS = (
    ("native", 12698996841, "opening-native-qualification",
     "opening-qualification-results.json", 83186,
     "9b2daf904d49918b29ff288a38ba3a093f94bbbd8d601311e95bd6e026e73ec4"),
    ("cpt", 12698107483, "opening-quote-placement-cpt-qualification",
     "opening-quote-placement-cpt-results.json", 50475,
     "602ffc1b39c56d74d9617615f847be1174f778696f19c2ef7eee5a7419812ee5"),
    ("http", 12698991724, "opening-http-qualification",
     "opening-http-qualification-results.json", 73531,
     "abd81ecb10afa6808d844fa6bee0201cf13f33aa8b4994916f6ef4afa0a8efe8"),
)
MAX_RECEIPT = 16 * 1024 * 1024

def need(value, message):
    if not value:
        raise RuntimeError(message)

def sha(raw):
    return hashlib.sha256(raw).hexdigest()

def canon(value):
    return json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode("utf-8")

def import_path(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    need(spec is not None and spec.loader is not None, "Module loader unavailable")
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module

def run_git(source, *args):
    return subprocess.check_output(
        ["git", "-C", str(source), *args], timeout=30,
        stderr=subprocess.DEVNULL).decode("utf-8").strip()

def captured(call):
    out, err = io.StringIO(), io.StringIO()
    value, error = None, None
    with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
        try:
            value = call()
        except Exception as caught:
            error = caught
    return value, error, {
        "stdout_bytes": len(out.getvalue().encode("utf-8")),
        "stdout_sha256": sha(out.getvalue().encode("utf-8")),
        "stderr_bytes": len(err.getvalue().encode("utf-8")),
        "stderr_sha256": sha(err.getvalue().encode("utf-8")),
    }

def failure(error, literals, qualification):
    allowed_types = {
        "RuntimeError", "ValueError", "TypeError", "KeyError", "OSError",
        "FileNotFoundError", "JSONDecodeError", "CalledProcessError",
        "TimeoutExpired", "AssertionError", "AttributeError", "IndexError",
    }
    kind = type(error).__name__
    result = {"type": kind if kind in allowed_types else "OtherException",
              "message": str(error) if type(error) is RuntimeError
                         and str(error) in literals else "message_withheld",
              "source_frames": []}
    tb = error.__traceback__
    while tb is not None:
        filename = Path(tb.tb_frame.f_code.co_filename)
        try:
            trusted = filename.resolve().parent == qualification
        except OSError:
            trusted = False
        if trusted and filename.suffix == ".py" and len(result["source_frames"]) < 16:
            result["source_frames"].append(
                {"file": filename.name, "line": tb.tb_lineno})
        tb = tb.tb_next
    return result

def inventory(report, prefix):
    cases = report.get("cases")
    if not isinstance(cases, list) or len(cases) > 1000:
        return {"cases_shape_valid": False}
    ids = [case.get("id") for case in cases if isinstance(case, dict)]
    if len(ids) != len(cases) or not all(
            isinstance(value, str)
            and re.fullmatch(r"[A-Za-z0-9_-]{1,200}", value) for value in ids):
        return {"cases_shape_valid": False, "count": len(cases)}
    selected = [value for value in ids if value.startswith(prefix)]
    retained = [value for value in ids if not value.startswith(prefix)]
    return {
        "cases_shape_valid": True, "count": len(ids),
        "unique_id_count": len(set(ids)), "ordered_ids_sha256": sha(canon(ids)),
        "selected_count": len(selected), "selected_ids_sha256": sha(canon(selected)),
        "retained_count": len(retained), "retained_ids_sha256": sha(canon(retained)),
    }

def environment(report):
    value = report.get("environment")
    if not isinstance(value, dict):
        return {"shape_valid": False}
    result = {"shape_valid": True, "key_count": len(value)}
    for key in ("php", "wordpress", "woocommerce", "database_version",
                "hpos", "schema", "schema_before"):
        if key not in value:
            continue
        item = value[key]
        result[key] = item if isinstance(item, str) and re.fullmatch(
            r"[A-Za-z0-9_.+-]{1,120}", item) else "value_withheld"
    return result

def http_public(report):
    diagnostic = report.get("fixture_diagnostic")
    result = {"fixture_diagnostic": {}, "fixture_lifecycle": {}}
    if isinstance(diagnostic, dict):
        dest = result["fixture_diagnostic"]
        dest["key_count"] = len(diagnostic)
        for key in ("command_log_present", "raw_output_retained", "server_log_present",
                    "listener_cleanup_requested_sigterm"):
            if key in diagnostic:
                value = diagnostic[key]
                dest[key] = value if type(value) is bool else "invalid_type"
        for key in ("command_log_sha256", "server_log_sha256"):
            if key in diagnostic:
                value = diagnostic[key]
                dest[key] = value if isinstance(value, str) and re.fullmatch(
                    r"[a-f0-9]{64}", value) else "value_withheld"
        for key in ("allowlisted_error_codes", "allowlisted_error_classes"):
            value = diagnostic.get(key)
            dest[key + "_count"] = len(value) if isinstance(value, list) else None
        for key, permitted in (
            ("stage", {"complete"}),
            ("listener_exit_before_cleanup", {"running"}),
            ("listener_signal_before_cleanup", set()),
            ("owned_listener_wait_exit", {"143"}),
            ("owned_listener_wait_signal", {"15"}),
            ("database_connect_before_cleanup", {"connected"}),
        ):
            if key in diagnostic:
                value = diagnostic[key]
                dest[key] = value if value is None or (
                    isinstance(value, str) and value in permitted) else "other"
        value = diagnostic.get("database_connect_wait_ms")
        dest["database_connect_wait_ms"] = value if type(value) is int and (
            0 <= value <= 10000) else None
    lifecycle = report.get("fixture_lifecycle")
    if isinstance(lifecycle, dict):
        dest = result["fixture_lifecycle"]
        dest["key_count"] = len(lifecycle)
        for key in ("listener_started", "owned_listener_attested", "listener_stopped",
                    "fixture_mu_removed", "private_files_removed",
                    "tracked_cleanup_command_success"):
            if key in lifecycle:
                value = lifecycle[key]
                dest[key] = value if type(value) is bool else "invalid_type"
        dest["partial_init_failure_fallback_present"] = (
            "partial_init_failure_fallback" in lifecycle)
    return result

def execute(args):
    os.umask(0o077)
    source = Path(args.source).resolve(strict=True)
    qualification = source / "scripts" / "qualification"
    need(source.is_dir(), "Source is not a directory")
    need(run_git(source, "rev-parse", "HEAD") == HEAD, "Candidate HEAD differs")
    need(not run_git(source, "status", "--porcelain"), "Candidate is not clean")
    bootstrap_path = Path(args.bootstrap).resolve(strict=True)
    need(sha(bootstrap_path.read_bytes()) == BOOTSTRAP_SHA, "Bootstrap digest differs")
    bootstrap = import_path("_r24_failed_quote_bootstrap", bootstrap_path)
    runner_temp = Path(os.environ["RUNNER_TEMP"]).resolve(strict=True)
    directory = Path(args.directory)
    need(directory.is_absolute() and directory.parent.resolve(strict=True) == runner_temp,
         "Output must be a fresh direct RUNNER_TEMP child")
    directory.mkdir(mode=0o700)
    need(stat.S_IMODE(directory.stat().st_mode) == 0o700, "Output permissions differ")
    token = os.environ.pop("GITHUB_TOKEN", "")
    need(bool(token), "Read token missing")
    run = bootstrap.get_json(API + "/actions/runs/" + str(RUN), token)
    need(run.get("id") == RUN and run.get("head_sha") == HEAD
         and run.get("status") == "completed" and run.get("conclusion") == "failure"
         and run.get("run_attempt") == 1, "Failed run identity differs")
    need(run.get("repository", {}).get("id") == REPO_ID
         and run.get("repository", {}).get("full_name") == REPO
         and run.get("head_repository", {}).get("id") == REPO_ID,
         "Run repository identity differs")
    listing = bootstrap.get_json(
        API + "/actions/runs/" + str(RUN) + "/artifacts?per_page=100", token)
    artifacts = listing.get("artifacts")
    need(isinstance(artifacts, list) and listing.get("total_count") == len(artifacts)
         and len(artifacts) <= 100, "Artifact list is incomplete")
    paths, copied = [], []
    for kind, artifact_id, name, member_name, size, digest in ITEMS:
        matches = [item for item in artifacts if item.get("id") == artifact_id]
        need(len(matches) == 1, "Original artifact identity missing")
        item = matches[0]
        workflow_run = item.get("workflow_run", {})
        endpoint = API + "/actions/artifacts/" + str(artifact_id)
        need(item.get("name") == name and item.get("size_in_bytes") == size
             and item.get("digest") == "sha256:" + digest
             and item.get("expired") is False and item.get("url") == endpoint
             and item.get("archive_download_url") == endpoint + "/zip",
             "Original artifact metadata differs")
        need(workflow_run.get("id") == RUN and workflow_run.get("head_sha") == HEAD
             and workflow_run.get("repository_id") == REPO_ID
             and workflow_run.get("head_repository_id") == REPO_ID,
             "Original artifact run identity differs")
        archive_path = directory / (str(artifact_id) + ".zip")
        bootstrap.download_exact(endpoint + "/zip", token, archive_path,
                                 size, digest, 1024 * 1024)
        bootstrap.zip_manifest(archive_path)
        bootstrap.crc_check(archive_path)
        with zipfile.ZipFile(archive_path) as archive:
            members = archive.infolist()
            need(len(members) == 1 and members[0].filename == member_name
                 and not members[0].is_dir()
                 and 0 < members[0].file_size <= MAX_RECEIPT,
                 "Original ZIP member closure differs")
            raw = archive.read(members[0])
        need(0 < len(raw) <= MAX_RECEIPT, "Receipt expansion differs")
        receipt_path = directory / member_name
        bootstrap.write_new(receipt_path, raw)
        paths.append(receipt_path)
        copied.append({"kind": kind, "id": artifact_id, "archive_bytes": size,
                       "archive_sha256": digest, "receipt_bytes": len(raw),
                       "receipt_sha256": sha(raw), "crc_verified": True,
                       "single_expected_member": True})
    del token
    verifier_path = qualification / "verify-quote-placement-receipts.py"
    verifier_raw = verifier_path.read_bytes()
    verifier_tree = ast.parse(verifier_raw, filename=verifier_path.name)
    literals = {node.value for node in ast.walk(verifier_tree)
                if isinstance(node, ast.Constant) and isinstance(node.value, str)}
    os.environ["CETECH_DE_QUALIFICATION_CANDIDATE_HEAD"] = HEAD
    os.environ["PYTHONDONTWRITEBYTECODE"] = "1"
    command = [sys.executable, str(verifier_path), *(str(path) for path in paths)]
    cli = subprocess.run(command, cwd=source, capture_output=True, timeout=120,
                         check=False, env=dict(os.environ))
    need(len(cli.stdout) <= 1024 * 1024 and len(cli.stderr) <= 1024 * 1024,
         "Verifier output exceeded diagnostic bound")
    report = {
        "format": "cetech-r24-failed-quote-diagnostic-v1",
        "diagnostic_only": True, "candidate_qualification": False,
        "repository": REPO, "run_id": RUN, "candidate_head": HEAD,
        "original_run_conclusion": "failure", "original_run_attempt": 1,
        "original_artifact_count": len(artifacts), "artifacts": copied,
        "verifier_sha256": sha(verifier_raw),
        "cli": {"returncode": cli.returncode, "stdout_bytes": len(cli.stdout),
                "stdout_sha256": sha(cli.stdout), "stderr_bytes": len(cli.stderr),
                "stderr_sha256": sha(cli.stderr),
                "closed_stdout": (
                    "quote_placement_receipts=FAIL" if cli.stdout.strip()
                    == b"quote_placement_receipts=FAIL" else "other")},
    }
    verifier = import_path("_r24_failed_quote_verifier", verifier_path)
    unused, error, output = captured(
        lambda: verifier.main([str(path) for path in paths]))
    report["unchanged_main"] = {
        "completed": error is None, "captured_output": output,
        "failure": failure(error, literals, qualification) if error else None,
    }
    stage = "load"
    stage_results = []
    try:
        reports = [verifier.load(str(path)) for path in paths]
        stage_results.append({"stage": stage, "completed": True})
        stage = "authority"
        expected = verifier.authority()
        stage_results.append({"stage": stage, "completed": True})
        report["source_expected"] = {
            "native_baseline_count": expected.native_baseline_count,
            "native_baseline_ids_sha256": expected.native_baseline_hash,
            "http_baseline_count": expected.http_baseline_count,
            "http_baseline_ids_sha256": expected.http_baseline_hash,
            "http_selected_count": len(expected.http.REQUIRED_IDS),
            "http_selected_ids_sha256": sha(canon(expected.http.REQUIRED_IDS)),
        }
        report["inventory"] = {
            kind: inventory(value, verifier.CPT_PREFIX if kind == "cpt"
                            else verifier.NATIVE_PREFIX if kind == "native"
                            else expected.http.PREFIX)
            for (kind, *unused), value in zip(ITEMS, reports)}
        report["environment"] = {
            item[0]: environment(value) for item, value in zip(ITEMS, reports)}
        report["http_public"] = http_public(reports[2])
        all_cases = []
        for kind, value in zip(("native", "cpt", "http"), reports):
            stage = "report_common_" + kind
            all_cases.append(verifier.report_common(value, expected, kind))
            stage_results.append({"stage": stage, "completed": True})
        native_all, cpt_all, http_all = all_cases
        stage = "baseline_native"
        verifier.baseline(native_all, verifier.NATIVE_PREFIX,
                          expected.native_baseline_count, expected.native_baseline_hash)
        stage_results.append({"stage": stage, "completed": True})
        stage = "baseline_http"
        verifier.baseline(http_all, expected.http.PREFIX,
                          expected.http_baseline_count, expected.http_baseline_hash)
        stage_results.append({"stage": stage, "completed": True})
        stage = "native_cases_hpos"
        verifier.native_cases(native_all, expected, True)
        stage_results.append({"stage": stage, "completed": True})
        stage = "native_cases_cpt"
        cpt_selected = verifier.native_cases(cpt_all, expected, False)
        verifier.require(len(cpt_all) == len(cpt_selected), "Extra CPT qualification case")
        stage_results.append({"stage": stage, "completed": True})
        stage = "http_cases"
        verifier.http_cases(http_all, expected)
        stage_results.append({"stage": stage, "completed": True})
        stage = "http_completion"
        verifier.http_completion(reports[2], expected)
        stage_results.append({"stage": stage, "completed": True})
    except Exception as caught:
        stage_results.append({"stage": stage, "completed": False,
                              "failure": failure(caught, literals, qualification)})
    report["stages"] = stage_results
    need(not run_git(source, "status", "--porcelain"),
         "Candidate changed during read-only diagnosis")
    report["candidate_clean_after"] = True
    report["status"] = "DIAGNOSTIC_COMPLETE"
    bootstrap.write_new(directory / "r24-quote-failure-diagnostic.json",
                        json.dumps(report, sort_keys=True, ensure_ascii=False,
                                   separators=(",", ":")).encode("utf-8") + b"\n")
    print("r24_failed_quote_diagnostic=COMPLETE candidate_qualification=false")

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--bootstrap", required=True)
    parser.add_argument("--source", required=True)
    parser.add_argument("--directory", required=True)
    execute(parser.parse_args())

if __name__ == "__main__":
    try:
        main()
    except Exception:
        print("r24_failed_quote_diagnostic=REFUSED", file=sys.stderr)
        raise SystemExit(1)
