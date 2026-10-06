<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\CollectionMutationStep;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfiguration;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Presentation\Admin\ConfigurationAuditLogger;

/**
 * Application boundary for Stage 4 scoped configuration administration.
 *
 * Effective preview always calls EffectiveConfigurationResolver — inheritance is never reimplemented here.
 */
final class ScopedConfigurationAdminService {

	public function __construct(
		private readonly ScopedConfigurationRepositoryInterface $repository,
		private readonly EffectiveConfigurationResolver $resolver,
		private readonly ScopedConfigurationSubmissionParser $parser,
		private readonly ProductVariationScopeGuard $scope_guard,
		private readonly EntityLabelResolver $entity_labels,
		private readonly LegacyCategoryConfigurationInspector $category_inspector,
		private readonly ?ConfigurationChangeAuditorInterface $audit_logger = null,
		/** @var callable|null */
		private readonly mixed $variation_relationship_checker = null
	) {
	}

	private bool $reset_replayed = false;

	public function reset_was_replayed(): bool {
		return $this->reset_replayed;
	}

	public function load_edit_model(
		ConfigurationScopeType $scope_type,
		int $scope_id,
		string $slice_key = ConfigurationScope::DEFAULT_SLICE_KEY,
		?int $parent_product_id = null,
		?string $product_label = null,
		?string $variation_label = null,
		array $category_ids = []
	): ScopedConfigurationEditViewModel {
		if ( ConfigurationScopeType::Global === $scope_type ) {
			$configuration = $this->repository->ensureGlobalScope();
			$slice_key     = ConfigurationScope::DEFAULT_SLICE_KEY;
			$scope_id      = ConfigurationScope::GLOBAL_SCOPE_ID;
			$parent_product_id = null;
		} else {
			$configuration = $this->repository->findByScopeAndSlice( $scope_type, $scope_id, $slice_key );
		}

		$available_slices = $this->list_slices( $scope_type, $scope_id );
		$effective        = $this->resolve_for_editor( $scope_type, $scope_id, $slice_key, $parent_product_id );
		$fields           = $this->build_field_view_models( $scope_type, $configuration, $effective );

		$category_warning = ConfigurationScopeType::Global === $scope_type
			? $this->category_inspector->inspect_site_wide()
			: $this->category_inspector->inspect( $scope_type === ConfigurationScopeType::Variation ? (int) $parent_product_id : $scope_id, $category_ids );

		$is_migrated     = null !== $configuration && ConfigurationSource::Migrated === $configuration->scope->source;
		$legacy_rule_id  = $configuration?->scope->legacy_rule_id;
		$config_version  = $configuration?->scope->config_version ?? 0;

		return new ScopedConfigurationEditViewModel(
			$scope_type->value,
			$scope_id,
			$slice_key,
			ConfigurationFieldCatalog::slice_label( $slice_key ),
			$parent_product_id,
			$product_label,
			$variation_label,
			$config_version,
			$is_migrated,
			$legacy_rule_id,
			$available_slices,
			$fields,
			[
				'legacy_runtime_label' => ScopedConfigurationNotices::LEGACY_RUNTIME_LABEL,
				'scoped_runtime_label' => ScopedConfigurationNotices::SCOPED_RUNTIME_LABEL,
			],
			$category_warning,
			[
				'scope_row_id'   => $configuration?->scope->id,
				'config_version' => $config_version,
				'source'         => $configuration?->scope->source->value,
				'slice_key_raw'  => $slice_key,
			],
			ScopedConfigurationNotices::TRANSITIONAL_TITLE,
			ScopedConfigurationNotices::TRANSITIONAL_MESSAGE
		);
	}

