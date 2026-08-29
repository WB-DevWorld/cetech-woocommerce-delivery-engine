# Post-RC.6 Bulk Tools — Implementation

**Historical identity:** `feat/post-rc6-bulk-tools` / `1.0.0-dev.bulk.6`  
**Current tree:** `integration/post-rc6-bulk-r1` / `1.0.0-dev.bulk.9` (Bulk.8 physical-QA repairs: `docs/POST-RC6-BULK8-PHYSICAL-QA-REPAIR.md`; background-runner: `docs/POST-RC6-BULK-BACKGROUND-PORTABILITY.md`)  
**Schema:** target `5` (`cetech_de_db_version`)

## Engine

- `BulkJobEngine` — create preview, apply, cancel, rollback, recipes. Creating a job enqueues one tick; it does not mutate the catalog in the request.
- `BulkJobWorker` + `BulkJobWorkerDispatch` — claim job, enumerate a page, claim items, mutate, checkpoint, requeue.
- `InMemoryBoundedQueue` (tests) / `ActionSchedulerQueue` (production). Production enqueue prefers async Action Scheduler dispatch plus a bounded admin continue path; see `docs/POST-RC6-BULK-BACKGROUND-PORTABILITY.md`.
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

Job History and Job Items use **query/repository pagination**. Default page size is **25**. WordPress Screen Options persist a numeric field; the server sanitises to `20` / `25` / `50` / `100` (option `cetech_de_bulk_list_per_page`, max **100**). Values such as `500`, `1000`, `0`, negative, or non-numeric do not persist as entered: oversized values clamp to 100; invalid/too-small values become 25. HTML attributes are not the authority. The wp-admin screen never defaults to 500 and does not hide extra rows in JavaScript after loading a large page. Job-item SQL is `ORDER BY id ASC LIMIT n OFFSET m` with `n ≤ 100`, using KEY `job_status_id (job_id, status, id)`. Job counters come from the job row, not from loading every item. Dry-run counters use future wording (Would change / No change / would be skipped / Would fail). Applied jobs use Changed / Unchanged / skipped / Failed.

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
- Owner physical QA is not performed by Cursor. Previous untagged owner-QA packages `1.0.0-dev.bulk.2`, `1.0.0-dev.bulk.3`, `1.0.0-dev.bulk.4`, and `1.0.0-dev.bulk.5` are immutable. Current untagged owner-QA package is `1.0.0-dev.bulk.6` (`cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.6.zip`, `1180297` bytes, SHA-256 `41fcc68ea4638d530aa710d9f25931248ce8ec1a3183a3908709fb3ea53150f1`, source `fb16554a2484d63a82451410323137d88d187d87`, schema `5`).

## Jobs/History preview presentation (`1.0.0-dev.bulk.4`)

Owner physical QA of `1.0.0-dev.bulk.3` passed the Catalog dry-run engine (`BULK-000001`, 1/1, ready, 1 proposed change, no catalog write) and failed normal-administrator preview presentation.

This repair does **not** change Bulk Job Engine, batching, Action Scheduler, schema 5, inheritance, resolver, rollback, target enumeration, or stored machine statuses.

- Dry-run counters: Total / Would change / No change / would be skipped / Would fail / Warnings.
- Applied counters remain Changed / Unchanged / skipped / Failed.
- Machine `ready` renders **Ready to apply**. Machine `catalog_update` renders **Catalog update**. Codes remain in Technical details.
- Job items batch-load product/variation titles and SKUs for the current page only (`BulkJobTargetLabelResolver`, cap 200 IDs). Primary label is the product or parent — variation name. Raw IDs stay secondary/technical.
- Current / Proposed (or Applied) columns reconstruct human field summaries from `before_snapshot` + the approved action manifest. Fulfilment uses registry labels. Collections use Add / Remove / Replace with / Restore inherited Delivery Options. Reset-to-Site-wide is inheritance, not a copied value.
- Keep-existing-variation-settings copy and optional inherit/override counts distinguish direct writes from downstream inheritance.
- **Cancel remaining work** is shown only in active worker states (`previewing`, `queued`, `running`, …). A finished preview (`ready`) hides it. `BulkJobStatus::allows_cancel()` is unchanged for engine/CLI.
- Preview banner: “Preview only — no product settings have been changed yet.” Apply help: “Applying starts a background job. Changes are processed in small batches.” Button: **Apply these changes**. Apply still uses the stored preview manifest.
- Progress AJAX adds `status_label`, `show_cancel`, and `allows_apply` without removing machine `status`.
- Layout stacks on mobile wp-admin; text, not colour alone.

## Admin UI consistency (`1.0.0-dev.bulk.5`)

Owner accepted bulk.4 preview semantics and asked for a visual-consistency pass before Apply/mutation QA. This repair does **not** change Bulk Job Engine, batching, Action Scheduler, schema 5, inheritance, resolver, rollback, or import/export behaviour.

- One Bulk Tools page class (`cetech-de-bulk-tools`) with a small spacing scale (8 / 12 / 16 / 24px) scoped so other wp-admin screens are unchanged.
- Catalog, Import / Export, Validation & Cleanup, Jobs / History, and Charges share the same workspace, panel, field, action, and table patterns.
- Preview-only uses a WordPress `notice notice-info inline`. Current / Proposed sit in labelled panes (not red/green).
- Empty Jobs/History explains the next action (Go to Catalog). Import and Charges empty/permission states follow the same empty-state component.
- Technical details remain collapsed; JSON wraps/scrolls inside a padded panel.
- Apply remains grouped with its batch-help copy. Cancel remaining work stays secondary.

## Rollback counters and execution presentation (`1.0.0-dev.bulk.6`)

Owner physical QA of `1.0.0-dev.bulk.5` proved Apply and Rollback behaviour (empty before snapshot restored true Product inheritance; `edited_after_job` still skips). The rollback job row left `processed_count = 0` while the item was `rolled_back` and `summary.rollback_restored = 1`, so the admin showed **Rolled back · 0 / 1**.

This repair does **not** change rollback conflict rules, schema 5, inheritance, the resolver, Action Scheduler, or batch size.

- Rollback generic mapping: `processed_count = rollback_restored + rollback_skipped + rollback_failed`. `changed_count` stays 0.
- Unexpected restore exceptions are recorded as `rollback_failed` (`rollback_exception`) instead of aborting the tick.
- Normal admin rollback counters: Total / Restored / Skipped / conflict / Failed / Warnings.
- Queued rollback compare heading is **Will restore**, not Applied. Completed rollback uses **Restored**.
- Variation-impact copy is state-aware (preview / applied / rollback). Preview-only “does not write variation rows” is not shown after a write.
- Apply clears `summary.examples` and the worker will not append the same target twice.
