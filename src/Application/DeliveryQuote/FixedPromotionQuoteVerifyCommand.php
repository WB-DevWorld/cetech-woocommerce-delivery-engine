<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\Contracts\{CanonicalIntent, OperationIdentity};
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetAllocationSet;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteContext, QuoteHeader, QuoteOwner, QuoteReference, QuoteShape, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationCommand;

/** Private saved-proof intent. Its proposed timestamp is never the mutation clock. */
final readonly class FixedPromotionQuoteVerifyCommand implements OperationCommand, \JsonSerializable {

	public const MAX_RECOVERY_BYTES = 262144;

	private function __construct( public OperationIdentity $identity, private CanonicalIntent $original_intent, private FixedPromotionQuoteBindCommand $original_binding, private QuoteBinding $prepared, private QuoteBinding $proposed ) {}

	public static function create( FixedPromotionQuoteBindCommand $binding_command, QuoteBinding $prepared, string $snapshot_digest, string $context_digest ): self {
		$binding_command = $binding_command->as_recovery(); $quote = $binding_command->quote_record(); $header = $binding_command->header(); $owner = $binding_command->owner();
		QuoteShape::digest( $snapshot_digest ); QuoteShape::digest( $context_digest );
		$row = $prepared->row(); $candidate = $binding_command->binding()->row();
		if ( 3 !== $header->format_version() || FixedPromotionQuoteProvider::CODE !== $header->profile() || 1 !== $header->profile_version() || 'checkout' !== $header->purpose()
			|| 'prepared' !== $prepared->state() || 1 !== $prepared->revision() || QuoteBinding::from_row( $row, $quote )->row() !== $row ) { QuoteShape::invalid(); }
		foreach ( array_diff( QuoteBinding::FIELDS, [ 'id', 'created_at' ] ) as $field ) { if ( $row[$field] !== $candidate[$field] ) { QuoteShape::invalid(); } }
		foreach ( [ 'snapshot_digest', 'context_digest', 'verified_at', 'sealed_at' ] as $field ) { if ( null !== $row[$field] ) { QuoteShape::invalid(); } }
		$created = QuoteTime::parse( $row['created_at'] );
		if ( $created->compare( $quote->accepted_at() ) < 0 || ! $header->valid_at( $created ) || $created->compare( $binding_command->hold_until() ) >= 0 ) { QuoteShape::invalid(); }
		$proposed = QuoteBinding::from_row( array_replace( $row, [ 'revision' => 2, 'snapshot_digest' => $snapshot_digest, 'context_digest' => $context_digest, 'verified_at' => $row['created_at'] ] ), $quote );
		$identity = new OperationIdentity( $owner->site_id(), 'delivery_quote_customer', $owner->digest(), 'delivery_quote.verify_binding', 1, 'placement:' . $row['placement_uuid'], $header->namespace_hashes()['issue'] );
		if ( ! hash_equals( QuoteDurableCommand::verification_namespace( $owner, $header, $row['placement_uuid'] ), $identity->namespace_digest() ) ) { QuoteShape::invalid(); }
		$intent = CanonicalIntent::from_command( $identity,
			[ 'owner_digest' => $owner->digest(), 'quote_id' => $header->id()->value() ],
			[ 'header_revision' => $header->revision(), 'body_digest' => $header->body_digest(), 'expires_at' => $header->expires_at()->sql() ],
			[ 'action' => 'verify_binding', 'original_binding_intent' => $binding_command->intent()->fingerprint(), 'allocation_digest' => $binding_command->allocation_digest(), 'hold_until' => $binding_command->hold_until()->sql(), 'prepared_binding' => $row, 'snapshot_digest' => $snapshot_digest, 'context_digest' => $context_digest ]
		);
		$command = new self( $identity, $intent, $binding_command, $prepared, $proposed ); $command->to_recovery_array(); return $command;
	}

	public function as_recovery(): self { return $this; }
	public function to_recovery_array(): array {
		$data = [ 'format_version' => 1, 'binding_command' => $this->original_binding->to_recovery_array(), 'prepared_binding' => $this->prepared->row(), 'snapshot_digest' => $this->snapshot_digest(), 'context_digest' => $this->context_digest() ];
		self::bounded( $data ); return $data;
	}
	public static function from_recovery_array( array $data ): self {
		self::bounded( $data ); QuoteShape::fields( $data, [ 'format_version', 'binding_command', 'prepared_binding', 'snapshot_digest', 'context_digest' ] );
		if ( 1 !== $data['format_version'] || ! is_array( $data['binding_command'] ) || ! is_array( $data['prepared_binding'] ) ) { QuoteShape::invalid(); }
		$binding = FixedPromotionQuoteBindCommand::from_recovery_array( $data['binding_command'] );
		$prepared = QuoteBinding::from_row( $data['prepared_binding'], $binding->quote_record() );
		$command = self::create( $binding, $prepared, QuoteShape::digest( $data['snapshot_digest'] ), QuoteShape::digest( $data['context_digest'] ) );
		if ( $command->prepared_binding()->row() !== $data['prepared_binding'] || $command->binding_command()->to_recovery_array() !== $data['binding_command'] ) { QuoteShape::invalid(); }
		return $command;
	}
	private static function bounded( array $data ): void {
		$nodes = 0; $bytes = 0; self::scalar_graph( $data, 0, $nodes, $bytes );
		try { if ( strlen( json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 30 ) ) > self::MAX_RECOVERY_BYTES ) { QuoteShape::invalid(); } } catch ( \Throwable ) { QuoteShape::invalid(); }
	}
	private static function scalar_graph( mixed $value, int $depth, int &$nodes, int &$bytes ): void {
		if ( $depth > 26 || ++$nodes > 10000 ) { QuoteShape::invalid(); }
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) { $bytes += strlen( (string) $key ); if ( $bytes > self::MAX_RECOVERY_BYTES ) { QuoteShape::invalid(); } self::scalar_graph( $item, $depth + 1, $nodes, $bytes ); }
			return;
		}
		if ( is_string( $value ) ) { $bytes += strlen( $value ); if ( $bytes > self::MAX_RECOVERY_BYTES ) { QuoteShape::invalid(); } return; }
		if ( null !== $value && ! is_int( $value ) && ! is_bool( $value ) ) { QuoteShape::invalid(); }
	}
	public function intent(): CanonicalIntent { return $this->original_intent; }
	public function binding_command(): FixedPromotionQuoteBindCommand { return $this->original_binding; }
	public function prepared_binding(): QuoteBinding { return $this->prepared; }
	public function binding(): QuoteBinding { return $this->proposed; }
	public function snapshot_digest(): string { return $this->proposed->row()['snapshot_digest']; }
	public function context_digest(): string { return $this->proposed->row()['context_digest']; }
	public function owner(): QuoteOwner { return $this->original_binding->owner(); }
	public function header(): QuoteHeader { return $this->original_binding->header(); }
	public function context(): QuoteContext { return $this->original_binding->context(); }
	public function terms(): QuoteTerms { return $this->original_binding->terms(); }
	public function reference(): QuoteReference { return $this->original_binding->reference(); }
	public function allocation_set(): FixedPromotionBudgetAllocationSet { return $this->original_binding->allocation_set(); }
	public function account_slots(): array { return $this->original_binding->account_slots(); }
	public function hold_until(): QuoteTime { return $this->original_binding->hold_until(); }
	public function allocation_digest(): string { return $this->original_binding->allocation_digest(); }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion verification commands require explicit private recovery access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion verification commands cannot be persisted implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion verification commands require strict recovery hydration.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_quote_verify_command' => 'redacted' ]; }
}
