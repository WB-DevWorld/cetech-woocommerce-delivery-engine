<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourcePlan;
use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourceSnapshot;
use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourceSeedRows;
use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStorageCodec;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Bounded physical capture. Caller owns, releases and retires the native unit. */
final class LegacyQuoteSourceSnapshotReader {
	public const MAX_CANDIDATES = 1000;
	public const MAX_SOURCE_ROWS = 2500;
	public const MAX_CAPTURE_BYTES = 2097152;
	private const MAX_TEXT_BYTES = 8192;
	private const RATE_FIELDS = [ 'id', 'internal_code', 'delivery_offer_id', 'destination_zone_id', 'logistics_profile_id', 'supplier_id', 'origin_id', 'charge_type', 'base_amount', 'base_currency', 'included_weight', 'increment_weight', 'increment_amount', 'per_item_amount', 'per_line_amount', 'highest_fee_mode', 'remote_surcharge', 'free_shipping_threshold', 'manual_currency_override_data', 'priority', 'effective_from', 'effective_to', 'status', 'created_at', 'updated_at' ];
	/** Source construction only: no rates, quote body, eligibility or currentness claim. */
	public function capture_seed( OperationSession $session, QuoteOwner $owner, array $fences ): LegacyQuoteSourceSeedRows {
		try {
			if ( $session->is_retired() || ! $session->in_transaction() || $session->site_id() !== $owner->site_id() ) { self::fail(); }
			$selectors = []; foreach ( LegacyQuoteSourceSeedRows::checked_fences( $fences ) as $fence ) { $selectors[LegacyQuoteSourcePlan::table( $session, $fence['source'] )] = $fence; } ksort( $selectors, SORT_STRING );
			if ( ! $session->validate_tables( array_keys( $selectors ) ) ) { self::fail(); } $columns = [];
			foreach ( $selectors as $table => $selector ) { $columns[$table] = $this->columns( $session, $table, $selector['source'] ); }
			$scope_ids = []; $opened_scopes = [];
			foreach ( $selectors as $table => $selector ) { if ( 'scopes' === $selector['source'] ) { $opened_scopes = $this->read( $session, $table, $columns[$table], $this->predicate( $session, $selector, [] ), 1001, false ); foreach ( $opened_scopes as $row ) { $scope_ids[] = QuoteStorageCodec::integer( $row['id'] ); } } }
			$rows = []; $count = 0; $bytes = 0;
			foreach ( $selectors as $table => $selector ) {
				$source = $selector['source']; $limit = 'zones' === $source ? 201 : 1001;
				$values = $this->read( $session, $table, $columns[$table], $this->predicate( $session, $selector, $scope_ids ), $limit, true ); if ( count( $values ) >= $limit || ( 'scopes' === $source && $opened_scopes !== $values ) ) { self::fail(); }
				foreach ( $values as $row ) { $this->budget( $row, $count, $bytes ); } $rows[$source] = $values;
			}
			$at = $session->get_row( 'SELECT UTC_TIMESTAMP(6) AS utc' ); if ( ! is_array( $at ) || array_keys( $at ) !== [ 'utc' ] || ! is_string( $at['utc'] ) ) { self::fail(); }
			return new LegacyQuoteSourceSeedRows( $rows, QuoteTime::parse( $at['utc'] ) );
		} catch ( \Throwable ) { self::fail(); }
	}
	public function capture( OperationSession $session, QuoteOwner $owner, QuoteContext $context, LegacyQuoteSourcePlan $plan ): LegacyQuoteSourceSnapshot {
		try {
			if ( $session->is_retired() || ! $session->in_transaction() || $session->site_id() !== $owner->site_id() || ! $owner->equals( $plan->owner() ) || ! $plan->matches_context( $context ) || ! $session->validate_tables( $plan->tables( $session ) ) ) { self::fail(); }
			$selectors = []; foreach ( $plan->fences() as $fence ) { $selectors[LegacyQuoteSourcePlan::table( $session, $fence['source'] )] = $fence; }
			$rate_table = WpdbOperationRecordRepository::table_name( $session, 'rate_cards' ); $selectors[$rate_table] = [ 'source' => 'rate_cards' ]; ksort( $selectors, SORT_STRING );
			$columns = []; foreach ( $selectors as $table => $selector ) { $columns[$table] = $this->columns( $session, $table, $selector['source'] ); }
			$opened_scopes = []; $scope_ids = [];
			foreach ( $selectors as $table => $selector ) { if ( 'scopes' !== $selector['source'] ) { continue; } $opened_scopes = $this->read( $session, $table, $columns[$table], $this->predicate( $session, $selector, [] ), 1001, false ); foreach ( $opened_scopes as $row ) { $scope_ids[] = QuoteStorageCodec::integer( $row['id'] ); } }
			$rows = []; $cards = []; $count = 0; $bytes = 0; $candidate_count = 0;
			foreach ( $selectors as $table => $selector ) {
				$source = $selector['source'];
				if ( 'rate_cards' === $source ) {
					foreach ( $plan->rate_ranges() as $range ) {
						$remaining = self::MAX_CANDIDATES - $candidate_count; $predicate = $session->prepare( 'delivery_offer_id=%d AND destination_zone_id=%d AND base_currency=%s', $range['delivery_offer_id'], $range['destination_zone_id'], $range['base_currency'] );
						$values = $this->read( $session, $table, $columns[$table], $predicate, $remaining + 1, true, 'quote_candidate_range' );
						if ( count( $values ) > $remaining ) { self::fail(); } $candidate_count += count( $values );
						foreach ( $values as $row ) { $this->rate( $row, $range ); $this->budget( $row, $count, $bytes ); } $cards[LegacyQuoteSourcePlan::range_key( $range )] = $values;
					} continue;
				}
				$limit = 'zones' === $source ? 201 : 1001; $values = $this->read( $session, $table, $columns[$table], $this->predicate( $session, $selector, $scope_ids ), $limit, true ); if ( count( $values ) >= $limit ) { self::fail(); }
				if ( 'scopes' === $source && $opened_scopes !== $values ) { self::fail(); }
				foreach ( $values as $row ) { $this->budget( $row, $count, $bytes ); } $rows[$source] = $values;
			}
			$this->relationships( $context, $rows, $plan ); $at = $session->get_row( 'SELECT UTC_TIMESTAMP(6) AS utc' ); if ( ! is_array( $at ) || array_keys( $at ) !== [ 'utc' ] || ! is_string( $at['utc'] ) ) { self::fail(); }
			return LegacyQuoteSourceSnapshot::captured( $plan, $context, $rows, $cards, QuoteTime::parse( $at['utc'] ) );
		} catch ( \Throwable ) { self::fail(); }
	}
	private function columns( OperationSession $session, string $table, string $source ): array {
		$metadata = $session->get_results( "SHOW FULL COLUMNS FROM `{$table}`" ); if ( false === $metadata || ! array_is_list( $metadata ) || [] === $metadata || count( $metadata ) > 48 ) { self::fail(); } $columns = [];
		foreach ( $metadata as $column ) { $name = $column['Field'] ?? null; if ( ! is_string( $name ) || 1 !== preg_match( '/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $name ) || isset( $columns[$name] ) || ! is_string( $column['Type'] ?? null ) ) { self::fail(); } $columns[$name] = $column['Type']; }
		$fixed = match ( $source ) { 'rate_cards' => self::RATE_FIELDS, 'product' => [ 'ID', 'post_parent', 'post_type', 'post_status', 'post_modified_gmt' ], 'product_meta' => [ 'meta_id', 'post_id', 'meta_key', 'meta_value' ], 'term_relationships' => [ 'object_id', 'term_taxonomy_id', 'term_order' ], 'term_taxonomy' => [ 'term_taxonomy_id', 'term_id', 'taxonomy', 'parent', 'count', 'description' ], 'options' => [ 'option_id', 'option_name', 'option_value', 'autoload' ], default => array_keys( $columns ) };
		foreach ( $fixed as $field ) { if ( ! isset( $columns[$field] ) ) { self::fail(); } } if ( 'rate_cards' === $source && count( $columns ) !== count( self::RATE_FIELDS ) ) { self::fail(); }
		$out = []; foreach ( $fixed as $field ) { $out[$field] = $columns[$field]; } return $out;
	}
	private function predicate( OperationSession $session, array $selector, array $scope_ids ): string {
		$source = $selector['source'];
		if ( in_array( $source, [ 'scope_fields', 'scope_collections' ], true ) ) { return [] === $scope_ids ? '1=0' : 'scope_row_id IN (' . implode( ',', $scope_ids ) . ')'; }
		if ( in_array( $source, [ 'zones', 'zone_rules', 'coverage_groups', 'coverage_members', 'coverage_postcodes' ], true ) ) { return '1=1'; }
		if ( 'options' === $source ) { $names = array_map( static fn( string $name ): string => $session->prepare( '%s', $name ), $selector['names'] ); return 'option_name IN (' . implode( ',', $names ) . ')'; }
		if ( in_array( $source, [ 'scopes', 'legacy_rules' ], true ) ) { $prefix = 'scopes' === $source ? 'scope' : 'target'; $parts = []; foreach ( $selector['targets'] as $target ) { $parts[] = $session->prepare( "({$prefix}_type=%s AND {$prefix}_id=%d)", $target['type'], $target['id'] ); } return '(' . implode( ' OR ', $parts ) . ')'; }
		$field = match ( $source ) { 'product' => 'ID', 'product_meta' => 'post_id', 'term_relationships' => 'object_id', 'term_taxonomy' => 'term_taxonomy_id', default => 'id' }; return "`{$field}` IN (" . implode( ',', $selector['ids'] ) . ')';
	}
	private function read( OperationSession $session, string $table, array $columns, string $predicate, int $limit, bool $lock, ?string $index = null ): array {
		$projection = []; $overflow = []; foreach ( $columns as $name => $type ) { if ( 1 === preg_match( '/char|text|blob|binary|json/i', $type ) ) { $projection[] = "CASE WHEN OCTET_LENGTH(`{$name}`)<=" . self::MAX_TEXT_BYTES . " THEN `{$name}` ELSE NULL END AS `{$name}`"; $overflow[] = "OCTET_LENGTH(`{$name}`)>" . self::MAX_TEXT_BYTES; } else { $projection[] = "`{$name}`"; } }
		$projection[] = ( [] === $overflow ? '0' : 'CASE WHEN ' . implode( ' OR ', $overflow ) . ' THEN 1 ELSE 0 END' ) . ' AS source_oversized'; $order = isset( $columns['id'] ) ? '`id`' : ( isset( $columns['ID'] ) ? '`ID`' : ( isset( $columns['meta_id'] ) ? '`meta_id`' : ( isset( $columns['option_id'] ) ? '`option_id`' : ( isset( $columns['object_id'] ) ? '`object_id`,`term_taxonomy_id`' : '`term_taxonomy_id`' ) ) ) );
		$sql = 'SELECT ' . implode( ',', $projection ) . " FROM `{$table}`" . ( null === $index ? '' : ' FORCE INDEX (`quote_candidate_range`)' ) . " WHERE {$predicate} ORDER BY {$order} ASC LIMIT {$limit}" . ( $lock ? ' FOR UPDATE' : '' );
		$rows = $session->get_results( $sql ); if ( false === $rows || ! array_is_list( $rows ) || count( $rows ) > $limit ) { self::fail(); } $out = []; $previous = null;
		foreach ( $rows as $row ) { if ( ! is_array( $row ) || ! in_array( $row['source_oversized'] ?? null, [ 0, '0' ], true ) ) { self::fail(); } unset( $row['source_oversized'] ); if ( count( $row ) !== count( $columns ) || [] !== array_diff( array_keys( $columns ), array_keys( $row ) ) ) { self::fail(); } foreach ( $row as &$value ) { if ( null !== $value && ! is_string( $value ) && ! is_int( $value ) ) { self::fail(); } if ( is_int( $value ) ) { $value = (string) $value; } } unset( $value );
			$key = isset( $row['id'] ) ? [ QuoteStorageCodec::integer( $row['id'] ) ] : ( isset( $row['ID'] ) ? [ QuoteStorageCodec::integer( $row['ID'] ) ] : ( isset( $row['meta_id'] ) ? [ QuoteStorageCodec::integer( $row['meta_id'] ) ] : ( isset( $row['option_id'] ) ? [ QuoteStorageCodec::integer( $row['option_id'] ) ] : ( isset( $row['object_id'] ) ? [ QuoteStorageCodec::integer( $row['object_id'] ), QuoteStorageCodec::integer( $row['term_taxonomy_id'] ) ] : [ QuoteStorageCodec::integer( $row['term_taxonomy_id'] ) ] ) ) ) );
			if ( null !== $previous && $key <= $previous ) { self::fail(); } $previous = $key; $out[] = $row;
		} return $out;
	}
	private function budget( array $row, int &$count, int &$bytes ): void { ++$count; $bytes += strlen( QuoteJson::encode( $row ) ); if ( $count > self::MAX_SOURCE_ROWS || $bytes > self::MAX_CAPTURE_BYTES ) { self::fail(); } }
	private function rate( array $row, array $range ): void { if ( (int) $row['delivery_offer_id'] !== $range['delivery_offer_id'] || (int) $row['destination_zone_id'] !== $range['destination_zone_id'] || $row['base_currency'] !== $range['base_currency'] || ! in_array( $row['status'], [ 'active', 'inactive' ], true ) ) { self::fail(); } foreach ( [ 'effective_from', 'effective_to' ] as $key ) { if ( null !== $row[$key] ) { QuoteTime::parse( $row[$key] . '.000000' ); } } }
	private function relationships( QuoteContext $context, array $rows, LegacyQuoteSourcePlan $plan ): void {
		$products = []; foreach ( $rows['product'] as $row ) { $products[(int) $row['ID']] = $row; }
		foreach ( $context->private_facts()['lines'] as $line ) { $product = $products[$line['product_id']] ?? null; if ( null === $product || 'product' !== $product['post_type'] || 'publish' !== $product['post_status'] ) { self::fail(); } if ( null !== $line['variation_id'] ) { $variation = $products[$line['variation_id']] ?? null; if ( null === $variation || 'product_variation' !== $variation['post_type'] || 'publish' !== $variation['post_status'] || (int) $variation['post_parent'] !== $line['parent_id'] || $line['parent_id'] !== $line['product_id'] ) { self::fail(); } } }
		$offers = []; foreach ( $rows['offers'] as $row ) { $offers[(int) $row['id']] = $row; } $zones = []; foreach ( $rows['zones'] as $row ) { $zones[(int) $row['id']] = $row; }
		foreach ( $context->private_facts()['groups'] as $group ) { if ( 'active' !== ( $offers[$group['offer_id']]['status'] ?? null ) || 'active' !== ( $zones[$group['destination_zone_id']]['status'] ?? null ) || $group['service_id'] !== $group['offer_id'] ) { self::fail(); } }
		// Taxonomy-dependent routing cannot be claimed from an omitted native hierarchy.
		if ( [] !== ( $rows['term_relationships'] ?? [] ) && ! isset( $rows['term_taxonomy'] ) ) { self::fail(); }
		$known_taxonomy = []; foreach ( $rows['term_taxonomy'] ?? [] as $row ) { $known_taxonomy[(int) $row['term_taxonomy_id']] = $row; } foreach ( $rows['term_relationships'] ?? [] as $row ) { if ( ! isset( $known_taxonomy[(int) $row['term_taxonomy_id']] ) ) { self::fail(); } }
	}
	private static function fail(): never { throw new OperationStorageException(); }
}
