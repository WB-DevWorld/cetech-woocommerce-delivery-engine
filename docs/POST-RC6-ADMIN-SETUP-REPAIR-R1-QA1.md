# POST-RC.6 Admin Setup Repair R1 — QA.1

**Document status:** Packaging record for owner physical QA on **training.cetechbpa.com**  
**QA identity:** `1.0.0-rc.6-r1-qa.1`  
**Owner physical QA:** **NOT RUN by Cursor.** Do not claim browser PASS.  
**Schema target:** `4` (unchanged; no schema 5)  
**Branch:** `fix/post-rc6-admin-setup-defects`  
**Protected published baseline:** tagged `1.0.0-rc.6` / `v1.0.0-rc.6` — **untouched**  
**This is not:** RC.7, Stage 15, or a replacement for tagged RC.6  
**Date:** 2026-08-22  
**FLAIROC:** **NOT MODIFIED**  
**Bulk Tools (`feat/post-rc6-bulk-tools`):** **NOT MODIFIED**  
**Git tag for this QA identity:** **none** (do not create `v1.0.0-rc.6-r1-qa.1`; do not retag `v1.0.0-rc.6`)

---

## 1. Purpose

Prepare a **training-site physical QA package** for owner-approved Repair R1 (tester-audit items 1–4 only).

Cursor did **not** deploy this ZIP. The package is for **training.cetechbpa.com only**.

ZIP source is the identified committed identity commit below, **not** this later docs-only checksum record.

---

## 2. Exact R1 scope

Implemented and packaged **only**:

1. Reference Code generate-before-validation (Delivery Options + Delivery Areas)
2. Validation-error form recovery / visible primary Create/Save
3. Responsive Fulfilment Availability / profile card wrapping
4. WooCommerce country-name picker for Delivery Area Country rules

Implementation record: `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1.md`  
Audit: `docs/POST-RC6-TESTER-OBSERVATIONS-AUDIT.md`

### Explicit exclusions (not in this package)

- In Store fulfilment semantics / Delivery + Store Pickup dual-choice
- Store Pickup runtime changes
- International ECR / product-page destination behaviour
- Browsing location
- Per-item destination
- Multiple shipping addresses
- Return Policy / Refund Policy
- Bulk Tools
- Schema 5
- FLAIROC
- Moving or retagging `v1.0.0-rc.6`
- Reuse of `1.0.0-rc.6-qa.1` or `1.0.0-rc.6-qa.2` identities

Do **not** use this R1 QA to judge or fix In Store XOR or the International training-product report. Those remain separate findings.

---

## 3. Version identity

| Item | Value |
|------|--------|
| Plugin header / `CETECH_DE_VERSION` | `1.0.0-rc.6-r1-qa.1` |
| Schema `SchemaVersion::TARGET` | `4` |
| Branch | `fix/post-rc6-admin-setup-defects` |
| R1 implementation commit | `79b751abdebf588295d470462ba29721bc4a7562` |
| QA identity / ZIP source commit | `41ca3e0afd246636837a2b82e0ac23775e6f2a84` |
| Protected tag `v1.0.0-rc.6` | annotated `1d3252199f45371ec4229808155f6017ee3da9ea`; peeled `8f37fe826e23406c9035312e279699b65c1e72e4` — **untouched** |

Build command (clean tree; not `-AllowDirty`):

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build-v1-rc-package.ps1 -Version 1.0.0-rc.6-r1-qa.1 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-rc.6-r1-qa.1.zip
```

---

## 4. ZIP identity

| Artifact | Path |
|----------|------|
| Dist ZIP | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.6-r1-qa.1.zip` |
| Desktop copy | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.6-r1-qa.1.zip` |
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-rc.6-r1-qa.1.zip` |
| Bytes | `1081009` |
| SHA-256 | `6eb0935c1046d4bd65ea017965970b7c0e4784a55d2db2758eab2be3841b5029` |
| Sidecar | same hash + `  cetech-woocommerce-delivery-engine-1.0.0-rc.6-r1-qa.1.zip` |
| Source commit | `41ca3e0` (`41ca3e0afd246636837a2b82e0ac23775e6f2a84`) |
| Schema | `4` |
| Plugin version | `1.0.0-rc.6-r1-qa.1` |

