<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Catalog;

use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;

/**
 * Inheritance-aware bulk field action.
 *
 * Blank/missing actions mean NO CHANGE. They never become inherit.
 */
final class CatalogFieldAction {

	public const NO_CHANGE = 'no_change';

	public const SET_OVERRIDE = 'set_override';

	public const CLEAR_OVERRIDE = 'clear_override';

	public const DISABLE = 'disable';

	public const COLLECTION_ADD = 'add';

	public const COLLECTION_REMOVE = 'remove';

	public const COLLECTION_REPLACE = 'replace';

	public const COLLECTION_INHERIT = 'inherit';

	/**
	 * @param list<int|string> $members
	 */
	public function __construct(
		public readonly string $field_key,
		public readonly string $action,
		public readonly mixed $value = null,
		public readonly array $members = []
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function from_array( array $data ): self {
		$members = [];
		foreach ( (array) ( $data['members'] ?? [] ) as $member ) {
			$members[] = $member;
		}

		return new self(
			(string) ( $data['field_key'] ?? '' ),
			(string) ( $data['action'] ?? self::NO_CHANGE ),
			$data['value'] ?? null,
			$members
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'field_key' => $this->field_key,
			'action'    => $this->action,
			'value'     => $this->value,
			'members'   => $this->members,
		];
	}

	public function is_no_change(): bool {
		return self::NO_CHANGE === $this->action || '' === $this->action;
	}

	public static function is_known_field( string $field_key ): bool {
		return in_array( $field_key, ConfigurationFieldKey::all(), true );
	}
}
