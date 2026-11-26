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
# Upstream re-uploads this asset under the same name (last seen 2026-11-24), which changes the
# archive bytes without changing the program: the php8.5 binary inside is pinned separately below.
# Re-pin only after checking that binary hash, the member dates and the merge_status helper text.
qualified_archive_sha256='698dafb6d620fc09c2bcf4ec11276437dd52c8b00a637c872bc583f00e796443'
actual_archive_sha256=$(sha256sum "$qualified_archive" | cut -d ' ' -f 1)
if [[ "$actual_archive_sha256" != "$qualified_archive_sha256" ]]; then
	echo "qualified_php85_archive=FAIL expected=$qualified_archive_sha256 actual=$actual_archive_sha256 bytes=$(stat -c %s "$qualified_archive")" >&2
	exit 1
fi

# Same root-relative extraction and dpkg status merge as the official installer.
# Archive bytes are checked before any root extraction or helper execution.
sudo cp /var/lib/dpkg/status /var/lib/dpkg/status-orig
sudo tar -I zstd -xf "$qualified_archive" -C /
sudo env LC_ALL=C.UTF-8 /usr/bin/python3 /usr/sbin/merge_status
sudo rm /usr/sbin/merge_status
sudo mv /var/lib/dpkg/status-orig /var/lib/dpkg/status
qualified_apt_check() {
	sudo apt-get check -o Dir::Etc::sourcelist=/dev/null -o Dir::Etc::sourceparts=- \
		-o Dir::Cache::pkgcache= -o Dir::Cache::srcpkgcache=
}

if ! qualified_apt_output=$(qualified_apt_check 2>&1); then
	printf '%s\n' "$qualified_apt_output"
	# One accepted drift (owner decision 2026-11-26): the archive's libpcre2-dev was built against
	# the PCRE2 runtime of early October, and the hosted ubuntu-24.04 image has since updated that
	# runtime. PHP already loads the runner's library, so only the development package is brought
	# up to it. Any other unmet dependency is not accepted and still fails this step.
	qualified_broken_packages=$(grep -E '^ [^ ]+ : ' <<<"$qualified_apt_output" | sed -E 's/^ ([^ ]+) : .*/\1/' | sort -u || true)
	if [[ "$qualified_broken_packages" != libpcre2-dev ]]; then
		echo "qualified_php85_package_state=FAIL unaccepted_drift=$(tr '\n' ',' <<<"$qualified_broken_packages")" >&2
		exit 1
	fi
	echo "qualified_php85_pcre2_before=$(dpkg-query -W -f='${Package}=${Version} ' libpcre2-dev libpcre2-8-0)"
	sudo apt-get update -qq
	sudo apt-get install -y -qq --no-install-recommends --no-remove --only-upgrade libpcre2-dev
	echo "qualified_php85_pcre2_after=$(dpkg-query -W -f='${Package}=${Version} ' libpcre2-dev libpcre2-8-0)"
	[[ "$(dpkg-query -W -f='${Version}' libpcre2-dev)" == "$(dpkg-query -W -f='${Version}' libpcre2-8-0)" ]]
	qualified_apt_check
fi

for qualified_tool in php phpize php-config phpdbg php-cgi phar.phar phar; do
	[[ -x "/usr/bin/${qualified_tool}8.5" ]]
	sudo update-alternatives --force --install "/usr/bin/$qualified_tool" "$qualified_tool" "/usr/bin/${qualified_tool}8.5" 85 \
		--slave "/usr/share/man/man1/$qualified_tool.1.gz" "$qualified_tool.1.gz" "/usr/share/man/man1/${qualified_tool}8.5.1.gz"
	sudo update-alternatives --set "$qualified_tool" "/usr/bin/${qualified_tool}8.5"
done

# The action uses its explicit installed-runtime mode; exact checks still run afterward.
[[ ! -e /tmp/sp_update ]]
[[ "$(php -r 'echo PHP_VERSION;')" == 8.5.11 ]]
qualified_binary_sha256='7dda44e554d086c1c4f441796fc19f11f46055c0a276dad66181f6c11566e46a'
actual_binary_sha256=$(sha256sum /usr/bin/php8.5 | cut -d ' ' -f 1)
if [[ "$actual_binary_sha256" != "$qualified_binary_sha256" ]]; then
	echo "qualified_php85_binary=FAIL expected=$qualified_binary_sha256 actual=$actual_binary_sha256" >&2
	exit 1
fi
echo 'qualified_php85_archive_and_binary=PASS'
