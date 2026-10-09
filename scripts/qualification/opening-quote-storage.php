<?php

declare(strict_types=1);

use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Bootstrap\Deactivator;
use CetechDeliveryEngine\Bootstrap\Uninstaller;
use CetechDeliveryEngine\Core\Versioning\MigrationRunner;
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteSchema;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteStorageFixtures;

/** Q02 native physical storage, migration and preservation; no checkout adoption. */
return static function ( callable $check ): void {
	global $wpdb;
	if ( '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) || ! defined( 'WP_ADMIN' ) || true !== WP_ADMIN || '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) || ! defined( 'DB_HOST' ) || 1 !== preg_match( '/^127\.0\.0\.1(?::[0-9]+)?$/D', DB_HOST ) || ! defined( 'DB_NAME' ) || 1 !== preg_match( '/^cetech_wp_opening_qualification(?:_[a-z0-9]+)?$/D', DB_NAME ) || ! $wpdb instanceof wpdb || wpdb::class !== get_class( $wpdb ) || is_multisite() || ! isset( $GLOBALS['wp_object_cache'] ) || WP_Object_Cache::class !== get_class( $GLOBALS['wp_object_cache'] ) || is_file( WP_CONTENT_DIR . '/object-cache.php' ) || is_file( WP_CONTENT_DIR . '/db.php' ) ) { throw new RuntimeException( 'Q02 proof requires the marked native default-cache fixture.' ); }
	foreach ( get_included_files() as $included ) { if ( str_ends_with( str_replace( '\\', '/', $included ), '/tests/bootstrap.php' ) ) { throw new RuntimeException( 'Unit bootstrap is forbidden in native quote storage proof.' ); } }
	$root = dirname( __DIR__, 2 );
	require_once $root . '/tests/Support/DeliveryQuote/QuoteStorageFixtures.php';
	require_once __DIR__ . '/opening-quote-storage-support.php';
	$main = $wpdb; $original = [];
	foreach ( [ 'wpdb', 'wp_roles', 'wp_user_roles', 'wp_object_cache', 'wp_rewrite', 'current_user', 'user_ID', 'blog_id' ] as $key ) { $original[$key] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[$key] ?? null ]; }
	$schema_options = static fn (): array => $main->get_results( $main->prepare( "SELECT option_id,option_name,option_value,autoload FROM `{$main->options}` WHERE option_name IN (%s,%s) ORDER BY option_name", SchemaVersion::OPTION_NAME, MigrationStatus::OPTION_NAME ), ARRAY_A );
	$main_definitions = static function () use ( $main ): array {
		$result = [];
		foreach ( [ ...DeliveryQuoteSchema::tables( $main->prefix ), $main->prefix . 'delivery_engine_rate_cards' ] as $table ) {
			if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/D', $table ) ) { throw new RuntimeException( 'Native quote source identity is invalid.' ); }
			$row = $main->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			if ( ! is_array( $row ) || ! is_string( $row[1] ?? null ) ) { throw new RuntimeException( 'Native quote source definition is unavailable.' ); }
			$result[$table] = $row[1];
		}
		return $result;
	};
	$options_before = $schema_options(); $definitions_before = $main_definitions();
	$fixture = null; $sessions = []; $standalone_path = null; $filter_installed = false; $publication_filter_installed = false; $failure = null;
	$no_hard_flush = static fn (): bool => false;
	$publication_calls = 0;
	$deny_publication = static function ( mixed $new, mixed $old ) use ( &$publication_calls ): mixed { ++$publication_calls; return 1 === $publication_calls ? $old : $new; };
	$read_quote = static function ( $factory, $id ) use ( &$sessions ): array {
		$session = $factory->open(); $sessions[] = $session;
		$readiness = new DeliveryQuoteReadiness( $session ); $readiness->assert_ready();
		if ( ! $session->begin() ) { throw new RuntimeException( 'Native quote read owner was not acquired.' ); }
		$row = ( new DeliveryQuoteRepository( $session, $readiness ) )->find_quote( $id );
		if ( null === $row || ! $session->rollback() || ! $session->retire() ) { throw new RuntimeException( 'Native quote physical read did not complete.' ); }
		return $row->row();
	};
	try {
		$main_readiness = new DeliveryQuoteReadiness( $main ); $main_readiness->assert_ready();
		$check( 'NATIVE-W2Q02-MAIN-SCHEMA9-EXACT-STORES-RATE-INDEX', '11' === (string) get_option( SchemaVersion::OPTION_NAME ) && 4 === count( $definitions_before ) && [ 'ready' => true, 'code' => 'ready' ] === $main_readiness->get_status(), [ 'quote_stores' => 3, 'rate_index' => DeliveryQuoteSchema::RATE_INDEX, 'currency_column' => 'base_currency', 'unit_bootstrap' => false ] );
		$fixture = new CetechNativeQuoteStorageFixture( $main, 99175 ); $fixture->install_schema8(); $fixture->select();
		if ( '8' !== (string) get_option( SchemaVersion::OPTION_NAME ) || 33 !== $fixture->prefix_table_count() ) { throw new RuntimeException( 'Native quote schema8 fixture did not open.' ); }
		// A compatible partial store must be repaired by actual WordPress
		// dbDelta, rather than a SQL-fixture stand-in for its behavior.
		$partial_table = $fixture->prefix . 'delivery_engine_' . DeliveryQuoteSchema::QUOTES_SUFFIX;
		$ddl = DeliveryQuoteSchema::create_table_statements( $GLOBALS['wpdb']->get_charset_collate(), $fixture->prefix . 'delivery_engine_' );
		$fixture->execute( $ddl[DeliveryQuoteSchema::QUOTES_SUFFIX] );
		$fixture->execute( "ALTER TABLE `{$partial_table}` DROP INDEX site_accept" );
		$partial_before = $fixture->rows( "SHOW CREATE TABLE `{$partial_table}`" );
		$missing_before = [] === $fixture->rows( "SHOW INDEX FROM `{$partial_table}` WHERE Key_name='site_accept'" );
		$quote_columns_before = $fixture->rows( "SHOW FULL COLUMNS FROM `{$partial_table}`" );
		$migration = require $root . '/database/migrations/20261007055100_create_delivery_quote_tables.php';
		$runner = new MigrationRunner( new Logger() ); $runner->set_migrations( [ $migration ] );
		add_filter( 'pre_update_option_' . SchemaVersion::OPTION_NAME, $deny_publication, PHP_INT_MAX, 2 ); $publication_filter_installed = true;
		$runner->run();
		remove_filter( 'pre_update_option_' . SchemaVersion::OPTION_NAME, $deny_publication, PHP_INT_MAX ); $publication_filter_installed = false;
		$failed_status = MigrationStatus::get();
		$physical_version = $fixture->rows( "SELECT option_value FROM `{$fixture->prefix}options` WHERE option_name='" . SchemaVersion::OPTION_NAME . "'" );
		$refused_ready = ( new DeliveryQuoteReadiness( $GLOBALS['wpdb'] ) )->get_status();
		$repaired_index = $fixture->rows( "SHOW INDEX FROM `{$partial_table}` WHERE Key_name='site_accept'" );
		$physical_definition_verified = true;
		try { ( new DeliveryQuoteReadiness( $GLOBALS['wpdb'] ) )->verify(); } catch ( Throwable ) { $physical_definition_verified = false; }
		$check( 'NATIVE-W2Q02-COMPATIBLE-PARTIAL-NATIVE-DBDELTA-REPAIR', $missing_before && [] !== $partial_before && 23 === count( $quote_columns_before ) && $quote_columns_before === $fixture->rows( "SHOW FULL COLUMNS FROM `{$partial_table}`" ) && 2 === count( $repaired_index ) && 'site_id' === $repaired_index[0]['Column_name'] && 'accept_namespace_hash' === $repaired_index[1]['Column_name'] && '0' === (string) $repaired_index[0]['Non_unique'] && $physical_definition_verified, [ 'missing_named_index_before' => $missing_before, 'actual_dbdelta' => function_exists( 'dbDelta' ), 'source_definition_sha256' => hash( 'sha256', $ddl[DeliveryQuoteSchema::QUOTES_SUFFIX] ), 'partial_definition_sha256' => hash( 'sha256', json_encode( $partial_before, JSON_THROW_ON_ERROR ) ), 'original_columns_unchanged' => $quote_columns_before === $fixture->rows( "SHOW FULL COLUMNS FROM `{$partial_table}`" ) ] );
		$check( 'NATIVE-W2Q02-REFUSED-WORDPRESS-PUBLICATION-KEEPS-SCHEMA8', 1 === $publication_calls && 1 === count( $physical_version ) && '8' === $physical_version[0]['option_value'] && '8' === (string) get_option( SchemaVersion::OPTION_NAME ) && 'failed' === ( $failed_status['status'] ?? null ) && '8' === ( $failed_status['from_version'] ?? null ) && '9' === ( $failed_status['to_version'] ?? null ) && DeliveryQuoteReadiness::MIGRATION_ID === ( $failed_status['migration_id'] ?? null ) && false === $refused_ready['ready'] && 'schema_unavailable' === $refused_ready['code'] && $physical_definition_verified && 36 === $fixture->prefix_table_count(), [ 'publication_attempts' => $publication_calls, 'physical_schema' => '8', 'migration_status' => $failed_status['status'] ?? null, 'new_physical_stores_present' => 36 === $fixture->prefix_table_count(), 'quote_readiness' => $refused_ready['code'] ] );
		// Retry the actual migration after the transient WordPress refusal.
		$runner->run();
		$status = MigrationStatus::get(); $ready = new DeliveryQuoteReadiness( $GLOBALS['wpdb'] ); $ready->assert_ready();
		$check( 'NATIVE-W2Q02-NATIVE-WPDB-DBDELTA-FORWARD-MIGRATION', '9' === (string) get_option( SchemaVersion::OPTION_NAME ) && 'success' === ( $status['status'] ?? null ) && '8' === ( $status['from_version'] ?? null ) && '9' === ( $status['to_version'] ?? null ) && DeliveryQuoteReadiness::MIGRATION_ID === ( $status['migration_id'] ?? null ) && 36 === $fixture->prefix_table_count() && wpdb::class === get_class( $GLOBALS['wpdb'] ) && WP_Object_Cache::class === get_class( $GLOBALS['wp_object_cache'] ), [ 'from_schema' => '8', 'to_schema' => '9', 'native_dbdelta' => function_exists( 'dbDelta' ), 'isolated_server_context' => true ] );
		$issued = QuoteStorageFixtures::quote( site: $fixture->site );
		$parent_issued = QuoteStorageFixtures::quote( id: 2, site: $fixture->site );
		$accepted = QuoteStorageFixtures::quote( id: 2, site: $fixture->site, quote_id: $parent_issued->header()->id(), state: 'accepted' );
		$binding = QuoteStorageFixtures::binding( $accepted ); $budget = QuoteStorageFixtures::budget( site: $fixture->site );
		$owner = $fixture->factory->open(); $sessions[] = $owner; $readiness = new DeliveryQuoteReadiness( $owner ); $readiness->assert_ready();
		if ( ! $owner->begin() || ! $owner->validate_tables( DeliveryQuoteSchema::tables( $fixture->prefix ) ) ) { throw new RuntimeException( 'Native quote write owner was not acquired.' ); }
		$repository = new DeliveryQuoteRepository( $owner, $readiness );
		// This is a synthetic storage CAS, not a C03 or checkout acceptance.
		$written = 1 === $repository->insert_quote( $issued ) && 2 === $repository->insert_quote( $parent_issued ) && $repository->replace_quote( $parent_issued, $accepted ) && 1 === $repository->insert_binding( $binding ) && 1 === $repository->insert_budget( $budget );
		$committed = OperationCommitResult::Acknowledged === $owner->commit();
		$stored = $read_quote( $fixture->factory, $issued->header()->id() ); $rows_before = $fixture->quote_rows();
		$roundtrip_owner = $fixture->factory->open(); $sessions[] = $roundtrip_owner;
		if ( ! $roundtrip_owner->begin() ) { throw new RuntimeException( 'Native quote roundtrip owner was not acquired.' ); }
		$roundtrip_repository = new DeliveryQuoteRepository( $roundtrip_owner );
		$stored_parent = $roundtrip_repository->find_quote( $accepted->header()->id() );
		$stored_binding = null === $stored_parent ? null : $roundtrip_repository->find_binding( $stored_parent );
		$stored_budget = $roundtrip_repository->find_budget( $budget->kind(), $budget->row()['slot_key'], QuoteTime::parse( $budget->row()['window_start'] ) );
		$roundtrip = $accepted->row() === $stored_parent?->row() && $binding->row() === $stored_binding?->row() && $budget->row() === $stored_budget?->row();
		$roundtrip_released = $roundtrip_owner->rollback() && $roundtrip_owner->retire();
		$check( 'NATIVE-W2Q02-OWNED-SYNTHETIC-QUOTE-COMMITTED', $written && $committed && $roundtrip && $roundtrip_released && $issued->row() === $stored && [ 2, 1, 1 ] === array_map( count( ... ), array_values( $rows_before ) ), [ 'synthetic_profile' => 'fixture_v1', 'committed_quote_rows' => 2, 'binding_rows' => 1, 'budget_rows' => 1, 'strict_repository_roundtrip' => $roundtrip, 'checkout_acceptance_claimed' => false ] );
		$stale = $stored; $stale['private_body_json'] = 'PRIVATE-Q02-STALE-CACHE-BODY'; $stale['revision'] = 999;
		$cache_key = 'quote:' . $issued->header()->id()->value(); $cache_group = 'cetech-q02-native-storage-fixture';
		wp_cache_set( $cache_key, $stale, $cache_group );
		// Also warm a real WordPress option cache. Runtime readiness must use
		// physical publication facts even while this advisory read reports8.
		$alloptions = wp_cache_get( 'alloptions', 'options' ); $alloptions = is_array( $alloptions ) ? $alloptions : [];
		$alloptions[SchemaVersion::OPTION_NAME] = '8'; wp_cache_set( 'alloptions', $alloptions, 'options' );
		wp_cache_set( SchemaVersion::OPTION_NAME, '8', 'options' );
		$advisory_version = (string) get_option( SchemaVersion::OPTION_NAME );
		$fresh = $read_quote( $fixture->factory, $issued->header()->id() );
		$check( 'NATIVE-W2Q02-WARM-DEFAULT-CACHE-CANNOT-OVERRIDE-PHYSICAL-QUOTE', WP_Object_Cache::class === get_class( $GLOBALS['wp_object_cache'] ) && '8' === $advisory_version && $stale === wp_cache_get( $cache_key, $cache_group ) && $stored === $fresh && $fresh['private_body_json'] !== $stale['private_body_json'], [ 'default_cache_warmed' => true, 'advisory_schema' => $advisory_version, 'physical_schema' => '9', 'physical_repository_read' => true, 'shopper_cache_adoption_claimed' => false ] );
		$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
		$retired = $owner->retire(); $old_refused = false;
		try { $old_refused = false === $owner->get_row( 'SELECT 1' ); } catch ( Throwable ) { $old_refused = true; }
		$replacement = $read_quote( $fixture->factory, $issued->header()->id() );
		$check( 'NATIVE-W2Q02-RETIRED-CONNECTION-REPLACEMENT-IMMUTABLE-FACTS', $retired && $owner->is_retired() && $old_refused && $stored === $replacement && $stored['header_json'] === $replacement['header_json'] && $stored['private_body_json'] === $replacement['private_body_json'], [ 'actual_native_connection_retired' => true, 'replacement_native_connection' => true, 'transparent_reconnect' => false ] );
		$installed_root = dirname( (string) ( new ReflectionClass( CetechDeliveryEngine\Bootstrap\Plugin::class ) )->getFileName(), 3 );
		$child = CetechNativeQuoteStorageFixture::child( __DIR__ . '/opening-quote-storage-reader.php', [ 'wp_load' => rtrim( ABSPATH, '/' ) . '/wp-load.php', 'plugin_root' => $installed_root, 'prefix' => $fixture->prefix, 'site' => $fixture->site, 'quote_uuid' => $issued->header()->id()->value(), 'row_hash' => hash( 'sha256', json_encode( $stored, JSON_THROW_ON_ERROR ) ), 'header_hash' => hash( 'sha256', $stored['header_json'] ), 'body_hash' => hash( 'sha256', $stored['private_body_json'] ) ] );
		$check( 'NATIVE-W2Q02-FRESH-WP-LOAD-PROCESS-EXACT-STORED-FACTS', 'PASS' === ( $child['status'] ?? null ) && is_int( $child['process_id'] ?? null ) && getmypid() !== $child['process_id'] && true === ( $child['wp_load'] ?? null ) && true === ( $child['default_object_cache'] ?? null ) && '9' === ( $child['schema'] ?? null ) && true === ( $child['exact_row'] ?? null ) && true === ( $child['immutable_header'] ?? null ) && true === ( $child['immutable_body'] ?? null ) && true === ( $child['retired'] ?? null ), [ 'fresh_os_process' => is_int( $child['process_id'] ?? null ) ? getmypid() !== $child['process_id'] : null, 'actual_wp_load' => $child['wp_load'] ?? null, 'default_object_cache' => $child['default_object_cache'] ?? null, 'unit_bootstrap' => false, 'installed_candidate_autoload' => $child['installed_candidate_autoload'] ?? null, 'reader_status' => $child['status'] ?? 'FAIL', 'reader_phase' => $child['phase'] ?? 'unreported', 'reader_error_class' => $child['error_class'] ?? null, 'reader_transport' => $child['transport_code'] ?? 'unreported', 'reader_exit_code' => $child['exit_code'] ?? null, 'reader_signal' => $child['signal'] ?? null, 'reader_stderr_present' => $child['stderr_present'] ?? null, 'exact_row' => $child['exact_row'] ?? null, 'immutable_header' => $child['immutable_header'] ?? null, 'immutable_body' => $child['immutable_body'] ?? null, 'reader_schema9' => $child['schema9'] ?? null, 'reader_rolled_back' => $child['rolled_back'] ?? null, 'reader_retired' => $child['retired'] ?? null ] );
		if ( is_object( $original['wp_rewrite'][1] ) ) { $GLOBALS['wp_rewrite'] = clone $original['wp_rewrite'][1]; }
		add_filter( 'flush_rewrite_rules_hard', $no_hard_flush, PHP_INT_MAX ); $filter_installed = true;
		Deactivator::deactivate();
		$check( 'NATIVE-W2Q02-DEACTIVATION-PRESERVES-THREE-STORES', $rows_before === $fixture->quote_rows(), [ 'preserved_stores' => 3, 'exact_full_rows' => true ] );
		$fixture->reset_roles(); update_option( DataLifecycleManifest::CAPABILITIES_MARKER, 4, false ); update_option( DataLifecycleManifest::UNINSTALL_INTENT, 1, false );
		Uninstaller::uninstall();
		$raw_status = get_option( DataLifecycleManifest::UNINSTALL_STATUS, '' ); $uninstall_status = is_string( $raw_status ) ? json_decode( $raw_status, true ) : null;
		$check( 'NATIVE-W2Q02-COMPOSER-EXPLICIT-UNINSTALL-PRESERVES-THREE-STORES', 'completed' === ( $uninstall_status['status'] ?? null ) && false === get_option( DataLifecycleManifest::UNINSTALL_INTENT, false ) && $rows_before === $fixture->quote_rows(), [ 'actual_uninstaller' => true, 'preserved_stores' => 3, 'exact_full_rows' => true ] );
		$fixture->reset_roles(); update_option( DataLifecycleManifest::CAPABILITIES_MARKER, 4, false ); update_option( DataLifecycleManifest::UNINSTALL_INTENT, 1, false );
		$standalone_path = sys_get_temp_dir() . '/cetech-q02-standalone-' . bin2hex( random_bytes( 8 ) );
		CetechNativeQuoteStorageFixture::copy_standalone( $root, $standalone_path );
		$fallback = CetechNativeQuoteStorageFixture::child( __DIR__ . '/opening-data-lifecycle-uninstall-reader.php', [ 'wp_load' => rtrim( ABSPATH, '/' ) . '/wp-load.php', 'uninstall' => $standalone_path . '/uninstall.php', 'prefix' => $fixture->prefix, 'site' => $fixture->site ] );
		$intent_rows = $fixture->rows( "SELECT option_id FROM `{$fixture->prefix}options` WHERE option_name='" . DataLifecycleManifest::UNINSTALL_INTENT . "'" );
		$check( 'NATIVE-W2Q02-STANDALONE-UNINSTALL-PRESERVES-THREE-STORES', 'PASS' === ( $fallback['status'] ?? null ) && 'completed' === ( $fallback['uninstall_status'] ?? null ) && false === ( $fallback['intent_present'] ?? null ) && false === ( $fallback['composer_loaded'] ?? null ) && false === ( $fallback['woocommerce_loaded'] ?? null ) && ! is_readable( $standalone_path . '/vendor/autoload.php' ) && [] === $intent_rows && $rows_before === $fixture->quote_rows(), [ 'fresh_wp_only_process' => true, 'no_vendor' => true, 'preserved_stores' => 3, 'exact_full_rows' => true ] );
	} catch ( Throwable $error ) { $failure = $error; throw $error; } finally {
		$cleanup = true;
		if ( $publication_filter_installed ) { remove_filter( 'pre_update_option_' . SchemaVersion::OPTION_NAME, $deny_publication, PHP_INT_MAX ); }
		if ( $filter_installed ) { remove_filter( 'flush_rewrite_rules_hard', $no_hard_flush, PHP_INT_MAX ); }
		foreach ( $sessions as $session ) { try { if ( $session->in_transaction() ) { $cleanup = $session->rollback() && $cleanup; } $cleanup = $session->retire() && $cleanup; } catch ( Throwable ) { $cleanup = false; } }
		foreach ( $original as $key => [ $exists, $value ] ) { if ( $exists ) { $GLOBALS[$key] = $value; } else { unset( $GLOBALS[$key] ); } }
		if ( null !== $fixture ) { $cleanup = $fixture->cleanup() && $cleanup; }
		if ( null !== $standalone_path ) { $cleanup = CetechNativeQuoteStorageFixture::remove_standalone( $standalone_path ) && $cleanup; }
		$condition = $cleanup && $GLOBALS['wpdb'] === $main && $GLOBALS['wp_object_cache'] === $original['wp_object_cache'][1] && get_current_blog_id() === (int) $original['blog_id'][1] && $options_before === $schema_options() && $definitions_before === $main_definitions() && '11' === (string) get_option( SchemaVersion::OPTION_NAME );
		$cleanup_evidence = [ 'owned_tables_removed' => $cleanup, 'main_schema_options_unchanged' => $options_before === $schema_options(), 'main_definitions_unchanged' => $definitions_before === $main_definitions(), 'native_context_restored' => $GLOBALS['wpdb'] === $main ];
		if ( null === $failure ) { $check( 'NATIVE-W2Q02-ISOLATED-FIXTURE-AND-MAIN-SOURCE-CLEANUP', $condition, $cleanup_evidence ); }
		else { try { $check( 'NATIVE-W2Q02-ISOLATED-FIXTURE-AND-MAIN-SOURCE-CLEANUP', $condition, $cleanup_evidence ); } catch ( Throwable ) { /* Preserve the original failed check while recording cleanup. */ } }
	}
};
