<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryCommercial;

use CetechDeliveryEngine\Domain\DeliveryCommercial\{QuoteCommercialEvidencePacket, QuoteCommercialEvidenceReadResult};
use CetechDeliveryEngine\Domain\DeliveryCurrency\CurrencyConversionReceipt;
use CetechDeliveryEngine\Domain\DeliveryEconomics\{EconomicAmountFact, QuoteEconomicsFacts};
use CetechDeliveryEngine\Domain\DeliveryPromotion\{DeliveryPromotionPlan, FixedDeliveryPromotion};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteJson, QuoteMoney, QuoteTerms};
use CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseHistoricalPacket;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff\PromiseHandoffFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/ServicePromise/Handoff/PromiseHandoffFixture.php';

/** Synthetic private-history proof, without a native writer, gateway or live adapter. */
final class QuoteCommercialEvidencePacketTest extends TestCase {
	public function test_export_and_file_restore_preserve_full_private_history_without_provider_calls(): void {
		$packet = self::packet(); $digest = $packet->digest(); $json = $packet->to_private_json();
		$path = tempnam( sys_get_temp_dir(), 'cetech-commercial-packet-' );
		self::assertIsString( $path );
		try {
			self::assertSame( strlen( $json ), file_put_contents( $path, $json ) );
			$read = QuoteCommercialEvidenceReadResult::read( (string) file_get_contents( $path ), $digest );
			self::assertSame( 'readable', $read->status() ); self::assertNotNull( $read->packet() );
			self::assertSame( $json, $read->packet()->to_private_json() ); self::assertSame( $digest, $read->packet()->digest() );
			$facts = $read->packet()->private_facts();
			self::assertSame( 'planning_only', $facts['scope'] ); self::assertSame( '12.50', $facts['terms']['groups'][0]['final']['amount'] );
			self::assertSame( '3.00', $facts['economics']['groups'][0]['estimated_internal_cost']['money']['amount'] );
			self::assertSame( '9.50', $facts['groups'][0]['promotion']['plan']['money']['final']['amount'] );
			self::assertSame( '6.17', $facts['groups'][0]['currency']['receipt']['conversion']['target']['amount'] );
			self::assertSame( '0.65000000000000000001', $facts['groups'][0]['currency']['receipt']['conversion']['applied_rate'] );
		} finally { unlink( $path ); }
	}

	public function test_explicit_quote_final_basis_does_not_silently_apply_a_recorded_promotion(): void {
		$packet = self::packet( basis: 'quote_final' );
		$facts = $packet->private_facts();
		self::assertSame( '9.50', $facts['groups'][0]['promotion']['plan']['money']['final']['amount'] );
		self::assertSame( '12.50', $facts['groups'][0]['currency']['receipt']['source']['amount'] );
		self::assertSame( '8.12', $facts['groups'][0]['currency']['receipt']['conversion']['target']['amount'] );
		self::assertSame( $packet->digest(), QuoteCommercialEvidenceReadResult::read( $packet->to_private_json() )->packet()->digest() );
	}

	public function test_unrecorded_slots_and_unavailable_conversion_never_gain_money(): void {
		$unrecorded = self::packet( recorded: false ); $facts = $unrecorded->private_facts();
		self::assertSame( [ 'state' => 'not_recorded' ], $facts['groups'][0]['promotion'] );
		self::assertSame( [ 'state' => 'not_recorded' ], $facts['groups'][0]['currency'] );
		$packet = self::packet( unavailable: true );
		$read = QuoteCommercialEvidenceReadResult::read( $packet->to_private_json(), $packet->digest() );
		self::assertSame( 'readable', $read->status() );
		$receipt = CurrencyConversionReceipt::from_array( $read->packet()->private_facts()['groups'][0]['currency']['receipt'] );
		self::assertFalse( $receipt->converted() ); self::assertNull( $receipt->target_money() ); self::assertSame( 'conversion_unavailable', $receipt->reason_code() );
	}

