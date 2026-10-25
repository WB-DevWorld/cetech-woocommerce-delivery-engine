#!/usr/bin/env bash
# R23 uses native admin forms on the exact marked disposable CI site.
set +x
set -euo pipefail
umask 077
TASK_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TASK_WORK="$(realpath "${1:?R23 needs the existing CI work directory}")"
TASK_SITE="$(realpath "${2:?R23 needs the exact disposable native site}")"
if [[ "$#" != 2 || "${GITHUB_ACTIONS:-}" != true || "${CETECH_DE_NATIVE_OPENING_QUALIFICATION:-0}" != 1 || "${CETECH_DE_HTTP_OPENING_QUALIFICATION:-0}" != 1 || "${CETECH_DE_WP_DB_HOST:-}" != 127.0.0.1 || "$TASK_SITE" != "$TASK_WORK/opening-qualification" || ! -f "$TASK_WORK/wp-cli.phar" || ! -f "$TASK_WORK/opening-qualification-results.json" ]]; then
    echo 'BLOCKED: R23 requires the exact marked disposable CI loopback fixture.' >&2
    exit 1
fi
TASK_SUPPORT="$TASK_ROOT/scripts/qualification/policy-assignment-admin-native.php"
TASK_DRIVER="$TASK_ROOT/scripts/qualification/verify-policy-assignment-admin-native.py"
TASK_PRIVATE="$TASK_WORK/policy-assignment-admin-private"
TASK_STATE="$TASK_PRIVATE/state.json"
TASK_RECEIPT="$TASK_WORK/policy-assignment-admin-native-results.json"
TASK_MU="$TASK_SITE/wp-content/mu-plugins/cetech-policy-assignment-admin-fixture.php"
for TASK_PATH in "$TASK_PRIVATE" "$TASK_RECEIPT" "$TASK_MU"; do
    if [[ -e "$TASK_PATH" || -L "$TASK_PATH" ]]; then
        echo 'BLOCKED: R23 refuses an existing private, receipt or MU allocation.' >&2
        exit 1
    fi
done
for TASK_PATH in "$TASK_SUPPORT" "$TASK_DRIVER"; do
    if [[ ! -f "$TASK_PATH" || -L "$TASK_PATH" ]]; then exit 1; fi
done
mkdir -m 700 "$TASK_PRIVATE"
mkdir -p "$(dirname "$TASK_MU")"
export CETECH_DE_HTTP_FIXTURE_SITE="$TASK_SITE"
export CETECH_DE_HTTP_WORK_PATH="$TASK_WORK"
export CETECH_DE_HTTP_PRIVATE_DIR="$TASK_PRIVATE"
export CETECH_DE_HTTP_NATIVE_RECEIPT="$TASK_WORK/opening-qualification-results.json"
export CETECH_DE_HTTP_PROBE_TOKEN="$(php -r 'echo bin2hex(random_bytes(24));')"
TASK_PHP="$(readlink -f "$(command -v php)")"
TASK_WP=("$TASK_PHP" "$TASK_WORK/wp-cli.phar" --allow-root --path="$TASK_SITE" --require="$TASK_ROOT/scripts/qualification/admin-context.php")
TASK_LISTENER_ARGS=()
case "${CETECH_DE_HTTP_LISTENER_JIT:-}" in
    '') ;;
    disable)
        if [[ "${CETECH_DE_HTTP_CRASH_DIAGNOSTIC:-}" != 1 ]]; then exit 1; fi
        TASK_LISTENER_ARGS=(-d opcache.jit=disable)
        ;;
    *) exit 1 ;;
esac
TASK_SERVER_PID=""
TASK_MU_CREATED=0
finish_r23() {
    local task_result=$?
    trap - EXIT
    set +e
    local task_stopped=1 task_mu_removed=1 task_private_removed=1 task_cleanup=0 task_wait=0
    if [[ "$task_result" != 0 ]]; then
        python3 "$TASK_DRIVER" --setup-diagnostic "$TASK_PRIVATE/prepare.log" --work "$TASK_WORK" --receipt "$TASK_RECEIPT"
    fi
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
    if [[ -f "$TASK_STATE" && ! -L "$TASK_STATE" ]]; then
        "${TASK_WP[@]}" eval-file "$TASK_SUPPORT" --use-include cleanup "$TASK_STATE" "$TASK_PRIVATE/cleanup.json" >"$TASK_PRIVATE/cleanup.log" 2>&1
        if [[ "$?" == 0 ]]; then
            python3 - "$TASK_PRIVATE/cleanup.json" <<'PY'
import json,sys
v=json.load(open(sys.argv[1]))
keys={'original_c01_rows_restored','original_delivery_configuration_preserved','protected_domain_and_native_rows_preserved','owned_native_products_and_offers_removed','owned_assignment_options_removed','owned_users_removed','owned_journal_removed'}
raise SystemExit(0 if set(v)==keys and all(v[k] is True for k in keys) else 1)
PY
            if [[ "$?" == 0 ]]; then task_cleanup=1; fi
        fi
    fi
    if [[ "$TASK_MU_CREATED" == 1 && -f "$TASK_MU" && ! -L "$TASK_MU" ]]; then
        if cmp -s "$TASK_SUPPORT" "$TASK_MU"; then rm "$TASK_MU"; else task_mu_removed=0; fi
    fi
    if [[ -e "$TASK_MU" || -L "$TASK_MU" ]]; then task_mu_removed=0; fi
    if [[ -d "$TASK_PRIVATE" && ! -L "$TASK_PRIVATE" && "$(realpath "$TASK_PRIVATE")" == "$TASK_WORK/policy-assignment-admin-private" ]]; then
        rm -r -- "$TASK_PRIVATE"
    else
        task_private_removed=0
    fi
    if [[ -e "$TASK_PRIVATE" || -L "$TASK_PRIVATE" ]]; then task_private_removed=0; fi
    python3 "$TASK_DRIVER" --finalize "$task_stopped" "$task_mu_removed" "$task_private_removed" "$task_cleanup" --work "$TASK_WORK" --receipt "$TASK_RECEIPT"
    if [[ "$?" != 0 ]]; then task_result=1; fi
    python3 "$TASK_DRIVER" --receipt "$TASK_RECEIPT" --work "$TASK_WORK"
    if [[ "$?" != 0 ]]; then task_result=1; fi
    exit "$task_result"
}
trap finish_r23 EXIT
python3 "$TASK_DRIVER" --initialize --work "$TASK_WORK" --receipt "$TASK_RECEIPT"
"${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-http-origin-preflight.php" --use-include "$TASK_PRIVATE/origin.json" >"$TASK_PRIVATE/origin.log" 2>&1
"${TASK_WP[@]}" eval-file "$TASK_SUPPORT" --use-include prepare "$TASK_STATE" "$TASK_PRIVATE/prepare.json" >"$TASK_PRIVATE/prepare.log" 2>&1
cp "$TASK_SUPPORT" "$TASK_MU"
TASK_MU_CREATED=1
"$TASK_PHP" "${TASK_LISTENER_ARGS[@]}" -S 127.0.0.1:8085 -t "$TASK_SITE" >"$TASK_PRIVATE/server.log" 2>&1 &
TASK_SERVER_PID=$!
python3 "$TASK_DRIVER" --run --work "$TASK_WORK" --receipt "$TASK_RECEIPT" --php "$TASK_PHP" --wpcli "$TASK_WORK/wp-cli.phar" --site "$TASK_SITE" --state "$TASK_STATE" --private "$TASK_PRIVATE"
