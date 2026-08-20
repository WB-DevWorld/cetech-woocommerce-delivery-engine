# Stage 14H-RC6-FINAL — RC.6 finalization

**Document status:** Final post-RC.5 defect-fix release record  
**Release version:** `1.0.0-rc.6`  
**Schema target:** `4` (unchanged; no migration)  
**Branch:** `master`  
**Accepted functional baseline:** owner-physical PASS of `1.0.0-rc.6-qa.2` on training.cetechbpa.com  
**RC.5 tag:** `v1.0.0-rc.5` — **untouched**  
**RC.6 QA.1 / QA.2 packages:** **immutable**  
**Date:** 2026-08-20  
**FLAIROC:** **NOT MODIFIED**

---

## 1. Verdict

**STAGE 14H-RC6-FINAL COMPLETE — `1.0.0-rc.6` PREPARED**

Owner physical QA of **`1.0.0-rc.6-qa.2`** on training.cetechbpa.com is **PASS**. This stage promotes that accepted runtime to the final RC.6 identity, documentation, package, and tag.

This is **not** Stage 15. FLAIROC was not modified from Cursor.

---

## 2. Purpose

Finalize the owner-accepted post-RC.5 portability repairs as the tagged release candidate:

`1.0.0-rc.6`

No new functionality beyond the two confirmed repairs. No redesign. No Checkout Blocks, carrier APIs, tracking automation, driver workflows, bulk shipping, POD, or other future scope.

---

## 3. Owner acceptance baseline (authoritative)

Live version during owner QA: **`1.0.0-rc.6-qa.2`**  
Site: **training.cetechbpa.com**  
Schema: **4**

Owner verdict: **QA.2 PASS**.

| Check | Result |
|-------|--------|
| Exact QA.2 package installed and active | PASS |
| Plugin version `1.0.0-rc.6-qa.2` | PASS |
| Schema remains 4 | PASS |
| Accra rule remained Country=`GH`, Region=`Greater Accra`, City=`Accra` (no migration/rewrite) | PASS |
| `GH` + `AA` + Accra resolves to Accra Delivery Area | PASS |
| `GH` + Greater Accra + Accra resolves to the same Accra Delivery Area | PASS |
| Direct quote Standard Delivery + Accra = GHS 40 from rate card 1 | PASS |
| Real WooCommerce checkout shows Standard Delivery ₵40.00 and total ₵245.00 for the ₵205 test product | PASS |
| Kumasi checkout shows configured ₵60.00 Standard Delivery and total ₵265.00 | PASS |
| Delivery remains the genuine WooCommerce shipping method | PASS |
| Runtime flags remain enabled | PASS |
| No accidental zero-price shipping | PASS |
| Homepage and REST healthy | PASS |
| No new PHP errors after QA.2 install | PASS |
| QA.1 shipping-method registry repair remains intact | PASS |

### Site-only items (not in this plugin package)

- The large “Outside Greater Accra Region” checkout block was an obsolete WoodMart child-theme override and was removed separately from the plugin.
- Training-site WooCommerce Ghana zone is intended to use only the Delivery shipping method (not Flat Rate alongside it).
- Optional remaining admin cleanup: deactivate/remove the unused Delivery Area named Ghana with 0 options / No pricing set, now that Whole Ghana has a configured charge. **Not a code blocker.**

---

## 4. What RC.6 finalizes

| Area | Outcome |
|------|---------|
| Shipping-method registry listing | Delivery is listed in WooCommerce Add shipping method whenever WooCommerce and the plugin are active, independent of storefront runtime flags. Rates stay flag-gated. The method is never auto-inserted into a zone. |
| Region code/label matching | Delivery Area Region rules match the WooCommerce canonical state code or that country’s human-readable label (Ghana `AA` ↔ Greater Accra), case-insensitively, without migrating stored area data. |
| Version identity | `1.0.0-rc.6` (plugin header, `CETECH_DE_VERSION`, readme Stable tag) |
| Schema | Target remains **4** |
| Flags | Stage 14 flags still default **OFF** in a fresh install |

