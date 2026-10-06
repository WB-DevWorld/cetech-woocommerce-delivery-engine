<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Contracts;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use PHPUnit\Framework\TestCase;

final class OperationIdentityTest extends TestCase {

	public function test_namespace_has_a_fixed_unambiguous_fixture(): void {
		$identity = $this->identity();
		self::assertSame(
			hash( 'sha256', 'cetech-operation-namespace-v1:[4,"wp-admin","user:8","scope.save",1,"product:23","original-token"]' ),
			$identity->namespace_digest()
		);
		self::assertSame( $identity->namespace_digest(), $this->identity()->namespace_digest() );
	}

	public function test_each_authority_target_version_and_key_dimension_is_isolated(): void {
		$baseline = $this->identity()->namespace_digest();
		$dimensions = [
			[ 5, 'wp-admin', 'user:8', 'scope.save', 1, 'product:23', 'original-token' ],
			[ 4, 'integration', 'user:8', 'scope.save', 1, 'product:23', 'original-token' ],
			[ 4, 'wp-admin', 'user:9', 'scope.save', 1, 'product:23', 'original-token' ],
			[ 4, 'wp-admin', 'user:8', 'scope.reset', 1, 'product:23', 'original-token' ],
			[ 4, 'wp-admin', 'user:8', 'scope.save', 2, 'product:23', 'original-token' ],
			[ 4, 'wp-admin', 'user:8', 'scope.save', 1, 'product:24', 'original-token' ],
			[ 4, 'wp-admin', 'user:8', 'scope.save', 1, 'product:23', 'another-token' ],
		];
		$digests = [ $baseline ];
		foreach ( $dimensions as $arguments ) {
			$digest = ( new OperationIdentity( ...$arguments ) )->namespace_digest();
			self::assertNotSame( $baseline, $digest );
			$digests[] = $digest;
		}
		self::assertCount( 8, array_unique( $digests ) );
	}

	public function test_tuple_boundaries_do_not_collide_and_opaque_values_are_not_trimmed(): void {
		$left = new OperationIdentity( 4, 'ab', 'c', 'scope.save', 1, 'product:23', 'original-token' );
		$right = new OperationIdentity( 4, 'a', 'bc', 'scope.save', 1, 'product:23', 'original-token' );
		self::assertNotSame( $left->namespace_digest(), $right->namespace_digest() );
		self::assertNotSame( $left->namespace_digest(), ( new OperationIdentity( 4, 'AB', 'c', 'scope.save', 1, 'product:23', 'original-token' ) )->namespace_digest() );
	}

	public function test_idempotency_key_is_not_a_public_field(): void {
		self::assertArrayNotHasKey( 'idempotency_key', get_object_vars( $this->identity() ) );
	}

	public function test_identity_is_immutable(): void {
		$identity = $this->identity();
		$this->expectException( \Error::class );
		$identity->target_key = 'product:99';
	}

	public function test_invalid_dimensions_are_rejected_without_echoing_the_input(): void {
		$invalid_arguments = [
			[ 0, 'wp-admin', 'user:8', 'scope.save', 1, 'product:23', 'original-token' ],
			[ 4, 'wp-admin', 'user:8', 'scope.save', 0, 'product:23', 'original-token' ],
			[ 4, '', 'user:8', 'scope.save', 1, 'product:23', 'original-token' ],
			[ 4, 'wp-admin', '', 'scope.save', 1, 'product:23', 'original-token' ],
			[ 4, 'wp-admin', 'user:8', 'scope.save', 1, '', 'original-token' ],
			[ 4, 'wp-admin', 'user:8', 'scope.save', 1, 'product:23', '' ],
			[ 4, 'wp-admin', 'user:8', 'Bad.Operation', 1, 'product:23', 'original-token' ],
			[ 4, 'wp-admin', 'user:8', 'scope.save', 1, 'product:23', ' private-secret ' ],
			[ 4, 'wp-admin', "private-secret\n", 'scope.save', 1, 'product:23', 'original-token' ],
			[ 4, 'wp-admin', 'user:8', 'scope.save', 1, 'product:23', "private-secret\x80" ],
			[ 4, str_repeat( 'x', 129 ), 'user:8', 'scope.save', 1, 'product:23', 'original-token' ],
			[ 4, 'wp-admin', str_repeat( 'x', 257 ), 'scope.save', 1, 'product:23', 'original-token' ],
			[ 4, 'wp-admin', 'user:8', 'scope.save', 1, str_repeat( 'x', 513 ), 'original-token' ],
			[ 4, 'wp-admin', 'user:8', 'scope.save', 1, 'product:23', str_repeat( 'x', 257 ) ],
		];
		foreach ( $invalid_arguments as $arguments ) {
			try {
				new OperationIdentity( ...$arguments );
				self::fail( 'Invalid operation identity was accepted.' );
			} catch ( \InvalidArgumentException $error ) {
				self::assertStringNotContainsString( 'private-secret', $error->getMessage() );
			}
		}
	}

	public function test_valid_boundary_lengths_are_retained(): void {
		$identity = new OperationIdentity( 1, str_repeat( 'a', 128 ), str_repeat( 'p', 256 ), str_repeat( 'o', 96 ), 1, str_repeat( 't', 512 ), str_repeat( 'k', 256 ) );
		self::assertSame( 64, strlen( $identity->namespace_digest() ) );
	}

	private function identity(): OperationIdentity {
		return new OperationIdentity( 4, 'wp-admin', 'user:8', 'scope.save', 1, 'product:23', 'original-token' );
	}
}
