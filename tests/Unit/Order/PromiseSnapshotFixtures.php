<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\Order;

use CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuotePreparation;
use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotEnvelope, OrderDeliveryLineSnapshot};
use CetechDeliveryEngine\Application\ServicePromise\Calculation\DeterministicPromiseCalculator;
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseInput, PromiseResult};
use CetechDeliveryEngine\Domain\ServicePromise\Handoff\{PromiseHistoricalPacket, PromiseQuotePacket};
use CetechDeliveryEngine\Tests\Support\ServicePromise\Calculation\CalculationFixture as F;
use CetechDeliveryEngine\Tests\Unit\CustomerContext\PerItemContextFixtures;

/** Synthetic reader history only; this does not claim native collection, placement or payment. */
final class PromiseSnapshotFixtures {
	public const GROUP = 'in_warehouse|delivery|1';
	public const TEXT = 'Original delivery commitment';
	public static function envelope( string $state = 'absolute_window' ): DeliveryQuoteSnapshotEnvelope {
		$data = DeliveryQuoteSnapshotFixtures::envelope()->private_facts(); $key = NativeCartQuotePreparation::component_key( self::GROUP );
		foreach ( [ 'money_receipt', 'provenance_receipt' ] as $name ) { $data[$name]['groups'][0]['component_key'] = $key; }
		$policy = F::policy( 'relative_window' === $state ? [ 'anchor' => 'payment_confirmed', 'late_payment_rule' => 'relative_after_payment' ] : [ 'promise_required' => 'absolute_window' === $state ] );
		$input = F::input( $policy, $data['issued_at'] )->private_facts(); $input['material']['group_id'] = $key; $typed = PromiseInput::from_array( $input );
		$result = in_array( $state, [ 'absolute_window', 'relative_window' ], true ) ? ( new DeterministicPromiseCalculator( F::runtime() ) )->calculate( $typed, [] ) : PromiseResult::from_array( [ 'format_version' => 1, 'state' => $state, 'input' => $typed->private_facts(), 'input_digest' => $typed->digest(), 'graph_digest' => $policy->graph()->digest(), 'body' => null, 'reason_codes' => [ 'estimate_unavailable' ] ] );
		$packet = PromiseQuotePacket::from_array( [ 'format' => 1, 'groups' => [ [ 'component_key' => $key, 'packet' => PromiseHistoricalPacket::capture( $result, in_array( $state, [ 'absolute_window', 'relative_window' ], true ) ? self::TEXT : 'Delivery estimate unavailable' )->private_facts() ] ] ] );
		$data['format'] = 2; $data['profile'] = DeliveryQuoteSnapshotEnvelope::PROMISE_PROFILE; $data['promise_packet'] = $packet->private_facts(); $data['promise_packet_digest'] = DeliveryQuoteSnapshotEnvelope::promise_digest( $packet );
		return DeliveryQuoteSnapshotEnvelope::from_array( $data );
	}
	public static function line( ?DeliveryQuoteSnapshotEnvelope $envelope = null ): array {
		$envelope ??= self::envelope(); $ctx = PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryEastLegon() ); $text = $envelope->promise_packet()->private_facts()['groups'][0]['packet']['customer_text'];
		$line = new OrderDeliveryLineSnapshot( '1', '3', 16, null, 'in_warehouse', 'delivery', 1, 'Fixture delivery', null, $text, 41, 1, 2, 'GHS', '12.50', 'quoted', null, null, '2026-10-07T05:00:10+00:00', self::GROUP, null, null, null, 1, $ctx->matching_location?->toArray(), $ctx->delivery_address?->toArray(), $ctx->matching_identity, $ctx->delivery_location_identity, null );
		return $line->toArray() + [ 'delivery_quote' => $envelope->private_facts() ];
	}
	public static function package( ?DeliveryQuoteSnapshotEnvelope $envelope = null ): array {
		$envelope ??= self::envelope(); $data = DeliveryQuoteSnapshotFixtures::package(); $data['snapshot_version'] = '3'; $data['shipping_method_label'] = $data['groups'][0]['shipping_method_label'] = 'Fixture delivery'; $data['groups'][0]['package_total_delivery_amount'] = $data['package_total_delivery_amount'] = '12.50';
		return $data + [ 'delivery_quote' => $envelope->private_facts() ];
	}
}
