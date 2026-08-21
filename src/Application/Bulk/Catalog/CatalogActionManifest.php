<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Catalog;

use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;

final class CatalogActionManifest {

	/**
	 * @param list<CatalogFieldAction> $field_actions
	 * @param list<string>             $copy_domains
	 */
	public function __construct(
		public readonly array $field_actions = [],
		public readonly bool $reset_entire_scope = false,
		public readonly ?int $copy_from_product_id = null,
		public readonly array $copy_domains = [],
		public readonly bool $reset_redundant_only = false
	) {
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function from_array( array $data ): self {
		$actions = [];
		foreach ( (array) ( $data['field_actions'] ?? [] ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$action = CatalogFieldAction::from_array( $row );
			if ( CatalogFieldAction::is_known_field( $action->field_key ) ) {
				$actions[] = $action;
			}
		}

		$copy_from = isset( $data['copy_from_product_id'] ) ? (int) $data['copy_from_product_id'] : 0;
		$domains   = [];
		foreach ( (array) ( $data['copy_domains'] ?? [] ) as $domain ) {
			$domain = (string) $domain;
			if ( CatalogFieldAction::is_known_field( $domain ) ) {
				$domains[] = $domain;
			}
		}

		return new self(
			$actions,
			(bool) ( $data['reset_entire_scope'] ?? false ),
			$copy_from > 0 ? $copy_from : null,
			$domains,
			(bool) ( $data['reset_redundant_only'] ?? false )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'field_actions'        => array_map(
				static fn ( CatalogFieldAction $action ): array => $action->to_array(),
				$this->field_actions
			),
			'reset_entire_scope'   => $this->reset_entire_scope,
			'copy_from_product_id' => $this->copy_from_product_id,
			'copy_domains'         => $this->copy_domains,
			'reset_redundant_only' => $this->reset_redundant_only,
		];
	}

	public function has_work(): bool {
		if ( $this->reset_entire_scope || $this->reset_redundant_only || null !== $this->copy_from_product_id ) {
			return true;
		}

		foreach ( $this->field_actions as $action ) {
			if ( ! $action->is_no_change() ) {
				return true;
			}
		}

		return false;
	}

	public function fulfilment_action(): ?CatalogFieldAction {
		foreach ( $this->field_actions as $action ) {
			if ( ConfigurationFieldKey::FULFILMENT_AVAILABILITY === $action->field_key ) {
				return $action;
			}
		}

		return null;
	}
}
