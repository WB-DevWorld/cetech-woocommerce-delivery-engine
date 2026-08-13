<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationFieldValueType;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;

/**
 * Authoritative storage-contract registry for scoped configuration fields.
 */
final class ConfigurationFieldRegistry {

	/** @var array<string, ConfigurationFieldDefinition>|null */
	private static ?array $definitions = null;

	public static function has( string $field_key ): bool {
		return isset( self::all()[ $field_key ] );
	}

	public static function get( string $field_key ): ConfigurationFieldDefinition {
		$definitions = self::all();

		if ( ! isset( $definitions[ $field_key ] ) ) {
			throw new InvalidConfigurationException(
				sprintf( 'Unknown configuration field key: %s', $field_key )
			);
		}

		return $definitions[ $field_key ];
	}

	/**
	 * @return array<string, ConfigurationFieldDefinition>
	 */
	public static function all(): array {
		if ( null !== self::$definitions ) {
			return self::$definitions;
		}

		self::$definitions = [
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY => new ConfigurationFieldDefinition(
				ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
				false,
				ConfigurationFieldValueType::String,
				[ ScalarConfigurationMode::Inherit, ScalarConfigurationMode::Override ],
				false,
				false,
				false,
				static fn ( mixed $value ): string => self::normalize_slug( $value ),
				static function ( mixed $value ): void {
					self::assert_string_enum( $value, FulfilmentAvailability::cases(), ConfigurationFieldKey::FULFILMENT_AVAILABILITY );
				}
			),
			ConfigurationFieldKey::FULFILMENT_CHOICE => new ConfigurationFieldDefinition(
				ConfigurationFieldKey::FULFILMENT_CHOICE,
				false,
				ConfigurationFieldValueType::String,
				[ ScalarConfigurationMode::Inherit, ScalarConfigurationMode::Override ],
				false,
				false,
				false,
				static fn ( mixed $value ): string => self::normalize_slug( $value ),
				static function ( mixed $value ): void {
					self::assert_string_enum( $value, FulfilmentChoice::cases(), ConfigurationFieldKey::FULFILMENT_CHOICE );
				}
			),
			ConfigurationFieldKey::LOGISTICS_PROFILE_ID => new ConfigurationFieldDefinition(
				ConfigurationFieldKey::LOGISTICS_PROFILE_ID,
				false,
				ConfigurationFieldValueType::Int,
				[
					ScalarConfigurationMode::Inherit,
					ScalarConfigurationMode::Override,
					ScalarConfigurationMode::Disable,
				],
				true,
				false,
				false,
				static fn ( mixed $value ): int => self::normalize_positive_int( $value ),
				static function ( mixed $value ): void {
					self::assert_positive_int( $value, ConfigurationFieldKey::LOGISTICS_PROFILE_ID );
				}
			),
			ConfigurationFieldKey::SUPPLIER_ID => new ConfigurationFieldDefinition(
				ConfigurationFieldKey::SUPPLIER_ID,
				false,
				ConfigurationFieldValueType::Int,
				[
					ScalarConfigurationMode::Inherit,
					ScalarConfigurationMode::Override,
					ScalarConfigurationMode::Disable,
				],
				true,
				false,
				false,
				static fn ( mixed $value ): int => self::normalize_positive_int( $value ),
				static function ( mixed $value ): void {
					self::assert_positive_int( $value, ConfigurationFieldKey::SUPPLIER_ID );
				}
			),
			ConfigurationFieldKey::ORIGIN_ID => new ConfigurationFieldDefinition(
				ConfigurationFieldKey::ORIGIN_ID,
				false,
				ConfigurationFieldValueType::Int,
				[
					ScalarConfigurationMode::Inherit,
					ScalarConfigurationMode::Override,
					ScalarConfigurationMode::Disable,
				],
				true,
				false,
				false,
				static fn ( mixed $value ): int => self::normalize_positive_int( $value ),
				static function ( mixed $value ): void {
					self::assert_positive_int( $value, ConfigurationFieldKey::ORIGIN_ID );
				}
			),
			ConfigurationFieldKey::PRIORITY => new ConfigurationFieldDefinition(
				ConfigurationFieldKey::PRIORITY,
				false,
				ConfigurationFieldValueType::Int,
				[ ScalarConfigurationMode::Inherit, ScalarConfigurationMode::Override ],
				false,
				false,
				false,
				static fn ( mixed $value ): int => self::normalize_int_including_zero( $value ),
				static function ( mixed $value ): void {
					self::assert_int_including_zero( $value, ConfigurationFieldKey::PRIORITY );
				}
			),
			ConfigurationFieldKey::ESTIMATED_DELIVERY => new ConfigurationFieldDefinition(
				ConfigurationFieldKey::ESTIMATED_DELIVERY,
				false,
				ConfigurationFieldValueType::String,
				[ ScalarConfigurationMode::Inherit, ScalarConfigurationMode::Override ],
				false,
				false,
				false,
				static fn ( mixed $value ): string => self::normalize_estimated_delivery( $value ),
				static function ( mixed $value ): void {
					self::assert_estimated_delivery( $value, ConfigurationFieldKey::ESTIMATED_DELIVERY );
				},
				true
			),
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => new ConfigurationFieldDefinition(
				ConfigurationFieldKey::DELIVERY_OFFER_IDS,
				true,
				ConfigurationFieldValueType::IntList,
				[
					CollectionConfigurationMode::Inherit,
					CollectionConfigurationMode::Add,
					CollectionConfigurationMode::Remove,
					CollectionConfigurationMode::Replace,
				],
				false,
				true,
				true,
				static fn ( mixed $value ): array => self::normalize_positive_int_list( $value ),
				static function ( mixed $value ): void {
					self::assert_positive_int_list( $value, ConfigurationFieldKey::DELIVERY_OFFER_IDS );
				}
			),
		];

		return self::$definitions;
	}

