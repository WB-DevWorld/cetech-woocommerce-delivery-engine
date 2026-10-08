#!/usr/bin/env bash
# Real HTTP admin qualification on the preceding native fixture only.
# Invoked after the first clean-site listener has been killed AND waited for.
set +x
set -euo pipefail
umask 077

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${1:?HTTP qualification needs the existing CI work directory}"
SITE="${2:?HTTP qualification needs the third fresh native site}"
if [[ "${CETECH_DE_NATIVE_OPENING_QUALIFICATION:-0}" != "1" || "${CETECH_DE_HTTP_OPENING_QUALIFICATION:-0}" != "1" || "${CETECH_DE_WP_DB_HOST:-}" != "127.0.0.1" ]]; then
    echo "BLOCKED: HTTP qualification requires explicit disposable loopback fixture flags" >&2
    exit 1
fi
WORK="$(realpath "$WORK")"
SITE="$(realpath "$SITE")"
if [[ "$SITE" != "$WORK/opening-qualification" || ! -f "$WORK/wp-cli.phar" || ! -f "$WORK/opening-qualification-results.json" ]]; then
    echo "BLOCKED: HTTP qualification must reuse the exact third native CI fixture" >&2
    exit 1
fi
PRIVATE="$WORK/opening-http-private"
RECEIPT="$WORK/opening-http-qualification-results.json"
MU="$SITE/wp-content/mu-plugins/cetech-opening-http-fixture.php"
EMERGENCY_MU="$SITE/wp-content/mu-plugins/cetech-opening-emergency-fixture.php"
QUOTE_CART_MU="$SITE/wp-content/mu-plugins/cetech-opening-quote-cart-fixture.php"
if [[ -e "$PRIVATE" || -L "$PRIVATE" || -e "$MU" || -L "$MU" || -e "$EMERGENCY_MU" || -L "$EMERGENCY_MU" || -e "$QUOTE_CART_MU" || -L "$QUOTE_CART_MU" || -e "$RECEIPT" || -L "$RECEIPT" ]]; then
    echo "BLOCKED: HTTP qualification refuses an existing credential, MU, or receipt allocation" >&2
    exit 1
fi
mkdir -m 700 "$PRIVATE"
mkdir -p "$(dirname "$MU")"
export CETECH_DE_HTTP_FIXTURE_SITE="$SITE"
export CETECH_DE_HTTP_WORK_PATH="$WORK"
export CETECH_DE_HTTP_PRIVATE_DIR="$PRIVATE"
export CETECH_DE_HTTP_NATIVE_RECEIPT="$WORK/opening-qualification-results.json"
export CETECH_DE_HTTP_PROBE_TOKEN="$(php -r 'echo bin2hex(random_bytes(24));')"
PHP_EXECUTABLE="$(readlink -f "$(command -v php)")"
CRASH_DIAGNOSTIC="${CETECH_DE_HTTP_CRASH_DIAGNOSTIC:-0}"
LISTENER_ARGS=()
case "${CETECH_DE_HTTP_LISTENER_JIT:-}" in
    '') ;;
    disable)
        if [[ "${GITHUB_ACTIONS:-}" != "true" || "$CRASH_DIAGNOSTIC" != "1" ]]; then
            echo "BLOCKED: listener JIT policy requires the disposable CI qualification fixture" >&2
            exit 1
        fi
        LISTENER_ARGS=(-d opcache.jit=disable)
        ;;
    *) echo "BLOCKED: unsupported listener JIT policy" >&2; exit 1 ;;
esac
CORE_PATTERN_CHANGED=0
CORE_PATTERN_ORIGINAL=""
LISTENER_CORE_PID=""
WP=("$PHP_EXECUTABLE" "$WORK/wp-cli.phar" --allow-root --path="$SITE" --require="$ROOT/scripts/qualification/admin-context.php")
IMPORT_BRIDGE="$ROOT/scripts/qualification/opening-http-fixture.php"
CONFIG_BRIDGE="$ROOT/scripts/qualification/opening-http-configuration-fixture.php"
EMERGENCY_BRIDGE="$ROOT/scripts/qualification/opening-http-emergency-fixture.php"
QUOTE_CART_BRIDGE="$ROOT/scripts/qualification/opening-http-quote-cart-fixture.php"
IMPORT_STATE="$PRIVATE/import-state.json"
CONFIG_STATE="$PRIVATE/config-state.json"
EMERGENCY_STATE="$PRIVATE/emergency-state.json"
QUOTE_CART_STATE="$PRIVATE/quote-cart-state.json"
export CETECH_DE_HTTP_EMERGENCY_STATE="$EMERGENCY_STATE"
export CETECH_DE_HTTP_QUOTE_CART_STATE="$QUOTE_CART_STATE"
IMPORT_PREPARE="$PRIVATE/import-prepare-output.json"
SERVER_PID=""
SERVER_STARTED=0
LISTENER_ATTESTED=0
MU_CREATED=0
EMERGENCY_MU_CREATED=0
QUOTE_CART_MU_CREATED=0
MU_REMOVED=0
ROW_CLEANUP=1
QUALIFICATION_STAGE="allocated"

