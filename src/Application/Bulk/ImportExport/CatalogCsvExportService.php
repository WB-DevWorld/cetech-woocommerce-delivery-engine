<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\ImportExport;

use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTarget;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetDefinition;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetQueryInterface;
use CetechDeliveryEngine\Application\Bulk\Portability\EntityCodeResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;

/**
 * Streams Delivery Engine CSV rows in keyset pages.
 *
 * COR-021 (D4): the file carries its format version, the customized site-wide defaults of every
 * fulfilment type, and one row per product/variation scope slice with the saved modes (override,
 * disable, inherit; explicit zero stays "0"). Private supplier/origin columns are written only
 * when the caller asks for them. Secrets are never part of this file.
 */
final class CatalogCsvExportService {

	public const PAGE_SIZE = 100;

	public function __construct(
		private readonly CatalogTargetQueryInterface $targets,
		private readonly ScopedConfigurationRepositoryInterface $scopes,
		private readonly EffectiveConfigurationResolver $resolver,
		private readonly EntityCodeResolver $codes
	) {
	}

	/**
	 * @param resource $handle
	 */
	public function write_stream( $handle, CatalogTargetDefinition $definition, bool $include_private = false ): int {
		$columns = CatalogCsvMapper::columns( $include_private );
		fputcsv( $handle, $columns );
		$count = 0;

		foreach ( $this->site_wide_default_rows() as $row ) {
			$this->write_row( $handle, $columns, $row );
			++$count;
		}

		foreach ( $this->walk_definitions( $definition ) as $walk ) {
			$after = 0;
			do {
				$page = $this->targets->page_after( $walk, $after, self::PAGE_SIZE );
				if ( [] === $page ) {
					break;
				}
				foreach ( $page as $target ) {
					if ( ! $target instanceof CatalogTarget ) {
						continue;
					}
					$after = $target->id;
					foreach ( $this->rows_for( $target ) as $row ) {
						$this->write_row( $handle, $columns, $row );
						++$count;
					}
				}
			} while ( count( $page ) >= self::PAGE_SIZE );
		}

		return $count;
	}

	/**
	 * A catalog definition addresses one target type; a complete file needs products and variations.
	 *
	 * @return list<CatalogTargetDefinition>
	 */
	private function walk_definitions( CatalogTargetDefinition $definition ): array {
		$walks = [ $definition ];
		if ( CatalogTargetDefinition::TARGET_PRODUCT === $definition->target_type ) {
			$walks[] = CatalogTargetDefinition::from_array(
				array_merge( $definition->to_array(), [ 'target_type' => CatalogTargetDefinition::TARGET_VARIATION ] )
			);
		}

		return $walks;
	}

	/**
	 * @param resource              $handle
	 * @param list<string>          $columns
	 * @param array<string, string> $row
	 */
	private function write_row( $handle, array $columns, array $row ): void {
		$line = [];
		foreach ( $columns as $header ) {
			$line[] = CatalogCsvMapper::escape_csv_value( (string) ( $row[ $header ] ?? '' ) );
		}
		fputcsv( $handle, $line );
	}

	/**
	 * The customized site-wide defaults, one row per fulfilment type that has any.
	 *
	 * @return list<array<string, string>>
	 */
	public function site_wide_default_rows(): array {
		$rows = [];
		foreach ( array_merge( [ ConfigurationScope::DEFAULT_SLICE_KEY ], FulfilmentProfileRegistry::keys() ) as $profile_key ) {
			$config = $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Global, ConfigurationScope::GLOBAL_SCOPE_ID, $profile_key );
			if ( ! $config instanceof ScopedConfiguration || ( [] === $config->scalars && [] === $config->collections ) ) {
				continue;
			}
			$row                      = $this->base_row();
			$row['de_scope']          = CatalogCsvMapper::SCOPE_GLOBAL;
			$row['de_slice']          = $profile_key;
			$this->fill_instructions( $row, $config );
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * One row per saved scope slice of the target, always including its default slice.
	 *
	 * @return list<array<string, string>>
	 */
	public function rows_for( CatalogTarget $target ): array {
		$is_variation = CatalogTargetDefinition::TARGET_VARIATION === $target->type;
		$scope_type   = $is_variation ? ConfigurationScopeType::Variation : ConfigurationScopeType::Product;
		$by_slice     = [ ConfigurationScope::DEFAULT_SLICE_KEY => null ];
		foreach ( $this->scopes->findByScope( $scope_type, $target->id ) as $config ) {
			if ( $config instanceof ScopedConfiguration ) {
				$by_slice[ $config->scope->slice_key ] = $config;
			}
		}
		ksort( $by_slice, SORT_STRING );

		$rows = [];
		foreach ( $by_slice as $slice_key => $config ) {
			$rows[] = $this->row_for( $target, (string) $slice_key, $config );
		}

		return $rows;
	}

	/**
	 * @return array<string, string>
	 */
	public function row_for( CatalogTarget $target, string $slice_key = ConfigurationScope::DEFAULT_SLICE_KEY, ?ScopedConfiguration $config = null ): array {
		$is_variation = CatalogTargetDefinition::TARGET_VARIATION === $target->type;
		$product_id   = $is_variation ? (int) ( $target->parent_id ?? 0 ) : $target->id;
		$variation_id = $is_variation ? $target->id : 0;
		$scope_type   = $is_variation ? ConfigurationScopeType::Variation : ConfigurationScopeType::Product;
		$config     ??= $this->scopes->findByScopeAndSlice( $scope_type, $target->id, $slice_key );

		$row = $this->base_row();
		$row['sku']           = $is_variation ? $this->targets->sku_for( CatalogTargetDefinition::TARGET_PRODUCT, $product_id ) : $target->external_key;
		$row['variation_sku'] = $is_variation ? $target->external_key : '';
		$row['product_id']    = $product_id > 0 ? (string) $product_id : (string) $target->id;
		$row['variation_id']  = $variation_id > 0 ? (string) $variation_id : '';
		$row['de_scope']      = $is_variation ? CatalogCsvMapper::SCOPE_VARIATION : CatalogCsvMapper::SCOPE_PRODUCT;
		$row['de_slice']      = $slice_key;

		$this->fill_instructions( $row, $config );

		try {
			$request = new EffectiveConfigurationRequest(
				$product_id > 0 ? $product_id : $target->id,
				$is_variation ? $target->id : null,
				$slice_key,
				$is_variation ? $product_id : null
			);
			$effective = $this->resolver->resolve( $request );
			$avail     = $effective->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY );
			$row['de_effective_fulfilment'] = is_object( $avail ) ? (string) $avail->value : '';
			$row['de_effective_source']     = is_object( $avail ) ? $avail->provenance->source_label : '';
		} catch ( \Throwable ) {
			$row['de_effective_fulfilment'] = '';
		}

		return $row;
	}