	/**
	 * Persist a complete validated scope mutation. Preview paths must never call this.
	 */
	public function save( ScopedConfigurationWriteCommand $command ): ScopedConfigurationWriteResult {
		$scope_errors = $this->scope_guard->validate(
			$command->scope_type,
			$command->scope_id,
			$command->parent_product_id
		);

		if ( ConfigurationScopeType::Variation === $command->scope_type && null !== $this->variation_relationship_checker ) {
			$relationship_error = $this->scope_guard->assert_variation_belongs_to_parent(
				$command->scope_id,
				(int) $command->parent_product_id,
				$this->variation_relationship_checker
			);
			if ( null !== $relationship_error ) {
				$scope_errors[] = $relationship_error;
			}
		}

		if ( [] !== $scope_errors ) {
			return ScopedConfigurationWriteResult::failure( $scope_errors );
		}

		$slice_key = $command->slice_key;
		if ( ConfigurationScopeType::Global === $command->scope_type ) {
			$slice_key = ConfigurationScope::DEFAULT_SLICE_KEY;
		}

		if ( ! $this->is_valid_slice_key( $slice_key ) ) {
			return ScopedConfigurationWriteResult::failure( [ 'Invalid slice key.' ] );
		}

		$parsed = $this->parser->parse( $command->scope_type, $command->raw_fields );
		if ( ! $parsed['ok'] ) {
			return ScopedConfigurationWriteResult::failure( $parsed['errors'] );
		}

		$scope_id = ConfigurationScopeType::Global === $command->scope_type ? ConfigurationScope::GLOBAL_SCOPE_ID : $command->scope_id;
		$existing = $this->repository->findByScopeAndSlice( $command->scope_type, $scope_id, $slice_key );

		if ( null === $existing && ConfigurationScopeType::Global !== $command->scope_type && ! $command->create_slice_if_missing ) {
			$existing_slices = $this->repository->findByScope( $command->scope_type, $command->scope_id );
			if ( [] !== $existing_slices && ! $this->slice_exists( $existing_slices, $slice_key ) ) {
				return ScopedConfigurationWriteResult::failure(
					[ 'The selected slice does not exist for this scope. Create it explicitly before editing.' ]
				);
			}
		}

		$version_before = $existing?->scope->config_version ?? 0;
		$previous_snapshot = null !== $existing ? $this->instruction_snapshot( $existing ) : null;
		$request_token = is_string( $command->request_token ) ? trim( $command->request_token ) : '';
		$intent_hash   = $this->request_intent_hash( 'scoped_configuration_updated', $command->scope_type, $scope_id, $slice_key, $command->parent_product_id, $command->expected_scope_row_id, $command->raw_fields );
		if ( '' !== $request_token && null !== $this->audit_logger ) {
			$recorded = $this->audit_logger->recorded_completion( $request_token );
			if ( null !== $recorded ) {
				if ( ( $recorded['intent_hash'] ?? '' ) !== $intent_hash || ( $recorded['action'] ?? '' ) !== 'scoped_configuration_updated' ) {
					return ScopedConfigurationWriteResult::failure( [ 'This request token does not match the saved operation.' ] );
				}
				$same_row = null !== $existing && $existing->scope->config_version === $recorded['version_after'];
				if ( $same_row && ConfigurationScopeType::Global === $command->scope_type && ! $this->repository->publishAcceptedRevision( $existing ) ) {
					return ScopedConfigurationWriteResult::failure( [ 'Save outcome could not be confirmed.' ] );
				}

				return ScopedConfigurationWriteResult::success(
					$same_row ? $existing : null,
					false,
					$recorded['version_before'],
					$recorded['version_after'],
					true,
					[],
					true
				);
			}
		}

		$scope = new ConfigurationScope(
			$existing?->scope->id,
			$command->scope_type,
			ConfigurationScopeType::Global === $command->scope_type ? ConfigurationScope::GLOBAL_SCOPE_ID : $command->scope_id,
			$slice_key,
			ConfigurationScopeType::Variation === $command->scope_type ? $command->parent_product_id : null,
			RecordStatus::Active,
			max( 1, $version_before > 0 ? $version_before : 1 ),
			$existing?->scope->source ?? ConfigurationSource::Native,
			$existing?->scope->legacy_rule_id,
			$existing?->scope->created_at,
			$existing?->scope->updated_at
		);

		$candidate = new ScopedConfiguration(
			$scope,
			$parsed['scalars'],
			$parsed['collections']
		);

		try {
			$saved = $this->repository->completeLocalUnit(
				function () use ( $candidate, $existing, $version_before, $previous_snapshot, $command, $slice_key, $request_token, $intent_hash, $scope_id ): ScopedConfiguration {
					$locked = $this->repository->lockScopeIdentity( $command->scope_type, $scope_id, $slice_key );
					$this->assert_locked_editor( $command, $locked );
					$saved           = $this->repository->saveScopedConfiguration( $candidate, false );
					$version_after   = $saved->scope->config_version;
					$version_changed = null === $existing || $saved->fingerprint() !== $existing->fingerprint();
					if ( ! $version_changed ) {
						return $saved;
					}

					if ( null === $this->audit_logger ) {
						throw new \RuntimeException( 'Settings were not saved. The authoritative audit is not available.' );
					}

					$audit_summary = $this->build_audit_summary(
						$command->scope_type,
						$command->scope_id,
						$slice_key,
						$previous_snapshot,
						$this->instruction_snapshot( $saved ),
						$version_before,
						$version_after
					);
					if ( '' !== $request_token ) {
						$audit_summary['new']['request_token'] = $request_token;
						$audit_summary['new']['intent_hash']   = $intent_hash;
					}
					$recorded = $this->audit_logger->log(
						'scoped_configuration_updated',
						'configuration_scope',
						(int) ( $saved->scope->id ?? 0 ),
						$audit_summary['previous'],
						$audit_summary['new']
					);
					if ( ! $recorded ) {
						throw new \RuntimeException( 'Settings were not saved. The authoritative audit was rejected.' );
					}

					return $saved;
				}
			);
		} catch ( \CetechDeliveryEngine\Domain\Configuration\InvalidConfigurationException $exception ) {
			if ( str_contains( $exception->getMessage(), 'Duplicate' ) ) {
				return ScopedConfigurationWriteResult::failure( [ 'These settings are out of date. Reload the current settings and submit the draft again.' ] );
			}

			return ScopedConfigurationWriteResult::failure( [ 'Settings were not saved. ' . $exception->getMessage() ] );
		} catch ( \RuntimeException $exception ) {
			return $this->completion_failure( $exception );
		}

		$this->resolver->clearMemoization();
		if ( ConfigurationScopeType::Global === $command->scope_type && ! $this->repository->publishAcceptedRevision( $saved ) ) {
			return ScopedConfigurationWriteResult::failure( [ 'Save outcome could not be confirmed.' ] );
		}
		$version_after   = $saved->scope->config_version;
		$version_changed = null === $existing || $saved->fingerprint() !== $existing->fingerprint();
		if ( ! $version_changed ) {
			$version_after = $version_before;
		}

		return ScopedConfigurationWriteResult::success(
			$saved,
			$version_changed,
			$version_before,
			$version_after,
			$version_changed && null !== $this->audit_logger,
			[]
		);
	}