python3 - "$RECEIPT" <<'PY'
import json, os, sys
from pathlib import Path
report = {
    "format": "cetech-opening-http-qualification-v1", "status": "RUNNING", "cases": [],
    "source_head": os.environ.get("CETECH_DE_QUALIFICATION_HEAD", ""),
    "candidate_head": os.environ.get("CETECH_DE_QUALIFICATION_CANDIDATE_HEAD", ""),
    "source_tree": os.environ.get("CETECH_DE_QUALIFICATION_TREE", ""),
    "limits": ["Only the pinned disposable Blocks browser and instrumented fixture gateway are exercised; broader theme, persistent-cache, external-payment and release qualification remain separate.", "Managed fixture cleanup does not certify rollback of Action Scheduler or ancillary WordPress state; the CI service/site are disposable."],
}
Path(sys.argv[1]).write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
PY

finish_http_fixture() {
    local result=$?
    trap - EXIT
    set +e
    local listener_exit="absent"
    local listener_signal=""
    local listener_wait_exit="absent"
    local listener_wait_signal=""
    local listener_cleanup_term=0
    if [[ -n "${SERVER_PID:-}" ]]; then
        if kill -0 "$SERVER_PID" 2>/dev/null; then
            listener_exit="running"
        else
            wait "$SERVER_PID" 2>/dev/null
            listener_exit="$?"
            listener_wait_exit="$listener_exit"
            if [[ "$listener_exit" -gt 128 ]]; then
                listener_signal="$(( listener_exit - 128 ))"
                listener_wait_signal="$listener_signal"
            fi
            SERVER_PID=""
        fi
    fi
    local server_stopped=1
    if [[ -n "$SERVER_PID" ]]; then
        if kill -0 "$SERVER_PID" 2>/dev/null; then
            kill "$SERVER_PID" 2>/dev/null
            if [[ "$?" != "0" ]]; then server_stopped=0; else listener_cleanup_term=1; fi
        fi
        wait "$SERVER_PID" 2>/dev/null
        listener_wait_exit="$?"
        if [[ "$listener_wait_exit" -gt 128 ]]; then listener_wait_signal="$(( listener_wait_exit - 128 ))"; fi
        if kill -0 "$SERVER_PID" 2>/dev/null; then server_stopped=0; fi
    fi
    # Wait/stop the owned listener before reading its core, so dump completion
    # precedes GDB. Arguments and memory remain in the private directory.
    local crash_status="not_requested"
    if [[ "$CRASH_DIAGNOSTIC" == "1" ]]; then
        crash_status="no_listener_core"
        if [[ -n "$LISTENER_CORE_PID" && -f "$PRIVATE/core.$LISTENER_CORE_PID" && ! -L "$PRIVATE/core.$LISTENER_CORE_PID" ]]; then
            crash_status="debugger_failed"
            CETECH_DE_HTTP_STACK_OUTPUT="$PRIVATE/native-stack.json" CETECH_DE_HTTP_DEBUG_EXECUTABLE="$PHP_EXECUTABLE" \
                timeout 20s gdb -nx -batch \
                -iex 'set auto-load off' -iex 'set debuginfod enabled off' \
                -iex 'set print frame-arguments none' \
                -ex "source $ROOT/scripts/qualification/opening-http-native-stack.py" \
                "$PHP_EXECUTABLE" "$PRIVATE/core.$LISTENER_CORE_PID" \
                >"$PRIVATE/native-debugger.log" 2>&1
            if [[ "$?" == "0" && -f "$PRIVATE/native-stack.json" ]]; then crash_status="collected"; fi
        fi
    fi
    local core_pattern_restored=1
    if [[ "$CORE_PATTERN_CHANGED" == "1" ]]; then
        printf '%s\n' "$CORE_PATTERN_ORIGINAL" | sudo -n tee /proc/sys/kernel/core_pattern >"$PRIVATE/core-restore.log" 2>&1
        if [[ "$?" != "0" ]]; then core_pattern_restored=0; result=1; fi
    fi
    # Stop the listener and restore core handling before database cleanup can
    # wait on any fixture locks. Touch only identity-tracked fixture rows.
    if [[ -f "$QUOTE_CART_STATE" && -f "$QUOTE_CART_BRIDGE" ]]; then
        "${WP[@]}" eval-file "$QUOTE_CART_BRIDGE" cleanupquotecart "$QUOTE_CART_STATE" "$PRIVATE/quote-cart-trap-cleanup.json" >"$PRIVATE/quote-cart-cleanup-command.log" 2>&1
        if [[ "$?" != "0" ]]; then ROW_CLEANUP=0; fi
    fi
    if [[ -f "$EMERGENCY_STATE" && -f "$EMERGENCY_BRIDGE" ]]; then
        "${WP[@]}" eval-file "$EMERGENCY_BRIDGE" cleanupemergency "$EMERGENCY_STATE" "$PRIVATE/emergency-trap-cleanup.json" >"$PRIVATE/emergency-cleanup-command.log" 2>&1
        if [[ "$?" != "0" ]]; then ROW_CLEANUP=0; fi
    fi
    if [[ -f "$CONFIG_STATE" && -f "$CONFIG_BRIDGE" ]]; then
        "${WP[@]}" eval-file "$CONFIG_BRIDGE" cleanupconfig "$CONFIG_STATE" "$PRIVATE/config-trap-cleanup.json" >"$PRIVATE/config-cleanup-command.log" 2>&1
        if [[ "$?" != "0" ]]; then ROW_CLEANUP=0; fi
    fi
    if [[ -f "$IMPORT_STATE" ]]; then
        "${WP[@]}" eval-file "$IMPORT_BRIDGE" cleanup "$IMPORT_STATE" "$PRIVATE/import-trap-cleanup.json" >"$PRIVATE/import-cleanup-command.log" 2>&1
        if [[ "$?" != "0" ]]; then ROW_CLEANUP=0; fi
    fi
    if [[ "$MU_CREATED" == "1" && -f "$MU" ]]; then
        if cmp -s "$ROOT/scripts/qualification/opening-http-mu.php" "$MU"; then
            rm "$MU"
            if [[ ! -e "$MU" ]]; then MU_REMOVED=1; fi
        fi
    elif [[ "$MU_CREATED" == "0" ]]; then
        MU_REMOVED=1
    fi
    if [[ "$EMERGENCY_MU_CREATED" == "1" && -f "$EMERGENCY_MU" ]]; then
        if cmp -s "$ROOT/scripts/qualification/opening-http-emergency-mu.php" "$EMERGENCY_MU"; then
            rm "$EMERGENCY_MU"
        fi
        if [[ -e "$EMERGENCY_MU" ]]; then MU_REMOVED=0; fi
    fi
    if [[ "$QUOTE_CART_MU_CREATED" == "1" && -f "$QUOTE_CART_MU" ]]; then
        if cmp -s "$ROOT/scripts/qualification/opening-http-quote-cart-mu.php" "$QUOTE_CART_MU"; then
            rm "$QUOTE_CART_MU"
        fi
        if [[ -e "$QUOTE_CART_MU" ]]; then MU_REMOVED=0; fi
    fi
    # Presence is the final cleanup truth, even when allocation/copy was refused.
    if [[ -e "$MU" || -L "$MU" || -e "$EMERGENCY_MU" || -L "$EMERGENCY_MU" || -e "$QUOTE_CART_MU" || -L "$QUOTE_CART_MU" ]]; then MU_REMOVED=0; fi
    local db_connect="absent"
    local db_wait_ms=""
    if [[ -n "${CETECH_DE_WP_DB_PORT:-}" ]]; then
        local db_probe=""
        db_probe="$(python3 - <<'PY'
import os, socket, time
port = int(os.environ.get("CETECH_DE_WP_DB_PORT", "0") or "0")
started = time.monotonic()
result = "unanswered"
if port > 0:
    try:
        with socket.create_connection(("127.0.0.1", port), 2):
            result = "connected"
    except OSError:
        result = "unanswered"
print(result + " " + str(int((time.monotonic() - started) * 1000)))
PY
)"
        db_connect="${db_probe%% *}"
        db_wait_ms="${db_probe##* }"
    fi
    # Retain only an allowlisted diagnostic code/class and a one-way log hash.
    # Raw WP-CLI/bootstrap output, paths, SQL, credentials and payloads stay
    # private and are deleted immediately below, including on early failures.
    local diagnostic_log="$PRIVATE/import-prepare-command.log"
    if [[ "$QUALIFICATION_STAGE" == "origin_preflight" ]]; then diagnostic_log="$PRIVATE/origin-preflight-command.log"; fi
    if [[ "$QUALIFICATION_STAGE" == "http_driver" || "$QUALIFICATION_STAGE" == "complete" ]]; then diagnostic_log="$PRIVATE/php-server.log"; fi
    python3 - "$RECEIPT" "$diagnostic_log" "$QUALIFICATION_STAGE" "$PRIVATE/origin-preflight-output.json" "$listener_exit" "$listener_signal" "$PRIVATE/php-server.log" "$db_connect" "$db_wait_ms" "$PRIVATE/native-stack.json" "$crash_status" "$core_pattern_restored" "$PRIVATE/runtime.json" "$listener_wait_exit" "$listener_wait_signal" "$listener_cleanup_term" "$PRIVATE/debugger-preflight.json" "$PRIVATE/synthetic-stack.json" <<'PY'
