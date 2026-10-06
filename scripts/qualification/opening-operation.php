<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\Operation\OperationCoordinator;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationProfileRegistry;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreReadiness;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnection;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionFactory;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionMysqliTransport;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofDatabase;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofProfile;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofTransport;

/** Native WordPress proof; the marked opening runner supplies the fixture guard. */
return static function ( callable $check ): void {
	global $wpdb;
	if ( ! defined( 'ABSPATH' ) || '1' !== (string) get_option( 'cetech_opening_qualification_disposable' )
		|| str_contains( (string) ( get_included_files()[0] ?? '' ), 'tests/bootstrap.php' ) ) {
		throw new RuntimeException( 'Operation qualification requires the native disposable fixture.' );
	}
	foreach ( get_included_files() as $included ) {
		if ( str_ends_with( str_replace( '\\', '/', $included ), '/tests/bootstrap.php' ) ) {
			throw new RuntimeException( 'Unit bootstrap is forbidden in native operation qualification.' );
		}
	}
	$root = dirname( __DIR__, 2 );
	foreach ( [ 'OperationProofCommand', 'OperationProofDatabase', 'OperationProofProfile', 'OperationProofTransport' ] as $fixture ) {
		require_once $root . '/tests/Support/Operation/' . $fixture . '.php';
	}
	$check( 'NATIVE-C03-ACTUAL-SCHEMA-SEVEN-READY', version_compare( (string) get_option( 'cetech_de_db_version' ), '7', '>=' ) && ( new OperationStoreReadiness() )->get_status()['ready'] );
	$global_id = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
	$native = ( new OperationConnectionFactory() )->open();
	try {
		$began = $native->begin();
		$own_id = $began ? $native->get_row( 'SELECT CONNECTION_ID() AS id' ) : false;
		$nested = $native->begin();
		$rolled_back = $native->rollback();
		$check( 'NATIVE-C03-DEDICATED-NON-NESTED-OWNER', $began && is_array( $own_id ) && (int) $own_id['id'] !== $global_id && ! $nested && $rolled_back && (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) === $global_id );
	} finally {
		$native->retire();
	}
	$check( 'NATIVE-C03-RETIRED-HANDLE-STAYS-CLOSED', $native->is_retired() && false === $native->query( 'SELECT 1' ) && ! $native->begin() );

	$host = $wpdb->parse_db_host( DB_HOST );
	if ( ! is_array( $host ) ) { throw new RuntimeException( 'Native operation database configuration is unavailable.' ); }
	$physical = new mysqli( $host[0], DB_USER, DB_PASSWORD, DB_NAME, $host[1] ?: 3306, $host[2] );
	$physical->set_charset( 'utf8mb4' );
	$prefix = OperationProofDatabase::prefix();
	$site = (int) get_current_blog_id();
	$prior_user = get_current_user_id();
	$user = 0;
	$installed = false;
	$lost_factory = null;
	try {
		OperationProofDatabase::install( $physical, $prefix );
		$installed = true;
		$user = wp_insert_user( [ 'user_login' => 'operation-proof-' . bin2hex( random_bytes( 8 ) ), 'user_pass' => wp_generate_password( 40, true, true ), 'role' => 'administrator' ] );
		if ( is_wp_error( $user ) || ! is_int( $user ) || $user < 1 ) { throw new RuntimeException( 'Native operation principal setup failed.' ); }
		wp_set_current_user( $user );
		$profile = new OperationProofProfile( $prefix, false, 'fixture.counter_update', 1,
			static fn ( OperationIdentity $identity ): bool => $site === (int) get_current_blog_id() && $site === $identity->site_id
				&& $user === get_current_user_id() && 'staff:' . $user === $identity->principal && current_user_can( 'manage_options' )
				&& 'counter:1' === $identity->target_key, $user );
		$registry = new OperationProfileRegistry( [ $profile ] );
		$configuration = [ 'host' => $host[0], 'port' => $host[1] ?: 3306, 'socket' => $host[2], 'user' => DB_USER, 'password' => DB_PASSWORD, 'database' => DB_NAME, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci' ];
		$factory = OperationConnectionFactory::from_server_configuration( $site, $prefix, $configuration );
		$service = new OperationCoordinator( $registry, $factory );
		$identity = new OperationIdentity( $site, 'wordpress', 'staff:' . $user, 'fixture.counter_update', 1, 'counter:1', 'native-accepted' );
		$payload = [ 'row_id' => 1, 'expected_revision' => 1, 'value' => 41 ];
		$first = $service->attempt( $identity, $payload, RequestContext::create() );
		$fresh = new OperationCoordinator( $registry, $factory );
		$replay = $fresh->attempt( $identity, $payload, RequestContext::create() );
		$resource = OperationProofDatabase::resource( $physical, $prefix );
		$event_count = (int) OperationProofDatabase::scalar( $physical, "SELECT COUNT(*) FROM `{$prefix}delivery_engine_operation_changes`" );
		$check( 'NATIVE-C03-ACCEPTED-REPLAY-NO-SECOND-EFFECT', 'accepted' === $first->outcome->state && 'accepted' === $replay->outcome->state && $replay->replayed && 41 === $resource['value'] && 2 === $resource['revision'] && 1 === $event_count && 1 === $profile->mutation_calls );
		wp_set_current_user( 0 );
		$denied = $fresh->attempt( $identity, $payload, RequestContext::create() );
		$check( 'NATIVE-C03-CURRENT-AUTHORITY-BEFORE-REPLAY', 'not_authorized' === $denied->outcome->error?->code && null === $denied->completion && 1 === $profile->mutation_calls );
		wp_set_current_user( $user );

		$lost_factory = new class( $site, $prefix, $configuration ) implements \CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory {
			public array $transports = [];
			public array $sessions = [];
			public function __construct( private int $site, private string $prefix, private array $configuration ) {}
			public function open(): OperationSession {
				$c = $this->configuration;
				$t = new OperationProofTransport( OperationConnectionMysqliTransport::connect( $c['host'], $c['user'], $c['password'], $c['database'], $c['port'], $c['socket'], $c['charset'] ) );
				if ( [] === $this->transports ) { $t->fault_commit = 2; $t->commit_fault = 'lost_ack'; }
				$this->transports[] = $t;
				$s = new OperationConnection( $this->site, $this->prefix, $t, $c['charset'], $c['collation'] );
				$this->sessions[] = $s;
				return $s;
			}
		};
		$lost_service = new OperationCoordinator( $registry, $lost_factory );
		$lost_identity = new OperationIdentity( $site, 'wordpress', 'staff:' . $user, 'fixture.counter_update', 1, 'counter:1', 'native-lost-ack' );
		$lost_payload = [ 'row_id' => 1, 'expected_revision' => 2, 'value' => 72 ];
		$unknown = $lost_service->attempt( $lost_identity, $lost_payload, RequestContext::create() );
		$before = OperationProofDatabase::resource( $physical, $prefix );
		$before_events = (int) OperationProofDatabase::scalar( $physical, "SELECT COUNT(*) FROM `{$prefix}delivery_engine_operation_changes`" );
		$resolved = $lost_service->reconcile( $lost_identity, $lost_payload, RequestContext::create() );
		$after = OperationProofDatabase::resource( $physical, $prefix );
		$check( 'NATIVE-C03-ACTUAL-COMMIT-LOST-ACK-RECONCILED', 'unconfirmed' === $unknown->outcome->state && 'outcome_unknown' === $unknown->outcome->error?->code && 'accepted' === $resolved->outcome->state && 72 === $before['value'] && 3 === $before['revision'] && $before === $after && 2 === $before_events && $before_events === (int) OperationProofDatabase::scalar( $physical, "SELECT COUNT(*) FROM `{$prefix}delivery_engine_operation_changes`" ) && $lost_factory->sessions[0]->is_retired() && false === $lost_factory->sessions[0]->query( 'SELECT 1' ) && 2 === $profile->mutation_calls,
			[ 'fault' => 'test decorator sends actual native COMMIT, then withholds its acknowledgement', 'production_bootstrap' => 'native WordPress; no unit bootstrap' ] );
		$empty_registry = new OperationCoordinator( new OperationProfileRegistry(), $factory );
		$unsupported = $empty_registry->attempt( $identity, $payload, RequestContext::create() );
		$check( 'NATIVE-C03-PRODUCTION-PROFILE-SET-EMPTY', 'unsupported_contract' === $unsupported->outcome->error?->code && [] === ( new OperationProfileRegistry() )->profiles() );
	} finally {
		if ( null !== $lost_factory ) { foreach ( $lost_factory->transports as $transport ) { $transport->force_close(); } }
		wp_set_current_user( $prior_user );
		if ( is_int( $user ) && $user > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			if ( ! wp_delete_user( $user ) ) { throw new RuntimeException( 'Native operation principal cleanup failed.' ); }
		}
		if ( $installed ) { OperationProofDatabase::cleanup( $physical, $prefix ); }
		$physical->close();
	}
	$check( 'NATIVE-C03-FIXTURE-CLEANUP', $prior_user === get_current_user_id() && false === get_user_by( 'id', $user ) && null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ) );

	// Native option/cache failures on the actual schema-7 migration. The marked
	// disposable site's original options are restored even if an assertion fails.
	$version_key = \CetechDeliveryEngine\Core\Versioning\SchemaVersion::OPTION_NAME;
	$status_key = \CetechDeliveryEngine\Core\Versioning\MigrationStatus::OPTION_NAME;
	$previous_version = get_option( $version_key );
	$previous_status = get_option( $status_key );
	$filters = [];
	$legacy_status = [ 'status' => 'success', 'migration_id' => '20260917120000_create_geography_coverage_tables', 'to_version' => '6' ];
	try {
		update_option( $version_key, '6', false );
		update_option( $status_key, $legacy_status, false );
		// Warm the native object cache before denying the required version write.
		get_option( $version_key ); get_option( $status_key );
		$deny_version = static fn ( mixed $new, mixed $old ): mixed => '7' === $new ? $old : $new;
		add_filter( 'pre_update_option_' . $version_key, $deny_version, 10, 2 );
		$filters[] = [ 'pre_update_option_' . $version_key, $deny_version ];
		$migration = require $root . '/database/migrations/20261006191156_create_operation_tables.php';
		$runner = new \CetechDeliveryEngine\Core\Versioning\MigrationRunner( new \CetechDeliveryEngine\Support\Logger() );
		$runner->set_migrations( [ $migration ] );
		$runner->run();
		$check( 'NATIVE-C03-DENIED-SCHEMA-PUBLICATION-KEEPS-LEGACY-AVAILABLE', '6' === get_option( $version_key ) && 'failed' === ( get_option( $status_key )['status'] ?? null )
			&& ! ( new OperationStoreReadiness() )->get_status()['ready'] && [] === \CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables::missing(),
			[ 'fault' => 'native pre_update_option denies version7; prewarmed option cache is not readiness truth' ] );
		remove_filter( 'pre_update_option_' . $version_key, $deny_version, 10 );
		$filters = [];

		update_option( $version_key, '6', false );
		update_option( $status_key, $legacy_status, false );
		$wrapped = new class( $migration ) implements \CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface {
			public int $up_count = 0;
			public int $verify_count = 0;
			public function __construct( private \CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface $migration ) {}
			public function get_id(): string { return $this->migration->get_id(); }
			public function get_version(): string { return $this->migration->get_version(); }
			public function up(): void { ++$this->up_count; $this->migration->up(); }
			public function verify(): void { ++$this->verify_count; $this->migration->verify(); }
		};
		$deny_status = static fn ( mixed $new, mixed $old ): mixed => is_array( $new ) && 'success' === ( $new['status'] ?? null ) && '7' === ( $new['to_version'] ?? null ) ? $old : $new;
		add_filter( 'pre_update_option_' . $status_key, $deny_status, 10, 2 );
		$filters[] = [ 'pre_update_option_' . $status_key, $deny_status ];
		$runner->set_migrations( [ $wrapped ] );
		$runner->run();
		$check( 'NATIVE-C03-DENIED-STATUS-KEEPS-VERIFIED-VERSION', '7' === get_option( $version_key ) && 'failed' === ( get_option( $status_key )['status'] ?? null )
			&& ! ( new OperationStoreReadiness() )->get_status()['ready'] && 1 === $wrapped->up_count );
		remove_filter( 'pre_update_option_' . $status_key, $deny_status, 10 );
		$filters = [];
		$runner->run();
		$check( 'NATIVE-C03-STATUS-RECOVERY-VERIFIES-WITHOUT-DDL', '7' === get_option( $version_key ) && 'success' === ( get_option( $status_key )['status'] ?? null )
			&& ( new OperationStoreReadiness() )->get_status()['ready'] && 1 === $wrapped->up_count && 2 === $wrapped->verify_count );
	} finally {
		foreach ( $filters as [ $hook, $filter ] ) { remove_filter( $hook, $filter, 10 ); }
		update_option( $version_key, $previous_version, false );
		update_option( $status_key, $previous_status, false );
		if ( $previous_version !== get_option( $version_key ) || $previous_status !== get_option( $status_key ) ) {
			throw new RuntimeException( 'Native operation migration option restoration failed.' );
		}
	}
};
