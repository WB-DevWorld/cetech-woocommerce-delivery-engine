<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryQuote;

/** Exact private physical/logical membership. Presence is not payment admission. */
final readonly class QuoteBinding implements \JsonSerializable {
	public const FIELDS = [ 'id', 'site_id', 'format_version', 'quote_uuid', 'order_id', 'placement_uuid', 'managed_group_manifest_digest', 'mapping_json', 'native_money_digest', 'accepted_body_digest', 'snapshot_digest', 'context_digest', 'bind_namespace_hash', 'seal_namespace_hash', 'state', 'revision', 'created_at', 'verified_at', 'sealed_at' ];
	private function __construct( private array $data ) {}

	public static function from_row( array $row, QuoteStoredRow $quote ): self {
		QuoteStorageCodec::exact( $row, self::FIELDS ); $data = [];
		foreach ( self::FIELDS as $field ) { $data[$field] = $row[$field]; }
		foreach ( [ 'id', 'site_id', 'format_version', 'order_id', 'revision' ] as $field ) { $data[$field] = QuoteStorageCodec::integer( $row[$field] ); }
		if ( 1 !== $data['format_version'] || $data['site_id'] !== $quote->site_id() || ! QuoteStorageCodec::uuid( $row['quote_uuid'] )->equals( $quote->header()->id() ) || null === $quote->accepted_at() || null === $quote->context() ) { QuoteShape::invalid(); }
		QuoteStorageCodec::uuid( $row['placement_uuid'] );
		foreach ( [ 'managed_group_manifest_digest', 'native_money_digest', 'accepted_body_digest', 'bind_namespace_hash', 'seal_namespace_hash' ] as $field ) { QuoteShape::digest( $row[$field] ); }
		if ( ! hash_equals( $quote->header()->body_digest(), $row['accepted_body_digest'] ) || $row['bind_namespace_hash'] === $row['seal_namespace_hash']
			|| ! hash_equals( $quote->context()->private_facts()['tax']['native_money_digest'], $row['native_money_digest'] ) ) { QuoteShape::invalid(); }
		$mapping = self::canonical_mapping( QuoteStorageCodec::json( $row['mapping_json'] ) );
		if ( $row['mapping_json'] !== QuoteJson::encode( $mapping ) || ! hash_equals( $row['managed_group_manifest_digest'], self::manifest_digest( $mapping ) ) ) { QuoteShape::invalid(); }
		$context = $quote->context()->private_facts(); $members = []; foreach ( $context['lines'] as $line ) { $members[$line['line_key']] = $line; }
		$groups = []; foreach ( $context['groups'] as $group ) { $groups[$group['component_key']] = $group; }
		if ( count( $mapping['groups'] ) !== count( $groups ) ) { QuoteShape::invalid(); }
		foreach ( $mapping['groups'] as $group ) {
			$original = $groups[$group['component_key']] ?? null; if ( null === $original || count( $original['line_keys'] ) !== count( $group['lines'] ) ) { QuoteShape::invalid(); }
			foreach ( $group['lines'] as $line ) {
				$member = $members[$line['line_key']] ?? null; if ( null === $member || ! in_array( $line['line_key'], $original['line_keys'], true ) ) { QuoteShape::invalid(); }
				foreach ( [ 'product_id', 'variation_id', 'parent_id', 'quantity' ] as $field ) { if ( $line[$field] !== $member[$field] ) { QuoteShape::invalid(); } }
			}
		}
		$state = QuoteShape::choice( $row['state'], [ 'prepared', 'sealed' ] ); $created = QuoteStorageCodec::time( $row['created_at'] ); $verified = QuoteStorageCodec::nullable_time( $row['verified_at'] ); $sealed = QuoteStorageCodec::nullable_time( $row['sealed_at'] );
		$snapshot = QuoteStorageCodec::nullable_digest( $row['snapshot_digest'] ); $digest = QuoteStorageCodec::nullable_digest( $row['context_digest'] );
		if ( ( null === $verified ) !== ( null === $snapshot ) || ( null === $verified ) !== ( null === $digest ) || $created->compare( $quote->accepted_at() ) < 0
			|| ( null !== $verified && $verified->compare( $created ) < 0 ) || ( 'prepared' === $state && null !== $sealed )
			|| ( 'prepared' === $state && $data['revision'] !== ( null === $verified ? 1 : 2 ) )
			|| ( 'sealed' === $state && ( null === $verified || null === $sealed || $sealed->compare( $verified ) < 0 || 3 !== $data['revision'] ) ) ) { QuoteShape::invalid(); }
		return new self( $data );
	}

	public static function canonical_mapping( array $mapping ): array {
		$mapping = QuoteJson::detach( $mapping ); QuoteShape::fields( $mapping, [ 'format_version', 'groups' ] ); if ( 1 !== $mapping['format_version'] ) { QuoteShape::invalid(); }
		$groups = QuoteShape::list( $mapping['groups'], 200, 1 ); $components = []; $keys = []; $items = []; $count = 0;
		foreach ( $groups as &$group ) {
			$group = QuoteShape::object( $group ); QuoteShape::fields( $group, [ 'component_key', 'lines' ] ); $component = QuoteShape::digest( $group['component_key'] ); if ( isset( $components[$component] ) ) { QuoteShape::invalid(); } $components[$component] = true;
			$lines = QuoteShape::list( $group['lines'], 200, 1 );
			foreach ( $lines as &$line ) {
				$line = QuoteShape::object( $line ); QuoteShape::fields( $line, [ 'line_key', 'product_id', 'variation_id', 'parent_id', 'quantity', 'item_id' ] ); $key = QuoteShape::machine( $line['line_key'], 128 ); $item = QuoteShape::integer( $line['item_id'] ); QuoteShape::integer( $line['product_id'] );
				if ( isset( $keys[$key] ) || isset( $items[$item] ) || ++$count > 200 ) { QuoteShape::invalid(); } $keys[$key] = true; $items[$item] = true;
				if ( null === $line['variation_id'] ) { if ( null !== $line['parent_id'] ) { QuoteShape::invalid(); } }
				else { QuoteShape::integer( $line['variation_id'] ); QuoteShape::integer( $line['parent_id'] ); if ( $line['parent_id'] !== $line['product_id'] || $line['variation_id'] === $line['product_id'] ) { QuoteShape::invalid(); } }
				if ( ! is_string( $line['quantity'] ) || 1 !== preg_match( '/\A(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,6}))?\z/D', $line['quantity'], $parts ) ) { QuoteShape::invalid(); }
				$fraction = rtrim( $parts[2] ?? '', '0' ); if ( '0' === $parts[1] && '' === $fraction ) { QuoteShape::invalid(); } $line['quantity'] = $parts[1] . ( '' === $fraction ? '' : '.' . $fraction );
			} unset( $line );
			usort( $lines, static fn( array $a, array $b ): int => strcmp( $a['line_key'], $b['line_key'] ) ); $group['lines'] = $lines;
		} unset( $group );
		usort( $groups, static fn( array $a, array $b ): int => strcmp( $a['component_key'], $b['component_key'] ) ); $mapping['groups'] = $groups; return $mapping;
	}
	public static function manifest_digest( array $mapping ): string { return hash( 'sha256', 'cetech-quote-binding-manifest-v1:' . QuoteJson::encode( self::canonical_mapping( $mapping ) ) ); }
	public function row(): array { return $this->data; }
	public function id(): int { return $this->data['id']; }
	public function site_id(): int { return $this->data['site_id']; }
	public function revision(): int { return $this->data['revision']; }
	public function state(): string { return $this->data['state']; }
	public function mapping(): array { return QuoteStorageCodec::json( $this->data['mapping_json'] ); }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized quote binding projection is required.' ); }
	public function __serialize(): array { throw new \LogicException( 'An explicit authorized quote binding projection is required.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Quote binding facts require strict row hydration.' ); }
}
