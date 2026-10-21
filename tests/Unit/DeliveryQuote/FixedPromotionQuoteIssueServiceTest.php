<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionEligibilityBudgetGuard};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteIssueCommand, FixedPromotionQuoteIssueInputs, FixedPromotionQuoteIssueResult, FixedPromotionQuoteIssueService, FixedPromotionQuoteProvider, QuoteAdmissionLease, QuoteIssueCommand, QuotePreparationAttempt, QuotePreparationCommand, QuotePreparationLease, QuoteProviderInterface, QuoteProviderRegistry};
use CetechDeliveryEngine\Domain\Contracts\{OperationOutcome, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBudgetSlot, QuoteContext, QuoteId, QuoteTerms};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteStorageFixtures};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionCurrentEvidenceGuardTest.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';

/** Source codecs are real; pre-transaction fault ports deliberately never open a database. */
final class FixedPromotionQuoteIssueServiceTest extends TestCase {

	private FixedPromotionCurrentFixture $f;
	private PromotionIssueConnectionProbe $factory;
	private PromotionIssueProviderProbe $provider;
	private FixedPromotionQuoteIssueInputs $inputs;
	private FixedPromotionQuoteIssueService $service;
	private bool $authorized = true;
	private int $authorizations = 0;
	private array $slots;

