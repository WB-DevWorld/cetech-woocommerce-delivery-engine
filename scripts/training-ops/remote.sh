#!/usr/bin/env bash
# CETECH Delivery Engine — training-site operations, server side.
#
# Executed ON the training server by .github/workflows/training-ops.yml as the
# site user (sudo -n -u "$TRAINING_SITE_USER"), never by hand on production.
# Every WordPress command therefore already runs as the site user with
# `wp --path="$WP_PATH"`.
#
# Usage:
#   remote.sh inspect  <wp_path> <deploy_root> <plugin_slug>
#   remote.sh backup   <wp_path> <deploy_root> <plugin_slug>
#   remote.sh install  <wp_path> <deploy_root> <plugin_slug> <zip under deploy_root/packages>
#   remote.sh rollback <wp_path> <deploy_root> <plugin_slug> <backup_id> <restore_db true|false>
#
# Output discipline: the repository is public and Actions logs are readable by
# anyone, so this script prints versions, names, sizes and hashes only. It never
# prints option values, wp-config values, dumps or customer data.

set -euo pipefail
umask 007

OPERATION="${1:?operation}"
WP_PATH="${2:?wp path}"
DEPLOY_ROOT="${3:?deploy root}"
PLUGIN_SLUG="${4:?plugin slug}"
ARG5="${5:-}"
ARG6="${6:-}"

PLUGINS_DIR="$WP_PATH/wp-content/plugins"
PLUGIN_DIR="$PLUGINS_DIR/$PLUGIN_SLUG"
MAIN_FILE="$PLUGIN_DIR/$PLUGIN_SLUG.php"
BACKUPS_DIR="$DEPLOY_ROOT/backups"
PACKAGES_DIR="$DEPLOY_ROOT/packages"
RESTORE_DIR="$DEPLOY_ROOT/restore"
SCHEMA_OPTION="cetech_de_db_version"

die() { echo "::error::$*" >&2; exit 1; }
section() { printf '\n== %s ==\n' "$1"; }
wp_site() { wp --path="$WP_PATH" "$@"; }

[[ -d "$WP_PATH" ]] || die "WordPress path not found: $WP_PATH"
[[ -d "$DEPLOY_ROOT" && -w "$DEPLOY_ROOT" ]] || die "deploy root missing or not writable: $DEPLOY_ROOT"
# The SSH user's home is not readable by the site user; work from the deploy root.
cd "$DEPLOY_ROOT"
command -v wp >/dev/null || die "wp-cli not found on PATH"

plugin_version() { wp_site plugin get "$PLUGIN_SLUG" --field=version 2>/dev/null || echo "not-installed"; }
plugin_status() { wp_site plugin get "$PLUGIN_SLUG" --field=status 2>/dev/null || echo "not-installed"; }
schema_version() { wp_site option get "$SCHEMA_OPTION" 2>/dev/null || echo "unset"; }
main_file_sha() { if [[ -f "$MAIN_FILE" ]]; then sha256sum "$MAIN_FILE" | awk '{print $1}'; else echo "missing"; fi; }

print_identity() {
	echo "plugin=$PLUGIN_SLUG version=$(plugin_version) status=$(plugin_status) ${SCHEMA_OPTION}=$(schema_version)"
	echo "main_file_sha256=$(main_file_sha)"
}

ensure_active() {
	if wp_site plugin is-active "$PLUGIN_SLUG" 2>/dev/null; then
		echo "plugin_active=yes"
	else
		echo "plugin_active=no; activating"
		wp_site plugin activate "$PLUGIN_SLUG"
		wp_site plugin is-active "$PLUGIN_SLUG" || die "plugin is still inactive after activation"
		echo "plugin_active=yes"
	fi
}

