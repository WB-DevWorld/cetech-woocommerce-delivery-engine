<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldRegistry;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationVersionInfo;
use CetechDeliveryEngine\Domain\Configuration\InvalidConfigurationException;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;

/**
 * wpdb-backed scoped configuration storage.
 *
 * Not consumed by storefront runtime in Stage 2.
 */
final class WpdbScopedConfigurationRepository implements ScopedConfigurationRepositoryInterface {

	public function getGlobalConfiguration(): ?ScopedConfiguration {
		return $this->findByScopeAndSlice(
			ConfigurationScopeType::Global,
			ConfigurationScope::GLOBAL_SCOPE_ID,
			ConfigurationScope::DEFAULT_SLICE_KEY
		);
	}

	public function ensureGlobalScope(): ScopedConfiguration {
		$existing = $this->getGlobalConfiguration();

		if ( null !== $existing ) {
			return $existing;
		}

		$saved = $this->saveScopedConfiguration(
			new ScopedConfiguration( ConfigurationScope::global( 1 ) )
		);

		$this->sync_global_version_option( $saved->scope->config_version );

		return $saved;
	}

	public function findByScope( ConfigurationScopeType $scope_type, int $scope_id ): array {
		global $wpdb;

		$table = TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX );
		$sql   = "SELECT * FROM `{$table}` WHERE scope_type = %s AND scope_id = %d ORDER BY slice_key ASC, id ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $scope_type->value, $scope_id ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$results = [];

		foreach ( $rows as $row ) {
			$hydrated = $this->hydrate_configuration( $row );

			if ( null !== $hydrated ) {
				$results[] = $hydrated;
			}
		}

