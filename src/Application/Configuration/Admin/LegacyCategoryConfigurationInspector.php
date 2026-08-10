<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;

/**
 * Detects quarantined legacy category rules that may affect a product (admin warning only).
 */
final class LegacyCategoryConfigurationInspector {

	public function __construct(
		private readonly ?ProductDeliveryRuleRepositoryInterface $legacy_rules = null
	) {
	}

	/**
	 * @param list<int> $category_ids product category term IDs
	 *
	 * @return array{
	 *   has_legacy_category_rules: bool,
	 *   rule_ids: list<int>,
	 *   warning_title: string,
	 *   warning_message: string
	 * }
	 */
	public function inspect( int $product_id, array $category_ids = [] ): array {
		if ( null === $this->legacy_rules || $product_id <= 0 || [] === $category_ids ) {
			return $this->result( false, [] );
		}

		$targets = [];
		foreach ( $category_ids as $category_id ) {
			$category_id = (int) $category_id;
			if ( $category_id > 0 ) {
				$targets[] = [
					'target_type' => ProductTargetType::Category->value,
					'target_id'   => $category_id,
				];
			}
		}

		if ( [] === $targets ) {
			return $this->result( false, [] );
		}

		$rule_ids = [];
		$matches  = $this->legacy_rules->findActiveByTargets( $targets );

		foreach ( $matches as $rule ) {
			if ( ProductTargetType::Category->value !== (string) ( $rule['target_type'] ?? '' ) ) {
				continue;
			}

			$id = (int) ( $rule['id'] ?? 0 );
			if ( $id > 0 ) {
				$rule_ids[] = $id;
			}
		}

		return $this->result( [] !== $rule_ids, $rule_ids );
	}

	/**
	 * Site-wide presence of quarantined category rules (informational for global admin).
	 *
	 * @return array{
	 *   has_legacy_category_rules: bool,
	 *   rule_ids: list<int>,
	 *   warning_title: string,
	 *   warning_message: string
	 * }
	 */
	public function inspect_site_wide(): array {
		if ( null === $this->legacy_rules ) {
			return $this->result( false, [] );
		}

		$rule_ids = [];
		$rules    = $this->legacy_rules->list(
			[
				'target_type' => ProductTargetType::Category->value,
				'limit'       => 50,
			]
		);

		foreach ( $rules as $rule ) {
			$id = (int) ( $rule['id'] ?? 0 );
			if ( $id > 0 ) {
				$rule_ids[] = $id;
			}
		}

		return $this->result( [] !== $rule_ids, $rule_ids );
	}

	/**
	 * @param list<int> $rule_ids
	 *
	 * @return array{
	 *   has_legacy_category_rules: bool,
	 *   rule_ids: list<int>,
	 *   warning_title: string,
	 *   warning_message: string
	 * }
	 */
	private function result( bool $has, array $rule_ids ): array {
		return [
			'has_legacy_category_rules' => $has,
			'rule_ids'                  => array_values( array_unique( $rule_ids ) ),
			'warning_title'             => ScopedConfigurationNotices::CATEGORY_WARNING_TITLE,
			'warning_message'           => ScopedConfigurationNotices::CATEGORY_WARNING_MESSAGE,
		];
	}
}
