<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;
use CetechDeliveryEngine\Presentation\Admin\Validation\RateCardValidator;
use PHPUnit\Framework\TestCase;

final class RateCardValidatorOptionalDatesTest extends TestCase {

	public function test_missing_effective_dates_do_not_warn_or_fail(): void {
		$warnings = $this->capture_warnings(
			function () use ( &$errors ): void {
				$errors = $this->validator()->validate( $this->valid_input() );
			}
		);

		self::assertSame( [], $warnings );
		self::assertArrayNotHasKey( 'effective_from', $errors );
		self::assertArrayNotHasKey( 'effective_to', $errors );
	}

	public function test_only_effective_from_present_does_not_warn(): void {
		$warnings = $this->capture_warnings(
			function () use ( &$errors ): void {
				$errors = $this->validator()->validate(
					$this->valid_input( [ 'effective_from' => '2026-01-01' ] )
				);
			}
		);

		self::assertSame( [], $warnings );
		self::assertArrayNotHasKey( 'effective_from', $errors );
		self::assertArrayNotHasKey( 'effective_to', $errors );
	}

	public function test_only_effective_to_present_does_not_warn(): void {
		$warnings = $this->capture_warnings(
			function () use ( &$errors ): void {
				$errors = $this->validator()->validate(
					$this->valid_input( [ 'effective_to' => '2026-12-31' ] )
				);
			}
		);

		self::assertSame( [], $warnings );
		self::assertArrayNotHasKey( 'effective_from', $errors );
		self::assertArrayNotHasKey( 'effective_to', $errors );
	}

	public function test_both_valid_dates_pass(): void {
		$warnings = $this->capture_warnings(
			function () use ( &$errors ): void {
				$errors = $this->validator()->validate(
					$this->valid_input(
						[
							'effective_from' => '2026-01-01',
							'effective_to'   => '2026-12-31',
						]
					)
				);
			}
		);

		self::assertSame( [], $warnings );
		self::assertSame( [], $errors );
	}

	public function test_invalid_supplied_dates_still_fail(): void {
		$errors = $this->validator()->validate(
			$this->valid_input(
				[
					'effective_from' => 'not-a-date',
					'effective_to'   => 'also-bad',
				]
			)
		);

		self::assertArrayHasKey( 'effective_from', $errors );
		self::assertArrayHasKey( 'effective_to', $errors );
	}

	public function test_from_after_to_fails_without_treating_dates_as_prices(): void {
		$errors = $this->validator()->validate(
			$this->valid_input(
				[
					'effective_from' => '2026-12-31',
					'effective_to'   => '2026-01-01',
				]
			)
		);

		self::assertArrayHasKey( 'effective_from', $errors );
		self::assertArrayNotHasKey( 'base_amount', $errors );
	}

	public function test_blank_date_strings_are_treated_as_not_supplied(): void {
		$warnings = $this->capture_warnings(
			function () use ( &$errors ): void {
				$errors = $this->validator()->validate(
					$this->valid_input(
						[
							'effective_from' => '   ',
							'effective_to'   => '',
						]
					)
				);
			}
		);

		self::assertSame( [], $warnings );
		self::assertArrayNotHasKey( 'effective_from', $errors );
		self::assertArrayNotHasKey( 'effective_to', $errors );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function valid_input( array $overrides = [] ): array {
		return array_merge(
			[
				'code'                 => 'accra-standard',
				'delivery_offer_id'    => 1,
				'destination_zone_id'  => 1,
				'charge_type'          => RateCardChargeType::FixedPerShipment->value,
				'base_amount'          => '10.00',
				'currency_code'        => 'GHS',
				'priority'             => '10',
				'status'               => RecordStatus::Active->value,
			],
			$overrides
		);
	}

	private function validator(): RateCardValidator {
		$found = [ 'id' => 1, 'supplier_id' => 1 ];

		$offers = $this->createMock( DeliveryOfferRepositoryInterface::class );
		$offers->method( 'findById' )->willReturn( $found );
		$zones = $this->createMock( DestinationZoneRepositoryInterface::class );
		$zones->method( 'findById' )->willReturn( $found );
		$profiles = $this->createMock( LogisticsProfileRepositoryInterface::class );
		$profiles->method( 'findById' )->willReturn( $found );
		$suppliers = $this->createMock( SupplierRepositoryInterface::class );
		$suppliers->method( 'findById' )->willReturn( $found );
		$origins = $this->createMock( OriginRepositoryInterface::class );
		$origins->method( 'findById' )->willReturn( $found );

		return new RateCardValidator( $offers, $zones, $profiles, $suppliers, $origins );
	}

	/**
	 * @param callable(): void $callback
	 * @return list<string>
	 */
	private function capture_warnings( callable $callback ): array {
		$warnings = [];
		set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
				if ( E_WARNING === $errno || E_NOTICE === $errno || E_USER_WARNING === $errno ) {
					$warnings[] = $errstr;
				}

				return true;
			}
		);

		try {
			$callback();
		} finally {
			restore_error_handler();
		}

		return $warnings;
	}
}
