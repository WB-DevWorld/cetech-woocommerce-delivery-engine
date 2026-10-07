<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteRequest;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\ValueObject\CurrencyCode;

/** Uses the retained matcher/formula over a source-verified complete bounded view. */
final class LegacyQuoteEngineCapture {

	/**
	 * No native money, tax, cart writes or durable quote effects occur here.
	 * The repository must be the complete source snapshot, never a limited list page.
	 *
	 * @return list<array{component_key:string,expected:QuoteMoney,rate_card_id:int,charge_type:string}>
	 */
	public function capture( QuoteContext $context, RateCardRepositoryInterface $repository ): array {
		if ( ! $context->checkout_acceptable() ) { QuoteShape::invalid(); }
		$facts = $context->private_facts(); $lines = [];
		foreach ( $facts['lines'] as $line ) { $lines[$line['line_key']] = $line; }
		$engine = new RateQuoteEngine( $repository ); $result = []; $candidate_count = 0;
		foreach ( $facts['groups'] as $group ) {
			// This profile declares offer-specific service identity; it invents no service table.
			if ( $group['service_id'] !== $group['offer_id'] ) { QuoteShape::invalid(); }
			$quantity = 0;
			foreach ( $group['line_keys'] as $key ) {
				$value = $lines[$key]['quantity'];
				if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/D', $value ) || strlen( $value ) > strlen( (string) PHP_INT_MAX ) || ( strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) > 0 ) ) { QuoteShape::invalid(); }
				$next = (int) $value; if ( $quantity > PHP_INT_MAX - $next ) { QuoteShape::invalid(); } $quantity += $next;
			}
			$cards = $repository->listActiveForQuoteMatch( $group['offer_id'], $group['destination_zone_id'], $facts['currency']['charged'] );
			$candidate_count += count( $cards ); if ( $candidate_count > QuoteContext::MAX_RATE_CANDIDATES ) { QuoteShape::invalid(); }
			foreach ( $cards as $card ) {
				if ( ! is_array( $card ) ) { QuoteShape::invalid(); }
				// The old matcher treats a missing request dimension as a wildcard. Retention
				// cannot claim that a positive scoped price was proved for an absent dimension.
				foreach ( [ 'origin' => 'origin_id', 'supplier' => 'supplier_id', 'profile' => 'logistics_profile_id' ] as $dimension => $field ) {
					$scoped = $card[$field] ?? null;
					if ( null !== $scoped && '' !== $scoped && ( ! is_int( $scoped ) && ! ( is_string( $scoped ) && 1 === preg_match( '/\A[0-9]+\z/D', $scoped ) ) ) ) { QuoteShape::invalid(); }
					if ( (int) $scoped > 0 && 'known' !== $group[$dimension]['state'] ) { QuoteShape::invalid(); }
				}
			}
			$first = $lines[$group['line_keys'][0]];
			$request = new RateQuoteRequest( $group['offer_id'], $group['destination_zone_id'], $quantity, new CurrencyCode( $facts['currency']['charged'] ), $first['product_id'], $first['variation_id'], null, self::dimension( $group['profile'] ), self::dimension( $group['supplier'] ), self::dimension( $group['origin'] ), null, 'delivery' );
			$quote = $engine->quote( $request );
			if ( ! $quote->success || null === $quote->amount || null === $quote->matched_rate_card_id || ! in_array( $quote->charge_type, [ RateCardChargeType::FixedPerItem->value, RateCardChargeType::FixedPerShipment->value ], true ) ) { QuoteShape::invalid(); }
			$result[] = [ 'component_key' => $group['component_key'], 'expected' => QuoteMoney::from_array( [ 'amount' => $quote->amount->amount(), 'currency' => $quote->amount->currency()->value(), 'precision' => 4 ] ), 'rate_card_id' => QuoteShape::integer( $quote->matched_rate_card_id ), 'charge_type' => $quote->charge_type ];
		}
		return $result;
	}

	private static function dimension( array $value ): ?int { return 'known' === $value['state'] ? $value['id'] : null; }
}
