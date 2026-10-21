<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionEligibilityBudgetGuard, FixedPromotionQuotePreparation, FixedPromotionQuotePreparationService};
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteCurrentEvidenceValidity;
use CetechDeliveryEngine\Application\Operation\{OperationReadiness, OperationStorageException};
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetRequest, FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteMoney, QuoteOwner, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{LegacyQuoteProviderFixtures as L, QuoteFixtures as Q};
use CetechDeliveryEngine\Tests\Unit\DeliveryQuote\{FixedPromotionCurrentFixture, FixedPromotionCurrentSession};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Reuse the retained strict source/native fixture; no test or fixture source is modified.
require_once dirname( __DIR__ ) . '/DeliveryQuote/FixedPromotionCurrentEvidenceGuardTest.php';

/** Real source/native codecs and guards over explicitly synthetic current SQL and budget ports. */
final class FixedPromotionQuotePreparationServiceTest extends TestCase {
	private FixedPromotionCurrentFixture $f;
	private PreparationBudgetStore $store;
	private PreparationEligibility $eligible;
	private PreparationReadiness $ready;
	private string $account;
	private array $slots;
	private bool $authorized = true;

	protected function setUp(): void {
		$this->f = new FixedPromotionCurrentFixture(); $this->store = new PreparationBudgetStore(); $this->eligible = new PreparationEligibility(); $this->ready = new PreparationReadiness();
		$this->account = Q::digest( 'private explicit preparation pool' ); $this->slots = [ [ 'component_key' => F::key(), 'account_key' => $this->account ] ];
		$this->seed();
	}
	private function seed( string $limit = '10.00' ): void {
		$r = FixedPromotionBudgetRequest::from_captured( $this->f->header, $this->f->provider->context(), $this->f->provider->terms(), F::key(), $this->account );
		$this->store->accounts[$this->account] = FixedPromotionBudgetState::create( 1, $this->account, $r->funding_source(), QuoteMoney::from_array( [ 'amount' => $limit, 'currency' => 'GHS', 'precision' => 2 ] ) );
	}
	private function service(): FixedPromotionQuotePreparationService { return new FixedPromotionQuotePreparationService( $this->store, $this->eligible, $this->ready, fn(): bool => $this->authorized ); }
	private function prepare( ?QuoteTime $at = null, ?QuoteTime $until = null, ?array $slots = null, ?QuoteOwner $owner = null, ?QuoteTerms $terms = null ): FixedPromotionQuotePreparation {
		return $this->service()->prepare( $this->f->session, $owner ?? F::owner(), $this->f->header, $this->f->provider->context(), $terms ?? $this->f->provider->terms(), $this->f->snapshot, $this->f->native, $slots ?? $this->slots, $at ?? Q::time(), $until ?? Q::time()->plus_seconds( 120 ) );
	}
	private function clean_refusal( callable $call ): void {
		$before = $this->store->accounts[$this->account]->to_private_json();
		try { $call(); self::fail( 'A clean refusal is required.' ); } catch ( \DomainException ) { self::assertSame( 0, $this->store->writes ); self::assertSame( [], $this->store->claims ); self::assertSame( $before, $this->store->accounts[$this->account]->to_private_json() ); }
	}
	private function uncertain( callable $call ): void { try { $call(); self::fail( 'The caller must roll back after a write attempt.' ); } catch ( OperationStorageException ) { self::assertTrue( $this->f->session->in_transaction() ); } }

