<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

use CetechDeliveryEngine\Application\Configuration\OperationalReadinessAssessor;
use CetechDeliveryEngine\Application\Configuration\OperationalStateService;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;

/**
 * Products whose effective delivery setup is incomplete for staff follow-up.
 */
final class NeedsAttentionQuery {

	public function __construct(
		private readonly CatalogIndexInterface $catalog,
		private readonly OperationalReadinessAssessor $readiness,
		private readonly OperationalStateService $operational_state,
		private readonly CatalogInheritanceClassifier $classifier,
		private readonly ScopedConfigurationRepositoryInterface $scopes
	) {
	}

	/**
	 * @return list<array{id: int, label: string, url: string, reason: string}>
	 */
	public function list( int $limit = 100 ): array {
		$items = [];

		foreach ( $this->catalog->published_product_ids( 0, 5000 ) as $product_id ) {
			$reason = $this->reason_for( $product_id );
			if ( null === $reason ) {
				continue;
			}

			$items[] = [
				'id'     => $product_id,
				'label'  => $this->catalog->product_label( $product_id ),
				'url'    => $this->catalog->product_edit_url( $product_id ),
				'reason' => $reason,
			];

			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items;
	}

	public function count(): int {
		return count( $this->list( 5000 ) );
	}

	public function reason_for( int $product_id ): ?string {
		$reason = $this->readiness->reason_for( $product_id );
		if ( null === $reason ) {
			return null;
		}

		if ( ! $this->operational_state->current()->scan_catalog_as_customer_problems ) {
			$product_scopes = $this->scopes->findByScope( ConfigurationScopeType::Product, $product_id );
			if ( ! $this->classifier->has_custom_fields( $product_scopes ) ) {
				return null;
			}
		}

		return $reason;
	}
}
