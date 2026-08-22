# POST-RC.6 Admin Setup Repair R1

**Document status:** Implementation record for Repair R1 (audit items 1–4 only)  
**Date:** 2026-08-22  
**Branch:** `fix/post-rc6-admin-setup-defects`  
**Protected baseline:** tagged `1.0.0-rc.6` / schema `4` / `v1.0.0-rc.6` **untouched**  
**Plugin version on this branch:** `1.0.0-rc.6` (not RC.7)  
**Schema:** `4` (no schema 5)  
**FLAIROC:** not modified  
**Bulk Tools (`feat/post-rc6-bulk-tools`):** not modified, not mixed  
**Stage 15:** not started

This is a post-RC.6 repair branch only. No QA ZIP. No RC.7.

---

## Scope

Implemented **only** audit repair items 1–4 from `docs/POST-RC6-TESTER-OBSERVATIONS-AUDIT.md`:

1. Reference Code generated before validation (Delivery Options + Delivery Areas)
2. Validation-error form recovery (Create/Save remains the obvious primary action)
3. Responsive Fulfilment Availability / profile cards
4. Delivery Area country picker using WooCommerce country data

**Not changed in this stage:**

- In Store model (Delivery + optional Store Pickup)
- International product behavior / Effective Configuration Resolver
- Multiple-address checkout
- Browsing location persistence
- Return Policy / Refund Policy
- Bulk Tools
- FLAIROC
- tagged `v1.0.0-rc.6`

---

## Exact defects repaired

| Item | Tester/audit defect | Root cause | Repair |
|------|---------------------|------------|--------|
| 1 | Blank Reference Code failed even though help text said it would be generated from the name | `DeliveryOffersPage::handle_save` and `DestinationZonesPage::handle_save` validated **before** `generate_code_from_name()` | Shared `AdminFormHelper::prepare_reference_code()` fills a blank code from the name (or restores the stored code on edit), then validation and uniqueness run |
| 2 | After a validation error, staff appeared to lose Create/Save | PHP still rendered footer `submit_button`, but the header primary control was only “Back to …”, and the error notice sits at the top of the page | Entity form now wraps the header. Header primary is a **submit** Create/Save button. Footer submit remains. Back remains secondary |
| 3 | Fulfilment / profile cards overlapped in narrower wp-admin | `.cetech-de-profile-card-grid` used rigid `minmax(220px/240px, 1fr)` that cannot shrink below that floor | Same resilient pattern as `.cetech-de-choice-grid`: `minmax(min(100%, …), 1fr)` plus `min-width: 0` / `overflow-wrap` |
| 4 | Staff had to type ISO-2 codes / confuse WooCommerce shipping-zone groupings with Delivery Areas | Country rule value was a free-text input | `WooCommerceCountryCatalog` reads `WC()->countries->get_countries()` (ISO-2 → name). Country rows use a name picker; stored value remains the ISO code. Fallback “everywhere” remains the distinct Advanced checkbox, not a country |

---

## Files changed

Runtime / admin:

- `src/Presentation/Admin/AdminFormHelper.php` — `prepare_reference_code()`
- `src/Presentation/Admin/DeliveryOffersPage.php` — generate-then-validate; form wraps header; header submit
- `src/Presentation/Admin/DestinationZonesPage.php` — same save order; form recovery; country select; copy distinguishing Delivery Areas from shipping zones
- `src/Presentation/Admin/AdminPageLayout.php` — header `type => submit`; sticky `.cetech-de-form-actions`
- `src/Presentation/Admin/Validation/DeliveryOfferValidator.php` — distinguish blank vs invalid explicit code
- `src/Presentation/Admin/Validation/DestinationZoneValidator.php` — same
- `src/Application/Destination/WooCommerceCountryCatalog.php` — **new**; WooCommerce country list only
- `assets/admin/delivery-engine-admin.css` — responsive grids; country select width
- `assets/admin/scoped-configuration.css` — matching profile-card grid
- `assets/admin/delivery-engine-admin.js` — country vs text `name`/disabled sync, including cloned rows

Tests:

