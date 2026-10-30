<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Selector;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Configuration\Admin\OfferEstimatedDeliveryDisplay;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultSummary;
use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Application\Runtime\EcrProductDeliveryConfigurationSource;
use CetechDeliveryEngine\Application\Runtime\EcrToRuntimeConfigurationAdapter;
use CetechDeliveryEngine\Application\Selector\DeliveryEstimatePrecedence;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Integrations\Blocks\BlocksPublicPayload;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDeliveryOfferRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryPickupLocationRepository;
use PHPUnit\Framework\TestCase;

/**
 * M2 ETA precedence: configured "Estimated delivery" text, else the offer's durations, computed once
 * and shown identically on every surface.
 */
final class DeliveryEstimatePrecedenceTest extends TestCase {

	private const OFFER_ESTIMATE = '2–4 business days';

	private InMemoryScopedConfigurationRepository $repository;
	private InMemoryDeliveryOfferRepository $offers;
	private InMemoryPickupLocationRepository $pickups;

	protected function setUp(): void {
		$this->repository = new InMemoryScopedConfigurationRepository();
		$this->offers     = new InMemoryDeliveryOfferRepository();
		$this->offers->seed( 7, $this->offer_row() );
		$this->pickups = new InMemoryPickupLocationRepository();
		$this->pickups->seed( 1, [ 'status' => RecordStatus::Active->value, 'location_name' => 'Main showroom', 'readiness_estimate' => 'Ready in 2 hours' ] );
	}

	public function test_configured_text_wins_over_offer_durations_and_blank_falls_back(): void {
		self::assertSame( 'Within 3 business days', DeliveryEstimatePrecedence::for_delivery( '  Within 3 business days ', $this->offer_row() ) );
		self::assertSame( self::OFFER_ESTIMATE, DeliveryEstimatePrecedence::for_delivery( '   ', $this->offer_row() ) );
		self::assertSame( self::OFFER_ESTIMATE, DeliveryEstimatePrecedence::for_delivery( null, $this->offer_row() ) );
		self::assertNull( DeliveryEstimatePrecedence::for_delivery( null, [ 'public_label' => 'No durations' ] ) );
		self::assertSame( 'Next business day', DeliveryEstimatePrecedence::for_delivery( 'Next business day', [ 'public_label' => 'No durations' ] ) );
	}

	public function test_builder_uses_the_configured_estimate_for_every_delivery_option_and_leaves_pickup_alone(): void {
		$builder = new ProductDeliveryOptionsBuilder( $this->offers, $this->pickups );

		$configured = $builder->buildFromResolution( $this->resolution( [ 7 ], 1, 'Within 3 business days' ) );
		self::assertSame( 'Within 3 business days', $configured[0]->estimate_text );
		self::assertSame( FulfilmentChoice::StorePickup->value, $configured[1]->fulfilment_choice );
		self::assertSame( 'Ready in 2 hours', $configured[1]->estimate_text, 'pickup keeps the location readiness text' );

		$fallback = $builder->buildFromResolution( $this->resolution( [ 7 ], 1, null ) );
		self::assertSame( self::OFFER_ESTIMATE, $fallback[0]->estimate_text );
	}

	public function test_runtime_adapter_carries_the_effective_configured_estimate_with_product_over_site_wide(): void {
		$this->save_global_in_store( [ 7 ], '3–6 business days' );
		$this->save_product_in_store( 101, null );

		$rule = $this->runtime_rule( 101 );
		self::assertSame( '3–6 business days', $rule->estimated_delivery, 'site-wide default of the fulfilment type reaches the runtime' );

		$this->save_product_in_store( 101, 'Next business day' );
		self::assertSame( 'Next business day', $this->runtime_rule( 101 )->estimated_delivery, 'product override wins' );
		// A blank override cannot be stored: ConfigurationFieldRegistry rejects it at write time.
	}

