<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Bootstrap;

/**
 * Pure own-source inventory shared by Composer and standalone lifecycle paths.
 * Declarations are identifiers, never caller-supplied SQL, paths or callbacks.
 */
final class DataLifecycleManifest {

	public const FORMAT = 1;
	public const CACHE_CLASS = 'geography_response_cache_v1';
	public const CACHE_PREFIX = 'cetech_de_gc_geo_v1_';
	public const COORDINATOR_OPTION = 'cetech_de_gc_state_geo_v1';
	public const CHECKOUT_CONTROL_OPTION = 'cetech_de_checkout_control_v1';
	public const UNINSTALL_STATUS = 'cetech_de_data_lifecycle_uninstall_status';
	public const UNINSTALL_INTENT = 'cetech_de_delete_data_on_uninstall';
	public const CAPABILITIES_MARKER = 'cetech_de_capabilities_version';
	public const ACTIVATION_NOTICE = 'cetech_de_activation_notice';
	public const CLEANUP_HOOK = 'cetech_de_data_lifecycle_cleanup_batch';
	public const CLEANUP_GROUP = 'cetech-delivery-engine-data-lifecycle';
	public const CACHE_TTL_SECONDS = 120;
	public const SCHEDULE_INTERVAL_SECONDS = 300;
	public const CACHE_MAX_PAYLOAD_BYTES = 65536;
	public const CACHE_MAX_ENVELOPE_BYTES = 67584;
	public const COORDINATOR_MAX_BYTES = 16384;
	public const MAX_INSPECTIONS = 200;
	public const MAX_DELETIONS = 50;
	public const MAX_ID_WINDOW = 1000;
	public const LOCK_WAIT_SECONDS = 2;
	public const SOFT_WALL_MILLISECONDS = 2000;

	public const UNINSTALL_STATUSES = [ 'incomplete', 'refused', 'outcome_unknown', 'completed' ];

	/** Original C06 inventory remains an exact, separately named preservation set. */
	public const ORIGINAL_DOMAIN_TABLE_SUFFIXES = [
		'delivery_offers', 'destination_zones', 'destination_rules', 'logistics_profiles',
		'suppliers', 'origins', 'pickup_locations', 'rate_cards', 'rate_card_rules', 'audit_log',
		'product_delivery_rules', 'configuration_scopes', 'configuration_fields', 'configuration_collections',
		'shipments', 'shipment_items', 'shipment_events', 'bulk_jobs', 'bulk_job_items', 'bulk_recipes',
		'geography_packs', 'geography_locations', 'geography_location_aliases', 'geography_provider_mappings',
		'destination_coverage_groups', 'destination_coverage_members', 'destination_coverage_postcodes',
		'operation_records', 'operation_changes', 'rule_family_guards', 'logical_rules', 'rule_versions',
	];
	/** Q02 only registers preservation; quote cleanup and admission are not granted here. */
	public const QUOTE_TABLE_SUFFIXES = [ 'delivery_quotes', 'delivery_quote_bindings', 'delivery_quote_budget_windows' ];
	public const DOMAIN_TABLE_SUFFIXES = [ ...self::ORIGINAL_DOMAIN_TABLE_SUFFIXES, ...self::QUOTE_TABLE_SUFFIXES ];

	public const CAPABILITIES = [
		'view_delivery_engine', 'manage_delivery_settings', 'manage_site_wide_defaults',
		'manage_delivery_offers', 'manage_delivery_rate_cards', 'manage_delivery_zones',
		'manage_pickup_locations', 'manage_logistics_profiles', 'manage_private_sources',
		'manage_product_delivery_rules', 'manage_shipments', 'update_shipment_status',
		'view_private_delivery_costs', 'view_private_origins', 'manage_delivery_integrations',
		'view_delivery_logs', 'import_delivery_data', 'view_delivery_diagnostics',
	];

