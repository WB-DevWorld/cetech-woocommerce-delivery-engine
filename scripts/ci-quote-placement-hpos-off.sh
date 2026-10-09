#!/usr/bin/env bash
# Fresh-process CPT native qualification; restore exact HPOS options on every exit.
set -euo pipefail
TASK_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TASK_SITE="${1:?WordPress site path required}"
TASK_CLI="${2:?WP-CLI phar required}"
TASK_RECEIPT="${3:?Receipt path required}"
TASK_PRIVATE="$(dirname "$TASK_RECEIPT")/q06-hpos-private-control.json"
TASK_WP=(php "$TASK_CLI" --allow-root --path="$TASK_SITE" --require="$TASK_ROOT/scripts/qualification/admin-context.php")
TASK_RESTORE_NEEDED=0
restore() {
  if [[ "$TASK_RESTORE_NEEDED" == 1 && -f "$TASK_PRIVATE" ]]; then
    "${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-quote-placement-hpos-control.php" restore "$TASK_PRIVATE" --use-include
    "${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-quote-placement-hpos-control.php" verify "$TASK_PRIVATE" --use-include
  fi
}
trap restore EXIT
if [[ -e "$TASK_PRIVATE" || -L "$TASK_PRIVATE" ]]; then
  echo "Q06 HPOS-off refuses an existing private restoration envelope." >&2
  exit 1
fi
TASK_RESTORE_NEEDED=1
"${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-quote-placement-hpos-control.php" prepare "$TASK_PRIVATE" --use-include
"${TASK_WP[@]}" eval-file "$TASK_ROOT/scripts/qualification/opening-quote-placement-hpos-off.php" "$TASK_RECEIPT" --use-include
restore
TASK_RESTORE_NEEDED=0