	/**
	 * Remove exactly one scope slice and append its ordinary mutation audit.
	 * Caller must first validate its current capability, nonce and object access.
	 */
	public function reset( ConfigurationScopeType $scope_type, int $scope_id, string $slice_key, ?int $parent_product_id = null, ?int $expected_revision = null, ?string $request_token = null, ?int $expected_scope_row_id = null ): bool {
		$errors = $this->scope_guard->validate( $scope_type, $scope_id, $parent_product_id );
		if ( ConfigurationScopeType::Global === $scope_type || ! $this->is_valid_slice_key( $slice_key ) || [] !== $errors ) {
			throw new \InvalidArgumentException( 'Invalid configuration reset target. ' . implode( ' ', $errors ) );
		}
		if ( ConfigurationScopeType::Variation === $scope_type && null !== $this->variation_relationship_checker ) {
			$error = $this->scope_guard->assert_variation_belongs_to_parent( $scope_id, (int) $parent_product_id, $this->variation_relationship_checker );
			if ( null !== $error ) {
				throw new \InvalidArgumentException( $error );
			}
		}

		$this->reset_replayed = false;
		$previous = $this->repository->findByScopeAndSlice( $scope_type, $scope_id, $slice_key );
		$token = is_string( $request_token ) ? trim( $request_token ) : '';
		$intent_hash = $this->request_intent_hash( 'scoped_configuration_reset', $scope_type, $scope_id, $slice_key, $parent_product_id, $expected_scope_row_id ?? $previous?->scope->id, [] );
		if ( '' !== $token && null !== $this->audit_logger ) {
			$recorded = $this->audit_logger->recorded_completion( $token );
			if ( null !== $recorded ) {
				if ( ( $recorded['intent_hash'] ?? '' ) !== $intent_hash || ( $recorded['action'] ?? '' ) !== 'scoped_configuration_reset' ) {
					throw new \RuntimeException( 'This request token does not match the saved operation.' );
				}
				$this->reset_replayed = true;

				return true;
			}
		}
		if ( null === $previous ) {
			return false;
		}
		try {
			$deleted = $this->repository->completeLocalUnit(
				function () use ( $scope_type, $scope_id, $slice_key, $parent_product_id, $previous, $expected_revision, $expected_scope_row_id, $token, $intent_hash ): bool {
					$locked = $this->repository->lockScopeIdentity( $scope_type, $scope_id, $slice_key );
					if ( null !== $expected_scope_row_id && $expected_scope_row_id > 0 && ( null === $locked || $locked['id'] !== $expected_scope_row_id ) ) {
						throw new \RuntimeException( 'These settings are out of date. Reload the current settings and submit the draft again.' );
					}
					if ( null !== $expected_revision && ( null === $locked || $expected_revision !== $locked['version'] ) ) {
						throw new \RuntimeException( 'These settings are out of date. Reload the current settings and submit the draft again.' );
					}
					if ( ! $this->repository->deleteScope( $scope_type, $scope_id, $slice_key ) ) {
						throw new \RuntimeException( 'Settings were not saved. The selected settings could not be removed.' );
					}
					if ( null === $this->audit_logger ) {
						throw new \RuntimeException( 'Settings were not saved. The authoritative audit is not available.' );
					}
					$recorded = $this->audit_logger->log(
						'scoped_configuration_reset',
						'configuration_scope',
						(int) ( $previous->scope->id ?? 0 ),
						array_merge( $this->instruction_snapshot( $previous ), [
							'scope_type' => $scope_type->value,
							'scope_id' => $scope_id,
							'slice_key' => $slice_key,
							'parent_product_id' => $parent_product_id,
							'config_version' => $previous->scope->config_version,
						] ),
						array_filter(
							[
								'scope_type' => $scope_type->value,
								'scope_id' => $scope_id,
								'slice_key' => $slice_key,
								'parent_product_id' => $parent_product_id,
								'inherits' => true,
								'request_token' => '' !== $token ? $token : null,
								'intent_hash' => '' !== $token ? $intent_hash : null,
							],
							static fn ( $value ): bool => null !== $value
						)
					);
					if ( ! $recorded ) {
						throw new \RuntimeException( 'Settings were not saved. The authoritative audit was rejected.' );
					}

					return true;
				}
			);
		} catch ( \RuntimeException $exception ) {
			throw $this->completion_exception( $exception );
		}
		if ( $deleted ) {
			$this->resolver->clearMemoization();
		}

		return $deleted;
	}

