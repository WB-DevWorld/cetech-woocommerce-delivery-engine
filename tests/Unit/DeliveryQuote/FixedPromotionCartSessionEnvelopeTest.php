<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{CartQuoteSessionEnvelope, FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteIssueCommand, QuoteIssueCommand, QuotePreparationCommand};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteHeader, QuoteId, QuoteReference};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';

/** Bounded recovery contracts, without a native session, merchant account or database. */
final class FixedPromotionCartSessionEnvelopeTest extends TestCase {
	private const TOKEN = '71eb41b0-d08d-4a25-9808-6ad03e6aa001';
	private function issue(): FixedPromotionQuoteIssueCommand {
		$p = F::mixed_provider(); $slots = [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'explicit cart pool' ) ], [ 'component_key' => F::second_key(), 'account_key' => null ] ];
		$original = QuoteIssueCommand::fixed_promotion( F::owner(), $p->context(), self::TOKEN, $slots, Q::time()->plus_seconds( 45 ) );
		$id = QuoteId::generate(); $ref = QuoteReference::generate( $id ); $header = QuoteHeader::issue( $id, F::owner(), $p->context(), $p->terms(), Q::time(), $original->namespace_hashes( $id ), 'fixed_promotion_native_v1', 1, $ref );
		return FixedPromotionQuoteIssueCommand::from_recovery_array( [ 'format_version' => 1, 'original_issue' => $original->to_promotion_recovery_array(), 'header_json' => $header->to_private_json(), 'terms_json' => $p->terms()->to_private_json(), 'reference' => $ref->public_fields() ] );
	}
	private function begin(): CartQuoteSessionEnvelope { return CartQuoteSessionEnvelope::begin( QuotePreparationCommand::fixed_promotion( F::owner(), Q::digest( 'native draft' ), self::TOKEN ), 1, 1791352800 ); }
	private function staged(): CartQuoteSessionEnvelope { return $this->begin()->stage_promotion( $this->issue() ); }
	public function test_recovery_roundtrip_preserves_original_deadline_slots_and_accept_identity_without_a_capability(): void {
		$command = $this->issue(); $begin = $this->begin(); $staged = $begin->stage_promotion( $command ); $copy = CartQuoteSessionEnvelope::from_private_json( $staged->to_private_json() );
		self::assertTrue( $begin->follows( null ) ); self::assertTrue( $staged->follows( $begin ) ); self::assertSame( 2, json_decode( $copy->to_private_json(), true, 16, JSON_THROW_ON_ERROR )['format_version'] );
		self::assertSame( $command->to_recovery_array(), $copy->promotion_issue()->to_recovery_array() ); self::assertNull( $copy->promotion_issue()->lease() );
		self::assertSame( $command->original_issue()->intent_digest(), $copy->original_issue()->intent_digest() ); self::assertSame( $command->account_slots(), $copy->original_issue()->promotion_account_slots() ); self::assertTrue( $command->hold_until()->equals( $copy->original_issue()->promotion_hold_until() ) );
		self::assertSame( $command->header()->to_private_json(), $copy->header()->to_private_json() ); self::assertSame( $command->reference()->public_fields(), $copy->reference()->public_fields() );
		self::assertSame( FixedPromotionQuoteAcceptCommand::from_issue( $command )->intent()->fingerprint(), FixedPromotionQuoteAcceptCommand::from_issue( $copy->promotion_issue() )->intent()->fingerprint() );
		self::assertCount( 2, $copy->rate_references() ); foreach ( $copy->rate_references() as $ref ) { self::assertTrue( $ref->matches( $command->header(), $ref->component_key(), 1 ) ); }
		$issued = $copy->with_phase( 'issued' ); $accepting = $issued->with_phase( 'accepting' ); $confirmed = $accepting->with_phase( 'confirmed' ); self::assertTrue( $issued->follows( $copy ) ); self::assertTrue( $accepting->follows( $issued ) ); self::assertTrue( $confirmed->follows( $accepting ) ); self::assertSame( $command->to_recovery_array(), $confirmed->promotion_issue()->to_recovery_array() );
	}
	public function test_recovery_is_detached_from_external_array_references_and_implicit_exports_are_refused(): void {
		$staged = $this->staged(); $facts = json_decode( $staged->to_private_json(), true, 16, JSON_THROW_ON_ERROR ); $handle =& $facts['promotion_issue']['reference']['acceptance_handle']; $original = $handle;
		$copy = CartQuoteSessionEnvelope::from_private_array( $facts ); $handle = Q::digest( 'external mutation' ); self::assertSame( $original, $copy->reference()->handle() );
		self::assertSame( [ 'private_cart_quote_session' => 'redacted' ], $copy->__debugInfo() );
		foreach ( [ static fn() => json_encode( $copy, JSON_THROW_ON_ERROR ), static fn() => serialize( $copy ) ] as $export ) { try { $export(); self::fail( 'Private session exported implicitly.' ); } catch ( \LogicException ) { self::assertSame( $staged->to_private_json(), $copy->to_private_json() ); } }
	}
	public static function corruptions(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'legacy preparation', 'legacy fields', 'live input', 'phase without command', 'preparing with command', 'foreign token', 'missing rate', 'duplicate handle', 'generation', 'recovery oversized', 'envelope oversized' ] ); }
	#[DataProvider( 'corruptions' )]
	public function test_conflicting_or_unbounded_promotion_sessions_refuse( string $case ): void {
		$facts = json_decode( $this->staged()->to_private_json(), true, 16, JSON_THROW_ON_ERROR );
		if ( 'legacy preparation' === $case ) { $facts['preparation'] = QuotePreparationCommand::create( F::owner(), Q::digest( 'native draft' ), self::TOKEN, 'legacy_fixed_base_v1', 1, 'legacy_fixed_base_v1', 1 )->to_private_array(); }
		elseif ( 'legacy fields' === $case ) { $facts['header_json'] = $facts['promotion_issue']['header_json']; }
		elseif ( 'live input' === $case ) { $facts['promotion_issue']['inputs'] = new \stdClass(); }
		elseif ( 'phase without command' === $case ) { $facts['promotion_issue'] = null; }
		elseif ( 'preparing with command' === $case ) { $facts['phase'] = 'preparing'; }
		elseif ( 'foreign token' === $case ) { $facts['preparation']['original_token'] = '71eb41b0-d08d-4a25-9808-6ad03e6aa002'; }
		elseif ( 'missing rate' === $case ) { array_pop( $facts['rate_references'] ); }
		elseif ( 'duplicate handle' === $case ) { $facts['rate_references'][1] = $facts['rate_references'][0]; }
		elseif ( 'generation' === $case ) { $facts['generation'] = 2; }
		elseif ( 'recovery oversized' === $case ) { $facts['promotion_issue']['terms_json'] = str_repeat( 'x', FixedPromotionQuoteIssueCommand::MAX_RECOVERY_BYTES + 1 ); }
		else { $this->expectException( \InvalidArgumentException::class ); CartQuoteSessionEnvelope::from_private_json( str_repeat( 'x', CartQuoteSessionEnvelope::MAX_PROMOTION_BYTES + 1 ) ); return; }
		$this->expectException( \InvalidArgumentException::class ); CartQuoteSessionEnvelope::from_private_array( $facts );
	}
	public function test_pending_generation_cannot_be_replaced_and_same_generation_cannot_change_original_command(): void {
		$staged = $this->staged(); $next = CartQuoteSessionEnvelope::begin( $staged->preparation(), 2, $staged->expires_at() ); self::assertFalse( $next->follows( $staged ) );
		$facts = json_decode( $staged->to_private_json(), true, 16, JSON_THROW_ON_ERROR ); $facts['revision']++; $facts['phase'] = 'issued'; $facts['promotion_issue']['original_issue']['hold_until'] = Q::time()->plus_seconds( 44 )->sql(); $retargeted = CartQuoteSessionEnvelope::from_private_array( $facts ); self::assertFalse( $retargeted->follows( $staged ) );
		self::assertSame( 131072, CartQuoteSessionEnvelope::MAX_BYTES ); self::assertLessThanOrEqual( CartQuoteSessionEnvelope::MAX_PROMOTION_BYTES, strlen( $staged->to_private_json() ) );
	}
}