	public function test_complete_preparation_reserves_once_and_returns_full_same_owner_current_proof(): void {
		$native_reads = $this->f->native_source->reads; $result = $this->prepare();
		self::assertSame( $this->f->header->to_private_json(), $result->allocation_set()->header()->to_private_json() );
		self::assertCount( 1, $result->reservations() ); self::assertSame( 'held', $result->reservations()[0]->state() ); self::assertSame( '3.00', $this->store->accounts[$this->account]->held()->amount() );
		self::assertSame( 2, $this->store->writes ); self::assertSame( 2, $this->ready->calls ); self::assertSame( 2, $this->eligible->calls );
		self::assertTrue( $result->validity()->valid_at( Q::time()->plus_seconds( 119 ) ) ); self::assertFalse( $result->validity()->valid_at( Q::time()->plus_seconds( 120 ) ) );
		self::assertSame( $native_reads, $this->f->native_source->reads ); self::assertSame( 0, $this->f->session->clocks );
		self::assertTrue( $result->guard()->verify_at( $this->f->session, F::owner(), $this->f->provider->context(), Q::time() ) );
		self::assertSame( [ spl_object_id( $this->f->session ) ], array_values( array_unique( $this->store->owners ) ) );
	}
	public function test_exact_retry_uses_original_held_claim_even_when_free_budget_is_zero(): void {
		$this->seed( '3.00' ); $first = $this->prepare(); $retry = $this->prepare( Q::time()->plus_seconds( 1 ) );
		self::assertSame( $first->reservations()[0]->to_private_json(), $retry->reservations()[0]->to_private_json() ); self::assertSame( 2, $this->store->writes );
		self::assertTrue( $this->store->accounts[$this->account]->remaining()->zero() ); self::assertSame( 2, $this->store->accounts[$this->account]->revision() );
	}
	public function test_missing_publication_refuses_before_source_or_current_policy_and_never_writes(): void {
		$this->ready->available = false; $this->clean_refusal( fn() => $this->prepare() ); self::assertSame( [], $this->f->session->sql ); self::assertSame( 0, $this->eligible->calls );
	}
	public function test_changed_original_source_refuses_before_current_eligibility_and_never_writes(): void {
		$this->f->session->rows['wp_posts'][0]['post_modified_gmt'] = '2026-10-07 05:00:01';
		$this->clean_refusal( fn() => $this->prepare() ); self::assertSame( 0, $this->eligible->calls );
	}
	public function test_changed_fresh_native_source_refuses_without_capturing_again(): void {
		$reads = $this->f->native_source->reads; $this->f->native_source->same = false; $this->clean_refusal( fn() => $this->prepare() ); self::assertSame( $reads, $this->f->native_source->reads );
	}
	public function test_current_merchant_eligibility_is_required_before_budget_mutations(): void { $this->eligible->deny_at = 1; $this->clean_refusal( fn() => $this->prepare() ); self::assertSame( 1, $this->eligible->calls ); }
	public function test_explicit_action_authorization_is_required_before_budget_mutations(): void { $this->authorized = false; $this->clean_refusal( fn() => $this->prepare() ); }
	public function test_insufficient_pool_refuses_with_no_partial_hold(): void { $this->seed( '2.99' ); $this->clean_refusal( fn() => $this->prepare() ); }

