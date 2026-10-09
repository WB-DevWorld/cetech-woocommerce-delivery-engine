<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise\Persistence;

use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseJson;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseShape;

/** Explicit trusted linkage; an opaque policy site identifier is never cast to a blog ID. */
final readonly class PromiseSiteBinding implements \JsonSerializable {
	private function __construct( private int $native_site, private string $key ) {}
	public static function bind( int $native_site_id, string $site_key ): self {
		PromiseShape::integer( $native_site_id, 1 ); PromiseShape::id( $site_key ); return new self( $native_site_id, $site_key );
	}
	public static function from_array( array $facts ): self {
		$facts = PromiseJson::detach( $facts, 1024 ); PromiseShape::fields( $facts, [ 'format_version', 'site_id', 'site_key' ] );
		if ( 1 !== $facts['format_version'] ) { PromiseShape::invalid(); }
		return self::bind( PromiseShape::integer( $facts['site_id'], 1 ), PromiseShape::id( $facts['site_key'] ) );
	}
	public static function from_json( string $json ): self { return self::from_array( PromiseJson::decode( $json, 1024 ) ); }
	public function site_id(): int { return $this->native_site; }
	public function native_site_id(): int { return $this->native_site; }
	public function site_key(): string { return $this->key; }
	public function assert_session( OperationSession $session ): void { if ( $session->site_id() !== $this->native_site || $session->is_retired() ) { PromiseShape::invalid(); } }
	public function assert_row( array $row ): void {
		if ( PromiseStorageCodec::integer( $row['site_id'] ?? null ) !== $this->native_site || ( $row['site_key'] ?? null ) !== $this->key ) { PromiseShape::invalid(); }
	}
	public function private_facts(): array { return [ 'format_version' => 1, 'site_id' => $this->native_site, 'site_key' => $this->key ]; }
	public function to_private_json(): string { return PromiseJson::encode( $this->private_facts(), 1024 ); }
	public function digest(): string { return hash( 'sha256', 'cetech-promise-site-binding-v1:' . $this->to_private_json() ); }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized promise site projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'An explicit authorized promise site projection is required.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promise site linkage requires strict construction.' ); }
}
