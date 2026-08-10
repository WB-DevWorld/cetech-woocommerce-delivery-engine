# Stage 2 — Scoped Configuration Storage

**Document status:** Stage 2 completion record  
**Plugin version:** `1.0.0-rc.1` (unchanged)  
**Schema target:** `3`  
**Date:** 2026-08-10

---

## 1. Verdict

**COMPLETE**

Stage 2 delivered schema v3 scoped configuration storage, explicit scalar/collection modes, repository APIs, configuration version foundation, idempotent legacy backfill, and PHPUnit coverage. Legacy `product_delivery_rules` remains intact and authoritative for the verified RC runtime. No storefront/runtime cutover occurred.

---

## 2. Schema v3

| Item | Value |
|------|-------|
| Previous target | `2` |
| New target | `3` (`SchemaVersion::TARGET`) |
| Migration | `database/migrations/20260810160000_create_scoped_configuration_tables.php` |
| Option | `cetech_de_db_version` |

### New tables

| Suffix | Purpose |
|--------|---------|
| `configuration_scopes` | Global / product / variation scope identity + version + migration provenance |
| `configuration_fields` | Scalar field instructions (`mode` + typed value) |
| `configuration_collections` | Collection instructions (`mode` + JSON members) |

### Uniqueness

- `UNIQUE (scope_type, scope_id, slice_key)` — one logical scope per identity
- `UNIQUE (legacy_rule_id)` — one migrated scope per legacy rule (NULLs allowed for native)
- `UNIQUE (scope_row_id, field_key)` for scalars and collections

### `slice_key`

Stage 1 maps **each legacy rule row → one scope**. Legacy can have multiple rows per product (one per `fulfilment_availability`). `slice_key` stores that availability for migrated rows so uniqueness holds without inventing a competing inheritance model. Native/global scopes use `slice_key = ''`.

### Not created

- `shipments`, `shipment_items`, `shipment_events`
- No drops/renames of v2 tables

### Modified existing tables

None.

---

## 3. Global Storage

- Represented as a singleton scope: `scope_type=global`, `scope_id=0`, `slice_key=''`
- Ensured on migration via `ensureGlobalScope()`
- No manufactured customer-facing defaults (Stage 1 / design: do not invent unspecified globals)
- Mirror option: `cetech_de_global_configuration_version`

---

## 4. Product Scope

- `scope_type=product`, `scope_id=<product_id>`
- Field-by-field scalar/collection instructions
- Migrated RC product rules become OVERRIDE / REPLACE (not INHERIT)

---

## 5. Variation Scope

- `scope_type=variation`, `scope_id=<variation_id>`
- Requires `parent_product_id`
- Not modelled as WooCommerce variation attributes
- No storefront variation behaviour in Stage 2

---

## 6. Scalar Modes

Enum: `ScalarConfigurationMode`

| Mode | Semantics |
|------|-----------|
| `inherit` | No active override value |
| `override` | Typed value required; `0` / `false` remain valid |
| `disable` | Explicit none; only for fields that allow DISABLE |

Null / empty / `0` / `false` are **never** generically treated as INHERIT.

---

## 7. Collection Modes

Enum: `CollectionConfigurationMode`

| Mode | Semantics |
|------|-----------|
| `inherit` | No members payload |
| `add` / `remove` / `replace` | Validated member lists |

`REPLACE []` is distinct from `INHERIT`. Offer ID order is preserved; duplicates are deduped first-seen.

---

## 8. Field Registry

`ConfigurationFieldRegistry` — storage contract only (not ECR).

| Key | Kind | Notes |
|-----|------|-------|
| `fulfilment_availability` | scalar | OVERRIDE/INHERIT |
| `fulfilment_choice` | scalar | OVERRIDE/INHERIT |
| `logistics_profile_id` | scalar | + DISABLE |
| `supplier_id` | scalar | + DISABLE |
| `origin_id` | scalar | + DISABLE |
| `priority` | scalar | int including 0 |
| `delivery_offer_ids` | collection | ADD/REMOVE/REPLACE/INHERIT |

Derived from current `product_delivery_rules` + Stage 1 mapping. No speculative future fields.

---

## 9. Repository APIs

`ScopedConfigurationRepositoryInterface` + `WpdbScopedConfigurationRepository` (+ `InMemoryScopedConfigurationRepository` for tests).

Operations: get/ensure global; find by scope / slice / legacy rule / parent product; save (version-aware); delete non-global scope; version info.

**No** `resolveEffectiveConfiguration()`.

Registered in `ServiceContainer`; **not** wired into selector/cart/checkout/shipping/snapshot consumers.

---

## 10. Configuration Versioning Foundation

- Per-scope `config_version` (monotonic integer, starts at 1)
- Semantic write → increment
- Identical fingerprint write → **no** increment
- Product/variation updates do not bump unrelated global/other scopes
- Global option mirror for future cache keys
- Effective fingerprints deferred to Stage 3

---

## 11. V2 → V3 Migration

1. Create v3 tables (`dbDelta`, idempotent)
2. Ensure global scope
3. Backfill product/variation rules via `LegacyConfigurationMigrator`
4. Quarantine category rules (report only; leave legacy rows)
5. `verify()` requires tables + global scope + legacy table intact + no shipment tables
6. Schema version set to `3` only after successful `up()` + `verify()`

Legacy rows are **not** deleted or altered.

Audit: migration writes **do not** spam `audit_log` (Stage 4 owns field-level admin audit UX).

Report option: `cetech_de_v3_config_migration_report` (internal).

---

## 12. Full Legacy Compatibility Matrix

Source of truth in code: `LegacyProductRuleMigrationMapper::compatibility_matrix()`.

