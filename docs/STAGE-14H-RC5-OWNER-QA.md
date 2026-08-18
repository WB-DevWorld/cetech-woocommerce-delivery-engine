# Stage 14H — RC.5 QA.1 owner QA package

**Date:** 2026-08-18  
**QA identity:** `1.0.0-rc.5-qa.1`  
**Schema:** `4`  
**Protected published baseline:** `1.0.0-rc.4` / schema `3` / tag `v1.0.0-rc.4` (untouched)  
**Branch:** `master`  
**Package source commit:** `b8f04682190fc7472bcf9f70bf1d19bab0c4b26d` (`chore: prepare rc.5 qa.1`)  
**G-R1:** `35a80ddac8b429e07b95015a1d84cff89cd5eddc` (pushed)  
**FLAIROC:** **not installed in this stage**  
**Final RC.5 tag:** **none**

---

## Verdict

**RC.5-QA.1 READY FOR OWNER QA**

This is **not** owner QA pass, **not** Stage 14 released, and **not** final `1.0.0-rc.5`.

---

## 1. Release identity

| Item | Value |
|------|--------|
| QA candidate | `1.0.0-rc.5-qa.1` |
| Plugin header / `CETECH_DE_VERSION` / readme Stable tag | `1.0.0-rc.5-qa.1` |
| Eventual final identity | `1.0.0-rc.5` (only after owner QA pass + Stage 14H-FINAL) |
| Schema | `4` |
| Feature-flag defaults | all Stage 14 flags **OFF** |

---

## 2. Settings activation contract

Protected Settings (`Delivery Engine → Settings`), capability `manage_delivery_settings`, nonce `cetech_de_save_delivery_settings`.

| Control | Flag | Default | Settings |
|---------|------|---------|----------|
| Enable shipment records | `enable_shipment_records` | OFF | Exposed. Turns on shipment creation and the staff Shipments workspace for eligible paid Delivery Engine orders. |
| Enable customer tracking links | `enable_tracking_links` | OFF | Exposed independently. Customer **Track shipment** still requires shipment records ON **and** a safe http/https URL. Tracking links ON alone does not create shipments or customer cards. Does not contact carriers. |
| Customer delivery timeline | `enable_customer_timeline` | OFF | Reserved / unavailable. Ordinary Settings cannot enable it. |

Upgrade from RC.4 does **not** enable Stage 14. `ensure_defaults()` writes `0` for missing options.

---

