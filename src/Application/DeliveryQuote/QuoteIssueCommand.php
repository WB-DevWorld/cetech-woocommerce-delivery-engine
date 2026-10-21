<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\Contracts\CanonicalIntent;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;

/** Original semantic command only; it neither persists nor deduplicates issuance. */
final readonly class QuoteIssueCommand implements \JsonSerializable {
	private function __construct( private QuoteOwner $quote_owner, private QuoteContext $quote_context, private string $provider_code, private int $provider_version, private string $quote_profile, private int $quote_profile_version, private OperationIdentity $issue_identity, private CanonicalIntent $intent, private ?string $promotion_token = null, private ?array $promotion_slots = null, private ?QuoteTime $promotion_deadline = null ) {}

	public static function create( QuoteOwner $owner, QuoteContext $context, string $provider_code, int $provider_version, string $profile, int $profile_version, string $original_token ): self {
		QuoteShape::machine( $provider_code ); QuoteShape::integer( $provider_version, 1, 1000000 ); QuoteShape::machine( $profile ); QuoteShape::integer( $profile_version, 1, 1000000 );
		if ( $owner->key_epoch() !== $context->private_facts()['destination']['key_epoch'] ) { QuoteShape::invalid(); }
		$identity = new OperationIdentity( $owner->site_id(), 'delivery_quote_customer', $owner->digest(), 'delivery_quote.issue', 1, 'cart:' . $owner->digest(), $original_token );
		$intent = CanonicalIntent::from_command( $identity, $owner->facts(), [ 'format_version' => 1 ], [ 'context' => $context->private_facts(), 'provider' => [ 'code' => $provider_code, 'version' => $provider_version ], 'profile' => $profile, 'profile_version' => $profile_version ] );
		return new self( $owner, $context, $provider_code, $provider_version, $profile, $profile_version, $identity, $intent );
	}

	/** Dedicated internal v2 intent: complete account choices and the deadline are immutable. */
	public static function fixed_promotion( QuoteOwner $owner, QuoteContext $context, string $original_token, array $complete_slots, QuoteTime $hold_until ): self {
		QuoteId::from_string( $original_token );
		if ( 3 !== $context->format_version() || ! $context->checkout_acceptable() || $owner->key_epoch() !== $context->private_facts()['destination']['key_epoch'] || $hold_until->epoch_microseconds() < 1 ) { QuoteShape::invalid(); }
		$capture = $context->fixed_promotion_capture(); if ( null === $capture ) { QuoteShape::invalid(); }
		$groups = []; foreach ( $capture->private_facts()['groups'] as $group ) { $groups[$group['component_key']] = null !== $group['plan']; }
		$complete_slots = QuoteJson::detach( [ 'slots' => $complete_slots ] )['slots']; $slots = []; $promoted = 0;
		foreach ( QuoteShape::list( $complete_slots, QuoteContext::MAX_GROUPS, 1 ) as $slot ) {
			$slot = QuoteShape::object( $slot ); QuoteShape::fields( $slot, [ 'component_key', 'account_key' ] ); $key = QuoteShape::digest( $slot['component_key'] );
			if ( isset( $slots[$key] ) || ! array_key_exists( $key, $groups ) || $groups[$key] !== ( null !== $slot['account_key'] ) ) { QuoteShape::invalid(); }
			$account = null === $slot['account_key'] ? null : QuoteShape::digest( $slot['account_key'] ); if ( null !== $account ) { ++$promoted; }
			$slots[$key] = [ 'component_key' => $key, 'account_key' => $account ];
		}
		if ( count( $slots ) !== count( $groups ) || 0 === $promoted ) { QuoteShape::invalid(); } ksort( $slots, SORT_STRING ); $slots = array_values( $slots );
		$identity = new OperationIdentity( $owner->site_id(), 'delivery_quote_customer', $owner->digest(), 'delivery_quote.issue', 2, 'cart:' . $owner->digest(), $original_token );
		$intent = CanonicalIntent::from_command( $identity, $owner->facts(), [ 'format_version' => 2 ], [ 'context' => $context->private_facts(), 'provider' => [ 'code' => 'fixed_promotion_native_v1', 'version' => 1 ], 'profile' => 'fixed_promotion_native_v1', 'profile_version' => 1, 'promotion_budget' => [ 'account_slots' => $slots, 'hold_until' => $hold_until->sql() ] ] );
		return new self( $owner, $context, 'fixed_promotion_native_v1', 1, 'fixed_promotion_native_v1', 1, $identity, $intent, $original_token, $slots, $hold_until );
	}
	public function promotion_account_slots(): ?array { return $this->promotion_slots; }
	public function promotion_hold_until(): ?QuoteTime { return $this->promotion_deadline; }
	/** Private facts only; no admission, preparation, or database capability is serialized. */
	public function to_promotion_recovery_array(): array {
		if ( 2 !== $this->issue_identity->operation_version || null === $this->promotion_token || null === $this->promotion_slots || null === $this->promotion_deadline ) { QuoteShape::invalid(); }
		return QuoteJson::detach( [ 'format_version' => 1, 'owner' => $this->quote_owner->facts(), 'context' => $this->quote_context->private_facts(), 'original_token' => $this->promotion_token, 'account_slots' => $this->promotion_slots, 'hold_until' => $this->promotion_deadline->sql() ] );
	}
	public static function from_promotion_recovery_array( array $facts ): self {
		$facts = QuoteJson::detach( $facts ); QuoteShape::fields( $facts, [ 'format_version', 'owner', 'context', 'original_token', 'account_slots', 'hold_until' ] );
		if ( 1 !== $facts['format_version'] || ! is_string( $facts['original_token'] ) || ! is_string( $facts['hold_until'] ) ) { QuoteShape::invalid(); }
		return self::fixed_promotion( QuoteOwner::from_array( QuoteShape::object( $facts['owner'] ) ), QuoteContext::from_array( QuoteShape::object( $facts['context'] ) ), $facts['original_token'], QuoteShape::list( $facts['account_slots'], QuoteContext::MAX_GROUPS, 1 ), QuoteTime::parse( $facts['hold_until'] ) );
	}

	public function owner(): QuoteOwner { return $this->quote_owner; }
	public function context(): QuoteContext { return $this->quote_context; }
	public function provider_code(): string { return $this->provider_code; }
	public function provider_version(): int { return $this->provider_version; }
	public function profile(): string { return $this->quote_profile; }
	public function profile_version(): int { return $this->quote_profile_version; }
	public function intent_digest(): string { return $this->intent->fingerprint(); }
	/** The exact original internal namespace, never a newly minted replay token. */
	public function identity(): OperationIdentity { return $this->issue_identity; }
	public function intent(): CanonicalIntent { return $this->intent; }
	public function namespace_hashes( QuoteId $id ): array {
		$namespaces = [ 'issue' => $this->issue_identity->namespace_digest() ];
		foreach ( [ 'accept', 'invalidate' ] as $purpose ) {
			$identity = new OperationIdentity( $this->quote_owner->site_id(), 'delivery_quote_customer', $this->quote_owner->digest(), 'delivery_quote.' . $purpose, 1, 'quote:' . $id->value(), $this->issue_identity->namespace_digest() );
			$namespaces[$purpose] = $identity->namespace_digest();
		}
		return $namespaces;
	}
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized delivery quote command projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'An explicit private original issue projection is required.' ); }
	public function __unserialize( array $facts ): never { throw new \LogicException( 'Issue commands require explicit validated construction.' ); }
	public function __debugInfo(): array { return [ 'private_quote_issue_command' => 'redacted' ]; }
}
