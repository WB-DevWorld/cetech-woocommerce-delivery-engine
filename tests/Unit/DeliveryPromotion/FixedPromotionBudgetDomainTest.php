<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuoteProvider;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetRequest as Request, FixedPromotionBudgetReservation as Reservation, FixedPromotionBudgetState as State};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteHeader, QuoteJson, QuoteMoney, QuoteTime};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';

/** Exact private budget arithmetic and replay, without ledger persistence or payment authority. */
final class FixedPromotionBudgetDomainTest extends TestCase {
	private static ?FixedPromotionQuoteProvider $provider = null;
	private static ?Request $captured = null;
	private function request(): Request {
		self::$provider ??= F::provider();
		return self::$captured ??= Request::from_captured( F::header( self::$provider ), self::$provider->context(), self::$provider->terms(), F::key(), QuoteFixtures::digest( 'campaign account' ) );
	}
	private function money( string $amount, string $currency = 'GHS', int $precision = 2 ): QuoteMoney { return QuoteMoney::from_array( compact( 'amount', 'currency', 'precision' ) ); }
	private function account( string $limit = '10.00' ): State { $r = $this->request(); return State::create( $r->site_id(), $r->account_key(), $r->funding_source(), $this->money( $limit ) ); }
	private function hold(): Reservation { $r = $this->request(); return Reservation::hold( $r, $r->quote_created_at(), $r->quote_created_at()->plus_seconds( 60 ) ); }

