#!/usr/bin/env bash
# Separate fresh P06 CPT process; restore exact inherited HPOS options and history.
set -euo pipefail
TASK_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TASK_SITE="${1:?WordPress site required}"
TASK_CLI="${2:?WP-CLI required}"
TASK_RECEIPT="${3:?P06 CPT receipt required}"
shift 3
if [[ "$#" != 3 ]]; then echo 'P06 requires three preceding P05 receipts.' >&2; exit 1; fi
TASK_PRIVATE="$(dirname "$TASK_RECEIPT")/p06-bounds-hpos-private-control.json"
TASK_WP=(php "$TASK_CLI" --allow-root --path="$TASK_SITE" --require="$TASK_ROOT/scripts/qualification/admin-context.php")
TASK_RESTORE_NEEDED=0
restore() {
 if [[ "$TASK_RESTORE_NEEDED" == 1 && -f "$TASK_PRIVATE" ]]; then
  "${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-quote-placement-hpos-control.php" restore "$TASK_PRIVATE" --use-include
  "${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-quote-placement-hpos-control.php" verify "$TASK_PRIVATE" --use-include
 fi
}
trap restore EXIT
if [[ -e "$TASK_PRIVATE" || -L "$TASK_PRIVATE" ]]; then echo 'P06 refuses existing private restoration envelope.' >&2; exit 1; fi
TASK_RESTORE_NEEDED=1
"${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-quote-placement-hpos-control.php" prepare "$TASK_PRIVATE" --use-include
"${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-promise-qualification-native-bounds-runner.php" "$TASK_RECEIPT" hpos_off "$@" --use-include
restore
TASK_RESTORE_NEEDED=0
