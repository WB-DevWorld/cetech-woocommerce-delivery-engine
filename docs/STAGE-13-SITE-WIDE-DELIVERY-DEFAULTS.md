# Stage 13 — Site-wide Delivery Defaults + Guided Admin Experience

**Document status:** Stage 13 implementation record  
**Plugin version:** `1.0.0-rc.2` (public version unchanged until RC.3 is packaged)  
**Target candidate:** `1.0.0-rc.3`  
**Schema target:** `3` (unchanged)  
**Branch:** `feat/site-wide-delivery-defaults`  
**RC.2 tag:** `v1.0.0-rc.2` — **untouched**  
**Date:** 2026-08-13

---

## 1. Verdict

Local automated coverage is green. Stage 13B-R1 owner UI review corrections are implemented locally (`docs/STAGE-13B-WORDPRESS-NATIVE-UX.md`). Second owner screenshot review, then RC.3 package, then live inheritance QA.

---

## 2. Administrator model

Configure normal delivery rules once per fulfilment type.

- Every eligible existing and future product inherits them automatically.
- Only genuinely different products receive Product-Specific Settings.
- Only genuinely different variations receive Variation-Specific Settings.
- Inheritance is **field-by-field**. Overriding ETA does not freeze rate or delivery option.

Hierarchy:

`SITE-WIDE FULFILMENT DEFAULT → PRODUCT EXCEPTION → VARIATION EXCEPTION`

---

## 3. Storage

No schema 4. No new tables.

| Mechanism | Role |
|-----------|------|
| `configuration_scopes` `global/0/{profile_key}` | Per-fulfilment site-wide defaults |
| `configuration_scopes` `global/0/''` | RC.2 empty-slice fallback root |
| Option `cetech_de_sitewide_defaults` | Active profiles, primary profile, setup completed, applied_at |
| Product scope | Classification + true field exceptions only |
| Variation scope | Field exceptions only |
| `estimated_delivery` | Optional inheritable ETA text |

`FulfilmentProfileRegistry` is the extensible contract. Built-in profiles: In Warehouse, In Store, International. A later supported profile can register and appear in Site-wide Defaults, product classification, and Preview.

---

## 4. Apply Site-wide

Does:

- persist active profiles + primary default
- ensure profile default scopes exist
- convert migrated OVERRIDE/REPLACE fields that **already match** the profile default into INHERIT
- show calculated catalog counts first

Does not:

- copy defaults onto every product
- overwrite product/variation exceptions
- destroy Legacy Delivery Rules
- rewrite historical order snapshots

After apply, later Site-wide Default edits affect inherited fields immediately. No repeated mass-application is required.

---

## 5. RC.2 upgrade

Existing product scopes are **not** treated as intentional exceptions without analysis.

- Matching migrated overrides can be converted to INHERIT after administrator confirmation.
- True field differences remain product/variation exceptions.
- Legacy-only products stay in the Legacy bucket until a later controlled retirement stage.
- Multi-slice products are marked Needs review.

---

## 6. Admin progression

Delivery Settings → Delivery Options → Delivery Areas → Delivery Charges → Pickup Locations → Product Exceptions → Needs Attention → Legacy Delivery Rules (secondary) → technical diagnostic tools (secondary)

Stage 12 RC.2 training materials were **not** rewritten in this stage.

---

## 7. Tests

PHPUnit: **228 tests, 981 assertions, OK** (1 pre-existing deprecation in variable selector asset tests).

Focused Stage 13 coverage: `tests/Unit/Configuration/SiteWideDeliveryInheritanceTest.php` (22 cases including fresh install, classification, later global edits, field-level protection, reset, collections, hard constraints, legacy safety, migration preview, historical snapshot, matching-override conversion).

Stage 6/8 non-regression suites remain green.

Playwright: local label/fixture smoke in `tests/playwright/site-wide-defaults.spec.ts`. Live Cloudflare-backed UI is for RC.3 QA, not CI.

Vitest: selector JS suite unchanged; not required for this stage.
