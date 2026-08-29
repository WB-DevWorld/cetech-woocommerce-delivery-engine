# POST-RC.6 Bulk.8 Physical QA — Consolidated Bulk.9 Repair

**Document status:** Implementation + test record. Packaged after owner review as `1.0.0-dev.bulk.9` — see `docs/POST-RC6-BULK9-OWNER-QA.md`.  
**Date:** 2026-08-29  
**Branch:** `integration/post-rc6-bulk-r1`  
**Development identity after repair:** `1.0.0-dev.bulk.9`  
**Schema target:** `5` (unchanged; schema 6 was not created)  
**Protected published baseline:** tagged `v1.0.0-rc.6` / schema `4` **untouched**  
**This is not:** RC.6, RC.7, Stage 15, In Store redesign, International ECR rewrite, Return/Refund, Blocks, carrier work, or FLAIROC deploy  
**Packaging:** Owner-reviewed. Package record: `docs/POST-RC6-BULK9-OWNER-QA.md`.

Protected artifacts **not modified and not retagged:**

- `v1.0.0-rc.6` and the RC.6 ZIP
- immutable `1.0.0-dev.bulk.7.zip`
- immutable `1.0.0-dev.bulk.8.zip`
- FLAIROC
- unrelated branches (`feat/post-rc6-bulk-tools`, `fix/post-rc6-admin-setup-defects`, `wip/rc6-adversarial-security-audit`, master)

Physical QA of `1.0.0-dev.bulk.8` was stopped. The Bulk.8 test matrix was not expanded. This repair consolidates the confirmed defects into one development identity.

---

## 1. Validation scan was functionally incomplete — BLOCKING

**Physical evidence:** BULK-000018, `operation_type=validation_scan`, Product 49164. Job finished `ready`, `dry_run=1`, empty `action_manifest_json`, item `{"reason":"no_change"}`, no resolver-health result, UI **Ready to apply** / **Apply these changes**. Product was unmodified (no-write safety passed), but it was not a real scan.

**Root cause:** Validation Scan reused the catalog mutator with an empty action manifest and `dry_run=true`. An empty manifest is a no-op (`no_change`). Dry-run jobs always finalize as machine `ready`, so the admin showed Apply. The worker never called `EffectiveConfigurationResolver`.

**Repair:**

- `CatalogScopeMutator::scan()` resolves each target through the existing ECR (no overlay, no second resolver) and records Valid / Warning / Invalid using `OperationalReadinessAssessor::reason_for_effective()`.
- Validation jobs finalize as `completed` while remaining `dry_run`. They never enter `ready`, never show Apply, never create a mutation manifest, and never write Product/Variation configuration.
- `BulkJobEngine::apply()` rejects Validation Scan.

---

## 2. Bulk catalog safety validation gap — BLOCKING

**Physical evidence:** Product 49164, site-wide In Warehouse / Standard Delivery. Delivery Options → Remove → Standard Delivery. BULK-000025 preview: Total 1 / Would change 1 / Would fail 0. Removal of the only effective valid In-Warehouse option was treated as safe.

**Root cause:** Preview already overlay-resolved the proposed scoped configuration, but it only failed closed on `EffectiveFieldState::Invalid`. Hard fulfilment constraints filter disallowed routes and can leave a **Valid empty** Delivery Options collection. Operational unreadiness (“No usable delivery option is configured.”) was not treated as a blocking preview/apply failure. The mutation was therefore considered storable.

**Repair:**

- After overlay ECR resolve, preview/apply also consults the existing operational-readiness authority.
- A proposed configuration with no valid delivery path fails as `no_valid_delivery_path` (blocking).
- Existing hard-constraint Invalid combinations still fail as `hard_rule_failure`.
- Valid Add / Replace that still leave a usable path remain allowed.
- Catalog conflict-aware rollback is unchanged.

---

## 3. Rate Card rollback false conflict — BLOCKING

**Physical evidence:** Charge #1 50.0000 GHS → BULK-000019 +10% → 55.0000 GHS. Immediate rollback BULK-000020: `partially_rolled_back`, Restored 0, Skipped/conflict 1, `edited_after_job`. Rate Card `updated_at` had not changed after Job 19. Job 19 `after_fingerprint` matched the rollback item fingerprint.

**Root cause:** Rollback always called `CatalogScopeMutator::rollback()`, which compares **product scoped-configuration** fingerprints. Rate Card jobs store a rate-card amount fingerprint. Looking up product scope for the charge ID never matches, so every Rate Card rollback was a false `edited_after_job`. Catalog conflict protection was not the defect.

**Repair:**

- `RateCardBulkMutator::rollback()` compares the current canonical Rate Card fingerprint (`id`, `internal_code`, `base_amount`, `status`, `priority`) with the job’s `after_fingerprint`.
- After apply, the persisted row is re-fingerprinted.
- Immediate rollback of 50 → 55 with no later edit restores 50.
- A genuine post-apply Rate Card edit still skips with `edited_after_job`.
- Catalog `edited_after_job` protection is unchanged.

---

## 4. Rate Card preview / result presentation

