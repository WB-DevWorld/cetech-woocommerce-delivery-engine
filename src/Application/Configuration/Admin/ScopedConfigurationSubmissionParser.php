<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldRegistry;
use CetechDeliveryEngine\Domain\Configuration\InvalidConfigurationException;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;

/**
 * Parses and validates a complete scope submission before persistence.
 *
 * Empty form values never silently become INHERIT; mode is always explicit.
 * Invalid numerics are rejected (never coerced to 0).
 */
final class ScopedConfigurationSubmissionParser {

	/**
	 * @param array<string, mixed> $raw_fields
	 *
	 * @return array{
	 *   ok: bool,
	 *   errors: list<string>,
	 *   scalars: array<string, ScalarFieldInstruction>,
	 *   collections: array<string, CollectionFieldInstruction>
	 * }
	 */
	public function parse( ConfigurationScopeType $scope_type, array $raw_fields ): array {
		$errors      = [];
		$scalars     = [];
		$collections = [];

		foreach ( ConfigurationFieldRegistry::all() as $field_key => $definition ) {
			$payload = $raw_fields[ $field_key ] ?? null;

			if ( ! is_array( $payload ) ) {
				// Global omit = leave not configured.
				// Product/variation omit = inherit (admin form posts every field; API callers may omit).
				continue;
			}

			$mode = isset( $payload['mode'] ) ? strtolower( trim( (string) $payload['mode'] ) ) : '';

			if ( '' === $mode || 'not_configured' === $mode ) {
				if ( ConfigurationScopeType::Global === $scope_type ) {
					// Explicitly leave global field unset (not inherit, not a fabricated default).
					continue;
				}

				$errors[] = sprintf( 'Field %s requires an explicit mode.', $field_key );
				continue;
			}

			if ( ! $definition->allows_mode( $mode ) ) {
				$errors[] = sprintf( 'Mode "%s" is not allowed for field %s.', $mode, $field_key );
				continue;
			}

			try {
				if ( $definition->is_collection ) {
					$instruction = $this->parse_collection( $field_key, $mode, $payload );
					if ( ConfigurationScopeType::Global === $scope_type && CollectionConfigurationMode::Inherit === $instruction->mode ) {
						// Global inherit is meaningless; treat as omit.
						continue;
					}
					if (
						ConfigurationScopeType::Global !== $scope_type
						&& CollectionConfigurationMode::Inherit === $instruction->mode
					) {
						// Product/variation INHERIT = omit instruction (reset to inherit).
						continue;
					}
					$collections[ $field_key ] = $instruction;
				} else {
					$instruction = $this->parse_scalar( $scope_type, $field_key, $mode, $payload );
					if ( null === $instruction ) {
						continue;
					}
					$scalars[ $field_key ] = $instruction;
				}
			} catch ( InvalidConfigurationException $exception ) {
				$errors[] = $exception->getMessage();
			}
		}

		foreach ( array_keys( $raw_fields ) as $submitted_key ) {
			if ( ! is_string( $submitted_key ) || ! ConfigurationFieldRegistry::has( $submitted_key ) ) {
				$errors[] = sprintf( 'Unknown configuration field key: %s', (string) $submitted_key );
			}
		}

		return [
			'ok'           => [] === $errors,
			'errors'       => $errors,
			'scalars'      => $scalars,
			'collections'  => $collections,
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function parse_scalar(
		ConfigurationScopeType $scope_type,
		string $field_key,
		string $mode,
		array $payload
	): ?ScalarFieldInstruction {
		$mode_enum = ScalarConfigurationMode::tryFrom( $mode );

		if ( null === $mode_enum ) {
			throw new InvalidConfigurationException( sprintf( 'Invalid scalar mode for %s.', $field_key ) );
		}

		if ( ConfigurationScopeType::Global === $scope_type ) {
			if ( ScalarConfigurationMode::Inherit === $mode_enum ) {
				// Global has no parent; inherit means not configured / omit.
				return null;
			}

			if ( ScalarConfigurationMode::Disable === $mode_enum ) {
				return ScalarFieldInstruction::disable( $field_key );
			}

			return ScalarFieldInstruction::override( $field_key, $this->extract_scalar_value( $field_key, $payload ) );
		}

		if ( ScalarConfigurationMode::Inherit === $mode_enum ) {
			return null;
		}

		if ( ScalarConfigurationMode::Disable === $mode_enum ) {
			return ScalarFieldInstruction::disable( $field_key );
		}

		return ScalarFieldInstruction::override( $field_key, $this->extract_scalar_value( $field_key, $payload ) );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function parse_collection( string $field_key, string $mode, array $payload ): CollectionFieldInstruction {
		$mode_enum = CollectionConfigurationMode::tryFrom( $mode );

		if ( null === $mode_enum ) {
			throw new InvalidConfigurationException( sprintf( 'Invalid collection mode for %s.', $field_key ) );
		}

		if ( CollectionConfigurationMode::Inherit === $mode_enum ) {
			return CollectionFieldInstruction::inherit( $field_key );
		}

		$members = $payload['members'] ?? [];

		if ( ! is_array( $members ) ) {
			throw new InvalidConfigurationException(
				sprintf( 'Field %s members must be a list.', $field_key )
			);
		}

		// Preserve order; do not alphabetically sort.
		$normalized_raw = [];

		foreach ( $members as $member ) {
			if ( is_bool( $member ) || is_float( $member ) ) {
				throw new InvalidConfigurationException(
					sprintf( 'Field %s rejected a non-integer member.', $field_key )
				);
			}

			if ( is_string( $member ) ) {
				$trimmed = trim( $member );
				if ( '' === $trimmed ) {
					continue;
				}
				if ( ! preg_match( '/^-?\d+$/', $trimmed ) ) {
					throw new InvalidConfigurationException(
						sprintf( 'Field %s rejected a non-integer member value.', $field_key )
					);
				}
				$normalized_raw[] = (int) $trimmed;
				continue;
			}

			if ( ! is_int( $member ) ) {
				throw new InvalidConfigurationException(
					sprintf( 'Field %s rejected a non-integer member.', $field_key )
				);
			}

			$normalized_raw[] = $member;
		}

		return CollectionFieldInstruction::create( $field_key, $mode_enum, $normalized_raw );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function extract_scalar_value( string $field_key, array $payload ): mixed {
		if ( ! array_key_exists( 'value', $payload ) ) {
			throw new InvalidConfigurationException(
				sprintf( 'OVERRIDE for field %s requires a value.', $field_key )
			);
		}

		$value = $payload['value'];

		if ( is_string( $value ) ) {
			$value = trim( $value );
		}

		if ( '' === $value || null === $value ) {
			throw new InvalidConfigurationException(
				sprintf( 'OVERRIDE for field %s requires a value; empty input is not inherit.', $field_key )
			);
		}

		$definition = ConfigurationFieldRegistry::get( $field_key );

		if ( 'int' === $definition->value_type->value ) {
			if ( is_bool( $value ) || is_float( $value ) ) {
				throw new InvalidConfigurationException(
					sprintf( 'Field %s requires an integer value; invalid numeric input is rejected.', $field_key )
				);
			}

			if ( is_string( $value ) ) {
				if ( ! preg_match( '/^-?\d+$/', $value ) ) {
					throw new InvalidConfigurationException(
						sprintf( 'Field %s requires an integer value; invalid numeric input is rejected.', $field_key )
					);
				}

				return (int) $value;
			}

			if ( ! is_int( $value ) ) {
				throw new InvalidConfigurationException(
					sprintf( 'Field %s requires an integer value; invalid numeric input is rejected.', $field_key )
				);
			}
		}

		return $value;
	}
}