- `tests/Unit/Presentation/Admin/PostRc6AdminSetupRepairR1Test.php`
- `tests/Unit/Destination/WooCommerceCountryCatalogTest.php`
- `tests/Unit/Runtime/InMemoryDestinationZoneRepository.php`
- `tests/js/delivery-engine-admin-country-picker.test.js`

Docs:

- `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1.md` (this file)
- `docs/AI-HANDOFF.md` — CURRENT STATUS development-state only
- `docs/POST-RC6-TESTER-OBSERVATIONS-AUDIT.md` — copied onto this branch in commit `9fd3f06`; original Bulk Tools copy left in place

---

## Exact Reference Code fix

Contract (server-authoritative; no JavaScript generation):

```text
Name supplied
Reference Code blank
    → generate stable sanitized code from the name
    → validate generated code
    → validate uniqueness
    → save
```

- Blank on **create** → `generate_code_from_name()` (same sanitiser already used by Rate Cards / wizard).
- Blank on **edit** → restore the stored `internal_code`. Ordinary display-name edits do **not** regenerate identity.
- Non-empty valid manual code → sanitized and retained.
- Non-empty invalid manual code (sanitises to empty) → validation error; not auto-replaced from the name.
- Duplicate explicit code → rejected with the existing uniqueness error.
- Generated collisions suffix `-2`… as before.

Applied to Delivery Options and Delivery Areas only.

---

## Exact error-state UX fix

- Create/edit forms open **before** the page header (`class="cetech-de-entity-form"`).
- Header primary action is `<button type="submit">` Create/Save.
- Header secondary remains “Back to Delivery Options/Areas”.
- Footer still has the normal WordPress `submit_button` plus Cancel.
- `.cetech-de-form-actions` is sticky at the bottom of the admin canvas.
- Failed saves still `stash_form_draft` and redisplay entered values.

No full admin redesign.

---

## Exact responsive CSS fix

Choice cards already used `repeat(auto-fit, minmax(min(100%, 240px), 1fr))`.

Profile / overview / next-step grids now use:

```css
grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr));
```

plus `min-width: 0`, `max-width: 100%`, and `overflow-wrap: anywhere` on the cards.

Radios on fulfilment choice cards remain clipped (`clip: rect(...)`) for accessibility, **not** `display: none`. Focus-visible and checked styles are unchanged.

No hardcoded single browser width. Owner still needs live visual QA at several wp-admin widths.

---

## Exact country-picker implementation

- New `WooCommerceCountryCatalog` uses WooCommerce `get_countries()` only.
- Keys that are not `/^[A-Z]{2}$/` are dropped (continent labels / “Everywhere” cannot appear as countries).
- Country condition rows render a `<select>` of human-readable names; option **values** are ISO-2 codes (`GH`, `NG`, `GB`, `US`, `DE`, `CN`, …).
- Unknown stored codes get an extra selected option so existing areas still load.
- Region / City / Postcode stay text inputs. JS moves the `name="destination_rules[i][rule_value]"` onto the visible control.
- If WooCommerce countries are unavailable, staff still get the text fallback.
- Stored authority remains the ISO code. No second country database. No CETECH-specific country list. No migration of valid existing areas.
- Fallback Delivery Area remains a distinct Advanced checkbox, **not** a country named Everywhere.
- Copy tells staff this is a Delivery Engine Delivery Area, not a WooCommerce shipping zone.

---

## Tests added

1. Delivery Option: name + blank code → generated `same-day-delivery` → saves  
2. Delivery Area: name + blank code → generated `greater-accra` → saves  
3. Explicit valid code retained  
4. Duplicate explicit code rejected  
5. Invalid explicit code (`!!!`) rejected  
6. Existing generated code unchanged when display name is later edited  
7. WooCommerce country source (via test override) includes GH/NG/GB/US/DE/CN names  
8. Edit UI source loads stored ISO via `selected()` and unknown-code option  
9. Saving `gb` stores canonical `GB`  
10. `Africa` / `Everywhere` rejected as country codes; catalog drops non-ISO-2 keys  
11. Validation-failure source contract: entity form wraps header submit; Back strings remain  

JS: country select receives the submitted `name` when type is Country; text restored for Region; cloned rows sync.

---

## Automated results

PHPUnit (repair worktree, PHP 8.5.0, PHPUnit 10.5.64):

