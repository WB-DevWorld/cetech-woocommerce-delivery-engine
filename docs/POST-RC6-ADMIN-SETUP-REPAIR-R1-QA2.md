# POST-RC.6 Admin Setup Repair R1 — QA.2

**Document status:** Packaging record for owner physical QA on **training.cetechbpa.com**  
**QA identity:** `1.0.0-rc.6-r1-qa.2`  
**Owner physical QA:** **not yet run**. Cursor will **not** deploy this ZIP.  
**Schema target:** `4` (unchanged; no schema 5)  
**Branch:** `fix/post-rc6-admin-setup-defects`  
**Protected published baseline:** tagged `1.0.0-rc.6` / `v1.0.0-rc.6` — **untouched**  
**This is not:** RC.7, Stage 15, R2, or a replacement for tagged RC.6  
**Date:** 2026-08-25  
**FLAIROC:** **NOT MODIFIED**  
**Bulk Tools (`feat/post-rc6-bulk-tools`):** **NOT MODIFIED**  
**Git tag for this QA identity:** **none** (do not create `v1.0.0-rc.6-r1-qa.2`; do not retag `v1.0.0-rc.6`)

---

## 1. R1-QA.1 — OWNER PHYSICAL FAIL (not rewritten)

QA.1 identity `1.0.0-rc.6-r1-qa.1` **FAILED** owner physical testing on training.cetechbpa.com.

ZIP `cetech-woocommerce-delivery-engine-1.0.0-rc.6-r1-qa.1.zip` is an **immutable FAILED** artifact:

| Item | Value |
|------|--------|
| Bytes | `1081009` |
| SHA-256 | `6eb0935c1046d4bd65ea017965970b7c0e4784a55d2db2758eab2be3841b5029` |
| Source | `41ca3e0` (`41ca3e0afd246636837a2b82e0ac23775e6f2a84`) |

Do **not** overwrite it. Automated PASS numbers in `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1-QA1.md` were real against source/unit tests. They were **insufficient to prove browser wp-admin behavior**.

Exact physical causes:

1. **Entity form ownership / browser HTML repair.** Header Create was a `<button type="submit">` inside a form that also wrapped the wp-admin page header. Implicit `</form>` / nested-form repair could close the entity form after the header.
2. **Missing posted name/code.** The surviving header submit posted nonce + action only. `prepare_reference_code()` then saw a blank name and a blank code, so validators correctly reported `Code is required.`
3. **Country display label submitted instead of ISO.** New Country rows named the text field `destination_rules[i][rule_value]`. If JS did not move `name` onto the select, PHP received `Germany` instead of `DE`.
4. **Draft-restored blank numeric values caused TypeError.** Empty Advanced number strings (`''`) were passed into `AdminFormHelper::number_field(… ?int $value …)` → `Argument #3 ($value) must be of type ?int, string given` inside Delivery Option Advanced details.
5. **Header primary action not reliable.** After the error re-render, the header showed only Back. Footer Create still existed, so the user had to scroll.

Investigation: `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1-QA1-FAILURE-REPAIR.md`  
Repair commit: `fc76c4c911c5ea5832b61b6cd80e6cfcecd455ce`

---

## 2. Purpose of QA.2

Owner-approved packaging of **only** the R1 work plus the QA.1 request-path repairs:

- Reference Code generate-before-validation on the real wp-admin POST
- correct entity-form ownership (`id="cetech-de-entity-form"`, header `form=` attribute)
- persistent visible Create/Save after validation errors (header + in-form toolbar + footer)
- Delivery Area WooCommerce country picker submitting ISO-2
- draft/error rendering TypeError repair
- previously implemented responsive fulfilment-card CSS (unchanged in this repair; **not** claimed PASS)

Cursor did **not** deploy this ZIP. Training.cetechbpa.com only.

### Explicit exclusions

- In Store fulfilment semantics / Delivery + Store Pickup dual-choice
- Store Pickup runtime changes
- International ECR / product-page destination behaviour
- Browsing location, item-level destinations, multi-address checkout
- Return Policy / Refund Policy
- Bulk Tools
- Schema 5 / RC.7
- FLAIROC
- Moving or retagging `v1.0.0-rc.6`
- Overwrite of `1.0.0-rc.6-r1-qa.1`

---

## 3. Version identity

