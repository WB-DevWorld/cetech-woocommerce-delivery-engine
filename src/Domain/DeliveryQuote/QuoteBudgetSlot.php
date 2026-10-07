<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryQuote;

/** Counter versus single-use admission storage shapes; no admission algorithm. */
final readonly class QuoteBudgetSlot implements \JsonSerializable {
	public const FIELDS = [ 'id', 'site_id', 'format_version', 'purpose', 'slot_kind', 'slot_key', 'window_start', 'principal_hash', 'attempt_count', 'revision', 'created_at', 'last_seen_at', 'admission_namespace_hash', 'admission_intent_digest', 'server_attempt_digest', 'lease_expires_at', 'lease_state', 'consumed_quote_uuid', 'consumed_at' ];
	private function __construct( private array $data ) {}
	public static function from_row( array $row, ?QuoteStoredRow $consumed_quote = null ): self {
		QuoteStorageCodec::exact( $row, self::FIELDS ); $data = []; foreach ( self::FIELDS as $field ) { $data[$field] = $row[$field]; }
		foreach ( [ 'id', 'site_id', 'format_version', 'revision' ] as $field ) { $data[$field] = QuoteStorageCodec::integer( $row[$field] ); }
		$data['attempt_count'] = QuoteStorageCodec::integer( $row['attempt_count'], 0, 200 );
		if ( 1 !== $data['format_version'] || 'delivery_quote.issue' !== $row['purpose'] ) { QuoteShape::invalid(); }
		$kind = QuoteShape::choice( $row['slot_kind'], [ 'site_minute', 'session_minute', 'admission' ] ); QuoteShape::digest( $row['slot_key'] );
		$window = QuoteStorageCodec::time( $row['window_start'] ); $created = QuoteStorageCodec::time( $row['created_at'] ); $seen = QuoteStorageCodec::time( $row['last_seen_at'] );
		if ( ! str_ends_with( $window->sql(), ':00.000000' ) || $created->compare( $window ) < 0 || $created->compare( $window->plus_seconds( 60 ) ) >= 0 || $seen->compare( $created ) < 0 ) { QuoteShape::invalid(); }
		$principal = QuoteStorageCodec::nullable_digest( $row['principal_hash'] );
		if ( 'admission' !== $kind ) {
			if ( ( 'site_minute' === $kind && null !== $principal ) || ( 'session_minute' === $kind && ( null === $principal || $data['attempt_count'] > 20 ) ) ) { QuoteShape::invalid(); }
			if ( ( 'site_minute' === $kind && ! hash_equals( self::site_slot_key( $data['site_id'] ), $row['slot_key'] ) ) || null !== $consumed_quote ) { QuoteShape::invalid(); }
			foreach ( [ 'admission_namespace_hash', 'admission_intent_digest', 'server_attempt_digest', 'lease_expires_at', 'lease_state', 'consumed_quote_uuid', 'consumed_at' ] as $field ) { if ( null !== $row[$field] ) { QuoteShape::invalid(); } }
		} else {
			foreach ( [ 'admission_namespace_hash', 'admission_intent_digest', 'server_attempt_digest' ] as $field ) { QuoteShape::digest( $row[$field] ); }
			$expiry = QuoteStorageCodec::time( $row['lease_expires_at'] ); $state = QuoteShape::choice( $row['lease_state'], [ 'granted', 'consumed', 'terminated' ] );
			if ( null === $principal || 1 !== $data['attempt_count'] || ! $expiry->equals( $created->plus_seconds( 60 ) ) || ! hash_equals( self::admission_slot_key( $row['admission_namespace_hash'] ), $row['slot_key'] ) ) { QuoteShape::invalid(); }
			if ( 'consumed' === $state ) {
				$uuid = QuoteStorageCodec::uuid( $row['consumed_quote_uuid'] ); $consumed = QuoteStorageCodec::time( $row['consumed_at'] );
				if ( 2 !== $data['revision'] || $consumed->compare( $created ) < 0 || $consumed->compare( $expiry ) >= 0 || $seen->compare( $consumed ) < 0 ) { QuoteShape::invalid(); }
				if ( null === $consumed_quote || $consumed_quote->site_id() !== $data['site_id'] || ! $uuid->equals( $consumed_quote->header()->id() ) || ! hash_equals( $row['admission_namespace_hash'], $consumed_quote->row()['issue_namespace_hash'] )
					|| ! hash_equals( $principal, $consumed_quote->header()->owner()->facts()['principal_hash'] ) || $consumed_quote->header()->created_at()->compare( $consumed ) > 0
					|| $consumed_quote->header()->created_at()->compare( $created ) < 0 || $consumed_quote->header()->created_at()->compare( $expiry ) >= 0 ) { QuoteShape::invalid(); }
			} elseif ( null !== $consumed_quote || null !== $row['consumed_quote_uuid'] || null !== $row['consumed_at'] || ( 'granted' === $state && 1 !== $data['revision'] ) || ( 'terminated' === $state && 2 !== $data['revision'] ) ) { QuoteShape::invalid(); }
		}
		return new self( $data );
	}
	public static function site_slot_key( int $site_id ): string { QuoteShape::integer( $site_id ); return hash( 'sha256', 'cetech-quote-budget-site-v1:' . $site_id ); }
	public static function session_slot_key( QuoteOwner $owner ): string { return hash( 'sha256', 'cetech-quote-budget-session-v1:' . $owner->digest() ); }
	public static function admission_slot_key( string $namespace_hash ): string { return hash( 'sha256', 'cetech-quote-budget-admission-v1:' . QuoteShape::digest( $namespace_hash ) ); }
	public function row(): array { return $this->data; }
	public function id(): int { return $this->data['id']; }
	public function site_id(): int { return $this->data['site_id']; }
	public function revision(): int { return $this->data['revision']; }
	public function kind(): string { return $this->data['slot_kind']; }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized quote budget projection is required.' ); }
	public function __serialize(): array { throw new \LogicException( 'An explicit authorized quote budget projection is required.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Quote budget facts require strict row hydration.' ); }
}
