#!/usr/bin/env bash
# Separate fresh CPT P04 process; restore exact inherited HPOS rows and protected history.
set -euo pipefail
TASK_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TASK_SITE="${1:?WordPress site path required}"
TASK_CLI="${2:?WP-CLI phar required}"
TASK_RECEIPT="${3:?P04 CPT receipt path required}"
shift 3
if [[ "$#" != 5 ]]; then echo 'P04 needs the five preceding primary receipt paths.' >&2; exit 1; fi
TASK_PRIVATE="$(dirname "$TASK_RECEIPT")/p04-hpos-private-control.json"
TASK_WP=(php "$TASK_CLI" --allow-root --path="$TASK_SITE" --require="$TASK_ROOT/scripts/qualification/admin-context.php")
TASK_RESTORE_NEEDED=0
restore() {
  if [[ "$TASK_RESTORE_NEEDED" == 1 && -f "$TASK_PRIVATE" ]]; then
    "${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-quote-placement-hpos-control.php" restore "$TASK_PRIVATE" --use-include
    "${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-quote-placement-hpos-control.php" verify "$TASK_PRIVATE" --use-include
  fi
}
trap restore EXIT
if [[ -e "$TASK_PRIVATE" || -L "$TASK_PRIVATE" ]]; then echo 'P04 refuses an existing private restoration envelope.' >&2; exit 1; fi
TASK_RESTORE_NEEDED=1
"${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-quote-placement-hpos-control.php" prepare "$TASK_PRIVATE" --use-include
"${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-promise-handoff-runner.php" "$TASK_RECEIPT" hpos_off "$@" --use-include
restore
TASK_RESTORE_NEEDED=0