ZIP root folder: `cetech-woocommerce-delivery-engine/`

Do **not** rebuild this ZIP after the SHA-256 recording commit. Do **not** overwrite historical `1.0.0-rc.6`, `1.0.0-rc.6-qa.1`, or `1.0.0-rc.6-qa.2` ZIPs.

---

## 5. Automated results

Re-run against committed identity tree `41ca3e0` (PHP 8.5.0, PHPUnit 10.5.64) **before** this docs-only checksum commit. No runtime files changed after that run.

| Gate | Result |
|------|--------|
| R1 PHPUnit (`PostRc6AdminSetupRepairR1Test` + `WooCommerceCountryCatalogTest`) | **14 tests, 82 assertions, OK** |
| Schema identity (`SchemaV4InspectionTest`) | **6 tests, 75 assertions, OK** |
| Proportional regression (site-wide / ECR parity / hard fulfilment / admin UX / access / shipping registration / schema 4 migration / shipments / rate-card dates / destination matcher / admin language) | **132 tests, 933 assertions, OK** |
| ECR/shipment subset (`SimpleProductNonRegressionTest` / `VariableEcrRuntimeTest` / `StaffShipmentsWorkspaceTest` / `ShipmentStatusWorkflowTest`) | **48 tests, 285 assertions, OK** |
| PHP lint of R1-changed PHP files | **no syntax errors** |
| `npm run test:js` | **14 passed / 14** |

Playwright / live wp-admin / training.cetechbpa.com / FLAIROC: **not run**. Do not treat CSS wrap or picker UX as live PASS.

---

## 6. Extracted-package results

