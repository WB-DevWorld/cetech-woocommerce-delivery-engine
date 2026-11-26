<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;

/**
 * Batched presence lookup for scoped configuration rows (no hydration).
 *
 * Lets catalog scans skip per-product lookups for products that have no saved
 * scope at all, which is most of a large catalog.
 */
interface ScopePresenceQueryInterface {

	/**
	 * Of the given scope ids, the ones that have at least one saved scope row of
	 * this type, in any status or slice.
	 *
	 * @param list<int> $scope_ids
	 *
	 * @return list<int>
	 */
	public function scopeIdsWithRows( ConfigurationScopeType $scope_type, array $scope_ids ): array;
}
