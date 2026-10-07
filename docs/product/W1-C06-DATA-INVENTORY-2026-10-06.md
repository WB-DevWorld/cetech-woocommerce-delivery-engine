# W1-C06 exact data inventory — source evidence

Inspected the shared C05 source during integration; final read-only HEAD observed `8b038743a3b67fa8aab2e4a7fcc0c92abbb6c4c5`. No database/live-site inventory or cleanup executed. This note does not grant deletion or numeric retention. All table names below are `${wpdb->prefix}delivery_engine_<suffix>` for the current site (not a global base-prefix sweep). `ConfigurationTables::all_suffixes()` currently returns 27 legacy tables. The two C03 and three C04 stores are separately registered schemas/readiness and deliberately excluded from that legacy explicit-uninstall drop list: 32 total. No DDL declares foreign-key constraints; listed relations are repository/domain invariants, so cleanup cannot rely on cascade enforcement.

This inventory describes existing code. The companion [C06 design](W1-C06-DATA-LIFECYCLE-DESIGN-2026-10-06.md) proposes a narrower shared uninstall manifest and only one managed response-cache adopter. Existing deletion descriptions below are historical baseline behavior, not the proposed C06 allowance.

## Exact plugin tables, columns and relations

Columns are the current CREATE TABLE definitions; types/defaults/complete indexes remain authoritative in the named source. Existing migrations rerun those schema definitions. Common legacy IDs are unsigned bigint; JSON is longtext; schema7/8 timestamps use datetime(6); most newer participating stores explicitly use InnoDB. No table has an age-retention TTL.

### 1. `delivery_offers`

- Owner: WpdbDeliveryOfferRepository / Offers admin.
- Source: `database/migrations/20260705160000_create_configuration_tables.php`.
- Columns: `id, internal_code, internal_name, public_label, route, service_level, carrier_visibility, carrier_name, public_description, tax_class, price_basis, default_processing_min, default_processing_max, default_transit_min, default_transit_max, default_final_mile_min, default_final_mile_max, duration_unit, display_priority, status, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY internal_code (internal_code); KEY status (status); KEY route (route)`.
- References: rate_cards.delivery_offer_id; product_delivery_rules.delivery_offer_ids JSON; configuration_collections delivery_offer_ids JSON; cart/order/shipment historical offer IDs.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 2. `destination_zones`

- Owner: WpdbDestinationZoneRepository / Zones admin.
- Source: `database/migrations/20260705160000_create_configuration_tables.php`.
- Columns: `id, internal_code, internal_name, public_label, is_fallback, remote_area_flag, priority, status, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY internal_code (internal_code); KEY status (status); KEY priority (priority)`.
- References: destination_rules.zone_id; destination_coverage_groups.zone_id; rate_cards.destination_zone_id; stored line/package/shipment destination_zone_id.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 3. `destination_rules`

- Owner: WpdbDestinationRuleRepository.
- Source: `database/migrations/20260705160000_create_configuration_tables.php`.
- Columns: `id, zone_id, rule_type, rule_value, match_mode, priority, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); KEY zone_id (zone_id); KEY rule_type (rule_type); KEY priority (priority)`.
- References: zone_id -> destination_zones; legacy free-text geography predicates.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 4. `logistics_profiles`

- Owner: WpdbLogisticsProfileRepository.
- Source: `database/migrations/20260705160000_create_configuration_tables.php`.
- Columns: `id, internal_code, internal_name, description, parcel_size_class, handling_class, route_eligibility, consolidation_rule, dispatch_type, status, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY internal_code (internal_code); KEY status (status)`.
- References: rate_cards/product_delivery_rules/configuration_fields/shipments.logistics_profile_id.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 5. `suppliers`

- Owner: WpdbSupplierRepository.
- Source: `database/migrations/20260705160000_create_configuration_tables.php`.
- Columns: `id, internal_code, internal_name, contact_email, contact_phone, internal_notes, status, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY internal_code (internal_code); KEY status (status)`.
- References: origins.supplier_id; rate_cards/product_delivery_rules/configuration_fields/shipments.supplier_id.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 6. `origins`

- Owner: WpdbOriginRepository.
- Source: `database/migrations/20260705160000_create_configuration_tables.php`.
- Columns: `id, supplier_id, internal_code, internal_name, internal_address, country_code, dispatch_lead_days_min, dispatch_lead_days_max, internal_notes, status, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY internal_code (internal_code); KEY supplier_id (supplier_id); KEY status (status)`.
- References: supplier_id -> suppliers; rate_cards/product_delivery_rules/configuration_fields/shipments.origin_id.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 7. `pickup_locations`

