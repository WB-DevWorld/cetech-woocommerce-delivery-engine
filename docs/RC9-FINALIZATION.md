# RC.9 finalization

**Document status:** Final post-RC.8 Blocks identity-only release record  
**Release version:** `1.0.0-rc.9`  
**Schema target:** `5` (unchanged from accepted Blocks.4; no migration)  
**Branch:** `feat/post-rc8-integrations`  
**Accepted functional baseline:** frozen packaged source `1.0.0-dev.blocks.4` (`796b9a52add1053520b2e440d60aa88814d6dd0c`)  
**RC.8 tag:** `v1.0.0-rc.8` / `6d166227998d4b0f5047fea91944ff024b389810` — **untouched**  
**Historical Blocks / fulfilment / Bulk QA ZIPs:** **immutable**  
**Date:** 2026-09-01  
**FLAIROC:** **NOT MODIFIED**

---

## 1. Verdict

**RC.9 COMPLETE — `1.0.0-rc.9` PREPARED**

Owner authorization promotes the frozen **`1.0.0-dev.blocks.4`** packaged source to the tagged RC.9 identity. This is identity/finalization only. There is **no** runtime/business-logic change versus Blocks.4.

This is **not** Stage 15. No schema 6. FLAIROC was not modified from Cursor.

---

## 2. Purpose

Freeze the accepted Blocks.4 runtime as:

`1.0.0-rc.9`

Identity/finalization only. No new integrations, no Blocks redesign, no fulfilment redesign, no Bulk expansion, no migration, no refactor.

---

## 3. Owner acceptance baseline (authoritative)

Accepted development source: **`1.0.0-dev.blocks.4`**  
Packaged source SHA: **`796b9a52add1053520b2e440d60aa88814d6dd0c`**  
Blocks.4 ZIP SHA-256: **`494c20a88d88c359f549402de4832828081380c047111a90afdff066fe32ef65`**  
Schema: **5**

Owner authorization: identity-only promotion of that frozen package to `1.0.0-rc.9`.

QA package record: `docs/POST-RC8-BLOCKS-4-QA.md`.  
Repair record: `docs/POST-RC8-BLOCKS-4-CONSTRAINED-FALLBACK.md`.

RC.9 includes the already-accepted Blocks.4 work, inherited unchanged:

- Bulk Tools hardening from previous accepted releases
- Correct In Store Delivery + Store Pickup architecture
- International Air/Sea constraints
- In Warehouse local-delivery constraints
- Pickup zero charge and no delivery shipment
- Fulfilment/customer-facing presentation repairs
- Settings honesty cleanup
- Real native WooCommerce Cart/Checkout Blocks integration
- Blocks managed-package fail-closed validation
- WoodMart coexistence findings
- Deterministic overlapping Delivery Area ordering
- Same-selected-offer pricing inheritance (for example Accra → broader genuinely matching Greater Accra)
- No native WooCommerce fallback for DE-managed packages
- Constrained fallback Delivery Areas stay within their location rules
- Only a ruleless explicit fallback may behave as Everywhere Else
- Test an address uses the WooCommerce country selector
- Admin Primary match / Also matches explanation
- Classic Checkout and Blocks share the same authoritative calculation logic

---

## 4. What RC.9 finalizes

| Area | Outcome |
|------|---------|
| Version identity | `1.0.0-rc.9` (plugin header, `CETECH_DE_VERSION`, readme Stable tag) |
| Schema | Target remains **5** (no RC.9 migration) |
| Runtime | Exact Blocks.4 functionality; no behaviour change |
| Flags | Stage 14 flags still default **OFF** in a fresh install |

---

## 5. Source gates (final)

Run on the RC.9 identity source immediately before packaging:

| Gate | Result |
|------|--------|
| `composer validate --no-check-publish` | **valid** |
| Production PHP lint (`src/`, `database/`, root plugin, uninstall) | **399 files, 0 failures** |
| PHPUnit | **840 tests, 4798 assertions, OK** (5 pre-existing deprecations) |
| Focused release identity tests | **6 tests, 84 assertions, OK** (`SchemaV4InspectionTest`; version is `1.0.0-rc.9`; schema remains `5`) |
| `npm run test:js` | **32 passed / 32** |
| Identity / schema | `CETECH_DE_VERSION` = `1.0.0-rc.9`; `SchemaVersion::TARGET` = `5`; not `1.0.0-dev.blocks.4`; not `1.0.0-rc.8` |
| `scripts/verify-production-package-autoload.php` | **exit 0** on staged package and extracted ZIP (stale “Schema target 4…” success string; actual TARGET is `5`) |
| Playwright / FLAIROC | **not run** |

Accepted Blocks.4 baseline: PHPUnit **840 tests / 4798 assertions**; JS **32 / 32**; production PHP lint **399 / 0**. RC.9 remains green with no runtime regression. The identity test now asserts `1.0.0-rc.9` instead of `1.0.0-dev.blocks.4`. No assertions were weakened.

---

## 6. Package identity

| Item | Value |
|------|--------|
| ZIP | `cetech-woocommerce-delivery-engine-1.0.0-rc.9.zip` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.9.zip` |
| Desktop path | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.9.zip` |
| Root folder | `cetech-woocommerce-delivery-engine/` |
| Schema target | `5` |
| Tag | `v1.0.0-rc.9` (created only after package verification; local only; not pushed) |
| Built with | `scripts/build-v1-rc-package.ps1 -Version 1.0.0-rc.9 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-rc.9.zip` (**no** `-AllowDirty`) |