## 3. QA package

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.1.zip` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.1.zip` |
| Desktop path | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.1.zip` |
| Bytes | `1006497` |
| SHA-256 | `3bb2e321e71fca8d0168b1078f06d800e6f4801efacfc7757ede1ebc66a78342` |
| Sidecar | same hash + `  cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.1.zip` |
| ZIP root | exactly one folder `cetech-woocommerce-delivery-engine/` |
| Built from | committed clean `master` at `b8f0468` (not `-AllowDirty`) |

This is a **QA candidate checksum**, not a final RC.5 checksum.

---

## 4. Source gates (before package)

| Gate | Result |
|------|--------|
| `composer validate --no-check-publish` | valid |
| Production PHP lint | **311 files, 0 failures** |
| PHPUnit | **485 tests, 2703 assertions** |
| Deprecations | **3** (`ReflectionMethod::setAccessible()` PHP 8.5) — same type as G-R1; no new type |
| `npm run test:js` | **11 passed** |
| Playwright | **not run** |

G-R1 baseline was 477 / 2667 / 11 JS / 3 deprecations. Delta is Settings activation tests (+8 tests).

---

## 5. Extracted package verification

Extracted to a disposable directory outside the git repo. Re-ran `scripts/verify-production-package-autoload.php` against the **extracted** tree: **PASS** (exit 0). The verifier success line still prints a stale “Schema target 3” phrase; the actual assertion requires `SchemaVersion::TARGET === '4'` and passed.

| Check | Result |
|-------|--------|
| One plugin root | PASS |
| Version `1.0.0-rc.5-qa.1` | PASS |
| Schema target `4` | PASS |
| Production autoload / Linux-case | PASS |
| Packaged PHP lint | **311 files, 0 failures** |
| No PHPUnit / `vendor/phpunit` / phpunit.xml / tests / node_modules / `.git` / `.env` / package.json | PASS |
| `ENGINE=InnoDB` on all three shipment CREATE TABLE statements | PASS |
| Stage 14 classes (schema, repository, planner, creation, workspace, tracking, customer cards, status/ETA, cancel-refund, Needs Attention, Settings) | PASS |
| Flag defaults OFF | PASS |
| Settings expose records + tracking; timeline reserved | PASS |

---

## 6. Pre-FLAIROC package verdict

**QA PACKAGE READY FOR OWNER INSTALL**

Do not call owner QA passed.

---

## 7. FLAIROC baseline / installation

**Not performed by this agent.** Cloudflare historically blocks automated wp-admin HTML; no local FLAIROC credentials were present in the workspace. Do not invent a live install.

### Rollback ZIP (keep immediately available)

| Item | Value |
|------|--------|
| RC.4 ZIP | `cetech-woocommerce-delivery-engine-1.0.0-rc.4.zip` (Stage 13G) |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.4.zip` |
| Desktop path | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.4.zip` |
| Tag | `v1.0.0-rc.4` (do not modify) |

### Owner clean-folder install (established procedure)

Before replacement, record:

1. Active plugin version is `1.0.0-rc.4`
2. `cetech_de_db_version` is `3`
3. Plugin is active; storefront reachable
4. One known RC.4 product path is healthy
5. PHP error-log timestamp / last marker
6. Current plugin folder is enough for rollback, plus the RC.4 ZIP above

Then:

1. Deactivate CETECH WooCommerce Delivery Engine.
2. **Delete** the entire `wp-content/plugins/cetech-woocommerce-delivery-engine/` folder (do not overlay).
3. Install **only** `cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.1.zip`.
4. Activate.
5. Do **not** use Code Snippets or a helper plugin.
6. Do **not** call this final RC.5.

### Immediate post-install gates (flags still OFF)

Stop and roll back if any fail:

- Plugin active; reported version `1.0.0-rc.5-qa.1`
- `cetech_de_db_version` = **4**
- Tables `*_delivery_engine_shipments`, `*_delivery_engine_shipment_items`, `*_delivery_engine_shipment_events` exist
- All three **ENGINE=InnoDB**
- Unique `(order_id, delivery_group_id)` and other Stage 14B indexes present
- **Zero** shipment rows created by migration
- Stage 14 flags still OFF
- Storefront and wp-admin healthy
- No new PHP fatal / migration SQL error

### Short RC.4 compatibility smoke (flags still OFF)

One known product: selector → cart persist → checkout → correct WooCommerce shipping → existing customer delivery presentation.

### Stage 14 activation (only after the smokes pass)

Administrator → Delivery Engine → Settings:

- Enable shipment records = **ON**
- Enable customer tracking links = **ON**
- Customer timeline remains reserved / OFF

No SQL. No SSH-only flag edits. No Code Snippets.

---

## 8. Owner physical QA checklist

Owner-dependent. **Do not mark PASS without Jane’s confirmation.**

### A. Basic delivery shipment

1. Place an eligible Delivery Engine delivery order and reach a paid/eligible state.
2. Confirm **one** shipment created.
3. Open **Delivery Engine → Shipments**.
4. Confirm order, customer, items, Delivery Option, ETA.
5. Mark **Processing**.
6. Add carrier + tracking number + a safe https tracking URL.
7. Confirm status did **not** change merely from saving tracking.
8. Mark **Dispatched**, then **In transit**.
9. Update current ETA with an internal reason.
10. Customer View Order shows current status, current ETA, tracking.
11. **Track shipment** works.
12. Mark **Delivered**; customer card shows Delivered.

### B. Multiple shipments

Air + Sea (or equivalent saved groups): two separate records/cards; no cross-contamination.

### C. Pickup + delivery

Pickup is not turned into a fake delivery shipment.

### D. Delay

Mark Delayed / issue → Needs Attention → recover → delayed issue clears; history remains.

### E. Correction

Wrong status, then **Correct status** with required reason. Customer sees only the final status, not the internal reason.

### F. Cancellation before dispatch

Shipment cancels appropriately.

### G. Cancellation/refund after progress

Physical shipment is not falsely cancelled; Needs Attention appears.

### H. Permissions

- Administrator
- one staff role with `manage_shipments`
- one without `update_shipment_status` if practical

### I. Mobile

Customer View Order on a phone/narrow view: cards and tracking usable.

### J. Privacy

Customer must **not** see: supplier, origin, Logistics Profile, internal cost, Rate Card, private notes/reasons, `delivery_group_id`, technical ids.

---

## 9. Owner QA status

**PENDING**

---

## 10. Log review

**Not started** (no FLAIROC install this stage). After the owner session, inspect PHP log, shipment counts/duplicates, migration errors, and private-data leaks.

---

## 11. Defects

None from local/source/package gates.

If owner QA finds a genuine defect: stop finalization; smallest repair; `1.0.0-rc.5-qa.2` only if another ZIP is required. Do not overwrite QA.1. Do not finalize RC.5.

---

## 12. Training documentation status

**DRAFT ONLY.** Existing RC.4 training remains the published staff set until owner QA accepts the physical UI.

Draft coverage: `docs/training/11-STAGE-14-SHIPMENTS-DRAFT.md`.

After owner QA PASS: recapture screenshots/wording in Stage 14H-FINAL.

---

## 13. Rollback readiness

RC.4 ZIP + tag retained. Procedure: deactivate, delete plugin folder, install RC.4 ZIP, activate. Schema 4 shipment tables remain (empty or with QA rows); disabling flags does not delete shipment history. Do not drop tables to “undo” QA unless uninstall delete-data is explicitly intended.

---

## 14. Intentionally excluded

Carrier APIs, tracking polling/webhooks, shipment emails, customer timeline, guest portal, labels, WooCommerce Fulfillments dual-write, historical backfill, Checkout Blocks, bulk import, GPS/OTP/QR/POD, driver app, Stage 15, final `1.0.0-rc.5` tag, GitHub release.

---

## STOP

WAIT FOR OWNER PHYSICAL QA.

Do not finalize RC.5. Recommend Stage 14H-FINAL only after owner QA pass.
