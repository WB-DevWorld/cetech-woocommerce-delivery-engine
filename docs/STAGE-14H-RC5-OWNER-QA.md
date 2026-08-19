# Stage 14H — RC.5 owner QA

**Date:** 2026-08-19  
**Failed QA identities:** `1.0.0-rc.5-qa.1` and `1.0.0-rc.5-qa.2` (ZIPs immutable; do not overwrite)  
**Repair candidate:** `1.0.0-rc.5-qa.3`  
**Inheritance repair commit:** `709bea6bd3ceac27e324040e0d003e456ef43885` (`fix: treat optional ECR fields as valid when required delivery fields resolve`)  
**QA.3 package source commit:** `2a98757b28ba47694f42fbc9cd69497c5c29b988` (`chore: prepare rc.5 qa.3`)  
**Schema:** `4`  
**Protected published baseline:** `1.0.0-rc.4` / schema `3` / tag `v1.0.0-rc.4` (untouched)  
**Branch:** `master`  
**FLAIROC:** **not modified** by this repair. No SSH. No hotfix. No product-meta copy.  
**Final RC.5 tag:** **none**

---

## Verdict

**RC.5-QA.1 FAILED OWNER QA**  
**RC.5-QA.2 FAILED OWNER QA** (additional release blocker: Site-wide inheritance / ECR validity)  
**RC.5-QA.3 PREPARED FOR RETEST**

This is **not** owner QA pass, **not** Stage 14 released, and **not** final `1.0.0-rc.5`.

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

## QA.2 owner failure — Site-wide inheritance / ECR validity (physical FLAIROC)

Owner completed the Setup Guide and applied Site-wide Defaults.

Live policy: `setup_completed = YES`, `primary_profile = in_warehouse`, active profiles `in_warehouse` / `in_store` / `international_fulfilment`.

Ordinary WooCommerce products with **no** Delivery Engine Product Exception received **zero** customer delivery options.

Canonical reproduction:

| Target | Result |
|--------|--------|
| Untouched simple **4156** (no CETECH product meta, no Product scope) | ECR `state = unresolved`, reason `UNRESOLVED_GLOBAL_VALUE`, runtime Success = NO, chosen rules none, customer options 0 |
| Untouched variation **39422** under parent **39420** (no Product/Variation scopes) | Same failure |
| Explicit QA product **39705** | ECR `state = valid`, logistics/supplier/origin/priority set, runtime success, one customer option |

For 4156, `resolveAll(4156, null)` selected the primary Site-wide `in_warehouse` profile (`ordered_slice_keys = ['']`). These inherited values were already valid:

- `fulfilment_availability` = `in_warehouse`
- `fulfilment_choice` = `delivery`
- `estimated_delivery` = `3-5 business days`
- `delivery_offer_ids` = `[1]`

The fields still unresolved were optional/private/defaultable:

- `logistics_profile_id`
- `supplier_id`
- `origin_id`
- `priority`

`SiteWideDefaultsPolicyInterface` was correctly injected. This was **not** a DI/wiring defect and **not** a missing Product Exception.

Root cause: `EffectiveConfigurationValidator` already skipped `ConfigurationFieldRegistry::is_optional()` fields, but the registry marked supplier / origin / logistics profile / priority as required (`is_optional = false`). Downstream runtime (`EcrToRuntimeConfigurationAdapter`) already treats those as nullable / priority default 100. Rate cards already allow nullable logistics/supplier/origin.

Repair: mark those four fields optional in the registry so field-level UNRESOLVED does not fail-close the whole EffectiveConfiguration. Required fields (`fulfilment_availability`, `fulfilment_choice`, `delivery_offer_ids`) still fail closed. No product-meta copy. No extra Product scopes. Inheritance remains GLOBAL → PRODUCT → VARIATION.

WP-CLI diagnostic warnings about nonexistent `ProductDeliveryOption` properties were caused by the diagnostic itself and are **not** application defects.

Queued UX polish (not in this repair): shipment History staff identity, customer Track shipment button appearance, Shipments/Needs Attention notification badges.

### QA.2 owner verdict

**FAIL**

Do not overwrite `cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.2.zip`.

---

## 1. Release identity

| Item | Value |
|------|--------|
| Failed QA candidates | `1.0.0-rc.5-qa.1`, `1.0.0-rc.5-qa.2` (ZIPs immutable) |
| Current QA candidate | `1.0.0-rc.5-qa.3` |
| Plugin header / `CETECH_DE_VERSION` / readme Stable tag | `1.0.0-rc.5-qa.3` |
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

This is a **failed QA.2 checksum**. Do not overwrite this ZIP.

---

