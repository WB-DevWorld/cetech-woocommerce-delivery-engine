<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionNativeCapture, FixedPromotionNativeRequests};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionNativeCartPreparedCapture, LegacyQuoteSourcePlan, LegacyQuoteSourceSnapshot};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteId, QuoteTerms};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{LegacyQuoteProviderFixtures as L, QuoteFixtures as Q};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';

final class FixedPromotionNativeCartPreparationTest extends TestCase {
	public function test_prepared_capture_uses_real_v2_command_and_retains_exact_transient_ports(): void {
		[ $source, $native ] = self::ports(); $until = $native->original_requests()->header()->created_at()->plus_seconds( 120 );
		$slots = [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'owned account' ) ] ];
		$prepared = new FixedPromotionNativeCartPreparedCapture( $source, $native, F::rules(), $slots, $until );
		$token = QuoteId::generate()->value(); $command = $prepared->command( $token );
		self::assertSame( 2, $command->identity()->operation_version ); self::assertSame( 3, $command->context()->format_version() );
		self::assertSame( 'fixed_promotion_native_v1', $command->profile() ); self::assertSame( $slots, $command->promotion_account_slots() );
		self::assertSame( $until, $command->promotion_hold_until() ); self::assertSame( $prepared->context()->digest(), $command->context()->digest() );
		self::assertSame( $source, $prepared->original() ); self::assertSame( $native, $prepared->fresh_native() );
		self::assertSame( $source, $prepared->issue_inputs()->original() ); self::assertSame( $native, $prepared->issue_inputs()->fresh_native() );
		self::assertSame( $source, $prepared->accept_inputs()->original() ); self::assertSame( $native, $prepared->accept_inputs()->fresh_native() );
		self::assertSame( $prepared->terms()->digest(), $prepared->registry()->capture( 'fixed_promotion_native_v1', 1, 'fixed_promotion_native_v1', 1, $prepared->context() )->digest() );
		self::assertSame( '9.5000', $prepared->terms()->private_facts()['groups'][0]['final']['amount'] );
	}
	#[DataProvider( 'invalid_captures' )]
	public function test_capture_refuses_changed_source_missing_account_or_unbounded_deadline( string $mode ): void {
		[ $source, $native ] = self::ports(); $until = $native->original_requests()->header()->created_at()->plus_seconds( 120 );
		$slots = [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'owned account' ) ] ];
		if ( 'source' === $mode ) { $facts = $source->context()->private_facts(); $facts['tax']['context_digest'] = Q::digest( 'changed source context' ); $source = $source->bind_context( QuoteContext::from_array( $facts ) ); }
		if ( 'account' === $mode ) { $slots[0]['account_key'] = null; }
		if ( 'missing' === $mode ) { $slots = []; }
		if ( 'deadline' === $mode ) { $until = $native->original_requests()->header()->expires_at()->plus_seconds( 1 ); }
		if ( 'past_deadline' === $mode ) { $until = $native->original_requests()->header()->created_at(); }
		$this->expectException( \InvalidArgumentException::class );
		new FixedPromotionNativeCartPreparedCapture( $source, $native, F::rules(), $slots, $until );
	}
	public static function invalid_captures(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'source', 'account', 'missing', 'deadline', 'past_deadline' ] ); }
	public function test_capture_does_not_serialize_source_or_native_capabilities(): void {
		[ $source, $native ] = self::ports(); $prepared = new FixedPromotionNativeCartPreparedCapture( $source, $native, F::rules(), [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'owned account' ) ] ], $native->original_requests()->header()->expires_at() );
		foreach ( [ static fn() => json_encode( $prepared, JSON_THROW_ON_ERROR ), static fn() => serialize( $prepared ) ] as $export ) { try { $export(); self::fail( 'Private capability was serialized.' ); } catch ( \LogicException ) { self::assertTrue( true ); } }
		self::assertSame( [ 'private_promotion_cart_capture' => 'redacted' ], $prepared->__debugInfo() );
	}
	#[DataProvider( 'early_refusals' )]
	public function test_native_environment_refuses_before_totals_source_or_control_work( string $mode, int $controls ): void {
		$process = proc_open( [ PHP_BINARY, dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/native-cart-promotion-environment-probe.php', $mode ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes );
		self::assertIsResource( $process ); fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $stderr ); $out = json_decode( $stdout, true, 16, JSON_THROW_ON_ERROR );
		self::assertTrue( $out['refused'] ); self::assertSame( 0, $out['getters'] ); self::assertSame( 0, $out['queries'] ); self::assertSame( $controls, $out['controls'] );
	}
	public static function early_refusals(): array { return [ [ 'off_prepare', 0 ], [ 'generic_profile3', 0 ], [ 'paused_inputs', 1 ], [ 'changed_owner_inputs', 0 ], [ 'changed_selection_inputs', 0 ], [ 'rotated_session_inputs', 0 ] ]; }
	#[DataProvider( 'late_failures' )]
	public function test_failure_after_second_native_totals_removes_discounted_packets_without_recalculation( string $mode ): void {
		$process = proc_open( [ PHP_BINARY, dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/native-cart-promotion-cleanup-probe.php', $mode ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes ); self::assertIsResource( $process ); fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); self::assertSame( 0, proc_close( $process ), $stderr ); $out = json_decode( $stdout, true, 16, JSON_THROW_ON_ERROR );
		self::assertTrue( $out['refused'] ); self::assertSame( 2, $out['totals'] ); self::assertSame( 2, $out['layouts'] );
		if ( 'key_changed' === $mode ) { self::assertSame( 2, $out['writes'] ); self::assertSame( '9.50', $out['original']['shipping_for_package_0']['amount'] ); self::assertFalse( $out['request_reset'] ); }
		else { self::assertSame( 'twice' === $mode ? 4 : 3, $out['writes'] ); self::assertSame( [ 'unrelated' => 'preserve' ], $out['original'] ); self::assertTrue( $out['request_reset'] ); }
		self::assertSame( 'FOREIGN', $out['foreign']['shipping_for_package_0']['amount'] );
		self::assertSame( 'key_changed' === $mode ? 0 : ( 'twice' === $mode ? 2 : 1 ), $out['readbacks'] ); self::assertSame( $out['readbacks'], $out['retired'] );
		if ( in_array( $mode, [ 'stale_read', 'fresh_stale_read' ], true ) ) { self::assertFalse( $out['abort_confirmed'] ); }
		if ( 'fresh' === $mode ) { self::assertTrue( $out['abort_confirmed'] ); }
	}
	public static function late_failures(): array { return [ [ 'late' ], [ 'cold' ], [ 'swapped' ], [ 'twice' ], [ 'key_changed' ], [ 'stale_read' ], [ 'fresh' ], [ 'fresh_stale_read' ] ]; }
	private static function ports(): array {
		$context = F::original_context(); $terms = F::original_terms(); $legacy = L::plan();
		$plan = LegacyQuoteSourcePlan::create( F::owner(), $context, $legacy->member_proofs(), $legacy->rate_ranges(), $legacy->fences() );
		$range = $plan->rate_ranges()[0]; $source = LegacyQuoteSourceSnapshot::captured( $plan, $context, [], [ LegacyQuoteSourcePlan::range_key( $range ) => [ L::card() ] ], Q::time() );
		$facts = $context->private_facts(); $facts['groups'][0]['policy_digest'] = $source->policy_digest(); $facts['groups'][0]['candidate_digest'] = $source->candidate_digest( 20, 50, 'GHS' ); $facts['groups'][0]['candidate_count'] = 1; $context = QuoteContext::from_array( $facts ); $source = $source->bind_context( $context );
		$facts = $terms->private_facts(); $facts['groups'][0]['policy_digest'] = $source->policy_digest(); $terms = QuoteTerms::from_array( $facts );
		$requests = FixedPromotionNativeRequests::from_captured( F::original_header( $context, $terms ), $context, $terms, [ [ 'component_key' => F::key(), 'plan' => F::plan( $terms ) ] ], [ [ 'component_key' => F::key(), 'legacy_group_id' => 'in_store|delivery|20' ] ] );
		return [ $source, ( new FixedPromotionNativeCapture( F::source() ) )->capture( F::owner(), $requests ) ];
	}
}
