<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise;

/** An exact known duration, including zero. No unit inference or calendar arithmetic. */
final readonly class PromiseDuration implements \JsonSerializable {
	public const FORMAT = 1;
	public const UNITS = [ 'elapsed_minutes', 'calendar_days', 'business_minutes', 'business_days' ];
	private function __construct( private string $json ) {}
	public static function from_array( array $data ): self {
		$data = PromiseJson::detach( $data, PromiseLimits::RECORD_BYTES ); PromiseShape::fields( $data, [ 'format_version', 'min', 'max', 'unit', 'calendar' ] );
		if ( self::FORMAT !== $data['format_version'] || PromiseShape::integer( $data['min'] ) > PromiseShape::integer( $data['max'] ) ) { PromiseShape::invalid(); }
		PromiseShape::choice( $data['unit'], self::UNITS );
		if ( 'elapsed_minutes' === $data['unit'] ) { if ( null !== $data['calendar'] ) { PromiseShape::invalid(); } }
		else { $data['calendar'] = PromiseCalendarReference::from_array( PromiseShape::object( $data['calendar'] ) )->private_facts(); }
		return new self( PromiseJson::encode( $data, PromiseLimits::RECORD_BYTES ) );
	}
	public static function from_json( string $json ): self { return self::from_array( PromiseJson::decode( $json, PromiseLimits::RECORD_BYTES ) ); }
	public function private_facts(): array { return PromiseJson::decode( $this->json, PromiseLimits::RECORD_BYTES ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-promise-duration-v1:' . $this->json ); }
	public function __serialize(): never { throw new \LogicException( 'Private service promise serialization is forbidden.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Private service promise unserialization is forbidden.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized promise duration projection is required.' ); }
}
