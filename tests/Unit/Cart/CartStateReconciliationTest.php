<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Cart;

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
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CartStateReconciliationTest extends TestCase {

	private const BASE_INTENT = [
		'contract_version'        => '1',
		'product_id'              => 101,
		'variation_id'            => null,
		'target_type'             => 'product',
		'target_id'               => 101,
		'display_key'             => 'in_warehouse:delivery:10',
		'fulfilment_availability' => 'in_warehouse',
		'fulfilment_choice'       => 'delivery',
		'delivery_offer_id'       => 10,
		'rule_id'                 => 5,
		'issued_at'               => '2026-08-01T00:00:00+00:00',
		'configuration_fingerprint' => 'old-fingerprint',
	];

	public function test_metadata_change_refreshes_existing_cart_without_duplicate_identity(): void {
		$stored  = $this->cart_item( self::BASE_INTENT, 'Standard', '2 days', 'old-fingerprint' );
		$current = $this->option( 'in_warehouse:delivery:10', 'in_warehouse', 'delivery', 10, 'Standard Plus', '3–4 days' );
		$fresh   = $this->intent_from_option( $current, 'new-fingerprint' );

		$reconciler = $this->reconciler();
		$outcome    = $reconciler->reconcile_line(
			'old-key',
			$stored,
			[ $current ],
			'required',
			fn () => ProductDeliverySelectionValidationResult::valid( $current, ProductDeliverySelectionIntent::fromArray( $fresh ) )
		);

		self::assertSame( CartReconciliationOutcome::ACTION_REFRESHED, $outcome->action );
		self::assertSame( 'Standard Plus', $outcome->cart_item[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ]['delivery_offer_public_label'] );
		self::assertSame( '3–4 days', $outcome->cart_item[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ]['estimate_text'] );
		self::assertSame( 'new-fingerprint', $outcome->cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]['configuration_fingerprint'] );
		self::assertSame( '2026-08-01T00:00:00+00:00', $outcome->cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]['issued_at'] );
		self::assertArrayNotHasKey( CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY, $outcome->cart_item );

		$stale_id = CartLineCustomerIdentity::cartIdFromItem( $stored );
		$fresh_id = CartLineCustomerIdentity::cartIdFromItem( $outcome->cart_item );
		self::assertSame( $stale_id, $fresh_id );
	}

	public function test_removed_option_marks_needs_reselection_and_strips_stale_summary(): void {
		$stored     = $this->cart_item( self::BASE_INTENT, 'Option A', '2 days', 'old-fingerprint' );
		$other      = $this->option( 'in_warehouse:delivery:20', 'in_warehouse', 'delivery', 20, 'Option B', '1 day' );
		$reconciler = $this->reconciler();
		$outcome    = $reconciler->reconcile_line(
			'line-a',
			$stored,
			[ $other ],
			'required',
			fn () => ProductDeliverySelectionValidationResult::invalid( 'option_not_found', 'missing' )
		);

		self::assertTrue( $outcome->needsCustomerReselection() );
		self::assertTrue( ! empty( $outcome->cart_item[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] ) );
		self::assertArrayNotHasKey( CartDeliverySelectionCapture::CART_SUMMARY_KEY, $outcome->cart_item );
		self::assertSame( 10, $outcome->cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]['delivery_offer_id'] );
		self::assertStringContainsString( 'do not need to remove', strtolower( $outcome->message ) );
		self::assertStringNotContainsString( 'remove and re-add', strtolower( $outcome->message ) );
	}

	public function test_replaced_option_does_not_silently_substitute_another_offer(): void {
		$stored = $this->cart_item( self::BASE_INTENT, 'A', '2 days', 'old' );
		$b      = $this->option( 'in_warehouse:delivery:99', 'in_warehouse', 'delivery', 99, 'Replacement', '1 day' );

		self::assertNull( CartDeliverySelectionEquivalence::findEquivalentOption( [ $b ], self::BASE_INTENT ) );

		$outcome = $this->reconciler()->reconcile_line(
			'line-a',
			$stored,
			[ $b ],
			'required',
			fn () => ProductDeliverySelectionValidationResult::invalid( 'option_not_found', 'missing' )
		);

		self::assertSame( CartReconciliationOutcome::ACTION_NEEDS_RESELECTION, $outcome->action );
		self::assertSame( 10, $outcome->cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]['delivery_offer_id'] );
	}

	public function test_same_offer_id_under_new_display_key_is_deterministic_equivalent(): void {
		$stored = self::BASE_INTENT;
		$moved  = $this->option( 'in_store:delivery:10', 'in_store', 'delivery', 10, 'Standard', '2 days' );

		$match = CartDeliverySelectionEquivalence::findEquivalentOption( [ $moved ], $stored );

		self::assertNotNull( $match );
		self::assertSame( 'in_store:delivery:10', $match->display_key );
		self::assertSame( 10, $match->delivery_offer_id );
	}

	public function test_readded_product_does_not_keep_admin_fingerprint_in_cart_id(): void {
		$old = $this->cart_item( self::BASE_INTENT, 'Standard', '2 days', 'old-fingerprint' );
		$new_intent = self::BASE_INTENT;
		$new_intent['configuration_fingerprint'] = 'new-fingerprint';
		$new_intent['issued_at'] = '2026-09-01T00:00:00+00:00';
		$new = $this->cart_item( $new_intent, 'Standard Plus', '4 days', 'new-fingerprint' );

		self::assertNotSame(
			CartDeliverySelectionFingerprint::fromIntent( self::BASE_INTENT ),
			CartDeliverySelectionFingerprint::fromIntent( $new_intent )
		);
		self::assertSame(
			CartLineCustomerIdentity::cartIdFromItem( $old ),
			CartLineCustomerIdentity::cartIdFromItem( $new )
		);

		$merged = $this->reconciler()->rekey_contents(
			[
				'stale-md5' => $old,
				'fresh-md5' => $new,
			]
		);

		self::assertCount( 1, $merged );
		self::assertSame( 2, (int) array_values( $merged )[0]['quantity'] );
	}

	public function test_same_context_consolidates_and_different_choice_stays_separate(): void {
		$delivery = $this->cart_item( self::BASE_INTENT, 'Standard', '2 days', 'fp' );
		$copy     = $this->cart_item( self::BASE_INTENT, 'Standard renamed', '9 days', 'other-fp' );
		$pickup_intent = self::BASE_INTENT;
		$pickup_intent['fulfilment_choice'] = 'store_pickup';
		$pickup_intent['delivery_offer_id'] = null;
		$pickup_intent['display_key'] = 'in_store:store_pickup:pickup';
		$pickup_intent['fulfilment_availability'] = 'in_store';
		$pickup = $this->cart_item( $pickup_intent, 'Store pickup', 'Ready', 'fp' );

		$merged = $this->reconciler()->rekey_contents(
			[
				'a' => $delivery,
				'b' => $copy,
				'c' => $pickup,
			]
		);

		self::assertCount( 2, $merged );
		$quantities = array_map( static fn ( array $item ): int => (int) $item['quantity'], $merged );
		self::assertContains( 2, $quantities );
		self::assertContains( 1, $quantities );
	}

	public function test_different_per_item_locations_never_merge(): void {
		$accra = self::BASE_INTENT;
		$accra['customer_location'] = 'GH-AA';
		$kumasi = self::BASE_INTENT;
		$kumasi['customer_location'] = 'GH-AH';

		$merged = $this->reconciler()->rekey_contents(
			[
				'a' => $this->cart_item( $accra, 'Standard', '2 days', 'fp' ),
				'b' => $this->cart_item( $kumasi, 'Standard', '2 days', 'fp' ),
			]
		);

		self::assertCount( 2, $merged );
	}

	public function test_variation_identity_remains_separate(): void {
		$v1 = self::BASE_INTENT;
		$v1['variation_id'] = 201;
		$v2 = self::BASE_INTENT;
		$v2['variation_id'] = 202;

		$item_a = $this->cart_item( $v1, 'Standard', '2 days', 'fp' );
		$item_a['variation_id'] = 201;
		$item_b = $this->cart_item( $v2, 'Standard', '2 days', 'fp' );
		$item_b['variation_id'] = 202;

		$merged = $this->reconciler()->rekey_contents(
			[
				'a' => $item_a,
				'b' => $item_b,
			]
		);

		self::assertCount( 2, $merged );
	}

	public function test_does_not_switch_delivery_to_pickup_or_air_for_price(): void {
		$delivery = self::BASE_INTENT;
		$pickup   = $this->option( 'in_store:store_pickup:pickup', 'in_store', 'store_pickup', null, 'Store pickup', 'Ready' );
		$air      = $this->option( 'international_fulfilment:delivery:80', 'international_fulfilment', 'delivery', 80, 'Air', '5 days' );

		self::assertNull( CartDeliverySelectionEquivalence::findEquivalentOption( [ $pickup ], $delivery ) );
		self::assertNull( CartDeliverySelectionEquivalence::findEquivalentOption( [ $air ], $delivery ) );
	}

	public function test_international_remains_air_sea_only(): void {
		$stored = self::BASE_INTENT;
		$stored['fulfilment_availability'] = 'international_fulfilment';
		$stored['display_key'] = 'international_fulfilment:delivery:80';
		$stored['delivery_offer_id'] = 80;

		$local = $this->option( 'in_warehouse:delivery:10', 'in_warehouse', 'delivery', 10, 'Local', '2 days' );
		$air   = $this->option( 'international_fulfilment:delivery:80', 'international_fulfilment', 'delivery', 80, 'Air', '5 days' );
		$sea   = $this->option( 'international_fulfilment:delivery:81', 'international_fulfilment', 'delivery', 81, 'Sea', '20 days' );

		self::assertNull( CartDeliverySelectionEquivalence::findEquivalentOption( [ $local ], $stored ) );
		self::assertSame( 80, CartDeliverySelectionEquivalence::findEquivalentOption( [ $air, $sea, $local ], $stored )?->delivery_offer_id );
	}

	public function test_in_warehouse_remains_local_delivery_only(): void {
		$air = $this->option( 'international_fulfilment:delivery:80', 'international_fulfilment', 'delivery', 80, 'Air', '5 days' );
		$local = $this->option( 'in_warehouse:delivery:10', 'in_warehouse', 'delivery', 10, 'Local', '2 days' );

		self::assertNull( CartDeliverySelectionEquivalence::findEquivalentOption( [ $air ], self::BASE_INTENT ) );
		self::assertSame( 10, CartDeliverySelectionEquivalence::findEquivalentOption( [ $local ], self::BASE_INTENT )?->delivery_offer_id );
	}

	public function test_invalid_stale_managed_selection_fails_closed_as_needs_reselection(): void {
		$stored  = $this->cart_item( self::BASE_INTENT, 'Gone', '2 days', 'fp' );
		$outcome = $this->reconciler()->reconcile_line(
			'line-a',
			$stored,
			[],
			'blocked',
			fn () => ProductDeliverySelectionValidationResult::invalid( 'option_unavailable', 'gone' )
		);

		self::assertTrue( $outcome->needsCustomerReselection() );
		self::assertSame( CartDeliverySelectionRevalidationResult::STATUS_UNAVAILABLE, $outcome->status );
		self::assertNotEmpty( $outcome->cart_item[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] );
	}

	public function test_session_restore_after_configuration_mutation_keeps_customer_owned_location(): void {
		$intent = self::BASE_INTENT;
		$intent['customer_location'] = 'GH-AA';
		$hash = CartDeliverySelectionFingerprint::fromIntent( $intent );

		$restored = CartDeliverySelectionSessionData::restoreFromSession(
			[
				CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent,
				CartDeliverySelectionCapture::CART_SUMMARY_KEY   => [
					'delivery_offer_public_label' => 'Standard',
					'estimate_text'               => '2 days',
				],
				CartDeliverySelectionCapture::CART_HASH_KEY => $hash,
			]
		);

		self::assertNotNull( $restored );
		self::assertSame( 'GH-AA', $restored['intent']['customer_location'] );
		self::assertFalse( $restored['needs_reselection'] );

		$needs = CartDeliverySelectionSessionData::restoreFromSession(
			[
				CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent,
				CartDeliverySelectionCapture::CART_HASH_KEY      => $hash,
				CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY => true,
			]
		);

		self::assertNotNull( $needs );
		self::assertTrue( $needs['needs_reselection'] );
		self::assertSame( [], $needs['summary'] );
	}

	public function test_needs_reselection_group_does_not_share_package_with_valid_same_offer(): void {
		$valid = $this->cart_item( self::BASE_INTENT, 'Standard', '2 days', 'fp' );
		$stale = CartDeliverySelectionReconciler::apply_needs_reselection(
			$this->cart_item( self::BASE_INTENT, 'Standard', '2 days', 'fp' )
		);

		self::assertNotSame(
			DeliveryGroupIdentity::fromCartItem( $valid ),
			DeliveryGroupIdentity::fromCartItem( $stale )
		);
		self::assertStringEndsWith( '|reselect', (string) DeliveryGroupIdentity::fromCartItem( $stale ) );
	}

	public function test_woocommerce_cart_id_ignores_summary_and_hash(): void {
		$a = [
			CartDeliverySelectionCapture::CART_SELECTION_KEY => self::BASE_INTENT,
			CartDeliverySelectionCapture::CART_SUMMARY_KEY   => [ 'delivery_offer_public_label' => 'Old' ],
			CartDeliverySelectionCapture::CART_HASH_KEY      => str_repeat( 'a', 64 ),
		];
		$b = [
			CartDeliverySelectionCapture::CART_SELECTION_KEY => array_merge( self::BASE_INTENT, [ 'configuration_fingerprint' => 'new' ] ),
			CartDeliverySelectionCapture::CART_SUMMARY_KEY   => [ 'delivery_offer_public_label' => 'New' ],
			CartDeliverySelectionCapture::CART_HASH_KEY      => str_repeat( 'b', 64 ),
		];

		self::assertSame(
			CartLineCustomerIdentity::generateCartId( 101, 0, [], $a ),
			CartLineCustomerIdentity::generateCartId( 101, 0, [], $b )
		);
	}

	/**
	 * @param array<string, mixed> $intent
	 *
	 * @return array<string, mixed>
	 */
	private function cart_item( array $intent, string $label, string $estimate, string $fingerprint ): array {
		$intent['configuration_fingerprint'] = $fingerprint;
		$normalized = CartDeliverySelectionSessionData::normalizeIntent( $intent );
		self::assertNotNull( $normalized );

		return [
			'key'          => 'line',
			'product_id'   => (int) $intent['product_id'],
			'variation_id' => (int) ( $intent['variation_id'] ?? 0 ),
			'quantity'     => 1,
			'variation'    => [],
			CartDeliverySelectionCapture::CART_SELECTION_KEY => $normalized,
			CartDeliverySelectionCapture::CART_SUMMARY_KEY   => [
				'delivery_offer_public_label' => $label,
				'estimate_text'               => $estimate,
			],
			CartDeliverySelectionCapture::CART_HASH_KEY => CartDeliverySelectionFingerprint::fromIntent( $normalized ),
		];
	}

	private function option(
		string $display_key,
		string $availability,
		string $choice,
		?int $offer_id,
		string $label,
		string $estimate
	): ProductDeliveryOption {
		return new ProductDeliveryOption(
			$display_key,
			$availability,
			$availability,
			$choice,
			$choice,
			$offer_id,
			$label,
			null,
			$estimate,
			true,
			null
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function intent_from_option( ProductDeliveryOption $option, string $fingerprint ): array {
		return ProductDeliverySelectionIntent::fromValidatedOption(
			101,
			null,
			'product',
			101,
			$option,
			5,
			$fingerprint
		)->toArray();
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
