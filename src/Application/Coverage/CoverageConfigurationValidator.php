<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Coverage;

use CetechDeliveryEngine\Domain\Enum\CoverageMembership;
use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleMatchMode;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\LocationAncestry;

/**
 * Server-side coverage payload validation. Never trust hidden IDs.
 */
final class CoverageConfigurationValidator {

	public const SELECT_ALL_LIMIT = 40;

	public function __construct(
		private CanonicalLocationRepositoryInterface $locations
	) {
	}

	/**
	 * @param list<array<string, mixed>> $groups
	 *
	 * @return array{ok:bool,errors:list<string>,groups:list<array<string, mixed>>}
	 */
	public function validate( array $groups, string $fallback_country = '' ): array {
		$errors   = [];
		$cleaned  = [];
		$fallback_country = strtoupper( trim( $fallback_country ) );

		foreach ( $groups as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$result = $this->validate_group( $row, $index, $fallback_country );
			if ( [] !== $result['errors'] ) {
				$errors = array_merge( $errors, $result['errors'] );
				continue;
			}
			if ( is_array( $result['group'] ) ) {
				$cleaned[] = $result['group'];
			}
		}

		return [
			'ok'     => [] === $errors,
			'errors' => $errors,
			'groups' => $cleaned,
		];
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array{errors:list<string>,group:?array<string, mixed>}
	 */
	private function validate_group( array $row, int $index, string $fallback_country ): array {
		$label   = sprintf( 'Coverage group %d', $index + 1 );
		$errors  = [];
		$country = strtoupper( trim( (string) ( $row['country'] ?? $fallback_country ) ) );
		$root_id = (int) ( $row['root_location_id'] ?? 0 );
		$root_key = trim( (string) ( $row['root_key'] ?? '' ) );
		$mode_raw = sanitize_key( (string) ( $row['coverage_mode'] ?? $row['mode'] ?? '' ) );
		if ( '' === $country && $root_id <= 0 && '' === $root_key ) {
			return [ 'errors' => [], 'group' => null ];
		}
		$mode    = CoverageMode::tryFrom( $mode_raw );
		if ( ! $mode instanceof CoverageMode ) {
			$errors[] = sprintf( '%s has an invalid coverage mode.', $label );

			return [ 'errors' => $errors, 'group' => null ];
		}

		$root = $this->resolve_root( $row, $country );
		if ( ! $root instanceof CanonicalLocation || ! $root->isActive() ) {
			$errors[] = sprintf( '%s needs a valid country or administrative area.', $label );

			return [ 'errors' => $errors, 'group' => null ];
		}
		if ( '' !== $country && $root->country_code !== $country ) {
			$errors[] = sprintf( '%s root does not belong to the selected country.', $label );

			return [ 'errors' => $errors, 'group' => null ];
		}

		$includes = $this->validate_members(
			is_array( $row['members'] ?? null ) ? $row['members'] : [],
			$root,
			CoverageMembership::Include,
			$label,
			$errors
		);
		$excludes = $this->validate_members(
			is_array( $row['exclusions'] ?? null ) ? $row['exclusions'] : ( is_array( $row['exclude_members'] ?? null ) ? $row['exclude_members'] : [] ),
			$root,
			CoverageMembership::Exclude,
			$label,
			$errors
		);

		if ( CoverageMode::SelectedDescendants === $mode && [] === $includes ) {
			$errors[] = sprintf( '%s is set to selected locations but has no included localities.', $label );
		}
		if ( CoverageMode::EntireArea === $mode && [] !== $includes ) {
			$errors[] = sprintf( '%s is entire-area coverage and cannot also list included localities.', $label );
		}
		if ( CoverageMode::EntireExcept === $mode && [] === $excludes ) {
			$errors[] = sprintf( '%s is entire-area except… but has no exclusions.', $label );
		}
		if ( CoverageMode::SelectedDescendants === $mode && [] !== $excludes ) {
			$errors[] = sprintf( '%s selected-location coverage cannot also list exclusions.', $label );
		}

		$postcodes = $this->validate_postcodes(
			is_array( $row['postcodes'] ?? null ) ? $row['postcodes'] : [],
			$label,
			$errors
		);

		if ( [] !== $errors ) {
			return [ 'errors' => $errors, 'group' => null ];
		}

		$members = [];
		foreach ( $includes as $id ) {
			$members[] = [ 'location_id' => $id, 'membership' => CoverageMembership::Include->value ];
		}
		foreach ( $excludes as $id ) {
			$members[] = [ 'location_id' => $id, 'membership' => CoverageMembership::Exclude->value ];
		}

		return [
			'errors' => [],
			'group'  => [
				'id'               => (int) ( $row['id'] ?? 0 ),
				'root_location_id' => $root->id,
				'coverage_mode'    => $mode->value,
				'sort_order'       => (int) ( $row['sort_order'] ?? ( ( $index + 1 ) * 10 ) ),
				'status'           => RecordStatus::Active->value,
				'review_required'  => ! empty( $row['review_required'] ),
				'legacy_migration' => is_array( $row['legacy_migration'] ?? null ) ? $row['legacy_migration'] : [],
				'members'          => $members,
				'postcodes'        => $postcodes,
			],
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function resolve_root( array $row, string $country ): ?CanonicalLocation {
		$root_id  = (int) ( $row['root_location_id'] ?? 0 );
		$root_key = trim( (string) ( $row['root_key'] ?? '' ) );
		$root     = $root_id > 0 ? $this->locations->find_by_id( $root_id ) : null;
		if ( ! $root instanceof CanonicalLocation && '' !== $root_key ) {
			$root = $this->locations->find_by_key( $root_key );
		}
		if ( ! $root instanceof CanonicalLocation && '' !== $country ) {
			$root = $this->locations->find_country( $country );
		}

		return $root;
	}

	/**
	 * @param list<mixed> $raw
	 * @param list<string> $errors
	 *
	 * @return list<int>
	 */
	private function validate_members( array $raw, CanonicalLocation $root, CoverageMembership $membership, string $label, array &$errors ): array {
		$ids = [];
		foreach ( $raw as $item ) {
			$id = is_array( $item ) ? (int) ( $item['location_id'] ?? $item['id'] ?? 0 ) : (int) $item;
			if ( $id <= 0 ) {
				continue;
			}
			$location = $this->locations->find_by_id( $id );
			if ( ! $location instanceof CanonicalLocation || ! $location->isActive() ) {
				$errors[] = sprintf( '%s contains an invalid %s location.', $label, $membership->value );
				continue;
			}
			if ( ! LocationAncestry::is_self_or_descendant( $location, $root ) ) {
				$errors[] = sprintf( '%s contains a location outside the selected area.', $label );
				continue;
			}
			$ids[] = $id;
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param list<mixed> $raw
	 * @param list<string> $errors
	 *
	 * @return list<array<string, mixed>>
	 */
	private function validate_postcodes( array $raw, string $label, array &$errors ): array {
		$out = [];
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				$value = strtoupper( trim( (string) $item ) );
				$item  = [ 'postcode_value' => $value ];
			}
			$value = strtoupper( trim( (string) ( $item['postcode_value'] ?? $item['value'] ?? '' ) ) );
			if ( '' === $value ) {
				continue;
			}
			$mode = DestinationRuleMatchMode::tryFrom( (string) ( $item['match_mode'] ?? DestinationRuleMatchMode::Exact->value ) );
			if ( ! $mode instanceof DestinationRuleMatchMode ) {
				$errors[] = sprintf( '%s has an invalid postcode match mode.', $label );
				continue;
			}
			$out[] = [
				'postcode_value' => $value,
				'match_mode'     => $mode->value,
				'priority'       => (int) ( $item['priority'] ?? 100 ),
				'status'         => RecordStatus::Active->value,
			];
		}

		return $out;
	}
}
