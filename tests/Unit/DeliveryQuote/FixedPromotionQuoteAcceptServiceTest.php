<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionEligibilityBudgetGuard};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteAcceptInputs, FixedPromotionQuoteAcceptService};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationSession};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionQuoteIssueHistoryTest.php';
require_once __DIR__ . '/FixedPromotionCurrentEvidenceGuardTest.php';

/** Explicit pre-owner faults; no physical SQL, provider capture, or budget mutation is available. */
final class FixedPromotionQuoteAcceptServiceTest extends TestCase {
	private PromotionAcceptConnectionProbe $factory;
	private FixedPromotionQuoteAcceptCommand $command;
	private FixedPromotionQuoteAcceptInputs $inputs;
	private FixedPromotionQuoteAcceptService $service;
	private bool $authorized = true;
	private bool $throw_authorizer = false;
	private array $authorizations = [];

	protected function setUp(): void {
		$this->factory = new PromotionAcceptConnectionProbe(); $issued = new PromotionIssueHistoryFactory(); $this->command = FixedPromotionQuoteAcceptCommand::from_issue( $issued->recovery );
		$f = new FixedPromotionCurrentFixture(); $this->inputs = FixedPromotionQuoteAcceptInputs::from_sources( $f->snapshot, $f->native );
		$store = $this->createMock( FixedPromotionBudgetAccountStore::class ); $eligibility = $this->createMock( FixedPromotionEligibilityBudgetGuard::class );
		foreach ( [ 'tables', 'lock_account', 'lock_reservation', 'lock_reservation_for_account', 'replace_account', 'insert_reservation', 'replace_reservation' ] as $method ) { $store->expects( $this->never() )->method( $method ); }
		$eligibility->expects( $this->never() )->method( 'tables' ); $eligibility->expects( $this->never() )->method( 'validity_for_requests_at' );
		$this->service = new FixedPromotionQuoteAcceptService( $this->factory, $store, $eligibility, function( $owner, string $purpose ): bool {
			$this->authorizations[] = [ $owner->digest(), $purpose ]; if ( $this->throw_authorizer ) { throw new \RuntimeException( 'Synthetic unavailable owner authority.' ); } return $this->authorized;
		} );
	}
	public static function actions(): array { return [ [ 'accept' ], [ 'reconcile' ] ]; }
	private function call( string $action ) { return 'accept' === $action ? $this->service->accept( $this->command, $this->inputs, RequestContext::create() ) : $this->service->reconcile( $this->command, RequestContext::create() ); }

	#[DataProvider( 'actions' )]
	public function test_denied_owner_never_opens_an_owner_or_returns_private_recovery( string $action ): void {
		$this->authorized = false; $result = $this->call( $action );
		self::assertSame( 'rejected', $result->attempt->outcome->state ); self::assertSame( 'not_authorized', $result->attempt->outcome->error->code ); self::assertNull( $result->command ); self::assertNull( $result->quote ); self::assertSame( 0, $this->factory->opens );
		self::assertSame( [ [ $this->command->owner()->digest(), 'delivery_quote.accept' ] ], $this->authorizations );
	}

	#[DataProvider( 'actions' )]
	public function test_throwing_authority_is_denial_before_any_owner_or_budget_work( string $action ): void {
		$this->throw_authorizer = true; $result = $this->call( $action ); self::assertSame( 'not_authorized', $result->attempt->outcome->error->code ); self::assertNull( $result->command ); self::assertNull( $result->quote ); self::assertSame( 0, $this->factory->opens );
	}

	#[DataProvider( 'actions' )]
	public function test_open_failure_cannot_authorize_capture_allocation_or_an_accepted_quote( string $action ): void {
		$result = $this->call( $action ); self::assertSame( 'accept' === $action ? 'rejected' : 'unconfirmed', $result->attempt->outcome->state ); self::assertNull( $result->quote ); self::assertSame( 1, $this->factory->opens ); self::assertNotNull( $result->command ); self::assertNull( $result->command->issue()->lease() );
		self::assertSame( $this->command->to_recovery_array(), $result->command->to_recovery_array() );
	}

	#[DataProvider( 'actions' )]
	public function test_authority_lost_during_owner_open_withholds_every_private_projection( string $action ): void {
		$this->factory->before_open = function(): void { $this->authorized = false; }; $result = $this->call( $action ); self::assertSame( 'unconfirmed', $result->attempt->outcome->state ); self::assertNull( $result->attempt->completion ); self::assertNull( $result->quote ); self::assertNull( $result->command ); self::assertSame( 1, $this->factory->opens );
	}

	public function test_service_implicit_exports_refuse_and_debug_redacts_private_dependencies(): void {
		foreach ( [ fn() => json_encode( $this->service, JSON_THROW_ON_ERROR ), fn() => serialize( $this->service ) ] as $export ) { try { $export(); self::fail( 'Implicit private service export must refuse.' ); } catch ( \LogicException $e ) { self::assertStringNotContainsString( $this->command->reference()->handle(), $e->getMessage() ); } }
		ob_start(); var_dump( $this->service ); $debug = ob_get_clean(); self::assertStringContainsString( 'redacted', $debug ); self::assertStringNotContainsString( $this->command->owner()->digest(), $debug ); self::assertStringNotContainsString( $this->command->reference()->handle(), $debug );
	}
}

final class PromotionAcceptConnectionProbe implements OperationConnectionFactory {
	public int $opens = 0;
	public ?\Closure $before_open = null;
	public function open(): OperationSession { ++$this->opens; if ( null !== $this->before_open ) { ( $this->before_open )(); } throw new \RuntimeException( 'Synthetic owner-open failure.' ); }
}
