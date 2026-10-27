#!/usr/bin/env bash
# Restore the exact historical HTTP qualification executable on an owned runner.
# setup-php truncates X.Y.Z to X.Y; debug=true also forces its moving cache update.
set -euo pipefail

[[ "${GITHUB_ACTIONS:-}" == true && "${RUNNER_OS:-}" == Linux ]]
source /etc/os-release
[[ "$ID" == ubuntu && "$VERSION_ID" == 24.04 && "$(dpkg --print-architecture)" == amd64 ]]
[[ -n "${RUNNER_TEMP:-}" && -d "$RUNNER_TEMP" ]]
[[ ! -e /var/lib/dpkg/status-orig && ! -e /tmp/sp_update ]]

if ! command -v zstd >/dev/null || ! /usr/bin/python3 -c 'import apt' 2>/dev/null; then
	sudo apt-get update -qq
	sudo apt-get install -y -qq zstd python3-apt
fi

qualified_work=$(mktemp -d "$RUNNER_TEMP/cetech-qualified-php85.XXXXXX")
trap 'rm -rf "$qualified_work"' EXIT
qualified_archive="$qualified_work/php8511-debug.tar.zst"
curl --fail --location --retry 2 --connect-timeout 15 --max-time 180 --silent --show-error \
	'https://github.com/shivammathur/php-ubuntu/releases/download/builds/php_8.5.11-nts-dbgsym%2Bubuntu24.04.tar.zst' \
	--output "$qualified_archive"
printf '%s  %s\n' '47cb0511350e18579f95af9cfd7d2e47ed2ce5e8d88a7df140a4f5e54ed8140b' "$qualified_archive" | sha256sum --check --status

# Same root-relative extraction and dpkg status merge as the official installer.
# Archive bytes are checked before any root extraction or helper execution.
sudo cp /var/lib/dpkg/status /var/lib/dpkg/status-orig
sudo tar -I zstd -xf "$qualified_archive" -C /
sudo env LC_ALL=C.UTF-8 /usr/bin/python3 /usr/sbin/merge_status
sudo rm /usr/sbin/merge_status
sudo mv /var/lib/dpkg/status-orig /var/lib/dpkg/status
sudo apt-get check -o Dir::Etc::sourcelist=/dev/null -o Dir::Etc::sourceparts=- \
	-o Dir::Cache::pkgcache= -o Dir::Cache::srcpkgcache=

for qualified_tool in php phpize php-config phpdbg php-cgi phar.phar phar; do
	[[ -x "/usr/bin/${qualified_tool}8.5" ]]
	sudo update-alternatives --force --install "/usr/bin/$qualified_tool" "$qualified_tool" "/usr/bin/${qualified_tool}8.5" 85 \
		--slave "/usr/share/man/man1/$qualified_tool.1.gz" "$qualified_tool.1.gz" "/usr/share/man/man1/${qualified_tool}8.5.1.gz"
	sudo update-alternatives --set "$qualified_tool" "/usr/bin/${qualified_tool}8.5"
done

# The action uses its explicit installed-runtime mode; exact checks still run afterward.
[[ ! -e /tmp/sp_update ]]
[[ "$(php -r 'echo PHP_VERSION;')" == 8.5.11 ]]
printf '%s  %s\n' '7dda44e554d086c1c4f441796fc19f11f46055c0a276dad66181f6c11566e46a' /usr/bin/php8.5 | sha256sum --check --status
echo 'qualified_php85_archive_and_binary=PASS'