- Owner: WpdbPickupLocationRepository.
- Source: `database/migrations/20260705160000_create_configuration_tables.php`.
- Columns: `id, internal_code, location_name, public_address, public_opening_hours, public_pickup_instructions, contact_phone, contact_email, readiness_estimate, status, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY internal_code (internal_code); KEY status (status)`.
- References: configuration_fields.pickup_location_id; customer cart context and V2 snapshot pickup_location_id.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 8. `rate_cards`

- Owner: WpdbRateCardRepository / Quote engine.
- Source: `database/migrations/20260705160000_create_configuration_tables.php`.
- Columns: `id, internal_code, delivery_offer_id, destination_zone_id, logistics_profile_id, supplier_id, origin_id, charge_type, base_amount, base_currency, included_weight, increment_weight, increment_amount, per_item_amount, per_line_amount, highest_fee_mode, remote_surcharge, free_shipping_threshold, manual_currency_override_data, priority, effective_from, effective_to, status, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY internal_code (internal_code); KEY delivery_offer_id (delivery_offer_id); KEY destination_zone_id (destination_zone_id); KEY logistics_profile_id (logistics_profile_id); KEY status (status); KEY priority (priority)`.
- References: delivery_offer_id/destination_zone_id/logistics_profile_id/supplier_id/origin_id -> domain rows; rate_card_rules.rate_card_id; saved line/shipment rate_card_id + rate_card_code.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 9. `rate_card_rules`

- Owner: Initial configuration migration; no dedicated production repository found.
- Source: `database/migrations/20260705160000_create_configuration_tables.php`.
- Columns: `id, rate_card_id, rule_key, rule_value, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); KEY rate_card_id (rate_card_id); KEY rule_key (rule_key)`.
- References: rate_card_id -> rate_cards.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 10. `audit_log`

- Owner: WpdbAuditLogRepository / ConfigurationAuditLogger.
- Source: `database/migrations/20260705160000_create_configuration_tables.php`.
- Columns: `id, actor_user_id, action, entity_type, entity_id, previous_value, new_value, site_context, created_at`.
- Keys: `PRIMARY KEY  (id); KEY entity_type (entity_type); KEY entity_id (entity_id); KEY action (action); KEY created_at (created_at)`.
- References: entity_type/entity_id; actor_user_id -> WP users; previous_value/new_value JSON; COR-007 completion request_token/intent/revision/scope identity in new_value; not merely disposable diagnostics.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 11. `product_delivery_rules`

- Owner: WpdbProductDeliveryRuleRepository / legacy migration.
- Source: `database/migrations/20260705170000_create_product_delivery_rules_table.php`.
- Columns: `id, target_type, target_id, target_label_snapshot, fulfilment_availability, fulfilment_choice, delivery_offer_ids, logistics_profile_id, supplier_id, origin_id, priority, status, internal_notes, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); KEY target_lookup (target_type, target_id); KEY fulfilment_availability (fulfilment_availability); KEY logistics_profile_id (logistics_profile_id); KEY supplier_id (supplier_id); KEY origin_id (origin_id); KEY status (status); KEY priority (priority)`.
- References: target_type/id -> Woo product/variation/category/site; IDs/offer JSON -> domain rows; configuration_scopes.legacy_rule_id backlink.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 12. `bulk_jobs`

- Owner: WpdbBulkJobRepository / BulkJobWorker.
- Source: `src/Infrastructure/Persistence/BulkJobSchema.php`.
- Columns: `id, job_uuid, job_code, operation_type, actor_user_id, status, dry_run, cancel_requested, target_hash, action_hash, target_definition_json, action_manifest_json, summary_json, total_count, enumerated_count, processed_count, changed_count, skipped_count, failed_count, warning_count, enumeration_complete, checkpoint_cursor, batch_size, claim_token, claimed_at, retry_count, error_code, error_summary, parent_job_id, format_version, created_at, started_at, completed_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY job_uuid (job_uuid); UNIQUE KEY job_code (job_code); KEY status (status); KEY status_updated (status, updated_at); KEY actor_user_id (actor_user_id); KEY operation_type (operation_type); KEY parent_job_id (parent_job_id)`.
- References: parent_job_id -> prior bulk_jobs; actor_user_id; target/action/summary JSON and scan ceiling/cursor; items.job_id; queued AS args job_id.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 13. `bulk_job_items`

- Owner: WpdbBulkJobRepository / BulkJobWorker.
- Source: `src/Infrastructure/Persistence/BulkJobSchema.php`.
- Columns: `id, job_id, target_type, target_id, external_key, parent_target_id, status, attempt_count, precondition_fingerprint, after_fingerprint, before_snapshot_json, result_json, error_code, error_summary, claim_token, claimed_at, completed_at, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY job_target (job_id, target_type, target_id, external_key); KEY job_status_id (job_id, status, id); KEY job_id (job_id); KEY claim_token (claim_token); KEY target_lookup (target_type, target_id)`.
- References: job_id -> bulk_jobs; target_type/id and parent_target_id -> domain/Woo targets; before/result JSON and fingerprints can be rollback/replay evidence.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 14. `bulk_recipes`

