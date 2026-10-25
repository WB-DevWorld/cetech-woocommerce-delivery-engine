<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\PolicyAssignment;

use CetechDeliveryEngine\Application\Configuration\{EffectiveConfigurationResolver, EffectiveConfigurationValidator};
use CetechDeliveryEngine\Application\PolicyAssignment\{PolicyAssignmentCurrentService, PolicyAssignmentService};
use CetechDeliveryEngine\Application\PolicyAssignment\PolicyCurrentCatalog;
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentCommand, PolicyAssignmentState};
use CetechDeliveryEngine\Infrastructure\Persistence\{InMemoryScopedConfigurationRepository, PolicyAssignmentStore};
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;
use CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\PolicyAssignmentAdminFixture;
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\{PolicyAssignmentFixture as Fixture, PolicyAssignmentReadFenceFactory, PolicyAssignmentUnitFactory, PolicyAssignmentUnitReadiness};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/PolicyAssignment/PolicyAssignmentAdminFixture.php';

/** Cross-boundary original/CAS/owner proofs; the shared SQLite model is not native SQL acceptance. */
final class PolicyAssignmentSecurityTest extends TestCase {
	use RestoresWordPressFixtureGlobals;
	private Fixture $f;
	private mixed $old_blog;
	private mixed $old_vendor;
	protected function setUp(): void {
		$this->remember_fixture_globals(); $this->old_blog = $GLOBALS['blog_id'] ?? null;
		$this->old_vendor = ( new \ReflectionProperty( AdminPageAccess::class, 'vendor_isolation' ) )->getValue();
		$this->f = new Fixture(); $this->f->publish( 'return_policy_v1', 101, 1 ); $this->f->publish( 'refund_policy_v1', 102, 2 );
	}
	protected function tearDown(): void {
		$this->f->close(); AdminPageAccess::bind( $this->old_vendor ); $this->restore_fixture_globals();
		if ( null === $this->old_blog ) { unset( $GLOBALS['blog_id'] ); } else { $GLOBALS['blog_id'] = $this->old_blog; }
	}
	private function payload(): array { return $this->f->payload( instructions: [ 'return_policy_v1' => [ 'mode' => 'assigned', 'policy_uuid' => Fixture::uuid( 101 ) ], 'refund_policy_v1' => [ 'mode' => 'assigned', 'policy_uuid' => Fixture::uuid( 102 ) ] ] ); }
	private function read_identity( ?array $coordinate = null, int $offer = 0 ): OperationIdentity {
		$c = $coordinate ?? PolicyAssignmentState::make_coordinate( 1, 'global', 0 );
		return new OperationIdentity( 1, PolicyAssignmentCommand::AUTHORITY, 'staff:9', 'policy.assignment.read', 1, PolicyAssignmentCommand::make_target( $c['scope_type'], $c['scope_id'], $offer ), 'private-read' );
	}
	private function reader( OperationIdentity $identity, mixed $factory = null ): PolicyAssignmentCurrentService {
		return new PolicyAssignmentCurrentService( $factory ?? $this->f->factory, $this->f->store, $this->f->catalog( $identity ),
			new EffectiveConfigurationResolver( new InMemoryScopedConfigurationRepository(), new EffectiveConfigurationValidator() ), [ $this->f, 'authorize' ], new PolicyAssignmentUnitReadiness() );
	}
	private function assignment_rows(): array { return array_values( array_filter( $this->f->rows( 'options' ), static fn( array $row ): bool => str_starts_with( $row['option_name'], PolicyAssignmentStore::PREFIX ) ) ); }
	private function refused_read( \Closure $run ): void { try { $run(); self::fail( 'An unconfirmed or invalid current observation escaped.' ); } catch ( \RuntimeException $error ) { self::assertNotSame( '', $error->getMessage() ); } }

	public function test_forged_positive_actor_is_refused_before_any_sql_or_reservation(): void {
		$payload = $this->payload(); $payload['actor_user_id'] = 10; $opens = $this->f->factory->opens; $events = $this->f->count( 'operation_changes' );
		$result = $this->f->attempt( $payload ); self::assertSame( 'rejected', $result->outcome->state ); self::assertSame( $opens, $this->f->factory->opens );
		self::assertSame( [], $this->assignment_rows() ); self::assertSame( $events, $this->f->count( 'operation_changes' ) );
	}

