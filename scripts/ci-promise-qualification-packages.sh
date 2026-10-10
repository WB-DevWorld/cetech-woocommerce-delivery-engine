#!/usr/bin/env bash
# Build immutable P06 development/current and supported historical reader ZIPs.
set -euo pipefail
p06_repo="$(cd "$(dirname "$0")/.." && pwd)"
p06_dest="${1:?Usage: ci-promise-qualification-packages.sh <new-directory>}"
if [[ -e "$p06_dest" ]]; then echo 'P06 package destination already exists' >&2; exit 1; fi
mkdir -p "$p06_dest"
p06_head="$(git -C "$p06_repo" rev-parse HEAD)"
for p06_kind in current previous reader; do
  case "$p06_kind" in
    current) p06_ref="$p06_head" ;;
    previous) p06_ref=543275af6844d649268f9ac02db518420dac9fe6 ;;
    reader) p06_ref=3b57ffc53b64f485aa9ad30e2d9e5d424afc3468 ;;
  esac
  python3 "$p06_repo/scripts/qualification/build-promise-qualification-package.py" "$p06_dest/$p06_kind.zip" --ref "$p06_ref"
  python3 "$p06_repo/scripts/qualification/extract-promise-qualification-package.py" "$p06_dest/$p06_kind.zip" "$p06_dest/$p06_kind.json" "$p06_dest/$p06_kind-extract" --ref "$p06_ref"
done
