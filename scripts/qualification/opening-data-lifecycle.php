<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\DataLifecycle\DataLifecycleCleanupService;
use CetechDeliveryEngine\Application\DataLifecycle\ManagedGeographyCache;
use CetechDeliveryEngine\Application\Operation\OperationCoordinator;
use CetechDeliveryEngine\Application\RuleLifecycle\RuleLifecycleService;
use CetechDeliveryEngine\Bootstrap\DataLifecycleBootstrap;
use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Bootstrap\DataLifecycleScheduler;
use CetechDeliveryEngine\Bootstrap\DataLifecycleUninstallExecutor;
use CetechDeliveryEngine\Bootstrap\Deactivator;
use CetechDeliveryEngine\Bootstrap\Uninstaller;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleRegistry;
use CetechDeliveryEngine\Domain\DataLifecycle\ManagedGeographyCacheEnvelope;
use CetechDeliveryEngine\Domain\DataLifecycle\ManagedGeographyCacheIdentity;
use CetechDeliveryEngine\Domain\Operation\OperationProfileRegistry;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyRegistry;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionFactory;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofDatabase;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofOperationProfile;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofEnvelope;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofFamily;

/** C06 native options/roles/AS/Woo boundaries. Only the marked disposable fixture is accepted. */
return static function ( callable $check, ?array $history = null ): void {
	global $wpdb;
	if ( '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) || ! defined( 'WP_ADMIN' ) || true !== WP_ADMIN || '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) || ! defined( 'DB_HOST' ) || 1 !== preg_match( '/^127\.0\.0\.1(?::[0-9]+)?$/D', DB_HOST ) || ! defined( 'DB_NAME' ) || 1 !== preg_match( '/^cetech_wp_opening_qualification(?:_[a-z0-9]+)?$/D', DB_NAME ) || ! $wpdb instanceof wpdb || is_multisite() || ! isset( $GLOBALS['wp_object_cache'] ) || WP_Object_Cache::class !== get_class( $GLOBALS['wp_object_cache'] ) ) { throw new RuntimeException( 'C06 proof requires the marked native default-cache fixture.' ); }
	foreach ( get_included_files() as $included ) { if ( str_ends_with( str_replace( '\\', '/', $included ), '/tests/bootstrap.php' ) ) { throw new RuntimeException( 'Unit bootstrap is forbidden in native lifecycle proof.' ); } }
	$root = dirname( __DIR__, 2 );
	foreach ( [ 'Operation/OperationProofCommand', 'Operation/OperationProofDatabase', 'DataLifecycle/DataLifecycleProofDatabase', 'DataLifecycle/DataLifecycleProofOperationProfile', 'RuleLifecycle/RuleProofDatabase', 'RuleLifecycle/RuleProofEnvelope', 'RuleLifecycle/RuleProofFamily' ] as $fixture ) { require_once $root . '/tests/Support/' . $fixture . '.php'; }
	require_once __DIR__ . '/opening-data-lifecycle-support.php';
	$pressure = static function (): void {
		global $wpdb;
		$site = (int) get_current_blog_id();
		$revision = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name=%s", 'cetech_de_geography_revision' ) );
		$revision = null === $revision ? '0' : (string) $revision;
		// Fixture clock only; server maintenance still uses actual native UTC.
		$cache = new ManagedGeographyCache( clock: static fn (): int => time() - 300 );
		$identity = $cache->identity( $site, 'postcode', 'GH', '', '', 'native-history-' . bin2hex( random_bytes( 4 ) ), 1, $revision, 'en_US' );
		if ( ! $cache->publish( $cache->lookup( $identity ), [ 'required' => false, 'visible' => false ] ) ) { throw new RuntimeException( 'Native history cache pressure was not stored.' ); }
		$worker = new DataLifecycleCleanupService( DataLifecycleRegistry::standard(), new OperationConnectionFactory(), static fn ( int $target ): bool => $target === get_current_blog_id() );
		$state = $worker->read( $site );
		if ( null !== $state->progress && 'completed' !== $state->progress->status ) { $state = $worker->batch( $site, $state->continuation ); }
		if ( null === $state->progress || 'completed' === $state->progress->status ) { $state = $worker->start( $site ); }
		for ( $step = 0; $step < 3 && 'accepted' === $state->status && 'completed' !== $state->progress?->status; ++$step ) { $state = $worker->batch( $site, $state->continuation ); }
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM `{$wpdb->options}` WHERE option_name=%s", $identity->option_name() ) );
		if ( 'accepted' !== $state->status || null === $state->progress || 'completed' !== $state->progress->status || $state->progress->deleted < 1 || null !== $raw ) { throw new RuntimeException( 'Native history cleanup pressure did not complete its bounded pass.' ); }
	};
	if ( null !== $history ) {
		if ( ! is_callable( $history['physical'] ?? null ) || ! is_callable( $history['historical'] ?? null ) ) { throw new RuntimeException( 'C06 historical fixture hooks are invalid.' ); }
		$before = $history['physical'](); $facts = $history['historical'](); $pressure();
		$check( 'NATIVE-C06-HPOS-HISTORICAL-V1V2-MALFORMED-CLEANUP-PRESERVED', $before === $history['physical']() && $facts === $history['historical'](), [ 'actual_hpos_history' => true, 'protected_meta_rows_unchanged' => $before === $history['physical'](), 'historical_planner_unchanged' => $facts === $history['historical']() ] );
		return;
	}
	$original = [];
	foreach ( [ 'wpdb', 'wp_roles', 'wp_user_roles', 'wp_object_cache', 'wp_rewrite', 'current_user', 'user_ID', 'blog_id' ] as $key ) { $original[$key] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[$key] ?? null ]; }
	$main = $wpdb;
	$schema_before = $main->get_results( $main->prepare( "SELECT option_name,option_value,autoload FROM `{$main->options}` WHERE option_name IN (%s,%s) ORDER BY option_name", 'cetech_de_db_version', 'cetech_de_last_migration_status' ), ARRAY_A );
	$fixture = null; $temporary = []; $actions = []; $cleanup_ok = false; $scheduler = null; $principal = null; $principal_caps = null; $role_refusal = null; $role_conflict = null; $fixture_failure = null;
	$no_hard_flush = static fn (): bool => false;
	$foreign_callback = static function (): void {};
	$filter_installed = false;
	$read_fresh = static function ( CetechNativeDataLifecycleFixture $fixture, array $names ) use ( $root ): bool {
		$expectations = [];
		foreach ( $names as $name ) {
			$row = $fixture->option( $name );
			$expectations[] = null === $row ? [ 'name' => $name, 'absent' => true ] : [ 'name' => $name, 'absent' => false, 'raw_hash' => hash( 'sha256', $row['option_value'] ), 'value_hash' => hash( 'sha256', serialize( maybe_unserialize( $row['option_value'] ) ) ), 'autoload' => $row['autoload'] ];
		}
		$decoded = CetechNativeDataLifecycleFixture::child( $root . '/scripts/qualification/opening-data-lifecycle-option-reader.php', [ 'wp_load' => rtrim( ABSPATH, '/' ) . '/wp-load.php', 'prefix' => $fixture->prefix, 'site' => $fixture->site, 'expectations' => $expectations ] );
		return 'PASS' === ( $decoded['status'] ?? null ) && count( $names ) === ( $decoded['present'] ?? -1 ) + ( $decoded['absent'] ?? -1 );
	};
	$copy_fallback = static function ( string $path, bool $with_helpers ) use ( $root ): void {
		if ( ! mkdir( $path, 0700 ) || ! copy( $root . '/uninstall.php', $path . '/uninstall.php' ) ) { throw new RuntimeException( 'Native standalone fixture could not be prepared.' ); }
		if ( ! $with_helpers ) { return; }
		foreach ( array_unique( [ 'src/Bootstrap/DataLifecycleBootstrap.php', ...array_map( static fn ( string $file ): string => 'src/' . $file, DataLifecycleBootstrap::FILES ) ] ) as $relative ) {
			if ( ! is_string( $relative ) || str_contains( $relative, '..' ) || ! str_starts_with( $relative, 'src/' ) ) { throw new RuntimeException( 'Native standalone dependency identity is invalid.' ); }
			$target = $path . '/' . $relative;
			if ( ! is_dir( dirname( $target ) ) && ! mkdir( dirname( $target ), 0700, true ) ) { throw new RuntimeException( 'Native standalone dependency fixture failed.' ); }
			if ( ! copy( $root . '/' . $relative, $target ) ) { throw new RuntimeException( 'Native standalone dependency fixture failed.' ); }
		}
	};
	$run_fallback = static function ( string $path ) use ( &$fixture ): array { return CetechNativeDataLifecycleFixture::child( __DIR__ . '/opening-data-lifecycle-uninstall-reader.php', [ 'wp_load' => rtrim( ABSPATH, '/' ) . '/wp-load.php', 'uninstall' => $path . '/uninstall.php', 'prefix' => $fixture->prefix, 'site' => $fixture->site ] ); };
	$uninstall_status = static function (): ?array { $value = get_option( DataLifecycleManifest::UNINSTALL_STATUS, '' ); return is_string( $value ) && '' !== $value ? json_decode( $value, true, 4, JSON_THROW_ON_ERROR ) : null; };
	try {
		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( [] === $admins ) { throw new RuntimeException( 'Native C06 user-grant fixture requires an administrator.' ); }
		$principal = new WP_User( (int) $admins[0] ); $principal_caps = $principal->caps; $principal->add_cap( 'manage_shipments', true );
		$principal_key = $main->get_blog_prefix( (int) get_current_blog_id() ) . 'capabilities';
		$principal_before = $main->get_row( $main->prepare( "SELECT umeta_id,user_id,meta_key,meta_value FROM `{$main->usermeta}` WHERE user_id=%d AND meta_key=%s", $principal->ID, $principal_key ), ARRAY_A );
		// A server-context fixture ID separates actual AS rows from main-site ticks;
		// this is not a claim of multisite routing certification.
		$GLOBALS['blog_id'] = 99174;
		$fixture = new CetechNativeDataLifecycleFixture( $main ); $fixture->install(); $fixture->seed_preserved_options(); $fixture->select();
		$pack_dir = sys_get_temp_dir() . '/cetech-c06-provider-' . bin2hex( random_bytes( 8 ) );
		if ( ! mkdir( $pack_dir, 0700 ) ) { throw new RuntimeException( 'Native provider preservation fixture failed.' ); } $temporary[] = $pack_dir;
		$outside = tempnam( sys_get_temp_dir(), 'c06-outside-' ); if ( false === $outside ) { throw new RuntimeException( 'Native provider preservation fixture failed.' ); } $temporary[] = $outside;
		$active_file = $pack_dir . '/GH.active-token.aaaaaaaaaaaa.txt'; $unknown_file = $pack_dir . '/GH.unknown-token.zip';
		if ( false === file_put_contents( $active_file, 'PRIVATE-C06-ACTIVE-PACK' ) || false === file_put_contents( $unknown_file, 'PRIVATE-C06-UNKNOWN-PACK' ) || false === file_put_contents( $outside, 'PRIVATE-C06-OUTSIDE' ) || ! symlink( $outside, $pack_dir . '/GH.forged-link.txt' ) ) { throw new RuntimeException( 'Native provider preservation fixture failed.' ); }
		$fixture->execute( "UPDATE `{$fixture->prefix}delivery_engine_geography_packs` SET source_reference=" . $fixture->literal( $active_file ) . ' WHERE id=1' );
		$file_hashes = [ hash_file( 'sha256', $active_file ), hash_file( 'sha256', $unknown_file ), hash_file( 'sha256', $outside ) ];
		foreach ( [ 'actionscheduler_actions', 'actionscheduler_claims', 'actionscheduler_groups', 'actionscheduler_logs' ] as $property ) { if ( isset( $main->{$property} ) ) { $fixture->selected->{$property} = $main->{$property}; } }
		if ( isset( $original['wp_rewrite'][1] ) && is_object( $original['wp_rewrite'][1] ) ) { $GLOBALS['wp_rewrite'] = clone $original['wp_rewrite'][1]; }
		add_filter( 'flush_rewrite_rules_hard', $no_hard_flush, PHP_INT_MAX ); $filter_installed = true;
		$worker = new DataLifecycleCleanupService( DataLifecycleRegistry::standard(), $fixture->factory, static fn ( int $target ): bool => 99174 === $target );
		$check( 'NATIVE-C06-DEFAULT-WORDPRESS-ISOLATED-FIXTURE', WP_Object_Cache::class === get_class( $GLOBALS['wp_object_cache'] ) && $GLOBALS['wpdb']->prefix === $fixture->prefix && 99174 === get_current_blog_id(), [ 'default_object_cache' => true, 'fixture_server_context' => true, 'schema' => (string) get_option( 'cetech_de_db_version' ) ] );
		$profile = new DataLifecycleProofOperationProfile( $fixture->prefix );
		$identity = new OperationIdentity( $fixture->site, 'wordpress', 'staff:9', 'fixture.counter_update', 1, 'counter:1', 'c06-preserved-receipt' );
		$accepted = ( new OperationCoordinator( new OperationProfileRegistry( [ $profile ] ), $fixture->factory ) )->attempt( $identity, [ 'row_id' => 1, 'expected_revision' => 1, 'value' => 73 ], RequestContext::create() );
		$rules = new RuleLifecycleService( new RuleFamilyRegistry( [ new RuleProofFamily() ] ), $fixture->factory );
		$payload = RuleProofEnvelope::create( RuleProofEnvelope::uuid( 61 ), RuleProofEnvelope::uuid( 161 ) );
		$draft = $rules->attempt( RuleProofEnvelope::identity( $payload, 'rule.draft.create', 'c06-draft', $fixture->site ), $payload, RequestContext::create() );
		$opened = RuleProofEnvelope::opened( $fixture->physical, $fixture->prefix, $payload['logical_uuid'], $payload['version_uuid'] );
		$published = $rules->attempt( RuleProofEnvelope::identity( $opened, 'rule.publish', 'c06-published', $fixture->site ), $opened, RequestContext::create() );
		$domain_before = $fixture->domain_rows(); $options_before = $fixture->preserved_options();
		$original32 = array_intersect_key( $domain_before, array_fill_keys( DataLifecycleManifest::ORIGINAL_DOMAIN_TABLE_SUFFIXES, true ) );
		$check( 'NATIVE-C06-ALL32-PHYSICAL-DOMAIN-SENTINELS', 32 === count( $original32 ) && 32 === count( array_filter( $original32, static fn ( array $rows ): bool => [] !== $rows ) ) && 35 === count( $domain_before ) && 35 === count( array_filter( $domain_before, static fn ( array $rows ): bool => [] !== $rows ) ), [ 'original_physical_tables' => count( $original32 ), 'current_physical_tables' => count( $domain_before ), 'quote_marker_rows_not_dto_proof' => true ] );
		$check( 'NATIVE-C06-C03-C04-REAL-ACCEPTANCE-HISTORY-SEEDED', 'accepted' === $accepted->outcome->state && 'accepted' === $draft->outcome->state && 'accepted' === $published->outcome->state && 3 === count( $domain_before['operation_records'] ) && 3 === count( $domain_before['operation_changes'] ) && 'published' === $domain_before['rule_versions'][0]['state'] );
		$check( 'NATIVE-C06-AUTHORED-OPTIONS-ALL23-FLAGS-SEEDED', 23 === count( DataLifecycleManifest::FEATURE_FLAG_OPTIONS ) && count( DataLifecycleManifest::PRESERVED_OPTIONS ) - 2 === count( $options_before ) && [] === array_filter( $options_before, static fn ( array $row ): bool => 'off' !== $row['autoload'] ) );
		$clock = time(); $cache = new ManagedGeographyCache( $fixture->factory, clock: static fn (): int => $clock );
		$item = [ 'key' => 'c06-synthetic-locality', 'name' => 'Synthetic locality', 'type' => 'locality', 'label' => 'Synthetic locality — Synthetic parent', 'breadcrumb' => 'Synthetic parent' ];
		$payloads = [ 'children' => [ 'items' => [ $item ], 'page' => 1, 'total' => 1, 'has_more' => false, 'label' => 'City', 'skip_admin' => false ], 'search' => [ 'items' => [ $item ], 'page' => 1, 'total' => 1, 'has_more' => false, 'has_pack' => true, 'request_token' => 'PRIVATE-C06-CALLER-TOKEN' ], 'postcode' => [ 'required' => true, 'visible' => true ] ];
		$cache_names = []; $tickets = [];
		foreach ( $payloads as $kind => $derived ) {
			$id = $cache->identity( $fixture->site, $kind, 'GH', '', 'synthetic', 'native-' . $kind, 1, '7', 'en_US' ); $ticket = $cache->lookup( $id ); $stored = $cache->publish( $ticket, $derived ); $hit = $cache->lookup( $id ); $expected = $derived; unset( $expected['request_token'] );
			$cache_names[$kind] = $id->option_name(); $tickets[$kind] = $hit;
			$check( 'NATIVE-C06-' . strtoupper( $kind ) . '-MANAGED-CACHE-RESPONSE-PARITY', null === $ticket->payload() && $stored && $expected === $hit->payload() && null !== $fixture->option( $id->option_name() ) );
		}
		$search_row = $fixture->option( $cache_names['search'] ); $search_envelope = ManagedGeographyCacheEnvelope::from_json( $search_row['option_value'] );
		$check( 'NATIVE-C06-MANAGED-TTL120-REQUEST-TOKEN-NOT-STORED', 120 === $search_envelope->expires_at() - $tickets['search']->observed_at() && 'off' === $search_row['autoload'] && ! str_contains( $search_row['option_value'], 'PRIVATE-C06-CALLER-TOKEN' ) && ! array_key_exists( 'request_token', $tickets['search']->payload() ) );
		$base_id = $cache->identity( $fixture->site, 'search', 'GH', '', 'synthetic', 'same', 1, '7', 'en_US' ); $locale_id = $cache->identity( $fixture->site, 'search', 'GH', '', 'synthetic', 'same', 1, '7', 'fr_FR' ); $revision_id = $cache->identity( $fixture->site, 'search', 'GH', '', 'synthetic', 'same', 1, 'opaque:changed', 'en_US' );
		$check( 'NATIVE-C06-LOCALE-OPAQUE-REVISION-SEPARATE-IDENTITIES', 3 === count( array_unique( [ $base_id->option_name(), $locale_id->option_name(), $revision_id->option_name() ] ) ) && null === $cache->lookup( $revision_id )->payload() );
		$expired_cache = new ManagedGeographyCache( $fixture->factory, clock: static fn (): int => $clock + 120 );
		$check( 'NATIVE-C06-PREWARMED-ADVISORY-CACHE-INLINE-EXPIRY', is_array( wp_cache_get( $tickets['search']->identity()->digest(), ManagedGeographyCacheIdentity::CACHE_GROUP ) ) && null === $expired_cache->lookup( $tickets['search']->identity() )->payload() && 'expired' === $expired_cache->diagnostics()['status'] );
		// Every participant in this controlled observation uses the same fixture clock.
		// A native-time writer can otherwise create a correctly refused future row
		// for the already frozen reader when a second boundary is crossed.
		$pending_cache = new ManagedGeographyCache( $fixture->factory, clock: static fn (): int => $clock, cache_set: static fn (): bool => false ); $pending_id = $pending_cache->identity( $fixture->site, 'postcode', 'GH', '', '', 'publication-failure', 1, '7', 'en_US' );
		$pending = $pending_cache->publish( $pending_cache->lookup( $pending_id ), $payloads['postcode'] ); $pending_row = $fixture->option( $pending_id->option_name() );
		$pending_status = $pending_cache->diagnostics()['status']; $pending_read = $cache->lookup( $pending_id ); $pending_after = $fixture->option( $pending_id->option_name() );
		$pending_observed = null === $pending_row ? null : ManagedGeographyCacheEnvelope::from_json( $pending_row['option_value'] )->expires_at() - 120;
		$check( 'NATIVE-C06-ADVISORY-PUBLICATION-FAILURE-KEEPS-ACCEPTED-SQL', $pending && 'publication_pending' === $pending_status && null !== $pending_row && $payloads['postcode'] === $pending_read->payload() && $pending_row === $pending_after,
			[ 'sql_accepted' => $pending, 'publication_status' => $pending_status, 'row_present' => null !== $pending_row, 'writer_observed_at' => $pending_observed, 'reader_observed_at' => $pending_read->observed_at(), 'fixture_clock' => $clock, 'reader_status' => $cache->diagnostics()['status'], 'payload_matches' => $payloads['postcode'] === $pending_read->payload(), 'row_unchanged' => $pending_row === $pending_after ] );
		set_transient( 'cetech_de_scoped_draft_9_product_17_in_warehouse_none', [ 'save_token' => 'PRIVATE-C06-DRAFT-TOKEN' ], 900 ); set_transient( 'cetech_de_geo_rl_' . str_repeat( 'a', 32 ), 7, 60 );
		$legacy_before = $fixture->rows( "SELECT * FROM `{$fixture->prefix}options` WHERE option_name LIKE '_transient%cetech_de%' ORDER BY option_id" );
		$all_before = $fixture->option_rows(); $preview = $worker->preview( $fixture->site );
		$check( 'NATIVE-C06-DRY-RUN-REAL-WP-OPTIONS-ZERO-WRITES', 'preview' === $preview->status && $all_before === $fixture->option_rows() && $domain_before === $fixture->domain_rows() && $preview->progress?->inspected <= 200 );
		$check( 'NATIVE-C06-OLD-TRANSIENT-DRAFT-RATE-OWNER-PRESERVED', $legacy_before === $fixture->rows( "SELECT * FROM `{$fixture->prefix}options` WHERE option_name LIKE '_transient%cetech_de%' ORDER BY option_id" ) && 'PRIVATE-C06-DRAFT-TOKEN' === ( get_transient( 'cetech_de_scoped_draft_9_product_17_in_warehouse_none' )['save_token'] ?? null ) && 7 === get_transient( 'cetech_de_geo_rl_' . str_repeat( 'a', 32 ) ) );
		foreach ( [ 'on', 'off' ] as $autoload ) {
			$name = 'cetech_de_gc_geo_v1_' . hash( 'sha256', 'native-wp-cache-' . $autoload );
			$native_identity = $cache->identity( $fixture->site, 'postcode', 'GH', '', '', 'native-wp-cache-' . $autoload, 1, '7', 'en_US' ); $name = $native_identity->option_name();
			$envelope = ManagedGeographyCacheEnvelope::create( $native_identity, $payloads['postcode'], time() - 300 );
			$fixture->write_option( $name, $envelope->to_json() );
			$fixture->execute( "UPDATE `{$fixture->prefix}options` SET autoload='off'" );
			if ( 'on' === $autoload ) { $fixture->write_option( 'c06_fixture_autoload_marker', '1' ); $fixture->execute( "UPDATE `{$fixture->prefix}options` SET autoload='on' WHERE option_name='c06_fixture_autoload_marker'" ); }
			$GLOBALS['wp_object_cache'] = new WP_Object_Cache(); get_option( 'cetech_de_db_version' ); get_option( 'cetech_de_absent_native_option', false ); $loaded = get_option( $name );
			$state = $worker->read( $fixture->site ); if ( null === $state->progress || 'completed' === $state->progress->status ) { $state = $worker->start( $fixture->site ); }
			$state = $worker->batch( $fixture->site, $state->continuation );
			$check( 'NATIVE-C06-' . strtoupper( $autoload ) . '-AUTOLOAD-PREWARMED-PHYSICAL-FRESH-READ-PARITY', $envelope->to_json() === $loaded && 'accepted' === $state->status && 'completed' === $state->progress?->status && null === $fixture->option( $name ) && false === get_option( $name, false ) && SchemaVersion::TARGET === get_option( 'cetech_de_db_version' ) && $read_fresh( $fixture, [ $name, 'cetech_de_db_version', 'cetech_de_geography_revision' ] ), [ 'all_autoload_off' => 'off' === $autoload, 'fresh_os_process' => true ] );
			if ( 'on' === $autoload ) { $fixture->execute( "DELETE FROM `{$fixture->prefix}options` WHERE option_name='c06_fixture_autoload_marker'" ); }
		}
		$check( 'NATIVE-C06-ORDINARY-CLEANUP-PRESERVES-ALL32-AND-CONTROLS', $domain_before === $fixture->domain_rows() && $options_before === $fixture->preserved_options() && null !== $fixture->option( DataLifecycleManifest::COORDINATOR_OPTION ) && 'off' === $fixture->option( DataLifecycleManifest::COORDINATOR_OPTION )['autoload'] );
		$roles_before = $fixture->option( $fixture->prefix . 'user_roles' ); set_transient( DataLifecycleManifest::ACTIVATION_NOTICE, 1, 60 );
		$schedule_clock = time(); $scheduler = new DataLifecycleScheduler( $worker, static fn (): int => $schedule_clock ); $scheduler->register(); add_action( DataLifecycleManifest::CLEANUP_HOOK, $foreign_callback, 77 );
		if ( ! function_exists( 'as_schedule_single_action' ) || ! ActionScheduler::is_initialized() ) { throw new RuntimeException( 'Native C06 proof requires initialized Action Scheduler.' ); }
		$scheduled = static function ( $result ) use ( $fixture ): array { return as_get_scheduled_actions( [ 'hook' => DataLifecycleManifest::CLEANUP_HOOK, 'group' => DataLifecycleManifest::CLEANUP_GROUP, 'status' => 'pending', 'args' => [ 'site_id' => $fixture->site, 'run_id' => $result->progress->run_id, 'checkpoint_token' => $result->progress->checkpoint_token ], 'per_page' => 2 ], 'ids' ); };
		$cadence = $worker->read( $fixture->site ); $cadence_published = $scheduler->publish( $cadence ); $cadence_ids = $scheduled( $cadence ); $cadence_id = (int) ( $cadence_ids[0] ?? 0 );
		$action_table = $fixture->selected->actionscheduler_actions; $cadence_row = $cadence_id > 0 ? $fixture->rows( "SELECT scheduled_date_gmt FROM `{$action_table}` WHERE action_id={$cadence_id}" ) : [];
		$check( 'NATIVE-C06-ACTUAL-AS-300SECOND-MAINTENANCE-CADENCE', ! $cadence_published->publication_pending && 1 === count( $cadence_ids ) && gmdate( 'Y-m-d H:i:s', $schedule_clock + 300 ) === ( $cadence_row[0]['scheduled_date_gmt'] ?? null ) );
		$active_run = $worker->start( $fixture->site ); $successor = $scheduler->publish( $active_run ); $successor_ids = $scheduled( $active_run ); $successor_id = (int) ( $successor_ids[0] ?? 0 ); $successor_row = $successor_id > 0 ? $fixture->rows( "SELECT scheduled_date_gmt FROM `{$action_table}` WHERE action_id={$successor_id}" ) : [];
		$check( 'NATIVE-C06-ACTUAL-AS-ONESECOND-OWNED-SUCCESSOR', 'running' === $active_run->progress?->status && ! $successor->publication_pending && 1 === count( $successor_ids ) && gmdate( 'Y-m-d H:i:s', $schedule_clock + 1 ) === ( $successor_row[0]['scheduled_date_gmt'] ?? null ) );
		$active_done = $worker->batch( $fixture->site, $active_run->continuation ); if ( 'accepted' !== $active_done->status || 'completed' !== $active_done->progress?->status ) { throw new RuntimeException( 'Native scheduler fixture checkpoint did not complete.' ); }
		$own_action = as_schedule_single_action( time() + 86400, DataLifecycleManifest::CLEANUP_HOOK, [ 'site_id' => $fixture->site, 'run_id' => str_repeat( 'a', 32 ), 'checkpoint_token' => str_repeat( 'b', 64 ) ], DataLifecycleManifest::CLEANUP_GROUP, false );
		$foreign_action = as_schedule_single_action( time() + 86400, 'c06_fixture_foreign_tick', [ 'site_id' => (int) $original['blog_id'][1] ], 'cetech-delivery-engine-bulk', false ); $actions = [ $own_action, $foreign_action, $cadence_id, $successor_id ];
		if ( ! is_int( $own_action ) || $own_action < 1 || ! is_int( $foreign_action ) || $foreign_action < 1 ) { throw new RuntimeException( 'Native C06 action sentinels were not stored.' ); }
		$fixture->execute( "UPDATE `{$action_table}` SET status='in-progress' WHERE action_id={$own_action}" );
		$action_before = $fixture->rows( "SELECT * FROM `{$action_table}` WHERE action_id IN ({$own_action},{$foreign_action}) ORDER BY action_id" );
		$pre_deactivation = [
			'domain_matches_original_before' => $domain_before === $fixture->domain_rows(),
			'controls_match_original_before' => $options_before === $fixture->preserved_options(),
			'roles_match_original_before' => $roles_before === $fixture->option( $fixture->prefix . 'user_roles' ),
			'notice_physically_present_before' => null !== $fixture->option( '_transient_' . DataLifecycleManifest::ACTIVATION_NOTICE ),
			'notice_timeout_physically_present_before' => null !== $fixture->option( '_transient_timeout_' . DataLifecycleManifest::ACTIVATION_NOTICE ),
		];
		$alloptions_before_deactivation = wp_cache_get( 'alloptions', 'options' );
		$pre_deactivation['notice_in_alloptions_before'] = is_array( $alloptions_before_deactivation ) && array_key_exists( '_transient_' . DataLifecycleManifest::ACTIVATION_NOTICE, $alloptions_before_deactivation );
		Deactivator::deactivate();
		$post_deactivation = [
			'domain_matches_original_after' => $domain_before === $fixture->domain_rows(),
			'controls_match_original_after' => $options_before === $fixture->preserved_options(),
			'roles_match_original_after' => $roles_before === $fixture->option( $fixture->prefix . 'user_roles' ),
			'notice_native_read_absent_after' => false === get_transient( DataLifecycleManifest::ACTIVATION_NOTICE ),
			'notice_physically_absent_after' => null === $fixture->option( '_transient_' . DataLifecycleManifest::ACTIVATION_NOTICE ),
			'notice_timeout_physically_absent_after' => null === $fixture->option( '_transient_timeout_' . DataLifecycleManifest::ACTIVATION_NOTICE ),
		];
		$alloptions_after_deactivation = wp_cache_get( 'alloptions', 'options' );
		$post_deactivation['notice_in_alloptions_after'] = is_array( $alloptions_after_deactivation ) && array_key_exists( '_transient_' . DataLifecycleManifest::ACTIVATION_NOTICE, $alloptions_after_deactivation );
		$check( 'NATIVE-C06-DEACTIVATION-PRESERVES-DOMAIN-CONTROL-ROLES', $post_deactivation['domain_matches_original_after'] && $post_deactivation['controls_match_original_after'] && $post_deactivation['roles_match_original_after'] && $post_deactivation['notice_native_read_absent_after'], $pre_deactivation + $post_deactivation );
		$check( 'NATIVE-C06-OWN-DISPATCH-STOP-SHARED-AS-ROWS-PRESERVED', $action_before === $fixture->rows( "SELECT * FROM `{$action_table}` WHERE action_id IN ({$own_action},{$foreign_action}) ORDER BY action_id" ) && false === has_action( DataLifecycleManifest::CLEANUP_HOOK, [ $scheduler, 'tick' ] ) && 77 === has_action( DataLifecycleManifest::CLEANUP_HOOK, $foreign_callback ), [ 'pending_rows_retained' => true, 'atomic_cancellation_claimed' => false ] );
		Uninstaller::uninstall();
		$check( 'NATIVE-C06-DEFAULT-UNINSTALL-PRESERVES-DATA-AND-ROLE-PERMISSIONS', $domain_before === $fixture->domain_rows() && $options_before === $fixture->preserved_options() && $roles_before === $fixture->option( $fixture->prefix . 'user_roles' ) );
		update_option( DataLifecycleManifest::UNINSTALL_INTENT, '1junk', false ); $invalid_before = $fixture->option_rows(); Uninstaller::uninstall();
		$check( 'NATIVE-C06-MALFORMED-EXPLICIT-INTENT-NO-CLEANUP', $invalid_before === $fixture->option_rows() && $domain_before === $fixture->domain_rows() && $roles_before === $fixture->option( $fixture->prefix . 'user_roles' ) );
		$fixture->reset_roles(); update_option( DataLifecycleManifest::CAPABILITIES_MARKER, 4, false ); update_option( DataLifecycleManifest::UNINSTALL_INTENT, 1, false );
		$role_key = $fixture->prefix . 'user_roles'; $role_refusal = static fn ( mixed $new, mixed $old ): mixed => $old;
		add_filter( 'pre_update_option_' . $role_key, $role_refusal, PHP_INT_MAX, 2 ); Uninstaller::uninstall(); remove_filter( 'pre_update_option_' . $role_key, $role_refusal, PHP_INT_MAX ); $role_refusal = null; $refusal_status = $uninstall_status();
		$check( 'NATIVE-C06-PARTIAL-PERMISSION-FAILURE-RETAINS-MARKER-INTENT', is_array( $refusal_status ) && 'refused' === ( $refusal_status['status'] ?? null ) && 1 === (int) get_option( DataLifecycleManifest::UNINSTALL_INTENT ) && 4 === (int) get_option( DataLifecycleManifest::CAPABILITIES_MARKER ) && $domain_before === $fixture->domain_rows() );
		$fixture->reset_roles(); update_option( DataLifecycleManifest::CAPABILITIES_MARKER, 4, false ); update_option( DataLifecycleManifest::UNINSTALL_INTENT, 1, false );
		$concurrent_roles = $fixture->roles(); $concurrent_roles['c06_fixture_custom']['capabilities']['c06_concurrent_role_permission'] = true;
		$concurrent_value = serialize( $concurrent_roles ); $conflict_calls = 0; $concurrent_role_row = null;
		$role_conflict = static function ( mixed $new ) use ( $fixture, $role_key, $concurrent_value, &$conflict_calls, &$concurrent_role_row ): mixed {
			if ( 0 !== $conflict_calls++ ) { return $new; }
			// This is the fixture's second physical connection, distinct from
			// the executor's owned operation connection. Commit before its CAS.
			$fixture->execute( 'START TRANSACTION' );
			try {
				$fixture->execute( "UPDATE `{$fixture->prefix}options` SET option_value=" . $fixture->literal( $concurrent_value ) . ' WHERE option_name=' . $fixture->literal( $role_key ) );
				$fixture->execute( 'COMMIT' );
			} catch ( Throwable $error ) { $fixture->execute( 'ROLLBACK' ); throw $error; }
			$concurrent_role_row = $fixture->option( $role_key );
			return $new;
		};
		add_filter( 'pre_update_option_' . $role_key, $role_conflict, PHP_INT_MAX, 2 ); Uninstaller::uninstall(); remove_filter( 'pre_update_option_' . $role_key, $role_conflict, PHP_INT_MAX ); $role_conflict = null; $conflict_status = $uninstall_status();
		$GLOBALS['wp_object_cache'] = new WP_Object_Cache(); $GLOBALS['wp_user_roles'] = null; $GLOBALS['wp_roles'] = new WP_Roles( $fixture->site );
		$concurrent_role = get_role( 'c06_fixture_custom' ); $conflict_caps_preserved = $concurrent_role instanceof WP_Role;
		foreach ( DataLifecycleManifest::CAPABILITIES as $cap ) { $conflict_caps_preserved = $conflict_caps_preserved && $concurrent_role instanceof WP_Role && $concurrent_role->has_cap( $cap ); }
		$check( 'NATIVE-C06-SECOND-CONNECTION-ROLE-CONFLICT-PRESERVES-NEWER-GRANT', 1 === $conflict_calls && is_array( $conflict_status ) && 'refused' === ( $conflict_status['status'] ?? null ) && null !== $concurrent_role_row && $concurrent_value === $concurrent_role_row['option_value'] && $concurrent_role_row === $fixture->option( $role_key ) && $conflict_caps_preserved && $concurrent_role->has_cap( 'c06_concurrent_role_permission' ) && 1 === (int) get_option( DataLifecycleManifest::UNINSTALL_INTENT ) && 4 === (int) get_option( DataLifecycleManifest::CAPABILITIES_MARKER ) && $domain_before === $fixture->domain_rows(), [ 'second_connection_committed' => 1 === $conflict_calls, 'newer_role_bytes_preserved' => $concurrent_role_row === $fixture->option( $role_key ) ] );
		$fixture->reset_roles(); update_option( DataLifecycleManifest::CAPABILITIES_MARKER, 4, false ); update_option( DataLifecycleManifest::UNINSTALL_INTENT, 1, false ); set_transient( DataLifecycleManifest::ACTIVATION_NOTICE, 1, 60 );
		Uninstaller::uninstall();
		$status = $uninstall_status();
		$check( 'NATIVE-C06-EXPLICIT-AUTOLOAD-ZERO-DOMAIN-DROPS', $domain_before === $fixture->domain_rows() && $options_before === $fixture->preserved_options() && false === get_transient( DataLifecycleManifest::ACTIVATION_NOTICE ) );
		$caps_removed = true; $foreign_preserved = true;
		foreach ( array_keys( $fixture->roles() ) as $slug ) { $role = get_role( $slug ); foreach ( DataLifecycleManifest::CAPABILITIES as $cap ) { $caps_removed = $caps_removed && $role instanceof WP_Role && ! $role->has_cap( $cap ); } $foreign_preserved = $foreign_preserved && $role instanceof WP_Role && $role->has_cap( 'c06_foreign_role_permission' ); }
		$check( 'NATIVE-C06-CUSTOM-ROLES-EXACT18-CAPABILITIES-REMOVED', $caps_removed && 18 === count( DataLifecycleManifest::CAPABILITIES ) && null !== $fixture->option( $fixture->prefix . 'user_roles' ) );
		$check( 'NATIVE-C06-UNRELATED-ROLE-GRANTS-PRESERVED', $foreign_preserved && get_role( 'administrator' )->has_cap( 'manage_options' ) );
		$principal_after = $main->get_row( $main->prepare( "SELECT umeta_id,user_id,meta_key,meta_value FROM `{$main->usermeta}` WHERE user_id=%d AND meta_key=%s", $principal->ID, $principal_key ), ARRAY_A );
		$check( 'NATIVE-C06-PERSISTED-DIRECT-USER-GRANT-UNCHANGED', is_array( $principal_before ) && $principal_before === $principal_after && true === ( $principal->caps['manage_shipments'] ?? null ) );
		$check( 'NATIVE-C06-CAPABILITY-MARKER-INTENT-STATUS-ORDERING', false === get_option( DataLifecycleManifest::CAPABILITIES_MARKER, false ) && false === get_option( DataLifecycleManifest::UNINSTALL_INTENT, false ) && is_array( $status ) && 'completed' === ( $status['status'] ?? null ) && 'off' === $fixture->option( DataLifecycleManifest::UNINSTALL_STATUS )['autoload'] && null !== $fixture->option( DataLifecycleManifest::COORDINATOR_OPTION ) );
		$autoload_roles = $fixture->option( $fixture->prefix . 'user_roles' )['option_value'];
		$fixture->reset_roles(); update_option( DataLifecycleManifest::CAPABILITIES_MARKER, 4, false ); update_option( DataLifecycleManifest::UNINSTALL_INTENT, 1, false );
		$path = sys_get_temp_dir() . '/cetech-c06-standalone-' . bin2hex( random_bytes( 8 ) ); $temporary[] = $path; $copy_fallback( $path, true ); $standalone = $run_fallback( $path );
		$check( 'NATIVE-C06-NO-VENDOR-SHARED-MANIFEST-DISPOSITION-PARITY', 'PASS' === ( $standalone['status'] ?? null ) && 'completed' === ( $standalone['uninstall_status'] ?? null ) && false === ( $standalone['composer_loaded'] ?? null ) && false === ( $standalone['woocommerce_loaded'] ?? null ) && ! is_readable( $path . '/vendor/autoload.php' ) && $domain_before === $fixture->domain_rows() && $options_before === $fixture->preserved_options() && $autoload_roles === $fixture->option( $fixture->prefix . 'user_roles' )['option_value'] && null === $fixture->option( DataLifecycleManifest::UNINSTALL_INTENT ), [ 'fresh_os_process' => true, 'no_composer_or_woo' => true ] );
		$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
		$missing = sys_get_temp_dir() . '/cetech-c06-missing-helper-' . bin2hex( random_bytes( 8 ) ); $temporary[] = $missing; $copy_fallback( $missing, false ); update_option( DataLifecycleManifest::UNINSTALL_INTENT, 1, false ); $missing_before = $fixture->option_rows(); $missing_result = $run_fallback( $missing );
		$check( 'NATIVE-C06-MISSING-STANDALONE-HELPER-FAILS-PRESERVED', 'PASS' === ( $missing_result['status'] ?? null ) && true === ( $missing_result['intent_present'] ?? null ) && $missing_before === $fixture->option_rows() && $domain_before === $fixture->domain_rows() );
		$fixture->reset_roles(); update_option( DataLifecycleManifest::CAPABILITIES_MARKER, 4, false );
		for ( $index = 0; $index < 55; ++$index ) { $bulk_id = $cache->identity( $fixture->site, 'postcode', 'GH', '', '', 'bounded-uninstall-' . $index, 1, '7', 'en_US' ); $fixture->write_option( $bulk_id->option_name(), ManagedGeographyCacheEnvelope::create( $bulk_id, $payloads['postcode'], time() )->to_json() ); }
		Uninstaller::uninstall(); $partial = $uninstall_status(); $checkpoint = $worker->read( $fixture->site );
		$remaining = (int) $fixture->scalar( "SELECT COUNT(*) FROM `{$fixture->prefix}options` WHERE option_name LIKE 'cetech!_de!_gc!_geo!_v1!_%' ESCAPE '!'" );
		$check( 'NATIVE-C06-LARGE-EXPLICIT-UNINSTALL-BOUNDED-INCOMPLETE', is_array( $partial ) && 'incomplete' === ( $partial['status'] ?? null ) && 'running' === $checkpoint->progress?->status && $checkpoint->progress->deleted <= 50 && $remaining >= 5 && 1 === (int) get_option( DataLifecycleManifest::UNINSTALL_INTENT ) && $domain_before === $fixture->domain_rows() );
		$next = $worker->batch( $fixture->site, $checkpoint->continuation ); if ( 'accepted' !== $next->status || 'completed' !== $next->progress?->status ) { throw new RuntimeException( 'Native bounded uninstall continuation failed.' ); }
		$unknown_name = DataLifecycleManifest::CACHE_PREFIX . hash( 'sha256', 'unknown-native-cache' ); $fixture->write_option( $unknown_name, '{"format":99,"private":"PRIVATE-C06-UNKNOWN"}' ); $unknown_before = $fixture->option( $unknown_name );
		Uninstaller::uninstall(); $unknown_status = $uninstall_status();
		$check( 'NATIVE-C06-UNKNOWN-CACHE-FORMAT-RETAINED-SAFE-STATUS', $unknown_before === $fixture->option( $unknown_name ) && $domain_before === $fixture->domain_rows() && is_array( $unknown_status ) && 'incomplete' === ( $unknown_status['status'] ?? null ) && 'retained_unknown_cache' === ( $unknown_status['code'] ?? null ) && ( $unknown_status['counts']['invalid'] ?? 0 ) > 0 && 1 === (int) get_option( DataLifecycleManifest::UNINSTALL_INTENT ) && ! str_contains( json_encode( $unknown_status ), 'PRIVATE-C06-UNKNOWN' ) );
		$check( 'NATIVE-C06-PROVIDER-REFERENCED-OUTSIDE-SYMLINK-FILES-PRESERVED', $file_hashes === [ hash_file( 'sha256', $active_file ), hash_file( 'sha256', $unknown_file ), hash_file( 'sha256', $outside ) ] && is_link( $pack_dir . '/GH.forged-link.txt' ) && $outside === readlink( $pack_dir . '/GH.forged-link.txt' ) && $domain_before['geography_packs'] === $fixture->domain_rows()['geography_packs'], [ 'file_deletion_adopted' => false, 'referenced_source_unchanged' => true ] );
		$fixture->execute( "ALTER TABLE `{$fixture->prefix}options` ENGINE=MyISAM" ); $unsupported_before = $fixture->option_rows(); $unavailable = $cache->lookup( $tickets['postcode']->identity() ); $denied = $worker->preview( $fixture->site );
		$check( 'NATIVE-C06-NON-INNODB-REFUSAL-KEEPS-DERIVED-FALLBACK', null === $unavailable->payload() && ! $cache->publish( $unavailable, $payloads['postcode'] ) && 'refused' === $denied->status && $unsupported_before === $fixture->option_rows() && $domain_before === $fixture->domain_rows() );
		$fixture->execute( "ALTER TABLE `{$fixture->prefix}options` ENGINE=InnoDB" );
		$private_json_refused = false; try { json_encode( $checkpoint, JSON_THROW_ON_ERROR ); } catch ( LogicException ) { $private_json_refused = true; }
		$check( 'NATIVE-C06-FINITE-SAFE-STATUS-NO-PRIVATE-CONTINUATION', $private_json_refused && ! str_contains( json_encode( $checkpoint->safe() ), 'PRIVATE-C06' ) && ! str_contains( json_encode( $checkpoint->safe() ), 'checkpoint_token' ) && ! str_contains( json_encode( $checkpoint->safe() ), 'run_id' ) );
	} catch ( Throwable $error ) { $fixture_failure = $error; throw $error; } finally {
		if ( $filter_installed ) { remove_filter( 'flush_rewrite_rules_hard', $no_hard_flush, PHP_INT_MAX ); }
		if ( null !== $role_refusal && null !== $fixture ) { remove_filter( 'pre_update_option_' . $fixture->prefix . 'user_roles', $role_refusal, PHP_INT_MAX ); }
		if ( null !== $role_conflict && null !== $fixture ) { remove_filter( 'pre_update_option_' . $fixture->prefix . 'user_roles', $role_conflict, PHP_INT_MAX ); }
		if ( $scheduler instanceof DataLifecycleScheduler ) { remove_action( DataLifecycleManifest::CLEANUP_HOOK, [ $scheduler, 'tick' ], 10 ); remove_action( 'action_scheduler_init', [ $scheduler, 'boot' ], 20 ); }
		remove_action( DataLifecycleManifest::CLEANUP_HOOK, $foreign_callback, 77 );
		foreach ( $actions as $id ) { if ( is_int( $id ) && $id > 0 && class_exists( ActionScheduler::class ) ) { ActionScheduler::store()->delete_action( $id ); } }
		foreach ( $original as $key => [ $exists, $value ] ) { if ( $exists ) { $GLOBALS[$key] = $value; } else { unset( $GLOBALS[$key] ); } }
		if ( $principal instanceof WP_User && is_array( $principal_caps ) ) { update_user_meta( $principal->ID, $principal_key, $principal_caps ); $principal->caps = $principal_caps; }
		if ( null !== $fixture ) { $cleanup_ok = $fixture->cleanup(); }
		foreach ( array_reverse( $temporary ) as $path ) {
			if ( is_file( $path ) ) { unlink( $path ); continue; }
			if ( ! is_dir( $path ) ) { continue; }
			$entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ); foreach ( $entries as $entry ) { if ( $entry->isDir() ) { rmdir( $entry->getPathname() ); } else { unlink( $entry->getPathname() ); } } rmdir( $path );
		}
		$cleanup_condition = $cleanup_ok && $GLOBALS['wpdb'] === $main && $GLOBALS['wp_object_cache'] === $original['wp_object_cache'][1] && get_current_blog_id() === (int) $original['blog_id'][1];
		if ( null === $fixture_failure ) { $check( 'NATIVE-C06-ISOLATED-TABLES-CACHE-CONTEXT-CLEANUP', $cleanup_condition ); }
		else { try { $check( 'NATIVE-C06-ISOLATED-TABLES-CACHE-CONTEXT-CLEANUP', $cleanup_condition ); } catch ( Throwable ) { /* The failed cleanup is recorded; retain the original fixture exception. */ } }
	}
	$classic = require __DIR__ . '/opening-data-lifecycle-classic.php'; $classic( $check, $pressure );
	$schema_after = $main->get_results( $main->prepare( "SELECT option_name,option_value,autoload FROM `{$main->options}` WHERE option_name IN (%s,%s) ORDER BY option_name", 'cetech_de_db_version', 'cetech_de_last_migration_status' ), ARRAY_A );
	$check( 'NATIVE-C06-FIXTURE-SOURCE-SCHEMA-RESTORED', $schema_before === $schema_after && SchemaVersion::TARGET === (string) get_option( 'cetech_de_db_version' ) && $GLOBALS['wpdb'] === $main );
};
