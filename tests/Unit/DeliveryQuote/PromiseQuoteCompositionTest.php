<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

require_once __DIR__ . '/../../Support/DeliveryQuote/PromiseQuoteActivationFixtures.php';
use CetechDeliveryEngine\Application\DeliveryQuote\{LegacyFixedBaseQuoteProvider,PromiseQuotePlacementActivation,ServicePromiseQuoteProvider};
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Bootstrap\PromiseQuoteComposition;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding;
use CetechDeliveryEngine\Integrations\ServicePromise\PromiseNativeServiceRegistry;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\PromiseQuoteActivationFactory;
use PHPUnit\Framework\TestCase;

/** Actual lazy composition and protected option/CAS model; no native runtime qualification claim. */
final class PromiseQuoteCompositionTest extends TestCase {
	private function activation( PromiseQuoteActivationFactory $f, ?callable $ready = null ): PromiseQuotePlacementActivation {
		return new PromiseQuotePlacementActivation( $f, $ready ?? static fn(): bool => true, new class implements OperationReadiness { public function assert_ready( OperationSession $s ): void {} }, static fn(): bool => true );
	}
	private function configure( PromiseQuotePlacementActivation $a ): OperationIdentity {
		$actor = new OperationIdentity( 1, 'service_promise.settings', 'user:1', 'service_promise.adoption', 1, 'promise-adoption:original_site', 'original-adoption' );
		$registry = new PromiseNativeServiceRegistry( [ [ 'native_service_id' => 5, 'service_kind' => 'merchant', 'service_code' => 'native_ground', 'origin_endpoint' => 'dispatch_one', 'origin_kind' => 'dispatch', 'destination_endpoint' => 'doorstep_one', 'destination_kind' => 'doorstep' ] ] );
		self::assertTrue( $a->configure( PromiseSiteBinding::bind( 1, 'original_site' ), $registry, 0, $actor ) ); return $actor;
	}
	public function test_service_resolution_and_explicit_off_neither_open_sql_eagerly_nor_install_new_profile(): void {
		$f = new PromiseQuoteActivationFactory(); $a = $this->activation( $f ); $c = new PromiseQuoteComposition( $f, $a ); $env = $c->environment();
		self::assertSame( $env, $c->environment() ); self::assertSame( 0, $f->opens ); self::assertSame( 0, $f->adoption_rows() );
		self::assertSame( LegacyFixedBaseQuoteProvider::CODE, $env->profile() ); self::assertNull( $c->capture_service() ); self::assertSame( 0, $f->adoption_rows() );
		$this->configure( $a ); self::assertSame( LegacyFixedBaseQuoteProvider::CODE, $env->profile() ); self::assertNull( $c->capture_service() );
	}
	public function test_separate_enable_installs_exact_native_capture_once_and_changed_configuration_refuses(): void {
		$f = new PromiseQuoteActivationFactory(); $a = $this->activation( $f ); $actor = $this->configure( $a ); self::assertTrue( $a->change( true, 1, $actor ) ); $c = new PromiseQuoteComposition( $f, $a );
		self::assertSame( ServicePromiseQuoteProvider::PROFILE, $c->environment()->profile() ); $capture = $c->capture_service(); self::assertNotNull( $capture ); self::assertSame( $capture, $c->capture_service() );
		self::assertTrue( $a->change( false, 2, $actor ) ); self::assertSame( LegacyFixedBaseQuoteProvider::CODE, $c->environment()->profile() ); self::assertNull( $c->capture_service() );
		self::assertTrue( $a->change( true, 3, $actor ) ); self::assertNull( $c->capture_service() ); $this->expectException( \RuntimeException::class ); $c->environment()->profile();
	}
	public function test_requested_profile_with_lost_readiness_refuses_without_legacy_fallback(): void {
		$f = new PromiseQuoteActivationFactory(); $ready = true; $a = $this->activation( $f, static function () use ( &$ready ): bool { return $ready; } ); $actor = $this->configure( $a ); self::assertTrue( $a->change( true, 1, $actor ) ); $ready = false;
		$c = new PromiseQuoteComposition( $f, $a ); self::assertNull( $c->capture_service() ); $this->expectException( \RuntimeException::class ); $c->environment()->profile();
	}
	public function test_unknown_protected_option_cannot_be_interpreted_as_explicit_off(): void {
		$f = new PromiseQuoteActivationFactory(); $a = $this->activation( $f ); $this->configure( $a ); $f->pdo->exec( "UPDATE activation_options SET option_value='{\"format\":999}' WHERE option_name='" . PromiseQuotePlacementActivation::OPTION . "'" );
		$c = new PromiseQuoteComposition( $f, $a ); self::assertNull( $c->capture_service() ); $this->expectException( \RuntimeException::class ); $c->environment()->profile();
	}
}
