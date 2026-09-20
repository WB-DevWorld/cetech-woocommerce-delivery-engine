<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Diagnostics;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidator;
use CetechDeliveryEngine\Application\Checkout\CheckoutDeliverySelectionValidator;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummaryBuilder;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotGate;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotIntegrity;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotPersister;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Presentation\Admin\OrderDeliverySnapshotAdminDisplay;
use CetechDeliveryEngine\Presentation\Email\CustomerOrderDeliveryEmailSummaryRenderer;
use CetechDeliveryEngine\Presentation\Frontend\CustomerOrderDeliverySummaryRenderer;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Destination\OverlappingDeliveryAreaCoverage;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolver;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionIntent;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidator;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleMatchMode;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbProductDeliveryRuleRepository;
use CetechDeliveryEngine\Presentation\Admin\FeatureFlagLabels;
use CetechDeliveryEngine\Presentation\Admin\ProductTargetResolver;

/**
 * Read-only configuration health diagnostics for wp-admin use only.
 *
 * Does not write configuration data or expose customer/order/payment information.
 */
final class ConfigurationHealthChecker {

	private const LIST_LIMIT = 500;

	public function __construct(
		private LogisticsProfileRepositoryInterface $logistics_profile_repository,
		private DeliveryOfferRepositoryInterface $delivery_offer_repository,
		private DestinationZoneRepositoryInterface $destination_zone_repository,
		private DestinationRuleRepositoryInterface $destination_rule_repository,
		private PickupLocationRepositoryInterface $pickup_location_repository,
		private SupplierRepositoryInterface $supplier_repository,
		private OriginRepositoryInterface $origin_repository,
		private RateCardRepositoryInterface $rate_card_repository,
		private ProductDeliveryRuleRepositoryInterface $product_rule_repository,
		private ProductTargetResolver $target_resolver,
		private FeatureFlags $feature_flags,
		private ?\CetechDeliveryEngine\Domain\Coverage\CoverageGroupRepositoryInterface $coverage_groups = null
	) {
	}

	/**
	 * @return array{
	 *     diagnostics: list<ConfigurationDiagnostic>,
	 *     summary: array{error: int, warning: int, info: int}
	 * }
	 */
	public function run(): array {
		$diagnostics = [];

		$this->check_baseline( $diagnostics );
		$this->check_schema( $diagnostics );
		$this->check_destinations( $diagnostics );
		$this->check_suppliers_origins( $diagnostics );
		$this->check_rate_cards( $diagnostics );
		$this->check_product_rules( $diagnostics );
		$this->check_product_delivery_selector( $diagnostics );
		$this->check_cart_delivery_selection_capture( $diagnostics );
		$this->check_checkout_delivery_selection_validation( $diagnostics );
		$this->check_rate_quote( $diagnostics );
		$this->check_woocommerce_shipping_rate_calculation( $diagnostics );
		$this->check_order_delivery_snapshot_persistence( $diagnostics );
		$this->check_customer_order_delivery_summary( $diagnostics );
		$this->check_customer_email_delivery_summary( $diagnostics );
		$this->check_privacy( $diagnostics );

		return [
			'diagnostics' => $diagnostics,
			'summary'     => $this->summarize( $diagnostics ),
		];
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 */
	private function check_baseline( array &$diagnostics ): void {
		$checks = [
			'zero_logistics_profiles' => [
				'count' => $this->logistics_profile_repository->count_all(),
				'title' => __( 'No logistics profiles', 'cetech-woocommerce-delivery-engine' ),
				'message' => __( 'No logistics profiles are configured.', 'cetech-woocommerce-delivery-engine' ),
				'entity'  => 'logistics_profile',
			],
			'zero_delivery_offers' => [
				'count' => $this->delivery_offer_repository->count_all(),
				'title' => __( 'No delivery offers', 'cetech-woocommerce-delivery-engine' ),
				'message' => __( 'No delivery offers are configured.', 'cetech-woocommerce-delivery-engine' ),
				'entity'  => 'delivery_offer',
			],
			'zero_destination_zones' => [
				'count' => $this->destination_zone_repository->count_all(),
				'title' => __( 'No destination zones', 'cetech-woocommerce-delivery-engine' ),
				'message' => __( 'No destination zones are configured.', 'cetech-woocommerce-delivery-engine' ),
				'entity'  => 'destination_zone',
			],
			'zero_pickup_locations' => [
				'count' => $this->pickup_location_repository->count_all(),
				'title' => __( 'No pickup locations', 'cetech-woocommerce-delivery-engine' ),
				'message' => __( 'No pickup locations are configured.', 'cetech-woocommerce-delivery-engine' ),
				'entity'  => 'pickup_location',
			],
			'zero_suppliers' => [
				'count' => $this->supplier_repository->count_all(),
				'title' => __( 'No suppliers', 'cetech-woocommerce-delivery-engine' ),
				'message' => __( 'No suppliers are configured.', 'cetech-woocommerce-delivery-engine' ),
				'entity'  => 'supplier',
			],
			'zero_origins' => [
				'count' => $this->origin_repository->count_all(),
				'title' => __( 'No origins', 'cetech-woocommerce-delivery-engine' ),
				'message' => __( 'No origins are configured.', 'cetech-woocommerce-delivery-engine' ),
				'entity'  => 'origin',
			],
			'zero_rate_cards' => [
				'count' => $this->rate_card_repository->count_all(),
				'title' => __( 'No rate cards', 'cetech-woocommerce-delivery-engine' ),
				'message' => __( 'No rate cards are configured.', 'cetech-woocommerce-delivery-engine' ),
				'entity'  => 'rate_card',
			],
			'zero_product_delivery_rules' => [
				'count' => $this->product_rule_repository->count_all(),
				'title' => __( 'No product delivery rules', 'cetech-woocommerce-delivery-engine' ),
				'message' => __( 'No product delivery rules are configured.', 'cetech-woocommerce-delivery-engine' ),
				'entity'  => 'product_delivery_rule',
			],
		];

		foreach ( $checks as $code => $check ) {
			if ( 0 === (int) $check['count'] ) {
				$this->add(
					$diagnostics,
					DiagnosticSeverity::Warning,
					$code,
					(string) $check['title'],
					(string) $check['message'],
					(string) $check['entity']
				);
			}
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 */
	private function check_schema( array &$diagnostics ): void {
		if ( ! SchemaVersion::is_up_to_date() ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Error,
				'schema_version_outdated',
				__( 'Schema version out of date', 'cetech-woocommerce-delivery-engine' ),
				sprintf(
					/* translators: 1: installed schema version, 2: target schema version */
					__( 'Installed schema version is %1$s; target is %2$s.', 'cetech-woocommerce-delivery-engine' ),
					SchemaVersion::get(),
					SchemaVersion::target()
				),
				'schema',
				null,
				sprintf( 'installed=%s target=%s', SchemaVersion::get(), SchemaVersion::target() )
			);
		}

		$missing = ConfigurationTables::missing();

		if ( [] !== $missing ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Error,
				'configuration_tables_missing',
				__( 'Missing configuration tables', 'cetech-woocommerce-delivery-engine' ),
				__( 'One or more configuration tables are missing.', 'cetech-woocommerce-delivery-engine' ),
				'schema',
				null,
				implode( ', ', $missing )
			);
		} elseif ( ! ConfigurationTables::all_exist() ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Error,
				'configuration_tables_incomplete',
				__( 'Configuration tables incomplete', 'cetech-woocommerce-delivery-engine' ),
				__( 'Not all configuration tables could be verified.', 'cetech-woocommerce-delivery-engine' ),
				'schema'
			);
		}

		$migration = MigrationStatus::get();

		if ( is_array( $migration ) && isset( $migration['status'] ) && 'failed' === (string) $migration['status'] ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Error,
				'last_migration_failed',
				__( 'Last migration failed', 'cetech-woocommerce-delivery-engine' ),
				__( 'The most recent schema migration did not complete successfully.', 'cetech-woocommerce-delivery-engine' ),
				'schema',
				null,
				isset( $migration['migration_id'] ) ? (string) $migration['migration_id'] : null
			);
		} elseif ( null === $migration ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Info,
				'last_migration_unknown',
				__( 'Migration status unknown', 'cetech-woocommerce-delivery-engine' ),
				__( 'No migration status record was found.', 'cetech-woocommerce-delivery-engine' ),
				'schema'
			);
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 */
	private function check_destinations( array &$diagnostics ): void {
		$zones_by_id = [];
		$unrestricted_fallback_count = 0;
		$after = 0;
		do {
			$page = $this->destination_zone_repository->page_after( $after, 100 );
			foreach ( $page as $zone ) {
				$zone_id = (int) ( $zone['id'] ?? 0 );
				$after   = max( $after, $zone_id );
				if ( $zone_id <= 0 ) {
					continue;
				}
				$zones_by_id[ $zone_id ] = $zone;
				$status = (string) ( $zone['status'] ?? '' );
				if ( RecordStatus::Active->value !== $status ) {
					continue;
				}
				$zone_rules = $this->destination_rule_repository->listByZoneId( $zone_id );
				if ( DestinationZoneMatcher::is_unrestricted_fallback( $zone, $zone_rules ) ) {
					++$unrestricted_fallback_count;
				}
				if ( [] === $zone_rules && empty( $zone['is_fallback'] ) && ! $this->zone_has_canonical_coverage( $zone_id ) ) {
					$this->add(
						$diagnostics,
						DiagnosticSeverity::Warning,
						'active_zone_without_rules',
						__( 'Active zone without rules', 'cetech-woocommerce-delivery-engine' ),
						__( 'An active destination zone has no rules and is not marked as fallback.', 'cetech-woocommerce-delivery-engine' ),
						'destination_zone',
						$zone_id,
						$this->entity_code_detail( $zone, 'internal_code' )
					);
				}
				foreach ( $zone_rules as $rule ) {
					$rule_id = (int) ( $rule['id'] ?? 0 );
					$rule_type = (string) ( $rule['rule_type'] ?? '' );
					if ( ! $this->is_valid_rule_type( $rule_type ) ) {
						$this->add(
							$diagnostics,
							DiagnosticSeverity::Warning,
							'destination_rule_invalid_type',
							__( 'Invalid destination rule type', 'cetech-woocommerce-delivery-engine' ),
							__( 'A destination rule uses an unknown rule type.', 'cetech-woocommerce-delivery-engine' ),
							'destination_rule',
							$rule_id,
							sprintf( 'rule_type=%s', $rule_type )
						);
					}
					$match_mode = (string) ( $rule['match_mode'] ?? '' );
					if ( ! $this->is_valid_match_mode( $match_mode ) ) {
						$this->add(
							$diagnostics,
							DiagnosticSeverity::Warning,
							'destination_rule_invalid_match_mode',
							__( 'Invalid destination rule match mode', 'cetech-woocommerce-delivery-engine' ),
							__( 'A destination rule uses an unknown match mode.', 'cetech-woocommerce-delivery-engine' ),
							'destination_rule',
							$rule_id,
							sprintf( 'match_mode=%s', $match_mode )
						);
					}
				}
			}
		} while ( [] !== $page );

		if ( $unrestricted_fallback_count > 1 ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'multiple_active_fallback_zones',
				__( 'More than one Everywhere else fallback', 'cetech-woocommerce-delivery-engine' ),
				sprintf(
					/* translators: %d: number of unrestricted fallback delivery areas */
					__( '%d active Delivery Areas have no location rules and are marked as fallback. Keep only one true Everywhere else area.', 'cetech-woocommerce-delivery-engine' ),
					$unrestricted_fallback_count
				),
				'destination_zone'
			);
		}

