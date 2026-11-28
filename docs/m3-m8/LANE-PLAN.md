# M3–M8 lane plan and file ownership

Written 2026-11-28 in phase 0 by the lead (Claude Code, integration editor, WS3). Authority: Frank's M3–M8 authorization of 28 November 2026 (§6 phases, §7 scope, §9 lanes). The leases below are recorded in `CURRENT-WORK.md`; this file is the working detail.

## 1. What phase 0 found that shapes the plan

1. **`master`'s engines were built to qualification grade and have never run on a site.**
   - `ServicePromise`, `EmergencyControl` and `DeliveryQuote` write through their own `mysqli` connection (`OperationConnectionFactory`), not `$wpdb`.
   - They use MariaDB-only session variables: `@@in_transaction`, and `@@SESSION.tx_isolation`, which MySQL 8 removed.
   - They refuse a `db.php` drop-in.
2. **The promise engine fingerprints the host on every call.**
   - `NativePromiseRuntimeCapture::capture()` hashes the PHP binary, every executable mapping in `/proc/self/maps` and, with system tzdata (staging: `0.system`), about 600 zoneinfo files.
   - The product-page promise path calls it twice per render (`NativePromisePdpRuntime.php:49-51`).
   - Staging has 2 CPUs and 12 PHP-FPM workers. This must become a cheap, cached runtime identity before promised dates can be switched on.
3. **Promise adoption depends on quote adoption.**
   - `cetech_de_service_promise_adoption` is active only when quote placement adoption is active (`Plugin.php` 514–518).
   - The promise on the order is frozen only through the quote snapshot.
   - M3 ships before M4, so the promise must stand on its own: its own switch, and its own freeze at order placement (decision 6).
4. **Quote adoption cannot run on staging today.**
   - It refuses an external object cache (`NativeCartQuoteShipping.php:83`, `NativeCartQuoteEnvironment.php:101`). Staging runs Redis.
   - It also refuses coupons (`QuoteNativeReceiptCapture.php:17`) and a non-`mysqli` handle (`QuoteNativeReceiptGuard.php:29`).
   - M4's re-scope (decision 5) replaces this fencing.
5. **Pause control is store-wide only.**
   - The state is `enabled | checkout_suspended` with a fixed reason list and a 2 KiB limit. There is no free-text reason and no end time.
   - It is written with raw SQL through the operation connection, and its writer is hidden behind `CETECH_DE_EMERGENCY_WRITER_DISABLED`.
   - Decision 4 needs a portable store: `$wpdb` only and no MariaDB-only SQL.
6. **Public surfaces start from nothing.**
   - There are no REST routes, shortcodes or registered blocks.
   - The only CLI class is `BulkJobCliCommand` (`cetech-de bulk`, `cetech-de config`).
   - The only plugin actions are `cetech_de_services_registered` and `cetech_de_catalog_readiness_changed`.
   - The health checker's REST scan (`ConfigurationHealthChecker.php:2144`) matches its own text, so it probably always warns. This is inferred and has not been run.
7. **Pickup has no structured data yet.** Opening hours and readiness are free text. Capacity exists only as a port (`PromiseCapacityObserver`, with no implementation).
8. **No brand identity source.**
   - The text domain literal is on 3,397 lines.
   - `cetech_de_` is on 571 lines and `cetech-de-` on 2,241.
   - There is no `languages/` folder and no `load_plugin_textdomain`.
9. **Registry gaps.**
   - `DE-EMBED-*`, `DE-CTX-007` and `DE-UX-009` are not registry rows, so they go in `REGISTRY-PROPOSALS.md` for Frank.
   - No `DE-NOTIFY` row is required before Stable 1.0.
   - All six `DE-ORIGIN` rows are required.
   - COR-023 is "Qualify authoritative Woo monetary and tax boundary" (audit register 205/206). It is M4's money-parity work.
10. **The staging stack has moved since the brief** (read 2026-11-28):
    - WordPress 7.1.5 and WooCommerce 11.3.1. B2BKing is **active**.
    - Web PHP-FPM is **8.3**; the CLI is 8.4.27.
    - Redis is on, the handle is `mysqli`, the database is MariaDB 11.8.9, and the site time zone is `+00:00`.
    - Nobody in this batch changed any of it; Frank is told in the phase 0 report.

