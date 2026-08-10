<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;

final class CollectionMutationStep {

	/**
	 * @param list<int> $members
	 */
	public function __construct(
		public readonly ConfigurationScopeType $scope,
		public readonly CollectionConfigurationMode $mode,
		public readonly array $members,
		public readonly ?int $scope_row_id = null
	) {
		$this->assert_list_of_ints( $this->members );
	}

	/**
	 * @return array{scope: string, mode: string, members: list<int>, scope_row_id: int|null}
	 */
	public function to_array(): array {
		return [
			'scope'        => $this->scope->value,
			'mode'         => $this->mode->value,
			'members'      => $this->members,
			'scope_row_id' => $this->scope_row_id,
		];
	}

	/**
	 * @param list<int> $members
	 */
	private function assert_list_of_ints( array $members ): void {
		foreach ( $members as $member ) {
			if ( ! is_int( $member ) ) {
				throw new InvalidConfigurationException( 'Collection mutation members must be integers.' );
			}
		}
	}
}
