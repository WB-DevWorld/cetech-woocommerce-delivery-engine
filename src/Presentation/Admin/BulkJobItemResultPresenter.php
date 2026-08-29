<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogActionManifest;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogFieldAction;
use CetechDeliveryEngine\Domain\Bulk\BulkJobItem;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;

/**
 * Builds administrator-facing current/proposed change summaries from stored
 * snapshots and the approved action manifest. Does not mutate jobs.
 */
final class BulkJobItemResultPresenter {

	/**
	 * @param array<int|string, string> $entity_labels id or code => human name
	 */
	public function __construct(
		private readonly array $entity_labels = []
	) {
	}

	/**
	 * @return list<array{field: string, current: string, proposed: string}>
	 */
	public function blocks( BulkJobItem $item, CatalogActionManifest $manifest ): array {
		if ( ! empty( $item->result['scan'] ) || isset( $item->result['scan_verdict'] ) ) {
			return $this->scan_blocks( $item );
		}

		if ( 'rate_card' === $item->target_type || ! empty( $item->result['entity_type'] ) && 'rate_card' === $item->result['entity_type'] ) {
			$rate_blocks = $this->rate_card_blocks( $item );
			if ( [] !== $rate_blocks ) {
				return $rate_blocks;
			}
		}

		if ( isset( $item->result['entity_type'] ) && 'product' !== $item->result['entity_type'] && 'variation' !== $item->result['entity_type'] && 'rate_card' !== $item->result['entity_type'] ) {
			$config_blocks = $this->config_entity_blocks( $item );
			if ( [] !== $config_blocks ) {
				return $config_blocks;
			}
		}

		if ( BulkJobItemStatus::Failed === $item->status ) {
			$summary = trim( (string) ( $item->error_summary ?? $item->error_code ?? '' ) );

			return [
				[
					'field'    => __( 'Result', 'cetech-woocommerce-delivery-engine' ),
					'current'  => '',
					'proposed' => '' !== $summary ? $summary : __( 'This item would fail.', 'cetech-woocommerce-delivery-engine' ),
				],
			];
		}

		if ( $manifest->reset_entire_scope || ! empty( $item->result['would_delete_scope'] ) || ! empty( $item->result['deleted_scope'] ) ) {
			return [
				[
					'field'    => __( 'Product Exception', 'cetech-woocommerce-delivery-engine' ),
					'current'  => $this->current_exception_summary( $item ),
					'proposed' => __( 'Restore Site-wide inheritance', 'cetech-woocommerce-delivery-engine' ),
				],
			];
		}

		$blocks = [];
		foreach ( $manifest->field_actions as $action ) {
			if ( $action->is_no_change() ) {
				continue;
			}
			$block = $this->block_for_action( $item, $action );
			if ( null !== $block ) {
				$blocks[] = $block;
			}
		}

		if ( [] === $blocks && BulkJobItemStatus::Unchanged === $item->status ) {
			$reason = (string) ( $item->result['reason'] ?? '' );
			$blocks[] = [
				'field'    => __( 'Result', 'cetech-woocommerce-delivery-engine' ),
				'current'  => '',
				'proposed' => $this->unchanged_reason_label( $reason ),
			];
		}

		return $blocks;
	}

	/**
	 * @return list<array{field: string, current: string, proposed: string}>
	 */
	private function scan_blocks( BulkJobItem $item ): array {
		$fulfilment = (string) ( $item->result['effective_fulfilment'] ?? '' );
		$source     = (string) ( $item->result['effective_source'] ?? '' );
		$current    = '' !== $fulfilment
			? trim( $this->fulfilment_label( $fulfilment ) . ( '' !== $source ? ' · ' . $source : '' ) )
			: (string) ( $item->result['effective_state'] ?? '' );
		$finding    = (string) ( $item->result['scan_label'] ?? '' );
		$reason     = trim( (string) ( $item->result['reason'] ?? $item->error_summary ?? '' ) );
		if ( '' !== $reason ) {
			$finding = '' !== $finding ? $finding . ' — ' . $reason : $reason;
		}

		return [
			[
				'field'    => __( 'Resolver health', 'cetech-woocommerce-delivery-engine' ),
				'current'  => $current,
				'proposed' => $finding,
			],
		];
	}

