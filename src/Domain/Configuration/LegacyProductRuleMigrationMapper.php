<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;

/**
 * Pure mapper: legacy product_delivery_rules row → v3 scoped configuration.
 *
 * Category rows are never mapped here; callers must quarantine them.
 */
final class LegacyProductRuleMigrationMapper {

	public const QUARANTINE_CATEGORY = 'category_quarantined';
	public const QUARANTINE_UNKNOWN_TARGET = 'unknown_target_quarantined';

	/**
	 * @param array<string, mixed> $row
	 * @param list<int>            $offer_ids already-decoded offer IDs preserving order/dedupe rules
	 *
	 * @return array{
	 *     action: 'migrate'|'quarantine',
	 *     reason: string|null,
	 *     configuration: ScopedConfiguration|null
	 * }
	 */
	public function map_row( array $row, array $offer_ids = [], ?int $parent_product_id = null ): array {
		$legacy_rule_id = (int) ( $row['id'] ?? 0 );
		$target_type    = (string) ( $row['target_type'] ?? '' );
		$target_id      = (int) ( $row['target_id'] ?? 0 );

		if ( ProductTargetType::Category->value === $target_type ) {
			return [
				'action'        => 'quarantine',
				'reason'        => self::QUARANTINE_CATEGORY,
				'configuration' => null,
			];
		}

		$scope_type = match ( $target_type ) {
			ProductTargetType::Product->value => ConfigurationScopeType::Product,
			ProductTargetType::Variation->value => ConfigurationScopeType::Variation,
			default => null,
		};

		if ( null === $scope_type || $target_id <= 0 || $legacy_rule_id <= 0 ) {
			return [
				'action'        => 'quarantine',
				'reason'        => self::QUARANTINE_UNKNOWN_TARGET,
				'configuration' => null,
			];
		}

		if ( ConfigurationScopeType::Variation === $scope_type ) {
			if ( null === $parent_product_id || $parent_product_id <= 0 ) {
				$parent_product_id = isset( $row['parent_product_id'] ) ? (int) $row['parent_product_id'] : 0;
			}

			if ( $parent_product_id <= 0 ) {
				throw new InvalidConfigurationException(
					sprintf( 'Variation legacy rule #%d requires parent_product_id for migration.', $legacy_rule_id )
				);
			}
		} else {
			$parent_product_id = null;
		}

		$status_raw = (string) ( $row['status'] ?? RecordStatus::Active->value );
		$status     = RecordStatus::tryFrom( $status_raw ) ?? RecordStatus::Inactive;

		$slice_key = (string) ( $row['fulfilment_availability'] ?? '' );

		if ( '' === $slice_key ) {
			throw new InvalidConfigurationException(
				sprintf( 'Legacy rule #%d is missing fulfilment_availability.', $legacy_rule_id )
			);
		}

		$scope = new ConfigurationScope(
			null,
			$scope_type,
			$target_id,
			$slice_key,
			$parent_product_id,
			$status,
			1,
			ConfigurationSource::Migrated,
			$legacy_rule_id
		);

		$scalars = [
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override(
				ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
				$slice_key
			),
			ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::override(
				ConfigurationFieldKey::FULFILMENT_CHOICE,
				(string) ( $row['fulfilment_choice'] ?? '' )
			),
			ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override(
				ConfigurationFieldKey::PRIORITY,
				$this->map_priority( $row['priority'] ?? 100 )
			),
		];

		$scalars[ ConfigurationFieldKey::LOGISTICS_PROFILE_ID ] = $this->map_nullable_id_field(
			ConfigurationFieldKey::LOGISTICS_PROFILE_ID,
			$row['logistics_profile_id'] ?? null
		);
		$scalars[ ConfigurationFieldKey::SUPPLIER_ID ] = $this->map_nullable_id_field(
			ConfigurationFieldKey::SUPPLIER_ID,
			$row['supplier_id'] ?? null
		);
		$scalars[ ConfigurationFieldKey::ORIGIN_ID ] = $this->map_nullable_id_field(
			ConfigurationFieldKey::ORIGIN_ID,
			$row['origin_id'] ?? null
		);

		$collections = [
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
				ConfigurationFieldKey::DELIVERY_OFFER_IDS,
				$offer_ids
			),
		];

