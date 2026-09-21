#!/usr/bin/env bash
# Isolated WordPress + WooCommerce activation smoke on PHP 8.5.
# Not a WoodMart / B2BKing / FOX / cache / payment / Pilot certification.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${CETECH_DE_WP_WORK:-${RUNNER_TEMP:-/tmp}/cetech-de-php85-wp}"
PLUGIN_STAGE="${CETECH_DE_PLUGIN_STAGE:-${WORK}/plugin-stage}"
DB_HOST="${CETECH_DE_WP_DB_HOST:-127.0.0.1}"
DB_PORT="${CETECH_DE_WP_DB_PORT:-3306}"
DB_USER="${CETECH_DE_WP_DB_USER:-root}"
DB_PASSWORD="${CETECH_DE_WP_DB_PASSWORD:-wordpress}"
DB_NAME_CLEAN="${CETECH_DE_WP_DB_CLEAN:-cetech_wp_clean}"
DB_NAME_UPGRADE="${CETECH_DE_WP_DB_UPGRADE:-cetech_wp_upgrade}"
RC12_ZIP_URL="${CETECH_DE_RC12_ZIP_URL:-https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/releases/download/v1.0.0-rc.12/cetech-woocommerce-delivery-engine-1.0.0-rc.12.zip}"
PHP_MAJOR_MINOR="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"

if [[ "$PHP_MAJOR_MINOR" != "8.5" ]]; then
	echo "BLOCKED: this smoke must run on PHP 8.5, found $(php -r 'echo PHP_VERSION;')" >&2
	exit 1
fi

echo "php=$(php -r 'echo PHP_VERSION;')"
echo "work=${WORK}"

rm -rf "$WORK"
mkdir -p "$WORK"
bash "$ROOT/scripts/ci-stage-production-tree.sh" "$PLUGIN_STAGE"

curl -sSLo "$WORK/wp-cli.phar" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
php "$WORK/wp-cli.phar" --info >/dev/null
WP=(php "$WORK/wp-cli.phar" --allow-root)

curl -sSLo "$WORK/wordpress.tar.gz" https://wordpress.org/latest.tar.gz
mkdir -p "$WORK/src"
tar -xzf "$WORK/wordpress.tar.gz" -C "$WORK/src"
WP_SRC="$WORK/src/wordpress"

mysql_admin() {
	php -r '
		$h = getenv("CETECH_DE_WP_DB_HOST") ?: "127.0.0.1";
		$p = (int) (getenv("CETECH_DE_WP_DB_PORT") ?: 3306);
		$u = getenv("CETECH_DE_WP_DB_USER") ?: "root";
		$w = getenv("CETECH_DE_WP_DB_PASSWORD") ?: "wordpress";
		mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
		$db = new mysqli($h, $u, $w, "", $p);
		foreach (array_slice($argv, 1) as $sql) {
			$db->query($sql);
		}
	' -- "$@"
}

export CETECH_DE_WP_DB_HOST="$DB_HOST"
export CETECH_DE_WP_DB_PORT="$DB_PORT"
export CETECH_DE_WP_DB_USER="$DB_USER"
export CETECH_DE_WP_DB_PASSWORD="$DB_PASSWORD"

mysql_admin \
	"CREATE DATABASE IF NOT EXISTS \`${DB_NAME_CLEAN}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" \
	"CREATE DATABASE IF NOT EXISTS \`${DB_NAME_UPGRADE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