	/**
	 * @return list<array{field: string, current: string, proposed: string}>
	 */
	private function rate_card_blocks( BulkJobItem $item ): array {
		$current  = $this->money_label(
			(string) ( $item->result['current_amount'] ?? $item->before_snapshot['base_amount'] ?? '' ),
			(string) ( $item->result['currency'] ?? $item->before_snapshot['currency'] ?? '' )
		);
		$proposed = $this->money_label(
			(string) ( $item->result['proposed_amount'] ?? '' ),
			(string) ( $item->result['currency'] ?? $item->before_snapshot['currency'] ?? '' )
		);
		if ( '' === $proposed && isset( $item->before_snapshot['base_amount'] ) && BulkJobItemStatus::Unchanged === $item->status ) {
			$proposed = $current;
		}

		if ( '' === $current && '' === $proposed ) {
			return [];
		}

		return [
			[
				'field'    => __( 'Delivery Charge', 'cetech-woocommerce-delivery-engine' ),
				'current'  => $current,
				'proposed' => $proposed,
			],
		];
	}

	/**
	 * @return list<array{field: string, current: string, proposed: string}>
	 */
	private function config_entity_blocks( BulkJobItem $item ): array {
		$current  = (string) ( $item->result['current_summary'] ?? '' );
		$proposed = (string) ( $item->result['proposed_summary'] ?? $item->result['action_label'] ?? $item->error_summary ?? '' );
		if ( '' === $current && '' === $proposed ) {
			return [];
		}

		return [
			[
				'field'    => BulkJobAdminCopy::target_type_label( (string) ( $item->result['entity_type'] ?? $item->target_type ) ),
				'current'  => $current,
				'proposed' => $proposed,
			],
		];
	}

	private function money_label( string $amount, string $currency ): string {
		if ( '' === $amount ) {
			return '';
		}
		$formatted = $amount;
		if ( is_numeric( $amount ) ) {
			$formatted = number_format( (float) $amount, 2, '.', '' );
		}
		$currency = strtoupper( trim( $currency ) );

		return '' !== $currency ? $currency . ' ' . $formatted : $formatted;
	}

	/**
	 * @param list<array{field: string, current: string, proposed: string}> $blocks
	 */
	public static function compact_line( array $blocks ): string {
		$parts = [];
		foreach ( $blocks as $block ) {
			if ( '' !== $block['current'] && '' !== $block['proposed'] ) {
				$parts[] = $block['field'] . ': ' . $block['current'] . ' → ' . $block['proposed'];
			} elseif ( '' !== $block['proposed'] ) {
				$parts[] = $block['field'] . ': ' . $block['proposed'];
			}
		}

		return implode( '; ', $parts );
	}

	/**
	 * @return array{field: string, current: string, proposed: string}|null
	 */
	private function block_for_action( BulkJobItem $item, CatalogFieldAction $action ): ?array {
		if ( ConfigurationFieldKey::FULFILMENT_AVAILABILITY === $action->field_key ) {
			return [
				'field'    => BulkJobAdminCopy::field_label( $action->field_key ),
				'current'  => $this->current_fulfilment( $item ),
				'proposed' => $this->fulfilment_proposed( $action ),
			];
		}

		if ( ConfigurationFieldKey::DELIVERY_OFFER_IDS === $action->field_key ) {
			return [
				'field'    => BulkJobAdminCopy::field_label( $action->field_key ),
				'current'  => $this->current_offers( $item ),
				'proposed' => $this->offers_proposed( $action ),
			];
		}

		return [
			'field'    => BulkJobAdminCopy::field_label( $action->field_key ),
			'current'  => $this->current_scalar( $item, $action->field_key ),
			'proposed' => $this->generic_proposed( $action ),
		];
	}

	private function current_exception_summary( BulkJobItem $item ): string {
		$fulfilment = $this->snapshot_scalar_value( $item, ConfigurationFieldKey::FULFILMENT_AVAILABILITY );
		if ( '' !== $fulfilment ) {
			return $this->fulfilment_label( $fulfilment );
		}

		return __( 'Has Product Exception', 'cetech-woocommerce-delivery-engine' );
	}

	private function current_fulfilment( BulkJobItem $item ): string {
		$value = $this->snapshot_scalar_value( $item, ConfigurationFieldKey::FULFILMENT_AVAILABILITY );
		if ( '' !== $value ) {
			return $this->fulfilment_label( $value );
		}

		return __( 'Uses Site-wide Defaults', 'cetech-woocommerce-delivery-engine' );
	}

	private function fulfilment_proposed( CatalogFieldAction $action ): string {
		if ( CatalogFieldAction::CLEAR_OVERRIDE === $action->action || CatalogFieldAction::COLLECTION_INHERIT === $action->action ) {
			return __( 'Restore Site-wide inheritance', 'cetech-woocommerce-delivery-engine' );
		}

		return $this->fulfilment_label( (string) $action->value );
	}