| Suite | Result |
|-------|--------|
| `PostRc6AdminSetupRepairR1Test` + `WooCommerceCountryCatalogTest` | **14 tests, 82 assertions, OK** |
| Site-wide / ECR / Hard fulfilment / admin UX / access / shipping registration / schema 4 / shipments / rate-card dates / destination matcher / admin language | **132 tests, 933 assertions, OK** |
| Simple/variable ECR + shipment workspace/status | **48 tests, 285 assertions, OK** |

PHP lint: no syntax errors on changed PHP files.

JS (`npm run test:js`): **14 passed / 14** (preview + country picker + variable selector).

Browser/manual checks actually performed: **none**. No live wp-admin, training site, or FLAIROC session. Do not treat CSS wrap or picker UX as live PASS.

---

## Remaining known findings from the audit (not repaired)

- Issue 1 — International product showing local delivery: configuration-dependent; ECR not rewritten here.
- Issue 3 — Store Pickup XOR vs Delivery: In Store dual-choice not implemented.
- Issue 4 — assigning multiple Delivery Options: storage already supports it; In Store UX deferred.
- WooCommerce shipping-zone labels (“Everywhere / Africa / Ghana”) remain a **WooCommerce** zone UI, distinct from Delivery Areas.
- Owner visual QA of responsive cards and country picker is still required.

---

## Owner physical QA checklist (do not deploy)

Cursor did **not** perform owner live deployment.

### A. Delivery Option

1. Add Delivery Option.
2. Enter Name only. Leave Reference Code blank.
3. Create succeeds.
4. Edit: generated code is visible under Advanced details.

### B. Delivery Area

1. Add Delivery Area.
2. Enter Name only. Leave Reference Code blank.
3. Create succeeds.
4. Edit: generated code is visible under Advanced details.

### C. Validation

1. Intentionally submit a duplicate or invalid Reference Code.
2. Error text appears.
3. Entered values remain.
4. Create/Save is still obvious in the header and the normal action area. No reload workaround.

### D. Countries

Create/edit areas for:

- Ghana
- Nigeria
- United Kingdom
- United States
- Germany
- China

Staff choose the readable name. Saved matching uses the canonical WooCommerce/ISO code (`GH`, `NG`, `GB`, `US`, `DE`, `CN`). Continents and “Everywhere” are not offered as countries. Fallback remains the Advanced checkbox if used.

### E. Responsive cards

Check wp-admin at:

- wide desktop
- narrower desktop / sidebar expanded
- tablet-like width
- small/mobile width

No overlapping Fulfilment Availability / profile cards. Radios remain operable (keyboard, label click, selected state obvious).

---

## JavaScript-disabled country behavior (recorded, not redesigned)

This is **progressive enhancement**, not a new defect found during packaging review. Scope was not expanded.

| Situation | Without JavaScript |
|-----------|-------------------|
| Edit an existing Country rule | PHP already renders the WooCommerce name `<select>` with `name="destination_rules[i][rule_value]"`. The picker works. Stored ISO-2 codes load. |
| Add a new area / change Location type to Country | PHP initially renders the text field as the named control and keeps the country `<select>` hidden/disabled. Without JS, switching Location to Country does **not** reveal the name picker. Staff can still type an ISO-2 code in the visible text field. `DestinationRuleValidator` still rejects continent labels / “Everywhere”. |
| WooCommerce countries unavailable | Text fallback with ISO-2 placeholder (already PHP). |

A no-JS type switch that reveals the picker would be a later enhancement. It is not required to ship R1.

---

## Schema / version / safety

| Item | Status |
|------|--------|
| Plugin version | `1.0.0-rc.6` |
| Schema `SchemaVersion::TARGET` | `4` |
| Tag `v1.0.0-rc.6` | **untouched** |
| RC.7 | **not created** |
| QA ZIP | **not packaged** |
| In Store model | **untouched** |
| International ECR | **untouched** |
| Bulk Tools branch | **untouched** |
| FLAIROC | **untouched** |

---

## STOP

Repair R1 implementation is complete for items 1–4. Do not continue into In Store dual-choice, International ECR, or packaging unless the owner explicitly instructs.
