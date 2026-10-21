<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryCommercial;

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteCurrentEvidenceValidity;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use PHPUnit\Framework\TestCase;

final class PromotionValidityIntersectionTest extends TestCase {
	private function time( int $seconds = 0 ): QuoteTime { return QuoteTime::parse( '2026-10-21 12:00:00.000000' )->plus_seconds( $seconds ); }
	public function test_composed_validity_uses_event_as_lower_bound_and_earliest_exclusive_end(): void {
		$a = QuoteCurrentEvidenceValidity::capture( $this->time(), $this->time( 60 ) );
		$b = QuoteCurrentEvidenceValidity::capture( $this->time( 5 ), $this->time( 20 ) );
		foreach ( [ [ $a, $b ], [ $b, $a ] ] as $sources ) {
			$combined = QuoteCurrentEvidenceValidity::intersection( $this->time( 10 ), ...$sources );
			self::assertNotNull( $combined );
			self::assertFalse( $combined->valid_at( $this->time( 9 ) ) );
			self::assertTrue( $combined->valid_at( $this->time( 10 ) ) );
			self::assertTrue( $combined->valid_at( QuoteTime::from_epoch_microseconds( $this->time( 20 )->epoch_microseconds() - 1 ) ) );
			self::assertFalse( $combined->valid_at( $this->time( 20 ) ) );
		}
	}
	public function test_any_future_or_expired_source_refuses_the_whole_event(): void {
		$covering = QuoteCurrentEvidenceValidity::capture( $this->time(), $this->time( 60 ) );
		foreach ( [ QuoteCurrentEvidenceValidity::capture( $this->time( 11 ), $this->time( 20 ) ), QuoteCurrentEvidenceValidity::capture( $this->time(), $this->time( 10 ) ) ] as $other ) {
			self::assertNull( QuoteCurrentEvidenceValidity::intersection( $this->time( 10 ), $covering, $other ) );
			self::assertNull( QuoteCurrentEvidenceValidity::intersection( $this->time( 10 ), $other, $covering ) );
		}
	}
	public function test_composition_requires_finite_nonempty_current_proof(): void {
		self::assertNull( QuoteCurrentEvidenceValidity::intersection( $this->time() ) );
		$source = QuoteCurrentEvidenceValidity::capture( $this->time(), $this->time( 1 ) );
		self::assertNotNull( QuoteCurrentEvidenceValidity::intersection( $this->time(), ...array_fill( 0, 256, $source ) ) );
		self::assertNull( QuoteCurrentEvidenceValidity::intersection( $this->time(), ...array_fill( 0, 257, $source ) ) );
	}
}
