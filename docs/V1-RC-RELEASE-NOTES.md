# CETECH WooCommerce Delivery Engine — v1.0.0-rc.1

> **Historical release-note notice (2026-09-22):** this document records the early private-staging V1 RC baseline. Current published release candidate is RC.12/schema 6; see `docs/RC12-PROMOTION.md` and `docs/STATUS_CURRENT.md`. Do not use this file as current release identity.

**Status:** Release Candidate (private staging)  
**Package identity:** `cetech-woocommerce-delivery-engine-v1.0.0-rc.1.zip`  
**Plugin header version:** `1.0.0-rc.1`

---

## Scope summary

V1 RC delivers a **feature-flagged** delivery and fulfilment foundation for WooCommerce:

| Area | Delivered in V1 RC |
|------|---------------------|
| Admin configuration | Delivery offers, destination zones/rules, logistics profiles, suppliers, origins, pickup locations, rate cards, audit log |
| Product delivery rules | Variation → product → category resolution |
| Product selector | Public-safe product-page delivery options (display or capture mode) |
| Cart capture | Validated selection stored on cart lines with hash/fingerprint |
| Cart revalidation | Session restore hardening; cart-page warnings |
| Checkout validation | Preflight blocking of stale/invalid/missing selections |
| Shipping | Single WooCommerce method `delivery_engine_selected_offer` (**Delivery** label), rate-card quoting |
| Order snapshots | Protected HPOS-compatible meta at checkout |
| Admin snapshot display | Read-only order meta box |
| Customer order summary | Thank-you / My Account read-only view |
| Customer email summary | Customer order emails (HTML + plain text) |

All customer/runtime features default **off**. Enable incrementally per [V1-RC-FLAG-MATRIX.md](V1-RC-FLAG-MATRIX.md).

---

## Explicit exclusions (not in V1 RC)

- Shipment records or shipment tables
- Tracking timeline or tracking numbers
- Carrier APIs or live carrier quotes
- Driver app / driver accounts
- OTP, QR, GPS, proof-of-delivery workflows
- Automatic order completion from delivery events
- Public REST / Store API exposure
- WooCommerce Blocks checkout support
- Variable product capture (deferred — simple products first)

Reserved flags `enable_shipment_records` and `enable_tracking_links` exist but default **off** with **no runtime behavior**.

---

## Required flags for full V1 workflow

Enable in order (after admin configuration is complete):

1. `enable_product_delivery_selector`
2. `enable_cart_delivery_selection_capture`
3. `enable_checkout_delivery_selection_validation`
4. `enable_woocommerce_shipping_rate_calculation`
5. `enable_order_delivery_snapshot_persistence`
6. `enable_customer_order_delivery_summary` (optional read-only)
7. `enable_customer_email_delivery_summary` (optional read-only)

See [V1-RC-FLAG-MATRIX.md](V1-RC-FLAG-MATRIX.md) for dependencies.

---

## Staging smoke-test requirement

**Do not promote RC beyond staging until** [V1-RC-SMOKE-TEST-CHECKLIST.md](V1-RC-SMOKE-TEST-CHECKLIST.md) passes on a staging site including:

- All flags off baseline
- Full flag chain with rate card + snapshots
- Missing rate card → no free shipping
- HPOS order edit
- Privacy scan on customer surfaces

---

## Known limitations

- Variable product delivery capture deferred
- Mixed-cart line quotes may differ from WooCommerce shipping line total
- `vendor/` must be present in deployment ZIP (Composer autoload required)
- Classic checkout only; Blocks adapter flag off

---

## Upgrade / rollback

**Upgrade:** Replace plugin folder or upload new ZIP; deactivate/reactivate if needed. Database migrations run on boot. Feature flags persist in `wp_options`.

**Rollback:** Deactivate and remove plugin folder. Configuration tables remain unless uninstall delete-data opt-in was used.

---

## Privacy / safety notes

- Customer surfaces show public delivery labels, estimates, and safe summaries only
- Supplier, origin, logistics profile names, internal notes, rate-card IDs, and selection hashes are **not** exposed to customers
- Snapshots use protected order/item meta (underscore prefix)
- Missing quotes never become zero/free shipping
- Email summary does not alter recipients, subjects, headers, or attachments

---

## References

- [V1-RC-PACKAGING-GUIDE.md](V1-RC-PACKAGING-GUIDE.md)
- [V1-RC-FLAG-MATRIX.md](V1-RC-FLAG-MATRIX.md)
- [V1-RC-SMOKE-TEST-CHECKLIST.md](V1-RC-SMOKE-TEST-CHECKLIST.md)
- Phase docs: `docs/PHASE-2A-IMPLEMENTATION.md` through `docs/PHASE-2H4-IMPLEMENTATION.md`