	public static function bad_inputs(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'owner', 'site', 'retired', 'transaction', 'prefix', 'before quote', 'at expiry', 'empty hold', 'hold past expiry', 'missing mapping', 'duplicate mapping', 'wrong terms' ] ); }
	#[DataProvider( 'bad_inputs' )]
	public function test_invalid_complete_scope_or_deadline_has_no_writes( string $case ): void {
		$owner = F::owner(); $at = Q::time(); $until = $at->plus_seconds( 120 ); $slots = $this->slots; $terms = null;
		if ( 'owner' === $case ) { $facts = $owner->facts(); $facts['principal_hash'] = Q::digest( 'foreign principal' ); $owner = QuoteOwner::from_array( $facts ); }
		elseif ( 'site' === $case ) { $this->f->session->site = 2; }
		elseif ( 'retired' === $case ) { $this->f->session->retired = true; }
		elseif ( 'transaction' === $case ) { $this->f->session->transaction = false; }
		elseif ( 'prefix' === $case ) { $this->f->session->prefix = 'foreign_'; }
		elseif ( 'before quote' === $case ) { $at = $at->plus_seconds( -1 ); }
		elseif ( 'at expiry' === $case ) { $at = $this->f->header->expires_at(); $until = $at->plus_seconds( 1 ); }
		elseif ( 'empty hold' === $case ) { $until = $at; }
		elseif ( 'hold past expiry' === $case ) { $until = $this->f->header->expires_at()->plus_seconds( 1 ); }
		elseif ( 'missing mapping' === $case ) { $slots = []; }
		elseif ( 'duplicate mapping' === $case ) { $slots[] = $slots[0]; }
		else { $terms = F::original_terms(); }
		$this->clean_refusal( fn() => $this->prepare( $at, $until, $slots, $owner, $terms ) );
	}
	public function test_post_write_policy_loss_is_storage_uncertainty_and_does_not_release_claim(): void {
		$this->eligible->deny_at = 2; $this->uncertain( fn() => $this->prepare() ); self::assertSame( 2, $this->store->writes ); self::assertSame( 'held', array_values( $this->store->claims )[0]->state() ); self::assertSame( '3.00', $this->store->accounts[$this->account]->held()->amount() );
	}
	public function test_post_write_original_source_loss_cannot_return_a_clean_refusal(): void {
		$this->store->on_write = function( int $number ): void { if ( 2 === $number ) { $this->f->session->rows['wp_posts'][0]['post_modified_gmt'] = '2026-10-07 05:00:01'; } };
		$this->uncertain( fn() => $this->prepare() ); self::assertSame( 2, $this->store->writes );
	}
	public function test_post_write_publication_loss_requires_caller_rollback(): void { $this->ready->deny_at = 2; $this->uncertain( fn() => $this->prepare() ); self::assertSame( 2, $this->store->writes ); }
	public function test_even_a_failed_first_write_attempt_is_storage_uncertainty(): void { $this->store->fail_first = true; $this->uncertain( fn() => $this->prepare() ); self::assertSame( 1, $this->store->writes ); self::assertTrue( $this->store->accounts[$this->account]->held()->zero() ); }
	public function test_owner_loss_after_mutation_never_returns_preparation(): void {
		$this->store->on_write = function(): void { $this->f->session->prefix = 'foreign_'; };
		$this->uncertain( fn() => $this->prepare() ); self::assertSame( 1, $this->store->writes );
	}
	public function test_charset_rebinding_in_readiness_refuses_before_any_budget_write(): void {
		$session = new PreparationCharsetSession( $this->f->session );
		$this->ready->on_call = static function() use ( $session ): void { $session->charset = 'utf8mb4_unicode_520_ci'; };
		$this->clean_refusal( fn() => $this->service()->prepare( $session, F::owner(), $this->f->header, $this->f->provider->context(), $this->f->provider->terms(), $this->f->snapshot, $this->f->native, $this->slots, Q::time(), Q::time()->plus_seconds( 120 ) ) );
	}
	public function test_charset_rebinding_after_a_write_requires_caller_rollback(): void {
		$session = new PreparationCharsetSession( $this->f->session );
		$this->store->on_write = static function() use ( $session ): void { $session->charset = 'utf8mb4_unicode_520_ci'; };
		$this->uncertain( fn() => $this->service()->prepare( $session, F::owner(), $this->f->header, $this->f->provider->context(), $this->f->provider->terms(), $this->f->snapshot, $this->f->native, $this->slots, Q::time(), Q::time()->plus_seconds( 120 ) ) );
		self::assertGreaterThanOrEqual( 1, $this->store->writes );
	}
	public function test_prewrite_eligibility_ceiling_is_not_extended_by_a_later_wider_proof(): void {
		$this->eligible->first_seconds = 20; $result = $this->prepare(); self::assertTrue( $result->validity()->valid_at( Q::time()->plus_seconds( 19 ) ) ); self::assertFalse( $result->validity()->valid_at( Q::time()->plus_seconds( 20 ) ) );
	}
	public function test_original_source_transition_caps_preparation_before_claim_deadline(): void {
		$this->f = new FixedPromotionCurrentFixture( [ L::card(), L::card( overrides: [ 'id' => 2, 'effective_from' => '2026-10-07 05:00:20' ] ) ] ); $this->seed();
		$result = $this->prepare(); self::assertTrue( $result->validity()->valid_at( Q::time()->plus_seconds( 19 ) ) ); self::assertFalse( $result->validity()->valid_at( Q::time()->plus_seconds( 20 ) ) ); self::assertTrue( $result->reservations()[0]->expires_at()->equals( Q::time()->plus_seconds( 120 ) ) );
	}
	public function test_result_and_service_do_not_implicitly_export_private_funding_or_source_facts(): void {
		$result = $this->prepare();
		foreach ( [ $result, $this->service() ] as $value ) {
			foreach ( [ static fn(): string => json_encode( $value, JSON_THROW_ON_ERROR ), static fn(): string => serialize( $value ) ] as $export ) { try { $export(); self::fail( 'Private preparation must not export.' ); } catch ( \LogicException $error ) { self::assertStringNotContainsString( 'private_fixed_funder', $error->getMessage() ); } }
			ob_start(); var_dump( $value ); $debug = ob_get_clean(); self::assertStringContainsString( 'redacted', $debug ); self::assertStringNotContainsString( 'private_fixed_funder', $debug ); self::assertStringNotContainsString( $this->account, $debug );
		}
	}
	public function test_result_does_not_retain_a_callers_reference_to_a_claim_array_element(): void {
		$prepared = $this->prepare(); $claim = $prepared->reservations()[0]; $original = $claim->to_private_json(); $referenced = [ &$claim ];
		$result = FixedPromotionQuotePreparation::from_prepared( $prepared->allocation_set(), $referenced, $prepared->guard(), $prepared->validity(), Q::time(), Q::time()->plus_seconds( 120 ) );
		$claim = null;
		self::assertNull( $referenced[0] ); self::assertSame( $original, $result->reservations()[0]->to_private_json() );
	}
}

final class PreparationReadiness implements OperationReadiness {
	public bool $available = true; public int $calls = 0; public int $deny_at = 0;
	public ?\Closure $on_call = null;
	public function assert_ready( OperationSession $session ): void { ++$this->calls; if ( null !== $this->on_call ) { ( $this->on_call )( $session, $this->calls ); } if ( ! $this->available || $this->deny_at === $this->calls ) { throw new \DomainException( 'Synthetic publication unavailable.' ); } }
}

