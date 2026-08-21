# Post-RC.6 Bulk Tools — Implementation

**Branch:** `feat/post-rc6-bulk-tools`  
**Plugin version:** `1.0.0-dev.bulk.1` (must not be packaged as RC.6)  
**Schema:** target `5` (`cetech_de_db_version`)

## Engine

- `BulkJobEngine` — create preview, apply, cancel, rollback, recipes. Creating a job enqueues one tick; it does not mutate the catalog in the request.
- `BulkJobWorker` + `BulkJobWorkerDispatch` — claim job, enumerate a page, claim items, mutate, checkpoint, requeue.
- `InMemoryBoundedQueue` (tests) / `ActionSchedulerQueue` (production).
- Default batch size 25; claim TTL 300s; time budget ~8s per tick.

Job statuses are machine codes (`previewing`, `queued`, `running`, `completed_with_errors`, …), not translated labels.

## Catalog targeting

`WooCommerceCatalogTargetQuery` uses keyset pagination on `posts.ID` plus SQL narrowing for native WooCommerce and Delivery Engine filters. Effective fulfilment / missing usable rate / invalid configuration are evaluated in resolver batches of 100 candidates. `MatchingFilters` with no SKUs and no filters matches **nothing** (entire catalog requires explicit confirmation). Count uses `COUNT(*)` for SQL candidates; enumeration corrects totals when effective filters apply.

## Mutations

`CatalogScopeMutator` applies field actions through scoped configuration + ECR overlay validation. Hard combinations still fail closed. `EntityCodeResolver` maps Delivery Option / logistics / supplier / origin **codes** to local IDs.

## CSV

`CatalogCsvMapper` columns are documented in `CatalogCsvMapper::COLUMNS`.

Blank cell = no change.  
`inherit` / `clear_override` = write inheritance.  
`override` with blank value = error.  
Exports prefix `= + - @` to mitigate CSV formula injection.

Large CSV apply is a `catalog_csv_import` bulk job. Rows are enumerated in batches; SKUs resolve at process time.

## Configuration portability

`ConfigurationExporter` / `ConfigurationImporter` / `ConfigurationPackage` / `EntityKeysetPager`.

Complete export iterates every Delivery Option, Area, Area rule, Rate Card, Logistics Profile, Pickup Location, and (when authorised) Supplier/Origin. It does not use the admin `list()` 500-row screen cap.

Import conflict policy is explicit. Apply retries items that failed during dry-run preview so same-package references (Area rules, Rate Cards) resolve after earlier sections are written. Site-wide import sets `setup_completed` false so sister-store checkout is not implicitly ready. Runtime flags are not imported. Post-import `health_report()` records entity counts and flag state on the job summary.

## Rate Cards

`RateCardBulkMutator` + `RateCardBulkAmountMath`. Percent/fixed updates go through `RateCardAmountFormatter`. Malformed amounts throw; they never become `0`. Explicit numeric zero remains valid.

`remote_surcharge` / `free_shipping_threshold` exist on schema 4 rate cards but are unused by the quote engine and are not presented as working checkout fields.

## Admin / CLI

Menu: Delivery Engine → Bulk Tools (`cetech-delivery-engine-bulk-tools`). Tabs: Catalog, Import / Export, Validation & Cleanup, Jobs / History, Charges.

Capabilities reused: `manage_product_delivery_rules`, `import_delivery_data`, `manage_delivery_rate_cards`, `manage_private_sources`, `manage_delivery_settings`.

WP-CLI: `wp cetech-de bulk preview|apply|status|cancel|rollback`, `wp cetech-de config export|import`. Same engine as admin.

Progress AJAX: `cetech_de_bulk_job_status`, polled every 5 seconds only on the job detail screen.

## Known limitations

- Cleanup mutations do not include a dedicated redundant-override detector beyond reset-entire-scope / clear-override actions.
- Recurring automatic catalog rules are intentionally not implemented.
- Shipment bulk status edits are intentionally not implemented.
- Owner physical QA and packaging are **not** authorised until the owner reviews `docs/POST-RC6-BULK-TOOLS-QUALIFICATION.md`.
- `assets/admin/bulk-tools.js` has no dedicated Vitest file; progress polling is 5 seconds on the job detail screen only.
