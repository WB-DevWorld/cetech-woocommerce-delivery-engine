<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Catalog;

final class CatalogTarget {

	public function __construct(
		public readonly string $type,
		public readonly int $id,
		public readonly string $external_key = '',
		public readonly ?int $parent_id = null,
		public readonly string $label = ''
	) {
	}
}