	#[DataProvider( 'untrusted_grants' )]
	public function test_only_strict_true_grant_can_open_a_write_owner( mixed $grant ): void {
		$payload = $this->payload(); $this->f->allowed = $grant; $opens = $this->f->factory->opens;
		self::assertSame( 'rejected', $this->f->attempt( $payload )->outcome->state ); self::assertSame( $opens, $this->f->factory->opens ); self::assertSame( [], $this->assignment_rows() );
	}
	public static function untrusted_grants(): array { return [ 'false' => [ false ], 'integer_true' => [ 1 ], 'string_true' => [ '1' ], 'array' => [ [ true ] ], 'object' => [ new \stdClass() ] ]; }

	#[DataProvider( 'wrong_identities' )]
	public function test_foreign_identity_cannot_bind_a_valid_review( string $which ): void {
		$payload = $this->payload(); $normal = $this->f->identity( $payload );
		$identity = new OperationIdentity( 'site' === $which ? 2 : 1, 'authority' === $which ? 'return_policy.configuration' : $normal->authority,
			'principal' === $which ? 'staff:10' : $normal->principal, 'operation' === $which ? 'policy.assignment.read' : $normal->operation,
			'version' === $which ? 2 : 1, 'target' === $which ? PolicyAssignmentCommand::make_target( 'global', 0, 20 ) : $normal->target_key, 'original' );
		$opens = $this->f->factory->opens; $result = $this->f->service( $identity )->attempt( $identity, $payload, RequestContext::create() );
		self::assertSame( 'rejected', $result->outcome->state ); self::assertSame( $opens, $this->f->factory->opens ); self::assertSame( [], $this->assignment_rows() );
	}
	public static function wrong_identities(): array { return array_map( static fn( string $value ): array => [ $value ], [ 'site', 'authority', 'principal', 'operation', 'version', 'target' ] ); }

	#[DataProvider( 'witness_mutations' )]
	public function test_every_reviewed_current_field_is_authoritative_before_effect( string $field, mixed $value ): void {
		$payload = $this->payload(); $payload['reviewed']['return_policy_v1'][$field] = $value; $events = $this->f->count( 'operation_changes' );
		self::assertSame( 'rejected', $this->f->attempt( $payload )->outcome->state ); self::assertSame( [], $this->assignment_rows() ); self::assertSame( $events, $this->f->count( 'operation_changes' ) );
	}
	public static function witness_mutations(): array { return [ 'logical' => [ 'policy_uuid', Fixture::uuid( 102 ) ], 'version' => [ 'version_uuid', Fixture::uuid( 2 ) ], 'logical_revision' => [ 'logical_revision', 991 ], 'version_revision' => [ 'version_revision', 992 ], 'guard_revision' => [ 'guard_revision', 993 ], 'content_hash' => [ 'content_hash', str_repeat( 'a', 64 ) ] ]; }

	public function test_cross_family_uuid_and_genuine_foreign_witness_do_not_relabel_a_policy(): void {
		$payload = $this->payload(); $payload['instructions']['return_policy_v1']['policy_uuid'] = Fixture::uuid( 102 ); $payload['reviewed']['return_policy_v1'] = $payload['reviewed']['refund_policy_v1'];
		self::assertSame( 'rejected', $this->f->attempt( $payload )->outcome->state ); self::assertSame( [], $this->assignment_rows() );
	}

	public function test_same_token_with_different_other_family_instruction_cannot_overwrite_the_original(): void {
		$original = $this->payload(); $identity = $this->f->identity( $original ); $service = $this->f->service( $identity );
		self::assertSame( 'accepted', $service->attempt( $identity, $original, RequestContext::create() )->outcome->state ); $rows = $this->assignment_rows(); $events = $this->f->count( 'operation_changes' );
		$changed = $original; $changed['instructions']['refund_policy_v1'] = [ 'mode' => 'disabled' ]; $changed['reviewed']['refund_policy_v1'] = null;
		self::assertContains( $service->attempt( $identity, $changed, RequestContext::create() )->outcome->state, [ 'rejected', 'unconfirmed' ] ); self::assertSame( $rows, $this->assignment_rows() ); self::assertSame( $events, $this->f->count( 'operation_changes' ) );
	}