- Owner: WpdbBulkJobRepository / Bulk recipes.
- Source: `src/Infrastructure/Persistence/BulkJobSchema.php`.
- Columns: `id, recipe_code, name, owner_user_id, target_definition_json, action_manifest_json, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY recipe_code (recipe_code); KEY owner_user_id (owner_user_id)`.
- References: owner_user_id -> WP users; target/action JSON contains domain codes/IDs and recipe semantics.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 15. `destination_coverage_groups`

- Owner: WpdbCoverageGroupRepository / CoverageService.
- Source: `src/Infrastructure/Persistence/CoverageSchema.php`.
- Columns: `id, zone_id, root_location_id, coverage_mode, sort_order, status, review_required, legacy_migration_json, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); KEY zone_id (zone_id); KEY zone_status_order (zone_id, status, sort_order); KEY root_location_id (root_location_id); KEY review_required (review_required)`.
- References: zone_id -> destination_zones; root_location_id -> geography_locations; members/postcodes.coverage_group_id.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 16. `destination_coverage_members`

- Owner: WpdbCoverageGroupRepository.
- Source: `src/Infrastructure/Persistence/CoverageSchema.php`.
- Columns: `id, coverage_group_id, location_id, membership, created_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY group_location_membership (coverage_group_id, location_id, membership); KEY coverage_group_id (coverage_group_id); KEY location_id (location_id)`.
- References: coverage_group_id -> coverage_groups; location_id -> geography_locations; membership include/exclude semantics.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 17. `destination_coverage_postcodes`

- Owner: WpdbCoverageGroupRepository.
- Source: `src/Infrastructure/Persistence/CoverageSchema.php`.
- Columns: `id, coverage_group_id, postcode_value, match_mode, priority, status, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY group_postcode_mode (coverage_group_id, postcode_value, match_mode); KEY coverage_group_id (coverage_group_id); KEY postcode_value (postcode_value)`.
- References: coverage_group_id -> coverage_groups; postcode/match/status rules; not a disposable geography cache.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 18. `geography_packs`

- Owner: WpdbGeographyPackRepository / GeographyPackService.
- Source: `src/Infrastructure/Persistence/GeographySchema.php`.
- Columns: `id, country_code, provider, dataset_name, dataset_version, source_url, source_reference, checksum, license_name, license_url, attribution_text, status, import_cursor, progress_json, last_error, installed_at, target_token, lease_owner, lease_role, lease_acquired_at, lease_expires_at, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY country_provider_dataset (country_code, provider, dataset_name); KEY status (status); KEY country_status (country_code, status); KEY target_token (target_token); KEY lease_owner (lease_owner)`.
- References: provider mappings.pack_id; source_reference + progress_json.last_successful and generation tokens point to source files/generations; license/attribution/provenance durable.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 19. `geography_locations`

- Owner: WpdbCanonicalLocationRepository.
- Source: `src/Infrastructure/Persistence/GeographySchema.php`.
- Columns: `id, location_key, country_code, parent_location_id, location_type, administrative_level, canonical_name, normalized_name, ascii_name, latitude, longitude, status, ancestry_path, generation, generation_token, draft_generation_token, prepared_generation_token, prepared_ancestry_path, prepared_hierarchy_root_id, draft_json, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY location_key (location_key); KEY country_parent_type_status (country_code, parent_location_id, location_type, status); KEY country_normalized (country_code, normalized_name, parent_location_id); KEY parent_id (parent_location_id); KEY type_level (location_type, administrative_level); KEY ancestry_path (ancestry_path); KEY generation_status (generation, status); KEY generation_token (generation_token); KEY draft_generation_token (draft_generation_token); KEY prepared_generation_token (prepared_generation_token); KEY prepared_hierarchy_root_id (prepared_hierarchy_root_id); KEY status (status)`.
- References: parent_location_id + prepared_hierarchy_root_id -> same table; stable location_key consumed by cart/order facts/coverage; aliases/mappings/location coverage references; generation/token staged state.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 20. `geography_location_aliases`

- Owner: WpdbLocationAliasRepository / canonical promotion.
- Source: `src/Infrastructure/Persistence/GeographySchema.php`.
- Columns: `id, location_id, alias, normalized_alias, language_code, alias_type, is_preferred, status, generation_token, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY location_normalized_generation (location_id, normalized_alias, generation_token); KEY normalized_alias (normalized_alias); KEY location_id (location_id); KEY generation_token (generation_token); KEY status (status)`.
- References: location_id -> canonical location; generation_token -> pack generation, preserved by ownership-aware staging/promotion.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 21. `geography_provider_mappings`

