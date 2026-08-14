<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Runtime;

use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteRequest;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class RateQuoteSafetyTest extends TestCase {

	public function test_non_numeric_base_amount_never_becomes_free(): void {
		$engine = new RateQuoteEngine( new FixedRateCardRepository( [
			[
				'id'                   => 1,
				'internal_code'        => 'BAD',
				'delivery_offer_id'    => 10,
				'destination_zone_id'  => 20,
				'logistics_profile_id' => null,
				'supplier_id'          => null,
				'origin_id'            => null,
				'charge_type'          => RateCardChargeType::FixedPerShipment->value,
				'base_amount'          => 'banana',
				'base_currency'        => 'USD',
				'priority'             => 100,
				'status'               => 'active',
			],
		] ) );

		$result = $engine->quote(
			RateQuoteRequest::fromArray(
				[
					'delivery_offer_id'   => 10,
					'destination_zone_id' => 20,
					'quantity'            => 1,
					'currency_code'       => 'USD',
				]
			)
		);

		self::assertFalse( $result->success );
		self::assertSame( RateQuoteEngine::ERROR_INVALID_AMOUNT, $result->error_code );
		self::assertNull( $result->amount );
	}

	public function test_explicit_numeric_zero_remains_configured_zero(): void {
		$engine = new RateQuoteEngine( new FixedRateCardRepository( [
			[
				'id'                   => 2,
				'internal_code'        => 'FREE',
				'delivery_offer_id'    => 10,
				'destination_zone_id'  => 20,
				'logistics_profile_id' => null,
				'supplier_id'          => null,
				'origin_id'            => null,
				'charge_type'          => RateCardChargeType::FixedPerShipment->value,
				'base_amount'          => '0',
				'base_currency'        => 'USD',
				'priority'             => 100,
				'status'               => 'active',
			],
		] ) );

		$result = $engine->quote(
			RateQuoteRequest::fromArray(
				[
					'delivery_offer_id'   => 10,
					'destination_zone_id' => 20,
					'quantity'            => 1,
					'currency_code'       => 'USD',
				]
			)
		);

		self::assertTrue( $result->success );
		self::assertSame( '0.0000', $result->amount?->amount() );
	}

	public function test_qty_one_fixed_per_shipment_does_not_multiply_base_amount(): void {
		$engine = new RateQuoteEngine( new FixedRateCardRepository( [
			[
				'id'                   => 25,
				'internal_code'        => 'QA25',
				'delivery_offer_id'    => 10,
				'destination_zone_id'  => 20,
				'logistics_profile_id' => null,
				'supplier_id'          => null,
				'origin_id'            => null,
				'charge_type'          => RateCardChargeType::FixedPerShipment->value,
				'base_amount'          => '25.00',
				'base_currency'        => 'USD',
				'priority'             => 100,
				'status'               => 'active',
			],
		] ) );

		$result = $engine->quote(
			RateQuoteRequest::fromArray(
				[
					'delivery_offer_id'   => 10,
					'destination_zone_id' => 20,
					'quantity'            => 1,
					'currency_code'       => 'USD',
				]
			)
		);

		self::assertTrue( $result->success );
		self::assertSame( '25.0000', $result->amount?->amount() );
	}

	public function test_configured_base_amount_250_quotes_250_not_rewritten(): void {
		$engine = new RateQuoteEngine( new FixedRateCardRepository( [
			[
				'id'                   => 250,
				'internal_code'        => 'QA250',
				'delivery_offer_id'    => 10,
				'destination_zone_id'  => 20,
				'logistics_profile_id' => null,
				'supplier_id'          => null,
				'origin_id'            => null,
				'charge_type'          => RateCardChargeType::FixedPerShipment->value,
				'base_amount'          => '250.00',
				'base_currency'        => 'USD',
				'priority'             => 100,
				'status'               => 'active',
			],
		] ) );

		$result = $engine->quote(
			RateQuoteRequest::fromArray(
				[
					'delivery_offer_id'   => 10,
					'destination_zone_id' => 20,
					'quantity'            => 1,
					'currency_code'       => 'USD',
				]
			)
		);

		self::assertTrue( $result->success );
		self::assertSame( '250.0000', $result->amount?->amount() );
	}

	public function test_missing_base_amount_is_unavailable(): void {
		$engine = new RateQuoteEngine( new FixedRateCardRepository( [
			[
				'id'                   => 3,
				'internal_code'        => 'MISS',
				'delivery_offer_id'    => 10,
				'destination_zone_id'  => 20,
				'charge_type'          => RateCardChargeType::FixedPerShipment->value,
				'base_currency'        => 'USD',
				'priority'             => 100,
				'status'               => 'active',
			],
		] ) );

		$result = $engine->quote(
			RateQuoteRequest::fromArray(
				[
					'delivery_offer_id'   => 10,
					'destination_zone_id' => 20,
					'quantity'            => 1,
					'currency_code'       => 'USD',
				]
			)
		);

		self::assertFalse( $result->success );
		self::assertSame( RateQuoteEngine::ERROR_INVALID_AMOUNT, $result->error_code );
	}

	public function test_expected_twenty_five_quote_parity_fixture(): void {
		$engine = new RateQuoteEngine( new FixedRateCardRepository( [
			[
				'id'                   => 4,
				'internal_code'        => 'STAGE0B',
				'delivery_offer_id'    => 10,
				'destination_zone_id'  => 20,
				'charge_type'          => RateCardChargeType::FixedPerShipment->value,
				'base_amount'          => '25.00',
				'base_currency'        => 'USD',
				'priority'             => 100,
				'status'               => 'active',
			],
		] ) );

		$result = $engine->quote(
			RateQuoteRequest::fromArray(
				[
					'delivery_offer_id'   => 10,
					'destination_zone_id' => 20,
					'quantity'            => 1,
					'currency_code'       => 'USD',
				]
			)
		);

		self::assertTrue( $result->success );
		self::assertSame( '25.0000', $result->amount?->amount() );
	}

	public function test_format_decimal_rejects_non_numeric(): void {
		$this->expectException( \InvalidArgumentException::class );
		\CetechDeliveryEngine\Domain\RateCard\RateCardAmountFormatter::format( 'banana' );
	}

	public function test_format_decimal_preserves_explicit_zero(): void {
		self::assertSame( '0.0000', \CetechDeliveryEngine\Domain\RateCard\RateCardAmountFormatter::format( 0 ) );
		self::assertSame( '25.0000', \CetechDeliveryEngine\Domain\RateCard\RateCardAmountFormatter::format( 25 ) );
	}
}

