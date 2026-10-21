<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryEconomics;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteJson, QuoteMoney, QuoteShape, QuoteTerms};

/** Detached private observations bound to captured terms, without quote lifecycle authority. */
final readonly class QuoteEconomicsFacts implements \JsonSerializable {
	public const FORMAT = 1;
	private const AMOUNTS = [ 'estimated_internal_cost', 'actual_internal_cost', 'provider_payable' ];

	private function __construct( private string $json, private string $terms_digest ) {}

	public static function for_terms( QuoteTerms $terms, array $groups ): self {
		return self::from_array( $terms, [ 'format_version' => self::FORMAT, 'terms_digest' => $terms->digest(), 'groups' => $groups ] );
	}

	public static function from_array( QuoteTerms $terms, array $data ): self {
		$data = QuoteJson::detach( $data );
		QuoteShape::fields( $data, [ 'format_version', 'terms_digest', 'groups' ] );
		if ( self::FORMAT !== $data['format_version'] || ! hash_equals( $terms->digest(), QuoteShape::digest( $data['terms_digest'] ) ) ) {
			QuoteShape::invalid();
		}
		$basis = [];
		foreach ( $terms->private_facts()['groups'] as $group ) {
			$basis[$group['component_key']] = QuoteMoney::from_array( $group['final'] );
		}
		$groups = QuoteShape::list( $data['groups'], 200, 1 );
		$seen = [];
		foreach ( $groups as &$group ) {
			$group = QuoteShape::object( $group );
			QuoteShape::fields( $group, [ 'component_key', ...self::AMOUNTS, 'subsidy' ] );
			$key = QuoteShape::digest( $group['component_key'] );
			if ( isset( $seen[$key] ) || ! isset( $basis[$key] ) ) { QuoteShape::invalid(); }
			$seen[$key] = true;
			foreach ( self::AMOUNTS as $field ) {
				$amount = EconomicAmountFact::from_array( QuoteShape::object( $group[$field] ) );
				$amount->assert_money_unit( $basis[$key] );
				$group[$field] = $amount->private_facts();
			}
			$subsidy = QuoteShape::object( $group['subsidy'] );
			QuoteShape::fields( $subsidy, [ 'amount', 'funding_source' ] );
			$amount = EconomicAmountFact::from_array( QuoteShape::object( $subsidy['amount'] ) );
			$amount->assert_money_unit( $basis[$key] );
			if ( $amount->is_known() ) {
				$funding = QuoteShape::object( $subsidy['funding_source'] );
				QuoteShape::fields( $funding, [ 'code', 'version' ] );
				QuoteShape::machine( $funding['code'] );
				QuoteShape::integer( $funding['version'], 1, 1000000 );
			} elseif ( null !== $subsidy['funding_source'] ) { QuoteShape::invalid(); }
			$subsidy['amount'] = $amount->private_facts();
			$group['subsidy'] = $subsidy;
		}
		unset( $group );
		if ( count( $seen ) !== count( $basis ) ) { QuoteShape::invalid(); }
		usort( $groups, static fn( array $a, array $b ): int => strcmp( $a['component_key'], $b['component_key'] ) );
		$data['groups'] = $groups;
		return new self( QuoteJson::encode( $data ), $data['terms_digest'] );
	}

	/** Strict historical hydration; captured terms are supplied, never fetched or repriced. */
	public static function from_json( QuoteTerms $terms, string $json ): self { return self::from_array( $terms, QuoteJson::decode( $json ) ); }

	public function matches_terms( QuoteTerms $terms ): bool { return hash_equals( $this->terms_digest, $terms->digest() ); }
	public function terms_digest(): string { return $this->terms_digest; }
	public function private_facts(): array { return QuoteJson::decode( $this->json ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-quote-economics-facts-v1:' . $this->json ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Private economics require an explicit authorized projection.' ); }
	public function __serialize(): array { throw new \LogicException( 'Private economics cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Private economics cannot be unserialized implicitly.' ); }
	public function __debugInfo(): array { return [ 'private_economics' => 'redacted' ]; }
}
