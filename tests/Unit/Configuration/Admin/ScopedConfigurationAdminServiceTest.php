<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\EntityLabelResolver;
use CetechDeliveryEngine\Application\Configuration\Admin\LegacyCategoryConfigurationInspector;
use CetechDeliveryEngine\Application\Configuration\Admin\ProductVariationScopeGuard;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAdminService;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationNotices;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationSubmissionParser;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationWriteCommand;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\PassthroughFulfilmentConstraintService;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use PHPUnit\Framework\TestCase;

final class ScopedConfigurationAdminServiceTest extends TestCase {

	private InMemoryScopedConfigurationRepository $repository;
	private EffectiveConfigurationResolver $resolver;
	private RecordingConfigurationAuditLogger $audit;
	private ScopedConfigurationAdminService $service;

	protected function setUp(): void {
		$this->repository = new InMemoryScopedConfigurationRepository();
		$this->resolver   = new EffectiveConfigurationResolver(
			$this->repository,
			new EffectiveConfigurationValidator(),
			new PassthroughFulfilmentConstraintService()
		);
		$this->audit = new RecordingConfigurationAuditLogger();

		$legacy = new class() implements ProductDeliveryRuleRepositoryInterface {
			public array $category_rules = [];

			public function findById( int $id ): ?array {
				return null;
			}

			public function findByTarget( string $target_type, int $target_id ): array {
				return [];
			}

			public function findByTargetAndAvailability( string $target_type, int $target_id, string $availability ): array {
				return [];
			}

			public function list( array $filters = [] ): array {
				if ( ( $filters['target_type'] ?? '' ) === ProductTargetType::Category->value ) {
					return $this->category_rules;
				}

				return [];
			}

			public function listActive( array $filters = [] ): array {
				return $this->list( $filters );
			}

			public function findActiveByTargets( array $targets ): array {
				$out = [];
				foreach ( $this->category_rules as $rule ) {
					foreach ( $targets as $target ) {
						if (
							(string) ( $rule['target_type'] ?? '' ) === (string) ( $target['target_type'] ?? '' )
							&& (int) ( $rule['target_id'] ?? 0 ) === (int) ( $target['target_id'] ?? 0 )
						) {
							$out[] = $rule;
						}
					}
				}

				return $out;
			}

			public function save( array $data ): int {
				return 0;
			}

			public function deactivate( int $id ): bool {
				return false;
			}

			public function hardDelete( int $id ): bool {
				return false;
			}

			public function count_all(): int {
				return 0;
			}

			public function countBySupplierId( int $supplier_id ): int {
				return 0;
			}

			public function countByOriginId( int $origin_id ): int {
				return 0;
			}

			public function countByLogisticsProfileId( int $logistics_profile_id ): int {
				return 0;
			}
		};

		$legacy->category_rules = [
			[
				'id'          => 77,
				'target_type' => ProductTargetType::Category->value,
				'target_id'   => 5,
				'status'      => RecordStatus::Active->value,
			],
		];

		$this->service = new ScopedConfigurationAdminService(
			$this->repository,
			$this->resolver,
			new ScopedConfigurationSubmissionParser(),
			new ProductVariationScopeGuard(),
			new EntityLabelResolver(),
			new LegacyCategoryConfigurationInspector( $legacy ),
			$this->audit,
			static fn ( int $variation_id, int $parent_product_id ): bool => 202 === $variation_id && 101 === $parent_product_id
		);
	}

	public function test_global_configured_and_unresolved_states(): void {
		$this->save_global_baseline();

		$model = $this->service->load_edit_model( ConfigurationScopeType::Global, 0 );
		$by_key = [];
		foreach ( $model->fields as $field ) {
			$by_key[ $field->field_key ] = $field;
		}

		self::assertSame( 'Configured', $by_key[ ConfigurationFieldKey::PRIORITY ]->configured_state_label );
		self::assertSame( ScopedConfigurationNotices::TRANSITIONAL_MESSAGE, $model->transitional_message );
		self::assertTrue( $model->category_warning['has_legacy_category_rules'] );
	}

	public function test_product_inherit_override_disable_and_preview_uses_resolver(): void {
		$this->save_global_baseline();
		$audits_before = count( $this->audit->calls );

		$save = $this->service->save(
			new ScopedConfigurationWriteCommand(
				ConfigurationScopeType::Product,
				101,
				'in_store',
				null,
				[
					ConfigurationFieldKey::FULFILMENT_CHOICE => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::LOGISTICS_PROFILE_ID => [ 'mode' => 'disable' ],
					ConfigurationFieldKey::SUPPLIER_ID => [ 'mode' => 'override', 'value' => '21' ],
					ConfigurationFieldKey::ORIGIN_ID => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::PRIORITY => [ 'mode' => 'override', 'value' => '0' ],
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'add', 'members' => [ '3' ] ],
				],
				true
			)
		);