	public function test_request_roundtrip_binds_the_complete_compiled_quote_and_exact_discount(): void {
		$r = $this->request(); $facts = $r->private_facts();
		self::assertSame( $r->to_private_json(), Request::from_json( $r->to_private_json() )->to_private_json() ); self::assertSame( $r->claim_key(), Request::from_array( $facts )->claim_key() );
		self::assertSame( self::$provider->context()->digest(), $facts['material_digest'] ); self::assertSame( self::$provider->terms()->digest(), $facts['terms_digest'] );
		self::assertSame( '3.00', $r->amount()->amount() ); self::assertSame( F::rule()->private_facts()['funding_source'], $r->funding_source() );
		self::assertNotSame( $r->digest(), $r->claim_key() ); self::assertSame( F::owner()->digest(), $facts['owner_digest'] );
	}
	public function test_different_compiled_quote_id_or_header_window_cannot_reuse_a_claim(): void {
		$r = $this->request(); $header = F::header( self::$provider );
		$other = Request::from_captured( $header, self::$provider->context(), self::$provider->terms(), F::key(), $r->account_key() );
		self::assertNotSame( $r->claim_key(), $other->claim_key() ); self::assertSame( $r->private_facts()['capture_digest'], $other->private_facts()['capture_digest'] );
		$data = $header->private_facts(); $data['created_at'] = $header->created_at()->plus_seconds( 1 )->sql(); $data['expires_at'] = $header->expires_at()->plus_seconds( 1 )->sql();
		$shifted = Request::from_captured( QuoteHeader::from_array( $data ), self::$provider->context(), self::$provider->terms(), F::key(), $r->account_key() );
		self::assertSame( $other->private_facts()['quote_id'], $shifted->private_facts()['quote_id'] ); self::assertSame( $other->private_facts()['body_digest'], $shifted->private_facts()['body_digest'] );
		self::assertNotSame( $other->private_facts()['header_digest'], $shifted->private_facts()['header_digest'] ); self::assertNotSame( $other->claim_key(), $shifted->claim_key() );
	}
	public function test_unadjusted_component_does_not_silently_obtain_a_zero_reservation(): void {
		$p = F::mixed_provider(); $this->expectException( \InvalidArgumentException::class );
		Request::from_captured( F::header( $p ), $p->context(), $p->terms(), F::second_key(), $this->request()->account_key() );
	}
	public static function malformed_requests(): array { return [ 'future format' => [ 'format_version', 2 ], 'string site' => [ 'site_id', '1' ], 'missing money' => [ 'amount', null ], 'malformed digest' => [ 'capture_digest', 'broken' ], 'bad uuid' => [ 'quote_id', 'uuid' ], 'wrong clock' => [ 'quote_expires_at', '2026-10-07 05:04:59.000000' ] ]; }
	#[DataProvider( 'malformed_requests' )]
	public function test_request_malformed_frozen_facts_refuse( string $field, mixed $value ): void { $data = $this->request()->private_facts(); $data[$field] = $value; $this->expectException( \InvalidArgumentException::class ); Request::from_array( $data ); }
	public function test_structural_request_replay_does_not_claim_to_authenticate_original_facts(): void {
		$original = $this->request(); $data = $original->private_facts(); $data['owner_digest'] = QuoteFixtures::digest( 'different owner' ); $replacement = Request::from_array( $data );
		self::assertNotSame( $original->claim_key(), $replacement->claim_key() ); self::assertFalse( $this->hold()->matches( $replacement ) );
	}
	public function test_balances_move_between_available_held_and_committed_without_creating_money(): void {
		$initial = $this->account(); $held = $initial->reserve( $this->money( '3.00' ) ); $committed = $held->commit( $this->money( '3.00' ) ); $released = $committed->reserve( $this->money( '2.00' ) )->release( $this->money( '2.00' ) );
		self::assertSame( '10.00', $initial->remaining()->amount() ); self::assertSame( '3.00', $held->held()->amount() ); self::assertSame( '7.00', $held->remaining()->amount() );
		self::assertSame( '0.00', $committed->held()->amount() ); self::assertSame( '3.00', $committed->spent()->amount() ); self::assertSame( '7.00', $committed->remaining()->amount() );
		self::assertSame( $committed->spent()->facts(), $released->spent()->facts() ); self::assertSame( $committed->remaining()->facts(), $released->remaining()->facts() ); self::assertSame( 5, $released->revision() );
		self::assertSame( $released->to_private_json(), State::from_json( $released->to_private_json() )->to_private_json() );
	}
	public function test_known_zero_limit_and_zero_allocation_are_explicit_without_unknown_defaults(): void {
		$s = $this->account( '0.00' ); $held = $s->reserve( $this->money( '0.00' ) ); self::assertTrue( $held->remaining()->zero() ); self::assertSame( 2, $held->revision() );
		$data = $s->private_facts(); $data['limit'] = null; $this->expectException( \InvalidArgumentException::class ); State::from_array( $data );
	}
	public function test_exhausted_budget_cannot_reserve_one_more_minor_unit(): void { $s = $this->account()->reserve( $this->money( '10.00' ) ); self::assertTrue( $s->remaining()->zero() ); $this->expectException( \InvalidArgumentException::class ); $s->reserve( $this->money( '0.01' ) ); }
	public static function wrong_units(): array { return [ 'currency' => [ 'USD', 2 ], 'precision' => [ 'GHS', 3 ] ]; }
	#[DataProvider( 'wrong_units' )]
	public function test_budget_does_not_convert_or_round_units( string $currency, int $precision ): void { $this->expectException( \InvalidArgumentException::class ); $this->account()->reserve( $this->money( '1', $currency, $precision ) ); }
	public function test_account_cannot_oversubscribe_alter_aggregate_unit_or_overflow_revision(): void {
		$data = $this->account()->private_facts(); $data['held'] = $this->money( '8.00' )->facts(); $data['spent'] = $this->money( '3.00' )->facts();
		try { State::from_array( $data ); self::fail( 'Overcommitted summary accepted.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
		$data = $this->account()->private_facts(); $data['revision'] = PHP_INT_MAX; $this->expectException( \InvalidArgumentException::class ); State::from_array( $data )->reserve( $this->money( '0' ) );
	}
	public function test_reservation_clock_and_commit_are_exclusive_at_the_actual_hold_boundary(): void {
		$hold = $this->hold(); self::assertFalse( $hold->is_held_at( $hold->held_at()->plus_seconds( -1 ) ) ); self::assertTrue( $hold->is_held_at( $hold->held_at() ) ); self::assertFalse( $hold->is_held_at( $hold->expires_at() ) );
		self::assertSame( $hold->to_private_json(), Reservation::from_json( $hold->to_private_json() )->to_private_json() ); $this->expectException( \InvalidArgumentException::class ); $hold->commit( $hold->expires_at() );
	}
	public function test_commit_replay_cannot_double_allocate_and_cannot_move_time_backwards(): void {
		$hold = $this->hold(); $at = $hold->held_at()->plus_seconds( 5 ); $committed = $hold->commit( $at );
		self::assertSame( 'held', $hold->state() ); self::assertSame( 'committed', $committed->state() ); self::assertSame( 2, $committed->revision() ); self::assertSame( $committed, $committed->commit( $hold->expires_at()->plus_seconds( 600 ) ) );
		$this->expectException( \InvalidArgumentException::class ); $committed->commit( $at->plus_seconds( -1 ) );
	}
	public function test_expiry_does_not_release_but_explicit_authorized_release_can_be_recorded_later(): void {
		$hold = $this->hold(); self::assertSame( 'held', $hold->state() ); self::assertFalse( $hold->is_held_at( $hold->expires_at() ) );
		$released = $hold->release( $hold->expires_at()->plus_seconds( 1 ) ); self::assertSame( 'released', $released->state() ); self::assertSame( $released, $released->release( $released->changed_at()->plus_seconds( 1 ) ) );
		$this->expectException( \InvalidArgumentException::class ); $released->commit( $released->changed_at() );
	}
	public function test_committed_allocation_is_not_implicitly_refunded_by_release(): void { $hold = $this->hold(); $committed = $hold->commit( $hold->held_at() ); $this->expectException( \InvalidArgumentException::class ); $committed->release( $committed->changed_at() ); }
	public function test_hold_expiry_is_independent_of_quote_ttl_and_creation_cannot_happen_after_quote_expiry(): void {
		$r = $this->request(); $long = Reservation::hold( $r, $r->quote_created_at(), $r->quote_expires_at()->plus_seconds( 60 ) ); self::assertTrue( $long->is_held_at( $r->quote_expires_at() ) );
		$this->expectException( \InvalidArgumentException::class ); Reservation::hold( $r, $r->quote_expires_at(), $r->quote_expires_at()->plus_seconds( 60 ) );
	}
	public static function invalid_reservation_rows(): array { return [ 'held revision' => [ 'revision', 2 ], 'future format' => [ 'format_version', 2 ], 'unknown state' => [ 'state', 'pending' ], 'same expiry' => [ 'expires_at', '2026-10-07 05:00:00.000000' ], 'past changed' => [ 'changed_at', '2026-10-07 04:59:59.000000' ] ]; }
	#[DataProvider( 'invalid_reservation_rows' )]
	public function test_malformed_reservation_rows_refuse( string $field, mixed $value ): void { $data = $this->hold()->private_facts(); $data[$field] = $value; $this->expectException( \InvalidArgumentException::class ); Reservation::from_array( $data ); }
	public function test_private_budget_carriers_refuse_implicit_export_and_redact_funding(): void {
		foreach ( [ $this->request(), $this->account(), $this->hold() ] as $private ) {
			try { json_encode( $private, JSON_THROW_ON_ERROR ); self::fail( 'Implicit JSON export accepted.' ); } catch ( \LogicException ) { self::assertTrue( true ); }
			try { serialize( $private ); self::fail( 'Implicit serialization accepted.' ); } catch ( \LogicException ) { self::assertTrue( true ); }
			ob_start(); var_dump( $private ); $debug = ob_get_clean(); self::assertStringNotContainsString( 'private_fixed_funder', $debug ); self::assertStringContainsString( 'redacted', $debug );
		}
	}
}
