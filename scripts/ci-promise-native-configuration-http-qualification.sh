#!/usr/bin/env bash
# Separate P05 listener after complete retained/P02/P03 and native P05 proofs.
set +x
set -euo pipefail
umask 077
TASK_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TASK_WORK="$(realpath "${1:?P05 HTTP requires the existing qualification work directory}")"
TASK_SITE="$(realpath "${2:?P05 HTTP requires its exact marked native site}")"
if [[ "${CETECH_DE_NATIVE_OPENING_QUALIFICATION:-0}" != 1 || "${CETECH_DE_HTTP_OPENING_QUALIFICATION:-0}" != 1 || "${CETECH_DE_WP_DB_HOST:-}" != 127.0.0.1 || "$TASK_SITE" != "$TASK_WORK/opening-qualification" || ! -f "$TASK_WORK/wp-cli.phar" ]]; then
    echo 'BLOCKED: P05 HTTP requires the exact preceding marked native loopback fixture.' >&2
    exit 1
fi
TASK_PRIVATE="$TASK_WORK/opening-promise-native-configuration-http-private"
TASK_RECEIPT="$TASK_WORK/opening-http-promise-native-configuration-results.json"
TASK_STATE="$TASK_PRIVATE/quote-cart-state.json"
TASK_MU="$TASK_SITE/wp-content/mu-plugins/cetech-opening-promise-native-configuration-fixture.php"
for TASK_ALLOCATION in "$TASK_PRIVATE" "$TASK_RECEIPT" "$TASK_MU"; do
    if [[ -e "$TASK_ALLOCATION" || -L "$TASK_ALLOCATION" ]]; then
        echo 'BLOCKED: P05 refuses an existing private, receipt or MU allocation.' >&2
        exit 1
    fi
done
python3 "$TASK_ROOT/scripts/qualification/verify-opening-promise-native-configuration.py" --native-only \
    "$TASK_WORK/opening-promise-native-configuration-results.json" "$TASK_WORK/opening-promise-native-configuration-cpt-results.json" \
    "$TASK_WORK/opening-qualification-results.json" "$TASK_WORK/opening-quote-placement-cpt-results.json" \
    "$TASK_WORK/opening-http-qualification-results.json" "$TASK_WORK/opening-promise-storage-results.json" \
    "$TASK_WORK/opening-promise-calculation-results.json" "$TASK_WORK/opening-promise-handoff-results.json" \
    "$TASK_WORK/opening-promise-handoff-cpt-results.json" "$TASK_WORK/opening-http-promise-handoff-results.json"
mkdir -m 700 "$TASK_PRIVATE"
mkdir -p "$(dirname "$TASK_MU")"
export CETECH_DE_HTTP_FIXTURE_SITE="$TASK_SITE"
export CETECH_DE_HTTP_WORK_PATH="$TASK_WORK"
export CETECH_DE_HTTP_PRIVATE_DIR="$TASK_PRIVATE"
export CETECH_DE_HTTP_NATIVE_RECEIPT="$TASK_WORK/opening-qualification-results.json"
export CETECH_DE_HTTP_QUOTE_CART_STATE="$TASK_STATE"
export CETECH_DE_HTTP_P04_SUPPORT="$TASK_ROOT/scripts/qualification/opening-http-promise-handoff-support.php"
export CETECH_DE_HTTP_P05_SUPPORT="$TASK_ROOT/scripts/qualification/opening-http-promise-native-configuration-support.php"
export CETECH_DE_HTTP_PROBE_TOKEN="$(php -r 'echo bin2hex(random_bytes(24));')"
TASK_PHP="$(readlink -f "$(command -v php)")"
TASK_WP=("$TASK_PHP" "$TASK_WORK/wp-cli.phar" --allow-root --path="$TASK_SITE" --require="$TASK_ROOT/scripts/qualification/admin-context.php")
TASK_LISTENER_ARGS=()
case "${CETECH_DE_HTTP_LISTENER_JIT:-}" in
    '') ;;
    disable)
        if [[ "${GITHUB_ACTIONS:-}" != true || "${CETECH_DE_HTTP_CRASH_DIAGNOSTIC:-}" != 1 ]]; then
            echo 'BLOCKED: P05 listener policy requires its disposable CI runtime.' >&2
            exit 1
        fi
        TASK_LISTENER_ARGS=(-d opcache.jit=disable)
        ;;
    *) echo 'BLOCKED: unsupported P05 listener policy.' >&2; exit 1 ;;
esac
TASK_SERVER_PID=""
TASK_MU_CREATED=0
TASK_STAGE=allocated