import hashlib, json, re, sys
from pathlib import Path
receipt = Path(sys.argv[1])
log = Path(sys.argv[2])
server_log = Path(sys.argv[7]) if len(sys.argv) > 7 else Path("")
try:
    report = json.loads(receipt.read_text(encoding="utf-8"))
except (OSError, ValueError):
    report = {"format": "cetech-opening-http-qualification-v1", "status": "FAIL", "cases": []}
raw = log.read_bytes() if log.is_file() else b""
text = raw[:262144].decode("utf-8", "replace")
messages = {
    "HTTP_MU_FIXTURE_GUARD": "HTTP fixture MU plugin refused an unmarked or different disposable site.",
    "HTTP_COMMON_FIXTURE_GUARD": "Refusing HTTP fixture access outside the marked, exact-path disposable loopback CI site.",
    "HTTP_NATIVE_RECEIPT_REQUIRED": "HTTP qualification requires the native PASS receipt from this fresh fixture.",
    "HTTP_INSTALLED_SOURCE_IDENTITY": "HTTP installed production sources diverged from the same-run native qualification receipt.",
    "HTTP_IMPORT_ARGUMENTS": "Usage: opening-http-fixture.php MODE PRIVATE_STATE OUTPUT [JOB_ID]",
    "HTTP_IMPORT_STATE_EXISTS": "Refusing to overwrite an existing HTTP credential state.",
    "HTTP_IMPORT_PROBE_TOKEN": "HTTP fixture preparation requires a fresh unpredictable listener token.",
    "HTTP_IMPORT_ROLE_CREATE": "Could not create the exclusive synthetic HTTP import role.",
    "HTTP_IMPORT_PRINCIPAL_CREATE": "Could not create the synthetic HTTP import principal.",
    "HTTP_OUTPUT_PATH_GUARD": "HTTP fixture output must be an ordinary file outside the WordPress document root.",
    "HTTP_JSON_PERSIST": "Could not persist the HTTP fixture JSON.",
    "HTTP_HTTP_MARKER_PERSIST": "Could not persist the HTTP qualification fixture marker.",
    "HTTP_IMPORT_PRINCIPAL_GRANTS": "Synthetic HTTP import grants or public fixture uniqueness diverged.",
    "HTTP_SQL_SNAPSHOT": "HTTP fixture SQL snapshot failed:",
    "HTTP_ROLE_SQL_READ": "Could not read physical fixture role capabilities.",
    "HTTP_PRIVATE_STATE_GUARD": "HTTP fixture state must be a private ordinary file outside the document root.",
    "HTTP_PRIVATE_STATE_SITE": "HTTP fixture state belongs to a different disposable site.",
    "HTTP_IMPORT_STATE_FORMAT": "Unrecognized HTTP import fixture state.",
}
codes = [code for code, literal in messages.items() if literal in text]
for prefix in ("HTTP_MU_GUARD_", "HTTP_COMMON_GUARD_", "HTTP_ORIGIN_PREFLIGHT_"):
    for condition in ("FLAGS", "DB_HOST", "DB_NAME", "SITE_PATH", "WORK_PATH", "PRIVATE_PATH", "MARKER", "CRON", "SITEURL", "HOME", "NATIVE_CONTEXT", "PLUGIN_CLASSES", "NATIVE_STORAGE_CONTEXT", "OUTPUT_PATH", "RECEIPT_WRITE", "URL_SQL", "EXACT_READBACK", "NATIVE_RECEIPT", "NATIVE_IDENTITY", "FAILED"):
        literal = prefix + condition
        if re.search(r"\b" + re.escape(literal) + r"\b", text):
            codes.append(literal)
