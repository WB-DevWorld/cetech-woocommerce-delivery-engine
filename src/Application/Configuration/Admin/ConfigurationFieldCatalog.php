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
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY => 'Fulfilment availability',
			ConfigurationFieldKey::FULFILMENT_CHOICE => 'Fulfilment choice',
			ConfigurationFieldKey::LOGISTICS_PROFILE_ID => 'Logistics profile',
			ConfigurationFieldKey::SUPPLIER_ID => 'Supplier',
			ConfigurationFieldKey::ORIGIN_ID => 'Origin',
			ConfigurationFieldKey::PRIORITY => 'Priority',
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => 'Delivery offers',
			default => $field_key,
		};
	}

	public static function description( string $field_key ): string {
		return match ( $field_key ) {
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY => 'Which fulfilment path these settings apply to, such as In Store, In Warehouse, or International.',
			ConfigurationFieldKey::FULFILMENT_CHOICE => 'Whether the customer chooses delivery or store pickup for this path.',
			ConfigurationFieldKey::LOGISTICS_PROFILE_ID => 'Private logistics profile used for fulfilment planning. Choose “Turn off” if this item should not use a profile.',
			ConfigurationFieldKey::SUPPLIER_ID => 'Private supplier reference. Choose “Turn off” if this item should not use a supplier.',
			ConfigurationFieldKey::ORIGIN_ID => 'Private origin reference. Choose “Turn off” if this item should not use an origin.',
			ConfigurationFieldKey::PRIORITY => 'Relative priority when multiple configurations compete. Zero is a valid value.',
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => 'Choose whether this product should use the delivery options it inherits, add more options, remove some, or use its own list. Choosing an empty list means no delivery options for this setup.',
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
				FulfilmentAvailability::InternationalFulfilment->value => 'International fulfilment',
				FulfilmentAvailability::InStore->value => 'In store',
				FulfilmentAvailability::InWarehouse->value => 'In warehouse',
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