	public function test_retained_digest_detects_coherent_replacement_that_self_checks_cannot_authenticate(): void {
		$packet = self::packet(); $data = $packet->private_facts();
		$data['groups'][0]['currency']['receipt']['conversion']['target']['amount'] = '6.18';
		$data['groups'][0]['currency']['receipt']['conversion']['evidence_digest'] = QuoteFixtures::digest( 'coherently replaced provider result' );
		$json = QuoteJson::encode( $data );
		self::assertSame( 'readable', QuoteCommercialEvidenceReadResult::read( $json )->status() );
		$pinned = QuoteCommercialEvidenceReadResult::read( $json, $packet->digest() );
		self::assertSame( 'malformed', $pinned->status() ); self::assertNull( $pinned->packet() );
	}

	#[DataProvider( 'future_versions' )]
	public function test_unknown_future_versions_are_opaque_and_never_partially_decoded( string $section ): void {
		$data = self::packet()->private_facts();
		switch ( $section ) {
			case 'packet': $data['format_version'] = 2; break;
			case 'header': $data['header']['format_version'] = 3; break;
			case 'context': $data['context']['format_version'] = 3; break;
			case 'terms': $data['terms']['format_version'] = 3; break;
			case 'economics': $data['economics']['format_version'] = 2; break;
			case 'rule': $data['groups'][0]['promotion']['rule']['format_version'] = 2; break;
			case 'plan': $data['groups'][0]['promotion']['plan']['format_version'] = 2; break;
			case 'promotion_evidence': $data['groups'][0]['promotion']['plan']['evidence']['format_version'] = 2; break;
			case 'currency': $data['groups'][0]['currency']['receipt']['format_version'] = 2; break;
			case 'currency_context': $data['groups'][0]['currency']['receipt']['context']['format_version'] = 2; break;
			case 'conversion': $data['groups'][0]['currency']['receipt']['conversion']['format_version'] = 2; break;
		}
		$read = QuoteCommercialEvidenceReadResult::read( QuoteJson::encode( $data ) );
		self::assertSame( 'unsupported', $read->status() ); self::assertNull( $read->packet() );
	}
	public static function future_versions(): array { return array_map( static fn( string $s ): array => [ $s ], [ 'packet', 'header', 'context', 'terms', 'economics', 'rule', 'plan', 'promotion_evidence', 'currency', 'currency_context', 'conversion' ] ); }

	#[DataProvider( 'malformed_packets' )]
	public function test_known_format_corruption_and_foreign_bindings_refuse_without_partial_history( string $change ): void {
		$data = self::packet()->private_facts();
		switch ( $change ) {
			case 'coerced_format': $data['format_version'] = '1'; break;
			case 'zero_format': $data['format_version'] = 0; break;
			case 'extra': $data['accepted'] = true; break;
			case 'scope': $data['scope'] = 'accepted'; break;
			case 'header': $data['header']['body_digest'] = QuoteFixtures::digest( 'foreign body' ); break;
			case 'economics': $data['economics']['terms_digest'] = QuoteFixtures::digest( 'foreign terms' ); break;
			case 'missing_slot': unset( $data['groups'][0]['promotion'] ); break;
			case 'duplicate': $data['groups'][] = $data['groups'][0]; break;
			case 'foreign_component': $data['groups'][0]['component_key'] = QuoteFixtures::digest( 'foreign group' ); break;
			case 'missing_components': $data['groups'] = []; break;
			case 'plan_money': $data['groups'][0]['promotion']['plan']['money']['final']['amount'] = '9.51'; break;
			case 'funding': $data['groups'][0]['promotion']['plan']['funding_source']['code'] = 'replacement_funder'; break;
			case 'currency_source': $data['groups'][0]['currency']['receipt']['source']['amount'] = '12.50'; break;
			case 'currency_material': $data['groups'][0]['currency']['receipt']['context']['material_digest'] = QuoteFixtures::digest( 'foreign conversion material' ); break;
			case 'changed_basis': $data['groups'][0]['currency']['basis'] = 'quote_final'; break;
			case 'missing_plan': $data['groups'][0]['promotion'] = [ 'state' => 'not_recorded' ]; break;
		}
		$read = QuoteCommercialEvidenceReadResult::read( QuoteJson::encode( $data ) );
		self::assertSame( 'malformed', $read->status() ); self::assertNull( $read->packet() );
	}
	public static function malformed_packets(): array { return array_map( static fn( string $s ): array => [ $s ], [ 'coerced_format', 'zero_format', 'extra', 'scope', 'header', 'economics', 'missing_slot', 'duplicate', 'foreign_component', 'missing_components', 'plan_money', 'funding', 'currency_source', 'currency_material', 'changed_basis', 'missing_plan' ] ); }

