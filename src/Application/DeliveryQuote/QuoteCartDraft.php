<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;

/** Cheap loaded native facts only. No product, resolver, tax, provider or query is called. */
final readonly class QuoteCartDraft implements \JsonSerializable {
	private function __construct( private QuoteOwner $quote_owner, private string $json, private string $digest ) {}
	public static function from_loaded_cart( QuoteOwner $owner, array $loaded_cart_items, array $loaded_customer_destination, string $currency, int $precision, ?QuoteNativeContextIdentity $identity = null ): self {
		$identity ??= QuoteNativeContextIdentity::from_server(); if ( $identity->key_epoch() !== $owner->key_epoch() ) { QuoteShape::invalid(); }
		QuoteShape::currency( $currency ); QuoteShape::integer( $precision, 0, 6 );
		if ( [] === $loaded_cart_items || count( $loaded_cart_items ) > 200 ) { QuoteShape::invalid(); }
		$destination = self::destination( $loaded_customer_destination ); $lines = [];
		foreach ( $loaded_cart_items as $key => $item ) {
			QuoteShape::machine( $key, 128 ); if ( ! is_array( $item ) ) { QuoteShape::invalid(); }
			$product = QuoteShape::integer( $item['product_id'] ?? null ); $variation = $item['variation_id'] ?? 0; QuoteShape::integer( $variation, 0 );
			$quantity = QuoteShape::integer( $item['quantity'] ?? null, 1, 1000000000 );
			$raw = QuoteShape::object( $item[CartDeliverySelectionCapture::CART_SELECTION_KEY] ?? null ); $selection = [];
			foreach ( [ 'contract_version', 'product_id', 'variation_id', 'target_type', 'target_id', 'display_key', 'fulfilment_availability', 'fulfilment_choice', 'delivery_offer_id', 'rule_id' ] as $field ) { if ( ! array_key_exists( $field, $raw ) ) { QuoteShape::invalid(); } $selection[$field] = $raw[$field]; }
			$selection['configuration_fingerprint'] = $raw['configuration_fingerprint'] ?? null;
			if ( '1' !== $selection['contract_version'] || $selection['product_id'] !== $product || $selection['variation_id'] !== ( $variation ?: null ) ) { QuoteShape::invalid(); }
			QuoteShape::choice( $selection['fulfilment_choice'], [ 'delivery', 'store_pickup' ] );
			foreach ( [ 'target_type', 'display_key', 'fulfilment_availability' ] as $field ) { self::text( $selection[$field], 191 ); }
			QuoteShape::integer( $selection['target_id'] ); foreach ( [ 'delivery_offer_id', 'rule_id' ] as $field ) { if ( null !== $selection[$field] ) { QuoteShape::integer( $selection[$field] ); } }
			if ( null !== $selection['configuration_fingerprint'] ) { QuoteShape::digest( $selection['configuration_fingerprint'] ); }
			$customer = QuoteShape::object( $item[CustomerCartContext::CART_KEY] ?? null ); unset( $customer['recipient'] );
			if ( is_array( $customer['delivery_address'] ?? null ) ) { unset( $customer['delivery_address']['recipient'] ); foreach ( [ 'country', 'state', 'city', 'postcode', 'address', 'address_1', 'address_2' ] as $field ) { if ( array_key_exists( $field, $customer['delivery_address'] ) ) { self::text( $customer['delivery_address'][$field], 512, true ); } } }
			if ( ( $customer['contract_version'] ?? null ) !== 1 || ( $customer['fulfilment_choice'] ?? null ) !== $selection['fulfilment_choice'] ) { QuoteShape::invalid(); }
			$lines[] = [ 'line_key' => $key, 'product_id' => $product, 'variation_id' => $variation ?: null, 'quantity' => (string) $quantity, 'selection' => $selection, 'selection_hash' => QuoteShape::digest( $item[CartDeliverySelectionCapture::CART_HASH_KEY] ?? null ), 'customer_context' => $customer ];
		}
		usort( $lines, static fn( array $a, array $b ): int => strcmp( $a['line_key'], $b['line_key'] ) );
		$facts = [ 'format_version' => 1, 'owner_digest' => $owner->digest(), 'currency' => [ 'code' => $currency, 'precision' => $precision ], 'lines' => $lines, 'customer_destination' => $destination ]; $nodes = 0;
		$facts = self::normalize( $facts, 0, $nodes ); $json = json_encode( $facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); if ( strlen( $json ) > 65536 ) { QuoteShape::invalid(); }
		return new self( $owner, $json, $identity->cart_draft_digest( $owner->site_id(), $owner->digest(), $json ) );
	}
	public function owner(): QuoteOwner { return $this->quote_owner; }
	/** Private sealed-order restoration; every field passes the original loaded-draft grammar. */
	public static function from_private_facts( QuoteOwner $owner, array $facts, ?QuoteNativeContextIdentity $identity = null ): self {
		QuoteShape::fields( $facts, [ 'format_version', 'owner_digest', 'currency', 'lines', 'customer_destination' ] );
		if ( 1 !== $facts['format_version'] || ! is_string( $facts['owner_digest'] ) || ! hash_equals( $owner->digest(), $facts['owner_digest'] ) ) { QuoteShape::invalid(); }
		$currency = QuoteShape::object( $facts['currency'] ); QuoteShape::fields( $currency, [ 'code', 'precision' ] ); $items = [];
		foreach ( QuoteShape::list( $facts['lines'], 200, 1 ) as $line ) {
			$line = QuoteShape::object( $line ); QuoteShape::fields( $line, [ 'line_key', 'product_id', 'variation_id', 'quantity', 'selection', 'selection_hash', 'customer_context' ] );
			$key = QuoteShape::machine( $line['line_key'], 128 );
			if ( isset( $items[$key] ) || ! is_string( $line['quantity'] ) || 1 !== preg_match( '/\A[1-9][0-9]{0,9}\z/D', $line['quantity'] ) || (int) $line['quantity'] > 1000000000 ) { QuoteShape::invalid(); }
			$items[$key] = [ 'product_id' => $line['product_id'], 'variation_id' => $line['variation_id'] ?? 0, 'quantity' => (int) $line['quantity'], CartDeliverySelectionCapture::CART_SELECTION_KEY => $line['selection'], CartDeliverySelectionCapture::CART_HASH_KEY => $line['selection_hash'], CustomerCartContext::CART_KEY => $line['customer_context'] ];
		}
		$draft = self::from_loaded_cart( $owner, $items, QuoteShape::object( $facts['customer_destination'] ), $currency['code'], $currency['precision'], $identity );
		$nodes = 0; if ( $draft->private_facts() !== self::normalize( $facts, 0, $nodes ) ) { QuoteShape::invalid(); } return $draft;
	}
	public function draft_digest(): string { return $this->digest; }
	public function private_facts(): array { return json_decode( $this->json, true, 16, JSON_THROW_ON_ERROR ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Cart quote drafts require an explicit private consumer.' ); }
	public function __serialize(): never { throw new \LogicException( 'Cart quote drafts cannot be serialized generically.' ); }
	private static function destination( array $raw ): array {
		$fields = [ 'country', 'state', 'city', 'postcode', 'address', 'address_2' ]; $keys = array_keys( $raw ); sort( $keys, SORT_STRING ); $expected = $fields; sort( $expected, SORT_STRING ); if ( $keys !== $expected ) { QuoteShape::invalid(); }
		$out = []; foreach ( $fields as $field ) { $out[$field] = self::text( $raw[$field], 512, true ); } return $out;
	}
	private static function text( mixed $value, int $max, bool $empty = false ): string { if ( ! is_string( $value ) || strlen( $value ) > $max || ( ! $empty && '' === $value ) || 1 !== preg_match( '//u', $value ) || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) { QuoteShape::invalid(); } return $value; }
	private static function normalize( mixed $value, int $depth, int &$nodes ): mixed {
		if ( $depth > 12 || ++$nodes > 16384 ) { QuoteShape::invalid(); }
		if ( is_array( $value ) ) { if ( ! array_is_list( $value ) ) { foreach ( array_keys( $value ) as $key ) { if ( ! is_string( $key ) ) { QuoteShape::invalid(); } } ksort( $value, SORT_STRING ); } $out = []; foreach ( $value as $key => $child ) { $out[$key] = self::normalize( $child, $depth + 1, $nodes ); } return $out; }
		if ( is_string( $value ) ) { return self::text( $value, 2048, true ); } if ( null === $value || is_int( $value ) || is_bool( $value ) ) { return $value; } QuoteShape::invalid();
	}
}
