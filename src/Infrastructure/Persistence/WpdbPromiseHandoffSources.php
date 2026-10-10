<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseJson;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseEffectiveAssignment, PromiseSiteBinding, PromiseStoredAssignment, PromiseStoredObject, PromiseStoredVersion};

/** P02 source checks shared by outside-quote capture and the final existing-owner fence. */
final readonly class WpdbPromiseHandoffSources {
	public function __construct( private OperationSession $session, private PromiseSiteBinding $binding ) {}
	/**
	 * Materialize the complete census and groups in one synchronous, read-only unit.
	 * The memo belongs to this call, never to this reusable source object/session:
	 * OperationSession deliberately exposes no identity for a later transaction.
	 */
	public function prime( QuoteContext $base, array $services, RuleTime $at ): array {
		$this->binding->assert_session( $this->session );
		if ( $this->session->is_retired() || ! $this->session->in_transaction() ) { throw new OperationStorageException(); }
		$session = self::scoped_reads( $this->session ); $sources = new self( $session, $this->binding );
		$repository = new WpdbPromiseRepository( $session, $this->binding );
		$sources->prime_census( $base, $services, $at, $repository ); $groups = [];
		foreach ( $services as $component => $service ) { $groups[$component] = $sources->group_with_repository( $base, $component, $service, $at, $repository ); }
		// Always touch the actual pinned owner after the memoized reads. A dead or
		// lost native transaction must not be hidden by a final cache hit.
		$owner = $this->session->get_row( 'SELECT 1 AS promise_source_owner' );
		if ( ! is_array( $owner ) || '1' !== (string) ( $owner['promise_source_owner'] ?? '' ) || $this->session->is_retired() || ! $this->session->in_transaction() ) { throw new OperationStorageException(); }
		return $groups;
	}
	/** Acquire the complete census in one order before group reads can interleave locks. */
	private function prime_census( QuoteContext $base, array $services, RuleTime $at, WpdbPromiseRepository $repository ): void {
		$this->binding->assert_session( $this->session ); if ( ! $this->session->in_transaction() ) { throw new OperationStorageException(); }
		$isolation = $this->session->get_row( 'SELECT @@SESSION.tx_isolation AS isolation_level, @@SESSION.innodb_lock_wait_timeout AS lock_wait_seconds' );
		if ( ! is_array( $isolation ) || 'REPEATABLE-READ' !== strtoupper( (string) ( $isolation['isolation_level'] ?? '' ) ) || '2' !== (string) ( $isolation['lock_wait_seconds'] ?? '' ) ) { throw new OperationStorageException(); }
		$all = [];
		foreach ( $services as $component => $service ) { foreach ( self::keys( $base, $component, $service, $this->binding ) as $key ) { $all[PromiseStoredAssignment::key_digest( $key )] = $key; } } ksort( $all, SORT_STRING ); $decisions = [];
		foreach ( $all as $digest => $key ) { $decisions[$digest] = $this->decision( $key, $repository ); }
		$policies = [];
		foreach ( $services as $component => $service ) {
			foreach ( $base->private_facts()['lines'] as $line ) {
				if ( $component !== $line['component_key'] ) { continue; }
				$scopes = null === $line['variation_id'] ? [ [ 'product', $line['product_id'] ], [ 'global', 0 ] ] : [ [ 'variation', $line['variation_id'] ], [ 'product', $line['product_id'] ], [ 'global', 0 ] ];
				foreach ( $scopes as [ $kind, $id ] ) { $key = [ 'site_id' => $this->binding->site_id(), 'site_key' => $this->binding->site_key(), 'scope_kind' => $kind, 'scope_id' => $id ] + $service; $row = $decisions[PromiseStoredAssignment::key_digest( $key )]; if ( null === $row || 'inherit' === $row->state() ) { continue; } if ( 'disabled' === $row->state() ) { throw new OperationStorageException(); } $reference = $row->reference(); $policies[$reference->policy_id() . ':' . $reference->version()] = $reference; break; }
			}
		}
		ksort( $policies, SORT_STRING ); $calendars = [];
		foreach ( $policies as $reference ) {
			$head = $repository->lock_object( 'policy', $reference->policy_id() ); $row = $repository->lock_version( 'policy', $reference->policy_id(), $reference->version() ); if ( null === $head || null === $row ) { throw new OperationStorageException(); }
			$policy = PromiseStoredVersion::from_row( $row, $this->binding )->body(); foreach ( $policy->calendars() as $calendar ) { $calendars[$calendar->calendar_id() . ':' . $calendar->version()] = $calendar; }
		}
		ksort( $calendars, SORT_STRING );
		foreach ( $calendars as $reference ) { if ( null === $repository->lock_object( 'calendar', $reference->calendar_id() ) || null === $repository->lock_version( 'calendar', $reference->calendar_id(), $reference->version() ) ) { throw new OperationStorageException(); } }
	}
	/** Derive scope identity from native lines; no submitted scope can pick the policy. */
	public static function keys( QuoteContext $base, string $component, array $service_endpoint, PromiseSiteBinding $binding ): array {
		$keys = [ 'global:0' => [ 'scope_kind' => 'global', 'scope_id' => 0 ] ]; $found = false;
		foreach ( $base->private_facts()['lines'] as $line ) {
			if ( $component !== $line['component_key'] ) { continue; } $found = true;
			$keys['product:' . $line['product_id']] = [ 'scope_kind' => 'product', 'scope_id' => $line['product_id'] ];
			if ( null !== $line['variation_id'] ) { $keys['variation:' . $line['variation_id']] = [ 'scope_kind' => 'variation', 'scope_id' => $line['variation_id'] ]; }
		}
		if ( ! $found ) { throw new OperationStorageException(); } ksort( $keys, SORT_STRING );
		return array_values( array_map( static fn( array $scope ): array => [ 'site_id' => $binding->site_id(), 'site_key' => $binding->site_key() ] + $scope + $service_endpoint, $keys ) );
	}
	/** Every relevant absence/row is hashed, including lower-priority decisions. */
	public function group( QuoteContext $base, string $component, array $service_endpoint, RuleTime $at ): array {
		return $this->group_with_repository( $base, $component, $service_endpoint, $at, new WpdbPromiseRepository( $this->session, $this->binding ) );
	}
	private function group_with_repository( QuoteContext $base, string $component, array $service_endpoint, RuleTime $at, WpdbPromiseRepository $repository ): array {
		$keys = self::keys( $base, $component, $service_endpoint, $this->binding ); $decisions = []; $rows = []; $selected = null;
		foreach ( $keys as $key ) {
			$stored = $this->decision( $key, $repository ); $rows[$key['scope_kind'] . ':' . $key['scope_id']] = $stored;
			$decisions[] = [ 'key' => $key, 'row' => $stored?->row() ];
		}
		foreach ( $base->private_facts()['lines'] as $line ) {
			if ( $component !== $line['component_key'] ) { continue; }
			$priority = null === $line['variation_id'] ? [ 'product:' . $line['product_id'], 'global:0' ] : [ 'variation:' . $line['variation_id'], 'product:' . $line['product_id'], 'global:0' ]; $choice = null;
			foreach ( $priority as $scope ) { $row = $rows[$scope]; if ( null === $row || 'inherit' === $row->state() ) { continue; } if ( 'disabled' === $row->state() ) { throw new OperationStorageException(); } $choice = $row; break; }
			if ( null === $choice ) { throw new OperationStorageException(); }
			if ( null !== $selected && $selected->reference()->to_private_json() !== $choice->reference()->to_private_json() ) { throw new OperationStorageException(); } $selected ??= $choice;
		}
		$key = array_intersect_key( $selected->row(), array_flip( PromiseStoredAssignment::KEY_FIELDS ) ); $effective = $this->assignment_with_repository( $key, $at, $repository );
		if ( 'assigned' !== $effective->state() ) { throw new OperationStorageException(); }
		return [ 'assignment_receipt_digest' => hash( 'sha256', 'cetech-promise-effective-assignment-v1:' . PromiseJson::encode( [ 'decisions' => $decisions ] ) ), 'effective' => $effective, 'valid_until' => $this->valid_until( $effective, $repository ) ];
	}
	/** Detached time bound from the exact already acknowledged and held source rows. */
	private function valid_until( PromiseEffectiveAssignment $effective, WpdbPromiseRepository $repository ): ?RuleTime {
		$reference = $effective->policy()->reference();
		$references = [ [ 'policy', $reference->policy_id(), $reference->version() ] ]; foreach ( $effective->policy()->calendars() as $calendar ) { $references[] = [ 'calendar', $calendar->calendar_id(), $calendar->version() ]; }
		$until = null;
		foreach ( $references as [ $kind, $id, $version ] ) {
			$row = $repository->lock_version( $kind, $id, $version ); if ( null === $row ) { throw new OperationStorageException(); } $stored = PromiseStoredVersion::from_row( $row, $this->binding );
			if ( ! $stored->eligible_at( $effective->captured_at() ) ) { throw new OperationStorageException(); }
			if ( null !== $row['declared_until'] ) { $bound = RuleTime::parse( $row['declared_until'] ); if ( null === $until || $bound->compare( $until ) < 0 ) { $until = $bound; } }
		}
		return $until;
	}
	private function decision( array $key, WpdbPromiseRepository $repository ): ?PromiseStoredAssignment {
		$this->binding->assert_session( $this->session ); if ( ! $this->session->in_transaction() ) { throw new OperationStorageException(); }
		$row = $repository->lock_assignment( $key );
		if ( null === $row ) { return null; } $assignment = PromiseStoredAssignment::from_row( $row, $this->binding ); $repository->accepted_receipt( $assignment->source_receipt(), $row ); return $assignment;
	}
	public function assignment( array $key, RuleTime $at ): PromiseEffectiveAssignment {
		return $this->assignment_with_repository( $key, $at, new WpdbPromiseRepository( $this->session, $this->binding ) );
	}
	private function assignment_with_repository( array $key, RuleTime $at, WpdbPromiseRepository $repository ): PromiseEffectiveAssignment {
		$this->binding->assert_session( $this->session );
		if ( ! $this->session->in_transaction() ) { throw new OperationStorageException(); }
		$row = $repository->lock_assignment( $key );
		if ( null === $row ) { return new PromiseEffectiveAssignment( 'absent', null, null, [], $at ); }
		$assignment = PromiseStoredAssignment::from_row( $row, $this->binding ); $repository->accepted_receipt( $assignment->source_receipt(), $row );
		if ( 'assigned' !== $assignment->state() ) { return new PromiseEffectiveAssignment( $assignment->state(), $assignment, null, [], $at ); }
		$reference = $assignment->reference();
		$head = $repository->lock_object( 'policy', $reference->policy_id() ); $version_row = $repository->lock_version( 'policy', $reference->policy_id(), $reference->version() );
		if ( null === $head || null === $version_row ) { throw new OperationStorageException(); }
		$repository->assert_object_ack( $head ); $version = PromiseStoredVersion::from_row( $version_row, $this->binding ); $version->assert_parent( PromiseStoredObject::from_row( $head, $this->binding ) ); $assignment->assert_policy( $version );
		if ( $head['published_version_id'] !== $version->id() || ! $version->eligible_at( $at ) ) { return new PromiseEffectiveAssignment( 'unavailable', $assignment, null, [], $at ); }
		$policy = $repository->load_policy_versions( [ $reference ] )[0];
		$calendars = $repository->load_calendar_versions( $policy->calendars() );
		foreach ( $policy->calendars() as $calendar_reference ) {
			$calendar_head = $repository->lock_object( 'calendar', $calendar_reference->calendar_id() ); $calendar_row = $repository->lock_version( 'calendar', $calendar_reference->calendar_id(), $calendar_reference->version() );
			if ( null === $calendar_head || null === $calendar_row ) { throw new OperationStorageException(); }
			$repository->assert_object_ack( $calendar_head ); $calendar_version = PromiseStoredVersion::from_row( $calendar_row, $this->binding ); $calendar_version->assert_parent( PromiseStoredObject::from_row( $calendar_head, $this->binding ) );
			if ( $calendar_head['published_version_id'] !== $calendar_version->id() || ! $calendar_version->eligible_at( $at ) ) { return new PromiseEffectiveAssignment( 'unavailable', $assignment, null, [], $at ); }
		}
		return new PromiseEffectiveAssignment( 'assigned', $assignment, $policy, $calendars, $at );
	}
	/** No cached reads, repository or session wrapper can escape prime(). */
	private static function scoped_reads( OperationSession $owner ): OperationSession {
		$tables = [];
		foreach ( [ 'promise_objects', 'promise_versions', 'promise_assignments', 'operation_records', 'operation_changes' ] as $suffix ) { $tables[] = WpdbOperationRecordRepository::table_name( $owner, $suffix ); }
		return new class( $owner, $tables ) implements OperationSession {
			private array $reads = [];
			public function __construct( private readonly OperationSession $owner, private readonly array $tables ) {}
			public function site_id(): int { return $this->owner->site_id(); }
			public function table_prefix(): string { return $this->owner->table_prefix(); }
			public function charset_collate(): string { return $this->owner->charset_collate(); }
			public function begin(): bool { throw new OperationStorageException(); }
			public function commit(): OperationCommitResult { throw new OperationStorageException(); }
			public function rollback(): bool { throw new OperationStorageException(); }
			public function retire(): bool { throw new OperationStorageException(); }
			public function is_retired(): bool { return $this->owner->is_retired(); }
			public function in_transaction(): bool { return $this->owner->in_transaction(); }
			public function validate_tables( array $table_names ): bool { $this->assert_owned(); return $this->owner->validate_tables( $table_names ); }
			public function query( string $sql ): int|false { throw new OperationStorageException(); }
			public function get_row( string $sql ): array|null|false { $this->assert_owned(); return $this->owner->get_row( $sql ); }
			public function get_results( string $sql ): array|false {
				$this->assert_owned(); $cacheable = false;
				if ( str_starts_with( $sql, 'SELECT ' ) ) { foreach ( $this->tables as $table ) { if ( str_contains( $sql, ' FROM `' . $table . '` ' ) ) { $cacheable = true; break; } } }
				if ( $cacheable && array_key_exists( $sql, $this->reads ) ) { return $this->reads[$sql]; }
				$rows = $this->owner->get_results( $sql );
				if ( $cacheable && false !== $rows ) { $this->assert_owned(); $this->reads[$sql] = $rows; }
				return $rows;
			}
			public function prepare( string $sql, mixed ...$args ): string { $this->assert_owned(); return $this->owner->prepare( $sql, ...$args ); }
			public function errno(): int { return $this->owner->errno(); }
			public function insert_id(): int { return $this->owner->insert_id(); }
			private function assert_owned(): void { if ( $this->owner->is_retired() || ! $this->owner->in_transaction() ) { throw new OperationStorageException(); } }
		};
	}
}
