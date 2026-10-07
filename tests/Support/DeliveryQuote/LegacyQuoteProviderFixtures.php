<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\LegacyFixedBaseQuoteProvider;
use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourcePlan;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;

/** Synthetic native receipts for pure contract tests, never native Woo proof. */
final class LegacyQuoteProviderFixtures {
	public static function context(): QuoteContext {
		$data = QuoteFixtures::context()->private_facts(); $data['groups'][0]['service_id'] = $data['groups'][0]['offer_id'];
		return QuoteContext::from_array( $data );
	}
	public static function terms(): QuoteTerms {
		$data = QuoteFixtures::terms()->private_facts(); $data['groups'][0]['provider'] = [ 'code' => LegacyFixedBaseQuoteProvider::CODE, 'version' => 1 ];
		$data['groups'][0]['promotion']['provider'] = [ 'code' => LegacyFixedBaseQuoteProvider::PROMOTION_PROVIDER, 'version' => 1 ];
		return QuoteTerms::from_array( $data );
	}
	public static function card( string $type = 'fixed_per_shipment', string $amount = '12.50', array $overrides = [] ): array {
		return array_replace( [ 'id' => 1, 'internal_code' => 'NATIVE_LEGACY', 'delivery_offer_id' => 20, 'destination_zone_id' => 50, 'logistics_profile_id' => null, 'supplier_id' => null, 'origin_id' => null, 'charge_type' => $type, 'base_amount' => $amount, 'base_currency' => 'GHS', 'priority' => 100, 'status' => 'active', 'effective_from' => null, 'effective_to' => null ], $overrides );
	}
	public static function plan(): LegacyQuoteSourcePlan {
		$context = self::context(); $group = $context->private_facts()['groups'][0]; $member = [ 'line_key' => 'line_one' ];
		foreach ( [ 'offer_id', 'service_id', 'choice', 'origin', 'supplier', 'profile', 'destination_zone_id', 'endpoint_digest' ] as $field ) { $member[$field] = $group[$field]; }
		return LegacyQuoteSourcePlan::create( QuoteFixtures::owner(), $context, [ $member ], [ [ 'delivery_offer_id' => 20, 'destination_zone_id' => 50, 'base_currency' => 'GHS' ] ], [
			[ 'source' => 'product', 'ids' => [ 10 ] ], [ 'source' => 'product_meta', 'ids' => [ 10 ] ], [ 'source' => 'term_relationships', 'ids' => [ 10 ] ], [ 'source' => 'offers', 'ids' => [ 20 ] ], [ 'source' => 'origins', 'ids' => [ 40 ] ],
			[ 'source' => 'options', 'names' => LegacyQuoteSourcePlan::OPTIONS ], [ 'source' => 'zones' ], [ 'source' => 'zone_rules' ], [ 'source' => 'scopes', 'targets' => [ [ 'type' => 'global', 'id' => 0 ], [ 'type' => 'product', 'id' => 10 ] ] ], [ 'source' => 'scope_fields' ], [ 'source' => 'scope_collections' ],
		] );
	}
}
