<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Presentation;

use CetechDeliveryEngine\Application\ProductRule\{ProductRuleResolutionResult, ResolvedProductDeliveryRule};
use CetechDeliveryEngine\Application\Selector\{ProductDeliveryOption, ProductDeliveryOptionsBuilder};
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use PHPUnit\Framework\TestCase;

final class PromiseOptionSelectionTest extends TestCase {
	private function builder( ?callable $presenter = null ): ProductDeliveryOptionsBuilder {
		$repository = $this->createMock( DeliveryOfferRepositoryInterface::class ); $repository->method( 'findById' )->willReturn( [ 'id' => 7, 'status' => 'active', 'route' => 'local_delivery', 'public_label' => 'Standard', 'default_processing_min' => 1, 'default_processing_max' => 2, 'duration_unit' => 'business_days' ] ); return new ProductDeliveryOptionsBuilder( $repository, promise_presenter: $presenter );
	}
	private function resolution(): ProductRuleResolutionResult { $availability = FulfilmentAvailability::InStore->value; $rule = new ResolvedProductDeliveryRule( 1, 'product', 10, null, 1, $availability, 'delivery', [ 7 ], 1, 1, 1, 1 ); return new ProductRuleResolutionResult( true, null, 'product', 10, null, [], '', [], [ $availability => $rule ], [], [], [], null ); }
	public function test_unavailable_required_promise_cannot_remain_the_default_choice_and_retains_original_transport_identity(): void {
		$result = $this->resolution(); $legacy = $this->builder()->buildFromResolution( $result )[0]; $new = $this->builder( static fn( ProductDeliveryOption $option ): ProductDeliveryOption => $option->withPromiseEstimate( null, false, 'Service unavailable' ) )->buildFromResolution( $result )[0];
		self::assertTrue( $legacy->is_available ); self::assertFalse( $new->is_available ); self::assertFalse( $new->is_default ); self::assertSame( '', ProductDeliveryOptionsBuilder::defaultDisplayKey( [ $new ] ) ); self::assertSame( $legacy->display_key, $new->display_key ); self::assertSame( $legacy->delivery_offer_id, $new->delivery_offer_id ); self::assertNull( $new->estimate_text );
	}
	public function test_unavailable_source_or_broken_preview_cannot_fallback_to_legacy_winning_estimate(): void {
		$options = $this->builder( static function(): never { throw new \RuntimeException( 'PRIVATE-DATABASE-DETAIL' ); } )->buildFromResolution( $this->resolution() );
		self::assertFalse( $options[0]->is_available ); self::assertNull( $options[0]->estimate_text ); self::assertStringNotContainsString( 'PRIVATE', json_encode( $options[0]->toArray() ) ); self::assertStringNotContainsString( '1–2', json_encode( $options[0]->toArray() ) );
	}
	public function test_preliminary_copy_keeps_native_option_identity_and_requires_final_checkout_review(): void {
		$options = $this->builder( static fn( ProductDeliveryOption $option ): ProductDeliveryOption => $option->withPromiseEstimate( 'Preliminary estimate. Review delivery at checkout.', true ) )->buildFromResolution( $this->resolution() );
		self::assertTrue( $options[0]->is_available ); self::assertStringStartsWith( 'Preliminary', $options[0]->estimate_text ); self::assertArrayNotHasKey( 'acceptance_handle', $options[0]->toArray() ); self::assertArrayNotHasKey( 'promise_policy', $options[0]->toArray() ); self::assertSame( 7, $options[0]->delivery_offer_id );
	}
}