if "Call to undefined function " in text:
    codes.append("PHP_UNDEFINED_FUNCTION")
if "Class " in text and " not found" in text:
    codes.append("PHP_CLASS_NOT_FOUND")
if "Call to undefined method " in text:
    codes.append("PHP_UNDEFINED_METHOD")
if "must be the very first statement" in text:
    codes.append("PHP_STRICT_TYPES_POSITION")
if "Segmentation fault" in text or sys.argv[6] == "11" or sys.argv[15] == "11":
    codes.append("PHP_SERVER_SIGSEGV")
classes = [name for name in ("RuntimeException", "Error", "TypeError", "ParseError", "ValueError", "JsonException", "Exception") if re.search(r"Uncaught\s+" + name + r"(?:\s|:)", text)]
report["fixture_diagnostic"] = {
    "stage": sys.argv[3] if sys.argv[3] in ("allocated", "debugger_preflight", "synthetic_core_validation", "origin_preflight", "mu_copy", "import_prepare", "listener_preflight", "listener_readiness", "http_driver", "complete") else "unknown_stage",
    "command_log_present": log.is_file(),
    "command_log_sha256": hashlib.sha256(raw).hexdigest() if raw else None,
    "allowlisted_error_codes": sorted(set(codes)),
    "allowlisted_error_classes": classes,
    "raw_output_retained": False,
    "listener_exit_before_cleanup": sys.argv[5] if len(sys.argv) > 5 else "absent",
    "listener_signal_before_cleanup": sys.argv[6] or None if len(sys.argv) > 6 else None,
    "owned_listener_wait_exit": sys.argv[14],
    "owned_listener_wait_signal": sys.argv[15] or None,
    "listener_cleanup_requested_sigterm": sys.argv[16] == "1",
    "server_log_present": server_log.is_file(),
    "server_log_sha256": hashlib.sha256(server_log.read_bytes()).hexdigest() if server_log.is_file() else None,
    "database_connect_before_cleanup": sys.argv[8] if len(sys.argv) > 8 and sys.argv[8] in ("connected", "unanswered", "absent") else "absent",
    "database_connect_wait_ms": int(sys.argv[9]) if len(sys.argv) > 9 and sys.argv[9].isdigit() else None,
}
report["native_crash_diagnostic"] = {
    "capture_status": sys.argv[11], "kernel_core_pattern_restored": sys.argv[12] == "1",
    "raw_core_or_debugger_output_retained": False, "frames": [],
    "limits": "Symbols narrow native execution location; they do not establish a product defect. Prior failure did not record an INI fingerprint.",
}
def safe_stack(path):
    try:
        stack = json.loads(path.read_text(encoding="utf-8"))
        if stack.get("format") == "cetech-opening-native-stack-v2":
            frames = []
            for frame in stack.get("frames", [])[:32]:
                symbol = frame.get("symbol")
                module = frame.get("module")
                source = frame.get("source_file")
                line = frame.get("source_line")
                frames.append({"depth": len(frames),
                    "symbol": symbol if isinstance(symbol, str) and re.fullmatch(r"[A-Za-z_][A-Za-z0-9_:.$~]{0,159}", symbol) else None,
                    "module": module if isinstance(module, str) and re.fullmatch(r"[A-Za-z0-9_.+-]{1,100}", module) else None,
                    "mapping_known": frame.get("mapping_known") is True,
                    "source_file": source if isinstance(source, str) and re.fullmatch(r"[A-Za-z0-9_.+-]{1,100}", source) else None,
                    "source_line": line if type(line) is int and 0 < line <= 1000000 else None})
            validation = stack.get("symbol_validation", {})
            safe_validation = {key: validation.get(key) is True for key in ("exact_executable_loaded", "zend_execute_full_symbol", "zend_execute_data_type")}
            safe_validation["status"] = validation.get("status") if validation.get("status") in ("PASS", "FAIL") else "FAIL"
            for key in ("executable_build_id", "matching_separate_debug_build_id"):
                value = validation.get(key)
                safe_validation[key] = value if isinstance(value, str) and re.fullmatch(r"[a-f0-9]{16,128}", value) else None
            value = validation.get("executable_module")
            safe_validation["executable_module"] = value if isinstance(value, str) and re.fullmatch(r"[A-Za-z0-9_.+-]{1,100}", value) else None
            return {"frames": frames, "symbol_validation": safe_validation,
                    "capture_status": stack.get("status") if stack.get("status") in ("symbols_captured", "symbols_unavailable", "partial_symbols", "debugger_unavailable", "preflight_pass", "preflight_fail") else "unreadable"}
    except (OSError, ValueError, TypeError):
        pass
    return {"capture_status": "unreadable", "frames": []}
