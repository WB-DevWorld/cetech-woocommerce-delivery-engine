<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteReference;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;

/** Synthetic typed test facts. These receipts do not claim native Woo capture. */
final class QuoteFixtures {

	public static function owner(): QuoteOwner {
		return QuoteOwner::from_array( [ 'site_id' => 1, 'kind' => 'guest', 'principal_hash' => self::digest( 'principal' ), 'session_hash' => self::digest( 'session' ), 'key_epoch' => 'fixture_key_1' ] );
	}

	public static function time( string $sql = '2026-10-07 05:00:00.000000' ): QuoteTime { return QuoteTime::parse( $sql ); }

	/** Overrides replace complete root fields, never merge a partial nested schema. */
	public static function context( array $overrides = [] ): QuoteContext {
		return QuoteContext::from_array( array_replace( [
			'format_version' => 1, 'kind' => 'checkout', 'selection_digest' => self::digest( 'selection' ),
			'destination' => [ 'kind' => 'full', 'digest' => self::digest( 'destination' ), 'key_epoch' => 'fixture_key_1' ],
			'currency' => [ 'base' => 'GHS', 'presentment' => 'GHS', 'charged' => 'GHS', 'precision' => 2 ],
			'tax' => [ 'context_digest' => self::digest( 'tax_context' ), 'native_money_digest' => self::digest( 'native_money' ) ],
			'lines' => [ [ 'line_key' => 'line_one', 'product_id' => 10, 'variation_id' => null, 'parent_id' => null, 'quantity' => '2', 'component_key' => self::digest( 'component' ), 'source' => [ 'route' => 'ecr', 'identity_digest' => self::digest( 'source' ), 'revision' => 3 ], 'inventory' => [ 'status' => 'eligible', 'evidence_digest' => self::digest( 'inventory' ) ] ] ],
			'groups' => [ [ 'component_key' => self::digest( 'component' ), 'line_keys' => [ 'line_one' ], 'choice' => 'delivery', 'offer_id' => 20, 'service_id' => 30, 'origin' => [ 'state' => 'known', 'id' => 40 ], 'supplier' => [ 'state' => 'absent', 'id' => null ], 'profile' => [ 'state' => 'absent', 'id' => null ], 'destination_zone_id' => 50, 'endpoint_digest' => self::digest( 'endpoint' ), 'policy_digest' => self::digest( 'policy' ), 'candidate_digest' => self::digest( 'candidates' ), 'candidate_count' => 1 ] ],
		], $overrides ) );
	}

	public static function terms( array $overrides = [] ): QuoteTerms {
		$money = [ 'amount' => '12.50', 'currency' => 'GHS', 'precision' => 2 ]; $zero = [ 'amount' => '0.00', 'currency' => 'GHS', 'precision' => 2 ];
		return QuoteTerms::from_array( array_replace( [ 'format_version' => 1, 'groups' => [ [
			'component_key' => self::digest( 'component' ), 'customer_label' => 'Fixture delivery', 'provider' => [ 'code' => 'fixture_v1', 'version' => 1 ], 'policy_digest' => self::digest( 'policy' ),
			'list' => $money, 'final' => $money, 'tax' => $zero, 'total' => $money,
			'promotion' => [ 'state' => 'none', 'amount' => $zero, 'provider' => [ 'code' => 'fixture_none_v1', 'version' => 1 ] ],
			'cost' => [ 'state' => 'unavailable', 'reason' => 'cost_provider_unavailable' ], 'route' => [ 'state' => 'not_recorded' ],
			'native_tax_receipt' => [ 'state' => 'recorded', 'source' => 'woocommerce', 'context_digest' => self::digest( 'tax_context' ), 'exempt' => true, 'tax_status' => 'none', 'tax_class' => '', 'location_digest' => self::digest( 'tax_location' ), 'rounding' => 'per_line', 'rates' => [], 'rounded_tax' => $zero, 'display_precision' => 2 ],
			'native_money_receipt' => [ 'state' => 'recorded', 'source' => 'woocommerce', 'evidence_digest' => self::digest( 'native_money' ), 'native_total' => $money, 'display_total' => $money, 'display_precision' => 2 ],
		] ] ], $overrides ) );
	}

	public static function issue( ?QuoteOwner $owner = null, ?QuoteTime $time = null, ?QuoteContext $context = null, ?QuoteTerms $terms = null ): DeliveryQuote {
		$owner ??= self::owner(); $time ??= self::time(); $context ??= self::context(); $terms ??= self::terms(); $id = QuoteId::generate();
		$header = QuoteHeader::issue( $id, $owner, $context, $terms, $time, [ 'issue' => self::digest( 'issue:' . $id->value() ), 'accept' => self::digest( 'accept:' . $id->value() ), 'invalidate' => self::digest( 'invalidate:' . $id->value() ) ], 'fixture_v1', 1, self::reference( $id ) );
		return DeliveryQuote::issue( $header, $context, $terms );
	}

	/** A deterministic fixture-only handle, never a production handle generator. */
	public static function reference( QuoteId $id ): QuoteReference { return QuoteReference::from_array( [ 'quote_id' => $id->value(), 'acceptance_handle' => self::digest( 'handle:' . $id->value() ) ] ); }
	public static function digest( string $value ): string { return hash( 'sha256', 'fixture-delivery-quote:' . $value ); }
}
