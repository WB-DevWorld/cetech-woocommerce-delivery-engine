# Stage 14H — RC.5 owner QA

**Date:** 2026-08-19  
**Failed QA identity:** `1.0.0-rc.5-qa.1` (immutable ZIP; do not overwrite)  
**Repair candidate:** `1.0.0-rc.5-qa.2`  
**Repair commit:** `5bf94e016c2c0bf6295032f4a10544e54635032b` (`fix: harden order summary and payment-confirmed shipment creation`)  
**QA.2 package source commit:** `8d7c4f21e7c2fdb8c8b5e0710dadf4b1ca0826a3` (`chore: prepare rc.5 qa.2`)  
**Schema:** `4`  
**Protected published baseline:** `1.0.0-rc.4` / schema `3` / tag `v1.0.0-rc.4` (untouched)  
**Branch:** `master`  
**QA.1 package source commit:** `b8f04682190fc7472bcf9f70bf1d19bab0c4b26d` (`chore: prepare rc.5 qa.1`)  
**G-R1:** `35a80ddac8b429e07b95015a1d84cff89cd5eddc` (pushed)  
**FLAIROC:** QA.1 **is installed**. Stage 14 shipment/tracking flags were turned **OFF** after the failure. This Cursor repair does **not** modify FLAIROC.  
**Final RC.5 tag:** **none**

---

## Verdict

**RC.5-QA.1 FAILED OWNER QA**

Physical FLAIROC checkout/email and My Account View Order fatals, plus COD shipment creation without payment confirmation.

This is **not** owner QA pass, **not** Stage 14 released, and **not** final `1.0.0-rc.5`.

**RC.5-QA.2 PREPARED FOR RETEST**

Do not claim QA.2 owner QA passed. FLAIROC was not modified by this repair. Owner must install QA.2 by controlled clean-folder replacement.

---

## QA.1 owner failure (physical FLAIROC)

### Checkout displayed a false failure

Customer checked out **FLAIROC Delivery Engine QA Product** with **FLAIROC QA Standard Delivery**. WooCommerce created the order and Stage 14 created a shipment, then checkout displayed:

> There was an error processing your order. Please check for any charges in your payment method and review your order history before placing the order again.

Retries produced legitimate separate orders **39733**, **39734**, **39735** and shipments **39733-D1**, **39734-D1**, **39735-D1**. This was **not** shipment idempotency failure.

### Exact fatal

WooCommerce fatal log: `wp-content/uploads/wc-logs/fatal-errors-2026-08-19-....log`

```text
Uncaught Error: Call to a member function get_id() on null
File: src/Application/Order/CustomerOrderDeliverySummaryBuilder.php
Line: 111
```

QA.1 `map_line_snapshot()` called `$item->get_id()` even though `$item` is not a parameter of that method. PHP treated `$item` as null. RC.4 ended the constructor at three trailing `null` pickup fields and did not pass an order-item ID.

This is **not** a deleted/missing product. Order **39735** line item **19** is `WC_Product_Simple` product **39705** with a valid `_cetech_de_delivery_snapshot`.

### Path A — checkout / transactional email

`CustomerOrderDeliverySummaryBuilder` → `CustomerOrderDeliveryEmailSummaryRenderer` → WooCommerce Processing Order email → COD `process_payment()` → `WC_Checkout` → WC AJAX checkout.

The order already existed. Email rendering crashed. The customer was falsely told checkout failed.

### Path B — My Account → View Order

`CustomerOrderDeliverySummaryBuilder` → `CustomerOrderDeliverySummaryRenderer` → WooCommerce order-details → My Account View Order → WoodMart WooCommerce My Account template.

Order details rendered, then: “There has been a critical error on this website.”

### COD payment-confirmation finding

Orders 39733 / 39734 / 39735:

- status `processing`
- payment method `cod` / Cash on delivery
- `date_paid` NULL
- `is_paid()` YES
- `needs_payment()` NO

