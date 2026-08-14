<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldRegistry;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * Admin-facing field metadata derived from the authoritative registry.
 */
final class ConfigurationFieldCatalog {

	/**
	 * @return array{
	 *   key: string,
	 *   label: string,
	 *   description: string,
	 *   is_collection: bool,
	 *   value_type: string,
	 *   allows_disable: bool,
	 *   allowed_modes: list<string>,
	 *   entity_kind: string|null,
	 *   enum_options: array<string, string>|null
	 * }
	 */
	public static function describe( string $field_key ): array {
		$definition = ConfigurationFieldRegistry::get( $field_key );

		return [
			'key'            => $field_key,
			'label'          => self::label( $field_key ),
			'description'    => self::description( $field_key ),
			'is_collection'  => $definition->is_collection,
			'value_type'     => $definition->value_type->value,
			'allows_disable' => $definition->allows_disable,
			'allowed_modes'  => array_map(
				static fn ( object $mode ): string => $mode->value,
				$definition->allowed_modes
			),
			'entity_kind'    => self::entity_kind( $field_key ),
			'enum_options'   => self::enum_options( $field_key ),
		];
	}

	/**
	 * @return list<array{
	 *   key: string,
	 *   label: string,
	 *   description: string,
	 *   is_collection: bool,
	 *   value_type: string,
	 *   allows_disable: bool,
	 *   allowed_modes: list<string>,
	 *   entity_kind: string|null,
	 *   enum_options: array<string, string>|null
	 * }>
	 */
	public static function all(): array {
		$fields = [];

		foreach ( ConfigurationFieldRegistry::all() as $key => $_definition ) {
			$fields[] = self::describe( $key );
		}

		return $fields;
	}

	public static function label( string $field_key ): string {
		return match ( $field_key ) {
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY => 'Fulfilment',
			ConfigurationFieldKey::FULFILMENT_CHOICE => 'Delivery method',
			ConfigurationFieldKey::LOGISTICS_PROFILE_ID => 'Logistics profile',
			ConfigurationFieldKey::SUPPLIER_ID => 'Supplier',
			ConfigurationFieldKey::ORIGIN_ID => 'Origin',
			ConfigurationFieldKey::PRIORITY => 'Priority',
			ConfigurationFieldKey::ESTIMATED_DELIVERY => 'Estimated delivery',
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => 'Delivery options',
			default => $field_key,
		};
	}

	/**
	 * Business-facing fields shown on normal Product Exceptions / product summaries.
	 *
	 * @return list<string>
	 */
	public static function business_field_keys(): array {
		return [
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
			ConfigurationFieldKey::FULFILMENT_CHOICE,
			ConfigurationFieldKey::DELIVERY_OFFER_IDS,
			ConfigurationFieldKey::ESTIMATED_DELIVERY,
		];
	}

	/**
	 * Private / technical fields — never list individually on normal staff tables.
	 *
	 * @return list<string>
	 */
	public static function private_field_keys(): array {
		return [
			ConfigurationFieldKey::LOGISTICS_PROFILE_ID,
			ConfigurationFieldKey::SUPPLIER_ID,
			ConfigurationFieldKey::ORIGIN_ID,
			ConfigurationFieldKey::PRIORITY,
		];
	}

	public static function technical_delivery_details_label(): string {
		return 'Technical delivery details';
	}

	public static function is_business_field( string $field_key ): bool {
		return in_array( $field_key, self::business_field_keys(), true );
	}

	public static function is_private_field( string $field_key ): bool {
		return in_array( $field_key, self::private_field_keys(), true );
	}

	public static function description( string $field_key ): string {
		return match ( $field_key ) {
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY => 'Choose where this item is fulfilled from, such as In Store, In Warehouse, or International. This affects which delivery methods can be offered.',
			ConfigurationFieldKey::FULFILMENT_CHOICE => 'Whether the customer chooses delivery or store pickup for this path.',
			ConfigurationFieldKey::LOGISTICS_PROFILE_ID => 'A logistics profile groups the delivery handling rules used to fulfil an item, such as how it is dispatched or which delivery services can be used. Choose “Turn off” if this item should not use a profile.',
			ConfigurationFieldKey::SUPPLIER_ID => 'Private supplier reference. Choose “Turn off” if this item should not use a supplier.',
			ConfigurationFieldKey::ORIGIN_ID => 'Private origin reference. Choose “Turn off” if this item should not use an origin.',
			ConfigurationFieldKey::PRIORITY => 'Priority decides which delivery setup takes precedence if more than one setup could apply. A lower number is considered first. Most products can leave this unchanged. Zero is a valid value.',
			ConfigurationFieldKey::ESTIMATED_DELIVERY => 'Customer-facing estimated delivery, such as 3–5 days. Leave this inherited to follow the site-wide default. Setting a different value here does not freeze the delivery option or charge.',
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => 'Choose whether this product should use the delivery options it inherits, add more options, remove some, or use only these options. Choosing an empty list means no delivery options for this setup.',
			default => '',
		};
	}

	public static function entity_kind( string $field_key ): ?string {
		return match ( $field_key ) {
			ConfigurationFieldKey::LOGISTICS_PROFILE_ID => 'logistics_profile',
			ConfigurationFieldKey::SUPPLIER_ID => 'supplier',
			ConfigurationFieldKey::ORIGIN_ID => 'origin',
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => 'delivery_offer',
			default => null,
		};
	}

	/**
	 * @return array<string, string>|null
	 */
	public static function enum_options( string $field_key ): ?array {
		return match ( $field_key ) {
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [
				FulfilmentAvailability::InternationalFulfilment->value => 'International',
				FulfilmentAvailability::InStore->value => 'In Store',
				FulfilmentAvailability::InWarehouse->value => 'In Warehouse',
			],
			ConfigurationFieldKey::FULFILMENT_CHOICE => [
				FulfilmentChoice::Delivery->value => 'Delivery',
				FulfilmentChoice::StorePickup->value => 'Store pickup',
			],
			default => null,
		};
	}

	public static function slice_label( string $slice_key ): string {
		if ( '' === $slice_key ) {
			return 'Default delivery setup';
		}

		$options = self::enum_options( ConfigurationFieldKey::FULFILMENT_AVAILABILITY );

		return $options[ $slice_key ] ?? $slice_key;
	}
}
