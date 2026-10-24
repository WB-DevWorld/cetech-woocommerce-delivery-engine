<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\PolicyAssignment;

use CetechDeliveryEngine\Application\Configuration\{EffectiveConfigurationResolver, EffectiveConfigurationValidator};
use CetechDeliveryEngine\Application\PolicyAssignment\PolicyAssignmentCurrentService;
use CetechDeliveryEngine\Application\ReturnPolicy\ReturnPolicyCatalogService;
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentCommand, PolicyAssignmentMap, PolicyAssignmentState};
use CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyContent;
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleContent, RuleLifecycleCommand};
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\{PolicyAssignmentFixture, PolicyAssignmentUnitReadiness};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/PolicyAssignment/PolicyAssignmentFixture.php';

/** Owned sequential SQL transport with production store/current reader/ECR, not native acceptance. */
final class PolicyAssignmentCurrentServiceTest extends TestCase {

	private PolicyAssignmentFixture $fixture;
	protected function setUp(): void { $this->fixture = new PolicyAssignmentFixture(); }
	protected function tearDown(): void { $this->fixture->close(); }

	public function test_absent_saved_read_and_preview_create_no_option_rule_or_operation_rows(): void {
		$f = $this->fixture; $coordinate = PolicyAssignmentState::make_coordinate( 1, 'product', 33 ); $id = $this->identity( $coordinate, 7 ); $service = $this->service( $id );
		$before = $this->rows(); $commits = $f->factory->commits;
		self::assertSame( 0, $service->read( $id, $coordinate, 7 )->row_id() );
		$result = $service->capture( $id, $coordinate, 7 ); self::assertCount( 2, $result['states'] );
		foreach ( $result['selections'] as $selection ) { self::assertSame( 'not_assigned', $selection->disposition() ); self::assertNull( $selection->version() ); }
		self::assertSame( $before, $this->rows() ); self::assertSame( $commits, $f->factory->commits );
		foreach ( $f->factory->sessions as $session ) { self::assertTrue( $session->is_retired() ); }
	}

	public function test_both_policy_families_use_saved_whole_values_without_merging_text(): void {
		$f = $this->fixture; $f->publish( 'return_policy_v1', 101, 1, [ 'summary' => 'Return only' ] ); $f->publish( 'refund_policy_v1', 201, 2, [ 'summary' => 'Refund only' ] );
		$coordinate = PolicyAssignmentState::make_coordinate( 1, 'global', 0 );
		$this->seed( $coordinate, [ 'mode' => 'assigned', 'policy_uuid' => $f::uuid( 101 ) ], [ 'mode' => 'assigned', 'policy_uuid' => $f::uuid( 201 ) ] );
		$id = $this->identity( $coordinate, 7 ); $before = $this->rows(); $result = $this->service( $id )->capture( $id, $coordinate, 7 );
		self::assertSame( 'assigned', $result['selections']['return_policy_v1']->disposition() ); self::assertSame( 'Return only', $result['selections']['return_policy_v1']->version()->content()->facts()['summary'] );
		self::assertSame( 'assigned', $result['selections']['refund_policy_v1']->disposition() ); self::assertSame( 'Refund only', $result['selections']['refund_policy_v1']->version()->content()->facts()['summary'] );
		self::assertNotSame( $result['selections']['return_policy_v1']->fingerprint(), $result['selections']['refund_policy_v1']->fingerprint() );
		self::assertSame( $before, $this->rows() );
	}