	#[DataProvider( 'malformed_rows' )]
	public function test_malformed_persisted_state_never_turns_into_inherited_terms( string $mutation ): void {
		self::assertSame( 'accepted', $this->f->attempt( $this->payload() )->outcome->state ); $row = $this->assignment_rows()[0]; $data = json_decode( $row['option_value'], true, 16, JSON_THROW_ON_ERROR );
		if ( 'format' === $mutation ) { $data['format'] = 2; }
		elseif ( 'family' === $mutation ) { $map = json_decode( $data['return_map'], true, 16, JSON_THROW_ON_ERROR ); $map['family'] = 'refund_policy_v1'; $data['return_map'] = json_encode( $map, JSON_THROW_ON_ERROR ); }
		elseif ( 'site' === $mutation ) { $data['site_id'] = 2; }
		elseif ( 'parent' === $mutation ) { $data['parent_id'] = 77; }
		else { $data['unexpected_private_field'] = 'PRIVATE_PACKET'; }
		$bytes = json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$statement = $this->f->factory->inspection()->prepare( 'UPDATE ' . $this->f->table( 'options' ) . ' SET option_value=? WHERE option_id=?' ); $statement->execute( [ $bytes, $row['option_id'] ] );
		$identity = $this->read_identity(); $events = $this->f->count( 'operation_changes' ); $this->refused_read( fn() => $this->reader( $identity )->capture( $identity, PolicyAssignmentState::make_coordinate( 1, 'global', 0 ), 20 ) );
		self::assertSame( $bytes, $this->assignment_rows()[0]['option_value'] ); self::assertSame( $events, $this->f->count( 'operation_changes' ) );
	}
	public static function malformed_rows(): array { return array_map( static fn( string $v ): array => [ $v ], [ 'format', 'family', 'site', 'parent', 'extra' ] ); }

	public function test_original_history_reconciliation_does_not_repair_or_require_current_option_bytes(): void {
		$original = $this->payload(); $identity = $this->f->identity( $original ); $service = $this->f->service( $identity ); $first = $service->attempt( $identity, $original, RequestContext::create() ); self::assertSame( 'accepted', $first->outcome->state );
		$row = $this->assignment_rows()[0]; $this->f->factory->inspection()->exec( 'UPDATE ' . $this->f->table( 'options' ) . " SET option_value='UNKNOWN_FUTURE_BYTES' WHERE option_id=" . (int) $row['option_id'] ); $events = $this->f->count( 'operation_changes' );
		$result = $service->reconcile( $identity, $original, RequestContext::create() ); self::assertSame( 'accepted', $result->outcome->state ); self::assertTrue( $result->replayed ); self::assertSame( $first->completion?->to_json(), $result->completion?->to_json() );
		self::assertSame( 'UNKNOWN_FUTURE_BYTES', $this->assignment_rows()[0]['option_value'] ); self::assertSame( $events, $this->f->count( 'operation_changes' ) );
	}

	public function test_publication_invalidation_cannot_republish_old_body_over_a_newer_assignment(): void {
		$original = $this->payload(); $identity = $this->f->identity( $original ); $f = $this->f; $called = false; $names = [];
		$store = new PolicyAssignmentStore( 1, PolicyAssignmentUnitFactory::PREFIX, static function( string $name, string $group ) use ( $f, &$called, &$names ): bool {
			$names[] = [ $name, $group ];
			foreach ( $f->factory->sessions as $session ) { self::assertTrue( $session->is_retired() ); }
			if ( ! $called ) { $called = true; $next = $f->payload( instructions: [ 'return_policy_v1' => [ 'mode' => 'disabled' ], 'refund_policy_v1' => [ 'mode' => 'assigned', 'policy_uuid' => Fixture::uuid( 102 ) ] ] ); self::assertSame( 'accepted', $f->attempt( $next, 'newer-cache-callback' )->outcome->state ); }
			return true;
		} );
		$service = new PolicyAssignmentService( $f->factory, $store, $f->catalog( $identity ), [ $f, 'authorize' ], new PolicyAssignmentUnitReadiness() );
		$accepted = $service->attempt( $identity, $original, RequestContext::create() ); self::assertSame( 'accepted', $accepted->outcome->state );
		self::assertSame( 2, $f->state()->revision() ); self::assertSame( [ 'mode' => 'disabled' ], $f->state()->map( 'return_policy_v1' )->facts()['default'] ); self::assertCount( 3, $names );
		self::assertSame( [ $store->name( $original['coordinate'] ), 'alloptions', 'notoptions' ], array_column( $names, 0 ) ); self::assertSame( [ 'options', 'options', 'options' ], array_column( $names, 1 ) );
		$rows = $this->assignment_rows(); self::assertSame( 'accepted', $service->reconcile( $identity, $original, RequestContext::create() )->outcome->state ); self::assertSame( $rows, $this->assignment_rows() );
	}

