#!/usr/bin/env bash
# N18 separate native listener; only the root-owned trusted fixture mounts adoption.
set +x
set -euo pipefail
umask 077
TASK_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TASK_WORK="$(realpath "${1:?N18 HTTP requires the existing qualification work directory}")"
TASK_SITE="$(realpath "${2:?N18 HTTP requires its exact marked native site}")"
if [[ "${GITHUB_ACTIONS:-}" != true || "${CETECH_DE_NATIVE_OPENING_QUALIFICATION:-0}" != 1 || "${CETECH_DE_HTTP_OPENING_QUALIFICATION:-0}" != 1 || "${CETECH_DE_WP_DB_HOST:-}" != 127.0.0.1 || "$TASK_SITE" != "$TASK_WORK/opening-qualification" || ! -f "$TASK_WORK/wp-cli.phar" ]]; then
    echo 'BLOCKED: N18 HTTP requires the exact marked disposable CI loopback fixture.' >&2
    exit 1
fi
TASK_SUPPORT="$TASK_ROOT/scripts/qualification/opening-http-promotion-cart-support.php"
TASK_MU_SOURCE="$TASK_ROOT/scripts/qualification/opening-http-promotion-cart-mu.php"
for TASK_SOURCE in "$TASK_SUPPORT" "$TASK_MU_SOURCE"; do
    if [[ ! -f "$TASK_SOURCE" || -L "$TASK_SOURCE" ]]; then
        echo 'BLOCKED: N18 trusted native composition fixture is unavailable.' >&2
        exit 1
    fi
done
TASK_PRIVATE="$TASK_WORK/opening-promotion-cart-http-private"
TASK_STATE="$TASK_PRIVATE/quote-cart-state.json"
TASK_RECEIPT="$TASK_WORK/opening-http-promotion-cart-results.json"
TASK_BROWSER_RECEIPT="$TASK_WORK/opening-http-promotion-cart-browser-results.json"
TASK_PLACEMENT_RECEIPT="$TASK_WORK/opening-http-promotion-placement-results.json"
TASK_CLOSEOUT="$TASK_WORK/opening-http-promotion-cart-closeout.json"
TASK_MU="$TASK_SITE/wp-content/mu-plugins/cetech-opening-promotion-cart-fixture.php"
for TASK_ALLOCATION in "$TASK_PRIVATE" "$TASK_RECEIPT" "$TASK_BROWSER_RECEIPT" "$TASK_PLACEMENT_RECEIPT" "$TASK_CLOSEOUT" "$TASK_MU"; do
    if [[ -e "$TASK_ALLOCATION" || -L "$TASK_ALLOCATION" ]]; then
        echo 'BLOCKED: N18 refuses an existing private, receipt or MU allocation.' >&2
        exit 1
    fi
done
export CETECH_DE_HTTP_FIXTURE_SITE="$TASK_SITE"
export CETECH_DE_HTTP_WORK_PATH="$TASK_WORK"
export CETECH_DE_HTTP_PRIVATE_DIR="$TASK_PRIVATE"
export CETECH_DE_HTTP_NATIVE_RECEIPT="$TASK_WORK/opening-qualification-results.json"
export CETECH_DE_HTTP_QUOTE_CART_STATE="$TASK_STATE"
export CETECH_DE_HTTP_PROMOTION_CART_STATE="$TASK_STATE"
export CETECH_DE_HTTP_N18_SUPPORT="$TASK_SUPPORT"
export CETECH_DE_HTTP_PROBE_TOKEN="$(php -r 'echo bin2hex(random_bytes(24));')"
TASK_PHP="$(readlink -f "$(command -v php)")"
TASK_WP=("$TASK_PHP" "$TASK_WORK/wp-cli.phar" --allow-root --path="$TASK_SITE" --require="$TASK_ROOT/scripts/qualification/admin-context.php")
TASK_LISTENER_ARGS=()
case "${CETECH_DE_HTTP_LISTENER_JIT:-}" in
    '') ;;
    disable)
        if [[ "${CETECH_DE_HTTP_CRASH_DIAGNOSTIC:-}" != 1 ]]; then
            echo 'BLOCKED: N18 listener policy requires its explicit CI diagnostic lane.' >&2
            exit 1
        fi
        TASK_LISTENER_ARGS=(-d opcache.jit=disable)
        ;;
    *) echo 'BLOCKED: unsupported N18 listener policy.' >&2; exit 1 ;;
