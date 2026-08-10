<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;

/**
 * Explicit collection field instruction for one scope.
 *
 * REPLACE with [] is intentionally distinct from INHERIT.
 */
final class CollectionFieldInstruction {

	/**
	 * @param list<int|string> $members
	 */
	private function __construct(
		public readonly string $field_key,
		public readonly CollectionConfigurationMode $mode,
		public readonly array $members
	) {
	}

	/**
	 * @param list<int|string> $members
	 */
	public static function create( string $field_key, CollectionConfigurationMode $mode, array $members = [] ): self {
		$definition = ConfigurationFieldRegistry::get( $field_key );

		if ( ! $definition->is_collection ) {
			throw new InvalidConfigurationException(
				sprintf( 'Field %s is a scalar field, not a collection.', $field_key )
			);
		}

		if ( ! $definition->allows_mode( $mode->value ) ) {
			throw new InvalidConfigurationException(
				sprintf( 'Mode %s is not allowed for field %s.', $mode->value, $field_key )
			);
		}

		if ( CollectionConfigurationMode::Inherit === $mode ) {
			if ( [] !== $members ) {
				throw new InvalidConfigurationException(
					sprintf( 'INHERIT for field %s must not carry members.', $field_key )
				);
			}

			return new self( $field_key, $mode, [] );
		}

		$normalizer = $definition->normalizer;
		$normalized = is_callable( $normalizer ) ? $normalizer( $members ) : $members;

		$validator = $definition->validator;
		if ( is_callable( $validator ) ) {
			$validator( $normalized );
		}

		if ( ! is_array( $normalized ) ) {
			throw new InvalidConfigurationException( sprintf( 'Normalized members for %s must be an array.', $field_key ) );
		}

		/** @var list<int> $normalized */
		return new self( $field_key, $mode, array_values( $normalized ) );
	}

	public static function inherit( string $field_key ): self {
		return self::create( $field_key, CollectionConfigurationMode::Inherit, [] );
	}

	/**
	 * @param list<int|string> $members
	 */
	public static function replace( string $field_key, array $members ): self {
		return self::create( $field_key, CollectionConfigurationMode::Replace, $members );
	}

	/**
	 * @param list<int|string> $members
	 */
	public static function add( string $field_key, array $members ): self {
		return self::create( $field_key, CollectionConfigurationMode::Add, $members );
	}

	/**
	 * @param list<int|string> $members
	 */
	public static function remove( string $field_key, array $members ): self {
		return self::create( $field_key, CollectionConfigurationMode::Remove, $members );
	}

	/**
	 * @return array{field_key: string, mode: string, members: list<int|string>}
	 */
	public function toStorageArray(): array {
		return [
			'field_key' => $this->field_key,
			'mode'      => $this->mode->value,
			'members'   => $this->members,
		];
	}

	public function fingerprint(): string {
		return json_encode(
			[
				'k' => $this->field_key,
				'm' => $this->mode->value,
				'v' => $this->members,
			],
			JSON_THROW_ON_ERROR
		);
	}

	public static function fromStorage( string $field_key, string $mode, mixed $members_json ): self {
		$mode_enum = CollectionConfigurationMode::tryFrom( $mode );

		if ( null === $mode_enum ) {
			throw new InvalidConfigurationException( sprintf( 'Invalid collection mode: %s', $mode ) );
		}

		if ( CollectionConfigurationMode::Inherit === $mode_enum ) {
			return self::inherit( $field_key );
		}

		$members = [];

		if ( is_string( $members_json ) && '' !== $members_json ) {
			$decoded = json_decode( $members_json, true );

			if ( ! is_array( $decoded ) ) {
				throw new InvalidConfigurationException( 'Stored collection members JSON is invalid.' );
			}

			$members = $decoded;
		} elseif ( is_array( $members_json ) ) {
			$members = $members_json;
		}

		return self::create( $field_key, $mode_enum, $members );
	}
}