	public function test_grant_loss_during_retired_owner_publication_hides_the_completion(): void {
		$original = $this->payload(); $identity = $this->f->identity( $original ); $f = $this->f;
		$store = new PolicyAssignmentStore( 1, PolicyAssignmentUnitFactory::PREFIX, static function() use ( $f ): bool { $f->allowed = false; return true; } );
		$service = new PolicyAssignmentService( $f->factory, $store, $f->catalog( $identity ), [ $f, 'authorize' ], new PolicyAssignmentUnitReadiness() ); $result = $service->attempt( $identity, $original, RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state ); self::assertNull( $result->completion ); self::assertSame( 1, $f->state()->revision() );
		foreach ( $f->factory->sessions as $session ) { self::assertTrue( $session->is_retired() ); }
	}

	#[DataProvider( 'read_owner_faults' )]
	public function test_read_requires_rollback_retirement_and_final_owner_metadata_acknowledgement( string $fault ): void {
		$identity = $this->read_identity(); $factory = new PolicyAssignmentReadFenceFactory( $this->f->factory, $fault );
		$this->refused_read( fn() => $this->reader( $identity, $factory )->read( $identity, PolicyAssignmentState::make_coordinate( 1, 'global', 0 ) ) );
		self::assertGreaterThanOrEqual( 1, $factory->rollback_calls ); self::assertGreaterThanOrEqual( 1, $factory->retire_calls ); self::assertTrue( $factory->inner->is_retired() ); self::assertSame( [], $this->assignment_rows() );
	}
	public static function read_owner_faults(): array { return array_map( static fn( string $v ): array => [ $v ], [ 'rollback_false', 'rollback_throw', 'retire_false', 'retire_throw', 'metadata_throws_after_begin', 'prefix_drift', 'late_site_throw' ] ); }

	public function test_fresh_read_grant_loss_after_retirement_blocks_disclosure_and_leaves_rows_unchanged(): void {
		self::assertSame( 'accepted', $this->f->attempt( $this->payload() )->outcome->state ); $identity = $this->read_identity(); $f = $this->f; $opened = $f->factory->opens; $rows = $this->assignment_rows();
		$f->on_authorize = static function() use ( $f, $opened ): void {
			if ( $f->factory->opens <= $opened ) { return; }
			foreach ( $f->factory->sessions as $session ) { if ( ! $session->is_retired() ) { return; } }
			$f->allowed = false;
		};
		$this->refused_read( fn() => $this->reader( $identity )->read( $identity, PolicyAssignmentState::make_coordinate( 1, 'global', 0 ) ) ); self::assertSame( $rows, $this->assignment_rows() );
	}

	public function test_backward_sql_clock_cannot_write_a_new_revision_before_the_original_change(): void {
		$this->f->factory->clock = '2026-10-24 10:00:00.000010'; self::assertSame( 'accepted', $this->f->attempt( $this->payload(), 'first' )->outcome->state );
		$rows = $this->assignment_rows(); $events = $this->f->count( 'operation_changes' );
		$next = $this->f->payload( instructions: [ 'return_policy_v1' => [ 'mode' => 'disabled' ], 'refund_policy_v1' => [ 'mode' => 'disabled' ] ] );
		$this->f->factory->clock = '2026-10-24 10:00:00.000005';
		self::assertContains( $this->f->attempt( $next, 'backward-clock' )->outcome->state, [ 'rejected', 'unconfirmed' ] );
		self::assertSame( $rows, $this->assignment_rows() ); self::assertSame( $events, $this->f->count( 'operation_changes' ) );
	}

	#[DataProvider( 'read_paths' )]
	public function test_read_clock_before_saved_assignment_refuses_instead_of_showing_future_terms( string $path ): void {
		$this->f->factory->clock = '2026-10-24 10:00:00.000010'; self::assertSame( 'accepted', $this->f->attempt( $this->payload(), 'first' )->outcome->state ); $rows = $this->assignment_rows();
		$this->f->factory->clock = '2026-10-24 10:00:00.000005'; $coordinate = PolicyAssignmentState::make_coordinate( 1, 'global', 0 ); $identity = $this->read_identity( $coordinate, 'capture' === $path ? 20 : 0 );
		$this->refused_read( fn() => 'capture' === $path ? $this->reader( $identity )->capture( $identity, $coordinate, 20 ) : $this->reader( $identity )->read( $identity, $coordinate ) );
		self::assertSame( $rows, $this->assignment_rows() ); foreach ( $this->f->factory->sessions as $session ) { self::assertTrue( $session->is_retired() ); }
	}
	public static function read_paths(): array { return [ 'read' => [ 'read' ], 'capture' => [ 'capture' ] ]; }

