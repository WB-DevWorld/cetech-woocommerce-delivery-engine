<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Order;

use CetechDeliveryEngine\Application\Order\SnapshotExtensionFacts;
use CetechDeliveryEngine\Application\Order\SnapshotExtensionParser;
use CetechDeliveryEngine\Application\Order\SnapshotExtensionReadResult;
use CetechDeliveryEngine\Application\Order\SnapshotExtensionSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SnapshotExtensionsTest extends TestCase {
	public function test_absence_and_empty_object_have_no_invented_facts(): void {
		foreach ( [ [], [ 'extensions' => [] ] ] as $decoded ) {
			$set = ( new SnapshotExtensionParser() )->read( $decoded );
			self::assertTrue( $set->required_semantics_supported() );
			self::assertSame( [], $set->customer_facts() );
			foreach ( SnapshotExtensionFacts::NAMES as $name ) {
				self::assertSame( 'not_recorded', $set->get( $name )->status );
				self::assertNull( $set->get( $name )->facts );
			}
		}
	}

	public function test_all_five_independent_names_preserve_stored_facts_and_exclude_references_from_customer_projection(): void {
		$payloads = self::payloads();
		$entries = [];
		foreach ( $payloads as $name => $payload ) { $entries[$name] = self::envelope( $payload ); }
		$set = ( new SnapshotExtensionParser() )->read( [ 'extensions' => $entries ] );
		self::assertTrue( $set->required_semantics_supported() );
		foreach ( $payloads as $name => $payload ) {
			$result = $set->get( $name );
			self::assertSame( 'recorded', $result->status );
			self::assertNotNull( $result->facts );
			self::assertSame( $name, $result->facts->namespace() );
			self::assertSame( $payload, $result->facts->internal_facts() );
			unset( $payload['reference'] );
			self::assertSame( $payload, $result->facts->customer_facts() );
			self::assertSame( $payload, $set->customer_facts()[$name] );
		}
		self::assertSame( 0, $set->diagnostics()['ignored_optional_count'] );
	}

	public function test_return_and_refund_are_distinct_and_absence_is_not_a_policy_default(): void {
		$set = ( new SnapshotExtensionParser() )->read( [ 'extensions' => [ 'return_policy' => self::envelope( self::payloads()['return_policy'] ) ] ] );
		self::assertSame( 'recorded', $set->get( 'return_policy' )->status );
		self::assertSame( 'not_recorded', $set->get( 'refund_policy' )->status );
		self::assertArrayNotHasKey( 'refund_policy', $set->customer_facts() );
		self::assertSame( "Return text\nkept verbatim.", $set->customer_facts()['return_policy']['customer_text'] );
	}

	public function test_unknown_optional_name_and_known_future_version_are_ignored_without_payload_or_name_in_diagnostics(): void {
		$name = 'PRIVATE_PHONE_0244123456';
		$set = ( new SnapshotExtensionParser() )->read( [ 'extensions' => [
			$name => self::envelope( [ 'private_customer_email' => 'secret@example.test' ] ),
			'quote_policy' => self::envelope( [ 'future' => 'PRIVATE_NONCE' ], 2 ),
		] ] );
		self::assertTrue( $set->required_semantics_supported() );
		self::assertSame( 'ignored_optional', $set->get( $name )->status );
		self::assertNull( $set->get( $name )->facts );
		self::assertSame( 'ignored_optional', $set->get( 'quote_policy' )->status );
		self::assertSame( 2, $set->diagnostics()['ignored_optional_count'] );
		self::assertSame( [], $set->customer_facts() );
		$diagnostics = json_encode( $set->diagnostics(), JSON_THROW_ON_ERROR );
		self::assertStringNotContainsString( $name, $diagnostics );
		self::assertStringNotContainsString( 'secret@example.test', $diagnostics );
		self::assertStringNotContainsString( 'PRIVATE_NONCE', $diagnostics );
	}

	public function test_known_and_unknown_mandatory_extensions_are_unsupported_even_at_known_version(): void {
		foreach ( [ [ 'quote_policy', 1 ], [ 'quote_policy', 2 ], [ 'future_policy', 1 ] ] as [ $name, $version ] ) {
			$entry = self::envelope( [ 'secret' => 'PRIVATE_SQL' ], $version ); $entry['required'] = true;
			$set = ( new SnapshotExtensionParser() )->read( [ 'extensions' => [ $name => $entry ] ] );
			self::assertFalse( $set->required_semantics_supported() );
			self::assertSame( 'unsupported_required', $set->get( $name )->status );
			self::assertNull( $set->get( $name )->facts );
			self::assertSame( [], $set->customer_facts() );
		}
	}

	public function test_known_mandatory_flag_is_preserved_when_aggregate_shape_is_over_budget(): void {
		$entry = self::envelope( [ 'nested' => str_repeat( 'x', 16385 ) ] ); $entry['required'] = true;
		$set = ( new SnapshotExtensionParser() )->read( [ 'extensions' => [ 'quote_policy' => $entry ] ] );
		self::assertFalse( $set->required_semantics_supported() );
		self::assertSame( 'unsupported_required', $set->get( 'quote_policy' )->status );
	}

	#[DataProvider( 'malformed_containers' )]
	public function test_malformed_container_cannot_imply_absent_or_supported_optional_data( mixed $container ): void {
		$set = ( new SnapshotExtensionParser() )->read( [ 'extensions' => $container ] );
		self::assertFalse( $set->required_semantics_supported() );
		foreach ( SnapshotExtensionFacts::NAMES as $name ) { self::assertSame( 'malformed', $set->get( $name )->status ); }
	}
	public static function malformed_containers(): array { return [ [ null ], [ false ], [ 'private' ], [ 1 ], [ [ self::envelope( [ 'future' => 'x' ] ) ] ] ]; }

	#[DataProvider( 'uncertain_envelopes' )]
	public function test_missing_or_non_boolean_required_is_structural_uncertainty( mixed $entry ): void {
		$set = ( new SnapshotExtensionParser() )->read( [ 'extensions' => [ 'quote_policy' => $entry ] ] );
		self::assertFalse( $set->required_semantics_supported() );
		self::assertSame( 'malformed', $set->get( 'quote_policy' )->status );
	}
	public static function uncertain_envelopes(): array { return [ [ null ], [ [] ], [ [ 'version' => 1, 'data' => [] ] ], [ [ 'version' => 1, 'required' => 0, 'data' => [] ] ], [ [ 'version' => 1, 'required' => 'false', 'data' => [] ] ] ]; }

	#[DataProvider( 'malformed_optional_envelopes' )]
	public function test_truthfully_optional_malformed_envelope_preserves_base_support( array $entry ): void {
		$set = ( new SnapshotExtensionParser() )->read( [ 'extensions' => [ 'quote_policy' => $entry ] ] );
		self::assertTrue( $set->required_semantics_supported() );
		self::assertSame( 'malformed', $set->get( 'quote_policy' )->status );
		self::assertNull( $set->get( 'quote_policy' )->facts );
	}
	public static function malformed_optional_envelopes(): array {
		$valid = self::envelope( self::payloads()['quote_policy'] );
		$string_version = $valid; $string_version['version'] = '1';
		$float_version = $valid; $float_version['version'] = 1.0;
		$zero_version = $valid; $zero_version['version'] = 0;
		$extra = $valid; $extra['customer_email'] = 'private@example.test';
		$list = $valid; $list['data'] = [ 'private' ];
		$scalar = $valid; $scalar['data'] = 'private';
		return [ [ $string_version ], [ $float_version ], [ $zero_version ], [ $extra ], [ $list ], [ $scalar ] ];
	}

	public function test_known_payload_unknown_keys_and_reference_private_renames_are_rejected(): void {
		foreach ( self::payloads() as $name => $data ) {
			$extra = $data; $extra['customer_private'] = 'PRIVATE_SECRET';
			$reference = $data; $reference['reference']['private_row_id'] = 17;
			$renamed = $data; $renamed['reference']['row_id'] = $renamed['reference']['id']; unset( $renamed['reference']['id'] );
			foreach ( [ $extra, $reference, $renamed ] as $bad ) {
				$set = ( new SnapshotExtensionParser() )->read( [ 'extensions' => [ $name => self::envelope( $bad ) ] ] );
				self::assertTrue( $set->required_semantics_supported() );
				self::assertSame( 'malformed', $set->get( $name )->status );
				self::assertSame( [], $set->customer_facts() );
			}
		}
	}

	#[DataProvider( 'invalid_references' )]
	public function test_reference_is_an_opaque_strict_recorded_fact( array $reference ): void {
		$data = self::payloads()['return_policy']; $data['reference'] = $reference;
		$this->assert_optional_malformed( 'return_policy', $data );
	}
	public static function invalid_references(): array {
		$base = self::reference( 'return' ); $cases = [];
		foreach ( [ '', 'with space', str_repeat( 'a', 129 ), 17 ] as $value ) { $bad = $base; $bad['id'] = $value; $cases[] = [ $bad ]; }
		foreach ( [ '', str_repeat( 'a', 65 ), 1 ] as $value ) { $bad = $base; $bad['version'] = $value; $cases[] = [ $bad ]; }
		foreach ( [ str_repeat( 'A', 64 ), str_repeat( 'a', 63 ), 12 ] as $value ) { $bad = $base; $bad['content_hash'] = $value; $cases[] = [ $bad ]; }
		return $cases;
	}

	#[DataProvider( 'valid_amounts' )]
	public function test_quote_preserves_decimal_bytes_without_numeric_conversion( string $amount ): void {
		$data = self::payloads()['quote_policy']; $data['amount'] = $amount;
		$set = ( new SnapshotExtensionParser() )->read( [ 'extensions' => [ 'quote_policy' => self::envelope( $data ) ] ] );
		self::assertSame( 'recorded', $set->get( 'quote_policy' )->status );
		self::assertSame( $amount, $set->get( 'quote_policy' )->facts?->internal_facts()['amount'] );
	}
	public static function valid_amounts(): array { return [ [ '0' ], [ '0.0000' ], [ '001.20' ], [ '9007199254740993.1234' ] ]; }

	#[DataProvider( 'invalid_amounts' )]
	public function test_quote_rejects_lossy_or_non_decimal_amounts( mixed $amount ): void {
		$data = self::payloads()['quote_policy']; $data['amount'] = $amount;
		$this->assert_optional_malformed( 'quote_policy', $data );
	}
	public static function invalid_amounts(): array { return [ [ 1 ], [ 1.2 ], [ '-1' ], [ '+1' ], [ '1e2' ], [ '1.12345' ], [ '.1' ], [ '1.' ], [ ' 1' ], [ '' ] ]; }

	public function test_currency_is_explicit_uppercase_three_letter_string(): void {
		foreach ( [ 'ghs', 'GHS1', '', 1 ] as $currency ) { $data = self::payloads()['quote_policy']; $data['currency_code'] = $currency; $this->assert_optional_malformed( 'quote_policy', $data ); }
	}

	public function test_promise_uses_valid_gregorian_utc_instants_and_preserves_fractional_text(): void {
		$data = self::payloads()['promise']; $data['from'] = '2024-02-29T23:59:59.1Z'; $data['until'] = '2024-02-29T23:59:59.100001Z';
		$set = ( new SnapshotExtensionParser() )->read( [ 'extensions' => [ 'promise' => self::envelope( $data ) ] ] );
		self::assertSame( 'recorded', $set->get( 'promise' )->status );
		self::assertSame( $data, $set->get( 'promise' )->facts?->internal_facts() );
	}

	#[DataProvider( 'invalid_promise_ranges' )]
	public function test_promise_rejects_impossible_time_ambiguity_and_non_increasing_range( string $from, string $until ): void {
		$data = self::payloads()['promise']; $data['from'] = $from; $data['until'] = $until; $this->assert_optional_malformed( 'promise', $data );
	}
	public static function invalid_promise_ranges(): array {
		return [
			[ '2025-02-29T00:00:00Z', '2025-03-01T00:00:00Z' ], [ '0000-01-01T00:00:00Z', '2026-01-01T00:00:00Z' ],
			[ '2026-10-07T24:00:00Z', '2026-10-08T01:00:00Z' ], [ '2026-10-07T10:60:00Z', '2026-10-08T00:00:00Z' ],
			[ '2026-10-07T10:00:60Z', '2026-10-08T00:00:00Z' ], [ '2026-10-07T10:00:00+00:00', '2026-10-08T00:00:00Z' ],
			[ '2026-10-07T10:00:00.1234567Z', '2026-10-08T00:00:00Z' ], [ '2026-10-08T00:00:00Z', '2026-10-07T00:00:00Z' ],
			[ '2026-10-07T10:00:00.1Z', '2026-10-07T10:00:00.100000Z' ],
		];
	}

	public function test_labels_are_a_bounded_plain_utf8_list(): void {
		foreach ( [ [], array_fill( 0, 17, 'label' ), [ 'key' => 'label' ], [ '' ], [ "private\0" ], [ str_repeat( 'x', 257 ) ], [ "\xc3\x28" ], [ 12 ] ] as $labels ) {
			$data = self::payloads()['fulfilment_labels']; $data['labels'] = $labels; $this->assert_optional_malformed( 'fulfilment_labels', $data, ! in_array( "\xc3\x28", $labels, true ) );
		}
		$data = self::payloads()['fulfilment_labels']; $data['labels'] = array_fill( 0, 16, str_repeat( 'é', 128 ) );
		self::assertSame( 'recorded', ( new SnapshotExtensionParser() )->read( [ 'extensions' => [ 'fulfilment_labels' => self::envelope( $data ) ] ] )->get( 'fulfilment_labels' )->status );
	}

	public function test_optional_customer_text_limits_and_controls_do_not_supply_missing_policy(): void {
		foreach ( [ '', str_repeat( 'x', 8193 ), "private\0", 17 ] as $text ) { $data = self::payloads()['return_policy']; $data['customer_text'] = $text; $this->assert_optional_malformed( 'return_policy', $data ); }
		$data = self::payloads()['refund_policy']; unset( $data['customer_text'] ); $this->assert_optional_malformed( 'refund_policy', $data );
		$data = self::payloads()['promise']; $data['label'] = str_repeat( 'x', 513 ); $this->assert_optional_malformed( 'promise', $data );
		$data = self::payloads()['quote_policy']; unset( $data['customer_text'] );
		$result = ( new SnapshotExtensionParser() )->read( [ 'extensions' => [ 'quote_policy' => self::envelope( $data ) ] ] )->get( 'quote_policy' );
		self::assertSame( 'recorded', $result->status ); self::assertArrayNotHasKey( 'customer_text', $result->facts?->customer_facts() ?? [] );
	}

	public function test_entry_count_budget_has_a_real_boundary(): void {
		$entries = []; for ( $i = 0; $i < 16; ++$i ) { $entries['future_' . $i] = self::envelope( [ 'future' => 'x' ] ); }
		$set = ( new SnapshotExtensionParser() )->read( [ 'extensions' => $entries ] );
		self::assertTrue( $set->required_semantics_supported() ); self::assertSame( 16, $set->diagnostics()['ignored_optional_count'] );
		$entries['future_16'] = self::envelope( [ 'future' => 'x' ] );
		self::assertFalse( ( new SnapshotExtensionParser() )->read( [ 'extensions' => $entries ] )->required_semantics_supported() );
	}

	public function test_entry_and_aggregate_byte_budgets_are_independent(): void {
		$entry = self::envelope( [ 'future' => str_repeat( 'x', 16300 ) ] );
		$good = ( new SnapshotExtensionParser() )->read( [ 'extensions' => [ 'future' => $entry ] ] );
		self::assertSame( 'ignored_optional', $good->get( 'future' )->status );
		$large = self::envelope( [ 'future' => str_repeat( 'x', 16384 ) ] );
		$one = ( new SnapshotExtensionParser() )->read( [ 'extensions' => [ 'future' => $large ] ] );
		self::assertTrue( $one->required_semantics_supported() ); self::assertSame( 'malformed', $one->get( 'future' )->status );
		$entries = []; for ( $i = 0; $i < 5; ++$i ) { $entries['future_' . $i] = $entry; }
		self::assertFalse( ( new SnapshotExtensionParser() )->read( [ 'extensions' => $entries ] )->required_semantics_supported() );
	}

	public function test_depth_and_node_budgets_stop_nested_unknown_payloads(): void {
		$nested = 'private'; for ( $i = 0; $i < 5; ++$i ) { $nested = [ 'nested' => $nested ]; }
		$nodes = []; for ( $i = 0; $i < 129; ++$i ) { $nodes['field_' . $i] = 'x'; }
		foreach ( [ [ 'nested' => $nested ], $nodes ] as $data ) {
			$set = ( new SnapshotExtensionParser() )->read( [ 'extensions' => [ 'future' => self::envelope( $data ) ] ] );
			self::assertFalse( $set->required_semantics_supported() ); self::assertSame( [], $set->customer_facts() );
		}
	}

	public function test_arbitrary_objects_invalid_float_and_invalid_name_fail_safely(): void {
		foreach ( [ [ 'future' => self::envelope( [ 'object' => new \stdClass() ] ) ], [ 'future' => self::envelope( [ 'float' => NAN ] ) ], [ '' => self::envelope( [ 'future' => 'x' ] ) ], [ 9 => self::envelope( [ 'future' => 'x' ] ) ] ] as $entries ) {
			$set = ( new SnapshotExtensionParser() )->read( [ 'extensions' => $entries ] ); self::assertFalse( $set->required_semantics_supported() ); self::assertSame( [], $set->customer_facts() );
		}
	}

	public function test_projection_arrays_are_detached_from_both_input_and_each_other(): void {
		$data = self::payloads()['fulfilment_labels']; $aliased = 'Stored label'; $data['labels'][0] =& $aliased;
		$set = ( new SnapshotExtensionParser() )->read( [ 'extensions' => [ 'fulfilment_labels' => self::envelope( $data ) ] ] );
		$aliased = 'Changed input'; $data['reference']['id'] = 'ChangedReference';
		$first = $set->get( 'fulfilment_labels' )->facts?->internal_facts() ?? []; $first['labels'][0] = 'Changed return'; $first['reference']['id'] = 'ChangedOutput';
		$public = $set->customer_facts(); $public['fulfilment_labels']['labels'][0] = 'Changed customer output';
		self::assertSame( 'Stored label', $set->get( 'fulfilment_labels' )->facts?->internal_facts()['labels'][0] );
		self::assertSame( 'labels:opaque-v1', $set->get( 'fulfilment_labels' )->facts?->internal_facts()['reference']['id'] );
		self::assertSame( 'Stored label', $set->customer_facts()['fulfilment_labels']['labels'][0] );
	}

	public function test_generic_json_serialization_is_blocked_and_safe_errors_do_not_echo_source(): void {
		$set = ( new SnapshotExtensionParser() )->read( [ 'extensions' => [ 'quote_policy' => self::envelope( self::payloads()['quote_policy'] ) ] ] );
		foreach ( [ $set, $set->get( 'quote_policy' ), $set->get( 'quote_policy' )->facts ] as $value ) {
			try { json_encode( $value, JSON_THROW_ON_ERROR ); self::fail( 'Generic extension JSON was accepted.' ); }
			catch ( \LogicException $error ) { self::assertStringNotContainsString( 'quote:opaque-v1', $error->getMessage() ); self::assertStringContainsString( 'explicit projection', $error->getMessage() ); }
		}
		try { SnapshotExtensionFacts::from_payload( 'quote_policy', [ 'PRIVATE_SQL' => 'secret' ] ); self::fail( 'Unknown private fields were accepted.' ); }
		catch ( \InvalidArgumentException $error ) { self::assertSame( 'Invalid snapshot extension facts.', $error->getMessage() ); }
	}

	public function test_direct_set_construction_cannot_relabel_or_expose_unknown_recorded_facts(): void {
		$facts = SnapshotExtensionFacts::from_payload( 'return_policy', self::payloads()['return_policy'] );
		foreach ( [ 'quote_policy', 'unknown_private_name' ] as $name ) {
			try { new SnapshotExtensionSet( [ $name => new SnapshotExtensionReadResult( 'recorded', $facts ) ] ); self::fail( 'Facts were relabelled.' ); }
			catch ( \InvalidArgumentException $error ) { self::assertSame( 'Invalid snapshot extension set.', $error->getMessage() ); }
		}
		$this->expectException( \InvalidArgumentException::class ); new SnapshotExtensionSet( [ 'quote_policy' => new SnapshotExtensionReadResult( 'unsupported_required' ) ], true );
	}

	public function test_result_state_requires_facts_only_for_recorded_status(): void {
		foreach ( [ [ 'recorded', null ], [ 'unknown', null ], [ 'malformed', SnapshotExtensionFacts::from_payload( 'return_policy', self::payloads()['return_policy'] ) ] ] as [ $status, $facts ] ) {
			try { new SnapshotExtensionReadResult( $status, $facts ); self::fail( 'Invalid result invariant was accepted.' ); }
			catch ( \InvalidArgumentException $error ) { self::assertSame( 'Invalid snapshot extension result.', $error->getMessage() ); }
		}
	}

	private function assert_optional_malformed( string $name, array $data, bool $supported = true ): void {
		$set = ( new SnapshotExtensionParser() )->read( [ 'extensions' => [ $name => self::envelope( $data ) ] ] );
		self::assertSame( $supported, $set->required_semantics_supported() );
		self::assertSame( 'malformed', $set->get( $name )->status ); self::assertNull( $set->get( $name )->facts );
	}
	private static function envelope( array $data, int $version = 1 ): array { return [ 'version' => $version, 'required' => false, 'data' => $data ]; }
	private static function reference( string $kind ): array { return [ 'id' => $kind . ':opaque-v1', 'version' => '1.0', 'content_hash' => str_repeat( 'a', 64 ) ]; }
	private static function payloads(): array {
		return [
			'quote_policy' => [ 'reference' => self::reference( 'quote' ), 'currency_code' => 'GHS', 'amount' => '15.0000', 'customer_text' => 'Stored quote text' ],
			'promise' => [ 'reference' => self::reference( 'promise' ), 'from' => '2026-10-07T10:00:00Z', 'until' => '2026-10-08T10:00:00Z', 'label' => 'Stored promise label' ],
			'fulfilment_labels' => [ 'reference' => self::reference( 'labels' ), 'labels' => [ 'Stored label', 'Second label' ] ],
			'return_policy' => [ 'reference' => self::reference( 'return' ), 'customer_text' => "Return text\nkept verbatim." ],
			'refund_policy' => [ 'reference' => self::reference( 'refund' ), 'customer_text' => 'Independent refund text' ],
		];
	}
}