		self::assertTrue( $save->success, implode( '; ', $save->errors ) );
		self::assertTrue( $save->version_changed );
		self::assertCount( $audits_before + 1, $this->audit->calls );

		$model = $this->service->load_edit_model( ConfigurationScopeType::Product, 101, 'in_store' );
		$by_key = [];
		foreach ( $model->fields as $field ) {
			$by_key[ $field->field_key ] = $field;
		}

		self::assertSame( 'disable', $by_key[ ConfigurationFieldKey::LOGISTICS_PROFILE_ID ]->current_mode );
		self::assertSame( 'disabled', $by_key[ ConfigurationFieldKey::LOGISTICS_PROFILE_ID ]->effective_state );
		self::assertSame( 'Explicitly disabled', $by_key[ ConfigurationFieldKey::LOGISTICS_PROFILE_ID ]->provenance_label );
		self::assertSame( 'override', $by_key[ ConfigurationFieldKey::PRIORITY ]->current_mode );
		self::assertSame( 0, $by_key[ ConfigurationFieldKey::PRIORITY ]->effective_value );

		$preview = $this->service->preview( 101, null, 'in_store' );
		self::assertTrue( $preview->used_authoritative_resolver );
		self::assertSame( ScopedConfigurationNotices::PREVIEW_LIMITATION_TITLE, $preview->limitation_title );

		$direct = $this->resolver->resolve(
			new \CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest( 101, null, 'in_store' )
		);

		$preview_priority = null;
		$preview_offers   = null;
		foreach ( $preview->fields as $field ) {
			if ( ConfigurationFieldKey::PRIORITY === $field['field_key'] ) {
				$preview_priority = $field['effective_value'];
			}
			if ( ConfigurationFieldKey::DELIVERY_OFFER_IDS === $field['field_key'] ) {
				$preview_offers = $field['effective_members'];
			}
		}

