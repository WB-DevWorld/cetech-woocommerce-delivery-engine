<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\ImportExport;

use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogFieldAction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;

/**
 * Catalog CSV mapping. Blank cells mean NO CHANGE, never inherit or zero.
 *
 * COR-021 (D4): every export carries `de_format_version`; unknown versions are refused; rows that
 * cannot be mapped are reported with their line number and skipped, never dropped silently.
 * Rows address a product or variation scope slice (`de_scope`, `de_slice`) or a site-wide default
 * (`de_scope=global`; `de_slice` blank for the site-wide root, or a fulfilment type key).
 */
final class CatalogCsvMapper {

	public const FORMAT_VERSION = '2';

	/** Files without a `de_format_version` column are the pre-M2 layout. */
	public const LEGACY_FORMAT_VERSION = '1';

	public const SCOPE_PRODUCT   = 'product';
	public const SCOPE_VARIATION = 'variation';
	public const SCOPE_GLOBAL    = 'global';

	/** Columns that name private suppliers and origins; exported only on request by a private-capable user. */
	public const PRIVATE_COLUMNS = [
		'de_supplier_mode',
		'de_supplier_code',
		'de_origin_mode',
		'de_origin_code',
	];

	public const COLUMNS = [
		'de_format_version',
		'sku',
		'variation_sku',
		'product_id',
		'variation_id',
		'de_scope',
		'de_slice',
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
		'de_pickup_location_mode',
		'de_pickup_location_code',
		'de_eta_mode',
		'de_eta_value',
		'de_priority_mode',
		'de_priority_value',
		'de_effective_fulfilment',
		'de_effective_source',
	];

	/**
	 * The export header for a given privacy choice.
	 *
	 * @return list<string>
	 */
	public static function columns( bool $include_private ): array {
		if ( $include_private ) {
			return self::COLUMNS;
		}

		return array_values( array_diff( self::COLUMNS, self::PRIVATE_COLUMNS ) );
	}

	/**
	 * @param array<string, string> $row
	 * @return array{ok: bool, errors: list<string>, target_type: string, scope: string, slice: string, sku: string, product_id: int, variation_id: int, actions: list<array<string, mixed>>}
	 */
	public function map_row( array $row, int $line ): array {
		$errors = [];

		$version = trim( (string) ( $row['de_format_version'] ?? '' ) );
		if ( '' !== $version && self::FORMAT_VERSION !== $version ) {
			$errors[] = sprintf( 'Line %d: unsupported catalog CSV format version "%s"; this build reads version %s.', $line, $version, self::FORMAT_VERSION );
		}

		$sku   = trim( (string) ( $row['sku'] ?? '' ) );
		$vsku  = trim( (string) ( $row['variation_sku'] ?? '' ) );
		$pid   = $this->int_cell( $row, 'product_id', $errors, $line );
		$vid   = $this->int_cell( $row, 'variation_id', $errors, $line );
		$scope = strtolower( trim( (string) ( $row['de_scope'] ?? '' ) ) );
		$slice = strtolower( trim( (string) ( $row['de_slice'] ?? '' ) ) );

		if ( '' === $scope ) {
			$scope = '' !== $vsku || $vid > 0 ? self::SCOPE_VARIATION : self::SCOPE_PRODUCT;
		}
		if ( ! in_array( $scope, [ self::SCOPE_PRODUCT, self::SCOPE_VARIATION, self::SCOPE_GLOBAL ], true ) ) {
			$errors[] = sprintf( 'Line %d: unknown de_scope "%s" (use product, variation or global).', $line, $scope );
		}
		if ( '' !== $slice && ! FulfilmentProfileRegistry::has( $slice ) ) {
			$errors[] = sprintf( 'Line %d: unknown de_slice "%s"; use a fulfilment type key or leave it blank.', $line, $slice );
		}
		if ( self::SCOPE_GLOBAL !== $scope && '' === $sku && $pid <= 0 ) {
			$errors[] = sprintf( 'Line %d: SKU or product_id is required.', $line );
		}

		$actions = [];
		$this->map_scalar( $actions, $errors, $line, $row, 'de_fulfilment_mode', 'de_fulfilment_availability', ConfigurationFieldKey::FULFILMENT_AVAILABILITY );
		$this->map_scalar( $actions, $errors, $line, $row, 'de_fulfilment_choice_mode', 'de_fulfilment_choice', ConfigurationFieldKey::FULFILMENT_CHOICE );
		$this->map_scalar( $actions, $errors, $line, $row, 'de_logistics_profile_mode', 'de_logistics_profile_code', ConfigurationFieldKey::LOGISTICS_PROFILE_ID, true );
		$this->map_scalar( $actions, $errors, $line, $row, 'de_supplier_mode', 'de_supplier_code', ConfigurationFieldKey::SUPPLIER_ID, true );
		$this->map_scalar( $actions, $errors, $line, $row, 'de_origin_mode', 'de_origin_code', ConfigurationFieldKey::ORIGIN_ID, true );
		$this->map_scalar( $actions, $errors, $line, $row, 'de_pickup_location_mode', 'de_pickup_location_code', ConfigurationFieldKey::PICKUP_LOCATION_ID, true );
		$this->map_scalar( $actions, $errors, $line, $row, 'de_eta_mode', 'de_eta_value', ConfigurationFieldKey::ESTIMATED_DELIVERY );
		$this->map_scalar( $actions, $errors, $line, $row, 'de_priority_mode', 'de_priority_value', ConfigurationFieldKey::PRIORITY );
		$this->map_collection( $actions, $errors, $line, $row );

		return [
			'ok'           => [] === $errors,
			'errors'       => $errors,
			'target_type'  => $scope,
			'scope'        => $scope,
			'slice'        => $slice,
			'sku'          => '' !== $vsku ? $vsku : $sku,
			'product_id'   => $pid,
			'variation_id' => $vid,
			'actions'      => $actions,
		];
	}

