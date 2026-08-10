<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;

final class EffectiveCollectionField {

	/**
	 * @param list<int>                $members
	 * @param list<string>             $reason_codes
	 * @param list<CollectionMutationStep> $mutation_steps
	 */
	public function __construct(
		public readonly string $field_key,
		public readonly EffectiveFieldState $state,
		public readonly array $members,
		public readonly FieldProvenance $provenance,
		public readonly array $reason_codes = [],
		public readonly array $mutation_steps = []
	) {
		foreach ( $this->members as $member ) {
			if ( ! is_int( $member ) ) {
				throw new InvalidConfigurationException( 'Effective collection members must be integers.' );
			}
		}

		foreach ( $this->mutation_steps as $step ) {
			if ( ! $step instanceof CollectionMutationStep ) {
				throw new InvalidConfigurationException( 'Mutation steps must be CollectionMutationStep objects.' );
			}
		}
	}
}