	public function test_runtime_adapter_leaves_the_estimate_null_when_nothing_is_configured(): void {
		$this->save_global_in_store( [ 7 ], null );
		$this->save_product_in_store( 101, null );

		$rule = $this->runtime_rule( 101 );
		self::assertNull( $rule->estimated_delivery );

		$option = ( new ProductDeliveryOptionsBuilder( $this->offers, $this->pickups ) )->buildFromResolution(
			$this->resolution( $rule->delivery_offer_ids, null, $rule->estimated_delivery )
		)[0];
		self::assertSame( self::OFFER_ESTIMATE, $option->estimate_text, 'offer durations remain the fallback' );
	}

	public function test_resolved_rule_round_trips_the_estimate(): void {
		$rule = new ResolvedProductDeliveryRule( 1, ProductTargetType::Product->value, 101, null, 2, FulfilmentAvailability::InStore->value, FulfilmentChoice::Delivery->value, [ 7 ], null, null, null, 100, null, 'Within 3 business days' );

		self::assertSame( 'Within 3 business days', ResolvedProductDeliveryRule::fromArray( $rule->toArray() )->estimated_delivery );
		self::assertNull( ResolvedProductDeliveryRule::fromArray( [ 'estimated_delivery' => '  ' ] )->estimated_delivery );
		self::assertNull( ResolvedProductDeliveryRule::from_row( [ 'id' => 1 ], [], 2 )->estimated_delivery, 'legacy rows carry no estimate' );
	}

	public function test_site_wide_summary_shows_the_same_text_as_the_customer(): void {
		$this->save_global_in_store( [ 7 ], null );
		$summary = new SiteWideDefaultSummary( $this->repository, $this->offers );
		self::assertSame( self::OFFER_ESTIMATE, $summary->for_profile( FulfilmentAvailability::InStore->value )['estimated_delivery'] );

		$this->save_global_in_store( [ 7 ], '3–6 business days' );
		self::assertSame( '3–6 business days', $summary->for_profile( FulfilmentAvailability::InStore->value )['estimated_delivery'] );
	}

	public function test_offer_list_shows_durations_before_free_text_service_levels(): void {
		self::assertSame( self::OFFER_ESTIMATE, OfferEstimatedDeliveryDisplay::label( $this->offer_row() + [ 'service_level' => '3–5 days' ] ) );
		self::assertSame( '3–5 days', OfferEstimatedDeliveryDisplay::label( [ 'service_level' => '3–5 days' ] ) );
		self::assertSame( '—', OfferEstimatedDeliveryDisplay::label( [ 'service_level' => 'standard' ] ) );
	}

	public function test_blocks_payload_strips_a_historical_estimated_prefix_like_the_other_surfaces(): void {
		$payload = BlocksPublicPayload::cart_item(
			[
				'product_id'   => 11,
				'variation_id' => 0,
				CartDeliverySelectionCapture::CART_SELECTION_KEY => [
					'contract_version'        => '1',
					'product_id'              => 11,
					'variation_id'            => null,
					'target_type'             => 'product',
					'target_id'               => 11,
					'display_key'             => 'in_store:delivery:44',
					'fulfilment_availability' => 'in_store',
					'fulfilment_choice'       => 'delivery',
					'delivery_offer_id'       => 44,
					'rule_id'                 => null,
					'issued_at'               => '2026-08-31T00:00:00+00:00',
				],
				CartDeliverySelectionCapture::CART_SUMMARY_KEY => [
					'delivery_offer_public_label' => 'Standard Delivery',
					'estimate_text'               => 'Estimated delivery: 2–4 business days',
					'pickup_location_label'       => null,
					'pickup_address'              => null,
					'pickup_instructions'         => null,
				],
			]
		);

		self::assertSame( self::OFFER_ESTIMATE, $payload['estimate_text'] );

		$renderer = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Frontend/CartDeliveryReselectionRenderer.php' );
		self::assertStringContainsString( 'DeliveryPresentationLabels::strip_estimated_prefix( (string) $option->estimate_text )', $renderer );
	}

