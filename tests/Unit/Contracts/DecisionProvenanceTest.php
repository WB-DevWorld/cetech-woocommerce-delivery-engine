<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Contracts;

use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Contracts\DecisionProvenance;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecisionProvenanceTest extends TestCase {

	public function test_known_configuration_fields_project_only_explicit_provenance_facts(): void {
		foreach ( ConfigurationFieldKey::all() as $field ) {
			$provenance = new DecisionProvenance( $field, EffectiveFieldState::Valid, ConfigurationScopeType::Variation, 'variation', 31, 8 );
			self::assertSame( [
				'field_key'             => $field,
				'state'                 => 'valid',
				'source_scope'          => 'variation',
				'source_label'          => 'variation',
				'scope_row_id'          => 31,
				'configuration_version' => 8,
			], $provenance->admin_fields() );
		}
	}

	public function test_actual_default_unresolved_constraint_and_invalid_disable_sources_remain_representable(): void {
		$cases = [
			new DecisionProvenance( 'priority', EffectiveFieldState::Unresolved, null, 'global' ),
			new DecisionProvenance( 'priority', EffectiveFieldState::Valid, null, 'system_default' ),
			new DecisionProvenance( 'fulfilment_choice', EffectiveFieldState::Invalid, null, 'hard_constraint' ),
			new DecisionProvenance( 'priority', EffectiveFieldState::Invalid, ConfigurationScopeType::Product, 'explicit_disable', 8, 0 ),
			new DecisionProvenance( 'delivery_offer_ids', EffectiveFieldState::Disabled, ConfigurationScopeType::Global, 'explicit_disable', 9, 3 ),
			new DecisionProvenance( 'priority', EffectiveFieldState::Valid, ConfigurationScopeType::Global, 'global', null, 0 ),
		];
		self::assertNull( $cases[0]->admin_fields()['configuration_version'] );
		self::assertNull( $cases[1]->admin_fields()['scope_row_id'] );
		self::assertSame( 'hard_constraint', $cases[2]->admin_fields()['source_label'] );
		self::assertSame( 'invalid', $cases[3]->admin_fields()['state'] );
		self::assertSame( 'explicit_disable', $cases[3]->admin_fields()['source_label'] );
		self::assertSame( 0, $cases[3]->admin_fields()['configuration_version'] );
		self::assertSame( 'disabled', $cases[4]->admin_fields()['state'] );
		self::assertSame( 0, $cases[5]->admin_fields()['configuration_version'] );
	}

	#[DataProvider( 'incoherent_sources' )]
	public function test_unrecognized_or_incoherent_source_is_rejected_without_echo( string $field, ?ConfigurationScopeType $scope, string $label, ?int $row, ?int $version ): void {
		try {
			new DecisionProvenance( $field, EffectiveFieldState::Valid, $scope, $label, $row, $version );
			self::fail( 'Invalid provenance was accepted.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame( 'Decision provenance is invalid.', $exception->getMessage() );
			self::assertNull( $exception->getPrevious() );
		}
	}

	public static function incoherent_sources(): array {
		return [
			[ 'private_marker SELECT token', ConfigurationScopeType::Product, 'product', 1, 1 ],
			[ 'priority', ConfigurationScopeType::Product, 'private_marker SELECT token', 1, 1 ],
			[ 'priority', ConfigurationScopeType::Product, 'variation', 1, 1 ],
			[ 'priority', ConfigurationScopeType::Global, 'product', 1, 1 ],
			[ 'priority', null, 'product', null, null ],
			[ 'priority', null, 'explicit_disable', null, null ],
			[ 'priority', null, 'global', 1, null ],
			[ 'priority', null, 'global', null, 0 ],
			[ 'priority', ConfigurationScopeType::Product, 'system_default', null, null ],
			[ 'priority', null, 'system_default', 1, null ],
			[ 'priority', null, 'system_default', null, 0 ],
			[ 'priority', ConfigurationScopeType::Global, 'hard_constraint', null, null ],
			[ 'priority', null, 'hard_constraint', 1, null ],
			[ 'priority', null, 'hard_constraint', null, 0 ],
			[ 'priority', ConfigurationScopeType::Product, 'product', 0, 1 ],
			[ 'priority', ConfigurationScopeType::Product, 'product', -1, 1 ],
			[ 'priority', ConfigurationScopeType::Product, 'product', 1, -1 ],
		];
	}

	public function test_referenced_input_and_mutated_admin_array_do_not_change_recorded_provenance(): void {
		$field = 'priority';
		$state = EffectiveFieldState::Valid;
		$scope = ConfigurationScopeType::Product;
		$label = 'product';
		$row = 31;
		$version = 8;
		$arguments = [ &$field, &$state, &$scope, &$label, &$row, &$version ];
		$provenance = new DecisionProvenance( ...$arguments );
		$field = 'supplier_id';
		$state = EffectiveFieldState::Invalid;
		$scope = ConfigurationScopeType::Variation;
		$label = 'variation';
		$row = 99;
		$version = 100;
		$expected = ( new DecisionProvenance( 'priority', EffectiveFieldState::Valid, ConfigurationScopeType::Product, 'product', 31, 8 ) )->admin_fields();
		self::assertSame( $expected, $provenance->admin_fields() );
		$rendered = $provenance->admin_fields();
		$rendered['scope_row_id'] = 999;
		$rendered['value'] = 'private_marker';
		self::assertSame( $expected, $provenance->admin_fields() );
	}
}