	/**
	 * @return array<string, string>
	 */
	private function base_row(): array {
		$row                      = array_fill_keys( CatalogCsvMapper::COLUMNS, '' );
		$row['de_format_version'] = CatalogCsvMapper::FORMAT_VERSION;

		return $row;
	}

	/**
	 * @param array<string, string> $row
	 */
	private function fill_instructions( array &$row, ?ScopedConfiguration $config ): void {
		$this->fill_scalar( $row, $config, ConfigurationFieldKey::FULFILMENT_AVAILABILITY, 'de_fulfilment_mode', 'de_fulfilment_availability' );
		$this->fill_scalar( $row, $config, ConfigurationFieldKey::FULFILMENT_CHOICE, 'de_fulfilment_choice_mode', 'de_fulfilment_choice' );
		$this->fill_scalar( $row, $config, ConfigurationFieldKey::LOGISTICS_PROFILE_ID, 'de_logistics_profile_mode', 'de_logistics_profile_code', 'logistics' );
		$this->fill_scalar( $row, $config, ConfigurationFieldKey::SUPPLIER_ID, 'de_supplier_mode', 'de_supplier_code', 'supplier' );
		$this->fill_scalar( $row, $config, ConfigurationFieldKey::ORIGIN_ID, 'de_origin_mode', 'de_origin_code', 'origin' );
		$this->fill_scalar( $row, $config, ConfigurationFieldKey::PICKUP_LOCATION_ID, 'de_pickup_location_mode', 'de_pickup_location_code', 'pickup' );
		$this->fill_scalar( $row, $config, ConfigurationFieldKey::ESTIMATED_DELIVERY, 'de_eta_mode', 'de_eta_value' );
		$this->fill_scalar( $row, $config, ConfigurationFieldKey::PRIORITY, 'de_priority_mode', 'de_priority_value' );

		$collection = $config instanceof ScopedConfiguration ? ( $config->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ] ?? null ) : null;
		if ( $collection instanceof CollectionFieldInstruction ) {
			$row['de_delivery_options_mode'] = $collection->mode->value;
			$codes = [];
			foreach ( $collection->members as $member ) {
				$codes[] = $this->codes->code_for_id( 'offer', (int) $member );
			}
			$row['de_delivery_option_codes'] = implode( ',', array_filter( $codes ) );
		} else {
			$row['de_delivery_options_mode'] = 'inherit';
		}
	}

	/**
	 * @param array<string, string> $row
	 */
	private function fill_scalar( array &$row, ?ScopedConfiguration $config, string $field_key, string $mode_col, string $value_col, string $code_kind = '' ): void {
		$instruction = $config instanceof ScopedConfiguration ? ( $config->scalars[ $field_key ] ?? null ) : null;
		if ( ! $instruction instanceof ScalarFieldInstruction ) {
			$row[ $mode_col ]  = 'inherit';
			$row[ $value_col ] = '';
			return;
		}
		$row[ $mode_col ] = $instruction->mode->value;
		if ( ScalarConfigurationMode::Override !== $instruction->mode ) {
			$row[ $value_col ] = '';
			return;
		}
		$value = $instruction->value;
		if ( '' !== $code_kind && is_numeric( $value ) ) {
			$row[ $value_col ] = $this->codes->code_for_id( $code_kind, (int) $value );
			return;
		}
		$row[ $value_col ] = is_scalar( $value ) ? (string) $value : '';
	}
}
