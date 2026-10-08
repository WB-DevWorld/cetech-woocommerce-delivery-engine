<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Integrations\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\CartQuoteResult;
use CetechDeliveryEngine\Application\DeliveryQuote\CartQuoteReviewService;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteReviewRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuoteReviewRuntimeTest extends TestCase {
	private array $server;
	private array $post;
	protected function setUp(): void {
		$this->server = $_SERVER; $this->post = $_POST;
		$GLOBALS['cetech_de_test_actions'] = [];
		$GLOBALS['cetech_de_test_store_api_endpoints'] = [];
		$GLOBALS['cetech_de_test_quote_update_callbacks'] = [];
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) { eval( 'function woocommerce_store_api_register_endpoint_data(array $args):void { $GLOBALS["cetech_de_test_store_api_endpoints"][]=$args; }' ); }
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) { eval( 'function woocommerce_store_api_register_update_callback(array $args):void { $GLOBALS["cetech_de_test_quote_update_callbacks"][]=$args; }' ); }
	}
	protected function tearDown(): void { $_SERVER = $this->server; $_POST = $this->post; }
	private function spy(): QuoteReviewServiceSpy { return new QuoteReviewServiceSpy(); }
	private function runtime( QuoteReviewServiceSpy $spy, bool $mounted = true ): QuoteReviewRuntime { return new QuoteReviewRuntime( $spy, $mounted ); }

	public function test_default_off_mount_registers_no_shopper_transport_and_denies_direct_actions(): void {
		$spy = $this->spy(); $runtime = new QuoteReviewRuntime( $spy ); $runtime->register(); $runtime->register_store_api();
		self::assertSame( [], $GLOBALS['cetech_de_test_actions'] ); self::assertSame( [], $GLOBALS['cetech_de_test_store_api_endpoints'] );
		$facts = $runtime->current_facts(); self::assertSame( 'unavailable', $facts['status'] ); self::assertFalse( $facts['can_refresh'] ); self::assertNull( $facts['quote'] ); self::assertSame( [], $spy->calls );
		try { $runtime->dispatch( [ 'action' => 'confirm', 'generation' => 0 ] ); self::fail( 'Unmounted transport ran.' ); }
		catch ( \RuntimeException $error ) { self::assertSame( 'Delivery quoting is temporarily unavailable. Try again.', $error->getMessage() ); }
		self::assertSame( [], $spy->calls );
	}

	public function test_explicit_mount_uses_native_cart_and_checkout_read_callbacks_and_one_explicit_update_callback(): void {
		$spy = $this->spy(); $runtime = $this->runtime( $spy ); $runtime->register(); $runtime->register(); $runtime->register_store_api();
		self::assertSame( [ 'cart', 'checkout' ], array_column( $GLOBALS['cetech_de_test_store_api_endpoints'], 'endpoint' ) );
		foreach ( $GLOBALS['cetech_de_test_store_api_endpoints'] as $endpoint ) {
			self::assertSame( QuoteReviewRuntime::NAMESPACE, $endpoint['namespace'] ); self::assertSame( [ $runtime, 'current_facts' ], $endpoint['data_callback'] );
			self::assertSame( ARRAY_A, $endpoint['schema_type'] ); self::assertSame( $runtime->schema(), ( $endpoint['schema_callback'] )() );
		}
		self::assertCount( 1, $GLOBALS['cetech_de_test_quote_update_callbacks'] );
		self::assertSame( QuoteReviewRuntime::NAMESPACE, $GLOBALS['cetech_de_test_quote_update_callbacks'][0]['namespace'] );
		self::assertArrayHasKey( 'wc_ajax_' . QuoteReviewRuntime::AJAX_ACTION, $GLOBALS['cetech_de_test_actions'] );
		self::assertArrayNotHasKey( 'woocommerce_after_calculate_totals', $GLOBALS['cetech_de_test_actions'] );
		self::assertArrayNotHasKey( 'woocommerce_checkout_order_created', $GLOBALS['cetech_de_test_actions'] );
		self::assertSame( [], $spy->calls );
	}

	public function test_readonly_dto_rechecks_current_after_native_totals_and_never_uses_mutation_result_as_authority(): void {
		$spy = $this->spy(); $runtime = $this->runtime( $spy );
		$runtime->handle_store_api_update( [ 'action' => 'refresh', 'generation' => 2, 'review_token' => QuoteReviewServiceSpy::TOKEN ] );
		$spy->status = 'expired'; $first = $runtime->current_facts(); $spy->status = 'changed'; $second = $runtime->current_facts();
		self::assertSame( 'expired', $first['status'] ); self::assertSame( 'changed', $second['status'] );
		self::assertSame( [ 'refresh', 'current', 'current' ], array_column( $spy->calls, 'method' ) );
		self::assertNotSame( $spy->calls[1]['request'], $spy->calls[2]['request'] );
	}

	public function test_explicit_actions_pass_exact_token_generation_and_fresh_server_request_context(): void {
		$spy = $this->spy(); $runtime = $this->runtime( $spy );
		$runtime->dispatch( [ 'action' => 'refresh', 'generation' => 7, 'review_token' => QuoteReviewServiceSpy::TOKEN ] );
		$runtime->dispatch( [ 'action' => 'confirm', 'generation' => 8 ] ); $runtime->dispatch( [ 'action' => 'retry', 'generation' => 8 ] );
		self::assertSame( [ 'refresh', 'confirm', 'retry' ], array_column( $spy->calls, 'method' ) );
		self::assertSame( QuoteReviewServiceSpy::TOKEN, $spy->calls[0]['token'] ); self::assertSame( [ 7, 8, 8 ], array_column( $spy->calls, 'generation' ) );
		foreach ( $spy->calls as $call ) { self::assertInstanceOf( RequestContext::class, $call['request'] ); }
		self::assertNotSame( $spy->calls[0]['request']->request_id, $spy->calls[1]['request']->request_id );
	}

	#[DataProvider( 'invalid_commands' )]
	public function test_extra_private_or_malformed_caller_fields_refuse_before_core( array $data ): void {
		$spy = $this->spy(); $runtime = $this->runtime( $spy );
		try { $runtime->dispatch( $data ); self::fail( 'Malformed action reached core.' ); }
		catch ( \RuntimeException $error ) { self::assertSame( 'Delivery quoting is temporarily unavailable. Try again.', $error->getMessage() ); }
		self::assertSame( [], $spy->calls );
	}
	public static function invalid_commands(): iterable {
		yield 'reference is not authority' => [ [ 'action' => 'confirm', 'generation' => 2, 'acceptance_handle' => 'PRIVATE-HANDLE' ] ];
		yield 'nested renamed context' => [ [ 'action' => 'refresh', 'generation' => 2, 'review_token' => QuoteReviewServiceSpy::TOKEN, 'customer_facts' => [ 'owner' => 'PRIVATE-OWNER' ] ] ];
		yield 'quote ID is not authority' => [ [ 'action' => 'confirm', 'generation' => 2, 'quote_id' => QuoteReviewServiceSpy::TOKEN ] ];
		yield 'body hash forbidden' => [ [ 'action' => 'confirm', 'generation' => 2, 'body_digest' => str_repeat( 'a', 64 ) ] ];
		yield 'string generation in native JSON' => [ [ 'action' => 'confirm', 'generation' => '2' ] ];
		yield 'boolean generation' => [ [ 'action' => 'confirm', 'generation' => true ] ];
		yield 'negative generation' => [ [ 'action' => 'confirm', 'generation' => -1 ] ];
		yield 'unsafe browser generation' => [ [ 'action' => 'confirm', 'generation' => 9007199254740992 ] ];
		yield 'nested generation' => [ [ 'action' => 'confirm', 'generation' => [ 2 ] ] ];
		yield 'unknown action' => [ [ 'action' => 'place_order', 'generation' => 2 ] ];
		yield 'required refresh token missing' => [ [ 'action' => 'refresh', 'generation' => 2 ] ];
		yield 'token namespace string is not a UUID' => [ [ 'action' => 'refresh', 'generation' => 2, 'review_token' => 'PRIVATE-TOKEN' ] ];
		yield 'token version not v4' => [ [ 'action' => 'refresh', 'generation' => 2, 'review_token' => '3b319753-c651-1dd6-8a23-9af9bc6f48c1' ] ];
		yield 'token list' => [ [ 'action' => 'refresh', 'generation' => 2, 'review_token' => [ QuoteReviewServiceSpy::TOKEN ] ] ];
	}

	public function test_classic_requires_actual_post_purpose_nonce_and_canonical_generation_before_core(): void {
		$spy = $this->spy(); $runtime = $this->runtime( $spy );
		$valid = [ '_wpnonce' => 'test-nonce-' . QuoteReviewRuntime::NONCE_ACTION, 'action' => 'confirm', 'generation' => '5' ];
		foreach ( [ [ $valid, 'GET' ], [ array_replace( $valid, [ '_wpnonce' => 'stolen-other-purpose' ] ), 'POST' ], [ array_replace( $valid, [ 'generation' => '05' ] ), 'POST' ] ] as [ $data, $method ] ) {
			try { $runtime->classic_response( $data, $method ); self::fail( 'Invalid Classic route accepted.' ); }
			catch ( \RuntimeException $error ) { self::assertStringNotContainsString( 'stolen', $error->getMessage() ); }
		}
		self::assertSame( [], $spy->calls );
		$runtime->classic_response( $valid, 'POST' ); self::assertSame( 'confirm', $spy->calls[0]['method'] ); self::assertSame( 5, $spy->calls[0]['generation'] );
	}

	public function test_unexpected_core_errors_are_replaced_without_messages_paths_or_private_payloads(): void {
		$spy = $this->spy(); $spy->fail = true; $runtime = $this->runtime( $spy );
		$facts = $runtime->current_facts(); self::assertSame( 'unavailable', $facts['status'] ); self::assertNull( $facts['quote'] );
		self::assertStringNotContainsString( 'PRIVATE', json_encode( $facts, JSON_THROW_ON_ERROR ) );
		try { $runtime->dispatch( [ 'action' => 'confirm', 'generation' => 3 ] ); self::fail( 'Private error exposed.' ); }
		catch ( \RuntimeException $error ) { self::assertSame( 'Delivery quoting is temporarily unavailable. Try again.', $error->getMessage() ); self::assertSame( 503, $error->getCode() ); }
	}

	public function test_store_schema_is_readonly_finite_and_contains_no_private_reference_or_arbitrary_object(): void {
		$schema = $this->runtime( $this->spy() )->schema();
		foreach ( $schema as $field ) { self::assertTrue( $field['readonly'] ); }
		self::assertFalse( $schema['quote']['additionalProperties'] ); self::assertFalse( $schema['quote']['properties']['money']['items']['additionalProperties'] );
		self::assertSame( 200, $schema['quote']['properties']['money']['maxItems'] );
		$encoded = json_encode( $schema, JSON_THROW_ON_ERROR ); self::assertStringNotContainsString( 'acceptance_handle', $encoded ); self::assertStringNotContainsString( 'owner_digest', $encoded ); self::assertStringNotContainsString( 'private_body', $encoded );
	}
}