stack_path = Path(sys.argv[10])
if stack_path.is_file():
    report["native_crash_diagnostic"].update(safe_stack(stack_path))
for key, argument in (("debugger_preflight", 17), ("synthetic_core_validation", 18)):
    path = Path(sys.argv[argument])
    if path.is_file():
        report["native_crash_diagnostic"][key] = safe_stack(path)
report["native_crash_diagnostic"]["synthetic_validation_is_product_reproduction"] = False
runtime_path = Path(sys.argv[13])
if runtime_path.is_file():
    report["diagnostic_runtime"] = json.loads(runtime_path.read_text(encoding="utf-8"))
origin_path = Path(sys.argv[4])
if origin_path.is_file():
    try:
        origin = json.loads(origin_path.read_text(encoding="utf-8"))
        if origin.get("format") == "cetech-opening-http-origin-preflight-v1" and origin.get("expected_origin") == "http://127.0.0.1:8085":
            report["fixture_origin_preflight"] = {key: origin[key] for key in ("format", "status", "expected_origin", "same_run_native_pass_before_mutations", "before", "after", "option_update_returns", "identity", "installed_php_source_files", "error_class", "error_code") if key in origin}
    except (OSError, ValueError, TypeError):
        report["fixture_origin_preflight"] = {"status": "UNREADABLE", "raw_output_retained": False}
receipt.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
PY
    if [[ "$?" != "0" ]]; then result=1; fi
    # Only this newly allocated, exact directory is removed. It contains all
    # passwords, package-private markers, cookies, nonces and server responses.
    rm -rf "$PRIVATE"
    local private_removed=0
    if [[ ! -e "$PRIVATE" ]]; then private_removed=1; fi
    if [[ "$server_stopped" != "1" || "$MU_REMOVED" != "1" || "$private_removed" != "1" || "$ROW_CLEANUP" != "1" ]]; then result=1; fi
    python3 - "$RECEIPT" "$result" "$SERVER_STARTED" "$LISTENER_ATTESTED" "$server_stopped" "$MU_REMOVED" "$private_removed" "$ROW_CLEANUP" "$WORK/opening-qualification-results.json" <<'PY'
import hashlib, json, sys
from pathlib import Path
path = Path(sys.argv[1])
try:
    report = json.loads(path.read_text(encoding="utf-8"))