install_site() {
	local dest="$1"
	local dbname="$2"
	rm -rf "$dest"
	mkdir -p "$dest"
	cp -a "$WP_SRC/." "$dest/"
	"${WP[@]}" config create \
		--path="$dest" \
		--dbname="$dbname" \
		--dbuser="$DB_USER" \
		--dbpass="$DB_PASSWORD" \
		--dbhost="${DB_HOST}:${DB_PORT}" \
		--skip-check \
		--force
	"${WP[@]}" config set WP_DEBUG true --raw --path="$dest"
	"${WP[@]}" config set WP_DEBUG_LOG true --raw --path="$dest"
	"${WP[@]}" config set WP_DEBUG_DISPLAY false --raw --path="$dest"
	"${WP[@]}" core install \
		--path="$dest" \
		--url="http://127.0.0.1:8085" \
		--title="CETECH PHP 8.5 QA" \
		--admin_user="admin" \
		--admin_password="admin" \
		--admin_email="qa@example.com" \
		--skip-email
	"${WP[@]}" plugin install woocommerce --activate --path="$dest"
	"${WP[@]}" eval --path="$dest" '
		if ( function_exists( "wc_get_container" ) && class_exists( "Automattic\\WooCommerce\\Internal\\Features\\FeaturesController" ) ) {
			$controller = wc_get_container()->get( Automattic\WooCommerce\Internal\Features\FeaturesController::class );
			$controller->change_feature_is_enabled( "custom_order_tables", true );
		}
		update_option( "woocommerce_custom_orders_table_enabled", "yes" );
		echo "hpos=" . (string) get_option( "woocommerce_custom_orders_table_enabled" ) . PHP_EOL;
	'
	"${WP[@]}" wc tool run install_pages --user=1 --path="$dest" || true
}

copy_plugin() {
	local dest="$1"
	local source="$2"
	local plugin_dir="$dest/wp-content/plugins/cetech-woocommerce-delivery-engine"
	rm -rf "$plugin_dir"
	mkdir -p "$plugin_dir"
	cp -a "$source/." "$plugin_dir/"
}

cat > "$WORK/inspect-engine.php" <<'PHP'
<?php
$label  = isset( $args[0] ) ? (string) $args[0] : (string) getenv( 'CETECH_DE_SMOKE_LABEL' );
$active = in_array( 'cetech-woocommerce-delivery-engine/cetech-woocommerce-delivery-engine.php', (array) get_option( 'active_plugins', array() ), true );
$version = defined( 'CETECH_DE_VERSION' ) ? CETECH_DE_VERSION : 'missing';
$schema  = (string) get_option( 'cetech_de_db_version', 'missing' );
global $wpdb;
$table_like = $wpdb->esc_like( $wpdb->prefix . 'delivery_engine_' ) . '%';
$tables     = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_like ) );
$geo_like   = $wpdb->esc_like( $wpdb->prefix . 'delivery_engine_geography_locations' );
$geo        = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $geo_like ) );
$hpos       = (string) get_option( 'woocommerce_custom_orders_table_enabled', '' );
$as         = class_exists( 'ActionScheduler' ) ? 'present' : 'missing';
$blocks     = class_exists( 'CetechDeliveryEngine\\Integrations\\Blocks\\BlocksCheckoutAdapter' ) ? 'adapter_loaded' : 'adapter_missing';
echo $label
	. ' php=' . PHP_VERSION
	. ' active=' . ( $active ? 'yes' : 'no' )
	. ' version=' . $version
	. ' schema=' . $schema
	. ' tables=' . count( $tables )
	. ' geo=' . ( $geo ? 'schema6_tables_ok' : 'schema6_tables_missing' )
	. ' hpos=' . $hpos
	. ' action_scheduler=' . $as
	. ' blocks=' . $blocks
	. PHP_EOL;
if ( ! $active ) {
	fwrite( STDERR, "plugin not active\n" );
	exit( 1 );
}
if ( '6' !== $schema ) {
	fwrite( STDERR, "schema is not 6\n" );
	exit( 1 );
}
if ( count( $tables ) < 10 ) {
	fwrite( STDERR, "too few Delivery Engine tables\n" );
	exit( 1 );
}
if ( 'yes' !== $hpos ) {
	fwrite( STDERR, "HPOS is not enabled\n" );
	exit( 1 );
}
if ( 'present' !== $as ) {
	fwrite( STDERR, "Action Scheduler missing after WooCommerce activation\n" );
	exit( 1 );
}
PHP

