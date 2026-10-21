<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryPromotion;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Infrastructure\Persistence\{FixedPromotionBudgetRecordCodec as Codec, FixedPromotionBudgetSchema as Schema, SqlFixedPromotionBudgetStore as Store};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\PromotionBudgetSqlFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\FixedPromotionBudgetMetadataSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/PromotionBudgetSqlFixture.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/FixedPromotionBudgetMetadataSession.php';

/** SQL protocol/refusal tests; physical lock and commit behavior has a separate real-DB group. */
final class SqlFixedPromotionBudgetStoreTest extends TestCase {
	private function failure( callable $call ): void { try { $call(); self::fail( 'Storage must refuse.' ); } catch ( OperationStorageException ) { self::assertTrue( true ); } }
	private function session( ?FixedPromotionBudgetState $account = null, ?FixedPromotionBudgetReservation $claim = null ): PromotionBudgetStoreSession { $s = new PromotionBudgetStoreSession(); if ( null !== $account ) { $s->account = [ 'id' => 1 ] + Codec::account_values( $account ); } if ( null !== $claim ) { $s->claim = [ 'id' => 2 ] + Codec::reservation_values( $claim ); } return $s; }
	public function test_explicit_precision_two_zero_account_can_be_inserted_without_transaction_control(): void {
		$r = F::request(); $s = $this->session(); self::assertTrue( ( new Store() )->insert_account( $s, F::account( $r ) ) ); self::assertCount( 1, $s->writes ); self::assertStringContainsString( 'INSERT INTO `wp_delivery_engine_promotion_budget_accounts`', $s->writes[0] ); self::assertSame( 0, $s->controls ); self::assertSame( 2, count( $s->validated[0] ) );
	}
	public static function bad_seed(): array { return [ 'held' => [ 'held' ], 'spent' => [ 'spent' ], 'foreign site' => [ 'site' ] ]; }
	#[DataProvider( 'bad_seed' )]
	public function test_initial_account_has_no_implicit_existing_allocations( string $fault ): void {
		$r = F::request(); $a = F::account( $r ); $a = match ( $fault ) { 'held' => $a->reserve( F::money( '1' ) ), 'spent' => $a->reserve( F::money( '1' ) )->commit( F::money( '1' ) ), default => FixedPromotionBudgetState::create( 2, $r->account_key(), $r->funding_source(), F::money( '3' ) ) }; $s = $this->session(); $this->failure( fn() => ( new Store() )->insert_account( $s, $a ) ); self::assertSame( [], $s->writes );
	}
	public function test_known_account_path_always_locks_account_before_claim_without_capability_cache(): void {
		$r = F::request(); $a = F::account( $r )->reserve( $r->amount() ); $claim = FixedPromotionBudgetReservation::hold( $r, $r->quote_created_at(), $r->quote_created_at()->plus_seconds( 60 ) ); $s = $this->session( $a, $claim ); $store = new Store();
		for ( $i = 0; $i < 2; ++$i ) { self::assertSame( $claim->to_private_json(), $store->lock_reservation_for_account( $s, $r->claim_key(), $r->account_key() )->to_private_json() ); }
		$locks = array_values( array_filter( $s->reads, static fn( string $q ): bool => str_ends_with( $q, 'FOR UPDATE' ) ) ); self::assertCount( 4, $locks ); self::assertStringContainsString( 'promotion_budget_accounts', $locks[0] ); self::assertStringContainsString( 'promotion_budget_reservations', $locks[1] ); self::assertStringContainsString( 'promotion_budget_accounts', $locks[2] ); self::assertStringContainsString( 'promotion_budget_reservations', $locks[3] ); self::assertSame( 0, $s->controls );
	}
	public function test_unknown_account_discovery_missing_is_uncertainty_while_known_account_absence_is_current(): void {
		$r = F::request(); $s = $this->session( F::account( $r ) ); $store = new Store(); $this->failure( fn() => $store->lock_reservation( $s, $r->claim_key() ) ); self::assertNull( $store->lock_reservation_for_account( $s, $r->claim_key(), $r->account_key() ) );
	}
	public function test_account_cas_compares_id_and_every_canonical_mirror_byte(): void {
		$r = F::request(); $a = F::account( $r ); $s = $this->session( $a ); self::assertTrue( ( new Store() )->replace_account( $s, $a, $a->reserve( F::money( '1' ) ) ) ); $where = explode( ' WHERE ', $s->writes[0], 2 )[1]; self::assertStringContainsString( 'id = 1', $where ); foreach ( array_keys( Codec::account_values( $a ) ) as $column ) { self::assertStringContainsString( 'BINARY `' . $column . '` = BINARY ', $where ); }
	}
	public function test_full_expected_body_conflict_refuses_before_cas(): void {
		$r = F::request(); $a = F::account( $r ); $s = $this->session( $a->reserve( F::money( '1' ) ) ); self::assertFalse( ( new Store() )->replace_account( $s, $a, $a->reserve( F::money( '2' ) ) ) ); self::assertSame( [], $s->writes );
	}
	public static function cas_results(): array { return [ 'conflict' => [ 0, 0, false ], 'duplicate insert' => [ false, 1062, false ], 'statement failure' => [ false, 45000, null ], 'multiple rows' => [ 2, 0, null ] ]; }
	#[DataProvider( 'cas_results' )]
	public function test_insert_conflict_is_distinct_from_uncertain_or_invalid_write( int|false $affected, int $errno, ?bool $expected ): void {
		$r = F::request(); $s = $this->session(); $s->affected = $affected; $s->write_errno = $errno;
		if ( null === $expected || 0 === $affected ) { $this->failure( fn() => ( new Store() )->insert_account( $s, F::account( $r ) ) ); } else { self::assertSame( $expected, ( new Store() )->insert_account( $s, F::account( $r ) ) ); }
	}
	public function test_zero_affected_cas_is_false_and_cannot_be_reported_as_success(): void {
		$r = F::request(); $a = F::account( $r ); $s = $this->session( $a ); $s->affected = 0; self::assertFalse( ( new Store() )->replace_account( $s, $a, $a->reserve( F::money( '1' ) ) ) );
	}
	public static function illegal_accounts(): array { return [ 'limit' => [ 'limit' ], 'funding' => [ 'funding_source' ], 'revision jump' => [ 'revision' ], 'spent decreases' => [ 'spent' ] ]; }
	#[DataProvider( 'illegal_accounts' )]
	public function test_account_identity_limit_and_legal_balance_transition_are_enforced( string $field ): void {
		$r = F::request(); $a = F::account( $r )->reserve( F::money( '1' ) )->commit( F::money( '1' ) ); $facts = $a->release( F::money( '0' ) )->private_facts();
		match ( $field ) { 'limit' => $facts['limit']['amount'] = '9.00', 'funding_source' => $facts['funding_source']['code'] = 'other_funder', 'revision' => $facts['revision'] += 1, default => $facts['spent']['amount'] = '0.00' };
		$b = FixedPromotionBudgetState::from_array( $facts ); $s = $this->session( $a ); $this->failure( fn() => ( new Store() )->replace_account( $s, $a, $b ) ); self::assertSame( [], $s->writes );
	}
	public function test_claim_transition_cas_is_exact_and_does_not_require_already_decreased_held_again(): void {
		$r = F::request(); $held = FixedPromotionBudgetReservation::hold( $r, $r->quote_created_at(), $r->quote_created_at()->plus_seconds( 60 ) ); $done = $held->commit( $r->quote_created_at()->plus_seconds( 1 ) ); $a = F::account( $r )->reserve( $r->amount() )->commit( $r->amount() ); $s = $this->session( $a, $held ); self::assertTrue( ( new Store() )->replace_reservation( $s, $held, $done ) ); $where = explode( ' WHERE ', $s->writes[0], 2 )[1]; foreach ( array_keys( Codec::reservation_values( $held ) ) as $column ) { self::assertStringContainsString( 'BINARY `' . $column . '` = BINARY ', $where ); }
	}
	public function test_claim_transition_refuses_foreign_funding_account_before_write(): void {
		$r = F::request(); $held = FixedPromotionBudgetReservation::hold( $r, $r->quote_created_at(), $r->quote_created_at()->plus_seconds( 60 ) ); $a = FixedPromotionBudgetState::create( 1, $r->account_key(), [ 'code' => 'other_funder', 'version' => 1 ], F::money( '3' ) ); $s = $this->session( $a, $held ); $this->failure( fn() => ( new Store() )->replace_reservation( $s, $held, $held->release( $r->quote_created_at() ) ) ); self::assertSame( [], $s->writes );
	}
	public function test_owner_loss_after_account_lock_prevents_claim_read(): void {
		$r = F::request(); $s = $this->session( F::account( $r ) ); $s->on_query = static function( string $sql, $s ): void { if ( str_ends_with( $sql, 'FOR UPDATE' ) ) { $s->transaction = false; } }; $this->failure( fn() => ( new Store() )->lock_reservation_for_account( $s, $r->claim_key(), $r->account_key() ) ); self::assertCount( 1, array_filter( $s->reads, static fn( string $q ): bool => str_ends_with( $q, 'FOR UPDATE' ) ) );
	}
	public function test_corrupt_record_body_or_marker_does_not_escape_as_a_typed_account(): void {
		$r = F::request(); $s = $this->session( F::account( $r ) ); $s->account['state_json'] .= ' '; $this->failure( fn() => ( new Store() )->lock_account( $s, $r->account_key() ) ); self::assertSame( [], $s->writes );
	}
	public function test_readiness_is_rechecked_each_call_and_schema_failure_is_storage_failure(): void {
		$r = F::request(); $s = $this->session( F::account( $r ) ); $store = new Store(); self::assertNotNull( $store->lock_account( $s, $r->account_key() ) ); $s->valid_tables = false; $this->failure( fn() => $store->lock_account( $s, $r->account_key() ) ); self::assertSame( 0, $s->controls );
	}
}