	public function test_global_product_variation_and_real_offer_specificity_remain_independent(): void {
		$f = $this->fixture; $f->publish( 'return_policy_v1', 101, 1 ); $f->publish( 'return_policy_v1', 102, 2 ); $f->publish( 'refund_policy_v1', 201, 3 );
		$global = PolicyAssignmentState::make_coordinate( 1, 'global', 0 ); $product = PolicyAssignmentState::make_coordinate( 1, 'product', 33 ); $variation = PolicyAssignmentState::make_coordinate( 1, 'variation', 44, 33 );
		$this->seed( $global, [ 'mode' => 'assigned', 'policy_uuid' => $f::uuid( 101 ) ], [ 'mode' => 'assigned', 'policy_uuid' => $f::uuid( 201 ) ] );
		$this->seed( $product, [ 'mode' => 'disabled' ], [ 'mode' => 'inherit' ] );
		$this->seed( $variation, [ 'mode' => 'inherit' ], [ 'mode' => 'inherit' ], [ 7 => [ 'mode' => 'assigned', 'policy_uuid' => $f::uuid( 102 ) ] ] );
		$id = $this->identity( $variation, 7 ); $result = $this->service( $id )->capture( $id, $variation, 7 ); self::assertCount( 3, $result['states'] );
		self::assertSame( $f::uuid( 102 ), $result['selections']['return_policy_v1']->version()->reference()->logical_uuid );
		self::assertSame( $f::uuid( 201 ), $result['selections']['refund_policy_v1']->version()->reference()->logical_uuid );
		$id2 = $this->identity( $variation, 8 ); $other = $this->service( $id2 )->capture( $id2, $variation, 8 );
		self::assertSame( 'disabled', $other['selections']['return_policy_v1']->disposition() ); self::assertSame( 'assigned', $other['selections']['refund_policy_v1']->disposition() );
		self::assertNotSame( $result['selections']['refund_policy_v1']->fingerprint(), $other['selections']['refund_policy_v1']->fingerprint() );
	}

	public function test_assigned_unavailable_policy_does_not_fall_back_and_does_not_hide_other_family(): void {
		$f = $this->fixture; $f->publish( 'return_policy_v1', 101, 1 ); $f->publish( 'refund_policy_v1', 201, 2 );
		$global = PolicyAssignmentState::make_coordinate( 1, 'global', 0 ); $product = PolicyAssignmentState::make_coordinate( 1, 'product', 33 );
		$this->seed( $global, [ 'mode' => 'assigned', 'policy_uuid' => $f::uuid( 101 ) ], [ 'mode' => 'assigned', 'policy_uuid' => $f::uuid( 201 ) ] );
		$this->seed( $product, [ 'mode' => 'assigned', 'policy_uuid' => $f::uuid( 999 ) ], [ 'mode' => 'inherit' ] );
		$id = $this->identity( $product, 7 ); $result = $this->service( $id )->capture( $id, $product, 7 );
		self::assertSame( 'unresolved', $result['selections']['return_policy_v1']->disposition() ); self::assertNull( $result['selections']['return_policy_v1']->version() );
		self::assertSame( 'assigned', $result['selections']['refund_policy_v1']->disposition() );
	}

	public function test_malformed_selected_history_is_unresolved_while_transport_fault_aborts_whole_capture(): void {
		$f = $this->fixture; $f->publish( 'return_policy_v1', 101, 1 ); $f->publish( 'refund_policy_v1', 201, 2 ); $coordinate = PolicyAssignmentState::make_coordinate( 1, 'global', 0 );
		$this->seed( $coordinate, [ 'mode' => 'assigned', 'policy_uuid' => $f::uuid( 101 ) ], [ 'mode' => 'assigned', 'policy_uuid' => $f::uuid( 201 ) ] );
		$f->factory->inspection()->exec( 'UPDATE ' . $f->table( 'rule_versions' ) . " SET content_hash='" . str_repeat( 'f', 64 ) . "' WHERE version_uuid='" . $f::uuid( 1 ) . "'" );
		$id = $this->identity( $coordinate, 7 ); $result = $this->service( $id )->capture( $id, $coordinate, 7 );
		self::assertSame( 'unresolved', $result['selections']['return_policy_v1']->disposition() ); self::assertSame( 'assigned', $result['selections']['refund_policy_v1']->disposition() );
		$f->factory->on_query = static function( string $sql ): void { if ( str_contains( $sql, 'logical_rule_id=' ) ) { throw new \RuntimeException( 'Injected transport loss' ); } };
		$this->unavailable( fn() => $this->service( $id )->capture( $id, $coordinate, 7 ) );
		foreach ( $f->factory->sessions as $session ) { self::assertTrue( $session->is_retired() ); }
	}

	public function test_clock_is_read_after_source_locks_and_time_alone_does_not_change_material_fingerprint(): void {
		$f = $this->fixture; $f->publish( 'return_policy_v1', 101, 1 ); $coordinate = PolicyAssignmentState::make_coordinate( 1, 'global', 0 ); $this->seed( $coordinate, [ 'mode' => 'assigned', 'policy_uuid' => $f::uuid( 101 ) ], [ 'mode' => 'inherit' ] );
		$id = $this->identity( $coordinate, 7 ); $f->factory->sql = []; $first = $this->service( $id )->capture( $id, $coordinate, 7 ); $sql = implode( "\n", $f->factory->sql );
		self::assertGreaterThan( strrpos( $sql, 'logical_rule_id=' ), strrpos( $sql, 'SELECT UTC_TIMESTAMP(6)' ) );
		$f->factory->clock = '2026-10-24 10:01:00.000001'; $second = $this->service( $id )->capture( $id, $coordinate, 7 );
		self::assertNotSame( $first['selections']['return_policy_v1']->evaluated_at()->sql(), $second['selections']['return_policy_v1']->evaluated_at()->sql() );
		self::assertSame( $first['selections']['return_policy_v1']->fingerprint(), $second['selections']['return_policy_v1']->fingerprint() );
	}