| Legacy Input | V3 Scope | V3 Field Mode | V3 Value | Reason |
|--------------|----------|---------------|----------|--------|
| `id` | `scope.legacy_rule_id` | n/a | Traceability | Provenance |
| `target_type=product` | product | n/a | `scope_id=target_id` | Stage 1 |
| `target_type=variation` | variation | n/a | + `parent_product_id` | Stage 1 |
| `target_type=category` | quarantined | n/a | Not in v3 scopes | Stage 1 quarantine |
| `target_id` | `scope.scope_id` | n/a | Copied | Identity |
| `target_label_snapshot` | not migrated | n/a | Omitted | Display cache |
| `fulfilment_availability` | product\|variation | OVERRIDE | Exact string; also `slice_key` | Whole-record FA |
| `fulfilment_choice` | product\|variation | OVERRIDE | Exact string | Populated scalar |
| `delivery_offer_ids` list | product\|variation | REPLACE | Ordered unique ints | Whole-list semantics |
| `delivery_offer_ids` null/empty | product\|variation | REPLACE | `[]` | Distinct from INHERIT |
| `logistics_profile_id` >0 | product\|variation | OVERRIDE | Positive int | Populated scalar |
| `logistics_profile_id` null/0 | product\|variation | DISABLE | none | Explicit none |
| `supplier_id` >0 | product\|variation | OVERRIDE | Positive int | Populated scalar |
| `supplier_id` null/0 | product\|variation | DISABLE | none | Explicit none |
| `origin_id` >0 | product\|variation | OVERRIDE | Positive int | Populated scalar |
| `origin_id` null/0 | product\|variation | DISABLE | none | Explicit none |
| `priority` (incl. 0) | product\|variation | OVERRIDE | Exact int | 0 ≠ inherit |
| `status` | `scope.status` | n/a | Copied | Inactive preserved |
| `internal_notes` | not migrated | n/a | Omitted | Avoid dual private copies |
| timestamps | scope timestamps | n/a | New on insert | Non-destructive |

---

## 13. Category Legacy Handling

**Quarantined** per Stage 1 §12.2: do not auto-promote to Global. Category rows remain solely in `product_delivery_rules`. Migration report records quarantine reason `category_quarantined`. Future design decision (category soft layer vs deprecate) remains open for later stages; not silently flattened.

---

## 14. Idempotence / Partial Failure Safety

- Re-running migrator skips scopes that already have `legacy_rule_id`
- Identical saves do not bump versions
- `dbDelta` re-entry safe
- Failed migration does not advance `cetech_de_db_version` (existing `MigrationRunner` contract)
- Legacy table untouched on failure/retry

---

## 15. Tests

PHPUnit 10.5 foundation (`composer require-dev`).

| Suite | Coverage |
|-------|----------|
| Mode semantics | INHERIT/OVERRIDE/DISABLE; REPLACE [] vs INHERIT; 0 preserved; invalid rejected |
| Storage | In-memory repository round-trips, uniqueness, IDs |
| Versioning | Initial version, increment, identical write, isolation |
| Migration | Mapping matrix, category quarantine, idempotence, legacy fixture unchanged |
| Schema | Target=3, required markers, no shipment tables |

**Integration gap (honest):** live `wpdb` DDL/upgrade against MySQL was not executed in this environment. Domain/mapper/in-memory repository tests ran. Staging should run schema 2→3 once on a non-FLAIROC clone.

---

## 16. Runtime Non-Impact Proof

Git diff of Stage 2 does **not** modify:

- `ProductDeliveryOptionsBuilder` / selector
- cart capture / fingerprint
- checkout validation
- shipping calculator/method
- `RateQuoteEngine`
- order snapshot persistence
- customer projections

Only additive registration of scoped repositories in `ServiceContainer`. Runtime still resolves via `ProductDeliveryRuleResolver` + legacy table.

**RUNTIME IMPACT = NONE**

---

## 17. Security / Validation

- Field keys validated against registry (no arbitrary injection)
- Modes validated per field
- Invalid numerics throw (no non-numeric → zero coercion)
- Prepared statements for parameterized SQL
- No new customer REST/AJAX endpoints
- No secrets in this storage model

---

## 18. Performance Characteristics

Stage 3 can load configuration with a **bounded** read set:

1. Global scope (+ its fields/collections) — 1–3 queries or one joined fetch pattern
2. Product scopes for product_id (all slices) — 1 scope query + field/collection queries by `scope_row_id` IN (...)
3. Variation scopes for variation_id — same pattern

Avoids mandatory one-query-per-field × per-variation design. No resolver caching in Stage 2.

---

## 19. Deferred Stage 1 Risks

| Risk | Status |
|------|--------|
| `WpdbRateCardRepository::format_decimal` non-numeric → `0.0000` | **DEFERRED** |
| Rate-card `base_amount` default 0 | **DEFERRED** |
| HPOS `countOrderSnapshotReferences` uses postmeta | **DEFERRED** |
| Redis object-cache namespace hygiene | **DEFERRED** (operational) |
| Disabled Code Snippets residual warning on FLAIROC | **DEFERRED** (ops) |

---

## 20. Stage 3 Entry Criteria

1. Stage 2 COMPLETE (this document accepted)
2. Schema target `3` available on target environments after controlled upgrade dry-run
3. Scoped repositories registered and unit-tested
4. Explicit instruction: implement `EffectiveConfigurationResolver` only (admin/test harness; no storefront cutover)
5. Do not flip customer runtime flags
6. Do not begin variable capture / shipments / package consolidation

**Recommended next step:** Stage 3 — EffectiveConfigurationResolver and deterministic configuration resolution.
