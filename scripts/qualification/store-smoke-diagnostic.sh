# Sourced only around the first clean-site Store API smoke listener.
# Raw output and owned cores stay private; this is not opening qualification.
start_store_smoke_listener() {
    STORE_SMOKE_PRIVATE="$WORK/store-smoke-private"
    STORE_SMOKE_RECEIPT="$WORK/store-smoke-diagnostic.json"
    if [[ -e "$STORE_SMOKE_PRIVATE" || -L "$STORE_SMOKE_PRIVATE" || -e "$STORE_SMOKE_RECEIPT" || -L "$STORE_SMOKE_RECEIPT" ]]; then
        echo "BLOCKED: Store API smoke diagnostic allocation already exists" >&2
        return 1
    fi
    mkdir -m 700 "$STORE_SMOKE_PRIVATE"
    STORE_SMOKE_PHP="$(readlink -f "$(command -v php)")"
    STORE_SMOKE_HEAD="$(git -C "$ROOT" rev-parse HEAD)"
    STORE_SMOKE_TREE="$(git -C "$ROOT" rev-parse HEAD^{tree})"
    STORE_SMOKE_STAGE="allocated"
    STORE_SMOKE_PID=""
    STORE_SMOKE_CORE_PID=""
    STORE_SMOKE_CORE_CHANGED=0
    STORE_SMOKE_CORE_ORIGINAL=""
    STORE_SMOKE_INTERRUPTED=0
    STORE_SMOKE_CRASH="${CETECH_DE_HTTP_CRASH_DIAGNOSTIC:-0}"
    trap 'finish_store_smoke_listener "$?" exit' EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM
    STORE_SMOKE_LISTENER_ARGS=()
    case "${CETECH_DE_HTTP_LISTENER_JIT:-}" in
        '') ;;
        disable)
            if [[ "${GITHUB_ACTIONS:-}" != "true" || "$DB_HOST" != "127.0.0.1" || "${CETECH_DE_NATIVE_OPENING_QUALIFICATION:-}" != "1" || "${CETECH_DE_HTTP_OPENING_QUALIFICATION:-}" != "1" || "$STORE_SMOKE_CRASH" != "1" ]]; then
                echo "BLOCKED: listener JIT policy requires the disposable CI qualification fixture" >&2
                return 1
            fi
            STORE_SMOKE_LISTENER_ARGS=(-d opcache.jit=disable)
            ;;
        *) echo "BLOCKED: unsupported listener JIT policy" >&2; return 1 ;;
    esac
    if [[ "$STORE_SMOKE_CRASH" == "1" ]]; then
        if [[ "${GITHUB_ACTIONS:-}" != "true" || "$DB_HOST" != "127.0.0.1" || ! -x "$(command -v gdb)" ]]; then
            echo "BLOCKED: Store API native capture requires the disposable loopback GitHub runner and gdb" >&2
            return 1
        fi
        STORE_SMOKE_STAGE="debugger_preflight"
        CETECH_DE_HTTP_STACK_OUTPUT="$STORE_SMOKE_PRIVATE/preflight.json" CETECH_DE_HTTP_DEBUG_EXECUTABLE="$STORE_SMOKE_PHP" CETECH_DE_HTTP_DEBUG_MODE=preflight \
            timeout 20s gdb -nx -batch -iex 'set auto-load off' -iex 'set debuginfod enabled off' \
            -ex "source $ROOT/scripts/qualification/opening-http-native-stack.py" "$STORE_SMOKE_PHP" \
            >"$STORE_SMOKE_PRIVATE/preflight.log" 2>&1
        python3 - "$STORE_SMOKE_PRIVATE/preflight.json" <<'PY'
import json, sys
from pathlib import Path
report = json.loads(Path(sys.argv[1]).read_text())
if report.get("status") != "preflight_pass" or report.get("symbol_validation", {}).get("status") != "PASS":
    raise SystemExit("BLOCKED: Store API GDB did not load matching PHP debug files")
PY
        STORE_SMOKE_CORE_ORIGINAL="$(cat /proc/sys/kernel/core_pattern)"
        STORE_SMOKE_CORE_CHANGED=1
        printf '%s\n' "$STORE_SMOKE_PRIVATE/core.%p" | sudo -n tee /proc/sys/kernel/core_pattern >"$STORE_SMOKE_PRIVATE/core-setup.log" 2>&1
    fi
    STORE_SMOKE_STAGE="listener_start"
    # Same executable, INI and listener arguments as the original smoke.
    if [[ "$STORE_SMOKE_CRASH" == "1" ]]; then
        # Core limits apply only to the owned listener, never later WP-CLI.
        (ulimit -c unlimited; exec "$STORE_SMOKE_PHP" "${STORE_SMOKE_LISTENER_ARGS[@]}" -S 127.0.0.1:8085 -t "$CLEAN") >"$STORE_SMOKE_PRIVATE/php-server.log" 2>&1 &
    else
        "$STORE_SMOKE_PHP" "${STORE_SMOKE_LISTENER_ARGS[@]}" -S 127.0.0.1:8085 -t "$CLEAN" >"$STORE_SMOKE_PRIVATE/php-server.log" 2>&1 &
    fi
    STORE_SMOKE_PID=$!
    STORE_SMOKE_CORE_PID="$STORE_SMOKE_PID"
}

