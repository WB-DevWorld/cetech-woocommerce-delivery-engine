<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryCommercial;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteProvider, QuoteAdmissionAttempt, QuoteAdmissionGate, QuoteAdmissionLease, QuoteCurrentEvidenceGuard, QuoteDurableCommand, QuoteDurableResult, QuoteDurableService, QuoteIssueCommand, QuoteOperationProfile, QuotePlacementProof, QuotePlacementSavedEvidenceGuard, QuoteProviderInterface, QuoteProviderRegistry};
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteBudgetSlot, QuoteContext, QuoteHeader, QuoteId, QuoteOwner, QuoteReference, QuoteTerms};
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures, QuoteStorageFixtures};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';

/** Reader support never supplies campaign reservation or checkout admission authority. */
final class FixedPromotionAdmissionRefusalTest extends TestCase {

	private FixedPromotionAdmissionConnectionProbe $factory;
	private FixedPromotionAdmissionProviderProbe $provider;
	private int $authorizations = 0;
	private QuoteDurableService $service;

	protected function setUp(): void {
		$this->factory = new FixedPromotionAdmissionConnectionProbe();
		$this->provider = new FixedPromotionAdmissionProviderProbe( F::provider() );
		$authorize = function(): bool { ++$this->authorizations; return true; };
		$evidence = new class implements QuoteCurrentEvidenceGuard {
			public function tables( OperationSession $session ): array { throw new \LogicException( 'Evidence must not be called.' ); }
			public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool { throw new \LogicException( 'Evidence must not be called.' ); }
		};
		$this->service = new QuoteDurableService( $this->factory, new QuoteProviderRegistry( [ $this->provider ] ), $authorize, evidence: $evidence );
	}

	/** @return array{QuoteIssueCommand,QuoteHeader,QuoteReference} */
	private function parts( bool $forward = true ): array {
		$context = $forward ? $this->provider->inner->context() : QuoteFixtures::context();
		$terms = $forward ? $this->provider->inner->terms() : QuoteFixtures::terms();
		$profile = $forward ? FixedPromotionQuoteProvider::CODE : 'fixture_v1';
		$command = QuoteIssueCommand::create( F::owner(), $context, $profile, 1, $profile, 1, 'reader_first_original_issue' );
		$id = QuoteId::generate(); $reference = QuoteReference::generate( $id );
		$header = QuoteHeader::issue( $id, $command->owner(), $context, $terms, QuoteFixtures::time(), $command->namespace_hashes( $id ), $profile, 1, $reference );
		return [ $command, $header, $reference ];
	}

	private function assert_refused( QuoteDurableResult $result ): void {
		self::assertSame( 'rejected', $result->attempt->outcome->state );
		self::assertSame( 'temporarily_unavailable', $result->attempt->outcome->error->code );
		self::assertNull( $result->command ); self::assertNull( $result->quote ); self::assertNull( $result->binding );
	}
	private function assert_quiet(): void {
		self::assertSame( 0, $this->authorizations );
		self::assertSame( 0, $this->factory->opens );
		self::assertSame( 0, $this->provider->captures );
	}

	public function test_issue_refuses_before_authorizer_provider_gate_or_attempt_consumption(): void {
		[ $command ] = $this->parts(); $attempt = QuoteAdmissionAttempt::generate();
		$this->assert_refused( $this->service->issue( $command, $attempt, RequestContext::create() ) );
		$this->assert_quiet(); self::assertTrue( $attempt->begin( $command ) );
	}

	public function test_admitted_issue_keeps_matching_live_lease_unused_and_does_not_stage(): void {
		[ $command ] = $this->parts(); $attempt = QuoteAdmissionAttempt::generate();
		self::assertTrue( $attempt->begin( $command ) ); self::assertTrue( $attempt->confirm() );
		$namespace = $command->identity()->namespace_digest();
		$slot = QuoteBudgetSlot::from_row( array_replace( QuoteStorageFixtures::budget()->row(), [
			'admission_namespace_hash' => $namespace, 'slot_key' => QuoteBudgetSlot::admission_slot_key( $namespace ),
			'admission_intent_digest' => $command->intent_digest(), 'server_attempt_digest' => $attempt->digest(),
		] ) );
		$lease = QuoteAdmissionLease::confirmed( $slot, $command, $attempt ); self::assertTrue( $lease->matches( $command ) );
		$stages = 0;
		$this->assert_refused( $this->service->issue_admitted( $command, $lease, RequestContext::create(), static function() use ( &$stages ): bool { ++$stages; return true; } ) );
		$this->assert_quiet(); self::assertSame( 0, $stages ); self::assertFalse( $lease->capture_started() ); self::assertTrue( $lease->claim_capture() );
	}

