<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteBindService, FixedPromotionQuoteSealService, FixedPromotionQuoteVerifyService, FixedPromotionSavedOrderPlacementEvidenceReader};
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
use CetechDeliveryEngine\Application\Order\QuoteNativeOrderStager;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Integrations\DeliveryQuote\FixedPromotionNativePlacementRuntime;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionNativeUnitFixture, PromotionSealedReadFixture};
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionNativeUnitFixture.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionSealedReadFixture.php';

/** Composition refusals over real typed services; no successful native checkout is claimed here. */
final class FixedPromotionNativePlacementRuntimeTest extends TestCase {
	private array $globals = [];
	protected function setUp(): void { foreach ( [ 'current_user', 'blog_id', 'woocommerce', '_GET', '_POST' ] as $key ) { $this->globals[$key] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[$key] ?? null ]; } }
	protected function tearDown(): void { foreach ( $this->globals as $key => [ $present, $value ] ) { if ( $present ) { $GLOBALS[$key] = $value; } else { unset( $GLOBALS[$key] ); } } }
	private function runtime( callable $current, callable $authorize, ?callable $selected = null ): array {
		$history = new PromotionSealedReadFixture(); $native = PromotionNativeUnitFixture::create(); $owner = static fn(): bool => true;
		$runtime = new FixedPromotionNativePlacementRuntime( $history, new QuoteNativeOrderStager( $history ), new FixedPromotionQuoteBindService( $history, $history->budget, $history->eligibility, $owner ), new FixedPromotionQuoteVerifyService( $history, $history->budget, $history->eligibility, $owner ), new FixedPromotionQuoteSealService( $history, $history->budget, $history->eligibility, $owner ), $history->reader(), new FixedPromotionSavedOrderPlacementEvidenceReader( $history, $authorize, $owner ), $current, $authorize, selected: $selected );
		return compact( 'runtime', 'history', 'native' );
	}
	public function test_absent_private_promotion_selection_is_off_without_touching_sql(): void {
		$f = $this->runtime( static fn(): null => null, static fn(): bool => true ); self::assertFalse( $f['runtime']->selected() ); self::assertSame( [], $f['history']->sessions );
	}
	public function test_corrupt_or_throwing_private_bundle_is_owned_for_refusal_and_never_falls_back(): void {
		foreach ( [ static fn(): array => [ 'evidence' => 'shopper-field' ], static function(): never { throw new \RuntimeException( 'Unavailable private session.' ); } ] as $current ) {
			$f = $this->runtime( $current, static fn(): bool => true ); self::assertTrue( $f['runtime']->selected() ); self::assertSame( [], $f['history']->sessions );
		}
	}
	public function test_explicit_selection_does_not_read_a_cart_as_an_implicit_activation_side_effect(): void {
		$calls = 0; $f = $this->runtime( static function() use ( &$calls ): never { ++$calls; throw new \LogicException(); }, static fn(): bool => true, static fn(): bool => false );
		self::assertFalse( $f['runtime']->selected() ); self::assertSame( 0, $calls );
	}
	public function test_native_authorization_precedes_order_getters_cart_or_sql_on_public_private_entrypoints(): void {
		$calls = 0; $f = $this->runtime( static function() use ( &$calls ): never { ++$calls; throw new \LogicException(); }, static fn(): bool => false ); $order = $f['native']['order']; $gets = $order->gets;
		self::assertFalse( $f['runtime']->validate_order( $order, 'classic' ) ); self::assertNull( $f['runtime']->sealed( $order ) ); $f['runtime']->observe_native_logging_save( $order );
		$local = ( new \ReflectionClass( EmergencyCheckoutLocalBinding::class ) )->newInstanceWithoutConstructor();
		self::assertFalse( $f['runtime']->complete( $order, 'classic', 1, $local ) ); self::assertFalse( $f['runtime']->admitted( $order, 'classic', 1, $local ) );
		try { $f['runtime']->stage( $order, 'classic' ); self::fail( 'Unauthorized order reached native staging.' ); } catch ( \UnexpectedValueException ) {}
		self::assertNull( $f['runtime']->current_sealed( $order, 1, RequestContext::create() ) ); self::assertSame( $gets, $order->gets ); self::assertSame( 0, $calls ); self::assertSame( [], $f['history']->sessions );
	}
	public function test_no_unstaged_order_can_use_c07_confirmation_as_payment_admission(): void {
		$f = $this->runtime( static fn(): null => null, static fn(): bool => true ); $order = $f['native']['order']; $local = ( new \ReflectionClass( EmergencyCheckoutLocalBinding::class ) )->newInstanceWithoutConstructor();
		foreach ( [ 'classic', 'store_api', 'order_pay' ] as $route ) { self::assertFalse( $f['runtime']->complete( $order, $route, 1, $local ) ); self::assertFalse( $f['runtime']->admitted( $order, $route, 1, $local ) ); }
		self::assertNull( $f['runtime']->sealed( $order ) ); self::assertSame( [], $f['history']->sessions );
	}
	public function test_runtime_cannot_escape_through_implicit_serialization(): void {
		$f = $this->runtime( static fn(): null => null, static fn(): bool => true );
		foreach ( [ static fn() => json_encode( $f['runtime'], JSON_THROW_ON_ERROR ), static fn() => serialize( $f['runtime'] ) ] as $attempt ) { try { $attempt(); self::fail( 'Trusted runtime escaped implicitly.' ); } catch ( \LogicException ) {} }
		self::assertSame( [ 'private_native_promotion_placement' => 'redacted' ], $f['runtime']->__debugInfo() ); self::assertStringNotContainsString( $f['history']->command->header()->id()->value(), print_r( $f['runtime'], true ) );
		self::assertSame( [], $f['history']->sessions );
	}
}
