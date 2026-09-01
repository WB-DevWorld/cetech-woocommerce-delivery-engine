<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\WPML;

/**
 * Registers and translates supported customer-facing dynamic fields.
 *
 * Supported:
 * - delivery offer public_label, public_description
 * - pickup location_name, public_opening_hours, public_pickup_instructions, readiness_estimate
 * - destination zone public_label
 *
 * Intentionally excluded: IDs, internal_code, internal_name, route, service_level,
 * tax/price_basis, numeric processing values, duration_unit, status, matching rules,
 * rate cards, currency/amount, canonical pickup public_address.
 */
final class WpmlPublicCopyCatalog {

	public function __construct(
		private WpmlDynamicStringTranslator $translator
	) {
	}

	public function translator(): WpmlDynamicStringTranslator {
		return $this->translator;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public function register_delivery_offer( array $row ): void {
		$id = (int) ( $row['id'] ?? 0 );

		if ( $id <= 0 ) {
			return;
		}

		$this->translator->register(
			WpmlPublicStringNames::delivery_offer( $id, WpmlPublicStringNames::OFFER_PUBLIC_LABEL ),
			(string) ( $row['public_label'] ?? '' )
		);
		$this->translator->register(
			WpmlPublicStringNames::delivery_offer( $id, WpmlPublicStringNames::OFFER_PUBLIC_DESCRIPTION ),
			(string) ( $row['public_description'] ?? '' )
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public function register_pickup_location( array $row ): void {
		$id = (int) ( $row['id'] ?? 0 );

		if ( $id <= 0 ) {
			return;
		}

		$this->translator->register(
			WpmlPublicStringNames::pickup_location( $id, WpmlPublicStringNames::PICKUP_LOCATION_NAME ),
			(string) ( $row['location_name'] ?? '' )
		);
		$this->translator->register(
			WpmlPublicStringNames::pickup_location( $id, WpmlPublicStringNames::PICKUP_OPENING_HOURS ),
			(string) ( $row['public_opening_hours'] ?? '' )
		);
		$this->translator->register(
			WpmlPublicStringNames::pickup_location( $id, WpmlPublicStringNames::PICKUP_INSTRUCTIONS ),
			(string) ( $row['public_pickup_instructions'] ?? '' )
		);
		$this->translator->register(
			WpmlPublicStringNames::pickup_location( $id, WpmlPublicStringNames::PICKUP_READINESS ),
			(string) ( $row['readiness_estimate'] ?? '' )
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public function register_destination_zone( array $row ): void {
		$id = (int) ( $row['id'] ?? 0 );

		if ( $id <= 0 ) {
			return;
		}

		$this->translator->register(
			WpmlPublicStringNames::destination_zone( $id, WpmlPublicStringNames::ZONE_PUBLIC_LABEL ),
			(string) ( $row['public_label'] ?? '' )
		);
	}

	public function translate_delivery_offer_label( int $id, string $source ): string {
		return $this->translator->translate(
			WpmlPublicStringNames::delivery_offer( $id, WpmlPublicStringNames::OFFER_PUBLIC_LABEL ),
			$source
		);
	}

	public function translate_delivery_offer_description( int $id, string $source ): string {
		return $this->translator->translate(
			WpmlPublicStringNames::delivery_offer( $id, WpmlPublicStringNames::OFFER_PUBLIC_DESCRIPTION ),
			$source
		);
	}

	public function translate_pickup_location_name( int $id, string $source ): string {
		return $this->translator->translate(
			WpmlPublicStringNames::pickup_location( $id, WpmlPublicStringNames::PICKUP_LOCATION_NAME ),
			$source
		);
	}

	public function translate_pickup_opening_hours( int $id, string $source ): string {
		return $this->translator->translate(
			WpmlPublicStringNames::pickup_location( $id, WpmlPublicStringNames::PICKUP_OPENING_HOURS ),
			$source
		);
	}

	public function translate_pickup_instructions( int $id, string $source ): string {
		return $this->translator->translate(
			WpmlPublicStringNames::pickup_location( $id, WpmlPublicStringNames::PICKUP_INSTRUCTIONS ),
			$source
		);
	}

	public function translate_pickup_readiness( int $id, string $source ): string {
		return $this->translator->translate(
			WpmlPublicStringNames::pickup_location( $id, WpmlPublicStringNames::PICKUP_READINESS ),
			$source
		);
	}

	public function translate_destination_zone_label( int $id, string $source ): string {
		return $this->translator->translate(
			WpmlPublicStringNames::destination_zone( $id, WpmlPublicStringNames::ZONE_PUBLIC_LABEL ),
			$source
		);
	}
}
