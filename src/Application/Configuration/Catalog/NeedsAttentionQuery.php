<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

use CetechDeliveryEngine\Application\Configuration\OperationalReadinessAssessor;
use CetechDeliveryEngine\Application\Configuration\OperationalStateService;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;

/**
 * Products whose effective delivery setup is incomplete for staff follow-up.
 *
 * Walks every published product in batches (no catalog cap). When the catalog
 * is not yet scanned as customer problems, a product without product-specific
 * settings can never be flagged, so that cheap check runs before the resolve.
 */
final class NeedsAttentionQuery {

	public const BATCH_SIZE = 250;

	private ?bool $batch_scan_as_customer = null;

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
		return $this->listing( $limit )->items;
	}

	public function listing( int $limit = 100 ): NeedsAttentionCatalogListing {
		$items   = [];
		$offset  = 0;
		$scanned = 0;

		while ( true ) {
			$product_ids = $this->catalog->published_product_ids( $offset, self::BATCH_SIZE );
			if ( [] === $product_ids ) {
				return new NeedsAttentionCatalogListing( $items, true, $scanned );
			}

			$this->begin_batch( $product_ids );
			try {
				foreach ( $product_ids as $product_id ) {
					++$scanned;
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
						return new NeedsAttentionCatalogListing( $items, false, $scanned );
					}
				}
			} finally {
				$this->end_batch();
			}

			if ( count( $product_ids ) < self::BATCH_SIZE ) {
				return new NeedsAttentionCatalogListing( $items, true, $scanned );
			}

			$offset += count( $product_ids );
		}
	}

	/**
	 * Full catalog count. Builds no labels or URLs.
	 */
	public function count(): int {
		$count  = 0;
		$offset = 0;

		do {
			$batch   = $this->count_batch( $offset );
			$count  += $batch->flagged;
			$offset += $batch->scanned;
		} while ( ! $batch->reached_end );

		return $count;
	}

	/**
	 * Count one batch of published products starting at $offset (resumable scans).
	 */
	public function count_batch( int $offset, int $batch_size = self::BATCH_SIZE ): NeedsAttentionCatalogBatch {
		$batch_size  = max( 1, $batch_size );
		$product_ids = $this->catalog->published_product_ids( max( 0, $offset ), $batch_size );
		if ( [] === $product_ids ) {
			return new NeedsAttentionCatalogBatch( 0, 0, true );
		}

		$flagged = 0;
		$this->begin_batch( $product_ids );
		try {
			foreach ( $product_ids as $product_id ) {
				if ( null !== $this->reason_for( $product_id ) ) {
					++$flagged;
				}
			}
		} finally {
			$this->end_batch();
		}

		return new NeedsAttentionCatalogBatch( $flagged, count( $product_ids ), count( $product_ids ) < $batch_size );
	}

	public function reason_for( int $product_id ): ?string {
		if ( ! $this->scan_as_customer() ) {
			$product_scopes = $this->scopes->findByScope( ConfigurationScopeType::Product, $product_id );
			if ( ! $this->classifier->has_custom_fields( $product_scopes ) ) {
				return null;
			}
		}

		return $this->readiness->reason_for( $product_id );
	}

	private function scan_as_customer(): bool {
		return $this->batch_scan_as_customer
			?? $this->operational_state->current()->scan_catalog_as_customer_problems;
	}

	/**
	 * @param list<int> $product_ids
	 */
	private function begin_batch( array $product_ids ): void {
		$this->batch_scan_as_customer = $this->operational_state->current()->scan_catalog_as_customer_problems;

		if ( $this->scopes instanceof CatalogScanScopedConfigurationRepository ) {
			$this->scopes->prime_products( $product_ids );
		}
	}

	private function end_batch(): void {
		$this->batch_scan_as_customer = null;

		if ( $this->scopes instanceof CatalogScanScopedConfigurationRepository ) {
			$this->scopes->reset();
		}

		$this->readiness->clear_memoization();
	}
}