		return $results;
	}

	public function findByScopeAndSlice( ConfigurationScopeType $scope_type, int $scope_id, string $slice_key ): ?ScopedConfiguration {
		$row = $this->fetch_scope_row( $scope_type->value, $scope_id, $slice_key );

		return null === $row ? null : $this->hydrate_configuration( $row );
	}

	public function findByLegacyRuleId( int $legacy_rule_id ): ?ScopedConfiguration {
		if ( $legacy_rule_id <= 0 ) {
			return null;
		}

		global $wpdb;

		$table = TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX );
		$sql   = "SELECT * FROM `{$table}` WHERE legacy_rule_id = %d LIMIT 1";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $legacy_rule_id ), ARRAY_A );

		return is_array( $row ) ? $this->hydrate_configuration( $row ) : null;
	}

	public function findByParentProductId( int $parent_product_id ): array {
		if ( $parent_product_id <= 0 ) {
			return [];
		}

		global $wpdb;

		$table = TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX );
		$sql   = "SELECT * FROM `{$table}` WHERE parent_product_id = %d ORDER BY scope_id ASC, slice_key ASC, id ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $parent_product_id ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$results = [];

		foreach ( $rows as $row ) {
			$hydrated = $this->hydrate_configuration( $row );

			if ( null !== $hydrated ) {
				$results[] = $hydrated;
			}
		}

		return $results;
	}

	public function saveScopedConfiguration( ScopedConfiguration $configuration ): ScopedConfiguration {
		$scope = $configuration->scope;

		$this->assert_valid_scope_identity( $scope );
		$this->assert_known_instructions( $configuration );

		$existing = $this->findByScopeAndSlice( $scope->scope_type, $scope->scope_id, $scope->slice_key );

		if ( null !== $existing ) {
			$compare = $configuration->withScope(
				new ConfigurationScope(
					$existing->scope->id,
					$scope->scope_type,
					$scope->scope_id,
					$scope->slice_key,
					$scope->parent_product_id,
					$scope->status,
					$existing->scope->config_version,
					$scope->source,
					$scope->legacy_rule_id ?? $existing->scope->legacy_rule_id,
					$existing->scope->created_at,
					$existing->scope->updated_at
				)
			);

			if ( $compare->fingerprint() === $existing->fingerprint() ) {
				return $existing;
			}

			$new_version = $existing->scope->config_version + 1;
			$this->replace_instructions( (int) $existing->scope->id, $configuration );
			$this->update_scope_meta(
				(int) $existing->scope->id,
				$scope,
				$new_version,
				$scope->legacy_rule_id ?? $existing->scope->legacy_rule_id
			);

			$saved = $this->findByScopeAndSlice( $scope->scope_type, $scope->scope_id, $scope->slice_key );

			if ( null === $saved ) {
				throw new InvalidConfigurationException( 'Failed to reload scoped configuration after update.' );
			}

			if ( ConfigurationScopeType::Global === $scope->scope_type ) {
				$this->sync_global_version_option( $saved->scope->config_version );
			}

			return $saved;
		}

		$scope_row_id = $this->insert_scope( $scope );
		$this->replace_instructions( $scope_row_id, $configuration );

		$saved = $this->findByScopeAndSlice( $scope->scope_type, $scope->scope_id, $scope->slice_key );

		if ( null === $saved ) {
			throw new InvalidConfigurationException( 'Failed to reload scoped configuration after insert.' );
		}

		if ( ConfigurationScopeType::Global === $scope->scope_type ) {
			$this->sync_global_version_option( $saved->scope->config_version );
		}

		return $saved;
	}

	public function deleteScope( ConfigurationScopeType $scope_type, int $scope_id, string $slice_key = ConfigurationScope::DEFAULT_SLICE_KEY ): bool {
		if ( ConfigurationScopeType::Global === $scope_type ) {
			throw new InvalidConfigurationException( 'Global scope cannot be deleted.' );
		}

		$existing = $this->findByScopeAndSlice( $scope_type, $scope_id, $slice_key );

		if ( null === $existing || null === $existing->scope->id ) {
			return false;
		}

		$scope_row_id = (int) $existing->scope->id;
		$this->delete_instructions( $scope_row_id );

		global $wpdb;
		$table = TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $table, [ 'id' => $scope_row_id ], [ '%d' ] );

		return false !== $deleted && $deleted > 0;
	}

	public function getVersionInfo( ConfigurationScopeType $scope_type, int $scope_id, string $slice_key = ConfigurationScope::DEFAULT_SLICE_KEY ): ?ConfigurationVersionInfo {
		$row = $this->fetch_scope_row( $scope_type->value, $scope_id, $slice_key );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return new ConfigurationVersionInfo(
			(int) $row['id'],
			(string) $row['scope_type'],
			(int) $row['scope_id'],
			(string) $row['slice_key'],
			(int) $row['config_version']
		);
	}

	public function getGlobalVersion(): int {
		$global = $this->getGlobalConfiguration();

		if ( null !== $global ) {
			return $global->scope->config_version;
		}

		$stored = get_option( ScopedConfigurationSchema::GLOBAL_VERSION_OPTION, 0 );

		return max( 0, (int) $stored );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate_configuration( array $row ): ?ScopedConfiguration {
		$scope_type = ConfigurationScopeType::tryFrom( (string) ( $row['scope_type'] ?? '' ) );
		$status     = RecordStatus::tryFrom( (string) ( $row['status'] ?? '' ) );
		$source     = ConfigurationSource::tryFrom( (string) ( $row['source'] ?? ConfigurationSource::Native->value ) );

		if ( null === $scope_type || null === $status ) {
			return null;
		}

		$parent = $row['parent_product_id'] ?? null;
		$legacy = $row['legacy_rule_id'] ?? null;

		$scope = new ConfigurationScope(
			(int) $row['id'],
			$scope_type,
			(int) $row['scope_id'],
			(string) ( $row['slice_key'] ?? '' ),
			null === $parent || '' === $parent ? null : (int) $parent,
			$status,
			max( 1, (int) ( $row['config_version'] ?? 1 ) ),
			$source ?? ConfigurationSource::Native,
			null === $legacy || '' === $legacy ? null : (int) $legacy,
			isset( $row['created_at'] ) ? (string) $row['created_at'] : null,
			isset( $row['updated_at'] ) ? (string) $row['updated_at'] : null
		);

		$scope_row_id = (int) $row['id'];

		return new ScopedConfiguration(
			$scope,
			$this->load_scalars( $scope_row_id ),
			$this->load_collections( $scope_row_id )
		);
	}

	/**
	 * @return array<string, ScalarFieldInstruction>
	 */
	private function load_scalars( int $scope_row_id ): array {
		global $wpdb;

		$table = TableNames::for( ScopedConfigurationSchema::FIELDS_SUFFIX );
		$sql   = "SELECT * FROM `{$table}` WHERE scope_row_id = %d ORDER BY field_key ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $scope_row_id ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$scalars = [];

		foreach ( $rows as $row ) {
			$field_key = (string) ( $row['field_key'] ?? '' );

			if ( ! ConfigurationFieldRegistry::has( $field_key ) ) {
				continue;
			}

			$scalars[ $field_key ] = ScalarFieldInstruction::fromStorage(
				$field_key,
				(string) ( $row['mode'] ?? '' ),
				$row['value_text'] ?? null,
				(string) ( $row['value_type'] ?? '' )
			);
		}

		return $scalars;
	}

	/**
	 * @return array<string, CollectionFieldInstruction>
	 */
	private function load_collections( int $scope_row_id ): array {
		global $wpdb;

		$table = TableNames::for( ScopedConfigurationSchema::COLLECTIONS_SUFFIX );
		$sql   = "SELECT * FROM `{$table}` WHERE scope_row_id = %d ORDER BY field_key ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $scope_row_id ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$collections = [];

		foreach ( $rows as $row ) {
			$field_key = (string) ( $row['field_key'] ?? '' );

			if ( ! ConfigurationFieldRegistry::has( $field_key ) ) {
				continue;
			}

			$collections[ $field_key ] = CollectionFieldInstruction::fromStorage(
				$field_key,
				(string) ( $row['mode'] ?? '' ),
				$row['members_json'] ?? '[]'
			);
		}

		return $collections;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function fetch_scope_row( string $scope_type, int $scope_id, string $slice_key ): ?array {
		global $wpdb;

		$table = TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX );
		$sql   = "SELECT * FROM `{$table}` WHERE scope_type = %s AND scope_id = %d AND slice_key = %s LIMIT 1";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $scope_type, $scope_id, $slice_key ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	private function insert_scope( ConfigurationScope $scope ): int {
		global $wpdb;

		$now   = gmdate( 'Y-m-d H:i:s' );
		$table = TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			[
				'scope_type'        => $scope->scope_type->value,
				'scope_id'          => $scope->scope_id,
				'slice_key'         => $scope->slice_key,
				'parent_product_id' => $scope->parent_product_id,
				'status'            => $scope->status->value,
				'config_version'    => max( 1, $scope->config_version ),
				'source'            => $scope->source->value,
				'legacy_rule_id'    => $scope->legacy_rule_id,
				'created_at'        => $now,
				'updated_at'        => $now,
			],
			[ '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			throw new InvalidConfigurationException( 'Failed to insert configuration scope.' );
		}

		return (int) $wpdb->insert_id;
	}

	private function update_scope_meta( int $scope_row_id, ConfigurationScope $scope, int $config_version, ?int $legacy_rule_id ): void {
		global $wpdb;

		$table = TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			[
				'parent_product_id' => $scope->parent_product_id,
				'status'            => $scope->status->value,
				'config_version'    => $config_version,
				'source'            => $scope->source->value,
				'legacy_rule_id'    => $legacy_rule_id,
				'updated_at'        => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $scope_row_id ],
			[ '%d', '%s', '%d', '%s', '%d', '%s' ],
			[ '%d' ]
		);

		if ( false === $updated ) {
			throw new InvalidConfigurationException( 'Failed to update configuration scope metadata.' );
		}
	}

	private function replace_instructions( int $scope_row_id, ScopedConfiguration $configuration ): void {
		$this->delete_instructions( $scope_row_id );

		foreach ( $configuration->scalars as $instruction ) {
			$this->insert_scalar( $scope_row_id, $instruction );
		}

		foreach ( $configuration->collections as $instruction ) {
			$this->insert_collection( $scope_row_id, $instruction );
		}
	}

	private function delete_instructions( int $scope_row_id ): void {
		global $wpdb;

		$fields = TableNames::for( ScopedConfigurationSchema::FIELDS_SUFFIX );
		$collections = TableNames::for( ScopedConfigurationSchema::COLLECTIONS_SUFFIX );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $fields, [ 'scope_row_id' => $scope_row_id ], [ '%d' ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $collections, [ 'scope_row_id' => $scope_row_id ], [ '%d' ] );
	}

	private function insert_scalar( int $scope_row_id, ScalarFieldInstruction $instruction ): void {
		global $wpdb;

		$storage = $instruction->toStorageArray();
		$value_text = null;

		if ( ScalarConfigurationMode::Override === $instruction->mode ) {
			$value_text = $this->encode_scalar_value( $instruction->value );
		}

		$table = TableNames::for( ScopedConfigurationSchema::FIELDS_SUFFIX );
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			[
				'scope_row_id' => $scope_row_id,
				'field_key'    => $storage['field_key'],
				'mode'         => $storage['mode'],
				'value_type'   => $storage['value_type'],
				'value_text'   => $value_text,
				'created_at'   => $now,
				'updated_at'   => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			throw new InvalidConfigurationException(
				sprintf( 'Failed to insert scalar field %s.', $instruction->field_key )
			);
		}
	}

	private function insert_collection( int $scope_row_id, CollectionFieldInstruction $instruction ): void {
		global $wpdb;

		$encoded = wp_json_encode( $instruction->members );

		if ( false === $encoded ) {
			throw new InvalidConfigurationException(
				sprintf( 'Failed to encode collection members for %s.', $instruction->field_key )
			);
		}

		$table = TableNames::for( ScopedConfigurationSchema::COLLECTIONS_SUFFIX );
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			[
				'scope_row_id'  => $scope_row_id,
				'field_key'     => $instruction->field_key,
				'mode'          => $instruction->mode->value,
				'members_json'  => $encoded,
				'created_at'    => $now,
				'updated_at'    => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			throw new InvalidConfigurationException(
				sprintf( 'Failed to insert collection field %s.', $instruction->field_key )
			);
		}
	}

	private function encode_scalar_value( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( is_int( $value ) ) {
			return (string) $value;
		}

		if ( is_string( $value ) ) {
			return $value;
		}

		throw new InvalidConfigurationException( 'Unsupported scalar value encoding.' );
	}

	private function assert_valid_scope_identity( ConfigurationScope $scope ): void {
		if ( ConfigurationScopeType::Global === $scope->scope_type ) {
			return;
		}

		if ( $scope->scope_id <= 0 ) {
			throw new InvalidConfigurationException( 'Invalid scope_id.' );
		}
	}

	private function assert_known_instructions( ScopedConfiguration $configuration ): void {
		foreach ( array_keys( $configuration->scalars ) as $field_key ) {
			if ( ! ConfigurationFieldRegistry::has( (string) $field_key ) ) {
				throw new InvalidConfigurationException( sprintf( 'Unknown scalar field key: %s', $field_key ) );
			}

			$definition = ConfigurationFieldRegistry::get( (string) $field_key );

			if ( $definition->is_collection ) {
				throw new InvalidConfigurationException( sprintf( 'Field %s must be stored as a collection.', $field_key ) );
			}
		}

		foreach ( array_keys( $configuration->collections ) as $field_key ) {
			if ( ! ConfigurationFieldRegistry::has( (string) $field_key ) ) {
				throw new InvalidConfigurationException( sprintf( 'Unknown collection field key: %s', $field_key ) );
			}

			$definition = ConfigurationFieldRegistry::get( (string) $field_key );

			if ( ! $definition->is_collection ) {
				throw new InvalidConfigurationException( sprintf( 'Field %s must be stored as a scalar.', $field_key ) );
			}
		}
	}

	private function sync_global_version_option( int $version ): void {
		update_option( ScopedConfigurationSchema::GLOBAL_VERSION_OPTION, $version, false );
	}
}
