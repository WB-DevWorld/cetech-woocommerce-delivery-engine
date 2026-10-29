<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

use CetechDeliveryEngine\Domain\Configuration\CollectionMutationStep;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldRegistry;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationReasonCode;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\EffectiveCollectionField;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfiguration;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationSet;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationVersionDescriptor;
use CetechDeliveryEngine\Domain\Configuration\EffectiveScalarField;
use CetechDeliveryEngine\Domain\Configuration\FieldProvenance;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;

final class EffectiveConfigurationResolver {

	/** @var array<string, array<string, mixed>> */
	private array $loaded_contexts = [];

	/** @var array<string, EffectiveConfiguration> */
	private array $memoized = [];

	/** @var array<string, ScopedConfiguration|false> */
	private array $profile_global_cache = [];

	private ConfigurationFingerprintBuilder $fingerprint_builder;

	public function __construct(
		private readonly ScopedConfigurationRepositoryInterface $repository,
		private readonly EffectiveConfigurationValidator $validator,
		private readonly ?FulfilmentConstraintServiceInterface $constraint_service = null,
		private readonly ?SiteWideDefaultsPolicyInterface $sitewide_policy = null
	) {
		$this->fingerprint_builder = new ConfigurationFingerprintBuilder();
	}

	public function resolve( EffectiveConfigurationRequest $request ): EffectiveConfiguration {
		return $this->resolve_with( $request, null );
	}

	/**
	 * COR-012 (D3b): the configuration an item scope inherits, i.e. resolved as if that scope type
	 * had no saved instructions. Used to decide whether "Add"/"Remove" have a complete base.
	 */
	public function resolve_inherited_base( EffectiveConfigurationRequest $request, ConfigurationScopeType $excluded_scope_type ): EffectiveConfiguration {
		return $this->resolve_with( $request, $excluded_scope_type );
	}

	private function resolve_with( EffectiveConfigurationRequest $request, ?ConfigurationScopeType $excluded_scope_type ): EffectiveConfiguration {
		$context = $this->load_context( $request->product_id, $request->variation_id );

		$product_scope   = $this->select_item_scope( $context['product_by_slice'], $request->slice_key );
		$variation_scope = $this->select_item_scope( $context['variation_by_slice'], $request->slice_key );

		if ( ConfigurationScopeType::Product === $excluded_scope_type ) {
			$product_scope = null;
		}
		if ( ConfigurationScopeType::Variation === $excluded_scope_type ) {
			$variation_scope = null;
		}

		// COR-012 (D3a): inactive item scopes are ignored; remember the one that would have applied.
		$ignored_product   = $product_scope instanceof ScopedConfiguration || ConfigurationScopeType::Product === $excluded_scope_type
			? null
			: $this->select_item_scope( $context['inactive_product_by_slice'], $request->slice_key );
		$ignored_variation = $variation_scope instanceof ScopedConfiguration || ConfigurationScopeType::Variation === $excluded_scope_type
			? null
			: $this->select_item_scope( $context['inactive_variation_by_slice'], $request->slice_key );
		$global_scope    = $this->select_profile_global(
			$request,
			$product_scope instanceof ScopedConfiguration ? $product_scope : null,
			$variation_scope instanceof ScopedConfiguration ? $variation_scope : null,
			$context['global'] instanceof ScopedConfiguration ? $context['global'] : null
		);

		$product_version   = $product_scope instanceof ScopedConfiguration ? $product_scope->scope->config_version : 0;
		$variation_version = $variation_scope instanceof ScopedConfiguration ? $variation_scope->scope->config_version : 0;
		$global_version    = $global_scope instanceof ScopedConfiguration ? $global_scope->scope->config_version : 0;

		$memo_key = implode(
			'|',
			[
				$request->product_id,
				$request->variation_id ?? 0,
				$request->slice_key,
				$global_version,
				$product_version,
				$variation_version,
				$excluded_scope_type?->value ?? '',
				$ignored_product?->scope->id ?? 0,
				$ignored_variation?->scope->id ?? 0,
			]
		);

		if ( isset( $this->memoized[ $memo_key ] ) ) {
			return $this->apply_constraints( $this->memoized[ $memo_key ] );
		}

		if ( $this->has_invalid_variation_relationship( $request, $context['variation_scopes'] ) ) {
			$configuration = $this->invalid_configuration(
				$request,
				$global_version,
				$product_version,
				$variation_version,
				[ ConfigurationReasonCode::INVALID_SCOPE_RELATIONSHIP ]
			);

			$this->memoized[ $memo_key ] = $configuration;

			return $this->apply_constraints( $configuration );
		}

		$configuration = $this->build_configuration(
			$request,
			$global_scope instanceof ScopedConfiguration ? $global_scope : null,
			$product_scope instanceof ScopedConfiguration ? $product_scope : null,
			$variation_scope instanceof ScopedConfiguration ? $variation_scope : null,
			$global_version,
			$product_version,
			$variation_version,
			$ignored_product instanceof ScopedConfiguration ? $ignored_product : null,
			$ignored_variation instanceof ScopedConfiguration ? $ignored_variation : null
		);

		$this->memoized[ $memo_key ] = $configuration;

		return $this->apply_constraints( $configuration );
	}