final class PromotionBudgetStoreSession extends FixedPromotionBudgetMetadataSession {
	public ?array $account = null; public ?array $claim = null; public array $writes = []; public int|false $affected = 1; public int $write_errno = 0;
	public function get_row( string $sql ): array|null|false {
		if ( ! str_starts_with( $sql, 'SELECT ' ) ) { return parent::get_row( $sql ); }
		$this->touch( $sql ); $this->error = 0;
		if ( str_starts_with( $sql, 'SELECT account_key ' ) ) { return null === $this->claim ? null : [ 'account_key' => $this->claim['account_key'] ]; }
		$row = str_contains( $sql, 'promotion_budget_accounts`' ) ? $this->account : $this->claim;
		if ( null === $row ) { return null; } $field = isset( $row['state_json'] ) ? 'state_json' : 'reservation_json'; $row['__' . $field . '_bytes'] = strlen( $row[$field] ); return $row;
	}
	public function query( string $sql ): int|false { $this->writes[] = $sql; $this->touch( $sql ); $this->error = $this->write_errno; return $this->affected; }
	public function insert_id(): int { return 1; }
	public function prepare( string $sql, mixed ...$args ): string { preg_match_all( '/%[sd]/', $sql, $matches ); foreach ( $matches[0] as $i => $placeholder ) { if ( ( '%s' === $placeholder && ! is_string( $args[$i] ) ) || ( '%d' === $placeholder && ! is_int( $args[$i] ) ) ) { throw new \LogicException( 'Native prepare requires exact scalar types.' ); } } return parent::prepare( $sql, ...$args ); }
}
