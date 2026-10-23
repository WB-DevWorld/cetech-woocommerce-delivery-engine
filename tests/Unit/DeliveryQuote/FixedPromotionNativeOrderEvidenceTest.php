<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\Order\QuoteNativeOrderFacts;
use CetechDeliveryEngine\Integrations\DeliveryQuote\FixedPromotionNativeOrderEvidence;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionNativeUnitFixture, PromotionNativeUnitMode};
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionNativeUnitFixture.php';

/** Native-shaped models prove adapter contracts; physical Woo acceptance has its own lane. */
final class FixedPromotionNativeOrderEvidenceTest extends TestCase {
	private array $globals_before; private array $get_before; private array $post_before; private bool $mode_before;
	protected function setUp(): void {
		$this->globals_before = []; foreach ( [ 'current_user', 'woocommerce', 'blog_id', 'cetech_de_n17_native_identity_key' ] as $key ) { $this->globals_before[$key] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[$key] ?? null ]; }
		$this->get_before = $_GET; $this->post_before = $_POST; $this->mode_before = PromotionNativeUnitMode::$enabled;
	}
	protected function tearDown(): void {
		foreach ( $this->globals_before as $key => [ $present, $value ] ) { if ( $present ) { $GLOBALS[$key] = $value; } else { unset( $GLOBALS[$key] ); } }
		$_GET = $this->get_before; $_POST = $this->post_before; PromotionNativeUnitMode::$enabled = $this->mode_before;
	}

	public function test_both_storage_modes_capture_and_verify_current_sql_without_native_callbacks_or_ending_the_owner(): void {
		foreach ( [ false, true ] as $hpos ) {
			$m = PromotionNativeUnitFixture::create( $hpos ); self::assertInstanceOf( FixedPromotionNativeOrderEvidence::class, $m['guard'] );
			$f = $m['factory']; self::assertSame( 1, $f->rollbacks ); self::assertSame( 1, $f->retirements );
			$s = $f->open(); $s->begin(); $before = [ $f->rollbacks, $f->retirements, $f->opens, $m['order']->gets ]; $offset = count( $f->sql );
			$m['order']->on_get = static function(): never { throw new \LogicException( 'Native callback during current proof.' ); };
			self::assertTrue( $m['guard']->verify( $s, $m['fixture']['quote'], $m['candidate'] ) );
			self::assertSame( $before, [ $f->rollbacks, $f->retirements, $f->opens, $m['order']->gets ] ); self::assertTrue( $s->in_transaction() ); self::assertFalse( $s->is_retired() );
			foreach ( array_slice( $f->sql, $offset ) as $sql ) { self::assertStringEndsWith( ' FOR UPDATE', $sql ); }
			self::assertSame( 0, $f->commits ); self::assertSame( 0, $f->queries );
		}
	}

	public function test_original_candidate_digest_survives_owned_packet_additions_but_keeps_native_group_and_money(): void {
		foreach ( [ false, true ] as $hpos ) {
			$m = PromotionNativeUnitFixture::create( $hpos ); $o = $m['order']; $f = $m['factory']; $guard = $m['guard'];
			$o->update_meta_data( '_cetech_de_quote_format', '3' ); $o->update_meta_data( QuoteNativeOrderFacts::META_DRAFT, '{"private":"snapshot"}' );
			$o->lines[0]->update_meta_data( QuoteNativeOrderFacts::META_LINE_KEY, 'line_one' ); $o->lines[0]->update_meta_data( '_cetech_de_delivery_line_snapshot', '{"version":"4"}' ); $o->save();
			$again = FixedPromotionNativeOrderEvidence::capture( $f, $o, $m['fixture']['quote'], $m['mapping'], $m['fixture']['tax_source'], static fn(): bool => true );
			self::assertNotNull( $again ); self::assertSame( $guard->evidence_digest(), $again->evidence_digest() );
			$s = $f->open(); $s->begin(); self::assertTrue( $guard->verify( $s, $m['fixture']['quote'], $m['candidate'] ) );
			foreach ( $f->physical['item_meta'] as &$row ) { if ( 'cetech_de_group_id' === $row['meta_key'] ) { $row['meta_value'] .= '|changed'; } } unset( $row );
			self::assertFalse( $guard->verify( $s, $m['fixture']['quote'], $m['candidate'] ) );
		}
	}

	public function test_compiled_final_is_not_discounted_a_second_time_or_replaced_by_native_label(): void {
		foreach ( [ 'amount', 'label', 'quantity' ] as $mutation ) {
			$m = PromotionNativeUnitFixture::create(); $o = $m['order']; $f = $m['factory'];
			if ( 'amount' === $mutation ) { $o->shipping[0]->amount = '6.50'; $o->shipping_total = '6.50'; $o->total = '31.50'; }
			elseif ( 'label' === $mutation ) { $o->shipping[0]->label = 'Unaccepted delivery'; }
			else { $o->lines[0]->quantity = 3; }
			$f->refresh(); $opens = $f->opens;
			self::assertNull( FixedPromotionNativeOrderEvidence::capture( $f, $o, $m['fixture']['quote'], $m['mapping'], $m['fixture']['tax_source'], static fn(): bool => true ) );
			self::assertSame( $opens, $f->opens ); self::assertSame( 0, $o->saves );
		}
	}

	public function test_late_native_money_customer_and_authoritative_storage_changes_refuse_the_original_candidate(): void {
		foreach ( [ false, true ] as $hpos ) { foreach ( [ 'money', 'customer', 'mode' ] as $mutation ) {
			$m = PromotionNativeUnitFixture::create( $hpos ); $f = $m['factory']; $s = $f->open(); $s->begin();
			if ( 'mode' === $mutation ) { $f->mode_rows[0]['option_value'] = $hpos ? 'no' : 'yes'; }
			elseif ( $hpos ) { $f->physical['order'][0]['money' === $mutation ? 'total_amount' : 'customer_id'] = 'money' === $mutation ? '34.51' : '23'; }
			else { foreach ( $f->physical['order_meta'] as &$row ) { if ( ( 'money' === $mutation ? '_order_total' : '_customer_user' ) === $row['meta_key'] ) { $row['meta_value'] = 'money' === $mutation ? '34.51' : '23'; } } unset( $row ); }
			self::assertFalse( $m['guard']->verify( $s, $m['fixture']['quote'], $m['candidate'] ) ); self::assertTrue( $s->active ); self::assertFalse( $s->retired );
		} }
	}

	public function test_paid_timestamp_refund_or_parent_mutations_cannot_hide_behind_an_unchanged_loaded_order(): void {
		foreach ( [ false, true ] as $hpos ) { foreach ( [ 'paid', 'refund', 'parent' ] as $mutation ) {
			$m = PromotionNativeUnitFixture::create( $hpos ); $f = $m['factory']; $s = $f->open(); $s->begin();
			if ( 'paid' === $mutation ) { $f->lifecycle_paid = $hpos ? [ [ 'order_id' => '100', 'date_paid_gmt' => '2026-10-23 12:00:00' ] ] : [ [ 'meta_key' => '_date_paid', 'meta_value' => '1792756800' ] ]; }
			elseif ( 'refund' === $mutation ) { $f->lifecycle_order[0][$hpos ? 'type' : 'post_type'] = 'shop_order_refund'; }
			else { $f->lifecycle_order[0][$hpos ? 'parent_order_id' : 'post_parent'] = '99'; }
			self::assertFalse( $m['guard']->verify( $s, $m['fixture']['quote'], $m['candidate'] ) ); self::assertSame( 'pending', $m['order']->state ); self::assertNull( $m['order']->date_paid );
		} }
	}

	public function test_capture_requires_cleanup_acknowledgements_and_cleans_up_even_when_metadata_throws(): void {
		foreach ( [ 'refuse_rollback', 'refuse_retire', 'metadata' ] as $failure ) {
			$m = PromotionNativeUnitFixture::create(); $f = $m['factory']; $rollbacks = $f->rollbacks; $retires = $f->retirements;
			if ( 'metadata' === $failure ) { $f->on_metadata = static function(): never { throw new \RuntimeException( 'Owner metadata unavailable.' ); }; }
			else { $f->$failure = true; }
			self::assertNull( FixedPromotionNativeOrderEvidence::capture( $f, $m['order'], $m['fixture']['quote'], $m['mapping'], $m['fixture']['tax_source'], static fn(): bool => true ) );
			self::assertGreaterThan( $rollbacks, $f->rollbacks ); self::assertGreaterThan( $retires, $f->retirements ); self::assertSame( 0, $f->commits );
		}
	}

	public function test_native_destination_and_current_server_key_must_match_the_accepted_context_before_sql(): void {
		foreach ( [ 'address', 'key' ] as $mutation ) {
			$m = PromotionNativeUnitFixture::create(); $f = $m['factory']; $o = $m['order']; $opens = $f->opens;
			if ( 'address' === $mutation ) { $o->fixture['destination']['address'] = 'A different physical destination'; $f->refresh(); }
			else { $GLOBALS['cetech_de_n17_native_identity_key'] = 'a-different-private-native-server-key'; }
			self::assertNull( FixedPromotionNativeOrderEvidence::capture( $f, $o, $m['fixture']['quote'], $m['mapping'], $m['fixture']['tax_source'], static fn(): bool => true ) );
			self::assertSame( $opens, $f->opens ); unset( $GLOBALS['cetech_de_n17_native_identity_key'] );
		}
	}

	public function test_initial_physical_shipping_label_and_group_must_equal_the_preloaded_native_facts(): void {
		foreach ( [ 'label', 'group' ] as $mutation ) {
			$m = PromotionNativeUnitFixture::create(); $f = $m['factory'];
			if ( 'label' === $mutation ) { $f->physical['items'][1]['order_item_name'] = 'Changed persisted delivery'; }
			else { foreach ( $f->physical['item_meta'] as &$row ) { if ( 'cetech_de_group_id' === $row['meta_key'] ) { $row['meta_value'] .= '|changed'; } } unset( $row ); }
			self::assertNull( FixedPromotionNativeOrderEvidence::capture( $f, $m['order'], $m['fixture']['quote'], $m['mapping'], $m['fixture']['tax_source'], static fn(): bool => true ) );
		}
	}
}
