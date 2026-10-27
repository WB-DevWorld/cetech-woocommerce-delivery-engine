<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryEconomics;

use CetechDeliveryEngine\Domain\DeliveryEconomics\QuoteEconomicsFacts;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteShape, QuoteTerms};

/** Unmounted capture seam; its callers must authorize the containing quote and private purpose. */
final readonly class QuoteEconomicsCapture implements \JsonSerializable {
	private function __construct( private QuoteTerms $terms, private QuoteEconomicsFacts $economics ) {}

	/** Source observations are supplied explicitly; legacy cost or discounts imply no new fact. */
	public static function capture( QuoteTerms $terms, array $groups ): self {
		return self::bind( $terms, QuoteEconomicsFacts::for_terms( $terms, $groups ) );
	}

	public static function bind( QuoteTerms $terms, QuoteEconomicsFacts $economics ): self {
		if ( ! $economics->matches_terms( $terms ) ) { QuoteShape::invalid(); }
		return new self( $terms, $economics );
	}

	/** Uses the existing public whitelist; this method does not grant access to a quote. */
	public function customer_money(): array { return $this->terms->display_money(); }
	/** Requires the caller's separate private-economic authorization; never a customer payload. */
	public function private_economics(): array { return $this->economics->private_facts(); }
	public function terms_digest(): string { return $this->economics->terms_digest(); }
	public function jsonSerialize(): never { throw new \LogicException( 'Private economics require an explicit authorized projection.' ); }
	public function __serialize(): array { throw new \LogicException( 'Private economics cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Private economics cannot be unserialized implicitly.' ); }
	public function __debugInfo(): array { return [ 'private_economics' => 'redacted' ]; }
}
