<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Domain\ServicePromise\Handoff;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteJson, QuoteOwner, QuoteShape, QuoteTime};
use CetechDeliveryEngine\Domain\ServicePromise\PromiseShape;

/** Required recorded per-group history, independent of the later acknowledged final-event companion. */
final readonly class PromiseQuotePacket implements \JsonSerializable {
	private function __construct( private string $json ) {}
	public static function from_array( array $data ): self {
		$data = QuoteJson::detach( $data ); QuoteShape::fields( $data, [ 'format', 'groups' ] ); if ( 1 !== $data['format'] ) { QuoteShape::invalid(); } $seen = []; $groups = QuoteShape::list( $data['groups'], 200, 1 );
		foreach ( $groups as &$group ) { $group = QuoteShape::object( $group ); QuoteShape::fields( $group, [ 'component_key', 'packet' ] ); $key = QuoteShape::digest( $group['component_key'] ); if ( isset( $seen[$key] ) ) { QuoteShape::invalid(); } $seen[$key] = true; $packet = PromiseHistoricalPacket::from_array( QuoteShape::object( $group['packet'] ) ); if ( $packet->input_facts()['material']['group_id'] !== $key ) { QuoteShape::invalid(); } $group['packet'] = $packet->private_facts(); } unset( $group );
		usort( $groups, static fn( array $a, array $b ): int => strcmp( $a['component_key'], $b['component_key'] ) ); $data['groups'] = $groups; return new self( QuoteJson::encode( $data ) );
	}
	public static function from_json( string $json ): self { $packet = self::from_array( QuoteJson::decode( $json ) ); if ( $packet->to_private_json() !== $json ) { QuoteShape::invalid(); } return $packet; }
	public function private_facts(): array { return QuoteJson::decode( $this->json ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-required-promise-snapshot-v1:' . $this->json ); }
	public function group( string $component_key ): PromiseHistoricalPacket { QuoteShape::digest( $component_key ); foreach ( $this->private_facts()['groups'] as $group ) { if ( $group['component_key'] === $component_key ) { return PromiseHistoricalPacket::from_array( $group['packet'] ); } } QuoteShape::invalid(); }
	public function feasibility_at( QuoteTime $at ): bool { foreach ( $this->private_facts()['groups'] as $group ) { if ( ! PromiseHistoricalPacket::from_array( $group['packet'] )->feasibility_at( $at ) ) { return false; } } return true; }
	public function assert_component_keys( array $keys ): void { sort( $keys, SORT_STRING ); $actual = array_column( $this->private_facts()['groups'], 'component_key' ); if ( $actual !== $keys ) { QuoteShape::invalid(); } }
	public function assert_quote_capture( QuoteHeader $header, QuoteContext $context ): void {
		if ( 2 !== $header->format_version() ) { QuoteShape::invalid(); } $this->assert_context_capture( $context, $header->owner() ); $this->assert_clock( $header->created_at(), $header->expires_at() );
	}
	public function assert_context_capture( QuoteContext $context, ?QuoteOwner $native_owner = null ): void {
		if ( 2 !== $context->format_version() ) { QuoteShape::invalid(); } $base = $context->base_context()->private_facts(); $this->assert_component_keys( array_column( $base['groups'], 'component_key' ) ); $capture = $context->private_facts()['promise_capture']; $captures = []; foreach ( $capture['groups'] as $group ) { $captures[$group['component_key']] = $group; }
		foreach ( $this->private_facts()['groups'] as $group ) { $packet = PromiseHistoricalPacket::from_array( $group['packet'] ); $input = $packet->input_facts(); $captured = $captures[$group['component_key']] ?? null;
			if ( null === $captured || $input['site_id'] !== $capture['site_key'] || $input['material']['material_digest'] !== $context->base_material_digest() || $packet->private_facts()['input_json'] !== $captured['input'] || $packet->private_facts()['input_digest'] !== $captured['input_digest'] ) { QuoteShape::invalid(); }
			if ( null !== $native_owner ) { $owner = $native_owner->facts(); $owner['site_id'] = $capture['site_key']; if ( QuoteJson::encode( $input['owner'] ) !== QuoteJson::encode( $owner ) ) { QuoteShape::invalid(); } }
		}
	}
	public function assert_clock( QuoteTime $issued, QuoteTime $expires ): void {
		foreach ( $this->private_facts()['groups'] as $group ) { $input = PromiseHistoricalPacket::from_array( $group['packet'] )->input_facts(); if ( $input['evaluated_at'] !== $issued->sql() || $input['anchor']['quote_expires_at'] !== $expires->sql() ) { QuoteShape::invalid(); } }
	}
	public function public_groups(): array { $out = []; foreach ( $this->private_facts()['groups'] as $group ) { $out[] = [ 'component_key' => $group['component_key'], ...PromiseHistoricalPacket::from_array( $group['packet'] )->public_facts() ]; } return $out; }
	public function jsonSerialize(): never { throw new \LogicException( 'An authorized frozen promise projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'Private promise packets cannot be serialized.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Private promise packets require strict history decoding.' ); }
}
