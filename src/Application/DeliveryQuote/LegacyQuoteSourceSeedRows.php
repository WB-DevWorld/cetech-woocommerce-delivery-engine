<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Infrastructure\Persistence\LegacyQuoteSourceSnapshotReader;

/** Bounded observed rows for constructing an input. This is no quote or currentness receipt. */
final readonly class LegacyQuoteSourceSeedRows implements \JsonSerializable {
	private array $rows;
	public function __construct( array $rows, private QuoteTime $observed_at ) {
		$count = 0; $bytes = 0; $copy = [];
		foreach ( $rows as $source => $values ) {
			if ( ! is_string( $source ) || ! isset( LegacyQuoteSourcePlan::SOURCES[$source] ) || ! is_array( $values ) || ! array_is_list( $values ) || count( $values ) > ( 'zones' === $source ? 200 : 1000 ) ) { QuoteShape::invalid(); }
			$copy[$source] = [];
			foreach ( $values as $row ) {
				if ( ! is_array( $row ) || array_is_list( $row ) ) { QuoteShape::invalid(); }
				foreach ( $row as $name => $value ) { if ( ! is_string( $name ) || ( null !== $value && ! is_string( $value ) && ! is_int( $value ) ) ) { QuoteShape::invalid(); } }
				$detached = QuoteJson::detach( $row ); ++$count; $bytes += strlen( QuoteJson::encode( $detached ) );
				if ( $count > LegacyQuoteSourceSnapshotReader::MAX_SOURCE_ROWS || $bytes > LegacyQuoteSourceSnapshotReader::MAX_CAPTURE_BYTES ) { QuoteShape::invalid(); }
				$copy[$source][] = $detached;
			}
		} ksort( $copy, SORT_STRING ); $this->rows = $copy;
	}
	public function rows_for( string $source ): array { return $this->rows[$source] ?? []; }
	public function observed_at(): QuoteTime { return $this->observed_at; }
	/** Observed rows only; this digest makes no rate-candidate or currentness assertion. */
	public function digest(): string {
		$hash = hash_init( 'sha256' ); hash_update( $hash, 'cetech-cart-quote-source-seed-v1:' );
		foreach ( $this->rows as $source => $rows ) { hash_update( $hash, strlen( $source ) . ':' . $source . ':' . count( $rows ) . ':' ); foreach ( $rows as $row ) { hash_update( $hash, hex2bin( LegacyQuoteSourceSnapshot::row_digest( $row ) ) ); } }
		return hash_final( $hash );
	}
	/** Fixed selector grammar shared with the retained source universe, without a provisional quote. */
	public static function checked_fences( array $fences ): array {
		if ( ! array_is_list( $fences ) || [] === $fences || count( $fences ) > count( LegacyQuoteSourcePlan::SOURCES ) ) { QuoteShape::invalid(); } $checked = [];
		foreach ( $fences as $fence ) {
			if ( ! is_array( $fence ) || ! is_string( $fence['source'] ?? null ) || ! isset( LegacyQuoteSourcePlan::SOURCES[$fence['source']] ) || isset( $checked[$fence['source']] ) ) { QuoteShape::invalid(); } $source = $fence['source'];
			if ( 'options' === $source ) {
				QuoteShape::fields( $fence, [ 'source', 'names' ] ); $names = QuoteShape::list( $fence['names'], count( LegacyQuoteSourcePlan::OPTIONS ), 1 );
				foreach ( $names as $name ) { if ( ! in_array( $name, LegacyQuoteSourcePlan::OPTIONS, true ) ) { QuoteShape::invalid(); } } if ( count( array_unique( $names ) ) !== count( $names ) ) { QuoteShape::invalid(); } sort( $names, SORT_STRING ); $fence['names'] = $names;
			} elseif ( in_array( $source, [ 'scopes', 'legacy_rules' ], true ) ) {
				QuoteShape::fields( $fence, [ 'source', 'targets' ] ); $targets = [];
				foreach ( QuoteShape::list( $fence['targets'], 600, 1 ) as $target ) { QuoteShape::fields( $target, [ 'type', 'id' ] ); $type = QuoteShape::choice( $target['type'], 'scopes' === $source ? [ 'global', 'product', 'variation' ] : [ 'product', 'variation', 'category' ] ); $id = QuoteShape::integer( $target['id'], 'global' === $type ? 0 : 1 ); if ( 'global' === $type && 0 !== $id ) { QuoteShape::invalid(); } $key = $type . ':' . str_pad( (string) $id, 20, '0', STR_PAD_LEFT ); if ( isset( $targets[$key] ) ) { QuoteShape::invalid(); } $targets[$key] = [ 'type' => $type, 'id' => $id ]; } ksort( $targets, SORT_STRING ); $fence['targets'] = array_values( $targets );
			} elseif ( in_array( $source, [ 'scope_fields', 'scope_collections', 'zones', 'zone_rules', 'coverage_groups', 'coverage_members', 'coverage_postcodes' ], true ) ) { QuoteShape::fields( $fence, [ 'source' ] ); }
			else { QuoteShape::fields( $fence, [ 'source', 'ids' ] ); $ids = QuoteShape::list( $fence['ids'], 600, 1 ); foreach ( $ids as $id ) { QuoteShape::integer( $id ); } if ( count( array_unique( $ids ) ) !== count( $ids ) ) { QuoteShape::invalid(); } sort( $ids, SORT_NUMERIC ); $fence['ids'] = $ids; }
			$checked[$source] = $fence;
		} ksort( $checked, SORT_STRING ); return array_values( $checked );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Source seed rows are private.' ); }
	public function __serialize(): never { throw new \LogicException( 'Source seed rows cannot be serialized generically.' ); }
}
