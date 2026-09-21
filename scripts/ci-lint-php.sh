#!/usr/bin/env bash
# Lint PHP files. Arguments are files or directories.
set -euo pipefail

if [[ $# -lt 1 ]]; then
	echo "Usage: $0 <path> [path...]" >&2
	exit 1
fi

fail=0
count=0

while IFS= read -r -d '' file; do
	count=$((count + 1))
	if ! php -l "$file" >/dev/null; then
		fail=$((fail + 1))
	fi
done < <(
	for target in "$@"; do
		if [[ -f "$target" ]]; then
			printf '%s\0' "$target"
		elif [[ -d "$target" ]]; then
			find "$target" -type f -name '*.php' \
				-not -path '*/vendor/*' \
				-not -path '*/node_modules/*' \
				-not -path '*/build/*' \
				-not -path '*/dist/*' \
				-print0
		fi
	done
)

echo "php=$(php -r 'echo PHP_VERSION;') linted=${count} failures=${fail}"
test "$count" -gt 0
test "$fail" -eq 0