# After the plugin folder changes (install or rollback), nothing cached may keep serving the
# previous build: the Redis object cache (options, transients) and WP Rocket's page cache and
# minified CSS/JS copies. The 1.0.0-m2.1 install on 2026-10-29 left WP Rocket serving the old
# minified product-delivery-selector.js until the cache was purged. The installed WP Rocket
# registers no `wp rocket clean` command (its CLI offers only abilities-catalog), so its purge
# functions are called directly through `wp eval`, guarded so a site without WP Rocket just
# reports it. A failed purge is a warning, not an install failure: the identity lines that
# follow are the truth about what is installed.
purge_caches() {
	section "Purge caches"
	if wp_site cache flush >/dev/null 2>&1; then
		echo "object_cache_flushed=yes"
	else
		echo "::warning::wp cache flush failed"
		echo "object_cache_flushed=no"
	fi
	wp_site eval '
		if ( ! function_exists( "rocket_clean_domain" ) ) { echo "wp_rocket=absent\n"; return; }
		rocket_clean_domain();
		if ( function_exists( "rocket_clean_minify" ) ) { rocket_clean_minify(); }
		if ( function_exists( "rocket_clean_cache_busting" ) ) { rocket_clean_cache_busting(); }
		echo "wp_rocket=purged version=" . ( defined( "WP_ROCKET_VERSION" ) ? WP_ROCKET_VERSION : "unknown" ) . "\n";
	' || { echo "::warning::WP Rocket purge failed"; echo "wp_rocket=purge-failed"; }
}

do_inspect() {
	section "Platform"
	echo "wordpress=$(wp_site core version)"
	echo "woocommerce=$(wp_site plugin get woocommerce --field=version 2>/dev/null || echo not-installed)"
	echo "php_cli=$(wp_site eval 'echo PHP_VERSION;')"
	echo "database=$(wp_site db query 'SELECT VERSION()' --skip-column-names)"

	section "Plugins (name, version, status)"
	# wp-cli warns about must-use plugins without a Requires header; that noise is not a finding.
	wp_site plugin list --fields=name,version,status 2> >(grep -v 'Undefined property: stdClass::\$requires' >&2 || true)

	section "Active theme"
	wp_site theme list --status=active --fields=name,version

	section "Delivery engine schema"
	echo "${SCHEMA_OPTION}=$(schema_version)"
	local prefix
	prefix="$(wp_site db prefix)"
	wp_site db query "SELECT TABLE_NAME, TABLE_COLLATION, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '${prefix}delivery\_engine\_%' ORDER BY TABLE_NAME"

	section "Runtime"
	echo "hpos_enabled=$(wp_site option get woocommerce_custom_orders_table_enabled 2>/dev/null || echo unset)"
	if [[ -f "$WP_PATH/wp-content/object-cache.php" ]]; then
		echo "object_cache_dropin=present"
	else
		echo "object_cache_dropin=absent"
	fi

	section "Disk"
	df -h "$WP_PATH" "$DEPLOY_ROOT"

	section "Deploy folder ($DEPLOY_ROOT)"
	find "$DEPLOY_ROOT" -mindepth 1 -maxdepth 3 -printf '%M %u:%g %10s %TY-%Tm-%TdT%TH:%TMZ %p\n' | sort -k5 || true

	section "Installed plugin"
	print_identity
}