		self::assertSame( $direct->scalar( ConfigurationFieldKey::PRIORITY )?->value, $preview_priority );
		self::assertSame( $direct->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members, $preview_offers );
		self::assertSame( [ 1, 2, 3 ], $preview_offers );
	}

	public function test_collection_replace_empty_and_variation_mutations(): void {
		$this->save_global_baseline();

		$this->service->save(
			new ScopedConfigurationWriteCommand(
				ConfigurationScopeType::Product,
				101,
				'in_store',
				null,
				[
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::FULFILMENT_CHOICE => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::LOGISTICS_PROFILE_ID => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::SUPPLIER_ID => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::ORIGIN_ID => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::PRIORITY => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'add', 'members' => [ '4' ] ],
				],
				true
			)
		);

		$this->service->save(
			new ScopedConfigurationWriteCommand(
				ConfigurationScopeType::Variation,
				202,
				'in_store',
				101,
				[
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::FULFILMENT_CHOICE => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::LOGISTICS_PROFILE_ID => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::SUPPLIER_ID => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::ORIGIN_ID => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::PRIORITY => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'remove', 'members' => [ '2' ] ],
				],
				true
			)
		);

		$preview = $this->service->preview( 101, 202, 'in_store', 101 );
		$offers  = null;
		$lines   = [];
		foreach ( $preview->fields as $field ) {
			if ( ConfigurationFieldKey::DELIVERY_OFFER_IDS === $field['field_key'] ) {
				$offers = $field['effective_members'];
				$lines  = $field['provenance_lines'];
			}
		}

		self::assertSame( [ 1, 3, 4 ], $offers );
		self::assertNotEmpty( $lines );

		// REPLACE [] at product.
		$this->service->save(
			new ScopedConfigurationWriteCommand(
				ConfigurationScopeType::Product,
				101,
				'in_store',
				null,
				[
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::FULFILMENT_CHOICE => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::LOGISTICS_PROFILE_ID => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::SUPPLIER_ID => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::ORIGIN_ID => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::PRIORITY => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => [] ],
				],
				true
			)
		);

		$preview2 = $this->service->preview( 101, null, 'in_store' );
		foreach ( $preview2->fields as $field ) {
			if ( ConfigurationFieldKey::DELIVERY_OFFER_IDS === $field['field_key'] ) {
				self::assertSame( [], $field['effective_members'] );
				self::assertSame( 'valid', $field['effective_state'] );
			}
		}
	}

	public function test_identical_save_no_version_or_audit_noise(): void {
		$this->save_global_baseline();
		$fields = $this->global_all_fields_payload();

		$first = $this->service->save(
			new ScopedConfigurationWriteCommand(
				ConfigurationScopeType::Global,
				0,
				'',
				null,
				$fields
			)
		);
		self::assertTrue( $first->success );
		$version = $first->version_after;
		$audits  = count( $this->audit->calls );

		$second = $this->service->save(
			new ScopedConfigurationWriteCommand(
				ConfigurationScopeType::Global,
				0,
				'',
				null,
				$fields
			)
		);

		self::assertTrue( $second->success );
		self::assertFalse( $second->version_changed );
		self::assertSame( $version, $second->version_after );
		self::assertCount( $audits, $this->audit->calls );
	}

	public function test_preview_does_not_audit_or_write(): void {
		$this->save_global_baseline();
		$writes_before = $this->repository->getWriteCalls();
		$audits_before = count( $this->audit->calls );

		$this->service->preview( 101, null, 'in_store' );

		self::assertSame( $writes_before, $this->repository->getWriteCalls() );
		self::assertCount( $audits_before, $this->audit->calls );
	}

	public function test_forged_variation_relationship_rejected(): void {
		$result = $this->service->save(
			new ScopedConfigurationWriteCommand(
				ConfigurationScopeType::Variation,
				999,
				'in_store',
				101,
				[
					ConfigurationFieldKey::PRIORITY => [ 'mode' => 'override', 'value' => '1' ],
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::FULFILMENT_CHOICE => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::LOGISTICS_PROFILE_ID => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::SUPPLIER_ID => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::ORIGIN_ID => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'inherit' ],
				],
				true
			)
		);

		self::assertFalse( $result->success );
		self::assertStringContainsString( 'does not belong', implode( ' ', $result->errors ) );
	}

	public function test_category_warning_for_affected_product(): void {
		$warning = $this->service->load_edit_model(
			ConfigurationScopeType::Product,
			101,
			'in_store',
			null,
			null,
			null,
			[ 5 ]
		)->category_warning;

		self::assertTrue( $warning['has_legacy_category_rules'] );
		self::assertContains( 77, $warning['rule_ids'] );
	}

	public function test_disable_preview_does_not_show_disabled_value_as_effective(): void {
		$this->save_global_baseline();
		$this->service->save(
			new ScopedConfigurationWriteCommand(
				ConfigurationScopeType::Product,
				101,
				'in_store',
				null,
				[
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::FULFILMENT_CHOICE => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::LOGISTICS_PROFILE_ID => [ 'mode' => 'disable' ],
					ConfigurationFieldKey::SUPPLIER_ID => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::ORIGIN_ID => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::PRIORITY => [ 'mode' => 'inherit' ],
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'inherit' ],
				],
				true
			)
		);

		$preview = $this->service->preview( 101, null, 'in_store' );
		foreach ( $preview->fields as $field ) {
			if ( ConfigurationFieldKey::LOGISTICS_PROFILE_ID === $field['field_key'] ) {
				self::assertSame( 'disabled', $field['effective_state'] );
				self::assertSame( 'Disabled / None', $field['effective_value_label'] );
				self::assertNull( $field['effective_value'] );
			}
		}
	}

	public function test_unresolved_preview_does_not_fabricate_free(): void {
		$this->repository->ensureGlobalScope();
		$preview = $this->service->preview( 101, null, '' );

		foreach ( $preview->fields as $field ) {
			if ( ConfigurationFieldKey::PRIORITY === $field['field_key'] ) {
				self::assertSame( 'unresolved', $field['effective_state'] );
				self::assertNull( $field['effective_value'] );
			}
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function global_all_fields_payload(): array {
		return [
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [
				'mode'  => 'override',
				'value' => FulfilmentAvailability::InStore->value,
			],
			ConfigurationFieldKey::FULFILMENT_CHOICE => [
				'mode'  => 'override',
				'value' => FulfilmentChoice::Delivery->value,
			],
			ConfigurationFieldKey::LOGISTICS_PROFILE_ID => [
				'mode'  => 'override',
				'value' => '10',
			],
			ConfigurationFieldKey::SUPPLIER_ID => [
				'mode'  => 'override',
				'value' => '20',
			],
			ConfigurationFieldKey::ORIGIN_ID => [
				'mode'  => 'override',
				'value' => '30',
			],
			ConfigurationFieldKey::PRIORITY => [
				'mode'  => 'override',
				'value' => '5',
			],
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => [
				'mode'    => 'replace',
				'members' => [ '1', '2', '3' ],
			],
		];
	}

	private function save_global_baseline(): void {
		$result = $this->service->save(
			new ScopedConfigurationWriteCommand(
				ConfigurationScopeType::Global,
				0,
				'',
				null,
				$this->global_all_fields_payload()
			)
		);

		self::assertTrue( $result->success, implode( '; ', $result->errors ) );
	}
}
