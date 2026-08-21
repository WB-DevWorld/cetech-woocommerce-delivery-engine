# Post-RC.6 Bulk Tools — Real database / large-operation qualification

**Date:** 2026-08-21  
**Branch:** `feat/post-rc6-bulk-tools`  
**Plugin identity:** `1.0.0-dev.bulk.1` (must not be packaged as RC.6)  
**Result:** automated qualification **PASS** — not owner physical QA, not a package

This record describes what actually ran. Disposable stack: `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-bulk-tools-qual` (not FLAIROC, not committed into this repository).

## Environment

| Item | Value |
|------|--------|
| WordPress | 7.1 |
| WooCommerce | 11.0.1 |
| PHP | 8.2.33 (containers) / 8.5.0 (host PHPUnit) |
| MariaDB | 11.4.12-MariaDB-ubu2404 |
| Ports | WP upgrade `127.0.0.1:18090`, WP fresh `127.0.0.1:18091`, MariaDB `127.0.0.1:33090` |
| Action Scheduler | WooCommerce bundled `ActionScheduler_QueueRunner` |
| Evidence | `cetech-de-bulk-tools-qual/evidence/` |

## 1. Unrelated work isolation

Leftover RC.6 adversarial security-audit artifacts were **not discarded**. They were committed on:

- Branch: `wip/rc6-adversarial-security-audit`
- Commit: `a88f28048afcc84456e6933351c71b01a9838c5a` (158 files)

Includes `docs/RC6-ADVERSARIAL-SECURITY-AUDIT.md`, `docs/audit/`, `scripts/audit-rc6-*.php`, `tests/Security/`, and the Security suite `phpunit.xml` addition. Default `phpunit.xml` on `feat/post-rc6-bulk-tools` remains Unit+Integration only.

## 2. Schema 4 → 5 (real MariaDB)

Production `MigrationRunner` on an RC.6 schema-4 install with representative Site-wide Defaults, scoped configuration, Delivery Options/Areas/rules, Rate Cards, Logistics Profiles, Pickup Locations, Suppliers/Origins, shipment tables + sample shipment, feature flags, and marker `schema4-baseline-keep`.

| Gate | Result |
|------|--------|
| A. 4→5 upgrade | `cetech_de_db_version=5`; three bulk tables created InnoDB `utf8mb4_unicode_520_ci`; schema-4 tables/data retained; marker unchanged |
| Second run | Idempotent (`schema5-second-run.json`) |
| C. Interrupted/retry | `bulk_recipes` dropped + version pinned 4; retry restored schema 5 |
| D. Deactivate/reactivate | Version stayed 5 |
| E. Same-version reinstall | Already active |
| B. Fresh activate | Direct schema 5 (`schema5-fresh.json`) |
| F. Preserve-data uninstall | `Uninstaller::uninstall()` with delete-data option **off**: 20 plugin tables retained including bulk jobs. File delete via `wp plugin delete` was **not** executed against bind-mounted source. |
| G. Explicit delete-data | Option on: all plugin tables dropped; `shop_order` post retained; WooCommerce `order_items` table retained; `cetech_de_db_version` cleared. Reactivate restored schema 5 (`schema5-fresh-reinstall.json`). |

### Bulk table inventory (MariaDB SHOW CREATE)

**`wp_delivery_engine_bulk_jobs`** — InnoDB, `utf8mb4_unicode_520_ci`  
Indexes: PRIMARY, UNIQUE `job_uuid`, UNIQUE `job_code`, KEY `status`, KEY `status_updated (status, updated_at)`, KEY `actor_user_id`, KEY `operation_type`, KEY `parent_job_id`

**`wp_delivery_engine_bulk_job_items`** — InnoDB, `utf8mb4_unicode_520_ci`  
Indexes: PRIMARY, UNIQUE `job_target (job_id, target_type, target_id, external_key)`, KEY `job_status_id (job_id, status, id)`, KEY `job_id`, KEY `claim_token`, KEY `target_lookup (target_type, target_id)`

**`wp_delivery_engine_bulk_recipes`** — InnoDB, `utf8mb4_unicode_520_ci`  
Indexes: PRIMARY, UNIQUE `recipe_code`, KEY `owner_user_id`

No duplicate tables/indexes observed. Schema-4 configuration and shipment tables were not rebuilt.

