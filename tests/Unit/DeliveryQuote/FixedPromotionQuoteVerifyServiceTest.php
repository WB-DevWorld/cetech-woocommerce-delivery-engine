<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionEligibilityBudgetGuard};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptInputs, FixedPromotionQuoteBindInputs, QuotePlacementSavedEvidenceGuard, FixedPromotionQuoteVerifyCommand, FixedPromotionQuoteVerifyInputs, FixedPromotionQuoteVerifyResult, FixedPromotionQuoteVerifyService, FixedPromotionQuoteOrderEvidenceGuard, QuoteOperationProfile};
use CetechDeliveryEngine\Domain\Contracts\{ContractError, OperationOutcome, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationCompletion, OperationConnectionFactory, OperationSession};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionQuoteVerifyHistoryTest.php';
require_once __DIR__ . '/FixedPromotionCurrentEvidenceGuardTest.php';

/** Explicit pre-owner faults and closed result projection; no provider or physical SQL exists here. */
final class FixedPromotionQuoteVerifyServiceTest extends TestCase {
	private PromotionVerifyConnectionProbe $factory;
	private FixedPromotionQuoteVerifyCommand $command;
	private FixedPromotionQuoteVerifyInputs $inputs;
	private FixedPromotionQuoteVerifyService $service;
	private bool $authorized = true;
	private bool $throw_authorizer = false;
	private array $authorizations = [];
	protected function setUp(): void {
		$this->factory = new PromotionVerifyConnectionProbe(); $history = new PromotionVerifyHistoryFactory(); $this->command = $history->command;
		$f = new FixedPromotionCurrentFixture(); $source = FixedPromotionQuoteAcceptInputs::from_sources( $f->snapshot, $f->native );
		$order = new class( $this->command->binding_command()->order_evidence_digest() ) implements FixedPromotionQuoteOrderEvidenceGuard {
			public function __construct( private string $digest ) {} public function evidence_digest(): string { return $this->digest; }
			public function tables( OperationSession $s ): array { throw new \LogicException( 'No order proof before owned unit.' ); }
			public function verify( OperationSession $s, QuoteStoredRow $q, QuoteBinding $b ): bool { throw new \LogicException( 'No order proof before owned unit.' ); }
		};
		$current = FixedPromotionQuoteBindInputs::from_sources( $source, $order );
		$saved = new class implements QuotePlacementSavedEvidenceGuard {
			public function tables( OperationSession $s ): array { throw new \LogicException( 'No saved proof before owned unit.' ); }
			public function verify( OperationSession $s, QuoteBinding $b ): bool { throw new \LogicException( 'No saved proof before owned unit.' ); }
		};
		$this->inputs = FixedPromotionQuoteVerifyInputs::from_sources( $current, $saved );
		$store = $this->createMock( FixedPromotionBudgetAccountStore::class ); $eligibility = $this->createMock( FixedPromotionEligibilityBudgetGuard::class );
		foreach ( [ 'tables', 'lock_account', 'lock_reservation', 'lock_reservation_for_account', 'replace_account', 'insert_reservation', 'replace_reservation' ] as $method ) { $store->expects( $this->never() )->method( $method ); }
		$eligibility->expects( $this->never() )->method( 'tables' ); $eligibility->expects( $this->never() )->method( 'validity_for_requests_at' );
		$this->service = new FixedPromotionQuoteVerifyService( $this->factory, $store, $eligibility, function( $owner, string $purpose ): bool { $this->authorizations[] = [ $owner->digest(), $purpose ]; if ( $this->throw_authorizer ) { throw new \RuntimeException( 'Synthetic unavailable owner authority.' ); } return $this->authorized; } );
	}
	public static function actions(): array { return [ [ 'verify' ], [ 'reconcile' ] ]; }
	private function call( string $action ): FixedPromotionQuoteVerifyResult { return 'verify' === $action ? $this->service->verify( $this->command, $this->inputs, RequestContext::create() ) : $this->service->reconcile( $this->command, RequestContext::create() ); }
	#[DataProvider( 'actions' )]
	public function test_owner_denial_never_opens_or_returns_private_recovery( string $action ): void {
		$this->authorized = false; $result = $this->call( $action ); self::assertSame( 'rejected', $result->attempt->outcome->state ); self::assertSame( 'not_authorized', $result->attempt->outcome->error->code ); self::assertNull( $result->command ); self::assertNull( $result->quote ); self::assertNull( $result->binding ); self::assertSame( 0, $this->factory->opens ); self::assertSame( [ [ $this->command->owner()->digest(), 'delivery_quote.verify_binding' ] ], $this->authorizations );
	}
	#[DataProvider( 'actions' )]
	public function test_throwing_authority_is_denial_before_owner_and_order_work( string $action ): void {
		$this->throw_authorizer = true; $result = $this->call( $action ); self::assertSame( 'not_authorized', $result->attempt->outcome->error->code ); self::assertNull( $result->command ); self::assertNull( $result->quote ); self::assertNull( $result->binding ); self::assertSame( 0, $this->factory->opens );
	}
	#[DataProvider( 'actions' )]
	public function test_owner_open_failure_cannot_expose_a_quote_or_verified_binding( string $action ): void {
		$result = $this->call( $action ); self::assertSame( 'verify' === $action ? 'rejected' : 'unconfirmed', $result->attempt->outcome->state ); self::assertNull( $result->quote ); self::assertNull( $result->binding ); self::assertSame( 1, $this->factory->opens ); self::assertSame( $this->command->to_recovery_array(), $result->command->to_recovery_array() ); self::assertNull( $result->command->binding_command()->acceptance()->issue()->lease() );
	}
	#[DataProvider( 'actions' )]
	public function test_authority_lost_while_opening_withholds_all_private_projections( string $action ): void {
		$this->factory->before_open = function(): void { $this->authorized = false; }; $result = $this->call( $action ); self::assertSame( 'unconfirmed', $result->attempt->outcome->state ); self::assertNull( $result->command ); self::assertNull( $result->quote ); self::assertNull( $result->binding ); self::assertNull( $result->attempt->completion ); self::assertSame( 1, $this->factory->opens );
	}
	public function test_service_implicit_exports_refuse_and_redact_private_dependencies(): void {
		$result = $this->call( 'reconcile' );
		foreach ( [ $this->service ] as $private ) {
			foreach ( [ fn() => json_encode( $private, JSON_THROW_ON_ERROR ), fn() => serialize( $private ) ] as $export ) { try { $export(); self::fail( 'Private service/result export must refuse.' ); } catch ( \LogicException $e ) { self::assertStringNotContainsString( $this->command->reference()->handle(), $e->getMessage() ); } }
			ob_start(); var_dump( $private ); $debug = ob_get_clean(); self::assertStringContainsString( 'redacted', $debug ); self::assertStringNotContainsString( $this->command->owner()->digest(), $debug ); self::assertStringNotContainsString( $this->command->reference()->handle(), $debug );
		}
	}

}

final class PromotionVerifyConnectionProbe implements OperationConnectionFactory {
	public int $opens = 0; public ?\Closure $before_open = null;
	public function open(): OperationSession { ++$this->opens; if ( null !== $this->before_open ) { ( $this->before_open )(); } throw new \RuntimeException( 'Synthetic owner-open failure.' ); }
}
