<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Catalog;

/**
 * Attribute matching for catalog filters used by in-memory tests and effective eval.
 *
 * @phpstan-type AttrMap array<string, mixed>
 */
final class CatalogFilterMatcher {

	/**
	 * @param array<string, mixed> $attributes
	 * @param array<string, mixed> $filters
	 */
	public static function matches( array $attributes, array $filters ): bool {
		foreach ( $filters as $key => $wanted ) {
			if ( ! self::attribute_matches( $attributes, (string) $key, $wanted ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	private static function attribute_matches( array $attributes, string $key, mixed $wanted ): bool {
		return match ( $key ) {
			CatalogTargetFilters::PRODUCT_TYPE => self::same_string( $attributes[ CatalogTargetFilters::PRODUCT_TYPE ] ?? '', $wanted ),
			CatalogTargetFilters::STOCK_STATUS => self::same_string( $attributes[ CatalogTargetFilters::STOCK_STATUS ] ?? '', $wanted ),
			CatalogTargetFilters::SHIPPING_CLASS_ID => self::same_int( $attributes[ CatalogTargetFilters::SHIPPING_CLASS_ID ] ?? 0, $wanted ),
			CatalogTargetFilters::CATEGORY_ID => self::same_int( $attributes[ CatalogTargetFilters::CATEGORY_ID ] ?? 0, $wanted ) || self::in_int_list( $attributes['category_ids'] ?? [], $wanted ),
			CatalogTargetFilters::TAG_ID => self::same_int( $attributes[ CatalogTargetFilters::TAG_ID ] ?? 0, $wanted ) || self::in_int_list( $attributes['tag_ids'] ?? [], $wanted ),
			CatalogTargetFilters::SEARCH => self::search_matches( (string) ( $attributes['label'] ?? '' ), (string) ( $attributes['sku'] ?? '' ), $wanted ),
			CatalogTargetFilters::CONFIGURED_FULFILMENT => self::same_string( $attributes[ CatalogTargetFilters::CONFIGURED_FULFILMENT ] ?? '', $wanted ),
			CatalogTargetFilters::EFFECTIVE_FULFILMENT => self::same_string( $attributes[ CatalogTargetFilters::EFFECTIVE_FULFILMENT ] ?? '', $wanted ),
			CatalogTargetFilters::EXCEPTION_STATE => self::same_string( $attributes[ CatalogTargetFilters::EXCEPTION_STATE ] ?? CatalogTargetFilters::EXCEPTION_SITE_WIDE, $wanted ),
			CatalogTargetFilters::VARIATION_STATE => self::same_string( $attributes[ CatalogTargetFilters::VARIATION_STATE ] ?? CatalogTargetFilters::VARIATION_INHERIT, $wanted ),
			CatalogTargetFilters::DELIVERY_OPTION_ID => self::in_int_list( $attributes['delivery_option_ids'] ?? [], $wanted ) || self::same_int( $attributes[ CatalogTargetFilters::DELIVERY_OPTION_ID ] ?? 0, $wanted ),
			CatalogTargetFilters::LOGISTICS_PROFILE_ID => self::same_int( $attributes[ CatalogTargetFilters::LOGISTICS_PROFILE_ID ] ?? 0, $wanted ),
			CatalogTargetFilters::PICKUP_LOCATION_ID => self::same_int( $attributes[ CatalogTargetFilters::PICKUP_LOCATION_ID ] ?? 0, $wanted ) || self::in_int_list( $attributes['pickup_location_ids'] ?? [], $wanted ) || ( (int) $wanted > 0 && ! empty( $attributes['in_store_pickup'] ) ),
			CatalogTargetFilters::SUPPLIER_ID => self::same_int( $attributes[ CatalogTargetFilters::SUPPLIER_ID ] ?? 0, $wanted ),
			CatalogTargetFilters::ORIGIN_ID => self::same_int( $attributes[ CatalogTargetFilters::ORIGIN_ID ] ?? 0, $wanted ),
			CatalogTargetFilters::MISSING_USABLE_RATE => ! empty( $wanted ) ? ! empty( $attributes[ CatalogTargetFilters::MISSING_USABLE_RATE ] ) : true,
			CatalogTargetFilters::INVALID_EFFECTIVE => ! empty( $wanted ) ? ! empty( $attributes[ CatalogTargetFilters::INVALID_EFFECTIVE ] ) : true,
			default => true,
		};
	}

	private static function same_string( mixed $have, mixed $wanted ): bool {
		return (string) $have === (string) $wanted;
	}

	private static function same_int( mixed $have, mixed $wanted ): bool {
		return (int) $have === (int) $wanted && (int) $wanted > 0;
	}

	/**
	 * @param mixed $list
	 */
	private static function in_int_list( mixed $list, mixed $wanted ): bool {
		$wanted = (int) $wanted;
		if ( $wanted <= 0 || ! is_array( $list ) ) {
			return false;
		}
		foreach ( $list as $item ) {
			if ( (int) $item === $wanted ) {
				return true;
			}
		}

		return false;
	}

	private static function search_matches( string $label, string $sku, mixed $wanted ): bool {
		$needle = strtolower( trim( (string) $wanted ) );
		if ( '' === $needle ) {
			return true;
		}

		return str_contains( strtolower( $label ), $needle ) || str_contains( strtolower( $sku ), $needle );
	}
}
