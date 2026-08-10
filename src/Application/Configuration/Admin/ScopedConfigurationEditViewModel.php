<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

/**
 * Editor screen view model for one scope + slice.
 */
final class ScopedConfigurationEditViewModel {

	/**
	 * @param list<FieldEditViewModel> $fields
	 * @param list<array{key: string, label: string, migrated: bool, legacy_rule_id: int|null}> $available_slices
	 * @param array<string, mixed> $notices
	 * @param array<string, mixed> $category_warning
	 * @param array<string, mixed> $technical_details
	 */
	public function __construct(
		public readonly string $scope_type,
		public readonly int $scope_id,
		public readonly string $slice_key,
		public readonly string $slice_label,
		public readonly ?int $parent_product_id,
		public readonly ?string $product_label,
		public readonly ?string $variation_label,
		public readonly int $config_version,
		public readonly bool $is_migrated,
		public readonly ?int $legacy_rule_id,
		public readonly array $available_slices,
		public readonly array $fields,
		public readonly array $notices,
		public readonly array $category_warning,
		public readonly array $technical_details,
		public readonly string $transitional_title,
		public readonly string $transitional_message
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'scope_type'            => $this->scope_type,
			'scope_id'              => $this->scope_id,
			'slice_key'             => $this->slice_key,
			'slice_label'           => $this->slice_label,
			'parent_product_id'     => $this->parent_product_id,
			'product_label'         => $this->product_label,
			'variation_label'       => $this->variation_label,
			'config_version'        => $this->config_version,
			'is_migrated'           => $this->is_migrated,
			'legacy_rule_id'        => $this->legacy_rule_id,
			'available_slices'      => $this->available_slices,
			'fields'                => array_map(
				static fn ( FieldEditViewModel $field ): array => $field->to_array(),
				$this->fields
			),
			'notices'               => $this->notices,
			'category_warning'      => $this->category_warning,
			'technical_details'     => $this->technical_details,
			'transitional_title'    => $this->transitional_title,
			'transitional_message'  => $this->transitional_message,
		];
	}
}