- Owner: WpdbProviderMappingRepository / canonical promotion.
- Source: `src/Infrastructure/Persistence/GeographySchema.php`.
- Columns: `id, location_id, provider, external_id, pack_id, dataset_version, provider_parent_reference, feature_class, feature_code, provider_metadata_json, generation_token, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY provider_external_generation (provider, external_id, generation_token); KEY location_id (location_id); KEY pack_id (pack_id); KEY provider_parent (provider, provider_parent_reference)`.
- References: location_id -> canonical location; pack_id -> packs; provider/external_id/generation uniqueness and parent provenance.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 22. `operation_records`

- Owner: WpdbOperationRecordRepository / OperationCoordinator.
- Source: `src/Infrastructure/Persistence/OperationStoreSchema.php`.
- Columns: `id, site_id, namespace_hash, intent_hash, namespace_format, intent_format, record_format, operation, operation_version, target_hash, state, publication_state, completion_json, audit_id, row_version, created_at, updated_at, completed_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY site_namespace (site_id, namespace_hash); KEY site_state_id (site_id, state, id)`.
- References: audit_id -> operation_changes.id, exact same site + operation_id; namespace/intent/target hashes + accepted/not_applicable/rejected/pending state + publication receipt protect durable replay.
- Existing lifecycle: Preserve contractual completion/material history; excluded from legacy explicit-uninstall drops.

### 23. `operation_changes`

- Owner: WpdbOperationChangeRepository / OperationCoordinator.
- Source: `src/Infrastructure/Persistence/OperationStoreSchema.php`.
- Columns: `id, site_id, operation_id, event_format, event_json, created_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY operation_event (site_id, operation_id)`.
- References: operation_id -> operation_records.id with same site; unique operation_event; typed event JSON carries original rule identities/transition facts; immutable material ledger.
- Existing lifecycle: Preserve contractual completion/material history; excluded from legacy explicit-uninstall drops.

### 24. `rule_family_guards`

- Owner: WpdbRuleLifecycleRepository / RuleLifecycleOperationProfile.
- Source: `src/Infrastructure/Persistence/RuleLifecycleSchema.php`.
- Columns: `id, site_id, family_code, family_format, policy_hash, revision, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY site_family (site_id, family_code)`.
- References: logical_rules.family_guard_id; site+family uniqueness and frozen policy_hash + revision lock.
- Existing lifecycle: Preserve contractual completion/material history; excluded from legacy explicit-uninstall drops.

### 25. `logical_rules`

- Owner: WpdbRuleLifecycleRepository.
- Source: `src/Infrastructure/Persistence/RuleLifecycleSchema.php`.
- Columns: `id, site_id, family_guard_id, logical_uuid, scope_format, scope_json, scope_hash, revision, last_version_sequence, current_published_version_id, draft_version_id, scheduled_version_id, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY site_uuid (site_id, logical_uuid); KEY family_rules (site_id, family_guard_id, id)`.
- References: family_guard_id -> guard; published/draft/scheduled version IDs -> rule_versions same logical/site; scope hash/JSON; monotonic last_version_sequence.
- Existing lifecycle: Preserve contractual completion/material history; excluded from legacy explicit-uninstall drops.

### 26. `rule_versions`

- Owner: WpdbRuleLifecycleRepository.
- Source: `src/Infrastructure/Persistence/RuleLifecycleSchema.php`.
- Columns: `id, site_id, logical_rule_id, version_uuid, version_sequence, row_revision, state, payload_format, payload_json, content_hash, priority, start_mode, effective_from, effective_until, author_user_id, change_reason, supersedes_version_id, scheduled_revision, scheduled_logical_revision, scheduled_predecessor_row_revision, sealed_at, scheduled_at, published_at, retired_at, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY site_version_uuid (site_id, version_uuid); UNIQUE KEY logical_sequence (site_id, logical_rule_id, version_sequence); KEY activation_due (site_id, state, effective_from, id); KEY logical_eligibility (site_id, logical_rule_id, state, effective_from, id)`.
- References: logical_rule_id -> logical; supersedes_version_id -> same-logical older version; author_user_id -> WP user; immutable sealed payload/reason/content_hash/authored interval + schedule revision evidence; accepted transitions in C03 ledger.
- Existing lifecycle: Preserve contractual completion/material history; excluded from legacy explicit-uninstall drops.

### 27. `configuration_scopes`

- Owner: WpdbScopedConfigurationRepository / ScopedConfigurationAdminService.
- Source: `src/Infrastructure/Persistence/ScopedConfigurationSchema.php`.
- Columns: `id, scope_type, scope_id, slice_key, parent_product_id, status, config_version, source, legacy_rule_id, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY scope_identity (scope_type, scope_id, slice_key); UNIQUE KEY legacy_rule_id (legacy_rule_id); KEY parent_product_id (parent_product_id); KEY status (status); KEY config_version (config_version)`.
- References: scope_type/id/slice_key identity; parent_product_id -> Woo product; legacy_rule_id -> product_delivery_rules; fields/collections.scope_row_id children.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 28. `configuration_fields`

