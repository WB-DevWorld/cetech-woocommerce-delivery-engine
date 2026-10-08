<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\Contracts\CanonicalIntent;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;

/** Original cheap, server-owned request; it makes no claim about resolved material facts. */
final readonly class QuotePreparationCommand implements \JsonSerializable {
	private function __construct( private QuoteOwner $quote_owner, private string $draft, private string $token, private string $provider, private int $provider_revision, private string $quote_profile, private int $profile_revision, private OperationIdentity $issue_identity, private CanonicalIntent $intent ) {}
	public static function create( QuoteOwner $owner, string $draft_digest, string $original_token, string $provider_code, int $provider_version, string $profile, int $profile_version ): self {
		QuoteShape::digest( $draft_digest ); QuoteId::from_string( $original_token );
		QuoteShape::machine( $provider_code ); QuoteShape::integer( $provider_version, 1, 1000000 ); QuoteShape::machine( $profile ); QuoteShape::integer( $profile_version, 1, 1000000 );
		$identity = new OperationIdentity( $owner->site_id(), 'delivery_quote_customer', $owner->digest(), 'delivery_quote.issue', 1, 'cart:' . $owner->digest(), $original_token );
		$intent = CanonicalIntent::from_command( $identity, $owner->facts(), [ 'format_version' => 1, 'stage' => 'cart_preparation' ], [ 'draft_digest' => $draft_digest, 'provider' => [ 'code' => $provider_code, 'version' => $provider_version ], 'profile' => $profile, 'profile_version' => $profile_version ] );
		return new self( $owner, $draft_digest, $original_token, $provider_code, $provider_version, $profile, $profile_version, $identity, $intent );
	}
	public function owner(): QuoteOwner { return $this->quote_owner; }
	public function draft_digest(): string { return $this->draft; }
	public function identity(): OperationIdentity { return $this->issue_identity; }
	public function intent_digest(): string { return $this->intent->fingerprint(); }
	public function provider_code(): string { return $this->provider; }
	public function provider_version(): int { return $this->provider_revision; }
	public function profile(): string { return $this->quote_profile; }
	public function profile_version(): int { return $this->profile_revision; }
	public function original_token(): string { return $this->token; }
	public function matches_issue( QuoteIssueCommand $command ): bool {
		return $this->quote_owner->equals( $command->owner() ) && hash_equals( $this->issue_identity->namespace_digest(), $command->identity()->namespace_digest() ) && $this->provider === $command->provider_code() && $this->provider_revision === $command->provider_version() && $this->quote_profile === $command->profile() && $this->profile_revision === $command->profile_version();
	}
	/** Only a trusted session adapter persists this original envelope; it contains no capability. */
	public function to_private_array(): array { return [ 'format_version' => 1, 'owner' => $this->quote_owner->facts(), 'draft_digest' => $this->draft, 'original_token' => $this->token, 'provider_code' => $this->provider, 'provider_version' => $this->provider_revision, 'profile' => $this->quote_profile, 'profile_version' => $this->profile_revision ]; }
	public static function from_private_array( array $facts ): self {
		QuoteShape::fields( $facts, [ 'format_version', 'owner', 'draft_digest', 'original_token', 'provider_code', 'provider_version', 'profile', 'profile_version' ] );
		QuoteShape::integer( $facts['format_version'], 1, 1 );
		if ( ! is_string( $facts['original_token'] ) ) { QuoteShape::invalid(); }
		return self::create( QuoteOwner::from_array( QuoteShape::object( $facts['owner'] ) ), QuoteShape::digest( $facts['draft_digest'] ), $facts['original_token'], QuoteShape::machine( $facts['provider_code'] ), QuoteShape::integer( $facts['provider_version'], 1, 1000000 ), QuoteShape::machine( $facts['profile'] ), QuoteShape::integer( $facts['profile_version'], 1, 1000000 ) );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized quote preparation projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'Use the explicit private original envelope.' ); }
}
