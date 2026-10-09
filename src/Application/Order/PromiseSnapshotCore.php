<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\Order;

use CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuotePreparation;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteMoney, QuoteShape};

/** Exact outer-format-3 historical facts. No current policy, calendar or localization is consulted. */
final class PromiseSnapshotCore {
	public const LINE_FIELDS = [ 'contract_version', 'snapshot_version', 'product_id', 'variation_id', 'fulfilment_availability', 'fulfilment_choice', 'delivery_offer_id', 'delivery_offer_public_label', 'delivery_offer_public_description', 'estimate_text', 'rule_id', 'destination_zone_id', 'quantity', 'currency_code', 'quoted_amount', 'quote_status', 'rate_card_id', 'rate_card_code', 'snapshotted_at', 'delivery_group_id', 'customer_context_version', 'matching_location', 'delivery_address', 'matching_identity', 'delivery_location_identity', 'pickup_location_id', 'delivery_quote' ];
	public const PACKAGE_FIELDS = [ 'snapshot_version', 'shipping_method_id', 'shipping_method_label', 'package_total_delivery_amount', 'currency_code', 'destination_zone_id', 'quote_status', 'snapshotted_at', 'groups', 'delivery_quote' ];
	public const GROUP_FIELDS = [ 'group_id', 'shipping_method_id', 'shipping_method_label', 'package_total_delivery_amount', 'fulfilment_choice', 'is_pickup', 'display_index' ];
	public static function line( array $data, DeliveryQuoteSnapshotEnvelope $envelope ): void {
		QuoteShape::fields( $data, self::LINE_FIELDS ); self::common( $data, $envelope );
		if ( '1' !== $data['contract_version'] || 1 !== $data['customer_context_version'] || 'delivery' !== $data['fulfilment_choice'] || 'quoted' !== $data['quote_status'] || null !== $data['pickup_location_id'] ) { QuoteShape::invalid(); }
		foreach ( [ 'product_id', 'quantity', 'delivery_offer_id', 'destination_zone_id' ] as $field ) { QuoteShape::integer( $data[$field] ); }
		foreach ( [ 'variation_id', 'rule_id', 'rate_card_id' ] as $field ) { if ( null !== $data[$field] ) { QuoteShape::integer( $data[$field] ); } }
		self::location( $data['matching_location'], false ); self::location( $data['delivery_address'], true );
		foreach ( [ 'matching_identity', 'delivery_location_identity' ] as $field ) { QuoteShape::digest( $data[$field] ); }
		$group = self::group( $envelope, $data['delivery_group_id'] );
		if ( $data['delivery_offer_public_label'] !== $group['money']['customer_label'] || $data['estimate_text'] !== $group['packet']['customer_text'] ) { QuoteShape::invalid(); }
		self::money( $data['quoted_amount'], $data['currency_code'], $group['money']['final'] );
	}
	public static function package( array $data, DeliveryQuoteSnapshotEnvelope $envelope ): void {
		QuoteShape::fields( $data, self::PACKAGE_FIELDS ); self::common( $data, $envelope );
		if ( 'success' !== $data['quote_status'] || 'delivery_engine_selected_offer' !== $data['shipping_method_id'] ) { QuoteShape::invalid(); }
		$seen = []; $total = null; $index = 1;
		foreach ( QuoteShape::list( $data['groups'], 200, 1 ) as $row ) {
			$row = QuoteShape::object( $row ); QuoteShape::fields( $row, self::GROUP_FIELDS ); $group = self::group( $envelope, $row['group_id'] ); $key = $group['key'];
			if ( isset( $seen[$key] ) || 'delivery' !== $row['fulfilment_choice'] || false !== $row['is_pickup'] || $row['display_index'] !== $index++ || 'delivery_engine_selected_offer' !== $row['shipping_method_id'] || $row['shipping_method_label'] !== $group['money']['customer_label'] ) { QuoteShape::invalid(); } $seen[$key] = true;
			self::money( $row['package_total_delivery_amount'], $data['currency_code'], $group['money']['final'] );
			$amount = QuoteMoney::from_array( $group['money']['final'] ); $total = null === $total ? $amount : $total->add( $amount );
		}
		if ( count( $seen ) !== count( $envelope->private_facts()['money_receipt']['groups'] ) || null === $total ) { QuoteShape::invalid(); }
		self::money( $data['package_total_delivery_amount'], $data['currency_code'], $total->facts() );
		$label = 1 === count( $seen ) ? $envelope->private_facts()['money_receipt']['groups'][0]['customer_label'] : 'Multiple deliveries';
		if ( $data['shipping_method_label'] !== $label ) { QuoteShape::invalid(); }
	}
	private static function common( array $data, DeliveryQuoteSnapshotEnvelope $envelope ): void {
		if ( '3' !== $data['snapshot_version'] || ! $envelope->is_promise() || ! RequiredPromiseSnapshotReadiness::supports( $data['snapshot_version'], $envelope->format(), $envelope->private_facts()['profile'], $envelope->promise_packet()?->private_facts()['format'] ) ) { QuoteShape::invalid(); }
	}
	/** A line's captured text comes from its exact original group, never a session string. */
	public static function group( DeliveryQuoteSnapshotEnvelope $envelope, mixed $group_id ): array {
		if ( ! is_string( $group_id ) ) { QuoteShape::invalid(); } try { $key = NativeCartQuotePreparation::component_key( $group_id ); } catch ( \Throwable ) { QuoteShape::invalid(); } $money = null; $packet = null;
		foreach ( $envelope->private_facts()['money_receipt']['groups'] as $candidate ) { if ( $candidate['component_key'] === $key ) { $money = $candidate; break; } }
		foreach ( $envelope->promise_packet()?->private_facts()['groups'] ?? [] as $candidate ) { if ( $candidate['component_key'] === $key ) { $packet = $candidate['packet']; break; } }
		if ( null === $money || null === $packet ) { QuoteShape::invalid(); } return [ 'key' => $key, 'money' => $money, 'packet' => $packet ];
	}
	private static function money( mixed $value, mixed $currency, array $expected ): void {
		if ( ! is_string( $value ) || ! is_string( $currency ) || ! QuoteMoney::from_array( [ 'amount' => $value, 'currency' => $currency, 'precision' => $expected['precision'] ] )->equals( QuoteMoney::from_array( $expected ) ) ) { QuoteShape::invalid(); }
	}
	private static function location( mixed $value, bool $address ): void {
		$data = QuoteShape::object( $value ); $fields = [ 'country', 'state', 'city', 'postcode', 'country_identity', 'state_identity', 'city_identity', 'postcode_identity' ];
		if ( $address ) { $fields = [ ...$fields, 'address_1', 'address_2', 'address_1_identity', 'address_2_identity', 'recipient' ]; }
		if ( array_key_exists( 'canonical_location_key', $data ) ) { $fields[] = 'canonical_location_key'; } QuoteShape::fields( $data, $fields );
		if ( $address ) { $recipient = QuoteShape::object( $data['recipient'] ); QuoteShape::fields( $recipient, [ 'first_name', 'last_name', 'company', 'phone' ] ); foreach ( $recipient as $text ) { self::text( $text ); } unset( $data['recipient'] ); }
		foreach ( $data as $text ) { self::text( $text ); }
	}
	private static function text( mixed $value ): void { if ( ! is_string( $value ) || strlen( $value ) > 4096 || 1 !== preg_match( '//u', $value ) || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) { QuoteShape::invalid(); } }
}