- Owner: WpdbScopedConfigurationRepository.
- Source: `src/Infrastructure/Persistence/ScopedConfigurationSchema.php`.
- Columns: `id, scope_row_id, field_key, mode, value_type, value_text, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY scope_field (scope_row_id, field_key); KEY field_key (field_key); KEY mode (mode)`.
- References: scope_row_id -> scopes; registry-defined scalar references in value_text, including logistics/supplier/origin/pickup IDs.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 29. `configuration_collections`

- Owner: WpdbScopedConfigurationRepository.
- Source: `src/Infrastructure/Persistence/ScopedConfigurationSchema.php`.
- Columns: `id, scope_row_id, field_key, mode, members_json, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY scope_collection (scope_row_id, field_key); KEY field_key (field_key); KEY mode (mode)`.
- References: scope_row_id -> scopes; registry-defined member references in members_json, including delivery_offer_ids.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 30. `shipments`

- Owner: WpdbShipmentRepository / shipment services.
- Source: `src/Infrastructure/Persistence/ShipmentSchema.php`.
- Columns: `id, order_id, shipment_number, idempotency_key, delivery_group_id, status, fulfilment_availability, fulfilment_choice, delivery_offer_id, delivery_offer_public_label, route, service_level, carrier_visibility, public_carrier_name, destination_zone_id, logistics_profile_id, supplier_id, origin_id, currency_code, customer_paid_shipping_amount, rate_card_id, rate_card_code, internal_cost, eta_original, eta_current, tracking_number, tracking_url, tracking_carrier_display, dispatch_at, delivered_at, public_note, private_note, wc_fulfillment_id, created_at, updated_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY idempotency_key (idempotency_key); UNIQUE KEY order_group (order_id, delivery_group_id); KEY order_id (order_id); KEY status (status); KEY status_updated (status, updated_at)`.
- References: order_id -> Woo order; wc_fulfillment_id -> optional Woo fulfillment; historical offer/zone/logistics/supplier/origin/rate IDs; order+group and idempotency uniqueness.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 31. `shipment_items`

- Owner: WpdbShipmentRepository.
- Source: `src/Infrastructure/Persistence/ShipmentSchema.php`.
- Columns: `id, shipment_id, order_id, order_item_id, product_id, variation_id, quantity, product_name_snapshot, created_at`.
- Keys: `PRIMARY KEY  (id); UNIQUE KEY shipment_item (shipment_id, order_item_id); KEY shipment_id (shipment_id); KEY order_item_id (order_item_id)`.
- References: shipment_id -> shipments; order/order_item -> Woo; historical product/variation IDs + product_name_snapshot.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

### 32. `shipment_events`

- Owner: WpdbShipmentRepository / activity cursor.
- Source: `src/Infrastructure/Persistence/ShipmentSchema.php`.
- Columns: `id, shipment_id, event_type, from_status, to_status, public_note, internal_note, actor_user_id, source, event_at, created_at`.
- Keys: `PRIMARY KEY  (id); KEY shipment_id (shipment_id); KEY shipment_time (shipment_id, event_at)`.
- References: shipment_id -> shipments; actor_user_id -> WP users; durable public/private event history; user reviewed cursor refers to event IDs.
- Existing lifecycle: Preserve default/runtime; existing explicit delete-data uninstall drops this legacy table. This is existing behavior, not a new C06 cleanup allowance.

## Exact options and user stores (current writers)

All ordinary options use the current WordPress site's options API and `autoload=false` in plugin writers; there is no network-option writer found. Core-managed role data is an exception owned by WordPress, not a removable plugin option.

