<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Contracts;

use CetechDeliveryEngine\Domain\Contracts\DecisionTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecisionTargetTest extends TestCase {

	public function test_exact_identity_distinguishes_site_parent_variation_and_opened_slice(): void {
		$target = new DecisionTarget( 1, 12, 34, 'warehouse' );
		self::assertTrue( $target->equals( new DecisionTarget( 1, 12, 34, 'warehouse' ) ) );
		foreach ( [
			new DecisionTarget( 2, 12, 34, 'warehouse' ),
			new DecisionTarget( 1, 13, 34, 'warehouse' ),
			new DecisionTarget( 1, 12, 35, 'warehouse' ),
			new DecisionTarget( 1, 12, null, 'warehouse' ),
			new DecisionTarget( 1, 12, 34, 'pickup' ),
			new DecisionTarget( 1, 12, 34, '' ),
			new DecisionTarget( 1, 12, 34 ),
		] as $different ) {
			self::assertFalse( $target->equals( $different ) );
		}
		self::assertSame( 'product:12:variation:34:slice:warehouse', $target->key() );
		self::assertSame( 'product:12', ( new DecisionTarget( 1, 12 ) )->key() );
		self::assertSame( 'product:12:slice:', ( new DecisionTarget( 1, 12, null, '' ) )->key() );
		self::assertFalse( ( new DecisionTarget( 1, 12 ) )->equals( new DecisionTarget( 1, 12, null, '' ) ) );
	}

	#[DataProvider( 'invalid_identities' )]
	public function test_invalid_identity_has_safe_error( int $site, int $product, ?int $variation, ?string $slice ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Decision target identity is invalid.' );
		new DecisionTarget( $site, $product, $variation, $slice );
	}

	public static function invalid_identities(): array {
		return [
			[ 0, 12, null, null ], [ -1, 12, null, null ],
			[ 1, 0, null, null ], [ 1, -12, null, null ],
			[ 1, 12, 0, null ], [ 1, 12, -34, null ],
			[ 1, 12, null, 'SELECT private_token' ],
			[ 1, 12, null, 'Warehouse' ],
			[ 1, 12, null, '1warehouse' ],
			[ 1, 12, null, str_repeat( 'a', 65 ) ],
			[ 1, 12, null, "warehouse\n" ],
		];
	}

	public function test_input_references_and_representations_cannot_change_identity(): void {
		$site = 1;
		$product = 12;
		$variation = 34;
		$slice = 'warehouse';
		$arguments = [ &$site, &$product, &$variation, &$slice ];
		$target = new DecisionTarget( ...$arguments );
		$site = 9;
		$product = 99;
		$variation = 98;
		$slice = 'pickup';
		self::assertTrue( $target->equals( new DecisionTarget( 1, 12, 34, 'warehouse' ) ) );
		self::assertSame( 'product:12:variation:34:slice:warehouse', $target->key() );
		$this->expectException( \Error::class );
		$target->product_id = 99;
	}
}
