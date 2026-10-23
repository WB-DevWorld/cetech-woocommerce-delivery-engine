<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteSealedEvidence, QuoteCurrentEvidenceValidity};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteId, QuoteJson, QuoteStoredRow, QuoteTime};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionQuoteSealContractTest.php';

/** Pure detached carrier invariants; receipt/current/native authority is established by the reader. */
final class FixedPromotionQuoteSealedEvidenceContractTest extends TestCase {
	public function test_exact_sealed_observations_retain_originals_and_an_independent_current_control_revision(): void {
		$c = PromotionSealContractFixture::command(); [ $q, $b ] = PromotionSealContractFixture::acknowledged( $c ); $at = Q::time()->plus_seconds( 5 ); $valid = QuoteCurrentEvidenceValidity::capture( $at, $c->hold_until() );
		$before = [ $c->to_recovery_array(), $q->row(), $b->row() ];
		$e = new FixedPromotionQuoteSealedEvidence( command: $c, quote: $q, binding: $b, current_control_revision: 9, evaluated: $at, validity: $valid );
		self::assertSame( $c->owner(), $e->owner() ); self::assertSame( $c->reference(), $e->reference() ); self::assertSame( $q->header(), $e->header() ); self::assertSame( $q->context(), $e->context() ); self::assertSame( $q->terms(), $e->terms() );
		self::assertSame( $q, $e->quote_record() ); self::assertSame( $b, $e->binding() ); self::assertSame( $at, $e->evaluated_at() ); self::assertSame( $valid, $e->validity() );
		self::assertSame( $b->row()['sealed_at'], $e->sealed_at()->sql() ); self::assertNotSame( $c->binding()->row()['sealed_at'], $e->sealed_at()->sql() ); self::assertSame( 9, $e->control_revision() ); self::assertSame( 1, $c->control_revision() );
		self::assertSame( $before, [ $c->to_recovery_array(), $q->row(), $b->row() ] ); self::assertNull( $c->verification()->binding_command()->acceptance()->issue()->lease() );
	}

