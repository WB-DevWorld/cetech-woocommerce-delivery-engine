<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Bulk;

final class BulkRecipe {

	/**
	 * @param array<string, mixed> $target_definition
	 * @param array<string, mixed> $action_manifest
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $recipe_code,
		public readonly string $name,
		public readonly int $owner_user_id,
		public readonly array $target_definition,
		public readonly array $action_manifest,
		public readonly ?string $created_at,
		public readonly ?string $updated_at
	) {
	}

	public static function create( string $name, int $owner_user_id, array $target_definition, array $action_manifest ): self {
		$now  = gmdate( 'Y-m-d H:i:s' );
		$code = 'recipe-' . substr( BulkJob::new_uuid(), 0, 12 );

		return new self( null, $code, $name, $owner_user_id, $target_definition, $action_manifest, $now, $now );
	}

	public function with_id( int $id ): self {
		return new self(
			$id,
			$this->recipe_code,
			$this->name,
			$this->owner_user_id,
			$this->target_definition,
			$this->action_manifest,
			$this->created_at,
			$this->updated_at
		);
	}
}
