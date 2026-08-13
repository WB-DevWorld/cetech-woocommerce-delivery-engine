<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

/**
 * Administrator-facing catalog inheritance counts. Numbers are calculated, never invented.
 */
final class CatalogInheritancePreview {

	/**
	 * @param list<array{id: int, label: string, reason: string}> $can_safely_inherit_examples
	 * @param list<array{id: int, label: string, reason: string}> $product_exception_examples
	 * @param list<array{id: int, label: string, reason: string}> $variation_exception_examples
	 * @param list<array{id: int, label: string, reason: string}> $legacy_examples
	 * @param list<array{id: int, label: string, reason: string}> $needs_review_examples
	 */
	public function __construct(
		public readonly int $published_products,
		public readonly int $can_safely_inherit,
		public readonly int $product_exceptions,
		public readonly int $variation_exceptions,
		public readonly int $legacy_dependent,
		public readonly int $needs_review,
		public readonly int $needs_attention,
		public readonly array $can_safely_inherit_examples = [],
		public readonly array $product_exception_examples = [],
		public readonly array $variation_exception_examples = [],
		public readonly array $legacy_examples = [],
		public readonly array $needs_review_examples = []
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'published_products'           => $this->published_products,
			'can_safely_inherit'           => $this->can_safely_inherit,
			'product_exceptions'           => $this->product_exceptions,
			'variation_exceptions'         => $this->variation_exceptions,
			'legacy_dependent'             => $this->legacy_dependent,
			'needs_review'                 => $this->needs_review,
			'needs_attention'              => $this->needs_attention,
			'can_safely_inherit_examples'  => $this->can_safely_inherit_examples,
			'product_exception_examples'   => $this->product_exception_examples,
			'variation_exception_examples' => $this->variation_exception_examples,
			'legacy_examples'              => $this->legacy_examples,
			'needs_review_examples'        => $this->needs_review_examples,
		];
	}
}