| Item | Value |
|------|--------|
| Plugin header / `CETECH_DE_VERSION` | `1.0.0-rc.6-r1-qa.2` |
| Schema `SchemaVersion::TARGET` | `4` |
| Branch | `fix/post-rc6-admin-setup-defects` |
| Failure-repair commit | `fc76c4c911c5ea5832b61b6cd80e6cfcecd455ce` |
| QA identity / ZIP source commit | `a7f9946d47e484362ab063018ec72af721ff15a8` |
| Protected tag `v1.0.0-rc.6` | annotated `1d3252199f45371ec4229808155f6017ee3da9ea` — **untouched** |

Build command (clean tree; not `-AllowDirty`):

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build-v1-rc-package.ps1 -Version 1.0.0-rc.6-r1-qa.2 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-rc.6-r1-qa.2.zip
```

---

## 4. ZIP identity

| Artifact | Path |
|----------|------|
| Dist ZIP | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.6-r1-qa.2.zip` |
| Desktop copy | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.6-r1-qa.2.zip` |
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-rc.6-r1-qa.2.zip` |
| Bytes | `1092406` |
| SHA-256 | `62c1fea94bcdea60540ea422220ea9c6f275b16a0c08d806091a5538598c3610` |
| Sidecar | same hash + `  cetech-woocommerce-delivery-engine-1.0.0-rc.6-r1-qa.2.zip` |
| Source commit | `a7f9946` (`a7f9946d47e484362ab063018ec72af721ff15a8`) |
| Schema | `4` |
| Plugin version | `1.0.0-rc.6-r1-qa.2` |

ZIP root folder: `cetech-woocommerce-delivery-engine/`

QA.1 ZIP remains on disk at `1081009` bytes / SHA-256 `6eb0935c1046d4bd65ea017965970b7c0e4784a55d2db2758eab2be3841b5029`.

---

## 5. Rendered-form ownership verification

`PostRc6AdminSetupRepairR1RenderedFormOwnershipTest` parses **rendered** add/edit/error HTML with DOMDocument (not PHP source order).

Proved for Delivery Option and Delivery Area:

1. Exactly one intended entity form `id="cetech-de-entity-form"`
2. Main entity fields belong to that form
3. Reference Code belongs to that form
4. Delivery Area country fields belong to that form
5. Footer submit belongs to that form
6. Sticky in-form submit belongs to that form
7. Header Create/Save is `<input type="submit" name="cetech_de_save" form="cetech-de-entity-form">` outside the form (no nested form)
8. No nested entity form
9. Successful controls for that form include name/code, not only nonce/action
10. Error-state re-render preserves the same ownership

Country markup: `<option value="DE">Germany</option>` (and GH/NG/GB/US/CN). Authoritative field is `destination_rules[i][country_code]`. JS `FormData` posts `DE`, not `Germany`. Cloned “Add another location” rows keep the same contract.

Critical-error sequence: invalid Reference Code + blank Advanced numbers → draft restore → Advanced fields render empty, no TypeError. A numeric `processing_min_days=3` still saves.

---

## 6. Automated results

PHP 8.5.0 / PHPUnit 10.5.64. Re-run on the pre-identity repair tree (runtime identical to ZIP source except the QA identity string). Not copied from QA.1.

| Gate | Result |
|------|--------|
| R1 + request-path + rendered-form ownership + catalog | **26 tests, 352 assertions, OK** |
| Those plus Stage13B UX + hard fulfilment | **41 tests, 505 assertions, OK** |
| Proportional regression (site-wide / ECR parity / hard fulfilment / admin UX / access / shipping registration / schema 4 / shipments / rate-card dates / destination matcher / admin language / R1 gates) | **170 tests, 1379 assertions, OK** |
| ECR/shipment subset (`SimpleProductNonRegressionTest` / `VariableEcrRuntimeTest` / `StaffShipmentsWorkspaceTest` / `ShipmentStatusWorkflowTest`) | **48 tests, 285 assertions, OK** |
| Schema identity after QA.2 version bump (`SchemaV4InspectionTest`) | **6 tests, 75 assertions, OK** |
| PHP lint of changed PHP | **no syntax errors** |
| `npm run test:js` | **15 passed / 15** |

Playwright / live wp-admin / training.cetechbpa.com / FLAIROC: **not run**. Do not treat this package as physical PASS.

---

## 7. Extracted-package results