| Key | Owner / facts | Existing expiry |
|---|---|---|
| `cetech_de_db_version` | `Core/Versioning/SchemaVersion.php`; integrated schema version 8 | None; preserve publication/readiness truth |
| `cetech_de_last_migration_status` | `Core/Versioning/MigrationStatus.php`; last migration result/checkpoint | None |
| `cetech_de_delete_data_on_uninstall` | `Bootstrap/Uninstaller.php`, `Presentation/Admin/DeliverySettingsPage.php`; explicit legacy delete policy | None |
| `cetech_de_capabilities_version` | `Core/Capabilities/Capabilities.php`; installed capability revision | None |
| `cetech_de_global_configuration_version` | `ScopedConfigurationSchema.php`, `WpdbScopedConfigurationRepository.php`; COR-007 monotonic published revision, object-cache coherence | None; never a stale cache cleanup target |
| `cetech_de_v3_config_migration_report` | `Application/Configuration/LegacyConfigurationMigrator.php`; legacy-to-scoped mapping report | None |
| `cetech_de_sitewide_defaults` | `Application/Configuration/SiteWideDefaultsSettings.php`; authored site defaults | None |
| `cetech_de_setup_wizard` | `Application/Configuration/SetupWizardProgress.php`; wizard state | None |
| `cetech_de_shipment_creation_failure_order_ids` | `Application/Shipment/ShipmentCreationFailureStore.php`; index of Woo order failure meta | None; entry removed after success |
| `cetech_de_cod_awaiting_shipment_order_ids` | `Application/Shipment/CodAwaitingShipmentStore.php`; index of awaiting Woo order meta | None; entry removed when cleared |
| `cetech_de_shipment_ops_issues` | `Application/Shipment/ShipmentOperationsIssueStore.php`; shipment/order issue state and reason index | None; explicit resolve/remove paths |
| `cetech_de_schema6_coverage_upgrade` | `Application/Geography/Schema6CoverageUpgradeService.php`; progress, cursor, durable result and worker lease | lease 300s; worker 60s (lease expiration is not record deletion) |
| `cetech_de_country_identity_repair_revision` | `Application/Geography/CountryIdentityReconciler.php`; completed repair revision | None |
| `cetech_de_country_identity_repair_lock` | Same + `WordPressOptionCasStore.php`; ownership lease | 60s expiry; compare-and-delete only held lease |
| `cetech_de_coverage_migration_report` | `Application/Geography/LegacyDestinationCoverageMigrator.php`; coverage migration/review report | None |
| `cetech_de_geography_revision` | `Application/Geography/GeographyPackService.php`; version for current geography read-cache keys | None; active identity, not a disposable cached response |
| `cetech_de_country_identity_repair` | Legacy key only named in both uninstall paths; no active writer found | No current expiry evidence |

`Bootstrap/FeatureFlags.php` supplies exactly 23 separate `cetech_de_` options:

```
cetech_de_enable_product_delivery_selector
cetech_de_enable_cart_delivery_selection_capture
cetech_de_enable_checkout_delivery_selection_validation
cetech_de_enable_woocommerce_shipping_rate_calculation
cetech_de_enable_order_delivery_snapshot_persistence
cetech_de_enable_customer_order_delivery_summary
cetech_de_enable_customer_email_delivery_summary
cetech_de_enable_shipment_records
cetech_de_enable_customer_timeline
cetech_de_enable_tracking_links
cetech_de_enable_wpml_adapter
cetech_de_enable_wcml_adapter
cetech_de_enable_woodmart_adapter
cetech_de_enable_wcfm_adapter
cetech_de_enable_vitepos_adapter
cetech_de_enable_bulk_import
cetech_de_enable_classic_checkout_adapter
cetech_de_enable_blocks_adapter
cetech_de_enable_category_rules
cetech_de_enable_site_fallback_rule
cetech_de_enable_effective_configuration_runtime
cetech_de_enable_variable_product_ecr_runtime
cetech_de_demo_data_on_activation
```

User/shared stores:

- `cetech_de_dismissed_notices`: user meta; notice IDs; `Core/AdminNoticeManager.php`; no TTL.
- `_cetech_de_shipments_reviewed_event_id`: user meta; last reviewed shipment-event ID; `Application/Shipment/ShipmentActivityCursor.php`; no TTL; reference to durable `shipment_events` IDs.
- `cetech_de_bulk_list_per_page`: user option/screen preference; `Presentation/Admin/BulkAdminListPreferences.php` + `BulkToolsPage.php`; core may store per-site blog-prefix-qualified key in user meta; no TTL; finite 20/25/50/100 values.
- WordPress role option `${blog_prefix}user_roles` and role capabilities: plugin adds/removes a finite capability list through role APIs. It never owns the whole role option/user capability rows. `Core/Capabilities/Capabilities.php`.
- Woo shipping method settings/zone instance rows are managed by `WC_Shipping_Method::init_settings()/process_admin_options()` for method `delivery_engine_selected_offer`, including title/tax_status. The concrete option naming/store adapter is Woo-owned (typical `woocommerce_delivery_engine_selected_offer[_<instance_id>]_settings`); do not add a generic plugin delete selector from this inference. `Infrastructure/WooCommerce/Shipping/SelectedOfferShippingMethod.php`.

## Exact ephemeral keys and existing lifetimes

For transients, WordPress's database backend stores value/timeout option pairs (`_transient_<key>`, `_transient_timeout_<key>`), while an external object-cache backend may hold no such database row. These are not new plugin tables; a C06 worker must not equate a prefix row with proved expiry.

