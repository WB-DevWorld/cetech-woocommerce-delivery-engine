<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\Contracts\{CanonicalIntent, OperationIdentity};
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetAllocationSet;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteContext, QuoteHeader, QuoteOwner, QuoteReference, QuoteShape, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationCommand;

/** Private immutable linkage intent; original holds remain unchanged and no payment authority is granted. */
final readonly class FixedPromotionQuoteSealCommand implements OperationCommand, \JsonSerializable {

	public const MAX_RECOVERY_BYTES = 262144;
	public const FUNDING_POLICY = 'retain_original_hold_v1';

	private function __construct( public OperationIdentity $identity, private CanonicalIntent $original_intent, private FixedPromotionQuoteVerifyCommand $original_verification, private QuoteBinding $verified, private QuoteBinding $proposed, private int $opened_control_revision ) {}

	public static function create( FixedPromotionQuoteVerifyCommand $verification, QuoteBinding $verified, int $control_revision ): self {
		$verification = $verification->as_recovery(); $quote = $verification->binding_command()->quote_record(); $header = $verification->header(); $owner = $verification->owner();
		QuoteShape::integer( $control_revision ); $row = $verified->row(); $candidate = $verification->binding()->row();
		if ( 3 !== $header->format_version() || FixedPromotionQuoteProvider::CODE !== $header->profile() || 1 !== $header->profile_version() || 'checkout' !== $header->purpose()
			|| 'prepared' !== $verified->state() || 2 !== $verified->revision() || QuoteBinding::from_row( $row, $quote )->row() !== $row ) { QuoteShape::invalid(); }
		foreach ( array_diff( QuoteBinding::FIELDS, [ 'verified_at' ] ) as $field ) { if ( $row[$field] !== $candidate[$field] ) { QuoteShape::invalid(); } }
		$at = QuoteTime::parse( $row['verified_at'] );
		if ( null !== $row['sealed_at'] || $at->compare( QuoteTime::parse( $row['created_at'] ) ) < 0 || ! $header->valid_at( $at ) || $at->compare( $verification->hold_until() ) >= 0 ) { QuoteShape::invalid(); }
		$proposed = QuoteBinding::from_row( array_replace( $row, [ 'state' => 'sealed', 'revision' => 3, 'sealed_at' => $row['verified_at'] ] ), $quote );
		$identity = new OperationIdentity( $owner->site_id(), 'delivery_quote_customer', $owner->digest(), 'delivery_quote.seal', 2, 'placement:' . $row['placement_uuid'], $header->namespace_hashes()['issue'] );
		if ( ! hash_equals( $row['seal_namespace_hash'], $identity->namespace_digest() ) ) { QuoteShape::invalid(); }
		$intent = CanonicalIntent::from_command( $identity,
			[ 'owner_digest' => $owner->digest(), 'quote_id' => $header->id()->value() ],
			[ 'header_revision' => $header->revision(), 'body_digest' => $header->body_digest(), 'expires_at' => $header->expires_at()->sql() ],
			[ 'action' => 'seal', 'original_verification_intent' => $verification->intent()->fingerprint(), 'allocation_digest' => $verification->allocation_digest(), 'hold_until' => $verification->hold_until()->sql(), 'verified_binding' => $row, 'control_revision' => $control_revision, 'funding_policy' => self::FUNDING_POLICY ]
		);
		$command = new self( $identity, $intent, $verification, $verified, $proposed, $control_revision ); $command->to_recovery_array(); return $command;
	}

	public function as_recovery(): self { return $this; }
	public function to_recovery_array(): array {
		$data = [ 'format_version' => 1, 'verification' => $this->original_verification->to_recovery_array(), 'verified_binding' => $this->verified->row(), 'control_revision' => $this->opened_control_revision ];
		self::bounded( $data ); return $data;
	}
	public static function from_recovery_array( array $data ): self {
		self::bounded( $data ); QuoteShape::fields( $data, [ 'format_version', 'verification', 'verified_binding', 'control_revision' ] );
		if ( 1 !== $data['format_version'] || ! is_array( $data['verification'] ) || ! is_array( $data['verified_binding'] ) ) { QuoteShape::invalid(); }
		$verification = FixedPromotionQuoteVerifyCommand::from_recovery_array( $data['verification'] );
		$verified = QuoteBinding::from_row( $data['verified_binding'], $verification->binding_command()->quote_record() );
		$command = self::create( $verification, $verified, QuoteShape::integer( $data['control_revision'] ) );
		if ( $command->verified_binding()->row() !== $data['verified_binding'] || $command->verification()->to_recovery_array() !== $data['verification'] ) { QuoteShape::invalid(); }
		return $command;
	}

	private static function bounded( array $data ): void {
		$nodes = 0; $bytes = 0; self::scalar_graph( $data, 0, $nodes, $bytes );
		try { if ( strlen( json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 32 ) ) > self::MAX_RECOVERY_BYTES ) { QuoteShape::invalid(); } } catch ( \Throwable ) { QuoteShape::invalid(); }
	}
	private static function scalar_graph( mixed $value, int $depth, int &$nodes, int &$bytes ): void {
		if ( $depth > 28 || ++$nodes > 10000 ) { QuoteShape::invalid(); }
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) { $bytes += strlen( (string) $key ); if ( $bytes > self::MAX_RECOVERY_BYTES ) { QuoteShape::invalid(); } self::scalar_graph( $item, $depth + 1, $nodes, $bytes ); }
			return;
		}
		if ( is_string( $value ) ) { $bytes += strlen( $value ); if ( $bytes > self::MAX_RECOVERY_BYTES ) { QuoteShape::invalid(); } return; }
		if ( null !== $value && ! is_int( $value ) && ! is_bool( $value ) ) { QuoteShape::invalid(); }
	}
	public function intent(): CanonicalIntent { return $this->original_intent; }
	public function verification(): FixedPromotionQuoteVerifyCommand { return $this->original_verification; }
	public function verified_binding(): QuoteBinding { return $this->verified; }
	public function binding(): QuoteBinding { return $this->proposed; }
	public function control_revision(): int { return $this->opened_control_revision; }
	public function snapshot_digest(): string { return $this->original_verification->snapshot_digest(); }
	public function context_digest(): string { return $this->original_verification->context_digest(); }
	public function owner(): QuoteOwner { return $this->original_verification->owner(); }
	public function header(): QuoteHeader { return $this->original_verification->header(); }
	public function context(): QuoteContext { return $this->original_verification->context(); }
	public function terms(): QuoteTerms { return $this->original_verification->terms(); }
	public function reference(): QuoteReference { return $this->original_verification->reference(); }
	public function allocation_set(): FixedPromotionBudgetAllocationSet { return $this->original_verification->allocation_set(); }
	public function account_slots(): array { return $this->original_verification->account_slots(); }
	public function hold_until(): QuoteTime { return $this->original_verification->hold_until(); }
	public function allocation_digest(): string { return $this->original_verification->allocation_digest(); }

	public function jsonSerialize(): never { throw new \LogicException( 'Promotion seal commands require explicit private recovery access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion seal commands cannot be persisted implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion seal commands require strict recovery hydration.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_quote_seal_command' => 'redacted' ]; }
}