## 3. Complete export (no silent 500-row truncation)

Unit: `CompleteExportAndFilterTest` — 1500 Delivery Options, no duplicates.  
Real MariaDB export: **601 Delivery Options**, **551 Delivery Areas**, plus rules/rate cards/logistics/pickup/site-wide (`export-counts.json`). Private suppliers/origins omitted.

## 4. Catalog filters

SQL + batched ECR. Empty `MatchingFilters` = no targets. Combinations covered in `CompleteExportAndFilterTest`.

## 5. Action Scheduler

| Size | Create processed | Batch | Preview ticks | Apply | Memory peak | Payload |
|------|------------------|-------|---------------|-------|-------------|---------|
| 100 | 0 | 25 | 1 runner drain | completed 100/100 | ~132 MB | `{"job_id":1}` |
| 1,000 | 0 | 25 | 80 | 40 ticks, 1000/1000 | ~134 MB | job id only |
| 10,000 | 0 in 151 ms | 25 | 586 | 400 ticks, 10000 changed | 136 MB flat | `{"job_id":15}` |

Additional proofs (`as-extra.json`, `as-cancel-dup.json`):

- Cancel during apply: 50 changed intact, 70 remaining cancelled
- Exclusive claim: 25 then 0
- Claim TTL reclaim after 400s-old token
- Queue unavailable: `background_queue_unavailable`, processed 0
- Rollback child job: 32 inherit restored, 8 `edited_after_job` / `rollback_skipped`

## 6. Storefront during 10k job

Homepage and `/wp-json/` returned HTTP 200 during and after the 10k job (homepage ~0.4–1.1s, REST ~0.2s). Admin progress polling is 5 seconds (`assets/admin/bulk-tools.js`). REST stayed healthy while the worker ran in WP-CLI, not on storefront requests.

## 7. Sister-site configuration portability

Source export counts: 601 options, 551 areas, 1 rule, 1 rate card, 1 logistics, 1 pickup. Private off.

Clean Store B single-pass apply (`import-single-pass.json`): **601 / 551 / 1 / 1 / 1 / 1**, suppliers 0, shipments 0, runtime flags 0, `setup_completed` false, `post_import_health` recorded. Source DB IDs unused (`internal_code`).

| Mode | Result |
|------|--------|
| skip_conflicts | 1157 skipped, counts unchanged |
| update_matching | `bulk_opt_1` public_label → `Updated Sister Label` |
| replace | completed, counts unchanged |
| malformed / unsupported / dangling zone | rejected (`package-guard.json`) |

## 8. Catalog CSV

1500-row job: bounded batches of 25, AS payload job id only, initiating request processed 0. Blank override = `csv_row_invalid`. Round-trip (`csv-roundtrip.json`): seed 80 overrides, then inherit 40 + override 20 + blank 20 → **60 changed, 20 unchanged, 0 failed**. Retry did not increase processed count.

## 9. Rollback

Physical: 40-product job, then 8 products edited through `CatalogScopeMutator`. Child rollback job `parent_job_id=7`: 32 inherit restored, 8 skipped `edited_after_job`, status `partially_rolled_back`.

## 10. Host gates

| Gate | Result |
|------|--------|
| composer validate --no-check-publish | valid |
| PHP lint `src/` + plugin root + uninstall | no syntax errors |
| PHPUnit default (Unit+Integration) | **639 tests, 3370 assertions, PASS** (5 deprecations) |
| Vitest | **11 tests, 2 files, PASS** |
| Dedicated `bulk-tools.js` Vitest | **not present** |
| Security suite | **not run** (isolated on `wip/rc6-adversarial-security-audit`) |

## 11. Protected baselines

- RC.6 tag `v1.0.0-rc.6` peeled commit `8f37fe826e23406c9035312e279699b65c1e72e4` **unchanged**
- RC.6 ZIP not rebuilt or retagged in this task
- FLAIROC not modified
- Stage 15 not started

## Remaining limitations

- Owner physical QA is not started.
- No untagged QA ZIP was built.
- `wp plugin delete` was not executed on bind-mounted plugin directories (would wipe source). Uninstall policy was exercised via `Uninstaller::uninstall()`.
- Admin entity screens still paginate at 500 rows; complete export does not use that path.
- 10k selected-id jobs store the ID list on the job row, not in Action Scheduler args.
