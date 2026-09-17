<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Geography;

/**
 * Parent-chain helpers for canonical locations. Depth is arbitrary.
 */
final class LocationAncestry {

	/**
	 * @param list<CanonicalLocation> $chain Root-first or leaf-first; order does not matter.
	 *
	 * @return list<int>
	 */
	public static function ids( array $chain ): array {
		$ids = [];

		foreach ( $chain as $location ) {
			if ( $location->id > 0 ) {
				$ids[] = $location->id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	public static function path_contains( string $ancestry_path, int $ancestor_id ): bool {
		if ( $ancestor_id <= 0 ) {
			return false;
		}

		$needle = '/' . $ancestor_id . '/';

		return str_contains( $ancestry_path, $needle );
	}

	public static function is_self_or_descendant( CanonicalLocation $candidate, CanonicalLocation $root ): bool {
		if ( $candidate->id === $root->id ) {
			return true;
		}

		if ( $candidate->country_code !== $root->country_code ) {
			return false;
		}

		if ( '' !== $candidate->ancestry_path && self::path_contains( $candidate->ancestry_path, $root->id ) ) {
			return true;
		}

		return $candidate->parent_location_id === $root->id;
	}

	public static function append_path( string $parent_path, int $id ): string {
		$base = trim( $parent_path );
		if ( '' === $base ) {
			return '/' . $id . '/';
		}

		if ( ! str_starts_with( $base, '/' ) ) {
			$base = '/' . $base;
		}
		if ( ! str_ends_with( $base, '/' ) ) {
			$base .= '/';
		}

		return $base . $id . '/';
	}
}
