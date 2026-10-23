<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionEligibilityBudgetGuard};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptInputs, FixedPromotionQuoteBindCommand, FixedPromotionQuoteBindInputs, FixedPromotionQuoteBindResult, FixedPromotionQuoteBindService, FixedPromotionQuoteOrderEvidenceGuard, QuoteOperationProfile};
use CetechDeliveryEngine\Domain\Contracts\{ContractError, OperationOutcome, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationCompletion, OperationConnectionFactory, OperationSession};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionQuoteBindHistoryTest.php';
require_once __DIR__ . '/FixedPromotionCurrentEvidenceGuardTest.php';

/** Explicit pre-owner faults and closed result projection; no provider or physical SQL exists here. */
final class FixedPromotionQuoteBindServiceTest extends TestCase {
	private PromotionBindConnectionProbe $factory;
	private FixedPromotionQuoteBindCommand $command;
	private FixedPromotionQuoteBindInputs $inputs;
	private FixedPromotionQuoteBindService $service;
	private bool $authorized = true;
	private bool $throw_authorizer = false;
	private array $authorizations = [];
	protected function setUp(): void {
		$this->factory = new PromotionBindConnectionProbe(); $history = new PromotionBindHistoryFactory(); $this->command = $history->command;
		$f = new FixedPromotionCurrentFixture(); $source = FixedPromotionQuoteAcceptInputs::from_sources( $f->snapshot, $f->native );
		$order = new class( $this->command->order_evidence_digest() ) implements FixedPromotionQuoteOrderEvidenceGuard {
			public function __construct( private string $digest ) {} public function evidence_digest(): string { return $this->digest; }
			public function tables( OperationSession $s ): array { throw new \LogicException( 'No order proof before owned unit.' ); }
			public function verify( OperationSession $s, QuoteStoredRow $q, QuoteBinding $b ): bool { throw new \LogicException( 'No order proof before owned unit.' ); }
		};
		$this->inputs = FixedPromotionQuoteBindInputs::from_sources( $source, $order );
		$store = $this->createMock( FixedPromotionBudgetAccountStore::class ); $eligibility = $this->createMock( FixedPromotionEligibilityBudgetGuard::class );
		foreach ( [ 'tables', 'lock_account', 'lock_reservation', 'lock_reservation_for_account', 'replace_account', 'insert_reservation', 'replace_reservation' ] as $method ) { $store->expects( $this->never() )->method( $method ); }
		$eligibility->expects( $this->never() )->method( 'tables' ); $eligibility->expects( $this->never() )->method( 'validity_for_requests_at' );
		$this->service = new FixedPromotionQuoteBindService( $this->factory, $store, $eligibility, function( $owner, string $purpose ): bool { $this->authorizations[] = [ $owner->digest(), $purpose ]; if ( $this->throw_authorizer ) { throw new \RuntimeException( 'Synthetic unavailable owner authority.' ); } return $this->authorized; } );
	}
	public static function actions(): array { return [ [ 'bind' ], [ 'reconcile' ] ]; }
	private function call( string $action ): FixedPromotionQuoteBindResult { return 'bind' === $action ? $this->service->bind( $this->command, $this->inputs, RequestContext::create() ) : $this->service->reconcile( $this->command, RequestContext::create() ); }
	#[DataProvider( 'actions' )]
	public function test_owner_denial_never_opens_or_returns_private_recovery( string $action ): void {
		$this->authorized = false; $result = $this->call( $action ); self::assertSame( 'rejected', $result->attempt->outcome->state ); self::assertSame( 'not_authorized', $result->attempt->outcome->error->code ); self::assertNull( $result->command ); self::assertNull( $result->quote ); self::assertNull( $result->binding ); self::assertSame( 0, $this->factory->opens ); self::assertSame( [ [ $this->command->owner()->digest(), 'delivery_quote.bind' ] ], $this->authorizations );
	}
	#[DataProvider( 'actions' )]
	public function test_throwing_authority_is_denial_before_owner_and_order_work( string $action ): void {
		$this->throw_authorizer = true; $result = $this->call( $action ); self::assertSame( 'not_authorized', $result->attempt->outcome->error->code ); self::assertNull( $result->command ); self::assertNull( $result->quote ); self::assertNull( $result->binding ); self::assertSame( 0, $this->factory->opens );
	}
	#[DataProvider( 'actions' )]
	public function test_owner_open_failure_cannot_expose_a_quote_or_prepared_binding( string $action ): void {
		$result = $this->call( $action ); self::assertSame( 'bind' === $action ? 'rejected' : 'unconfirmed', $result->attempt->outcome->state ); self::assertNull( $result->quote ); self::assertNull( $result->binding ); self::assertSame( 1, $this->factory->opens ); self::assertSame( $this->command->to_recovery_array(), $result->command->to_recovery_array() ); self::assertNull( $result->command->acceptance()->issue()->lease() );
	}
	#[DataProvider( 'actions' )]
	public function test_authority_lost_while_opening_withholds_all_private_projections( string $action ): void {
		$this->factory->before_open = function(): void { $this->authorized = false; }; $result = $this->call( $action ); self::assertSame( 'unconfirmed', $result->attempt->outcome->state ); self::assertNull( $result->command ); self::assertNull( $result->quote ); self::assertNull( $result->binding ); self::assertNull( $result->attempt->completion ); self::assertSame( 1, $this->factory->opens );
	}
	public function test_service_and_result_implicit_exports_refuse_and_redact_private_dependencies(): void {
		$result = $this->call( 'reconcile' );
		foreach ( [ $this->service, $result ] as $private ) {
			foreach ( [ fn() => json_encode( $private, JSON_THROW_ON_ERROR ), fn() => serialize( $private ) ] as $export ) { try { $export(); self::fail( 'Private service/result export must refuse.' ); } catch ( \LogicException $e ) { self::assertStringNotContainsString( $this->command->reference()->handle(), $e->getMessage() ); } }
			ob_start(); var_dump( $private ); $debug = ob_get_clean(); self::assertStringContainsString( 'redacted', $debug ); self::assertStringNotContainsString( $this->command->owner()->digest(), $debug ); self::assertStringNotContainsString( $this->command->reference()->handle(), $debug );
		}
	}
	public static function invalid_results(): array { return array_map( static fn( string $v ): array => [ $v ], [ 'missing quote', 'missing binding', 'different order', 'different completion order', 'different completion namespace', 'different completion time' ] ); }
	#[DataProvider( 'invalid_results' )]
	public function test_a_positive_result_requires_paired_exact_command_binding_and_completion( string $case ): void {
		$f = new PromotionBindHistoryFactory(); $profile = new QuoteOperationProfile( 'delivery_quote.bind' ); $completion = OperationCompletion::from_json( $f->records[3]['completion_json'], $profile ); $quote = $f->quote; $binding = $f->binding;
		if ( 'missing quote' === $case ) { $quote = null; } elseif ( 'missing binding' === $case ) { $binding = null; }
		elseif ( 'different order' === $case ) { $binding = QuoteBinding::from_row( array_replace( $binding->row(), [ 'order_id' => 999 ] ), $quote ); }
		else { $fields = $completion->result; $field = match( $case ) { 'different completion order' => 'order_id', 'different completion namespace' => 'namespace_hash', default => 'completed_at' }; $fields[$field] = match( $field ) { 'order_id' => 999, 'namespace_hash' => str_repeat( 'a', 64 ), default => $fields[$field] + 1 }; $completion = OperationCompletion::accepted( $profile, $fields, $completion->publication ); }
		$this->expectException( \InvalidArgumentException::class ); new FixedPromotionQuoteBindResult( new OperationAttemptResult( OperationOutcome::accepted(), $completion ), $f->command, $quote, $binding );
	}
	public function test_genuinely_unknown_completion_cannot_borrow_verified_facts(): void {
		$f = new PromotionBindHistoryFactory(); $outcome = OperationOutcome::unconfirmed( new ContractError( 'outcome_unknown', RequestContext::create(), 'reconcile_original_request' ) );
		$this->expectException( \InvalidArgumentException::class ); new FixedPromotionQuoteBindResult( new OperationAttemptResult( $outcome ), $f->command, $f->quote, $f->binding );
	}
}

final class PromotionBindConnectionProbe implements OperationConnectionFactory {
	public int $opens = 0; public ?\Closure $before_open = null;
	public function open(): OperationSession { ++$this->opens; if ( null !== $this->before_open ) { ( $this->before_open )(); } throw new \RuntimeException( 'Synthetic owner-open failure.' ); }
}
