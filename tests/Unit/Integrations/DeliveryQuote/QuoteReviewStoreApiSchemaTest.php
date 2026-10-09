<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\Integrations\DeliveryQuote;

use PHPUnit\Framework\TestCase;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteCartPlacementEvidenceReader;
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{CartQuoteFixtureEnvironment,CartQuoteFixtureSessions,CartQuoteFixtures,QuoteDurableFixtureFactory,QuoteFixtureControl,QuoteFixturePublication};

require_once dirname( __DIR__, 3 ) . '/Support/DeliveryQuote/CartQuoteFixtures.php';

final class QuoteReviewStoreApiSchemaTest extends TestCase {
	public function test_native_creatable_defaults_accept_checkout_without_client_response_extensions(): void {
		$out = $this->probe( 'omitted' );
		self::assertSame( [ 'cart', 'checkout' ], $out['endpoints'] );
		self::assertSame( [ true, true ], $out['empty_namespace_defaults'] );
		self::assertSame( [ true, true ], $out['native_validation'] );
		self::assertSame( [], $out['service_calls'], 'Native request schema/default handling cannot capture, confirm or place a quote.' );
	}
	public function test_spoofed_shopper_projection_cannot_supply_review_or_placement_authority(): void {
		$out = $this->probe( 'spoof' );
		self::assertSame( [ 'changed', 'changed' ], $out['response_statuses'] );
		self::assertSame( [ 7, 7 ], $out['response_generations'] );
		self::assertSame( [ null, null ], $out['response_quotes'] );
		self::assertSame( [ true, true ], $out['spoofed_commands_refused'] );
		self::assertSame( [ 'current', 'current' ], $out['service_calls'] );
	}
	public function test_matching_public_quote_projection_cannot_confirm_private_session_for_placement(): void {
		$factory = new QuoteDurableFixtureFactory(); $environment = new CartQuoteFixtureEnvironment( $factory ); $sessions = new CartQuoteFixtureSessions();
		$facts = CartQuoteFixtures::service( $factory, $environment, $sessions )->refresh( '12345678-1234-4abc-8abc-123456789abc', 0, RequestContext::create() )->shopper_facts();
		self::assertSame( 'review_required', $facts['status'] ); self::assertNotNull( $facts['quote'] );
		$original = $sessions->current->to_private_json(); $records = $factory->count( 'operation_records' ); $changes = $factory->count( 'operation_changes' );
		$ready = new class implements OperationReadiness { public function assert_ready( OperationSession $session ): void {} };
		$reader = new QuoteCartPlacementEvidenceReader( $environment, $sessions, $factory, $ready, new QuoteFixtureControl( $factory ), new QuoteFixturePublication() );
		$post = $_POST; $request = $_REQUEST;
		try {
			// Even the exact public quote UUID, with a forged confirmed projection,
			// cannot replace the server's private, still-issued session envelope.
			$_POST = $_REQUEST = [ 'extensions' => [ 'cetech-delivery-quote-review' => [ ...$facts, 'status' => 'confirmed', 'can_confirm' => true ] ] ];
			self::assertNull( $reader->current( RequestContext::create() ) );
		} finally { $_POST = $post; $_REQUEST = $request; }
		self::assertSame( $original, $sessions->current->to_private_json() ); self::assertSame( $records, $factory->count( 'operation_records' ) ); self::assertSame( $changes, $factory->count( 'operation_changes' ) );
	}
	private function probe( string $mode ): array {
		$process = proc_open( [ PHP_BINARY, dirname( __DIR__, 3 ) . '/Support/DeliveryQuote/native-review-store-schema-probe.php', $mode ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes );
		self::assertIsResource( $process ); fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $stderr );
		return json_decode( $stdout, true, 16, JSON_THROW_ON_ERROR );
	}
}
