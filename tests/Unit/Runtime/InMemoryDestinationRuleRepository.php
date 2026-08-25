<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Runtime;

use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;

final class InMemoryDestinationRuleRepository implements DestinationRuleRepositoryInterface {

	/** @var array<int, list<array<string, mixed>>> */
	private array $by_zone = [];

	public function listByZoneId( int $zone_id ): array {
		return $this->by_zone[ $zone_id ] ?? [];
	}

	public function deleteByZoneId( int $zone_id ): bool {
		unset( $this->by_zone[ $zone_id ] );

		return true;
	}

	public function replaceForZone( int $zone_id, array $rules ): bool {
		$this->by_zone[ $zone_id ] = array_values( $rules );

		return true;
	}

	public function count_all(): int {
		$count = 0;
		foreach ( $this->by_zone as $rules ) {
			$count += count( $rules );
		}

		return $count;
	}

	public function list( int $limit = 500 ): array {
		$all = [];
		foreach ( $this->by_zone as $rules ) {
			foreach ( $rules as $rule ) {
				$all[] = $rule;
				if ( count( $all ) >= $limit ) {
					return $all;
				}
			}
		}

		return $all;
	}
}
