<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

require_once __DIR__ . '/../../Support/DeliveryQuote/QuoteFixtures.php';
require_once __DIR__ . '/../../Support/DeliveryQuote/LegacyQuoteProviderFixtures.php';

use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteCaptureGuard;
use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuotePreparedCapture;
use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteProviderStack;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteCurrentEvidenceGuard;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeCaptureSource;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeReceiptCapture;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeState;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\LegacyQuoteProviderFixtures as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use PHPUnit\Framework\TestCase;

final class LegacyQuoteStackTest extends TestCase {
	public function test_current_native_fence_cannot_run_after_source_refusal(): void {
		$seen = [];
		$source = $this->guard( [ 'shared', 'source' ], false, $seen, 'source' ); $native = $this->guard( [ 'native', 'shared' ], true, $seen, 'native' );
		$combined = new LegacyQuoteCaptureGuard( $source, $native ); $session = $this->createMock( OperationSession::class );
		self::assertSame( [ 'native', 'shared', 'source' ], $combined->tables( $session ) ); self::assertFalse( $combined->verify( $session, QuoteFixtures::owner(), F::context() ) ); self::assertSame( [ 'source' ], $seen );
	}
	public function test_source_success_still_requires_current_native_fence(): void {
		$seen = []; $combined = new LegacyQuoteCaptureGuard( $this->guard( [], true, $seen, 'source' ), $this->guard( [], false, $seen, 'native' ) );
		self::assertFalse( $combined->verify( $this->createMock( OperationSession::class ), QuoteFixtures::owner(), F::context() ) ); self::assertSame( [ 'source', 'native' ], $seen );
	}
	public function test_preparation_exposes_an_original_command_and_no_default_registration(): void {
		$seen = []; $prepared = new LegacyQuotePreparedCapture( QuoteFixtures::owner(), F::context(), F::terms(), $this->guard( [], true, $seen, 'guard' ) );
		$first = $prepared->command( 'original_issue_token' ); $retry = $prepared->command( 'original_issue_token' );
		self::assertSame( $first->intent_digest(), $retry->intent_digest() ); self::assertSame( $first->identity()->namespace_digest(), $retry->identity()->namespace_digest() ); self::assertSame( 'legacy_fixed_base_v1', $first->provider_code() ); self::assertSame( [], $seen );
		self::assertSame( F::terms()->to_private_json(), $prepared->registry()->capture( 'legacy_fixed_base_v1', 1, 'legacy_fixed_base_v1', 1, $prepared->context() )->to_private_json() );
	}
	public function test_failed_source_owner_never_calls_native_woo_capture(): void {
		$session = $this->createMock( OperationSession::class ); $session->method( 'site_id' )->willReturn( 1 ); $session->method( 'is_retired' )->willReturn( false ); $session->method( 'in_transaction' )->willReturn( false ); $session->expects( self::once() )->method( 'begin' )->willReturn( false ); $session->expects( self::never() )->method( 'rollback' ); $session->expects( self::once() )->method( 'retire' )->willReturn( true );
		$factory = new class( $session ) implements OperationConnectionFactory { public function __construct( private OperationSession $session ) {} public function open(): OperationSession { return $this->session; } };
		$source = new class implements QuoteNativeCaptureSource { public int $reads = 0; public function current_owner(): QuoteOwner { return QuoteFixtures::owner(); } public function capture(): QuoteNativeState { ++$this->reads; throw new \LogicException( 'Unexpected native capture.' ); } public function unchanged(): bool { return true; } };
		$stack = new LegacyQuoteProviderStack( $factory, new QuoteNativeReceiptCapture( $source ) );
		try { $stack->prepare( QuoteFixtures::owner(), F::context(), F::plan(), [ F::context()->private_facts()['groups'][0]['component_key'] => 'local|delivery|20' ] ); self::fail( 'Unavailable source accepted.' ); } catch ( \RuntimeException $error ) { self::assertSame( 'Delivery quote source unavailable.', $error->getMessage() ); }
		self::assertSame( 0, $source->reads );
	}
	private function guard( array $tables, bool $answer, array &$seen, string $name ): QuoteCurrentEvidenceGuard {
		return new class( $tables, $answer, $seen, $name ) implements QuoteCurrentEvidenceGuard {
			private array $seen; public function __construct( private array $names, private bool $answer, array &$seen, private string $name ) { $this->seen = &$seen; }
			public function tables( OperationSession $session ): array { return $this->names; }
			public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool { $this->seen[] = $this->name; return $this->answer; }
		};
	}
}
