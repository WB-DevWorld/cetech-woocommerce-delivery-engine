<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise\Persistence;

use CetechDeliveryEngine\Domain\ServicePromise\PromiseJson;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseLimits;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseShape;

/** Mutable generation head, never a substituted historical body or publication authority. */
final readonly class PromiseStoredObject implements \JsonSerializable {
	public const FIELDS = [ 'id', 'site_id', 'site_key', 'kind', 'logical_id', 'revision', 'last_sequence', 'draft_version_id', 'scheduled_version_id', 'published_version_id', 'latest_version_id', 'latest_source_receipt_digest', 'created_at', 'updated_at' ];
	private function __construct( private array $data ) {}
	public static function from_row( array $row, ?PromiseSiteBinding $binding = null, bool $allow_baseline = false ): self {
		PromiseStorageCodec::exact( $row, self::FIELDS ); PromiseStorageCodec::budget( $row ); $data = [];
		foreach ( self::FIELDS as $field ) { $data[$field] = $row[$field]; }
		foreach ( [ 'id', 'site_id', 'revision' ] as $field ) { $data[$field] = PromiseStorageCodec::integer( $row[$field] ); }
		$data['last_sequence'] = PromiseStorageCodec::integer( $row['last_sequence'], 0, PromiseLimits::VERSION_MAX );
		PromiseShape::id( $row['site_key'] ); PromiseShape::id( $row['logical_id'] ); PromiseShape::choice( $row['kind'], [ 'policy', 'calendar' ] );
		$pointers = [];
		foreach ( [ 'draft_version_id', 'scheduled_version_id', 'published_version_id' ] as $field ) { $data[$field] = PromiseStorageCodec::nullable_integer( $row[$field] ); if ( null !== $data[$field] ) { $pointers[] = $data[$field]; } }
		if ( count( $pointers ) !== count( array_unique( $pointers ) ) || ( 0 === $data['last_sequence'] && [] !== $pointers ) ) { PromiseShape::invalid(); }
		$data['latest_version_id'] = PromiseStorageCodec::nullable_integer( $row['latest_version_id'] );
		if ( 0 === $data['last_sequence'] ) {
			if ( ! $allow_baseline || 1 !== $data['revision'] || null !== $data['latest_version_id'] || null !== $row['latest_source_receipt_digest'] || $row['created_at'] !== $row['updated_at'] ) { PromiseShape::invalid(); }
		} elseif ( null === $data['latest_version_id'] || null === $row['latest_source_receipt_digest'] ) { PromiseShape::invalid(); }
		else { PromiseShape::digest( $row['latest_source_receipt_digest'] ); }
		$created = PromiseStorageCodec::time( $row['created_at'] ); $updated = PromiseStorageCodec::time( $row['updated_at'] ); if ( $created->compare( $updated ) > 0 ) { PromiseShape::invalid(); }
		$binding?->assert_row( $data ); return new self( $data );
	}
	public function row(): array { return $this->data; }
	public function id(): int { return $this->data['id']; }
	public function revision(): int { return $this->data['revision']; }
	public function site_id(): int { return $this->data['site_id']; }
	public function site_key(): string { return $this->data['site_key']; }
	public function kind(): string { return $this->data['kind']; }
	public function logical_id(): string { return $this->data['logical_id']; }
	public function logical_digest(): string { return self::identity_digest( $this->data['site_id'], $this->data['site_key'], $this->data['kind'], $this->data['logical_id'] ); }
	public function assert_latest( PromiseStoredVersion $version ): void {
		$version->assert_parent( $this ); $receipt = $version->source_receipt(); $facts = $receipt->private_facts();
		if ( $version->id() !== $this->data['latest_version_id'] || ! hash_equals( $receipt->digest(), $this->data['latest_source_receipt_digest'] ?? '' ) || 'primary' !== $facts['role'] || $facts['after_revision'] !== $this->revision() || $receipt->accepted_at()->sql() !== $this->data['updated_at'] ) { PromiseShape::invalid(); }
	}
	public static function identity_digest( int $site_id, string $site_key, string $kind, string $logical_id ): string {
		PromiseSiteBinding::bind( $site_id, $site_key ); PromiseShape::choice( $kind, [ 'policy', 'calendar' ] ); PromiseShape::id( $logical_id );
		return hash( 'sha256', 'cetech-promise-object-v1:' . PromiseJson::encode( [ 'site_id' => $site_id, 'site_key' => $site_key, 'kind' => $kind, 'logical_id' => $logical_id ], 1024 ) );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized promise object projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'An explicit authorized promise object projection is required.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promise object facts require strict row hydration.' ); }
}
