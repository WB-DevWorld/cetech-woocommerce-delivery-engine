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
NATIVE_OPENING_ENABLED="${CETECH_DE_NATIVE_OPENING_QUALIFICATION:-0}"
HTTP_OPENING_ENABLED="${CETECH_DE_HTTP_OPENING_QUALIFICATION:-0}"
WP_VERSION="${CETECH_DE_WP_VERSION:-latest}"
WOO_VERSION="${CETECH_DE_WOO_VERSION:-}"
RC12_ZIP_URL="${CETECH_DE_RC12_ZIP_URL:-https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/releases/download/v1.0.0-rc.12/cetech-woocommerce-delivery-engine-1.0.0-rc.12.zip}"
PHP_MAJOR_MINOR="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"

if [[ "$PHP_MAJOR_MINOR" != "8.5" ]]; then
	echo "BLOCKED: this smoke must run on PHP 8.5, found $(php -r 'echo PHP_VERSION;')" >&2
	exit 1
fi

if [[ "$NATIVE_OPENING_ENABLED" == "1" && "$DB_HOST" != "127.0.0.1" ]]; then
	echo "BLOCKED: targeted native checks require the disposable loopback database service" >&2
	exit 1
fi

if [[ "$HTTP_OPENING_ENABLED" == "1" && "$NATIVE_OPENING_ENABLED" != "1" ]]; then
	echo "BLOCKED: HTTP qualification requires preceding native qualification" >&2
	exit 1
fi

echo "php=$(php -r 'echo PHP_VERSION;')"
echo "work=${WORK}"

rm -rf "$WORK"
mkdir -p "$WORK"
if [[ -n "${CETECH_DE_P06_CURRENT_PACKAGE:-}" ]]; then
  python3 "$ROOT/scripts/qualification/extract-promise-qualification-package.py" \
    "$CETECH_DE_P06_CURRENT_PACKAGE" "${CETECH_DE_P06_CURRENT_PACKAGE%.zip}.json" "$WORK/p06-installed-package" --ref "$(git -C "$ROOT" rev-parse HEAD)"
  PLUGIN_STAGE="$WORK/p06-installed-package/cetech-woocommerce-delivery-engine"
else
  bash "$ROOT/scripts/ci-stage-production-tree.sh" "$PLUGIN_STAGE"
fi

curl -sSLo "$WORK/wp-cli.phar" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
php "$WORK/wp-cli.phar" --info >/dev/null
WP=(php "$WORK/wp-cli.phar" --allow-root)

