<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\ImportExport;

use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogFieldAction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;

/**
 * Catalog CSV mapping. Blank cells mean NO CHANGE, never inherit or zero.
 */
final class CatalogCsvMapper {

	public const COLUMNS = [
		'sku',
		'variation_sku',
		'product_id',
		'variation_id',
		'de_fulfilment_mode',
		'de_fulfilment_availability',
		'de_fulfilment_choice_mode',
		'de_fulfilment_choice',
		'de_delivery_options_mode',
		'de_delivery_option_codes',
		'de_logistics_profile_mode',
		'de_logistics_profile_code',
		'de_supplier_mode',
		'de_supplier_code',
		'de_origin_mode',
		'de_origin_code',
		'de_eta_mode',
		'de_eta_value',
		'de_priority_mode',
		'de_priority_value',
		'de_effective_fulfilment',
		'de_effective_source',
	];

	/**
	 * @param array<string, string> $row
	 * @return array{ok: bool, errors: list<string>, target_type: string, sku: string, product_id: int, variation_id: int, actions: list<array<string, mixed>>}
	 */
	public function map_row( array $row, int $line ): array {
		$errors = [];
		$sku    = trim( (string) ( $row['sku'] ?? '' ) );
		$vsku   = trim( (string) ( $row['variation_sku'] ?? '' ) );
		$pid    = $this->optional_int( $row['product_id'] ?? '' );
		$vid    = $this->optional_int( $row['variation_id'] ?? '' );
		if ( '' === $sku && $pid <= 0 ) {
			$errors[] = sprintf( 'Line %d: SKU or product_id is required.', $line );
		}

		$actions = [];
		$this->map_scalar( $actions, $errors, $line, $row, 'de_fulfilment_mode', 'de_fulfilment_availability', ConfigurationFieldKey::FULFILMENT_AVAILABILITY );
		$this->map_scalar( $actions, $errors, $line, $row, 'de_fulfilment_choice_mode', 'de_fulfilment_choice', ConfigurationFieldKey::FULFILMENT_CHOICE );
		$this->map_scalar( $actions, $errors, $line, $row, 'de_logistics_profile_mode', 'de_logistics_profile_code', ConfigurationFieldKey::LOGISTICS_PROFILE_ID, true );
		$this->map_scalar( $actions, $errors, $line, $row, 'de_supplier_mode', 'de_supplier_code', ConfigurationFieldKey::SUPPLIER_ID, true );
		$this->map_scalar( $actions, $errors, $line, $row, 'de_origin_mode', 'de_origin_code', ConfigurationFieldKey::ORIGIN_ID, true );
		$this->map_scalar( $actions, $errors, $line, $row, 'de_eta_mode', 'de_eta_value', ConfigurationFieldKey::ESTIMATED_DELIVERY );
		$this->map_scalar( $actions, $errors, $line, $row, 'de_priority_mode', 'de_priority_value', ConfigurationFieldKey::PRIORITY );
		$this->map_collection( $actions, $errors, $line, $row );

		return [
			'ok'           => [] === $errors,
			'errors'       => $errors,
			'target_type'  => '' !== $vsku || $vid > 0 ? 'variation' : 'product',
			'sku'          => '' !== $vsku ? $vsku : $sku,
			'product_id'   => $pid,
			'variation_id' => $vid,
			'actions'      => $actions,
		];
	}

	/**
	 * @param array<int, array<string, string>> $rows
	 * @return array{ok: bool, errors: list<string>, rows: list<array<string, mixed>>}
	 */
	public function parse_csv( string $csv ): array {
		$errors = [];
		$mapped = [];
		$handle = fopen( 'php://temp', 'r+' );
		if ( false === $handle ) {
			return [ 'ok' => false, 'errors' => [ 'Unable to read CSV.' ], 'rows' => [] ];
		}
		fwrite( $handle, $csv );
		rewind( $handle );
		$header = fgetcsv( $handle );
		if ( ! is_array( $header ) ) {
			fclose( $handle );
			return [ 'ok' => false, 'errors' => [ 'CSV header is missing.' ], 'rows' => [] ];
		}
		$header = array_map( static fn ( $col ): string => strtolower( trim( (string) $col ) ), $header );
		$line   = 1;
		while ( is_array( $data = fgetcsv( $handle ) ) ) {
			++$line;
			if ( $this->row_empty( $data ) ) {
				continue;
			}
			$row = [];
			foreach ( $header as $i => $name ) {
				$row[ $name ] = (string) ( $data[ $i ] ?? '' );
			}
			$mapped[] = $this->map_row( $row, $line );
		}
		fclose( $handle );

		foreach ( $mapped as $item ) {
			foreach ( $item['errors'] as $error ) {
				$errors[] = $error;
			}
		}

		return [
			'ok'     => [] === $errors,
			'errors' => $errors,
			'rows'   => $mapped,
		];
	}