	/**
	 * @param array{id: int, version: int}|null $locked
	 */
	private function assert_locked_editor( ScopedConfigurationWriteCommand $command, ?array $locked ): void {
		$message = 'These settings are out of date. Reload the current settings and submit the draft again.';
		if ( null !== $command->expected_scope_row_id && $command->expected_scope_row_id > 0 && ( null === $locked || $locked['id'] !== $command->expected_scope_row_id ) ) {
			throw new \RuntimeException( $message );
		}
		if ( null !== $command->expected_revision && $command->expected_revision !== ( null === $locked ? 0 : $locked['version'] ) ) {
			throw new \RuntimeException( $message );
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function request_intent_hash( string $action, ConfigurationScopeType $scope_type, int $scope_id, string $slice_key, ?int $parent_product_id, ?int $scope_row_id, array $payload ): string {
		$fields = $payload;
		ksort( $fields );
		$encoded = wp_json_encode(
			[
				'action'            => $action,
				'actor_user_id'     => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'scope_type'        => $scope_type->value,
				'scope_id'          => $scope_id,
				'slice_key'         => $slice_key,
				'parent_product_id' => $parent_product_id,
				'scope_row_id'      => $scope_row_id,
				'fields'            => $fields,
			]
		);
		if ( ! is_string( $encoded ) ) {
			throw new \RuntimeException( 'Settings were not saved. The request could not be encoded.' );
		}

		return hash( 'sha256', $encoded );
	}

	private function completion_failure( \RuntimeException $exception ): ScopedConfigurationWriteResult {
		return ScopedConfigurationWriteResult::failure( [ $this->completion_exception( $exception )->getMessage() ] );
	}

	private function completion_exception( \RuntimeException $exception ): \RuntimeException {
		$message = $exception->getMessage();
		if ( str_starts_with( $message, 'Save outcome could not be confirmed.' ) || str_starts_with( $message, 'Settings were not saved.' ) ) {
			return $exception;
		}

		return new \RuntimeException( 'Settings were not saved. ' . $message, 0, $exception );
	}

	/**
	 * Read-only preview. Must never write configuration or audit.
	 */
	public function preview(
		int $product_id,
		?int $variation_id,
		string $slice_key,
		?int $parent_product_id = null,
		array $category_ids = []
	): EffectiveConfigurationPreviewViewModel {
		$errors = [];
		if ( $product_id <= 0 ) {
			$errors[] = 'Product ID must be a positive integer.';
		}

		if ( null !== $variation_id ) {
			if ( $variation_id <= 0 ) {
				$errors[] = 'Variation ID must be a positive integer when provided.';
			} else {
				$parent = $parent_product_id ?? $product_id;
				$relationship_error = $this->scope_guard->assert_variation_belongs_to_parent(
					$variation_id,
					$parent,
					$this->variation_relationship_checker
				);
				if ( null !== $relationship_error ) {
					$errors[] = $relationship_error;
				}
			}
		}

		if ( [] !== $errors ) {
			return $this->invalid_preview( $product_id, $variation_id, $slice_key, $errors, $category_ids );
		}

		$resolved = $this->resolver->resolve(
			new EffectiveConfigurationRequest( $product_id, $variation_id, $slice_key, $parent_product_id )
		);

		return $this->map_preview( $resolved, $category_ids );
	}

	/**
	 * @return list<array{key: string, label: string, migrated: bool, legacy_rule_id: int|null}>
	 */
	public function list_slices( ConfigurationScopeType $scope_type, int $scope_id ): array {
		if ( ConfigurationScopeType::Global === $scope_type ) {
			return [
				[
					'key'            => ConfigurationScope::DEFAULT_SLICE_KEY,
					'label'          => ConfigurationFieldCatalog::slice_label( ConfigurationScope::DEFAULT_SLICE_KEY ),
					'migrated'       => false,
					'legacy_rule_id' => null,
				],
			];
		}

		$slices = [];
		foreach ( $this->repository->findByScope( $scope_type, $scope_id ) as $configuration ) {
			$key = $configuration->scope->slice_key;
			$slices[ $key ] = [
				'key'            => $key,
				'label'          => ConfigurationFieldCatalog::slice_label( $key ),
				'migrated'       => ConfigurationSource::Migrated === $configuration->scope->source,
				'legacy_rule_id' => $configuration->scope->legacy_rule_id,
			];
		}

		if ( ! isset( $slices[ ConfigurationScope::DEFAULT_SLICE_KEY ] ) ) {
			$slices[ ConfigurationScope::DEFAULT_SLICE_KEY ] = [
				'key'            => ConfigurationScope::DEFAULT_SLICE_KEY,
				'label'          => ConfigurationFieldCatalog::slice_label( ConfigurationScope::DEFAULT_SLICE_KEY ),
				'migrated'       => false,
				'legacy_rule_id' => null,
			];
		}

		return array_values( $slices );
	}

	private function resolve_for_editor(
		ConfigurationScopeType $scope_type,
		int $scope_id,
		string $slice_key,
		?int $parent_product_id
	): ?EffectiveConfiguration {
		if ( ConfigurationScopeType::Global === $scope_type ) {
			// Preview effective against a synthetic product context is not meaningful for global-only edit;
			// field effective values on global editor come from global instructions + resolver over product 0 is invalid.
			return null;
		}

		$product_id   = ConfigurationScopeType::Product === $scope_type ? $scope_id : (int) $parent_product_id;
		$variation_id = ConfigurationScopeType::Variation === $scope_type ? $scope_id : null;

		if ( $product_id <= 0 ) {
			return null;
		}

		return $this->resolver->resolve(
			new EffectiveConfigurationRequest( $product_id, $variation_id, $slice_key, $parent_product_id )
		);
	}

	/**
	 * @return list<FieldEditViewModel>
	 */
	private function build_field_view_models(
		ConfigurationScopeType $scope_type,
		?ScopedConfiguration $configuration,
		?EffectiveConfiguration $effective
	): array {
		$fields = [];
		$profile_default_label = $this->profile_default_mode_label( $scope_type, $effective );

		foreach ( ConfigurationFieldCatalog::all() as $meta ) {
			$field_key = $meta['key'];
			$is_collection = $meta['is_collection'];

			$mode_labels = $is_collection
				? [
					'inherit' => ConfigurationScopeType::Variation === $scope_type
						? 'Use Product Setting'
						: 'Use Site-wide Default',
					'add'     => 'Add options',
					'remove'  => 'Remove options',
					'replace' => 'Use only these options',
				]
				: [
					'inherit'  => $profile_default_label,
					'override' => ConfigurationScopeType::Variation === $scope_type
						? 'Use a different value'
						: 'Set a different value here',
					'disable'  => 'Turn off',
				];

			if ( ConfigurationScopeType::Global === $scope_type ) {
				unset( $mode_labels['inherit'] );
			}

			$allowed = [];
			foreach ( $meta['allowed_modes'] as $mode ) {
				if ( ConfigurationScopeType::Global === $scope_type && 'inherit' === $mode ) {
					continue;
				}
				$allowed[] = $mode;
			}

			$scalar_instruction     = $configuration?->scalars[ $field_key ] ?? null;
			$collection_instruction = $configuration?->collections[ $field_key ] ?? null;

			$current_mode = 'inherit';
			$configured_value = null;
			$configured_members = [];
			$configured_state_label = 'Not configured';

			if ( $is_collection ) {
				if ( $collection_instruction instanceof CollectionFieldInstruction ) {
					$current_mode = $collection_instruction->mode->value;
					$configured_members = $collection_instruction->members;
					$configured_state_label = match ( $collection_instruction->mode ) {
						CollectionConfigurationMode::Replace => [] === $collection_instruction->members
							? 'Configured (no delivery options for this setup)'
							: 'Configured (use only these options)',
						CollectionConfigurationMode::Add => 'Configured (add to inherited options)',
						CollectionConfigurationMode::Remove => 'Configured (remove from inherited options)',
						CollectionConfigurationMode::Inherit => 'Use inherited setting',
					};
				} elseif ( ConfigurationScopeType::Global === $scope_type ) {
					$current_mode = 'replace';
					$configured_state_label = 'Not configured';
					// Global UI default mode when unset: show as needing configuration; mode select starts empty-ish.
					// Use a sentinel display mode "not_configured" via configured_state_label; keep current_mode as replace for form default when saving new.
					$current_mode = '';
				}
			} else {
				if ( $scalar_instruction instanceof ScalarFieldInstruction ) {
					$current_mode = $scalar_instruction->mode->value;
					$configured_value = $scalar_instruction->value;
					$configured_state_label = match ( $scalar_instruction->mode ) {
						ScalarConfigurationMode::Override => 'Configured',
						ScalarConfigurationMode::Disable => 'Turned off',
						ScalarConfigurationMode::Inherit => 'Use inherited setting',
					};
				} elseif ( ConfigurationScopeType::Global === $scope_type ) {
					$current_mode = '';
					$configured_state_label = 'Not configured';
				}
			}

			$effective_field = null;
			if ( null !== $effective ) {
				$effective_field = $is_collection
					? $effective->collection( $field_key )
					: $effective->scalar( $field_key );
			}

			$effective_state = $effective_field?->state ?? (
				ConfigurationScopeType::Global === $scope_type
					? ( '' === $current_mode
						? EffectiveFieldState::Unresolved
						: ( 'disable' === $current_mode ? EffectiveFieldState::Disabled : EffectiveFieldState::Valid ) )
					: EffectiveFieldState::Unresolved
			);

			if ( ! $effective_state instanceof EffectiveFieldState ) {
				$effective_state = EffectiveFieldState::Unresolved;
			}

			$provenance_label = '';
			$provenance_lines = [];
			$inherited_value = null;
			$inherited_members = [];
			$effective_value = null;
			$effective_members = [];
			$validation_messages = [];

			if ( null !== $effective_field ) {
				$provenance_label = ProvenanceLabelMapper::map( $effective_field->provenance->source_label );
				if ( $is_collection && $effective_field instanceof \CetechDeliveryEngine\Domain\Configuration\EffectiveCollectionField ) {
					$effective_members = $effective_field->members;
					$provenance_lines = ProvenanceLabelMapper::mutation_summaries(
						$effective_field->mutation_steps !== [] ? $effective_field->mutation_steps : $effective_field->provenance->contributing_mutations,
						fn ( int $id ): string => $this->entity_labels->label( (string) $meta['entity_kind'], $id )
					);
					$validation_messages = ReasonCodeLabelMapper::explain_codes( $effective_field->reason_codes );
				} elseif ( ! $is_collection && $effective_field instanceof \CetechDeliveryEngine\Domain\Configuration\EffectiveScalarField ) {
					$effective_value = $effective_field->value;
					$validation_messages = ReasonCodeLabelMapper::explain_codes( $effective_field->reason_codes );
				}
			} elseif ( ConfigurationScopeType::Global === $scope_type ) {
				if ( $scalar_instruction instanceof ScalarFieldInstruction && ScalarConfigurationMode::Disable === $scalar_instruction->mode ) {
					$effective_state = EffectiveFieldState::Disabled;
					$provenance_label = ProvenanceLabelMapper::map( 'explicit_disable' );
				} elseif ( $scalar_instruction instanceof ScalarFieldInstruction && ScalarConfigurationMode::Override === $scalar_instruction->mode ) {
					$effective_state = EffectiveFieldState::Valid;
					$effective_value = $scalar_instruction->value;
					$provenance_label = ProvenanceLabelMapper::map( 'global' );
				} elseif ( $collection_instruction instanceof CollectionFieldInstruction ) {
					$effective_state = EffectiveFieldState::Valid;
					$effective_members = $collection_instruction->members;
					$provenance_label = ProvenanceLabelMapper::map( 'global' );
					if ( CollectionConfigurationMode::Replace === $collection_instruction->mode && [] === $collection_instruction->members ) {
						$provenance_lines = ProvenanceLabelMapper::mutation_summaries(
							[
								new CollectionMutationStep(
									ConfigurationScopeType::Global,
									CollectionConfigurationMode::Replace,
									[]
								),
							]
						);
					}
				} else {
					$effective_state = EffectiveFieldState::Unresolved;
					$validation_messages = [ ReasonCodeLabelMapper::explain( \CetechDeliveryEngine\Domain\Configuration\ConfigurationReasonCode::UNRESOLVED_GLOBAL_VALUE ) ];
				}
			}

			// Inherited display for product/variation: show effective when mode is inherit, else show parent contribution via resolver provenance.
			if ( ConfigurationScopeType::Global !== $scope_type && null !== $effective_field ) {
				if ( $is_collection && $effective_field instanceof \CetechDeliveryEngine\Domain\Configuration\EffectiveCollectionField ) {
					$inherited_members = $effective_field->members;
					if ( 'inherit' !== $current_mode && 'inherit' !== $current_mode ) {
						// Keep inherited as pre-mutation base approximation from provenance when available.
						$inherited_members = $effective_field->members;
					}
				} elseif ( ! $is_collection ) {
					$inherited_value = $effective_field->value;
				}
			}

			$selector_options = null;
			if ( null !== $meta['entity_kind'] ) {
				$selector_options = $this->entity_labels->options_for( $meta['entity_kind'] );
			}

			$fields[] = new FieldEditViewModel(
				$field_key,
				$meta['label'],
				$meta['description'],
				$is_collection,
				$meta['value_type'],
				$allowed,
				array_intersect_key( $mode_labels, array_flip( $allowed !== [] ? $allowed : array_keys( $mode_labels ) ) ),
				$current_mode,
				$configured_value,
				$inherited_value,
				$effective_value,
				$effective_state->value,
				ReasonCodeLabelMapper::state_label( $effective_state ),
				ReasonCodeLabelMapper::state_tone( $effective_state ),
				$provenance_label,
				$provenance_lines,
				$validation_messages,
				$meta['entity_kind'],
				$selector_options,
				$meta['enum_options'],
				$configured_members,
				$inherited_members,
				$effective_members,
				ConfigurationScopeType::Global === $scope_type,
				$configured_state_label
			);
		}

		return $fields;
	}

	/**
	 * @param list<string> $errors
	 * @param list<int>    $category_ids
	 */
	private function invalid_preview(
		int $product_id,
		?int $variation_id,
		string $slice_key,
		array $errors,
		array $category_ids
	): EffectiveConfigurationPreviewViewModel {
		return new EffectiveConfigurationPreviewViewModel(
			$product_id,
			$variation_id,
			$slice_key,
			ConfigurationFieldCatalog::slice_label( $slice_key ),
			EffectiveFieldState::Invalid->value,
			ReasonCodeLabelMapper::state_label( EffectiveFieldState::Invalid ),
			ReasonCodeLabelMapper::state_tone( EffectiveFieldState::Invalid ),
			$errors,
			[],
			[],
			ScopedConfigurationNotices::PREVIEW_LIMITATION_TITLE,
			ScopedConfigurationNotices::PREVIEW_LIMITATION_MESSAGE,
			ScopedConfigurationNotices::HARD_CONSTRAINT_NOTE,
			ScopedConfigurationNotices::TRANSITIONAL_TITLE,
			ScopedConfigurationNotices::TRANSITIONAL_MESSAGE,
			$this->category_inspector->inspect( $product_id, $category_ids ),
			true
		);
	}

	/**
	 * @param list<int> $category_ids
	 */
	private function map_preview( EffectiveConfiguration $resolved, array $category_ids ): EffectiveConfigurationPreviewViewModel {
		$fields = [];

		foreach ( ConfigurationFieldCatalog::all() as $meta ) {
			$field_key = $meta['key'];
			if ( $meta['is_collection'] ) {
				$field = $resolved->collection( $field_key );
				$members = $field?->members ?? [];
				$labels = [];
				foreach ( $members as $member_id ) {
					$labels[] = null !== $meta['entity_kind']
						? $this->entity_labels->label( $meta['entity_kind'], $member_id )
						: (string) $member_id;
				}

				$state = $field?->state ?? EffectiveFieldState::Unresolved;
				$fields[] = [
					'field_key'             => $field_key,
					'label'                 => $meta['label'],
					'is_collection'         => true,
					'effective_state'       => $state->value,
					'effective_state_label' => ReasonCodeLabelMapper::state_label( $state ),
					'effective_state_tone'  => ReasonCodeLabelMapper::state_tone( $state ),
					'effective_value'       => null,
					'effective_members'     => $members,
					'effective_member_labels' => $labels,
					'provenance_label'      => ProvenanceLabelMapper::map( $field?->provenance->source_label ?? 'global' ),
					'provenance_lines'      => ProvenanceLabelMapper::mutation_summaries(
						$field?->mutation_steps ?? [],
						fn ( int $id ): string => $this->entity_labels->label( (string) $meta['entity_kind'], $id )
					),
					'validation_messages'   => ReasonCodeLabelMapper::explain_codes( $field?->reason_codes ?? [] ),
				];
			} else {
				$field = $resolved->scalar( $field_key );
				$state = $field?->state ?? EffectiveFieldState::Unresolved;
				$value = $field?->value;
				$value_label = $value;
				if ( null !== $meta['entity_kind'] && is_int( $value ) ) {
					$value_label = $this->entity_labels->label( $meta['entity_kind'], $value );
				} elseif ( is_array( $meta['enum_options'] ) && is_string( $value ) ) {
					$value_label = $meta['enum_options'][ $value ] ?? $value;
				}

				$fields[] = [
					'field_key'             => $field_key,
					'label'                 => $meta['label'],
					'is_collection'         => false,
					'effective_state'       => $state->value,
					'effective_state_label' => ReasonCodeLabelMapper::state_label( $state ),
					'effective_state_tone'  => ReasonCodeLabelMapper::state_tone( $state ),
					'effective_value'       => $value,
					'effective_value_label' => EffectiveFieldState::Disabled === $state ? 'Turned off' : $value_label,
					'effective_members'     => [],
					'provenance_label'      => ProvenanceLabelMapper::map( $field?->provenance->source_label ?? 'global' ),
					'provenance_lines'      => [],
					'validation_messages'   => ReasonCodeLabelMapper::explain_codes( $field?->reason_codes ?? [] ),
				];
			}
		}

		return new EffectiveConfigurationPreviewViewModel(
			$resolved->product_id,
			$resolved->variation_id,
			$resolved->slice_key,
			ConfigurationFieldCatalog::slice_label( $resolved->slice_key ),
			$resolved->state->value,
			ReasonCodeLabelMapper::state_label( $resolved->state ),
			ReasonCodeLabelMapper::state_tone( $resolved->state ),
			ReasonCodeLabelMapper::explain_codes( $resolved->reason_codes ),
			$fields,
			[
				'global_version'     => $resolved->version->global_version,
				'product_version'    => $resolved->version->product_version,
				'variation_version'  => $resolved->version->variation_version,
				'fingerprint'        => $resolved->version->fingerprint,
			],
			ScopedConfigurationNotices::PREVIEW_LIMITATION_TITLE,
			ScopedConfigurationNotices::PREVIEW_LIMITATION_MESSAGE,
			ScopedConfigurationNotices::HARD_CONSTRAINT_NOTE,
			ScopedConfigurationNotices::TRANSITIONAL_TITLE,
			ScopedConfigurationNotices::TRANSITIONAL_MESSAGE,
			$this->category_inspector->inspect( $resolved->product_id, $category_ids ),
			true
		);
	}

	/**
	 * @return array{previous: array<string, mixed>|null, new: array<string, mixed>}
	 */
	private function build_audit_summary(
		ConfigurationScopeType $scope_type,
		int $scope_id,
		string $slice_key,
		?array $previous,
		array $new,
		int $version_before,
		int $version_after
	): array {
		return [
			'previous' => null === $previous ? null : array_merge(
				$previous,
				[
					'scope_type'      => $scope_type->value,
					'scope_id'        => $scope_id,
					'slice_key'       => $slice_key,
					'config_version'  => $version_before,
				]
			),
			'new' => array_merge(
				$new,
				[
					'scope_type'     => $scope_type->value,
					'scope_id'       => $scope_id,
					'slice_key'      => $slice_key,
					'config_version' => $version_after,
				]
			),
		];
	}

	/**
	 * @return array{scalars: array<string, array<string, mixed>>, collections: array<string, array<string, mixed>>}
	 */
	private function instruction_snapshot( ScopedConfiguration $configuration ): array {
		$scalars = [];
		foreach ( $configuration->scalars as $key => $instruction ) {
			$scalars[ $key ] = $instruction->toStorageArray();
		}

		$collections = [];
		foreach ( $configuration->collections as $key => $instruction ) {
			$collections[ $key ] = $instruction->toStorageArray();
		}

		return [
			'scalars'      => $scalars,
			'collections'  => $collections,
		];
	}

	private function is_valid_slice_key( string $slice_key ): bool {
		if ( ConfigurationScope::DEFAULT_SLICE_KEY === $slice_key ) {
			return true;
		}

		$options = ConfigurationFieldCatalog::enum_options(
			ConfigurationFieldKey::FULFILMENT_AVAILABILITY
		);

		return is_array( $options ) && isset( $options[ $slice_key ] );
	}

	/**
	 * @param list<ScopedConfiguration> $configurations
	 */
	private function slice_exists( array $configurations, string $slice_key ): bool {
		foreach ( $configurations as $configuration ) {
			if ( $configuration->scope->slice_key === $slice_key ) {
				return true;
			}
		}

		return false;
	}

	private function profile_default_mode_label( ConfigurationScopeType $scope_type, ?EffectiveConfiguration $effective ): string {
		if ( ConfigurationScopeType::Variation === $scope_type ) {
			return 'Use Product Setting';
		}

		$availability = $effective?->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY )?->value;
		if ( is_string( $availability ) && '' !== $availability ) {
			$profile = FulfilmentProfileRegistry::get( $availability );
			if ( null !== $profile ) {
				return sprintf( 'Use Site-wide Default: %s', $profile->label );
			}
		}

		return 'Use Site-wide Default';
	}
}
