<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

use CetechDeliveryEngine\Domain\Configuration\InvalidConfigurationException;
use CetechDeliveryEngine\Domain\Configuration\LegacyProductRuleMigrationMapper;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\ScopedConfigurationSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbProductDeliveryRuleRepository;
use CetechDeliveryEngine\Support\Logger;

/**
 * Backfills v3 scoped configuration from legacy product_delivery_rules.
 *
 * Does not modify legacy rows. Does not emit audit_log entries (migration spam avoidance).
 * Runtime continues to read legacy rules until Stage 5.
 */
final class LegacyConfigurationMigrator {

	public function __construct(
		private ProductDeliveryRuleRepositoryInterface $legacy_rules,
		private ScopedConfigurationRepositoryInterface $scoped_repository,
		private LegacyProductRuleMigrationMapper $mapper,
		private Logger $logger
	) {
	}

	/**
	 * @return array{
	 *     migrated: list<int>,
	 *     quarantined: list<array{legacy_rule_id: int, reason: string}>,
	 *     skipped_existing: list<int>,
	 *     unchanged_existing: list<int>
	 * }
	 */
	public function migrate(): array {
		$this->scoped_repository->ensureGlobalScope();

		$report = [
			'migrated'           => [],
			'quarantined'        => [],
			'skipped_existing'   => [],
			'unchanged_existing' => [],
		];

		$rules = $this->legacy_rules->list( [ 'limit' => 500 ] );

		foreach ( $rules as $row ) {
			$legacy_rule_id = (int) ( $row['id'] ?? 0 );

			if ( $legacy_rule_id <= 0 ) {
				continue;
			}

			$existing = $this->scoped_repository->findByLegacyRuleId( $legacy_rule_id );

			if ( null !== $existing ) {
				$report['skipped_existing'][] = $legacy_rule_id;
				continue;
			}

			$offer_ids = $this->decode_offer_ids( $row['delivery_offer_ids'] ?? null );
			$parent_id = $this->resolve_parent_product_id( $row );

			try {
				$mapped = $this->mapper->map_row( $row, $offer_ids, $parent_id );
			} catch ( InvalidConfigurationException $exception ) {
				$report['quarantined'][] = [
					'legacy_rule_id' => $legacy_rule_id,
					'reason'         => 'mapping_error:' . $exception->getMessage(),
				];
				$this->logger->warning(
					'Legacy configuration rule quarantined during v3 migration.',
					[
						'legacy_rule_id' => $legacy_rule_id,
						'error'          => $exception->getMessage(),
					]
				);
				continue;
			}

			if ( 'quarantine' === $mapped['action'] ) {
				$report['quarantined'][] = [
					'legacy_rule_id' => $legacy_rule_id,
					'reason'         => (string) ( $mapped['reason'] ?? 'quarantined' ),
				];
				continue;
			}

			$configuration = $mapped['configuration'];

			if ( null === $configuration ) {
				continue;
			}

			$saved = $this->scoped_repository->saveScopedConfiguration( $configuration );
			$report['migrated'][] = $legacy_rule_id;

			$this->logger->info(
				'Migrated legacy product delivery rule into scoped configuration.',
				[
					'legacy_rule_id' => $legacy_rule_id,
					'scope_row_id'   => $saved->scope->id,
					'scope_type'     => $saved->scope->scope_type->value,
					'scope_id'       => $saved->scope->scope_id,
					'slice_key'      => $saved->scope->slice_key,
				]
			);
		}

		$this->persist_report( $report );

		return $report;
	}

	/**
	 * @param array<string, mixed> $report
	 */
	private function persist_report( array $report ): void {
		update_option(
			ScopedConfigurationSchema::MIGRATION_REPORT_OPTION,
			[
				'completed_at'       => gmdate( 'c' ),
				'migrated'           => $report['migrated'],
				'quarantined'        => $report['quarantined'],
				'skipped_existing'   => $report['skipped_existing'],
				'unchanged_existing' => $report['unchanged_existing'],
			],
			false
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function resolve_parent_product_id( array $row ): ?int {
		if ( ProductTargetType::Variation->value !== (string) ( $row['target_type'] ?? '' ) ) {
			return null;
		}

		if ( isset( $row['parent_product_id'] ) && (int) $row['parent_product_id'] > 0 ) {
			return (int) $row['parent_product_id'];
		}

		$variation_id = (int) ( $row['target_id'] ?? 0 );

		if ( $variation_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $variation_id );

		if ( null === $product || ! $product->is_type( 'variation' ) ) {
			return null;
		}

		$parent_id = (int) $product->get_parent_id();

		return $parent_id > 0 ? $parent_id : null;
	}

	/**
	 * @return list<int>
	 */
	private function decode_offer_ids( mixed $stored ): array {
		if ( $this->legacy_rules instanceof WpdbProductDeliveryRuleRepository ) {
			return $this->legacy_rules->decode_offer_ids( $stored );
		}

		if ( null === $stored || '' === $stored ) {
			return [];
		}

		$decoded = json_decode( (string) $stored, true );

		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$ids  = [];
		$seen = [];

		foreach ( $decoded as $value ) {
			$int = (int) $value;

			if ( $int <= 0 || isset( $seen[ $int ] ) ) {
				continue;
			}

			$seen[ $int ] = true;
			$ids[]        = $int;
		}

		return $ids;
	}
}