	public function test_same_reader_follows_a_later_published_head_without_rewriting_the_assignment(): void {
		$f = $this->fixture; $f->publish( 'return_policy_v1', 101, 1, [ 'summary' => 'First authored version' ] ); $coordinate = PolicyAssignmentState::make_coordinate( 1, 'global', 0 );
		$this->seed( $coordinate, [ 'mode' => 'assigned', 'policy_uuid' => $f::uuid( 101 ) ], [ 'mode' => 'inherit' ] );
		$id = $this->identity( $coordinate, 7 ); $service = $this->service( $id ); $first = $service->capture( $id, $coordinate, 7 ); $options = $f->rows( 'options' );
		$f->factory->clock = '2026-10-24 10:02:00.000001'; $opened = $f->opened( 'return_policy_v1', $f::uuid( 101 ), $f::uuid( 1 ) );
		$payload = $opened; $payload['version_uuid'] = $f::uuid( 2 ); $payload['predecessor_uuid'] = $f::uuid( 1 );
		$payload['payload'] = ReturnPolicyContent::from_array( array_replace( $first['selections']['return_policy_v1']->version()->content()->facts(), [ 'summary' => 'Later published version' ] ) )->to_rule_payload();
		$payload['effective_from'] = null; $payload['effective_until'] = null;
		$payload['preconditions']['version_id'] = 0; $payload['preconditions']['version_revision'] = 0;
		$payload['preconditions']['predecessor_id'] = $opened['preconditions']['version_id']; $payload['preconditions']['predecessor_revision'] = $opened['preconditions']['version_revision'];
		$catalog = new ReturnPolicyCatalogService( $f->return_family, $f->factory, static fn(): bool => true, new PolicyAssignmentUnitReadiness() );
		$make = static fn( string $operation, string $token ): OperationIdentity => new OperationIdentity( 1, 'wordpress', 'staff:9', $operation, 1, RuleLifecycleCommand::make_target_key( 'return_policy_v1', RuleContent::scope_hash( $f->return_family, $payload['scope'] ), $payload['logical_uuid'] ), $token );
		self::assertSame( 'accepted', $catalog->attempt( $make( 'rule.draft.create', 'replacement-draft' ), $payload, RequestContext::create() )->outcome->state );
		$publish = $f->opened( 'return_policy_v1', $f::uuid( 101 ), $f::uuid( 2 ) );
		self::assertSame( 'accepted', $catalog->attempt( $make( 'rule.publish', 'replacement-publish' ), $publish, RequestContext::create() )->outcome->state );
		$second = $service->capture( $id, $coordinate, 7 );
		self::assertSame( $f::uuid( 1 ), $first['selections']['return_policy_v1']->version()->reference()->version_uuid );
		self::assertSame( $f::uuid( 2 ), $second['selections']['return_policy_v1']->version()->reference()->version_uuid );
		self::assertSame( 'First authored version', $first['selections']['return_policy_v1']->version()->content()->facts()['summary'] );
		self::assertSame( 'Later published version', $second['selections']['return_policy_v1']->version()->content()->facts()['summary'] );
		self::assertNotSame( $first['selections']['return_policy_v1']->fingerprint(), $second['selections']['return_policy_v1']->fingerprint() ); self::assertSame( $options, $f->rows( 'options' ) );
	}

