<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise;

/** Captured internal link; constructing it supplies no persistence or publication authority. */
final readonly class PromiseCalendarReference implements \JsonSerializable {
	public const FORMAT = 1;
	private function __construct( private string $json ) {}
	public static function from_array( array $data ): self {
		$data = PromiseJson::detach( $data, PromiseLimits::RECORD_BYTES );
		PromiseShape::fields( $data, [ 'format_version', 'site_id', 'calendar_id', 'version', 'digest' ] );
		if ( self::FORMAT !== $data['format_version'] ) { PromiseShape::invalid(); }
		PromiseShape::id( $data['site_id'] ); PromiseShape::id( $data['calendar_id'] );
		PromiseShape::integer( $data['version'], 1, PromiseLimits::VERSION_MAX ); PromiseShape::digest( $data['digest'] );
		return new self( PromiseJson::encode( $data, PromiseLimits::RECORD_BYTES ) );
	}
	public static function from_json( string $json ): self { return self::from_array( PromiseJson::decode( $json, PromiseLimits::RECORD_BYTES ) ); }
	public function private_facts(): array { return PromiseJson::decode( $this->json, PromiseLimits::RECORD_BYTES ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-promise-calendar-reference-v1:' . $this->json ); }
	public function content_digest(): string { return $this->private_facts()['digest']; }
	public function site_id(): string { return $this->private_facts()['site_id']; }
	public function calendar_id(): string { return $this->private_facts()['calendar_id']; }
	public function version(): int { return $this->private_facts()['version']; }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicitly authorized service promise projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'Private service promise values cannot be implicitly serialized.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Private service promise values require an explicit validated factory.' ); }
}