Extracted with .NET `ZipFile` into disposable `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-r1-qa2-extract\` (outside the repo). Checks are against the **extracted** package, not source.

| # | Check | Result |
|---|--------|--------|
| 1 | One plugin root `cetech-woocommerce-delivery-engine/` | PASS |
| 2 | Version identity `1.0.0-rc.6-r1-qa.2` (header + `CETECH_DE_VERSION`) | PASS |
| 3 | `SchemaVersion::TARGET` = `4` | PASS |
| 4 | Composer production autoload (`scripts/verify-production-package-autoload.php`) | PASS (exit 0; schema 4; ECR flags default OFF; no PHPUnit in vendor; Linux-case classmap) |
| 5 | Linux/forward-slash ZIP paths; classmap includes `WooCommerceCountryCatalog` | PASS (442 entries; 0 backslash; classmap path `/src/Application/Destination/WooCommerceCountryCatalog.php`) |
| 6 | Required runtime assets (`delivery-engine-admin.css/js`, `scoped-configuration.css/js`) | PASS |
| 7 | Form-ownership repair present (`ENTITY_FORM_ID`, `form="%3$s"`, `prepare_reference_code`, `country_code`, `canonical_iso2`, `int_or_null`, responsive `minmax(min(100%, …))`) | PASS |
| 8 | Dev artifacts excluded: `tests`, `phpunit.xml`, `node_modules`, `.git`, `.env`, `package.json`, `vendor/phpunit`, `.cursor` | PASS |
| 9 | No secrets / nested ZIPs | PASS |
| 10 | No Bulk Tools source / no `1.0.0-dev.bulk` / no schema 5 | PASS |
| 11 | Feature-flag defaults unchanged from RC.6 (`enable_bulk_import` false; ECR/shipping flags default OFF except Classic Checkout adapter default true) | PASS |
| 12 | Packaged PHP lint (non-vendor) | **333 files, 0 failures** |

---

## 8. Owner physical QA checklist

Install this ZIP on **training.cetechbpa.com only**. Do **not** install on FLAIROC. Cursor will not deploy it.

After activate, WordPress should show plugin version **`1.0.0-rc.6-r1-qa.2`**. Schema remains **4**.

Do **not** use QA.2 to judge the unresolved In Store semantic issue or International training-product issue.

### TEST 1 — DELIVERY OPTION

Create:

- Customer-facing name = `Delivery to Madina Zone`
- Reference Code = blank

Expected:

- Create succeeds
- no `Code is required`
- generated code exists on edit
- changing visible name does not change code

### TEST 2 — DELIVERY AREA

Create:

- Name = `Germany Test Area`
- Location = Country
- Country = Germany
- Reference Code = blank

Expected:

- Create succeeds
- no `Code is required`
- no ISO-code error
- generated Reference Code exists
- edit shows Germany correctly

### TEST 3 — MULTIPLE COUNTRIES

Confirm by name:

- Ghana
- Nigeria
- United Kingdom
- United States
- Germany
- China

Expected:

- no manual ISO knowledge required
- saved/reopened country remains correct

### TEST 4 — ERROR RECOVERY

Cause an intentional duplicate/invalid Reference Code.

Expected:

- error shown
- form values preserved
- no WordPress critical error
- Create/Save visible near the top
- Create/Save also available through normal in-form/footer action
- Back is secondary
- correct and resubmit successfully

### TEST 5 — ADVANCED DETAILS

Open Delivery Option Advanced details.

Leave processing/transit/final-mile numeric values empty.

Trigger/recover from a validation error.

Expected:

- no critical error
- blank optional numbers remain usable

### TEST 6 — RESPONSIVE CARDS

Check fulfilment cards at:

- wide desktop
- narrow desktop / sidebar expanded
- tablet width
- mobile width

Expected:

- no overlap
- controls usable
- labels readable
- selected state obvious
- no ordinary horizontal overflow

Do not claim Test 6 PASS until this physical check.

### TEST 7 — SHORT REGRESSION

Open:

- existing Delivery Options
- Delivery Areas
- Site-wide Defaults
- Product Exceptions

Confirm no obvious regression.

---

## 9. Open findings still deferred

- In Store Delivery + Store Pickup XOR / dual-choice
- International training-product verification

---

## 10. Confirmations

- QA.1 ZIP **unchanged / immutable**
- In Store semantics **untouched**
- International ECR **untouched**
- Bulk Tools **untouched**
- FLAIROC **untouched**
- `v1.0.0-rc.6` **untouched**
- No RC.7
- No schema 5

---

## STOP

QA.2 is prepared for owner physical install on training.cetechbpa.com. Cursor must not deploy it. Do not start R2.