	#[DataProvider( 'invalid_identity_fields' )]
	public function test_wrong_identity_or_target_is_refused_before_any_connection( string $field, mixed $value ): void {
		$f = $this->fixture; $coordinate = PolicyAssignmentState::make_coordinate( 1, 'global', 0 ); $valid = $this->identity( $coordinate, 7 );
		$tuple = [ 'site_id' => 1, 'authority' => PolicyAssignmentCommand::AUTHORITY, 'principal' => 'staff:9', 'operation' => 'policy.assignment.read', 'operation_version' => 1, 'target_key' => $valid->target_key, 'idempotency_key' => 'read' ]; $tuple[$field] = $value;
		$id = new OperationIdentity( ...$tuple ); $opens = $f->factory->opens; $this->unavailable( fn() => $this->service( $id )->capture( $id, $coordinate, 7 ) ); self::assertSame( $opens, $f->factory->opens );
	}
	public static function invalid_identity_fields(): array { return [ 'site' => [ 'site_id', 2 ], 'authority' => [ 'authority', 'return_policy.configuration' ], 'principal' => [ 'principal', 'staff:10' ], 'operation' => [ 'operation', 'policy.assignment.set' ], 'version' => [ 'operation_version', 2 ], 'target' => [ 'target_key', 'policy-assignment:product:33:offer:7' ] ]; }

	public function test_default_edit_slot_cannot_be_used_for_effective_preview(): void {
		$f = $this->fixture; $coordinate = PolicyAssignmentState::make_coordinate( 1, 'global', 0 ); $id = $this->identity( $coordinate, 0 ); $opens = $f->factory->opens;
		$this->unavailable( fn() => $this->service( $id )->capture( $id, $coordinate, 0 ) ); self::assertSame( $opens, $f->factory->opens );
		self::assertSame( 0, $this->service( $id )->read( $id, $coordinate )->revision() );
	}

	public function test_revoked_or_nonstrict_grant_never_opens_or_discloses(): void {
		$f = $this->fixture; $coordinate = PolicyAssignmentState::make_coordinate( 1, 'global', 0 ); $id = $this->identity( $coordinate, 7 );
		foreach ( [ false, 1, '1', null ] as $value ) { $f->allowed = $value; $opens = $f->factory->opens; $this->unavailable( fn() => $this->service( $id )->capture( $id, $coordinate, 7 ) ); self::assertSame( $opens, $f->factory->opens ); }
	}

	public function test_late_revocation_hides_a_retired_successful_read_and_has_no_later_callback(): void {
		$f = $this->fixture; $coordinate = PolicyAssignmentState::make_coordinate( 1, 'global', 0 ); $id = $this->identity( $coordinate, 7 );
		$f->on_authorize = static function() use ( $f ): void { if ( [] !== $f->factory->sessions && end( $f->factory->sessions )->is_retired() ) { $f->allowed = false; } };
		$this->unavailable( fn() => $this->service( $id )->capture( $id, $coordinate, 7 ) ); self::assertTrue( end( $f->factory->sessions )->is_retired() );
		$f->on_authorize = null; $f->allowed = true; $before = $f->factory->retirements; $this->service( $id )->capture( $id, $coordinate, 7 ); self::assertSame( $before + 1, $f->factory->retirements );
	}

	#[DataProvider( 'owner_faults' )]
	public function test_owner_and_cleanup_failures_never_expose_partial_results( string $fault ): void {
		$f = $this->fixture; $coordinate = PolicyAssignmentState::make_coordinate( 1, 'global', 0 ); $id = $this->identity( $coordinate, 7 ); $wrapped = null;
		$factory = new class( $f->factory, $fault, $wrapped ) implements OperationConnectionFactory {
			public function __construct( private OperationConnectionFactory $inner, private string $fault, private mixed &$opened ) {}
			public function open(): OperationSession { return $this->opened = new PolicyCurrentFaultSession( $this->inner->open(), $this->fault ); }
		};
		$this->unavailable( fn() => $this->service( $id, $factory )->capture( $id, $coordinate, 7 ) );
		self::assertInstanceOf( PolicyCurrentFaultSession::class, $wrapped ); self::assertGreaterThanOrEqual( 1, $wrapped->retire_calls );
		if ( 'poison_metadata' === $fault ) { self::assertGreaterThanOrEqual( 1, $wrapped->rollback_calls ); }
		self::assertTrue( end( $f->factory->sessions )->is_retired() );
	}
	public static function owner_faults(): array { return array_combine( [ 'begin_false', 'rollback_false', 'retire_false', 'metadata_after_retire', 'poison_metadata', 'validate_false' ], array_map( static fn( string $fault ): array => [ $fault ], [ 'begin_false', 'rollback_false', 'retire_false', 'metadata_after_retire', 'poison_metadata', 'validate_false' ] ) ); }

