# Stage 14H — Owner QA package (`1.0.0-rc.6-qa.1`)

**Document status:** Packaging record for owner physical QA of the shipping-method registry listing repair  
**QA version:** `1.0.0-rc.6-qa.1`  
**Schema target:** `4` (unchanged; no migration)  
**Branch:** `master`  
**Protected published baseline:** tagged `v1.0.0-rc.5` — **untouched**; do not retag or overwrite  
**Historical QA ZIPs:** RC.5 and `1.0.0-rc.5-qa.1` through `qa.6` remain **immutable**  
**Date:** 2026-08-20  
**FLAIROC:** **NOT MODIFIED**  
**Git tag for this QA identity:** **none** (do not create `v1.0.0-rc.6-qa.1`)

---

## 1. Verdict

**READY FOR OWNER PHYSICAL QA of `1.0.0-rc.6-qa.1`.**

This is a **defect-fix QA package after protected RC.5**. It is **not** a replacement for tagged `v1.0.0-rc.5`. Cursor did **not** deploy it to FLAIROC.

Built from committed clean `master` (not `-AllowDirty`):

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build-v1-rc-package.ps1 -Version 1.0.0-rc.6-qa.1 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-rc.6-qa.1.zip
```

| Artifact | Path |
|----------|------|
| Dist ZIP | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.6-qa.1.zip` |
| Desktop ZIP | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.6-qa.1.zip` |
| Bytes | `1049447` |
| SHA-256 | `d5dbc392bf6e170e411ac19ce9bb4a14e6ef5458d55583ed56b4e07fe9e2d3ff` |
| Sidecar | same hash + `  cetech-woocommerce-delivery-engine-1.0.0-rc.6-qa.1.zip` |
| Source commit | `116f67d` (`116f67d88c108fe2ce51bf1cbde7af405fc6445d`) |

ZIP root folder: `cetech-woocommerce-delivery-engine/`

Do **not** rebuild this ZIP after the SHA-256 recording commit. The source commit is the version/repair commit `116f67d`, not the later docs-only hash record.

---

## 2. What this QA package contains

Post-RC.5 shipping-method registry listing repair only:

- `SelectedOfferShippingMethod` is registered on `woocommerce_shipping_methods` whenever WooCommerce and the Delivery Engine are active, including before **Activate Delivery Engine**.
- Rate calculation and managed-package exclusivity remain behind `ShippingRateCalculationGate`.
- The method is never auto-inserted into a shipping zone.
- Setup/test wording: add **Delivery** only to the WooCommerce zones where Delivery Engine shipping should operate. **Rest of the World** (Locations not covered by your other zones) is optional unless leftover addresses are intentionally supported.

No schema, Air/Sea, inheritance, pricing, checkout grouping, shipment, tracking, or Stage 15 changes.

---

## 3. Pre-package gates

| Gate | Result |
|------|--------|
| `composer validate --no-check-publish` | valid |
| PHP lint (`src/`, `database/`, root plugin, uninstall) | **327 files, 0 failures** |
| PHPUnit | **561 tests, 3072 assertions, OK** (3 pre-existing `ReflectionMethod::setAccessible()` deprecations) |
| PHPUnit shipping / checkout / schema identity | **53 tests, 233 assertions, OK** (`tests/Unit/Shipping`, `VariableCartLifecycleTest`, `ClassicCheckoutRuntimeActivationTest`, `SchemaV4InspectionTest`, `Stage13FCustomerPresentationTest`) |
| `npm run test:js` | **11 passed** |

Playwright: not claimed. QA.6 baseline was 555 / 3050. Delta +6 tests / +22 assertions (registry listing coverage plus version identity).

---

## 4. Extracted-package verification

| # | Check | Result |
|---|--------|--------|
| 1 | One plugin root `cetech-woocommerce-delivery-engine/` | PASS |
| 2 | Version identity `1.0.0-rc.6-qa.1` (header + `CETECH_DE_VERSION`) | PASS |
| 3 | `SchemaVersion::TARGET` = `4` | PASS |
| 4 | `vendor/autoload.php` works; production autoload verifier | PASS (exit 0; schema target 4) |
| 5 | Linux/forward-slash ZIP paths; PSR-4 casing | PASS (0 backslash entries; verifier Linux-case classmap) |
| 6 | Runtime files: root PHP, `src/`, `database/`, uninstall | PASS |
| 7 | Dev artifacts excluded: tests, `.git`, phpunit.xml, node_modules, `vendor/phpunit` | PASS |
| 8 | Registry repair present: `register_shipping_method()` does not early-return on `is_runtime_active()`; rate filter remains gated | PASS |
| 9 | SHA-256 sidecar next to ZIP | PASS |
| 10 | Packaged PHP lint (non-vendor) | **330 files, 0 failures** |

---

## 5. Short owner physical test checklist

Install this ZIP on a **clean folder** (not FLAIROC unless the owner later authorises a separate deploy). Do not overwrite tagged RC.5 or historical QA ZIPs.

1. After activate, WordPress shows plugin version **`1.0.0-rc.6-qa.1`**. Schema remains **4**. Runtime flags default **off**.
2. WooCommerce → Settings → Shipping → open a zone → **Add shipping method** lists **Delivery** *before* **Activate Delivery Engine**.
3. Add **Delivery** only to the zones where this plugin should operate. Skip **Rest of the World** / **Locations not covered by your other zones** unless leftover addresses should use this plugin.
4. With flags still off: checkout does **not** show a Delivery Engine fee; native WooCommerce methods on that zone are not taken over.
5. After **Activate Delivery Engine** (and matching Delivery Areas / Charges): checkout shows the quoted Delivery fee; missing configuration must **not** become free/$0 shipping.
6. Confirm the method was **not** auto-inserted into unused zones.

Do **not** begin Stage 15. Do **not** retag `v1.0.0-rc.5`.

---

## STOP

This task stops after the immutable `1.0.0-rc.6-qa.1` owner-QA package and this checklist. FLAIROC was not modified.
