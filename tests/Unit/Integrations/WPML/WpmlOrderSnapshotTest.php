<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Integrations\WPML;

use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummaryBuilder;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotBuilder;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotIntegrity;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Integrations\WPML\WpmlDynamicStringTranslator;
use CetechDeliveryEngine\Integrations\WPML\WpmlLivePublicCopyPresenter;
use CetechDeliveryEngine\Integrations\WPML\WpmlPublicCopyCatalog;
use CetechDeliveryEngine\Integrations\WPML\WpmlPublicStringNames;
use CetechDeliveryEngine\Presentation\Email\CustomerOrderDeliveryEmailSummaryRenderer;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDeliveryOfferRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryPickupLocationRepository;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;

final class WpmlOrderSnapshotTest extends TestCase {

	private FakeWpmlStringTranslationApi $api;

	private WpmlPublicCopyCatalog $catalog;

	private InMemoryDeliveryOfferRepository $offers;

	private InMemoryPickupLocationRepository $pickups;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options'] = [
			'date_format' => 'Y-m-d',
			'time_format' => 'H:i',
			'cetech_de_enable_customer_order_delivery_summary' => 1,
			'cetech_de_enable_customer_email_delivery_summary' => 1,
		];
		$this->api     = new FakeWpmlStringTranslationApi();
		$this->api->wpml = true;
		$this->api->st   = true;
		$this->catalog = new WpmlPublicCopyCatalog( new WpmlDynamicStringTranslator( $this->api ) );
		$this->offers  = new InMemoryDeliveryOfferRepository();
		$this->pickups = new InMemoryPickupLocationRepository();
		$this->offers->seed(
			4,
			[
				'internal_code'      => 'air-int',
				'internal_name'      => 'AIR-INT-INTERNAL',
				'public_label'       => 'International Air',
				'public_description' => 'By air, 5–7 business days',
				'route'              => DeliveryRoute::Air->value,
				'status'             => RecordStatus::Active->value,
			]
		);
		$this->api->translations = [
			WpmlPublicStringNames::delivery_offer( 4, WpmlPublicStringNames::OFFER_PUBLIC_LABEL )       => 'Internationale Luftfracht',
			WpmlPublicStringNames::delivery_offer( 4, WpmlPublicStringNames::OFFER_PUBLIC_DESCRIPTION ) => 'Per Luft, 5–7 Werktage',
		];
	}

	public function test_order_created_in_translated_language_snapshots_translated_public_text(): void {
		$snapshot = $this->build_delivery_snapshot();
		$builder_source = (string) file_get_contents(
			dirname( __DIR__, 4 ) . '/src/Application/Order/OrderDeliverySnapshotBuilder.php'
		);

		self::assertNotNull( $snapshot );
		self::assertSame( 4, $snapshot->delivery_offer_id );
		self::assertSame( 'Internationale Luftfracht', $snapshot->delivery_offer_public_label );
		self::assertSame( 'Per Luft, 5–7 Werktage', $snapshot->delivery_offer_public_description );
		self::assertSame( 9, $snapshot->rate_card_id );
		self::assertSame( 'AIR-INT', $snapshot->rate_card_code );
		self::assertSame( 'USD', $snapshot->currency_code );
		self::assertSame( '50.0000', $snapshot->quoted_amount );
		self::assertSame( FulfilmentChoice::Delivery->value, $snapshot->fulfilment_choice );
		self::assertSame( DeliveryRoute::Air->value, (string) ( $this->offers->findById( 4 )['route'] ?? '' ) );
		self::assertStringContainsString( 'localize_summary', $builder_source );
		self::assertStringContainsString( 'translate_offer_description', $builder_source );
	}

	public function test_later_translation_edit_does_not_alter_historical_order_or_customer_surfaces(): void {
		$snapshot = $this->build_delivery_snapshot();
		self::assertNotNull( $snapshot );

		$item = new WC_Order_Item_Product(
			[
				'id'   => 88,
				'name' => 'QA Widget',
				'meta' => [
					OrderDeliverySnapshot::META_LINE_SNAPSHOT         => wp_json_encode( $snapshot->toArray() ),
					OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION => OrderDeliverySnapshot::VERSION,
				],
			]
		);
		$order = new WC_Order(
			[
				'id'    => 4401,
				'items' => [ $item ],
			]
		);

		$this->api->translations[ WpmlPublicStringNames::delivery_offer( 4, WpmlPublicStringNames::OFFER_PUBLIC_LABEL ) ] = 'Air Freight Tomorrow';
		$this->offers->save(
			array_merge(
				$this->offers->findById( 4 ) ?? [],
				[ 'id' => 4, 'public_label' => 'International Air Revised' ]
			)
		);

		$builder = new CustomerOrderDeliverySummaryBuilder(
			new OrderDeliverySnapshotReader(),
			new OrderDeliverySnapshotIntegrity()
		);
		$summary = $builder->build( $order );
		self::assertNotNull( $summary );
		self::assertSame( 'Internationale Luftfracht', $summary->lines[0]->delivery_option_label );
		self::assertSame( 'Per Luft, 5–7 Werktage', $summary->lines[0]->delivery_option_description );

		$flags = new FeatureFlags();
		$flags->set( 'enable_customer_email_delivery_summary', true );
		$renderer = new CustomerOrderDeliveryEmailSummaryRenderer( $flags, new Requirements(), $builder );
		ob_start();
		$renderer->render( $order, false, false );
		$email = (string) ob_get_clean();
		self::assertStringContainsString( 'Internationale Luftfracht', $email );
		self::assertStringNotContainsString( 'Air Freight Tomorrow', $email );
		self::assertStringNotContainsString( 'International Air Revised', $email );
	}

	private function build_delivery_snapshot(): \CetechDeliveryEngine\Application\Order\OrderDeliveryLineSnapshot {
		$intent = [
			'contract_version'        => '1',
			'product_id'              => 101,
			'variation_id'            => null,
			'target_type'             => 'product',
			'target_id'               => 101,
			'display_key'             => 'international_fulfilment:delivery:4',
			'fulfilment_availability' => FulfilmentAvailability::InternationalFulfilment->value,
			'fulfilment_choice'       => FulfilmentChoice::Delivery->value,
			'delivery_offer_id'       => 4,
			'rule_id'                 => 1,
			'issued_at'               => '2026-09-01T00:00:00+00:00',
		];
		$summary = [
			'delivery_offer_public_label' => 'International Air',
			'estimate_text'               => 'Estimated 5–7 business days',
		];
		$presenter = new WpmlLivePublicCopyPresenter( $this->catalog, $this->offers, $this->pickups );
		$summary   = $presenter->localize_summary( $summary, $intent );

		$builder = ( new \ReflectionClass( OrderDeliverySnapshotBuilder::class ) )->newInstanceWithoutConstructor();
		$reflection = new \ReflectionClass( $builder );
		foreach (
			[
				'delivery_offer_repository' => $this->offers,
				'wpml_presenter'            => $presenter,
			] as $name => $value
		) {
			$property = $reflection->getProperty( $name );
			$property->setValue( $builder, $value );
		}
		$describe = $reflection->getMethod( 'public_description' );
		$description = $describe->invoke( $builder, 4 );

		return new \CetechDeliveryEngine\Application\Order\OrderDeliveryLineSnapshot(
			'1',
			OrderDeliverySnapshot::VERSION,
			101,
			null,
			FulfilmentAvailability::InternationalFulfilment->value,
			FulfilmentChoice::Delivery->value,
			4,
			$summary['delivery_offer_public_label'] ?? null,
			is_string( $description ) ? $description : null,
			$summary['estimate_text'] ?? null,
			1,
			7,
			1,
			'USD',
			'50.0000',
			OrderDeliverySnapshot::QUOTE_STATUS_QUOTED,
			9,
			'AIR-INT',
			'2026-09-01T00:00:00+00:00'
		);
	}
}
