# Stage 13C-R1 — Owner-review / live upgrade repair

**Document status:** Local implementation record. Owner live retest is the remaining gate.  
**Plugin version:** unchanged (`1.0.0-rc.3-qa.1` working tree; public RC.3 is **not** tagged)  
**Schema target:** `3` (unchanged)  
**Branch:** `feat/site-wide-delivery-defaults` (uncommitted)  
**RC.2 tag:** `v1.0.0-rc.2` — **untouched**  
**Date:** 2026-08-14  
**FLAIROC:** not modified  
**Package / commit:** none

---

## 1. Verdict

**Pending owner live retest on physical FLAIROC.**

Local Stage 13C-R1 repairs from the `1.0.0-rc.3-qa.1` owner review are implemented. PHPUnit is green. Affected fixture screenshots were regenerated. Do **not** package. Do **not** commit or tag RC.3 until the owner retests the live WordPress admin.

This record does not declare the live findings closed.

---

## 2. What this repair addressed

Focused admin/upgrade repairs from physical WordPress testing of `1.0.0-rc.3-qa.1`. Inheritance, cart/checkout, shipping, and order snapshots were not rewritten.

| # | Finding | Local repair |
|---|---------|--------------|
| 1 | Setup Guide Continue routed to Preview | Wizard save/continue/back args are explicit. Preview POST handling is page-gated. |
| 2 | Administrator “not allowed” on some Delivery Engine links | Versioned `Capabilities::ensure_current()` (VERSION `2`) runs after migrations without requiring reactivation. Product Delivery Settings is a hidden `options.php` page. |
| 3 | Contradictory Overview / Settings / Preview status | One `OperationalStateService` drives Overview, Settings, Setup Guide, Preview, Needs Attention, and Legacy messaging. |
| 4 | Prior RC.2 install treated as broken first-time setup | Prior-install detection + truthful copy: existing rules still serve customers until Site-wide Defaults are finished and the new runtime is activated. |
| 5 | “Products using defaults” when 0 defaults configured | Label is **Products eligible for Site-wide Defaults** until defaults are applied. |
| 6 | Needs Attention flooded unmigrated catalogs | Catalog scan as customer problems only when ECR customer runtime is active. Prior-legacy stores get a system-level notice instead. |
| 7 | Preview “no usable option” while an active Local Delivery option showed | `OperationalReadinessAssessor` empty-slice fix; Preview and Needs Attention share the same assessor. |
| 8 | Preview summary collapsed mixed provenance to Site-wide Default | Summary uses Mixed / Product-specific / Variation-specific / Site-wide Default. Variation immediate source is Product Settings. |
| 9 | Product Exceptions listed supplier/origin/priority | Normal Customized column is business fields only; private differences summarize as **Technical delivery details**. |
| 10 | Variation exception suggested Site-wide Default | Immediate inherited source is Product Settings. |
| 11 | Product panel said Site-wide Defaults and “customized settings” | Customized: **Currently using: Product-specific delivery settings**. Inherited: **Currently using: {profile} Site-wide Default** plus “no special delivery settings.” |
| 12 | Offer `service_level` code shown as ETA | `OfferEstimatedDeliveryDisplay` hides internal codes (`standard`, etc.). Stored rows are not mutated. |
| 13 | Charges showed `25.0000` | `AdminMoneyFormatter` trims excess decimals for display/input. Missing-rate / explicit-zero safety unchanged. |
| 14 | Hard-coded Accra / GHS examples | `StoreAwareExamples` uses store currency and neutral city copy. |
| 15 | Access section was explanatory while caps failed | Informational **Permissions** matrix (Administrator full/fixed; Shop Manager operational; diagnostics separate). No custom roles. Item 2 self-heal remains mandatory. |
| 16 | Third-party notices inside plugin headers | Delivery Engine pages keep `hr.wp-header-end` so core notices stay in the standard notice area. |
| 17 | Legacy page taught new setups / raw codes | Migration-only copy; Overview and Legacy counts use the same classifier source. |
| 18 | Experimental/future flags looked usable | Unsupported shipment/tracking/timeline flags are unavailable/read-only in Advanced. |

---

## 3. Release-blocker root causes (local)

1. **Wizard → Preview:** Preview `admin_post` / `admin_init` handling was not strictly page-gated, so Setup Guide Continue could land on Delivery Settings Preview.
2. **Administrator access:** Newly introduced capabilities were registered mainly on activation. Replacing the plugin folder on an already-active install did not grant them. Some customize/edit URLs also targeted a page that was not registered for the role.
3. **Contradictory status:** Overview, Settings, and Preview each inferred “active” from different flag/setup combinations.
4. **Prior-install classification:** Missing Site-wide Defaults was treated like a fresh incomplete setup, including catalog-wide Needs Attention, even while RC.2 runtime still served customers.
7. **Preview readiness:** Assessing the empty default slice (`''`) could reject a valid product-specific Local Delivery option.