finish_p05_http() {
    local task_result=$?
    trap - EXIT
    set +e
    local task_stopped=1 task_mu_removed=1 task_private_removed=1 task_cleanup=1 task_wait=0
    if [[ -n "$TASK_SERVER_PID" ]]; then
        if kill -0 "$TASK_SERVER_PID" 2>/dev/null; then
            kill "$TASK_SERVER_PID" 2>/dev/null || task_stopped=0
            wait "$TASK_SERVER_PID" 2>/dev/null
            task_wait=$?
            if [[ "$task_wait" != 0 && "$task_wait" != 143 ]]; then task_stopped=0; fi
        else
            wait "$TASK_SERVER_PID" 2>/dev/null
            task_wait=$?
            task_stopped=0
        fi
        if kill -0 "$TASK_SERVER_PID" 2>/dev/null; then task_stopped=0; fi
    else
        task_stopped=0
    fi
    # Original tracked state supplies deletion authority, never an appeared-row diff.
    if [[ -f "$TASK_STATE" && ! -L "$TASK_STATE" ]]; then
        if python3 - "$TASK_STATE" <<'P05_SHIPMENT'
import json,sys
sys.exit(0 if 'p05_http_shipment' in json.load(open(sys.argv[1])) else 1)
P05_SHIPMENT
        then
            "${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-http-promise-native-configuration-shipment-support.php" --use-include cleanuphttpshipment "$TASK_STATE" "$TASK_PRIVATE/shipment-trap-cleanup.json" >"$TASK_PRIVATE/shipment-trap.log" 2>&1 || task_cleanup=0
        fi
        if python3 - "$TASK_STATE" <<'PY'
import json,sys
sys.exit(0 if 'q06' in json.load(open(sys.argv[1])) else 1)
PY
        then
            "${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-http-quote-placement-support.php" --use-include cleanupplacement "$TASK_STATE" "$TASK_PRIVATE/placement-trap-cleanup.json" >"$TASK_PRIVATE/placement-trap.log" 2>&1 || task_cleanup=0
        fi
        if python3 - "$TASK_STATE" <<'PY'
import json,sys
sys.exit(0 if 'p05' in json.load(open(sys.argv[1])) else 1)
PY
        then
            "${TASK_WP[@]}" eval-file "$CETECH_DE_HTTP_P05_SUPPORT" --use-include cleanupconfiguration "$TASK_STATE" "$TASK_PRIVATE/configuration-trap-cleanup.json" >"$TASK_PRIVATE/configuration-trap.log" 2>&1 || task_cleanup=0
        fi
        if python3 - "$TASK_STATE" <<'PY'
import json,sys
sys.exit(0 if 'p04' in json.load(open(sys.argv[1])) else 1)
PY
        then
            "${TASK_WP[@]}" eval-file "$CETECH_DE_HTTP_P04_SUPPORT" --use-include cleanuppromise "$TASK_STATE" "$TASK_PRIVATE/promise-trap-cleanup.json" >"$TASK_PRIVATE/promise-trap.log" 2>&1 || task_cleanup=0
        fi
        "${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-http-quote-cart-fixture.php" --use-include cleanupquotecart "$TASK_STATE" "$TASK_PRIVATE/cart-trap-cleanup.json" >"$TASK_PRIVATE/cart-trap.log" 2>&1 || task_cleanup=0
        python3 - "$TASK_PRIVATE" <<'PY'
import json,sys
from pathlib import Path
root=Path(sys.argv[1])
paths=list(root.glob('*-trap-cleanup.json'))
if not paths or any(not isinstance(value:=json.loads(path.read_text()),dict) or not value or any(item is not True for item in value.values()) for path in paths):
    raise SystemExit(1)
PY
        if [[ "$?" != 0 ]]; then task_cleanup=0; fi
    else
        task_cleanup=0
    fi
    if [[ "$TASK_MU_CREATED" == 1 && -f "$TASK_MU" && ! -L "$TASK_MU" ]]; then
        if cmp -s "$TASK_ROOT/scripts/qualification/opening-http-promise-native-configuration-mu.php" "$TASK_MU"; then rm "$TASK_MU"; else task_mu_removed=0; fi
    fi
    if [[ -e "$TASK_MU" || -L "$TASK_MU" ]]; then task_mu_removed=0; fi
    if [[ -f "$TASK_RECEIPT" && "$task_result" != 0 ]]; then
        python3 - "$TASK_RECEIPT" "$TASK_PRIVATE" "$TASK_STAGE" <<'PY'
import hashlib,json,sys
from pathlib import Path
path=Path(sys.argv[1]); report=json.loads(path.read_text()); report['status']='FAIL'
root=Path(sys.argv[2]); logs={entry.name:hashlib.sha256(entry.read_bytes()).hexdigest() for entry in sorted(root.glob('*.log')) if entry.is_file() and not entry.is_symlink()}
report['fixture_diagnostic']={'stage':sys.argv[3] if sys.argv[3] in {'allocated','origin','prepare','listener','driver','complete'} else 'unknown','private_command_log_hashes':logs,'raw_output_retained':False}
path.write_text(json.dumps(report,indent=2)+'\n')
PY
    fi
    # Remove only the exclusive credential directory allocated by this run.
    if [[ -d "$TASK_PRIVATE" && ! -L "$TASK_PRIVATE" && "$(realpath "$TASK_PRIVATE")" == "$TASK_WORK/opening-promise-native-configuration-http-private" ]]; then
        rm -r -- "$TASK_PRIVATE"
    else
        task_private_removed=0
    fi
    if [[ -e "$TASK_PRIVATE" || -L "$TASK_PRIVATE" ]]; then task_private_removed=0; fi
    if [[ -f "$TASK_RECEIPT" ]]; then
        python3 "$TASK_ROOT/scripts/qualification/opening-http-promise-native-configuration.py" --work "$TASK_WORK" --receipt "$TASK_RECEIPT" --finalize "$task_stopped" "$task_mu_removed" "$task_private_removed" "$task_cleanup"
        if [[ "$?" != 0 ]]; then task_result=1; fi
    else
        task_result=1
    fi
    exit "$task_result"
}
trap finish_p05_http EXIT
python3 "$TASK_ROOT/scripts/qualification/opening-http-promise-native-configuration.py" --work "$TASK_WORK" --receipt "$TASK_RECEIPT" --initialize
TASK_STAGE=origin
"${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-http-origin-preflight.php" --use-include "$TASK_PRIVATE/origin.json" >"$TASK_PRIVATE/origin.log" 2>&1
TASK_MU_CREATED=1
cp "$TASK_ROOT/scripts/qualification/opening-http-promise-native-configuration-mu.php" "$TASK_MU"
TASK_STAGE=prepare
"${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-http-quote-cart-fixture.php" --use-include preparequotecart "$TASK_STATE" "$TASK_PRIVATE/cart-prepare.json" >"$TASK_PRIVATE/cart-prepare.log" 2>&1
"${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-http-quote-placement-support.php" --use-include prepareplacement "$TASK_STATE" "$TASK_PRIVATE/placement-prepare.json" >"$TASK_PRIVATE/placement-prepare.log" 2>&1
"${TASK_WP[@]}" eval-file "$CETECH_DE_HTTP_P04_SUPPORT" --use-include preparepromise "$TASK_STATE" "$TASK_PRIVATE/promise-prepare.json" >"$TASK_PRIVATE/promise-prepare.log" 2>&1
"${TASK_WP[@]}" eval-file "$CETECH_DE_HTTP_P05_SUPPORT" --use-include prepareconfiguration "$TASK_STATE" "$TASK_PRIVATE/configuration-prepare.json" >"$TASK_PRIVATE/configuration-prepare.log" 2>&1
TASK_STAGE=listener
python3 - <<'PY'
import socket
with socket.socket(socket.AF_INET,socket.SOCK_STREAM) as probe:
    probe.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1)
    probe.bind(('127.0.0.1',8085))
