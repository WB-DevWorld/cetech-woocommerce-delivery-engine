<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

use CetechDeliveryEngine\Domain\Enum\ConfigurationFieldValueType;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;

/**
 * Explicit scalar field instruction for one scope.
 */
final class ScalarFieldInstruction {

	private function __construct(
		public readonly string $field_key,
		public readonly ScalarConfigurationMode $mode,
		public readonly mixed $value
	) {
	}

	public static function inherit( string $field_key ): self {
		return self::create( $field_key, ScalarConfigurationMode::Inherit, null );
	}

	public static function override( string $field_key, mixed $value ): self {
		return self::create( $field_key, ScalarConfigurationMode::Override, $value );
	}

	public static function disable( string $field_key ): self {
		return self::create( $field_key, ScalarConfigurationMode::Disable, null );
	}

	public static function create( string $field_key, ScalarConfigurationMode $mode, mixed $value ): self {
		$definition = ConfigurationFieldRegistry::get( $field_key );

		if ( $definition->is_collection ) {
			throw new InvalidConfigurationException(
				sprintf( 'Field %s is a collection field, not a scalar.', $field_key )
			);
		}

		if ( ! $definition->allows_mode( $mode->value ) ) {
			throw new InvalidConfigurationException(
				sprintf( 'Mode %s is not allowed for field %s.', $mode->value, $field_key )
			);
		}

		if ( ScalarConfigurationMode::Disable === $mode && ! $definition->allows_disable ) {
			throw new InvalidConfigurationException(
				sprintf( 'DISABLE is not supported for field %s.', $field_key )
			);
		}

		if ( ScalarConfigurationMode::Inherit === $mode || ScalarConfigurationMode::Disable === $mode ) {
			if ( null !== $value ) {
				throw new InvalidConfigurationException(
					sprintf( 'Mode %s for field %s must not carry an active override value.', $mode->value, $field_key )
				);
			}

			return new self( $field_key, $mode, null );
		}

		if ( null === $value ) {
			throw new InvalidConfigurationException(
				sprintf( 'OVERRIDE for field %s requires a typed value (null is not inherit).', $field_key )
			);
		}

		$normalizer = $definition->normalizer;
		$normalized = is_callable( $normalizer ) ? $normalizer( $value ) : $value;

		$validator = $definition->validator;
		if ( is_callable( $validator ) ) {
			$validator( $normalized );
		}

		return new self( $field_key, $mode, $normalized );
	}

	/**
	 * @return array{field_key: string, mode: string, value: mixed, value_type: string}
	 */
	public function toStorageArray(): array {
		$definition = ConfigurationFieldRegistry::get( $this->field_key );

		return [
			'field_key'  => $this->field_key,
			'mode'       => $this->mode->value,
			'value'      => $this->value,
			'value_type' => $definition->value_type->value,
		];
	}

	public function fingerprint(): string {
		return json_encode(
			[
				'k' => $this->field_key,
				'm' => $this->mode->value,
				'v' => $this->value,
			],
			JSON_THROW_ON_ERROR
		);
	}

	public static function fromStorage( string $field_key, string $mode, mixed $raw_value, string $value_type ): self {
		$mode_enum = ScalarConfigurationMode::tryFrom( $mode );

		if ( null === $mode_enum ) {
			throw new InvalidConfigurationException( sprintf( 'Invalid scalar mode: %s', $mode ) );
		}

		if ( ScalarConfigurationMode::Inherit === $mode_enum || ScalarConfigurationMode::Disable === $mode_enum ) {
			return self::create( $field_key, $mode_enum, null );
		}

		return self::create( $field_key, $mode_enum, self::decode_stored_value( $raw_value, $value_type ) );
	}

	private static function decode_stored_value( mixed $raw_value, string $value_type ): mixed {
		if ( null === $raw_value ) {
			throw new InvalidConfigurationException( 'Stored OVERRIDE scalar value is missing.' );
		}

		$type = ConfigurationFieldValueType::tryFrom( $value_type );

		return match ( $type ) {
			ConfigurationFieldValueType::String => (string) $raw_value,
			ConfigurationFieldValueType::Int => self::decode_int( $raw_value ),
			ConfigurationFieldValueType::Bool => self::decode_bool( $raw_value ),
			default => throw new InvalidConfigurationException( sprintf( 'Unsupported scalar value type: %s', $value_type ) ),
		};
	}

	private static function decode_int( mixed $raw_value ): int {
		if ( is_int( $raw_value ) ) {
			return $raw_value;
		}

		if ( is_string( $raw_value ) && is_numeric( $raw_value ) && ! str_contains( $raw_value, '.' ) ) {
			return (int) $raw_value;
		}

		throw new InvalidConfigurationException( 'Stored integer scalar is invalid.' );
	}

	private static function decode_bool( mixed $raw_value ): bool {
		if ( is_bool( $raw_value ) ) {
			return $raw_value;
		}

		if ( '0' === $raw_value || 0 === $raw_value ) {
			return false;
		}

		if ( '1' === $raw_value || 1 === $raw_value ) {
			return true;
		}

		throw new InvalidConfigurationException( 'Stored boolean scalar is invalid.' );
	}
}
