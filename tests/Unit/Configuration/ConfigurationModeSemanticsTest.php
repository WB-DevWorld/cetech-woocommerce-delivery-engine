<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\InvalidConfigurationException;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;
use PHPUnit\Framework\TestCase;

final class ConfigurationModeSemanticsTest extends TestCase {

	public function test_scalar_inherit_distinct_from_override(): void {
		$inherit  = ScalarFieldInstruction::inherit( ConfigurationFieldKey::FULFILMENT_CHOICE );
		$override = ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_CHOICE, 'delivery' );

		self::assertSame( ScalarConfigurationMode::Inherit, $inherit->mode );
		self::assertNull( $inherit->value );
		self::assertSame( ScalarConfigurationMode::Override, $override->mode );
		self::assertSame( 'delivery', $override->value );
		self::assertNotSame( $inherit->fingerprint(), $override->fingerprint() );
	}

	public function test_scalar_inherit_distinct_from_disable(): void {
		$inherit = ScalarFieldInstruction::inherit( ConfigurationFieldKey::SUPPLIER_ID );
		$disable = ScalarFieldInstruction::disable( ConfigurationFieldKey::SUPPLIER_ID );

		self::assertNotSame( $inherit->mode, $disable->mode );
		self::assertNotSame( $inherit->fingerprint(), $disable->fingerprint() );
	}

	public function test_override_zero_remains_zero(): void {
		$instruction = ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 0 );

		self::assertSame( 0, $instruction->value );
		self::assertSame( ScalarConfigurationMode::Override, $instruction->mode );
	}

	public function test_invalid_scalar_mode_rejected(): void {
		$this->expectException( InvalidConfigurationException::class );

		ScalarFieldInstruction::create(
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
			ScalarConfigurationMode::Disable,
			null
		);
	}

	public function test_disable_rejected_where_unsupported(): void {
		$this->expectException( InvalidConfigurationException::class );

		ScalarFieldInstruction::disable( ConfigurationFieldKey::FULFILMENT_CHOICE );
	}

	public function test_collection_inherit_distinct_from_replace_empty(): void {
		$inherit = CollectionFieldInstruction::inherit( ConfigurationFieldKey::DELIVERY_OFFER_IDS );
		$replace = CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [] );

		self::assertSame( CollectionConfigurationMode::Inherit, $inherit->mode );
		self::assertSame( CollectionConfigurationMode::Replace, $replace->mode );
		self::assertSame( [], $inherit->members );
		self::assertSame( [], $replace->members );
		self::assertNotSame( $inherit->fingerprint(), $replace->fingerprint() );
	}

	public function test_collection_add_remove_replace_validation(): void {
		$add     = CollectionFieldInstruction::add( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 3, 1, 3, 2 ] );
		$remove  = CollectionFieldInstruction::remove( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 2, 2 ] );
		$replace = CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 9, 8 ] );

		self::assertSame( [ 3, 1, 2 ], $add->members );
		self::assertSame( [ 2 ], $remove->members );
		self::assertSame( [ 9, 8 ], $replace->members );
	}

	public function test_collection_ordering_preserved_and_duplicates_deduped(): void {
		$instruction = CollectionFieldInstruction::replace(
			ConfigurationFieldKey::DELIVERY_OFFER_IDS,
			[ 10, 20, 10, 30 ]
		);

		self::assertSame( [ 10, 20, 30 ], $instruction->members );
	}

	public function test_invalid_collection_values_rejected(): void {
		$this->expectException( InvalidConfigurationException::class );

		CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 'abc' ] );
	}

	public function test_non_numeric_priority_rejected_not_coerced_to_zero(): void {
		$this->expectException( InvalidConfigurationException::class );

		ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 'not-a-number' );
	}

	public function test_fulfilment_availability_override_round_trip_value(): void {
		$instruction = ScalarFieldInstruction::override(
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
			FulfilmentAvailability::InternationalFulfilment->value
		);

		self::assertSame(
			FulfilmentAvailability::InternationalFulfilment->value,
			$instruction->value
		);
	}
}