		return [
			'action'        => 'migrate',
			'reason'        => null,
			'configuration' => new ScopedConfiguration( $scope, $scalars, $collections ),
		];
	}

	/**
	 * Compatibility matrix rows for documentation/tests.
	 *
	 * @return list<array{legacy_field: string, v3_scope: string, v3_mode: string, v3_value_rule: string, reason: string}>
	 */
	public static function compatibility_matrix(): array {
		return [
			[
				'legacy_field'  => 'id',
				'v3_scope'      => 'scope.legacy_rule_id',
				'v3_mode'       => 'n/a',
				'v3_value_rule' => 'Traceability only',
				'reason'        => 'Migration provenance; not an inheritance field',
			],
			[
				'legacy_field'  => 'target_type=product',
				'v3_scope'      => 'product',
				'v3_mode'       => 'n/a',
				'v3_value_rule' => 'scope_type=product, scope_id=target_id',
				'reason'        => 'Stage 1 product scope mapping',
			],
			[
				'legacy_field'  => 'target_type=variation',
				'v3_scope'      => 'variation',
				'v3_mode'       => 'n/a',
				'v3_value_rule' => 'scope_type=variation, scope_id=target_id, parent_product_id required',
				'reason'        => 'Stage 1 variation scope mapping',
			],
			[
				'legacy_field'  => 'target_type=category',
				'v3_scope'      => 'quarantined (legacy only)',
				'v3_mode'       => 'n/a',
				'v3_value_rule' => 'Not written to v3 scopes',
				'reason'        => 'Stage 1 quarantine; do not auto-promote to Global',
			],
			[
				'legacy_field'  => 'target_id',
				'v3_scope'      => 'scope.scope_id',
				'v3_mode'       => 'n/a',
				'v3_value_rule' => 'Copied as scope_id',
				'reason'        => 'Scope identity',
			],
			[
				'legacy_field'  => 'target_label_snapshot',
				'v3_scope'      => 'not migrated',
				'v3_mode'       => 'n/a',
				'v3_value_rule' => 'Omitted',
				'reason'        => 'Display cache only; not configuration inheritance',
			],
			[
				'legacy_field'  => 'fulfilment_availability',
				'v3_scope'      => 'product|variation',
				'v3_mode'       => ScalarConfigurationMode::Override->value,
				'v3_value_rule' => 'Exact string; also used as slice_key',
				'reason'        => 'Whole-record FA becomes explicit OVERRIDE; slice_key preserves multi-FA rows',
			],
			[
				'legacy_field'  => 'fulfilment_choice',
				'v3_scope'      => 'product|variation',
				'v3_mode'       => ScalarConfigurationMode::Override->value,
				'v3_value_rule' => 'Exact string',
				'reason'        => 'Populated scalar → OVERRIDE',
			],
			[
				'legacy_field'  => 'delivery_offer_ids (JSON list)',
				'v3_scope'      => 'product|variation',
				'v3_mode'       => 'replace',
				'v3_value_rule' => 'Ordered unique positive ints',
				'reason'        => 'Whole-list semantics → REPLACE',
			],
			[
				'legacy_field'  => 'delivery_offer_ids null/empty',
				'v3_scope'      => 'product|variation',
				'v3_mode'       => 'replace',
				'v3_value_rule' => '[]',
				'reason'        => 'REPLACE [] is distinct from INHERIT',
			],
			[
				'legacy_field'  => 'logistics_profile_id positive',
				'v3_scope'      => 'product|variation',
				'v3_mode'       => ScalarConfigurationMode::Override->value,
				'v3_value_rule' => 'Positive int',
				'reason'        => 'Populated scalar → OVERRIDE',
			],
			[
				'legacy_field'  => 'logistics_profile_id null/0',
				'v3_scope'      => 'product|variation',
				'v3_mode'       => ScalarConfigurationMode::Disable->value,
				'v3_value_rule' => 'No value',
				'reason'        => 'Explicit none on whole-record rule; not INHERIT',
			],
			[
				'legacy_field'  => 'supplier_id positive',
				'v3_scope'      => 'product|variation',
				'v3_mode'       => ScalarConfigurationMode::Override->value,
				'v3_value_rule' => 'Positive int',
				'reason'        => 'Populated scalar → OVERRIDE',
			],
			[
				'legacy_field'  => 'supplier_id null/0',
				'v3_scope'      => 'product|variation',
				'v3_mode'       => ScalarConfigurationMode::Disable->value,
				'v3_value_rule' => 'No value',
				'reason'        => 'Explicit none; not INHERIT',
			],
			[
				'legacy_field'  => 'origin_id positive',
				'v3_scope'      => 'product|variation',
				'v3_mode'       => ScalarConfigurationMode::Override->value,
				'v3_value_rule' => 'Positive int',
				'reason'        => 'Populated scalar → OVERRIDE',
			],
			[
				'legacy_field'  => 'origin_id null/0',
				'v3_scope'      => 'product|variation',
				'v3_mode'       => ScalarConfigurationMode::Disable->value,
				'v3_value_rule' => 'No value',
				'reason'        => 'Explicit none; not INHERIT',
			],
			[
				'legacy_field'  => 'priority (including 0)',
				'v3_scope'      => 'product|variation',
				'v3_mode'       => ScalarConfigurationMode::Override->value,
				'v3_value_rule' => 'Exact int including 0',
				'reason'        => '0 remains OVERRIDE 0, never inherit',
			],
			[
				'legacy_field'  => 'status',
				'v3_scope'      => 'scope.status',
				'v3_mode'       => 'n/a',
				'v3_value_rule' => 'Copied to scope status',
				'reason'        => 'Inactive legacy rules remain inactive scopes',
			],
			[
				'legacy_field'  => 'internal_notes',
				'v3_scope'      => 'not migrated',
				'v3_mode'       => 'n/a',
				'v3_value_rule' => 'Omitted',
				'reason'        => 'Private notes stay on legacy row; avoid dual private copies',
			],
			[
				'legacy_field'  => 'created_at/updated_at',
				'v3_scope'      => 'scope timestamps',
				'v3_mode'       => 'n/a',
				'v3_value_rule' => 'New timestamps on insert; legacy timestamps untouched',
				'reason'        => 'Non-destructive migration',
			],
		];
	}

	private function map_nullable_id_field( string $field_key, mixed $raw ): ScalarFieldInstruction {
		if ( null === $raw || '' === $raw ) {
			return ScalarFieldInstruction::disable( $field_key );
		}

		if ( ! is_numeric( $raw ) || str_contains( (string) $raw, '.' ) ) {
			throw new InvalidConfigurationException(
				sprintf( 'Legacy field %s has a non-integer value; refusing silent zero coercion.', $field_key )
			);
		}

		$int = (int) $raw;

		if ( $int <= 0 ) {
			return ScalarFieldInstruction::disable( $field_key );
		}

		return ScalarFieldInstruction::override( $field_key, $int );
	}

	private function map_priority( mixed $raw ): int {
		if ( is_bool( $raw ) || ( is_string( $raw ) && ! is_numeric( $raw ) ) || is_float( $raw ) ) {
			throw new InvalidConfigurationException( 'Legacy priority must be an integer.' );
		}

		if ( is_string( $raw ) && str_contains( $raw, '.' ) ) {
			throw new InvalidConfigurationException( 'Legacy priority must be an integer.' );
		}

		return (int) $raw;
	}
}
