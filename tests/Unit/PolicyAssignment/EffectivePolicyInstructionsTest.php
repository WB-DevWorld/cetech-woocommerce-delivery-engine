<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\PolicyAssignment;

use CetechDeliveryEngine\Application\Configuration\{EffectiveConfigurationResolver, EffectiveConfigurationValidator};
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentMap, PolicyAssignmentState};
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Policy layering belongs to ECR; these detached proposals grant no current/customer authority. */
final class EffectivePolicyInstructionsTest extends TestCase {
	private const RETURN = 'return_policy_v1';
	private const REFUND = 'refund_policy_v1';
	private EffectiveConfigurationResolver $resolver;
	private InMemoryScopedConfigurationRepository $repository;

	protected function setUp(): void {
		$this->repository = new InMemoryScopedConfigurationRepository();
		$this->resolver = new EffectiveConfigurationResolver( $this->repository, new EffectiveConfigurationValidator() );
	}

	public function test_all_absent_scopes_leave_each_family_explicitly_not_assigned(): void {
		$states = $this->absent_chain();
		$result = $this->resolve( $states );
		self::assertSame( [ self::RETURN => [ 'mode' => 'inherit' ], self::REFUND => [ 'mode' => 'inherit' ] ], $result['instructions'] );
		self::assertSame( [ self::RETURN => [], self::REFUND => [] ], $result['provenance'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/D', $result['fingerprint'] );
	}

	public function test_two_independent_root_defaults_survive_absent_product_and_variation(): void {
		$states = $this->absent_chain();
		$states[0] = $this->state( $states[0], [ self::RETURN => [ 0 => $this->assigned( 1 ) ], self::REFUND => [ 0 => $this->assigned( 2 ) ] ] );
		$result = $this->resolve( $states );
		self::assertSame( $this->assigned( 1 ), $result['instructions'][self::RETURN] );
		self::assertSame( $this->assigned( 2 ), $result['instructions'][self::REFUND] );
		self::assertSame( 'global', $result['provenance'][self::RETURN]['scope_type'] );
		self::assertSame( 'default', $result['provenance'][self::RETURN]['slot'] );
	}

	public function test_real_offer_override_is_independent_of_the_other_family_and_other_offer(): void {
		$states = $this->absent_chain();
		$states[0] = $this->state( $states[0], [ self::RETURN => [ 0 => $this->assigned( 1 ), 42 => $this->assigned( 3 ) ], self::REFUND => [ 0 => $this->assigned( 2 ) ] ] );
		$a = $this->resolve( $states, 42 ); $b = $this->resolve( $states, 43 );
		self::assertSame( $this->assigned( 3 ), $a['instructions'][self::RETURN] );
		self::assertSame( $this->assigned( 1 ), $b['instructions'][self::RETURN] );
		self::assertSame( $a['instructions'][self::REFUND], $b['instructions'][self::REFUND] );
		self::assertSame( 42, $a['provenance'][self::RETURN]['offer_id'] );
		self::assertSame( 'offer', $a['provenance'][self::RETURN]['slot'] );
		self::assertNotSame( $a['fingerprint'], $b['fingerprint'] );
	}

	public function test_same_scope_offer_inherit_uses_that_scopes_default(): void {
		$states = $this->absent_chain();
		$states[0] = $this->state( $states[0], [ self::RETURN => [ 0 => $this->assigned( 1 ), 42 => [ 'mode' => 'inherit' ] ] ] );
		$result = $this->resolve( $states );
		self::assertSame( $this->assigned( 1 ), $result['instructions'][self::RETURN] );
		self::assertSame( 'default', $result['provenance'][self::RETURN]['slot'] );
	}

	public function test_product_default_is_more_specific_than_global_offer_without_changing_refund(): void {
		$states = $this->absent_chain();
		$states[0] = $this->state( $states[0], [ self::RETURN => [ 42 => $this->assigned( 1 ) ], self::REFUND => [ 0 => $this->assigned( 2 ) ] ] );
		$states[1] = $this->state( $states[1], [ self::RETURN => [ 0 => $this->assigned( 3 ) ] ] );
		$result = $this->resolve( $states );
		self::assertSame( $this->assigned( 3 ), $result['instructions'][self::RETURN] );
		self::assertSame( $this->assigned( 2 ), $result['instructions'][self::REFUND] );
		self::assertSame( 'product', $result['provenance'][self::RETURN]['scope_type'] );
	}

	public function test_product_inherit_preserves_the_root_offer_selection(): void {
		$states = $this->absent_chain();
		$states[0] = $this->state( $states[0], [ self::RETURN => [ 42 => $this->assigned( 1 ) ] ] );
		$states[1] = $this->state( $states[1], [ self::RETURN => [ 42 => [ 'mode' => 'inherit' ] ] ] );
		self::assertSame( $this->assigned( 1 ), $this->resolve( $states )['instructions'][self::RETURN] );
	}

	public function test_variation_can_explicitly_replace_an_ancestor_disable(): void {
		$states = $this->absent_chain();
		$states[1] = $this->state( $states[1], [ self::RETURN => [ 0 => [ 'mode' => 'disabled' ] ] ] );
		$states[2] = $this->state( $states[2], [ self::RETURN => [ 42 => $this->assigned( 4 ) ] ] );
		$result = $this->resolve( $states );
		self::assertSame( $this->assigned( 4 ), $result['instructions'][self::RETURN] );
		self::assertSame( 'variation', $result['provenance'][self::RETURN]['scope_type'] );
		self::assertSame( 100, $result['provenance'][self::RETURN]['parent_id'] );
	}

	public function test_explicit_variation_disable_keeps_refund_and_never_falls_back(): void {
		$states = $this->absent_chain();
		$states[0] = $this->state( $states[0], [ self::RETURN => [ 0 => $this->assigned( 1 ) ], self::REFUND => [ 0 => $this->assigned( 2 ) ] ] );
		$states[2] = $this->state( $states[2], [ self::RETURN => [ 0 => [ 'mode' => 'disabled' ] ] ] );
		$result = $this->resolve( $states );
		self::assertSame( [ 'mode' => 'disabled' ], $result['instructions'][self::RETURN] );
		self::assertSame( $this->assigned( 2 ), $result['instructions'][self::REFUND] );
	}

	#[DataProvider( 'invalid_chains' )]
	public function test_missing_extra_foreign_or_reordered_scope_chains_are_refused( string $case ): void {
		$states = $this->absent_chain();
		match ( $case ) {
			'missing' => array_pop( $states ),
			'extra' => $states[] = PolicyAssignmentState::absent( 1, 'product', 200 ),
			'reordered' => $states = [ $states[1], $states[0], $states[2] ],
			'site' => $states[1] = PolicyAssignmentState::absent( 2, 'product', 100 ),
			'parent' => $states[2] = PolicyAssignmentState::absent( 1, 'variation', 101, 200 ),
			'type' => $states[2] = new \stdClass(),
			'keys' => $states = [ 1 => $states[0], 2 => $states[1], 3 => $states[2] ],
		};
		$this->expectException( \InvalidArgumentException::class );
		$this->resolve( $states );
	}
	public static function invalid_chains(): array { return array_combine( $keys = [ 'missing', 'extra', 'reordered', 'site', 'parent', 'type', 'keys' ], array_map( static fn( $v ) => [ $v ], $keys ) ); }

	#[DataProvider( 'non_offer_ids' )]
	public function test_default_edit_slot_is_never_a_real_offer_preview( int $offer ): void {
		$this->expectException( \InvalidArgumentException::class ); $this->resolve( $this->absent_chain(), $offer );
	}
	public static function non_offer_ids(): array { return [ [ 0 ], [ -1 ] ]; }

	public function test_fingerprint_binds_physical_revision_generation_and_other_family(): void {
		$states = $this->absent_chain();
		$original = $this->resolve( $states )['fingerprint'];
		$states[0] = $this->state( $states[0], [], 7, 1, 90 );
		$first = $this->resolve( $states )['fingerprint']; self::assertNotSame( $original, $first );
		$states[0] = $this->state( $states[0], [], 8, 1, 90 );
		self::assertNotSame( $first, $this->resolve( $states )['fingerprint'] );
		$states[0] = $this->state( $states[0], [], 7, 2, 90 );
		self::assertNotSame( $first, $this->resolve( $states )['fingerprint'] );
		$states[0] = $this->state( $states[0], [], 7, 1, 91 );
		self::assertNotSame( $first, $this->resolve( $states )['fingerprint'] );
		$states[0] = $this->state( $states[0], [ self::REFUND => [ 99 => [ 'mode' => 'disabled' ] ] ], 7, 1, 90 );
		self::assertNotSame( $first, $this->resolve( $states )['fingerprint'] );
	}

	public function test_identical_detached_material_has_stable_fingerprint_and_no_repository_work(): void {
		$states = $this->absent_chain(); $before = $this->repository->getReadCallCounts();
		self::assertSame( $this->resolve( $states ), $this->resolve( $this->absent_chain() ) );
		self::assertSame( $before, $this->repository->getReadCallCounts() ); self::assertSame( 0, $this->repository->getWriteCalls() );
	}

	public function test_policy_resolution_preserves_existing_delivery_result_and_memo(): void {
		$this->repository->ensureGlobalScope();
		$before = $this->resolver->resolve( new EffectiveConfigurationRequest( 100, 101, 'in_store', 100 ) );
		$reads = $this->repository->getReadCallCounts(); $writes = $this->repository->getWriteCalls();
		$states = $this->absent_chain();
		$states[0] = $this->state( $states[0], [ self::RETURN => [ 42 => $this->assigned( 1 ) ] ] );
		$this->resolve( $states );
		self::assertSame( $reads, $this->repository->getReadCallCounts() ); self::assertSame( $writes, $this->repository->getWriteCalls() );
		$after = $this->resolver->resolve( new EffectiveConfigurationRequest( 100, 101, 'in_store', 100 ) );
		self::assertSame( $before, $after );
		self::assertSame( $before->version->fingerprint, $after->version->fingerprint );
	}

	public function test_global_and_product_targets_require_only_their_actual_chain(): void {
		$global = PolicyAssignmentState::absent( 1, 'global', 0 );
		$this->resolver->resolve_policy_instructions( $global->coordinate(), 42, [ $global ] );
		$product = PolicyAssignmentState::absent( 1, 'product', 100 );
		self::assertSame( [ 'mode' => 'inherit' ], $this->resolver->resolve_policy_instructions( $product->coordinate(), 42, [ $global, $product ] )['instructions'][self::RETURN] );
	}

	private function resolve( array $states, int $offer = 42 ): array { return $this->resolver->resolve_policy_instructions( PolicyAssignmentState::make_coordinate( 1, 'variation', 101, 100 ), $offer, $states ); }
	private function absent_chain(): array { return [ PolicyAssignmentState::absent( 1, 'global', 0 ), PolicyAssignmentState::absent( 1, 'product', 100 ), PolicyAssignmentState::absent( 1, 'variation', 101, 100 ) ]; }
	private function assigned( int $id ): array { return [ 'mode' => 'assigned', 'policy_uuid' => $this->uuid( $id ) ]; }
	private function uuid( int $id ): string { return sprintf( '00000000-0000-4000-8000-%012d', $id ); }
	private function state( PolicyAssignmentState $before, array $patch, int $row = 7, int $revision = 1, int $generation = 90 ): PolicyAssignmentState {
		$return = $before->map( self::RETURN ); $refund = $before->map( self::REFUND );
		foreach ( $patch as $family => $slots ) {
			foreach ( $slots as $offer => $slot ) {
				if ( self::RETURN === $family ) { $return = $return->with_instruction( $offer, $slot ); } else { $refund = $refund->with_instruction( $offer, $slot ); }
			}
		}
		$c = $before->coordinate();
		$bytes = PolicyAssignmentState::record_json( $c, $this->uuid( $generation ), $revision, $return, $refund, 9, 'Owned ECR test', '2026-10-24 12:00:00.000000' );
		return PolicyAssignmentState::from_physical( $c['site_id'], $c['scope_type'], $c['scope_id'], $c['parent_id'], $row, $bytes );
	}
}
