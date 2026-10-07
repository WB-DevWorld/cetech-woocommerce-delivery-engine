<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryQuote;

/** Private persisted facts, not a recovered authorization or commit receipt. */
final readonly class QuoteStoredRow implements \JsonSerializable {
	public const FIELDS = [ 'id', 'site_id', 'quote_uuid', 'format_version', 'profile_code', 'profile_version', 'purpose', 'principal_hash', 'owner_digest', 'material_digest', 'body_digest', 'header_json', 'private_body_json', 'issue_namespace_hash', 'accept_namespace_hash', 'invalidate_namespace_hash', 'state', 'revision', 'retention_revision', 'created_at', 'expires_at', 'accepted_at', 'transition_at' ];
	private function __construct( private array $data, private QuoteHeader $original_header, private ?QuoteContext $original_context, private ?QuoteTerms $original_terms ) {}

	public static function from_row( array $row ): self {
		QuoteStorageCodec::exact( $row, self::FIELDS ); $data = [];
		foreach ( self::FIELDS as $field ) { $data[$field] = $row[$field]; }
		foreach ( [ 'id', 'site_id', 'format_version', 'profile_version', 'revision', 'retention_revision' ] as $field ) { $data[$field] = QuoteStorageCodec::integer( $row[$field] ); }
		if ( 1 !== $data['format_version'] || ! is_string( $row['header_json'] ) ) { QuoteShape::invalid(); }
		$header = QuoteHeader::from_json( $row['header_json'] ); $owner = $header->owner(); $owner_facts = $owner->facts();
		if ( $row['header_json'] !== $header->to_private_json() || ! QuoteStorageCodec::uuid( $row['quote_uuid'] )->equals( $header->id() ) || $data['site_id'] !== $owner->site_id()
			|| $row['profile_code'] !== $header->profile() || $data['profile_version'] !== $header->profile_version() || $row['purpose'] !== $header->purpose() ) { QuoteShape::invalid(); }
		foreach ( [ 'principal_hash' => $owner_facts['principal_hash'], 'owner_digest' => $owner->digest(), 'material_digest' => $header->material_digest(), 'body_digest' => $header->body_digest() ] as $field => $expected ) { if ( ! hash_equals( $expected, QuoteShape::digest( $row[$field] ) ) ) { QuoteShape::invalid(); } }
		foreach ( $header->namespace_hashes() as $purpose => $hash ) { if ( ! hash_equals( $hash, QuoteShape::digest( $row[$purpose . '_namespace_hash'] ) ) ) { QuoteShape::invalid(); } }
		$created = QuoteStorageCodec::time( $row['created_at'] ); $expires = QuoteStorageCodec::time( $row['expires_at'] ); $accepted = QuoteStorageCodec::nullable_time( $row['accepted_at'] ); $transition = QuoteStorageCodec::nullable_time( $row['transition_at'] );
		if ( ! $created->equals( $header->created_at() ) || ! $expires->equals( $header->expires_at() ) || ( null !== $accepted && ! $header->valid_at( $accepted ) )
			|| ( null !== $transition && ( $transition->compare( $created ) < 0 || ( null !== $accepted && $transition->compare( $accepted ) < 0 ) ) ) ) { QuoteShape::invalid(); }
		$state = QuoteShape::choice( $row['state'], [ 'issued', 'accepted', 'invalidated', 'stripped' ] ); $context = null; $terms = null;
		if ( 'stripped' === $state ) {
			if ( null !== $row['private_body_json'] || null !== $accepted || null === $transition || $transition->compare( $expires->plus_seconds( 1800 ) ) < 0 || 2 !== $data['retention_revision'] || ! in_array( $data['revision'], [ 2, 3 ], true ) ) { QuoteShape::invalid(); }
		} else {
			if ( ! is_string( $row['private_body_json'] ) || 1 !== $data['retention_revision'] ) { QuoteShape::invalid(); }
			$body = QuoteStorageCodec::json( $row['private_body_json'] ); QuoteShape::fields( $body, [ 'context', 'terms' ] );
			$context = QuoteContext::from_array( QuoteShape::object( $body['context'] ) ); $terms = QuoteTerms::from_array( QuoteShape::object( $body['terms'] ) ); $header->assert_body( $context, $terms );
			if ( $row['private_body_json'] !== QuoteJson::encode( [ 'context' => $context->private_facts(), 'terms' => $terms->private_facts() ] ) ) { QuoteShape::invalid(); }
			if ( 'issued' === $state && ( 1 !== $data['revision'] || null !== $accepted || null !== $transition ) ) { QuoteShape::invalid(); }
			if ( 'accepted' === $state && ( 2 !== $data['revision'] || null === $accepted || null === $transition || ! $accepted->equals( $transition ) ) ) { QuoteShape::invalid(); }
			if ( 'invalidated' === $state && ( null === $transition || $data['revision'] !== ( null === $accepted ? 2 : 3 ) ) ) { QuoteShape::invalid(); }
			if ( null !== $accepted && ! DeliveryQuote::issue( $header, $context, $terms )->usable_at( $accepted ) ) { QuoteShape::invalid(); }
		}
		return new self( $data, $header, $context, $terms );
	}

	public function row(): array { return $this->data; }
	public function id(): int { return $this->data['id']; }
	public function site_id(): int { return $this->data['site_id']; }
	public function state(): string { return $this->data['state']; }
	public function revision(): int { return $this->data['revision']; }
	public function header(): QuoteHeader { return $this->original_header; }
	public function context(): ?QuoteContext { return $this->original_context; }
	public function terms(): ?QuoteTerms { return $this->original_terms; }
	public function accepted_at(): ?QuoteTime { return QuoteStorageCodec::nullable_time( $this->data['accepted_at'] ); }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized stored quote projection is required.' ); }
	public function __serialize(): array { throw new \LogicException( 'An explicit authorized stored quote projection is required.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Stored quote facts require strict row hydration.' ); }
}