	/**
	 * @return list<string>
	 */
	public static function scalar_keys(): array {
		$keys = [];

		foreach ( self::all() as $key => $definition ) {
			if ( ! $definition->is_collection ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * @return list<string>
	 */
	public static function collection_keys(): array {
		$keys = [];

		foreach ( self::all() as $key => $definition ) {
			if ( $definition->is_collection ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * Reset cached definitions (tests only).
	 */
	public static function reset_for_tests(): void {
		self::$definitions = null;
	}

	public static function is_optional( string $field_key ): bool {
		return self::has( $field_key ) && self::get( $field_key )->is_optional;
	}

	private static function normalize_estimated_delivery( mixed $value ): string {
		$text = trim( (string) $value );
		$text = preg_replace( '/\s+/', ' ', $text );

		return is_string( $text ) ? $text : '';
	}

	private static function assert_estimated_delivery( mixed $value, string $field_key ): void {
		if ( ! is_string( $value ) || '' === $value ) {
			throw new InvalidConfigurationException(
				sprintf( 'Field %s requires a non-empty estimated delivery value for OVERRIDE.', $field_key )
			);
		}

		if ( strlen( $value ) > 120 ) {
			throw new InvalidConfigurationException(
				sprintf( 'Field %s exceeds the maximum length of 120 characters.', $field_key )
			);
		}
	}

	private static function normalize_slug( mixed $value ): string {
		$slug = strtolower( trim( (string) $value ) );
		$slug = preg_replace( '/[^a-z0-9_\-]/', '', $slug );

		return is_string( $slug ) ? $slug : '';
	}

	/**
	 * @param list<\BackedEnum> $cases
	 */
	private static function assert_string_enum( mixed $value, array $cases, string $field_key ): void {
		if ( ! is_string( $value ) || '' === $value ) {
			throw new InvalidConfigurationException(
				sprintf( 'Field %s requires a non-empty string value for OVERRIDE.', $field_key )
			);
		}

		foreach ( $cases as $case ) {
			if ( $case->value === $value ) {
				return;
			}
		}

		throw new InvalidConfigurationException(
			sprintf( 'Field %s has an invalid enum value: %s', $field_key, $value )
		);
	}

	private static function normalize_positive_int( mixed $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) && is_numeric( $value ) && ! str_contains( $value, '.' ) ) {
			return (int) $value;
		}

		throw new InvalidConfigurationException( 'Expected a positive integer value.' );
	}

	private static function normalize_int_including_zero( mixed $value ): int {
		if ( is_bool( $value ) ) {
			throw new InvalidConfigurationException( 'Boolean is not a valid integer configuration value.' );
		}

		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) && is_numeric( $value ) && ! str_contains( trim( $value ), '.' ) ) {
			return (int) $value;
		}

		if ( is_float( $value ) ) {
			throw new InvalidConfigurationException( 'Non-integer numeric values are not accepted.' );
		}

		throw new InvalidConfigurationException( 'Expected an integer value.' );
	}

	private static function assert_positive_int( mixed $value, string $field_key ): void {
		if ( ! is_int( $value ) || $value <= 0 ) {
			throw new InvalidConfigurationException(
				sprintf( 'Field %s OVERRIDE requires a positive integer (0 is invalid; use DISABLE for none).', $field_key )
			);
		}
	}

	private static function assert_int_including_zero( mixed $value, string $field_key ): void {
		if ( ! is_int( $value ) ) {
			throw new InvalidConfigurationException(
				sprintf( 'Field %s OVERRIDE requires an integer (including 0).', $field_key )
			);
		}
	}

	/**
	 * @return list<int>
	 */
	private static function normalize_positive_int_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			throw new InvalidConfigurationException( 'Collection value must be a list of positive integers.' );
		}

		$normalized = [];
		$seen       = [];

		foreach ( $value as $item ) {
			if ( is_bool( $item ) || is_float( $item ) || ( is_string( $item ) && ! is_numeric( $item ) ) ) {
				throw new InvalidConfigurationException( 'Collection members must be positive integers.' );
			}

			if ( is_string( $item ) && ( str_contains( $item, '.' ) || ! ctype_digit( ltrim( $item, '+' ) ) ) ) {
				throw new InvalidConfigurationException( 'Collection members must be positive integers.' );
			}

			$int = (int) $item;

			if ( $int <= 0 ) {
				throw new InvalidConfigurationException( 'Collection members must be positive integers.' );
			}

			if ( isset( $seen[ $int ] ) ) {
				continue;
			}

			$seen[ $int ] = true;
			$normalized[] = $int;
		}

		return $normalized;
	}

	/**
	 * @param list<int> $value
	 */
	private static function assert_positive_int_list( mixed $value, string $field_key ): void {
		if ( ! is_array( $value ) ) {
			throw new InvalidConfigurationException(
				sprintf( 'Field %s requires a list of positive integers.', $field_key )
			);
		}

		foreach ( $value as $item ) {
			if ( ! is_int( $item ) || $item <= 0 ) {
				throw new InvalidConfigurationException(
					sprintf( 'Field %s members must be positive integers.', $field_key )
				);
			}
		}
	}
}