| Logical key / exact construction | Owner | Existing expiry / removal |
|---|---|---|
| `cetech_de_activation_notice` | `Bootstrap/Activator.php`, `Plugin.php`, `Deactivator.php` | 60s; consumed or deactivation deletes |
| `cetech_de_admin_notice_<current_user_id>` | `Presentation/Admin/AdminNoticeService.php` | 60s; consumed on render |
| `cetech_de_admin_draft_<current_user_id>_<sanitize_key(page_slug)>` | Same | 60s; consumed on draft retrieval |
| `cetech_de_scoped_draft_<user>_<scope_type>_<scope_id>_<slice_key>_<parent_id-or-none>` | `Presentation/Admin/ScopedConfigurationPage.php::draft_key/retain_failed_draft` | 900s; scope-specific draft preserves original/current request envelope; only matching successful save/reset removes |
| `cetech_de_geo_rl_<md5(REMOTE_ADDR)>` | `Application/Geography/StorefrontGeographyEndpoint.php::allow_request` | 60s; request hit bucket |
| `cetech_de_geo_<md5(kind|upper(country)|parent|query|extra|page|geography_revision)>` | Same `cache_key/cache_set` | 120s transient AND `cetech_de_geography` object-cache group TTL |

`WordPressOptionCasStore` and scoped-save option publication invalidate/update WordPress `options` cache entries (`alloptions`, `notoptions`, exact option key). These shared cache groups are not plugin-owned global flush targets. Request-local feature/configuration/preview caches are in-memory only, not retained stores.

## Woo order/item metadata and historical payloads

These live in Woo CRUD-owned storage: order meta may use CPT postmeta or HPOS order meta; item meta uses Woo order-item storage. The plugin reads/writes via Woo CRUD and cannot claim to own the whole storage table.

| Exact key | Location / owner | Lifecycle |
|---|---|---|
| `_cetech_de_delivery_snapshot` | Product order-item JSON; `OrderDeliverySnapshot`, Builder/Persister/Reader | V1/V2 protected historical facts; optional quote/promise/labels/return/refund extension payloads embedded in same JSON; no expiry, no current-policy reconstruction |
| `_cetech_de_delivery_snapshot_version` | Same product item | Historical format identity; preserve with JSON |
| `_cetech_de_delivery_quote_snapshot` | Woo order JSON | Historical package/group/money facts + optional typed extensions; preserve; current package persister overwrite placement decision remains COR-029, not C06 repair |
| `_cetech_de_order_delivery_snapshot_version` | Woo order | Historical package version; preserve with JSON |
| `_cetech_de_cart_item_key` | Product order item; Store API snapshot mapping | Temporary checkout mapping; persister explicitly removes after line snapshot written; not blanket cleanup permission on accepted history/draft distinction |
| `cetech_de_group_id` | Shipping order item / selected-offer rate metadata | Historical delivery-group identity referenced by line/package/shipment; preserve |
| `_cetech_de_shipment_creation_state` | Woo order; `ShipmentCreationFailureStore` | failed/succeeded state; preserve operational truth |
| `_cetech_de_shipment_creation_error_code` | Same | Explicitly cleared after success; otherwise no age expiry |
| `_cetech_de_shipment_creation_attempted_at` | Same | Last attempt time; no TTL |
| `_cetech_de_cod_awaiting_shipment` | Woo order; `CodAwaitingShipmentStore` | awaiting state; explicit clear on fulfillment eligibility change, not age cleanup |

`package_qty` / `Package Qty` are technical shipping-item keys handled by `OrderShippingItemPresentationGuard`; they are not created/owned here merely because presentation hides them. Woo order/payment/customer/address/tax/product/category stores and Woo fulfillment records (`shipments.wc_fulfillment_id`) are foreign/shared storage and must be excluded.

Woo session/cart stores (Woo owns storage and expiry):

- Session convenience key `cetech_de_browsing_matching_location`: matching geography only; `CustomerBrowsingLocationStore`.
- Cart-line fields `cetech_de_delivery_selection`, `cetech_de_delivery_selection_summary`, `cetech_de_delivery_selection_hash`, `cetech_de_needs_reselection`: `CartDeliverySelectionCapture/SessionData/Reconciler`.
- Cart-line `cetech_de_customer_context`: customer destination/recipient/pickup facts (`CustomerCartContext::CART_KEY`).
- Cart-line `cetech_de_customer_location`: `CartLineCustomerIdentity::CART_LOCATION_KEY`.
- Woo package runtime key `cetech_de`: `DeliveryGroupIdentity::PACKAGE_META_KEY`; current package grouping metadata, not a standalone retained table.
- `shipping_for_package_0` is Woo's cached shipping-session state that the cart mutation service invalidates. Do not delete shared Woo session rows to clear a plugin field.

## File / external log / queue inventory