	public static function issue_probe_shapes(): array { return [ 'header absent' => [ false ], 'header present' => [ true ] ]; }
	#[DataProvider( 'issue_probe_shapes' )]
	public function test_reconcile_refuses_both_original_probe_shapes_before_callbacks( bool $with_header ): void {
		[ $command, $header, $reference ] = $this->parts();
		$probe = QuoteDurableCommand::issue_probe( $command, $with_header ? $reference : null, $with_header ? $header : null );
		$this->assert_refused( $this->service->reconcile( $probe, RequestContext::create() ) ); $this->assert_quiet();
	}

	public static function transition_shapes(): array {
		return [ 'accept forward header' => [ 'accept', true ], 'invalidate forward header' => [ 'invalidate', true ],
			'accept forward current' => [ 'accept', false ], 'invalidate forward current' => [ 'invalidate', false ] ];
	}
	#[DataProvider( 'transition_shapes' )]
	public function test_transitions_refuse_forward_header_or_current_context_before_callbacks( string $action, bool $forward_header ): void {
		[ $command, $header, $reference ] = $this->parts( $forward_header );
		$this->assert_refused( $this->service->$action( $command->owner(), $reference, $header, $this->provider->inner->context(), RequestContext::create() ) );
		$this->assert_quiet();
	}

	public static function operation_shapes(): array { return [ 'headerless issue' => [ 'probe' ], 'opened issue' => [ 'opened' ], 'legacy header with forward current' => [ 'current' ] ]; }
	#[DataProvider( 'operation_shapes' )]
	public function test_bound_operation_profile_refuses_before_authorizer( string $shape ): void {
		[ $command, $header, $reference ] = $this->parts( 'current' !== $shape );
		$bound = match ( $shape ) {
			'probe' => QuoteDurableCommand::issue_probe( $command ),
			'opened' => QuoteDurableCommand::issue_probe( $command, $reference, $header ),
			default => QuoteDurableCommand::accept( $command->owner(), $reference, $header, $this->provider->inner->context() ),
		};
		$calls = 0; $profile = new QuoteOperationProfile( $bound->identity->operation, $bound, static function() use ( &$calls ): bool { ++$calls; return true; } );
		self::assertFalse( $profile->authorize( $bound->identity ) ); self::assertSame( 0, $calls ); $this->assert_quiet();
	}

	public function test_retained_v1_bound_profile_still_calls_its_authorizer(): void {
		[ $command ] = $this->parts( false ); $bound = QuoteDurableCommand::issue_probe( $command ); $calls = 0;
		$profile = new QuoteOperationProfile( $bound->identity->operation, $bound, static function() use ( &$calls ): bool { ++$calls; return true; } );
		self::assertTrue( $profile->authorize( $bound->identity ) ); self::assertSame( 1, $calls );
	}

	public static function legacy_context_aliases(): array {
		return [ 'forward profile' => [ 'fixture_v1', FixedPromotionQuoteProvider::CODE ], 'forward provider' => [ FixedPromotionQuoteProvider::CODE, 'fixture_v1' ] ];
	}
	#[DataProvider( 'legacy_context_aliases' )]
	public function test_forward_profile_or_provider_cannot_charge_under_legacy_context( string $provider, string $profile ): void {
		$command = QuoteIssueCommand::create( F::owner(), QuoteFixtures::context(), $provider, 1, $profile, 1, 'reader_first_alias_probe' );
		$attempt = QuoteAdmissionAttempt::generate();
		$this->assert_refused( $this->service->issue( $command, $attempt, RequestContext::create() ) ); self::assertTrue( $attempt->begin( $command ) );
		$bound = QuoteDurableCommand::issue_probe( $command ); $this->assert_refused( $this->service->reconcile( $bound, RequestContext::create() ) );
		$calls = 0; $operation = new QuoteOperationProfile( $bound->identity->operation, $bound, static function() use ( &$calls ): bool { ++$calls; return true; } );
		self::assertFalse( $operation->authorize( $bound->identity ) ); self::assertSame( 0, $calls );
		$gate = new QuoteAdmissionGate( $this->factory, function(): bool { ++$this->authorizations; return true; } );
		foreach ( [ 'admit', 'inspect', 'reconcile' ] as $method ) {
			$attempt = QuoteAdmissionAttempt::generate(); $result = 'inspect' === $method ? $gate->inspect( $command ) : $gate->$method( $command, $attempt );
			self::assertSame( 'denied', $result->status ); self::assertSame( 'storage_unavailable', $result->reason ); self::assertNull( $result->lease ); self::assertTrue( $attempt->begin( $command ) );
		}
		$this->assert_quiet();
	}