	public function test_duplicate_json_and_byte_depth_node_budgets_are_checked_before_recognition(): void {
		$json = self::packet()->to_private_json();
		$duplicate = '{"format_version":1,' . substr( $json, 1 );
		$oversized = json_encode( [ 'format_version' => 2, 'padding' => str_repeat( 'x', 65536 ) ], JSON_THROW_ON_ERROR );
		$deep = 'x'; for ( $i = 0; $i < 20; ++$i ) { $deep = [ 'nested' => $deep ]; }
		$many = json_encode( [ 'format_version' => 2, 'nodes' => array_fill( 0, 4096, 1 ) ], JSON_THROW_ON_ERROR );
		foreach ( [ $duplicate, $oversized, json_encode( [ 'format_version' => 2, 'deep' => $deep ], JSON_THROW_ON_ERROR ), $many ] as $bad ) {
			$read = QuoteCommercialEvidenceReadResult::read( $bad ); self::assertSame( 'malformed', $read->status() ); self::assertNull( $read->packet() );
		}
	}

	public function test_full_v2_promise_bytes_are_preserved_and_changed_promise_cannot_reuse_economics(): void {
		$packet = self::packet( promise: true, recorded: false );
		$read = QuoteCommercialEvidenceReadResult::read( $packet->to_private_json(), $packet->digest() );
		self::assertSame( 'readable', $read->status() ); self::assertSame( $packet->to_private_json(), $read->packet()->to_private_json() );
		$data = $packet->private_facts();
		$changed = PromiseHandoffFixture::terms( PromiseHistoricalPacket::capture( PromiseHandoffFixture::result(), 'Changed retained promise explanation' ) );
		self::assertSame( $data['terms']['groups'], $changed->private_facts()['groups'] );
		$context = QuoteContext::from_array( $data['context'] ); $header = PromiseHandoffFixture::header( $context, $changed );
		$data['terms'] = $changed->private_facts(); $data['header'] = $header->private_facts();
		$refused = QuoteCommercialEvidenceReadResult::read( QuoteJson::encode( $data ) );
		self::assertSame( 'malformed', $refused->status() ); self::assertNull( $refused->packet() );
	}

	public function test_conversion_identity_binds_quote_component_basis_and_full_plan(): void {
		[ $header, $context, $terms ] = self::body(); $key = $terms->private_facts()['groups'][0]['component_key'];
		$rule = self::rule(); $plan = self::plan( $terms, $key, $rule );
		$original = QuoteCommercialEvidencePacket::conversion_material_digest( $header, $context, $terms, $key, 'quote_final' );
		$planned = QuoteCommercialEvidencePacket::conversion_material_digest( $header, $context, $terms, $key, 'planned_final', $plan );
		self::assertNotSame( $original, $planned );
		[ $other_header ] = self::body();
		self::assertNotSame( $original, QuoteCommercialEvidencePacket::conversion_material_digest( $other_header, $context, $terms, $key, 'quote_final' ) );
		$other_rule = self::rule( '4.00' ); $other_plan = self::plan( $terms, $key, $other_rule );
		self::assertNotSame( $planned, QuoteCommercialEvidencePacket::conversion_material_digest( $header, $context, $terms, $key, 'planned_final', $other_plan ) );
	}