	public const FEATURE_FLAG_OPTIONS = [
		'cetech_de_enable_product_delivery_selector', 'cetech_de_enable_cart_delivery_selection_capture',
		'cetech_de_enable_checkout_delivery_selection_validation', 'cetech_de_enable_woocommerce_shipping_rate_calculation',
		'cetech_de_enable_order_delivery_snapshot_persistence', 'cetech_de_enable_customer_order_delivery_summary',
		'cetech_de_enable_customer_email_delivery_summary', 'cetech_de_enable_shipment_records',
		'cetech_de_enable_customer_timeline', 'cetech_de_enable_tracking_links', 'cetech_de_enable_wpml_adapter',
		'cetech_de_enable_wcml_adapter', 'cetech_de_enable_woodmart_adapter', 'cetech_de_enable_wcfm_adapter',
		'cetech_de_enable_vitepos_adapter', 'cetech_de_enable_bulk_import', 'cetech_de_enable_classic_checkout_adapter',
		'cetech_de_enable_blocks_adapter', 'cetech_de_enable_category_rules', 'cetech_de_enable_site_fallback_rule',
		'cetech_de_enable_effective_configuration_runtime', 'cetech_de_enable_variable_product_ecr_runtime',
		'cetech_de_demo_data_on_activation',
	];

	/** Original value/autoload disposition survives ordinary and explicit maintenance. */
	public const PRESERVED_OPTIONS = [
		'cetech_de_db_version', 'cetech_de_last_migration_status', 'cetech_de_global_configuration_version',
		'cetech_de_v3_config_migration_report', 'cetech_de_sitewide_defaults', 'cetech_de_setup_wizard',
		'cetech_de_shipment_creation_failure_order_ids', 'cetech_de_cod_awaiting_shipment_order_ids',
		'cetech_de_shipment_ops_issues', 'cetech_de_schema6_coverage_upgrade',
		'cetech_de_country_identity_repair_revision', 'cetech_de_country_identity_repair_lock',
		'cetech_de_country_identity_repair', 'cetech_de_coverage_migration_report', 'cetech_de_geography_revision',
		...self::FEATURE_FLAG_OPTIONS, self::COORDINATOR_OPTION, self::UNINSTALL_STATUS, self::CHECKOUT_CONTROL_OPTION,
	];
	public const CONDITIONAL_OPTIONS = [ self::CAPABILITIES_MARKER, self::UNINSTALL_INTENT ];
	public const OPTIONS = [ ...self::PRESERVED_OPTIONS, ...self::CONDITIONAL_OPTIONS ];

	/** Names describe owned metadata only; Woo/user storage tables remain shared. */
	public const WOO_META_KEYS = [
		'_cetech_de_delivery_snapshot', '_cetech_de_delivery_snapshot_version',
		'_cetech_de_delivery_quote_snapshot', '_cetech_de_order_delivery_snapshot_version',
		'_cetech_de_cart_item_key', 'cetech_de_group_id', '_cetech_de_shipment_creation_state',
		'_cetech_de_shipment_creation_error_code', '_cetech_de_shipment_creation_attempted_at',
		'_cetech_de_cod_awaiting_shipment',
	];
	public const USER_KEYS = [
		'cetech_de_dismissed_notices', '_cetech_de_shipments_reviewed_event_id', 'cetech_de_bulk_list_per_page',
	];
	public const WOO_SESSION_KEYS = [
		'cetech_de_browsing_matching_location', 'cetech_de_delivery_selection',
		'cetech_de_delivery_selection_summary', 'cetech_de_delivery_selection_hash',
		'cetech_de_needs_reselection', 'cetech_de_customer_context', 'cetech_de_customer_location',
	];

