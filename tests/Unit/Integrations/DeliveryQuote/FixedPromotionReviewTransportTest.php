<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Integrations\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{CartQuoteResult, CartQuoteReviewService, FixedPromotionQuoteIssuedEvidence, QuoteProjection};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{DeliveryQuote, QuoteStoredRow};
use CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteReviewRuntime;
use CetechDeliveryEngine\Presentation\Frontend\QuoteReviewRenderer;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\PromotionNativeHandoffFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/Support/DeliveryPromotion/PromotionNativeHandoffFixture.php';

/** Real format-3 shopper projections through the existing transport; the core service is a declared spy. */
final class FixedPromotionReviewTransportTest extends TestCase {

	private array $actions_before;
	private bool $actions_present;

	protected function setUp(): void {
		$this->actions_present = array_key_exists( 'cetech_de_test_actions', $GLOBALS );
		$this->actions_before = $GLOBALS['cetech_de_test_actions'] ?? [];
		$GLOBALS['cetech_de_test_actions'] = [];
	}

	protected function tearDown(): void {
		if ( $this->actions_present ) { $GLOBALS['cetech_de_test_actions'] = $this->actions_before; }
		else { unset( $GLOBALS['cetech_de_test_actions'] ); }
	}

	public function test_default_off_does_not_register_or_call_the_promotion_service(): void {
		$service = new PromotionReviewTransportService(); $runtime = new QuoteReviewRuntime( $service );
		$runtime->register();
		self::assertSame( [], $GLOBALS['cetech_de_test_actions'] );
		self::assertSame( 'unavailable', $runtime->current_facts()['status'] );
		self::assertSame( [], $service->calls );
	}

	public function test_generic_format_three_history_does_not_become_current_review_authority(): void {
		$row = PromotionNativeHandoffFixture::create()['quote'];
		$quote = DeliveryQuote::issue( $row->header(), $row->context(), $row->terms() );
		$projection = QuoteProjection::for_shopper( $quote, $row->accepted_at(), RequestContext::create() )->fields();
		self::assertFalse( $projection['currently_applicable'] );
		self::assertSame( 'quote_unavailable', $projection['reason_code'] );
		self::assertSame( 'applied', $projection['money'][0]['promotion']['state'] );
		self::assertSame( '3.0000', $projection['money'][0]['promotion']['amount']['amount'] );
	}

	public function test_read_only_current_projects_actual_promotion_money_without_private_evidence(): void {
		$service = new PromotionReviewTransportService(); $runtime = new QuoteReviewRuntime( $service, true );
		$facts = $runtime->current_facts();
		self::assertSame( [ 'current' ], array_column( $service->calls, 'method' ) );
		self::assertSame( 'review_required', $facts['status'] );
		self::assertTrue( $facts['can_confirm'] );
		$component = $facts['quote']['money'][0];
		self::assertSame( 'applied', $component['promotion']['state'] );
		self::assertSame( '3.0000', $component['promotion']['amount']['amount'] );
		self::assertSame( '12.5000', $component['list_price']['amount'] );
		self::assertSame( '9.5000', $component['final_price']['amount'] );
		$encoded = json_encode( $facts, JSON_THROW_ON_ERROR );
		foreach ( [ 'PRIVATE-', 'account_key', 'funding_source', 'owner_digest', 'principal_hash', 'session_hash', 'header_json', 'body_digest', 'recovery_json', 'acceptance_handle' ] as $private ) { self::assertStringNotContainsString( $private, $encoded ); }
	}

	public function test_classic_refresh_and_confirm_keep_the_existing_exact_public_contract(): void {
		$service = new PromotionReviewTransportService(); $runtime = new QuoteReviewRuntime( $service, true );
		$issued = $runtime->classic_response( [ '_wpnonce' => 'test-nonce-' . QuoteReviewRuntime::NONCE_ACTION, 'action' => 'refresh', 'generation' => '0', 'review_token' => PromotionReviewTransportService::TOKEN ], 'POST' );
		$accepted = $runtime->classic_response( [ '_wpnonce' => 'test-nonce-' . QuoteReviewRuntime::NONCE_ACTION, 'action' => 'confirm', 'generation' => '1' ], 'POST' );
		self::assertSame( 'review_required', $issued['status'] ); self::assertSame( 'confirmed', $accepted['status'] );
		self::assertSame( [ 'refresh', 'confirm' ], array_column( $service->calls, 'method' ) );
		self::assertSame( PromotionReviewTransportService::TOKEN, $service->calls[0]['token'] );
		self::assertSame( [ 0, 1 ], array_column( $service->calls, 'generation' ) );
		self::assertNotSame( $service->calls[0]['request'], $service->calls[1]['request'] );
	}

