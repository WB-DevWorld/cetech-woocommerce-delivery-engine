<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Cart;

use CetechDeliveryEngine\Application\Cart\CartCustomerContextMutationService;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionReconciler;
use CetechDeliveryEngine\Application\Cart\CartLineCustomerIdentity;
use CetechDeliveryEngine\Application\Cart\CartMutationResult;
use CetechDeliveryEngine\Application\Cart\CartReconciliationOutcome;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionIntent;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidationResult;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Tests\Unit\CustomerContext\PerItemContextFixtures;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PerItemCartIdentityAndMutationTest extends TestCase {

	private const INTENT = [
		'contract_version'          => '1',
		'product_id'               => 101,
		'variation_id'             => 7,
		'target_type'              => 'variation',
		'target_id'                 => 7,
		'display_key'              => 'in_warehouse:delivery:10',
		'fulfilment_availability'    => 'in_warehouse',
		'fulfilment_choice'         => 'delivery',
		'delivery_offer_id'        => 10,
		'rule_id'                  => 5,
		'issued_at'                => '2026-08-01T00:00:00+00:00',
		'configuration_fingerprint' => 'old-fingerprint',
	];

	public function test_same_product_same_incomplete_matching_context_merges(): void {
		$ctx  = PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() );
		$a    = PerItemContextFixtures::cartItem( self::INTENT, $ctx, 1 );
		$b    = PerItemContextFixtures::cartItem( self::INTENT, $ctx, 1 );

		self::assertSame( CartLineCustomerIdentity::cartIdFromItem( $a ), CartLineCustomerIdentity::cartIdFromItem( $b ) );
	}

	public function test_different_matching_locations_separate(): void {
		$accra   = PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() );
		$spintex = PerItemContextFixtures::incompleteContext(
			10,
			\CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation::fromInput(
				[ 'country' => 'GH', 'state' => 'AA', 'city' => 'Spintex', 'postcode' => 'GA-111' ]
			)
		);

		self::assertNotSame(
			CartLineCustomerIdentity::cartIdFromItem( PerItemContextFixtures::cartItem( self::INTENT, $accra ) ),
			CartLineCustomerIdentity::cartIdFromItem( PerItemContextFixtures::cartItem( self::INTENT, $spintex ) )
		);
	}

	public function test_same_product_same_full_destination_merges(): void {
		$ctx = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryEastLegon() );

		self::assertSame(
			CartLineCustomerIdentity::cartIdFromItem( PerItemContextFixtures::cartItem( self::INTENT, $ctx ) ),
			CartLineCustomerIdentity::cartIdFromItem( PerItemContextFixtures::cartItem( self::INTENT, $ctx ) )
		);
	}

	public function test_different_streets_separate(): void {
		$a = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryEastLegon( '12 Boundary Rd' ) );
		$b = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryEastLegon( '99 Boundary Rd' ) );

		self::assertNotSame(
			CartLineCustomerIdentity::cartIdFromItem( PerItemContextFixtures::cartItem( self::INTENT, $a ) ),
			CartLineCustomerIdentity::cartIdFromItem( PerItemContextFixtures::cartItem( self::INTENT, $b ) )
		);
	}

	public function test_delivery_vs_pickup_separate(): void {
		$delivery = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryEastLegon() );
		$pickup   = CustomerCartContext::pickup( 2 );

		$pickup_intent = array_merge( self::INTENT, [ 'fulfilment_choice' => 'store_pickup', 'delivery_offer_id' => null ] );

		self::assertNotSame(
			CartLineCustomerIdentity::cartIdFromItem( PerItemContextFixtures::cartItem( self::INTENT, $delivery ) ),
			CartLineCustomerIdentity::cartIdFromItem( PerItemContextFixtures::cartItem( $pickup_intent, $pickup ) )
		);
	}

	public function test_different_pickup_location_ids_separate(): void {
		$intent = array_merge( self::INTENT, [ 'fulfilment_choice' => 'store_pickup', 'delivery_offer_id' => null ] );

		self::assertNotSame(
			CartLineCustomerIdentity::cartIdFromItem( PerItemContextFixtures::cartItem( $intent, CustomerCartContext::pickup( 1 ) ) ),
			CartLineCustomerIdentity::cartIdFromItem( PerItemContextFixtures::cartItem( $intent, CustomerCartContext::pickup( 2 ) ) )
		);
	}

	public function test_admin_label_and_fingerprint_do_not_split(): void {
		$ctx = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryEastLegon() );
		$a    = PerItemContextFixtures::cartItem(
			self::INTENT,
			$ctx,
			1,
			[
				CartDeliverySelectionCapture::CART_SUMMARY_KEY => [ 'delivery_offer_public_label' => 'Old' ],
				CartDeliverySelectionCapture::CART_HASH_KEY    => str_repeat( 'a', 64 ),
			]
		);
		$b = PerItemContextFixtures::cartItem(
			array_merge( self::INTENT, [ 'configuration_fingerprint' => 'new', 'rule_id' => 99 ] ),
			$ctx,
			1,
			[
				CartDeliverySelectionCapture::CART_SUMMARY_KEY => [ 'delivery_offer_public_label' => 'New', 'estimate_text' => '9 days' ],
				CartDeliverySelectionCapture::CART_HASH_KEY    => str_repeat( 'b', 64 ),
			]
		);

		self::assertSame( CartLineCustomerIdentity::cartIdFromItem( $a ), CartLineCustomerIdentity::cartIdFromItem( $b ) );
		self::assertStringNotContainsString( 'Boundary', CartLineCustomerIdentity::cartIdFromItem( $a ) );
	}

	public function test_recipient_name_only_change_does_not_split(): void {
		$base = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryEastLegon() );
		$renamed = $base->withRecipient( PerItemContextFixtures::recipient() );

		self::assertSame(
			CartLineCustomerIdentity::cartIdFromItem( PerItemContextFixtures::cartItem( self::INTENT, $base ) ),
			CartLineCustomerIdentity::cartIdFromItem( PerItemContextFixtures::cartItem( self::INTENT, $renamed ) )
		);
	}

	public function test_phone_only_change_does_not_split(): void {
		$base  = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryEastLegon() );
		$phone = $base->withRecipient( PerItemContextFixtures::recipient( 'Ama', '0555555555' ) );

		self::assertSame(
			CartLineCustomerIdentity::cartIdFromItem( PerItemContextFixtures::cartItem( self::INTENT, $base ) ),
			CartLineCustomerIdentity::cartIdFromItem( PerItemContextFixtures::cartItem( self::INTENT, $phone ) )
		);
	}

	public function test_whole_line_address_move_rekeys(): void {
		$east    = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryEastLegon() );
		$spintex = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliverySpintex() );
		$source  = PerItemContextFixtures::cartItem( self::INTENT, $east, 2, [ 'variation' => [ 'attribute_pa_colour' => 'oak' ], 'third_party_gift' => 'wrap' ] );
		$old_key = CartLineCustomerIdentity::cartIdFromItem( $source );

		$result = ( new CartCustomerContextMutationService() )->updateWholeLine( [ $old_key => $source ], $old_key, $spintex );

		self::assertTrue( $result->ok );
		self::assertArrayNotHasKey( $old_key, $result->contents );
		self::assertArrayHasKey( $result->target_key, $result->contents );
		self::assertSame( 2, $result->contents[ $result->target_key ]['quantity'] );
		self::assertSame( 'oak', $result->contents[ $result->target_key ]['variation']['attribute_pa_colour'] );
	}

	public function test_move_consolidates_into_existing_identical_target(): void {
		$east    = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryEastLegon() );
		$spintex = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliverySpintex() );
		$a       = PerItemContextFixtures::cartItem( self::INTENT, $east, 1 );
		$b       = PerItemContextFixtures::cartItem( self::INTENT, $spintex, 1 );
		$key_a   = CartLineCustomerIdentity::cartIdFromItem( $a );
		$key_b   = CartLineCustomerIdentity::cartIdFromItem( $b );

		$result = ( new CartCustomerContextMutationService() )->updateWholeLine(
			[ $key_a => $a, $key_b => $b ],
			$key_a,
			$spintex
		);

		self::assertTrue( $result->ok );
		self::assertCount( 1, $result->contents );
		self::assertSame( 2, $result->contents[ $key_b ]['quantity'] );
	}

	public function test_split_quantity_one_from_quantity_two_produces_two_lines(): void {
		$east    = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryEastLegon() );
		$spintex = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliverySpintex() );
		$source  = PerItemContextFixtures::cartItem( self::INTENT, $east, 2, [ 'variation' => [ 'attribute_pa_colour' => 'oak' ] ] );
		$key     = CartLineCustomerIdentity::cartIdFromItem( $source );

		$result = ( new CartCustomerContextMutationService() )->splitQuantity( [ $key => $source ], $key, 1, $spintex );

		self::assertTrue( $result->ok );
		self::assertCount( 2, $result->contents );
		self::assertSame( 1, $result->contents[ $result->source_key ]['quantity'] );
		self::assertSame( 1, $result->contents[ $result->target_key ]['quantity'] );
		self::assertSame( 'oak', $result->contents[ $result->source_key ]['variation']['attribute_pa_colour'] );
		self::assertSame( 'oak', $result->contents[ $result->target_key ]['variation']['attribute_pa_colour'] );
	}

	public function test_split_into_existing_identical_target_consolidates(): void {
		$east    = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryEastLegon() );
		$spintex = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliverySpintex() );
		$a       = PerItemContextFixtures::cartItem( self::INTENT, $east, 2 );
		$b       = PerItemContextFixtures::cartItem( self::INTENT, $spintex, 1 );
		$key_a   = CartLineCustomerIdentity::cartIdFromItem( $a );
		$key_b   = CartLineCustomerIdentity::cartIdFromItem( $b );

		$result = ( new CartCustomerContextMutationService() )->splitQuantity(
			[ $key_a => $a, $key_b => $b ],
			$key_a,
			1,
			$spintex
		);

		self::assertTrue( $result->ok );
		self::assertSame( 1, $result->contents[ $key_a ]['quantity'] );
		self::assertSame( 2, $result->contents[ $key_b ]['quantity'] );
	}

	public function test_invalid_split_quantity_is_rejected_atomically(): void {
		$east   = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryEastLegon() );
		$source = PerItemContextFixtures::cartItem( self::INTENT, $east, 2 );
		$key    = CartLineCustomerIdentity::cartIdFromItem( $source );
		$before = [ $key => $source ];

		$zero = ( new CartCustomerContextMutationService() )->splitQuantity( $before, $key, 0, $east );
		$over = ( new CartCustomerContextMutationService() )->splitQuantity( $before, $key, 3, $east );

		self::assertFalse( $zero->ok );
		self::assertSame( CartMutationResult::CODE_INVALID_QUANTITY, $zero->code );
		self::assertSame( $before, $zero->contents );
		self::assertFalse( $over->ok );
		self::assertSame( $before, $over->contents );
	}

	public function test_variation_and_third_party_data_are_preserved(): void {
		$east    = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryEastLegon() );
		$spintex = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliverySpintex() );
		$source  = PerItemContextFixtures::cartItem(
			self::INTENT,
			$east,
			2,
			[
				'variation'          => [ 'attribute_pa_colour' => 'oak', 'attribute_pa_size' => 'l' ],
				'third_party_gift'   => 'wrap',
				'ywapo_meta_data'    => [ 'addon' => 'yes' ],
			]
		);
		$key    = CartLineCustomerIdentity::cartIdFromItem( $source );
		$result = ( new CartCustomerContextMutationService() )->splitQuantity( [ $key => $source ], $key, 1, $spintex );

		self::assertTrue( $result->ok );
		$moved = $result->contents[ $result->target_key ];
		self::assertSame( 'oak', $moved['variation']['attribute_pa_colour'] );
		self::assertSame( 'l', $moved['variation']['attribute_pa_size'] );
		self::assertSame( 'wrap', $moved['third_party_gift'] );
		self::assertSame( [ 'addon' => 'yes' ], $moved['ywapo_meta_data'] );
	}

	public function test_admin_config_refresh_preserves_customer_context(): void {
		$ctx    = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryEastLegon() );
		$stored = PerItemContextFixtures::cartItem(
			self::INTENT,
			$ctx,
			1,
			[
				CartDeliverySelectionCapture::CART_SUMMARY_KEY => [ 'delivery_offer_public_label' => 'Old', 'estimate_text' => '2 days' ],
			]
		);
		$option = new ProductDeliveryOption(
			'in_warehouse:delivery:10',
			'in_warehouse',
			'in_warehouse',
			'delivery',
			'delivery',
			10,
			'Standard Plus',
			null,
			'3–4 days',
			true,
			null
		);
		$fresh = ProductDeliverySelectionIntent::fromValidatedOption( 101, 7, 'variation', 7, $option, 5, 'new-fingerprint' )->toArray();

		$outcome = $this->reconciler()->reconcile_line(
			'old-key',
			$stored,
			[ $option ],
			'required',
			fn () => ProductDeliverySelectionValidationResult::valid( $option, ProductDeliverySelectionIntent::fromArray( $fresh ) )
		);

		self::assertSame( CartReconciliationOutcome::ACTION_REFRESHED, $outcome->action );
		self::assertSame(
			$ctx->toArray(),
			$outcome->cart_item[ CustomerCartContext::CART_KEY ]
		);
		self::assertSame( CartLineCustomerIdentity::cartIdFromItem( $stored ), CartLineCustomerIdentity::cartIdFromItem( $outcome->cart_item ) );
	}

	public function test_removed_offer_preserves_destination_and_requires_reselection(): void {
		$ctx    = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryEastLegon() );
		$stored = PerItemContextFixtures::cartItem( self::INTENT, $ctx );
		$other  = new ProductDeliveryOption(
			'in_warehouse:delivery:20',
			'in_warehouse',
			'in_warehouse',
			'delivery',
			'delivery',
			20,
			'Option B',
			null,
			'1 day',
			true,
			null
		);

		$outcome = $this->reconciler()->reconcile_line(
			'line-a',
			$stored,
			[ $other ],
			'required',
			fn () => ProductDeliverySelectionValidationResult::invalid( 'option_not_found', 'missing' )
		);

		self::assertTrue( $outcome->needsCustomerReselection() );
		self::assertSame( $ctx->toArray(), $outcome->cart_item[ CustomerCartContext::CART_KEY ] );
		self::assertSame( '12 Boundary Rd', $outcome->cart_item[ CustomerCartContext::CART_KEY ]['delivery_address']['address_1'] );
	}

	public function test_unserviceable_destination_is_not_replaced_with_checkout_address(): void {
		$ctx    = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryEastLegon() );
		$stored = PerItemContextFixtures::cartItem( self::INTENT, $ctx );

		$outcome = $this->reconciler()->reconcile_line(
			'line-a',
			$stored,
			[],
			'blocked',
			fn () => ProductDeliverySelectionValidationResult::invalid( 'destination_unserviceable', 'blocked' )
		);

		self::assertTrue( $outcome->needsCustomerReselection() );
		self::assertSame( $ctx->toArray(), $outcome->cart_item[ CustomerCartContext::CART_KEY ] );
		self::assertArrayNotHasKey( 'checkout_address', $outcome->cart_item );
		self::assertSame( 'East Legon', $outcome->cart_item[ CustomerCartContext::CART_KEY ]['matching_location']['city'] );
	}

	public function test_session_restore_preserves_customer_context(): void {
		$ctx  = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryEastLegon() );
		$item = PerItemContextFixtures::cartItem( self::INTENT, $ctx, 1, [ 'third_party_gift' => 'wrap' ] );
		$json = json_encode( $item );
		self::assertIsString( $json );
		$restored = json_decode( $json, true );
		self::assertIsArray( $restored );
		$parsed = CustomerCartContext::fromCartItem( $restored );

		self::assertNotNull( $parsed );
		self::assertTrue( $ctx->equals( $parsed ) );
		self::assertSame( CartLineCustomerIdentity::cartIdFromItem( $item ), CartLineCustomerIdentity::cartIdFromItem( $restored ) );
		self::assertStringNotContainsString( 'Boundary', CartLineCustomerIdentity::cartIdFromItem( $restored ) );
	}

	private function reconciler(): CartDeliverySelectionReconciler {
		$capture = ( new ReflectionClass( CartDeliverySelectionCapture::class ) )->newInstanceWithoutConstructor();

		return new CartDeliverySelectionReconciler(
			new FeatureFlags(),
			new Requirements(),
			$capture,
			( new ReflectionClass( \CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidator::class ) )->newInstanceWithoutConstructor()
		);
	}
}