	private const TABLE_OWNERS = [
		'delivery_offers' => 'configuration', 'destination_zones' => 'configuration', 'destination_rules' => 'configuration',
		'logistics_profiles' => 'configuration', 'suppliers' => 'configuration', 'origins' => 'configuration',
		'pickup_locations' => 'configuration', 'rate_cards' => 'configuration', 'rate_card_rules' => 'configuration',
		'audit_log' => 'configuration_audit', 'product_delivery_rules' => 'configuration',
		'configuration_scopes' => 'scoped_configuration', 'configuration_fields' => 'scoped_configuration',
		'configuration_collections' => 'scoped_configuration', 'shipments' => 'shipment',
		'shipment_items' => 'shipment', 'shipment_events' => 'shipment', 'bulk_jobs' => 'bulk',
		'bulk_job_items' => 'bulk', 'bulk_recipes' => 'bulk', 'geography_packs' => 'geography',
		'geography_locations' => 'geography', 'geography_location_aliases' => 'geography',
		'geography_provider_mappings' => 'geography', 'destination_coverage_groups' => 'coverage',
		'destination_coverage_members' => 'coverage', 'destination_coverage_postcodes' => 'coverage',
		'operation_records' => 'operation', 'operation_changes' => 'operation',
		'rule_family_guards' => 'rule_lifecycle', 'logical_rules' => 'rule_lifecycle', 'rule_versions' => 'rule_lifecycle',
		'delivery_quotes' => 'delivery_quote', 'delivery_quote_bindings' => 'delivery_quote', 'delivery_quote_budget_windows' => 'delivery_quote',
	];

	private function __construct() {
	}

	/** The saved checkbox has precisely the supported WP string/integer representations. */
	public static function supports_uninstall_intent( mixed $value ): bool {
		return 1 === $value || '1' === $value;
	}

