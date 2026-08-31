# RC.8 finalization

**Document status:** Final post-RC.7 Fulfilment Correctness release record  
**Release version:** `1.0.0-rc.8`  
**Schema target:** `5` (unchanged from accepted Fulfilment.4; no migration)  
**Branch:** `feat/post-rc7-fulfilment-correctness`  
**Accepted functional baseline:** owner-physical PASS of `1.0.0-dev.fulfilment.4` on training.cetechbpa.com  
**RC.7 tag:** `v1.0.0-rc.7` / `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6` — **untouched**  
**Historical fulfilment QA ZIPs / Bulk ZIPs:** **immutable**  
**Date:** 2026-08-31  
**FLAIROC:** **NOT MODIFIED**

---

## 1. Verdict

**RC.8 COMPLETE — `1.0.0-rc.8` PREPARED**

Owner physical QA of **`1.0.0-dev.fulfilment.4`** on training.cetechbpa.com is **PASS** (all three Fulfilment Correctness scenarios). This stage promotes that accepted runtime to the final RC.8 identity, documentation, package, and tag.

This is **not** Stage 15. No runtime/business-logic change during promotion. No schema 6. FLAIROC was not modified from Cursor.

---

## 2. Purpose

Freeze the physically accepted Fulfilment.4 runtime as:

`1.0.0-rc.8`

Identity/finalization only. No In Store redesign, International expansion, Return/Refund work, item-location architecture, Checkout Blocks, carriers, Bulk work, or unrelated cleanup.

---

## 3. Owner acceptance baseline (authoritative)

Live version during owner QA: **`1.0.0-dev.fulfilment.4`**  
Site: **training.cetechbpa.com**  
Schema: **5**  
Date: **2026-08-31**

Owner verdict: **ALL THREE FULFILMENT CORRECTNESS SCENARIOS PASSED**

| Scenario | Result |
|----------|--------|
| 1 — In Store | **PASS** — Delivery + Store Pickup both enabled; default Delivery; ECR Pickup Location; human-readable pickup address; zero pickup charge; mixed-cart grouping; Delivery group alone receives GHS 50 |
| 2 — International | **PASS** — International classification; Delivery only; Air offer auto-selected; no Standard Delivery / Store Pickup; mis-routed local option does not leak |
| 3 — In Warehouse | **PASS** — Standard/local Delivery only; Standard Delivery selected; no Store Pickup; no Air/Sea |

QA package record: `docs/POST-RC7-FULFILMENT-CORRECTNESS-QA.md`.

### Known non-blocking items (frozen, not repaired)

- International and In Warehouse product-admin override UI may still offer Store Pickup as a selectable different default customer choice. Server/ECR hard constraints prevent the invalid choice from becoming customer-facing.
- Mixed Delivery + Pickup cart may suppress the Delivery package's cart-level Change address link after pickup-package presentation filtering. Delivery destination/rating itself remains correct.
- Training catalog contains legacy/misleading Delivery Option names such as an Air Shipping label whose stored route is actually `local_delivery`. Catalog cleanup, not resolver behaviour.

---

## 4. What RC.8 finalizes

| Area | Outcome |
|------|---------|
| Version identity | `1.0.0-rc.8` (plugin header, `CETECH_DE_VERSION`, readme Stable tag) |
| Schema | Target remains **5** (no RC.8 migration) |
| Runtime | Exact Fulfilment.4 functionality as physically accepted |
| Flags | Stage 14 flags still default **OFF** in a fresh install |

Inherited unchanged from accepted Fulfilment.4: In Store concurrent Delivery + Store Pickup, ECR Pickup Location, Pickup Locations R1, human-readable pickup address, pickup package presentation (`Pickup at {location}`), zero pickup charge, International single-offer auto-select, In Warehouse local-only constraints, Bulk Tools + R1 from RC.7.

---

## 5. Source gates (final)

Run on the RC.8 identity source immediately before packaging:

| Gate | Result |
|------|--------|
| `composer validate --no-check-publish` | **valid** |
| Production PHP lint (`src/`, `database/`, root plugin, uninstall) | **389 files, 0 failures** |
| PHPUnit | **783 tests, 4545 assertions, OK** (5 pre-existing deprecations) |
| Fulfilment-focused PHPUnit | **53 tests, 290 assertions, OK** |
| `npm run test:js` | **28 passed / 28** |
| Identity / schema | `CETECH_DE_VERSION` = `1.0.0-rc.8`; `SchemaVersion::TARGET` = `5`; not `1.0.0-dev.fulfilment.4`; not `1.0.0-rc.7` |
| `scripts/verify-production-package-autoload.php` | **PASS** on staged package and extracted ZIP |
| Playwright / FLAIROC | **not run** |