## 3c. QA.3 repair package

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.3.zip` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.3.zip` |
| Desktop path | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.3.zip` |
| Bytes | `1014268` |
| SHA-256 | `1376d18de8cda8b00ee65fc3714e18e2335c849cee96e64e0065add4b5b1ab1a` |
| Sidecar | same hash + `  cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.3.zip` |
| ZIP root | exactly one folder `cetech-woocommerce-delivery-engine/` |
| Built from | committed clean `master` at `2a98757` (not `-AllowDirty`) |

This is a **QA candidate checksum**, not a final RC.5 checksum.

---

## 4. Source gates (QA.3)

| Gate | Result |
|------|--------|
| `composer validate --no-check-publish` | valid |
| Production PHP lint | **311 files, 0 failures** |
| Focused inheritance/ECR suite | **74 tests, 272 assertions, OK** (includes new `SiteWideOptionalFieldValidityTest`) |
| PHPUnit | **511 tests, 2823 assertions** |
| Deprecations | **3** (`ReflectionMethod::setAccessible()` PHP 8.5) — same type as QA.2; no new type |
| `npm run test:js` | **11 passed** |
| Playwright | **not run** |

QA.2 baseline was 500 / 2753 / 11 JS / 3 deprecations. Delta is Site-wide optional-field validity tests (+11).

---

## 5. Extracted QA.3 package verification

Extracted to a disposable directory outside the git repo (`cetech-de-qa3-extract`). Re-ran `scripts/verify-production-package-autoload.php` against the **extracted** tree: **PASS** (exit 0). Verifier success text reports **Schema target 4**.

| Check | Result |
|-------|--------|
| One plugin root | PASS |
| Version `1.0.0-rc.5-qa.3` | PASS |
| Schema target `4` | PASS |
| Production autoload / Linux-case | PASS |
| Packaged PHP lint | **311 files, 0 failures** |
| No PHPUnit / `vendor/phpunit` / phpunit.xml / tests / node_modules / `.git` / `.env` / package.json | PASS |
| `ENGINE=InnoDB` on all three shipment CREATE TABLE statements | PASS (verifier) |
| Stage 14 critical classes present | PASS |
| Flag defaults OFF | PASS |

---

## 6. Pre-FLAIROC package verdict

**QA.3 PACKAGE READY FOR OWNER RETEST INSTALL**

Do not call owner QA passed. Do not install from this Cursor session. Do not overwrite QA.1 or QA.2 ZIPs.

---

## 7. FLAIROC baseline / installation

FLAIROC still has a failed QA candidate installed (QA.1 and/or QA.2 depending on the last owner install). Stage 14 flags are **OFF**. This Cursor task did **not** SSH, hotfix, edit files, copy product meta, or repair the database.

Preserved evidence (do not modify remotely): orders **39733 / 39734 / 39735** and shipments **39733-D1 / 39734-D1 / 39735-D1**. Canonical inheritance reproduction remains products **4156**, **39420** / **39422** / **39424** / **39426**, and explicit QA product **39705**.

### Rollback ZIP (keep immediately available)

| Item | Value |
|------|--------|
| RC.4 ZIP | `cetech-woocommerce-delivery-engine-1.0.0-rc.4.zip` (Stage 13G) |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.4.zip` |
| Desktop path | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.4.zip` |
| Tag | `v1.0.0-rc.4` (do not modify) |
| Failed QA.1 ZIP | `cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.1.zip` (keep; do not overwrite) |
| Failed QA.2 ZIP | `cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.2.zip` (keep; do not overwrite) |

### Owner clean-folder install of QA.3 (established procedure)

Before replacement, record:

1. Active plugin version (`1.0.0-rc.5-qa.1` or `1.0.0-rc.5-qa.2`)
2. `cetech_de_db_version` is `4`
3. Stage 14 flags OFF
4. Orders 39733 / 39734 / 39735 and shipments 39733-D1 / 39734-D1 / 39735-D1 still present
5. Untouched products 4156 / 39420 / 39422 / 39424 / 39426 still have no Product/Variation Exception rows created merely to inherit
6. PHP error-log timestamp / last marker
7. Current plugin folder is enough for rollback, plus the RC.4 ZIP above

Then:

1. Deactivate CETECH WooCommerce Delivery Engine.
2. **Delete** the entire `wp-content/plugins/cetech-woocommerce-delivery-engine/` folder (do not overlay).
3. Install **only** `cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.3.zip`.
4. Activate.
5. Do **not** use Code Snippets or a helper plugin.
6. Do **not** call this final RC.5.
7. Do **not** delete the three QA evidence orders/shipments.
8. Do **not** create Product/Variation rows merely so products can inherit.

### Immediate post-install gates (flags still OFF)

Stop and roll back if any fail:

- Plugin active; reported version `1.0.0-rc.5-qa.3`
- `cetech_de_db_version` = **4**
- Existing QA shipments still present (3 / 3 / 3)
- Storefront and wp-admin healthy
- Order 39735 View Order does **not** fatal
- No new PHP fatal from customer delivery summary

### First QA.3 retest (inheritance first; then remaining Stage 14 checklist)

Inheritance acceptance (this repair):

1. Existing simple product 4156 automatically inherits Site-wide configuration and shows a usable customer Delivery Option.
2. Variable parent 39420 and variations 39422 / 39424 / 39426 inherit correctly.
3. Explicit QA product 39705 continues working unchanged.
4. No Product/Variation rows need to be created merely to inherit.
5. Delivery Areas and Delivery Charges continue resolving at cart/checkout.
6. A new untouched simple product inherits immediately.
7. A new untouched variation inherits immediately.
8. Existing Product Exceptions remain exceptions.
9. Existing Stage 14 shipment/payment/tracking functionality is unchanged.

Then confirm the earlier QA.1/QA.2 repairs still hold:

10. existing Order 39735 View Order
11. customer email-summary rendering
12. one COD checkout — prove NO premature shipment
13. one genuinely payment-confirmed checkout — prove exactly one shipment
14. Thank You / View Order — prove no fatal

Only after those pass resume the remaining Stage 14 owner QA checklist.

Schema 4 tables and the three preserved QA shipments must still exist after folder replacement. Migration must not recreate or wipe them. Stage 14 flags stay **OFF** until the inheritance and payment-confirmation retests pass.

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

**QA.1 FAIL.** **QA.2 FAIL** (Site-wide inheritance). **QA.3 PREPARED FOR RETEST.** Do not claim owner QA PASS.

---

## 10. Log review

Owner physical session on 2026-08-19 captured the fatal above from WooCommerce `fatal-errors-2026-08-19-....log`. Checkout/email and View Order both crashed in `CustomerOrderDeliverySummaryBuilder.php` line 111.

---

## 11. Defects

### Defect 1 — checkout / email / View Order fatal (release-blocking)

`map_line_snapshot()` dereferenced `$item` that it did not own. Repair: `build_line_from_item()` passes `(int) $item->get_id()` into the mapper. No snapshot rewrite. No current-product lookup.

### Defect 2 — COD shipment without payment confirmation

Processing/completed fallback used `WC_Order::is_paid()`. Repair: `woocommerce_payment_complete` remains eligible; status fallback and retry require persisted `get_date_paid()`. COD `processing` + `date_paid` NULL must not create a shipment.

### Defect 3 — Site-wide inheritance / optional ECR fields (release-blocking)

`EffectiveConfigurationValidator` already skipped optional fields, but `ConfigurationFieldRegistry` marked `logistics_profile_id`, `supplier_id`, `origin_id`, and `priority` as required. Untouched products that inherited a complete Site-wide delivery policy therefore became whole-configuration Unresolved (`UNRESOLVED_GLOBAL_VALUE`). Repair: mark those four fields optional. Required delivery fields still fail closed. No product-meta copy. No extra Product scopes.

If owner QA finds a further genuine defect: stop finalization; smallest repair; `1.0.0-rc.5-qa.4` only if another ZIP is required. Do not overwrite QA.1, QA.2, or QA.3. Do not finalize RC.5.

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

Queued UX polish (do not include in this inheritance repair):

- Shipment History should display the actual staff/account identity, not merely “Staff”.
- Customer “Track shipment” must look clearly like a button.
- Delivery Engine / Shipments / Needs Attention should have useful notification badges/count bubbles for new/unresolved activity.

---

## STOP

QA.1 failed owner QA. QA.2 failed owner QA (Site-wide inheritance / ECR validity). Do not finalize RC.5.

Owner retest of QA.3 must start with:

1. Existing simple product 4156 automatically inherits Site-wide configuration.
2. Variable parent 39420 and variations 39422 / 39424 / 39426 inherit correctly.
3. Explicit QA product 39705 continues working unchanged.
4. No Product/Variation rows need to be created merely to inherit.
5. Delivery Areas and Delivery Charges continue resolving at cart/checkout.
6. A new untouched simple product inherits immediately.
7. A new untouched variation inherits immediately.
8. Existing Product Exceptions remain exceptions.
9. Existing Stage 14 shipment/payment/tracking functionality is unchanged.

Then confirm the earlier repairs still hold (View Order 39735, email summary, COD no premature shipment, payment-confirmed one shipment, Thank You / View Order no fatal).

Only after those pass resume the remaining Stage 14 owner QA checklist.

