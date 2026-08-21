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

/**
 * Streams Product/Variation Delivery Engine CSV rows in keyset pages.
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
	public function write_stream( $handle, CatalogTargetDefinition $definition ): int {
		fputcsv( $handle, CatalogCsvMapper::COLUMNS );
		$after = 0;
		$count = 0;
		do {
			$page = $this->targets->page_after( $definition, $after, self::PAGE_SIZE );
			if ( [] === $page ) {
				break;
			}
			foreach ( $page as $target ) {
				if ( ! $target instanceof CatalogTarget ) {
					continue;
				}
				$after = $target->id;
				$row   = $this->row_for( $target );
				$line  = [];
				foreach ( CatalogCsvMapper::COLUMNS as $header ) {
					$line[] = CatalogCsvMapper::escape_csv_value( (string) ( $row[ $header ] ?? '' ) );
				}
				fputcsv( $handle, $line );
				++$count;
			}
		} while ( count( $page ) >= self::PAGE_SIZE );

		return $count;
	}

	/**
	 * @return array<string, string>
	 */
	public function row_for( CatalogTarget $target ): array {
		$is_variation = CatalogTargetDefinition::TARGET_VARIATION === $target->type;
		$product_id   = $is_variation ? (int) ( $target->parent_id ?? 0 ) : $target->id;
		$variation_id = $is_variation ? $target->id : 0;
		$scope_type   = $is_variation ? ConfigurationScopeType::Variation : ConfigurationScopeType::Product;
		$config       = $this->scopes->findByScopeAndSlice( $scope_type, $target->id, ConfigurationScope::DEFAULT_SLICE_KEY );

		$row = array_fill_keys( CatalogCsvMapper::COLUMNS, '' );
		$row['sku']           = $is_variation ? $this->targets->sku_for( CatalogTargetDefinition::TARGET_PRODUCT, $product_id ) : $target->external_key;
		$row['variation_sku'] = $is_variation ? $target->external_key : '';
		$row['product_id']    = $product_id > 0 ? (string) $product_id : (string) $target->id;
		$row['variation_id']  = $variation_id > 0 ? (string) $variation_id : '';

		$this->fill_scalar( $row, $config, ConfigurationFieldKey::FULFILMENT_AVAILABILITY, 'de_fulfilment_mode', 'de_fulfilment_availability' );
		$this->fill_scalar( $row, $config, ConfigurationFieldKey::FULFILMENT_CHOICE, 'de_fulfilment_choice_mode', 'de_fulfilment_choice' );
		$this->fill_scalar( $row, $config, ConfigurationFieldKey::LOGISTICS_PROFILE_ID, 'de_logistics_profile_mode', 'de_logistics_profile_code', 'logistics' );
		$this->fill_scalar( $row, $config, ConfigurationFieldKey::SUPPLIER_ID, 'de_supplier_mode', 'de_supplier_code', 'supplier' );
		$this->fill_scalar( $row, $config, ConfigurationFieldKey::ORIGIN_ID, 'de_origin_mode', 'de_origin_code', 'origin' );
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

		try {
			$request = new EffectiveConfigurationRequest(
				$product_id > 0 ? $product_id : $target->id,
				$is_variation ? $target->id : null,
				ConfigurationScope::DEFAULT_SLICE_KEY,
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
