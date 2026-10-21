<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryCommercial;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetCurrentAuthority, FixedPromotionBudgetService, FixedPromotionEligibilityGuard};
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteCurrentEvidenceValidity;
use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetRequest, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteMoney, QuoteOwner, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use CetechDeliveryEngine\Tests\Unit\DeliveryPromotion\{SyntheticPromotionBudgetSession, SyntheticPromotionBudgetStore};
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/DeliveryPromotion/FixedPromotionBudgetServiceTest.php';

/** Actual budget service and current authority over a synthetic caller-owned transactional store. */
final class PromotionBudgetLifecycleIntegrationTest extends TestCase {
	private function fixture(): array {
		$provider = F::provider(); $header = F::header( $provider ); $pool = Q::digest( 'explicit integration funding account' );
		$request = FixedPromotionBudgetRequest::from_captured( $header, $provider->context(), $provider->terms(), F::key(), $pool );
		$store = new SyntheticPromotionBudgetStore( FixedPromotionBudgetState::create( 1, $pool, $request->funding_source(), QuoteMoney::from_array( [ 'amount' => '3.00', 'currency' => 'GHS', 'precision' => 2 ] ) ) );
		$session = new SyntheticPromotionBudgetSession( $store ); $session->begin();
		$eligibility = new class implements FixedPromotionEligibilityGuard {
			public function tables( OperationSession $session ): array { return [ 'wp_campaigns' ]; }
			public function validity_at( OperationSession $session, QuoteOwner $owner, QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, QuoteTime $at ): ?QuoteCurrentEvidenceValidity { return QuoteCurrentEvidenceValidity::capture( $at, $header->expires_at() ); }
		};
		$authority = FixedPromotionBudgetCurrentAuthority::from_captured( $header, $provider->context(), $provider->terms(), [ [ 'component_key' => F::key(), 'account_key' => $pool ] ], $store, $eligibility );
		return [ $provider, $header, $request, $store, $session, $authority ];
	}
	public function test_service_hold_is_current_with_zero_free_budget_and_commit_cannot_be_reused_as_held_authority(): void {
		[ $provider, $header, $request, $store, $session, $authority ] = $this->fixture();
		$service = new FixedPromotionBudgetService( $store, static fn(): bool => true ); $at = $header->created_at();
		$held = $service->reserve( $session, $request, $at, $at->plus_seconds( 60 ) );
		self::assertSame( '0.00', $store->account->remaining()->amount() );
		$validity = $authority->validity_at( $session, F::owner(), $header, $provider->context(), $provider->terms(), $at->plus_seconds( 1 ) );
		self::assertNotNull( $validity ); self::assertFalse( $validity->valid_at( $at->plus_seconds( 60 ) ) );
		self::assertSame( $held, $service->reserve( $session, $request, $at->plus_seconds( 2 ), $at->plus_seconds( 60 ) ) );
		$committed = $service->commit( $session, $request, $at->plus_seconds( 3 ) ); $writes = $store->writes;
		self::assertSame( 'committed', $committed->state() ); self::assertSame( '0.00', $store->account->held()->amount() ); self::assertSame( '3.00', $store->account->spent()->amount() );
		self::assertSame( $committed, $service->commit( $session, $request, $at->plus_seconds( 4 ) ) ); self::assertSame( $writes, $store->writes );
		self::assertNull( $authority->validity_at( $session, F::owner(), $header, $provider->context(), $provider->terms(), $at->plus_seconds( 5 ) ) );
		self::assertSame( [ 'begin' ], $session->lifecycle ); $session->commit(); self::assertSame( [ 'begin', 'commit' ], $session->lifecycle );
	}
	public function test_partial_store_failure_never_supplies_current_claim_and_only_caller_rollback_restores_account(): void {
		[ $provider, $header, $request, $store, $session, $authority ] = $this->fixture(); $at = $header->created_at();
		$service = new FixedPromotionBudgetService( $store, static fn(): bool => true ); $store->fault = 'insert_false';
		try { $service->reserve( $session, $request, $at, $at->plus_seconds( 60 ) ); self::fail( 'An uncertain claim write must escape.' ); } catch ( OperationStorageException ) {}
		self::assertSame( '3.00', $store->account->held()->amount() ); self::assertSame( [], $store->claims );
		self::assertNull( $authority->validity_at( $session, F::owner(), $header, $provider->context(), $provider->terms(), $at->plus_seconds( 1 ) ) );
		self::assertSame( [ 'begin' ], $session->lifecycle ); $session->rollback(); self::assertSame( '0.00', $store->account->held()->amount() );
		$session->begin(); $store->fault = ''; $service->reserve( $session, $request, $at->plus_seconds( 2 ), $at->plus_seconds( 60 ) );
		self::assertNotNull( $authority->validity_at( $session, F::owner(), $header, $provider->context(), $provider->terms(), $at->plus_seconds( 3 ) ) );
	}
	public function test_expired_current_read_does_not_release_and_release_still_needs_its_explicit_action_authorizer(): void {
		[ $provider, $header, $request, $store, $session, $authority ] = $this->fixture(); $at = $header->created_at(); $release_allowed = false;
		$service = new FixedPromotionBudgetService( $store, static function( OperationSession $owner, FixedPromotionBudgetRequest $original, string $action ) use ( &$release_allowed ): bool { return 'release' !== $action || $release_allowed; } );
		$service->reserve( $session, $request, $at, $at->plus_seconds( 10 ) ); $writes = $store->writes;
		self::assertNull( $authority->validity_at( $session, F::owner(), $header, $provider->context(), $provider->terms(), $at->plus_seconds( 10 ) ) );
		self::assertSame( '3.00', $store->account->held()->amount() ); self::assertSame( $writes, $store->writes );
		try { $service->release( $session, $request, $at->plus_seconds( 11 ) ); self::fail( 'Expiry alone must not authorize release.' ); } catch ( \DomainException ) {}
		self::assertSame( $writes, $store->writes ); self::assertSame( 'held', $store->claims[$request->claim_key()]->state() );
		$release_allowed = true; $released = $service->release( $session, $request, $at->plus_seconds( 12 ) );
		self::assertSame( 'released', $released->state() ); self::assertSame( '0.00', $store->account->held()->amount() ); self::assertSame( '0.00', $store->account->spent()->amount() );
		self::assertNull( $authority->validity_at( $session, F::owner(), $header, $provider->context(), $provider->terms(), $at->plus_seconds( 13 ) ) );
	}
}