	public static function gate_methods(): array { return [ 'admit' => [ 'admit' ], 'inspect' => [ 'inspect' ], 'reconcile' => [ 'reconcile' ] ]; }
	#[DataProvider( 'gate_methods' )]
	public function test_direct_gate_refuses_without_authorizer_sql_or_attempt_consumption( string $method ): void {
		[ $command ] = $this->parts(); $attempt = QuoteAdmissionAttempt::generate();
		$gate = new QuoteAdmissionGate( $this->factory, function(): bool { ++$this->authorizations; return true; } );
		$result = 'inspect' === $method ? $gate->inspect( $command ) : $gate->$method( $command, $attempt );
		self::assertSame( 'denied', $result->status ); self::assertSame( 'storage_unavailable', $result->reason ); self::assertNull( $result->lease );
		$this->assert_quiet(); self::assertTrue( $attempt->begin( $command ) );
	}

	public function test_placement_and_order_pay_refuse_forward_current_with_real_retained_bindings(): void {
		$quote = QuoteStorageFixtures::quote( state: 'accepted' ); $header = $quote->header(); $owner = $header->owner(); $reference = QuoteFixtures::reference( $header->id() );
		$binding = QuoteStorageFixtures::binding( $quote );
		$verified = QuoteBinding::from_row( array_replace( $binding->row(), [ 'revision' => 2, 'verified_at' => QuoteFixtures::time()->plus_seconds( 2 )->sql(), 'snapshot_digest' => QuoteFixtures::digest( 'saved' ), 'context_digest' => QuoteFixtures::digest( 'saved context' ) ] ), $quote );
		$sealed = QuoteBinding::from_row( array_replace( $verified->row(), [ 'revision' => 3, 'state' => 'sealed', 'sealed_at' => QuoteFixtures::time()->plus_seconds( 3 )->sql() ] ), $quote );
		$saved = new class implements QuotePlacementSavedEvidenceGuard {
			public int $calls = 0;
			public function tables( OperationSession $session ): array { ++$this->calls; throw new \LogicException( 'Saved facts must not be called.' ); }
			public function verify( OperationSession $session, QuoteBinding $binding ): bool { ++$this->calls; throw new \LogicException( 'Saved facts must not be called.' ); }
		};
		$local = EmergencyCheckoutLocalBinding::capture( new \WC_Order( [ 'id' => 100 ] ) ); self::assertNotNull( $local );
		$proof = QuotePlacementProof::capture( $verified, 1, $local, $saved ); $current = $this->provider->inner->context(); $request = RequestContext::create();
		$this->assert_refused( $this->service->bind( $owner, $reference, $header, $binding, $request, $current ) );
		$this->assert_refused( $this->service->seal( $owner, $reference, $header, $verified, $request, $current ) );
		$this->assert_refused( $this->service->verify_binding( $owner, $reference, $header, $verified, $request, $current, $saved ) );
		$this->assert_refused( $this->service->seal_placement( $owner, $reference, $header, $verified, $request, $current, $proof ) );
		self::assertFalse( $this->service->admit_sealed_placement( $owner, $reference, $header, $sealed, $request, $current, 1, $local, $saved ) );
		$this->assert_quiet(); self::assertSame( 0, $saved->calls );
	}
}

final class FixedPromotionAdmissionConnectionProbe implements OperationConnectionFactory {
	public int $opens = 0;
	public function open(): OperationSession { ++$this->opens; throw new \LogicException( 'No SQL session may be opened for a reader-only format.' ); }
}

final class FixedPromotionAdmissionProviderProbe implements QuoteProviderInterface {
	public int $captures = 0;
	public function __construct( public readonly FixedPromotionQuoteProvider $inner ) {}
	public function code(): string { return $this->inner->code(); }
	public function version(): int { return $this->inner->version(); }
	public function profile(): string { return $this->inner->profile(); }
	public function profile_version(): int { return $this->inner->profile_version(); }
	public function evidence_providers(): array { return $this->inner->evidence_providers(); }
	public function capture( QuoteContext $context ): QuoteTerms { ++$this->captures; return $this->inner->capture( $context ); }
}