final class PreparationEligibility implements FixedPromotionEligibilityBudgetGuard {
	public int $calls = 0; public int $deny_at = 0; public int $first_seconds = 300;
	public function tables( OperationSession $session ): array { return [ 'wp_preparation_policies' ]; }
	public function validity_at( OperationSession $session, QuoteOwner $owner, QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, QuoteTime $at ): ?QuoteCurrentEvidenceValidity { throw new \LogicException( 'Request-aware eligibility is mandatory.' ); }
	public function validity_for_requests_at( OperationSession $session, QuoteOwner $owner, QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, array $requests, QuoteTime $at ): ?QuoteCurrentEvidenceValidity { ++$this->calls; if ( $this->calls === $this->deny_at ) { return null; } return QuoteCurrentEvidenceValidity::capture( $at, $at->plus_seconds( 1 === $this->calls ? $this->first_seconds : 300 ) ); }
}

final class PreparationBudgetStore implements FixedPromotionBudgetAccountStore {
	public array $accounts = []; public array $claims = []; public array $owners = []; public int $writes = 0; public bool $fail_first = false; public ?\Closure $on_write = null;
	public function tables( OperationSession $session ): array { return [ 'wp_preparation_accounts', 'wp_preparation_claims' ]; }
	public function lock_account( OperationSession $session, string $key ): ?FixedPromotionBudgetState { $this->owners[] = spl_object_id( $session ); return $this->accounts[$key] ?? null; }
	public function lock_reservation( OperationSession $session, string $key ): ?FixedPromotionBudgetReservation { throw new \LogicException( 'The exact account must accompany a claim read.' ); }
	public function lock_reservation_for_account( OperationSession $session, string $key, string $account ): ?FixedPromotionBudgetReservation { $this->owners[] = spl_object_id( $session ); $claim = $this->claims[$key] ?? null; if ( null !== $claim && $claim->request()->account_key() !== $account ) { throw new \DomainException( 'Foreign claim.' ); } return $claim; }
	private function writing(): bool { ++$this->writes; if ( null !== $this->on_write ) { ( $this->on_write )( $this->writes ); } return ! ( $this->fail_first && 1 === $this->writes ); }
	public function replace_account( OperationSession $session, FixedPromotionBudgetState $expected, FixedPromotionBudgetState $next ): bool { if ( ! $this->writing() || ( $this->accounts[$expected->account_key()] ?? null )?->to_private_json() !== $expected->to_private_json() ) { return false; } $this->accounts[$next->account_key()] = $next; return true; }
	public function insert_reservation( OperationSession $session, FixedPromotionBudgetReservation $claim ): bool { if ( ! $this->writing() || isset( $this->claims[$claim->request()->claim_key()] ) ) { return false; } $this->claims[$claim->request()->claim_key()] = $claim; return true; }
	public function replace_reservation( OperationSession $session, FixedPromotionBudgetReservation $expected, FixedPromotionBudgetReservation $next ): bool { if ( ! $this->writing() || ( $this->claims[$expected->request()->claim_key()] ?? null )?->to_private_json() !== $expected->to_private_json() ) { return false; } $this->claims[$next->request()->claim_key()] = $next; return true; }
}

/** Synthetic rebinding fault over the same strict source-session object. */
final class PreparationCharsetSession implements OperationSession {
	public string $charset = 'utf8mb4_unicode_ci';
	public function __construct( private OperationSession $inner ) {}
	public function site_id(): int { return $this->inner->site_id(); } public function table_prefix(): string { return $this->inner->table_prefix(); } public function charset_collate(): string { return $this->charset; }
	public function begin(): bool { return $this->inner->begin(); } public function commit(): OperationCommitResult { return $this->inner->commit(); } public function rollback(): bool { return $this->inner->rollback(); } public function retire(): bool { return $this->inner->retire(); }
	public function is_retired(): bool { return $this->inner->is_retired(); } public function in_transaction(): bool { return $this->inner->in_transaction(); } public function validate_tables( array $tables ): bool { return $this->inner->validate_tables( $tables ); }
	public function query( string $sql ): int|false { return $this->inner->query( $sql ); } public function get_row( string $sql ): array|null|false { return $this->inner->get_row( $sql ); } public function get_results( string $sql ): array|false { return $this->inner->get_results( $sql ); }
	public function prepare( string $sql, mixed ...$args ): string { return $this->inner->prepare( $sql, ...$args ); } public function errno(): int { return $this->inner->errno(); } public function insert_id(): int { return $this->inner->insert_id(); }
}