except (OSError, ValueError):
    report = {"format": "cetech-opening-http-qualification-v1", "status": "FAIL", "cases": []}
result, started, attested, stopped, mu_removed, private_removed, tracked_cleanup = map(int, sys.argv[2:9])
cases = report.setdefault("cases", [])
origin = report.get("fixture_origin_preflight", {})
after = origin.get("after", {})
try:
    native = json.loads(Path(sys.argv[9]).read_text(encoding="utf-8"))
except (OSError, ValueError):
    native = {}
sources = native.get("installed_php_sources", {})
identity = origin.get("identity", {})
source_identity_verified = (
    native.get("status") == "PASS"
    and isinstance(sources, dict) and bool(sources)
    and sources == report.get("installed_php_sources")
    and type(origin.get("installed_php_source_files")) is int
    and origin["installed_php_source_files"] == len(sources)
    and native.get("installed_php_sources_hash") == hashlib.sha256(json.dumps(sources, sort_keys=True, separators=(",", ":")).encode("utf-8")).hexdigest()
    and native.get("installed_php_sources_hash") == report.get("installed_php_sources_hash") == identity.get("installed_php_sources_hash")
    and all(native.get(key) == report.get(key) == identity.get(key) and bool(native.get(key)) for key in ("source_head", "candidate_head", "source_tree"))
)
origin_verified = origin.get("status") == "PASS" and origin.get("same_run_native_pass_before_mutations") is True and source_identity_verified and all(after.get(layer, {}).get(option, {}).get("exact_expected") is True for layer in ("physical", "native") for option in ("home", "siteurl"))
new_cases = [
    ("HTTP-FIXTURE-EXACT-LOOPBACK-ORIGIN", origin_verified, {"same_run_native_pass_before_mutations": origin.get("same_run_native_pass_before_mutations") is True, "physical_and_native_home_siteurl_exact": origin_verified, "origin": "http://127.0.0.1:8085", "source_map_files": origin.get("installed_php_source_files")}),
    ("HTTP-FIXTURE-OWNED-LISTENER-ATTESTED", started == 1 and attested == 1, {"loopback": "127.0.0.1:8085", "actual_docroot": "exact third fresh native site", "pid_liveness_and_header_token": attested == 1}),
    ("HTTP-FIXTURE-OWNED-LISTENER-STOPPED", stopped == 1, {"owned_pid_waited": started == 1 and stopped == 1, "listener_never_started": started == 0}),
    ("HTTP-FIXTURE-MU-AND-CREDENTIAL-FILES-REMOVED", mu_removed == 1 and private_removed == 1, {"fixture_mu_removed": mu_removed == 1, "private_directory_removed": private_removed == 1}),
]
for case_id, passed, evidence in new_cases:
    if any(case.get("id") == case_id for case in cases):
        result = 1
        continue
    cases.append({"id": case_id, "status": "PASS" if passed else "FAIL", "evidence": evidence})
    if not passed:
        result = 1
report["fixture_lifecycle"] = {"listener_started": started == 1, "owned_listener_attested": attested == 1, "listener_stopped": stopped == 1, "fixture_mu_removed": mu_removed == 1, "private_files_removed": private_removed == 1, "tracked_cleanup_command_success": tracked_cleanup == 1, "partial_init_failure_fallback": "entire dedicated CI database/site/service are disposable; no total-database rollback claim"}
if result or report.get("status") != "PASS" or any(case.get("status") != "PASS" for case in cases):
    report["status"] = "FAIL"
    report.setdefault("error", "HTTP route or fixture lifecycle qualification failed; credentials and responses were removed.")
path.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
sys.exit(0 if report.get("status") == "PASS" else 1)
PY
    if [[ "$?" != "0" ]]; then result=1; fi
    exit "$result"
}
trap finish_http_fixture EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if [[ "$CRASH_DIAGNOSTIC" == "1" ]]; then
    if [[ "${GITHUB_ACTIONS:-}" != "true" || ! -x "$(command -v gdb)" ]]; then
        echo "BLOCKED: native crash capture requires the disposable GitHub runner and gdb" >&2
        exit 1
    fi
    QUALIFICATION_STAGE="debugger_preflight"
    CETECH_DE_HTTP_STACK_OUTPUT="$PRIVATE/debugger-preflight.json" CETECH_DE_HTTP_DEBUG_EXECUTABLE="$PHP_EXECUTABLE" CETECH_DE_HTTP_DEBUG_MODE=preflight \
        timeout 20s gdb -nx -batch -iex 'set auto-load off' -iex 'set debuginfod enabled off' \
        -ex "source $ROOT/scripts/qualification/opening-http-native-stack.py" "$PHP_EXECUTABLE" \
        >"$PRIVATE/debugger-preflight.log" 2>&1
    python3 - "$PRIVATE/debugger-preflight.json" <<'PY'
import json, sys
from pathlib import Path
report = json.loads(Path(sys.argv[1]).read_text())
if report.get("status") != "preflight_pass" or report.get("symbol_validation", {}).get("status") != "PASS":
    raise SystemExit("BLOCKED: GDB did not load matching PHP debug files and full Zend metadata")
