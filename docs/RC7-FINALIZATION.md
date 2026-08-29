# RC.7 finalization

**Document status:** Final post-RC.6 Bulk Tools + R1 release record  
**Release version:** `1.0.0-rc.7`  
**Schema target:** `5` (unchanged from accepted Bulk.9; no migration)  
**Branch:** `integration/post-rc6-bulk-r1`  
**Accepted functional baseline:** owner-physical PASS of `1.0.0-dev.bulk.9` on training.cetechbpa.com  
**RC.6 tag:** `v1.0.0-rc.6` — **untouched**  
**Historical Bulk.7 / Bulk.8 / Bulk.9 ZIPs:** **immutable**  
**Date:** 2026-08-29  
**FLAIROC:** **NOT MODIFIED**

---

## 1. Verdict

**RC.7 COMPLETE — `1.0.0-rc.7` PREPARED**

Owner physical QA of **`1.0.0-dev.bulk.9`** on training.cetechbpa.com is **PASS** (all six acceptance checks). This stage promotes that accepted runtime to the final RC.7 identity, documentation, package, and tag.

This is **not** Stage 15. No new Bulk.10 features. No schema 6. FLAIROC was not modified from Cursor.

---

## 2. Purpose

Freeze the physically accepted Bulk.9 + R1 runtime as:

`1.0.0-rc.7`

No new functionality. No Catalog preview presentation repair. No In Store redesign, International ECR redesign, Return/Refund work, item-location architecture, Checkout Blocks, or carrier/tracking integrations.

---

## 3. Owner acceptance baseline (authoritative)

Live version during owner QA: **`1.0.0-dev.bulk.9`**  
Site: **training.cetechbpa.com**  
Schema: **5**  
Date: **2026-08-29**

Owner verdict: **OWNER PHYSICAL QA: ALL SIX ACCEPTANCE CHECKS PASSED**

| Check | Result |
|-------|--------|
| Catalog preview → Apply → rollback | PASS |
| Multi-batch/background processing | PASS |
| Validation Scan | PASS |
| Remove-last-valid-option safety gate | PASS |
| Delivery Charge GHS 50 → 55 → rollback to 50 | PASS |
| R1 admin smoke — blank Reference Code, WooCommerce country picker, single clear Create/Save | PASS |

Record: `docs/POST-RC6-BULK9-OWNER-QA.md`.

### Known non-blocking presentation issue (frozen, not repaired)

A Catalog preview where every target fails proposed-state validation correctly reports **Would fail**, but the page may still display **Ready to apply** and an **Apply these changes** button. The unsafe configuration itself is rejected by the resolver/safety gate. This is a UI/presentation issue, not an accepted proof that invalid configuration can be applied.

---

## 4. What RC.7 finalizes

| Area | Outcome |
|------|---------|
| Version identity | `1.0.0-rc.7` (plugin header, `CETECH_DE_VERSION`, readme Stable tag) |
| Schema | Target remains **5** (no RC.7 migration) |
| Runtime | Exact Bulk.9 + R1 functionality as physically accepted |
| Flags | Stage 14 flags still default **OFF** in a fresh install |

Inherited unchanged from accepted Bulk.9: Bulk Job Engine, Catalog/Rate Card bulk operations, validation scan, last-valid-option safety gate, background Action Scheduler portability, R1 admin/setup repairs, schema 5 tables.

---

## 5. Source gates (final)

Run on the RC.7 identity source immediately before packaging:

| Gate | Result |
|------|--------|
| `composer validate --no-check-publish` | valid |
| Production PHP lint (`src/`, `database/`, root plugin, uninstall) | **386 files, 0 failures** |
| PHPUnit | **745 tests, 4224 assertions, OK** (5 pre-existing deprecations) |
| `npm run test:js` | **24 passed / 24** |
| Identity / schema | `CETECH_DE_VERSION` = `1.0.0-rc.7`; `SchemaVersion::TARGET` = `5`; not `1.0.0-dev.bulk.9`; not `1.0.0-rc.6` |
| `scripts/verify-production-package-autoload.php` | lint clean; schema-5 + Bulk Tools checks apply to `1.0.0-rc.7` |
| Playwright / FLAIROC | **not run** |

Accepted Bulk.9 baseline: PHPUnit **745 tests / 4223 assertions**; JS **24 / 24**; changed PHP lint **0 errors**. RC.7 remains green. The extra PHPUnit assertion is the identity check that the plugin is no longer `1.0.0-dev.bulk.9`. No assertions were weakened.