finish_store_smoke_listener() {
    local result="$1" mode="${2:-return}"
    local command_exit="$result"
    # Defer catchable interruption until owned-child cleanup and restoration.
    trap 'STORE_SMOKE_INTERRUPTED=130' INT
    trap 'STORE_SMOKE_INTERRUPTED=143' TERM
    trap - EXIT
    set +e
    local before="absent" waited="absent" signal="" term=0 stopped=1 restored=1 removed=1
    if [[ -n "$STORE_SMOKE_PID" ]]; then
        if kill -0 "$STORE_SMOKE_PID" 2>/dev/null; then
            before="running"
            kill "$STORE_SMOKE_PID" 2>/dev/null
            if [[ "$?" == "0" ]]; then term=1; fi
        else
            before="exited"
        fi
        wait "$STORE_SMOKE_PID" 2>/dev/null
        waited="$?"
        # A trapped signal can interrupt wait before the child is reaped.
        while kill -0 "$STORE_SMOKE_PID" 2>/dev/null; do
            wait "$STORE_SMOKE_PID" 2>/dev/null
            waited="$?"
        done
        if [[ "$waited" -gt 128 ]]; then signal="$((waited - 128))"; fi
        if kill -0 "$STORE_SMOKE_PID" 2>/dev/null; then stopped=0; fi
    fi
    # Restore global core handling before the potentially slow debugger.
    if [[ "$STORE_SMOKE_CORE_CHANGED" == "1" ]]; then
        printf '%s\n' "$STORE_SMOKE_CORE_ORIGINAL" | sudo -n tee /proc/sys/kernel/core_pattern >"$STORE_SMOKE_PRIVATE/core-restore.log" 2>&1
        if [[ "$?" != "0" ]]; then restored=0; fi
    fi
    local capture="not_requested"
    if [[ "$STORE_SMOKE_CRASH" == "1" ]]; then
        capture="no_listener_core"
        # Decode only the exact owned child's completed ordinary core.
        if [[ -n "$STORE_SMOKE_CORE_PID" && -f "$STORE_SMOKE_PRIVATE/core.$STORE_SMOKE_CORE_PID" && ! -L "$STORE_SMOKE_PRIVATE/core.$STORE_SMOKE_CORE_PID" ]]; then
            capture="debugger_failed"
            CETECH_DE_HTTP_STACK_OUTPUT="$STORE_SMOKE_PRIVATE/stack.json" CETECH_DE_HTTP_DEBUG_EXECUTABLE="$STORE_SMOKE_PHP" \
                timeout 20s gdb -nx -batch -iex 'set auto-load off' -iex 'set debuginfod enabled off' \
                -iex 'set print frame-arguments none' -ex "source $ROOT/scripts/qualification/opening-http-native-stack.py" \
                "$STORE_SMOKE_PHP" "$STORE_SMOKE_PRIVATE/core.$STORE_SMOKE_CORE_PID" >"$STORE_SMOKE_PRIVATE/debugger.log" 2>&1
            if [[ "$?" == "0" && -f "$STORE_SMOKE_PRIVATE/stack.json" ]]; then capture="collected"; fi
        fi
    fi
    # The receipt writer revalidates the stack allowlist, never copies raw logs.
    python3 "$ROOT/scripts/qualification/store-smoke-receipt.py" \
        "$STORE_SMOKE_PRIVATE" "$STORE_SMOKE_RECEIPT" "$STORE_SMOKE_STAGE" "$command_exit" \
        "$before" "$waited" "$signal" "$term" "$stopped" "$restored" "$capture" \
        "$STORE_SMOKE_HEAD" "${CETECH_DE_QUALIFICATION_CANDIDATE_HEAD:-$STORE_SMOKE_HEAD}" "$STORE_SMOKE_TREE"
    if [[ "$?" != "0" && "$result" == "0" ]]; then result=1; fi
    rm -rf -- "$STORE_SMOKE_PRIVATE"
    rm -f -- "$STORE_JSON"
    if [[ -e "$STORE_SMOKE_PRIVATE" || -L "$STORE_SMOKE_PRIVATE" || -e "$STORE_JSON" || -L "$STORE_JSON" ]]; then removed=0; fi
    python3 "$ROOT/scripts/qualification/store-smoke-receipt.py" --finalize "$STORE_SMOKE_RECEIPT" "$removed" "$STORE_SMOKE_INTERRUPTED"
    if [[ "$?" != "0" || "$stopped" != "1" || "$restored" != "1" || "$removed" != "1" ]]; then
        if [[ "$result" == "0" ]]; then result=1; fi
    fi
    echo "store_smoke_stage=$STORE_SMOKE_STAGE command_exit=$command_exit listener_wait=$waited capture=$capture"
    if [[ "$STORE_SMOKE_INTERRUPTED" != "0" && "$result" == "0" ]]; then result="$STORE_SMOKE_INTERRUPTED"; fi
    trap - INT TERM
    if [[ "$mode" == "exit" ]]; then exit "$result"; fi
    set -e
    return "$result"
}