	/** @return list<array<string, mixed>> */
	public static function definitions(): array {
		$entries = [];
		foreach ( self::DOMAIN_TABLE_SUFFIXES as $suffix ) {
			$owner = self::TABLE_OWNERS[$suffix];
			$quote = in_array( $suffix, self::QUOTE_TABLE_SUFFIXES, true );
			$entries[] = self::entry( 'table.' . $suffix, $owner, 'plugin_table', $suffix, 'observe_domain_table_v1', $quote ? 'schema9' : 'schema8',
				protections: self::table_protections( $suffix ),
				sources: [ self::table_source( $owner, $suffix ) ], proofs: $quote ? [ 'W2Q-09', 'W2Q-39' ] : [ 'C06-01', 'C06-02', 'C06-24' ] );
		}
		foreach ( self::OPTIONS as $name ) {
			$policy = match ( $name ) {
				self::CAPABILITIES_MARKER => 'capability_marker_removal',
				self::UNINSTALL_INTENT => 'uninstall_intent_completion',
				default => 'preserve',
			};
			$entries[] = self::entry( 'option.' . $name, self::option_owner( $name ), 'wp_option', $name,
				'observe_exact_option_v1', 'wordpress_option', explicit: $policy,
				protections: [ 'preserve_original_value_and_autoload', 'conditional_removal_requires_acknowledged_steps' ],
				sources: [ self::option_source( $name ) ], proofs: [ 'C06-01', 'C06-02', 'C06-24', 'C06-25' ] );
		}
		foreach ( self::WOO_META_KEYS as $name ) {
			$owner = str_contains( $name, 'shipment' ) ? 'shipment' : 'order_snapshot';
			$entries[] = self::entry( 'woo_meta.' . $name, $owner, 'woo_crud_meta', $name, 'observe_woo_meta_v1',
				'woo_historical_or_operational_meta', protections: [ 'preserve_original_bytes_and_versions', 'woo_storage_is_shared', 'owner_only_current_mutation' ],
					sources: [ self::meta_source( $name ) ],
				proofs: [ 'C06-01', 'C06-05', 'C06-24' ] );
		}
		foreach ( self::USER_KEYS as $name ) {
			$entries[] = self::entry( 'user.' . $name, 'user_preferences', 'wp_user_api', $name, 'observe_user_key_v1', 'wordpress_user_value',
				scope: 'shared_observe_only', protections: [ 'preserve_user_grants_and_preferences', 'retained_event_cursor_reference' ],
				sources: [ 'src/Core/AdminNoticeManager.php', 'src/Application/Shipment/ShipmentActivityCursor.php', 'src/Presentation/Admin/BulkAdminListPreferences.php' ],
				proofs: [ 'C06-01', 'C06-24', 'C06-26' ] );
		}
		foreach ( self::WOO_SESSION_KEYS as $name ) {
			$entries[] = self::entry( 'session.' . $name, 'customer_context', 'woo_session', $name, 'observe_woo_session_v1', 'woo_session_value',
				protections: [ 'woo_owns_storage_and_expiry', 'no_shared_session_deletion' ],
				sources: [ 'src/Application/Cart/CartDeliverySelectionCapture.php', 'src/Domain/CustomerContext/CustomerCartContext.php' ], proofs: [ 'C06-01', 'C06-06' ] );
		}
		foreach ( [
			'activation_notice' => [ self::ACTIVATION_NOTICE, 60, 'activation_notice_removal' ],
			'admin_notice' => [ 'cetech_de_admin_notice_', 60, 'preserve' ],
			'admin_draft' => [ 'cetech_de_admin_draft_', 60, 'preserve' ],
			'scoped_draft' => [ 'cetech_de_scoped_draft_', 900, 'preserve' ],
			'geography_rate_bucket' => [ 'cetech_de_geo_rl_', 60, 'preserve' ],
			'legacy_geography_response' => [ 'cetech_de_geo_', 120, 'preserve' ],
		] as $id => [ $key, $ttl, $explicit ] ) {
			$entries[] = self::entry( 'transient.' . $id, 'legacy_ephemeral', 'wp_transient', $key, 'observe_legacy_transient_v1', 'wordpress_transient_pair',
				normal: 'owner_expiry_only', explicit: $explicit, ttl: $ttl,
				protections: [ 'unfenced_pair_not_worker_eligible', 'existing_owner_expiry_and_consumption' ],
				sources: [ 'src/Presentation/Admin/AdminNoticeService.php', 'src/Presentation/Admin/ScopedConfigurationPage.php', 'src/Application/Geography/StorefrontGeographyEndpoint.php' ],
				proofs: [ 'C06-01', 'C06-06', 'C06-23', 'C06-24' ] );
		}
		$entries[] = self::entry( self::CACHE_CLASS, 'geography_cache', 'native_options_envelope_v1', self::CACHE_PREFIX,
			'managed_geo_current_site_options_v1', 'managed_geo_envelope_v1', normal: 'managed_cache_expiry', explicit: 'managed_cache_removal', ttl: self::CACHE_TTL_SECONDS,
			protections: [ 'current_owned_site_table_and_full_lower_hex_key', 'exact_physical_id_and_current_generation', 'valid_inline_expiry_strictly_before_cutoff', 'non_authoritative_response_only' ],
			sources: [ 'src/Application/Geography/StorefrontGeographyEndpoint.php' ], proofs: [ 'C06-01', 'C06-06', 'C06-07', 'C06-13', 'C06-14', 'C06-29' ] );
		foreach ( [
			'geography_generation_source' => 'country_token_checksum_txt',
			'geography_incoming' => 'country_token_incoming_txt',
			'geography_archive' => 'country_token_zip',
			'external_source' => 'external_source_reference',
		] as $id => $key ) {
			$entries[] = self::entry( 'file.' . $id, 'geography', 'observe_file_only', $key, 'observe_source_file_v1', 'opaque_file_reference',
				protections: [ 'no_file_enumeration_or_deletion', 'preserve_current_last_successful_and_provider_evidence', 'unproved_path_ownership_preserves' ],
				sources: [ 'src/Application/Geography/GeographyPackService.php' ], proofs: [ 'C06-01', 'C06-22', 'C06-24' ] );
		}
		foreach ( [
			'bulk_tick' => [ 'cetech_de_bulk_job_tick', 'cetech-delivery-engine-bulk' ],
			'geography_tick' => [ 'cetech_de_geography_pack_tick', 'cetech-delivery-engine-geography-{pack_id}' ],
			'geography_download' => [ 'cetech_de_geography_pack_download', 'cetech-delivery-engine-geography-{pack_id}' ],
			'geography_liveness' => [ 'cetech_de_geography_pack_liveness', 'cetech-delivery-engine-geography-liveness' ],
			'schema6_tick' => [ 'cetech_de_schema6_coverage_upgrade_tick', 'cetech-delivery-engine-schema6' ],
			'data_lifecycle_tick' => [ self::CLEANUP_HOOK, self::CLEANUP_GROUP ],
		] as $id => [ $hook, $group ] ) {
			$protections = [ 'scheduler_storage_is_shared', 'no_global_drain_or_table_delete', 'only_owned_pending_dispatch_api' ];
			if ( in_array( $id, [ 'geography_tick', 'geography_download' ], true ) ) { $protections[] = 'pack_group_suffix_is_bounded_owner_pack_id'; }
			$entries[] = self::entry( 'queue.' . $id, 'owned_dispatch', 'action_scheduler_api', $hook, 'observe_owned_hook_group_v1', 'owned_action_arguments',
				protections: $protections, hook: $hook, group: $group,
				sources: [ 'src/Application/Bulk/Queue/ActionSchedulerQueue.php', 'src/Application/Geography/GeographyPackService.php', 'src/Application/Geography/Schema6CoverageUpgradeService.php' ],
				proofs: [ 'C06-01', 'C06-21', 'C06-24' ] );
		}
		foreach ( [
			'legacy_geography_object_cache' => [ 'cetech_de_geography', 'object_cache_provider' ],
			'managed_geography_object_cache' => [ 'cetech_de_geography_managed_v1', 'object_cache_provider' ],
			'wordpress_options_cache' => [ 'options_alloptions_notoptions', 'object_cache_provider' ],
			'woo_diagnostic_log' => [ 'cetech-delivery-engine', 'woo_or_php_logger' ],
			'wordpress_roles' => [ 'wordpress_role_permissions', 'wordpress_role_api' ],
			'wordpress_cron' => [ 'wordpress_cron', 'wordpress_cron_api' ],
			'action_scheduler_storage' => [ 'action_scheduler_shared_storage', 'action_scheduler_api' ],
			'woo_order_customer_product' => [ 'woo_order_customer_product_storage', 'woo_crud' ],
			'woo_shipping_settings' => [ 'delivery_engine_selected_offer', 'woo_shipping_method_api' ],
			'woo_shipping_package' => [ 'cetech_de', 'woo_session' ],
			'woo_shipping_session' => [ 'shipping_for_package_0', 'woo_session' ],
		] as $id => [ $key, $adapter ] ) {
			$entries[] = self::entry( 'shared.' . $id, 'shared_external', $adapter, $key, 'observe_shared_storage_v1', 'opaque_foreign_storage',
				scope: 'shared_observe_only', protections: [ 'not_owned_storage', 'no_global_flush_or_delete', 'no_assumed_retention' ],
				sources: [ 'docs/product/W1-C06-DATA-INVENTORY-2026-10-06.md' ], proofs: [ 'C06-01', 'C06-06', 'C06-22', 'C06-24' ] );
		}
		$entries[] = self::entry( 'permissions.role_capabilities', 'data_lifecycle', 'wordpress_role_api', 'finite_plugin_role_capabilities', 'exact_known_role_capabilities_v1', 'finite_capability_list',
			explicit: 'role_permission_removal', scope: 'shared_observe_only', protections: [ 'exact_eighteen_capabilities_only', 'preserve_unrelated_roles_caps_and_direct_user_grants' ],
			sources: [ 'src/Core/Capabilities/Capabilities.php' ], proofs: [ 'C06-01', 'C06-24', 'C06-26' ] );
		return $entries;
	}

