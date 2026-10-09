<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\RuleLifecycle\RuleLifecycleService;
use CetechDeliveryEngine\Application\RuleLifecycle\RuleLifecycleReadService;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyRegistry;
use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleReadiness;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnection;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionFactory;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionMysqliTransport;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofDatabase;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofEnvelope;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofFamily;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofTransport;

/** Actual WordPress authority, native InnoDB commits, and default option cache. */
return static function ( callable $check ): void {
	global $wpdb;
	if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_ADMIN' ) || true !== WP_ADMIN
		|| '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' )
		|| '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) || ! $wpdb instanceof wpdb ) {
		throw new RuntimeException( 'Rule qualification requires the native disposable fixture.' );
	}
	foreach ( get_included_files() as $included ) {
		if ( str_ends_with( str_replace( '\\', '/', $included ), '/tests/bootstrap.php' ) ) { throw new RuntimeException( 'Unit bootstrap is forbidden in native rule qualification.' ); }
	}
	$root = dirname( __DIR__, 2 );
	require_once $root . '/tests/Support/Operation/OperationProofDatabase.php';
	foreach ( [ 'RuleProofDatabase', 'RuleProofEnvelope', 'RuleProofFamily', 'RuleProofTransport' ] as $fixture ) { require_once $root . '/tests/Support/RuleLifecycle/' . $fixture . '.php'; }
	$check( 'NATIVE-C04-ACTUAL-SCHEMA-EIGHT-FIVE-TABLES-READY', SchemaVersion::TARGET === get_option( 'cetech_de_db_version' ) && ( new RuleLifecycleReadiness() )->get_status()['ready'] && ( new OperationStoreReadiness() )->get_status()['ready'] );
	$host = $wpdb->parse_db_host( DB_HOST );
	if ( ! is_array( $host ) ) { throw new RuntimeException( 'Native rule database configuration is unavailable.' ); }
	$physical = new mysqli( $host[0], DB_USER, DB_PASSWORD, DB_NAME, $host[1] ?: 3306, $host[2] );
	if ( $physical->connect_errno || ! $physical->set_charset( 'utf8mb4' ) ) { throw new RuntimeException( 'Native rule database is unavailable.' ); }
	$prefix = RuleProofDatabase::prefix();
	$site = (int) get_current_blog_id();
	$prior_user = get_current_user_id();
	$user = 0; $installed = false; $lost_factory = null;
	try {
		RuleProofDatabase::install( $physical, $prefix ); $installed = true;
		$user = wp_insert_user( [ 'user_login' => 'rule-proof-' . bin2hex( random_bytes( 8 ) ), 'user_pass' => wp_generate_password( 40, true, true ), 'role' => 'administrator' ] );
		if ( is_wp_error( $user ) || ! is_int( $user ) || $user < 1 ) { throw new RuntimeException( 'Native rule principal setup failed.' ); }
		wp_set_current_user( $user );
		$family = new RuleProofFamily(
			static fn ( OperationIdentity $identity, array $scope ): bool => $site === (int) get_current_blog_id() && $identity->site_id === $site && 'wordpress' === $identity->authority && $user === get_current_user_id() && 'staff:' . $user === $identity->principal && current_user_can( 'manage_options' ) && 'global' === $scope['type'],
			static fn ( int $author_site, int $author, array $scope ): bool => $site === $author_site && $user === $author && user_can( $user, 'manage_options' ) && 'global' === $scope['type']
		);
		$families = new RuleFamilyRegistry( [ $family ] );
		$configuration = [ 'host' => $host[0], 'port' => $host[1] ?: 3306, 'socket' => $host[2], 'user' => DB_USER, 'password' => DB_PASSWORD, 'database' => DB_NAME, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci' ];
		$factory = OperationConnectionFactory::from_server_configuration( $site, $prefix, $configuration );
		$service = new RuleLifecycleService( $families, $factory );
		$payload = RuleProofEnvelope::create( RuleProofEnvelope::uuid( 1 ), RuleProofEnvelope::uuid( 101 ) ); $payload['author_user_id'] = $user;
		$identity = RuleProofEnvelope::identity( $payload, 'rule.draft.create', 'native-rule-draft', $site, 'staff:' . $user );
		$draft = $service->attempt( $identity, $payload, RequestContext::create() );
		$replay = ( new RuleLifecycleService( $families, $factory ) )->attempt( $identity, $payload, RequestContext::create() );
		$count = static fn ( string $suffix ): int => (int) RuleProofDatabase::scalar( $physical, "SELECT COUNT(*) FROM `{$prefix}delivery_engine_{$suffix}`" );
		$check( 'NATIVE-C04-DRAFT-ACCEPTED-REPLAY-ONE-VERSION-ONE-EVENT', 'accepted' === $draft->outcome->state && 'accepted' === $replay->outcome->state && $replay->replayed && 1 === $count( 'rule_versions' ) && 1 === $count( 'operation_changes' ) );
		wp_set_current_user( 0 );
		$denied = $service->attempt( $identity, $payload, RequestContext::create() );
		$check( 'NATIVE-C04-CURRENT-WORDPRESS-AUTHORITY-BEFORE-PRIVATE-REPLAY', 'not_authorized' === $denied->outcome->error?->code && null === $denied->completion && 1 === $count( 'operation_changes' ) );
		wp_set_current_user( $user );
		$opened = RuleProofEnvelope::opened( $physical, $prefix, $payload['logical_uuid'], $payload['version_uuid'] );
		$publish_id = RuleProofEnvelope::identity( $opened, 'rule.publish', 'native-rule-publish', $site, 'staff:' . $user );
		$published = $service->attempt( $publish_id, $opened, RequestContext::create() );
		$sealed = RuleProofEnvelope::version( $physical, $prefix, $payload['version_uuid'] );
		$check( 'NATIVE-C04-IMMEDIATE-PUBLISH-SEALS-DATABASE-INSTANT', 'accepted' === $published->outcome->state && 'published' === $sealed['state'] && null === $opened['effective_from'] && $sealed['published_at'] === $sealed['effective_from'] && null !== $sealed['sealed_at'] && 2 === $count( 'operation_changes' ) );
		$read = new RuleLifecycleReadService( $families, $factory );
		$before_counts = [ $count( 'operation_records' ), $count( 'operation_changes' ), $count( 'rule_versions' ) ];
		$snapshot = $read->capture( $publish_id, $payload['family'], $payload['scope'], RequestContext::create() );
		$check( 'NATIVE-C04-COHERENT-NATIVE-READ-ADDS-NO-OPERATION-OR-AUDIT', $snapshot instanceof \CetechDeliveryEngine\Domain\RuleLifecycle\RuleSnapshot && $snapshot->complete && 1 === count( $snapshot->versions ) && $before_counts === [ $count( 'operation_records' ), $count( 'operation_changes' ), $count( 'rule_versions' ) ] );
		$all_facts = static function () use ( $physical, $prefix ): array {
			$facts = [];
			foreach ( [ 'operation_records', 'operation_changes', 'rule_family_guards', 'logical_rules', 'rule_versions' ] as $suffix ) {
				$result = $physical->query( "SELECT * FROM `{$prefix}delivery_engine_{$suffix}` ORDER BY id ASC LIMIT 100" );
				if ( ! $result instanceof mysqli_result ) { throw new RuntimeException( 'Native preview sentinel read failed.' ); }
				$facts[$suffix] = $result->fetch_all( MYSQLI_ASSOC ); $result->free();
			}
			return $facts;
		};
		$preview_before = $all_facts();
		$overlay_row = $snapshot->versions[0]->row();
		$overlay_row['payload_json'] = $family->payload_schema()->encode( [ 'availability' => 'deny' ], 16384 );
		$overlay_row['content_hash'] = \CetechDeliveryEngine\Domain\RuleLifecycle\RuleContent::hash( $family, [ 'availability' => 'deny' ], $snapshot->versions[0]->start_mode, $snapshot->versions[0]->effective_from, $snapshot->versions[0]->effective_until, $snapshot->versions[0]->priority, null );
		$overlay_version = \CetechDeliveryEngine\Domain\RuleLifecycle\RuleVersion::hypothetical( $overlay_row, $snapshot->logicals[0], $family );
		$overlay = new \CetechDeliveryEngine\Domain\RuleLifecycle\RuleCandidate( $snapshot->logicals[0], $overlay_version );
		$preview_at = \CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime::parse( (string) RuleProofDatabase::scalar( $physical, 'SELECT UTC_TIMESTAMP(6)' ) );
		$preview = ( new \CetechDeliveryEngine\Application\RuleLifecycle\RuleImpactPreviewService( $read ) )->preview( $publish_id, $payload['family'], $payload['scope'], [ $payload['scope'] ], $overlay, $preview_at, RequestContext::create() );
		$check( 'NATIVE-C04-BASELINE-OVERLAY-PREVIEW-LEAVES-ALL-FIVE-STORES-UNCHANGED', $preview instanceof \CetechDeliveryEngine\Domain\RuleLifecycle\RuleImpactPreview && $preview->comparisons[0]['baseline']->complete && $preview->comparisons[0]['proposed']->complete && $sealed['content_hash'] === $preview->comparisons[0]['baseline']->selected?->content_hash && $overlay_row['content_hash'] === $preview->comparisons[0]['proposed']->selected?->content_hash && true === $preview->comparisons[0]['proposed']->selected?->hypothetical && $preview_before === $all_facts(), [ 'actual_native_database' => true, 'same_evaluation_instant' => true, 'stored_bytes_unchanged' => $preview_before === $all_facts() ] );
		$lost_factory = new class( $site, $prefix, $configuration ) implements \CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory {
			public array $transports = []; public array $sessions = [];
			public function __construct( private int $site, private string $prefix, private array $configuration ) {}
			public function open(): OperationSession {
				$c = $this->configuration;
				$t = new RuleProofTransport( OperationConnectionMysqliTransport::connect( $c['host'], $c['user'], $c['password'], $c['database'], $c['port'], $c['socket'], $c['charset'] ) );
				if ( [] === $this->transports ) { $t->fault_commit = 2; $t->commit_fault = 'lost_ack'; }
				$this->transports[] = $t;
				$s = new OperationConnection( $this->site, $this->prefix, $t, $c['charset'], $c['collation'] ); $this->sessions[] = $s; return $s;
			}
		};
		$lost_service = new RuleLifecycleService( $families, $lost_factory );
		$retire_payload = RuleProofEnvelope::opened( $physical, $prefix, $payload['logical_uuid'], $payload['version_uuid'] );
		$retire_id = RuleProofEnvelope::identity( $retire_payload, 'rule.retire', 'native-rule-lost-ack', $site, 'staff:' . $user );
		$unknown = $lost_service->attempt( $retire_id, $retire_payload, RequestContext::create() );
		$retired = RuleProofEnvelope::version( $physical, $prefix, $payload['version_uuid'] ); $events = $count( 'operation_changes' );
		$resolved = $lost_service->reconcile( $retire_id, $retire_payload, RequestContext::create() );
		$check( 'NATIVE-C04-ACTUAL-RULE-COMMIT-LOST-ACK-READ-ONLY-RECONCILIATION', 'unconfirmed' === $unknown->outcome->state && 'accepted' === $resolved->outcome->state && $resolved->replayed && 'retired' === $retired['state'] && $retired === RuleProofEnvelope::version( $physical, $prefix, $payload['version_uuid'] ) && 3 === $events && $events === $count( 'operation_changes' ) && $lost_factory->sessions[0]->is_retired() && false === $lost_factory->sessions[0]->query( 'SELECT 1' ), [ 'fault' => 'decorator sends actual native COMMIT then withholds acknowledgement', 'bootstrap' => 'actual WordPress, no unit bootstrap' ] );
		$unsupported = ( new RuleLifecycleService( new RuleFamilyRegistry(), $factory ) )->attempt( $identity, $payload, RequestContext::create() );
		$check( 'NATIVE-C04-PRODUCTION-FAMILY-REGISTRY-DEFAULT-EMPTY', 'unsupported_contract' === $unsupported->outcome->error?->code && [] === ( new RuleFamilyRegistry() )->profiles() && 3 === $count( 'operation_changes' ) );
	} finally {
		if ( null !== $lost_factory ) { foreach ( $lost_factory->transports as $transport ) { $transport->force_close(); } }
		wp_set_current_user( $prior_user );
		if ( is_int( $user ) && $user > 0 ) { require_once ABSPATH . 'wp-admin/includes/user.php'; if ( ! wp_delete_user( $user ) ) { throw new RuntimeException( 'Native rule principal cleanup failed.' ); } }
		if ( $installed ) { RuleProofDatabase::cleanup( $physical, $prefix ); } $physical->close();
	}
	$check( 'NATIVE-C04-FIXTURE-USER-TABLES-CLEANED', $prior_user === get_current_user_id() && false === get_user_by( 'id', $user ) && null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ) );
	// Native option/cache failures on the actual schema-8 migration. The marked
	// disposable site's original options are restored even if an assertion fails.
	$version_key = \CetechDeliveryEngine\Core\Versioning\SchemaVersion::OPTION_NAME;
	$status_key = \CetechDeliveryEngine\Core\Versioning\MigrationStatus::OPTION_NAME;
	$previous_version = get_option( $version_key );
	$previous_status = get_option( $status_key );
	$filters = [];
	$legacy_status = [ 'status' => 'success', 'migration_id' => '20261006191156_create_operation_tables', 'to_version' => '7' ];
	$legacy_probe = static function (): array {
		$container = \CetechDeliveryEngine\Bootstrap\Plugin::instance()->container();
		$resolver = $container->get( \CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver::class );
		$resolver->clearMemoization();
		$config = $resolver->resolve( new \CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest( 2147483647 ) );
		$quote = $container->get( \CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine::class )->quote(
			new \CetechDeliveryEngine\Application\RateQuote\RateQuoteRequest( 2147483647, 2147483647, 1, new \CetechDeliveryEngine\Domain\ValueObject\CurrencyCode( 'GHS' ) )
		);
		return [ $config->state->value, $config->version->fingerprint, $quote->success, $quote->error_code ];
	};
	$legacy_before = $legacy_probe();
	try {
		update_option( $version_key, '7', false );
		update_option( $status_key, $legacy_status, false );
		// Warm the native object cache before denying the required version write.
		get_option( $version_key ); get_option( $status_key );
		$deny_version = static fn ( mixed $new, mixed $old ): mixed => '8' === $new ? $old : $new;
		add_filter( 'pre_update_option_' . $version_key, $deny_version, 10, 2 );
		$filters[] = [ 'pre_update_option_' . $version_key, $deny_version ];
		$migration = require $root . '/database/migrations/20261006214731_create_rule_lifecycle_tables.php';
		$runner = new \CetechDeliveryEngine\Core\Versioning\MigrationRunner( new \CetechDeliveryEngine\Support\Logger() );
		$runner->set_migrations( [ $migration ] );
		$runner->run();
		$check( 'NATIVE-C04-DENIED-SCHEMA-PUBLICATION-KEEPS-LEGACY-AVAILABLE', '7' === get_option( $version_key ) && 'failed' === ( get_option( $status_key )['status'] ?? null )
			&& ! ( new OperationStoreReadiness() )->get_status()['ready'] && ! ( new RuleLifecycleReadiness() )->get_status()['ready'] && [] === \CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables::missing(),
			[ 'fault' => 'native pre_update_option denies version8; prewarmed option cache is not readiness truth' ] );
		$legacy_after = $legacy_probe();
		$check( 'NATIVE-C04-FAILED-SCHEMA-LEGACY-CONFIGURATION-AND-NO-RATE-QUOTE-UNCHANGED', $legacy_before === $legacy_after && false === $legacy_after[2] && 'no_matching_rate_card' === $legacy_after[3], [ 'actual_services' => 'native container resolver and quote engine', 'quote_case' => 'known absent rate IDs, same domain refusal before and during schema failure' ] );
		remove_filter( 'pre_update_option_' . $version_key, $deny_version, 10 );
		$filters = [];

		update_option( $version_key, '7', false );
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
		$deny_status = static fn ( mixed $new, mixed $old ): mixed => is_array( $new ) && 'success' === ( $new['status'] ?? null ) && '8' === ( $new['to_version'] ?? null ) ? $old : $new;
		add_filter( 'pre_update_option_' . $status_key, $deny_status, 10, 2 );
		$filters[] = [ 'pre_update_option_' . $status_key, $deny_status ];
		$runner->set_migrations( [ $wrapped ] );
		$runner->run();
		$check( 'NATIVE-C04-DENIED-STATUS-KEEPS-VERIFIED-VERSION', '8' === get_option( $version_key ) && 'failed' === ( get_option( $status_key )['status'] ?? null )
			&& ! ( new OperationStoreReadiness() )->get_status()['ready'] && ! ( new RuleLifecycleReadiness() )->get_status()['ready'] && 1 === $wrapped->up_count );
		remove_filter( 'pre_update_option_' . $status_key, $deny_status, 10 );
		$filters = [];
		$runner->run();
		$check( 'NATIVE-C04-STATUS-RECOVERY-VERIFIES-WITHOUT-DDL', '8' === get_option( $version_key ) && 'success' === ( get_option( $status_key )['status'] ?? null )
			&& ( new OperationStoreReadiness() )->get_status()['ready'] && ( new RuleLifecycleReadiness() )->get_status()['ready'] && 1 === $wrapped->up_count && 2 === $wrapped->verify_count );
	} finally {
		foreach ( $filters as [ $hook, $filter ] ) { remove_filter( $hook, $filter, 10 ); }
		update_option( $version_key, $previous_version, false );
		update_option( $status_key, $previous_status, false );
		if ( $previous_version !== get_option( $version_key ) || $previous_status !== get_option( $status_key ) ) {
			throw new RuntimeException( 'Native operation migration option restoration failed.' );
		}
	}
};
