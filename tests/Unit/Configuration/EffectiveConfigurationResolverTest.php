<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\PassthroughFulfilmentConstraintService;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationReasonCode;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use PHPUnit\Framework\TestCase;

final class EffectiveConfigurationResolverTest extends TestCase {

	private InMemoryScopedConfigurationRepository $repository;
	private EffectiveConfigurationResolver $resolver;

	protected function setUp(): void {
		$this->repository = new InMemoryScopedConfigurationRepository();
		$this->resolver   = new EffectiveConfigurationResolver(
			$this->repository,
			new EffectiveConfigurationValidator(),
			new PassthroughFulfilmentConstraintService()
		);
	}

	public function test_scalar_inheritance_matrix_and_zero_preservation(): void {
		$this->save_global(
			[
				ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override(
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
					FulfilmentAvailability::InStore->value
				),
				ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::override(
					ConfigurationFieldKey::FULFILMENT_CHOICE,
					FulfilmentChoice::Delivery->value
				),
				ConfigurationFieldKey::LOGISTICS_PROFILE_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::LOGISTICS_PROFILE_ID, 10 ),
				ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::SUPPLIER_ID, 20 ),
				ConfigurationFieldKey::ORIGIN_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::ORIGIN_ID, 30 ),
				ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 5 ),
			],
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1, 2 ] ),
			]
		);
		$this->save_product(
			101,
			'in_store',
			[
				ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::inherit( ConfigurationFieldKey::FULFILMENT_CHOICE ),
				ConfigurationFieldKey::LOGISTICS_PROFILE_ID => ScalarFieldInstruction::disable( ConfigurationFieldKey::LOGISTICS_PROFILE_ID ),
				ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::SUPPLIER_ID, 21 ),
				ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 0 ),
			]
		);
		$this->save_variation(
			101,
			202,
			'in_store',
			[
				ConfigurationFieldKey::ORIGIN_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::ORIGIN_ID, 31 ),
			]
		);

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, 202, 'in_store' ) );

		self::assertSame( EffectiveFieldState::Valid, $config->state );
		self::assertSame( FulfilmentAvailability::InStore->value, $config->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY )?->value );
		self::assertSame( FulfilmentChoice::Delivery->value, $config->scalar( ConfigurationFieldKey::FULFILMENT_CHOICE )?->value );
		self::assertSame( EffectiveFieldState::Disabled, $config->scalar( ConfigurationFieldKey::LOGISTICS_PROFILE_ID )?->state );
		self::assertNull( $config->scalar( ConfigurationFieldKey::LOGISTICS_PROFILE_ID )?->value );
		self::assertSame( 21, $config->scalar( ConfigurationFieldKey::SUPPLIER_ID )?->value );
		self::assertSame( 31, $config->scalar( ConfigurationFieldKey::ORIGIN_ID )?->value );
		self::assertSame( 0, $config->scalar( ConfigurationFieldKey::PRIORITY )?->value );
		self::assertSame( 'explicit_disable', $config->scalar( ConfigurationFieldKey::LOGISTICS_PROFILE_ID )?->provenance->source_label );
	}

	public function test_missing_global_values_remain_unresolved_not_defaults(): void {
		$this->repository->ensureGlobalScope();

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, null, '' ) );

		self::assertSame( EffectiveFieldState::Unresolved, $config->state );
		self::assertSame( EffectiveFieldState::Unresolved, $config->scalar( ConfigurationFieldKey::PRIORITY )?->state );
		self::assertContains( ConfigurationReasonCode::UNRESOLVED_GLOBAL_VALUE, $config->reason_codes );
		self::assertNull( $config->scalar( ConfigurationFieldKey::PRIORITY )?->value );
	}

	public function test_collection_mutation_matrix_and_replace_empty(): void {
		$this->save_global(
			$this->required_global_scalars(),
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1, 2, 3 ] ),
			]
		);
		$this->save_product(
			101,
			'in_store',
			[],
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::add( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 3, 4, 5 ] ),
			]
		);
		$this->save_variation(
			101,
			202,
			'in_store',
			[],
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::remove( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 2, 5 ] ),
			]
		);

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, 202, 'in_store' ) );

		self::assertSame( [ 1, 3, 4 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
		self::assertCount( 3, $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->mutation_steps );
		self::assertSame( 'variation', $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->provenance->source_label );

		$this->save_variation(
			101,
			203,
			'in_store',
			[],
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [] ),
			]
		);

		$replace_empty = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, 203, 'in_store' ) );

		self::assertSame( EffectiveFieldState::Valid, $replace_empty->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->state );
		self::assertSame( [], $replace_empty->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
	}

	public function test_invalid_collection_operation_on_unresolved_root(): void {
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				ConfigurationScope::global(),
				[],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::add( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1 ] ),
				]
			)
		);

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, null, '' ) );

		self::assertSame( EffectiveFieldState::Invalid, $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->state );
		self::assertContains( ConfigurationReasonCode::INVALID_COLLECTION_OPERATION, $config->reason_codes );
	}

	public function test_slice_resolution_never_cross_merges(): void {
		$this->save_global( $this->required_global_scalars(), [
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1 ] ),
		] );
		$this->save_product( 101, 'in_store', [
			ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 1 ),
		] );
		$this->save_product( 101, 'international_fulfilment', [
			ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 9 ),
		] );

		$in_store      = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, null, 'in_store' ) );
		$international = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, null, 'international_fulfilment' ) );
		$all           = $this->resolver->resolveAll( 101 );

		self::assertSame( 1, $in_store->scalar( ConfigurationFieldKey::PRIORITY )?->value );
		self::assertSame( 9, $international->scalar( ConfigurationFieldKey::PRIORITY )?->value );
		self::assertNotSame( $in_store->version->fingerprint, $international->version->fingerprint );
		self::assertSame( [ 'in_store', 'international_fulfilment' ], $all->ordered_slice_keys );
	}

	public function test_resolve_all_returns_default_slice_when_no_product_slices_exist(): void {
		$this->save_global( $this->required_global_scalars(), [
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1 ] ),
		] );

		$set = $this->resolver->resolveAll( 101 );

		self::assertSame( [ '' ], $set->ordered_slice_keys );
		self::assertNotNull( $set->for_slice( '' ) );
	}

	public function test_variation_overrides_one_field_only(): void {
		$this->save_global( $this->required_global_scalars(), [
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1 ] ),
		] );
		$this->save_product( 101, 'in_store', [
			ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::SUPPLIER_ID, 200 ),
			ConfigurationFieldKey::ORIGIN_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::ORIGIN_ID, 300 ),
		] );
		$this->save_variation( 101, 202, 'in_store', [
			ConfigurationFieldKey::ORIGIN_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::ORIGIN_ID, 301 ),
		] );

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, 202, 'in_store' ) );

		self::assertSame( 200, $config->scalar( ConfigurationFieldKey::SUPPLIER_ID )?->value );
		self::assertSame( 'product', $config->scalar( ConfigurationFieldKey::SUPPLIER_ID )?->provenance->source_label );
		self::assertSame( 301, $config->scalar( ConfigurationFieldKey::ORIGIN_ID )?->value );
		self::assertSame( 'variation', $config->scalar( ConfigurationFieldKey::ORIGIN_ID )?->provenance->source_label );
	}

	public function test_variation_parent_mismatch_returns_invalid_configuration(): void {
		$this->save_global( $this->required_global_scalars(), [
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1 ] ),
		] );
		$this->save_variation( 999, 202, 'in_store', [
			ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 1 ),
		] );

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, 202, 'in_store' ) );

		self::assertSame( EffectiveFieldState::Invalid, $config->state );
		self::assertContains( ConfigurationReasonCode::INVALID_SCOPE_RELATIONSHIP, $config->reason_codes );
	}

	public function test_version_and_fingerprint_change_with_effective_values(): void {
		$this->save_global( $this->required_global_scalars(), [
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1 ] ),
		] );

		$first = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, null, '' ) );
		$this->save_global(
			array_merge(
				$this->required_global_scalars(),
				[
					ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 6 ),
				]
			),
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1 ] ),
			]
		);
		$this->resolver->clearMemoization();

		$second = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, null, '' ) );

		self::assertSame( 1, $first->version->global_version );
		self::assertSame( 2, $second->version->global_version );
		self::assertNotSame( $first->version->fingerprint, $second->version->fingerprint );
		self::assertSame( 6, $second->scalar( ConfigurationFieldKey::PRIORITY )?->value );
	}

	public function test_global_change_affects_inheriting_products_only(): void {
		$this->save_global( $this->required_global_scalars(), [
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1 ] ),
		] );
		$this->save_product( 101, '', [
			ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 99 ),
		] );

		$inheriting_before = $this->resolver->resolve( new EffectiveConfigurationRequest( 102, null, '' ) );
		$overridden_before = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, null, '' ) );

		$this->save_global(
			array_merge(
				$this->required_global_scalars(),
				[
					ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 8 ),
				]
			),
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1 ] ),
			]
		);
		$this->resolver->clearMemoization();

		$inheriting_after = $this->resolver->resolve( new EffectiveConfigurationRequest( 102, null, '' ) );
		$overridden_after = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, null, '' ) );

		self::assertSame( 5, $inheriting_before->scalar( ConfigurationFieldKey::PRIORITY )?->value );
		self::assertSame( 8, $inheriting_after->scalar( ConfigurationFieldKey::PRIORITY )?->value );
		self::assertSame( 99, $overridden_before->scalar( ConfigurationFieldKey::PRIORITY )?->value );
		self::assertSame( 99, $overridden_after->scalar( ConfigurationFieldKey::PRIORITY )?->value );
	}

	public function test_bounded_reads_and_purity(): void {
		$this->save_global( $this->required_global_scalars(), [
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1 ] ),
		] );
		$this->save_product( 101, 'in_store' );
		$this->save_variation( 101, 202, 'in_store' );
		$this->repository->resetReadCallCounts();
		$this->repository->resetWriteCalls();

		$this->resolver->resolve( new EffectiveConfigurationRequest( 101, 202, 'in_store' ) );
		$this->resolver->resolve( new EffectiveConfigurationRequest( 101, 202, 'in_store' ) );

		$counts = $this->repository->getReadCallCounts();
		self::assertSame( 1, $counts['getGlobalConfiguration'] ?? 0 );
		self::assertSame( 2, $counts['findByScope'] ?? 0 );
		self::assertSame( 0, $counts['findByScopeAndSlice'] ?? 0 );
		self::assertSame( 0, $this->repository->getWriteCalls() );
	}

	public function test_category_scope_is_not_available_to_resolver(): void {
		self::assertSame( 'category', ProductTargetType::Category->value );
		self::assertNull( ConfigurationScopeType::tryFrom( 'category' ) );
	}

	public function test_invalid_request_product_id_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		new EffectiveConfigurationRequest( 0 );
	}

	/**
	 * @return array<string, ScalarFieldInstruction>
	 */
	private function required_global_scalars(): array {
		return [
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override(
				ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
				FulfilmentAvailability::InStore->value
			),
			ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::override(
				ConfigurationFieldKey::FULFILMENT_CHOICE,
				FulfilmentChoice::Delivery->value
			),
			ConfigurationFieldKey::LOGISTICS_PROFILE_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::LOGISTICS_PROFILE_ID, 10 ),
			ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::SUPPLIER_ID, 20 ),
			ConfigurationFieldKey::ORIGIN_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::ORIGIN_ID, 30 ),
			ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 5 ),
		];
	}

	/**
	 * @param array<string, ScalarFieldInstruction>     $scalars
	 * @param array<string, CollectionFieldInstruction> $collections
	 */
	private function save_global( array $scalars, array $collections = [] ): ScopedConfiguration {
		return $this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				ConfigurationScope::global(),
				$scalars,
				$collections
			)
		);
	}

	/**
	 * @param array<string, ScalarFieldInstruction>     $scalars
	 * @param array<string, CollectionFieldInstruction> $collections
	 */
	private function save_product( int $product_id, string $slice_key, array $scalars = [], array $collections = [] ): ScopedConfiguration {
		return $this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Product,
					$product_id,
					$slice_key,
					null,
					RecordStatus::Active,
					1,
					ConfigurationSource::Native,
					null
				),
				$scalars,
				$collections
			)
		);
	}

	/**
	 * @param array<string, ScalarFieldInstruction>     $scalars
	 * @param array<string, CollectionFieldInstruction> $collections
	 */
	private function save_variation( int $product_id, int $variation_id, string $slice_key, array $scalars = [], array $collections = [] ): ScopedConfiguration {
		return $this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Variation,
					$variation_id,
					$slice_key,
					$product_id,
					RecordStatus::Active,
					1,
					ConfigurationSource::Native,
					null
				),
				$scalars,
				$collections
			)
		);
	}
}
