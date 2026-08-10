<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Runtime;

use CetechDeliveryEngine\Application\Runtime\ProductTypeInspectorInterface;

final class FixedProductTypeInspector implements ProductTypeInspectorInterface {

	/** @var array<int, string|null> */
	private array $map;

	/**
	 * @param array<int, string|null> $map
	 */
	public function __construct( array $map = [] ) {
		$this->map = $map;
	}

	public function set( int $product_id, ?string $type ): void {
		$this->map[ $product_id ] = $type;
	}

	public function inspect( int $product_id ): ?string {
		return $this->map[ $product_id ] ?? null;
	}
}