inspect_engine() {
	local dest="$1"
	local label="$2"
	"${WP[@]}" plugin activate cetech-woocommerce-delivery-engine --path="$dest"
	"${WP[@]}" eval-file "$WORK/inspect-engine.php" "$label" --path="$dest"
}

CLEAN="$WORK/clean"
install_site "$CLEAN" "$DB_NAME_CLEAN"
copy_plugin "$CLEAN" "$PLUGIN_STAGE"
inspect_engine "$CLEAN" "clean"

STORE_JSON="$WORK/store-cart.json"
php -S 127.0.0.1:8085 -t "$CLEAN" >"$WORK/php-server.log" 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
sleep 2
curl -fsS "http://127.0.0.1:8085/?rest_route=/wc/store/v1/cart" -o "$STORE_JSON"
php -r '
	$json = json_decode((string) file_get_contents($argv[1]), true);
	if ( ! is_array($json) || ! array_key_exists("items", $json) ) {
		fwrite(STDERR, "Store API cart did not return a cart payload\n");
		exit(1);
	}
	echo "store_api=cart_ok\n";
' "$STORE_JSON"
"${WP[@]}" eval --path="$CLEAN" '
	$checkout = get_option( "woocommerce_checkout_page_id" );
	$cart = get_option( "woocommerce_cart_page_id" );
	echo "classic_cart_page=" . (int) $cart . " classic_checkout_page=" . (int) $checkout . PHP_EOL;
	if ( (int) $checkout < 1 || (int) $cart < 1 ) {
		fwrite( STDERR, "WooCommerce cart/checkout pages were not created\n" );
		exit( 1 );
	}
'

DEBUG_LOG="$CLEAN/wp-content/debug.log"
if [[ -f "$DEBUG_LOG" ]] && grep -E 'PHP (Fatal|Parse) error' "$DEBUG_LOG" | grep -Ei 'cetech|delivery.engine' >/dev/null; then
	echo "Delivery Engine PHP fatal found in debug.log" >&2
	grep -E 'PHP (Fatal|Parse) error' "$DEBUG_LOG" >&2 || true
	exit 1
fi
echo "debug_log=no_delivery_engine_fatal"

kill "$SERVER_PID" 2>/dev/null || true
trap - EXIT

echo "Downloading immutable RC.12 ZIP for upgrade verification"
curl -fsSL "$RC12_ZIP_URL" -o "$WORK/rc12.zip"
php -r '
	$zip = new ZipArchive();
	if ( true !== $zip->open($argv[1]) ) {
		fwrite(STDERR, "could not open RC.12 ZIP\n");
		exit(1);
	}
	$zip->extractTo($argv[2]);
	$zip->close();
' "$WORK/rc12.zip" "$WORK/rc12-extract"
RC12_ROOT="$WORK/rc12-extract/cetech-woocommerce-delivery-engine"
if [[ ! -f "$RC12_ROOT/cetech-woocommerce-delivery-engine.php" ]]; then
	echo "RC.12 extract missing plugin root" >&2
	exit 1
fi

UPGRADE="$WORK/upgrade"
install_site "$UPGRADE" "$DB_NAME_UPGRADE"
copy_plugin "$UPGRADE" "$RC12_ROOT"
"${WP[@]}" plugin activate cetech-woocommerce-delivery-engine --path="$UPGRADE"
"${WP[@]}" option update cetech_php85_upgrade_sentinel keep_me --path="$UPGRADE"
inspect_engine "$UPGRADE" "rc12_before_upgrade"
copy_plugin "$UPGRADE" "$PLUGIN_STAGE"
inspect_engine "$UPGRADE" "upgrade"
SENTINEL="$("${WP[@]}" option get cetech_php85_upgrade_sentinel --path="$UPGRADE")"
echo "sentinel=${SENTINEL}"
if [[ "$SENTINEL" != "keep_me" ]]; then
	echo "upgrade sentinel was not retained" >&2
	exit 1
fi

echo "wordpress_woocommerce_php85_smoke=PASS"
