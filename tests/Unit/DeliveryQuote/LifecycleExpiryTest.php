<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteLifecycle;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteFixtures.php';

final class LifecycleExpiryTest extends TestCase {
	public function test_exact_absolute_expiry_is_half_open_and_reads_do_not_extend_it(): void {
		$quote = QuoteFixtures::issue(); $header = $quote->header(); $expires = $header->expires_at();
		$before = QuoteTime::from_epoch_microseconds( $expires->epoch_microseconds() - 1 ); $after = QuoteTime::from_epoch_microseconds( $expires->epoch_microseconds() + 1 );
		self::assertSame( 300000000, $expires->epoch_microseconds() - $header->created_at()->epoch_microseconds() );
		self::assertTrue( $quote->usable_at( $before ) ); self::assertFalse( $quote->usable_at( $expires ) ); self::assertFalse( $quote->usable_at( $after ) );
		self::assertSame( 'expired', $quote->current_status( $expires ) ); self::assertSame( 'quote_expired', $quote->reason_at( $after ) );
		self::assertSame( 'issued', $quote->state() ); self::assertSame( 1, $quote->revision() ); self::assertSame( $expires, $quote->header()->expires_at() );
	}
	public function test_new_acceptance_at_exact_expiry_refuses_and_keeps_original_issue(): void {
		$quote = QuoteFixtures::issue(); $result = ( new QuoteLifecycle() )->accept( $quote, QuoteFixtures::owner(), QuoteFixtures::reference( $quote->header()->id() ), QuoteFixtures::context(), $quote->header()->expires_at(), 1, $quote->header()->body_digest(), $quote->header()->expires_at() );
		self::assertFalse( $result->completed() ); self::assertSame( 'quote_expired', $result->reason_code() ); self::assertSame( $quote, $result->quote() ); self::assertNull( $quote->accepted_at() );
	}
	public function test_clock_before_creation_or_previous_observation_is_unavailable_not_invalidated(): void {
		$quote = QuoteFixtures::issue(); $before = QuoteFixtures::time()->plus_seconds( -1 );
		self::assertFalse( $quote->usable_at( $before ) ); self::assertSame( 'quote_unavailable', $quote->reason_at( $before ) ); self::assertSame( 'issued', $quote->current_status( $before ) );
		$result = ( new QuoteLifecycle() )->current( $quote, QuoteFixtures::owner(), QuoteFixtures::context(), QuoteFixtures::time()->plus_seconds( 5 ), QuoteFixtures::time()->plus_seconds( 10 ) );
		self::assertFalse( $result->completed() ); self::assertSame( 'quote_unavailable', $result->reason_code() ); self::assertSame( $quote, $result->quote() );
	}
	public function test_acceptance_replay_does_not_retimestamp_or_renew_expiry_after_expiration(): void {
		$quote = QuoteFixtures::issue(); $accepted_at = QuoteFixtures::time()->plus_seconds( 299 ); $reference = QuoteFixtures::reference( $quote->header()->id() );
		$accepted = $quote->accept( QuoteFixtures::owner(), $reference, QuoteFixtures::context(), $accepted_at, 1, $quote->header()->body_digest(), $quote->header()->expires_at() );
		$replay = ( new QuoteLifecycle() )->accept( $accepted, QuoteFixtures::owner(), $reference, QuoteFixtures::context(), QuoteFixtures::time()->plus_seconds( 600 ), 1, $quote->header()->body_digest(), $quote->header()->expires_at() );
		self::assertTrue( $replay->completed() ); self::assertSame( $accepted, $replay->quote() ); self::assertSame( $accepted_at, $accepted->accepted_at() ); self::assertSame( 2, $accepted->revision() ); self::assertSame( 1, $accepted->header()->revision() );
		self::assertSame( 'accepted', $accepted->state() ); self::assertSame( 'expired', $accepted->current_status( QuoteFixtures::time()->plus_seconds( 600 ) ) ); self::assertSame( 'quote_expired', $replay->reason_code() ); self::assertSame( $quote->header()->expires_at(), $accepted->header()->expires_at() );
	}
	public function test_clock_regression_before_accepted_receipt_never_makes_it_current(): void {
		$quote = QuoteFixtures::issue(); $accepted = $quote->accept( QuoteFixtures::owner(), QuoteFixtures::reference( $quote->header()->id() ), QuoteFixtures::context(), QuoteFixtures::time()->plus_seconds( 10 ), 1, $quote->header()->body_digest(), $quote->header()->expires_at() );
		self::assertFalse( $accepted->usable_at( QuoteFixtures::time()->plus_seconds( 9 ) ) ); self::assertSame( 'quote_unavailable', $accepted->reason_at( QuoteFixtures::time()->plus_seconds( 9 ) ) ); self::assertSame( 'accepted', $accepted->state() );
	}
	public function test_hydrated_bad_or_sliding_interval_refuses(): void {
		$facts = QuoteFixtures::issue()->header()->private_facts(); $facts['expires_at'] = QuoteFixtures::time()->plus_seconds( 301 )->sql();
		$this->expectException( \InvalidArgumentException::class ); QuoteHeader::from_array( $facts );
	}
}
