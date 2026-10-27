<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionSourceValidity, LegacyQuoteSourcePlan, LegacyQuoteSourceSnapshot};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{LegacyQuoteProviderFixtures as F, QuoteFixtures};
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/LegacyQuoteProviderFixtures.php';

final class FixedPromotionSourceValidityTest extends TestCase {
	private function snapshot( array $cards, ?QuoteTime $at = null ): LegacyQuoteSourceSnapshot { $plan = F::plan(); return LegacyQuoteSourceSnapshot::captured( $plan, F::context(), [], [ LegacyQuoteSourcePlan::range_key( $plan->rate_ranges()[0] ) => $cards ], $at ?? QuoteFixtures::time() ); }
	public function test_explicit_ceiling_is_exclusive_and_no_clock_is_needed(): void {
		$at = QuoteFixtures::time(); $v = FixedPromotionSourceValidity::validity_at( $this->snapshot( [ F::card() ] ), $at, $at->plus_seconds( 30 ) ); self::assertNotNull( $v ); self::assertTrue( $v->valid_at( $at ) ); self::assertTrue( $v->valid_at( $at->plus_seconds( 29 ) ) ); self::assertFalse( $v->valid_at( $at->plus_seconds( 30 ) ) );
	}
	public function test_future_candidate_activation_caps_interval_even_if_current_candidate_is_unchanged(): void {
		$at = QuoteFixtures::time(); $snapshot = $this->snapshot( [ F::card(), F::card( overrides: [ 'id' => 2, 'effective_from' => '2026-10-07 05:00:10' ] ) ] );
		$v = FixedPromotionSourceValidity::validity_at( $snapshot, $at, $at->plus_seconds( 300 ) ); self::assertNotNull( $v ); self::assertTrue( $v->valid_at( $at->plus_seconds( 9 ) ) ); self::assertFalse( $v->valid_at( $at->plus_seconds( 10 ) ) ); self::assertNull( FixedPromotionSourceValidity::validity_at( $snapshot, $at->plus_seconds( 10 ), $at->plus_seconds( 300 ) ) );
	}
	public function test_legacy_effective_to_includes_last_microsecond_of_the_whole_second(): void {
		$at = QuoteFixtures::time(); $snapshot = $this->snapshot( [ F::card( overrides: [ 'effective_to' => '2026-10-07 05:00:00' ] ) ] );
		$v = FixedPromotionSourceValidity::validity_at( $snapshot, $at, $at->plus_seconds( 300 ) ); self::assertNotNull( $v ); self::assertTrue( $v->valid_at( QuoteTime::parse( '2026-10-07 05:00:00.999999' ) ) ); self::assertFalse( $v->valid_at( $at->plus_seconds( 1 ) ) );
	}
	public function test_inactive_future_dates_do_not_create_time_transitions(): void {
		$at = QuoteFixtures::time(); $snapshot = $this->snapshot( [ F::card(), F::card( overrides: [ 'id' => 2, 'status' => 'inactive', 'effective_from' => '2026-10-07 05:00:01', 'effective_to' => '2026-10-07 05:00:02' ] ) ] );
		$v = FixedPromotionSourceValidity::validity_at( $snapshot, $at, $at->plus_seconds( 300 ) ); self::assertNotNull( $v ); self::assertTrue( $v->valid_at( $at->plus_seconds( 299 ) ) );
	}
	public function test_unobserved_past_and_elapsed_ceiling_refuse(): void {
		$at = QuoteFixtures::time(); $snapshot = $this->snapshot( [ F::card() ], $at->plus_seconds( 1 ) ); self::assertNull( FixedPromotionSourceValidity::validity_at( $snapshot, $at, $at->plus_seconds( 300 ) ) ); self::assertNull( FixedPromotionSourceValidity::validity_at( $this->snapshot( [ F::card() ] ), $at, $at ) );
	}
	public function test_exact_candidate_budget_counts_inactive_rows_too(): void {
		$at = QuoteFixtures::time(); $cards = []; for ( $id = 1; $id <= 1000; ++$id ) { $cards[] = F::card( overrides: [ 'id' => $id, 'status' => 'inactive' ] ); }
		self::assertNotNull( FixedPromotionSourceValidity::validity_at( $this->snapshot( $cards ), $at, $at->plus_seconds( 300 ) ) );
		$cards[] = F::card( overrides: [ 'id' => 1001, 'status' => 'inactive' ] ); self::assertNull( FixedPromotionSourceValidity::validity_at( $this->snapshot( $cards ), $at, $at->plus_seconds( 300 ) ) );
	}
	public function test_candidate_budget_is_aggregate_across_all_ranges(): void {
		$at = QuoteFixtures::time(); $old = F::plan(); $range = [ 'delivery_offer_id' => 20, 'destination_zone_id' => 51, 'base_currency' => 'GHS' ]; $plan = LegacyQuoteSourcePlan::create( QuoteFixtures::owner(), F::context(), $old->member_proofs(), [ ...$old->rate_ranges(), $range ], $old->fences() );
		$first = []; for ( $id = 1; $id <= 1000; ++$id ) { $first[] = F::card( overrides: [ 'id' => $id, 'status' => 'inactive' ] ); }
		$snapshot = LegacyQuoteSourceSnapshot::captured( $plan, F::context(), [], [ LegacyQuoteSourcePlan::range_key( $old->rate_ranges()[0] ) => $first, LegacyQuoteSourcePlan::range_key( $range ) => [ F::card( overrides: [ 'id' => 1001, 'destination_zone_id' => 51 ] ) ] ], $at );
		self::assertNull( FixedPromotionSourceValidity::validity_at( $snapshot, $at, $at->plus_seconds( 300 ) ) );
	}
	public function test_malformed_active_boundary_is_unavailable(): void {
		$at = QuoteFixtures::time(); self::assertNull( FixedPromotionSourceValidity::validity_at( $this->snapshot( [ F::card( overrides: [ 'effective_from' => 'malformed' ] ) ] ), $at, $at->plus_seconds( 300 ) ) );
	}
}