Extracted with .NET `ZipFile` into disposable `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-r1-qa1-extract\` (outside the repo). Checks are against the **extracted** package, not source.

| # | Check | Result |
|---|--------|--------|
| 1 | One plugin root `cetech-woocommerce-delivery-engine/` | PASS |
| 2 | Version identity `1.0.0-rc.6-r1-qa.1` (header + `CETECH_DE_VERSION`) | PASS |
| 3 | `SchemaVersion::TARGET` = `4` | PASS |
| 4 | Composer production autoload (`scripts/verify-production-package-autoload.php`) | PASS (exit 0; schema 4; ECR flags default OFF; no PHPUnit in vendor; Linux-case classmap) |
| 5 | Linux/forward-slash ZIP paths; classmap includes `WooCommerceCountryCatalog` | PASS (440 entries; 0 backslash; classmap path `/src/Application/Destination/WooCommerceCountryCatalog.php`) |
| 6 | Required runtime assets (`delivery-engine-admin.css/js`, `scoped-configuration.css/js`) | PASS |
| 7 | R1 runtime files present (`AdminFormHelper::prepare_reference_code`, entity form, country catalog, responsive `minmax(min(100%, …))`) | PASS |
| 8 | Dev artifacts excluded: `tests`, `phpunit.xml`, `node_modules`, `.git`, `.env`, `package.json`, `vendor/phpunit`, `.cursor` | PASS |
| 9 | No secrets / nested ZIPs | PASS |
| 10 | No Bulk Tools source / no `1.0.0-dev.bulk` / no schema 5 | PASS |
| 11 | Feature-flag defaults unchanged from RC.6 (`enable_bulk_import` false; ECR/shipping flags default OFF except existing Classic Checkout adapter default) | PASS |
| 12 | Packaged PHP lint (non-vendor) | **333 files, 0 failures** |

---

## 7. JavaScript-disabled country behavior (recorded, not redesigned)

Progressive enhancement. No additional implementation was added during packaging review.

| Situation | Without JavaScript |
|-----------|-------------------|
| Edit an existing Country rule | PHP already names the WooCommerce country `<select>`. The picker works. Stored ISO-2 codes load. |
| New area / switching Location type to Country | The text field remains the named control; the picker stays hidden. Staff can still type an ISO-2 code. Continent names / `Everywhere` still fail validation. |
| WooCommerce countries unavailable | Text fallback with ISO-2 placeholder. |

---

## 8. Remaining known risks

- Responsive card wrapping is CSS-only; **owner visual QA at several wp-admin widths is required**.
- No-JS new Country rows still use typed ISO-2 (not a silent data-loss defect; not redesigned in R1).
- WooCommerce **shipping-zone** labels (“Everywhere / Africa / Ghana”) remain WooCommerce zone UI, distinct from Delivery Areas.
- Audit Issue 1 (International product showing local delivery) and Issue 3 (In Store Store Pickup XOR vs Delivery) are **unchanged** and out of this QA’s judgement.

---

## 9. Owner physical QA checklist

Install this ZIP on **training.cetechbpa.com only**. Do **not** install on FLAIROC. Cursor will not deploy it.

After activate, WordPress should show plugin version **`1.0.0-rc.6-r1-qa.1`**. Schema remains **4**.

### TEST 1 — DELIVERY OPTION AUTO CODE

Create a new Delivery Option.

- enter a unique Name
- leave Reference Code completely blank
- Create

Expected:

- creation succeeds
- no Reference Code error
- reopen/edit entity
- generated stable Reference Code exists

Then rename the Delivery Option.

Expected:

- Reference Code does not unexpectedly change

### TEST 2 — DELIVERY AREA AUTO CODE

Repeat the same test for a new Delivery Area.

Expected:

- blank Reference Code is accepted
- code is generated
- edit retains generated code

### TEST 3 — ERROR RECOVERY

Attempt:

- duplicate Reference Code
and/or
- deliberately invalid manual Reference Code

Expected:

- clear validation error
- entered values preserved
- Create/Save remains clearly visible
- Back remains secondary
- administrator can correct the form and resubmit without reloading/restarting

### TEST 4 — COUNTRY PICKER

Create/edit Delivery Areas using the displayed COUNTRY NAMES:

- Ghana
- Nigeria
- United Kingdom
- United States
- Germany
- China

Expected:

- administrator selects readable names
- no need to know GH/NG/GB/US/DE/CN
- saved area reloads with the correct country
- continent names are not masquerading as countries
- Everywhere/global fallback remains distinct

### TEST 5 — RESPONSIVE FULFILMENT CARDS

Inspect Site-wide Defaults / applicable Overview/setup profile cards at:

- wide desktop
- wp-admin sidebar expanded / narrower desktop
- tablet-like width
- small/mobile width

Expected:

- no overlap
- no horizontal collision
- all radio controls selectable
- text readable
- selected state visible
- keyboard/touch interaction usable

### TEST 6 — SHORT NON-REGRESSION

Confirm that this R1 package did NOT intentionally change:

- existing configured Delivery Options
- existing Delivery Areas
- Site-wide Defaults
- product exceptions
- International behaviour
- Store Pickup behaviour
- shipping rate calculation

Do not use this R1 QA to judge/fix In Store XOR or the International report yet.

---

## 10. Remaining open issues (not R1)

1. **In Store Delivery + Store Pickup** — Store Pickup XOR vs “Delivery and/or Store Pickup” remains an open audit finding. Not repaired here.
2. **International training-product verification** — configuration-dependent local-vs-international display remains an open audit finding. ECR was not rewritten here.

---

## STOP

This task stops after the immutable `1.0.0-rc.6-r1-qa.1` training QA package and this checklist.

- FLAIROC untouched
- Bulk Tools untouched
- `v1.0.0-rc.6` untouched
- No RC.7
- No schema 5
- No remote deployment
