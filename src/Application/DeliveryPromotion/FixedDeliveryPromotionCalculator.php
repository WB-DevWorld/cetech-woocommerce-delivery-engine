<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryPromotion;

use CetechDeliveryEngine\Domain\DeliveryPromotion\{DeliveryPromotionPlan, FixedDeliveryPromotion};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteShape, QuoteTerms};

/** Plans exactly one fixed discount, without modifying native quote/tax/economic facts. */
final readonly class FixedDeliveryPromotionCalculator implements \JsonSerializable {
	public function __construct( private DeliveryPromotionEvidenceProvider $provider ) {}

	public function calculate( QuoteTerms $terms, string $component_key, FixedDeliveryPromotion $rule ): DeliveryPromotionPlan {
		$refusal = DeliveryPromotionPlan::preflight( $terms, $component_key, $rule );
		if ( null !== $refusal ) { return $refusal; }
		try {
			$code = QuoteShape::machine( $this->provider->code() );
			$version = QuoteShape::integer( $this->provider->version(), 1, 1000000 );
			$evidence = $this->provider->capture( $terms, $component_key, $rule );
			if ( $code !== $this->provider->code() || $version !== $this->provider->version() ) {
				return DeliveryPromotionPlan::refused( $terms, $component_key, $rule, 'evidence_invalid' );
			}
		} catch ( \Throwable ) {
			return DeliveryPromotionPlan::refused( $terms, $component_key, $rule, 'evidence_unavailable' );
		}
		return DeliveryPromotionPlan::from_evidence( $terms, $component_key, $rule, $code, $version, $evidence );
	}

	public function jsonSerialize(): never { throw new \LogicException( 'Promotion calculators cannot expose their private evidence provider.' ); }
	public function __serialize(): array { throw new \LogicException( 'Promotion calculators cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Promotion calculators cannot be unserialized implicitly.' ); }
	public function __debugInfo(): array { return [ 'private_promotion' => 'redacted' ]; }
}