	public function test_generic_exports_refuse_and_debug_never_exposes_private_history(): void {
		$packet = self::packet(); $read = QuoteCommercialEvidenceReadResult::read( $packet->to_private_json() );
		foreach ( [ $packet, $read ] as $object ) {
			ob_start(); var_dump( $object ); $debug = (string) ob_get_clean();
			self::assertStringContainsString( 'redacted', $debug );
			foreach ( [ 'private_funder', 'private_cost_source', '6.17', 'request_digest', 'provider_context_digest' ] as $private ) { self::assertStringNotContainsString( $private, $debug ); }
			foreach ( [ static fn() => json_encode( $object, JSON_THROW_ON_ERROR ), static fn() => serialize( $object ) ] as $export ) {
				try { $export(); self::fail( 'Private commercial evidence escaped through generic export.' ); } catch ( \LogicException ) { self::assertTrue( true ); }
			}
		}
	}

	public function test_captured_factory_neither_mutates_caller_references_nor_retains_them(): void {
		[ $header, $context, $terms ] = self::body(); $key = $terms->private_facts()['groups'][0]['component_key'];
		$rule = self::rule(); $plan = self::plan( $terms, $key, $rule );
		$conversion_context = [ 'format_version' => 1, 'material_digest' => QuoteCommercialEvidencePacket::conversion_material_digest( $header, $context, $terms, $key, 'planned_final', $plan ), 'base' => 'GHS', 'presentment' => 'USD', 'charged' => 'USD', 'target_precision' => 2, 'provider' => [ 'code' => 'private_currency_provider', 'version' => 2 ], 'provider_context_digest' => QuoteFixtures::digest( 'provider state' ) ];
		$source = QuoteMoney::from_array( $plan->private_facts()['money']['final'] );
		$receipt = CurrencyConversionReceipt::unavailable( $source, $conversion_context, 'provider_unavailable' );
		$original_rule = $rule; $original_plan = $plan; $original_receipt = $receipt;
		$promotion = [ 'state' => 'recorded', 'rule' => &$rule, 'plan' => &$plan ];
		$currency = [ 'state' => 'recorded', 'basis' => 'planned_final', 'receipt' => &$receipt ];
		$row = [ 'component_key' => $key, 'promotion' => &$promotion, 'currency' => &$currency ]; $groups = [ &$row ];
		$packet = QuoteCommercialEvidencePacket::from_captured( $header, $context, $terms, self::economics( $terms ), $groups );
		self::assertSame( $original_rule, $rule ); self::assertSame( $original_plan, $plan ); self::assertSame( $original_receipt, $receipt );
		self::assertSame( $original_rule, $groups[0]['promotion']['rule'] ); self::assertSame( $original_plan, $groups[0]['promotion']['plan'] ); self::assertSame( $original_receipt, $groups[0]['currency']['receipt'] );
		$before = $packet->to_private_json();
		$rule = self::rule( '4.00' ); $plan = self::plan( $terms, $key, $rule ); $receipt = null; $row['component_key'] = QuoteFixtures::digest( 'replaced group' );
		self::assertSame( $before, $packet->to_private_json() );
	}

