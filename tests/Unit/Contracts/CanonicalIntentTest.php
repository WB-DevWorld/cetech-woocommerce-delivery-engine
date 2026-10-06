<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Contracts;

use CetechDeliveryEngine\Domain\Contracts\CanonicalIntent;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use PHPUnit\Framework\TestCase;

final class CanonicalIntentTest extends TestCase {

	public function test_typed_golden_fixtures_and_distinct_fingerprints(): void {
		$fixtures = [
			[ null, '["null"]' ],
			[ false, '["boolean",false]' ],
			[ true, '["boolean",true]' ],
			[ 0, '["integer","0"]' ],
			[ 1, '["integer","1"]' ],
			[ -1, '["integer","-1"]' ],
			[ 0.0, '["float64","0000000000000000"]' ],
			[ -0.0, '["float64","8000000000000000"]' ],
			[ 1.0, '["float64","3ff0000000000000"]' ],
			[ '', '["string",""]' ],
			[ '0', '["string","0"]' ],
			[ '1', '["string","1"]' ],
			[ [], '["list",[]]' ],
			[ new \stdClass(), '["object",[]]' ],
			[ [ null ], '["list",[["null"]]]' ],
			[ [ 'x' => null ], '["object",[["x",["null"]]]]' ],
		];
		$digests = [];
		foreach ( $fixtures as [ $payload, $canonical_payload ] ) {
			$intent = $this->intent( $payload );
			$golden = '["object",[["operation",["string","scope.save"]],["operation_version",["integer","1"]],["payload",'
				. $canonical_payload
				. '],["preconditions",["object",[["opened_revision",["integer","7"]]]]],["target_identity",["object",[["row_id",["integer","12"]]]]],["target_key",["string","product:23"]]]]';
			self::assertSame( hash( 'sha256', 'cetech-intent-v1:' . $golden ), $intent->fingerprint() );
			$digests[] = $intent->fingerprint();
		}
		self::assertCount( count( $fixtures ), array_unique( $digests ) );
	}

	public function test_nested_object_order_is_equivalent_and_lists_remain_ordered(): void {
		$left = $this->intent( [ 'z' => [ 'b' => 2, 'a' => 1 ], 'a' => [ 3, 4 ] ] );
		$right = $this->intent( (object) [ 'a' => [ 3, 4 ], 'z' => (object) [ 'a' => 1, 'b' => 2 ] ] );
		self::assertTrue( $left->equals( $right ) );
		self::assertFalse( $left->equals( $this->intent( [ 'a' => [ 4, 3 ], 'z' => [ 'a' => 1, 'b' => 2 ] ] ) ) );
	}

	public function test_absence_null_false_zero_and_empty_collections_are_not_coerced(): void {
		$payloads = [ new \stdClass(), [ 'x' => null ], [ 'x' => false ], [ 'x' => 0 ], [ 'x' => '' ], [ 'x' => [] ], [ 'x' => new \stdClass() ] ];
		$digests = array_map( fn( mixed $payload ): string => $this->intent( $payload )->fingerprint(), $payloads );
		self::assertCount( count( $payloads ), array_unique( $digests ) );
		self::assertFalse( $this->intent( new \stdClass() )->equals( $this->intent( [ 'delivery_options' => [] ] ) ) );
	}

	public function test_original_operation_exact_row_target_and_preconditions_are_bound(): void {
		$identity = $this->identity();
		$original = $this->intent( [ 'priority' => 2 ] );
		$changes = [
			CanonicalIntent::from_command( $this->identity( 'scope.reset' ), [ 'row_id' => 12 ], [ 'opened_revision' => 7 ], [ 'priority' => 2 ] ),
			CanonicalIntent::from_command( $this->identity( version: 2 ), [ 'row_id' => 12 ], [ 'opened_revision' => 7 ], [ 'priority' => 2 ] ),
			CanonicalIntent::from_command( $this->identity( target: 'product:24' ), [ 'row_id' => 12 ], [ 'opened_revision' => 7 ], [ 'priority' => 2 ] ),
			CanonicalIntent::from_command( $identity, [ 'row_id' => 13 ], [ 'opened_revision' => 7 ], [ 'priority' => 2 ] ),
			CanonicalIntent::from_command( $identity, [ 'row_id' => 12 ], [ 'opened_revision' => 8 ], [ 'priority' => 2 ] ),
			CanonicalIntent::from_command( $identity, [ 'row_id' => 12 ], [ 'opened_revision' => 7 ], [ 'priority' => 3 ] ),
		];
		foreach ( $changes as $changed ) {
			self::assertFalse( $original->equals( $changed ) );
		}
	}

	public function test_token_and_namespace_authority_do_not_change_semantic_intent(): void {
		$first_identity = $this->identity();
		$retry_identity = new OperationIdentity( 9, 'integration', 'user:91', 'scope.save', 1, 'product:23', 'another-token' );
		$first = CanonicalIntent::from_command( $first_identity, [ 'row_id' => 12 ], [ 'opened_revision' => 7 ], [ 'priority' => 2 ] );
		$retry = CanonicalIntent::from_command( $retry_identity, [ 'row_id' => 12 ], [ 'opened_revision' => 7 ], [ 'priority' => 2 ] );
		self::assertTrue( $first->equals( $retry ) );
		self::assertNotSame( $first_identity->namespace_digest(), $retry_identity->namespace_digest() );
	}