esac
mkdir -m 700 "$TASK_PRIVATE"
mkdir -p "$(dirname "$TASK_MU")"
TASK_SERVER_PID=""
TASK_MU_CREATED=0
TASK_STAGE=allocated
finish_n18_http() {
    local task_result=$?
    trap - EXIT
    set +e
    local task_stopped=1 task_mu_removed=1 task_private_removed=1 task_cleanup=1 task_wait=0
    local task_promotion_cleanup_exit=0 task_cart_cleanup_exit=0
    if [[ -n "$TASK_SERVER_PID" ]]; then
        if kill -0 "$TASK_SERVER_PID" 2>/dev/null; then
            kill "$TASK_SERVER_PID" 2>/dev/null || task_stopped=0
            wait "$TASK_SERVER_PID" 2>/dev/null
            task_wait=$?
            if [[ "$task_wait" != 0 && "$task_wait" != 143 ]]; then task_stopped=0; fi
        else
            wait "$TASK_SERVER_PID" 2>/dev/null
            task_stopped=0
        fi
        if kill -0 "$TASK_SERVER_PID" 2>/dev/null; then task_stopped=0; fi
    else
        task_stopped=0
    fi
    # Exact tracked fixture IDs provide cleanup authority; appeared-row diffs do not.
    if [[ -f "$TASK_STATE" && ! -L "$TASK_STATE" ]]; then
        "$TASK_PHP" "$TASK_WORK/wp-cli.phar" --allow-root --path="$TASK_SITE" --require="$TASK_ROOT/scripts/qualification/admin-context.php" eval-file "$TASK_SUPPORT" --use-include cleanuppromotioncart "$TASK_STATE" "$TASK_PRIVATE/promotion-cleanup.json" >"$TASK_PRIVATE/promotion-cleanup.log" 2>&1 || { task_promotion_cleanup_exit=$?; task_cleanup=0; }
        "${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-http-quote-cart-fixture.php" --use-include cleanupquotecart "$TASK_STATE" "$TASK_PRIVATE/cart-cleanup.json" >"$TASK_PRIVATE/cart-cleanup.log" 2>&1 || { task_cart_cleanup_exit=$?; task_cleanup=0; }
        python3 "$TASK_ROOT/scripts/qualification/promotion-cart-cleanup-diagnostic.py" \
            "$TASK_PRIVATE" "$TASK_STATE" "$task_promotion_cleanup_exit" "$task_cart_cleanup_exit"
        if [[ "$?" != 0 ]]; then task_cleanup=0; fi
        python3 "$TASK_ROOT/scripts/qualification/verify-opening-promotion-cart.py" \
            "$TASK_RECEIPT" "$TASK_BROWSER_RECEIPT" "$TASK_PLACEMENT_RECEIPT" "$TASK_STATE" \
            --cleanup "$TASK_PRIVATE/promotion-cleanup.json" "$TASK_PRIVATE/cart-cleanup.json"
        if [[ "$?" != 0 ]]; then task_result=1; fi
    else
        task_cleanup=0
    fi
    if [[ "$TASK_MU_CREATED" == 1 && -f "$TASK_MU" && ! -L "$TASK_MU" ]]; then
        if cmp -s "$TASK_MU_SOURCE" "$TASK_MU"; then rm "$TASK_MU"; else task_mu_removed=0; fi
    fi
    if [[ -e "$TASK_MU" || -L "$TASK_MU" ]]; then task_mu_removed=0; fi
    if [[ -d "$TASK_PRIVATE" && ! -L "$TASK_PRIVATE" && "$(realpath "$TASK_PRIVATE")" == "$TASK_WORK/opening-promotion-cart-http-private" ]]; then
        rm -r -- "$TASK_PRIVATE"
    else
        task_private_removed=0
    fi
    if [[ -e "$TASK_PRIVATE" || -L "$TASK_PRIVATE" ]]; then task_private_removed=0; fi
    python3 - "$TASK_CLOSEOUT" "$TASK_RECEIPT" "$TASK_BROWSER_RECEIPT" "$TASK_PLACEMENT_RECEIPT" "$TASK_STAGE" "$task_result" "$task_stopped" "$task_mu_removed" "$task_private_removed" "$task_cleanup" <<'PY'
import json,sys
from pathlib import Path
out,direct,browser,placement=map(Path,sys.argv[1:5]); stage=sys.argv[5]
flags=dict(zip(('listener_stopped','mu_removed','private_removed','tracked_fixture_cleanup'),(value=='1' for value in sys.argv[7:11])))
receipts=[]
for path,kind,count in ((direct,'cetech-n18-promotion-cart-http-v1',7),(browser,'cetech-n18-promotion-cart-browser-v1',3),(placement,'cetech-n18-promotion-placement-http-v1',3)):
    try:
        value=json.loads(path.read_text())
        ok=(not path.is_symlink() and value.get('format')==kind and value.get('status')=='PASS' and len(value.get('cases',[]))==count and all(case.get('status')=='PASS' for case in value['cases']))
    except (OSError,ValueError,TypeError): ok=False
    receipts.append(ok)
passed=sys.argv[6]=='0' and all(flags.values()) and all(receipts)
out.write_text(json.dumps({'format':'cetech-n18-promotion-http-closeout-v1','status':'PASS' if passed else 'FAIL','stage':stage if stage in {'allocated','origin','prepare','listener','direct','browser','placement','complete'} else 'unknown','direct_receipt_pass':receipts[0],'browser_receipt_pass':receipts[1],'placement_receipt_pass':receipts[2],**flags},indent=2)+'\n')
raise SystemExit(0 if passed else 1)
PY
    if [[ "$?" != 0 ]]; then task_result=1; fi
    python3 "$TASK_ROOT/scripts/qualification/verify-opening-promotion-cart.py" --closeout "$TASK_CLOSEOUT"
    if [[ "$?" != 0 ]]; then task_result=1; fi
    exit "$task_result"
}
trap finish_n18_http EXIT
TASK_STAGE=origin
"${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-http-origin-preflight.php" --use-include "$TASK_PRIVATE/origin.json" >"$TASK_PRIVATE/origin.log" 2>&1
TASK_MU_CREATED=1
cp "$TASK_MU_SOURCE" "$TASK_MU"
TASK_STAGE=prepare
"${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-http-quote-cart-fixture.php" --use-include preparequotecart "$TASK_STATE" "$TASK_PRIVATE/cart-prepare.json" >"$TASK_PRIVATE/cart-prepare.log" 2>&1
"${TASK_WP[@]}" eval-file "$TASK_SUPPORT" --use-include preparepromotioncart "$TASK_STATE" "$TASK_PRIVATE/promotion-prepare.json" >"$TASK_PRIVATE/promotion-prepare.log" 2>&1
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
        if payload!=expected: raise SystemExit('N18 listener identity differs before credentials')
        os.kill(pid,0)
        break
    except (OSError,ValueError): time.sleep(0.1)