	public function test_same_price_components_roundtrip_canonically_but_cannot_swap_conversion_receipts(): void {
		$context_data = QuoteFixtures::context()->private_facts(); $terms_data = QuoteFixtures::terms()->private_facts();
		$other_key = QuoteFixtures::digest( 'second same-price component' );
		$line = $context_data['lines'][0]; $line['line_key'] = 'line_two'; $line['component_key'] = $other_key;
		$group = $context_data['groups'][0]; $group['component_key'] = $other_key; $group['line_keys'] = [ 'line_two' ];
		$context_data['lines'][] = $line; $context_data['groups'][] = $group;
		$term = $terms_data['groups'][0]; $term['component_key'] = $other_key; $terms_data['groups'][] = $term;
		$context = QuoteContext::from_array( $context_data ); $terms = QuoteTerms::from_array( $terms_data );
		$header = QuoteFixtures::issue( context: $context, terms: $terms )->header(); $rows = [];
		foreach ( $terms->private_facts()['groups'] as $term ) {
			$key = $term['component_key']; $source = QuoteMoney::from_array( $term['final'] );
			$conversion_context = [ 'format_version' => 1, 'material_digest' => QuoteCommercialEvidencePacket::conversion_material_digest( $header, $context, $terms, $key, 'quote_final' ), 'base' => 'GHS', 'presentment' => 'USD', 'charged' => 'USD', 'target_precision' => 2, 'provider' => [ 'code' => 'private_currency_provider', 'version' => 2 ], 'provider_context_digest' => QuoteFixtures::digest( 'same provider state' ) ];
			$receipt = CurrencyConversionReceipt::from_provider( $source, $conversion_context, 'private_currency_provider', 2, [ 'format_version' => 1, 'state' => 'converted', 'provider' => $conversion_context['provider'], 'request_digest' => CurrencyConversionReceipt::identity( $source, $conversion_context ), 'source' => $source->facts(), 'target' => [ 'amount' => '8.12', 'currency' => 'USD', 'precision' => 2 ], 'applied_rate' => '0.65', 'converted_at' => '2026-10-21 09:15:00.000000', 'evidence_digest' => QuoteFixtures::digest( 'conversion ' . $key ) ] );
			$rows[] = [ 'component_key' => $key, 'promotion' => [ 'state' => 'not_recorded' ], 'currency' => [ 'state' => 'recorded', 'basis' => 'quote_final', 'receipt' => $receipt ] ];
		}
		$economics = self::economics( $terms );
		$packet = QuoteCommercialEvidencePacket::from_captured( $header, $context, $terms, $economics, $rows );
		$permuted = QuoteCommercialEvidencePacket::from_captured( $header, $context, $terms, $economics, array_reverse( $rows ) );
		self::assertSame( $packet->digest(), $permuted->digest() );
		self::assertSame( $packet->to_private_json(), QuoteCommercialEvidenceReadResult::read( $permuted->to_private_json(), $packet->digest() )->packet()->to_private_json() );
		$data = $packet->private_facts();
		self::assertSame( $data['groups'][0]['currency']['receipt']['source'], $data['groups'][1]['currency']['receipt']['source'] );
		self::assertNotSame( $data['groups'][0]['currency']['receipt']['context']['material_digest'], $data['groups'][1]['currency']['receipt']['context']['material_digest'] );
		[ $data['groups'][0]['currency']['receipt'], $data['groups'][1]['currency']['receipt'] ] = [ $data['groups'][1]['currency']['receipt'], $data['groups'][0]['currency']['receipt'] ];
		$refused = QuoteCommercialEvidenceReadResult::read( QuoteJson::encode( $data ) );
		self::assertSame( 'malformed', $refused->status() ); self::assertNull( $refused->packet() );
	}

