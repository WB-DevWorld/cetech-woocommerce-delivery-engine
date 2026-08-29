<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Catalog;

use CetechDeliveryEngine\Application\Bulk\OverlayScopedConfigurationRepository;
use CetechDeliveryEngine\Application\Bulk\Portability\EntityCodeResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\FulfilmentConstraintServiceInterface;
use CetechDeliveryEngine\Application\Configuration\OperationalReadinessAssessor;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsPolicyInterface;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldRegistry;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationReasonCode;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfiguration;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\Configuration\FieldProvenance;
use CetechDeliveryEngine\Domain\Configuration\InvalidConfigurationException;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;

/**
 * Applies inheritance-aware catalog mutations through the same scoped
 * repositories and EffectiveConfigurationResolver used by ordinary editing.
 */
final class CatalogScopeMutator {

	public function __construct(
		private readonly ScopedConfigurationRepositoryInterface $scopes,
		private readonly EffectiveConfigurationValidator $validator,
		private readonly ?FulfilmentConstraintServiceInterface $constraints = null,
		private readonly ?SiteWideDefaultsPolicyInterface $sitewide_policy = null,
		private readonly ?EntityCodeResolver $codes = null
	) {
	}

	/**
	 * @return array{
	 *   outcome: string,
	 *   error_code: ?string,
	 *   error_summary: ?string,
	 *   warning: bool,
	 *   before_snapshot: array<string, mixed>,
	 *   precondition_fingerprint: string,
	 *   after_fingerprint: string,
	 *   result: array<string, mixed>
	 * }
	 */
	public function process(
		string $target_type,
		int $target_id,
		?int $parent_product_id,
		CatalogActionManifest $manifest,
		bool $dry_run
	): array {
		$scope_type = CatalogTargetDefinition::TARGET_VARIATION === $target_type
			? ConfigurationScopeType::Variation
			: ConfigurationScopeType::Product;

		if ( ConfigurationScopeType::Variation === $scope_type && ( null === $parent_product_id || $parent_product_id <= 0 ) ) {
			return $this->fail( 'missing_parent', 'Variation configuration requires a parent product.', [], '' );
		}

		$existing = $this->scopes->findByScopeAndSlice(
			$scope_type,
			$target_id,
			ConfigurationScope::DEFAULT_SLICE_KEY
		);

		$before_snapshot = $this->snapshot( $existing );
		$precondition    = $existing instanceof ScopedConfiguration ? $existing->fingerprint() : '';

		try {
			$candidate = $this->build_candidate( $scope_type, $target_id, $parent_product_id, $existing, $manifest );
		} catch ( InvalidConfigurationException $exception ) {
			return $this->fail( 'invalid_configuration', $exception->getMessage(), $before_snapshot, $precondition );
		} catch ( \InvalidArgumentException $exception ) {
			return $this->fail( 'invalid_value', $exception->getMessage(), $before_snapshot, $precondition );
		}

		if ( $candidate['conflict_code'] ) {
			return $this->fail(
				(string) $candidate['conflict_code'],
				(string) $candidate['conflict_summary'],
				$before_snapshot,
				$precondition,
				true
			);
		}

		$after_config = $candidate['configuration'];
		$delete_scope = (bool) $candidate['delete_scope'];

		if ( $delete_scope ) {
			$after_fingerprint = '';
			if ( ! $existing instanceof ScopedConfiguration ) {
				return $this->unchanged( $before_snapshot, $precondition, 'already_inherited' );
			}
		} elseif ( $after_config instanceof ScopedConfiguration ) {
			$after_fingerprint = $after_config->fingerprint();
			if ( $existing instanceof ScopedConfiguration && $existing->fingerprint() === $after_fingerprint ) {
				return $this->unchanged( $before_snapshot, $precondition, 'already_correct' );
			}
		} else {
			return $this->unchanged( $before_snapshot, $precondition, 'no_change' );
		}

		$effective = $this->resolve_candidate(
			$scope_type,
			$target_id,
			$parent_product_id,
			$delete_scope ? null : $after_config,
			$delete_scope
		);

		if ( $effective instanceof EffectiveConfiguration && EffectiveFieldState::Invalid === $effective->state ) {
			return $this->fail(
				'hard_rule_failure',
				'This change would create an invalid fulfilment combination.',
				$before_snapshot,
				$precondition
			);
		}

		if ( $effective instanceof EffectiveConfiguration ) {
			$unusable = OperationalReadinessAssessor::reason_for_effective( $effective );
			if ( null !== $unusable ) {
				return $this->fail(
					'no_valid_delivery_path',
					$unusable,
					$before_snapshot,
					$precondition
				);
			}
		}

		if ( $dry_run ) {
			return [
				'outcome'                   => 'changed',
				'error_code'                => null,
				'error_summary'             => null,
				'warning'                   => false,
				'before_snapshot'           => $before_snapshot,
				'precondition_fingerprint'  => $precondition,
				'after_fingerprint'         => $after_fingerprint,
				'result'                    => [
					'dry_run'              => true,
					'would_delete_scope'   => $delete_scope,
					'effective_state'      => $effective?->state->value,
					'effective_fingerprint'=> $effective?->version->fingerprint,
				],
			];
		}

		if ( $delete_scope ) {
			$this->scopes->deleteScope( $scope_type, $target_id, ConfigurationScope::DEFAULT_SLICE_KEY );
		} elseif ( $after_config instanceof ScopedConfiguration ) {
			$this->scopes->saveScopedConfiguration( $after_config );
		}

		$saved = $this->scopes->findByScopeAndSlice( $scope_type, $target_id, ConfigurationScope::DEFAULT_SLICE_KEY );

		return [
			'outcome'                  => 'changed',
			'error_code'               => null,
			'error_summary'            => null,
			'warning'                  => false,
			'before_snapshot'          => $before_snapshot,
			'precondition_fingerprint' => $precondition,
			'after_fingerprint'        => $saved instanceof ScopedConfiguration ? $saved->fingerprint() : '',
			'result'                   => [
				'dry_run'         => false,
				'deleted_scope'   => $delete_scope,
				'config_version'  => $saved?->scope->config_version ?? 0,
			],
		];
	}