Accepted Fulfilment.4 baseline: PHPUnit **783 tests / 4544 assertions**; JS **28 / 28**; changed PHP lint **0 errors**. RC.8 remains green. The extra PHPUnit assertion is the identity check that the plugin is no longer `1.0.0-dev.fulfilment.4`. No assertions were weakened.

---

## 6. Package identity

| Item | Value |
|------|--------|
| ZIP | `cetech-woocommerce-delivery-engine-1.0.0-rc.8.zip` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.8.zip` |
| Desktop path | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.8.zip` |
| Root folder | `cetech-woocommerce-delivery-engine/` |
| Schema target | `5` |
| Tag | `v1.0.0-rc.8` (created only after package verification; local only; not pushed) |
| Built with | `scripts/build-v1-rc-package.ps1 -Version 1.0.0-rc.8 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-rc.8.zip` (**no** `-AllowDirty`) |

### Completion table (post-build)

| Item | Value |
|------|--------|
| Product finalize commit (package source) | `6d166227998d4b0f5047fea91944ff024b389810` |
| Tag `v1.0.0-rc.8` | local annotated tag `2c211eb5b8a3a6af23e67b4d254a6c19c4e4b8ae` peeling to package-source `6d166227998d4b0f5047fea91944ff024b389810` (**not pushed**) |
| ZIP bytes | `1307532` |
| SHA-256 | `70ae635e71741663d5084e3cccd0d2246871d7c485efb919310267d7c9d46b03` |
| Built from | committed clean `feat/post-rc7-fulfilment-correctness` (not `-AllowDirty`) |

Do **not** rebuild the ZIP after the SHA-256 recording commit. The source commit is the tagged RC.8 identity commit, not the later docs-only hash record.

---

## 7. Extracted package verification

| Check | Result |
|-------|--------|
| One plugin root | **PASS** — `cetech-woocommerce-delivery-engine/` |
| Version `1.0.0-rc.8` | **PASS** |
| Schema target `5` | **PASS** |
| Production autoload / Linux-case classmap | **PASS** (`scripts/verify-production-package-autoload.php`) |
| Bulk/R1 runtime present | **PASS** — `assets/admin/bulk-tools.js`, `WpActionSchedulerGateway.php` |
| Accepted Fulfilment Correctness runtime present | **PASS** — `CartFulfilmentPackagePresentation.php`, `PickupLocationAddressFormatter.php`, `InStoreMethodSelection.php` |
| Packaged PHP lint | **391 files, 0 failures** (excluding vendor) |
| No PHPUnit / tests / node_modules / `.git` / `.env` / nested ZIPs | **PASS** |
| Historical Bulk / fulfilment QA / RC.7 ZIPs unchanged | **PASS** — Fulfilment.4 Desktop `1303884` bytes unchanged; RC.7 Desktop present |

---

## 8. Deliberately not in RC.8

- Runtime/business-logic changes during promotion
- schema 6
- Admin UI restriction of Store Pickup on International / In Warehouse (known non-blocking)
- Mixed-cart Delivery `Change address` presentation (known non-blocking)
- Training catalog cleanup of mis-routed Air Shipping labels
- per-item location architecture
- Return/Refund work
- Checkout Blocks
- carrier APIs / live quotes / automatic tracking synchronisation
- Stage 15 or later roadmap work
- FLAIROC deploy from Cursor

---

## 9. Historical artifacts left untouched

- Tag `v1.0.0-rc.7` and ZIP `cetech-woocommerce-delivery-engine-1.0.0-rc.7.zip`
- Tag `v1.0.0-rc.6` and ZIP `cetech-woocommerce-delivery-engine-1.0.0-rc.6.zip`
- `cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.1.zip`–`fulfilment.4.zip`
- `cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.7.zip`–`bulk.9.zip`

---

## 10. Owner confirmation after RC.8 ZIP (minimal only)

Because RC.8 is the same runtime already physically accepted as Fulfilment.4, do **not** repeat the three fulfilment scenarios unless the package differs in runtime code or this smoke fails:

1. Install/replace Fulfilment.4 with the RC.8 ZIP on training.cetechbpa.com.
2. Confirm plugin is Active.
3. Confirm version = `1.0.0-rc.8`.
4. Confirm schema = `5`.
5. Open one PDP and one Delivery Engine admin page to confirm the packaged runtime loads normally.

Cursor must not deploy the ZIP.

---

## 11. Next step

**STOP.** Do not begin Stage 15, Checkout Blocks, carrier integrations, per-item location architecture, or Return/Refund work. Do not modify FLAIROC from Cursor. Do not retag `v1.0.0-rc.7` or earlier.