	/**
	 * @param array<int, array<string, string>> $rows
	 * @return array{ok: bool, errors: list<string>, rows: list<array<string, mixed>>, format_version: string, report: list<string>}
	 *         `report` lists every row that was skipped and why; `errors` repeats them for callers
	 *         that only read errors. A file of an unknown format version is refused with no rows.
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
		$versioned      = in_array( 'de_format_version', $header, true );
		$format_version = $versioned ? '' : self::LEGACY_FORMAT_VERSION;
		$line           = 1;
		while ( is_array( $data = fgetcsv( $handle ) ) ) {
			++$line;
			if ( $this->row_empty( $data ) ) {
				continue;
			}
			$row = [];
			foreach ( $header as $i => $name ) {
				$row[ $name ] = (string) ( $data[ $i ] ?? '' );
			}
			if ( $versioned && '' === $format_version ) {
				$declared = trim( (string) ( $row['de_format_version'] ?? '' ) );
				if ( '' !== $declared && self::FORMAT_VERSION !== $declared ) {
					fclose( $handle );

					return [
						'ok'             => false,
						'errors'         => [ sprintf( 'Unsupported catalog CSV format version "%s". This build exports and reads version %s; export the catalog again from this build.', $declared, self::FORMAT_VERSION ) ],
						'rows'           => [],
						'format_version' => $declared,
						'report'         => [],
					];
				}
				$format_version = self::FORMAT_VERSION;
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
			'ok'             => [] === $errors,
			'errors'         => $errors,
			'rows'           => $mapped,
			'format_version' => '' === $format_version ? self::FORMAT_VERSION : $format_version,
			'report'         => $errors,
		];
	}

	/**
	 * Reverses escape_csv_value(): an exported cell that starts with a quote before =, +, - or @
	 * is a protected value, not data. Round-trips negative priorities such as -5.
	 */
	public static function unescape_csv_value( string $value ): string {
		if ( strlen( $value ) >= 2 && "'" === $value[0] && in_array( $value[1], [ '=', '+', '-', '@' ], true ) ) {
			return substr( $value, 1 );
		}

		return $value;
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
		$value = self::unescape_csv_value( trim( (string) ( $row[ $value_col ] ?? '' ) ) );
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
				array_map(
					static fn ( string $code ): string => self::unescape_csv_value( trim( $code ) ),
					explode( ',', (string) ( $row['de_delivery_option_codes'] ?? '' ) )
				)
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

	/**
	 * A numeric id cell: blank means none; anything but digits is reported, never coerced to zero.
	 *
	 * @param array<string, string> $row
	 * @param list<string>          $errors
	 */
	private function int_cell( array $row, string $column, array &$errors, int $line ): int {
		$value = trim( (string) ( $row[ $column ] ?? '' ) );
		if ( '' === $value ) {
			return 0;
		}
		if ( ! ctype_digit( $value ) ) {
			$errors[] = sprintf( 'Line %d: %s must be a whole number, got "%s".', $line, $column, $value );

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