**Physical evidence:** Job 19 stored `target_type=rate_card` and `before_snapshot_json` with `base_amount=50.0000`, but the admin rendered **Product #1** / Type Product / Current — / Proposed —.

**Root cause:** `BulkJobAdminCopy::target_type_label()` defaulted every non-variation target to Product. `BulkJobItemResultPresenter` only reconstructed catalog scalar/collection fields. Rate Card snapshots were ignored. `BulkJobTargetLabelResolver` always queried WooCommerce products.

**Repair:** Rate Card items render Delivery Charge identity and money values from stored snapshot/result data (for example Current GHS 50.00 / Proposed GHS 55.00). Type is Delivery Charge, not Product.

---

## 5. Configuration import entity presentation

**Physical evidence:** BULK-000017 same-site import: 24 total / 0 changed / 24 skipped. Idempotency passed (no duplicate codes). UI still showed Product cards with Current/Proposed —.

**Root cause:** Same Product default as defect 4. Config import results stored only `section` + `code`, not entity label or current/proposed summaries.

**Repair:** Importer records entity type/name and current/proposed/action text. Admin labels Delivery Option, Delivery Area, Delivery Area Rule, Delivery Charge, Logistics Profile, Pickup Location, Supplier, Origin, and other supported sections.

---

## 6. Rollback button on unchanged jobs

**Physical evidence:** Same-site config import Changed 0 / Skipped 24 still exposed **Roll back eligible items**.

**Root cause:** `BulkJobStatus::allows_rollback()` is true for any `completed` / `completed_with_errors` job, ignoring `changed_count`.

**Repair:** The admin and engine expose rollback only when `changed_count > 0` and the operation is not a Validation Scan. Conflict-only / no-change jobs have no rollback action.

---

## 7. Asynchronous status / counter presentation race

**Physical evidence:** Headline Completed 1/1 while Changed still 0; item still Waiting; Recent Jobs still Queued/Processing after the result card looked terminal; rollback card Rolled back 1/1 while Recent Jobs said Processing. The durable job later settled correctly.

**Root cause:** AJAX polling updated only the headline from job-row fields and treated machine `completed`/`ready` as terminal immediately. It did not require counters to add up (`processed = changed + skipped + failed`) and, for completed jobs, stopped polling without reloading the item table. The browser therefore fabricated a terminal view from a partial snapshot. Large jobs were already backgrounded; this was not a runner defect.

**Repair:**

- Presentation is terminal only when counters are coherent.
- Incoherent completed/ready rows render **Finalizing** and keep polling.
- When a coherent terminal snapshot arrives and the page was rendered from an incoherent state, the job detail reloads once.
- Bounded/background execution from Bulk.8 is preserved (async Action Scheduler + bounded admin continue). Jobs are not made synchronous.

---

## 8. Charges increase/decrease UX

**Physical evidence:** Staff had to know that `10` means +10% and `-10` means decrease.

**Repair:** Explicit operations: Increase by percentage, Decrease by percentage, Increase by fixed amount, Decrease by fixed amount. Staff enter a positive value. The admin translates that to the existing signed percent/fixed math. Missing/invalid values still throw and never become 0. Explicit numeric zero remains valid. Legacy signed `percent` / `fixed` manifests remain accepted.

---

## Preserved, proven Bulk.8 / R1 behaviour

Not regressed by design:

- Background execution and bounded browser-assisted continue
- Cancellation
- Catalog scalar mutation persistence
- Catalog conflict-aware rollback (`edited_after_job`)
- Successful catalog rollback when no conflict exists
- Catalog CSV preview/apply/rollback
- Configuration package same-site conflict-safe idempotency
- Delivery Options collection Replace / Add (including deduplication)
- Charge math 50.0000 → +10% → 55.0000
- R1: generate Reference Code before validation; stable code on rename; invalid/duplicate code preserves form values; header Create/Save owns the form through `form=`; no nested-form request-path bug; Reference Code visible; blank Advanced number fields; WooCommerce country-name picker storing ISO-2; responsive card wrap; single primary Create/Save. No R1 QA identity strings.

Do **not** retry historical Job 19 rollback on the training site. Charge #1 was later restored manually to 50.0000 GHS, which is a genuine post-job edit of that historical job.

---

## Files changed

