<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Contracts;

use CetechDeliveryEngine\Domain\Contracts\DecisionTrace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecisionTraceTest extends TestCase {

	public function test_empty_source_does_not_infer_completeness(): void {
		$unknown = new DecisionTrace();
		$complete = new DecisionTrace( [], true );
		self::assertFalse( $unknown->is_complete() );
		self::assertFalse( $unknown->source_complete() );
		self::assertTrue( $complete->is_complete() );
		self::assertFalse( $complete->was_truncated() );
		self::assertSame( 0, $complete->captured_count() );
		self::assertSame( 0, $complete->observed_count() );
	}

	public function test_exact_limit_is_complete_but_one_extra_entry_marks_truncation(): void {
		$exact = new DecisionTrace( [ self::entry( 1 ), self::entry( 2 ) ], true, 2 );
		$extra = new DecisionTrace( [ self::entry( 1 ), self::entry( 2 ), self::entry( 3 ), self::entry( 4 ) ], true, 2 );
		self::assertTrue( $exact->is_complete() );
		self::assertFalse( $exact->was_truncated() );
		self::assertSame( 2, $exact->observed_count() );
		self::assertFalse( $extra->is_complete() );
		self::assertTrue( $extra->source_complete() );
		self::assertTrue( $extra->was_truncated() );
		self::assertSame( 2, $extra->captured_count() );
		self::assertSame( 3, $extra->observed_count() );
		self::assertSame( [ 1, 2 ], array_column( $extra->entries(), 'reference_id' ) );
	}

	public function test_infinite_iterable_is_stopped_after_one_extra_observation(): void {
		$produced = 0;
		$source = ( static function () use ( &$produced ): \Generator {
			while ( true ) {
				++$produced;
				yield self::entry( $produced );
			}
		} )();
		$trace = new DecisionTrace( $source, true, 64 );
		self::assertSame( 65, $produced );
		self::assertSame( 64, $trace->captured_count() );
		self::assertSame( 65, $trace->observed_count() );
		self::assertTrue( $trace->was_truncated() );
		self::assertFalse( $trace->is_complete() );
		self::assertSame( range( 1, 64 ), array_column( $trace->entries(), 'reference_id' ) );
	}

	public function test_unknown_reference_versions_remain_null_and_known_zero_is_preserved(): void {
		$unknown = self::entry();
		$known_zero = self::entry( 1 );
		$known_zero['reference_version'] = 0;
		$trace = new DecisionTrace( [ $unknown, $known_zero ], false );
		self::assertNull( $trace->entries()[0]['reference_kind'] );
		self::assertNull( $trace->entries()[0]['reference_id'] );
		self::assertNull( $trace->entries()[0]['reference_version'] );
		self::assertSame( 0, $trace->entries()[1]['reference_version'] );
		self::assertFalse( $trace->is_complete() );
		self::assertFalse( $trace->was_truncated() );
	}

	public function test_finite_stage_and_reference_allowlists_preserve_known_identity_and_version(): void {
		$entries = [];
		foreach ( [
			[ 'configuration', 'configuration_scope' ],
			[ 'coverage', 'coverage_zone' ],
			[ 'coverage', 'coverage_group' ],
			[ 'quote', 'rate_card' ],
			[ 'mutation', 'rule_version' ],
		] as $index => [ $stage, $kind ] ) {
			$entries[] = [
				'stage' => $stage,
				'reason_code' => 'INVALID_REFERENCE',
				'reference_kind' => $kind,
				'reference_id' => $index + 1,
				'reference_version' => $index,
			];
		}
		$trace = new DecisionTrace( $entries, true );
		self::assertSame( $entries, $trace->entries() );
		self::assertTrue( $trace->is_complete() );
		self::assertSame( 5, $trace->observed_count() );
	}

	#[DataProvider( 'invalid_entries' )]
	public function test_malformed_captured_or_extra_entry_is_rejected_without_private_echo( mixed $entry ): void {
		foreach ( [ [ $entry ], [ self::entry( 1 ), $entry ] ] as $entries ) {
			try {
				new DecisionTrace( $entries, true, 1 );
				self::fail( 'Malformed trace entry was accepted.' );
			} catch ( \InvalidArgumentException $exception ) {
				self::assertSame( 'Decision trace is invalid.', $exception->getMessage() );
				self::assertNull( $exception->getPrevious() );
			}
		}
	}

	public static function invalid_entries(): array {
		$valid = self::entry( 1 );
		$missing = $valid;
		unset( $missing['reference_version'] );
		return [
			[ 'private_marker SELECT token' ],
			[ new \stdClass() ],
			[ $missing ],
			[ $valid + [ 'safe_alias' => 'private_marker SELECT token' ] ],
			[ array_replace( $valid, [ 'stage' => 'private_marker SELECT token' ] ) ],
			[ array_replace( $valid, [ 'stage' => [ 'configuration' ] ] ) ],
			[ array_replace( $valid, [ 'reason_code' => 'private_marker SELECT token' ] ) ],
			[ array_replace( $valid, [ 'reason_code' => [ 'configuration_ready' ] ] ) ],
			[ array_replace( $valid, [ 'reference_kind' => 'private_marker SELECT token' ] ) ],
			[ array_replace( $valid, [ 'reference_kind' => [ 'configuration_scope' ] ] ) ],
			[ array_replace( $valid, [ 'reference_id' => 0 ] ) ],
			[ array_replace( $valid, [ 'reference_id' => -1 ] ) ],
			[ array_replace( $valid, [ 'reference_id' => '1' ] ) ],
			[ array_replace( $valid, [ 'reference_id' => 1.0 ] ) ],
			[ array_replace( $valid, [ 'reference_version' => -1 ] ) ],
			[ array_replace( $valid, [ 'reference_version' => '1' ] ) ],
			[ array_replace( $valid, [ 'reference_version' => 1.0 ] ) ],
			[ array_replace( $valid, [ 'reference_kind' => null ] ) ],
			[ array_replace( $valid, [ 'reference_id' => null ] ) ],
			[ array_replace( self::entry(), [ 'reference_version' => 0 ] ) ],
		];
	}

	#[DataProvider( 'invalid_limits' )]
	public function test_invalid_limit_fails_before_iterating_source( int $limit ): void {
		$iterated = false;
		$source = ( static function () use ( &$iterated ): \Generator {
			$iterated = true;
			yield self::entry();
		} )();
		try {
			new DecisionTrace( $source, true, $limit );
			self::fail( 'Invalid limit was accepted.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame( 'Decision trace is invalid.', $exception->getMessage() );
			self::assertFalse( $iterated );
		}
	}

	public static function invalid_limits(): array {
		return [ [ 0 ], [ -1 ], [ 65 ], [ PHP_INT_MAX ] ];
	}

	public function test_iterator_exception_does_not_publish_original_exception_or_previous_chain(): void {
		$source = ( static function (): \Generator {
			yield self::entry( 1 );
			throw new \RuntimeException( 'private_marker SELECT secret WHERE token=private' );
		} )();
		try {
			new DecisionTrace( $source, true );
			self::fail( 'Failed iterator was accepted.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame( 'Decision trace is invalid.', $exception->getMessage() );
			self::assertNull( $exception->getPrevious() );
		}
	}

	public function test_referenced_input_fields_and_returned_arrays_are_detached(): void {
		$stage = 'configuration';
		$reason = 'configuration_ready';
		$kind = 'configuration_scope';
		$id = 31;
		$version = 8;
		$entry = [
			'stage' => &$stage,
			'reason_code' => &$reason,
			'reference_kind' => &$kind,
			'reference_id' => &$id,
			'reference_version' => &$version,
		];
		$expected = self::entry( 31 );
		$expected['reference_version'] = 8;
		$trace = new DecisionTrace( [ $entry ], true );
		$stage = 'mutation';
		$reason = 'private_marker';
		$kind = 'rate_card';
		$id = 99;
		$version = 100;
		self::assertSame( [ $expected ], $trace->entries() );
		$returned = $trace->entries();
		$returned[0]['reference_id'] = 999;
		$returned[0]['private_alias'] = 'private_marker';
		self::assertSame( [ $expected ], $trace->entries() );
		self::assertTrue( $trace->is_complete() );
	}

	private static function entry( ?int $id = null ): array {
		return [
			'stage' => 'configuration',
			'reason_code' => 'configuration_ready',
			'reference_kind' => null !== $id ? 'configuration_scope' : null,
			'reference_id' => $id,
			'reference_version' => null,
		];
	}
}
