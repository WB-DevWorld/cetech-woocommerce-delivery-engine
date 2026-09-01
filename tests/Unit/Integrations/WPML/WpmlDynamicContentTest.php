<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Integrations\WPML;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionFingerprint;
use CetechDeliveryEngine\Application\Checkout\CheckoutDeliverySelectionValidator;
use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Application\Shipping\ShippingPackageBuilder;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Integrations\Blocks\BlocksCheckoutAdapter;
use CetechDeliveryEngine\Integrations\Blocks\BlocksPublicPayload;
use CetechDeliveryEngine\Integrations\Blocks\BlocksUsageDetector;
use CetechDeliveryEngine\Integrations\Registry\IntegrationRegistry;
use CetechDeliveryEngine\Integrations\Status\IntegrationStatus;
use CetechDeliveryEngine\Integrations\Status\IntegrationStatusCatalog;
use CetechDeliveryEngine\Integrations\WPML\WpmlDynamicStringTranslator;
use CetechDeliveryEngine\Integrations\WPML\WpmlLivePublicCopyPresenter;
use CetechDeliveryEngine\Integrations\WPML\WpmlPublicCopyCatalog;
use CetechDeliveryEngine\Integrations\WPML\WpmlPublicCopySync;
use CetechDeliveryEngine\Integrations\WPML\WpmlPublicStringNames;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDeliveryOfferRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryPickupLocationRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class WpmlDynamicContentTest extends TestCase {

	private FakeWpmlStringTranslationApi $api;

	private WpmlPublicCopyCatalog $catalog;

	private InMemoryDeliveryOfferRepository $offers;

	private InMemoryPickupLocationRepository $pickups;

	private InMemoryDestinationZoneRepository $zones;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options'] = [];
		$this->api                         = new FakeWpmlStringTranslationApi();
		$this->catalog                     = new WpmlPublicCopyCatalog( new WpmlDynamicStringTranslator( $this->api ) );
		$this->offers                      = new InMemoryDeliveryOfferRepository();
		$this->pickups                     = new InMemoryPickupLocationRepository();
		$this->zones                       = new InMemoryDestinationZoneRepository();
		$this->seed_canonical();
	}

	public function test_wpml_absent_returns_canonical_source_and_does_not_register(): void {
		$translated = $this->catalog->translate_delivery_offer_label( 4, 'International Air' );

		self::assertSame( 'International Air', $translated );
		$this->catalog->register_delivery_offer( $this->offers->findById( 4 ) ?? [] );
		self::assertSame( [], $this->api->registered );
	}

	public function test_wpml_present_without_string_translation_returns_source(): void {
		$this->api->wpml = true;
		$this->api->st   = false;

		self::assertSame( 'International Air', $this->catalog->translate_delivery_offer_label( 4, 'International Air' ) );
		$this->catalog->register_delivery_offer( $this->offers->findById( 4 ) ?? [] );
		self::assertSame( [], $this->api->registered );
	}

	public function test_supported_public_fields_translate_and_internal_fields_do_not(): void {
		$this->enable_translations();

		self::assertSame( 'Internationale Luftfracht', $this->catalog->translate_delivery_offer_label( 4, 'International Air' ) );
		self::assertSame( 'Per Luft, 5–7 Werktage', $this->catalog->translate_delivery_offer_description( 4, 'By air, 5–7 business days' ) );
		self::assertSame( 'Hauptausstellung', $this->catalog->translate_pickup_location_name( 2, 'Main showroom' ) );
		self::assertSame( 'Mo–Fr 09:00–17:00', $this->catalog->translate_pickup_opening_hours( 2, 'Mon–Fri 09:00–17:00' ) );
		self::assertSame( 'Bestellnummer mitbringen', $this->catalog->translate_pickup_instructions( 2, 'Bring your order number' ) );
		self::assertSame( 'In 2 Stunden bereit', $this->catalog->translate_pickup_readiness( 2, 'Ready in 2 hours' ) );
		self::assertSame( 'Großraum Accra', $this->catalog->translate_destination_zone_label( 7, 'Greater Accra' ) );

		$row = $this->offers->findById( 4 );
		self::assertIsArray( $row );
		self::assertSame( 'air-int', $row['internal_code'] );
		self::assertSame( 'AIR-INT-INTERNAL', $row['internal_name'] );
		self::assertSame( DeliveryRoute::Air->value, $row['route'] );
		self::assertSame( 4, (int) $row['id'] );
		self::assertArrayNotHasKey( WpmlPublicStringNames::delivery_offer( 4, 'internal_code' ), $this->api->translations );
		self::assertArrayNotHasKey( WpmlPublicStringNames::delivery_offer( 4, 'internal_name' ), $this->api->translations );
		self::assertArrayNotHasKey( WpmlPublicStringNames::delivery_offer( 4, 'route' ), $this->api->translations );
	}

	public function test_missing_translation_falls_back_to_source_and_blank_stays_blank(): void {
		$this->api->wpml = true;
		$this->api->st   = true;

		self::assertSame( 'International Air', $this->catalog->translate_delivery_offer_label( 4, 'International Air' ) );
		self::assertSame( '', $this->catalog->translate_delivery_offer_description( 4, '' ) );
		self::assertSame( '', $this->catalog->translate_pickup_instructions( 2, '   ' ) );
	}

	public function test_updated_source_text_is_reregistered_and_sync_is_idempotent(): void {
		$this->api->wpml = true;
		$this->api->st   = true;

		$this->catalog->register_delivery_offer( $this->offers->findById( 4 ) ?? [] );
		self::assertSame(
			'International Air',
			$this->api->registered[ WpmlPublicStringNames::delivery_offer( 4, WpmlPublicStringNames::OFFER_PUBLIC_LABEL ) ]
		);

		$this->offers->save(
			array_merge(
				$this->offers->findById( 4 ) ?? [],
				[ 'id' => 4, 'public_label' => 'International Air Express' ]
			)
		);
		$this->catalog->register_delivery_offer( $this->offers->findById( 4 ) ?? [] );
		self::assertSame(
			'International Air Express',
			$this->api->registered[ WpmlPublicStringNames::delivery_offer( 4, WpmlPublicStringNames::OFFER_PUBLIC_LABEL ) ]
		);

		$sync = new WpmlPublicCopySync( $this->catalog, $this->offers, $this->pickups, $this->zones );
		$first  = $sync->sync_all();
		$second = $sync->sync_all();
		self::assertSame( $first, $second );
		self::assertGreaterThan( 0, $first );
	}

	public function test_sync_is_safe_when_string_translation_unavailable(): void {
		$sync = new WpmlPublicCopySync( $this->catalog, $this->offers, $this->pickups, $this->zones );
		$sync->maybe_sync();
		self::assertFalse( isset( $GLOBALS['cetech_de_test_options'][ WpmlPublicCopySync::OPTION_NAME ] ) );
		self::assertSame( [], $this->api->registered );
	}

	public function test_pdp_cart_classic_and_blocks_resolve_the_same_current_language_strings(): void {
		$this->enable_translations();
		$builder   = new ProductDeliveryOptionsBuilder( $this->offers, $this->pickups, $this->catalog );
		$presenter = new WpmlLivePublicCopyPresenter( $this->catalog, $this->offers, $this->pickups );

		$options = $builder->buildFromResolution(
			$this->resolution( FulfilmentAvailability::InternationalFulfilment->value, FulfilmentChoice::Delivery->value, [ 4 ] )
		);
		self::assertCount( 1, $options );
		self::assertSame( 4, $options[0]->delivery_offer_id );
		self::assertSame( 'Internationale Luftfracht', $options[0]->delivery_offer_public_label );
		self::assertSame( 'Per Luft, 5–7 Werktage', $options[0]->delivery_offer_public_description );
		self::assertSame( DeliveryRoute::Air->value, (string) ( $this->offers->findById( 4 )['route'] ?? '' ) );

		$intent  = $this->delivery_intent();
		$summary = [
			'delivery_offer_public_label' => 'International Air',
			'estimate_text'               => 'Estimated 5–7 business days',
			'pickup_location_label'       => null,
			'pickup_address'              => null,
			'pickup_instructions'         => null,
			'pickup_location_id'          => null,
		];
		$live = $presenter->localize_summary( $summary, $intent );
		self::assertSame( 'Internationale Luftfracht', $live['delivery_offer_public_label'] );

		$cart_item = [
			'product_id'                                     => 101,
			CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent,
			CartDeliverySelectionCapture::CART_SUMMARY_KEY   => $summary,
		];
		$blocks = BlocksPublicPayload::cart_item( $cart_item, null, null, '', $presenter );
		self::assertSame( 'Internationale Luftfracht', $blocks['delivery_option_label'] );
		self::assertArrayNotHasKey( 'delivery_offer_id', $blocks );
		self::assertArrayNotHasKey( 'pickup_location_id', $blocks );

		$package_builder = new ShippingPackageBuilder(
			new ShippingRateCalculationGate( new FeatureFlags(), new Requirements() ),
			( new ReflectionClass( CartDeliverySelectionCapture::class ) )->newInstanceWithoutConstructor(),
			$presenter
		);
		$method = new \ReflectionMethod( ShippingPackageBuilder::class, 'build_managed_package' );
		$package = $method->invoke(
			$package_builder,
			[ 'contents' => [] ],
			[ 'abc' => $cart_item ],
			DeliveryGroupIdentity::fromIntent( $intent ) ?? 'international_fulfilment|delivery|4'
		);
		$meta = $package[ DeliveryGroupIdentity::PACKAGE_META_KEY ];
		self::assertSame( 'Internationale Luftfracht', $meta['offer_public_label'] );
		self::assertSame( 4, $meta['delivery_offer_id'] );
	}

	public function test_pickup_pdp_and_cart_share_translated_public_copy_without_translating_address(): void {
		$this->enable_translations();
		$builder   = new ProductDeliveryOptionsBuilder( $this->offers, $this->pickups, $this->catalog );
		$presenter = new WpmlLivePublicCopyPresenter( $this->catalog, $this->offers, $this->pickups );
		$options   = $builder->buildFromResolution(
			$this->resolution(
				FulfilmentAvailability::InStore->value,
				FulfilmentChoice::StorePickup->value,
				[],
				2
			)
		);

		self::assertSame( 'Hauptausstellung', $options[0]->pickup_location_label );
		self::assertSame( 'Bestellnummer mitbringen', $options[0]->pickup_instructions );
		self::assertSame( 'In 2 Stunden bereit', $options[0]->estimate_text );
		self::assertSame( 2, $options[0]->pickup_location_id );
		self::assertSame( '12 Harbour Street', $options[0]->pickup_address );

		$summary = CartDeliverySelectionCapture::buildPublicSummary( $options[0]->toArray() );
		self::assertSame( '2', $summary['pickup_location_id'] );
		$live = $presenter->localize_summary( $summary, $this->pickup_intent() );
		self::assertSame( 'Hauptausstellung', $live['pickup_location_label'] );
		self::assertSame( '12 Harbour Street', $live['pickup_address'] );
	}

	public function test_language_switch_changes_live_pre_order_copy_only(): void {
		$this->enable_translations();
		$presenter = new WpmlLivePublicCopyPresenter( $this->catalog, $this->offers, $this->pickups );
		$summary   = [
			'delivery_offer_public_label' => 'International Air',
			'estimate_text'               => 'Estimated 5–7 business days',
		];
		$first = $presenter->localize_summary( $summary, $this->delivery_intent() );
		self::assertSame( 'Internationale Luftfracht', $first['delivery_offer_public_label'] );

		$this->api->translations[ WpmlPublicStringNames::delivery_offer( 4, WpmlPublicStringNames::OFFER_PUBLIC_LABEL ) ] = 'International Air (AU)';
		$second = $presenter->localize_summary( $summary, $this->delivery_intent() );
		self::assertSame( 'International Air (AU)', $second['delivery_offer_public_label'] );
		self::assertSame( 'International Air', (string) ( $this->offers->findById( 4 )['public_label'] ?? '' ) );
	}

	public function test_deleted_entity_does_not_blank_stored_summary(): void {
		$this->enable_translations();
		$presenter = new WpmlLivePublicCopyPresenter( $this->catalog, $this->offers, $this->pickups );
		$this->offers->hardDelete( 4 );

		$live = $presenter->localize_summary(
			[ 'delivery_offer_public_label' => 'International Air' ],
			$this->delivery_intent()
		);
		self::assertSame( 'International Air', $live['delivery_offer_public_label'] );
	}

	public function test_cart_identity_and_grouping_ignore_translated_labels(): void {
		$intent = $this->delivery_intent();
		$hash   = CartDeliverySelectionFingerprint::fromIntent( $intent );
		$group  = DeliveryGroupIdentity::fromIntent( $intent );

		$translated_intent = $intent;
		$translated_intent['delivery_offer_public_label'] = 'Internationale Luftfracht';
		self::assertSame( $hash, CartDeliverySelectionFingerprint::fromIntent( $translated_intent ) );
		self::assertSame( $group, DeliveryGroupIdentity::fromIntent( $translated_intent ) );
	}

	public function test_store_pickup_charge_zero_and_checkout_validator_has_no_wpml_dependency(): void {
		$package = [
			DeliveryGroupIdentity::PACKAGE_META_KEY => [
				'managed'   => true,
				'is_pickup' => true,
			],
		];
		$payload = BlocksPublicPayload::package( $package, 0 );
		self::assertTrue( $payload['charge_is_zero'] );

		$source = (string) file_get_contents(
			dirname( __DIR__, 4 ) . '/src/Application/Checkout/CheckoutDeliverySelectionValidator.php'
		);
		self::assertStringNotContainsString( 'Wpml', $source );
		self::assertStringNotContainsString( 'wpml_translate_single_string', $source );
		self::assertTrue( class_exists( CheckoutDeliverySelectionValidator::class ) );
	}

	public function test_international_air_and_warehouse_local_constraints_unchanged_with_translator(): void {
		$this->enable_translations();
		$builder = new ProductDeliveryOptionsBuilder( $this->offers, $this->pickups, $this->catalog );

		$international = $builder->buildFromResolution(
			$this->resolution( FulfilmentAvailability::InternationalFulfilment->value, FulfilmentChoice::Delivery->value, [ 4 ] )
		);
		self::assertSame( [ FulfilmentChoice::Delivery->value ], array_map( static fn ( $o ) => $o->fulfilment_choice, $international ) );
		self::assertSame( [ 4 ], array_map( static fn ( $o ) => $o->delivery_offer_id, $international ) );

		$warehouse = $builder->buildFromResolution(
			$this->resolution( FulfilmentAvailability::InWarehouse->value, FulfilmentChoice::Delivery->value, [ 11 ] )
		);
		self::assertSame( [ FulfilmentChoice::Delivery->value ], array_map( static fn ( $o ) => $o->fulfilment_choice, $warehouse ) );
		self::assertSame( [ 11 ], array_map( static fn ( $o ) => $o->delivery_offer_id, $warehouse ) );
		self::assertSame( 'Warehouse Local', $warehouse[0]->delivery_offer_public_label );
	}

	public function test_schema_remains_five_and_translator_ignores_legacy_feature_flag(): void {
		self::assertSame( '5', SchemaVersion::TARGET );
		$source = (string) file_get_contents(
			dirname( __DIR__, 4 ) . '/src/Integrations/WPML/WpmlDynamicStringTranslator.php'
		);
		self::assertStringNotContainsString( "is_enabled( 'enable_wpml_adapter' )", $source );
	}

	public function test_status_catalog_is_truthful(): void {
		$catalog = new IntegrationStatusCatalog(
			new IntegrationRegistry( new Logger() ),
			new BlocksUsageDetector(),
			( new ReflectionClass( BlocksCheckoutAdapter::class ) )->newInstanceWithoutConstructor()
		);
		$absent = $catalog->wpml();
		self::assertSame( IntegrationStatus::STATE_NOT_INSTALLED, $absent->state );
		self::assertFalse( $absent->adapter_implemented );
		self::assertStringContainsString( 'not installed', strtolower( $absent->detail ) );

		$st_missing = new IntegrationStatusCatalog(
			new IntegrationRegistry( new Logger() ),
			new BlocksUsageDetector(),
			( new ReflectionClass( BlocksCheckoutAdapter::class ) )->newInstanceWithoutConstructor(),
			new WpmlDynamicStringTranslator( $this->with_wpml_only() )
		);
		$detected = $st_missing->wpml();
		self::assertSame( IntegrationStatus::STATE_DETECTED, $detected->state );
		self::assertFalse( $detected->adapter_implemented );
		self::assertStringContainsString( 'String Translation is unavailable', $detected->detail );

		$supported = new IntegrationStatusCatalog(
			new IntegrationRegistry( new Logger() ),
			new BlocksUsageDetector(),
			( new ReflectionClass( BlocksCheckoutAdapter::class ) )->newInstanceWithoutConstructor(),
			new WpmlDynamicStringTranslator( $this->with_st() )
		);
		$status = $supported->wpml();
		self::assertSame( IntegrationStatus::STATE_SUPPORTED, $status->state );
		self::assertTrue( $status->adapter_implemented );
		self::assertStringContainsString( 'WPML String Translation', $status->detail );
		self::assertStringContainsString( 'does not mean translations have been entered', $status->detail );
	}

	public function test_registration_does_not_expose_internal_name_or_code(): void {
		$this->api->wpml = true;
		$this->api->st   = true;
		$this->catalog->register_delivery_offer( $this->offers->findById( 4 ) ?? [] );
		$this->catalog->register_pickup_location( $this->pickups->findById( 2 ) ?? [] );
		$this->catalog->register_destination_zone( $this->zones->findById( 7 ) ?? [] );

		foreach ( array_keys( $this->api->registered ) as $name ) {
			self::assertStringNotContainsString( 'internal_code', $name );
			self::assertStringNotContainsString( 'internal_name', $name );
			self::assertStringNotContainsString( 'public_address', $name );
			self::assertStringNotContainsString( 'route', $name );
		}
	}

	private function enable_translations(): void {
		$this->api->wpml = true;
		$this->api->st   = true;
		$this->api->translations = [
			WpmlPublicStringNames::delivery_offer( 4, WpmlPublicStringNames::OFFER_PUBLIC_LABEL )       => 'Internationale Luftfracht',
			WpmlPublicStringNames::delivery_offer( 4, WpmlPublicStringNames::OFFER_PUBLIC_DESCRIPTION ) => 'Per Luft, 5–7 Werktage',
			WpmlPublicStringNames::pickup_location( 2, WpmlPublicStringNames::PICKUP_LOCATION_NAME )    => 'Hauptausstellung',
			WpmlPublicStringNames::pickup_location( 2, WpmlPublicStringNames::PICKUP_OPENING_HOURS )    => 'Mo–Fr 09:00–17:00',
			WpmlPublicStringNames::pickup_location( 2, WpmlPublicStringNames::PICKUP_INSTRUCTIONS )     => 'Bestellnummer mitbringen',
			WpmlPublicStringNames::pickup_location( 2, WpmlPublicStringNames::PICKUP_READINESS )        => 'In 2 Stunden bereit',
			WpmlPublicStringNames::destination_zone( 7, WpmlPublicStringNames::ZONE_PUBLIC_LABEL )      => 'Großraum Accra',
		];
	}

	private function with_wpml_only(): FakeWpmlStringTranslationApi {
		$api       = new FakeWpmlStringTranslationApi();
		$api->wpml = true;
		$api->st   = false;

		return $api;
	}

	private function with_st(): FakeWpmlStringTranslationApi {
		$api       = new FakeWpmlStringTranslationApi();
		$api->wpml = true;
		$api->st   = true;

		return $api;
	}

	private function seed_canonical(): void {
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
		$this->offers->seed(
			11,
			[
				'internal_code'      => 'local-wh',
				'internal_name'      => 'LOCAL-WH-INTERNAL',
				'public_label'       => 'Warehouse Local',
				'public_description' => '',
				'route'              => DeliveryRoute::LocalDelivery->value,
				'status'             => RecordStatus::Active->value,
			]
		);
		$this->pickups->seed(
			2,
			[
				'internal_code'              => 'showroom-accra',
				'location_name'              => 'Main showroom',
				'public_address'             => '12 Harbour Street',
				'public_opening_hours'       => 'Mon–Fri 09:00–17:00',
				'public_pickup_instructions' => 'Bring your order number',
				'readiness_estimate'         => 'Ready in 2 hours',
				'status'                     => RecordStatus::Active->value,
			]
		);
		$this->zones->save(
			[
				'id'            => 7,
				'internal_code' => 'accra-metro',
				'internal_name' => 'ACCRA-METRO-INTERNAL',
				'public_label'  => 'Greater Accra',
				'status'        => RecordStatus::Active->value,
			]
		);
	}

	/**
	 * @param list<int> $offer_ids
	 */
	private function resolution( string $availability, string $choice, array $offer_ids, ?int $pickup_location_id = null ): ProductRuleResolutionResult {
		$rule = new ResolvedProductDeliveryRule(
			1,
			ProductTargetType::Product->value,
			101,
			null,
			2,
			$availability,
			$choice,
			$offer_ids,
			null,
			null,
			null,
			100,
			$pickup_location_id
		);

		return new ProductRuleResolutionResult(
			true,
			null,
			ProductTargetType::Product->value,
			101,
			null,
			[],
			'',
			[ $rule ],
			[ $availability => $rule ],
			[],
			[],
			[],
			null
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function delivery_intent(): array {
		return [
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
	}

	/**
	 * @return array<string, mixed>
	 */
	private function pickup_intent(): array {
		return [
			'contract_version'        => '1',
			'product_id'              => 101,
			'variation_id'            => null,
			'target_type'             => 'product',
			'target_id'               => 101,
			'display_key'             => 'in_store:store_pickup:pickup',
			'fulfilment_availability' => FulfilmentAvailability::InStore->value,
			'fulfilment_choice'       => FulfilmentChoice::StorePickup->value,
			'delivery_offer_id'       => null,
			'rule_id'                 => 1,
			'issued_at'               => '2026-09-01T00:00:00+00:00',
		];
	}
}
