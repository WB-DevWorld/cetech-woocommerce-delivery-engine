# POST-RC.6 Repair R1 — QA.1 physical failure investigation + repair

**Document status:** Repair record after owner physical FAIL of `1.0.0-rc.6-r1-qa.1`  
**Date:** 2026-08-25  
**Branch:** `fix/post-rc6-admin-setup-defects`  
**Failed QA identity:** `1.0.0-rc.6-r1-qa.1` — **immutable**. Do not overwrite the ZIP.  
**This repair is not:** R2, RC.7, Stage 15, schema 5, or a QA.2 package  
**Schema:** `4`  
**Plugin version on this tree:** `1.0.0-rc.6-r1-qa.2` after the authorised identity commit. QA.1 ZIP remains immutable.  
**FLAIROC:** not modified  
**Bulk Tools:** not modified  
**Tag `v1.0.0-rc.6`:** not modified  

No QA.2 ZIP in this stage.

---

## R1-QA.1 — OWNER PHYSICAL FAIL

Failures recorded from training.cetechbpa.com (owner screenshots):

1. Delivery Option, blank Reference Code → `Code is required.`
2. Delivery Area, blank Reference Code → `Code is required.`
3. Country picker showed Germany; server `Country code must be a 2-letter ISO code.`
4. WordPress critical error inside Delivery Option Advanced details after failed submit
5. After error, header showed only Back to Delivery Options / Delivery Areas
6. Responsive fulfilment cards — **not yet verified**; do not claim Test 5 PASS

Previous automated PASS numbers in `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1-QA1.md` were real against source/unit tests. They did not exercise the live wp-admin POST/render path.

---

## 1. Exact root cause of blank-code failure

`AdminFormHelper::prepare_reference_code()` **was** called in `DeliveryOffersPage::handle_save` / `DestinationZonesPage::handle_save` **before** the validators. A complete `$_POST` with name + empty `code` generates a code.

Physical “Code is required.” means the **posted** `code` was empty **and** the posted display name used for generation was also empty at prepare time.

The live form put the header Create control in a `<button type="submit">` and wrapped the WordPress-style page header in the entity `<form>`. That structure is fragile in wp-admin:

- a later implicit `</form>` / nested form repair can close the entity form after the header
- the header submit then posts **nonce + action only** (or a truncated form)
- `public_label` / `name` and `code` never arrive
- HTML5 `required` on the name field does not run, because those inputs are no longer in the submitted form
- validators correctly report `Code is required.`

The Reference Code field also lived only inside closed `<details> Advanced details`, so it was easy to miss and easy to leave out of a repaired/truncated submit.

**Repair:**

- Form `id="cetech-de-entity-form"`
- Header Create/Save is `<input type="submit" name="cetech_de_save" form="cetech-de-entity-form">` (HTML `form` attribute; not JavaScript)
- Sticky in-form toolbar also contains Create/Save
- Footer submit remains
- Back remains a secondary link, not the only header control
- Reference Code moved onto the main visible panel (still optional; still generated when blank)

---

## 2. Why unit tests passed while the browser failed

R1 unit tests built an `$input` array with `'code' => ''` and `'public_label'` / `'name'` already filled, then called `prepare_reference_code()` directly.

They never:

- parsed `$_POST`
- ran `AdminActionHandler::verify_post`
- submitted through `handle_actions()`
- rendered the error form after a draft restore

Source-order tests also checked that a `<form>` string appeared before `'type' => 'submit'` in PHP, not that the browser would keep those controls in one form owner.

---

## 3. Exact root cause of Germany → invalid ISO

Country rows used **one** POST name `destination_rules[i][rule_value]` swapped by JavaScript between a hidden ISO `<select>` and a text input.

On a new area, PHP named the **text** field. The picker was hidden/`disabled` until JS moved `name` onto the select.

If JS did not run, ran late, or left the text field as the winning duplicate name, PHP received the visible label `Germany` (not `DE`). `DestinationRuleValidator` then rejected it (`^[A-Z]{2}$/`).

Option markup was already `value="DE">Germany</option>` when the catalog loaded. The defect was **which control owned `name`**, not a second hardcoded catalogue.

**Repair:**

- Country `<select>` always posts `destination_rules[i][country_code]` with ISO-2 option values
- Text always posts `destination_rules[i][rule_value]`
- JS only toggles `hidden`; it does not move `name` or copy labels into the ISO control
- Server `normalize_posted_destination_rules()` prefers `country_code`, then maps labels via `WooCommerceCountryCatalog::canonical_iso2()` (`Germany`/`de` → `DE`)
- New empty rows default Location type to Country so the name picker is visible without JS
- Continents / Everywhere still fail; fallback remains the Advanced checkbox

---

## 4. Exact critical error and stack origin

After a failed save, draft restore passed `$_POST` empty strings (`''`) into `AdminFormHelper::number_field( … ?int $value … )` for processing/transit/final-mile days.

PHP 8 TypeError:

`AdminFormHelper::number_field(): Argument #3 ($value) must be of type ?int, string given`

That call sits in Delivery Option **Advanced details**, which matches the screenshot location.

Triggered by the R1 blank-code failure path (draft + open Advanced). Not an unrelated host error.

**Repair:** `number_field()` accepts `mixed` and coerces via `AdminFormHelper::int_or_null()`. Draft mapping uses the same helper.

---

## 5. Exact header-action mismatch

