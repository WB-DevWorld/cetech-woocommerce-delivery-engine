<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteAcceptInputs, FixedPromotionQuoteBindCommand, FixedPromotionQuoteBindInputs, FixedPromotionQuoteIssueCommand, FixedPromotionQuoteOrderEvidenceGuard, QuoteDurableCommand, QuoteIssueCommand};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteContext, QuoteHeader, QuoteId, QuoteJson, QuoteReference, QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteStorageFixtures};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionCurrentEvidenceGuardTest.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';

/** Pure command/capture contracts only; receipt and current-order authority are separate proofs. */
final class FixedPromotionQuoteBindCommandTest extends TestCase {

	public function test_binding_uses_existing_v1_namespace_with_a_distinct_fully_bound_intent(): void {
		[ $acceptance, $quote, $mapping, $placement ] = PromotionBindContractFixture::facts();
		$before = [ $quote->row(), $acceptance->to_recovery_array() ];
		$c = FixedPromotionQuoteBindCommand::create( $acceptance, $quote, 301, $placement, $mapping, Q::digest( 'owned native candidate' ) );
		$generic = QuoteDurableCommand::bind( $c->owner(), $c->reference(), $c->header(), $c->binding(), $c->context() );
		self::assertSame( 'delivery_quote.bind', $c->identity->operation ); self::assertSame( 1, $c->identity->operation_version ); self::assertSame( 'placement:' . $placement->value(), $c->identity->target_key );
		self::assertSame( $generic->identity->namespace_digest(), $c->identity->namespace_digest() ); self::assertFalse( $generic->intent()->equals( $c->intent() ) );
		self::assertSame( $c->identity->namespace_digest(), $c->binding()->row()['bind_namespace_hash'] ); self::assertSame( QuoteDurableCommand::binding_namespaces( $c->owner(), $c->header(), $placement->value(), true )['seal'], $c->binding()->row()['seal_namespace_hash'] );
		self::assertSame( $acceptance, $c->acceptance() ); self::assertSame( $quote, $c->quote_record() ); self::assertSame( $acceptance->context(), $c->context() ); self::assertSame( $acceptance->terms(), $c->terms() ); self::assertSame( $acceptance->reference(), $c->reference() );
		self::assertSame( $acceptance->allocation_set()->account_slots(), $c->allocation_set()->account_slots() ); self::assertSame( $acceptance->account_slots(), $c->account_slots() ); self::assertSame( $acceptance->allocation_digest(), $c->allocation_digest() ); self::assertSame( $acceptance->hold_until(), $c->hold_until() );
		self::assertSame( [ 'prepared', 1, 1, $quote->accepted_at()->sql() ], [ $c->binding()->state(), $c->binding()->revision(), $c->binding()->id(), $c->binding()->row()['created_at'] ] );
		foreach ( [ 'snapshot_digest', 'context_digest', 'verified_at', 'sealed_at' ] as $field ) { self::assertNull( $c->binding()->row()[$field] ); }
		self::assertSame( $before, [ $quote->row(), $acceptance->to_recovery_array() ] ); self::assertNull( $c->acceptance()->issue()->lease() );
	}

	public function test_equivalent_mapping_order_and_quantity_spelling_produce_identical_command(): void {
		[ $acceptance, $quote, $mapping, $placement ] = PromotionBindContractFixture::facts(); $digest = Q::digest( 'owned native candidate' );
		$first = FixedPromotionQuoteBindCommand::create( $acceptance, $quote, 301, $placement, $mapping, $digest );
		$mapping['groups'] = array_reverse( $mapping['groups'] ); foreach ( $mapping['groups'] as &$group ) { $group['lines'] = array_reverse( $group['lines'] ); foreach ( $group['lines'] as &$line ) { $line['quantity'] .= '.000'; } unset( $line ); } unset( $group );
		$second = FixedPromotionQuoteBindCommand::create( $acceptance, $quote, 301, $placement, $mapping, $digest );
		self::assertSame( $first->to_recovery_array(), $second->to_recovery_array() ); self::assertSame( $first->intent()->fingerprint(), $second->intent()->fingerprint() );
	}

