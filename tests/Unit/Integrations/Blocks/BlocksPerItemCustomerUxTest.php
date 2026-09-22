<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Integrations\Blocks;

use CetechDeliveryEngine\Application\Cart\CartCustomerContextMutationService;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartLineCustomerIdentity;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Integrations\Blocks\BlocksAddToCartBridge;
use CetechDeliveryEngine\Integrations\Blocks\BlocksCheckoutAdapter;
use CetechDeliveryEngine\Integrations\Blocks\BlocksPublicPayload;
use CetechDeliveryEngine\Presentation\Shared\CartDeliveryUiAnchor;
use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;
use CetechDeliveryEngine\Tests\Unit\CustomerContext\PerItemContextFixtures;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class BlocksPerItemCustomerUxTest extends TestCase {

	protected function tearDown(): void {
		unset(
			$GLOBALS['cetech_de_blocks_delivery_option_key'],
			$GLOBALS['cetech_de_blocks_delivery_variation_id'],
			$GLOBALS['cetech_de_blocks_matching_location']
		);
		parent::tearDown();
	}

	public function test_blocks_add_to_cart_bridge_reads_matching_location_before_identity(): void {
		$capture = ( new ReflectionClass( CartDeliverySelectionCapture::class ) )->newInstanceWithoutConstructor();
		$bridge  = new BlocksAddToCartBridge( $capture );
		$bridge->prime_cart_item_data(
			[],
			[
				'extensions' => [
					BlocksCheckoutAdapter::NAMESPACE => [
						'delivery_option_key' => 'in_warehouse:delivery:1',
						'matching_location'    => [
							'country'                 => 'GH',
							'state'                   => 'AA',
							'city'                    => 'Accra',
							'postcode'                => 'GA-123',
							'canonical_location_key'  => 'loc-accra',
						],
					],
				],
			]
		);

		$matching = $bridge->filter_submitted_matching_location( null );
		self::assertInstanceOf( \CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation::class, $matching );
		self::assertSame( 'Accra', $matching->city );
		self::assertSame( 'loc-accra', $matching->canonical_location_key );
		self::assertSame( 'in_warehouse:delivery:1', $bridge->filter_submitted_option_key( '' ) );
	}

	public function test_blocks_add_to_cart_bridge_reads_pdp_context_payload(): void {
		$capture = ( new ReflectionClass( CartDeliverySelectionCapture::class ) )->newInstanceWithoutConstructor();
		$bridge  = new BlocksAddToCartBridge( $capture );
		$bridge->prime_cart_item_data(
			[],
			[
				'extensions' => [
					BlocksCheckoutAdapter::NAMESPACE => [
						'pdp_context' => [
							'display_key'        => 'in_warehouse:delivery:1',
							'matching_location' => [
								'country'  => 'GH',
								'state'    => 'AH',
								'city'     => 'Kumasi',
								'postcode' => 'AK-000',
							],
						],
					],
				],
			]
		);

		$matching = $bridge->filter_submitted_matching_location( null );
		self::assertInstanceOf( \CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation::class, $matching );
		self::assertSame( 'Kumasi', $matching->city );
		self::assertSame( 'in_warehouse:delivery:1', $bridge->filter_submitted_option_key( '' ) );
	}

	public function test_accra_and_kumasi_blocks_contexts_create_distinct_cart_ids(): void {
		$intent = $this->intent();
		$accra  = PerItemContextFixtures::incompleteContext( 1, PerItemContextFixtures::matchingAccra() );
		$kumasi = PerItemContextFixtures::incompleteContext( 1, PerItemContextFixtures::matchingKumasi() );

		$without = CartLineCustomerIdentity::generateCartId( 16, 0, [], [ CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent ] );
		$accra_id = CartLineCustomerIdentity::generateCartId( 16, 0, [], $accra->applyToCartItem( [ CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent ] ) );
		$kumasi_id = CartLineCustomerIdentity::generateCartId( 16, 0, [], $kumasi->applyToCartItem( [ CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent ] ) );

		self::assertNotSame( $without, $accra_id );
		self::assertNotSame( $accra_id, $kumasi_id );
		self::assertStringNotContainsString( 'Accra', $accra_id );
		self::assertStringNotContainsString( 'Kumasi', $kumasi_id );
	}

	public function test_same_blocks_context_consolidates(): void {
		$ctx = PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' ) );
		$a   = PerItemContextFixtures::cartItem( $this->intent(), $ctx, 1 );
		$b   = PerItemContextFixtures::cartItem( $this->intent(), $ctx, 1 );

		self::assertSame( CartLineCustomerIdentity::cartIdFromItem( $a ), CartLineCustomerIdentity::cartIdFromItem( $b ) );
	}

	public function test_pickup_contexts_with_different_ids_are_distinct(): void {
		$a = CustomerCartContext::pickup( 1 )->applyToCartItem( [ CartDeliverySelectionCapture::CART_SELECTION_KEY => $this->pickup_intent( 1 ) ] );
		$b = CustomerCartContext::pickup( 2 )->applyToCartItem( [ CartDeliverySelectionCapture::CART_SELECTION_KEY => $this->pickup_intent( 2 ) ] );

		self::assertNotSame( CartLineCustomerIdentity::cartIdFromItem( $a ), CartLineCustomerIdentity::cartIdFromItem( $b ) );
	}

	public function test_variable_and_location_create_distinct_cart_ids(): void {
		$intent = $this->intent();
		$oak    = PerItemContextFixtures::incompleteContext( 1, PerItemContextFixtures::matchingAccra() );
		$walnut = PerItemContextFixtures::incompleteContext( 1, PerItemContextFixtures::matchingKumasi() );

		$oak_id = CartLineCustomerIdentity::generateCartId(
			20,
			101,
			[ 'attribute_pa_finish' => 'oak' ],
			$oak->applyToCartItem( [ CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent ] )
		);
		$walnut_id = CartLineCustomerIdentity::generateCartId(
			20,
			102,
			[ 'attribute_pa_finish' => 'walnut' ],
			$walnut->applyToCartItem( [ CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent ] )
		);

		self::assertNotSame( $oak_id, $walnut_id );
	}

	public function test_pickup_public_payload_is_customer_safe(): void {
		$item = CustomerCartContext::pickup( 4 )->applyToCartItem(
			[
				CartDeliverySelectionCapture::CART_SELECTION_KEY => $this->pickup_intent( 4 ),
				CartDeliverySelectionCapture::CART_SUMMARY_KEY => [
					'pickup_location_label' => 'QA Accra Pickup',
					'pickup_address'        => '12 Harbour Street',
					'pickup_instructions'    => 'Bring ID',
				],
				'key' => 'pickupkey',
			]
		);

		$payload = BlocksPublicPayload::cart_item( $item, null, null, 'pickupkey' );

		self::assertTrue( $payload['is_pickup'] );
		self::assertTrue( $payload['has_customer_context'] );
		self::assertSame( 'QA Accra Pickup', $payload['pickup_location_label'] );
		self::assertArrayNotHasKey( 'pickup_location_id', $payload );
		self::assertArrayNotHasKey( 'delivery_offer_id', $payload );
		self::assertFalse( BlocksPublicPayload::contains_forbidden( $payload ) );
	}

	public function test_public_payload_exposes_customer_context_without_internals(): void {
		$ctx  = PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' ) );
		$item = PerItemContextFixtures::cartItem( $this->intent(), $ctx, 2 );
		$item['key'] = 'abc123';
		$item['data'] = new class() {
			public function get_name(): string {
				return 'QA Warehouse Chair';
			}
		};

		$payload = BlocksPublicPayload::cart_item( $item, null, null, 'abc123' );

		self::assertTrue( $payload['has_customer_context'] );
		self::assertTrue( $payload['address_complete'] );
		self::assertTrue( $payload['can_edit_context'] );
		self::assertTrue( $payload['can_split'] );
		self::assertSame( 'Accra', $payload['locality'] );
		self::assertSame( 'Accra', $payload['matching_location']['city'] ?? null );
		self::assertSame( '12 Boundary Rd', $payload['delivery_address']['address_1'] ?? null );
		self::assertSame( 'CETECH', $payload['delivery_address']['company'] ?? null );
		self::assertSame( CartDeliveryUiAnchor::for_cart_item_key( 'abc123' ), $payload['ui_anchor'] );
		self::assertFalse( $payload['address_needed'] );
		self::assertSame( CustomerStorefrontCopy::edit_delivery_details(), $payload['address_action_label'] );
		self::assertArrayNotHasKey( 'matching_identity', $payload );
		self::assertArrayNotHasKey( 'delivery_location_identity', $payload );
		self::assertArrayNotHasKey( 'delivery_offer_id', $payload );
		self::assertFalse( BlocksPublicPayload::contains_forbidden( $payload ) );
		self::assertStringNotContainsString( '12 Boundary Rd', (string) ( $payload['cart_item_key'] ?? '' ) );
	}

	public function test_incomplete_payload_exposes_customer_safe_anchor_and_address_needed(): void {
		$ctx  = PerItemContextFixtures::incompleteContext( 1, PerItemContextFixtures::matchingAccra() );
		$item = PerItemContextFixtures::cartItem( $this->intent(), $ctx, 1 );
		$payload = BlocksPublicPayload::cart_item( $item, null, null, 'incomplete-key' );

		self::assertTrue( $payload['address_needed'] );
		self::assertFalse( $payload['address_complete'] );
		self::assertSame( CustomerStorefrontCopy::add_delivery_address(), $payload['address_action_label'] );
		self::assertSame( CartDeliveryUiAnchor::for_cart_item_key( 'incomplete-key' ), $payload['ui_anchor'] );
		self::assertTrue( CartDeliveryUiAnchor::is_valid( (string) $payload['ui_anchor'] ) );
		self::assertStringNotContainsString( 'Accra', (string) $payload['ui_anchor'] );
		self::assertFalse( BlocksPublicPayload::contains_forbidden( $payload ) );
	}

	public function test_top_level_first_incomplete_anchor_cannot_point_at_reselection_item(): void {
		$policy   = ( new ReflectionClass( \CetechDeliveryEngine\Application\Checkout\CheckoutAddressPolicy::class ) )->newInstanceWithoutConstructor();
		$reselect = PerItemContextFixtures::cartItem(
			$this->intent(),
			PerItemContextFixtures::incompleteContext( 1, PerItemContextFixtures::matchingGhanaCountry() ),
			1,
			[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY => true ]
		);
		$editable = PerItemContextFixtures::cartItem(
			$this->intent(),
			PerItemContextFixtures::incompleteContext( 1, PerItemContextFixtures::matchingAccra() )
		);
		$summary = $policy->summarize_cart(
			[
				'reselect' => $reselect,
				'accra'    => $editable,
			]
		);
		$reselect_payload = BlocksPublicPayload::cart_item( $reselect, null, null, 'reselect' );
		$editable_payload = BlocksPublicPayload::cart_item( $editable, null, null, 'accra' );

		self::assertNull( $reselect_payload['ui_anchor'] );
		self::assertFalse( $reselect_payload['can_edit_context'] );
		self::assertSame( CartDeliveryUiAnchor::for_cart_item_key( 'accra' ), $summary['first_incomplete_anchor'] );
		self::assertSame( $summary['first_incomplete_anchor'], $editable_payload['ui_anchor'] );
		self::assertNotSame( $summary['first_incomplete_anchor'], $reselect_payload['ui_anchor'] );
	}

	public function test_quantity_split_uses_mutation_service(): void {
		$accra  = PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryAccraStreet( '1 Independence Avenue' ) );
		$street = PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryAccraStreet( '99 Ring Road' ) );
		$item   = PerItemContextFixtures::cartItem( $this->intent(), $accra, 2 );
		$key    = CartLineCustomerIdentity::cartIdFromItem( $item );
		$item['key'] = $key;

		$result = ( new CartCustomerContextMutationService() )->splitQuantity( [ $key => $item ], $key, 1, $street );

		self::assertTrue( $result->ok );
		$qtys = array_map( static fn ( array $row ): int => (int) ( $row['quantity'] ?? 0 ), $result->contents );
		self::assertCount( 2, $result->contents );
		self::assertSame( [ 1, 1 ], array_values( $qtys ) );
	}

	public function test_whole_line_update_rekeys_to_new_destination(): void {
		$accra  = PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryAccraStreet( '1 Independence Avenue' ) );
		$kumasi = PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryKumasi( '10 Prempeh II Street' ) );
		$item   = PerItemContextFixtures::cartItem( $this->intent(), $accra, 1 );
		$key    = CartLineCustomerIdentity::cartIdFromItem( $item );
		$item['key'] = $key;

		$result = ( new CartCustomerContextMutationService() )->updateWholeLine( [ $key => $item ], $key, $kumasi );

		self::assertTrue( $result->ok );
		self::assertCount( 1, $result->contents );
		$moved = $result->contents[ $result->target_key ];
		$ctx   = CustomerCartContext::fromCartItem( $moved );
		self::assertSame( 'Kumasi', $ctx->matching_location->city ?? null );
	}

	public function test_delivery_and_pickup_remain_distinct_lines(): void {
		$delivery = PerItemContextFixtures::cartItem(
			$this->intent(),
			PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryAccraStreet( '1 Independence Avenue' ) )
		);
		$pickup = CustomerCartContext::pickup( 4 )->applyToCartItem(
			[ CartDeliverySelectionCapture::CART_SELECTION_KEY => $this->pickup_intent( 4 ) ]
		);

		self::assertNotSame(
			CartLineCustomerIdentity::cartIdFromItem( $delivery ),
			CartLineCustomerIdentity::cartIdFromItem( $pickup )
		);
	}

	public function test_delivery_package_heading_uses_locality_not_street(): void {
		$package = [
			\CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity::PACKAGE_META_KEY => [
				'managed'        => true,
				'is_pickup'      => false,
				'locality_label' => 'Accra',
				'group_id'       => 'in_warehouse|delivery|1|dabc123',
			],
		];

		$payload = BlocksPublicPayload::package( $package, 0 );

		self::assertSame( 'Delivery to Accra', $payload['heading'] );
		self::assertStringNotContainsString( 'Independence', (string) $payload['heading'] );
		self::assertArrayNotHasKey( 'group_id', $payload );
		self::assertFalse( BlocksPublicPayload::contains_forbidden( $payload ) );
	}

	public function test_blocks_customer_update_does_not_mutate_complete_context(): void {
		$validation = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Integrations/Blocks/BlocksCheckoutValidation.php' );

		self::assertStringContainsString( 'after_customer_update', $validation );
		self::assertStringContainsString( 'must NOT mutate complete per-item CustomerCartContext', $validation );
		self::assertStringNotContainsString( 'CartCustomerContextMutationService', $validation );
		self::assertStringNotContainsString( 'applyCheckoutAddressToIncomplete', $validation );
	}

	public function test_store_api_update_dispatches_named_commands(): void {
		$reselection = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Application/Cart/CartDeliveryReselectionService.php' );
		$handler     = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Integrations/Blocks/BlocksCartContextCommandHandler.php' );

		self::assertStringContainsString( 'ACTION_SET_ITEM', $reselection );
		self::assertStringContainsString( 'ACTION_SPLIT', $reselection );
		self::assertStringContainsString( 'ACTION_USE_FOR_ALL', $reselection );
		self::assertStringContainsString( 'ACTION_APPLY_CHECKOUT_ADDRESS', $reselection );
		self::assertStringContainsString( 'ACTION_RESELECT', $reselection );
		self::assertStringContainsString( 'updateWholeLine', $handler );
		self::assertStringContainsString( 'splitQuantity', $handler );
		self::assertStringContainsString( 'applyDeliveryLocation', $handler );
		self::assertStringContainsString( 'applyCheckoutAddressToIncomplete', $handler );
		self::assertStringContainsString( "[] !== ( \$outcome['updated'] ?? [] )", $handler );
		self::assertStringNotContainsString( 'cart_contents =', $handler );
	}

	public function test_blocks_customer_copy_and_dom_editor_do_not_duplicate_react_editors(): void {
		$i18n = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Integrations/Blocks/BlocksScriptIntegration.php' );
		$js   = (string) file_get_contents( dirname( __DIR__, 4 ) . '/assets/frontend/blocks-checkout.js' );

		self::assertStringContainsString( 'address_line_1', $i18n );
		self::assertStringContainsString( 'address_line_2_optional', $i18n );
		self::assertStringContainsString( 'Quantity to move', $i18n );
		self::assertStringContainsString( 'use_my_checkout_address', $i18n );
		self::assertStringContainsString( 'your_deliveries', $i18n );
		self::assertStringContainsString( 'add_delivery_address', $i18n );
		self::assertStringContainsString( 'edit_delivery_details', $i18n );
		self::assertStringNotContainsString( 'Fulfilment and delivery option', $i18n );
		self::assertStringNotContainsString( 'per-destination tax', $i18n );
		self::assertStringNotContainsString( 'per destination tax', $i18n );
		self::assertStringContainsString( 'cetech-de-b-', $js );
		self::assertStringContainsString( 'lastDomUiSignature', $js );
		self::assertStringContainsString( 'scheduleApply', $js );
		self::assertStringNotContainsString( "registerPlugin('cetech-de-blocks-context-", $js );
		self::assertStringNotContainsString( 'per-destination tax', $js );
	}

	public function test_schema_target_remains_five(): void {
		self::assertSame( '6', \CetechDeliveryEngine\Core\Versioning\SchemaVersion::TARGET );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function intent(): array {
		return [
			'contract_version'         => '1',
			'product_id'              => 16,
			'variation_id'            => 0,
			'target_type'              => 'product',
			'target_id'                => 16,
			'display_key'              => 'in_warehouse:delivery:1',
			'fulfilment_availability'  => 'in_warehouse',
			'fulfilment_choice'        => 'delivery',
			'delivery_offer_id'       => 1,
			'rule_id'                 => 1,
			'issued_at'               => '2026-09-02T00:00:00+00:00',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function pickup_intent( int $pickup_id ): array {
		return [
			'contract_version'         => '1',
			'product_id'              => 15,
			'variation_id'            => 0,
			'target_type'              => 'product',
			'target_id'                => 15,
			'display_key'              => 'in_store:store_pickup:pickup',
			'fulfilment_availability'  => 'in_store',
			'fulfilment_choice'        => 'store_pickup',
			'delivery_offer_id'       => null,
			'pickup_location_id'     => $pickup_id,
			'rule_id'                 => 1,
			'issued_at'               => '2026-09-02T00:00:00+00:00',
		];
	}
}
