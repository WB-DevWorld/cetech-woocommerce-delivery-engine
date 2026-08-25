# Post-RC.6 Bulk Tools — Architecture

**Status:** Authorised post-RC.6 development on `feat/post-rc6-bulk-tools`  
**Protected baseline:** tagged `v1.0.0-rc.6` / schema `4` (immutable)  
**This tree:** plugin identity `1.0.0-dev.bulk.4`, schema target **`5`**  
**Does not authorise:** Checkout Blocks, carrier APIs, shipment mass-edit, Stage 15, FLAIROC, retagging RC.6

## Problem

Administrators of large catalogs must be able to issue **one logical command** covering hundreds to 100,000+ products without processing that set in a single PHP/HTTP request, and without flattening GLOBAL → PRODUCT → VARIATION inheritance.

## Decisions

### Schema 5 is required

Durable bulk jobs, per-item checkpoints, and conflict-aware rollback snapshots cannot live in autoloaded `wp_options`. Schema 5 adds three tables and does not alter schema 4 configuration or shipment tables:

| Table suffix | Purpose |
|--------------|---------|
| `bulk_jobs` | One logical job (status, hashes, progress, dry-run, cancellation) |
| `bulk_job_items` | Per-target checkpoint, before snapshot, after fingerprint |
| `bulk_recipes` | Saved filter/action recipes (apply is still a new preview job) |

RC.6 schema 4 remains protected history. Migration `20260821160000_create_bulk_job_tables` is forward-only, idempotent (`dbDelta`), and retry-safe.

### One Bulk Job Engine

All large administrative work uses `BulkJobEngine` + `BulkJobWorker`:

- Product/variation bulk configuration
- Catalog CSV import
- Configuration package import
- Rate Card amount updates
- Validation scans
- Rollback (a child job)

There is **no second resolver**. Dry-run uses `OverlayScopedConfigurationRepository` in front of the same `EffectiveConfigurationResolver`.

### Logical operation vs physical batches

Creating a job never walks the catalog. Workers process bounded batches (default **25**), keyset-paginate (`ID > cursor`), claim items with TTL, and requeue. The browser is not the runner. If Action Scheduler is unavailable the job **fails safe** (`background_queue_unavailable`) and does **not** fall back to a synchronous 20,000-item loop.

Action Scheduler group: `cetech-delivery-engine-bulk`. Hook: `cetech_de_bulk_job_tick`. Payload: job ID only.

Complete export uses `EntityKeysetPager` (`page_after`, page size 100, max 250). Admin `list()` remains a screen cap and is not the export path.

### Catalog targeting

`WooCommerceCatalogTargetQuery` narrows with indexed SQL (posts, term relationships, postmeta, configuration_scopes/fields/collections) then evaluates EffectiveConfigurationResolver in pages of 100 for filters that need effective state. `MatchingFilters` with no SKUs and no filters match **nothing**. Entire Catalog requires explicit confirmation.

Supported filters: SKU/name/search, category, tag, status, shipping class, native product type, stock status, configured fulfilment, effective fulfilment, site-wide vs product exception, variation inherit vs override, Delivery Option, Logistics Profile, Pickup Location, missing usable rate, invalid effective configuration, Supplier/Origin when the actor is authorised.

### Inheritance and collections

Bulk mutations write through `CatalogScopeMutator` → `ScopedConfigurationRepository`.

- Reset to Site-wide = `deleteScope` (inheritance), **not** copying today’s site-wide values into product rows.
- Delivery Option collections support INHERIT / ADD / REMOVE / REPLACE. REMOVE on an inheriting product writes a REMOVE instruction; it does not flatten Air+Sea into a REPLACE of Sea.
- Blank CSV cells mean **no change**, never inherit, never zero.

Variation policy default: **preserve existing variation overrides**.

### Portable identity

Cross-site configuration uses `internal_code`, never source database IDs.

Package format: `cetech-de-config-package` version **1** (independent of DB schema version). Unsupported versions are rejected. Import never enables storefront feature flags. Orders, shipments, audit, jobs, secrets are excluded.

Conflict modes: `skip_conflicts` (safer default), `add_missing`, `update_matching`, `replace` (advanced).

### Rollback

Rollback is a new batched job. An item is restored only when the current fingerprint still matches the fingerprint immediately after the original job. Later manual edits are skipped (`edited_after_job`).

## Non-goals (this initiative)

- Recurring automatic catalog mutations
- Broad shipment status mass-edit
- Duplicate WooCommerce product fields
- A bulk-only cache or bulk-only ECR
- Silent storefront activation after import