Stage 14 still created a shipment for each order because the processing fallback treated `WC_Order::is_paid()` as payment confirmation. WooCommerce `is_paid()` is status-based (`processing`/`completed`), not “money collected”. COD typically does not fire `woocommerce_payment_complete` or set `date_paid`.

### QA.1 owner verdict

**FAIL**

Do not overwrite `cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.1.zip`.

### Preserved FLAIROC evidence (do not modify remotely)

| Item | Value |
|------|--------|
| Orders | 39733, 39734, 39735 |
| Shipments | 39733-D1, 39734-D1, 39735-D1 |
| Shipment / item / event counts | 3 / 3 / 3 |
| Initial event | `created` → `awaiting_fulfilment` |
| Flags after failure | shipment records OFF, tracking OFF |

---

## 1. Release identity

| Item | Value |
|------|--------|
| Failed QA candidate | `1.0.0-rc.5-qa.1` (ZIP immutable) |
| Current QA candidate | `1.0.0-rc.5-qa.2` |
| Plugin header / `CETECH_DE_VERSION` / readme Stable tag | `1.0.0-rc.5-qa.2` |
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

This is a **failed QA.1 checksum**. Do not overwrite this ZIP.

---

## 3b. QA.2 repair package

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.2.zip` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.2.zip` |
| Desktop path | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.2.zip` |
| Bytes | `1013252` |
| SHA-256 | `399529e1b9d5989e991ada434f5e665325aeb85089b2584299996b94a9e02d59` |
| Sidecar | same hash + `  cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.2.zip` |
| ZIP root | exactly one folder `cetech-woocommerce-delivery-engine/` |
| Built from | committed clean `master` at `8d7c4f2` (not `-AllowDirty`) |

This is a **QA candidate checksum**, not a final RC.5 checksum.

---

## 4. Source gates (QA.2)

| Gate | Result |
|------|--------|
| `composer validate --no-check-publish` | valid |
| Production PHP lint | **311 files, 0 failures** |
| PHPUnit | **500 tests, 2753 assertions** |
| Deprecations | **3** (`ReflectionMethod::setAccessible()` PHP 8.5) — same type as QA.1 / G-R1; no new type |
| `npm run test:js` | **11 passed** |
| Playwright | **not run** |

QA.1 baseline was 485 / 2703 / 11 JS / 3 deprecations. Delta is customer-summary identity + payment-confirmation tests.

---

## 5. Extracted QA.2 package verification

Extracted to a disposable directory outside the git repo (`cetech-de-qa2-extract`). Re-ran `scripts/verify-production-package-autoload.php` against the **extracted** tree: **PASS** (exit 0). Verifier success text now correctly reports **Schema target 4**.

| Check | Result |
|-------|--------|
| One plugin root | PASS |
| Version `1.0.0-rc.5-qa.2` | PASS |
| Schema target `4` | PASS |
| Production autoload / Linux-case | PASS |
| Packaged PHP lint | **311 files, 0 failures** |
| No PHPUnit / `vendor/phpunit` / phpunit.xml / tests / node_modules / `.git` / `.env` / package.json | PASS |
| `ENGINE=InnoDB` on all three shipment CREATE TABLE statements | PASS (verifier) |
| Stage 14 critical classes present | PASS |
| Flag defaults OFF | PASS |

---

## 6. Pre-FLAIROC package verdict

**QA.2 PACKAGE READY FOR OWNER RETEST INSTALL**

Do not call owner QA passed. Do not install from this Cursor session.

---

## 7. FLAIROC baseline / installation

QA.1 **is already installed** on FLAIROC. Stage 14 flags are **OFF**. This Cursor task did **not** SSH, hotfix, edit files, or repair the database.

Preserved evidence (do not modify remotely): orders **39733 / 39734 / 39735** and shipments **39733-D1 / 39734-D1 / 39735-D1**.

### Rollback ZIP (keep immediately available)

| Item | Value |
|------|--------|
| RC.4 ZIP | `cetech-woocommerce-delivery-engine-1.0.0-rc.4.zip` (Stage 13G) |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.4.zip` |
| Desktop path | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.4.zip` |
| Tag | `v1.0.0-rc.4` (do not modify) |
| Failed QA.1 ZIP | `cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.1.zip` (keep; do not overwrite) |

### Owner clean-folder install of QA.2 (established procedure)

Before replacement, record:

1. Active plugin version is `1.0.0-rc.5-qa.1`
2. `cetech_de_db_version` is `4`
3. Stage 14 flags OFF
4. Orders 39733 / 39734 / 39735 and shipments 39733-D1 / 39734-D1 / 39735-D1 still present
5. PHP error-log timestamp / last marker
6. Current plugin folder is enough for rollback, plus the RC.4 ZIP above

Then:

1. Deactivate CETECH WooCommerce Delivery Engine.
2. **Delete** the entire `wp-content/plugins/cetech-woocommerce-delivery-engine/` folder (do not overlay).
3. Install **only** `cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.2.zip`.
4. Activate.
5. Do **not** use Code Snippets or a helper plugin.
6. Do **not** call this final RC.5.
7. Do **not** delete the three QA evidence orders/shipments.

### Immediate post-install gates (flags still OFF)

Stop and roll back if any fail:

- Plugin active; reported version `1.0.0-rc.5-qa.2`
- `cetech_de_db_version` = **4**
- Existing QA shipments still present (3 / 3 / 3)
- Storefront and wp-admin healthy
- Order 39735 View Order does **not** fatal
- No new PHP fatal from customer delivery summary

### First QA.2 retest (before remaining Stage 14 checklist)

1. existing Order 39735 View Order
2. customer email-summary rendering
3. one COD checkout — prove NO premature shipment
4. one genuinely payment-confirmed checkout — prove exactly one shipment
5. Thank You / View Order — prove no fatal

Only after those pass resume the remaining Stage 14 owner QA checklist.

Schema 4 tables and the three preserved QA shipments must still exist after folder replacement. Migration must not recreate or wipe them. Stage 14 flags stay **OFF** until the first five retests pass.

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

1. Place an eligible Delivery Engine delivery order and reach a **genuinely payment-confirmed** state (`woocommerce_payment_complete` or a persisted paid date). COD `processing` with `date_paid` NULL must **not** create a shipment.
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

**QA.1 FAIL.** QA.2 retest pending after install of `1.0.0-rc.5-qa.2`. Do not claim owner QA PASS.

---

## 10. Log review

Owner physical session on 2026-08-19 captured the fatal above from WooCommerce `fatal-errors-2026-08-19-....log`. Checkout/email and View Order both crashed in `CustomerOrderDeliverySummaryBuilder.php` line 111.

---

## 11. Defects

### Defect 1 — checkout / email / View Order fatal (release-blocking)

`map_line_snapshot()` dereferenced `$item` that it did not own. Repair: `build_line_from_item()` passes `(int) $item->get_id()` into the mapper. No snapshot rewrite. No current-product lookup.

### Defect 2 — COD shipment without payment confirmation

Processing/completed fallback used `WC_Order::is_paid()`. Repair: `woocommerce_payment_complete` remains eligible; status fallback and retry require persisted `get_date_paid()`. COD `processing` + `date_paid` NULL must not create a shipment.

If owner QA finds a further genuine defect: stop finalization; smallest repair; `1.0.0-rc.5-qa.3` only if another ZIP is required. Do not overwrite QA.1 or QA.2. Do not finalize RC.5.

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

QA.1 failed owner QA. Do not finalize RC.5.

Owner retest of QA.2 must start with:

1. existing Order 39735 View Order;
2. customer email-summary rendering;
3. one COD checkout — prove NO premature shipment;
4. one genuinely payment-confirmed checkout — prove exactly one shipment;
5. Thank You / View Order — prove no fatal.

Only after those pass resume the remaining Stage 14 owner QA checklist.