	public static function foreign_bindings(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'prepared1', 'prepared2', 'physical id', 'order', 'placement', 'created time', 'verified time', 'snapshot', 'context', 'bind namespace', 'seal namespace', 'mapped item' ] ); }
	#[DataProvider( 'foreign_bindings' )]
	public function test_otherwise_valid_binding_shapes_cannot_replace_original_sealed_membership( string $case ): void {
		$c = PromotionSealContractFixture::command(); [ $q, $b ] = PromotionSealContractFixture::acknowledged( $c ); $r = $b->row();
		if ( 'prepared1' === $case ) { $r = $c->verification()->prepared_binding()->row(); } elseif ( 'prepared2' === $case ) { $r = $c->verified_binding()->row(); }
		elseif ( 'physical id' === $case ) { ++$r['id']; } elseif ( 'order' === $case ) { ++$r['order_id']; } elseif ( 'placement' === $case ) { $r['placement_uuid'] = QuoteId::generate()->value(); }
		elseif ( 'created time' === $case ) { $r['created_at'] = $q->accepted_at()->sql(); } elseif ( 'verified time' === $case ) { $r['verified_at'] = $r['sealed_at']; }
		elseif ( 'snapshot' === $case ) { $r['snapshot_digest'] = Q::digest( 'other original snapshot' ); } elseif ( 'context' === $case ) { $r['context_digest'] = Q::digest( 'other original context' ); }
		elseif ( 'bind namespace' === $case ) { $r['bind_namespace_hash'] = Q::digest( 'other bind namespace' ); } elseif ( 'seal namespace' === $case ) { $r['seal_namespace_hash'] = Q::digest( 'other seal namespace' ); }
		else { $mapping = $b->mapping(); $mapping['groups'][0]['lines'][0]['item_id'] += 1000; $r['mapping_json'] = QuoteJson::encode( $mapping ); $r['managed_group_manifest_digest'] = QuoteBinding::manifest_digest( $mapping ); }
		$other = QuoteBinding::from_row( $r, $q ); self::assertNotSame( $b->row(), $other->row() );
		$this->expectException( \InvalidArgumentException::class ); new FixedPromotionQuoteSealedEvidence( $c, $q, $other, 1, Q::time()->plus_seconds( 5 ), QuoteCurrentEvidenceValidity::capture( Q::time(), $c->hold_until() ) );
	}

	public static function foreign_quotes(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'physical id', 'accepted time', 'issued', 'stripped', 'different original' ] ); }
	#[DataProvider( 'foreign_quotes' )]
	public function test_exact_original_accepted_physical_row_is_required( string $case ): void {
		$c = PromotionSealContractFixture::command(); [ $q, $b ] = PromotionSealContractFixture::acknowledged( $c ); $r = $q->row();
		if ( 'physical id' === $case ) { ++$r['id']; } elseif ( 'accepted time' === $case ) { $r['accepted_at'] = $r['transition_at'] = Q::time()->plus_seconds( 2 )->sql(); }
		elseif ( 'issued' === $case ) { $r = array_replace( $r, [ 'state' => 'issued', 'revision' => 1, 'accepted_at' => null, 'transition_at' => null ] ); }
		elseif ( 'stripped' === $case ) { $r = array_replace( $r, [ 'state' => 'stripped', 'retention_revision' => 2, 'private_body_json' => null, 'accepted_at' => null, 'transition_at' => $c->header()->expires_at()->plus_seconds( 1800 )->sql() ] ); }
		else { $r = PromotionSealContractFixture::command()->verification()->binding_command()->quote_record()->row(); }
		$other = QuoteStoredRow::from_row( $r ); self::assertNotSame( $q->row(), $other->row() );
		$this->expectException( \InvalidArgumentException::class ); new FixedPromotionQuoteSealedEvidence( $c, $other, $b, 1, Q::time()->plus_seconds( 5 ), QuoteCurrentEvidenceValidity::capture( Q::time(), $c->hold_until() ) );
	}

	public static function elapsed_fences(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'seal at hold', 'seal after hold', 'seal at quote expiry', 'evaluated before seal', 'evaluated at hold', 'evaluated at quote expiry', 'source not observed yet', 'source expires at evaluation' ] ); }
	#[DataProvider( 'elapsed_fences' )]
	public function test_original_deadlines_and_finite_current_validity_cannot_be_extended( string $case ): void {
		$c = PromotionSealContractFixture::command(); [ $q, $b ] = PromotionSealContractFixture::acknowledged( $c ); $at = Q::time()->plus_seconds( 5 ); $from = Q::time(); $until = $c->header()->expires_at()->plus_seconds( 1 );
		if ( str_starts_with( $case, 'seal ' ) ) { $sealed = match ( $case ) { 'seal at hold' => $c->hold_until(), 'seal after hold' => $c->hold_until()->plus_seconds( 1 ), default => $c->header()->expires_at() }; $b = QuoteBinding::from_row( array_replace( $b->row(), [ 'sealed_at' => $sealed->sql() ] ), $q ); $at = $sealed; }
		elseif ( 'evaluated before seal' === $case ) { $at = QuoteTime::from_epoch_microseconds( QuoteTime::parse( $b->row()['sealed_at'] )->epoch_microseconds() - 1 ); }
		elseif ( 'evaluated at hold' === $case ) { $at = $c->hold_until(); } elseif ( 'evaluated at quote expiry' === $case ) { $at = $c->header()->expires_at(); }
		elseif ( 'source not observed yet' === $case ) { $from = $at->plus_seconds( 1 ); } else { $until = $at; }
		$validity = QuoteCurrentEvidenceValidity::capture( $from, $until );
		$this->expectException( \InvalidArgumentException::class ); new FixedPromotionQuoteSealedEvidence( $c, $q, $b, 1, $at, $validity );
	}

	public static function inclusive_boundaries(): array { return [ [ 'actual seal' ], [ 'verified seal' ], [ 'last hold microsecond' ] ]; }
	#[DataProvider( 'inclusive_boundaries' )]
	public function test_exact_lower_bounds_and_last_microsecond_before_original_hold_are_observable( string $case ): void {
		$c = PromotionSealContractFixture::command(); [ $q, $b ] = PromotionSealContractFixture::acknowledged( $c ); $at = QuoteTime::parse( $b->row()['sealed_at'] );
		if ( 'verified seal' === $case ) { $at = QuoteTime::parse( $c->verified_binding()->row()['verified_at'] ); $b = QuoteBinding::from_row( array_replace( $b->row(), [ 'sealed_at' => $at->sql() ] ), $q ); }
		elseif ( 'last hold microsecond' === $case ) { $at = QuoteTime::from_epoch_microseconds( $c->hold_until()->epoch_microseconds() - 1 ); }
		$validity = QuoteCurrentEvidenceValidity::capture( $at, $c->hold_until() ); $e = new FixedPromotionQuoteSealedEvidence( $c, $q, $b, PHP_INT_MAX, $at, $validity );
		self::assertSame( $at, $e->evaluated_at() ); self::assertTrue( $e->validity()->valid_at( $at ) ); self::assertFalse( $e->validity()->valid_at( $c->hold_until() ) ); self::assertSame( PHP_INT_MAX, $e->control_revision() );
	}

	public static function nonpositive_control(): array { return [ [ 0 ], [ -1 ] ]; }
	#[DataProvider( 'nonpositive_control' )]
	public function test_current_control_revision_must_be_explicitly_positive( int $revision ): void {
		$c = PromotionSealContractFixture::command(); [ $q, $b ] = PromotionSealContractFixture::acknowledged( $c ); $this->expectException( \InvalidArgumentException::class );
		new FixedPromotionQuoteSealedEvidence( $c, $q, $b, $revision, Q::time()->plus_seconds( 5 ), QuoteCurrentEvidenceValidity::capture( Q::time(), $c->hold_until() ) );
	}

	public function test_returned_row_and_mapping_copies_cannot_change_detached_observations(): void {
		$c = PromotionSealContractFixture::command(); [ $q, $b ] = PromotionSealContractFixture::acknowledged( $c ); $e = new FixedPromotionQuoteSealedEvidence( $c, $q, $b, 1, Q::time()->plus_seconds( 5 ), QuoteCurrentEvidenceValidity::capture( Q::time(), $c->hold_until() ) );
		$original = $e->binding()->row(); $row = $e->binding()->row(); $row['order_id'] = 999; $mapping = $e->binding()->mapping(); $mapping['groups'][0]['lines'][0]['item_id'] = 999; $quote = $e->quote_record()->row(); $quote['id'] = 999;
		self::assertSame( $original, $e->binding()->row() ); self::assertSame( $q->row(), $e->quote_record()->row() ); self::assertNotSame( $mapping, $e->binding()->mapping() );
	}

	public function test_no_live_guard_owner_or_payment_api_is_exposed_and_generic_persistence_is_refused(): void {
		$c = PromotionSealContractFixture::command(); [ $q, $b ] = PromotionSealContractFixture::acknowledged( $c ); $e = new FixedPromotionQuoteSealedEvidence( $c, $q, $b, 1, Q::time()->plus_seconds( 5 ), QuoteCurrentEvidenceValidity::capture( Q::time(), $c->hold_until() ) );
		foreach ( [ 'guard', 'session', 'factory', 'command', 'admit', 'pay', 'commit', 'release', 'to_recovery_array' ] as $method ) { self::assertFalse( method_exists( $e, $method ) ); }
		$types = array_map( static fn( \ReflectionProperty $property ): string => (string) $property->getType(), ( new \ReflectionClass( $e ) )->getProperties() );
		self::assertCount( 6, $types ); foreach ( $types as $type ) { self::assertDoesNotMatchRegularExpression( '/Guard|Session|Factory|Closure|Inputs/', $type ); }
		$secrets = [ $c->reference()->handle(), $c->owner()->digest(), $c->snapshot_digest(), $c->context_digest(), $c->allocation_digest(), $q->header()->id()->value() ];
		foreach ( [ static fn() => json_encode( $e, JSON_THROW_ON_ERROR ), static fn() => serialize( $e ), static fn() => $e->__unserialize( [] ) ] as $persist ) { try { $persist(); self::fail( 'Private observations must not persist implicitly.' ); } catch ( \LogicException $error ) { foreach ( $secrets as $secret ) { self::assertStringNotContainsString( $secret, $error->getMessage() ); } } }
		ob_start(); var_dump( $e ); $debug = ob_get_clean(); self::assertStringContainsString( 'redacted', $debug ); foreach ( $secrets as $secret ) { self::assertStringNotContainsString( $secret, $debug ); }
	}
}
