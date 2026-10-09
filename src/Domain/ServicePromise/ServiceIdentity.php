<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise;

/** Service names are descriptive facts, never policy or placement authority. */
final readonly class ServiceIdentity implements \JsonSerializable {
	public const FORMAT = 1;
	public const BUILT_IN_CODES = [ 'express', 'same_day', 'next_day', 'standard', 'relaxed' ];
	private function __construct( private string $json ) {}
	public static function from_array( array $data ): self {
		$data = PromiseJson::detach( $data, PromiseLimits::RECORD_BYTES );
		PromiseShape::fields( $data, [ 'format_version', 'kind', 'code', 'customer_label' ] );
		if ( self::FORMAT !== $data['format_version'] ) { PromiseShape::invalid(); }
		PromiseShape::choice( $data['kind'], [ 'built_in', 'merchant' ] ); PromiseShape::id( $data['code'] ); PromiseShape::text( $data['customer_label'], 120 );
		if ( ( 'built_in' === $data['kind'] ) !== in_array( $data['code'], self::BUILT_IN_CODES, true ) ) { PromiseShape::invalid(); }
		return new self( PromiseJson::encode( $data, PromiseLimits::RECORD_BYTES ) );
	}
	public static function from_json( string $json ): self { return self::from_array( PromiseJson::decode( $json, PromiseLimits::RECORD_BYTES ) ); }
	public function private_facts(): array { return PromiseJson::decode( $this->json, PromiseLimits::RECORD_BYTES ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-service-identity-v1:' . $this->json ); }
	public function __serialize(): never { throw new \LogicException( 'Private service promise serialization is forbidden.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Private service promise unserialization is forbidden.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized service identity projection is required.' ); }
}
