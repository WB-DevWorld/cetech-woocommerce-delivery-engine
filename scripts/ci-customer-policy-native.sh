#!/usr/bin/env bash
# R24 owns two independent disposable CI derivatives, never the source fixture.
set +x
set -euo pipefail
umask 077
TASK_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [[ "$#" != 3 ]]; then echo 'R24 expects WORK NATIVE_SITE PACKAGE.' >&2; exit 1; fi
TASK_WORK="$(realpath "$1")"
TASK_SITE="$(realpath "$2")"
TASK_PACKAGE="$(realpath "$3")"
if [[ "${GITHUB_ACTIONS:-}" != true || "${CETECH_DE_NATIVE_OPENING_QUALIFICATION:-0}" != 1 || "${CETECH_DE_HTTP_OPENING_QUALIFICATION:-0}" != 1 || "${CETECH_DE_WP_DB_HOST:-}" != 127.0.0.1 || "$TASK_SITE" != "$TASK_WORK/opening-qualification" || ! -f "$TASK_WORK/wp-cli.phar" || ! -f "$TASK_WORK/opening-qualification-results.json" ]]; then
 echo 'BLOCKED: R24 requires the owned CI source fixture and loopback database.' >&2; exit 1
fi
exec python3 "$TASK_ROOT/scripts/qualification/customer-policy-native-driver.py" --work "$TASK_WORK" --site "$TASK_SITE" --package "$TASK_PACKAGE" --repo "$TASK_ROOT" --php "$(readlink -f "$(command -v php)")"