	public static function semantic_changes(): array { return array_map( static fn( string $kind ): array => [ $kind ], [ 'order', 'placement', 'item', 'order evidence', 'accepted time', 'account', 'hold deadline' ] ); }
	#[DataProvider( 'semantic_changes' )]
	public function test_each_original_semantic_fact_is_bound_into_the_intent( string $kind ): void {
		[ $acceptance, $quote, $mapping, $placement ] = PromotionBindContractFixture::facts(); $digest = Q::digest( 'owned native candidate' );
		$before = FixedPromotionQuoteBindCommand::create( $acceptance, $quote, 301, $placement, $mapping, $digest ); $order = 301;
		if ( 'order' === $kind ) { $order = 302; }
		elseif ( 'placement' === $kind ) { $placement = QuoteId::generate(); }
		elseif ( 'item' === $kind ) { $mapping['groups'][0]['lines'][0]['item_id'] += 1000; }
		elseif ( 'order evidence' === $kind ) { $digest = Q::digest( 'a different owned candidate capture' ); }
		elseif ( 'accepted time' === $kind ) { $at = $quote->accepted_at()->plus_seconds( 1 )->sql(); $quote = QuoteStoredRow::from_row( array_replace( $quote->row(), [ 'accepted_at' => $at, 'transition_at' => $at ] ) ); }
		else {
			$packet = $acceptance->to_recovery_array();
			if ( 'account' === $kind ) { foreach ( $packet['issue']['original_issue']['account_slots'] as &$slot ) { if ( null !== $slot['account_key'] ) { $slot['account_key'] = Q::digest( 'a different explicit owned pool' ); } } unset( $slot ); }
			else { $packet['issue']['original_issue']['hold_until'] = $acceptance->hold_until()->plus_seconds( -1 )->sql(); }
			$acceptance = FixedPromotionQuoteAcceptCommand::from_recovery_array( $packet );
		}
		$after = FixedPromotionQuoteBindCommand::create( $acceptance, $quote, $order, $placement, $mapping, $digest );
		self::assertFalse( $before->intent()->equals( $after->intent() ) );
		if ( 'placement' !== $kind ) { self::assertSame( $before->identity->namespace_digest(), $after->identity->namespace_digest() ); }
	}

	public function test_physical_quote_row_identity_is_not_a_new_semantic_operation(): void {
		[ $acceptance, $quote, $mapping, $placement ] = PromotionBindContractFixture::facts(); $digest = Q::digest( 'owned native candidate' );
		$a = FixedPromotionQuoteBindCommand::create( $acceptance, $quote, 301, $placement, $mapping, $digest ); $other = QuoteStoredRow::from_row( array_replace( $quote->row(), [ 'id' => 99 ] ) );
		$b = FixedPromotionQuoteBindCommand::create( $acceptance, $other, 301, $placement, $mapping, $digest ); self::assertSame( $a->intent()->fingerprint(), $b->intent()->fingerprint() ); self::assertSame( 99, $b->quote_record()->id() );
	}

