<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Presentation;

use CetechDeliveryEngine\Application\CustomerContext\{LocationAwareDeliveryOptions, LocationOfferQuoteProbe};
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface;
use CetechDeliveryEngine\Application\ProductRule\{ProductRuleResolutionResult, ResolvedProductDeliveryRule};
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\ServicePromise\Presentation\PromisePublicProjection;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Presentation\Frontend\ProductDeliverySelectorRenderer;
use PHPUnit\Framework\TestCase;

final class PromisePreliminaryProductRendererTest extends TestCase {
	private function html( string $estimate ): string {
		$offers = $this->createMock( DeliveryOfferRepositoryInterface::class ); $offers->method( 'findById' )->willReturn( [ 'id' => 7, 'status' => 'active', 'route' => 'local_delivery', 'public_label' => 'Standard' ] ); $builder = new ProductDeliveryOptionsBuilder( $offers );
		$rule = new ResolvedProductDeliveryRule( 1, 'product', 10, null, 1, 'in_warehouse', 'delivery', [ 7 ], 1, 1, 1, 1 ); $result = new ProductRuleResolutionResult( true, null, 'product', 10, null, [], '', [], [ 'in_warehouse' => $rule ], [], [], [], null ); $option = $builder->buildFromResolution( $result )[0]->withPromiseEstimate( $estimate, true );
		$zones = $this->createMock( PackageDestinationZoneResolverInterface::class ); $zones->expects( self::never() )->method( 'resolve_zone_ids' ); $rates = $this->createMock( RateCardRepositoryInterface::class ); $rates->expects( self::never() )->method( 'listActiveForQuoteMatch' );
		$source = $this->createMock( ProductDeliveryConfigurationSourceInterface::class ); $source->expects( self::never() )->method( 'resolve' );
		$location = new LocationAwareDeliveryOptions( new LocationOfferQuoteProbe( $zones, new RateQuoteEngine( $rates ) ) ); $renderer = new ProductDeliverySelectorRenderer( new FeatureFlags(), new Requirements(), $source, $builder, location_options: $location );
		ob_start(); try { ( new \ReflectionMethod( $renderer, 'render_interactive_options' ) )->invoke( $renderer, [ $option ], 10 ); return (string) ob_get_contents(); } finally { ob_end_clean(); }
	}
	public function test_incomplete_destination_shows_shared_preliminary_notice_while_unpriced_native_cards_stay_hidden(): void {
		$notice = PromisePublicProjection::hypothetical( [], false )['notice']; $html = $this->html( $notice );
		self::assertStringContainsString( 'data-cetech-de-location-panel="1"', $html ); self::assertStringContainsString( 'data-cetech-de-promise-preliminary="1"', $html ); self::assertStringContainsString( 'role="note"', $html ); self::assertStringContainsString( $notice, $html );
		self::assertStringNotContainsString( 'type="radio"', $html ); self::assertStringNotContainsString( 'data-cetech-de-original-promise', $html ); self::assertDoesNotMatchRegularExpression( '/20\d{2}-\d{2}-\d{2}/', $html );
	}
	public function test_legacy_unpriced_option_does_not_gain_a_promise_notice(): void { $html = $this->html( '3 business days' ); self::assertStringNotContainsString( 'data-cetech-de-promise-preliminary', $html ); self::assertStringNotContainsString( 'Preliminary estimate', $html ); self::assertStringNotContainsString( 'type="radio"', $html ); }
}