	public function resolveAll( int $product_id, ?int $variation_id = null ): EffectiveConfigurationSet {
		$request_context = $this->load_context( $product_id, $variation_id );
		$slice_keys      = [];

		foreach ( $request_context['product_scopes'] as $configuration ) {
			if ( $configuration instanceof ScopedConfiguration ) {
				$slice_keys[ $configuration->scope->slice_key ] = true;
			}
		}

		foreach ( $request_context['variation_scopes'] as $configuration ) {
			if ( $configuration instanceof ScopedConfiguration ) {
				$slice_keys[ $configuration->scope->slice_key ] = true;
			}
		}

		if ( [] === $slice_keys ) {
			$slice_keys[ ConfigurationScope::DEFAULT_SLICE_KEY ] = true;
		}

		$ordered_slice_keys = array_keys( $slice_keys );
		sort( $ordered_slice_keys, SORT_STRING );

		if ( in_array( ConfigurationScope::DEFAULT_SLICE_KEY, $ordered_slice_keys, true ) ) {
			$ordered_slice_keys = array_values(
				array_unique(
					array_merge(
						[ ConfigurationScope::DEFAULT_SLICE_KEY ],
						$ordered_slice_keys
					)
				)
			);
		}

		$configurations = [];
		foreach ( $ordered_slice_keys as $slice_key ) {
			$configurations[ $slice_key ] = $this->resolve(
				new EffectiveConfigurationRequest( $product_id, $variation_id, $slice_key )
			);
		}

		return new EffectiveConfigurationSet( $product_id, $variation_id, $configurations, $ordered_slice_keys );
	}