## 2. Waves and lanes

| Lane | Wave | Scope (requirement IDs) | Workstream areas |
|---|---|---|---|
| **L0 Lead** | all | Central files (§3), contracts, version, packages, staging installs, CI, merges, records | WS3 |
| **L1 Promise dates on customer surfaces** | A | Dates on the product page, cart, checkout (Classic and Blocks), thank-you page, My Account and both email formats, with the same text everywhere. Falls back to the M2 duration when no rule applies (`DE-PROMISE-*`) | WS1 |
| **L2 Promise rules, engine and order freeze** | A | Cheap runtime identity. Promise independent of quote adoption. Staff rules screen: working days, holidays, cut-offs, lead time per area and fulfilment profile, effective dates, a preview that tests a date, versions, scope per Delivery Option or site-wide. It reuses PR #114 where sound, with fresh fixtures. Freeze at order placement, staff order view, shipment estimates reconciled (`DE-PROMISE-*`) | WS3, WS2 |
| **L3 Emergency pause** | A | Decision 4 in full. Portable store, administrators only, reason required, optional end time, a state banner on every Delivery Engine screen, every action recorded, the fixed customer message (`DE-FLAG-003` and the other `DE-FLAG-*`) | WS3, WS2 |
| **L5 Embeds, then mobile** | A, then B | Wave A: shortcodes and nestable dynamic blocks for the delivery selector and delivery information; the site-wide shopping destination, advisory only. Wave B: mobile layouts and form factors from the agreed table, searchable selects, collapsed redundant checkout fields, WoodMart product-page placement, Quick View (`DE-EMBED*`, `DE-CTX-007`, `DE-UX-009`, `DE-PERF-*`) | WS1 |
| **L7 API and CLI** | A, then B | Wave A: read-only REST under `cetech-de/v1` with OpenAPI, JSON Schema, structured errors, paging, request IDs, rate limits and authorization, plus the order delivery block on the WooCommerce order response (Commerce Bridge note). Wave B: WP-CLI (configuration, diagnostics, import/export, simulation, shipments, pause) and API writes with idempotency keys. Wave C: rule dry-run and overrides, data lifecycle and reset (Issue #50) (`DE-API-*`, `DE-CLI-*`, `DE-RULE-*`, `DE-DATA-*`) | WS3 |
| **L4a Quote hold, server** | B | Decision 5: the server-side freeze working with Redis, coupons as a declared fingerprint input, pickup as zero-price components, 300 s absolute expiry, legacy path when off. Money parity on paid orders. Quote id and rule versions in the snapshot (`DE-QUOTE-*`, COR-023, COR-029) | WS2, WS3 |
| **L4b Quote checkout screens** | B | "Delivery price is held until…" with Refresh and Confirm on Classic and Blocks. An expired or changed price is shown again before payment. A quote view on the staff order screen (`DE-QUOTE-*`) | WS1 |
| **L6 Pickup hours and capacity** | B | Opening hours, closures, capacity and eligibility; the `DE-ORIGIN-*` rows the registry requires (`DE-PICKUP-*`, `DE-ORIGIN-*`) | WS2, WS3 |
| **L8 Events and webhooks** | B | Domain events and an outbox. Signed, replay-protected webhooks with retries, tested against a local receiver because staging suppresses webhooks (`DE-EVENT-*`) | WS3, WS2 |
| **L9 Verifier** | all | Reviews every pull request and records the review on it. Writes the ledgers under `scripts/qualification/m3/`, `m4/`, `m7/`, `m8/`. Runs the browser tests and the M2 regression ledger. Certification (CET-Q), security review, speed checks. **Never writes feature code** | — |

Wave C (phase 4): the L7 rule dry-run, data lifecycle and rename preparation (lead, identity source); certification and security review (L9 with the lead); the speed checks and the announced load check (lead with L9).

M8 order (authorization §7) is kept, so a budget cut is clean: read-only API → events and webhooks → CLI → API writes → dry-run → data lifecycle → certification → rename preparation.

## 3. Central files: lead only

Lanes hand the lead what they need registered. The lead does the registration in the lane's pull request branch, or in a follow-up, before merging.

- **Bootstrap and version:** the main plugin file, `readme.txt`, `src/Bootstrap/*` (`Plugin.php`, `ServiceContainer.php`, `RuntimeContracts.php`, `Activator.php`, `DataLifecycleManifest.php`, …), `src/Core/**`, `src/Presentation/Admin/AdminMenu.php`.
- **Feature switches:** `src/Bootstrap/FeatureFlags.php`, `src/Presentation/Admin/FeatureFlagLabels.php`, `src/Application/Configuration/ClassicCheckoutRuntimeActivation.php`.
- **Migrations and schema:** `database/migrations/*`, `src/Core/Versioning/*`, `src/Infrastructure/Persistence/*Schema.php`, `TableNames.php`, `ConfigurationTables.php`, `src/Domain/DataLifecycle/DataLifecycleRegistry.php`, `uninstall.php`.
- **Shared database transport:** `src/Infrastructure/WordPress/OperationConnection*.php`, `src/Application/Operation/*`.
- **CI and package:** `.github/**`, `phpunit*.xml`, `vitest.config.js`, `package*.json`, `composer.json`, `scripts/*.php`, `scripts/*.sh`, `scripts/*.ps1`, `scripts/qualification/**` except the four new ledger folders.
- **Shared cart and session:** `src/Application/Cart/CartDeliverySelection*.php`, `CartLineCustomerIdentity.php`, `src/Application/DeliveryQuote/NativeCartQuoteSessionStore.php`, `CartQuoteSessionEnvelope.php`, `src/Integrations/Blocks/BlocksStoreApiExtension.php`.
- **Order snapshot:** `src/Application/Order/*`, except the files leased below.
- **Shipment grouping:** `src/Application/Shipping/ShippingPackageBuilder.php`, `DeliveryGroupIdentity.php`, `SelectedOfferShipping*.php`, `src/Infrastructure/WooCommerce/Shipping/SelectedOfferShippingMethod.php`, `src/Application/Shipment/ShipmentPlan*.php`, `HistoricalShipmentPlanner.php`, `PaidOrderShipmentSubscriber.php`.
- **Shared admin host page:** `src/Presentation/Admin/DeliverySettingsPage.php`.
- **Records:** `CURRENT-WORK.md`, `docs/STATUS_CURRENT.md`, `docs/m3-m8/*` (except `reviews/`, which is L9's), and the registry files under `docs/product/`, which change only with evidence.

### Module seam (lead, contract pull request at the start of Wave A)

Each lane gets one module class it owns, `src/Bootstrap/Module/<Lane>Module.php`. It implements `PluginModule` (`register( ServiceContainer )` and `boot( ServiceContainer )`). `Plugin.php` calls every module in a fixed order, so no lane edits `Plugin.php`. A module only wires its own lane's services, behind its own feature switch.

### New feature switches (lead adds them; all default **off**)

| Switch | Wave | Lane |
|---|---|---|
| `enable_promised_dates` | A | L1, L2 |
| `enable_emergency_pause_control` | A | L3 |
| `enable_delivery_embeds` | A | L5 |
| `enable_rest_api` | A | L7 |
| `enable_quote_hold` | B | L4a, L4b |
| `enable_pickup_schedule` | B | L6 |
| `enable_webhooks` | B | L8 |

### Shared contracts in the Wave A contract pull request (lead)

- **`PromiseDisplay`** (as built in #206, amended 2026-11-28 after verifier review): a promised window only, never a fallback.
  - **Dates:** `from` and `to` are calendar days (`Y-m-d`) in one named time zone, the site time zone when the promise was made.
  - **No rule:** when no rule applies, the provider returns `null` and the surface shows the M2 duration. There is no "fallback" kind.
  - **Rule reference:** one string that records the source and version, in the form `promise:<scope>:<version id>`, where the scope is `site` or `offer-<Delivery Option id>`. For example: `promise:site:<version uuid>`, `promise:offer-12:<version uuid>`.
  - **Snapshot shape:** stable (`format` 1: `from`, `to`, `timezone`, `rule`). Any extra field needs format 2 and a reader for both.
- **`PromiseDateProvider`** is an interface; L2a implements it and L1 consumes it.
- **`FrozenOrderPromiseReader`** reads promises frozen at placement, keyed by delivery group id, from order meta `_cetech_de_promise_snapshot`, which is registered in the manifest.
- **`PromiseDateRangeFormatter`** gives the default wording, "Delivery between Tue 2 Dec and Thu 4 Dec". It formats in the promise's own stored zone, so a later time-zone change never moves a placed order's dates, and it is built to be easy to change. **One formatter serves every surface**: product page, cart, checkout, thank-you, My Account, both email formats and the staff order view.
- **Fault isolation:** a module that throws while registering or booting is skipped and logged (`ModuleFailures`), and the plugin keeps running.
- Pickup keeps the M2 readiness text in M3. Structured pickup dates come with L6.

### Migration numbers (the lead allocates)

Wave A needs none. Promise rules use the existing promise tables (schema 10), and the pause uses an option. Reserved:

| Number | Lane | Purpose |
|---|---|---|
| 13 | L8 | event outbox and webhook subscriptions |
| 14 | L6 | pickup schedule and capacity |
| 15 | L4a | quote freeze records, only if the existing quote tables cannot carry them |

Every migration is additive, keeps old readers working and is run on staging only after a backup. Raising the schema target also moves the package-verifier allowance and the data-lifecycle counts. Both are the lead's.

## 4. Lane leases (existing files a lane may edit; new files go in its own folders)

| Lane | Existing files leased | New files |
|---|---|---|
| L1 | `src/Application/Selector/DeliveryEstimatePrecedence.php`, `ProductDeliveryOptionsBuilder.php` (estimate parts), `src/Application/ServicePromise/Presentation/PublicPromiseFormatter.php`, `PromisePublicProjection.php`, `src/Integrations/ServicePromise/Presentation/**`, `src/Presentation/Frontend/CartFulfilmentPackagePresentation.php`, `CheckoutDeliveryPlanRenderer.php`, `CustomerOrderDeliverySummaryRenderer.php`, `CustomerShipmentRenderer.php`, `src/Application/Order/CustomerOrderDeliverySummaryBuilder.php`, `src/Presentation/Email/**`, `src/Presentation/Shared/DeliveryPresentationLabels.php`, `CustomerStorefrontCopy.php`, `src/Integrations/Blocks/BlocksPublicPayload.php`, `assets/frontend/blocks-checkout.js` (estimate display only) | `src/**/PromiseDates/**`, `src/Bootstrap/Module/PromiseDatesModule.php`, tests |
| L2 | `src/Domain/ServicePromise/**`, `src/Application/ServicePromise/**` except L1's two files, `src/Integrations/ServicePromise/**` except `Presentation/`, `src/Infrastructure/Persistence/WpdbPromiseRepository.php`, `PromiseStorageReadiness.php`, `WpdbShipmentPromiseRepository.php`, `StoredPromise*VersionLoader.php`, `WpdbPromiseHandoffSources.php`, `src/Application/DeliveryQuote/PromiseQuotePlacementActivation.php` (decoupling only), `src/Presentation/Admin/PromiseConfigurationPage.php`, `PromiseShipmentConfigurationSection.php`, `PromiseShipmentLegacyEtaGuard.php`, `src/Application/Order/PromiseSnapshotCore.php`, new `src/Application/Order/PromiseOrderFreeze*.php` (lead reviews both) | `src/**/PromiseRules/**`, `src/Bootstrap/Module/PromiseRulesModule.php`, tests including real-database tests |
| L3 | `src/Domain/EmergencyControl/**`, `src/Application/EmergencyControl/**`, `src/Integrations/EmergencyControl/**`, `src/Infrastructure/Persistence/EmergencyControlStore.php`, `src/Presentation/Admin/EmergencyControlSettings.php` | `src/**/EmergencyPause/**`, `src/Bootstrap/Module/EmergencyPauseModule.php`, tests including real-database tests |
| L5 | `src/Presentation/Frontend/ProductDeliverySelectorRenderer.php`, `assets/frontend/product-delivery-selector.js`, `variable-delivery-selector.js`, `assets/frontend/*.css` (Wave B: the mobile layout) | `src/Presentation/Embeds/**`, `blocks/**` (`block.json`, `render.php`, plain editor JS with no build step), `assets/embeds/**`, `src/Bootstrap/Module/EmbedsModule.php`, tests |
| L7 | `src/Application/Diagnostics/ConfigurationHealthChecker.php` (REST scan only), `src/Presentation/Cli/**` (Wave B) | `src/Presentation/Rest/**`, `src/Application/Api/**`, `docs/api/**` (OpenAPI, JSON Schema), `src/Bootstrap/Module/RestApiModule.php`, tests |
| L9 | none | `scripts/qualification/m3/`, `m4/`, `m7/`, `m8/`, `docs/m3-m8/reviews/` |

Wave B leases for L4a, L4b, L6 and L8 are written at the start of Wave B, from the same survey.

Two lanes never hold the same file. A lane that needs a change in someone else's file writes the request in its pull request, and the lead arranges it.

## 5. Working rules for every lane agent

- One worktree and one `fix/m3m8-<lane>-<topic>` branch per task, beside Frank's folder (`../cetech-m3m8-<lane>`), cut from the current batch head or the contract branch. Never touch Frank's folder.
- Stage explicit paths only, and run `git status` before every commit. Never commit `vendor/`, `node_modules/`, `dist/`, ZIPs, `.env*`, `Claude outputs/` or design references. No customer data, keys or configuration values anywhere.
- Every change has unit tests. Money, snapshot and concurrency rules also get real-database tests in the CI MariaDB group: the lead adds them to `phpunit.real-db.xml` and pins the counts in `ci.yml`.
- Local gates before push:
  - lint: `bash scripts/ci-lint-php.sh cetech-woocommerce-delivery-engine.php uninstall.php src database`;
  - `php scripts/verify-control-plane.php`;
  - targeted PHPUnit with `php -d extension=pdo_sqlite -d extension=sqlite3 -d memory_limit=2G vendor/bin/phpunit`;
  - `npx vitest run`.
- The full suite runs once before the pull request. Its failing set must equal the batch baseline: the 100 native-runtime tests that need the qualified Linux PHP runtime.
- Push only when ready for review. Every push runs CI, and pull requests into `batch/**` run it twice.
- Every customer-visible string goes through the text domain, with no invented business figures. No public payload, log or cache may show suppliers, origins, costs, rate cards or precise addresses.
- The pull request says what changed, which decision or requirement ID it implements, its tests, and what is not done.

## 6. Order of work

1. **Phase 0, now:**
   - #203 and #204 (CI trigger) and #205 (phase 1 base).
   - The phase 0 records on the batch branch.
   - The Wave A contract pull request (module seam, switches, promise display contract), stacked on #205.
2. **Phase 1, in parallel with the start of Wave A:**
   - Merge #205.
   - Build `1.0.0-m3.0-dev.2` and verify the ZIP.
   - Back up staging, install, schema 6 → 12, and check schema 12 and the version.
   - L9 re-runs the M2 ledger in a browser.
   - A fresh Cash on Delivery order to check Needs Attention.
   - A rollback note for `1.0.0-m2.6`. Its `SchemaVersion::is_up_to_date()` is `>=`, so m2.6 code on a schema-12 database should not try to migrate; the rehearsal proves it.
3. **Wave A:** L1, L2, L3, L5 (embeds), L7 (read-only API) and L9, from the contract branch. L2's runtime identity change comes first, because L1's product-page dates depend on it.
4. **Gate A:**
   - `1.0.0-m3.N` on staging.
   - The M3 ledger.
   - The staff kit for M3.
   - A cumulative pull request to `master` for Frank.
5. **Waves B and C, then the final phase,** as in authorization §6.

## 7. Risks the lead watches

- **Promise rules have no figures.** Staging uses rules named as test rules. Frank's real rules arrive as data and need no code change.
- **Speed.** Product-page and admin speed must be no worse than m2.6. Every product-page addition is measured on staging with the page cache bypassed.
- **CI capacity.** About 50 minutes per run, eight jobs, and double runs on batch pull requests. Lanes push only when ready.
- **A small staging server.** The 50–100 user load check is announced to Frank first and kept short.
