<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\CartQuoteResult;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteProjectionResult;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Presentation\Frontend\QuoteReviewRenderer;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteFixtures.php';

final class QuoteReviewRendererTest extends TestCase {
	public function test_exact_review_money_uses_native_display_total_and_explicit_non_submit_confirmation(): void {
		$request = RequestContext::create(); $quote = QuoteFixtures::issue(); $projection = QuoteProjectionResult::shopper( $quote, QuoteFixtures::time(), $request );
		$result = CartQuoteResult::create( 'review_required', 4, $request, $projection, true, true ); $html = ( new QuoteReviewRenderer() )->table_row( $result );
		self::assertStringContainsString( '<tr class="cetech-de-quote-review-row">', $html ); self::assertStringContainsString( 'GHS 12.50', $html );
		self::assertStringContainsString( 'Confirm delivery price', $html ); self::assertStringContainsString( 'type="button"', $html ); self::assertStringNotContainsString( 'type="submit"', $html );
		self::assertStringNotContainsString( '<form', $html ); self::assertStringNotContainsString( 'acceptance_handle', $html ); self::assertStringNotContainsString( 'body_digest', $html );
		preg_match( '/data-quote-review-facts="([^"]+)"/', $html, $match );
		$roundtrip = json_decode( html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ), true, 16, JSON_THROW_ON_ERROR ); self::assertSame( $result->shopper_facts(), $roundtrip );
		self::assertStringContainsString( $quote->header()->expires_at()->iso_utc(), $html );
	}

	public function test_unknown_outcome_shows_only_same_request_retry_and_no_fresh_confirmation(): void {
		$result = CartQuoteResult::create( 'unconfirmed', 7, RequestContext::create(), can_retry: true ); $html = ( new QuoteReviewRenderer() )->table_row( $result );
		self::assertStringContainsString( 'We could not confirm this quote. Retry the same request.', $html ); self::assertStringContainsString( 'data-quote-review-action="retry"', $html );
		self::assertStringNotContainsString( 'data-quote-review-action="refresh"', $html ); self::assertStringNotContainsString( 'data-quote-review-action="confirm"', $html ); self::assertStringNotContainsString( 'GHS', $html );
	}

	public function test_expired_or_changed_view_preserves_customer_recovery_without_claiming_current_acceptance(): void {
		foreach ( [ 'expired', 'changed' ] as $status ) {
			$result = CartQuoteResult::create( $status, 5, RequestContext::create(), can_refresh: true ); $html = ( new QuoteReviewRenderer() )->table_row( $result );
			self::assertStringContainsString( 'data-quote-review-action="refresh"', $html ); self::assertStringNotContainsString( 'data-quote-review-action="confirm"', $html ); self::assertStringNotContainsString( 'confirmed for', $html );
		}
	}

	public function test_no_private_domain_facts_are_serialized_even_when_present_in_the_valid_source_quote(): void {
		$quote = QuoteFixtures::issue(); $request = RequestContext::create(); $projection = QuoteProjectionResult::shopper( $quote, QuoteFixtures::time(), $request );
		$html = ( new QuoteReviewRenderer() )->table_row( CartQuoteResult::create( 'review_required', 1, $request, $projection, true, true ) );
		foreach ( [ $quote->header()->body_digest(), $quote->header()->material_digest(), $quote->header()->owner()->digest(), 'cost_provider_unavailable', 'fixture_none_v1', 'fixture_key_1', 'source', 'inventory' ] as $private ) { self::assertStringNotContainsString( $private, $html ); }
		self::assertStringContainsString( 'Fixture delivery', $html ); self::assertStringContainsString( $quote->header()->id()->value(), $html );
	}

	public function test_labels_are_rendered_as_text_and_safe_fact_json_is_attribute_escaped(): void {
		$terms = json_decode( QuoteFixtures::terms()->to_private_json(), true, 16, JSON_THROW_ON_ERROR ); $terms['groups'][0]['customer_label'] = 'Delivery "A" & B';
		$quote = QuoteFixtures::issue( terms: \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms::from_array( $terms ) ); $request = RequestContext::create();
		$html = ( new QuoteReviewRenderer() )->table_row( CartQuoteResult::create( 'review_required', 1, $request, QuoteProjectionResult::shopper( $quote, QuoteFixtures::time(), $request ), true, true ) );
		self::assertStringContainsString( 'Delivery &quot;A&quot; &amp; B', $html ); self::assertStringNotContainsString( 'data-quote-review-facts="{"', $html );
		self::assertStringNotContainsString( '<script', $html );
	}
}
