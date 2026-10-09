<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext,QuoteJson,QuoteShape};

/** Original finite native tax material. Private selectors are evidence, never an order access capability. */
final readonly class QuoteNativeTaxSource implements \JsonSerializable {
	private const TAX_FIELDS = [ 'currency', 'precision', 'exempt', 'tax_class', 'location_digest', 'rounding', 'tax_enabled', 'source' ];
	private const SOURCES = [ 'option_rows', 'tax_rows', 'tax_class_rows', 'tax_location_rows', 'method_rows', 'customer_rows' ];
	private function __construct( private array $facts ) {}
	public static function from_native_state( QuoteNativeState $state ): self { $s = $state->facts(); $source = $s['source_facts']; unset( $source['session_row'] ); return self::from_private_facts( [ 'format_version' => 1, 'digest_version' => 2, 'currency' => $s['currency'], 'precision' => $s['display_precision'], 'exempt' => $s['exempt'], 'tax_class' => $s['tax_class'], 'location_digest' => $s['location_digest'], 'rounding' => $s['rounding'], 'tax_enabled' => $s['tax_enabled'], 'source' => $source ] ); }
	public static function from_private_json( string $json ): self { $self = self::from_private_facts( QuoteJson::decode( $json ) ); if ( $self->to_private_json() !== $json ) { QuoteShape::invalid(); } return $self; }
	public static function from_private_facts( array $facts ): self {
		$facts = QuoteJson::detach( $facts ); QuoteShape::fields( $facts, [ 'format_version', 'digest_version', ...self::TAX_FIELDS ] ); if ( 1 !== $facts['format_version'] || 2 !== $facts['digest_version'] ) { QuoteShape::invalid(); }
		QuoteShape::currency( $facts['currency'] ); QuoteShape::integer( $facts['precision'], 0, 6 ); QuoteShape::digest( $facts['location_digest'] ); QuoteShape::choice( $facts['rounding'], [ 'per_line', 'subtotal' ] ); foreach ( [ 'exempt', 'tax_enabled' ] as $key ) { if ( ! is_bool( $facts[$key] ) ) { QuoteShape::invalid(); } }
		if ( ! is_string( $facts['tax_class'] ) || '' !== $facts['tax_class'] && 1 !== preg_match( '/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $facts['tax_class'] ) ) { QuoteShape::invalid(); }
		$source = QuoteShape::object( $facts['source'] ); QuoteShape::fields( $source, [ ...self::SOURCES, 'selectors' ] );
		foreach ( self::SOURCES as $kind ) { foreach ( QuoteShape::list( $source[$kind], 200 ) as $row ) { $row = QuoteShape::object( $row ); QuoteShape::fields( $row, QuoteNativeReceiptGuard::COLUMNS[$kind] ); foreach ( $row as $value ) { if ( ! is_string( $value ) || strlen( $value ) > 16384 ) { QuoteShape::invalid(); } } } }
		$selectors = QuoteShape::object( $source['selectors'] ); QuoteShape::fields( $selectors, [ 'option_names', 'tax_class', 'method_instance_ids', 'session_key', 'customer_id', 'site_id', 'table_prefix' ] ); QuoteShape::integer( $selectors['site_id'] ); QuoteShape::integer( $selectors['customer_id'], 0 ); QuoteShape::machine( $selectors['table_prefix'], 30 ); if ( ! is_string( $selectors['session_key'] ) || '' === $selectors['session_key'] || strlen( $selectors['session_key'] ) > 128 || $selectors['tax_class'] !== $facts['tax_class'] ) { QuoteShape::invalid(); }
		$seen = []; foreach ( QuoteShape::list( $selectors['option_names'], 64, 1 ) as $name ) { if ( ! is_string( $name ) || isset( $seen[$name] ) || ! in_array( $name, QuoteNativeWooSource::OPTIONS, true ) && 1 !== preg_match( '/\Awoocommerce_delivery_engine_selected_offer(?:_[0-9]+)?_settings\z/D', $name ) ) { QuoteShape::invalid(); } $seen[$name] = true; }
		$seen = []; foreach ( QuoteShape::list( $selectors['method_instance_ids'], 200, 1 ) as $id ) { QuoteShape::integer( $id, 0 ); if ( isset( $seen[$id] ) ) { QuoteShape::invalid(); } $seen[$id] = true; }
		return new self( $facts );
	}
	public function private_facts(): array { return $this->facts; }
	public function to_private_json(): string { return QuoteJson::encode( $this->facts ); }
	public function digest(): string { $facts = $this->facts; unset( $facts['format_version'], $facts['digest_version'] ); return hash( 'sha256', 'native-quote-tax-v2:' . QuoteJson::encode( $facts ) ); }
	public function matches_context( QuoteContext $context ): bool { $c = $context->private_facts(); return $this->facts['currency'] === $c['currency']['charged'] && $this->facts['precision'] === $c['currency']['precision'] && hash_equals( $this->digest(), $c['tax']['context_digest'] ); }
	/** Exact original scope, fresh native effective facts and fresh physical rows; session row remains a separate fence. */
	public function with_current( array $physical, array $effective ): self { QuoteShape::fields( $effective, [ 'currency', 'precision', 'exempt', 'tax_class', 'location_digest', 'rounding', 'tax_enabled' ] ); if ( QuoteJson::encode( [ 'selectors' => $physical['selectors'] ?? null ] ) !== QuoteJson::encode( [ 'selectors' => $this->facts['source']['selectors'] ] ) ) { QuoteShape::invalid(); } unset( $physical['session_row'] ); return self::from_private_facts( [ 'format_version' => 1, 'digest_version' => 2, ...$effective, 'source' => $physical ] ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Native tax source requires an explicit private consumer.' ); }
	public function __serialize(): never { throw new \LogicException( 'Native tax source requires an explicit private consumer.' ); }
}
