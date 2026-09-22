<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Shared;

use CetechDeliveryEngine\Presentation\Shared\CartDeliveryUiAnchor;
use PHPUnit\Framework\TestCase;

final class CartDeliveryUiAnchorTest extends TestCase {

	public function test_anchor_is_deterministic_html_safe_and_contains_no_pii(): void {
		$key     = 'aaa111bbbb2222cccc3333';
		$first   = CartDeliveryUiAnchor::for_cart_item_key( $key );
		$second  = CartDeliveryUiAnchor::for_cart_item_key( $key );
		$other   = CartDeliveryUiAnchor::for_cart_item_key( 'dddd4444eeee5555ffff6666' );
		$street  = '12 Independence Avenue';

		self::assertSame( $first, $second );
		self::assertNotSame( $first, $other );
		self::assertTrue( CartDeliveryUiAnchor::is_valid( $first ) );
		self::assertMatchesRegularExpression( '/^cetech-de-delivery-[a-f0-9]{16}$/', $first );
		self::assertStringNotContainsString( $key, $first );
		self::assertStringNotContainsString( $street, $first );
		self::assertStringNotContainsString( 'Ama', $first );
		self::assertStringNotContainsString( '0244000000', $first );
		self::assertSame(
			'https://example.test/cart/#' . $first,
			CartDeliveryUiAnchor::cart_url( 'https://example.test/cart/#stale', $first )
		);
		self::assertSame( $first . '-form', CartDeliveryUiAnchor::form_id_for_cart_item_key( $key ) );
		self::assertSame( $first . '-reselect', CartDeliveryUiAnchor::reselection_form_id_for_cart_item_key( $key ) );
		self::assertSame( 'https://example.test/cart/', CartDeliveryUiAnchor::cart_url( 'https://example.test/cart/', '' ) );
	}
}
