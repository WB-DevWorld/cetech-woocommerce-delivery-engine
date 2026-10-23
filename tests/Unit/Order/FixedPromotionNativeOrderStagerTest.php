<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Order;

use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotEnvelope, OrderDeliverySnapshot, QuoteNativeOrderFacts, QuoteNativeOrderHistory, QuoteNativeOrderStager};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionNativeHandoffFixture, PromotionNativeUnitFixture};
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionNativeUnitFixture.php';

/** Declared native and SQL models, not physical WooCommerce acceptance. */
final class FixedPromotionNativeOrderStagerTest extends TestCase {
	private array $saved_globals = []; private array $saved_modes = [];
	protected function setUp(): void {
		parent::setUp();
		foreach ( [ 'current_user', 'blog_id', 'woocommerce', '_GET', '_POST', 'cetech_de_n17_native_identity_key' ] as $name ) { $this->saved_globals[$name] = [ array_key_exists( $name, $GLOBALS ), $GLOBALS[$name] ?? null ]; }
		foreach ( [ 'enabled', 'hpos' ] as $property ) { $this->saved_modes[$property] = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && property_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', $property ) ? \Automattic\WooCommerce\Utilities\OrderUtil::${$property} : false; }
	}
	protected function tearDown(): void {
		foreach ( $this->saved_globals as $name => [ $present, $value ] ) { if ( $present ) { $GLOBALS[$name] = $value; } else { unset( $GLOBALS[$name] ); } }
		foreach ( $this->saved_modes as $property => $value ) { if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && property_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', $property ) ) { \Automattic\WooCommerce\Utilities\OrderUtil::${$property} = $value; } }
		parent::tearDown();
	}
	private function fixture( bool $hpos = false ): array {
		$model = PromotionNativeUnitFixture::create( $hpos );
		$model['acknowledged'] = PromotionNativeHandoffFixture::acknowledged( $model['fixture'], $model['order']->get_id(), $model['mapping'], $model['guard']->evidence_digest() );
		$model['stager'] = new QuoteNativeOrderStager( $model['factory'], static fn(): \WC_Order => clone $model['order'] );
		return $model;
	}
	private function stage( array $model ): \CetechDeliveryEngine\Application\Order\QuoteNativeOrderStageResult {
		return $model['stager']->stage_promotion( $model['order'], $model['acknowledged'], $model['fixture']['draft'], $model['fixture']['tax_source'], 'classic', static fn( \WC_Order $order ): bool => $order === $model['order'] );
	}
	public function test_acknowledged_promotion_uses_the_shared_writer_and_genuine_outer_four_packets(): void {
		foreach ( [ false, true ] as $hpos ) {
			$model = $this->fixture( $hpos ); $order = $model['order']; $native_calls = 0;
			$order->on_get = static function() use ( $model, &$native_calls ): void { ++$native_calls; foreach ( $model['factory']->sessions as $session ) { if ( $session->active ) { throw new \LogicException( 'Native getter executed under the SQL owner.' ); } } };
			self::assertSame( $model['mapping'], $model['stager']->promotion_mapping( $order, $model['fixture']['evidence'], static fn( \WC_Order $candidate ): bool => $candidate === $order ) );
			$stage = $this->stage( $model );
			$package = QuoteJson::decode( $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ) );
			$items = $order->get_items( 'line_item' ); $line = QuoteJson::decode( reset( $items )->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
			self::assertSame( '4', $package['snapshot_version'] ); self::assertSame( '4', $line['snapshot_version'] );
			self::assertSame( '3', $order->get_meta( DeliveryQuoteSnapshotEnvelope::META_FORMAT, true ) );
			self::assertSame( 3, $package['delivery_quote']['format'] ); self::assertSame( $package['delivery_quote'], $line['delivery_quote'] );
			self::assertSame( '9.500000', $package['package_total_delivery_amount'] );
			self::assertSame( $stage->context_digest(), $package['delivery_quote']['context_digest'] );
			self::assertTrue( QuoteNativeOrderHistory::verify( $stage->fresh_order() ) ); self::assertGreaterThan( 0, $native_calls );
			self::assertSame( 'prepared', $model['acknowledged']->binding->state() ); self::assertSame( 1, $model['acknowledged']->binding->revision() );
			self::assertNull( $model['acknowledged']->binding->row()['sealed_at'] ); self::assertArrayNotHasKey( 'final_event', $package['delivery_quote'] );
		}
	}
	public function test_original_prepared_retry_completes_missing_packets_without_changing_the_original_context(): void {
		$model = $this->fixture(); $order = $model['order']; $first = $this->stage( $model );
		$package = $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ); $items = $order->get_items( 'line_item' ); $line = reset( $items ); $line_packet = $line->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true );
		$order->delete_meta_data( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT ); $order->delete_meta_data( QuoteNativeOrderFacts::META_REFERENCE ); $order->save();
		$again = $this->stage( $model );
		self::assertSame( $package, $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ) ); self::assertSame( $line_packet, $line->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
		self::assertSame( $first->snapshot_digest(), $again->snapshot_digest() ); self::assertSame( $first->context_digest(), $again->context_digest() );
	}
	public function test_read_only_saved_promotion_inspection_reuses_exact_packets_and_has_no_new_writer_authority(): void {
		$model = $this->fixture(); $order = $model['order']; $first = $this->stage( $model ); $packet = $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true );
		$GLOBALS['cetech_de_n17_native_identity_key'] = 'rotated-private-native-history-fixture-key';
		$saved = $model['stager']->saved_promotion_guard( $order, $model['fixture']['quote'], $model['acknowledged']->binding, static fn( \WC_Order $candidate ): bool => $candidate === $order );
		self::assertSame( $packet, $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ) ); self::assertSame( $first->snapshot_digest(), $saved->snapshot_digest() ); self::assertSame( $first->context_digest(), $saved->context_digest() );
		self::assertTrue( QuoteNativeOrderHistory::verify( $saved->fresh_order() ) );
	}
	public function test_promotion_readback_rejects_a_new_prefix_even_when_copied_rows_match(): void {
		$model = $this->fixture(); $factory = $model['factory']; $readback_open = $factory->opens + 2;
		$factory->on_open = static function( object $session, object $source ) use ( $readback_open ): void { if ( $source->opens === $readback_open ) { $source->prefix = 'other_'; } };
		try { $this->stage( $model ); self::fail( 'A different physical store was accepted.' ); } catch ( \UnexpectedValueException ) {}
		self::assertTrue( QuoteNativeOrderHistory::owned( $model['order'] ) ); self::assertTrue( $factory->sessions[array_key_last( $factory->sessions )]->retired );
		self::assertGreaterThanOrEqual( 3, $factory->rollbacks ); self::assertSame( 0, $factory->queries ); self::assertSame( 0, $factory->commits );
	}
	public function test_promotion_readback_pins_session_metadata_through_read_and_termination(): void {
		foreach ( [ 'on_read', 'on_rollback', 'on_retire' ] as $phase ) {
			$model = $this->fixture(); $this->stage( $model ); $factory = $model['factory']; $before = $factory->rollbacks;
			$factory->$phase = static function( mixed ...$arguments ) use ( $factory ): void { $factory->charset = 'DEFAULT CHARACTER SET latin1'; };
			try { $model['stager']->saved_promotion_guard( $model['order'], $model['fixture']['quote'], $model['acknowledged']->binding, static fn(): bool => true ); self::fail( 'Changed physical metadata was accepted.' ); } catch ( \UnexpectedValueException ) {}
			self::assertTrue( $factory->sessions[array_key_last( $factory->sessions )]->retired ); self::assertGreaterThan( $before, $factory->rollbacks );
			self::assertSame( 0, $factory->queries ); self::assertSame( 0, $factory->commits );
		}
	}

	public function test_promotion_readback_requires_actual_rollback_and_retirement_state(): void {
		foreach ( [ 'rollback', 'retire' ] as $fault ) {
			$model = $this->fixture(); $source = $model['factory'];
			$factory = new class( $source, $fault ) implements \CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory {
				private int $opens = 0;
				public function __construct( private object $source, private string $fault ) {}
				public function open(): \CetechDeliveryEngine\Domain\Operation\OperationSession {
					$session = $this->source->open(); if ( 2 !== ++$this->opens ) { return $session; }
					return new class( $this->source, $this->fault ) extends \CetechDeliveryEngine\Tests\Support\DeliveryPromotion\PromotionNativeUnitSession {
						public function __construct( object $factory, private string $fault ) { parent::__construct( $factory ); }
						public function rollback(): bool { if ( 'rollback' === $this->fault ) { ++$this->factory->rollbacks; return true; } return parent::rollback(); }
						public function retire(): bool { if ( 'retire' === $this->fault ) { ++$this->factory->retirements; return true; } return parent::retire(); }
					};
				}
			};
			$model['stager'] = new QuoteNativeOrderStager( $factory, static fn(): \WC_Order => clone $model['order'] );
			try { $this->stage( $model ); self::fail( 'A false termination acknowledgement was accepted.' ); } catch ( \UnexpectedValueException ) {}
			self::assertTrue( QuoteNativeOrderHistory::owned( $model['order'] ) ); self::assertGreaterThanOrEqual( 3, $source->rollbacks ); self::assertGreaterThanOrEqual( 3, $source->retirements );
		}
	}

	public function test_promotion_requires_original_selected_hashes_and_customer_endpoints_before_writes(): void {
		foreach ( [ 'selection_hash', 'rule_id', 'configuration_fingerprint', 'line_destination', 'customer_destination' ] as $change ) {
			$model = $this->fixture(); $facts = $model['fixture']['draft']->private_facts();
			if ( 'selection_hash' === $change ) { $facts['lines'][0]['selection_hash'] = str_repeat( 'f', 64 ); }
			elseif ( 'rule_id' === $change ) { $facts['lines'][0]['selection']['rule_id'] = 99; }
			elseif ( 'configuration_fingerprint' === $change ) { $facts['lines'][0]['selection']['configuration_fingerprint'] = str_repeat( 'c', 64 ); }
			elseif ( 'customer_destination' === $change ) { $facts['customer_destination']['address'] = 'OTHER-PRIVATE-NATIVE-ADDRESS'; }
			else { $address = $facts['customer_destination']; $address['address'] = 'OTHER-PRIVATE-NATIVE-ADDRESS'; $facts['lines'][0]['customer_context'] = \CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext::delivery( 20, address: \CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress::fromInput( $address ) )->toArray(); unset( $facts['lines'][0]['customer_context']['recipient'], $facts['lines'][0]['customer_context']['delivery_address']['recipient'] ); }
			$draft = \CetechDeliveryEngine\Application\DeliveryQuote\QuoteCartDraft::from_private_facts( $model['fixture']['owner'], $facts, $model['fixture']['identity'] ); $opens = $model['factory']->opens;
			try { $model['stager']->stage_promotion( $model['order'], $model['acknowledged'], $draft, $model['fixture']['tax_source'], 'classic', static fn(): bool => true ); self::fail( 'A different selected draft was accepted.' ); } catch ( \UnexpectedValueException ) {}
			self::assertSame( 0, $model['order']->saves ); self::assertSame( 0, $model['order']->lines[0]->saves ); self::assertSame( $opens, $model['factory']->opens ); self::assertFalse( QuoteNativeOrderHistory::owned( $model['order'] ) );
		}
	}

}
