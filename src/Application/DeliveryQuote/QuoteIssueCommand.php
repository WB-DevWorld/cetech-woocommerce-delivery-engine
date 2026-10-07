<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\Contracts\CanonicalIntent;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;

/** Original semantic command only; it neither persists nor deduplicates issuance. */
final readonly class QuoteIssueCommand implements \JsonSerializable {
	private function __construct( private QuoteOwner $quote_owner, private QuoteContext $quote_context, private string $provider_code, private int $provider_version, private string $quote_profile, private int $quote_profile_version, private OperationIdentity $issue_identity, private CanonicalIntent $intent ) {}

	public static function create( QuoteOwner $owner, QuoteContext $context, string $provider_code, int $provider_version, string $profile, int $profile_version, string $original_token ): self {
		QuoteShape::machine( $provider_code ); QuoteShape::integer( $provider_version, 1, 1000000 ); QuoteShape::machine( $profile ); QuoteShape::integer( $profile_version, 1, 1000000 );
		if ( $owner->key_epoch() !== $context->private_facts()['destination']['key_epoch'] ) { QuoteShape::invalid(); }
		$identity = new OperationIdentity( $owner->site_id(), 'delivery_quote_customer', $owner->digest(), 'delivery_quote.issue', 1, 'cart:' . $owner->digest(), $original_token );
		$intent = CanonicalIntent::from_command( $identity, $owner->facts(), [ 'format_version' => 1 ], [ 'context' => $context->private_facts(), 'provider' => [ 'code' => $provider_code, 'version' => $provider_version ], 'profile' => $profile, 'profile_version' => $profile_version ] );
		return new self( $owner, $context, $provider_code, $provider_version, $profile, $profile_version, $identity, $intent );
	}

	public function owner(): QuoteOwner { return $this->quote_owner; }
	public function context(): QuoteContext { return $this->quote_context; }
	public function provider_code(): string { return $this->provider_code; }
	public function provider_version(): int { return $this->provider_version; }
	public function profile(): string { return $this->quote_profile; }
	public function profile_version(): int { return $this->quote_profile_version; }
	public function intent_digest(): string { return $this->intent->fingerprint(); }
	public function namespace_hashes( QuoteId $id ): array {
		$namespaces = [ 'issue' => $this->issue_identity->namespace_digest() ];
		foreach ( [ 'accept', 'invalidate' ] as $purpose ) {
			$identity = new OperationIdentity( $this->quote_owner->site_id(), 'delivery_quote_customer', $this->quote_owner->digest(), 'delivery_quote.' . $purpose, 1, 'quote:' . $id->value(), $this->issue_identity->namespace_digest() );
			$namespaces[$purpose] = $identity->namespace_digest();
		}
		return $namespaces;
	}
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized delivery quote command projection is required.' ); }
}