PY
    CORE_PATTERN_ORIGINAL="$(cat /proc/sys/kernel/core_pattern)"
    # Mark restoration required before attempting the privileged mutation.
    CORE_PATTERN_CHANGED=1
    printf '%s\n' "$PRIVATE/core.%p" | sudo -n tee /proc/sys/kernel/core_pattern >"$PRIVATE/core-setup.log" 2>&1
    ulimit -c unlimited
    # Separate CLI process: prove this executable's owned core can be decoded
    # before the real listener starts. This is not a product reproduction.
    QUALIFICATION_STAGE="synthetic_core_validation"
    "$PHP_EXECUTABLE" -r 'posix_kill(getmypid(), 11);' >"$PRIVATE/synthetic-command.log" 2>&1 &
    SYNTHETIC_PID=$!
    synthetic_exit=0
    wait "$SYNTHETIC_PID" 2>>"$PRIVATE/synthetic-command.log" || synthetic_exit=$?
    if [[ "$synthetic_exit" != "139" || ! -f "$PRIVATE/core.$SYNTHETIC_PID" || -L "$PRIVATE/core.$SYNTHETIC_PID" ]]; then
        echo "BLOCKED: synthetic owned PHP core was not produced" >&2
        exit 1
    fi
    CETECH_DE_HTTP_STACK_OUTPUT="$PRIVATE/synthetic-stack.json" CETECH_DE_HTTP_DEBUG_EXECUTABLE="$PHP_EXECUTABLE" \
        timeout 20s gdb -nx -batch -iex 'set auto-load off' -iex 'set debuginfod enabled off' \
        -iex 'set print frame-arguments none' -ex "source $ROOT/scripts/qualification/opening-http-native-stack.py" \
        "$PHP_EXECUTABLE" "$PRIVATE/core.$SYNTHETIC_PID" >"$PRIVATE/synthetic-debugger.log" 2>&1
    python3 - "$PRIVATE/synthetic-stack.json" <<'PY'
import json, sys
from pathlib import Path
report = json.loads(Path(sys.argv[1]).read_text())
validation = report.get("symbol_validation", {})
if validation.get("status") != "PASS" or not any(frame.get("module") == validation.get("executable_module") and frame.get("symbol") in ("zend_execute", "execute_ex") for frame in report.get("frames", [])):
    raise SystemExit("BLOCKED: synthetic PHP core did not resolve a Zend frame in the exact executable")
PY
    rm "$PRIVATE/core.$SYNTHETIC_PID"
fi

QUALIFICATION_STAGE="origin_preflight"
"${WP[@]}" eval-file "$ROOT/scripts/qualification/opening-http-origin-preflight.php" "$PRIVATE/origin-preflight-output.json" >"$PRIVATE/origin-preflight-command.log" 2>&1
QUALIFICATION_STAGE="mu_copy"
# Track each exact owned destination before copying: a failed partial copy
# remains an explicitly unremoved fixture rather than a false cleanup success.
MU_CREATED=1
cp "$ROOT/scripts/qualification/opening-http-mu.php" "$MU"
EMERGENCY_MU_CREATED=1
cp "$ROOT/scripts/qualification/opening-http-emergency-mu.php" "$EMERGENCY_MU"
QUOTE_CART_MU_CREATED=1
cp "$ROOT/scripts/qualification/opening-http-quote-cart-mu.php" "$QUOTE_CART_MU"
QUALIFICATION_STAGE="import_prepare"
"${WP[@]}" eval-file "$IMPORT_BRIDGE" prepare "$IMPORT_STATE" "$IMPORT_PREPARE" >"$PRIVATE/import-prepare-command.log" 2>&1

# Refuse a port collision before sending any fixture credentials. The later
# token/PID check also rejects a listener that races this availability probe.
QUALIFICATION_STAGE="listener_preflight"
python3 - <<'PY'
import socket
with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as probe:
    probe.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    try:
        probe.bind(("127.0.0.1", 8085))
    except OSError as failure:
        raise SystemExit("BLOCKED: qualification port 8085 is already occupied") from failure
PY
"$PHP_EXECUTABLE" "${LISTENER_ARGS[@]}" -S 127.0.0.1:8085 -t "$SITE" >"$PRIVATE/php-server.log" 2>&1 &
SERVER_PID=$!
LISTENER_CORE_PID="$SERVER_PID"
SERVER_STARTED=1
QUALIFICATION_STAGE="listener_readiness"
python3 - "$SERVER_PID" "$IMPORT_STATE" <<'PY'
import hashlib, json, os, sys, time
from pathlib import Path
from urllib.request import HTTPRedirectHandler, ProxyHandler, Request, build_opener
class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, file, code, message, headers, new_url):
        return None