	public static function invalid_candidates(): array { return array_map( static fn( string $kind ): array => [ $kind ], [ 'issued', 'foreign quote', 'legacy', 'accepted at hold', 'missing group', 'foreign component', 'foreign product', 'changed quantity', 'duplicate item', 'extra line field', 'order zero', 'bad digest' ] ); }
	#[DataProvider( 'invalid_candidates' )]
	public function test_only_exact_accepted_three_and_complete_owned_candidate_mapping_can_be_captured( string $kind ): void {
		[ $acceptance, $quote, $mapping, $placement ] = PromotionBindContractFixture::facts(); $order = 301; $digest = Q::digest( 'owned native candidate' );
		if ( 'issued' === $kind ) { $quote = QuoteStoredRow::from_row( array_replace( $quote->row(), [ 'state' => 'issued', 'revision' => 1, 'accepted_at' => null, 'transition_at' => null ] ) ); }
		elseif ( 'foreign quote' === $kind ) { [ , $quote ] = PromotionBindContractFixture::facts(); }
		elseif ( 'legacy' === $kind ) { $quote = QuoteStorageFixtures::quote( state: 'accepted' ); }
		elseif ( 'accepted at hold' === $kind ) { $at = $acceptance->hold_until()->sql(); $quote = QuoteStoredRow::from_row( array_replace( $quote->row(), [ 'accepted_at' => $at, 'transition_at' => $at ] ) ); }
		elseif ( 'missing group' === $kind ) { array_pop( $mapping['groups'] ); }
		elseif ( 'foreign component' === $kind ) { $mapping['groups'][0]['component_key'] = Q::digest( 'foreign component' ); }
		elseif ( 'foreign product' === $kind ) { ++$mapping['groups'][0]['lines'][0]['product_id']; }
		elseif ( 'changed quantity' === $kind ) { $mapping['groups'][0]['lines'][0]['quantity'] = '9'; }
		elseif ( 'duplicate item' === $kind ) { $mapping['groups'][1]['lines'][0]['item_id'] = $mapping['groups'][0]['lines'][0]['item_id']; }
		elseif ( 'extra line field' === $kind ) { $mapping['groups'][0]['lines'][0]['price'] = '0.00'; }
		elseif ( 'order zero' === $kind ) { $order = 0; }
		else { $digest = str_repeat( 'A', 64 ); }
		$this->expectException( \InvalidArgumentException::class ); FixedPromotionQuoteBindCommand::create( $acceptance, $quote, $order, $placement, $mapping, $digest );
	}

	public function test_closed_recovery_recreates_the_full_original_command_without_a_live_capability(): void {
		$c = PromotionBindContractFixture::command(); $packet = $c->to_recovery_array(); $copy = FixedPromotionQuoteBindCommand::from_recovery_array( json_decode( json_encode( $packet, JSON_THROW_ON_ERROR ), true, 32, JSON_THROW_ON_ERROR ) );
		self::assertSame( $c, $c->as_recovery() ); self::assertSame( $packet, $copy->to_recovery_array() ); self::assertSame( $c->intent()->fingerprint(), $copy->intent()->fingerprint() ); self::assertSame( $c->identity->namespace_digest(), $copy->identity->namespace_digest() );
		self::assertSame( $c->reference()->public_fields(), $copy->reference()->public_fields() ); self::assertSame( $c->quote_record()->row(), $copy->quote_record()->row() ); self::assertSame( $c->binding()->row(), $copy->binding()->row() ); self::assertNull( $copy->acceptance()->issue()->lease() );
	}

	public static function invalid_recovery(): array { return array_map( static fn( string $kind ): array => [ $kind ], [ 'extra', 'missing', 'version', 'acceptance', 'binding id', 'created time', 'verification', 'native money', 'seal v1', 'oversized', 'oversized key', 'object', 'depth', 'float', 'nodes', 'cycle' ] ); }
	#[DataProvider( 'invalid_recovery' )]
	public function test_recovery_refuses_noncanonical_or_unbounded_packets_before_calling_objects( string $kind ): void {
		$c = PromotionBindContractFixture::command(); $data = $c->to_recovery_array(); $probe = new PromotionBindRecoveryProbe();
		if ( 'extra' === $kind ) { $data['ports'] = []; }
		elseif ( 'missing' === $kind ) { unset( $data['order_evidence_digest'] ); }
		elseif ( 'version' === $kind ) { $data['format_version'] = '1'; }
		elseif ( 'acceptance' === $kind ) { $data['acceptance'] = null; }
		elseif ( 'binding id' === $kind ) { $data['binding']['id'] = 2; }
		elseif ( 'created time' === $kind ) { $data['binding']['created_at'] = $c->quote_record()->accepted_at()->plus_seconds( 1 )->sql(); }
		elseif ( 'verification' === $kind ) { $data['binding']['revision'] = 2; $data['binding']['snapshot_digest'] = Q::digest( 'snapshot' ); $data['binding']['context_digest'] = Q::digest( 'saved context' ); $data['binding']['verified_at'] = $c->quote_record()->accepted_at()->sql(); }
		elseif ( 'native money' === $kind ) { $data['binding']['native_money_digest'] = Q::digest( 'other native money' ); }
		elseif ( 'seal v1' === $kind ) { $data['binding']['seal_namespace_hash'] = QuoteDurableCommand::binding_namespaces( $c->owner(), $c->header(), $data['binding']['placement_uuid'] )['seal']; }
		elseif ( 'oversized' === $kind ) { $data['accepted_quote']['private_body_json'] = str_repeat( 'x', FixedPromotionQuoteBindCommand::MAX_RECOVERY_BYTES + 1 ); }
		elseif ( 'oversized key' === $kind ) { $data[str_repeat( 'x', FixedPromotionQuoteBindCommand::MAX_RECOVERY_BYTES + 1 )] = 1; }
		elseif ( 'object' === $kind ) { $data['accepted_quote']['private_body_json'] = $probe; }
		elseif ( 'depth' === $kind ) { $deep = []; for ( $i = 0; $i < 40; ++$i ) { $deep = [ $deep ]; } $data['acceptance'] = $deep; }
		elseif ( 'float' === $kind ) { $data['binding']['order_id'] = 301.0; }
		elseif ( 'nodes' === $kind ) { $data['binding'] = array_fill( 0, 10001, 0 ); }
		else { $cycle = []; $cycle[] =& $cycle; $data['binding'] = $cycle; }
		try { FixedPromotionQuoteBindCommand::from_recovery_array( $data ); self::fail( 'Invalid recovery must refuse.' ); } catch ( \InvalidArgumentException ) { self::assertSame( 0, $probe->calls ); }
	}