	private function current_offers( BulkJobItem $item ): string {
		$row = $item->before_snapshot['collections'][ ConfigurationFieldKey::DELIVERY_OFFER_IDS ] ?? null;
		if ( ! is_array( $row ) ) {
			return __( 'Uses inherited Delivery Options', 'cetech-woocommerce-delivery-engine' );
		}
		$mode = (string) ( $row['mode'] ?? '' );
		if ( 'inherit' === $mode || '' === $mode ) {
			return __( 'Uses inherited Delivery Options', 'cetech-woocommerce-delivery-engine' );
		}
		$names = $this->member_names( (array) ( $row['members'] ?? [] ) );
		if ( [] === $names ) {
			return __( 'Uses inherited Delivery Options', 'cetech-woocommerce-delivery-engine' );
		}

		return implode( ', ', $names );
	}

	private function offers_proposed( CatalogFieldAction $action ): string {
		$names = $this->member_names( $action->members );

		return match ( $action->action ) {
			CatalogFieldAction::COLLECTION_ADD => [] === $names
				? __( 'Add Delivery Options', 'cetech-woocommerce-delivery-engine' )
				: sprintf(
					/* translators: %s: delivery option names */
					__( 'Add %s', 'cetech-woocommerce-delivery-engine' ),
					implode( ', ', $names )
				),
			CatalogFieldAction::COLLECTION_REMOVE => [] === $names
				? __( 'Remove Delivery Options', 'cetech-woocommerce-delivery-engine' )
				: sprintf(
					/* translators: %s: delivery option names */
					__( 'Remove %s', 'cetech-woocommerce-delivery-engine' ),
					implode( ', ', $names )
				),
			CatalogFieldAction::COLLECTION_REPLACE => [] === $names
				? __( 'Replace Delivery Options', 'cetech-woocommerce-delivery-engine' )
				: sprintf(
					/* translators: %s: delivery option names */
					__( 'Replace with %s', 'cetech-woocommerce-delivery-engine' ),
					implode( ', ', $names )
				),
			CatalogFieldAction::COLLECTION_INHERIT => __( 'Restore inherited Delivery Options', 'cetech-woocommerce-delivery-engine' ),
			default => implode( ', ', $names ),
		};
	}

	private function current_scalar( BulkJobItem $item, string $field_key ): string {
		$value = $this->snapshot_scalar_value( $item, $field_key );
		if ( '' === $value ) {
			return __( 'Uses Site-wide Defaults', 'cetech-woocommerce-delivery-engine' );
		}

		return $this->entity_name( $value );
	}

	private function generic_proposed( CatalogFieldAction $action ): string {
		if ( CatalogFieldAction::CLEAR_OVERRIDE === $action->action || CatalogFieldAction::COLLECTION_INHERIT === $action->action ) {
			return __( 'Restore Site-wide inheritance', 'cetech-woocommerce-delivery-engine' );
		}

		return $this->entity_name( (string) $action->value );
	}

	private function snapshot_scalar_value( BulkJobItem $item, string $field_key ): string {
		$row = $item->before_snapshot['scalars'][ $field_key ] ?? null;
		if ( ! is_array( $row ) ) {
			return '';
		}
		$mode = (string) ( $row['mode'] ?? '' );
		if ( 'inherit' === $mode || 'disable' === $mode ) {
			return '';
		}
		$value = $row['value'] ?? '';
		if ( null === $value || '' === $value ) {
			return '';
		}

		return (string) $value;
	}

	private function fulfilment_label( string $key ): string {
		$profile = FulfilmentProfileRegistry::get( $key );

		return $profile?->label ?? $key;
	}

	/**
	 * @param list<int|string> $members
	 *
	 * @return list<string>
	 */
	private function member_names( array $members ): array {
		$names = [];
		foreach ( $members as $member ) {
			$name = $this->entity_name( $member );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	private function entity_name( int|string $id_or_code ): string {
		if ( isset( $this->entity_labels[ $id_or_code ] ) ) {
			return (string) $this->entity_labels[ $id_or_code ];
		}
		$key = (string) $id_or_code;
		if ( isset( $this->entity_labels[ $key ] ) ) {
			return (string) $this->entity_labels[ $key ];
		}
		if ( is_int( $id_or_code ) || ctype_digit( $key ) ) {
			$int = (int) $id_or_code;
			if ( isset( $this->entity_labels[ $int ] ) ) {
				return (string) $this->entity_labels[ $int ];
			}
		}
		$human = trim( str_replace( '_', ' ', $key ) );

		return '' !== $human ? ucwords( $human ) : $key;
	}

	private function unchanged_reason_label( string $reason ): string {
		return match ( $reason ) {
			'already_inherited' => __( 'Already uses Site-wide inheritance.', 'cetech-woocommerce-delivery-engine' ),
			'already_correct' => __( 'Already matches the proposed settings.', 'cetech-woocommerce-delivery-engine' ),
			'no_change' => __( 'No settings would change.', 'cetech-woocommerce-delivery-engine' ),
			default => __( 'No settings would change.', 'cetech-woocommerce-delivery-engine' ),
		};
	}
}
