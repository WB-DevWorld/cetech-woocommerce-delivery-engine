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
if [[ -e "$PRIVATE" || -L "$PRIVATE" || -e "$MU" || -L "$MU" || -e "$RECEIPT" || -L "$RECEIPT" ]]; then
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
PHP_EXECUTABLE="$(command -v php)"
WP=("$PHP_EXECUTABLE" "$WORK/wp-cli.phar" --allow-root --path="$SITE" --require="$ROOT/scripts/qualification/admin-context.php")
IMPORT_BRIDGE="$ROOT/scripts/qualification/opening-http-fixture.php"
CONFIG_BRIDGE="$ROOT/scripts/qualification/opening-http-configuration-fixture.php"
IMPORT_STATE="$PRIVATE/import-state.json"
CONFIG_STATE="$PRIVATE/config-state.json"
IMPORT_PREPARE="$PRIVATE/import-prepare-output.json"
SERVER_PID=""
SERVER_STARTED=0
LISTENER_ATTESTED=0
MU_CREATED=0
MU_REMOVED=0
ROW_CLEANUP=1

python3 - "$RECEIPT" <<'PY'
import json, os, sys
from pathlib import Path
report = {
    "format": "cetech-opening-http-qualification-v1", "status": "RUNNING", "cases": [],
    "source_head": os.environ.get("CETECH_DE_QUALIFICATION_HEAD", ""),
    "candidate_head": os.environ.get("CETECH_DE_QUALIFICATION_CANDIDATE_HEAD", ""),
    "source_tree": os.environ.get("CETECH_DE_QUALIFICATION_TREE", ""),
    "limits": ["No browser JavaScript/theme/cache/payment or release qualification.", "Managed fixture cleanup does not certify rollback of Action Scheduler or ancillary WordPress state; the CI service/site are disposable."],
}
Path(sys.argv[1]).write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
PY

finish_http_fixture() {
    local result=$?
    trap - EXIT
    set +e
    # On a driver failure, try only the identity-tracked fixture cleanup. The
    # completely fresh CI database/site remains the partial-init fallback.
    if [[ -f "$CONFIG_STATE" && -f "$CONFIG_BRIDGE" ]]; then
        "${WP[@]}" eval-file "$CONFIG_BRIDGE" cleanupconfig "$CONFIG_STATE" "$PRIVATE/config-trap-cleanup.json" >"$PRIVATE/config-cleanup-command.log" 2>&1
        if [[ "$?" != "0" ]]; then ROW_CLEANUP=0; fi
    fi
    if [[ -f "$IMPORT_STATE" ]]; then
        "${WP[@]}" eval-file "$IMPORT_BRIDGE" cleanup "$IMPORT_STATE" "$PRIVATE/import-trap-cleanup.json" >"$PRIVATE/import-cleanup-command.log" 2>&1
        if [[ "$?" != "0" ]]; then ROW_CLEANUP=0; fi
    fi
    local server_stopped=1
    if [[ -n "$SERVER_PID" ]]; then
        if kill -0 "$SERVER_PID" 2>/dev/null; then
            kill "$SERVER_PID" 2>/dev/null
            if [[ "$?" != "0" ]]; then server_stopped=0; fi
        fi
        wait "$SERVER_PID" 2>/dev/null
        if kill -0 "$SERVER_PID" 2>/dev/null; then server_stopped=0; fi
    fi
    if [[ "$MU_CREATED" == "1" && -f "$MU" ]]; then
        if cmp -s "$ROOT/scripts/qualification/opening-http-mu.php" "$MU"; then
            rm "$MU"
            if [[ ! -e "$MU" ]]; then MU_REMOVED=1; fi
        fi
    elif [[ "$MU_CREATED" == "0" ]]; then
        MU_REMOVED=1
    fi
    # Only this newly allocated, exact directory is removed. It contains all
    # passwords, package-private markers, cookies, nonces and server responses.
    rm -rf "$PRIVATE"
    local private_removed=0
    if [[ ! -e "$PRIVATE" ]]; then private_removed=1; fi
    if [[ "$server_stopped" != "1" || "$MU_REMOVED" != "1" || "$private_removed" != "1" || "$ROW_CLEANUP" != "1" ]]; then result=1; fi
    python3 - "$RECEIPT" "$result" "$SERVER_STARTED" "$LISTENER_ATTESTED" "$server_stopped" "$MU_REMOVED" "$private_removed" "$ROW_CLEANUP" <<'PY'
import json, sys
from pathlib import Path
path = Path(sys.argv[1])
try:
    report = json.loads(path.read_text(encoding="utf-8"))
except (OSError, ValueError):
    report = {"format": "cetech-opening-http-qualification-v1", "status": "FAIL", "cases": []}
result, started, attested, stopped, mu_removed, private_removed, tracked_cleanup = map(int, sys.argv[2:])
cases = report.setdefault("cases", [])
new_cases = [
    ("HTTP-FIXTURE-OWNED-LISTENER-ATTESTED", started == 1 and attested == 1, {"loopback": "127.0.0.1:8085", "actual_docroot": "exact third fresh native site", "pid_liveness_and_header_token": attested == 1}),
    ("HTTP-FIXTURE-OWNED-LISTENER-STOPPED", stopped == 1, {"owned_pid_waited": stopped == 1}),
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

cp "$ROOT/scripts/qualification/opening-http-mu.php" "$MU"
MU_CREATED=1
"${WP[@]}" eval-file "$IMPORT_BRIDGE" prepare "$IMPORT_STATE" "$IMPORT_PREPARE" >"$PRIVATE/import-prepare-command.log" 2>&1

# Refuse a port collision before sending any fixture credentials. The later
# token/PID check also rejects a listener that races this availability probe.
python3 - <<'PY'
import socket
with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as probe:
    probe.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    try:
        probe.bind(("127.0.0.1", 8085))
    except OSError as failure:
        raise SystemExit("BLOCKED: qualification port 8085 is already occupied") from failure
PY
"$PHP_EXECUTABLE" -S 127.0.0.1:8085 -t "$SITE" >"$PRIVATE/php-server.log" 2>&1 &
SERVER_PID=$!
SERVER_STARTED=1
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
        if payload != expected:
            raise SystemExit("HTTP qualification listener identity did not match the owned disposable site")
        os.kill(pid, 0)
        break
    except (OSError, ValueError):
        time.sleep(0.1)
else:
    raise SystemExit("HTTP qualification listener did not become ready within the bounded probe")
PY
LISTENER_ATTESTED=1

python3 "$ROOT/scripts/qualification/opening-http-driver.py" \
    --php "$PHP_EXECUTABLE" --wpcli "$WORK/wp-cli.phar" --site "$SITE" \
    --admin-context "$ROOT/scripts/qualification/admin-context.php" \
    --bridge "$IMPORT_BRIDGE" --state "$IMPORT_STATE" --work "$PRIVATE" \
    --receipt "$RECEIPT" --prepare-output "$IMPORT_PREPARE" \
    --configuration-driver "$ROOT/scripts/qualification/opening-http-configuration-driver.py" \
    --configuration-bridge "$CONFIG_BRIDGE" --configuration-state "$CONFIG_STATE"

# EXIT performs final owned-listener/MU/private-file cleanup and appends its
# proof to the single durable receipt. An existing driver failure remains FAIL.