	public function test_explicit_recovery_arrays_cannot_mutate_the_immutable_command_through_references(): void {
		$data = PromotionBindContractFixture::command()->to_recovery_array(); $value =& $data['order_evidence_digest']; $original = $value; $c = FixedPromotionQuoteBindCommand::from_recovery_array( $data ); $value = Q::digest( 'changed external reference' );
		self::assertSame( $original, $c->order_evidence_digest() ); $out = $c->to_recovery_array(); $out['binding']['order_id'] = 999; self::assertNotSame( $out, $c->to_recovery_array() );
	}

	public function test_explicit_inputs_keep_fresh_ports_without_executing_them_or_entering_recovery(): void {
		$f = new FixedPromotionCurrentFixture(); $sources = FixedPromotionQuoteAcceptInputs::from_sources( $f->snapshot, $f->native ); $order = new PromotionBindOrderPoisonPort(); $reads = $f->native_source->reads;
		$inputs = FixedPromotionQuoteBindInputs::from_sources( $sources, $order ); self::assertSame( $sources, $inputs->sources() ); self::assertSame( $order, $inputs->order_evidence() ); self::assertSame( 0, $order->calls ); self::assertSame( $reads, $f->native_source->reads ); self::assertSame( [], $f->session->sql );
		$this->assert_private( $inputs, [] ); self::assertSame( 0, $order->calls );
	}

	public function test_command_exports_are_explicit_and_debug_redacts_every_private_fact(): void {
		$c = PromotionBindContractFixture::command(); $this->assert_private( $c, [ $c->reference()->handle(), $c->order_evidence_digest(), 'private_fixed_funder' ] );
	}
	private function assert_private( object $object, array $secrets ): void {
		foreach ( [ static fn() => json_encode( $object, JSON_THROW_ON_ERROR ), static fn() => serialize( $object ), static fn() => $object->__unserialize( [] ) ] as $attempt ) { try { $attempt(); self::fail( 'Private command/ports cannot export or hydrate implicitly.' ); } catch ( \LogicException $error ) { foreach ( $secrets as $secret ) { self::assertStringNotContainsString( $secret, $error->getMessage() ); } } }
		ob_start(); var_dump( $object ); $debug = ob_get_clean(); self::assertStringContainsString( 'redacted', $debug ); foreach ( $secrets as $secret ) { self::assertStringNotContainsString( $secret, $debug ); }
	}
}

