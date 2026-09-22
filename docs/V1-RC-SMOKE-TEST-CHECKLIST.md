# V1 Release Candidate — Smoke Test Checklist

> **Historical checklist notice (2026-09-22):** retain these early V1 smoke scenarios as supplemental regression coverage. Current release/schema/environment truth comes from the active release issue, `CURRENT-WORK.md`, `docs/STATUS_CURRENT.md`, and later RC/post-RC evidence. Do not assume “all flags off” is the current environment state.

**Plugin:** CETECH WooCommerce Delivery Engine  
**Use with:** [V1-RC-FLAG-MATRIX.md](V1-RC-FLAG-MATRIX.md)

Run on **staging** before tagging V1 RC. Record pass/fail and notes per row.

---

## Baseline — all flags off

- [ ] Plugin activates without PHP errors
- [ ] WooCommerce admin loads; Delivery Engine menu and System Status accessible
- [ ] No product-page delivery selector on storefront
- [ ] Add-to-cart works without delivery fields
- [ ] Checkout completes without delivery validation errors
- [ ] No `delivery_engine_selected_offer` shipping rate when flags off
- [ ] New orders have no `_cetech_de_*` snapshot meta
- [ ] Thank-you / My Account order view has no **Delivery details** section
- [ ] Customer processing/completed email has no delivery summary block
- [ ] System Status shows shipment creation / tracking / public shipment page: **Not enabled**

---

## Selector only

**Flags:** `enable_product_delivery_selector` = on; all others off

- [ ] Simple product shows public-safe delivery options (labels, estimates — no supplier/origin/IDs)
- [ ] Display-only mode: no form capture when capture flag off
- [ ] No cart meta written when capture off

---

## Selector + capture

**Flags:** selector + `enable_cart_delivery_selection_capture` = on

- [ ] Add-to-cart requires delivery selection when multiple options exist
- [ ] Cart line shows public **Delivery** summary only (no hash, IDs, internal data)
- [ ] Protected cart keys (`cetech_de_delivery_selection*`) not exposed in HTML source as customer-visible fields

---

## Cart restore / revalidation

**Flags:** selector + capture on

- [ ] Complete checkout session → cart restore preserves valid selection
- [ ] Manually corrupt session hash in staging → restore strips bad data or cart shows revalidation warning (no silent wrong selection)
- [ ] Cart revalidation does **not** auto-remove lines without admin action

---

## Checkout validation

**Flags:** selector + capture + `enable_checkout_delivery_selection_validation` = on

- [ ] Checkout blocks with customer-safe notice when line missing selection
- [ ] Checkout blocks when selection is stale/invalid (simulate rule change after add-to-cart)
- [ ] Valid cart proceeds to shipping step

---

## Shipping method + rate card

**Flags:** selector + capture + checkout validation + `enable_woocommerce_shipping_rate_calculation` = on

**Prerequisites:** active destination zone, rate card (offer + zone + currency), product rule, captured selection with delivery offer

- [ ] WooCommerce shipping shows method **Delivery** (`delivery_engine_selected_offer`)
- [ ] Rate matches configured rate card (fixed per shipment / per item)
- [ ] Customer rate label is **Delivery** only — no rate-card code, supplier, or origin text

---

## Missing rate card → no free shipping

- [ ] Disable or mismatch rate card for test product/zone → **no** Delivery shipping rate offered
- [ ] Checkout does **not** show $0 / free delivery fallback from this plugin

---

## Unresolved destination → no rate

- [ ] Checkout with address matching no active destination zone → no Delivery rate (or package blocked per calculator)

---

## Order snapshot persistence

**Flags:** full chain through `enable_order_delivery_snapshot_persistence` = on

- [ ] Place test order with valid selections and selected-offer shipping
- [ ] Order item meta: `_cetech_de_delivery_snapshot` (protected/hidden JSON)
- [ ] Order meta: `_cetech_de_delivery_quote_snapshot` when applicable
- [ ] No shipment records or shipment tables created

---

## Admin order snapshot display

- [ ] WooCommerce → Orders → edit order: meta box **Delivery Engine — Order Snapshots**
- [ ] Read-only fields match protected meta; internal IDs in collapsed admin section only
- [ ] Order without snapshots: meta box shows missing state, no PHP errors
- [ ] Malformed snapshot JSON (staging): **Malformed** status, no fatal, meta unchanged after view

---

## Customer order summary (thank-you / My Account)

**Flags:** add `enable_customer_order_delivery_summary` = on

- [ ] Thank-you page shows **Delivery details** for order with valid snapshots
- [ ] My Account → View order shows same safe summary
- [ ] Order without snapshots: no delivery section
- [ ] No product_id, offer_id, rate_card, hash, supplier, or origin in customer HTML

---

## Customer email summary

**Flags:** add `enable_customer_email_delivery_summary` = on

- [ ] Customer **processing** or **completed** email includes delivery summary when snapshots exist
- [ ] **Plain-text** email variant includes text summary (if plain-text emails enabled in WC)
- [ ] **New order** (admin) email does **not** include customer delivery summary
- [ ] Email recipients, subject, and headers unchanged (body append only)

---

## HPOS smoke test

- [ ] On HPOS-enabled store: create/view order in admin
- [ ] Admin snapshot meta box renders
- [ ] Snapshot meta readable via order editor (no direct table queries required for ops)

---

## Privacy scan (customer surfaces)

On storefront cart, checkout, thank-you, My Account, and customer emails, confirm **absent**:

- [ ] Supplier names/details
- [ ] Origin names/details
- [ ] Logistics profile names/details
- [ ] Internal notes / internal costs
- [ ] Rate card ID/code
- [ ] Selection hash
- [ ] Raw snapshot JSON
- [ ] Tracking numbers / shipment status claims

---

## System Status / diagnostics

- [ ] System Status reflects enabled flags accurately
- [ ] Diagnostics show warnings when email summary on but persistence off (if tested)
- [ ] No live order/cart scans in diagnostics

---

## Sign-off

| Environment | Date | Tester | Result |
|-------------|------|--------|--------|
| Staging | | | Pass / Fail |

**Notes:**
