<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\Contracts\CanonicalIntent;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\DeliveryQuote\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteReference;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
use CetechDeliveryEngine\Domain\Operation\OperationCommand;

/** Private original command. Capture/evidence are not part of replay identity. */
final readonly class QuoteDurableCommand implements OperationCommand, \JsonSerializable {
	private function __construct(
		public OperationIdentity $identity, private CanonicalIntent $original_intent,
		private QuoteOwner $quote_owner, private ?QuoteIssueCommand $original_issue,
		private ?QuoteReference $original_reference, private ?QuoteHeader $opened_header,
		private ?QuoteContext $current_context, private ?DeliveryQuote $captured,
		private ?QuoteAdmissionLease $capture_lease, private ?QuoteBinding $binding_facts
	) {}
	public static function issue_probe( QuoteIssueCommand $original, ?QuoteReference $reference = null, ?QuoteHeader $header = null ): self {
		if ( ( null === $reference ) !== ( null === $header ) || ( null !== $header && ( ! $header->owner()->equals( $original->owner() ) || ! $header->matches_reference( $reference ) || $header->namespace_hashes() != $original->namespace_hashes( $header->id() ) || $header->material_digest() !== $original->context()->digest() ) ) ) { QuoteShape::invalid(); }
		return new self( $original->identity(), $original->intent(), $original->owner(), $original, $reference, $header, $original->context(), null, null, null );
	}
	public static function captured_issue( QuoteIssueCommand $original, QuoteAdmissionLease $lease, DeliveryQuote $captured, QuoteReference $reference ): self {
		$h = $captured->header();
		if ( ! $lease->matches( $original ) || ! $h->owner()->equals( $original->owner() ) || ! $h->matches_reference( $reference ) || $h->namespace_hashes() != $original->namespace_hashes( $h->id() ) || $h->material_digest() !== $original->context()->digest() || $h->profile() !== $original->profile() || $h->profile_version() !== $original->profile_version() ) { QuoteShape::invalid(); }
		return new self( $original->identity(), $original->intent(), $original->owner(), $original, $reference, $h, $original->context(), $captured, $lease, null );
	}
	public static function accept( QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, ?QuoteContext $current ): self { return self::transition( 'accept', $owner, $reference, $opened, $current ); }
	public static function invalidate( QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, ?QuoteContext $current ): self { return self::transition( 'invalidate', $owner, $reference, $opened, $current ); }
	public static function bind( QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, QuoteBinding $binding, ?QuoteContext $current = null ): self { return self::transition( 'bind', $owner, $reference, $opened, $current, $binding ); }
	public static function seal( QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, QuoteBinding $binding, ?QuoteContext $current = null ): self { return self::transition( 'seal', $owner, $reference, $opened, $current, $binding ); }
	private static function transition( string $action, QuoteOwner $owner, QuoteReference $reference, QuoteHeader $opened, ?QuoteContext $current, ?QuoteBinding $binding = null ): self {
		if ( ! $opened->owner()->equals( $owner ) || ! $opened->matches_reference( $reference ) ) { QuoteShape::invalid(); }
		$token = $opened->namespace_hashes()['issue']; $target = 'quote:' . $opened->id()->value();
		$payload = [ 'action' => $action ];
		if ( null !== $binding ) {
			$r = $binding->row();
			if ( $binding->site_id() !== $owner->site_id() || $r['quote_uuid'] !== $opened->id()->value() || $r['accepted_body_digest'] !== $opened->body_digest() || 'prepared' !== $binding->state() || $binding->revision() !== ( 'bind' === $action ? 1 : 2 ) ) { QuoteShape::invalid(); }
			$target = 'placement:' . $r['placement_uuid']; $token = $opened->namespace_hashes()['issue'];
			$payload['binding'] = $r; unset( $payload['binding']['id'], $payload['binding']['created_at'] );
		} elseif ( 'invalidate' === $action ) { $payload['current_material_digest'] = $current?->digest() ?? str_repeat( '0', 64 ); }
		$identity = new OperationIdentity( $owner->site_id(), 'delivery_quote_customer', $owner->digest(), 'delivery_quote.' . $action, 1, $target, $token );
		if ( null === $binding && ! hash_equals( $opened->namespace_hashes()[$action], $identity->namespace_digest() ) ) { QuoteShape::invalid(); }
		if ( null !== $binding ) {
			foreach ( [ 'bind', 'seal' ] as $purpose ) {
				$i = new OperationIdentity( $owner->site_id(), 'delivery_quote_customer', $owner->digest(), 'delivery_quote.' . $purpose, 1, $target, $token );
				if ( ! hash_equals( $binding->row()[$purpose . '_namespace_hash'], $i->namespace_digest() ) ) { QuoteShape::invalid(); }
			}
		}
		$intent = CanonicalIntent::from_command( $identity, [ 'owner_digest' => $owner->digest(), 'quote_id' => $opened->id()->value() ], [ 'header_revision' => $opened->revision(), 'body_digest' => $opened->body_digest(), 'expires_at' => $opened->expires_at()->sql() ], $payload );
		return new self( $identity, $intent, $owner, null, $reference, $opened, $current, null, null, $binding );
	}
	/** Deterministic server-owned placement namespaces; clients never select a key. */
	public static function binding_namespaces( QuoteOwner $owner, QuoteHeader $header, string $placement_uuid ): array {
		\CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId::from_string( $placement_uuid );
		if ( ! $header->owner()->equals( $owner ) ) { QuoteShape::invalid(); }
		$out = []; foreach ( [ 'bind', 'seal' ] as $action ) { $i = new OperationIdentity( $owner->site_id(), 'delivery_quote_customer', $owner->digest(), 'delivery_quote.' . $action, 1, 'placement:' . $placement_uuid, $header->namespace_hashes()['issue'] ); $out[$action] = $i->namespace_digest(); } return $out;
	}
	public function intent(): CanonicalIntent { return $this->original_intent; }
	public function owner(): QuoteOwner { return $this->quote_owner; }
	public function original_issue(): ?QuoteIssueCommand { return $this->original_issue; }
	public function reference(): ?QuoteReference { return $this->original_reference; }
	public function header(): ?QuoteHeader { return $this->opened_header; }
	public function current_context(): ?QuoteContext { return $this->current_context; }
	public function capture(): ?DeliveryQuote { return $this->captured; }
	public function lease(): ?QuoteAdmissionLease { return $this->capture_lease; }
	public function binding(): ?QuoteBinding { return $this->binding_facts; }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized quote command projection is required.' ); }
	public function __serialize(): array { throw new \LogicException( 'Original quote command credentials must remain private.' ); }
}