	private static function packet( string $basis = 'planned_final', bool $unavailable = false, bool $promise = false, bool $recorded = true ): QuoteCommercialEvidencePacket {
		[ $header, $context, $terms ] = self::body( $promise ); $key = $terms->private_facts()['groups'][0]['component_key'];
		$rule = self::rule(); $plan = self::plan( $terms, $key, $rule );
		$groups = [ [ 'component_key' => $key, 'promotion' => [ 'state' => 'not_recorded' ], 'currency' => [ 'state' => 'not_recorded' ] ] ];
		if ( $recorded ) {
			$source = QuoteMoney::from_array( 'planned_final' === $basis ? $plan->private_facts()['money']['final'] : $terms->private_facts()['groups'][0]['final'] );
			$material = QuoteCommercialEvidencePacket::conversion_material_digest( $header, $context, $terms, $key, $basis, 'planned_final' === $basis ? $plan : null );
			$conversion_context = [ 'format_version' => 1, 'material_digest' => $material, 'base' => 'GHS', 'presentment' => 'USD', 'charged' => 'USD', 'target_precision' => 2, 'provider' => [ 'code' => 'private_currency_provider', 'version' => 2 ], 'provider_context_digest' => QuoteFixtures::digest( 'private provider state' ) ];
			$evidence = [ 'format_version' => 1, 'state' => 'unavailable', 'provider' => $conversion_context['provider'], 'request_digest' => CurrencyConversionReceipt::identity( $source, $conversion_context ), 'reason' => 'source_unavailable' ];
			if ( ! $unavailable ) { $evidence = array_diff_key( $evidence, [ 'reason' => true ] ) + [ 'source' => $source->facts(), 'target' => [ 'amount' => 'planned_final' === $basis ? '6.17' : '8.12', 'currency' => 'USD', 'precision' => 2 ], 'applied_rate' => '0.65000000000000000001', 'converted_at' => '2026-10-21 09:15:00.000000', 'evidence_digest' => QuoteFixtures::digest( 'private provider receipt' ) ]; $evidence['state'] = 'converted'; }
			$receipt = CurrencyConversionReceipt::from_provider( $source, $conversion_context, 'private_currency_provider', 2, $evidence );
			$groups[0]['promotion'] = [ 'state' => 'recorded', 'rule' => $rule, 'plan' => $plan ];
			$groups[0]['currency'] = [ 'state' => 'recorded', 'basis' => $basis, 'receipt' => $receipt ];
		}
		return QuoteCommercialEvidencePacket::from_captured( $header, $context, $terms, self::economics( $terms ), $groups );
	}
	private static function body( bool $promise = false ): array {
		if ( $promise ) { $context = PromiseHandoffFixture::context(); $terms = PromiseHandoffFixture::terms(); return [ PromiseHandoffFixture::header( $context, $terms ), $context, $terms ]; }
		$quote = QuoteFixtures::issue(); return [ $quote->header(), $quote->context(), $quote->terms() ];
	}
	private static function rule( string $discount = '3.00' ): FixedDeliveryPromotion {
		return FixedDeliveryPromotion::from_array( [ 'format_version' => 1, 'code' => 'private_rule', 'version' => 4, 'discount' => [ 'amount' => $discount, 'currency' => 'GHS', 'precision' => 2 ], 'funding_source' => [ 'code' => 'private_funder', 'version' => 7 ] ] );
	}
	private static function plan( QuoteTerms $terms, string $key, FixedDeliveryPromotion $rule ): DeliveryPromotionPlan {
		return DeliveryPromotionPlan::from_evidence( $terms, $key, $rule, 'private_promotion_provider', 2, [ 'format_version' => 1, 'provider' => [ 'code' => 'private_promotion_provider', 'version' => 2 ], 'terms_digest' => $terms->digest(), 'component_key' => $key, 'rule_digest' => $rule->digest(), 'eligibility' => [ 'state' => 'eligible', 'evidence_digest' => QuoteFixtures::digest( 'eligibility' ) ], 'budget' => [ 'state' => 'available', 'evidence_digest' => QuoteFixtures::digest( 'budget' ) ] ] );
	}
	private static function economics( QuoteTerms $terms ): QuoteEconomicsFacts {
		$groups = [];
		foreach ( $terms->private_facts()['groups'] as $term ) {
			$unknown = EconomicAmountFact::unavailable( 'not_recorded' )->private_facts();
			$estimate = EconomicAmountFact::known( QuoteMoney::from_array( [ 'amount' => '3.00', 'currency' => $term['final']['currency'], 'precision' => $term['final']['precision'] ] ), 'private_cost_source', 3, QuoteFixtures::digest( 'estimate' ) )->private_facts();
			$groups[] = [ 'component_key' => $term['component_key'], 'estimated_internal_cost' => $estimate, 'actual_internal_cost' => $unknown, 'provider_payable' => $unknown, 'subsidy' => [ 'amount' => $unknown, 'funding_source' => null ] ];
		}
		return QuoteEconomicsFacts::for_terms( $terms, $groups );
	}
}