	private function service( OperationIdentity $id, ?OperationConnectionFactory $factory = null ): PolicyAssignmentCurrentService {
		$f = $this->fixture; return new PolicyAssignmentCurrentService( $factory ?? $f->factory, $f->store, $f->catalog( $id ), new EffectiveConfigurationResolver( new InMemoryScopedConfigurationRepository(), new EffectiveConfigurationValidator() ), [ $f, 'authorize' ], new PolicyAssignmentUnitReadiness() );
	}
	private function identity( array $coordinate, int $offer ): OperationIdentity { return new OperationIdentity( 1, PolicyAssignmentCommand::AUTHORITY, 'staff:9', 'policy.assignment.read', 1, PolicyAssignmentCommand::make_target( $coordinate['scope_type'], $coordinate['scope_id'], $offer ), 'current-read' ); }
	private function seed( array $coordinate, array $return, array $refund, array $return_offers = [] ): void {
		$f = $this->fixture; $returns = PolicyAssignmentMap::empty( 1, 'return_policy_v1' )->with_instruction( 0, $return ); foreach ( $return_offers as $offer => $instruction ) { $returns = $returns->with_instruction( $offer, $instruction ); }
		$refunds = PolicyAssignmentMap::empty( 1, 'refund_policy_v1' )->with_instruction( 0, $refund );
		$bytes = PolicyAssignmentState::record_json( $coordinate, $f::uuid( 900 + $coordinate['scope_id'] ), 1, $returns, $refunds, 9, 'Owned saved-state fixture', $f->factory->clock );
		$statement = $f->factory->inspection()->prepare( 'INSERT INTO ' . $f->table( 'options' ) . ' (option_name,option_value,autoload) VALUES (?,?,?)' ); $statement->execute( [ $f->store->name( $coordinate ), $bytes, 'off' ] );
	}
	private function rows(): array { $f = $this->fixture; return [ $f->rows( 'options' ), $f->rows( 'logical_rules' ), $f->rows( 'rule_versions' ), $f->rows( 'rule_family_guards' ), $f->rows( 'operation_records' ), $f->rows( 'operation_changes' ) ]; }
	private function unavailable( callable $call ): void { try { $call(); self::fail( 'An unavailable observation escaped.' ); } catch ( \RuntimeException $error ) { self::assertSame( 'Policy assignments are unavailable.', $error->getMessage() ); } }
}

/** Faults only the read-owner acknowledgements/metadata, never substitutes business behavior. */
final class PolicyCurrentFaultSession implements OperationSession {
	public int $retire_calls = 0;
	public int $rollback_calls = 0;
	private bool $poisoned = false;
	public function __construct( private OperationSession $inner, private string $fault ) {}
	public function site_id(): int { if ( 'metadata_after_retire' === $this->fault && $this->inner->is_retired() ) { throw new \RuntimeException(); } return $this->inner->site_id(); }
	public function table_prefix(): string { return $this->inner->table_prefix(); }
	public function charset_collate(): string { return $this->inner->charset_collate(); }
	public function begin(): bool { if ( 'begin_false' === $this->fault ) { return false; } return $this->inner->begin(); }
	public function commit(): OperationCommitResult { return $this->inner->commit(); }
	public function rollback(): bool { ++$this->rollback_calls; $result = $this->inner->rollback(); return 'rollback_false' === $this->fault ? false : $result; }
	public function retire(): bool { ++$this->retire_calls; $result = $this->inner->retire(); return 'retire_false' === $this->fault ? false : $result; }
	public function is_retired(): bool { return $this->inner->is_retired(); }
	public function in_transaction(): bool { if ( $this->poisoned ) { throw new \RuntimeException(); } return $this->inner->in_transaction(); }
	public function validate_tables( array $table_names ): bool { return 'validate_false' === $this->fault ? false : $this->inner->validate_tables( $table_names ); }
	public function query( string $sql ): int|false { return $this->inner->query( $sql ); }
	public function get_row( string $sql ): array|null|false { $row = $this->inner->get_row( $sql ); if ( 'poison_metadata' === $this->fault && str_contains( $sql, 'SELECT UTC_TIMESTAMP(6)' ) ) { $this->poisoned = true; } return $row; }
	public function get_results( string $sql ): array|false { return $this->inner->get_results( $sql ); }
	public function prepare( string $sql, mixed ...$args ): string { return $this->inner->prepare( $sql, ...$args ); }
	public function errno(): int { return $this->inner->errno(); }
	public function insert_id(): int { return $this->inner->insert_id(); }
}
