<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use InvalidArgumentException;

/**
 * Resolve one admin request without turning malformed input into another target.
 */
final class ScopedConfigurationTargetGuard {

	public function __construct(
		private readonly ProductVariationScopeGuard $scope_guard,
		private readonly ScopedConfigurationAuthorization $authorization
	) {
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{scope_type: ConfigurationScopeType, scope_id: int, slice_key: string, parent_product_id: ?int}
	 */
	public function resolve( array $input, bool $allow_picker = false ): array {
		$raw_type = $input['scope_type'] ?? 'global';
		$scope_type = is_string( $raw_type ) ? ConfigurationScopeType::tryFrom( $raw_type ) : null;
		if ( null === $scope_type ) {
			throw new InvalidArgumentException( 'Choose a valid configuration scope.' );
		}

		$scope_id = $this->id( $input['scope_id'] ?? 0 );
		$parent_raw = $input['parent_product_id'] ?? null;
		$parent_id = null === $parent_raw || '' === $parent_raw ? null : $this->id( $parent_raw );
		$parent_id = 0 === $parent_id ? null : $parent_id;
		$slice_key = $input['slice_key'] ?? ConfigurationScope::DEFAULT_SLICE_KEY;
		if ( ! is_string( $slice_key ) || ( ConfigurationScope::DEFAULT_SLICE_KEY !== $slice_key && null === FulfilmentAvailability::tryFrom( $slice_key ) ) ) {
			throw new InvalidArgumentException( 'Choose a valid delivery setup.' );
		}
		if ( ConfigurationScopeType::Global === $scope_type && ConfigurationScope::DEFAULT_SLICE_KEY !== $slice_key ) {
			throw new InvalidArgumentException( 'This editor only edits the default Global setup.' );
		}

		$picker = $allow_picker && ConfigurationScopeType::Global !== $scope_type && 0 === $scope_id && null === $parent_id && ! array_key_exists( 'scope_id', $input );
		$errors = $this->authorization->verify_scope_access( $scope_type, $scope_id, $parent_id, $picker );
		if ( ! $picker ) {
			$errors = array_merge( $errors, $this->scope_guard->validate( $scope_type, $scope_id, $parent_id ) );
		}
		if ( [] !== $errors ) {
			throw new InvalidArgumentException( implode( ' ', $errors ) );
		}

		return [
			'scope_type'        => $scope_type,
			'scope_id'          => $scope_id,
			'slice_key'         => $slice_key,
			'parent_product_id' => $parent_id,
		];
	}

	private function id( mixed $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) && (string) (int) $value === $value ) {
			return (int) $value;
		}

		throw new InvalidArgumentException( 'Enter a valid non-negative integer item ID.' );
	}
}