	public function test_numeric_object_property_names_remain_object_keys(): void {
		$left = new \stdClass();
		$left->{'20'} = 'second';
		$left->{'1'} = 'first';
		$right = new \stdClass();
		$right->{'1'} = 'first';
		$right->{'20'} = 'second';
		self::assertTrue( $this->intent( $left )->equals( $this->intent( $right ) ) );
		self::assertFalse( $this->intent( $left )->equals( $this->intent( [ 'first', 'second' ] ) ) );
	}

	public function test_fingerprint_does_not_retain_mutable_input_objects(): void {
		$payload = (object) [ 'priority' => 2 ];
		$intent = $this->intent( $payload );
		$expected = $intent->fingerprint();
		$payload->priority = 99;
		self::assertSame( $expected, $intent->fingerprint() );
		self::assertFalse( $intent->equals( $this->intent( $payload ) ) );
	}

	public function test_reusing_an_object_in_separate_branches_is_not_a_cycle(): void {
		$shared = (object) [ 'priority' => 2 ];
		$left = $this->intent( [ 'first' => $shared, 'second' => $shared ] );
		$right = $this->intent( [ 'second' => [ 'priority' => 2 ], 'first' => [ 'priority' => 2 ] ] );
		self::assertTrue( $left->equals( $right ) );
	}

	public function test_float_identity_does_not_depend_on_serialize_precision(): void {
		$before = ini_get( 'serialize_precision' );
		try {
			ini_set( 'serialize_precision', '3' );
			$low = $this->intent( 1.234567890123456 );
			ini_set( 'serialize_precision', '17' );
			$high = $this->intent( 1.234567890123456 );
			self::assertTrue( $low->equals( $high ) );
		} finally {
			if ( false !== $before ) {
				ini_set( 'serialize_precision', $before );
			}
		}
	}

	public function test_invalid_and_unsupported_values_have_safe_exceptions(): void {
		$resource = fopen( 'php://memory', 'r+' );
		try {
			$invalid = [ INF, -INF, NAN, "private-secret\x80", [ "private-secret\x80" => 1 ], [ 2 => 'private-secret' ], new \DateTimeImmutable(), $resource ];
			foreach ( $invalid as $payload ) {
				try {
					$this->intent( $payload );
					self::fail( 'Invalid canonical intent was accepted.' );
				} catch ( \InvalidArgumentException $error ) {
					self::assertStringNotContainsString( 'private-secret', $error->getMessage() );
				}
			}
		} finally {
			if ( is_resource( $resource ) ) {
				fclose( $resource );
			}
		}
	}

	public function test_deep_and_cyclic_inputs_are_bounded(): void {
		$deep = null;
		for ( $index = 0; $index < CanonicalIntent::MAX_DEPTH + 1; ++$index ) {
			$deep = [ $deep ];
		}
		$object = new \stdClass();
		$object->self = $object;
		$array = [];
		$array['self'] = &$array;
		foreach ( [ $deep, $object, $array ] as $payload ) {
			try {
				$this->intent( $payload );
				self::fail( 'Unbounded canonical intent was accepted.' );
			} catch ( \InvalidArgumentException $error ) {
				self::assertNotSame( '', $error->getMessage() );
			}
		}
	}

	public function test_node_and_byte_budgets_reject_excessive_inputs(): void {
		$payloads = [
			array_fill( 0, CanonicalIntent::MAX_NODES + 1, null ),
			str_repeat( 'x', CanonicalIntent::MAX_BYTES + 1 ),
			array_fill( 0, 100, str_repeat( 'x', 3000 ) ),
		];
		foreach ( $payloads as $payload ) {
			try {
				$this->intent( $payload );
				self::fail( 'Excessive canonical intent was accepted.' );
			} catch ( \InvalidArgumentException $error ) {
				self::assertNotSame( '', $error->getMessage() );
			}
		}
	}

	public function test_valid_values_at_the_depth_and_node_boundaries_are_accepted(): void {
		$deep = null;
		for ( $index = 0; $index < CanonicalIntent::MAX_DEPTH - 1; ++$index ) {
			$deep = [ $deep ];
		}
		self::assertSame( 64, strlen( $this->intent( $deep )->fingerprint() ) );
		// The six-member command and its two one-member objects use nine nodes.
		self::assertSame( 64, strlen( $this->intent( array_fill( 0, CanonicalIntent::MAX_NODES - 9, null ) )->fingerprint() ) );
	}

	private function intent( mixed $payload ): CanonicalIntent {
		return CanonicalIntent::from_command( $this->identity(), [ 'row_id' => 12 ], [ 'opened_revision' => 7 ], $payload );
	}

	private function identity( string $operation = 'scope.save', int $version = 1, string $target = 'product:23' ): OperationIdentity {
		return new OperationIdentity( 4, 'wp-admin', 'user:8', $operation, $version, $target, 'original-token' );
	}
}
