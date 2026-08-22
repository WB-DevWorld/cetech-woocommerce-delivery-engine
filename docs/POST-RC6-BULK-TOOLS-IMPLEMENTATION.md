# Post-RC.6 Bulk Tools — Implementation

**Branch:** `feat/post-rc6-bulk-tools`  
**Plugin version:** `1.0.0-dev.bulk.3` (must not be packaged as RC.6)  
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

Job History and Job Items use **query/repository pagination**. Default page size is **25**. WordPress Screen Options persist a numeric field; the server sanitises to `20` / `25` / `50` / `100` (option `cetech_de_bulk_list_per_page`, max **100**). Values such as `500`, `1000`, `0`, negative, or non-numeric do not persist as entered: oversized values clamp to 100; invalid/too-small values become 25. HTML attributes are not the authority. The wp-admin screen never defaults to 500 and does not hide extra rows in JavaScript after loading a large page. Job-item SQL is `ORDER BY id ASC LIMIT n OFFSET m` with `n ≤ 100`, using KEY `job_status_id (job_id, status, id)`. Job counters (Total / Changed / Failed) come from the job row, not from loading every item.

Capabilities reused: `manage_product_delivery_rules`, `import_delivery_data`, `manage_delivery_rate_cards`, `manage_private_sources`, `manage_delivery_settings`.

WP-CLI: `wp cetech-de bulk preview|apply|status|cancel|rollback`, `wp cetech-de config export|import`. Same engine as admin.

Progress AJAX: `cetech_de_bulk_job_status`, polled every 5 seconds only on the job detail screen.

## Catalog admin UX (`1.0.0-dev.bulk.3`)

Owner physical QA of `1.0.0-dev.bulk.2` found the Catalog screen too technical. This repair does **not** change Bulk Job Engine, batching, Action Scheduler, schema 5, inheritance, resolver, rollback, or import/export.

- Category, tag, shipping class, Delivery Option, Logistics Profile, Pickup Location, Supplier, and Origin use labelled searchable/select controls. Stable IDs remain the posted values.
- Product targeting: WooCommerce product search by name/SKU is primary. Paste SKU list and paste IDs remain under **Paste a large SKU or ID list**.
- Progressive disclosure: fulfilment value, Delivery Option picker, entire-catalog confirmation, matching filters, and reset explanation appear only when relevant. Supplier/Origin only for authorised private-source users.
- Filters are grouped: Basic product filters, Delivery filters, Private / advanced filters, Advanced / Technical details.
- Normal labels avoid “term taxonomy ID”, “Configured Fulfilment Availability”, “Effective Fulfilment Availability”, and similar implementation wording.
- Layout uses existing `AdminPageLayout` form panels / advanced / technical details. Variation setup uses a full-width select.

## Selected-ID jobs

A selected-ID job may store the ID list once on `bulk_jobs.target_definition_json` as compact create-time metadata (about 60 KB / 340 KB / 790 KB for 10k / 50k / 100k sequential IDs). Enumeration pages that sorted list with a binary search (`O(log n + batch)`), then inserts durable `bulk_job_items`. When enumeration completes, the ID array is dropped from the job row (`selected_ids_materialized`, `selected_id_count` retained). Later worker ticks load slim JSON and claim a batch of items. Worker cost stays approximately batch-bounded. IDs are not stored in autoloaded `wp_options`. The existing 10k Action Scheduler qualification remains valid for queue/batch behaviour.

## Known limitations

- Cleanup mutations do not include a dedicated redundant-override detector beyond reset-entire-scope / clear-override actions.
- Recurring automatic catalog rules are intentionally not implemented.
- Shipment bulk status edits are intentionally not implemented.
- `assets/admin/bulk-tools.js` includes Catalog progressive disclosure plus 5-second job-detail polling (`tests/js/bulk-tools-catalog.test.js`).
- Owner physical QA is not performed by Cursor. Previous untagged owner-QA package `1.0.0-dev.bulk.2` is immutable. Current untagged owner-QA package: `cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.3.zip` (`1167830` bytes, SHA-256 `5aa19ffb201452dc463b01b015653365d0e78aabe8b0d53baa082777549d8da6`, source `a250b045c5783d0f4761fce99cda7b21f87e4337`, schema `5`).
