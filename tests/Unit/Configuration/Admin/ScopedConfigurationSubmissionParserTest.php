<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationSubmissionParser;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;
use PHPUnit\Framework\TestCase;

final class ScopedConfigurationSubmissionParserTest extends TestCase {

	private ScopedConfigurationSubmissionParser $parser;

	protected function setUp(): void {
		$this->parser = new ScopedConfigurationSubmissionParser();
	}

	public function test_empty_override_value_is_rejected_not_inherit(): void {
		$result = $this->parser->parse(
			ConfigurationScopeType::Product,
			[
				ConfigurationFieldKey::PRIORITY => [
					'mode'  => 'override',
					'value' => '',
				],
			]
		);

		self::assertFalse( $result['ok'] );
		self::assertStringContainsString( 'empty input is not inherit', implode( ' ', $result['errors'] ) );
	}

	public function test_empty_mode_on_submitted_product_field_is_rejected(): void {
		$result = $this->parser->parse(
			ConfigurationScopeType::Product,
			[
				ConfigurationFieldKey::PRIORITY => [
					'mode'  => '',
					'value' => '1',
				],
			]
		);

		self::assertFalse( $result['ok'] );
		self::assertStringContainsString( 'explicit mode', implode( ' ', $result['errors'] ) );
	}

	public function test_invalid_numeric_is_rejected_not_zero(): void {
		$result = $this->parser->parse(
			ConfigurationScopeType::Product,
			[
				ConfigurationFieldKey::PRIORITY => [
					'mode'  => 'override',
					'value' => 'abc',
				],
			]
		);

		self::assertFalse( $result['ok'] );
		self::assertStringContainsString( 'rejected', implode( ' ', $result['errors'] ) );
	}

	public function test_zero_priority_is_valid_override(): void {
		$result = $this->parser->parse(
			ConfigurationScopeType::Product,
			[
				ConfigurationFieldKey::PRIORITY => [
					'mode'  => 'override',
					'value' => '0',
				],
			]
		);

		self::assertTrue( $result['ok'], implode( '; ', $result['errors'] ) );
		self::assertSame( 0, $result['scalars'][ ConfigurationFieldKey::PRIORITY ]->value );
	}

	public function test_unsupported_disable_rejected(): void {
		$result = $this->parser->parse(
			ConfigurationScopeType::Product,
			[
				ConfigurationFieldKey::PRIORITY => [
					'mode' => 'disable',
				],
			]
		);

		self::assertFalse( $result['ok'] );
	}

	public function test_replace_empty_is_distinct_from_inherit(): void {
		$replace = $this->parser->parse(
			ConfigurationScopeType::Product,
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => [
					'mode'    => 'replace',
					'members' => [],
				],
			]
		);
		$inherit = $this->parser->parse(
			ConfigurationScopeType::Product,
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => [
					'mode' => 'inherit',
				],
			]
		);

		self::assertTrue( $replace['ok'] );
		self::assertTrue( $inherit['ok'] );
		self::assertArrayHasKey( ConfigurationFieldKey::DELIVERY_OFFER_IDS, $replace['collections'] );
		self::assertSame( CollectionConfigurationMode::Replace, $replace['collections'][ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->mode );
		self::assertSame( [], $replace['collections'][ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->members );
		self::assertArrayNotHasKey( ConfigurationFieldKey::DELIVERY_OFFER_IDS, $inherit['collections'] );
	}

	public function test_unknown_field_key_rejected(): void {
		$result = $this->parser->parse(
			ConfigurationScopeType::Global,
			[
				'not_a_real_field' => [ 'mode' => 'override', 'value' => '1' ],
			]
		);

		self::assertFalse( $result['ok'] );
	}

	public function test_global_not_configured_omits_instruction(): void {
		$result = $this->parser->parse(
			ConfigurationScopeType::Global,
			[
				ConfigurationFieldKey::PRIORITY => [
					'mode' => 'not_configured',
				],
			]
		);

		self::assertTrue( $result['ok'], implode( '; ', $result['errors'] ) );
		self::assertArrayNotHasKey( ConfigurationFieldKey::PRIORITY, $result['scalars'] );
	}

	public function test_product_inherit_omits_instruction(): void {
		$result = $this->parser->parse(
			ConfigurationScopeType::Product,
			[
				ConfigurationFieldKey::SUPPLIER_ID => [
					'mode' => 'inherit',
				],
			]
		);

		self::assertTrue( $result['ok'] );
		self::assertArrayNotHasKey( ConfigurationFieldKey::SUPPLIER_ID, $result['scalars'] );
	}

	public function test_collection_order_preserved(): void {
		$result = $this->parser->parse(
			ConfigurationScopeType::Global,
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => [
					'mode'    => 'replace',
					'members' => [ '30', '10', '20' ],
				],
			]
		);

		self::assertTrue( $result['ok'] );
		self::assertSame( [ 30, 10, 20 ], $result['collections'][ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->members );
	}
}