/** A typed adapter spy; it supplies detached DTOs, never fabricated durable acceptance. */
final class QuoteReviewServiceSpy implements CartQuoteReviewService {
	public const TOKEN = '3b319753-c651-4dd6-8a23-9af9bc6f48c1';
	public array $calls = [];
	public string $status = 'no_quote';
	public bool $fail = false;
	private function result( string $method, RequestContext $request, ?int $generation = null, ?string $token = null ): CartQuoteResult {
		$this->calls[] = [ 'method' => $method, 'request' => $request, 'generation' => $generation, 'token' => $token ];
		if ( $this->fail ) { throw new \RuntimeException( 'PRIVATE-OWNER /private/path.php PRIVATE-ADDRESS' ); }
		return CartQuoteResult::create( $this->status, $generation ?? 3, $request, can_refresh: true );
	}
	public function current( RequestContext $request ): CartQuoteResult { return $this->result( 'current', $request ); }
	public function refresh( string $original_token, int $expected_generation, RequestContext $request ): CartQuoteResult { return $this->result( 'refresh', $request, $expected_generation, $original_token ); }
	public function confirm( int $expected_generation, RequestContext $request ): CartQuoteResult { return $this->result( 'confirm', $request, $expected_generation ); }
	public function retry( int $expected_generation, RequestContext $request ): CartQuoteResult { return $this->result( 'retry', $request, $expected_generation ); }
}