else: raise SystemExit('N18 owned listener did not become ready')
PY
TASK_STAGE=direct
python3 "$TASK_ROOT/scripts/qualification/opening-http-promotion-cart-driver.py" --state "$TASK_STATE.browser.json" --base-url http://127.0.0.1:8085 --receipt "$TASK_RECEIPT"
TASK_STAGE=browser
# The review assets use Woo's actual checkout-page context; visiting a second
# Blocks page alone does not make it the configured native checkout page.
"${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-http-quote-cart-fixture.php" --use-include blockspagequotecart "$TASK_STATE" "$TASK_PRIVATE/blocks-page.json" >"$TASK_PRIVATE/blocks-page.log" 2>&1
node "$TASK_ROOT/scripts/qualification/promotion-cart-browser.cjs" --state "$TASK_STATE.browser.json" --base-url http://127.0.0.1:8085 --receipt "$TASK_BROWSER_RECEIPT"
TASK_STAGE=placement
"${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-http-quote-cart-fixture.php" --use-include classicpagequotecart "$TASK_STATE" "$TASK_PRIVATE/classic-page.json" >"$TASK_PRIVATE/classic-page.log" 2>&1
python3 "$TASK_ROOT/scripts/qualification/opening-http-promotion-placement-driver.py" --state "$TASK_STATE.browser.json" --base-url http://127.0.0.1:8085 --receipt "$TASK_PLACEMENT_RECEIPT"
TASK_STAGE=complete
