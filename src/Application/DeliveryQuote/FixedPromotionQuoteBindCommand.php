<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\Contracts\{CanonicalIntent, OperationIdentity};
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetAllocationSet;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteContext, QuoteHeader, QuoteId, QuoteJson, QuoteOwner, QuoteReference, QuoteShape, QuoteStoredRow, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationCommand;

/** Exact private candidate binding; captured facts require physical receipt/current proof before use. */
final readonly class FixedPromotionQuoteBindCommand implements OperationCommand, \JsonSerializable {

	public const MAX_RECOVERY_BYTES = 262144;

	private function __construct( public OperationIdentity $identity, private CanonicalIntent $original_intent, private FixedPromotionQuoteAcceptCommand $original_acceptance, private QuoteStoredRow $accepted_quote, private QuoteBinding $candidate_binding, private string $native_order_digest ) {}

	public static function create( FixedPromotionQuoteAcceptCommand $acceptance, QuoteStoredRow $accepted, int $order_id, QuoteId $placement, array $mapping, string $order_evidence_digest ): self {
		$acceptance = $acceptance->as_recovery(); $header = $acceptance->header(); $owner = $acceptance->owner(); $at = $accepted->accepted_at();
		QuoteShape::integer( $order_id ); QuoteShape::digest( $order_evidence_digest );
		if ( 3 !== $header->format_version() || FixedPromotionQuoteProvider::CODE !== $header->profile() || 1 !== $header->profile_version() || 'checkout' !== $header->purpose()
			|| 'accepted' !== $accepted->state() || 2 !== $accepted->revision() || null === $at || $at->compare( $acceptance->hold_until() ) >= 0
			|| ! $accepted->header()->owner()->equals( $owner ) || ! $header->matches_reference( $acceptance->reference() )
			|| $accepted->header()->to_private_json() !== $header->to_private_json() || $accepted->context()?->to_private_json() !== $acceptance->context()->to_private_json() || $accepted->terms()?->to_private_json() !== $acceptance->terms()->to_private_json() ) { QuoteShape::invalid(); }
		$mapping = QuoteBinding::canonical_mapping( $mapping );
		$names = QuoteDurableCommand::binding_namespaces( $owner, $header, $placement->value(), true );
		$binding = QuoteBinding::from_row( [
			'id' => 1, 'site_id' => $owner->site_id(), 'format_version' => 1, 'quote_uuid' => $header->id()->value(), 'order_id' => $order_id, 'placement_uuid' => $placement->value(),
			'managed_group_manifest_digest' => QuoteBinding::manifest_digest( $mapping ), 'mapping_json' => QuoteJson::encode( $mapping ), 'native_money_digest' => $acceptance->context()->private_facts()['tax']['native_money_digest'], 'accepted_body_digest' => $header->body_digest(),
			'snapshot_digest' => null, 'context_digest' => null, 'bind_namespace_hash' => $names['bind'], 'seal_namespace_hash' => $names['seal'], 'state' => 'prepared', 'revision' => 1, 'created_at' => $at->sql(), 'verified_at' => null, 'sealed_at' => null,
		], $accepted );
		$identity = new OperationIdentity( $owner->site_id(), 'delivery_quote_customer', $owner->digest(), 'delivery_quote.bind', 1, 'placement:' . $placement->value(), $header->namespace_hashes()['issue'] );
		if ( ! hash_equals( $names['bind'], $identity->namespace_digest() ) ) { QuoteShape::invalid(); }
		$logical = $binding->row(); unset( $logical['id'], $logical['created_at'] );
		$intent = CanonicalIntent::from_command( $identity,
			[ 'owner_digest' => $owner->digest(), 'quote_id' => $header->id()->value() ],
			[ 'header_revision' => $header->revision(), 'body_digest' => $header->body_digest(), 'expires_at' => $header->expires_at()->sql() ],
			[ 'action' => 'bind', 'original_acceptance_intent' => $acceptance->intent()->fingerprint(), 'allocation_digest' => $acceptance->allocation_digest(), 'hold_until' => $acceptance->hold_until()->sql(), 'accepted_at' => $at->sql(), 'binding' => $logical, 'order_evidence_digest' => $order_evidence_digest ]
		);
		$command = new self( $identity, $intent, $acceptance, $accepted, $binding, $order_evidence_digest );
		// Even directly created commands must fit the same explicit recovery transport.
		$command->to_recovery_array();
		return $command;
	}

	public function as_recovery(): self { return $this; }
	public function to_recovery_array(): array {
		$data = [ 'format_version' => 1, 'acceptance' => $this->original_acceptance->to_recovery_array(), 'accepted_quote' => $this->accepted_quote->row(), 'binding' => $this->candidate_binding->row(), 'order_evidence_digest' => $this->native_order_digest ];
		self::bounded( $data ); return $data;
	}
	public static function from_recovery_array( array $data ): self {
		self::bounded( $data ); QuoteShape::fields( $data, [ 'format_version', 'acceptance', 'accepted_quote', 'binding', 'order_evidence_digest' ] );
		if ( 1 !== $data['format_version'] || ! is_array( $data['acceptance'] ) || ! is_array( $data['accepted_quote'] ) || ! is_array( $data['binding'] ) ) { QuoteShape::invalid(); }
		$acceptance = FixedPromotionQuoteAcceptCommand::from_recovery_array( $data['acceptance'] ); $accepted = QuoteStoredRow::from_row( $data['accepted_quote'] );
		$candidate = QuoteBinding::from_row( $data['binding'], $accepted ); $row = $candidate->row();
		$command = self::create( $acceptance, $accepted, $row['order_id'], QuoteId::from_string( $row['placement_uuid'] ), $candidate->mapping(), QuoteShape::digest( $data['order_evidence_digest'] ) );
		if ( $command->binding()->row() !== $data['binding'] || $command->quote_record()->row() !== $data['accepted_quote'] ) { QuoteShape::invalid(); }
		return $command;
	}
	private static function bounded( array $data ): void {
		$nodes = 0; $bytes = 0; self::scalar_graph( $data, 0, $nodes, $bytes );
		try { if ( strlen( json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 28 ) ) > self::MAX_RECOVERY_BYTES ) { QuoteShape::invalid(); } } catch ( \Throwable ) { QuoteShape::invalid(); }
	}
	private static function scalar_graph( mixed $value, int $depth, int &$nodes, int &$bytes ): void {
		if ( $depth > 24 || ++$nodes > 10000 ) { QuoteShape::invalid(); }
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) { $bytes += strlen( (string) $key ); if ( $bytes > self::MAX_RECOVERY_BYTES ) { QuoteShape::invalid(); } self::scalar_graph( $item, $depth + 1, $nodes, $bytes ); }
			return;
		}
		if ( is_string( $value ) ) { $bytes += strlen( $value ); if ( $bytes > self::MAX_RECOVERY_BYTES ) { QuoteShape::invalid(); } return; }
		if ( null !== $value && ! is_int( $value ) && ! is_bool( $value ) ) { QuoteShape::invalid(); }
	}
	public function intent(): CanonicalIntent { return $this->original_intent; }
	public function acceptance(): FixedPromotionQuoteAcceptCommand { return $this->original_acceptance; }
	public function quote_record(): QuoteStoredRow { return $this->accepted_quote; }
	public function binding(): QuoteBinding { return $this->candidate_binding; }
	public function order_evidence_digest(): string { return $this->native_order_digest; }
	public function owner(): QuoteOwner { return $this->original_acceptance->owner(); }
	public function header(): QuoteHeader { return $this->original_acceptance->header(); }
	public function context(): QuoteContext { return $this->original_acceptance->context(); }
	public function terms(): QuoteTerms { return $this->original_acceptance->terms(); }
	public function reference(): QuoteReference { return $this->original_acceptance->reference(); }
	public function allocation_set(): FixedPromotionBudgetAllocationSet { return $this->original_acceptance->allocation_set(); }
	public function account_slots(): array { return $this->original_acceptance->account_slots(); }
	public function hold_until(): QuoteTime { return $this->original_acceptance->hold_until(); }
	public function allocation_digest(): string { return $this->original_acceptance->allocation_digest(); }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion binding commands require explicit private recovery access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion binding commands cannot be persisted implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion binding commands require strict recovery hydration.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_quote_bind_command' => 'redacted' ]; }
}
