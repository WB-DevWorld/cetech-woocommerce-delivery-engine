# Stage 14H — Owner QA package (`1.0.0-rc.6-qa.2`)

**Document status:** Packaging record for owner physical QA of country-scoped region code/label matching  
**QA version:** `1.0.0-rc.6-qa.2`  
**Schema target:** `4` (unchanged; no migration)  
**Branch:** `master`  
**Protected published baseline:** tagged `v1.0.0-rc.5` — **untouched**; do not retag or overwrite  
**Historical QA ZIPs:** RC.5, `1.0.0-rc.5-qa.1` through `qa.6`, and `1.0.0-rc.6-qa.1` remain **immutable**  
**Date:** 2026-08-20  
**FLAIROC:** **NOT MODIFIED**  
**Git tag for this QA identity:** **none** (do not create `v1.0.0-rc.6-qa.2`; do not finalize RC.6)

---

## 1. Verdict

**READY FOR OWNER PHYSICAL QA of `1.0.0-rc.6-qa.2`.**

This is a **second defect-fix QA package after protected RC.5**. It keeps the QA.1 shipping-method registry listing repair and adds Region code/label matching. It is **not** a replacement for tagged `v1.0.0-rc.5`. Cursor did **not** deploy it to FLAIROC.

Built from committed clean `master` (not `-AllowDirty`):

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build-v1-rc-package.ps1 -Version 1.0.0-rc.6-qa.2 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-rc.6-qa.2.zip
```

| Artifact | Path |
|----------|------|
| Dist ZIP | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.6-qa.2.zip` |
| Desktop ZIP | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.6-qa.2.zip` |
| Bytes | *recorded after package build* |
| SHA-256 | *recorded after package build* |
| Sidecar | same hash + `  cetech-woocommerce-delivery-engine-1.0.0-rc.6-qa.2.zip` |
| Source commit | *recorded after package build* |

ZIP root folder: `cetech-woocommerce-delivery-engine/`

Do **not** rebuild this ZIP after the SHA-256 recording commit. The source commit is the version/repair commit, not the later docs-only hash record.

---

## 2. What this QA package contains

- **Kept from QA.1:** Delivery is listed in WooCommerce Add shipping method while the plugin is active; rates stay flag-gated; the method is never auto-inserted into a zone.
- **New:** Delivery Area Region rules match either the WooCommerce canonical state code or that country’s human-readable state label (Ghana `AA` ↔ Greater Accra). Matching is case-insensitive and country-scoped. Unknown codes/labels do not false-match. Country, City, and Postcode matching are unchanged. No Delivery Area data migration.

No schema, Air/Sea, inheritance, pricing, checkout grouping, shipment, tracking, or Stage 15 changes.

---

## 3. Pre-package gates

| Gate | Result |
|------|--------|
| `composer validate --no-check-publish` | valid |
| PHP lint (`src/`, `database/`, root plugin, uninstall) | **330 files, 0 failures** |
| PHPUnit | **581 tests, 3117 assertions, OK** (3 pre-existing `ReflectionMethod::setAccessible()` deprecations) |
| PHPUnit destination region repair | **20 tests, 45 assertions, OK** (`tests/Unit/Destination`) |
| PHPUnit shipping / checkout / boot / schema identity | **57 tests, 306 assertions, OK** |
| `npm run test:js` | **11 passed** |

Playwright: not claimed. QA.1 PHPUnit baseline was 561 / 3072. Delta +20 tests / +45 assertions (region code/label matching).

---

## 4. Extracted-package verification

| # | Check | Result |
|---|--------|--------|
| 1 | One plugin root `cetech-woocommerce-delivery-engine/` | *recorded after extract* |
| 2 | Version identity `1.0.0-rc.6-qa.2` (header + `CETECH_DE_VERSION`) | *recorded after extract* |
| 3 | `SchemaVersion::TARGET` = `4` | *recorded after extract* |
| 4 | `vendor/autoload.php` works; production autoload verifier | *recorded after extract* |
| 5 | Linux/forward-slash ZIP paths; PSR-4 casing | *recorded after extract* |
| 6 | Runtime files: root PHP, `src/`, `database/`, uninstall | *recorded after extract* |
| 7 | Dev artifacts excluded: tests, `.git`, phpunit.xml, node_modules, `vendor/phpunit` | *recorded after extract* |
| 8 | Registry repair still present (`register_shipping_method()` not gated) | *recorded after extract* |
| 9 | Region code/label matcher present | *recorded after extract* |
| 10 | SHA-256 sidecar next to ZIP | *recorded after extract* |
| 11 | Packaged PHP lint (non-vendor) | *recorded after extract* |

---

## 5. Short owner physical test checklist

Install this ZIP on a **clean folder** (not FLAIROC unless the owner later authorises a separate deploy). Do not overwrite tagged RC.5, RC.5 QA ZIPs, or `1.0.0-rc.6-qa.1`.

1. After activate, WordPress shows plugin version **`1.0.0-rc.6-qa.2`**. Schema remains **4**.
2. QA.1 still holds: **Add shipping method** lists **Delivery** before Activate Delivery Engine.
3. Delivery Area for Accra remains stored as Country=`GH`, Region=`Greater Accra` (or `AA`), City=`Accra`. Do **not** rewrite existing area rows.
4. Checkout / cart destination **Ghana + Greater Accra (`AA`) + Accra** resolves to the Accra area and quotes the configured charge (not fallback / not $0).
5. Delivery Areas address tester with `GH` / `Greater Accra` / `Accra` and with `GH` / `AA` / `Accra` matches the same Accra area as checkout.
6. A region label from another country must not steal the Ghana Accra match. An unknown region must not false-match Accra.

Do **not** finalize RC.6. Do **not** begin Stage 15. Do **not** retag `v1.0.0-rc.5`.

---

## STOP

This task stops after the immutable `1.0.0-rc.6-qa.2` owner-QA package and this checklist. FLAIROC was not modified.
