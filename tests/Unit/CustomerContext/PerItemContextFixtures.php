<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\CustomerContext;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\CustomerContext\RecipientContact;

final class PerItemContextFixtures {

	/**
	 * @return array<string, string>
	 */
	public static function accraMatching(): array {
		return [
			'country'  => 'gh',
			'state'    => 'aa',
			'city'     => '  Accra  ',
			'postcode' => 'ga-123',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function eastLegonAddress( string $street = '12 Boundary Rd' ): array {
		return array_merge(
			self::accraMatching(),
			[
				'city'       => 'East Legon',
				'postcode'   => 'GA-000',
				'address_1'  => $street,
				'address_2'  => 'House 4',
				'first_name' => 'Ama',
				'last_name'  => 'Mensah',
				'company'    => 'CETECH',
				'phone'      => '0244000000',
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function spintexAddress(): array {
		return array_merge(
			self::accraMatching(),
			[
				'city'       => 'Spintex',
				'postcode'   => 'GA-111',
				'address_1'  => '88 Spintex Road',
				'address_2'  => '',
				'first_name' => 'Ama',
				'last_name'  => 'Mensah',
				'company'    => 'CETECH',
				'phone'      => '0244000000',
			]
		);
	}

	public static function matchingGhanaCountry(): MatchingLocation {
		return MatchingLocation::fromInput(
			[
				'country'  => 'GH',
				'state'    => '',
				'city'     => '',
				'postcode' => '',
			]
		);
	}

	public static function matchingBoston(): MatchingLocation {
		return MatchingLocation::fromInput(
			[
				'country'  => 'US',
				'state'    => 'MA',
				'city'     => 'Boston',
				'postcode' => '02108',
			]
		);
	}

	public static function emptyMatchingContext( int $offer_id ): CustomerCartContext {
		return CustomerCartContext::delivery( $offer_id, null, null );
	}

	public static function matchingAccra(): MatchingLocation {
		return MatchingLocation::fromInput( self::accraMatching() );
	}

	public static function matchingKumasi(): MatchingLocation {
		return MatchingLocation::fromInput(
			[
				'country'  => 'GH',
				'state'    => 'AH',
				'city'     => 'Kumasi',
				'postcode' => 'AK-000',
			]
		);
	}

	public static function deliveryKumasi( string $street = '7 Lake Rd' ): DeliveryAddress {
		return DeliveryAddress::fromInput(
			[
				'country'    => 'GH',
				'state'      => 'AH',
				'city'       => 'Kumasi',
				'postcode'   => 'AK-000',
				'address_1'  => $street,
				'address_2'  => '',
				'first_name' => 'Ama',
				'last_name'  => 'Mensah',
				'company'    => 'CETECH',
				'phone'      => '0244000000',
			]
		);
	}

	public static function deliveryAccraStreet( string $street ): DeliveryAddress {
		return DeliveryAddress::fromInput(
			array_merge(
				self::accraMatching(),
				[
					'address_1'  => $street,
					'address_2'  => '',
					'first_name' => 'Ama',
					'last_name'  => 'Mensah',
					'company'    => 'CETECH',
					'phone'      => '0244000000',
				]
			)
		);
	}

	public static function deliveryEastLegon( string $street = '12 Boundary Rd' ): DeliveryAddress {
		return DeliveryAddress::fromInput( self::eastLegonAddress( $street ) );
	}

	public static function deliverySpintex(): DeliveryAddress {
		return DeliveryAddress::fromInput( self::spintexAddress() );
	}

	public static function deliveryContext( int $offer_id, DeliveryAddress $address ): CustomerCartContext {
		return CustomerCartContext::delivery( $offer_id, $address->matching, $address );
	}

	public static function incompleteContext( int $offer_id, MatchingLocation $matching ): CustomerCartContext {
		return CustomerCartContext::delivery( $offer_id, $matching, null );
	}

	/**
	 * @param array<string, mixed> $intent
	 * @param array<string, mixed> $extra
	 *
	 * @return array<string, mixed>
	 */
	public static function cartItem( array $intent, CustomerCartContext $context, int $quantity = 1, array $extra = [] ): array {
		$item = array_merge(
			[
				'product_id'   => (int) ( $intent['product_id'] ?? 101 ),
				'variation_id' => (int) ( $intent['variation_id'] ?? 0 ),
				'variation'    => is_array( $intent['variation'] ?? null ) ? $intent['variation'] : [],
				'quantity'     => $quantity,
				CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent,
			],
			$extra
		);

		return $context->applyToCartItem( $item );
	}

	public static function recipient( string $first = 'Kojo', string $phone = '0200000000' ): RecipientContact {
		return new RecipientContact( $first, 'Boateng', 'Other Co', $phone );
	}
}
