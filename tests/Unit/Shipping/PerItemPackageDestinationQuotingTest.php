<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipping;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionFingerprint;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\Shipping\CartLineShippingAssessorInterface;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingRateCalculator;
use CetechDeliveryEngine\Application\Shipping\ShippingPackageBuilder;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Unit\CustomerContext\PerItemContextFixtures;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryQuoteRateCardRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PerItemPackageDestinationQuotingTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [
			'cetech_de_enable_product_delivery_selector'              => 1,
			'cetech_de_enable_cart_delivery_selection_capture'    => 1,
			'cetech_de_enable_checkout_delivery_selection_validation' => 1,
			'cetech_de_enable_woocommerce_shipping_rate_calculation' => 1,
		];

		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' );
		}

		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			eval( 'function get_woocommerce_currency(): string { return "USD"; }' );
		}
	}

	public function test_v1_managed_line_without_customer_context_keeps_wc_destination(): void {
		$item = [
			'product_id'   => 101,
			'variation_id' => 0,
			'quantity'     => 1,
			CartDeliverySelectionCapture::CART_SELECTION_KEY => $this->intent( 101, 10 ),
			CartDeliverySelectionCapture::CART_SUMMARY_KEY   => [
				'delivery_offer_public_label' => 'QA Local Standard',
			],
			CartDeliverySelectionCapture::CART_HASH_KEY => CartDeliverySelectionFingerprint::fromIntent( $this->intent( 101, 10 ) ),
		];
		$packages = $this->builder()->split_package(
			$this->wc_package(
				[ 'line-v1' => $item ],
				[ 'country' => 'GH', 'city' => 'Accra', 'address' => 'WC Street' ]
			)
		);

		self::assertCount( 1, $packages );
		self::assertTrue( DeliveryGroupIdentity::is_managed_package( $packages[0] ) );
		self::assertSame( 'WC Street', $packages[0]['destination']['address'] );
	}

	public function test_accra_and_kumasi_same_offer_produce_separate_packages_and_rates(): void {
		$accra   = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' ) );
		$kumasi  = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryKumasi() );
		$intent  = $this->intent( 101, 10 );
		$packages = $this->builder()->split_package(
			$this->wc_package(
				[
					'line-accra'  => PerItemContextFixtures::cartItem( $intent, $accra ),
					'line-kumasi' => PerItemContextFixtures::cartItem( $intent, $kumasi ),
				],
				[ 'country' => 'NG', 'city' => 'Lagos', 'postcode' => '100001', 'state' => 'LA', 'address' => 'Global Street' ]
			)
		);

		self::assertCount( 2, $packages );
		$ids = array_map(
			static fn ( array $p ): string => (string) $p[ DeliveryGroupIdentity::PACKAGE_META_KEY ]['group_id'],
			$packages
		);
		self::assertCount( 2, array_unique( $ids ) );
		self::assertStringNotContainsString( 'Boundary', implode( ' ', $ids ) );
		self::assertStringNotContainsString( 'Lake Rd', implode( ' ', $ids ) );

		$calculator = $this->calculator();
		$totals     = [];
		foreach ( $packages as $package ) {
			$city = strtolower( (string) ( $package['destination']['city'] ?? '' ) );
			self::assertNotSame( 'lagos', $city );
			$result = $calculator->calculate_for_package( $package );
			self::assertTrue( $result->success, (string) $result->block_reason );
			$totals[ $city ] = $result->total_amount;
		}

		self::assertSame( '15.0000', $totals['accra'] ?? null );
		self::assertSame( '35.0000', $totals['kumasi'] ?? null );
		self::assertSame( '50.0000', $this->add( $totals['accra'] ?? '0', $totals['kumasi'] ?? '0' ) );
	}

	public function test_lab_accra_and_kumasi_quote_15_and_22_in_one_cart(): void {
		$accra  = PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingAccra() );
		$kumasi = PerItemContextFixtures::incompleteContext( 10, PerItemContextFixtures::matchingKumasi() );
		$intent = $this->intent( 16, 10 );
		$packages = $this->builder()->split_package(
			$this->wc_package(
				[
					'line-accra'  => PerItemContextFixtures::cartItem( $intent, $accra ),
					'line-kumasi' => PerItemContextFixtures::cartItem( $intent, $kumasi ),
				]
			)
		);

		self::assertCount( 2, $packages );
		$calculator = $this->calculator( '22.00' );
		$totals     = [];
		foreach ( $packages as $package ) {
			$city   = strtolower( (string) ( $package['destination']['city'] ?? '' ) );
			$result = $calculator->calculate_for_package( $package );
			self::assertTrue( $result->success, (string) $result->block_reason );
			$totals[ $city ] = $result->total_amount;
			self::assertStringNotContainsString( 'Boundary', (string) $package[ DeliveryGroupIdentity::PACKAGE_META_KEY ]['group_id'] );
		}

		self::assertSame( '15.0000', $totals['accra'] ?? null );
		self::assertSame( '22.0000', $totals['kumasi'] ?? null );
		self::assertSame( '37.0000', $this->add( $totals['accra'] ?? '0', $totals['kumasi'] ?? '0' ) );
	}

	public function test_same_zone_different_streets_remain_separate_groups_with_same_rate(): void {
		$a = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' ) );
		$b = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryAccraStreet( '99 Ring Road' ) );
		$intent = $this->intent( 101, 10 );
		$packages = $this->builder()->split_package(
			$this->wc_package(
				[
					'line-a' => PerItemContextFixtures::cartItem( $intent, $a ),
					'line-b' => PerItemContextFixtures::cartItem( $intent, $b ),
				]
			)
		);

		self::assertCount( 2, $packages );
		self::assertNotSame(
			$packages[0][ DeliveryGroupIdentity::PACKAGE_META_KEY ]['group_id'],
			$packages[1][ DeliveryGroupIdentity::PACKAGE_META_KEY ]['group_id']
		);

		$calculator = $this->calculator();
		$amounts    = [];
		foreach ( $packages as $package ) {
			self::assertSame( '12 Boundary Rd' === (string) ( $package['destination']['address'] ?? '' ) || '99 Ring Road' === (string) ( $package['destination']['address'] ?? '' ), true );
			$result = $calculator->calculate_for_package( $package );
			self::assertTrue( $result->success );
			$amounts[] = $result->total_amount;
		}

		self::assertSame( [ '15.0000', '15.0000' ], $amounts );
	}

	public function test_global_wc_address_does_not_override_managed_package_destination(): void {
		$kumasi = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryKumasi() );
		$packages = $this->builder()->split_package(
			$this->wc_package(
				[ 'line-k' => PerItemContextFixtures::cartItem( $this->intent( 101, 10 ), $kumasi ) ],
				[ 'country' => 'GH', 'city' => 'Accra', 'state' => 'AA', 'postcode' => 'GA-123', 'address' => 'WC Checkout St' ]
			)
		);

		self::assertCount( 1, $packages );
		self::assertSame( 'Kumasi', $packages[0]['destination']['city'] );
		self::assertSame( '7 Lake Rd', $packages[0]['destination']['address'] );
		self::assertNotSame( 'WC Checkout St', $packages[0]['destination']['address'] );
	}

	public function test_unmanaged_residual_package_keeps_wc_destination(): void {
		$managed = PerItemContextFixtures::cartItem(
			$this->intent( 101, 10 ),
			PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' ) )
		);
		$unmanaged = [
			'product_id'   => 999,
			'variation_id' => 0,
			'quantity'     => 1,
		];

		$packages = $this->builder()->split_package(
			$this->wc_package(
				[
					'line-m' => $managed,
					'line-u' => $unmanaged,
				],
				[ 'country' => 'GH', 'city' => 'Accra', 'state' => 'AA', 'postcode' => '', 'address' => 'WC Street' ]
			)
		);

		$residual = null;
		foreach ( $packages as $package ) {
			if ( empty( $package[ DeliveryGroupIdentity::PACKAGE_META_KEY ]['managed'] ) ) {
				$residual = $package;
			}
		}

		self::assertNotNull( $residual );
		self::assertSame( 'WC Street', $residual['destination']['address'] );
		self::assertArrayHasKey( 'line-u', $residual['contents'] );
	}

	public function test_pickup_package_has_empty_destination_and_zero_quote(): void {
		$pickup = CustomerCartContext::pickup( 4 );
		$item   = PerItemContextFixtures::cartItem(
			$this->intent( 202, null, 'in_store', 'store_pickup' ),
			$pickup
		);
		$item[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ]['pickup_location_label'] = 'QA Accra Pickup';
		$packages = $this->builder()->split_package( $this->wc_package( [ 'line-p' => $item ] ) );

		self::assertCount( 1, $packages );
		self::assertTrue( ! empty( $packages[0][ DeliveryGroupIdentity::PACKAGE_META_KEY ]['is_pickup'] ) );
		self::assertSame( '', $packages[0]['destination']['country'] );
		self::assertSame( '', $packages[0]['destination']['address'] );

		$result = $this->calculator()->calculate_for_package( $packages[0] );
		self::assertTrue( $result->success );
		self::assertSame( '0.0000', $result->total_amount );
	}

	public function test_package_labels_are_customer_safe(): void {
		$accra = PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' ) );
		$packages = $this->builder()->split_package(
			$this->wc_package( [ 'line-a' => PerItemContextFixtures::cartItem( $this->intent( 101, 10 ), $accra ) ] )
		);

		$label = (string) $packages[0][ DeliveryGroupIdentity::PACKAGE_META_KEY ]['rate_label'];
		self::assertStringContainsString( 'Delivery to Accra', $label );
		self::assertStringNotContainsString( '12 Boundary', $label );
		self::assertStringNotContainsString( $packages[0][ DeliveryGroupIdentity::PACKAGE_META_KEY ]['group_id'], 'Delivery to Accra' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function intent( int $product_id, ?int $offer_id, string $availability = 'in_warehouse', string $choice = 'delivery' ): array {
		$suffix = null === $offer_id ? 'pickup' : (string) $offer_id;

		return [
			'contract_version'        => '1',
			'product_id'              => $product_id,
			'variation_id'            => null,
			'target_type'             => 'product',
			'target_id'               => $product_id,
			'display_key'             => $availability . ':' . $choice . ':' . $suffix,
			'fulfilment_availability' => $availability,
			'fulfilment_choice'       => $choice,
			'delivery_offer_id'       => $offer_id,
			'rule_id'                 => null,
			'issued_at'               => '2026-09-02T00:00:00+00:00',
		];
	}

	/**
	 * @param array<string, array<string, mixed>> $contents
	 * @param array<string, string>                 $destination
	 *
	 * @return array<string, mixed>
	 */
	private function wc_package( array $contents, array $destination = [] ): array {
		foreach ( $contents as $key => $cart_item ) {
			if ( ! isset( $cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ) ) {
				continue;
			}
			if ( ! isset( $cart_item[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ] ) ) {
				$contents[ $key ][ CartDeliverySelectionCapture::CART_SUMMARY_KEY ] = [
					'delivery_offer_public_label' => 'QA Local Standard',
					'estimate_text'                 => '2-4 business days',
				];
			}
			if ( ! isset( $cart_item[ CartDeliverySelectionCapture::CART_HASH_KEY ] ) ) {
				$contents[ $key ][ CartDeliverySelectionCapture::CART_HASH_KEY ] = CartDeliverySelectionFingerprint::fromIntent(
					$cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]
				);
			}
		}

		return [
			'contents'      => $contents,
			'contents_cost' => 19.99,
			'destination'   => array_merge(
				[
					'country'   => 'GH',
					'state'     => '',
					'city'      => 'Accra',
					'postcode'  => '',
					'address'   => 'WC Default',
					'address_2' => '',
				],
				$destination
			),
			'applied_coupons' => [],
			'user'            => [ 'ID' => 0 ],
		];
	}

	private function builder(): ShippingPackageBuilder {
		return new ShippingPackageBuilder(
			new ShippingRateCalculationGate( new FeatureFlags(), new Requirements() ),
			( new ReflectionClass( CartDeliverySelectionCapture::class ) )->newInstanceWithoutConstructor()
		);
	}

	private function calculator( string $kumasi_amount = '35.00' ): SelectedOfferShippingRateCalculator {
		$zone = new class() implements PackageDestinationZoneResolverInterface {
			public function resolve_zone_id( array $destination ): ?int {
				$ids = $this->resolve_zone_ids( $destination );

				return $ids[0] ?? null;
			}

			public function resolve_zone_ids( array $destination ): array {
				$city = strtolower( (string) ( $destination['city'] ?? '' ) );
				if ( in_array( $city, [ 'accra', 'east legon', 'spintex' ], true ) ) {
					return [ 20 ];
				}
				if ( 'kumasi' === $city ) {
					return [ 21 ];
				}

				return [];
			}
		};

		$rules = $this->createMock( ProductDeliveryRuleRepositoryInterface::class );
		$rules->method( 'findById' )->willReturn( null );

		$assessor = new class() implements CartLineShippingAssessorInterface {
			public function assess_line( string $cart_item_key, array $cart_item ): array {
				$intent = $cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null;

				return [
					'action' => 'quote',
					'intent' => is_array( $intent ) ? $intent : [],
				];
			}
		};

		return new SelectedOfferShippingRateCalculator(
			new ShippingRateCalculationGate( new FeatureFlags(), new Requirements() ),
			$zone,
			$assessor,
			new RateQuoteEngine(
				new InMemoryQuoteRateCardRepository(
					[
						[
							'id'                   => 4,
							'internal_code'        => 'ACCRA',
							'delivery_offer_id'    => 10,
							'destination_zone_id'  => 20,
							'logistics_profile_id' => null,
							'supplier_id'          => null,
							'origin_id'            => null,
							'charge_type'          => RateCardChargeType::FixedPerShipment->value,
							'base_amount'          => '15.00',
							'base_currency'        => $this->currency(),
							'priority'             => 100,
							'status'               => 'active',
						],
						[
							'id'                   => 5,
							'internal_code'        => 'KUMASI',
							'delivery_offer_id'    => 10,
							'destination_zone_id'  => 21,
							'logistics_profile_id' => null,
							'supplier_id'          => null,
							'origin_id'            => null,
							'charge_type'          => RateCardChargeType::FixedPerShipment->value,
							'base_amount'          => $kumasi_amount,
							'base_currency'        => $this->currency(),
							'priority'             => 100,
							'status'               => 'active',
						],
					]
				)
			),
			$rules,
			( new ReflectionClass( Logger::class ) )->newInstanceWithoutConstructor()
		);
	}

	private function currency(): string {
		return function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : 'GHS';
	}

	private function add( string $a, string $b ): string {
		return number_format( (float) $a + (float) $b, 4, '.', '' );
	}
}