LAST_BACKUP_ID=""
do_backup() {
	local id dir
	id="$(date -u +%Y%m%dT%H%M%SZ)"
	dir="$BACKUPS_DIR/$id"
	[[ ! -e "$dir" ]] || die "backup folder already exists: $dir"
	mkdir -p "$dir"
	[[ -d "$PLUGIN_DIR" ]] || die "plugin folder not found: $PLUGIN_DIR"

	wp_site db export "$dir/db.sql" >/dev/null
	gzip -9 "$dir/db.sql"
	tar -C "$PLUGINS_DIR" -czf "$dir/plugin.tar.gz" "$PLUGIN_SLUG"
	{
		echo "backup_id=$id"
		echo "site_path=$WP_PATH"
		echo "plugin=$PLUGIN_SLUG"
		echo "plugin_version=$(plugin_version)"
		echo "${SCHEMA_OPTION}=$(schema_version)"
		echo "main_file_sha256=$(main_file_sha)"
		echo "wordpress=$(wp_site core version)"
		echo "woocommerce=$(wp_site plugin get woocommerce --field=version 2>/dev/null || echo not-installed)"
	} > "$dir/manifest.txt"
	( cd "$dir" && sha256sum db.sql.gz plugin.tar.gz manifest.txt > SHA256SUMS )

	echo "backup_id=$id"
	echo "backup_dir=$dir"
	( cd "$dir" && stat -c '%s %n' db.sql.gz plugin.tar.gz manifest.txt | awk '{printf "%s bytes  %s\n", $1, $2}' )
	cat "$dir/SHA256SUMS"
	LAST_BACKUP_ID="$id"
}

do_install() {
	local zip="$ARG5"
	[[ -n "$zip" ]] || die "install needs the server-side package path"
	[[ "$zip" == "$PACKAGES_DIR"/*.zip ]] || die "package must live under $PACKAGES_DIR: $zip"
	[[ -f "$zip" && -f "$zip.sha256" ]] || die "package or its .sha256 is missing: $zip"

	section "Package"
	( cd "$(dirname "$zip")" && sha256sum -c "$(basename "$zip").sha256" )
	echo "package_bytes=$(stat -c '%s' "$zip")"

	section "Pre-install state"
	print_identity

	section "Backup before install"
	do_backup

	section "Install"
	wp_site plugin install "$zip" --force
	ensure_active
	purge_caches

	section "Post-install state"
	print_identity
}

do_rollback() {
	local id="$ARG5" restore_db="$ARG6" dir stage sqlfile
	[[ "$id" =~ ^[0-9]{8}T[0-9]{6}Z$ ]] || die "backup_id must look like 20261028T143000Z"
	dir="$BACKUPS_DIR/$id"
	[[ -d "$dir" ]] || die "backup not found: $dir"
	[[ "$restore_db" == "true" || "$restore_db" == "false" ]] || die "restore_db must be true or false"

	section "Backup integrity ($id)"
	( cd "$dir" && sha256sum -c SHA256SUMS )
	cat "$dir/manifest.txt"

	section "Pre-rollback state"
	print_identity

	section "Safety backup before rollback"
	do_backup

	section "Restore plugin folder"
	stage="$RESTORE_DIR/$id-$(date -u +%Y%m%dT%H%M%SZ)"
	mkdir -p "$stage"
	tar -C "$stage" -xzf "$dir/plugin.tar.gz"
	[[ -f "$stage/$PLUGIN_SLUG/$PLUGIN_SLUG.php" ]] || die "backup archive does not contain $PLUGIN_SLUG/$PLUGIN_SLUG.php"
	rm -rf "$PLUGIN_DIR"
	mv "$stage/$PLUGIN_SLUG" "$PLUGIN_DIR"
	rmdir "$stage"
	echo "plugin_folder_restored_from=$id"

	if [[ "$restore_db" == "true" ]]; then
		section "Restore database"
		sqlfile="$RESTORE_DIR/$id.sql"
		gunzip -c "$dir/db.sql.gz" > "$sqlfile"
		wp_site db import "$sqlfile"
		rm -f "$sqlfile"
		wp_site cache flush || true
		echo "database_restored_from=$id"
	else
		echo "database_restored=no (restore_db=false)"
	fi

	ensure_active
	purge_caches

	section "Post-rollback state"
	print_identity
}

case "$OPERATION" in
	inspect) do_inspect ;;
	backup) section "Backup"; do_backup ;;
	install) do_install ;;
	rollback) do_rollback ;;
	*) die "unknown operation: $OPERATION" ;;
esac

echo
echo "training_ops_result=OK operation=$OPERATION${LAST_BACKUP_ID:+ backup_id=$LAST_BACKUP_ID}"