| File | Role |
|------|------|
| `src/Application/Bulk/Catalog/CatalogScopeMutator.php` | Real validation scan; proposed-effective operational-readiness gate |
| `src/Application/Configuration/OperationalReadinessAssessor.php` | Shared `reason_for_effective()` (no second resolver) |
| `src/Application/Bulk/BulkJobWorkerDispatch.php` | Dispatch scan; config-import presentation payload |
| `src/Application/Bulk/BulkJobWorker.php` | Validation completes read-only; Rate Card rollback path; warning counters |
| `src/Application/Bulk/BulkJobEngine.php` | Reject scan apply; rollback requires `changed_count > 0`; copy item result onto rollback children |
| `src/Application/Bulk/BulkJobRunnerState.php` | Coherent-counter / Finalizing presentation |
| `src/Domain/RateCard/RateCardBulkMutator.php` | Canonical fingerprint, persisted after-fingerprint, Rate Card rollback, display snapshot |
| `src/Domain/RateCard/RateCardBulkAmountMath.php` | Explicit increase/decrease → signed internal op |
| `src/Application/Bulk/Portability/ConfigurationImporter.php` | Entity presentation fields |
| `src/Presentation/Admin/BulkJobAdminCopy.php` | Scan copy, rollback visibility, entity type labels, coherent progress payload |
| `src/Presentation/Admin/BulkJobItemResultPresenter.php` | Rate Card / config / scan result cards |
| `src/Presentation/Admin/BulkJobTargetLabelResolver.php` | Non-product entity identity |
| `src/Presentation/Admin/BulkToolsPage.php` | Scan notice, no Apply/rollback when ineligible, explicit charge ops |
| `src/Bootstrap/Plugin.php` | Rate Card mutator receives offer/zone label repos |
| `assets/admin/bulk-tools.js` | Do not stop/reload on incoherent terminal; reload once when coherent |
| `cetech-woocommerce-delivery-engine.php` | Identity `1.0.0-dev.bulk.9` |
| `readme.txt` | Identity + changelog |
| `scripts/verify-production-package-autoload.php` | Identity check follows bulk.9 |
| `tests/Unit/Bulk/BulkJobBulk9RepairTest.php` | **Added** — defect coverage |
| `tests/Unit/Bulk/BulkJobPreviewPresentationTest.php` | Scan copy assertions |
| `tests/Unit/Shipment/SchemaV4InspectionTest.php` | Version identity `bulk.9` |
| `tests/js/bulk-tools-catalog.test.js` | Finalizing vs coherent terminal |

Schema / migrations: **none**. `SchemaVersion::TARGET` remains `5`.

---

## Tests added/changed

Added `tests/Unit/Bulk/BulkJobBulk9RepairTest.php`:

- Validation scan performs real resolver validation.
- Validation scan terminates read-only and cannot Apply.
- Validation scan writes no Product/Variation config.
- Remove-last-valid-option preview is blocked.
- Replace with an invalid option for the fulfilment classification is blocked.
- Valid Add remains allowed and can Apply.
- Rate Card +10% preview computes 50→55; Apply persists 55; immediate rollback restores 50.
- Genuine post-apply Rate Card edit causes conflict skip.
- Rate Card / config cards render correct entity/value information.
- Changed=0 job has no rollback action.
- Terminal UI does not claim completion before coherent counters.
- Explicit increase/decrease amount semantics; missing value never becomes zero.

Changed:

- `BulkJobPreviewPresentationTest` — scan copy / no Apply language
- `bulk-tools-catalog.test.js` — Finalizing keeps polling; coherent terminal reloads
- `SchemaV4InspectionTest` — `1.0.0-dev.bulk.9`

Existing Catalog rollback conflict tests, Bulk background portability tests, and R1 request-path / rendered-form ownership tests remain in the suite and were run.

---

## Fresh test counts (2026-08-29)

| Suite | Result |
|-------|--------|
| Focused new + regression (`BulkJobBulk9RepairTest`, `BulkJobEngineTest`, presentation/rollback/runner, portability/rate math, R1 request-path) | **94 tests, 736 assertions, OK** |
| All `tests/Unit/Bulk` + version identity | **121 tests, 629 assertions, OK** (2 pre-existing deprecations in that slice) |
| Full PHPUnit | **745 tests, 4223 assertions, OK** (5 pre-existing deprecations; previously 735 / 4157 on Bulk.8) |
| Full JS (`npm run test:js`) | **24 / 24 OK** (previously 22 / 22) |
| PHP lint on changed PHP files | **0 errors** |

Assertions were not weakened to make tests pass.

---

## Known remaining risks

- Training-site physical acceptance of Bulk.9 has not been run. Owner should use the short six-item list in the task, not an expanded matrix, after packaging is approved.
- Do not retry rollback of historical BULK-000019; Charge #1 was manually restored after that job.
- Configuration import **rollback** of actually-changed entities is still not a dedicated entity restorer. This repair only hides rollback when nothing changed. Same-site idempotent skip jobs no longer offer a meaningless rollback.
- Validation Scan warnings currently cover hard-constraint route filtering that still leaves a usable path. Invalid / Needs Attention is used when operational readiness fails.
- Rate Card display names depend on offer/zone rows being readable. Amounts still render from the stored snapshot if names are missing.
- Incoherent terminal presentation is fixed in job-detail polling. The Recent Jobs table is not live-polled; after a reload it reads the durable job row.
- Browser verification of wp-admin was not possible from this environment (WordPress admin is on the training site). Engine behaviour is covered by PHPUnit/JS.

---

## Explicit non-touch statement

Protected RC.6, immutable Bulk.7, immutable Bulk.8, FLAIROC, and unrelated feature streams were **untouched**. No RC.7. No schema 6. No Stage 15. No In Store semantic redesign. No International ECR rewrite. No Return/Refund, location redesign, carrier, or Blocks work.

---

## STOP

Implementation and tests are complete. Packaged after owner review as `cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.9.zip`. Record: `docs/POST-RC6-BULK9-OWNER-QA.md`.