opener = build_opener(ProxyHandler({}), NoRedirect())
pid = int(sys.argv[1])
state = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
token = state["probe_token"]
expected = {
    "format": "cetech-opening-http-owned-listener-v1",
    "probe_sha256": hashlib.sha256(token.encode()).hexdigest(),
    "site_path_sha256": hashlib.sha256(state["site_path"].encode()).hexdigest(),
    "database_name_sha256": hashlib.sha256(state["database_name"].encode()).hexdigest(),
    "source_head": os.environ["CETECH_DE_QUALIFICATION_HEAD"],
    "candidate_head": os.environ["CETECH_DE_QUALIFICATION_CANDIDATE_HEAD"],
    "source_tree": os.environ["CETECH_DE_QUALIFICATION_TREE"],
}
for _ in range(30):
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        raise SystemExit("HTTP qualification listener exited before readiness")
    try:
        request = Request(state["base_url"] + "/?cetech_opening_http_probe=1", headers={"X-CETECH-Opening-Probe": token})
        with opener.open(request, timeout=1) as response:
            payload = json.loads(response.read(8193))
        runtime = payload.pop("diagnostic_runtime", None)
        if payload != expected:
            raise SystemExit("HTTP qualification listener identity did not match the owned disposable site")
        if os.environ.get("CETECH_DE_HTTP_CRASH_DIAGNOSTIC") == "1":
            if not isinstance(runtime, dict) or runtime.get("sapi") != "cli-server" or runtime.get("php_version") != "8.5.11":
                raise SystemExit("HTTP diagnostic listener runtime differs from the recorded crash environment")
            extensions_hash = hashlib.sha256(json.dumps(runtime.get("extensions", {}), sort_keys=True, separators=(",", ":")).encode()).hexdigest()
            comparisons = {
                "binary": (runtime.get("php_binary_sha256"), os.environ.get("CETECH_DE_HTTP_EXPECT_BINARY_SHA256")),
                "ini": (runtime.get("full_ini_sha256"), os.environ.get("CETECH_DE_HTTP_EXPECT_INI_SHA256")),
                "extensions": (extensions_hash, os.environ.get("CETECH_DE_HTTP_EXPECT_EXTENSIONS_SHA256")),
            }
            if os.environ.get("CETECH_DE_HTTP_LISTENER_JIT") == "disable":
                opcache_state = runtime.get("opcache_state_at_existing_probe", {})
                jit = opcache_state.get("jit", {})
                if runtime.get("safe_ini", {}).get("opcache.jit") != "disable" or opcache_state.get("opcache_enabled") is not True or jit.get("enabled") is not False or jit.get("on") is not False:
                    raise SystemExit("Qualification listener did not retain OPcache and disable JIT")
                comparisons["original_ini_except_opcache_jit"] = (runtime.get("full_ini_jit1235_sha256"), os.environ.get("CETECH_DE_HTTP_EXPECT_ORIGINAL_INI_SHA256"))
                runtime["qualification_runtime_policy"] = {"profile": "php85-ci-opcache-jit-disabled", "scope": "disposable_web_listeners", "opcache_jit": "disable", "qualifies_jit1235_runtime": False}
            runtime["extensions_sha256"] = extensions_hash
            runtime["expected_qualification_runtime"] = {key: actual == expected_hash for key, (actual, expected_hash) in comparisons.items() if expected_hash}
            Path(os.environ["CETECH_DE_HTTP_PRIVATE_DIR"], "runtime.json").write_text(json.dumps(runtime) + "\n", encoding="utf-8")
            if any(actual != expected_hash for actual, expected_hash in comparisons.values() if expected_hash):
                raise SystemExit("HTTP qualification runtime fingerprint differs from the pinned CI profile")
        os.kill(pid, 0)
        break
    except (OSError, ValueError):
        time.sleep(0.1)
else:
    raise SystemExit("HTTP qualification listener did not become ready within the bounded probe")
PY
LISTENER_ATTESTED=1

QUALIFICATION_STAGE="http_driver"
python3 "$ROOT/scripts/qualification/opening-http-driver.py" \
    --php "$PHP_EXECUTABLE" --wpcli "$WORK/wp-cli.phar" --site "$SITE" \
    --admin-context "$ROOT/scripts/qualification/admin-context.php" \
    --bridge "$IMPORT_BRIDGE" --state "$IMPORT_STATE" --work "$PRIVATE" \
    --receipt "$RECEIPT" --prepare-output "$IMPORT_PREPARE" \
    --configuration-driver "$ROOT/scripts/qualification/opening-http-configuration-driver.py" \
    --configuration-bridge "$CONFIG_BRIDGE" --configuration-state "$CONFIG_STATE" \
    --emergency-driver "$ROOT/scripts/qualification/opening-http-emergency-driver.py" \
    --emergency-bridge "$EMERGENCY_BRIDGE" --emergency-state "$EMERGENCY_STATE" \
    --quote-cart-driver "$ROOT/scripts/qualification/opening-http-quote-cart-driver.py" \
    --quote-cart-bridge "$QUOTE_CART_BRIDGE" --quote-cart-state "$QUOTE_CART_STATE"
QUALIFICATION_STAGE="complete"

# EXIT performs final owned-listener/MU/private-file cleanup and appends its
# proof to the single durable receipt. An existing driver failure remains FAIL.
