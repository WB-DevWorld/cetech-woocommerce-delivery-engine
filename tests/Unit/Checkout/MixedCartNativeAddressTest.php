<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Checkout;

use CetechDeliveryEngine\Application\Cart\CartCustomerContextMutationService;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Checkout\CheckoutAddressPolicy;
use CetechDeliveryEngine\Application\CustomerContext\ApplyCustomerContextToEligibleLinesService;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Application\Shipping\ShippingPackageBuilder;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Integrations\Blocks\BlocksStoreApiExtension;
use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;
use CetechDeliveryEngine\Tests\Unit\CustomerContext\PerItemContextFixtures;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * COR-026: a managed Delivery line keeps its committed destination while a native
 * shippable line in the same cart keeps the customer's WooCommerce shipping address;
 * billing stays independent.
 */
final class MixedCartNativeAddressTest extends TestCase {

	private CheckoutAddressPolicy $policy;

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [
			'cetech_de_enable_product_delivery_selector'              => 1,
			'cetech_de_enable_cart_delivery_selection_capture'        => 1,
			'cetech_de_enable_checkout_delivery_selection_validation' => 1,
			'cetech_de_enable_woocommerce_shipping_rate_calculation'  => 1,
		];

		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' ); // phpcs:ignore Squiz.PHP.Eval -- test bootstrap only.
		}

		$this->policy = new CheckoutAddressPolicy(
			new FeatureFlags(),
			new Requirements(),
			( new ReflectionClass( ApplyCustomerContextToEligibleLinesService::class ) )->newInstanceWithoutConstructor(),
			new CartCustomerContextMutationService()
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cetech_de_test_wc'] );
	}

	public function test_native_line_keeps_the_posted_kumasi_shipping_address_and_billing_is_untouched(): void {
		$contents = [
			'a' => $this->managed_accra_line(),
			'b' => $this->native_line( true ),
		];
		$summary  = $this->policy->summarize_cart( $contents );

		self::assertTrue( $summary['has_delivery'] );
		self::assertSame( 1, $summary['native_shipping_lines'] );
		self::assertTrue( $summary['mixed_ownership'] );
		self::assertCount( 1, $summary['complete_identities'], 'the managed line is still complete and authoritative for itself' );
		self::assertNull( $this->policy->shared_complete_delivery_address( $contents ), 'no shared projection while a native line owns the checkout address' );

		$posted  = $this->posted_kumasi();
		$aligned = $this->policy->align_posted_shipping( $posted, $contents );

		self::assertSame( $posted, $aligned, 'the native line keeps the Kumasi checkout shipping address' );
		self::assertSame( 'Tamale', $aligned['billing_city'] );
	}

	public function test_managed_only_cart_still_projects_the_shared_address_but_never_billing(): void {
		$contents = [ 'a' => $this->managed_accra_line(), 'c' => $this->managed_accra_line() ];
		$summary  = $this->policy->summarize_cart( $contents );

		self::assertSame( 0, $summary['native_shipping_lines'] );
		self::assertFalse( $summary['mixed_ownership'] );

		$aligned = $this->policy->align_posted_shipping( $this->posted_kumasi(), $contents );

		self::assertSame( 'Accra', $aligned['shipping_city'] );
		self::assertSame( '12 Boundary Rd', $aligned['shipping_address_1'] );
		self::assertSame( 'Tamale', $aligned['billing_city'], 'billing remains independently governed' );
		self::assertSame( '5 Market Rd', $aligned['billing_address_1'] );
	}

	public function test_lines_that_need_no_shipping_do_not_claim_the_checkout_address(): void {
		$contents = [ 'a' => $this->managed_accra_line(), 'v' => $this->native_line( false ) ];
		$summary  = $this->policy->summarize_cart( $contents );

		self::assertSame( 0, $summary['native_shipping_lines'] );
		self::assertFalse( $summary['mixed_ownership'] );
		self::assertSame( 'Accra', $this->policy->align_posted_shipping( $this->posted_kumasi(), $contents )['shipping_city'] );
	}

	public function test_packages_keep_accra_for_the_managed_line_and_kumasi_for_the_native_line(): void {
		$builder  = new ShippingPackageBuilder(
			new ShippingRateCalculationGate( new FeatureFlags(), new Requirements() ),
			( new ReflectionClass( CartDeliverySelectionCapture::class ) )->newInstanceWithoutConstructor()
		);
		$packages = $builder->split_package(
			[
				'contents'        => [ 'a' => $this->managed_accra_line(), 'b' => $this->native_line( true ) ],
				'contents_cost'   => 40.0,
				'destination'     => [ 'country' => 'GH', 'state' => 'AH', 'city' => 'Kumasi', 'postcode' => 'AK-000', 'address' => '7 Lake Rd', 'address_2' => '' ],
				'applied_coupons' => [],
				'user'            => [ 'ID' => 0 ],
			]
		);

		self::assertCount( 2, $packages );
		$managed  = null;
		$residual = null;
		foreach ( $packages as $package ) {
			if ( DeliveryGroupIdentity::is_managed_package( $package ) ) {
				$managed = $package;
			} else {
				$residual = $package;
			}
		}

		self::assertNotNull( $managed );
		self::assertNotNull( $residual );
		self::assertSame( 'Accra', $managed['destination']['city'] );
		self::assertSame( '12 Boundary Rd', $managed['destination']['address'] );
		self::assertArrayHasKey( 'a', $managed['contents'] );
		self::assertSame( 'Kumasi', $residual['destination']['city'] );
		self::assertSame( '7 Lake Rd', $residual['destination']['address'] );
		self::assertArrayHasKey( 'b', $residual['contents'] );
		self::assertArrayNotHasKey( DeliveryGroupIdentity::PACKAGE_META_KEY, $residual );
	}

	public function test_classic_and_blocks_tell_the_customer_which_address_the_native_line_uses(): void {
		$contents = [ 'a' => $this->managed_accra_line(), 'b' => $this->native_line( true ) ];
		$this->install_cart( $contents );

		ob_start();
		$this->policy->render_notices();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( CustomerStorefrontCopy::native_lines_use_checkout_address(), $html );

		$extension = ( new ReflectionClass( BlocksStoreApiExtension::class ) )->newInstanceWithoutConstructor();
		$property  = ( new ReflectionClass( BlocksStoreApiExtension::class ) )->getProperty( 'address_policy' );
		$property->setValue( $extension, $this->policy );
		$method = new ReflectionMethod( BlocksStoreApiExtension::class, 'checkout_notices' );
		if ( \PHP_VERSION_ID < 80500 ) {
			$method->setAccessible( true );
		}
		$notices = $method->invoke( $extension );

		self::assertTrue( $notices['mixed_ownership'] );
		self::assertContains( 'mixed_ownership', array_column( $notices['messages'], 'code' ) );
		self::assertFalse( $notices['can_apply_checkout_address'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function managed_accra_line(): array {
		return PerItemContextFixtures::cartItem(
			[
				'contract_version'        => '1',
				'product_id'              => 101,
				'variation_id'            => 0,
				'target_type'             => 'product',
				'target_id'               => 101,
				'display_key'             => 'in_warehouse:delivery:10',
				'fulfilment_availability' => 'in_warehouse',
				'fulfilment_choice'       => 'delivery',
				'delivery_offer_id'       => 10,
				'rule_id'                 => null,
				'issued_at'               => '2026-10-29T00:00:00+00:00',
			],
			PerItemContextFixtures::deliveryContext( 10, PerItemContextFixtures::deliveryAccraStreet( '12 Boundary Rd' ) ),
			1,
			[
				CartDeliverySelectionCapture::CART_SUMMARY_KEY => [ 'delivery_offer_public_label' => 'Standard', 'estimate_text' => '2 days' ],
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function native_line( bool $needs_shipping ): array {
		return [
			'product_id'   => 999,
			'variation_id' => 0,
			'quantity'     => 1,
			'data'         => new class( $needs_shipping ) {
				public function __construct( private bool $needs_shipping ) {
				}

				public function needs_shipping(): bool {
					return $this->needs_shipping;
				}

				public function get_name(): string {
					return 'Native Lamp';
				}
			},
		];
	}

	/**
	 * @return array<string, string>
	 */
	private function posted_kumasi(): array {
		return [
			'billing_country'    => 'GH',
			'billing_city'       => 'Tamale',
			'billing_address_1'  => '5 Market Rd',
			'shipping_country'   => 'GH',
			'shipping_state'     => 'AH',
			'shipping_city'      => 'Kumasi',
			'shipping_postcode'  => 'AK-000',
			'shipping_address_1' => '7 Lake Rd',
		];
	}

	/**
	 * @param array<string, array<string, mixed>> $contents
	 */
	private function install_cart( array $contents ): void {
		$GLOBALS['cetech_de_test_wc'] = (object) [
			'cart' => new class( $contents ) {
				/**
				 * @param array<string, array<string, mixed>> $contents
				 */
				public function __construct( private array $contents ) {
				}

				public function get_cart(): array {
					return $this->contents;
				}
			},
		];
	}
}