1. Geography files live under **`wp_upload_dir()['basedir']/cetech-delivery-engine/geography`**. `GeographyPackService` creates:
   - immutable generation source `<COUNTRY>.<sanitized-generation-token>.<checksum-first-12>.txt`;
   - incoming upload `<COUNTRY>.<random-16-hex>.incoming.txt`;
   - uploaded zip `<COUNTRY>.<random-16-hex>-upload.zip`;
   - extracted incoming `<COUNTRY>.<sanitized-token-or-incoming>.incoming.txt`;
   - downloaded generation `<COUNTRY>.<sanitized-generation-token>.zip`.
   `geography_packs.source_reference` and `progress_json.last_successful.source_reference/source/checksum/generation_token/attempt_token` are live file references. Source upload, archive/extraction failures use exact-path deletion; uploaded ZIP is removed after extraction. `cleanup_abandoned_generation_files()` protects current/last-successful paths, current/last-successful token segments and active checksum; explicitly abandoned token matches may delete; other unprotected ZIP/TXT extras retain the newest **4 by mtime count**, not an age TTL. This existing function uses a country glob and is not evidence of a fixed-ceiling resumable C06 worker. `cleanup_incoming_source()` checks controlled directory/real path and incoming TXT before removing copied input. Source files can be external paths in non-WordPress contexts and must not be claimed plugin-owned solely from a database string. Canonical/provider identity and license/provenance data are not disposable source artifacts.
2. Bulk CSV exporters use `php://temp` / `php://output`. UI upload paths are PHP request temporary files; no plugin-owned retained bulk CSV export/upload directory was found. Accepted jobs retain content/snapshots in the existing bulk JSON columns. No new policy return/refund files are written by C05.
3. `Support/Logger.php` emits channel/source **`cetech-delivery-engine`** through Woo logger or PHP error_log when WP_DEBUG. Woo may choose filesystem/database handlers and its own retention. There is no plugin-owned log table/path or blanket log purge implementation here. These diagnostic logs are distinct from `audit_log`, `operation_changes` and shipment events.
4. Shared **Action Scheduler** storage is external/provider-owned, not any of the 32 plugin tables. The plugin only schedules/unschedules owned hook/args/group via API:
   - `cetech_de_bulk_job_tick`, group `cetech-delivery-engine-bulk`, args `job_id`; `Application/Bulk/Queue/ActionSchedulerQueue.php`.
   - `cetech_de_geography_pack_tick` and `cetech_de_geography_pack_download`, group `cetech-delivery-engine-geography-{positive pack ID}` (exact source correction during implementation; observe-only).
   - `cetech_de_geography_pack_liveness`, group `cetech-delivery-engine-geography-liveness`; 60s liveness interval.
   - `cetech_de_schema6_coverage_upgrade_tick`, group `cetech-delivery-engine-schema6`.
   Bulk claimed job/item lease TTL **300s**; pack ownership lease **120s**; expired leases enable fenced recovery, not deleting jobs/packs/history. Core WP `cron`/Action Scheduler claims/logs/groups/actions must not be globally drained or dropped; custom AS stores may not use canonical physical table names.

## Existing deletion and uninstall observations (preserve historical behavior, no new authority)

- Normal entity admin deletions call exact row deletion methods. Destination rules can be deleted by zone; coverage groups remove member/postcode children; scoped reset removes exact fields/collections/scope; shipment item replacement deletes only items for a shipment; pack staging/promotions can abandon token-owned staged location/alias/provider rows. These are mutation/replacement paths, **not** a general retention worker and not approval for C06 to choose new expiration.
- `audit_log.new_value` COR-007 completion lookup is **all history** via `JSON_EXTRACT(...,'$.request_token')`, not last 100 rows. Deleting these records can cause replay to write/audit again even when no `operation_records` adopter points to them. Root policy should preserve all legacy audit rows absent a separately classified, proved disposable row family.
- `operation_records.audit_id` / `operation_changes.operation_id` both directions, rule logical pointers + same-logical supersession, active/sourcepack reference identities, Woo line/package/group facts, bulk rollback parent/items and shipment order/item/event references need explicit registry classification. Age cannot prove unreferenced.
- Default uninstall returns without any deletion. Deactivation deletes only activation notice and flushes rewrite rules; no data deletion. Explicit delete-data uninstall currently drops **27 legacy tables only**, preserves the 5 C03/C04 stores, and does not delete Woo historical meta/source files/shared queues.
- Exact explicit-uninstall option parity gaps:
  - **autoload path deletes only** `cetech_de_sitewide_defaults`, `cetech_de_setup_wizard`, `cetech_de_cod_awaiting_shipment_order_ids`, and flags `cetech_de_enable_effective_configuration_runtime`, `cetech_de_enable_variable_product_ecr_runtime`;
  - **no-vendor fallback deletes only** `cetech_de_global_configuration_version`, `cetech_de_v3_config_migration_report`, `cetech_de_coverage_migration_report`, `cetech_de_geography_revision`;
  - both remove db/status/delete-data/capability markers, shipment failure index, schema6 upgrade and country-repair markers and common 21 flags;
  - neither path currently removes shipment ops index, user preferences/cursors, scoped drafts/transients, Woo shipping settings/meta, source files or queue history.
  Sources: `Bootstrap/Uninstaller.php`, root `uninstall.php`, `ConfigurationTables.php`, each option owner listed above. No code changed for this inventory.
