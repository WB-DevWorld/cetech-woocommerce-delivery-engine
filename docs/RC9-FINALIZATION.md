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
| `composer validate --no-check-publish` | TBD |
| Production PHP lint (`src/`, `database/`, root plugin, uninstall) | TBD |
| PHPUnit | TBD |
| Focused release identity tests | TBD |
| `npm run test:js` | TBD |
| Identity / schema | `CETECH_DE_VERSION` = `1.0.0-rc.9`; `SchemaVersion::TARGET` = `5`; not `1.0.0-dev.blocks.4`; not `1.0.0-rc.8` |
| `scripts/verify-production-package-autoload.php` | run on staged package during ZIP build |
| Playwright / FLAIROC | **not run** |

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
| Product finalize commit (package source) | TBD |
| Tag `v1.0.0-rc.9` | TBD |
| ZIP bytes | TBD |
| SHA-256 | TBD |
| Built from | committed clean `feat/post-rc8-integrations` (not `-AllowDirty`) |

Do **not** rebuild the ZIP after the SHA-256 recording commit. The source commit is the tagged RC.9 identity commit, not the later docs-only hash record.

The diff from packaged Blocks.4 source `796b9a52` to RC.9 source is release identity/documentation only.

---

## 7. Extracted package verification

| Check | Result |
|-------|--------|
| One plugin root | TBD |
| Version `1.0.0-rc.9` | TBD |
| Schema target `5` | TBD |
| Production autoload / Linux-case classmap | TBD |
| Blocks.4 runtime present | TBD |
| Packaged PHP lint | TBD |
| No PHPUnit / tests / node_modules / `.git` / `.env` / nested ZIPs | TBD |
| Historical Blocks / fulfilment / Bulk / RC.8 ZIPs unchanged | TBD |
| Tag `v1.0.0-rc.8` still peels to `6d166227998d4b0f5047fea91944ff024b389810` | TBD |

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

---

## 11. Next step

**STOP.** Do not begin Stage 15, additional integrations, per-item location architecture, or Return/Refund work. Do not modify FLAIROC from Cursor. Do not retag `v1.0.0-rc.8` or earlier.