	public static function escape_csv_value( string $value ): string {
		if ( str_starts_with( $value, '=' ) || str_starts_with( $value, '+' ) || str_starts_with( $value, '-' ) || str_starts_with( $value, '@' ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * @param list<array<string, mixed>> $actions
	 * @param list<string>               $errors
	 * @param array<string, string>      $row
	 */
	private function map_scalar( array &$actions, array &$errors, int $line, array $row, string $mode_col, string $value_col, string $field_key, bool $allow_disable = false ): void {
		$mode = strtolower( trim( (string) ( $row[ $mode_col ] ?? '' ) ) );
		if ( '' === $mode ) {
			return;
		}
		$value = trim( (string) ( $row[ $value_col ] ?? '' ) );
		if ( 'inherit' === $mode || CatalogFieldAction::CLEAR_OVERRIDE === $mode ) {
			$actions[] = [ 'field_key' => $field_key, 'action' => CatalogFieldAction::CLEAR_OVERRIDE ];
			return;
		}
		if ( 'disable' === $mode ) {
			if ( ! $allow_disable ) {
				$errors[] = sprintf( 'Line %d: disable is not allowed for %s.', $line, $field_key );
				return;
			}
			$actions[] = [ 'field_key' => $field_key, 'action' => CatalogFieldAction::DISABLE ];
			return;
		}
		if ( 'override' !== $mode && 'set_override' !== $mode ) {
			$errors[] = sprintf( 'Line %d: invalid mode "%s" for %s.', $line, $mode, $field_key );
			return;
		}
		if ( '' === $value ) {
			$errors[] = sprintf( 'Line %d: override for %s requires a value. Blank does not mean inherit.', $line, $field_key );
			return;
		}
		$actions[] = [
			'field_key' => $field_key,
			'action'    => CatalogFieldAction::SET_OVERRIDE,
			'value'     => $value,
		];
	}

	/**
	 * @param list<array<string, mixed>> $actions
	 * @param list<string>               $errors
	 * @param array<string, string>      $row
	 */
	private function map_collection( array &$actions, array &$errors, int $line, array $row ): void {
		$mode = strtolower( trim( (string) ( $row['de_delivery_options_mode'] ?? '' ) ) );
		if ( '' === $mode ) {
			return;
		}
		$codes = array_values(
			array_filter(
				array_map( 'trim', explode( ',', (string) ( $row['de_delivery_option_codes'] ?? '' ) ) )
			)
		);
		if ( 'inherit' === $mode ) {
			$actions[] = [
				'field_key' => ConfigurationFieldKey::DELIVERY_OFFER_IDS,
				'action'    => CatalogFieldAction::COLLECTION_INHERIT,
			];
			return;
		}
		if ( ! in_array( $mode, [ 'add', 'remove', 'replace' ], true ) ) {
			$errors[] = sprintf( 'Line %d: invalid delivery options mode "%s".', $line, $mode );
			return;
		}
		$actions[] = [
			'field_key' => ConfigurationFieldKey::DELIVERY_OFFER_IDS,
			'action'    => $mode,
			'members'   => $codes,
		];
	}

	private function optional_int( mixed $value ): int {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( ! ctype_digit( $value ) ) {
			return 0;
		}

		return (int) $value;
	}

	/**
	 * @param list<string|null> $data
	 */
	private function row_empty( array $data ): bool {
		foreach ( $data as $cell ) {
			if ( '' !== trim( (string) $cell ) ) {
				return false;
			}
		}

		return true;
	}
}
