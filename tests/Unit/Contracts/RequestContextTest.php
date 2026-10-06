<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Contracts;

use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestContextTest extends TestCase {

	public function test_absent_correlation_generates_separate_canonical_uuid_identifiers(): void {
		$first = RequestContext::create();
		$second = RequestContext::create();
		foreach ( [ $first->request_id, $first->correlation_id, $second->request_id, $second->correlation_id ] as $identifier ) {
			self::assertTrue( RequestContext::is_valid_identifier( $identifier ) );
			self::assertSame( '4', $identifier[14] );
		}
		self::assertCount( 4, array_unique( [ $first->request_id, $first->correlation_id, $second->request_id, $second->correlation_id ] ) );
	}

	public function test_caller_correlation_links_attempts_without_selecting_request_identity(): void {
		$correlation = '12345678-1234-7123-8123-123456789abc';
		$first = RequestContext::create( $correlation );
		$second = RequestContext::create( $correlation );
		$child = $first->child();
		self::assertSame( $correlation, $first->correlation_id );
		self::assertSame( $correlation, $second->correlation_id );
		self::assertSame( $correlation, $child->correlation_id );
		self::assertCount( 3, array_unique( [ $first->request_id, $second->request_id, $child->request_id ] ) );
		self::assertNotSame( $correlation, $first->request_id );
		self::assertTrue( RequestContext::is_valid_identifier( $child->request_id ) );
		self::assertSame( [ 'request_id', 'correlation_id' ], array_keys( get_object_vars( $first ) ) );
	}

	#[DataProvider( 'invalid_correlations' )]
	public function test_invalid_caller_correlation_is_replaced_without_echo_or_coercion( mixed $input ): void {
		self::assertFalse( RequestContext::is_valid_identifier( $input ) );
		$context = RequestContext::create( $input );
		self::assertTrue( RequestContext::is_valid_identifier( $context->request_id ) );
		self::assertTrue( RequestContext::is_valid_identifier( $context->correlation_id ) );
		self::assertNotSame( $input, $context->correlation_id );
		self::assertStringNotContainsString( 'private-marker', json_encode( get_object_vars( $context ), JSON_THROW_ON_ERROR ) );
		self::assertStringNotContainsString( 'SELECT', json_encode( get_object_vars( $context ), JSON_THROW_ON_ERROR ) );
	}

	public static function invalid_correlations(): array {
		return [
			[ null ], [ '' ], [ false ], [ 123 ], [ [] ],
			[ [ 'private-marker' => 'SELECT secrets' ] ],
			[ 'private-marker SELECT secrets WHERE token=secret' ],
			[ str_repeat( 'private-marker', 10000 ) ],
			[ '12345678-1234-4123-8123-123456789ABC' ],
			[ '00000000-0000-0000-0000-000000000000' ],
			[ '12345678-1234-9123-8123-123456789abc' ],
			[ '12345678-1234-4123-7123-123456789abc' ],
			[ "12345678-1234-4123-8123-123456789abc\n" ],
			[ '12345678123441238123123456789abc' ],
			[ new class() {
				public function __toString(): string {
					throw new \RuntimeException( 'private-marker must never be coerced.' );
				}
			} ],
		];
	}

	public function test_request_and_correlation_identifiers_cannot_be_mutated(): void {
		$context = RequestContext::create();
		foreach ( [ 'request_id', 'correlation_id' ] as $field ) {
			$original = $context->$field;
			try {
				$context->$field = '12345678-1234-4123-8123-123456789abc';
				self::fail( 'Immutable request context was modified.' );
			} catch ( \Error ) {
				self::assertSame( $original, $context->$field );
			}
		}
	}
}
