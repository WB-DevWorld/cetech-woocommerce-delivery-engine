<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise;

/** Nonbinding, captured observation. Never a reservation, hold or admission receipt. */
final readonly class PromiseCapacityObservation implements \JsonSerializable {
	private function __construct( private array $data ) {}
	public static function from_array( array $data ): self {
		$data = PromiseJson::detach( $data );
		$mode = PromiseShape::choice( $data['mode'] ?? null, [ 'none', 'required' ] );
		PromiseShape::integer( $data['format_version'] ?? null, 1, 1 );
		if ( 'none' === $mode ) {
			PromiseShape::fields( $data, [ 'format_version', 'mode' ] );
			return new self( [ 'format_version' => 1, 'mode' => 'none' ] );
		}
		PromiseShape::fields( $data, [ 'format_version', 'mode', 'site_id', 'service_digest', 'endpoint_digest', 'window', 'source', 'revision', 'state', 'observed_at', 'valid_until' ] );
		$window = PromiseShape::object( $data['window'] );
		PromiseShape::fields( $window, [ 'from', 'until', 'display_timezone' ] );
		$from = PromiseShape::instant( $window['from'] ); $until = PromiseShape::instant( $window['until'] );
		if ( $from->compare( $until ) > 0 ) { PromiseShape::invalid(); }
		$observed = PromiseShape::instant( $data['observed_at'] ); $valid = PromiseShape::instant( $data['valid_until'] );
		if ( $valid->compare( $observed ) <= 0 ) { PromiseShape::invalid(); }
		$source = PromiseShape::object( $data['source'] );
		PromiseShape::fields( $source, [ 'format_version', 'site_id', 'source_id', 'version', 'digest' ] );
		$site = PromiseShape::id( $data['site_id'] );
		PromiseShape::integer( $source['format_version'], 1, 1 );
		if ( $site !== PromiseShape::id( $source['site_id'] ) ) { PromiseShape::invalid(); }
		return new self( [
			'format_version' => 1, 'mode' => $mode, 'site_id' => $site,
			'service_digest' => PromiseShape::digest( $data['service_digest'] ), 'endpoint_digest' => PromiseShape::digest( $data['endpoint_digest'] ),
			'window' => [ 'from' => $from->sql(), 'until' => $until->sql(), 'display_timezone' => PromiseShape::timezone( $window['display_timezone'] ) ],
			'source' => [ 'format_version' => 1, 'site_id' => $site, 'source_id' => PromiseShape::id( $source['source_id'] ), 'version' => PromiseShape::integer( $source['version'], 1, PromiseLimits::VERSION_MAX ), 'digest' => PromiseShape::digest( $source['digest'] ) ],
			'revision' => PromiseShape::integer( $data['revision'], 1, PromiseLimits::VERSION_MAX ),
			'state' => PromiseShape::choice( $data['state'], [ 'available', 'unavailable', 'unknown' ] ), 'observed_at' => $observed->sql(), 'valid_until' => $valid->sql(),
		] );
	}
	public static function from_json( string $json ): self { return self::from_array( PromiseJson::decode( $json ) ); }
	public function mode(): string { return $this->data['mode']; }
	public function state(): ?string { return $this->data['state'] ?? null; }
	public function private_facts(): array { return $this->data; }
	public function to_private_json(): string { return PromiseJson::encode( $this->data ); }
	public function digest(): string { return hash( 'sha256', 'cetech-service-promise-capacity-v1:' . $this->to_private_json() ); }
	public function __serialize(): never { throw new \LogicException( 'Private service promise serialization is forbidden.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Private service promise unserialization is forbidden.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized promise projection is required.' ); }
}