		$overlap = new OverlappingDeliveryAreaCoverage(
			$this->destination_zone_repository,
			$this->destination_rule_repository,
			$this->rate_card_repository,
			null,
			$this->coverage_groups
		);

		foreach ( $overlap->warnings() as $warning ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				(string) $warning['code'],
				(string) $warning['title'],
				(string) $warning['message'],
				'destination_zone',
				(int) $warning['zone_id'],
				(string) $warning['details']
			);
		}
		foreach ( $overlap->unproven_zone_ids() as $unproven_id ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Info,
				'destination_coverage_unproven',
				__( 'Delivery area coverage not fully proven', 'cetech-woocommerce-delivery-engine' ),
				__( 'Canonical overlap/fallback could not be proven cheaply for this area. Test a specific address instead of treating it as uncovered.', 'cetech-woocommerce-delivery-engine' ),
				'destination_zone',
				$unproven_id,
				'needs_test'
			);
		}

		foreach ( $this->pickup_location_repository->list( [ 'limit' => self::LIST_LIMIT ] ) as $location ) {
			$location_id = (int) ( $location['id'] ?? 0 );
			$status      = (string) ( $location['status'] ?? '' );

			if ( RecordStatus::Active->value !== $status ) {
				continue;
			}

			if ( $this->is_empty_public_address( $location['public_address'] ?? null ) ) {
				$this->add(
					$diagnostics,
					DiagnosticSeverity::Warning,
					'pickup_location_empty_address',
					__( 'Pickup location missing address', 'cetech-woocommerce-delivery-engine' ),
					__( 'An active pickup location has no public address configured.', 'cetech-woocommerce-delivery-engine' ),
					'pickup_location',
					$location_id,
					$this->entity_code_detail( $location, 'internal_code' )
				);
			}
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 */
	private function check_suppliers_origins( array &$diagnostics ): void {
		$suppliers     = $this->supplier_repository->list( [ 'limit' => self::LIST_LIMIT ] );
		$suppliers_by_id = $this->index_by_id( $suppliers );
		$origins_by_supplier = [];

		foreach ( $this->origin_repository->list( [ 'limit' => self::LIST_LIMIT ] ) as $origin ) {
			$origin_id   = (int) ( $origin['id'] ?? 0 );
			$supplier_id = (int) ( $origin['supplier_id'] ?? 0 );

			if ( $supplier_id > 0 ) {
				$origins_by_supplier[ $supplier_id ] = ( $origins_by_supplier[ $supplier_id ] ?? 0 ) + 1;
			}

			if ( $supplier_id <= 0 || ! isset( $suppliers_by_id[ $supplier_id ] ) ) {
				$this->add(
					$diagnostics,
					DiagnosticSeverity::Error,
					'origin_missing_supplier',
					__( 'Origin missing supplier', 'cetech-woocommerce-delivery-engine' ),
					__( 'An origin references a supplier that does not exist.', 'cetech-woocommerce-delivery-engine' ),
					'origin',
					$origin_id,
					sprintf( 'supplier_id=%d', $supplier_id )
				);
				continue;
			}

			$supplier_status = (string) ( $suppliers_by_id[ $supplier_id ]['status'] ?? '' );

			if ( $this->is_non_active_status( $supplier_status ) ) {
				$this->add(
					$diagnostics,
					DiagnosticSeverity::Warning,
					'origin_inactive_supplier',
					__( 'Origin linked to inactive supplier', 'cetech-woocommerce-delivery-engine' ),
					__( 'An origin is linked to a supplier that is not active.', 'cetech-woocommerce-delivery-engine' ),
					'origin',
					$origin_id,
					sprintf( 'supplier_id=%d status=%s', $supplier_id, $supplier_status )
				);
			}

			if ( ! $this->is_valid_internal_address_json( $origin['internal_address'] ?? null ) ) {
				$this->add(
					$diagnostics,
					DiagnosticSeverity::Warning,
					'origin_invalid_internal_address',
					__( 'Origin invalid address JSON', 'cetech-woocommerce-delivery-engine' ),
					__( 'An origin has internal address data that is not valid JSON.', 'cetech-woocommerce-delivery-engine' ),
					'origin',
					$origin_id,
					$this->entity_code_detail( $origin, 'internal_code' )
				);
			}
		}

		foreach ( $suppliers as $supplier ) {
			$supplier_id = (int) ( $supplier['id'] ?? 0 );
			$status      = (string) ( $supplier['status'] ?? '' );

			if ( RecordStatus::Active->value !== $status ) {
				continue;
			}

			if ( 0 === ( $origins_by_supplier[ $supplier_id ] ?? 0 ) ) {
				$this->add(
					$diagnostics,
					DiagnosticSeverity::Info,
					'active_supplier_without_origins',
					__( 'Active supplier without origins', 'cetech-woocommerce-delivery-engine' ),
					__( 'An active supplier has no origins configured.', 'cetech-woocommerce-delivery-engine' ),
					'supplier',
					$supplier_id,
					$this->entity_code_detail( $supplier, 'internal_code' )
				);
			}
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 */
	private function check_rate_cards( array &$diagnostics ): void {
		$offers    = $this->index_by_id( $this->delivery_offer_repository->list( [ 'limit' => self::LIST_LIMIT ] ) );
		$zones     = [];
		$zone_after = 0;
		do {
			$zone_page = $this->destination_zone_repository->page_after( $zone_after, 100 );
			foreach ( $zone_page as $zone ) {
				$zone_id    = (int) ( $zone['id'] ?? 0 );
				$zone_after = max( $zone_after, $zone_id );
				if ( $zone_id > 0 ) {
					$zones[ $zone_id ] = $zone;
				}
			}
		} while ( [] !== $zone_page );
		$profiles  = $this->index_by_id( $this->logistics_profile_repository->list( [ 'limit' => self::LIST_LIMIT ] ) );
		$suppliers = $this->index_by_id( $this->supplier_repository->list( [ 'limit' => self::LIST_LIMIT ] ) );
		$origins   = $this->index_by_id( $this->origin_repository->list( [ 'limit' => self::LIST_LIMIT ] ) );
		$now       = gmdate( 'Y-m-d H:i:s' );
		$signatures = [];

		$card_after = 0;
		do {
			$cards = method_exists( $this->rate_card_repository, 'page_after' )
				? $this->rate_card_repository->page_after( $card_after, 100 )
				: ( 0 === $card_after ? $this->rate_card_repository->list( [ 'limit' => self::LIST_LIMIT ] ) : [] );
			foreach ( $cards as $card ) {
				$card_id    = (int) ( $card['id'] ?? 0 );
				$card_after = max( $card_after, $card_id );
				$status     = (string) ( $card['status'] ?? '' );

				$this->check_rate_card_delivery_offer( $diagnostics, $card, $offers );
				$this->check_rate_card_destination_zone( $diagnostics, $card, $zones );
			$this->check_rate_card_optional_fk(
				$diagnostics,
				$card,
				'logistics_profile_id',
				'logistics_profile',
				$profiles,
				'rate_card_missing_logistics_profile',
				'rate_card_inactive_logistics_profile'
			);
			$this->check_rate_card_optional_fk(
				$diagnostics,
				$card,
				'supplier_id',
				'supplier',
				$suppliers,
				'rate_card_missing_supplier',
				'rate_card_inactive_supplier'
			);
			$this->check_rate_card_optional_fk(
				$diagnostics,
				$card,
				'origin_id',
				'origin',
				$origins,
				'rate_card_missing_origin',
				'rate_card_inactive_origin'
			);

			if ( RecordStatus::Active->value === $status ) {
				$this->check_active_rate_card_values( $diagnostics, $card, $now );

				$signature = $this->rate_card_match_signature( $card );
				$signatures[ $signature ]   = $signatures[ $signature ] ?? [];
				$signatures[ $signature ][] = $card_id;
			}
		}
		} while ( [] !== $cards && method_exists( $this->rate_card_repository, 'page_after' ) );

		foreach ( $signatures as $signature => $card_ids ) {
			if ( count( $card_ids ) < 2 ) {
				continue;
			}

			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'duplicate_active_rate_card_match',
				__( 'Possible duplicate rate cards', 'cetech-woocommerce-delivery-engine' ),
				__( 'Multiple active rate cards share the same matching dimensions and priority.', 'cetech-woocommerce-delivery-engine' ),
				'rate_card',
				(int) $card_ids[0],
				sprintf( 'signature=%s ids=%s', $signature, implode( ',', array_map( 'strval', $card_ids ) ) )
			);
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 * @param array<int, array<string, mixed>> $offers
	 * @param array<string, mixed> $card
	 */
	private function check_rate_card_delivery_offer( array &$diagnostics, array $card, array $offers ): void {
		$card_id = (int) ( $card['id'] ?? 0 );
		$offer_id = (int) ( $card['delivery_offer_id'] ?? 0 );

		if ( $offer_id <= 0 || ! isset( $offers[ $offer_id ] ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Error,
				'rate_card_missing_delivery_offer',
				__( 'Rate card missing delivery offer', 'cetech-woocommerce-delivery-engine' ),
				__( 'A rate card references a delivery offer that does not exist.', 'cetech-woocommerce-delivery-engine' ),
				'rate_card',
				$card_id,
				sprintf( 'delivery_offer_id=%d', $offer_id )
			);

			return;
		}

		$offer_status = (string) ( $offers[ $offer_id ]['status'] ?? '' );

		if ( $this->is_non_active_status( $offer_status ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'rate_card_inactive_delivery_offer',
				__( 'Rate card inactive delivery offer', 'cetech-woocommerce-delivery-engine' ),
				__( 'A rate card references a delivery offer that is not active.', 'cetech-woocommerce-delivery-engine' ),
				'rate_card',
				$card_id,
				sprintf( 'delivery_offer_id=%d status=%s', $offer_id, $offer_status )
			);
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 * @param array<int, array<string, mixed>> $zones
	 * @param array<string, mixed> $card
	 */
	private function check_rate_card_destination_zone( array &$diagnostics, array $card, array $zones ): void {
		$card_id = (int) ( $card['id'] ?? 0 );
		$zone_id = (int) ( $card['destination_zone_id'] ?? 0 );

		if ( $zone_id <= 0 || ! isset( $zones[ $zone_id ] ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Error,
				'rate_card_missing_destination_zone',
				__( 'Rate card missing destination zone', 'cetech-woocommerce-delivery-engine' ),
				__( 'A rate card references a destination zone that does not exist.', 'cetech-woocommerce-delivery-engine' ),
				'rate_card',
				$card_id,
				sprintf( 'destination_zone_id=%d', $zone_id )
			);

			return;
		}

		$zone_status = (string) ( $zones[ $zone_id ]['status'] ?? '' );

		if ( $this->is_non_active_status( $zone_status ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'rate_card_inactive_destination_zone',
				__( 'Rate card inactive destination zone', 'cetech-woocommerce-delivery-engine' ),
				__( 'A rate card references a destination zone that is not active.', 'cetech-woocommerce-delivery-engine' ),
				'rate_card',
				$card_id,
				sprintf( 'destination_zone_id=%d status=%s', $zone_id, $zone_status )
			);
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 * @param array<string, mixed> $card
	 * @param array<int, array<string, mixed>> $entities
	 */
	private function check_rate_card_optional_fk(
		array &$diagnostics,
		array $card,
		string $field,
		string $entity_type,
		array $entities,
		string $missing_code,
		string $inactive_code
	): void {
		$card_id = (int) ( $card['id'] ?? 0 );
		$fk_id   = isset( $card[ $field ] ) ? (int) $card[ $field ] : 0;

		if ( $fk_id <= 0 ) {
			return;
		}

		if ( ! isset( $entities[ $fk_id ] ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Error,
				$missing_code,
				sprintf(
					/* translators: %s: related entity type label */
					__( 'Rate card missing %s', 'cetech-woocommerce-delivery-engine' ),
					str_replace( '_', ' ', $entity_type )
				),
				sprintf(
					/* translators: %s: related entity type label */
					__( 'A rate card references a %s that does not exist.', 'cetech-woocommerce-delivery-engine' ),
					str_replace( '_', ' ', $entity_type )
				),
				'rate_card',
				$card_id,
				sprintf( '%s=%d', $field, $fk_id )
			);

			return;
		}

		$entity_status = (string) ( $entities[ $fk_id ]['status'] ?? '' );

		if ( $this->is_non_active_status( $entity_status ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				$inactive_code,
				sprintf(
					/* translators: %s: related entity type label */
					__( 'Rate card inactive %s', 'cetech-woocommerce-delivery-engine' ),
					str_replace( '_', ' ', $entity_type )
				),
				sprintf(
					/* translators: %s: related entity type label */
					__( 'A rate card references a %s that is not active.', 'cetech-woocommerce-delivery-engine' ),
					str_replace( '_', ' ', $entity_type )
				),
				'rate_card',
				$card_id,
				sprintf( '%s=%d status=%s', $field, $fk_id, $entity_status )
			);
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 * @param array<string, mixed> $card
	 */
	private function check_active_rate_card_values( array &$diagnostics, array $card, string $now ): void {
		$card_id = (int) ( $card['id'] ?? 0 );

		$from = isset( $card['effective_from'] ) ? trim( (string) $card['effective_from'] ) : '';
		$to   = isset( $card['effective_to'] ) ? trim( (string) $card['effective_to'] ) : '';

		if ( '' !== $from && $now < $from ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'rate_card_effective_from_future',
				__( 'Rate card not yet effective', 'cetech-woocommerce-delivery-engine' ),
				__( 'An active rate card has an effective from date in the future.', 'cetech-woocommerce-delivery-engine' ),
				'rate_card',
				$card_id,
				sprintf( 'effective_from=%s', $from )
			);
		}

		if ( '' !== $to && $now > $to ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'rate_card_effective_to_expired',
				__( 'Rate card expired', 'cetech-woocommerce-delivery-engine' ),
				__( 'An active rate card has an effective to date in the past.', 'cetech-woocommerce-delivery-engine' ),
				'rate_card',
				$card_id,
				sprintf( 'effective_to=%s', $to )
			);
		}

		$base_amount = $card['base_amount'] ?? null;

		if ( ! is_numeric( $base_amount ) || (float) $base_amount < 0 ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'rate_card_invalid_base_amount',
				__( 'Rate card invalid base amount', 'cetech-woocommerce-delivery-engine' ),
				__( 'An active rate card has a missing, non-numeric, or negative base amount.', 'cetech-woocommerce-delivery-engine' ),
				'rate_card',
				$card_id,
				$this->entity_code_detail( $card, 'internal_code' )
			);
		}

		$charge_type = (string) ( $card['charge_type'] ?? '' );

		if ( ! $this->is_valid_charge_type( $charge_type ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'rate_card_unsupported_charge_type',
				__( 'Rate card unsupported charge type', 'cetech-woocommerce-delivery-engine' ),
				__( 'An active rate card uses a charge type that is not supported in the current admin preview.', 'cetech-woocommerce-delivery-engine' ),
				'rate_card',
				$card_id,
				sprintf( 'charge_type=%s', $charge_type )
			);
		}

		$currency = strtoupper( trim( (string) ( $card['base_currency'] ?? '' ) ) );

		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'rate_card_invalid_currency',
				__( 'Rate card invalid currency', 'cetech-woocommerce-delivery-engine' ),
				__( 'An active rate card has an invalid base currency code.', 'cetech-woocommerce-delivery-engine' ),
				'rate_card',
				$card_id,
				sprintf( 'base_currency=%s', $currency )
			);
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 */
	private function check_product_rules( array &$diagnostics ): void {
		$offers    = $this->index_by_id( $this->delivery_offer_repository->list( [ 'limit' => self::LIST_LIMIT ] ) );
		$profiles  = $this->index_by_id( $this->logistics_profile_repository->list( [ 'limit' => self::LIST_LIMIT ] ) );
		$suppliers = $this->index_by_id( $this->supplier_repository->list( [ 'limit' => self::LIST_LIMIT ] ) );
		$origins   = $this->index_by_id( $this->origin_repository->list( [ 'limit' => self::LIST_LIMIT ] ) );
		$active_signatures = [];
		$priority_competition = [];

		foreach ( $this->product_rule_repository->list( [ 'limit' => self::LIST_LIMIT ] ) as $rule ) {
			$rule_id = (int) ( $rule['id'] ?? 0 );
			$status  = (string) ( $rule['status'] ?? '' );
			$target_type = (string) ( $rule['target_type'] ?? '' );
			$target_id   = (int) ( $rule['target_id'] ?? 0 );
			$availability = (string) ( $rule['fulfilment_availability'] ?? '' );
			$choice       = (string) ( $rule['fulfilment_choice'] ?? '' );

			if ( RecordStatus::Active->value !== $status ) {
				continue;
			}

			if ( $this->target_resolver->is_woocommerce_available() && ! $this->target_resolver->target_exists( $target_type, $target_id ) ) {
				$this->add(
					$diagnostics,
					DiagnosticSeverity::Warning,
					'product_rule_missing_target',
					__( 'Product rule missing target', 'cetech-woocommerce-delivery-engine' ),
					__( 'An active product rule references a WooCommerce target that does not exist.', 'cetech-woocommerce-delivery-engine' ),
					'product_delivery_rule',
					$rule_id,
					sprintf( 'target_type=%s target_id=%d', $target_type, $target_id )
				);
			}

			if ( ! $this->is_valid_fulfilment_availability( $availability ) ) {
				$this->add(
					$diagnostics,
					DiagnosticSeverity::Warning,
					'product_rule_invalid_availability',
					__( 'Product rule invalid availability', 'cetech-woocommerce-delivery-engine' ),
					__( 'An active product rule uses an unknown fulfilment availability value.', 'cetech-woocommerce-delivery-engine' ),
					'product_delivery_rule',
					$rule_id,
					sprintf( 'fulfilment_availability=%s', $availability )
				);
			}

			if ( ! $this->is_valid_fulfilment_choice( $choice ) ) {
				$this->add(
					$diagnostics,
					DiagnosticSeverity::Warning,
					'product_rule_invalid_choice',
					__( 'Product rule invalid choice', 'cetech-woocommerce-delivery-engine' ),
					__( 'An active product rule uses an unknown fulfilment choice value.', 'cetech-woocommerce-delivery-engine' ),
					'product_delivery_rule',
					$rule_id,
					sprintf( 'fulfilment_choice=%s', $choice )
				);
			}

			if ( ! $this->is_valid_availability_choice_pair( $availability, $choice ) ) {
				$this->add(
					$diagnostics,
					DiagnosticSeverity::Warning,
					'product_rule_invalid_availability_choice',
					__( 'Product rule invalid availability/choice pair', 'cetech-woocommerce-delivery-engine' ),
					__( 'An active product rule combines fulfilment availability and choice in an unsupported way.', 'cetech-woocommerce-delivery-engine' ),
					'product_delivery_rule',
					$rule_id,
					sprintf( 'availability=%s choice=%s', $availability, $choice )
				);
			}

			$this->check_product_rule_offers( $diagnostics, $rule, $offers );

			if ( FulfilmentChoice::Delivery->value === $choice ) {
				$stored_offers = $rule['delivery_offer_ids'] ?? null;
				$offer_ids     = $this->decode_product_rule_offer_ids( $stored_offers );

				if ( [] === $offer_ids ) {
					if ( $this->is_offer_ids_json_invalid( $stored_offers ) ) {
						$this->add(
							$diagnostics,
							DiagnosticSeverity::Warning,
							'product_rule_invalid_offer_ids_json',
							__( 'Product rule invalid offer IDs JSON', 'cetech-woocommerce-delivery-engine' ),
							__( 'An active delivery rule has delivery_offer_ids data that is not valid JSON.', 'cetech-woocommerce-delivery-engine' ),
							'product_delivery_rule',
							$rule_id,
							sprintf( 'fulfilment_choice=%s', $choice )
						);
					} else {
						$this->add(
							$diagnostics,
							DiagnosticSeverity::Warning,
							'product_rule_delivery_without_offers',
							__( 'Product rule delivery without offers', 'cetech-woocommerce-delivery-engine' ),
							__( 'An active product rule uses delivery fulfilment choice but has no delivery offers.', 'cetech-woocommerce-delivery-engine' ),
							'product_delivery_rule',
							$rule_id,
							sprintf( 'fulfilment_choice=%s', $choice )
						);
					}
				}
			}

			$this->check_product_rule_optional_fk(
				$diagnostics,
				$rule,
				'logistics_profile_id',
				'logistics_profile',
				$profiles,
				'product_rule_missing_logistics_profile',
				'product_rule_inactive_logistics_profile'
			);
			$this->check_product_rule_optional_fk(
				$diagnostics,
				$rule,
				'supplier_id',
				'supplier',
				$suppliers,
				'product_rule_missing_supplier',
				'product_rule_inactive_supplier'
			);
			$this->check_product_rule_optional_fk(
				$diagnostics,
				$rule,
				'origin_id',
				'origin',
				$origins,
				'product_rule_missing_origin',
				'product_rule_inactive_origin'
			);

			$supplier_id = isset( $rule['supplier_id'] ) ? (int) $rule['supplier_id'] : 0;
			$origin_id     = isset( $rule['origin_id'] ) ? (int) $rule['origin_id'] : 0;

			if ( $supplier_id > 0 && $origin_id > 0 && isset( $origins[ $origin_id ] ) ) {
				$origin_supplier = (int) ( $origins[ $origin_id ]['supplier_id'] ?? 0 );

				if ( $origin_supplier !== $supplier_id ) {
					$this->add(
						$diagnostics,
						DiagnosticSeverity::Error,
						'product_rule_origin_supplier_mismatch',
						__( 'Product rule origin/supplier mismatch', 'cetech-woocommerce-delivery-engine' ),
						__( 'An active product rule links an origin that does not belong to the selected supplier.', 'cetech-woocommerce-delivery-engine' ),
						'product_delivery_rule',
						$rule_id,
						sprintf( 'supplier_id=%d origin_id=%d', $supplier_id, $origin_id )
					);
				}
			}

			$signature = sprintf( '%s|%d|%s', $target_type, $target_id, $availability );
			$active_signatures[ $signature ]   = $active_signatures[ $signature ] ?? [];
			$active_signatures[ $signature ][] = $rule_id;

			$priority_signature = sprintf(
				'%s|%d|%s|%d',
				$target_type,
				$target_id,
				$availability,
				(int) ( $rule['priority'] ?? 100 )
			);
			$priority_competition[ $priority_signature ]   = $priority_competition[ $priority_signature ] ?? [];
			$priority_competition[ $priority_signature ][] = $rule_id;
		}

		foreach ( $priority_competition as $signature => $rule_ids ) {
			if ( count( $rule_ids ) < 2 ) {
				continue;
			}

			$parts = explode( '|', $signature, 4 );

			if ( count( $parts ) >= 3 ) {
				$duplicate_signature = sprintf( '%s|%s|%s', $parts[0], $parts[1], $parts[2] );
				$duplicate_ids       = $active_signatures[ $duplicate_signature ] ?? [];

				if (
					count( $duplicate_ids ) >= 2
					&& count( $duplicate_ids ) === count( $rule_ids )
					&& [] === array_diff( $rule_ids, $duplicate_ids )
				) {
					continue;
				}
			}

			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'product_rule_competing_same_priority',
				__( 'Competing product rules at same priority', 'cetech-woocommerce-delivery-engine' ),
				__( 'Multiple active product rules share the same target, fulfilment availability, and priority.', 'cetech-woocommerce-delivery-engine' ),
				'product_delivery_rule',
				(int) $rule_ids[0],
				sprintf( 'signature=%s ids=%s', $signature, implode( ',', array_map( 'strval', $rule_ids ) ) )
			);
		}

		foreach ( $active_signatures as $signature => $rule_ids ) {
			if ( count( $rule_ids ) < 2 ) {
				continue;
			}

			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'duplicate_active_product_rule',
				__( 'Duplicate active product rules', 'cetech-woocommerce-delivery-engine' ),
				__( 'Multiple active product rules share the same target and fulfilment availability.', 'cetech-woocommerce-delivery-engine' ),
				'product_delivery_rule',
				(int) $rule_ids[0],
				sprintf( 'signature=%s ids=%s', $signature, implode( ',', array_map( 'strval', $rule_ids ) ) )
			);
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 * @param array<string, mixed> $rule
	 * @param array<int, array<string, mixed>> $offers
	 */
	private function check_product_rule_offers( array &$diagnostics, array $rule, array $offers ): void {
		$rule_id = (int) ( $rule['id'] ?? 0 );
		$choice  = (string) ( $rule['fulfilment_choice'] ?? '' );
		$offer_ids = $this->decode_product_rule_offer_ids( $rule['delivery_offer_ids'] ?? null );

		if ( FulfilmentChoice::StorePickup->value === $choice ) {
			return;
		}

		foreach ( $offer_ids as $offer_id ) {
			if ( ! isset( $offers[ $offer_id ] ) ) {
				$this->add(
					$diagnostics,
					DiagnosticSeverity::Error,
					'product_rule_missing_delivery_offer',
					__( 'Product rule missing delivery offer', 'cetech-woocommerce-delivery-engine' ),
					__( 'A product rule references a delivery offer that does not exist.', 'cetech-woocommerce-delivery-engine' ),
					'product_delivery_rule',
					$rule_id,
					sprintf( 'delivery_offer_id=%d', $offer_id )
				);
				continue;
			}

			$offer_status = (string) ( $offers[ $offer_id ]['status'] ?? '' );

			if ( $this->is_non_active_status( $offer_status ) ) {
				$this->add(
					$diagnostics,
					DiagnosticSeverity::Warning,
					'product_rule_inactive_delivery_offer',
					__( 'Product rule inactive delivery offer', 'cetech-woocommerce-delivery-engine' ),
					__( 'A product rule references a delivery offer that is not active.', 'cetech-woocommerce-delivery-engine' ),
					'product_delivery_rule',
					$rule_id,
					sprintf( 'delivery_offer_id=%d status=%s', $offer_id, $offer_status )
				);
			}
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 * @param array<string, mixed> $rule
	 * @param array<int, array<string, mixed>> $entities
	 */
	private function check_product_rule_optional_fk(
		array &$diagnostics,
		array $rule,
		string $field,
		string $entity_type,
		array $entities,
		string $missing_code,
		string $inactive_code
	): void {
		$rule_id = (int) ( $rule['id'] ?? 0 );
		$fk_id   = isset( $rule[ $field ] ) ? (int) $rule[ $field ] : 0;

		if ( $fk_id <= 0 ) {
			return;
		}

		if ( ! isset( $entities[ $fk_id ] ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Error,
				$missing_code,
				sprintf(
					/* translators: %s: related entity type label */
					__( 'Product rule missing %s', 'cetech-woocommerce-delivery-engine' ),
					str_replace( '_', ' ', $entity_type )
				),
				sprintf(
					/* translators: %s: related entity type label */
					__( 'A product rule references a %s that does not exist.', 'cetech-woocommerce-delivery-engine' ),
					str_replace( '_', ' ', $entity_type )
				),
				'product_delivery_rule',
				$rule_id,
				sprintf( '%s=%d', $field, $fk_id )
			);

			return;
		}

		$entity_status = (string) ( $entities[ $fk_id ]['status'] ?? '' );

		if ( $this->is_non_active_status( $entity_status ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				$inactive_code,
				sprintf(
					/* translators: %s: related entity type label */
					__( 'Product rule inactive %s', 'cetech-woocommerce-delivery-engine' ),
					str_replace( '_', ' ', $entity_type )
				),
				sprintf(
					/* translators: %s: related entity type label */
					__( 'A product rule references a %s that is not active.', 'cetech-woocommerce-delivery-engine' ),
					str_replace( '_', ' ', $entity_type )
				),
				'product_delivery_rule',
				$rule_id,
				sprintf( '%s=%d status=%s', $field, $fk_id, $entity_status )
			);
		}
	}

	/**
	 * @return list<int>
	 */
	private function decode_product_rule_offer_ids( mixed $stored ): array {
		if ( $this->product_rule_repository instanceof WpdbProductDeliveryRuleRepository ) {
			return $this->product_rule_repository->decode_offer_ids( $stored );
		}

		if ( null === $stored || '' === $stored ) {
			return [];
		}

		$decoded = json_decode( (string) $stored, true );

		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$ids = [];

		foreach ( $decoded as $value ) {
			$int = (int) $value;

			if ( $int > 0 ) {
				$ids[] = $int;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private function is_offer_ids_json_invalid( mixed $stored ): bool {
		if ( null === $stored || '' === trim( (string) $stored ) ) {
			return false;
		}

		json_decode( (string) $stored, true );

		return JSON_ERROR_NONE !== json_last_error();
	}

	private function is_valid_fulfilment_availability( string $availability ): bool {
		foreach ( FulfilmentAvailability::cases() as $case ) {
			if ( $case->value === $availability ) {
				return true;
			}
		}

		return false;
	}

	private function is_valid_fulfilment_choice( string $choice ): bool {
		foreach ( FulfilmentChoice::cases() as $case ) {
			if ( $case->value === $choice ) {
				return true;
			}
		}

		return false;
	}

	private function is_valid_availability_choice_pair( string $availability, string $choice ): bool {
		if ( FulfilmentAvailability::InStore->value === $availability ) {
			return FulfilmentChoice::Delivery->value === $choice
				|| FulfilmentChoice::StorePickup->value === $choice;
		}

		return FulfilmentChoice::Delivery->value === $choice;
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 */
	private function check_product_delivery_selector( array &$diagnostics ): void {
		if ( ! $this->feature_flags->is_enabled( 'enable_product_delivery_selector' ) ) {
			return;
		}

		if ( ! class_exists( ProductDeliveryOptionsBuilder::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'selector_enabled_options_builder_missing',
				$this->setting_missing_component_title( 'enable_product_delivery_selector' ),
				$this->setting_missing_component_message( 'enable_product_delivery_selector' ),
				'feature_flag'
			);
		}

		if ( ! class_exists( ProductDeliverySelectionValidator::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'selector_enabled_selection_validator_missing',
				$this->setting_missing_component_title( 'enable_product_delivery_selector' ),
				$this->setting_missing_component_message( 'enable_product_delivery_selector' ),
				'feature_flag'
			);
		}

		if ( ! ProductDeliverySelectionIntent::isCompatibleWithOptionContract( ProductDeliveryOption::CONTRACT_VERSION ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'selector_contract_version_mismatch',
				__( 'Product selector: contract version mismatch', 'cetech-woocommerce-delivery-engine' ),
				__( 'Selection intent and product delivery option contract versions are incompatible.', 'cetech-woocommerce-delivery-engine' ),
				'feature_flag'
			);
		}

		$active_rules = $this->product_rule_repository->listActive( [ 'limit' => 1 ] );

		if ( [] === $active_rules ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'selector_enabled_no_active_product_rules',
				__( 'Product selector enabled without active rules', 'cetech-woocommerce-delivery-engine' ),
				__( 'The product delivery selector is enabled but no active product delivery rules exist.', 'cetech-woocommerce-delivery-engine' ),
				'feature_flag'
			);
		}

		$active_offer_count = 0;

		foreach ( $this->delivery_offer_repository->list( [ 'limit' => self::LIST_LIMIT ] ) as $offer ) {
			if ( RecordStatus::Active->value === (string) ( $offer['status'] ?? '' ) ) {
				++$active_offer_count;
			}
		}

		if ( 0 === $active_offer_count ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'selector_enabled_no_active_delivery_offers',
				__( 'Product selector enabled without active delivery offers', 'cetech-woocommerce-delivery-engine' ),
				__( 'The product delivery selector is enabled but no active delivery offers are configured.', 'cetech-woocommerce-delivery-engine' ),
				'feature_flag'
			);
		}

		foreach ( $this->product_rule_repository->listActive( [ 'limit' => self::LIST_LIMIT ] ) as $rule ) {
			if ( FulfilmentChoice::Delivery->value !== (string) ( $rule['fulfilment_choice'] ?? '' ) ) {
				continue;
			}

			$rule_id   = (int) ( $rule['id'] ?? 0 );
			$offer_ids = $this->decode_product_rule_offer_ids( $rule['delivery_offer_ids'] ?? null );

			if ( [] === $offer_ids ) {
				$this->add(
					$diagnostics,
					DiagnosticSeverity::Warning,
					'selector_enabled_delivery_rule_without_offers',
					__( 'Product selector: delivery rule without offers', 'cetech-woocommerce-delivery-engine' ),
					__( 'The product delivery selector is enabled but an active delivery rule has no delivery offers.', 'cetech-woocommerce-delivery-engine' ),
					'product_delivery_rule',
					$rule_id
				);
				continue;
			}

			foreach ( $offer_ids as $offer_id ) {
				$offer = $this->delivery_offer_repository->findById( $offer_id );

				if ( null === $offer ) {
					continue;
				}

				if ( RecordStatus::Active->value !== (string) ( $offer['status'] ?? '' ) ) {
					$this->add(
						$diagnostics,
						DiagnosticSeverity::Warning,
						'selector_enabled_inactive_delivery_offer',
						__( 'Product selector: inactive delivery offer referenced', 'cetech-woocommerce-delivery-engine' ),
						__( 'The product delivery selector is enabled but an active product rule references an inactive delivery offer.', 'cetech-woocommerce-delivery-engine' ),
						'product_delivery_rule',
						$rule_id,
						sprintf( 'delivery_offer_id=%d', $offer_id )
					);
				}
			}
		}

		$delivery_rules_total     = 0;
		$delivery_rules_resolvable = 0;

		foreach ( $this->product_rule_repository->listActive( [ 'limit' => self::LIST_LIMIT ] ) as $rule ) {
			if ( FulfilmentChoice::Delivery->value !== (string) ( $rule['fulfilment_choice'] ?? '' ) ) {
				continue;
			}

			++$delivery_rules_total;
			$offer_ids = $this->decode_product_rule_offer_ids( $rule['delivery_offer_ids'] ?? null );

			foreach ( $offer_ids as $offer_id ) {
				$offer = $this->delivery_offer_repository->findById( $offer_id );

				if ( null !== $offer && RecordStatus::Active->value === (string) ( $offer['status'] ?? '' ) ) {
					++$delivery_rules_resolvable;
					break;
				}
			}
		}

		if ( $delivery_rules_total > 0 && 0 === $delivery_rules_resolvable ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'selector_enabled_all_delivery_rules_unavailable',
				__( 'Product selector: all delivery rules lack active offers', 'cetech-woocommerce-delivery-engine' ),
				__( 'The product delivery selector is enabled but every active delivery rule references missing or inactive delivery offers.', 'cetech-woocommerce-delivery-engine' ),
				'feature_flag'
			);
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 */
	private function check_cart_delivery_selection_capture( array &$diagnostics ): void {
		if ( ! $this->feature_flags->is_enabled( 'enable_cart_delivery_selection_capture' ) ) {
			return;
		}

		if ( ! $this->feature_flags->is_enabled( 'enable_product_delivery_selector' ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'cart_capture_enabled_selector_disabled',
				$this->setting_requires_other_title( 'enable_cart_delivery_selection_capture', 'enable_product_delivery_selector' ),
				$this->setting_requires_other_message( 'enable_cart_delivery_selection_capture', 'enable_product_delivery_selector' ),
				'feature_flag'
			);
		}

		if ( ! class_exists( ProductDeliverySelectionValidator::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'cart_capture_enabled_selection_validator_missing',
				$this->setting_missing_component_title( 'enable_cart_delivery_selection_capture' ),
				$this->setting_missing_component_message( 'enable_cart_delivery_selection_capture' ),
				'feature_flag'
			);
		}

		if ( ! class_exists( ProductDeliveryOptionsBuilder::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'cart_capture_enabled_options_builder_missing',
				$this->setting_missing_component_title( 'enable_cart_delivery_selection_capture' ),
				$this->setting_missing_component_message( 'enable_cart_delivery_selection_capture' ),
				'feature_flag'
			);
		}

		if ( ! class_exists( CartDeliverySelectionCapture::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'cart_capture_enabled_capture_service_missing',
				$this->setting_missing_component_title( 'enable_cart_delivery_selection_capture' ),
				$this->setting_missing_component_message( 'enable_cart_delivery_selection_capture' ),
				'feature_flag'
			);
		}

		if ( ! class_exists( CartDeliverySelectionRevalidator::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'cart_capture_enabled_revalidator_missing',
				$this->setting_missing_component_title( 'enable_cart_delivery_selection_capture' ),
				$this->setting_missing_component_message( 'enable_cart_delivery_selection_capture' ),
				'feature_flag'
			);
		}

		$active_rules = $this->product_rule_repository->listActive( [ 'limit' => 1 ] );

		if ( [] === $active_rules ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'cart_capture_enabled_no_active_product_rules',
				__( 'Cart capture enabled without active rules', 'cetech-woocommerce-delivery-engine' ),
				__( 'Cart delivery selection capture is enabled but no active product delivery rules exist.', 'cetech-woocommerce-delivery-engine' ),
				'feature_flag'
			);
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 */
	private function check_checkout_delivery_selection_validation( array &$diagnostics ): void {
		if ( ! $this->feature_flags->is_enabled( 'enable_checkout_delivery_selection_validation' ) ) {
			return;
		}

		if ( ! $this->feature_flags->is_enabled( 'enable_cart_delivery_selection_capture' ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'checkout_validation_enabled_cart_capture_disabled',
				$this->setting_requires_other_title( 'enable_checkout_delivery_selection_validation', 'enable_cart_delivery_selection_capture' ),
				$this->setting_requires_other_message( 'enable_checkout_delivery_selection_validation', 'enable_cart_delivery_selection_capture' ),
				'feature_flag'
			);
		}

		if ( ! $this->feature_flags->is_enabled( 'enable_product_delivery_selector' ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'checkout_validation_enabled_selector_disabled',
				$this->setting_requires_other_title( 'enable_checkout_delivery_selection_validation', 'enable_product_delivery_selector' ),
				$this->setting_requires_other_message( 'enable_checkout_delivery_selection_validation', 'enable_product_delivery_selector' ),
				'feature_flag'
			);
		}

		if ( ! class_exists( CheckoutDeliverySelectionValidator::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'checkout_validation_enabled_validator_missing',
				$this->setting_missing_component_title( 'enable_checkout_delivery_selection_validation' ),
				$this->setting_missing_component_message( 'enable_checkout_delivery_selection_validation' ),
				'feature_flag'
			);
		}

		if ( ! class_exists( CartDeliverySelectionRevalidator::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'checkout_validation_enabled_revalidator_missing',
				$this->setting_missing_component_title( 'enable_checkout_delivery_selection_validation' ),
				$this->setting_missing_component_message( 'enable_checkout_delivery_selection_validation' ),
				'feature_flag'
			);
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 */
	private function check_rate_quote( array &$diagnostics ): void {
		$active_rules = $this->product_rule_repository->listActive( [ 'limit' => 1 ] );
		$active_cards = $this->rate_card_repository->list(
			[
				'status' => RecordStatus::Active->value,
				'limit'  => 1,
			]
		);

		if ( [] !== $active_rules && [] === $active_cards ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'active_product_rules_without_active_rate_cards',
				__( 'Active product rules without active rate cards', 'cetech-woocommerce-delivery-engine' ),
				__( 'Active product delivery rules exist but no active rate cards are configured. Delivery cannot be priced.', 'cetech-woocommerce-delivery-engine' ),
				'rate_card'
			);
		}

		$capture_enabled  = $this->feature_flags->is_enabled( 'enable_cart_delivery_selection_capture' );
		$checkout_enabled = $this->feature_flags->is_enabled( 'enable_checkout_delivery_selection_validation' );

		if ( ( $capture_enabled || $checkout_enabled ) && ! class_exists( RateQuoteEngine::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'rate_quote_engine_missing_with_capture_or_checkout',
				__( 'A required pricing component is missing', 'cetech-woocommerce-delivery-engine' ),
				__( 'Cart memory or checkout checking is turned on, but a required Delivery Engine component is missing from this installation. Contact CETECH support.', 'cetech-woocommerce-delivery-engine' ),
				'feature_flag'
			);
		}

		$offer_ids_with_active_cards = [];

		foreach ( $this->delivery_offer_repository->list( [ 'limit' => self::LIST_LIMIT ] ) as $offer ) {
			$offer_id = (int) ( $offer['id'] ?? 0 );
			if ( $offer_id > 0 && $this->rate_card_repository->countActiveByDeliveryOfferId( $offer_id ) > 0 ) {
				$offer_ids_with_active_cards[ $offer_id ] = true;
			}
		}

		foreach ( $this->delivery_offer_repository->list( [ 'limit' => self::LIST_LIMIT ] ) as $offer ) {
			if ( RecordStatus::Active->value !== (string) ( $offer['status'] ?? '' ) ) {
				continue;
			}

			$offer_id = (int) ( $offer['id'] ?? 0 );

			if ( $offer_id <= 0 ) {
				continue;
			}

			if ( ! isset( $offer_ids_with_active_cards[ $offer_id ] ) ) {
				$this->add(
					$diagnostics,
					DiagnosticSeverity::Warning,
					'delivery_offer_without_active_rate_cards',
					__( 'Active delivery offer without rate cards', 'cetech-woocommerce-delivery-engine' ),
					sprintf(
						/* translators: %s: delivery offer internal code */
						__( 'Active delivery offer "%s" has no matching active rate cards.', 'cetech-woocommerce-delivery-engine' ),
						(string) ( $offer['internal_code'] ?? (string) $offer_id )
					),
					'delivery_offer',
					$offer_id
				);
			}
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 */
	private function check_woocommerce_shipping_rate_calculation( array &$diagnostics ): void {
		if ( ! $this->feature_flags->is_enabled( ShippingRateCalculationGate::SHIPPING_FLAG ) ) {
			return;
		}

		if ( ! $this->feature_flags->is_enabled( 'enable_product_delivery_selector' ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'shipping_calculation_enabled_selector_disabled',
				$this->setting_requires_other_title( ShippingRateCalculationGate::SHIPPING_FLAG, 'enable_product_delivery_selector' ),
				$this->setting_requires_other_message( ShippingRateCalculationGate::SHIPPING_FLAG, 'enable_product_delivery_selector' ),
				'feature_flag'
			);
		}

		if ( ! $this->feature_flags->is_enabled( 'enable_cart_delivery_selection_capture' ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'shipping_calculation_enabled_cart_capture_disabled',
				$this->setting_requires_other_title( ShippingRateCalculationGate::SHIPPING_FLAG, 'enable_cart_delivery_selection_capture' ),
				$this->setting_requires_other_message( ShippingRateCalculationGate::SHIPPING_FLAG, 'enable_cart_delivery_selection_capture' ),
				'feature_flag'
			);
		}

		if ( ! $this->feature_flags->is_enabled( 'enable_checkout_delivery_selection_validation' ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'shipping_calculation_enabled_checkout_validation_disabled',
				$this->setting_requires_other_title( ShippingRateCalculationGate::SHIPPING_FLAG, 'enable_checkout_delivery_selection_validation' ),
				$this->setting_requires_other_message( ShippingRateCalculationGate::SHIPPING_FLAG, 'enable_checkout_delivery_selection_validation' ),
				'feature_flag'
			);
		}

		if ( ! class_exists( RateQuoteEngine::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'shipping_calculation_enabled_quote_engine_missing',
				$this->setting_missing_component_title( ShippingRateCalculationGate::SHIPPING_FLAG ),
				$this->setting_missing_component_message( ShippingRateCalculationGate::SHIPPING_FLAG ),
				'feature_flag'
			);
		}

		if ( ! class_exists( DestinationZoneMatcher::class ) || ! class_exists( PackageDestinationZoneResolver::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'shipping_calculation_destination_resolver_unavailable',
				$this->setting_missing_component_title( ShippingRateCalculationGate::SHIPPING_FLAG ),
				$this->setting_missing_component_message( ShippingRateCalculationGate::SHIPPING_FLAG ),
				'feature_flag'
			);
		}

		$active_zones = $this->destination_zone_repository->list(
			[
				'status' => RecordStatus::Active->value,
				'limit'  => 1,
			]
		);

		if ( [] === $active_zones ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'shipping_calculation_enabled_no_active_destination_zones',
				__( 'Shipping calculation enabled without active destination zones', 'cetech-woocommerce-delivery-engine' ),
				__( 'WooCommerce shipping rate calculation is enabled but no active destination zones exist.', 'cetech-woocommerce-delivery-engine' ),
				'destination_zone'
			);
		}

		$active_cards = $this->rate_card_repository->list(
			[
				'status' => RecordStatus::Active->value,
				'limit'  => 1,
			]
		);

		if ( [] === $active_cards ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'shipping_calculation_enabled_no_active_rate_cards',
				__( 'Shipping calculation enabled without active rate cards', 'cetech-woocommerce-delivery-engine' ),
				__( 'WooCommerce shipping rate calculation is enabled but no active rate cards exist.', 'cetech-woocommerce-delivery-engine' ),
				'rate_card'
			);
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 */
	private function check_order_delivery_snapshot_persistence( array &$diagnostics ): void {
		if ( ! $this->feature_flags->is_enabled( OrderDeliverySnapshotGate::SNAPSHOT_FLAG ) ) {
			return;
		}

		if ( ! $this->feature_flags->is_enabled( ShippingRateCalculationGate::SHIPPING_FLAG ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'order_snapshot_enabled_shipping_calculation_disabled',
				$this->setting_requires_other_title( OrderDeliverySnapshotGate::SNAPSHOT_FLAG, ShippingRateCalculationGate::SHIPPING_FLAG ),
				$this->setting_requires_other_message( OrderDeliverySnapshotGate::SNAPSHOT_FLAG, ShippingRateCalculationGate::SHIPPING_FLAG ),
				'feature_flag'
			);
		}

		if ( ! $this->feature_flags->is_enabled( 'enable_checkout_delivery_selection_validation' ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'order_snapshot_enabled_checkout_validation_disabled',
				$this->setting_requires_other_title( OrderDeliverySnapshotGate::SNAPSHOT_FLAG, 'enable_checkout_delivery_selection_validation' ),
				$this->setting_requires_other_message( OrderDeliverySnapshotGate::SNAPSHOT_FLAG, 'enable_checkout_delivery_selection_validation' ),
				'feature_flag'
			);
		}

		if ( ! $this->feature_flags->is_enabled( 'enable_cart_delivery_selection_capture' ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'order_snapshot_enabled_cart_capture_disabled',
				$this->setting_requires_other_title( OrderDeliverySnapshotGate::SNAPSHOT_FLAG, 'enable_cart_delivery_selection_capture' ),
				$this->setting_requires_other_message( OrderDeliverySnapshotGate::SNAPSHOT_FLAG, 'enable_cart_delivery_selection_capture' ),
				'feature_flag'
			);
		}

		if ( ! $this->feature_flags->is_enabled( 'enable_product_delivery_selector' ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'order_snapshot_enabled_selector_disabled',
				$this->setting_requires_other_title( OrderDeliverySnapshotGate::SNAPSHOT_FLAG, 'enable_product_delivery_selector' ),
				$this->setting_requires_other_message( OrderDeliverySnapshotGate::SNAPSHOT_FLAG, 'enable_product_delivery_selector' ),
				'feature_flag'
			);
		}

		if ( ! class_exists( OrderDeliverySnapshotPersister::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'order_snapshot_enabled_persister_missing',
				$this->setting_missing_component_title( OrderDeliverySnapshotGate::SNAPSHOT_FLAG ),
				$this->setting_missing_component_message( OrderDeliverySnapshotGate::SNAPSHOT_FLAG ),
				'feature_flag'
			);
		}

		if ( ! function_exists( 'WC' ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'order_snapshot_enabled_woocommerce_unavailable',
				__( 'Order snapshot enabled without WooCommerce', 'cetech-woocommerce-delivery-engine' ),
				__( 'Order delivery snapshot persistence is enabled but WooCommerce is not available.', 'cetech-woocommerce-delivery-engine' ),
				'feature_flag'
			);
		}

		if ( ! class_exists( OrderDeliverySnapshotReader::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'order_snapshot_enabled_reader_missing',
				$this->setting_missing_component_title( OrderDeliverySnapshotGate::SNAPSHOT_FLAG ),
				$this->setting_missing_component_message( OrderDeliverySnapshotGate::SNAPSHOT_FLAG ),
				'feature_flag'
			);
		}

		if ( ! class_exists( OrderDeliverySnapshotAdminDisplay::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'order_snapshot_enabled_admin_display_missing',
				$this->setting_missing_component_title( OrderDeliverySnapshotGate::SNAPSHOT_FLAG ),
				$this->setting_missing_component_message( OrderDeliverySnapshotGate::SNAPSHOT_FLAG ),
				'feature_flag'
			);
		}

		if ( ! class_exists( OrderDeliverySnapshotIntegrity::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'order_snapshot_enabled_integrity_checker_missing',
				$this->setting_missing_component_title( OrderDeliverySnapshotGate::SNAPSHOT_FLAG ),
				$this->setting_missing_component_message( OrderDeliverySnapshotGate::SNAPSHOT_FLAG ),
				'feature_flag'
			);
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 */
	private function check_customer_order_delivery_summary( array &$diagnostics ): void {
		if ( ! $this->feature_flags->is_enabled( CustomerOrderDeliverySummaryBuilder::SUMMARY_FLAG ) ) {
			return;
		}

		if ( ! $this->feature_flags->is_enabled( OrderDeliverySnapshotGate::SNAPSHOT_FLAG ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'customer_summary_enabled_snapshot_persistence_disabled',
				$this->setting_requires_other_title( CustomerOrderDeliverySummaryBuilder::SUMMARY_FLAG, OrderDeliverySnapshotGate::SNAPSHOT_FLAG ),
				sprintf(
					/* translators: 1: enabled setting label, 2: required setting label */
					__( '“%1$s” is turned on, but “%2$s” is still off. Existing orders may have no saved delivery details to display. Turn on the required setting in Settings first.', 'cetech-woocommerce-delivery-engine' ),
					$this->setting_label( CustomerOrderDeliverySummaryBuilder::SUMMARY_FLAG ),
					$this->setting_label( OrderDeliverySnapshotGate::SNAPSHOT_FLAG )
				),
				'feature_flag'
			);
		}

		if ( ! class_exists( OrderDeliverySnapshotReader::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'customer_summary_enabled_reader_missing',
				$this->setting_missing_component_title( CustomerOrderDeliverySummaryBuilder::SUMMARY_FLAG ),
				$this->setting_missing_component_message( CustomerOrderDeliverySummaryBuilder::SUMMARY_FLAG ),
				'feature_flag'
			);
		}

		if ( ! class_exists( OrderDeliverySnapshotIntegrity::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'customer_summary_enabled_integrity_checker_missing',
				$this->setting_missing_component_title( CustomerOrderDeliverySummaryBuilder::SUMMARY_FLAG ),
				$this->setting_missing_component_message( CustomerOrderDeliverySummaryBuilder::SUMMARY_FLAG ),
				'feature_flag'
			);
		}

		if ( ! class_exists( CustomerOrderDeliverySummaryRenderer::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'customer_summary_enabled_renderer_missing',
				$this->setting_missing_component_title( CustomerOrderDeliverySummaryBuilder::SUMMARY_FLAG ),
				$this->setting_missing_component_message( CustomerOrderDeliverySummaryBuilder::SUMMARY_FLAG ),
				'feature_flag'
			);
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 */
	private function check_customer_email_delivery_summary( array &$diagnostics ): void {
		if ( ! $this->feature_flags->is_enabled( CustomerOrderDeliveryEmailSummaryRenderer::EMAIL_SUMMARY_FLAG ) ) {
			return;
		}

		if ( ! $this->feature_flags->is_enabled( CustomerOrderDeliverySummaryBuilder::SUMMARY_FLAG ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'email_summary_enabled_customer_summary_disabled',
				$this->setting_requires_other_title( CustomerOrderDeliveryEmailSummaryRenderer::EMAIL_SUMMARY_FLAG, CustomerOrderDeliverySummaryBuilder::SUMMARY_FLAG ),
				sprintf(
					/* translators: 1: enabled setting label, 2: required setting label */
					__( '“%1$s” is turned on, but “%2$s” is still off. Turn on the required setting in Settings first so email and order pages stay consistent.', 'cetech-woocommerce-delivery-engine' ),
					$this->setting_label( CustomerOrderDeliveryEmailSummaryRenderer::EMAIL_SUMMARY_FLAG ),
					$this->setting_label( CustomerOrderDeliverySummaryBuilder::SUMMARY_FLAG )
				),
				'feature_flag'
			);
		}

		if ( ! $this->feature_flags->is_enabled( OrderDeliverySnapshotGate::SNAPSHOT_FLAG ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'email_summary_enabled_snapshot_persistence_disabled',
				$this->setting_requires_other_title( CustomerOrderDeliveryEmailSummaryRenderer::EMAIL_SUMMARY_FLAG, OrderDeliverySnapshotGate::SNAPSHOT_FLAG ),
				sprintf(
					/* translators: 1: enabled setting label, 2: required setting label */
					__( '“%1$s” is turned on, but “%2$s” is still off. Existing orders may have no saved delivery details to display. Turn on the required setting in Settings first.', 'cetech-woocommerce-delivery-engine' ),
					$this->setting_label( CustomerOrderDeliveryEmailSummaryRenderer::EMAIL_SUMMARY_FLAG ),
					$this->setting_label( OrderDeliverySnapshotGate::SNAPSHOT_FLAG )
				),
				'feature_flag'
			);
		}

		if ( ! class_exists( CustomerOrderDeliverySummaryBuilder::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'email_summary_enabled_builder_missing',
				$this->setting_missing_component_title( CustomerOrderDeliveryEmailSummaryRenderer::EMAIL_SUMMARY_FLAG ),
				$this->setting_missing_component_message( CustomerOrderDeliveryEmailSummaryRenderer::EMAIL_SUMMARY_FLAG ),
				'feature_flag'
			);
		}

		if ( ! class_exists( OrderDeliverySnapshotReader::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'email_summary_enabled_reader_missing',
				$this->setting_missing_component_title( CustomerOrderDeliveryEmailSummaryRenderer::EMAIL_SUMMARY_FLAG ),
				$this->setting_missing_component_message( CustomerOrderDeliveryEmailSummaryRenderer::EMAIL_SUMMARY_FLAG ),
				'feature_flag'
			);
		}

		if ( ! class_exists( OrderDeliverySnapshotIntegrity::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'email_summary_enabled_integrity_checker_missing',
				$this->setting_missing_component_title( CustomerOrderDeliveryEmailSummaryRenderer::EMAIL_SUMMARY_FLAG ),
				$this->setting_missing_component_message( CustomerOrderDeliveryEmailSummaryRenderer::EMAIL_SUMMARY_FLAG ),
				'feature_flag'
			);
		}

		if ( ! class_exists( CustomerOrderDeliveryEmailSummaryRenderer::class ) ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'email_summary_enabled_renderer_missing',
				$this->setting_missing_component_title( CustomerOrderDeliveryEmailSummaryRenderer::EMAIL_SUMMARY_FLAG ),
				$this->setting_missing_component_message( CustomerOrderDeliveryEmailSummaryRenderer::EMAIL_SUMMARY_FLAG ),
				'feature_flag'
			);
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 */
	private function check_privacy( array &$diagnostics ): void {
		if ( $this->plugin_registers_public_rest_routes() ) {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Warning,
				'public_rest_route_detected',
				__( 'Public REST route detected', 'cetech-woocommerce-delivery-engine' ),
				__( 'The plugin codebase contains register_rest_route usage. Verify no private configuration is exposed.', 'cetech-woocommerce-delivery-engine' ),
				'privacy'
			);
		} else {
			$this->add(
				$diagnostics,
				DiagnosticSeverity::Info,
				'no_public_rest_routes_in_src',
				__( 'No public REST routes in plugin source', 'cetech-woocommerce-delivery-engine' ),
				__( 'Static inspection found no register_rest_route calls under src/.', 'cetech-woocommerce-delivery-engine' ),
				'privacy'
			);
		}
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 */
	private function setting_label( string $flag ): string {
		return FeatureFlagLabels::label( $flag );
	}

	private function setting_requires_other_title( string $enabled_flag, string $required_flag ): string {
		return sprintf(
			/* translators: 1: enabled setting label, 2: required setting label */
			__( '“%1$s” needs “%2$s”', 'cetech-woocommerce-delivery-engine' ),
			$this->setting_label( $enabled_flag ),
			$this->setting_label( $required_flag )
		);
	}

	private function setting_requires_other_message( string $enabled_flag, string $required_flag ): string {
		return sprintf(
			/* translators: 1: enabled setting label, 2: required setting label */
			__( '“%1$s” is turned on, but “%2$s” is still off. Turn on the required setting in Settings first.', 'cetech-woocommerce-delivery-engine' ),
			$this->setting_label( $enabled_flag ),
			$this->setting_label( $required_flag )
		);
	}

	private function setting_missing_component_title( string $enabled_flag ): string {
		return sprintf(
			/* translators: %s: setting label */
			__( '“%s” is missing a required component', 'cetech-woocommerce-delivery-engine' ),
			$this->setting_label( $enabled_flag )
		);
	}

	private function setting_missing_component_message( string $enabled_flag ): string {
		return sprintf(
			/* translators: %s: setting label */
			__( '“%s” is turned on, but a required Delivery Engine component is missing from this installation. Contact CETECH support.', 'cetech-woocommerce-delivery-engine' ),
			$this->setting_label( $enabled_flag )
		);
	}

	private function add(
		array &$diagnostics,
		DiagnosticSeverity $severity,
		string $code,
		string $title,
		string $message,
		?string $entity_type = null,
		?int $entity_id = null,
		?string $details = null
	): void {
		$diagnostics[] = new ConfigurationDiagnostic(
			$severity,
			$code,
			$title,
			$message,
			$entity_type,
			$entity_id,
			$details
		);
	}

	/**
	 * @param list<ConfigurationDiagnostic> $diagnostics
	 *
	 * @return array{error: int, warning: int, info: int}
	 */
	private function summarize( array $diagnostics ): array {
		$summary = [
			'error'   => 0,
			'warning' => 0,
			'info'    => 0,
		];

		foreach ( $diagnostics as $diagnostic ) {
			match ( $diagnostic->severity ) {
				DiagnosticSeverity::Error => ++$summary['error'],
				DiagnosticSeverity::Warning => ++$summary['warning'],
				DiagnosticSeverity::Info => ++$summary['info'],
				DiagnosticSeverity::Ok => null,
			};
		}

		return $summary;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function index_by_id( array $rows ): array {
		$indexed = [];

		foreach ( $rows as $row ) {
			$id = (int) ( $row['id'] ?? 0 );

			if ( $id > 0 ) {
				$indexed[ $id ] = $row;
			}
		}

		return $indexed;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function entity_code_detail( array $row, string $field ): string {
		$code = trim( (string) ( $row[ $field ] ?? '' ) );

		return '' !== $code ? sprintf( 'code=%s', $code ) : '';
	}

	private function is_non_active_status( string $status ): bool {
		return RecordStatus::Active->value !== $status;
	}

	private function is_valid_rule_type( string $rule_type ): bool {
		foreach ( DestinationRuleType::cases() as $case ) {
			if ( $case->value === $rule_type ) {
				return true;
			}
		}

		return false;
	}

	private function is_valid_match_mode( string $match_mode ): bool {
		foreach ( DestinationRuleMatchMode::cases() as $case ) {
			if ( $case->value === $match_mode ) {
				return true;
			}
		}

		return false;
	}

	private function is_valid_charge_type( string $charge_type ): bool {
		foreach ( RateCardChargeType::cases() as $case ) {
			if ( $case->value === $charge_type ) {
				return true;
			}
		}

		return false;
	}

	private function is_empty_public_address( mixed $stored ): bool {
		if ( null === $stored || '' === $stored ) {
			return true;
		}

		$string = trim( (string) $stored );

		if ( '' === $string ) {
			return true;
		}

		$decoded = json_decode( $string, true );

		if ( ! is_array( $decoded ) ) {
			return false;
		}

		$parts = [
			(string) ( $decoded['line1'] ?? '' ),
			(string) ( $decoded['line2'] ?? '' ),
			(string) ( $decoded['city'] ?? '' ),
			(string) ( $decoded['region'] ?? '' ),
			(string) ( $decoded['country_code'] ?? '' ),
			(string) ( $decoded['postcode'] ?? '' ),
		];

		return '' === implode( '', array_map( 'trim', $parts ) );
	}

	private function is_valid_internal_address_json( mixed $stored ): bool {
		if ( null === $stored || '' === $stored ) {
			return true;
		}

		$string = trim( (string) $stored );

		if ( '' === $string ) {
			return true;
		}

		$decoded = json_decode( $string, true );

		return is_array( $decoded );
	}

	/**
	 * @param array<string, mixed> $card
	 */
	private function rate_card_match_signature( array $card ): string {
		return implode(
			'|',
			[
				(string) ( $card['delivery_offer_id'] ?? 0 ),
				(string) ( $card['destination_zone_id'] ?? 0 ),
				$this->nullable_signature_part( $card['logistics_profile_id'] ?? null ),
				$this->nullable_signature_part( $card['supplier_id'] ?? null ),
				$this->nullable_signature_part( $card['origin_id'] ?? null ),
				strtoupper( trim( (string) ( $card['base_currency'] ?? '' ) ) ),
				(string) ( $card['priority'] ?? 0 ),
			]
		);
	}

	private function nullable_signature_part( mixed $value ): string {
		if ( null === $value || '' === $value ) {
			return '*';
		}

		$int = (int) $value;

		return $int > 0 ? (string) $int : '*';
	}

	private function plugin_registers_public_rest_routes(): bool {
		if ( ! defined( 'CETECH_DE_PATH' ) ) {
			return false;
		}

		$src_path = CETECH_DE_PATH . 'src';

		if ( ! is_dir( $src_path ) ) {
			return false;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $src_path, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			$contents = file_get_contents( $file->getPathname() );

			if ( false !== $contents && str_contains( $contents, 'register_rest_route' ) ) {
				return true;
			}
		}

		return false;
	}

	private function zone_has_canonical_coverage( int $zone_id ): bool {
		if ( $zone_id <= 0 || ! $this->coverage_groups instanceof \CetechDeliveryEngine\Domain\Coverage\CoverageGroupRepositoryInterface ) {
			return false;
		}
		foreach ( $this->coverage_groups->list_by_zone( $zone_id ) as $group ) {
			if ( $group->isUsable() ) {
				return true;
			}
		}

		return false;
	}
}
