<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Application\Bulk\Portability\ConfigImportConflictMode;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationImporter;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationPackage;
use CetechDeliveryEngine\Application\Bulk\Portability\ImportPackageGuard;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardBulkAmountMath;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class PortabilityAndRateMathTest extends TestCase {

	public function test_package_round_trip_and_rejects_unsupported_version(): void {
		$package = ConfigurationPackage::create( '1.0.0-dev.bulk.1', '5', [ 'delivery_options' => [ [ 'internal_code' => 'air_shipping' ] ] ], false );
		$json    = $package->to_json();
		$loaded  = ConfigurationPackage::from_json( $json );
		self::assertSame( 'air_shipping', $loaded->sections['delivery_options'][0]['internal_code'] );

		$this->expectException( \InvalidArgumentException::class );
		ConfigurationPackage::from_json( wp_json_encode( [ 'manifest' => [ 'format_name' => ConfigurationPackage::FORMAT_NAME, 'format_version' => 99 ], 'sections' => [] ] ) ?: '{}' );
	}

	public function test_malformed_json_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		ConfigurationPackage::from_json( '{not-json' );
	}

	public function test_zip_slip_and_nested_archive_are_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		ImportPackageGuard::assert_safe_zip_entry( '../wp-config.php' );
	}

	public function test_nested_zip_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		ImportPackageGuard::assert_safe_zip_entry( 'inner.zip' );
	}

	public function test_percentage_update_does_not_coerce_malformed_to_zero(): void {
		$updated = RateCardBulkAmountMath::increase_percent( '40.0000', '7.5' );
		self::assertSame( '43.0000', $updated );
		$this->expectException( \InvalidArgumentException::class );
		RateCardBulkAmountMath::increase_percent( 'not-a-number', '7.5' );
	}

	public function test_explicit_zero_remains_zero_after_zero_percent(): void {
		self::assertSame( '0.0000', RateCardBulkAmountMath::increase_percent( '0', '0' ) );
	}

	public function test_historical_orders_are_not_default_export_sections(): void {
		$package = ConfigurationPackage::create( '1.0.0-dev.bulk.1', '5', [ 'delivery_options' => [] ], false );
		self::assertArrayNotHasKey( 'orders', $package->sections );
		self::assertArrayNotHasKey( 'shipments', $package->sections );
		self::assertFalse( $package->manifest['include_private_sources'] );
	}

	public function test_importer_omits_private_and_transactional_sections(): void {
		$importer = new ConfigurationImporter(
			$this->createStub( DeliveryOfferRepositoryInterface::class ),
			$this->createStub( DestinationZoneRepositoryInterface::class ),
			$this->createStub( DestinationRuleRepositoryInterface::class ),
			$this->createStub( RateCardRepositoryInterface::class ),
			$this->createStub( LogisticsProfileRepositoryInterface::class ),
			$this->createStub( PickupLocationRepositoryInterface::class ),
			$this->createStub( SupplierRepositoryInterface::class ),
			$this->createStub( OriginRepositoryInterface::class )
		);
		$private = $importer->apply_item(
			'suppliers',
			[ 'internal_code' => 'SUP-001' ],
			ConfigImportConflictMode::SkipConflicts,
			true,
			false
		);
		self::assertSame( 'skipped', $private['outcome'] );
		self::assertSame( 'private_source_omitted', $private['error_code'] );

		$orders = $importer->apply_item(
			'orders',
			[],
			ConfigImportConflictMode::SkipConflicts,
			true,
			true
		);
		self::assertSame( 'non_portable_section', $orders['error_code'] );
	}
}