R1 rendered a header `<button type="submit">Create/Save</button>` plus a Back `<a>`.

Physical error screens showed only Back because:

- the submit `button` was easy for wp-admin HTML/CSS to drop or leave outside the surviving form
- Back is a normal link and remained visible

**Repair:** WordPress-native `<input type="submit" class="button button-primary">` in the header (with `form=`), plus an in-form sticky toolbar, plus footer submit. Back stays secondary.

---

## 6. Changed files

Runtime:

- `src/Presentation/Admin/DeliveryOffersPage.php`
- `src/Presentation/Admin/DestinationZonesPage.php`
- `src/Presentation/Admin/AdminPageLayout.php`
- `src/Presentation/Admin/AdminFormHelper.php`
- `src/Application/Destination/WooCommerceCountryCatalog.php`
- `assets/admin/delivery-engine-admin.js`

Tests:

- `tests/Unit/Presentation/Admin/PostRc6AdminSetupRepairR1RequestPathTest.php` **(new)**
- `tests/Unit/Presentation/Admin/PostRc6AdminSetupRepairR1RenderedFormOwnershipTest.php` **(new)**
- `tests/Unit/Runtime/InMemoryDestinationRuleRepository.php` **(new)**
- `tests/Unit/Presentation/Admin/PostRc6AdminSetupRepairR1Test.php`
- `tests/Unit/Presentation/Admin/Stage13BR1AdminUxTest.php`
- `tests/Unit/Destination/WooCommerceCountryCatalogTest.php`
- `tests/js/delivery-engine-admin-country-picker.test.js`
- `tests/bootstrap.php` (submit_button / checked / home_url stubs)

Docs:

- `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1-QA1.md` (FAIL recorded; automated numbers kept)
- `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1-QA1-FAILURE-REPAIR.md` (this file)
- `docs/AI-HANDOFF.md` (current-status only)

Responsive card CSS: **not changed**.

---

## 7. New higher-level tests

`PostRc6AdminSetupRepairR1RequestPathTest` posts through **`handle_actions()`** (the same `admin_init` path as wp-admin):

1. Delivery Option blank code + name → create, generated code
2. Delivery Area blank code + name → create, generated code
3. Germany as `country_code=DE` or `rule_value=Germany` → persisted `DE`
4. Failed validation draft (empty number strings) → `render()` does not fatal; Create submit + Back present
5. Numeric Advanced values still save (`processing_min_days=3`)

`PostRc6AdminSetupRepairR1RenderedFormOwnershipTest` parses **rendered HTML with DOMDocument** (not PHP source order):

- exactly one `id="cetech-de-entity-form"`
- name/code/country fields belong to that form
- header Create/Save uses `form="cetech-de-entity-form"` and is not nested
- sticky toolbar + footer submit belong to the entity form
- error-state re-render preserves ownership
- `<option value="DE">Germany</option>` and GH/NG/GB/US/CN equivalents
- blank Advanced number fields remain blank after a forced validation error

JS: `FormData` from the country select is `DE`, not `Germany`; cloned “Add another location” rows keep `destination_rules[i][country_code]`.

---

## 8. Automated results (this repair tree)

PHP 8.5.0 / PHPUnit 10.5.64. Re-run on the pre-QA.2 tree after the rendered-form ownership gate. Not copied from QA.1.

| Gate | Result |
|------|--------|
| R1 + request-path + rendered-form ownership + catalog | **26 tests, 352 assertions, OK** |
| Those plus Stage13B UX + hard fulfilment | **41 tests, 505 assertions, OK** |
| Proportional regression (site-wide / ECR parity / hard fulfilment / admin UX / access / shipping registration / schema 4 / shipments / rate-card dates / destination matcher / admin language / R1 gates) | **170 tests, 1379 assertions, OK** |
| ECR/shipment subset (`SimpleProductNonRegressionTest` / `VariableEcrRuntimeTest` / `StaffShipmentsWorkspaceTest` / `ShipmentStatusWorkflowTest`) | **48 tests, 285 assertions, OK** (run via `--filter` so `RecordingSource` autoloads) |
| PHP lint of changed PHP | **no syntax errors** |
| `npm run test:js` | **15 passed / 15** |

Browser / training.cetechbpa.com / FLAIROC: **not run**. Do not claim physical PASS.

---

## 9. Disposable WordPress / wp-admin reproduction

No live WordPress/WooCommerce site was booted in this session.

Reproduction used the **real page controllers** with browser-shaped `$_POST` (`cetech_de_action`, nonce, fields) via `handle_actions()`, then `render()` for the error form. That is the wp-admin PHP path. It is not a headed browser.

---

## 10. Schema / version

| Item | Status |
|------|--------|
| Schema `SchemaVersion::TARGET` | `4` |
| Plugin version | `1.0.0-rc.6-r1-qa.2` after authorised identity commit |
| Failed ZIP | immutable |
| QA.2 | packaged; see `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1-QA2.md` |
| RC.7 | **not created** |

---

## 11. Confirmation

- In Store fulfilment semantics: **untouched**
- International ECR: **untouched**
- Bulk Tools workspace: **untouched**
- FLAIROC: **untouched**
- `v1.0.0-rc.6`: **untouched** (`1d3252199f45371ec4229808155f6017ee3da9ea`)

---

## STOP

Owner reviewed and approved packaging. QA.2 identity and ZIP are recorded in `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1-QA2.md`. Do not start R2. Cursor must not deploy.