### Completion table (post-build)

| Item | Value |
|------|--------|
| Product finalize commit (package source) | `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5` |
| Tag `v1.0.0-rc.9` | local annotated tag `e6f98d91fc14585bf85a62f9bbee555b0be7b71e` peeling to package-source `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5` (**not pushed**) |
| ZIP bytes | `1380751` |
| SHA-256 | `08862b8c048b92dd0ff42ded7eff29c3a5e42a42c984ac43e0a603cef50d96fd` |
| Built from | committed clean `feat/post-rc8-integrations` at `e6bc7fb` (not `-AllowDirty`) |

Do **not** rebuild the ZIP after the SHA-256 recording commit. The source commit is the tagged RC.9 identity commit, not the later docs-only hash record.

The diff from packaged Blocks.4 source `796b9a52` to RC.9 source is release identity/documentation only.

---

## 7. Extracted package verification

| Check | Result |
|-------|--------|
| One plugin root | **PASS** — `cetech-woocommerce-delivery-engine/` |
| Version `1.0.0-rc.9` | **PASS** |
| Schema target `5` | **PASS** (`SchemaVersion::TARGET = '5'`) |
| Production autoload / Linux-case classmap | **PASS** (`scripts/verify-production-package-autoload.php` exit 0) |
| Blocks.4 runtime present | **PASS** — `src/Integrations/Blocks/BlocksCheckoutAdapter.php`, `assets/frontend/blocks-checkout.js`, `is_unrestricted_fallback`, `cart_checkout_blocks` |
| Packaged PHP lint | **399 files, 0 failures** (excluding vendor) |
| No PHPUnit / tests / node_modules / `.git` / `.env` / nested ZIPs | **PASS** |
| Historical Blocks / fulfilment / Bulk / RC.8 ZIPs unchanged | **PASS** — RC.8 ZIP still `1307532` bytes / SHA-256 `70ae635e71741663d5084e3cccd0d2246871d7c485efb919310267d7c9d46b03` |
| Tag `v1.0.0-rc.8` still peels to `6d166227998d4b0f5047fea91944ff024b389810` | **PASS** (tag object `2c211eb5b8a3a6af23e67b4d254a6c19c4e4b8ae`) |

---

## 8. Deliberately not in RC.9

- Runtime/business-logic changes versus Blocks.4
- schema 6
- Stage 15 or later roadmap work
- Additional integrations beyond the already-accepted Blocks.4 adapter
- FLAIROC deploy from Cursor
- Reassignment of training-site temporary Blocks QA pages
- Drafting WoodMart Classic layouts 1482 / 1496 / 1500
- A manufactured extra owner-QA cycle (beta testers vet Classic storefront usage)

---

## 9. Historical artifacts left untouched

- Tag `v1.0.0-rc.8` and ZIP `cetech-woocommerce-delivery-engine-1.0.0-rc.8.zip`
- Tag `v1.0.0-rc.7` and ZIP `cetech-woocommerce-delivery-engine-1.0.0-rc.7.zip`
- Tag `v1.0.0-rc.6` and ZIP `cetech-woocommerce-delivery-engine-1.0.0-rc.6.zip`
- `cetech-woocommerce-delivery-engine-1.0.0-dev.blocks.1.zip`–`blocks.4.zip`
- `cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.1.zip`–`fulfilment.4.zip`
- `cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.7.zip`–`bulk.9.zip`

---

## 10. Training deployment after RC.9 ZIP

After package verification, install RC.9 **in place** on training.cetechbpa.com. Do **not** uninstall or delete data.

Keep Classic WoodMart checkout:

- WooCommerce cart page ID = `12`
- WooCommerce checkout page ID = `13`
- WoodMart layouts `1482`, `1496`, `1500` remain **publish**

Do **not** reassign the temporary Blocks QA pages. Do **not** draft the WoodMart layouts.

Verify only:

1. Plugin is Active.
2. Version = `1.0.0-rc.9`.
3. Schema = `5`.
4. Cart page = `12`.
5. Checkout page = `13`.
6. WoodMart layouts 1482 / 1496 / 1500 remain publish.

Then **STOP**. Beta testers perform practical RC.9 vetting through normal Classic storefront usage.

### Training install status (this session)

**NOT INSTALLED FROM CURSOR.** RC.9 packaging and tag are complete. Cursor could not perform the in-place training install:

- No training SSH host or WP-CLI path is configured (SSH hosts are `flairoc` / `woo-app` / `woo-db` only).
- `woo-app` serves production `cetechbpa.com`, which does **not** contain the Delivery Engine. It was **not** modified.
- No training-site credentials exist in local `.env.local`.
- Browser automation for `training.cetechbpa.com/wp-admin` was unavailable.

FLAIROC was **not** modified. The install ZIP is on the Desktop for in-place replacement without uninstall:

`C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.9.zip`

After a human in-place install, confirm only the six checks above. Do not manufacture another large owner-QA cycle.

---

## 11. Next step

**STOP.** Do not begin Stage 15, additional integrations, per-item location architecture, or Return/Refund work. Do not modify FLAIROC from Cursor. Do not retag `v1.0.0-rc.8` or earlier.