Inherited unchanged from RC.5 / QA.2 runtime: Stage 14 shipments V1, genuine WooCommerce shipping, immutable snapshots, Air/Sea split, pickup suppression, privacy boundaries, inheritance, grouping.

---

## 5. Source gates (final)

Filled after the qualification run in this stage.

| Gate | Result |
|------|--------|
| `composer validate --no-check-publish` | valid |
| Production PHP lint (`src/`, `database/`, root plugin, uninstall) | **330 files, 0 failures** |
| PHPUnit | **581 tests, 3117 assertions, OK** |
| Deprecations | **3** (`ReflectionMethod::setAccessible()` PHP 8.5) — same type as QA.2; no new type |
| Destination / shipping / checkout / schema identity | **63 tests, 226 assertions, OK** |
| `npm run test:js` | **11 passed** |
| Playwright | **not run** |

QA.2 baseline: 581 tests / 3117 assertions; 330 PHP files / 0 lint failures; 11 JS passed; 3 PHP 8.5 `ReflectionMethod::setAccessible()` deprecations. Final source matches that baseline plus version identity. No runtime regression.

---

## 6. Package identity

| Item | Value |
|------|--------|
| ZIP | `cetech-woocommerce-delivery-engine-1.0.0-rc.6.zip` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.6.zip` |
| Desktop path | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.6.zip` |
| Root folder | `cetech-woocommerce-delivery-engine/` |
| Schema target | `4` |
| Tag | `v1.0.0-rc.6` (created only after package verification) |
| Built with | `scripts/build-v1-rc-package.ps1` (**no** `-AllowDirty`) |

### Completion table (post-build)

| Item | Value |
|------|--------|
| Product finalize commit (package source) | `7e52525` (`7e52525cc126f0c4c1841c9bf4b3d7ea9b0bb03f`) |
| Tag `v1.0.0-rc.6` | annotated tag created after package verification |
| ZIP bytes | `1059918` |
| SHA-256 | `0d4adbef50462d798a4ff9bf802643bed92a35cdd332ceee13a985dbda2a689d` |
| Sidecar | same hash + `  cetech-woocommerce-delivery-engine-1.0.0-rc.6.zip` |
| Built from | committed clean `master` at `7e52525` (not `-AllowDirty`) |

Do **not** rebuild the ZIP after the SHA-256 recording commit. The source commit is `7e52525`, not the later docs-only hash record.

---

## 7. Extracted package verification

| Check | Result |
|-------|--------|
| One plugin root | PASS |
| Version `1.0.0-rc.6` | PASS |
| Schema target `4` | PASS |
| Production autoload / Linux-case / forward-slash ZIP paths | PASS (0 backslash entries; verifier exit 0) |
| Packaged PHP lint | **333 files, 0 failures** |
| No PHPUnit / tests / node_modules / `.git` | PASS |
| Registry repair present | PASS |
| Region code/label matcher present | PASS |
| QA.1 / QA.2 / RC.5 ZIPs unchanged | PASS (`1049447` / `1056340` / `1043995` bytes) |

---

## 8. Deliberately not in RC.6

- Checkout Blocks
- carrier APIs / live quotes / automatic tracking synchronisation
- shipment emails
- customer timeline
- driver accounts, GPS, OTP, QR, POD, signatures
- bulk shipping / bulk import
- Stage 15 or later roadmap work
- FLAIROC deploy from Cursor
- WoodMart child-theme overrides (site-only)
- Training-site Delivery Area cleanup (admin UI only)

---

## 9. Historical artifacts left untouched

- Tag `v1.0.0-rc.5` and ZIP `cetech-woocommerce-delivery-engine-1.0.0-rc.5.zip`
- `cetech-woocommerce-delivery-engine-1.0.0-rc.6-qa.1.zip`
- `cetech-woocommerce-delivery-engine-1.0.0-rc.6-qa.2.zip`
- RC.5 QA.1–QA.6 ZIPs

---

## 10. Next step

**STOP.** Do not begin Stage 15, Checkout Blocks, carrier integrations, automated tracking, drivers, or POD. Do not modify FLAIROC from Cursor.