	/** @return array<string, mixed> */
	private static function entry(
		string $id, string $owner, string $adapter, string $storage_key, string $selector, string $accepted_format,
		string $normal = 'preserve', string $explicit = 'preserve', ?int $ttl = null,
		string $scope = 'current_site', array $protections = [], ?string $hook = null, ?string $group = null,
		array $sources = [], array $proofs = []
	): array {
		return [
			'class_id' => $id, 'owner' => $owner, 'storage_adapter' => $adapter, 'storage_key' => $storage_key,
			'site_scope' => $scope, 'format' => self::FORMAT, 'accepted_format' => $accepted_format, 'selector' => $selector,
			'normal_policy' => $normal, 'default_uninstall_policy' => 'preserve', 'explicit_uninstall_policy' => $explicit,
			'expires_after_seconds' => $ttl, 'protections' => $protections, 'owned_hook' => $hook, 'owned_group' => $group,
			'diagnostic_projection' => 'data_lifecycle_registry_v1', 'source_paths' => $sources, 'proof_cases' => $proofs,
		];
	}

	private static function table_source( string $owner, string $suffix ): string {
		if ( 'product_delivery_rules' === $suffix ) { return 'database/migrations/20260705170000_create_product_delivery_rules_table.php'; }
		return match ( $owner ) {
			'scoped_configuration' => 'src/Infrastructure/Persistence/ScopedConfigurationSchema.php',
			'shipment' => 'src/Infrastructure/Persistence/ShipmentSchema.php',
			'bulk' => 'src/Infrastructure/Persistence/BulkJobSchema.php',
			'geography' => 'src/Infrastructure/Persistence/GeographySchema.php',
			'coverage' => 'src/Infrastructure/Persistence/CoverageSchema.php',
			'operation' => 'src/Infrastructure/Persistence/OperationStoreSchema.php',
			'rule_lifecycle' => 'src/Infrastructure/Persistence/RuleLifecycleSchema.php',
			'delivery_quote' => 'src/Infrastructure/Persistence/DeliveryQuoteSchema.php',
			default => 'database/migrations/20260705160000_create_configuration_tables.php',
		};
	}