if [[ "$WP_VERSION" != "latest" && ! "$WP_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "BLOCKED: unsupported diagnostic WordPress version" >&2; exit 1
fi
if [[ -n "$WOO_VERSION" && ! "$WOO_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "BLOCKED: unsupported diagnostic WooCommerce version" >&2; exit 1
fi
WP_ARCHIVE="https://wordpress.org/latest.tar.gz"
if [[ "$WP_VERSION" != "latest" ]]; then WP_ARCHIVE="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"; fi
curl -fsSLo "$WORK/wordpress.tar.gz" "$WP_ARCHIVE"
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
	local woo_version_args=()
	if [[ -n "$WOO_VERSION" ]]; then woo_version_args=("--version=$WOO_VERSION"); fi
	"${WP[@]}" plugin install woocommerce "${woo_version_args[@]}" --activate --path="$dest"
	# WooCommerce 11 removed FeaturesController::change_feature_is_enabled().
	# Prefer the current WP-CLI command, then fall back to remaining controller APIs / options.
	"${WP[@]}" wc hpos enable --user=1 --path="$dest" || true
	"${WP[@]}" eval --path="$dest" '
		if ( function_exists( "wc_get_container" ) && class_exists( "Automattic\\WooCommerce\\Internal\\Features\\FeaturesController" ) ) {
			$controller = wc_get_container()->get( Automattic\WooCommerce\Internal\Features\FeaturesController::class );
			if ( method_exists( $controller, "change_feature_is_enabled" ) ) {
				$controller->change_feature_is_enabled( "custom_order_tables", true );
			} elseif ( method_exists( $controller, "change_feature_enable" ) ) {
				$controller->change_feature_enable( "custom_order_tables", true );
			}
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
$expected_schema = 'rc12_before_upgrade' === $label ? '6' : \CetechDeliveryEngine\Core\Versioning\SchemaVersion::TARGET;
if ( 'rc12_before_upgrade' !== $label && '11' !== $expected_schema ) { fwrite( STDERR, "current candidate schema authority differs\n" ); exit( 1 ); }
if ( $expected_schema !== $schema ) {
	fwrite( STDERR, "schema does not match this installation's expected version\n" );
	exit( 1 );
}
if ( '11' === $expected_schema && ! ( new \CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreReadiness() )->get_status()['ready'] ) {
	fwrite( STDERR, "retained operation storage is not verified ready\n" );
	exit( 1 );
}
if ( '11' === $expected_schema && ! ( new \CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleReadiness() )->get_status()['ready'] ) {
	fwrite( STDERR, "schema-8 rule lifecycle storage is not verified ready\n" );
	exit( 1 );
}
if ( '11' === $expected_schema && ! ( new \CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteReadiness() )->get_status()['ready'] ) {
	fwrite( STDERR, "schema-9 quote storage is not verified ready\n" );
	exit( 1 );
}
if ( '11' === $expected_schema ) {
	$expected_tables = array_map( static fn( string $suffix ): string => $wpdb->prefix . 'delivery_engine_' . $suffix, \CetechDeliveryEngine\Bootstrap\DataLifecycleManifest::DOMAIN_TABLE_SUFFIXES );
	sort( $expected_tables, SORT_STRING ); sort( $tables, SORT_STRING );
	if ( 39 !== count( $expected_tables ) || $tables !== $expected_tables || ! ( new \CetechDeliveryEngine\Infrastructure\Persistence\PromiseStorageReadiness() )->get_status()['ready'] || ! ( new \CetechDeliveryEngine\Infrastructure\Persistence\ShipmentPromiseReadiness() )->get_status()['ready'] ) { fwrite( STDERR, "schema-11 promise storage or exact current table census differs\n" ); exit( 1 ); }
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
source "$ROOT/scripts/qualification/store-smoke-diagnostic.sh"
start_store_smoke_listener
sleep 2
STORE_SMOKE_STAGE="store_api_cart"
curl -fsS "http://127.0.0.1:8085/?rest_route=/wc/store/v1/cart" -o "$STORE_JSON"
STORE_SMOKE_STAGE="cart_payload"
php -r '
	$json = json_decode((string) file_get_contents($argv[1]), true);
	if ( ! is_array($json) || ! array_key_exists("items", $json) ) {
		fwrite(STDERR, "Store API cart did not return a cart payload\n");
		exit(1);
	}
	echo "store_api=cart_ok\n";
' "$STORE_JSON"
STORE_SMOKE_STAGE="classic_pages"
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
STORE_SMOKE_STAGE="debug_log"
if [[ -f "$DEBUG_LOG" ]] && grep -E 'PHP (Fatal|Parse) error' "$DEBUG_LOG" | grep -Ei 'cetech|delivery.engine' >/dev/null; then
	echo "Delivery Engine PHP fatal found in debug.log" >&2
	grep -E 'PHP (Fatal|Parse) error' "$DEBUG_LOG" >&2 || true
	exit 1
fi
echo "debug_log=no_delivery_engine_fatal"

STORE_SMOKE_STAGE="complete"
finish_store_smoke_listener 0

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

# Targeted opening checks get their own database/site, never the clean/upgrade sites.
if [[ "$NATIVE_OPENING_ENABLED" != "1" ]]; then
	echo "opening_native_qualification=NOT_REQUESTED"
	exit 0
fi
DB_NAME_QUALIFICATION="cetech_wp_opening_qualification_$(php -r 'echo bin2hex(random_bytes(6));')"
# Plain CREATE fails on a collision; no populated fixture database is reused.
mysql_admin "CREATE DATABASE \`${DB_NAME_QUALIFICATION}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
NATIVE="$WORK/opening-qualification"
install_site "$NATIVE" "$DB_NAME_QUALIFICATION"
"${WP[@]}" config set DISABLE_WP_CRON true --raw --path="$NATIVE"
copy_plugin "$NATIVE" "$PLUGIN_STAGE"
inspect_engine "$NATIVE" "opening_qualification"
"${WP[@]}" option update cetech_opening_qualification_disposable 1 --path="$NATIVE"
export CETECH_DE_QUALIFICATION_HEAD="$(git -C "$ROOT" rev-parse HEAD)"
export CETECH_DE_QUALIFICATION_CANDIDATE_HEAD="${CETECH_DE_QUALIFICATION_CANDIDATE_HEAD:-$CETECH_DE_QUALIFICATION_HEAD}"
export CETECH_DE_QUALIFICATION_TREE="$(git -C "$ROOT" rev-parse HEAD^{tree})"
"${WP[@]}" --require="$ROOT/scripts/qualification/admin-context.php" \
	eval-file "$ROOT/scripts/qualification/opening-runner.php" --use-include \
	"$WORK/opening-qualification-results.json" --path="$NATIVE"

bash "$ROOT/scripts/ci-quote-placement-hpos-off.sh" "$NATIVE" "$WORK/wp-cli.phar" "$WORK/opening-quote-placement-cpt-results.json"

if [[ "$HTTP_OPENING_ENABLED" == "1" ]]; then
	bash "$ROOT/scripts/ci-opening-http-qualification.sh" "$WORK" "$NATIVE"
	python3 "$ROOT/scripts/qualification/verify-quote-placement-receipts.py" "$WORK/opening-qualification-results.json" "$WORK/opening-quote-placement-cpt-results.json" "$WORK/opening-http-qualification-results.json"
	# P02 is a separate receipt; all retained primary predicates must pass first.
	"${WP[@]}" --require="$ROOT/scripts/qualification/admin-context.php" \
		eval-file "$ROOT/scripts/qualification/opening-promise-storage-runner.php" --use-include \
		"$WORK/opening-promise-storage-results.json" "$WORK/opening-qualification-results.json" \
		"$WORK/opening-quote-placement-cpt-results.json" "$WORK/opening-http-qualification-results.json" --path="$NATIVE"
	python3 "$ROOT/scripts/qualification/verify-promise-storage-receipts.py" "$WORK/opening-promise-storage-results.json" "$WORK/opening-qualification-results.json" "$WORK/opening-quote-placement-cpt-results.json" "$WORK/opening-http-qualification-results.json"
	# P03 executes in a separate pure CLI process against the installed production package.
	CETECH_DE_PROMISE_CALCULATION_QUALIFICATION=1 php "$ROOT/scripts/qualification/opening-promise-calculation-runner.php" \
		"$WORK/opening-promise-calculation-results.json" "$NATIVE/wp-content/plugins/cetech-woocommerce-delivery-engine" "$WORK/opening-qualification-results.json" \
		"$WORK/opening-quote-placement-cpt-results.json" "$WORK/opening-http-qualification-results.json" "$WORK/opening-promise-storage-results.json"
	python3 "$ROOT/scripts/qualification/verify-promise-calculation-receipts.py" "$WORK/opening-promise-calculation-results.json" "$WORK/opening-promise-storage-results.json" "$WORK/opening-qualification-results.json" "$WORK/opening-quote-placement-cpt-results.json" "$WORK/opening-http-qualification-results.json"
	# P04 retains its own native and fresh CPT proofs after all five prior primary receipts.
	"${WP[@]}" --require="$ROOT/scripts/qualification/admin-context.php" \
		eval-file "$ROOT/scripts/qualification/opening-promise-handoff-runner.php" --use-include \
		"$WORK/opening-promise-handoff-results.json" hpos_on "$WORK/opening-qualification-results.json" \
		"$WORK/opening-quote-placement-cpt-results.json" "$WORK/opening-http-qualification-results.json" \
		"$WORK/opening-promise-storage-results.json" "$WORK/opening-promise-calculation-results.json" --path="$NATIVE"
	bash "$ROOT/scripts/ci-promise-handoff-hpos-off.sh" "$NATIVE" "$WORK/wp-cli.phar" \
		"$WORK/opening-promise-handoff-cpt-results.json" "$WORK/opening-qualification-results.json" \
		"$WORK/opening-quote-placement-cpt-results.json" "$WORK/opening-http-qualification-results.json" \
		"$WORK/opening-promise-storage-results.json" "$WORK/opening-promise-calculation-results.json"
	bash "$ROOT/scripts/ci-promise-handoff-http-qualification.sh" "$WORK" "$NATIVE"
	python3 "$ROOT/scripts/qualification/verify-promise-handoff-receipts.py" "$WORK/opening-promise-handoff-results.json" \
		"$WORK/opening-promise-handoff-cpt-results.json" "$WORK/opening-promise-calculation-results.json" \
		"$WORK/opening-promise-storage-results.json" "$WORK/opening-qualification-results.json" \
		"$WORK/opening-quote-placement-cpt-results.json" "$WORK/opening-http-qualification-results.json" \
		"$WORK/opening-http-promise-handoff-results.json"
	# P05 has separate native/CPT/customer receipts, after every retained original proof.
	P05_PRIORS=( "$WORK/opening-qualification-results.json" "$WORK/opening-quote-placement-cpt-results.json" \
		"$WORK/opening-http-qualification-results.json" "$WORK/opening-promise-storage-results.json" \
		"$WORK/opening-promise-calculation-results.json" "$WORK/opening-promise-handoff-results.json" \
		"$WORK/opening-promise-handoff-cpt-results.json" "$WORK/opening-http-promise-handoff-results.json" )
	"${WP[@]}" --require="$ROOT/scripts/qualification/admin-context.php" \
		eval-file "$ROOT/scripts/qualification/opening-promise-native-configuration-runner.php" --use-include \
		"$WORK/opening-promise-native-configuration-results.json" hpos_on "${P05_PRIORS[@]}" --path="$NATIVE"
	bash "$ROOT/scripts/ci-promise-native-configuration-hpos-off.sh" "$NATIVE" "$WORK/wp-cli.phar" \
		"$WORK/opening-promise-native-configuration-cpt-results.json" "${P05_PRIORS[@]}"
	bash "$ROOT/scripts/ci-promise-native-configuration-http-qualification.sh" "$WORK" "$NATIVE"
	python3 "$ROOT/scripts/qualification/verify-opening-promise-native-configuration.py" \
		"$WORK/opening-promise-native-configuration-results.json" "$WORK/opening-promise-native-configuration-cpt-results.json" \
		"${P05_PRIORS[@]}" "$WORK/opening-http-promise-native-configuration-results.json"
	# P06 executes new independent numerical bounds and native lifecycle receipts.
	P06_PRIORS=( "${P05_PRIORS[@]}" "$WORK/opening-promise-native-configuration-results.json" \
		"$WORK/opening-promise-native-configuration-cpt-results.json" "$WORK/opening-http-promise-native-configuration-results.json" )
	export CETECH_DE_P06_WP_CLI="$WORK/wp-cli.phar"
	CETECH_DE_PROMISE_BOUNDS_QUALIFICATION=1 php "$ROOT/scripts/qualification/opening-promise-qualification-bounds-runner.php" \
		"$WORK/opening-promise-qualification-bounds-results.json" "$NATIVE/wp-content/plugins/cetech-woocommerce-delivery-engine" \
		"$WORK/opening-promise-native-configuration-results.json" "$WORK/opening-promise-native-configuration-cpt-results.json" \
		"$WORK/opening-http-promise-native-configuration-results.json"
	P06_BOUND_PRIORS=( "$WORK/opening-promise-native-configuration-results.json" "$WORK/opening-promise-native-configuration-cpt-results.json" "$WORK/opening-http-promise-native-configuration-results.json" )
	"${WP[@]}" --require="$ROOT/scripts/qualification/admin-context.php" eval-file "$ROOT/scripts/qualification/opening-promise-qualification-native-bounds-runner.php" --use-include \
		"$WORK/opening-promise-qualification-native-bounds-results.json" hpos_on "${P06_BOUND_PRIORS[@]}" --path="$NATIVE"
	bash "$ROOT/scripts/ci-promise-qualification-bounds-hpos-off.sh" "$NATIVE" "$WORK/wp-cli.phar" \
		"$WORK/opening-promise-qualification-native-bounds-cpt-results.json" "${P06_BOUND_PRIORS[@]}"
	python3 "$ROOT/scripts/qualification/verify-promise-qualification-bounds.py" \
		"$WORK/opening-promise-qualification-bounds-results.json" "$WORK/opening-promise-qualification-native-bounds-results.json" \
		"$WORK/opening-promise-qualification-native-bounds-cpt-results.json" \
		"$WORK/opening-promise-native-configuration-results.json" "$WORK/opening-promise-native-configuration-cpt-results.json" \
		"${P05_PRIORS[@]}" "$WORK/opening-http-promise-native-configuration-results.json"
	"${WP[@]}" --require="$ROOT/scripts/qualification/admin-context.php" eval-file "$ROOT/scripts/qualification/opening-promise-operational-lifecycle-runner.php" --use-include \
		"$WORK/opening-promise-operational-lifecycle-results.json" hpos_on "${P06_PRIORS[@]}" --path="$NATIVE"
	bash "$ROOT/scripts/ci-promise-operational-lifecycle-hpos-off.sh" "$NATIVE" "$WORK/wp-cli.phar" \
		"$WORK/opening-promise-operational-lifecycle-cpt-results.json" "${P06_PRIORS[@]}"
	python3 "$ROOT/scripts/qualification/verify-promise-operational-lifecycle-receipts.py" \
		"$WORK/opening-promise-operational-lifecycle-results.json" "$WORK/opening-promise-operational-lifecycle-cpt-results.json" \
		"${P06_PRIORS[@]}" "$CETECH_DE_P06_CURRENT_PACKAGE" "$CETECH_DE_P06_PREDECESSOR_PACKAGE" "$CETECH_DE_P06_READER_PACKAGE"
else
	echo "opening_http_qualification=NOT_REQUESTED"
fi