---

## 6. Package identity

| Item | Value |
|------|--------|
| ZIP | `cetech-woocommerce-delivery-engine-1.0.0-rc.7.zip` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.7.zip` |
| Desktop path | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.7.zip` |
| Root folder | `cetech-woocommerce-delivery-engine/` |
| Schema target | `5` |
| Tag | `v1.0.0-rc.7` (created only after package verification) |
| Built with | `scripts/build-v1-rc-package.ps1 -Version 1.0.0-rc.7 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-rc.7.zip` (**no** `-AllowDirty`) |

### Completion table (post-build)

| Item | Value |
|------|--------|
| Product finalize commit (package source) | `ad3feeb` (`ad3feebfd1aa92d078caaa557c0c0d11c090a0c6`) |
| Tag `v1.0.0-rc.7` | annotated `8cba2e877d949c83fd18a0d67b4831d2012d47cd`; peeled `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6` |
| ZIP bytes | `1262689` |
| SHA-256 | `1881c0d89e288a14436e14a26f1ae359504a06cf2a77ef32b07f48e32797dbff` |
| Sidecar | same hash + `  cetech-woocommerce-delivery-engine-1.0.0-rc.7.zip` |
| Built from | committed clean `integration/post-rc6-bulk-r1` at `ad3feeb` (not `-AllowDirty`) |

Do **not** rebuild the ZIP after the SHA-256 recording commit. The source commit is `ad3feeb`, not the later docs-only hash record.

---

## 7. Extracted package verification

Extracted outside the repository to `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-rc7-verify-extract\`.

| Check | Result |
|-------|--------|
| One plugin root `cetech-woocommerce-delivery-engine/` | PASS |
| Version `1.0.0-rc.7` | PASS |
| `SchemaVersion::TARGET = 5` | PASS |
| Production `vendor/autoload.php` + Linux-case classmap | PASS (verifier exit 0) |
| R1 runtime (`WooCommerceCountryCatalog`, header `form=` Create/Save) | PASS |
| Bulk Tools runtime (`BulkJobEngine`, `CatalogScopeMutator::scan()`, `RateCardBulkMutator`, `advance=1` JS, `WpActionSchedulerGateway`) | PASS |
| Packaged PHP lint excluding vendor | **388 files / 0 failures** |
| No `tests/`, no `phpunit.xml`, no `node_modules`, no `.git`, no `.env`, no `vendor/phpunit`, no nested ZIPs, no secrets | PASS |
| Historical Bulk.7 / Bulk.8 / Bulk.9 / RC.6 ZIPs unchanged | PASS (`1226695` / `1240853` / `1256253` / `1059918` bytes; SHA-256 unchanged) |
| Tag `v1.0.0-rc.6` object unchanged | PASS (`1d3252199f45371ec4229808155f6017ee3da9ea`) |
| FLAIROC | **untouched** |
| No new feature stream started | PASS |

---

## 8. Deliberately not in RC.7

- Bulk.10 or any new Bulk feature
- schema 6
- Catalog preview Ready-to-apply presentation repair
- In Store redesign
- International ECR redesign
- Return/Refund work
- item-location architecture
- Checkout Blocks
- carrier APIs / live quotes / automatic tracking synchronisation
- Stage 15 or later roadmap work
- FLAIROC deploy from Cursor

---

## 9. Historical artifacts left untouched

- Tag `v1.0.0-rc.6` and ZIP `cetech-woocommerce-delivery-engine-1.0.0-rc.6.zip`
- `cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.7.zip`
- `cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.8.zip`
- `cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.9.zip`

---

## 10. Owner confirmation after RC.7 ZIP (minimal only)

Because RC.7 is the same runtime already physically accepted as Bulk.9, do **not** repeat the six-check matrix unless the package differs in runtime code or this smoke fails:

1. Install/replace Bulk.9 with the RC.7 ZIP on training.cetechbpa.com.
2. Confirm plugin is Active.
3. Confirm version = `1.0.0-rc.7`.
4. Confirm schema = `5`.
5. Open Bulk Tools and one Delivery Engine admin screen to confirm the packaged runtime loads normally.

Cursor must not deploy the ZIP.

---

## 11. Next step

**STOP.** Do not begin Stage 15, Checkout Blocks, carrier integrations, In Store redesign, International ECR redesign, Return/Refund work, or item-location architecture. Do not modify FLAIROC from Cursor.