	/** @return list<string> */
	private static function table_protections( string $suffix ): array {
		$quote_reference = match ( $suffix ) {
			'delivery_quotes' => 'preserve_immutable_header_body_namespaces_and_tombstones',
			'delivery_quote_bindings' => 'preserve_order_group_native_snapshot_and_seal_references',
			'delivery_quote_budget_windows' => 'preserve_admission_intent_leases_and_unknown_outcomes_without_takeover',
			default => null,
		};
		if ( null !== $quote_reference ) { return [ 'preserve_all_rows_and_identities', $quote_reference, 'no_quote_cleanup_or_admission_grant' ]; }
		$reference = match ( self::TABLE_OWNERS[$suffix] ) {
			'configuration_audit' => 'preserve_cor007_token_completions_and_all_material_audit',
			'operation' => 'preserve_acceptance_event_pair_both_directions_and_publication_receipt',
			'rule_lifecycle' => 'preserve_guard_pointers_supersession_all_states_and_schedule_evidence',
			'shipment' => 'preserve_woo_order_item_group_idempotency_and_event_cursor_references',
			'bulk' => 'preserve_parent_manifest_before_after_rollback_and_replay_evidence',
			'geography', 'coverage' => 'preserve_canonical_provider_pack_coverage_source_generation_references',
			default => 'preserve_authored_configuration_and_embedded_entity_references',
		};
		return [ 'preserve_all_rows_and_identities', $reference ];
	}

