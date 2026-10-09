<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise\Persistence;

use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\BusinessCalendarVersion;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseCalendarReference;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseLimits;
use CetechDeliveryEngine\Domain\ServicePromise\PromisePolicyReference;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseShape;
use CetechDeliveryEngine\Domain\ServicePromise\ServicePromisePolicy;

/** Exact immutable body with separate lifecycle facts. SQL acknowledgment is verified by the repository. */
final readonly class PromiseStoredVersion implements \JsonSerializable {
	public const FIELDS = [ 'id', 'site_id', 'site_key', 'object_id', 'kind', 'logical_id', 'version_uuid', 'format_version', 'domain_version', 'row_revision', 'state', 'body_json', 'body_digest', 'declared_from', 'declared_until', 'created_at', 'sealed_at', 'scheduled_at', 'published_at', 'retired_at', 'author_user_id', 'reason', 'predecessor_version_id', 'schedule_expected_object_revision', 'schedule_expected_published_version_id', 'create_receipt_json', 'create_receipt_digest', 'publication_receipt_json', 'publication_receipt_digest', 'source_receipt_json', 'source_receipt_digest' ];
	private function __construct( private array $data, private ServicePromisePolicy|BusinessCalendarVersion $version_body, private PromiseSourceReceipt $creation, private ?PromiseSourceReceipt $publication, private PromiseSourceReceipt $source ) {}
	public static function from_row( array $row, ?PromiseSiteBinding $binding = null ): self {
		PromiseStorageCodec::exact( $row, self::FIELDS ); PromiseStorageCodec::budget( $row ); $data = [];
		foreach ( self::FIELDS as $field ) { $data[$field] = $row[$field]; }
		foreach ( [ 'id', 'site_id', 'object_id', 'format_version', 'row_revision', 'author_user_id' ] as $field ) { $data[$field] = PromiseStorageCodec::integer( $row[$field] ); }
		$data['domain_version'] = PromiseStorageCodec::integer( $row['domain_version'], 1, PromiseLimits::VERSION_MAX );
		foreach ( [ 'predecessor_version_id', 'schedule_expected_object_revision', 'schedule_expected_published_version_id' ] as $field ) { $data[$field] = PromiseStorageCodec::nullable_integer( $row[$field] ); }
		if ( 1 !== $data['format_version'] || $data['id'] === $data['predecessor_version_id'] || $data['id'] === $data['schedule_expected_published_version_id'] ) { PromiseShape::invalid(); }
		PromiseShape::id( $row['site_key'] ); PromiseShape::id( $row['logical_id'] ); PromiseStorageCodec::uuid( $row['version_uuid'] ); PromiseShape::text( $row['reason'], 256 );
		$kind = PromiseShape::choice( $row['kind'], [ 'policy', 'calendar' ] ); $state = PromiseShape::choice( $row['state'], [ 'draft', 'sealed', 'scheduled', 'published', 'retired' ] );
		$json = PromiseStorageCodec::bytes( $row['body_json'], PromiseLimits::RECORD_BYTES ); $body = 'policy' === $kind ? ServicePromisePolicy::from_json( $json ) : BusinessCalendarVersion::from_json( $json ); $facts = $body->private_facts();
		if ( $json !== $body->to_private_json() || $facts['site_id'] !== $row['site_key'] || $facts['version'] !== $data['domain_version'] || $facts['policy' === $kind ? 'policy_id' : 'calendar_id'] !== $row['logical_id'] || ! hash_equals( $body->digest(), PromiseShape::digest( $row['body_digest'] ) ) ) { PromiseShape::invalid(); }
		$times = [];
		foreach ( [ 'declared_from', 'created_at' ] as $field ) { $times[$field] = PromiseStorageCodec::time( $row[$field] ); }
		foreach ( [ 'declared_until', 'sealed_at', 'scheduled_at', 'published_at', 'retired_at' ] as $field ) { $times[$field] = PromiseStorageCodec::nullable_time( $row[$field] ); }
		PromiseStorageCodec::chronological( $times['declared_from'], $times['declared_until'] );
		if ( 'policy' === $kind && ( $facts['effective_from'] !== $row['declared_from'] || $facts['effective_until'] !== $row['declared_until'] ) ) { PromiseShape::invalid(); }
		$last = $times['created_at'];
		foreach ( [ 'sealed_at', 'scheduled_at', 'published_at', 'retired_at' ] as $field ) { if ( null !== $times[$field] ) { if ( $times[$field]->compare( $last ) < 0 ) { PromiseShape::invalid(); } $last = $times[$field]; } }
		if ( ( 'draft' === $state && ( 1 !== $data['row_revision'] || null !== $times['sealed_at'] || null !== $times['scheduled_at'] || null !== $times['published_at'] || null !== $times['retired_at'] ) )
			|| ( in_array( $state, [ 'sealed', 'scheduled', 'published' ], true ) && null === $times['sealed_at'] )
			|| ( 'sealed' === $state && ( null !== $times['scheduled_at'] || null !== $times['published_at'] || null !== $times['retired_at'] ) )
			|| ( 'scheduled' === $state && ( null === $times['scheduled_at'] || null !== $times['published_at'] || null !== $times['retired_at'] ) )
			|| ( 'published' === $state && ( null === $times['published_at'] || null !== $times['retired_at'] ) )
			|| ( 'retired' === $state ) !== ( null !== $times['retired_at'] )
			|| ( null === $times['scheduled_at'] ) !== ( null === $data['schedule_expected_object_revision'] )
			|| ( null === $times['scheduled_at'] && null !== $data['schedule_expected_published_version_id'] ) ) { PromiseShape::invalid(); }
		$creation = self::receipt( $row['create_receipt_json'], $row['create_receipt_digest'] ); $source = self::receipt( $row['source_receipt_json'], $row['source_receipt_digest'] );
		$publication = null === $row['publication_receipt_json'] ? null : self::receipt( $row['publication_receipt_json'], $row['publication_receipt_digest'] );
		if ( ( null === $row['publication_receipt_json'] ) !== ( null === $row['publication_receipt_digest'] ) || ( null === $publication ) !== ( null === $times['published_at'] ) ) { PromiseShape::invalid(); }
		foreach ( [ $creation, $source, $publication ] as $receipt ) { if ( null !== $receipt ) { self::assert_receipt( $receipt, $data, $body ); } }
		$created_facts = $creation->private_facts(); $latest_facts = $source->private_facts();
		if ( 'promise.version.create' !== $creation->operation() || ! $creation->accepted_at()->equals( $times['created_at'] ) || $created_facts['author_user_id'] !== $data['author_user_id'] || $created_facts['reason'] !== $row['reason'] || 1 !== $created_facts['version_after_revision'] || $latest_facts['version_after_revision'] !== $data['row_revision'] || $latest_facts['state'] !== $state || ! $source->accepted_at()->equals( $last ) ) { PromiseShape::invalid(); }
		if ( null !== $data['schedule_expected_object_revision'] && ( $data['schedule_expected_object_revision'] > $latest_facts['after_revision'] || ( 'scheduled' === $state && $data['schedule_expected_object_revision'] !== $latest_facts['after_revision'] ) ) ) { PromiseShape::invalid(); }
		if ( null !== $publication && ( ! in_array( $publication->operation(), [ 'promise.version.publish', 'promise.version.activate' ], true ) || 'primary' !== $publication->private_facts()['role'] || ! $publication->accepted_at()->equals( $times['published_at'] ) || $publication->private_facts()['version_after_revision'] > $data['row_revision'] ) ) { PromiseShape::invalid(); }
		if ( 'draft' === $state && ! hash_equals( $creation->digest(), $source->digest() ) ) { PromiseShape::invalid(); }
		$binding?->assert_row( $data ); return new self( $data, $body, $creation, $publication, $source );
	}
	private static function receipt( mixed $json, mixed $digest ): PromiseSourceReceipt {
		$json = PromiseStorageCodec::bytes( $json, PromiseSourceReceipt::MAX_BYTES ); $receipt = PromiseSourceReceipt::from_json( $json );
		if ( $json !== $receipt->to_private_json() || ! hash_equals( $receipt->digest(), PromiseShape::digest( $digest ) ) ) { PromiseShape::invalid(); } return $receipt;
	}
	private static function assert_receipt( PromiseSourceReceipt $receipt, array $row, ServicePromisePolicy|BusinessCalendarVersion $body ): void {
		$facts = $receipt->private_facts();
		if ( 'version' !== $receipt->kind() || $facts['site_id'] !== $row['site_id'] || $facts['site_key'] !== $row['site_key'] || $facts['object_id'] !== $row['object_id'] || $facts['version_uuid'] !== $row['version_uuid'] || $facts['domain_version'] !== $row['domain_version'] || $facts['content_digest'] !== $row['body_digest'] || $facts['logical_digest'] !== PromiseStoredObject::identity_digest( $row['site_id'], $row['site_key'], $row['kind'], $row['logical_id'] ) || $facts['declared_from'] !== $row['declared_from'] || $facts['declared_until'] !== $row['declared_until'] ) { PromiseShape::invalid(); }
		$references = $body instanceof ServicePromisePolicy ? $body->calendars() : []; $declared = [];
		foreach ( $references as $reference ) { $declared[':' . $reference->calendar_id()] = $reference->to_private_json(); }
		$observed = [];
		foreach ( $facts['calendar_publications'] as $publication ) { $reference = PromiseCalendarReference::from_array( $publication['reference'] ); $key = ':' . $reference->calendar_id(); if ( ! isset( $declared[$key] ) || $reference->to_private_json() !== $declared[$key] ) { PromiseShape::invalid(); } $observed[$key] = true; }
		$requires_all = 'primary' === $facts['role'] && in_array( $receipt->operation(), [ 'promise.version.seal', 'promise.version.schedule', 'promise.version.publish', 'promise.version.activate' ], true );
		$requires_all = $requires_all || ( null !== $row['sealed_at'] && ( 'promise.version.retire' === $receipt->operation() || 'superseded' === $facts['role'] ) );
		if ( $requires_all && count( $observed ) !== count( $declared ) ) { PromiseShape::invalid(); }
	}
	public function assert_parent( PromiseStoredObject $parent ): void {
		$head = $parent->row();
		foreach ( [ 'site_id', 'site_key', 'kind', 'logical_id' ] as $field ) { if ( $this->data[$field] !== $head[$field] ) { PromiseShape::invalid(); } }
		if ( $parent->id() !== $this->data['object_id'] || $this->data['domain_version'] > $head['last_sequence'] ) { PromiseShape::invalid(); }
		if ( PromiseStorageCodec::time( $head['created_at'] )->compare( $this->created_at() ) > 0 ) { PromiseShape::invalid(); }
		foreach ( [ $this->creation, $this->publication, $this->source ] as $receipt ) { if ( null !== $receipt && ( $receipt->private_facts()['after_revision'] > $parent->revision() || $receipt->accepted_at()->compare( PromiseStorageCodec::time( $head['updated_at'] ) ) > 0 ) ) { PromiseShape::invalid(); } }
	}
	public function row(): array { return $this->data; }
	public function id(): int { return $this->data['id']; }
	public function revision(): int { return $this->data['row_revision']; }
	public function state(): string { return $this->data['state']; }
	public function body(): ServicePromisePolicy|BusinessCalendarVersion { return $this->version_body; }
	public function reference(): PromisePolicyReference|PromiseCalendarReference { return $this->version_body->reference(); }
	public function create_receipt(): PromiseSourceReceipt { return $this->creation; }
	public function source_receipt(): PromiseSourceReceipt { return $this->source; }
	public function publication_receipt(): ?PromiseSourceReceipt { return $this->publication; }
	public function created_at(): RuleTime { return PromiseStorageCodec::time( $this->data['created_at'] ); }
	public function published_at(): ?RuleTime { return PromiseStorageCodec::nullable_time( $this->data['published_at'] ); }
	public function history_available(): bool { return null !== $this->publication && in_array( $this->state(), [ 'published', 'retired' ], true ); }
	public function eligible_at( RuleTime $at ): bool {
		$published = $this->published_at(); $from = PromiseStorageCodec::time( $this->data['declared_from'] ); $until = PromiseStorageCodec::nullable_time( $this->data['declared_until'] );
		return 'published' === $this->state() && null !== $published && $at->compare( $from ) >= 0 && $at->compare( $published ) >= 0 && ( null === $until || $at->compare( $until ) < 0 );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized promise version projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'An explicit authorized promise version projection is required.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promise version facts require strict row hydration.' ); }
}
