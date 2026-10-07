<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DataLifecycle;

use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleClass;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecyclePolicy;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleRegistry;
use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataLifecycleRegistryTest extends TestCase {

	public function test_complete_inventory_matches_original32_and_three_quote_stores(): void {
		$original = array_merge( ConfigurationTables::all_suffixes(), OperationStoreSchema::SUFFIXES, RuleLifecycleSchema::SUFFIXES );
		$expected = array_merge( $original, DeliveryQuoteSchema::SUFFIXES );
		$registry = DataLifecycleRegistry::standard();
		$tables = array_values( array_filter( $registry->classes(), static fn( DataLifecycleClass $class ): bool => 'plugin_table' === $class->storage_adapter ) );
		self::assertCount( 32, $original );
		self::assertSame( $this->sorted( $original ), $this->sorted( DataLifecycleManifest::ORIGINAL_DOMAIN_TABLE_SUFFIXES ) );
		self::assertSame( DeliveryQuoteSchema::SUFFIXES, DataLifecycleManifest::QUOTE_TABLE_SUFFIXES );
		self::assertCount( 35, $expected );
		self::assertSame( $this->sorted( $expected ), $this->sorted( array_column( $tables, 'storage_key' ) ) );
		foreach ( $tables as $class ) {
			self::assertSame( [ DataLifecyclePolicy::Preserve, DataLifecyclePolicy::Preserve, DataLifecyclePolicy::Preserve, null ],
				[ $class->normal_policy, $class->default_uninstall_policy, $class->explicit_uninstall_policy, $class->expires_after_seconds ] );
		}
		self::assertContains( 'preserve_cor007_token_completions_and_all_material_audit', $registry->get( 'table.audit_log' )->protections );
		self::assertContains( 'preserve_acceptance_event_pair_both_directions_and_publication_receipt', $registry->get( 'table.operation_records' )->protections );
		self::assertContains( 'preserve_guard_pointers_supersession_all_states_and_schedule_evidence', $registry->get( 'table.rule_versions' )->protections );
		foreach ( DeliveryQuoteSchema::SUFFIXES as $suffix ) {
			$class = $registry->get( 'table.' . $suffix );
			self::assertSame( 'schema9', $class->accepted_format );
			self::assertFalse( $class->cleanup_eligible() );
			self::assertContains( 'no_quote_cleanup_or_admission_grant', $class->protections );
		}
		self::assertContains( 'preserve_immutable_header_body_namespaces_and_tombstones', $registry->get( 'table.delivery_quotes' )->protections );
		self::assertContains( 'preserve_order_group_native_snapshot_and_seal_references', $registry->get( 'table.delivery_quote_bindings' )->protections );
		self::assertContains( 'preserve_admission_intent_leases_and_unknown_outcomes_without_takeover', $registry->get( 'table.delivery_quote_budget_windows' )->protections );
	}

	public function test_exact_capabilities_and_authored_control_options_follow_existing_owners(): void {
		$flags = new FeatureFlags();
		$expected_flags = array_map( $flags->option_name( ... ), array_keys( $flags->defaults() ) );
		self::assertSame( Capabilities::ALL, DataLifecycleManifest::CAPABILITIES );
		self::assertCount( 18, DataLifecycleManifest::CAPABILITIES );
		self::assertSame( $this->sorted( $expected_flags ), $this->sorted( DataLifecycleManifest::FEATURE_FLAG_OPTIONS ) );
		self::assertCount( 43, DataLifecycleManifest::OPTIONS );
		self::assertCount( 41, DataLifecycleManifest::PRESERVED_OPTIONS );
		$registry = DataLifecycleRegistry::standard();
		foreach ( DataLifecycleManifest::PRESERVED_OPTIONS as $key ) {
			$class = $registry->get( 'option.' . $key );
			self::assertSame( [ DataLifecyclePolicy::Preserve, DataLifecyclePolicy::Preserve, DataLifecyclePolicy::Preserve ],
				[ $class->normal_policy, $class->default_uninstall_policy, $class->explicit_uninstall_policy ] );
		}
		self::assertSame( DataLifecyclePolicy::CapabilityMarkerRemoval, $registry->get( 'option.' . Capabilities::VERSION_OPTION )->explicit_uninstall_policy );
		self::assertSame( DataLifecyclePolicy::UninstallIntentCompletion, $registry->get( 'option.' . DataLifecycleManifest::UNINSTALL_INTENT )->explicit_uninstall_policy );
		foreach ( [ 'cetech_de_global_configuration_version', 'cetech_de_last_migration_status', 'cetech_de_shipment_ops_issues',
			'cetech_de_geography_revision', DataLifecycleManifest::COORDINATOR_OPTION, DataLifecycleManifest::UNINSTALL_STATUS ] as $key ) {
			self::assertContains( $key, DataLifecycleManifest::PRESERVED_OPTIONS );
		}
	}

	public function test_only_new_managed_cache_has_ordinary_cleanup_disposition(): void {
		$registry = DataLifecycleRegistry::standard();
		$eligible = array_values( array_filter( $registry->classes(), static fn( DataLifecycleClass $class ): bool => $class->cleanup_eligible() ) );
		self::assertCount( 1, $eligible );
		$cache = $eligible[0];
		self::assertSame( DataLifecycleManifest::CACHE_CLASS, $cache->class_id );
		self::assertSame( [ 'current_site', 'native_options_envelope_v1', DataLifecyclePolicy::ManagedCacheExpiry,
			DataLifecyclePolicy::ManagedCacheRemoval, 120 ], [ $cache->site_scope, $cache->storage_adapter, $cache->normal_policy,
			$cache->explicit_uninstall_policy, $cache->expires_after_seconds ] );
		self::assertSame( DataLifecycleManifest::CACHE_PREFIX, $cache->storage_key );
		self::assertSame( [ DataLifecycleManifest::CACHE_CLASS ], $registry->diagnostics()['normal_cleanup_classes'] );
	}

	public function test_old_transient_clocks_and_other_owned_stores_are_not_worker_grants(): void {
		$registry = DataLifecycleRegistry::standard();
		$expected_ttl = [ 'activation_notice' => 60, 'admin_notice' => 60, 'admin_draft' => 60, 'scoped_draft' => 900,
			'geography_rate_bucket' => 60, 'legacy_geography_response' => 120 ];
		foreach ( $expected_ttl as $id => $ttl ) {
			$class = $registry->get( 'transient.' . $id );
			self::assertSame( [ DataLifecyclePolicy::OwnerExpiryOnly, $ttl, false ], [ $class->normal_policy, $class->expires_after_seconds, $class->cleanup_eligible() ] );
		}
		foreach ( $registry->classes() as $class ) {
			if ( str_starts_with( $class->class_id, 'file.' ) || str_starts_with( $class->class_id, 'shared.' ) || str_starts_with( $class->class_id, 'queue.' ) ) {
				self::assertSame( [ false, false, DataLifecyclePolicy::Preserve ],
					[ $class->cleanup_eligible(), $class->normal_policy->sql_row_removal_eligible(), $class->explicit_uninstall_policy ] );
			}
		}
		self::assertSame( DataLifecyclePolicy::ActivationNoticeRemoval, $registry->get( 'transient.activation_notice' )->explicit_uninstall_policy );
		self::assertSame( DataLifecyclePolicy::RolePermissionRemoval, $registry->get( 'permissions.role_capabilities' )->explicit_uninstall_policy );
		self::assertSame( DataLifecycleManifest::CLEANUP_GROUP, $registry->get( 'queue.data_lifecycle_tick' )->owned_group );
		self::assertSame( 'cetech-delivery-engine-geography-{pack_id}', $registry->get( 'queue.geography_tick' )->owned_group );
		self::assertSame( 'cetech-delivery-engine-geography-{pack_id}', $registry->get( 'queue.geography_download' )->owned_group );
	}

	#[DataProvider( 'missing_store_provider' )]
	public function test_missing_any_storage_family_refuses_registration( string $missing ): void {
		$classes = array_values( array_filter( DataLifecycleRegistry::standard()->classes(), static fn( DataLifecycleClass $class ): bool => $missing !== $class->class_id ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Incomplete or invalid data lifecycle registry.' );
		new DataLifecycleRegistry( $classes );
	}

	public static function missing_store_provider(): array {
		return [
			'new operation history' => [ 'table.operation_records' ], 'new rule history' => [ 'table.rule_versions' ],
			'quote immutable body and tombstone' => [ 'table.delivery_quotes' ], 'quote order and seal binding' => [ 'table.delivery_quote_bindings' ],
			'quote budget and lease' => [ 'table.delivery_quote_budget_windows' ],
			'authored option' => [ 'option.cetech_de_sitewide_defaults' ], 'Woo snapshot' => [ 'woo_meta._cetech_de_delivery_snapshot' ],
			'user reference cursor' => [ 'user._cetech_de_shipments_reviewed_event_id' ], 'session' => [ 'session.cetech_de_customer_context' ],
			'legacy draft' => [ 'transient.scoped_draft' ], 'source file' => [ 'file.geography_generation_source' ],
			'owned queue' => [ 'queue.bulk_tick' ], 'foreign cache' => [ 'shared.legacy_geography_object_cache' ],
			'finite permissions' => [ 'permissions.role_capabilities' ], 'adopted cache' => [ DataLifecycleManifest::CACHE_CLASS ],
		];
	}

	public function test_duplicate_id_is_refused_even_when_all_stores_are_present(): void {
		$classes = DataLifecycleRegistry::standard()->classes();
		$classes[] = $classes[0];
		$this->expectException( \InvalidArgumentException::class );
		new DataLifecycleRegistry( $classes );
	}

	#[DataProvider( 'invalid_declaration_provider' )]
	public function test_unknown_or_altered_declarations_refuse_without_echoing_input( string $field, mixed $value ): void {
		$definition = DataLifecycleManifest::definitions()[0];
		$definition[$field] = $value;
		try {
			DataLifecycleClass::from_definition( $definition );
			self::fail( 'Altered declaration was accepted.' );
		} catch ( \InvalidArgumentException $error ) {
			self::assertSame( 'Invalid data lifecycle declaration.', $error->getMessage() );
			self::assertSame( 0, $error->getCode() );
		}
	}

	public static function invalid_declaration_provider(): array {
		return [
			'unknown class' => [ 'class_id', 'private query token' ], 'unknown owner' => [ 'owner', 'external_owner' ],
			'unknown adapter' => [ 'storage_adapter', 'raw_sql' ], 'forged table' => [ 'storage_key', 'other_site_options' ],
			'foreign site' => [ 'site_scope', 'network' ], 'unknown format' => [ 'format', 2 ], 'coerced format' => [ 'format', '1' ],
			'unknown codec' => [ 'accepted_format', 'arbitrary_json' ], 'SQL selector' => [ 'selector', 'DELETE FROM options' ],
			'path selector' => [ 'selector', '../../private-source.txt' ], 'arbitrary policy' => [ 'normal_policy', 'purge' ],
			'age purge' => [ 'normal_policy', 'managed_cache_expiry' ], 'default drop' => [ 'default_uninstall_policy', 'managed_cache_removal' ],
			'explicit domain drop' => [ 'explicit_uninstall_policy', 'managed_cache_removal' ], 'invented TTL' => [ 'expires_after_seconds', 1 ],
			'unknown references' => [ 'protections', [] ], 'arbitrary callback' => [ 'selector', static fn(): bool => true ],
			'private payload' => [ 'payload', [ 'renamed' => [ 'address' => 'private-value' ] ] ],
		];
	}

	public function test_missing_metadata_and_malformed_class_list_are_rejected(): void {
		$definition = DataLifecycleManifest::definitions()[0];
		unset( $definition['proof_cases'] );
		try { DataLifecycleClass::from_definition( $definition ); self::fail( 'Missing metadata accepted.' ); }
		catch ( \InvalidArgumentException $error ) { self::assertSame( 'Invalid data lifecycle declaration.', $error->getMessage() ); }
		foreach ( [ [ 'caller' => DataLifecycleRegistry::standard()->classes()[0] ], [ new \stdClass() ], array_fill( 0, 257, null ) ] as $classes ) {
			try { new DataLifecycleRegistry( $classes ); self::fail( 'Malformed registry accepted.' ); }
			catch ( \InvalidArgumentException $error ) { self::assertSame( 'Incomplete or invalid data lifecycle registry.', $error->getMessage() ); }
		}
	}

	public function test_caller_references_cannot_change_accepted_policy_or_source_metadata(): void {
		$definition = DataLifecycleManifest::definitions()[0];
		$expected = $definition;
		$id =& $definition['class_id'];
		$protection =& $definition['protections'][0];
		$source =& $definition['source_paths'][0];
		$class = DataLifecycleClass::from_definition( $definition );
		$id = 'caller_changed_id';
		$protection = 'delete_unreferenced';
		$source = '/private/symlink/source';
		self::assertSame( $expected, $class->definition() );
		$returned = $class->definition();
		$returned['protections'][0] = 'also_changed';
		self::assertSame( $expected, $class->definition() );
	}

	public function test_digest_is_stable_across_input_order_and_detached_class_lists(): void {
		$standard = DataLifecycleRegistry::standard();
		$reverse = new DataLifecycleRegistry( array_reverse( $standard->classes() ) );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $standard->policy_digest() );
		self::assertSame( $standard->policy_digest(), $reverse->policy_digest() );
		$list = $standard->classes();
		array_pop( $list );
		self::assertSame( $standard->policy_digest(), DataLifecycleRegistry::standard()->policy_digest() );
		self::assertCount( 127, $standard->classes() );
	}

	public function test_diagnostics_do_not_emit_selectors_paths_storage_names_or_caller_content(): void {
		$registry = DataLifecycleRegistry::standard();
		$class = $registry->get( 'file.geography_generation_source' );
		self::assertSame( [ 'format', 'class', 'owner', 'normal_policy', 'default_uninstall_policy', 'explicit_uninstall_policy', 'cleanup_eligible' ], array_keys( $class->diagnostics() ) );
		$encoded = json_encode( [ $registry->diagnostics(), $class->diagnostics() ], JSON_THROW_ON_ERROR );
		self::assertStringNotContainsString( 'src/', $encoded );
		self::assertStringNotContainsString( $class->selector, $encoded );
		self::assertStringNotContainsString( $class->storage_key, $encoded );
		foreach ( [ $registry, $class ] as $private ) {
			try { json_encode( [ 'renamed' => [ 'nested' => $private ] ], JSON_THROW_ON_ERROR ); self::fail( 'Generic private serialization accepted.' ); }
			catch ( \LogicException $error ) { self::assertStringContainsString( 'explicit projection', $error->getMessage() ); }
		}
		try { $registry->get( 'SQL exception: private-token' ); self::fail( 'Unknown class accepted.' ); }
		catch ( \InvalidArgumentException $error ) { self::assertSame( 'Data lifecycle class is unavailable.', $error->getMessage() ); }
	}

	public function test_compiled_source_and_proof_mapping_has_no_unresolved_files(): void {
		$root = dirname( __DIR__, 3 );
		foreach ( DataLifecycleRegistry::standard()->classes() as $class ) {
			self::assertNotEmpty( $class->source_paths );
			self::assertNotEmpty( $class->proof_cases );
			foreach ( $class->source_paths as $path ) {
				self::assertFileExists( $root . '/' . $path, $class->class_id );
			}
			foreach ( $class->proof_cases as $case ) {
				if ( in_array( $class->storage_key, DataLifecycleManifest::QUOTE_TABLE_SUFFIXES, true ) && 'plugin_table' === $class->storage_adapter ) {
					self::assertContains( $case, [ 'W2Q-09', 'W2Q-39' ] );
				} else {
					self::assertMatchesRegularExpression( '/^C06-(?:0[1-9]|[12][0-9]|30)$/D', $case );
				}
			}
		}
	}

	public function test_manifest_loads_without_composer_wordpress_woo_or_domain_dependencies(): void {
		$path = dirname( __DIR__, 3 ) . '/src/Bootstrap/DataLifecycleManifest.php';
		$code = 'require ' . var_export( $path, true ) . '; $m = \\CetechDeliveryEngine\\Bootstrap\\DataLifecycleManifest::class; '
			. 'echo json_encode([count($m::DOMAIN_TABLE_SUFFIXES),count($m::OPTIONS),count($m::CAPABILITIES),count($m::definitions()),'
			. 'function_exists("get_option"),class_exists("WC_Order",false),class_exists("CetechDeliveryEngine\\\\Domain\\\\DataLifecycle\\\\DataLifecycleRegistry",false),'
			. '$m::supports_uninstall_intent(1),$m::supports_uninstall_intent("1"),$m::supports_uninstall_intent(true)],JSON_THROW_ON_ERROR);';
		$process = proc_open( [ PHP_BINARY, '-n', '-r', $code ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		self::assertSame( [ 35, 43, 18, 127, false, false, false, true, true, false ], json_decode( $output, true, 16, JSON_THROW_ON_ERROR ) );
	}

	private function sorted( array $values ): array {
		sort( $values, SORT_STRING );
		return $values;
	}
}