	// ---- helpers --------------------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	private function offer_row(): array {
		return [
			'status'                 => 'active',
			'public_label'           => 'Standard Delivery',
			'internal_name'          => 'Standard Delivery',
			'route'                  => DeliveryRoute::LocalDelivery->value,
			'duration_unit'          => 'business_days',
			'default_processing_min' => 1,
			'default_processing_max' => 1,
			'default_transit_min'    => 1,
			'default_transit_max'    => 2,
			'default_final_mile_min' => 0,
			'default_final_mile_max' => 1,
		];
	}

	/**
	 * @param list<int> $offer_ids
	 */
	private function resolution( array $offer_ids, ?int $pickup_location_id, ?string $estimated_delivery ): ProductRuleResolutionResult {
		$rule = new ResolvedProductDeliveryRule( 1, ProductTargetType::Product->value, 101, null, 2, FulfilmentAvailability::InStore->value, FulfilmentChoice::Delivery->value, $offer_ids, null, null, null, 100, $pickup_location_id, $estimated_delivery );

		return new ProductRuleResolutionResult( true, null, ProductTargetType::Product->value, 101, null, [], '', [ $rule ], [ FulfilmentAvailability::InStore->value => $rule ], [], [], [], null );
	}

	/**
	 * @param list<int> $offer_ids
	 */
	private function save_global_in_store( array $offer_ids, ?string $estimate ): void {
		$scalars = [
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_AVAILABILITY, FulfilmentAvailability::InStore->value ),
			ConfigurationFieldKey::FULFILMENT_CHOICE       => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_CHOICE, FulfilmentChoice::Delivery->value ),
			ConfigurationFieldKey::PRIORITY                => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 100 ),
		];
		if ( null !== $estimate ) {
			$scalars[ ConfigurationFieldKey::ESTIMATED_DELIVERY ] = ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, $estimate );
		}
		$this->repository->ensureGlobalScope();
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Global, ConfigurationScope::GLOBAL_SCOPE_ID, FulfilmentAvailability::InStore->value, null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				$scalars,
				[ ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, $offer_ids ) ]
			)
		);
	}

	/**
	 * The product's in_store slice: everything inherited from the site-wide defaults of the
	 * fulfilment type, except an optional Estimated delivery override.
	 */
	private function save_product_in_store( int $product_id, ?string $estimate ): void {
		$existing = $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, $product_id, FulfilmentAvailability::InStore->value );
		$scope    = $existing?->scope ?? new ConfigurationScope( null, ConfigurationScopeType::Product, $product_id, FulfilmentAvailability::InStore->value, null, RecordStatus::Active, 1, ConfigurationSource::Native, null );

		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				$scope,
				[
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::inherit( ConfigurationFieldKey::FULFILMENT_AVAILABILITY ),
					ConfigurationFieldKey::FULFILMENT_CHOICE       => ScalarFieldInstruction::inherit( ConfigurationFieldKey::FULFILMENT_CHOICE ),
					ConfigurationFieldKey::PRIORITY                => ScalarFieldInstruction::inherit( ConfigurationFieldKey::PRIORITY ),
					ConfigurationFieldKey::ESTIMATED_DELIVERY      => null === $estimate
						? ScalarFieldInstruction::inherit( ConfigurationFieldKey::ESTIMATED_DELIVERY )
						: ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, $estimate ),
				],
				[ ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::inherit( ConfigurationFieldKey::DELIVERY_OFFER_IDS ) ]
			)
		);
	}

	private function runtime_rule( int $product_id ): ResolvedProductDeliveryRule {
		$source = new EcrProductDeliveryConfigurationSource(
			new EffectiveConfigurationResolver( $this->repository, new EffectiveConfigurationValidator(), new HardFulfilmentConstraintService( $this->offers, $this->pickups ) ),
			new EcrToRuntimeConfigurationAdapter()
		);
		$runtime = $source->resolve( ProductTargetType::Product->value, $product_id );

		self::assertTrue( $runtime->result->success );
		self::assertArrayHasKey( FulfilmentAvailability::InStore->value, $runtime->result->chosen_rules );

		return $runtime->result->chosen_rules[ FulfilmentAvailability::InStore->value ];
	}
}