	/**
	 * Read-only resolver validation for a catalog target. Never writes configuration.
	 *
	 * @return array{
	 *   outcome: string,
	 *   error_code: ?string,
	 *   error_summary: ?string,
	 *   warning: bool,
	 *   before_snapshot: array<string, mixed>,
	 *   precondition_fingerprint: string,
	 *   after_fingerprint: string,
	 *   result: array<string, mixed>
	 * }
	 */
	public function scan(
		string $target_type,
		int $target_id,
		?int $parent_product_id
	): array {
		$scope_type = CatalogTargetDefinition::TARGET_VARIATION === $target_type
			? ConfigurationScopeType::Variation
			: ConfigurationScopeType::Product;

		if ( ConfigurationScopeType::Variation === $scope_type && ( null === $parent_product_id || $parent_product_id <= 0 ) ) {
			return $this->fail( 'missing_parent', 'Variation configuration requires a parent product.', [], '' );
		}

		$existing        = $this->scopes->findByScopeAndSlice(
			$scope_type,
			$target_id,
			ConfigurationScope::DEFAULT_SLICE_KEY
		);
		$before_snapshot = $this->snapshot( $existing );
		$precondition    = $existing instanceof ScopedConfiguration ? $existing->fingerprint() : '';

		$product_id   = ConfigurationScopeType::Product === $scope_type ? $target_id : (int) $parent_product_id;
		$variation_id = ConfigurationScopeType::Variation === $scope_type ? $target_id : null;
		if ( $product_id <= 0 ) {
			return $this->fail( 'invalid_target', 'A product is required for validation.', $before_snapshot, $precondition );
		}

		$resolver  = new EffectiveConfigurationResolver(
			$this->scopes,
			$this->validator,
			$this->constraints,
			$this->sitewide_policy
		);
		$effective = $resolver->resolve(
			new EffectiveConfigurationRequest( $product_id, $variation_id, ConfigurationScope::DEFAULT_SLICE_KEY, $parent_product_id )
		);

		$reason        = OperationalReadinessAssessor::reason_for_effective( $effective );
		$warning_codes = array_values(
			array_filter(
				$effective->reason_codes,
				static fn ( string $code ): bool => ConfigurationReasonCode::CONSTRAINT_ROUTE_FILTERED === $code
			)
		);
		$verdict       = 'valid';
		if ( EffectiveFieldState::Invalid === $effective->state || null !== $reason ) {
			$verdict = 'invalid';
		} elseif ( [] !== $warning_codes ) {
			$verdict = 'warning';
		}

		$fulfilment = $effective->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY );
		$offers     = $effective->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS );
		$source     = $this->scan_source_label( $fulfilment?->provenance );

		$result = [
			'scan'                  => true,
			'scan_verdict'          => $verdict,
			'scan_label'            => match ( $verdict ) {
				'invalid' => 'Invalid / Needs Attention',
				'warning' => 'Warning',
				default   => 'Valid / Healthy',
			},
			'reason_code'           => 'invalid' === $verdict
				? ( $effective->reason_codes[0] ?? ( null !== $reason ? 'no_valid_delivery_path' : 'invalid_configuration' ) )
				: ( $warning_codes[0] ?? null ),
			'reason'                => $reason ?? ( 'warning' === $verdict ? 'Some Delivery Options were filtered by fulfilment rules.' : null ),
			'effective_state'       => $effective->state->value,
			'effective_fulfilment'  => is_string( $fulfilment?->value ) ? (string) $fulfilment->value : '',
			'effective_source'      => $source,
			'effective_offer_count' => is_array( $offers?->members ) ? count( $offers->members ) : 0,
		];

		if ( 'invalid' === $verdict ) {
			$failed                      = $this->fail(
				(string) $result['reason_code'],
				(string) ( $result['reason'] ?? 'This product needs attention.' ),
				$before_snapshot,
				$precondition
			);
			$failed['result']            = $result;
			$failed['after_fingerprint'] = '';

			return $failed;
		}

		return [
			'outcome'                  => 'unchanged',
			'error_code'               => null,
			'error_summary'            => null,
			'warning'                  => 'warning' === $verdict,
			'before_snapshot'          => $before_snapshot,
			'precondition_fingerprint' => $precondition,
			'after_fingerprint'        => '',
			'result'                   => $result,
		];
	}

	private function scan_source_label( ?FieldProvenance $provenance ): string {
		if ( ! $provenance instanceof FieldProvenance ) {
			return '';
		}
		if ( ConfigurationScopeType::Global === $provenance->source_scope ) {
			return 'Site-wide';
		}
		if ( ConfigurationScopeType::Product === $provenance->source_scope ) {
			return 'Product';
		}
		if ( ConfigurationScopeType::Variation === $provenance->source_scope ) {
			return 'Variation';
		}

		return $provenance->source_label;
	}

	/**
	 * Conflict-aware rollback: restore only when current fingerprint matches after-job fingerprint.
	 *
	 * @param array<string, mixed> $before_snapshot
	 * @return array{outcome: string, error_code: ?string, error_summary: ?string}
	 */
	public function rollback(
		string $target_type,
		int $target_id,
		?int $parent_product_id,
		array $before_snapshot,
		string $after_fingerprint
	): array {
		$scope_type = CatalogTargetDefinition::TARGET_VARIATION === $target_type
			? ConfigurationScopeType::Variation
			: ConfigurationScopeType::Product;

		$current = $this->scopes->findByScopeAndSlice(
			$scope_type,
			$target_id,
			ConfigurationScope::DEFAULT_SLICE_KEY
		);
		$current_fp = $current instanceof ScopedConfiguration ? $current->fingerprint() : '';

		if ( $current_fp !== $after_fingerprint ) {
			return [
				'outcome'       => 'rollback_skipped',
				'error_code'    => 'edited_after_job',
				'error_summary' => 'This item was edited after the bulk job, so rollback skipped it.',
			];
		}

		if ( [] === $before_snapshot || ( empty( $before_snapshot['scalars'] ) && empty( $before_snapshot['collections'] ) ) ) {
			if ( $current instanceof ScopedConfiguration ) {
				$this->scopes->deleteScope( $scope_type, $target_id, ConfigurationScope::DEFAULT_SLICE_KEY );
			}

			return [
				'outcome'       => 'rolled_back',
				'error_code'    => null,
				'error_summary' => null,
			];
		}

		$restored = $this->configuration_from_snapshot( $scope_type, $target_id, $parent_product_id, $before_snapshot );
		$this->scopes->saveScopedConfiguration( $restored );

		return [
			'outcome'       => 'rolled_back',
			'error_code'    => null,
			'error_summary' => null,
		];
	}

	/**
	 * @return array{
	 *   configuration: ?ScopedConfiguration,
	 *   delete_scope: bool,
	 *   conflict_code: ?string,
	 *   conflict_summary: ?string
	 * }
	 */
	private function build_candidate(
		ConfigurationScopeType $scope_type,
		int $target_id,
		?int $parent_product_id,
		?ScopedConfiguration $existing,
		CatalogActionManifest $manifest
	): array {
		if ( $manifest->reset_entire_scope ) {
			return [
				'configuration'     => null,
				'delete_scope'      => true,
				'conflict_code'     => null,
				'conflict_summary'  => null,
			];
		}

		$scalars     = $existing?->scalars ?? [];
		$collections = $existing?->collections ?? [];

		if ( null !== $manifest->copy_from_product_id ) {
			$source = $this->scopes->findByScopeAndSlice(
				ConfigurationScopeType::Product,
				$manifest->copy_from_product_id,
				ConfigurationScope::DEFAULT_SLICE_KEY
			);
			if ( ! $source instanceof ScopedConfiguration ) {
				return [
					'configuration'    => $existing,
					'delete_scope'     => false,
					'conflict_code'    => 'copy_source_missing',
					'conflict_summary' => 'The source product has no Delivery Engine override to copy.',
				];
			}
			$domains = [] === $manifest->copy_domains ? ConfigurationFieldKey::all() : $manifest->copy_domains;
			foreach ( $domains as $field_key ) {
				$definition = ConfigurationFieldRegistry::get( $field_key );
				if ( $definition->is_collection ) {
					if ( isset( $source->collections[ $field_key ] ) ) {
						$collections[ $field_key ] = $source->collections[ $field_key ];
					} else {
						unset( $collections[ $field_key ] );
					}
				} elseif ( isset( $source->scalars[ $field_key ] ) ) {
					$scalars[ $field_key ] = $source->scalars[ $field_key ];
				} else {
					unset( $scalars[ $field_key ] );
				}
			}
		}

		foreach ( $manifest->field_actions as $action ) {
			if ( $action->is_no_change() ) {
				continue;
			}
			$applied = $this->apply_field_action( $action, $scalars, $collections );
			if ( null !== $applied['conflict_code'] ) {
				return [
					'configuration'    => null,
					'delete_scope'     => false,
					'conflict_code'    => $applied['conflict_code'],
					'conflict_summary' => $applied['conflict_summary'],
				];
			}
			$scalars     = $applied['scalars'];
			$collections = $applied['collections'];
		}

		$scalars     = $this->strip_inherit_scalars( $scalars );
		$collections = $this->strip_inherit_collections( $collections );

		if ( [] === $scalars && [] === $collections ) {
			return [
				'configuration'    => null,
				'delete_scope'     => $existing instanceof ScopedConfiguration,
				'conflict_code'    => null,
				'conflict_summary' => null,
			];
		}

		$scope = new ConfigurationScope(
			$existing?->scope->id,
			$scope_type,
			$target_id,
			ConfigurationScope::DEFAULT_SLICE_KEY,
			ConfigurationScopeType::Variation === $scope_type ? $parent_product_id : null,
			RecordStatus::Active,
			max( 1, $existing?->scope->config_version ?? 1 ),
			$existing?->scope->source ?? ConfigurationSource::Native,
			$existing?->scope->legacy_rule_id
		);

		return [
			'configuration'    => new ScopedConfiguration( $scope, $scalars, $collections ),
			'delete_scope'     => false,
			'conflict_code'    => null,
			'conflict_summary' => null,
		];
	}

	/**
	 * @param array<string, ScalarFieldInstruction>     $scalars
	 * @param array<string, CollectionFieldInstruction> $collections
	 * @return array{
	 *   scalars: array<string, ScalarFieldInstruction>,
	 *   collections: array<string, CollectionFieldInstruction>,
	 *   conflict_code: ?string,
	 *   conflict_summary: ?string
	 * }
	 */
	private function apply_field_action( CatalogFieldAction $action, array $scalars, array $collections ): array {
		$definition = ConfigurationFieldRegistry::get( $action->field_key );

		if ( $definition->is_collection ) {
			return $this->apply_collection_action( $action, $scalars, $collections );
		}

		if ( CatalogFieldAction::CLEAR_OVERRIDE === $action->action || CatalogFieldAction::COLLECTION_INHERIT === $action->action ) {
			unset( $scalars[ $action->field_key ] );

			return $this->ok_fields( $scalars, $collections );
		}

		if ( CatalogFieldAction::DISABLE === $action->action ) {
			$scalars[ $action->field_key ] = ScalarFieldInstruction::disable( $action->field_key );

			return $this->ok_fields( $scalars, $collections );
		}

		if ( CatalogFieldAction::SET_OVERRIDE === $action->action ) {
			if ( null === $action->value || '' === $action->value ) {
				return [
					'scalars'          => $scalars,
					'collections'      => $collections,
					'conflict_code'    => 'missing_override_value',
					'conflict_summary' => sprintf( 'Override for %s requires a value. Blank does not mean inherit.', $action->field_key ),
				];
			}
			$value = $this->codes instanceof EntityCodeResolver
				? $this->codes->resolve_scalar_value( $action->field_key, $action->value )
				: $action->value;
			$scalars[ $action->field_key ] = ScalarFieldInstruction::override( $action->field_key, $value );

			return $this->ok_fields( $scalars, $collections );
		}

		return [
			'scalars'          => $scalars,
			'collections'      => $collections,
			'conflict_code'    => 'unsupported_field_action',
			'conflict_summary' => sprintf( 'Action %s is not valid for field %s.', $action->action, $action->field_key ),
		];
	}

	/**
	 * @param array<string, ScalarFieldInstruction>     $scalars
	 * @param array<string, CollectionFieldInstruction> $collections
	 * @return array{
	 *   scalars: array<string, ScalarFieldInstruction>,
	 *   collections: array<string, CollectionFieldInstruction>,
	 *   conflict_code: ?string,
	 *   conflict_summary: ?string
	 * }
	 */
	private function apply_collection_action( CatalogFieldAction $action, array $scalars, array $collections ): array {
		$current = $collections[ $action->field_key ] ?? null;

		if ( CatalogFieldAction::CLEAR_OVERRIDE === $action->action || CatalogFieldAction::COLLECTION_INHERIT === $action->action ) {
			unset( $collections[ $action->field_key ] );

			return $this->ok_fields( $scalars, $collections );
		}

		$members = $this->normalize_member_ids( $action->members );

		if ( CatalogFieldAction::COLLECTION_REPLACE === $action->action || CatalogFieldAction::SET_OVERRIDE === $action->action ) {
			$collections[ $action->field_key ] = CollectionFieldInstruction::replace( $action->field_key, $members );

			return $this->ok_fields( $scalars, $collections );
		}

		if ( CatalogFieldAction::COLLECTION_ADD === $action->action ) {
			if ( $current instanceof CollectionFieldInstruction && CollectionConfigurationMode::Remove === $current->mode ) {
				return $this->collection_conflict( $scalars, $collections, $action->field_key );
			}
			if ( $current instanceof CollectionFieldInstruction && CollectionConfigurationMode::Replace === $current->mode ) {
				$merged = array_values( array_unique( array_merge( $current->members, $members ) ) );
				$collections[ $action->field_key ] = CollectionFieldInstruction::replace( $action->field_key, $merged );

				return $this->ok_fields( $scalars, $collections );
			}
			$base = $current instanceof CollectionFieldInstruction && CollectionConfigurationMode::Add === $current->mode
				? $current->members
				: [];
			$collections[ $action->field_key ] = CollectionFieldInstruction::add(
				$action->field_key,
				array_values( array_unique( array_merge( $base, $members ) ) )
			);

			return $this->ok_fields( $scalars, $collections );
		}

		if ( CatalogFieldAction::COLLECTION_REMOVE === $action->action ) {
			if ( $current instanceof CollectionFieldInstruction && CollectionConfigurationMode::Add === $current->mode ) {
				$remaining = array_values( array_diff( $current->members, $members ) );
				if ( [] === $remaining ) {
					unset( $collections[ $action->field_key ] );
				} else {
					$collections[ $action->field_key ] = CollectionFieldInstruction::add( $action->field_key, $remaining );
				}

				return $this->ok_fields( $scalars, $collections );
			}
			if ( $current instanceof CollectionFieldInstruction && CollectionConfigurationMode::Replace === $current->mode ) {
				$remaining = array_values( array_diff( $current->members, $members ) );
				$collections[ $action->field_key ] = CollectionFieldInstruction::replace( $action->field_key, $remaining );

				return $this->ok_fields( $scalars, $collections );
			}
			$base = $current instanceof CollectionFieldInstruction && CollectionConfigurationMode::Remove === $current->mode
				? $current->members
				: [];
			$collections[ $action->field_key ] = CollectionFieldInstruction::remove(
				$action->field_key,
				array_values( array_unique( array_merge( $base, $members ) ) )
			);

			return $this->ok_fields( $scalars, $collections );
		}

		return [
			'scalars'          => $scalars,
			'collections'      => $collections,
			'conflict_code'    => 'unsupported_field_action',
			'conflict_summary' => sprintf( 'Action %s is not valid for field %s.', $action->action, $action->field_key ),
		];
	}

	private function resolve_candidate(
		ConfigurationScopeType $scope_type,
		int $target_id,
		?int $parent_product_id,
		?ScopedConfiguration $candidate,
		bool $deleted
	): ?EffectiveConfiguration {
		$overlay = new OverlayScopedConfigurationRepository(
			$this->scopes,
			$candidate,
			$deleted,
			$deleted ? $scope_type : null,
			$deleted ? $target_id : 0,
			$deleted ? ConfigurationScope::DEFAULT_SLICE_KEY : ''
		);
		$resolver = new EffectiveConfigurationResolver(
			$overlay,
			$this->validator,
			$this->constraints,
			$this->sitewide_policy
		);

		$product_id   = ConfigurationScopeType::Product === $scope_type ? $target_id : (int) $parent_product_id;
		$variation_id = ConfigurationScopeType::Variation === $scope_type ? $target_id : null;

		if ( $product_id <= 0 ) {
			return null;
		}

		return $resolver->resolve(
			new EffectiveConfigurationRequest( $product_id, $variation_id, ConfigurationScope::DEFAULT_SLICE_KEY, $parent_product_id )
		);
	}

	/**
	 * @param array<string, mixed>|null $existing
	 * @return array<string, mixed>
	 */
	private function snapshot( ?ScopedConfiguration $existing ): array {
		if ( ! $existing instanceof ScopedConfiguration ) {
			return [
				'scalars'     => [],
				'collections' => [],
			];
		}

		$scalars = [];
		foreach ( $existing->scalars as $key => $instruction ) {
			$scalars[ $key ] = $instruction->toStorageArray();
		}
		$collections = [];
		foreach ( $existing->collections as $key => $instruction ) {
			$collections[ $key ] = $instruction->toStorageArray();
		}

		return [
			'scalars'      => $scalars,
			'collections'  => $collections,
			'scope_id'     => $existing->scope->id,
			'config_version' => $existing->scope->config_version,
		];
	}

	/**
	 * @param array<string, mixed> $snapshot
	 */
	private function configuration_from_snapshot(
		ConfigurationScopeType $scope_type,
		int $target_id,
		?int $parent_product_id,
		array $snapshot
	): ScopedConfiguration {
		$scalars     = [];
		$collections = [];
		foreach ( (array) ( $snapshot['scalars'] ?? [] ) as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$scalars[ (string) $key ] = ScalarFieldInstruction::fromStorage(
				(string) $key,
				(string) ( $row['mode'] ?? '' ),
				$row['value'] ?? null,
				(string) ( $row['value_type'] ?? 'string' )
			);
		}
		foreach ( (array) ( $snapshot['collections'] ?? [] ) as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$collections[ (string) $key ] = CollectionFieldInstruction::fromStorage(
				(string) $key,
				(string) ( $row['mode'] ?? '' ),
				$row['members'] ?? []
			);
		}

		$existing = $this->scopes->findByScopeAndSlice( $scope_type, $target_id, ConfigurationScope::DEFAULT_SLICE_KEY );
		$scope    = new ConfigurationScope(
			$existing?->scope->id,
			$scope_type,
			$target_id,
			ConfigurationScope::DEFAULT_SLICE_KEY,
			ConfigurationScopeType::Variation === $scope_type ? $parent_product_id : null,
			RecordStatus::Active,
			max( 1, $existing?->scope->config_version ?? 1 ),
			$existing?->scope->source ?? ConfigurationSource::Native,
			$existing?->scope->legacy_rule_id
		);

		return new ScopedConfiguration( $scope, $scalars, $collections );
	}

	/**
	 * @param array<string, ScalarFieldInstruction> $scalars
	 * @return array<string, ScalarFieldInstruction>
	 */
	private function strip_inherit_scalars( array $scalars ): array {
		foreach ( $scalars as $key => $instruction ) {
			if ( ScalarConfigurationMode::Inherit === $instruction->mode ) {
				unset( $scalars[ $key ] );
			}
		}

		return $scalars;
	}

	/**
	 * @param array<string, CollectionFieldInstruction> $collections
	 * @return array<string, CollectionFieldInstruction>
	 */
	private function strip_inherit_collections( array $collections ): array {
		foreach ( $collections as $key => $instruction ) {
			if ( CollectionConfigurationMode::Inherit === $instruction->mode ) {
				unset( $collections[ $key ] );
			}
		}

		return $collections;
	}

	/**
	 * @param list<int|string> $members
	 * @return list<int>
	 */
	private function normalize_member_ids( array $members ): array {
		if ( $this->codes instanceof EntityCodeResolver ) {
			return $this->codes->resolve_offer_ids( $members );
		}
		$ids = [];
		foreach ( $members as $member ) {
			if ( is_int( $member ) || ( is_string( $member ) && ctype_digit( $member ) ) ) {
				$id = (int) $member;
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param array<string, mixed> $before_snapshot
	 * @return array<string, mixed>
	 */
	private function fail( string $code, string $summary, array $before_snapshot, string $precondition, bool $warning = false ): array {
		return [
			'outcome'                  => 'failed',
			'error_code'               => $code,
			'error_summary'            => $summary,
			'warning'                  => $warning,
			'before_snapshot'          => $before_snapshot,
			'precondition_fingerprint' => $precondition,
			'after_fingerprint'        => $precondition,
			'result'                   => [],
		];
	}

	/**
	 * @param array<string, mixed> $before_snapshot
	 * @return array<string, mixed>
	 */
	private function unchanged( array $before_snapshot, string $precondition, string $reason ): array {
		return [
			'outcome'                  => 'unchanged',
			'error_code'               => null,
			'error_summary'            => null,
			'warning'                  => false,
			'before_snapshot'          => $before_snapshot,
			'precondition_fingerprint' => $precondition,
			'after_fingerprint'        => $precondition,
			'result'                   => [ 'reason' => $reason ],
		];
	}

	/**
	 * @param array<string, ScalarFieldInstruction>     $scalars
	 * @param array<string, CollectionFieldInstruction> $collections
	 * @return array{
	 *   scalars: array<string, ScalarFieldInstruction>,
	 *   collections: array<string, CollectionFieldInstruction>,
	 *   conflict_code: ?string,
	 *   conflict_summary: ?string
	 * }
	 */
	private function ok_fields( array $scalars, array $collections ): array {
		return [
			'scalars'          => $scalars,
			'collections'      => $collections,
			'conflict_code'    => null,
			'conflict_summary' => null,
		];
	}

	/**
	 * @param array<string, ScalarFieldInstruction>     $scalars
	 * @param array<string, CollectionFieldInstruction> $collections
	 * @return array{
	 *   scalars: array<string, ScalarFieldInstruction>,
	 *   collections: array<string, CollectionFieldInstruction>,
	 *   conflict_code: ?string,
	 *   conflict_summary: ?string
	 * }
	 */
	private function collection_conflict( array $scalars, array $collections, string $field_key ): array {
		return [
			'scalars'          => $scalars,
			'collections'      => $collections,
			'conflict_code'    => 'collection_mode_conflict',
			'conflict_summary' => sprintf( 'Cannot ADD onto an existing REMOVE instruction for %s.', $field_key ),
		];
	}
}
