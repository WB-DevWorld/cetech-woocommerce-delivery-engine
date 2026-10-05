<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Catalog;

/**
 * Named catalog targeting filters. Empty MatchingFilters still match nothing
 * unless Entire Catalog was confirmed.
 */
final class CatalogTargetFilters {

	public const PRODUCT_TYPE = 'product_type';

	public const STOCK_STATUS = 'stock_status';

	public const SHIPPING_CLASS_ID = 'shipping_class_id';

	public const CATEGORY_ID = 'category_id';

	public const TAG_ID = 'tag_id';

	public const SEARCH = 'search';

	public const CONFIGURED_FULFILMENT = 'configured_fulfilment';

	public const EFFECTIVE_FULFILMENT = 'effective_fulfilment';

	public const EXCEPTION_STATE = 'exception_state';

	public const VARIATION_STATE = 'variation_state';

	public const DELIVERY_OPTION_ID = 'delivery_option_id';

	public const LOGISTICS_PROFILE_ID = 'logistics_profile_id';

	public const PICKUP_LOCATION_ID = 'pickup_location_id';

	public const SUPPLIER_ID = 'supplier_id';

	public const ORIGIN_ID = 'origin_id';

	public const MISSING_USABLE_RATE = 'missing_usable_rate';

	public const INVALID_EFFECTIVE = 'invalid_effective';

	public const EXCEPTION_SITE_WIDE = 'site_wide';

	public const EXCEPTION_PRODUCT = 'product_exception';

	public const VARIATION_INHERIT = 'inherit';

	public const VARIATION_OVERRIDE = 'override';

	/**
	 * @return list<string>
	 */
	public static function keys_requiring_effective_eval(): array {
		return [
			self::EFFECTIVE_FULFILMENT,
			self::MISSING_USABLE_RATE,
			self::INVALID_EFFECTIVE,
		];
	}

	/**
	 * @return list<string>
	 */
	public static function supported_keys(): array {
		return [
			self::PRODUCT_TYPE,
			self::STOCK_STATUS,
			self::SHIPPING_CLASS_ID,
			self::CATEGORY_ID,
			self::TAG_ID,
			self::SEARCH,
			self::CONFIGURED_FULFILMENT,
			self::EFFECTIVE_FULFILMENT,
			self::EXCEPTION_STATE,
			self::VARIATION_STATE,
			self::DELIVERY_OPTION_ID,
			self::LOGISTICS_PROFILE_ID,
			self::PICKUP_LOCATION_ID,
			self::SUPPLIER_ID,
			self::ORIGIN_ID,
			self::MISSING_USABLE_RATE,
			self::INVALID_EFFECTIVE,
		];
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	public static function assert_supported( array $filters ): void {
		$unsupported = [];
		foreach ( $filters as $key => $value ) {
			unset( $value );
			$key = (string) $key;
			if ( ! in_array( $key, self::supported_keys(), true ) ) {
				$unsupported[] = $key;
			}
		}
		if ( [] !== $unsupported ) {
			throw new \InvalidArgumentException( 'Unsupported catalog filter.' );
		}
	}

	/**
	 * @param array<string, mixed> $filters
	 *
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $filters ): array {
		$clean       = [];
		$unsupported = [];
		foreach ( $filters as $key => $value ) {
			$key  = (string) $key;
			$kept = self::kept_value( $key, $value );
			if ( null === $kept ) {
				continue;
			}
			if ( ! in_array( $key, self::supported_keys(), true ) ) {
				$unsupported[] = $key;
				continue;
			}
			$clean[ $key ] = $kept;
		}
		if ( [] !== $unsupported ) {
			throw new \InvalidArgumentException( 'Unsupported catalog filter.' );
		}

		return $clean;
	}

	private static function kept_value( string $key, mixed $value ): mixed {
		if ( in_array( $key, [ self::MISSING_USABLE_RATE, self::INVALID_EFFECTIVE ], true ) || ! in_array( $key, self::supported_keys(), true ) ) {
			if ( true === $value || 1 === $value || '1' === $value || 'true' === $value ) {
				return true;
			}
			if ( in_array( $key, [ self::MISSING_USABLE_RATE, self::INVALID_EFFECTIVE ], true ) ) {
				return null;
			}
		}
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			$int = (int) $value;
			return $int > 0 ? $int : null;
		}
		if ( is_string( $value ) ) {
			$value = trim( $value );
			return '' !== $value ? $value : null;
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	public static function requires_effective_eval( array $filters ): bool {
		foreach ( self::keys_requiring_effective_eval() as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}
}