---

## 4. What did not change

- Plugin version string (still `1.0.0-rc.3-qa.1` in the working tree; public version remains untagged RC.3).
- Schema target `3`.
- EffectiveConfigurationResolver inheritance semantics.
- Cart / checkout / shipping / order snapshot runtime.
- FLAIROC.
- RC.2 tag `v1.0.0-rc.2`.
- No ZIP package. No RC.3 commit or tag.

---

## 5. Tests (local)

| Check | Result |
|-------|--------|
| `composer validate --no-check-publish` | Pass |
| PHP lint on changed PHP | Pass |
| PHPUnit | **270 tests, 1328 assertions, OK** (sibling run in this session) |
| `npm run test:js` | Pass |
| Focused repair tests | `tests/Unit/Configuration/Stage13CR1RepairTest.php` |

Playwright env specs remain a live-WordPress concern and are not a local gate for this repair.

---

## 6. Fixture screenshots regenerated

Capture method is unchanged: PHP fixture HTML (`docs/review/rc3-admin-ui/harness/render-screens.php`) then Playwright Chrome (`harness/capture.mjs`). **Not live WordPress.**

`capture.mjs` now accepts optional screen ids so a subset can be recaptured.

**Regenerated 14 August 2026 (Stage 13C-R1):**

| File | Why |
|------|-----|
| `01-wizard-store-setup.png` | Wizard / prior-install copy path |
| `09-overview.png` | Operational state, counts, labels |
| `11-delivery-options.png` | Estimated Delivery display |
| `13-delivery-areas.png` | Store-aware examples |
| `14-delivery-area-editor.png` | Store-aware examples |
| `15-delivery-charges.png` | Money formatting / examples |
| `16-delivery-charge-editor.png` | Money input precision / examples |
| `18-product-exceptions.png` | Business-only Customized labels |
| `19-needs-attention.png` | Upgrade-aware catalog scan |
| `20-settings.png` | Permissions + unavailable experimental flags |
| `21-legacy-delivery-rules.png` | Migration-only / count source |
| `23-product-using-defaults.png` | Product panel wording |
| `24-product-customized.png` | Customize wording / business fields |
| `25-variation-inherited.png` | Product Settings as immediate source |
| `26-variation-customized.png` | Variation customize wording |
| `27-delivery-preview-ready.png` | Provenance / readiness |
| `28-delivery-preview-needs-attention.png` | Shared readiness with Needs Attention |
| `29-wizard-tablet.png` | Responsive of 01 |
| `30-overview-tablet.png` | Responsive of 09 |
| `31-product-panel-mobile-admin.png` | Responsive of 23 |

**Not recaptured (not required for this repair):** `02`–`08` remaining wizard steps, `10` Site-wide Defaults (page class not in this visual repair set), `12` option editor, `17` pickup locations, `22` diagnostics.

Harness HTML for all screens was rewritten by `render-screens.php`; only the PNGs above were recaptured.

Playwright-core was available at `training/playwright-videos/node_modules/playwright-core`. Capture exit code **0**.

---

## 7. Intentionally deferred

- Owner physical FLAIROC retest of the 18 findings.
- RC.3 package, commit, and tag.
- Full role-management UI (Permissions is informational; capability self-heal is the access fix).
- International processing/transit split fields (unchanged Stage 13B deferral).
- Live storefront / checkout screens.

---

## 8. Staging / owner checks required

1. Replace the active plugin folder on FLAIROC **without** deactivating; confirm Administrator can open Product Exceptions, customize, Preview, and diagnostics.
2. Setup Guide Step 1 Continue → Step 2 (not Preview). Walk Back/Continue through all six steps.
3. Confirm Overview / Settings / Preview / Needs Attention use the same operational story for: fresh install, prior RC.2 still serving, and Stage 13 runtime active.
4. Confirm a valid product-specific Local Delivery option is Preview **Ready**, and Incomplete Desk remains Needs Attention.
5. Confirm Product Exceptions does not list supplier/origin/priority in the normal table.

---

## 9. Companion docs

- `docs/STAGE-13-SITE-WIDE-DELIVERY-DEFAULTS.md`
- `docs/STAGE-13B-WORDPRESS-NATIVE-UX.md`
- `docs/review/rc3-admin-ui/README.md`