	private static function meta_source( string $name ): string {
		if ( '_cetech_de_cod_awaiting_shipment' === $name ) { return 'src/Application/Shipment/CodAwaitingShipmentStore.php'; }
		if ( str_contains( $name, 'shipment_creation' ) ) { return 'src/Application/Shipment/ShipmentCreationFailureStore.php'; }
		if ( 'cetech_de_group_id' === $name ) { return 'src/Presentation/Admin/OrderShippingItemPresentationGuard.php'; }
		return 'src/Application/Order/OrderDeliverySnapshot.php';
	}

	private static function option_owner( string $name ): string {
		if ( self::CHECKOUT_CONTROL_OPTION === $name ) { return 'emergency_control'; }
		if ( in_array( $name, self::FEATURE_FLAG_OPTIONS, true ) ) { return 'feature_flags'; }
		if ( in_array( $name, [ self::COORDINATOR_OPTION, self::UNINSTALL_STATUS, self::UNINSTALL_INTENT ], true ) ) { return 'data_lifecycle'; }
		if ( str_contains( $name, 'shipment' ) ) { return 'shipment'; }
		if ( str_contains( $name, 'geography' ) || str_contains( $name, 'coverage' ) || str_contains( $name, 'country_identity' ) ) { return 'geography'; }
		if ( in_array( $name, [ 'cetech_de_db_version', 'cetech_de_last_migration_status', self::CAPABILITIES_MARKER ], true ) ) { return 'bootstrap'; }
		return 'configuration';
	}

	private static function option_source( string $name ): string {
		if ( in_array( $name, self::FEATURE_FLAG_OPTIONS, true ) ) { return 'src/Bootstrap/FeatureFlags.php'; }
		return match ( $name ) {
			self::CHECKOUT_CONTROL_OPTION => 'src/Infrastructure/Persistence/EmergencyControlStore.php',
			'cetech_de_db_version' => 'src/Core/Versioning/SchemaVersion.php',
			'cetech_de_last_migration_status' => 'src/Core/Versioning/MigrationStatus.php',
			self::CAPABILITIES_MARKER => 'src/Core/Capabilities/Capabilities.php',
			self::UNINSTALL_INTENT => 'src/Presentation/Admin/DeliverySettingsPage.php',
			self::COORDINATOR_OPTION, self::UNINSTALL_STATUS => 'src/Bootstrap/DataLifecycleManifest.php',
			'cetech_de_global_configuration_version', 'cetech_de_v3_config_migration_report' => 'src/Infrastructure/Persistence/ScopedConfigurationSchema.php',
			'cetech_de_sitewide_defaults' => 'src/Application/Configuration/SiteWideDefaultsSettings.php',
			'cetech_de_setup_wizard' => 'src/Application/Configuration/SetupWizardProgress.php',
			'cetech_de_shipment_creation_failure_order_ids' => 'src/Application/Shipment/ShipmentCreationFailureStore.php',
			'cetech_de_cod_awaiting_shipment_order_ids' => 'src/Application/Shipment/CodAwaitingShipmentStore.php',
			'cetech_de_shipment_ops_issues' => 'src/Application/Shipment/ShipmentOperationsIssueStore.php',
			'cetech_de_schema6_coverage_upgrade' => 'src/Application/Geography/Schema6CoverageUpgradeService.php',
			'cetech_de_country_identity_repair_revision', 'cetech_de_country_identity_repair_lock' => 'src/Application/Geography/CountryIdentityReconciler.php',
			'cetech_de_country_identity_repair' => 'src/Bootstrap/Uninstaller.php',
			'cetech_de_coverage_migration_report' => 'src/Application/Geography/LegacyDestinationCoverageMigrator.php',
			'cetech_de_geography_revision' => 'src/Application/Geography/GeographyPackService.php',
			default => 'src/Bootstrap/DataLifecycleManifest.php',
		};
	}
}