	public function clearMemoization(): void {
		$this->loaded_contexts      = [];
		$this->memoized             = [];
		$this->profile_global_cache = [];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function load_context( int $product_id, ?int $variation_id ): array {
		$key = $product_id . '|' . ( $variation_id ?? 0 );

		if ( isset( $this->loaded_contexts[ $key ] ) ) {
			return $this->loaded_contexts[ $key ];
		}

		$global           = $this->repository->getGlobalConfiguration();
		$product_scopes   = $this->repository->findByScope( ConfigurationScopeType::Product, $product_id );
		$variation_scopes = null === $variation_id
			? []
			: $this->repository->findByScope( ConfigurationScopeType::Variation, $variation_id );

		// COR-012 (D3a): only active item scopes take part in resolution.
		$active_product     = $this->filter_by_status( $product_scopes, true );
		$active_variation   = $this->filter_by_status( $variation_scopes, true );
		$inactive_product   = $this->filter_by_status( $product_scopes, false );
		$inactive_variation = $this->filter_by_status( $variation_scopes, false );

		$context = [
			'global'                      => $global,
			'product_scopes'              => $active_product,
			'variation_scopes'            => $active_variation,
			'product_by_slice'            => $this->index_by_slice( $active_product ),
			'variation_by_slice'          => $this->index_by_slice( $active_variation ),
			'inactive_product_by_slice'   => $this->index_by_slice( $inactive_product ),
			'inactive_variation_by_slice' => $this->index_by_slice( $inactive_variation ),
		];

		$this->loaded_contexts[ $key ] = $context;

		return $context;
	}

	/**
	 * @param array<string, ScopedConfiguration> $by_slice
	 */
	private function select_item_scope( array $by_slice, string $slice_key ): ?ScopedConfiguration {
		if ( isset( $by_slice[ $slice_key ] ) ) {
			return $by_slice[ $slice_key ];
		}

		// Profile slice requested → fall back to the native default item scope.
		if ( ConfigurationScope::DEFAULT_SLICE_KEY !== $slice_key && isset( $by_slice[ ConfigurationScope::DEFAULT_SLICE_KEY ] ) ) {
			return $by_slice[ ConfigurationScope::DEFAULT_SLICE_KEY ];
		}

		// Stage 13 root (empty slice) → sole Stage 5/6 profile item scope.
		// Migrated variable parents often store business delivery on in_warehouse/etc.
		if ( ConfigurationScope::DEFAULT_SLICE_KEY === $slice_key ) {
			$profile_scopes = [];
			foreach ( $by_slice as $key => $scope ) {
				if ( ! $scope instanceof ScopedConfiguration ) {
					continue;
				}
				if ( ConfigurationScope::DEFAULT_SLICE_KEY !== $key && FulfilmentProfileRegistry::has( (string) $key ) ) {
					$profile_scopes[ (string) $key ] = $scope;
				}
			}
			if ( 1 === count( $profile_scopes ) ) {
				return reset( $profile_scopes ) ?: null;
			}
		}

		return null;
	}

	private function select_profile_global(
		EffectiveConfigurationRequest $request,
		?ScopedConfiguration $product_scope,
		?ScopedConfiguration $variation_scope,
		?ScopedConfiguration $fallback_global
	): ?ScopedConfiguration {
		$profile_key = $this->determine_profile_key( $request, $product_scope, $variation_scope );

		if ( null === $profile_key ) {
			return $fallback_global;
		}

		if ( ! array_key_exists( $profile_key, $this->profile_global_cache ) ) {
			$found = $this->repository->findByScopeAndSlice(
				ConfigurationScopeType::Global,
				ConfigurationScope::GLOBAL_SCOPE_ID,
				$profile_key
			);
			$this->profile_global_cache[ $profile_key ] = $found ?? false;
		}

		$profile_global = $this->profile_global_cache[ $profile_key ];

		return $profile_global instanceof ScopedConfiguration ? $profile_global : $fallback_global;
	}

	private function determine_profile_key(
		EffectiveConfigurationRequest $request,
		?ScopedConfiguration $product_scope,
		?ScopedConfiguration $variation_scope
	): ?string {
		foreach ( [ $variation_scope, $product_scope ] as $scope ) {
			if ( ! $scope instanceof ScopedConfiguration ) {
				continue;
			}

			$instruction = $scope->scalars[ ConfigurationFieldKey::FULFILMENT_AVAILABILITY ] ?? null;
			if (
				$instruction instanceof ScalarFieldInstruction
				&& ScalarConfigurationMode::Override === $instruction->mode
				&& is_string( $instruction->value )
				&& FulfilmentProfileRegistry::has( $instruction->value )
			) {
				return $instruction->value;
			}
		}

		if ( FulfilmentProfileRegistry::has( $request->slice_key ) ) {
			return $request->slice_key;
		}

		if (
			$product_scope instanceof ScopedConfiguration
			&& FulfilmentProfileRegistry::has( $product_scope->scope->slice_key )
		) {
			return $product_scope->scope->slice_key;
		}

		$primary = $this->sitewide_policy?->primary_profile_key();

		return null !== $primary && FulfilmentProfileRegistry::has( $primary ) ? $primary : null;
	}

	/**
	 * @param list<ScopedConfiguration> $configurations
	 *
	 * @return list<ScopedConfiguration>
	 */
	private function filter_by_status( array $configurations, bool $active ): array {
		$kept = [];

		foreach ( $configurations as $configuration ) {
			if ( ! $configuration instanceof ScopedConfiguration ) {
				continue;
			}
			if ( ( RecordStatus::Active === $configuration->scope->status ) === $active ) {
				$kept[] = $configuration;
			}
		}

		return $kept;
	}

	/**
	 * @param list<ScopedConfiguration> $configurations
	 *
	 * @return array<string, ScopedConfiguration>
	 */
	private function index_by_slice( array $configurations ): array {
		$indexed = [];

		foreach ( $configurations as $configuration ) {
			$indexed[ $configuration->scope->slice_key ] = $configuration;
		}

		return $indexed;
	}

	/**
	 * @param list<ScopedConfiguration> $variation_scopes
	 */
	private function has_invalid_variation_relationship( EffectiveConfigurationRequest $request, array $variation_scopes ): bool {
		if ( null === $request->variation_id ) {
			return false;
		}

		if ( null !== $request->parent_product_id && $request->parent_product_id !== $request->product_id ) {
			return true;
		}

		foreach ( $variation_scopes as $configuration ) {
			if ( $configuration->scope->parent_product_id !== $request->product_id ) {
				return true;
			}
		}

		return false;
	}

	private function build_configuration(
		EffectiveConfigurationRequest $request,
		?ScopedConfiguration $global_scope,
		?ScopedConfiguration $product_scope,
		?ScopedConfiguration $variation_scope,
		int $global_version,
		int $product_version,
		int $variation_version,
		?ScopedConfiguration $ignored_product = null,
		?ScopedConfiguration $ignored_variation = null
	): EffectiveConfiguration {
		$scalars     = $this->initial_scalars();
		$collections = $this->initial_collections();

		foreach ( [ $global_scope, $product_scope, $variation_scope ] as $scope_configuration ) {
			if ( ! $scope_configuration instanceof ScopedConfiguration ) {
				continue;
			}

			$scalars     = $this->apply_scalar_scope( $scalars, $scope_configuration );
			$collections = $this->apply_collection_scope( $collections, $scope_configuration );
		}

		foreach ( [ $ignored_product, $ignored_variation ] as $ignored ) {
			if ( $ignored instanceof ScopedConfiguration ) {
				[ $scalars, $collections ] = $this->note_ignored_inactive_scope( $scalars, $collections, $ignored );
			}
		}

		$version = new EffectiveConfigurationVersionDescriptor(
			$request->product_id,
			$request->variation_id,
			$request->slice_key,
			$global_version,
			$product_version,
			$variation_version,
			''
		);

		$configuration = new EffectiveConfiguration(
			$request->product_id,
			$request->variation_id,
			$request->slice_key,
			$scalars,
			$collections,
			EffectiveFieldState::Valid,
			[],
			$version
		);

		$validation    = $this->validator->validate( $configuration );
		$configuration = $configuration->with_validation( $validation->state, $validation->reason_codes );
		$fingerprint  = $this->fingerprint_builder->build(
			$request->product_id,
			$request->variation_id,
			$request->slice_key,
			$global_version,
			$product_version,
			$variation_version,
			$configuration->scalars,
			$configuration->collections
		);

		return $configuration->with_version(
			new EffectiveConfigurationVersionDescriptor(
				$request->product_id,
				$request->variation_id,
				$request->slice_key,
				$global_version,
				$product_version,
				$variation_version,
				$fingerprint
			)
		);
	}

	/**
	 * @return array<string, EffectiveScalarField>
	 */
	private function initial_scalars(): array {
		$scalars = [];

		foreach ( ConfigurationFieldRegistry::scalar_keys() as $field_key ) {
			$scalars[ $field_key ] = new EffectiveScalarField(
				$field_key,
				EffectiveFieldState::Unresolved,
				null,
				FieldProvenance::unresolved(),
				[ ConfigurationReasonCode::UNRESOLVED_GLOBAL_VALUE ]
			);
		}

		return $scalars;
	}

	/**
	 * @return array<string, EffectiveCollectionField>
	 */
	private function initial_collections(): array {
		$collections = [];

		foreach ( ConfigurationFieldRegistry::collection_keys() as $field_key ) {
			$collections[ $field_key ] = new EffectiveCollectionField(
				$field_key,
				EffectiveFieldState::Unresolved,
				[],
				FieldProvenance::unresolved(),
				[ ConfigurationReasonCode::UNRESOLVED_GLOBAL_VALUE ],
				[]
			);
		}

		return $collections;
	}

	/**
	 * COR-012 (D3a): mark every field the ignored inactive scope would have configured, so staff
	 * see "inactive" in the effective-value source without the value changing.
	 *
	 * @param array<string, EffectiveScalarField>     $scalars
	 * @param array<string, EffectiveCollectionField> $collections
	 *
	 * @return array{0: array<string, EffectiveScalarField>, 1: array<string, EffectiveCollectionField>}
	 */
	private function note_ignored_inactive_scope( array $scalars, array $collections, ScopedConfiguration $ignored ): array {
		$scope_type = $ignored->scope->scope_type;

		foreach ( $ignored->scalars as $field_key => $instruction ) {
			if ( ScalarConfigurationMode::Inherit === $instruction->mode || ! isset( $scalars[ $field_key ] ) ) {
				continue;
			}
			$field                 = $scalars[ $field_key ];
			$scalars[ $field_key ] = new EffectiveScalarField(
				$field->field_key,
				$field->state,
				$field->value,
				$field->provenance->withIgnoredInactive( $scope_type ),
				$field->reason_codes
			);
		}

		foreach ( $ignored->collections as $field_key => $instruction ) {
			if ( CollectionConfigurationMode::Inherit === $instruction->mode || ! isset( $collections[ $field_key ] ) ) {
				continue;
			}
			$field                     = $collections[ $field_key ];
			$collections[ $field_key ] = new EffectiveCollectionField(
				$field->field_key,
				$field->state,
				$field->members,
				$field->provenance->withIgnoredInactive( $scope_type ),
				$field->reason_codes,
				$field->mutation_steps
			);
		}

		return [ $scalars, $collections ];
	}

	/**
	 * @param array<string, EffectiveScalarField> $scalars
	 *
	 * @return array<string, EffectiveScalarField>
	 */
	private function apply_scalar_scope( array $scalars, ScopedConfiguration $configuration ): array {
		foreach ( $configuration->scalars as $field_key => $instruction ) {
			if ( ScalarConfigurationMode::Inherit === $instruction->mode ) {
				continue;
			}

			if ( ScalarConfigurationMode::Disable === $instruction->mode ) {
				$scalars[ $field_key ] = new EffectiveScalarField(
					$field_key,
					EffectiveFieldState::Disabled,
					null,
					FieldProvenance::explicit_disable( $configuration->scope->scope_type, $configuration->scope->id ),
					[]
				);
				continue;
			}

			$scalars[ $field_key ] = new EffectiveScalarField(
				$field_key,
				EffectiveFieldState::Valid,
				$instruction->value,
				new FieldProvenance(
					$configuration->scope->scope_type,
					$this->source_label( $configuration->scope->scope_type ),
					$configuration->scope->id
				),
				[]
			);
		}

		return $scalars;
	}

	/**
	 * @param array<string, EffectiveCollectionField> $collections
	 *
	 * @return array<string, EffectiveCollectionField>
	 */
	private function apply_collection_scope( array $collections, ScopedConfiguration $configuration ): array {
		foreach ( $configuration->collections as $field_key => $instruction ) {
			if ( CollectionConfigurationMode::Inherit === $instruction->mode ) {
				continue;
			}

			$current        = $collections[ $field_key ];
			$mutation_steps = $current->mutation_steps;
			$mutation_steps[] = new CollectionMutationStep(
				$configuration->scope->scope_type,
				$instruction->mode,
				$instruction->members,
				$configuration->scope->id
			);

			// COR-012 (D3b): Add/Remove need a complete, valid inherited list. Over an unresolved or an
			// already invalid base the field stays invalid until an explicit Replace.
			if (
				in_array( $current->state, [ EffectiveFieldState::Unresolved, EffectiveFieldState::Invalid ], true )
				&& CollectionConfigurationMode::Replace !== $instruction->mode
			) {
				$collections[ $field_key ] = new EffectiveCollectionField(
					$field_key,
					EffectiveFieldState::Invalid,
					[],
					new FieldProvenance(
						$configuration->scope->scope_type,
						$this->source_label( $configuration->scope->scope_type ),
						$configuration->scope->id,
						$mutation_steps
					),
					array_values( array_unique( array_merge( $current->reason_codes, [ ConfigurationReasonCode::INVALID_COLLECTION_OPERATION ] ) ) ),
					$mutation_steps
				);
				continue;
			}

			$members = match ( $instruction->mode ) {
				CollectionConfigurationMode::Replace => $instruction->members,
				CollectionConfigurationMode::Add => $this->append_missing( $current->members, $instruction->members ),
				CollectionConfigurationMode::Remove => $this->remove_members( $current->members, $instruction->members ),
				CollectionConfigurationMode::Inherit => $current->members,
			};

			$collections[ $field_key ] = new EffectiveCollectionField(
				$field_key,
				EffectiveFieldState::Valid,
				$members,
				new FieldProvenance(
					$configuration->scope->scope_type,
					$this->source_label( $configuration->scope->scope_type ),
					$configuration->scope->id,
					$mutation_steps
				),
				[],
				$mutation_steps
			);
		}

		return $collections;
	}

	/**
	 * @param list<int> $base
	 * @param list<int> $members
	 *
	 * @return list<int>
	 */
	private function append_missing( array $base, array $members ): array {
		$result = $base;
		$seen   = array_fill_keys( $base, true );

		foreach ( $members as $member ) {
			if ( isset( $seen[ $member ] ) ) {
				continue;
			}

			$seen[ $member ] = true;
			$result[]       = $member;
		}

		return $result;
	}

	/**
	 * @param list<int> $base
	 * @param list<int> $members
	 *
	 * @return list<int>
	 */
	private function remove_members( array $base, array $members ): array {
		$remove = array_fill_keys( $members, true );
		$result = [];

		foreach ( $base as $member ) {
			if ( ! isset( $remove[ $member ] ) ) {
				$result[] = $member;
			}
		}

		return $result;
	}

	/**
	 * @param list<string> $reason_codes
	 */
	private function invalid_configuration(
		EffectiveConfigurationRequest $request,
		int $global_version,
		int $product_version,
		int $variation_version,
		array $reason_codes
	): EffectiveConfiguration {
		$fingerprint = $this->fingerprint_builder->build(
			$request->product_id,
			$request->variation_id,
			$request->slice_key,
			$global_version,
			$product_version,
			$variation_version
		);

		return new EffectiveConfiguration(
			$request->product_id,
			$request->variation_id,
			$request->slice_key,
			[],
			[],
			EffectiveFieldState::Invalid,
			$reason_codes,
			new EffectiveConfigurationVersionDescriptor(
				$request->product_id,
				$request->variation_id,
				$request->slice_key,
				$global_version,
				$product_version,
				$variation_version,
				$fingerprint
			)
		);
	}

	private function source_label( ConfigurationScopeType $scope_type ): string {
		return match ( $scope_type ) {
			ConfigurationScopeType::Global => 'global',
			ConfigurationScopeType::Product => 'product',
			ConfigurationScopeType::Variation => 'variation',
		};
	}

	private function apply_constraints( EffectiveConfiguration $configuration ): EffectiveConfiguration {
		if ( null === $this->constraint_service ) {
			return $configuration;
		}

		return $this->constraint_service->apply( $configuration );
	}
}