/** Structurally accepted synthetic original, without a database, campaign or order-admission claim. */
final class PromotionBindContractFixture {
	public static function facts(): array {
		$p = F::mixed_provider(); $slots = [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'explicit binding pool' ) ], [ 'component_key' => F::second_key(), 'account_key' => null ] ];
		$original = QuoteIssueCommand::fixed_promotion( F::owner(), $p->context(), '71eb41b0-d08d-4a25-9808-6ad03e6aa013', $slots, Q::time()->plus_seconds( 45 ) );
		$id = QuoteId::generate(); $ref = QuoteReference::generate( $id ); $h = QuoteHeader::issue( $id, F::owner(), $p->context(), $p->terms(), Q::time(), $original->namespace_hashes( $id ), 'fixed_promotion_native_v1', 1, $ref );
		$issue = FixedPromotionQuoteIssueCommand::from_recovery_array( [ 'format_version' => 1, 'original_issue' => $original->to_promotion_recovery_array(), 'header_json' => $h->to_private_json(), 'terms_json' => $p->terms()->to_private_json(), 'reference' => $ref->public_fields() ] );
		$c = FixedPromotionQuoteAcceptCommand::from_issue( $issue ); $at = Q::time()->plus_seconds( 1 )->sql();
		$row = QuoteStoredRow::from_row( [ 'id' => 1, 'site_id' => 1, 'quote_uuid' => $h->id()->value(), 'format_version' => 3, 'profile_code' => $h->profile(), 'profile_version' => 1, 'purpose' => 'checkout', 'principal_hash' => $c->owner()->facts()['principal_hash'], 'owner_digest' => $c->owner()->digest(), 'material_digest' => $h->material_digest(), 'body_digest' => $h->body_digest(), 'header_json' => $h->to_private_json(), 'private_body_json' => QuoteJson::encode( [ 'context' => $c->context()->private_facts(), 'terms' => $c->terms()->private_facts() ] ), 'issue_namespace_hash' => $h->namespace_hashes()['issue'], 'accept_namespace_hash' => $h->namespace_hashes()['accept'], 'invalidate_namespace_hash' => $h->namespace_hashes()['invalidate'], 'state' => 'accepted', 'revision' => 2, 'retention_revision' => 1, 'created_at' => $h->created_at()->sql(), 'expires_at' => $h->expires_at()->sql(), 'accepted_at' => $at, 'transition_at' => $at ] );
		return [ $c, $row, self::mapping( $c->context() ), QuoteId::from_string( '71eb41b0-d08d-4a25-9808-6ad03e6aa014' ) ];
	}
	public static function mapping( QuoteContext $context ): array {
		$facts = $context->private_facts(); $members = []; $item = 100; foreach ( $facts['lines'] as $line ) { $members[$line['line_key']] = array_intersect_key( $line, array_flip( [ 'line_key', 'product_id', 'variation_id', 'parent_id', 'quantity' ] ) ) + [ 'item_id' => ++$item ]; }
		$groups = []; foreach ( $facts['groups'] as $group ) { $groups[] = [ 'component_key' => $group['component_key'], 'lines' => array_map( static fn( string $key ): array => $members[$key], $group['line_keys'] ) ]; }
		return [ 'format_version' => 1, 'groups' => $groups ];
	}
	public static function command(): FixedPromotionQuoteBindCommand { [ $a, $q, $m, $p ] = self::facts(); return FixedPromotionQuoteBindCommand::create( $a, $q, 301, $p, $m, Q::digest( 'owned native candidate' ) ); }
}

final class PromotionBindRecoveryProbe implements \JsonSerializable { public int $calls = 0; public function jsonSerialize(): array { ++$this->calls; return []; } }
final class PromotionBindOrderPoisonPort implements FixedPromotionQuoteOrderEvidenceGuard {
	public int $calls = 0;
	public function evidence_digest(): string { ++$this->calls; throw new \LogicException( 'Inputs cannot query an order port.' ); }
	public function tables( OperationSession $session ): array { ++$this->calls; throw new \LogicException( 'Inputs cannot query an order port.' ); }
	public function verify( OperationSession $session, QuoteStoredRow $accepted, QuoteBinding $candidate ): bool { ++$this->calls; throw new \LogicException( 'Inputs cannot verify an order.' ); }
}
