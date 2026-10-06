"""Separate first-listener smoke diagnostics; publish only an explicit allowlist."""
import hashlib
import json
import re
import sys
from pathlib import Path


def safe_stack(path):
    try:
        raw = json.loads(path.read_text())
    except (OSError, ValueError):
        return None
    if not isinstance(raw, dict) or raw.get("format") != "cetech-opening-native-stack-v2":
        return None
    frames = []
    items = raw.get("frames", [])
    if not isinstance(items, list):
        items = []
    for item in items[:32]:
        if not isinstance(item, dict):
            continue
        frame = {"depth": len(frames), "mapping_known": item.get("mapping_known") is True}
        for key, pattern in (("symbol", r"[A-Za-z_][A-Za-z0-9_:.$~]{0,159}"),
                             ("module", r"[A-Za-z0-9_.+-]{1,100}"),
                             ("source_file", r"[A-Za-z0-9_.+-]{1,100}")):
            value = item.get(key)
            frame[key] = value if isinstance(value, str) and re.fullmatch(pattern, value) else None
        line = item.get("source_line")
        frame["source_line"] = line if type(line) is int and 0 < line <= 1000000 else None
        frames.append(frame)
    validation = raw.get("symbol_validation", {})
    if not isinstance(validation, dict):
        validation = {}
    safe = {key: validation.get(key) is True for key in
            ("exact_executable_loaded", "zend_execute_full_symbol", "zend_execute_data_type")}
    safe["status"] = "PASS" if validation.get("status") == "PASS" else "FAIL"
    for key in ("executable_build_id", "matching_separate_debug_build_id"):
        value = validation.get(key)
        safe[key] = value if isinstance(value, str) and re.fullmatch(r"[a-f0-9]{16,128}", value) else None
    value = validation.get("executable_module")
    safe["executable_module"] = value if isinstance(value, str) and re.fullmatch(r"[A-Za-z0-9_.+-]{1,100}", value) else None
    status = raw.get("status")
    return {"status": status if status in ("preflight_pass", "preflight_fail", "symbols_captured", "symbols_unavailable", "partial_symbols", "debugger_unavailable") else "unreadable",
            "symbol_validation": safe, "frames": frames}


def receipt(args):
    private, output = map(Path, args[:2])
    stage, command_exit, before, waited, signal, term, stopped, restored, capture, source, candidate, tree = args[2:]
    stages = ("allocated", "debugger_preflight", "listener_start", "store_api_cart", "cart_payload", "classic_pages", "debug_log", "complete")
    stage = stage if stage in stages else "unknown"
    log = private / "php-server.log"
    raw = log.read_bytes() if log.is_file() else b""
    text = raw[:262144].decode("utf-8", "replace")
    codes = ["CURL_EMPTY_REPLY"] if stage == "store_api_cart" and command_exit == "52" else []
    if signal == "11":
        codes.append("PHP_SERVER_SIGSEGV")
    classes = [name for name in ("RuntimeException", "Error", "TypeError", "ParseError", "ValueError", "JsonException", "Exception")
               if re.search(r"Uncaught\s+" + name + r"(?:\s|:)", text)]
    report = {
        "format": "cetech-store-smoke-diagnostic-v1", "status": "PENDING_CLEANUP",
        "source_head": source, "candidate_head": candidate, "source_tree": tree,
        "stage": stage, "command_exit": int(command_exit),
        "request": {"method": "GET", "route": "/?rest_route=/wc/store/v1/cart"},
        "listener": {"state_before_cleanup": before if before in ("running", "exited", "absent") else "unknown",
                     "owned_wait_exit": int(waited) if waited.isdigit() else None,
                     "owned_wait_signal": int(signal) if signal.isdigit() else None,
                     "cleanup_requested_sigterm": term == "1", "stopped_and_waited": stopped == "1"},
        "server_log_sha256": hashlib.sha256(raw).hexdigest() if raw else None,
        "allowlisted_error_codes": codes, "allowlisted_error_classes": classes,
        "native_crash_diagnostic": {"capture_status": capture if capture in ("not_requested", "no_listener_core", "debugger_failed", "collected") else "unknown",
                                    "stack": safe_stack(private / "stack.json"), "debugger_preflight": safe_stack(private / "preflight.json")},
        "cleanup": {"kernel_core_pattern_restored": restored == "1", "private_files_removed": False},
        "raw_core_log_or_response_published": False,
        "limits": ["Separate clean Store API smoke, not native or authenticated HTTP opening qualification.",
                   "Empty reply alone does not establish SIGSEGV. Native frames narrow location, not product causation."]}
    output.write_text(json.dumps(report, indent=2) + "\n")


def finalize(path, removed, interrupted="0"):
    report = json.loads(Path(path).read_text())
    report["cleanup"]["private_files_removed"] = removed == "1"
    report["cleanup"]["interrupted_by_signal"] = int(interrupted) if interrupted in ("130", "143") else None
    passed = (report["command_exit"] == 0 and report["stage"] == "complete"
              and report["listener"]["stopped_and_waited"] and report["cleanup"]["kernel_core_pattern_restored"]
              and report["cleanup"]["private_files_removed"]
              and report["cleanup"]["interrupted_by_signal"] is None
              and report["listener"]["owned_wait_signal"] in (None, 15)
              and not report["allowlisted_error_codes"] and not report["allowlisted_error_classes"])
    report["status"] = "PASS" if passed else "FAIL"
    Path(path).write_text(json.dumps(report, indent=2) + "\n")
    return 0 if passed else 1


if __name__ == "__main__":
    if sys.argv[1] == "--finalize":
        sys.exit(finalize(*sys.argv[2:]))
    receipt(sys.argv[1:])
