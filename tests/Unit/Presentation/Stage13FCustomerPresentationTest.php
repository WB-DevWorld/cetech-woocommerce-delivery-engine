<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliveryLineSummary;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliveryPackageSummary;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummary;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingMethodLabel;
use CetechDeliveryEngine\Application\Shipping\ShippingPackageBuilder;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Presentation\Email\CustomerOrderDeliveryEmailSummaryRenderer;
use CetechDeliveryEngine\Presentation\Frontend\CustomerOrderDeliverySummaryRenderer;
use CetechDeliveryEngine\Presentation\Frontend\ProductDeliverySelectorRenderer;
use CetechDeliveryEngine\Presentation\Shared\DeliveryPresentationLabels;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class Stage13FCustomerPresentationTest extends TestCase {

	public function test_compact_public_rows_exclude_fulfilment_method_and_charge(): void {
		$rows = DeliveryPresentationLabels::format_public_summary_rows(
			[
				'fulfilment_availability_label' => 'In warehouse',
				'fulfilment_choice_label'       => 'Delivery',
				'delivery_offer_public_label'   => 'FLAIROC QA Standard Delivery',
				'estimate_text'                 => 'Estimated 3–6 business days',
			],
			'delivery'
		);

		$keys = array_column( $rows, 'key' );

		self::assertSame(
			[
				'Delivery option',
				'Estimated delivery',
			],
			$keys
		);
		self::assertSame( 'FLAIROC QA Standard Delivery', $rows[0]['value'] );
		self::assertSame( '3–6 business days', $rows[1]['value'] );
		self::assertNotContains( 'Fulfilment', $keys );
		self::assertNotContains( 'Delivery method', $keys );
		self::assertNotContains( 'Delivery charge', $keys );
		self::assertNotContains( 'Shipping method', $keys );
	}

	public function test_pickup_compact_rows_include_useful_pickup_fields_only(): void {
		$rows = DeliveryPresentationLabels::format_public_summary_rows(
			[
				'fulfilment_availability_label' => 'In store',
				'fulfilment_choice_label'       => 'Store pickup',
				'delivery_offer_public_label'   => 'Counter pickup',
				'estimate_text'                 => 'Estimated 1–2 business days',
				'pickup_location_label'         => 'Main showroom',
				'pickup_address'                => '12 Market Street',
				'pickup_instructions'           => 'Ask for the sales desk',
			],
			'store_pickup'
		);

		$keys = array_column( $rows, 'key' );

		self::assertSame(
			[
				'Delivery option',
				'Ready for pickup',
				'Pickup location',
				'Pickup address',
				'Pickup instructions',
			],
			$keys
		);
		self::assertNotContains( 'Fulfilment', $keys );
		self::assertNotContains( 'Method', $keys );
		self::assertNotContains( 'Delivery charge', $keys );
	}

	public function test_product_estimate_line_is_labeled_hierarchy(): void {
		self::assertSame(
			'Estimated delivery: 3–6 business days',
			DeliveryPresentationLabels::format_product_estimate_line( 'Estimated 3–6 business days', 'delivery' )
		);
		self::assertSame(
			'Ready for pickup: 1–2 business days',
			DeliveryPresentationLabels::format_product_estimate_line( '1–2 business days', 'store_pickup' )
		);
	}

	public function test_product_selector_renderer_omits_description_and_fulfilment_headings(): void {
		$source = (string) file_get_contents(
			( new ReflectionClass( ProductDeliverySelectorRenderer::class ) )->getFileName()
		);

		self::assertStringContainsString( 'cetech-de-delivery-option__body', $source );
		self::assertStringContainsString( 'format_product_estimate_line', $source );
		self::assertStringNotContainsString( 'delivery_offer_public_description', $source );
		self::assertStringNotContainsString( 'fulfilment_availability_label', $source );
		self::assertStringNotContainsString( 'cetech-de-delivery-availability__heading', $source );
	}

	public function test_customer_order_renderer_is_compact_and_non_duplicative(): void {
		$renderer = new CustomerOrderDeliverySummaryRenderer(
			( new ReflectionClass( FeatureFlags::class ) )->newInstanceWithoutConstructor(),
			( new ReflectionClass( Requirements::class ) )->newInstanceWithoutConstructor(),
			( new ReflectionClass( \CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummaryBuilder::class ) )->newInstanceWithoutConstructor()
		);

		$method = new ReflectionMethod( $renderer, 'render_summary' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke(
			$renderer,
			new CustomerOrderDeliverySummary(
				[
					new CustomerOrderDeliveryLineSummary(
						'Variation A',
						'FLAIROC QA Standard Delivery',
						'In warehouse',
						'Delivery',
						'QA-only delivery option for Delivery Engine Stage 0B.',
						'Estimated 3–6 business days',
						'Delivery price confirmed',
						'$250.00',
						'2026-08-14 12:00'
					),
				],
				new CustomerOrderDeliveryPackageSummary( 'Delivery', '$250.00', '2026-08-14 12:00' )
			)
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Delivery details', $html );
		self::assertStringContainsString( 'Delivery option:', $html );
		self::assertStringContainsString( 'FLAIROC QA Standard Delivery', $html );
		self::assertStringContainsString( 'Estimated delivery:', $html );
		self::assertStringContainsString( '3–6 business days', $html );
		self::assertStringNotContainsString( 'Fulfilment', $html );
		self::assertStringNotContainsString( 'In warehouse', $html );
		self::assertStringNotContainsString( 'Delivery method', $html );
		self::assertStringNotContainsString( 'Shipping summary', $html );
		self::assertStringNotContainsString( 'Delivery charge', $html );
		self::assertStringNotContainsString( '$250.00', $html );
		self::assertStringNotContainsString( 'QA-only', $html );
	}

	public function test_customer_email_renderer_uses_same_compact_contract(): void {
		$renderer = new CustomerOrderDeliveryEmailSummaryRenderer(
			( new ReflectionClass( FeatureFlags::class ) )->newInstanceWithoutConstructor(),
			( new ReflectionClass( Requirements::class ) )->newInstanceWithoutConstructor(),
			( new ReflectionClass( \CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummaryBuilder::class ) )->newInstanceWithoutConstructor()
		);

		$method = new ReflectionMethod( $renderer, 'render_html_summary' );
		$method->setAccessible( true );

		$html = (string) $method->invoke(
			$renderer,
			new CustomerOrderDeliverySummary(
				[
					new CustomerOrderDeliveryLineSummary(
						'Variation A',
						'FLAIROC QA Standard Delivery',
						'In warehouse',
						'Delivery',
						'Internal description',
						'Estimated 3–6 business days',
						null,
						'$250.00',
						null
					),
				],
				new CustomerOrderDeliveryPackageSummary( 'Delivery', '$250.00', null )
			)
		);

		self::assertStringContainsString( 'Delivery option', $html );
		self::assertStringContainsString( 'Estimated delivery', $html );
		self::assertStringNotContainsString( 'Fulfilment', $html );
		self::assertStringNotContainsString( 'Shipping summary', $html );
		self::assertStringNotContainsString( 'Delivery charge', $html );
		self::assertStringNotContainsString( '$250.00', $html );
		self::assertStringNotContainsString( 'Internal description', $html );
	}

	public function test_cart_public_summary_helper_uses_compact_contract(): void {
		$rows = CartDeliverySelectionCapture::formatPublicSummaryRows(
			[
				'fulfilment_availability_label' => 'In warehouse',
				'fulfilment_choice_label'       => 'Delivery',
				'delivery_offer_public_label'   => 'FLAIROC QA Standard Delivery',
				'estimate_text'                 => 'Estimated 3–6 business days',
			],
			'delivery'
		);

		self::assertCount( 2, $rows );
		self::assertSame( 'Delivery option', $rows[0]['key'] );
		self::assertSame( 'Estimated delivery', $rows[1]['key'] );
	}

	public function test_shipping_rate_label_uses_public_option_label_safely(): void {
		$GLOBALS['cetech_de_test_options'] = [
			'cetech_de_enable_product_delivery_selector'              => 1,
			'cetech_de_enable_cart_delivery_selection_capture'        => 1,
			'cetech_de_enable_checkout_delivery_selection_validation' => 1,
			'cetech_de_enable_woocommerce_shipping_rate_calculation'  => 1,
		];

		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' );
		}

		$builder = new ShippingPackageBuilder(
			new ShippingRateCalculationGate( new FeatureFlags(), new Requirements() ),
			( new ReflectionClass( CartDeliverySelectionCapture::class ) )->newInstanceWithoutConstructor()
		);

		$item = [
			'product_id'   => 39717,
			'variation_id' => 39718,
			'quantity'     => 1,
			'line_total'   => 19.99,
			CartDeliverySelectionCapture::CART_SELECTION_KEY => [
				'contract_version'        => '1',
				'product_id'              => 39717,
				'variation_id'            => 39718,
				'target_type'             => 'variation',
				'target_id'               => 39718,
				'display_key'             => 'in_warehouse:delivery:1',
				'fulfilment_availability' => 'in_warehouse',
				'fulfilment_choice'       => 'delivery',
				'delivery_offer_id'       => 1,
				'rule_id'                 => null,
				'issued_at'               => '2026-08-14T00:00:00+00:00',
			],
			CartDeliverySelectionCapture::CART_SUMMARY_KEY => [
				'fulfilment_availability_label' => 'In warehouse',
				'fulfilment_choice_label'       => 'Delivery',
				'delivery_offer_public_label'   => 'FLAIROC QA Standard Delivery',
				'estimate_text'                 => '3–6 business days',
			],
			CartDeliverySelectionCapture::CART_HASH_KEY => 'hash',
		];

		$packages = $builder->filter_packages(
			[
				[
					'contents'      => [ 'line-a' => $item ],
					'contents_cost' => 19.99,
					'destination'   => [ 'country' => 'GH' ],
				],
			]
		);

		self::assertCount( 1, $packages );
		$meta = $packages[0][ DeliveryGroupIdentity::PACKAGE_META_KEY ];
		self::assertSame( 'FLAIROC QA Standard Delivery', $meta['rate_label'] );
		self::assertSame( 'Delivery', SelectedOfferShippingMethodLabel::default_delivery_label() );

		$shipping_method_source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Infrastructure/WooCommerce/Shipping/SelectedOfferShippingMethod.php'
		);
		self::assertStringContainsString( "METHOD_ID = 'delivery_engine_selected_offer'", $shipping_method_source );
		self::assertStringContainsString( 'label_for_package', $shipping_method_source );
	}

	public function test_snapshot_meta_keys_remain_immutable_contract(): void {
		self::assertSame( '_cetech_de_delivery_snapshot', OrderDeliverySnapshot::META_LINE_SNAPSHOT );
		self::assertSame( '_cetech_de_delivery_quote_snapshot', OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT );
		self::assertSame( '1', OrderDeliverySnapshot::VERSION );
	}

	public function test_variable_js_omits_description_from_compact_hierarchy(): void {
		$js = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/assets/frontend/variable-delivery-selector.js'
		);

		self::assertStringContainsString( 'cetech-de-delivery-option__body', $js );
		self::assertStringContainsString( 'estimatedDelivery', $js );
		self::assertStringContainsString( 'Compact product selector', $js );
		self::assertStringNotContainsString( 'delivery_offer_public_description', $js );
	}
}
