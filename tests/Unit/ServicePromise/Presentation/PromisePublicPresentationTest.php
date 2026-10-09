<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Presentation;

use CetechDeliveryEngine\Application\DeliveryQuote\{CartQuoteResult, QuoteProjectionResult};
use CetechDeliveryEngine\Application\ServicePromise\Presentation\{PublicPromiseFormatter, PromisePublicProjection};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\ServicePromise\{PublicPromiseView, PromiseResult};
use CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseQuotePacket;
use CetechDeliveryEngine\Presentation\Frontend\{PromisePublicRenderer, QuoteReviewRenderer};
use CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff\PromiseHandoffFixture;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/Support/ServicePromise/Handoff/PromiseHandoffFixture.php';

final class PromisePublicPresentationTest extends TestCase {
	public function test_absolute_local_date_uses_captured_zone_across_midnight_instead_of_site_zone(): void {
		$view = PublicPromiseView::from_array( [ 'format_version' => 1, 'service_label' => 'Doorstep delivery', 'state' => 'absolute_window', 'display_timezone' => 'Asia/Tokyo', 'reason_codes' => [], 'from' => '2026-10-09 23:30:00.000000', 'until' => '2026-10-10 00:30:00.000000' ] );
		$GLOBALS['cetech_de_test_options']['timezone_string'] = 'America/New_York';
		try { $text = ( new PublicPromiseFormatter() )->text( $view ); self::assertStringContainsString( '2026-10-10 08:30', $text ); self::assertStringContainsString( '2026-10-10 09:30', $text ); self::assertStringContainsString( '(Asia/Tokyo)', $text ); self::assertStringNotContainsString( '2026-10-09 19:30', $text ); }
		finally { unset( $GLOBALS['cetech_de_test_options']['timezone_string'] ); }
	}
	public function test_relative_units_are_distinct_and_never_mint_absolute_dates(): void {
		foreach ( [ 'elapsed_minutes' => 'minutes', 'calendar_days' => 'calendar days', 'business_minutes' => 'business minutes', 'business_days' => 'business days' ] as $unit => $word ) {
			$view = self::relative( $unit ); $text = ( new PublicPromiseFormatter() )->text( $view );
			self::assertStringContainsString( '1–2 ' . $word . ' after payment confirmation (UTC)', $text ); self::assertStringNotContainsString( '2026-', $text );
		}
	}
	public function test_explicit_zero_is_not_unavailable_and_single_minute_is_not_plural(): void {
		$formatter = new PublicPromiseFormatter(); self::assertStringContainsString( '0 minutes after payment confirmation', $formatter->text( self::relative( 'elapsed_minutes', 0, 0 ) ) ); self::assertStringContainsString( '1 minute after payment confirmation', $formatter->text( self::relative( 'elapsed_minutes', 1, 1 ) ) );
	}
	public function test_formatter_honors_locale_callbacks_without_altering_structured_facts(): void {
		$view = self::relative( 'calendar_days' ); $formatter = new PublicPromiseFormatter( static fn( string $message ): string => str_replace( 'after payment confirmation', 'après confirmation du paiement', $message ), static fn( string $one, string $many, int $count ): string => 1 === $count ? 'jour calendaire' : 'jours calendaires' );
		$out = $formatter->format( $view ); self::assertSame( $view->fields(), $out['view'] ); self::assertStringContainsString( '1–2 jours calendaires après confirmation du paiement', $out['text'] );
	}
	public function test_incomplete_destination_exposes_no_precise_window_and_no_admission(): void {
		$view = PublicPromiseView::from_result( PromiseHandoffFixture::result(), 'delivery' ); $out = PromisePublicProjection::hypothetical( [ $view ], false );
		self::assertTrue( $out['preliminary'] ); self::assertFalse( $out['admission'] ); self::assertFalse( $out['destination_complete'] ); self::assertSame( [], $out['views'] ); self::assertSame( '', $out['text'] ); self::assertStringContainsString( 'Preliminary estimate', $out['notice'] ); self::assertStringNotContainsString( '2026-', json_encode( $out ) );
		self::assertStringContainsString( 'data-cetech-de-promise-preliminary="1"', PromisePublicRenderer::preliminary( $out ) );
	}
	public function test_complete_hypothetical_view_is_still_preliminary_with_no_admission_or_private_source(): void {
		$result = PromiseHandoffFixture::result(); $view = PublicPromiseView::from_result( $result, 'delivery' ); $out = PromisePublicProjection::hypothetical( [ $view ], true );
		self::assertSame( [ $view->fields() ], $out['views'] ); self::assertTrue( $out['preliminary'] ); self::assertFalse( $out['admission'] );
		foreach ( [ 'input_digest', 'result_digest', 'origin', 'source_receipts', 'capacity', 'policy_id', 'terminal_component_id', 'component_key' ] as $private ) { self::assertStringNotContainsString( $private, json_encode( $out ) ); }
	}
	public function test_all_closed_refusal_codes_have_safe_explanation_without_provider_details(): void {
		$formatter = new PublicPromiseFormatter(); foreach ( PromiseResult::REASON_CODES as $reason ) {
			$view = PublicPromiseView::from_array( [ 'format_version' => 1, 'service_label' => 'Standard', 'state' => 'unavailable', 'display_timezone' => 'UTC', 'reason_codes' => [ $reason ] ] );
			$out = $formatter->format( $view ); self::assertNotEmpty( $out['reason_texts'][0] ); self::assertStringNotContainsString( 'merchant-processing', json_encode( $out ) ); self::assertStringNotContainsString( '1970', $out['text'] );
		}
	}
	public function test_original_packet_projection_and_classic_view_keep_exact_captured_text_after_locale_change(): void {
		$quote = PromiseHandoffFixture::quote(); $packet = $quote->terms()->promise_packet(); $before = $packet->to_private_json(); $projection = PromisePublicProjection::quote( $packet );
		self::assertSame( [ 'views', 'customer_text' ], array_keys( $projection['groups'][0] ) ); self::assertSame( 'Standard delivery: 10:10–10:20 UTC', $projection['groups'][0]['customer_text'] );
		$request = RequestContext::create(); $shopper = QuoteProjectionResult::shopper( $quote, PromiseHandoffFixture::time(), $request );
		self::assertSame( $projection, $shopper->fields()['promise'] ); $html = ( new QuoteReviewRenderer() )->table_row( CartQuoteResult::create( 'review_required', 1, $request, $shopper, true, true ) );
		self::assertStringContainsString( 'Standard delivery: 10:10–10:20 UTC', $html ); self::assertStringContainsString( 'data-cetech-de-original-promise="1"', $html ); self::assertSame( $before, $packet->to_private_json() );
		foreach ( [ 'input_json', 'result_json', 'source_receipts', 'capacity_source', 'merchant-processing', 'policy_id', '"origin":', 'principal_hash', 'session_hash', 'component_key' ] as $private ) { self::assertStringNotContainsString( $private, json_encode( $projection ) ); }
	}
	public function test_legacy_shopper_projection_has_no_new_member_and_stripped_new_history_has_no_invented_promise(): void {
		self::assertArrayNotHasKey( 'promise', QuoteProjectionResult::shopper( QuoteFixtures::issue(), QuoteFixtures::time(), RequestContext::create() )->fields() );
	}
	public function test_unvalidated_private_array_cannot_enter_hypothetical_view(): void {
		$this->expectException( \InvalidArgumentException::class ); PromisePublicProjection::hypothetical( [ PromiseHandoffFixture::result()->private_facts() ], true );
	}
	private static function relative( string $unit, int $min = 1, int $max = 2 ): PublicPromiseView { return PublicPromiseView::from_array( [ 'format_version' => 1, 'service_label' => 'Standard', 'state' => 'relative_window', 'display_timezone' => 'UTC', 'reason_codes' => [], 'relative_explanation' => 'after_payment_confirmation', 'min' => $min, 'max' => $max, 'unit' => $unit, 'known_zero' => 0 === $min && 0 === $max ] ); }
}
