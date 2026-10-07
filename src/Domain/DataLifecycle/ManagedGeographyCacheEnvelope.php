<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DataLifecycle;

/** Strict, detached, public derived response; never a generic array serializer. */
final readonly class ManagedGeographyCacheEnvelope implements \JsonSerializable {
	public const CLASS_ID = 'geography_response_cache_v1';
	public const MAX_PAYLOAD_BYTES = 65536;
	public const MAX_BYTES = 67584;
	private function __construct( private int $site, private string $kind_value, private string $digest_value, private string $generation_value, private int $expires, private array $payload_value ) {}
	public static function create( ManagedGeographyCacheIdentity $identity, array $payload, int $observed_at, ?string $generation = null ): self {
		if ( $observed_at < 0 || $observed_at > PHP_INT_MAX - 120 ) { self::invalid(); }
		$generation ??= bin2hex( random_bytes( 16 ) );
		return self::from_array( [ 'format' => 1, 'class' => self::CLASS_ID, 'site_id' => $identity->site_id(), 'kind' => $identity->kind(), 'identity_digest' => $identity->digest(), 'generation' => $generation, 'expires_at' => $observed_at + 120, 'payload' => self::project( $identity->kind(), $payload, true ) ] );
	}
	public static function from_json( string $json ): self {
		if ( strlen( $json ) > self::MAX_BYTES ) { self::invalid(); }
		try {
			$decoded = json_decode( $json, true, 8, JSON_THROW_ON_ERROR );
			if ( ! is_array( $decoded ) || array_is_list( $decoded ) ) { self::invalid(); }
			// Own producers encode one canonical representation. This also rejects
			// duplicate property names, numeric coercion and non-object empty maps.
			$envelope = self::from_array( $decoded );
			if ( $envelope->to_json() !== $json ) { self::invalid(); }
			return $envelope;
		} catch ( \Throwable ) { self::invalid(); }
	}
	public static function from_array( array $value ): self {
		self::fields( $value, [ 'format', 'class', 'site_id', 'kind', 'identity_digest', 'generation', 'expires_at', 'payload' ] );
		if ( 1 !== $value['format'] || self::CLASS_ID !== $value['class'] || ! is_int( $value['site_id'] ) || $value['site_id'] < 1 || ! is_string( $value['kind'] ) || ! in_array( $value['kind'], [ 'children', 'search', 'postcode' ], true ) || ! is_string( $value['identity_digest'] ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/D', $value['identity_digest'] ) || ! is_string( $value['generation'] ) || 1 !== preg_match( '/\A[0-9a-f]{32}\z/D', $value['generation'] ) || ! is_int( $value['expires_at'] ) || $value['expires_at'] < 120 || ! is_array( $value['payload'] ) ) { self::invalid(); }
		$payload = self::project( $value['kind'], $value['payload'] );
		$copy = new self( $value['site_id'], $value['kind'], $value['identity_digest'], $value['generation'], $value['expires_at'], $payload );
		$nodes = 0; self::bounded( $copy->internal_fields(), 0, $nodes );
		if ( strlen( $copy->to_json() ) > self::MAX_BYTES ) { self::invalid(); }
		return $copy;
	}
	public function site_id(): int { return $this->site; }
	public function kind(): string { return $this->kind_value; }
	public function identity_digest(): string { return $this->digest_value; }
	public function generation(): string { return $this->generation_value; }
	public function expires_at(): int { return $this->expires; }
	public function payload(): array { return $this->payload_value; }
	public function matches( ManagedGeographyCacheIdentity $identity ): bool { return $identity->site_id() === $this->site && $identity->kind() === $this->kind_value && hash_equals( $identity->digest(), $this->digest_value ); }
	public function valid_at( int $now ): bool { return $now >= 0 && $this->expires - 120 <= $now && $now < $this->expires; }
	public function internal_fields(): array { return [ 'format' => 1, 'class' => self::CLASS_ID, 'site_id' => $this->site, 'kind' => $this->kind_value, 'identity_digest' => $this->digest_value, 'generation' => $this->generation_value, 'expires_at' => $this->expires, 'payload' => $this->payload_value ]; }
	public function to_json(): string { return self::encode( $this->internal_fields() ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Managed cache envelope requires an explicit projection.' ); }
	private static function project( string $kind, array $payload, bool $producer = false ): array {
		if ( $producer && 'search' === $kind ) { unset( $payload['request_token'] ); }
		if ( 'postcode' === $kind ) {
			self::fields( $payload, [ 'required', 'visible' ] );
			if ( ! is_bool( $payload['required'] ) || ! is_bool( $payload['visible'] ) || $payload['required'] !== $payload['visible'] ) { self::invalid(); }
			return [ 'required' => $payload['required'], 'visible' => $payload['visible'] ];
		}
		$fields = 'children' === $kind ? [ 'items', 'page', 'total', 'has_more', 'label', 'skip_admin' ] : [ 'items', 'page', 'total', 'has_more', 'has_pack' ];
		self::fields( $payload, $fields );
		if ( ! is_array( $payload['items'] ) || ! array_is_list( $payload['items'] ) || count( $payload['items'] ) > ( 'children' === $kind ? 50 : 25 ) || ! is_int( $payload['page'] ) || $payload['page'] < 1 || ! is_int( $payload['total'] ) || $payload['total'] < 0 || ! is_bool( $payload['has_more'] ) ) { self::invalid(); }
		$items = []; $keys = [];
		foreach ( $payload['items'] as $item ) {
			if ( ! is_array( $item ) ) { self::invalid(); }
			self::fields( $item, [ 'key', 'name', 'type', 'label', 'breadcrumb' ], [ 'code' ] );
			$key = self::text( $item['key'], 144 );
			if ( isset( $keys[$key] ) || ! is_string( $item['type'] ) || ! in_array( $item['type'], [ 'country', 'administrative', 'locality' ], true ) || ( 'search' === $kind && 'locality' !== $item['type'] ) ) { self::invalid(); }
			$keys[$key] = true;
			$copy = [ 'key' => $key, 'name' => self::text( $item['name'], 764 ), 'type' => $item['type'], 'label' => self::text( $item['label'], 8192 ), 'breadcrumb' => self::text( $item['breadcrumb'], 8192, true ) ];
			if ( array_key_exists( 'code', $item ) ) { if ( ! is_string( $item['code'] ) || strlen( $item['code'] ) > 64 || 1 !== preg_match( '/\A[A-Z0-9]+\z/D', $item['code'] ) ) { self::invalid(); } $copy['code'] = (string) $item['code']; }
			$items[] = $copy;
		}
		$copy = [ 'items' => $items, 'page' => $payload['page'], 'total' => $payload['total'], 'has_more' => $payload['has_more'] ];
		if ( 'children' === $kind ) { if ( ! is_bool( $payload['skip_admin'] ) ) { self::invalid(); } $copy['label'] = self::text( $payload['label'], 1024 ); $copy['skip_admin'] = $payload['skip_admin']; }
		else { if ( ! is_bool( $payload['has_pack'] ) ) { self::invalid(); } $copy['has_pack'] = $payload['has_pack']; }
		if ( strlen( self::encode( $copy ) ) > self::MAX_PAYLOAD_BYTES ) { self::invalid(); }
		return $copy;
	}
	private static function fields( array $value, array $required, array $optional = [] ): void { if ( array_is_list( $value ) || [] !== array_diff( $required, array_keys( $value ) ) || [] !== array_diff( array_keys( $value ), [ ...$required, ...$optional ] ) ) { self::invalid(); } }
	private static function text( mixed $text, int $max, bool $empty = false ): string { if ( ! is_string( $text ) || ( ! $empty && '' === $text ) || strlen( $text ) > $max || 1 !== preg_match( '//u', $text ) || 1 === preg_match( '/[\x00-\x1f\x7f]/', $text ) ) { self::invalid(); } return (string) $text; }
	private static function encode( array $value ): string { return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 8 ); }
	private static function bounded( mixed $value, int $depth, int &$nodes ): void { if ( $depth > 8 || ++$nodes > 1024 ) { self::invalid(); } if ( is_array( $value ) ) { foreach ( $value as $item ) { self::bounded( $item, $depth + 1, $nodes ); } } }
	private static function invalid(): never { throw new \InvalidArgumentException( 'Invalid managed geography cache envelope.' ); }
}