	/** Capture the actual native adapter, rather than the deliberately broad unit host grant. */
	private function native_variation_context( string $operation ): array {
		$GLOBALS['blog_id'] = 1; $GLOBALS['cetech_de_test_is_admin'] = true; $GLOBALS['cetech_de_test_user_id'] = 9;
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true, 'manage_site_wide_defaults' => true ];
		$GLOBALS['cetech_de_test_edit_posts'] = [ 100 => true, 101 => true, 200 => true ]; AdminPageAccess::bind( null );
		$native = new PolicyAssignmentAdminFixture();
		$native->products = [ 100 => [ 'parent_id' => 0, 'is_variation' => false, 'label' => 'Original parent' ],
			101 => [ 'parent_id' => 100, 'is_variation' => true, 'label' => 'Actual owned variation' ],
			200 => [ 'parent_id' => 0, 'is_variation' => false, 'label' => 'Other parent' ] ];
		$coordinate = $native->authority->target( 'variation', 101 );
		$identity = $native->authority->identity( $native->authority->identity_data( $operation, $coordinate, 7, 'native-context-original' ) );
		$grant = $native->authority->capture( $identity, $coordinate, 7 );
		return [ $native, $coordinate, $identity, $grant ];
	}

	public function test_actual_native_write_grant_cannot_be_reused_with_a_forged_variation_parent(): void {
		[ $native, $coordinate, $identity, $grant ] = $this->native_variation_context( PolicyAssignmentCommand::OPERATION );
		try {
			self::assertSame( 100, $coordinate['parent_id'] );
			$payload = $native->core->payload( $coordinate, 7, [ 'return_policy_v1' => [ 'mode' => 'disabled' ], 'refund_policy_v1' => [ 'mode' => 'disabled' ] ] );
			$forged = $payload; $forged['coordinate']['parent_id'] = 200;
			self::assertSame( $identity->target_key, PolicyAssignmentCommand::make_target( 'variation', 101, 7 ) );
			$service = new PolicyAssignmentService( $native->core->factory, $native->core->store,
				new PolicyCurrentCatalog( $grant['return_family'], $grant['refund_family'], $identity ), $grant['authorize'], new PolicyAssignmentUnitReadiness() );
			$opens = $native->core->factory->opens; $records = $native->core->rows( 'operation_records' ); $options = $native->core->rows( 'options' );
			$result = $service->attempt( $identity, $forged, RequestContext::create() );
			self::assertSame( 'rejected', $result->outcome->state ); self::assertSame( 'not_authorized', $result->outcome->error?->code );
			self::assertSame( $opens, $native->core->factory->opens ); self::assertSame( $records, $native->core->rows( 'operation_records' ) ); self::assertSame( $options, $native->core->rows( 'options' ) );
			self::assertSame( [], $native->core->rows( 'operation_changes' ) );
			self::assertSame( 'accepted', $service->attempt( $identity, $payload, RequestContext::create() )->outcome->state );
			self::assertSame( $coordinate, $native->core->state( $coordinate )->coordinate() ); self::assertSame( 1, $native->core->state( $coordinate )->revision() );
		} finally { $native->close(); }
	}

	#[DataProvider( 'read_paths' )]
	public function test_actual_native_read_grant_cannot_be_reused_for_another_variation_parent( string $path ): void {
		[ $native, $coordinate, $identity, $grant ] = $this->native_variation_context( 'policy.assignment.read' );
		try {
			$forged = $coordinate; $forged['parent_id'] = 200;
			$reader = new PolicyAssignmentCurrentService( $native->core->factory, $native->core->store,
				new PolicyCurrentCatalog( $grant['return_family'], $grant['refund_family'], $identity ),
				new EffectiveConfigurationResolver( $native->configuration, new EffectiveConfigurationValidator() ), $grant['authorize'], new PolicyAssignmentUnitReadiness() );
			$opens = $native->core->factory->opens; $records = $native->core->rows( 'operation_records' ); $options = $native->core->rows( 'options' );
			$this->refused_read( fn() => 'capture' === $path ? $reader->capture( $identity, $forged, 7 ) : $reader->read( $identity, $forged, 7 ) );
			self::assertSame( $opens, $native->core->factory->opens ); self::assertSame( $records, $native->core->rows( 'operation_records' ) ); self::assertSame( $options, $native->core->rows( 'options' ) );
			if ( 'capture' === $path ) {
				$valid = $reader->capture( $identity, $coordinate, 7 ); self::assertCount( 3, $valid['states'] );
				self::assertSame( 'not_assigned', $valid['selections']['return_policy_v1']->disposition() ); self::assertSame( 'not_assigned', $valid['selections']['refund_policy_v1']->disposition() );
			} else { self::assertSame( $coordinate, $reader->read( $identity, $coordinate, 7 )->coordinate() ); }
		} finally { $native->close(); }
	}
}
