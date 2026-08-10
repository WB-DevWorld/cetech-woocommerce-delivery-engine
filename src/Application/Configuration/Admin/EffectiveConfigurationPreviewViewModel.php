<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

/**
 * Read-only effective configuration preview view model (resolver-backed).
 */
final class EffectiveConfigurationPreviewViewModel {

	/**
	 * @param list<array<string, mixed>> $fields
	 * @param list<string>               $overall_reasons
	 * @param array<string, mixed>       $version
	 * @param array<string, mixed>       $category_warning
	 */
	public function __construct(
		public readonly int $product_id,
		public readonly ?int $variation_id,
		public readonly string $slice_key,
		public readonly string $slice_label,
		public readonly string $overall_state,
		public readonly string $overall_state_label,
		public readonly string $overall_state_tone,
		public readonly array $overall_reasons,
		public readonly array $fields,
		public readonly array $version,
		public readonly string $limitation_title,
		public readonly string $limitation_message,
		public readonly string $hard_constraint_note,
		public readonly string $transitional_title,
		public readonly string $transitional_message,
		public readonly array $category_warning,
		public readonly bool $used_authoritative_resolver = true
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'product_id'               => $this->product_id,
			'variation_id'             => $this->variation_id,
			'slice_key'                => $this->slice_key,
			'slice_label'              => $this->slice_label,
			'overall_state'            => $this->overall_state,
			'overall_state_label'      => $this->overall_state_label,
			'overall_state_tone'       => $this->overall_state_tone,
			'overall_reasons'          => $this->overall_reasons,
			'fields'                   => $this->fields,
			'version'                  => $this->version,
			'limitation_title'         => $this->limitation_title,
			'limitation_message'       => $this->limitation_message,
			'hard_constraint_note'     => $this->hard_constraint_note,
			'transitional_title'       => $this->transitional_title,
			'transitional_message'     => $this->transitional_message,
			'category_warning'         => $this->category_warning,
			'used_authoritative_resolver' => $this->used_authoritative_resolver,
		];
	}
}