	public function test_store_callback_reuses_the_same_service_without_a_second_public_command_shape(): void {
		$service = new PromotionReviewTransportService(); $runtime = new QuoteReviewRuntime( $service, true );
		$runtime->handle_store_api_update( [ 'action' => 'refresh', 'generation' => 0, 'review_token' => PromotionReviewTransportService::TOKEN ] );
		$runtime->handle_store_api_update( [ 'action' => 'confirm', 'generation' => 1 ] );
		$facts = $runtime->current_facts();
		self::assertSame( [ 'refresh', 'confirm', 'current' ], array_column( $service->calls, 'method' ) );
		self::assertSame( 'confirmed', $facts['status'] ); self::assertFalse( $facts['can_confirm'] );
		$schema = $runtime->schema();
		$money = $schema['quote']['oneOf'][0]['properties']['money']['items']['properties'];
		self::assertFalse( $money['promotion']['additionalProperties'] );
		self::assertSame( [ 'state', 'amount' ], array_keys( $money['promotion']['properties'] ) );
	}

	public function test_classic_nonce_and_method_refusals_happen_before_the_promotion_service(): void {
		$service = new PromotionReviewTransportService(); $runtime = new QuoteReviewRuntime( $service, true );
		foreach ( [ [ 'GET', [], 405 ], [ 'POST', [ 'action' => 'confirm', 'generation' => '1' ], 403 ], [ 'POST', [ '_wpnonce' => 'test-nonce-wc_store_api', 'action' => 'confirm', 'generation' => '1' ], 403 ] ] as [ $method, $data, $status ] ) {
			try { $runtime->classic_response( $data, $method ); self::fail( 'Untrusted transport reached the promotion service.' ); }
			catch ( \RuntimeException $error ) { self::assertSame( $status, $error->getCode() ); }
		}
		self::assertSame( [], $service->calls );
	}

	#[DataProvider( 'forged_authority' )]
	public function test_browser_cannot_supply_rule_funding_or_foreign_quote_authority( string $field, mixed $value ): void {
		$service = new PromotionReviewTransportService(); $runtime = new QuoteReviewRuntime( $service, true );
		try { $runtime->dispatch( [ 'action' => 'confirm', 'generation' => 1, $field => $value ] ); self::fail( 'Browser authority reached the promotion service.' ); }
		catch ( \RuntimeException $error ) { self::assertSame( 400, $error->getCode() ); }
		self::assertSame( [], $service->calls );
	}

	public static function forged_authority(): array {
		return [ [ 'rule_id', 9 ], [ 'account_key', str_repeat( 'a', 64 ) ], [ 'funding_source', [ 'code' => 'foreign' ] ], [ 'quote_id', PromotionReviewTransportService::TOKEN ], [ 'owner_digest', str_repeat( 'b', 64 ) ] ];
	}

	public function test_existing_renderer_shows_the_promotion_and_uses_non_submit_review_controls(): void {
		$service = new PromotionReviewTransportService(); $result = $service->current( RequestContext::create() );
		$html = ( new QuoteReviewRenderer() )->table_row( $result );
		self::assertStringContainsString( 'GHS 9.50', $html );
		self::assertStringContainsString( 'Confirm delivery price', $html );
		self::assertStringContainsString( 'type="button"', $html ); self::assertStringNotContainsString( 'type="submit"', $html );
		self::assertStringNotContainsString( 'PRIVATE-', $html ); self::assertStringNotContainsString( 'account_key', $html );
	}
}

final class PromotionReviewTransportService implements CartQuoteReviewService {
	public const TOKEN = 'c242cda8-f75e-4cdb-9004-f5b8569c0386';
	public array $calls = [];
	private bool $accepted = false;
	private int $generation = 1;
	private array $fixture;

	public function __construct() { $this->fixture = PromotionNativeHandoffFixture::create(); }
	public function current( RequestContext $request ): CartQuoteResult { $this->calls[] = [ 'method' => 'current', 'request' => $request ]; return $this->result( $request ); }
	public function refresh( string $original_token, int $expected_generation, RequestContext $request ): CartQuoteResult { $this->calls[] = [ 'method' => 'refresh', 'token' => $original_token, 'generation' => $expected_generation, 'request' => $request ]; $this->generation = $expected_generation + 1; return $this->result( $request ); }
	public function confirm( int $expected_generation, RequestContext $request ): CartQuoteResult { $this->calls[] = [ 'method' => 'confirm', 'generation' => $expected_generation, 'request' => $request ]; $this->accepted = true; return $this->result( $request ); }
	public function retry( int $expected_generation, RequestContext $request ): CartQuoteResult { $this->calls[] = [ 'method' => 'retry', 'generation' => $expected_generation, 'request' => $request ]; return $this->result( $request ); }
	private function result( RequestContext $request ): CartQuoteResult {
		$fixture = $this->fixture; $evidence = $fixture['evidence'];
		if ( ! $this->accepted ) {
			$row = QuoteStoredRow::from_row( array_replace( $fixture['quote']->row(), [ 'state' => 'issued', 'revision' => 1, 'accepted_at' => null, 'transition_at' => null ] ) );
			// Declared typed fixture observations exercise transport only; no SQL read is claimed.
			$evidence = new FixedPromotionQuoteIssuedEvidence( $fixture['acceptance']->issue(), $row, $fixture['evidence']->evaluated_at(), $fixture['evidence']->validity() );
		}
		return CartQuoteResult::create( $this->accepted ? 'confirmed' : 'review_required', $this->generation, $request, QuoteProjection::for_fixed_promotion_current( $evidence, $request ), true, ! $this->accepted );
	}
}