PY
"$TASK_PHP" "${TASK_LISTENER_ARGS[@]}" -S 127.0.0.1:8085 -t "$TASK_SITE" >"$TASK_PRIVATE/listener.log" 2>&1 &
TASK_SERVER_PID=$!
python3 - "$TASK_SERVER_PID" "$TASK_STATE" <<'PY'
import hashlib,json,os,sys,time
from urllib.request import ProxyHandler,Request,build_opener
pid=int(sys.argv[1]); state=json.load(open(sys.argv[2])); opener=build_opener(ProxyHandler({}))
for attempt in range(30):
    os.kill(pid,0)
    try:
        with opener.open(Request(state['base_url']+'/?cetech_opening_http_probe=1',headers={'X-CETECH-Opening-Probe':state['probe_token']}),timeout=1) as response:
            payload=json.loads(response.read(16385))
        expected={'format':'cetech-opening-http-owned-listener-v1','probe_sha256':hashlib.sha256(state['probe_token'].encode()).hexdigest(),'site_path_sha256':hashlib.sha256(state['site_path'].encode()).hexdigest(),'database_name_sha256':hashlib.sha256(state['database_name'].encode()).hexdigest(),**{key:state['identity'][key] for key in ('source_head','candidate_head','source_tree')}}
        payload.pop('runtime',None)
        if payload!=expected:
            raise SystemExit('P05 listener identity differs before credentials')
        os.kill(pid,0)
        break
    except (OSError,ValueError):
        time.sleep(0.1)
else:
    raise SystemExit('P05 owned listener did not become ready')
PY
TASK_STAGE=driver
python3 "$TASK_ROOT/scripts/qualification/opening-http-promise-native-configuration.py" --work "$TASK_WORK" --receipt "$TASK_RECEIPT" --php "$TASK_PHP" --wpcli "$TASK_WORK/wp-cli.phar" --site "$TASK_SITE" --state "$TASK_STATE" --private "$TASK_PRIVATE"
TASK_STAGE=complete
