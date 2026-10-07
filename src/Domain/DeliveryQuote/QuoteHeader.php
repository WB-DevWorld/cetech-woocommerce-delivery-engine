<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryQuote;

/** Minimal original immutable command references; no private body or self-hash. */
final readonly class QuoteHeader implements \JsonSerializable {
	public const FORMAT = 1;
	public const TTL_SECONDS = 300;
	public const MAX_BYTES = 4096;
	public const SUPPORTED_PROFILE = 'fixture_v1';
	public const SUPPORTED_PROFILE_VERSION = 1;
	private function __construct( private string $json, private QuoteId $quote_id, private QuoteOwner $quote_owner, private QuoteTime $created, private QuoteTime $expires ) {}
	public static function issue( QuoteId $id, QuoteOwner $owner, QuoteContext $context, QuoteTerms $terms, QuoteTime $created, array $namespace_hashes, string $profile = 'fixture_v1', int $profile_version = 1, ?QuoteReference $reference = null ): self {
		$reference ??= QuoteReference::generate( $id ); if ( ! $reference->id()->equals( $id ) ) { QuoteShape::invalid(); }
		$context_facts = $context->private_facts(); $body = self::checked_body( $owner, $context, $terms );
		return self::from_array( [ 'format_version' => self::FORMAT, 'quote_id' => $id->value(), 'owner' => $owner->facts(), 'profile' => $profile, 'profile_version' => $profile_version, 'purpose' => $context_facts['kind'], 'material_digest' => $context->digest(), 'body_digest' => hash( 'sha256', 'cetech-quote-body-v1:' . $body ), 'namespace_hashes' => $namespace_hashes, 'acceptance_handle_hash' => self::handle_digest( $reference ), 'created_at' => $created->sql(), 'expires_at' => $created->plus_seconds( self::TTL_SECONDS )->sql(), 'revision' => 1 ] );
	}
	public static function from_array( array $data ): self {
		$data = QuoteJson::detach( $data, self::MAX_BYTES ); QuoteShape::fields( $data, [ 'format_version', 'quote_id', 'owner', 'profile', 'profile_version', 'purpose', 'material_digest', 'body_digest', 'namespace_hashes', 'acceptance_handle_hash', 'created_at', 'expires_at', 'revision' ] );
		if ( self::FORMAT !== $data['format_version'] || 1 !== $data['revision'] || ! is_string( $data['quote_id'] ) || ! is_string( $data['created_at'] ) || ! is_string( $data['expires_at'] ) ) { QuoteShape::invalid(); }
		$id = QuoteId::from_string( $data['quote_id'] ); $owner = QuoteOwner::from_array( QuoteShape::object( $data['owner'] ) ); QuoteShape::machine( $data['profile'] ); QuoteShape::integer( $data['profile_version'], 1, 1000000 ); QuoteShape::choice( $data['purpose'], [ 'checkout', 'estimate' ] );
		if ( self::SUPPORTED_PROFILE !== $data['profile'] || self::SUPPORTED_PROFILE_VERSION !== $data['profile_version'] ) { QuoteShape::invalid(); }
		foreach ( [ 'material_digest', 'body_digest', 'acceptance_handle_hash' ] as $field ) { QuoteShape::digest( $data[$field] ); }
		$namespaces = QuoteShape::object( $data['namespace_hashes'] ); QuoteShape::fields( $namespaces, [ 'issue', 'accept', 'invalidate' ] ); foreach ( $namespaces as $value ) { QuoteShape::digest( $value ); } if ( 3 !== count( array_unique( $namespaces ) ) ) { QuoteShape::invalid(); }
		$created = QuoteTime::parse( $data['created_at'] ); $expires = QuoteTime::parse( $data['expires_at'] ); if ( ! $created->plus_seconds( self::TTL_SECONDS )->equals( $expires ) ) { QuoteShape::invalid(); }
		return new self( QuoteJson::encode( $data, self::MAX_BYTES ), $id, $owner, $created, $expires );
	}
	public static function from_json( string $json ): self { return self::from_array( QuoteJson::decode( $json, self::MAX_BYTES ) ); }
	public function private_facts(): array { return QuoteJson::decode( $this->json, self::MAX_BYTES ); }
	public function to_private_json(): string { return $this->json; }
	public function id(): QuoteId { return $this->quote_id; }
	public function owner(): QuoteOwner { return $this->quote_owner; }
	public function created_at(): QuoteTime { return $this->created; }
	public function expires_at(): QuoteTime { return $this->expires; }
	public function material_digest(): string { return $this->private_facts()['material_digest']; }
	public function body_digest(): string { return $this->private_facts()['body_digest']; }
	public function profile(): string { return $this->private_facts()['profile']; }
	public function profile_version(): int { return $this->private_facts()['profile_version']; }
	public function purpose(): string { return $this->private_facts()['purpose']; }
	public function revision(): int { return 1; }
	public function namespace_hashes(): array { return $this->private_facts()['namespace_hashes']; }
	public function valid_at( QuoteTime $at ): bool { return $at->compare( $this->created ) >= 0 && $at->compare( $this->expires ) < 0; }
	public function matches_reference( QuoteReference $reference ): bool { return $reference->id()->equals( $this->quote_id ) && hash_equals( $this->private_facts()['acceptance_handle_hash'], self::handle_digest( $reference ) ); }
	/** Hydration must prove semantic links as well as recomputable checksums. */
	public function assert_body( QuoteContext $context, QuoteTerms $terms ): void {
		$body = self::checked_body( $this->quote_owner, $context, $terms );
		if ( ! hash_equals( $this->material_digest(), $context->digest() ) || ! hash_equals( $this->body_digest(), hash( 'sha256', 'cetech-quote-body-v1:' . $body ) ) || $this->purpose() !== $context->private_facts()['kind'] ) { QuoteShape::invalid(); }
	}
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized delivery quote header projection is required.' ); }
	private static function handle_digest( QuoteReference $reference ): string { return hash( 'sha256', 'cetech-quote-accept-handle-v1:' . $reference->handle() ); }
	private static function checked_body( QuoteOwner $owner, QuoteContext $context, QuoteTerms $terms ): string {
		$context_facts = $context->private_facts(); $terms_facts = $terms->private_facts(); $groups = [];
		foreach ( $context_facts['groups'] as $group ) { $groups[$group['component_key']] = $group; }
		if ( count( $terms_facts['groups'] ) !== count( $groups ) || $owner->key_epoch() !== $context_facts['destination']['key_epoch'] ) { QuoteShape::invalid(); }
		foreach ( $terms_facts['groups'] as $term ) {
			$key = $term['component_key']; if ( ! isset( $groups[$key] ) || $term['policy_digest'] !== $groups[$key]['policy_digest'] ) { QuoteShape::invalid(); }
			foreach ( [ 'list', 'final', 'tax', 'total' ] as $field ) { if ( $term[$field]['currency'] !== $context_facts['currency']['charged'] ) { QuoteShape::invalid(); } }
			if ( 'known' === $term['cost']['state'] && $term['cost']['amount']['currency'] !== $context_facts['currency']['charged'] ) { QuoteShape::invalid(); }
			if ( 'recorded' === $term['native_tax_receipt']['state'] && $term['native_tax_receipt']['context_digest'] !== $context_facts['tax']['context_digest'] ) { QuoteShape::invalid(); }
			if ( 'recorded' === $term['native_money_receipt']['state'] && $term['native_money_receipt']['evidence_digest'] !== $context_facts['tax']['native_money_digest'] ) { QuoteShape::invalid(); }
		}
		return QuoteJson::encode( [ 'context' => $context_facts, 'terms' => $terms_facts ] );
	}
}