	protected function setUp(): void {
		$this->f = new FixedPromotionCurrentFixture(); $this->factory = new PromotionIssueConnectionProbe(); $this->provider = new PromotionIssueProviderProbe( $this->f->provider );
		$this->inputs = FixedPromotionQuoteIssueInputs::from_sources( $this->f->snapshot, $this->f->native );
		$this->slots = [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'explicit private E10 issue pool' ) ] ];
		$store = $this->createMock( FixedPromotionBudgetAccountStore::class ); $eligibility = $this->createMock( FixedPromotionEligibilityBudgetGuard::class );
		foreach ( [ 'lock_account', 'lock_reservation', 'lock_reservation_for_account', 'replace_account', 'insert_reservation', 'replace_reservation' ] as $method ) { $store->expects( $this->never() )->method( $method ); }
		$eligibility->expects( $this->never() )->method( 'validity_for_requests_at' );
		$this->service = new FixedPromotionQuoteIssueService( $this->factory, new QuoteProviderRegistry( [ $this->provider ] ), $store, $eligibility, function(): bool { ++$this->authorizations; return $this->authorized; }, static function(): never { throw new \LogicException( 'No budget authorization before C03.' ); } );
	}

	private function command( ?string $token = null ): QuoteIssueCommand { return QuoteIssueCommand::fixed_promotion( F::owner(), $this->f->provider->context(), $token ?? QuoteId::generate()->value(), $this->slots, Q::time()->plus_seconds( 120 ) ); }

	/** Real one-use preparation-to-issue bridge over an explicitly synthetic confirmed slot. */
	private function lease( QuoteIssueCommand $issue ): QuoteAdmissionLease {
		$preparation = QuotePreparationCommand::fixed_promotion( $issue->owner(), Q::digest( 'E10 cheap preparation' ), $issue->to_promotion_recovery_array()['original_token'] );
		$attempt = QuotePreparationAttempt::generate(); self::assertTrue( $attempt->begin( $preparation ) ); self::assertTrue( $attempt->confirm() );
		$namespace = $preparation->identity()->namespace_digest();
		$slot = QuoteBudgetSlot::from_row( array_replace( QuoteStorageFixtures::budget()->row(), [ 'admission_namespace_hash' => $namespace, 'slot_key' => QuoteBudgetSlot::admission_slot_key( $namespace ), 'admission_intent_digest' => $preparation->intent_digest(), 'server_attempt_digest' => $attempt->digest() ] ) );
		$lease = QuotePreparationLease::confirmed( $slot, $preparation, $attempt ); self::assertTrue( $lease->claim_preparation() );
		return $lease->bind_issue( $issue );
	}
	private function call( QuoteIssueCommand $command, QuoteAdmissionLease $lease, callable $stage ): FixedPromotionQuoteIssueResult { return $this->service->issue_admitted( $command, $lease, $this->inputs, RequestContext::create(), $stage ); }
	private function assert_unknown( FixedPromotionQuoteIssueResult $result ): void { self::assertSame( 'unconfirmed', $result->attempt->outcome->state ); self::assertNull( $result->attempt->completion ); self::assertSame( 'outcome_unknown', $result->attempt->outcome->error->code ); self::assertNull( $result->quote ); }

	public function test_inputs_keep_original_and_fresh_ports_without_executing_them(): void {
		$reads = $this->f->native_source->reads;
		self::assertSame( $this->f->snapshot, $this->inputs->original() ); self::assertSame( $this->f->native, $this->inputs->fresh_native() );
		self::assertSame( $reads, $this->f->native_source->reads ); self::assertSame( [], $this->f->session->sql );
	}

	public function test_generic_format_three_v1_is_refused_before_authorizer_provider_or_lease_claim(): void {
		$proper = $this->command(); $lease = $this->lease( $proper );
		$generic = QuoteIssueCommand::create( $proper->owner(), $proper->context(), FixedPromotionQuoteProvider::CODE, 1, FixedPromotionQuoteProvider::CODE, 1, $proper->to_promotion_recovery_array()['original_token'] );
		$stages = 0; $result = $this->call( $generic, $lease, static function() use ( &$stages ): bool { ++$stages; return true; } );
		self::assertSame( 'rejected', $result->attempt->outcome->state ); self::assertSame( 'temporarily_unavailable', $result->attempt->outcome->error->code );
		self::assertSame( 0, $this->authorizations ); self::assertSame( 0, $this->provider->captures ); self::assertSame( 0, $this->factory->opens ); self::assertSame( 0, $stages ); self::assertFalse( $lease->capture_started() );
	}

	public function test_authorization_denial_never_claims_or_stages_private_facts(): void {
		$command = $this->command(); $lease = $this->lease( $command ); $this->authorized = false;
		$result = $this->call( $command, $lease, static function(): never { throw new \LogicException( 'Stage must not run.' ); } );
		self::assertSame( 'not_authorized', $result->attempt->outcome->error->code ); self::assertNull( $result->command ); self::assertFalse( $lease->capture_started() ); self::assertSame( 0, $this->provider->captures ); self::assertSame( 0, $this->factory->opens );
	}

	public function test_foreign_live_lease_cannot_claim_capture_or_open_c03(): void {
		$lease = $this->lease( $this->command() );
		$result = $this->call( $this->command(), $lease, static function(): never { throw new \LogicException( 'Stage must not run.' ); } );
		self::assertSame( 'temporarily_unavailable', $result->attempt->outcome->error->code ); self::assertNull( $result->command ); self::assertFalse( $lease->capture_started() ); self::assertSame( 0, $this->provider->captures ); self::assertSame( 0, $this->factory->opens );
	}

	public static function failed_stage(): array { return [ 'false' => [ 'false' ], 'null' => [ 'null' ], 'throws' => [ 'throws' ] ]; }
	#[DataProvider( 'failed_stage' )]
	public function test_unacknowledged_exact_recovery_never_opens_c03( string $mode ): void {
		$command = $this->command(); $lease = $this->lease( $command ); $staged = null; $reads = $this->f->native_source->reads;
		$result = $this->call( $command, $lease, static function( FixedPromotionQuoteIssueCommand $recovery ) use ( &$staged, $mode ): mixed {
			$staged = $recovery; if ( 'throws' === $mode ) { throw new \RuntimeException( 'Synthetic durable staging failure.' ); } return 'false' === $mode ? false : null;
		} );
		$this->assert_unknown( $result ); self::assertNotNull( $staged ); self::assertSame( $staged, $result->command ); self::assertNull( $staged->lease() );
		self::assertSame( 0, $this->factory->opens ); self::assertSame( 1, $this->provider->captures ); self::assertTrue( $lease->capture_started() ); self::assertSame( $reads, $this->f->native_source->reads );
		self::assertSame( $command->intent_digest(), $staged->intent()->fingerprint() ); self::assertSame( $command->identity()->namespace_digest(), $staged->identity->namespace_digest() );
		self::assertSame( $command->context()->to_private_json(), $staged->context()->to_private_json() ); self::assertSame( $this->f->provider->terms()->to_private_json(), $staged->terms()->to_private_json() );
		self::assertSame( $this->slots, $staged->account_slots() ); self::assertTrue( $command->promotion_hold_until()->equals( $staged->hold_until() ) ); self::assertTrue( $staged->header()->matches_reference( $staged->reference() ) );
		$restored = FixedPromotionQuoteIssueCommand::from_recovery_array( $staged->to_recovery_array() ); self::assertNull( $restored->lease() ); self::assertSame( $staged->to_recovery_array(), $restored->to_recovery_array() ); self::assertSame( $staged->allocation_digest(), $restored->allocation_digest() );
	}

	public function test_acknowledged_stage_precedes_connection_failure_and_keeps_exact_recovery(): void {
		$command = $this->command(); $lease = $this->lease( $command ); $staged = null;
		$this->factory->before_open = function() use ( &$staged ): void { self::assertNotNull( $staged ); self::assertNull( $staged->lease() ); };
		$result = $this->call( $command, $lease, static function( FixedPromotionQuoteIssueCommand $recovery ) use ( &$staged ): bool { $staged = $recovery; return true; } );
		self::assertSame( 'rejected', $result->attempt->outcome->state ); self::assertFalse( $result->attempt->outcome->mutation_accepted ); self::assertNull( $result->quote ); self::assertNotNull( $result->command ); self::assertSame( $staged->to_recovery_array(), $result->command->to_recovery_array() ); self::assertSame( 1, $this->factory->opens ); self::assertSame( 1, $this->provider->captures );
	}

	public function test_consumed_capture_capability_never_recaptures_or_replaces_the_staged_header(): void {
		$command = $this->command(); $lease = $this->lease( $command ); $first = $this->call( $command, $lease, static fn(): bool => false );
		$again = $this->call( $command, $lease, static function(): never { throw new \LogicException( 'No replacement recovery.' ); } );
		self::assertSame( 'pending', $again->attempt->outcome->state ); self::assertNull( $again->command ); self::assertSame( 1, $this->provider->captures ); self::assertSame( 0, $this->factory->opens ); self::assertNotNull( $first->command );
	}

	public function test_capture_failure_claims_once_and_never_stages_or_opens_c03(): void {
		$command = $this->command(); $lease = $this->lease( $command ); $this->provider->throw = true;
		$result = $this->call( $command, $lease, static function(): never { throw new \LogicException( 'No invented capture.' ); } );
		$this->assert_unknown( $result ); self::assertNull( $result->command ); self::assertSame( 1, $this->provider->captures ); self::assertSame( 0, $this->factory->opens ); self::assertTrue( $lease->capture_started() );
		self::assertSame( 'pending', $this->call( $command, $lease, static fn(): bool => true )->attempt->outcome->state ); self::assertSame( 1, $this->provider->captures );
	}

	public function test_reconcile_uses_only_staged_recovery_and_does_not_recapture_or_stage(): void {
		$command = $this->command(); $first = $this->call( $command, $this->lease( $command ), static fn(): bool => false );
		$recovery = FixedPromotionQuoteIssueCommand::from_recovery_array( $first->command->to_recovery_array() );
		$result = $this->service->reconcile( $recovery, RequestContext::create() );
		$this->assert_unknown( $result ); self::assertNull( $result->command->lease() ); self::assertSame( $recovery->to_recovery_array(), $result->command->to_recovery_array() ); self::assertSame( 1, $this->provider->captures ); self::assertSame( 1, $this->factory->opens );
	}

	public function test_authorization_loss_in_stage_withholds_private_recovery_and_never_opens_c03(): void {
		$command = $this->command();
		$result = $this->call( $command, $this->lease( $command ), function(): bool { $this->authorized = false; return true; } );
		$this->assert_unknown( $result ); self::assertNull( $result->command ); self::assertSame( 0, $this->factory->opens ); self::assertSame( 1, $this->provider->captures );
	}

	public function test_reconcile_authorization_denial_returns_no_private_recovery(): void {
		$command = $this->command(); $staged = $this->call( $command, $this->lease( $command ), static fn(): bool => false )->command; $this->authorized = false;
		$result = $this->service->reconcile( $staged, RequestContext::create() );
		self::assertSame( 'not_authorized', $result->attempt->outcome->error->code ); self::assertNull( $result->command ); self::assertSame( 0, $this->factory->opens ); self::assertSame( 1, $this->provider->captures );
	}

	public function test_private_inputs_result_and_service_refuse_implicit_export_and_redact_debug(): void {
		$command = $this->command(); $result = $this->call( $command, $this->lease( $command ), static fn(): bool => false );
		foreach ( [ $this->inputs, $this->service, $result ] as $value ) {
			foreach ( [ static fn(): string => json_encode( $value, JSON_THROW_ON_ERROR ), static fn(): string => serialize( $value ) ] as $export ) { try { $export(); self::fail( 'Private issue data cannot export implicitly.' ); } catch ( \LogicException $error ) { self::assertStringNotContainsString( $this->slots[0]['account_key'], $error->getMessage() ); } }
			ob_start(); var_dump( $value ); $debug = ob_get_clean(); self::assertStringContainsString( 'redacted', $debug ); self::assertStringNotContainsString( $this->slots[0]['account_key'], $debug ); self::assertStringNotContainsString( $result->command->reference()->handle(), $debug );
		}
	}

	public function test_result_refuses_a_live_capture_command(): void {
		$command = $this->command(); $lease = $this->lease( $command ); $result = $this->call( $command, $lease, static fn(): bool => false ); $recovery = $result->command;
		$live = FixedPromotionQuoteIssueCommand::captured( $command, $lease, \CetechDeliveryEngine\Domain\DeliveryQuote\DeliveryQuote::issue( $recovery->header(), $recovery->context(), $recovery->terms() ), $recovery->reference() );
		$this->expectException( \InvalidArgumentException::class ); new FixedPromotionQuoteIssueResult( new OperationAttemptResult( OperationOutcome::pending() ), $live );
	}
}

final class PromotionIssueConnectionProbe implements OperationConnectionFactory {
	public int $opens = 0;
	public ?\Closure $before_open = null;
	public function open(): OperationSession { ++$this->opens; if ( null !== $this->before_open ) { ( $this->before_open )(); } throw new \RuntimeException( 'Synthetic owner-open failure.' ); }
}

final class PromotionIssueProviderProbe implements QuoteProviderInterface {
	public int $captures = 0;
	public bool $throw = false;
	public function __construct( public FixedPromotionQuoteProvider $inner ) {}
	public function code(): string { return $this->inner->code(); }
	public function version(): int { return $this->inner->version(); }
	public function profile(): string { return $this->inner->profile(); }
	public function profile_version(): int { return $this->inner->profile_version(); }
	public function evidence_providers(): array { return $this->inner->evidence_providers(); }
	public function capture( QuoteContext $context ): QuoteTerms { ++$this->captures; if ( $this->throw ) { throw new \RuntimeException( 'Synthetic capture failure.' ); } return $this->inner->capture( $context ); }
}