final class FixedRateCardRepository implements RateCardRepositoryInterface {

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	public function __construct( private array $rows ) {
	}

	public function findById( int $id ): ?array {
		foreach ( $this->rows as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $id ) {
				return $row;
			}
		}

		return null;
	}

	public function findByCode( string $code ): ?array {
		return null;
	}

	public function save( array $data ): int {
		return 0;
	}

	public function list( array $criteria = [] ): array {
		return $this->rows;
	}

	public function softDelete( int $id ): bool {
		return false;
	}

	public function hardDelete( int $id ): bool {
		return false;
	}

	public function count_all(): int {
		return count( $this->rows );
	}

	public function countByDeliveryOfferId( int $delivery_offer_id ): int {
		return 0;
	}

	public function countByDestinationZoneId( int $destination_zone_id ): int {
		return 0;
	}

	public function countOrderSnapshotReferences( int $rate_card_id ): int {
		return 0;
	}

	public function countByLogisticsProfileId( int $logistics_profile_id ): int {
		return 0;
	}

	public function listActiveForQuoteMatch(
		int $delivery_offer_id,
		int $destination_zone_id,
		string $currency_code
	): array {
		$out = [];

		foreach ( $this->rows as $row ) {
			if (
				(int) ( $row['delivery_offer_id'] ?? 0 ) === $delivery_offer_id
				&& (int) ( $row['destination_zone_id'] ?? 0 ) === $destination_zone_id
				&& strtoupper( (string) ( $row['base_currency'] ?? '' ) ) === strtoupper( $currency_code )
				&& 'active' === (string) ( $row['status'] ?? '' )
			) {
				$out[] = $row;
			}
		}

		return $out;
	}
}

