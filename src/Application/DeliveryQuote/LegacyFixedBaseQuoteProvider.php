<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;

/** Explicit internal provider for a read-only, source/native-verified capture. */
final readonly class LegacyFixedBaseQuoteProvider implements QuoteProviderInterface, \JsonSerializable {
	public const CODE = 'legacy_fixed_base_v1';
	public const VERSION = 1;
	public const PROMOTION_PROVIDER = 'native_no_delivery_promotion_v1';
	private QuoteContext $context;
	private QuoteTerms $terms;

	/** Prepared facts never authorize a customer; the durable service owns admission. */
	public function __construct( QuoteOwner $owner, QuoteContext $context, QuoteTerms $terms ) {
		if ( ! $context->checkout_acceptable() || ! $terms->checkout_acceptable() || $owner->key_epoch() !== $context->private_facts()['destination']['key_epoch'] ) { QuoteShape::invalid(); }
		$facts = $context->private_facts(); $groups = [];
		foreach ( $facts['groups'] as $group ) { if ( $group['service_id'] !== $group['offer_id'] ) { QuoteShape::invalid(); } $groups[$group['component_key']] = $group; }
		$captured = $terms->private_facts();
		if ( count( $captured['groups'] ) !== count( $groups ) ) { QuoteShape::invalid(); }
		foreach ( $captured['groups'] as $term ) {
			$group = $groups[$term['component_key']] ?? null;
			if ( null === $group || $term['policy_digest'] !== $group['policy_digest'] || $term['provider'] !== [ 'code' => self::CODE, 'version' => self::VERSION ] ) { QuoteShape::invalid(); }
			foreach ( [ 'list', 'final', 'tax', 'total' ] as $field ) { if ( $term[$field]['currency'] !== $facts['currency']['charged'] ) { QuoteShape::invalid(); } }
			if ( ! QuoteMoney::from_array( $term['list'] )->equals( QuoteMoney::from_array( $term['final'] ) ) || 'none' !== $term['promotion']['state'] || ! QuoteMoney::from_array( $term['promotion']['amount'] )->zero() || $term['promotion']['provider'] !== [ 'code' => self::PROMOTION_PROVIDER, 'version' => 1 ] || 'unavailable' !== $term['cost']['state'] || 'cost_provider_unavailable' !== $term['cost']['reason'] || $term['route'] !== [ 'state' => 'not_recorded' ] ) { QuoteShape::invalid(); }
			if ( 'recorded' !== $term['native_tax_receipt']['state'] || 'recorded' !== $term['native_money_receipt']['state'] || $term['native_tax_receipt']['context_digest'] !== $facts['tax']['context_digest'] || $term['native_money_receipt']['evidence_digest'] !== $facts['tax']['native_money_digest'] ) { QuoteShape::invalid(); }
		}
		$this->context = QuoteContext::from_json( $context->to_private_json() );
		$this->terms = QuoteTerms::from_json( $terms->to_private_json() );
	}

	public function code(): string { return self::CODE; }
	public function version(): int { return self::VERSION; }
	public function profile(): string { return self::CODE; }
	public function profile_version(): int { return self::VERSION; }
	public function evidence_providers(): array { return [ self::PROMOTION_PROVIDER => [ 1 ] ]; }
	/** A replay never consults native Woo or recalculates the original terms. */
	public function capture( QuoteContext $context ): QuoteTerms {
		if ( ! hash_equals( $this->context->digest(), $context->digest() ) ) { QuoteShape::invalid(); }
		return QuoteTerms::from_json( $this->terms->to_private_json() );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized delivery quote provider projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'Prepared delivery quote providers cannot be serialized generically.' ); }
}
