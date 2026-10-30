<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Cart;

use CetechDeliveryEngine\Application\Cart\CartDeliveryReselectionService;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionEquivalence;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionFingerprint;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionReconciler;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidationResult;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Application\Cart\CartLineCustomerIdentity;
use CetechDeliveryEngine\Application\Cart\CartReconciliationOutcome;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionIntent;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidationResult;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * COR-024: a Store Pickup choice is bound to its Pickup Location; a replaced or removed location
 * requires explicit reselection, an unchanged one may refresh its label and readiness.
 * COR-025: an explicit reselection updates intent and customer context in one coherent transition.
 */
final class PickupEndpointReselectionTest extends TestCase {

	private const PRODUCT_ID = 101;

	// ------------------------------------------------------------------ COR-024

	public function test_sole_location_replaced_by_another_requires_reselection_even_under_the_same_key(): void {
		$old_location = $this->pickup_option( 'in_store:store_pickup:pickup', 1, 'Accra showroom', 'Ready in 2 hours' );
		$new_location = $this->pickup_option( 'in_store:store_pickup:pickup', 2, 'Tema depot', 'Ready tomorrow' );
		$line         = $this->pickup_line( $old_location );

		$outcome = $this->reconcile( $line, [ $new_location ], $new_location );

		self::assertSame( CartReconciliationOutcome::ACTION_NEEDS_RESELECTION, $outcome->action );
		self::assertSame( CartDeliverySelectionRevalidationResult::STATUS_NEEDS_RESELECTION, $outcome->status );
		self::assertTrue( (bool) ( $outcome->cart_item[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] ?? false ) );
		self::assertArrayNotHasKey( CartDeliverySelectionCapture::CART_SUMMARY_KEY, $outcome->cart_item, 'the misleading old summary is hidden' );
		self::assertSame( 1, CustomerCartContext::fromCartItem( $outcome->cart_item )?->pickup_location_id, 'the customer-owned endpoint is retained, not overwritten' );
		self::assertSame( 1, $outcome->cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]['pickup_location_id'] ?? null );
	}

	public function test_removed_location_among_several_is_not_replaced_by_the_newly_sole_one(): void {
		$kept    = $this->pickup_option( 'in_store:store_pickup:p2', 2, 'Tema depot', 'Ready tomorrow' );
		$removed = $this->pickup_option( 'in_store:store_pickup:p1', 1, 'Accra showroom', 'Ready in 2 hours' );
		$line    = $this->pickup_line( $removed );

		// After the removal the remaining location is emitted under the sole-pickup key.
		$sole = $this->pickup_option( 'in_store:store_pickup:pickup', 2, 'Tema depot', 'Ready tomorrow' );

		self::assertNull( CartDeliverySelectionEquivalence::findEquivalentOption( [ $sole ], $line[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ) );
		self::assertNull( CartDeliverySelectionEquivalence::findEquivalentOption( [ $kept ], $line[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ) );

		$outcome = $this->reconcile( $line, [ $sole ], $sole );
		self::assertSame( CartReconciliationOutcome::ACTION_NEEDS_RESELECTION, $outcome->action );
		self::assertSame( 1, CustomerCartContext::fromCartItem( $outcome->cart_item )?->pickup_location_id );
	}

	public function test_unchanged_location_refreshes_label_and_readiness_even_when_its_key_changes(): void {
		$stored = $this->pickup_option( 'in_store:store_pickup:p2', 2, 'Tema depot', 'Ready tomorrow' );
		$line   = $this->pickup_line( $stored );

		// Same endpoint, edited label and readiness, now sole (its key changed from p2 to pickup).
		$live = $this->pickup_option( 'in_store:store_pickup:pickup', 2, 'Tema Depot (Community 1)', 'Ready in 4 hours' );

		$outcome = $this->reconcile( $line, [ $live ], $live );

		self::assertSame( CartReconciliationOutcome::ACTION_REFRESHED, $outcome->action );
		self::assertArrayNotHasKey( CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY, $outcome->cart_item );
		self::assertSame( 'Tema Depot (Community 1)', $outcome->cart_item[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ]['pickup_location_label'] );
		self::assertSame( 'Ready in 4 hours', $outcome->cart_item[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ]['estimate_text'] );
		self::assertSame( 'in_store:store_pickup:pickup', $outcome->cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]['display_key'] );
		self::assertSame( 2, $outcome->cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]['pickup_location_id'] );
		self::assertSame( 2, CustomerCartContext::fromCartItem( $outcome->cart_item )?->pickup_location_id );
	}

	public function test_legacy_intent_without_endpoint_id_uses_the_committed_context_endpoint(): void {
		$old_location = $this->pickup_option( 'in_store:store_pickup:pickup', 1, 'Accra showroom', 'Ready in 2 hours' );
		$line         = $this->pickup_line( $old_location );
		// Pre-COR-024 intent: no pickup_location_id, but the add-to-cart context committed endpoint 1.
		unset( $line[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]['pickup_location_id'] );
		$line[ CartDeliverySelectionCapture::CART_HASH_KEY ] = CartDeliverySelectionFingerprint::fromIntent( $line[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] );

		$replacement = $this->pickup_option( 'in_store:store_pickup:pickup', 2, 'Tema depot', 'Ready tomorrow' );
		$outcome     = $this->reconcile( $line, [ $replacement ], $replacement );
		self::assertSame( CartReconciliationOutcome::ACTION_NEEDS_RESELECTION, $outcome->action, 'the committed context endpoint still binds a legacy intent' );

		$same    = $this->pickup_option( 'in_store:store_pickup:pickup', 1, 'Accra Showroom', 'Ready in 3 hours' );
		$outcome = $this->reconcile( $line, [ $same ], $same );
		self::assertSame( CartReconciliationOutcome::ACTION_REFRESHED, $outcome->action );
		self::assertSame( 1, $outcome->cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]['pickup_location_id'], 'the refreshed intent now carries the endpoint' );
	}

	public function test_fingerprint_distinguishes_endpoints_but_is_unchanged_for_legacy_intents(): void {
		$at_one = $this->intent_for( $this->pickup_option( 'in_store:store_pickup:pickup', 1, 'Accra showroom', null ) );
		$at_two = $this->intent_for( $this->pickup_option( 'in_store:store_pickup:pickup', 2, 'Tema depot', null ) );
		self::assertNotSame( CartDeliverySelectionFingerprint::fromIntent( $at_one ), CartDeliverySelectionFingerprint::fromIntent( $at_two ) );

		$legacy = $at_one;
		unset( $legacy['pickup_location_id'] );
		$without_key = $legacy;
		self::assertSame( CartDeliverySelectionFingerprint::fromIntent( $legacy ), CartDeliverySelectionFingerprint::fromIntent( $without_key ) );
		self::assertSame( 1, CartDeliverySelectionSessionData::normalizeIntent( $at_one )['pickup_location_id'] ?? null, 'the session shape keeps the endpoint' );
	}

	// ------------------------------------------------------------------ COR-025

	public function test_delivery_offer_reselection_commits_the_offer_and_keeps_destination_and_address(): void {
		$line = $this->delivery_line( 10, true );
		$new  = $this->delivery_option( 'in_warehouse:delivery:20', 20, 'Express', '1 day' );

		$updated = CartDeliveryReselectionService::reselect_line( $line, $this->intent_for( $new ), $new->toArray() );
		$context = CustomerCartContext::fromCartItem( $updated );

		self::assertSame( 20, $updated[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]['delivery_offer_id'] );
		self::assertSame( 20, $context?->delivery_offer_id, 'the committed context names the new offer' );
		self::assertSame( 'Accra', $context?->matching_location?->city );
		self::assertSame( '12 Boundary Rd', $context?->delivery_address?->address_1, 'applicable customer fields are preserved' );
		self::assertTrue( $context?->hasCompleteDeliveryAddress() ?? false );
		self::assertSame( 'Express', $updated[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ]['delivery_offer_public_label'] );
		self::assertStringContainsString( '|20|', CartLineCustomerIdentity::identityString( $updated[ CartDeliverySelectionCapture::CART_SELECTION_KEY ], $updated ) );
		self::assertStringContainsString( '|delivery|20|', (string) DeliveryGroupIdentity::fromCartItem( $updated ), 'grouping and quoting read the same offer' );
		self::assertSame( '2026-08-01T00:00:00+00:00', $updated[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]['issued_at'], 'customer-owned fields overlay' );
	}

	public function test_delivery_to_pickup_reselection_commits_the_endpoint_and_drops_the_delivery_destination(): void {
		$line   = $this->delivery_line( 10, true );
		$pickup = $this->pickup_option( 'in_warehouse:store_pickup:pickup', 4, 'Accra showroom', 'Ready in 2 hours' );

		$updated = CartDeliveryReselectionService::reselect_line( $line, $this->intent_for( $pickup ), $pickup->toArray() );
		$context = CustomerCartContext::fromCartItem( $updated );

		self::assertSame( 'store_pickup', $updated[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]['fulfilment_choice'] );
		self::assertSame( 4, $updated[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]['pickup_location_id'] );
		self::assertTrue( $context?->isPickupComplete() ?? false );
		self::assertSame( 4, $context?->pickup_location_id );
		self::assertNull( $context?->matching_location );
		self::assertStringStartsWith( 'store_pickup|p4|', CartLineCustomerIdentity::identityString( $updated[ CartDeliverySelectionCapture::CART_SELECTION_KEY ], $updated ) );
		self::assertStringContainsString( '|store_pickup|p4|', (string) DeliveryGroupIdentity::fromCartItem( $updated ) );
		self::assertSame( 'Accra showroom', $updated[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ]['pickup_location_label'] );
		self::assertArrayNotHasKey( CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY, $updated );
	}

	public function test_pickup_to_other_pickup_reselection_moves_the_endpoint_in_one_step(): void {
		$line  = $this->pickup_line( $this->pickup_option( 'in_store:store_pickup:p1', 1, 'Accra showroom', null ) );
		$other = $this->pickup_option( 'in_store:store_pickup:p2', 2, 'Tema depot', 'Ready tomorrow' );

		$updated = CartDeliveryReselectionService::reselect_line( $line, $this->intent_for( $other ), $other->toArray() );

		self::assertSame( 2, $updated[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]['pickup_location_id'] );
		self::assertSame( 2, CustomerCartContext::fromCartItem( $updated )?->pickup_location_id );
		self::assertStringStartsWith( 'store_pickup|p2|', CartLineCustomerIdentity::identityString( $updated[ CartDeliverySelectionCapture::CART_SELECTION_KEY ], $updated ) );
		self::assertNotSame( CartLineCustomerIdentity::cartIdFromItem( $line ), CartLineCustomerIdentity::cartIdFromItem( $updated ), 'a different endpoint is a different cart line' );
	}

	public function test_pickup_to_delivery_reselection_does_not_borrow_a_browsing_location(): void {
		$line     = $this->pickup_line( $this->pickup_option( 'in_store:store_pickup:pickup', 1, 'Accra showroom', null ) );
		$delivery = $this->delivery_option( 'in_store:delivery:10', 10, 'Standard', '2 days' );

		$updated = CartDeliveryReselectionService::reselect_line( $line, $this->intent_for( $delivery ), $delivery->toArray() );
		$context = CustomerCartContext::fromCartItem( $updated );

		self::assertTrue( $context?->isDelivery() ?? false );
		self::assertSame( 10, $context?->delivery_offer_id );
		self::assertFalse( $context?->hasMatchingLocation() ?? true, 'the line waits for an explicit destination; no advisory location is written' );
		self::assertArrayNotHasKey( 'pickup_location_id', $updated[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] );
	}

	public function test_context_move_aligns_the_stored_intent_before_reconciliation(): void {
		$line    = $this->delivery_line( 10, true );
		$intent  = $line[ CartDeliverySelectionCapture::CART_SELECTION_KEY ];
		$to_offer = CustomerCartContext::delivery( 20, PerItemContextLocations::accra(), null );
		$aligned = CartLineCustomerIdentity::alignIntentWithContext( $intent, $to_offer );
		self::assertSame( 20, $aligned['delivery_offer_id'] );
		self::assertSame( 'in_warehouse:delivery:20', $aligned['display_key'], 'the stale key no longer points at the previous offer' );

		$to_pickup = CustomerCartContext::pickup( 7 );
		$aligned   = CartLineCustomerIdentity::alignIntentWithContext( $intent, $to_pickup );
		self::assertSame( 'store_pickup', $aligned['fulfilment_choice'] );
		self::assertNull( $aligned['delivery_offer_id'] );
		self::assertSame( 7, $aligned['pickup_location_id'] );
		self::assertSame( 'in_warehouse:store_pickup:p7', $aligned['display_key'] );

		// The aligned pickup intent resolves to the live endpoint whatever key the builder emits for it.
		$live = $this->pickup_option( 'in_warehouse:store_pickup:pickup', 7, 'Kumasi shop', null );
		self::assertSame( $live, CartDeliverySelectionEquivalence::findEquivalentOption( [ $live ], $aligned ) );
	}

	// ------------------------------------------------------------------ helpers

	/**
	 * @param list<ProductDeliveryOption> $live
	 */
	private function reconcile( array $line, array $live, ProductDeliveryOption $validated ): CartReconciliationOutcome {
		return $this->reconciler()->reconcile_line(
			'line',
			$line,
			$live,
			'required',
			fn (): ProductDeliverySelectionValidationResult => ProductDeliverySelectionValidationResult::valid(
				$validated,
				ProductDeliverySelectionIntent::fromArray( $this->intent_for( $validated ) )
			)
		);
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

	/**
	 * @return array<string, mixed>
	 */
	private function intent_for( ProductDeliveryOption $option ): array {
		$intent = ProductDeliverySelectionIntent::fromValidatedOption( self::PRODUCT_ID, null, 'product', self::PRODUCT_ID, $option, 5, 'fp' )->toArray();
		$intent['issued_at'] = '2026-08-01T00:00:00+00:00';

		return $intent;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function pickup_line( ProductDeliveryOption $option ): array {
		$intent = CartDeliverySelectionSessionData::normalizeIntent( $this->intent_for( $option ) );
		self::assertNotNull( $intent );
		$item = [
			'key'          => 'line',
			'product_id'   => self::PRODUCT_ID,
			'variation_id' => 0,
			'quantity'     => 1,
			'variation'    => [],
			CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent,
			CartDeliverySelectionCapture::CART_SUMMARY_KEY   => CartDeliverySelectionCapture::buildPublicSummary( $option->toArray() ),
			CartDeliverySelectionCapture::CART_HASH_KEY      => CartDeliverySelectionFingerprint::fromIntent( $intent ),
		];

		return CustomerCartContext::pickup( $option->pickup_location_id )->applyToCartItem( $item );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function delivery_line( int $offer_id, bool $with_address ): array {
		$option = $this->delivery_option( 'in_warehouse:delivery:' . $offer_id, $offer_id, 'Standard', '2 days' );
		$intent = CartDeliverySelectionSessionData::normalizeIntent( $this->intent_for( $option ) );
		self::assertNotNull( $intent );
		$item = [
			'key'          => 'line',
			'product_id'   => self::PRODUCT_ID,
			'variation_id' => 0,
			'quantity'     => 1,
			'variation'    => [],
			CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent,
			CartDeliverySelectionCapture::CART_SUMMARY_KEY   => CartDeliverySelectionCapture::buildPublicSummary( $option->toArray() ),
			CartDeliverySelectionCapture::CART_HASH_KEY      => CartDeliverySelectionFingerprint::fromIntent( $intent ),
		];
		$address = $with_address ? PerItemContextLocations::accra_address() : null;

		return CustomerCartContext::delivery( $offer_id, PerItemContextLocations::accra(), $address )->applyToCartItem( $item );
	}

	private function pickup_option( string $display_key, int $location_id, string $label, ?string $readiness ): ProductDeliveryOption {
		return new ProductDeliveryOption(
			$display_key,
			explode( ':', $display_key )[0],
			'Store',
			'store_pickup',
			'Store pickup',
			null,
			$label,
			null,
			$readiness,
			true,
			null,
			ProductDeliveryOption::CONTRACT_VERSION,
			false,
			$label,
			'1 Independence Ave',
			null,
			$location_id
		);
	}

	private function delivery_option( string $display_key, int $offer_id, string $label, string $estimate ): ProductDeliveryOption {
		return new ProductDeliveryOption(
			$display_key,
			explode( ':', $display_key )[0],
			'Warehouse',
			'delivery',
			'Delivery',
			$offer_id,
			$label,
			null,
			$estimate,
			true,
			null
		);
	}
}

/**
 * Small location fixtures local to this test.
 */
final class PerItemContextLocations {

	public static function accra(): MatchingLocation {
		return MatchingLocation::fromInput( [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra', 'postcode' => 'GA-123' ] );
	}

	public static function accra_address(): DeliveryAddress {
		return DeliveryAddress::fromInput(
			[
				'country'    => 'GH',
				'state'      => 'AA',
				'city'       => 'Accra',
				'postcode'   => 'GA-123',
				'address_1'  => '12 Boundary Rd',
				'first_name' => 'Ama',
				'last_name'  => 'Mensah',
				'phone'      => '0244000000',
			]
		);
	}
}
