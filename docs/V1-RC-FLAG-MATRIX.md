# V1 Release Candidate — Feature Flag Matrix

**Plugin:** CETECH WooCommerce Delivery Engine  
**Version:** 0.1.0 (V1 RC)  
**Schema target:** `2`

This document describes **runtime and customer-facing feature flags** for V1 RC. All flags are stored as `cetech_de_<flag_name>` in `wp_options` and default to **off** unless noted.

## V1 boundary

V1 RC includes configuration, product delivery selection, cart capture, checkout validation, quoted WooCommerce shipping, protected order snapshots, admin snapshot display, customer order summary, and customer email summary.

**Not enabled in V1 RC:**

- Shipment records (`enable_shipment_records` — reserved, no runtime)
- Tracking links / timeline (`enable_tracking_links` — reserved, no runtime)
- Carrier APIs, driver flows, OTP/QR/GPS/POD
- Public REST/Store API
- WooCommerce Blocks checkout (`enable_blocks_adapter` — default off, no adapter wired)
- Automatic order completion from delivery events

---

## Runtime / customer-facing flags

| # | Flag | Default | Meaning |
|---|------|---------|---------|
| 1 | `enable_product_delivery_selector` | **false** | Product-page delivery selector (display-only or form capture) |
| 2 | `enable_cart_delivery_selection_capture` | **false** | Validates and stores delivery selection on add-to-cart |
| 3 | `enable_checkout_delivery_selection_validation` | **false** | Checkout preflight: blocks stale/invalid/missing selections |
| 4 | `enable_woocommerce_shipping_rate_calculation` | **false** | Registers `delivery_engine_selected_offer` shipping method and quotes package rates |
| 5 | `enable_order_delivery_snapshot_persistence` | **false** | Writes protected delivery snapshots to order items/orders at checkout |
| 6 | `enable_customer_order_delivery_summary` | **false** | Read-only delivery summary on thank-you / My Account order view |
| 7 | `enable_customer_email_delivery_summary` | **false** | Read-only delivery summary in customer order emails |

### Reserved post-V1 flags (keep off in V1 RC)

| Flag | Default | Meaning |
|------|---------|---------|
| `enable_shipment_records` | **false** | Reserved for future shipment module — **no V1 runtime behavior** |
| `enable_tracking_links` | **false** | Reserved for future tracking — **no V1 runtime behavior** |
| `enable_customer_timeline` | **false** | Reserved customer timeline — not implemented in V1 |

### Other flags (admin / future)

| Flag | Default | V1 notes |
|------|---------|----------|
| `enable_effective_configuration_runtime` | **false** | Stage 5A ECR source cutover for **simple products**. Independent of selector/cart/checkout/shipping flags. OFF = exact legacy path. Does not create customer UI by itself. Keep OFF on FLAIROC until an approved cutover. |
| `enable_variable_product_ecr_runtime` | **false** | Stage 6A variable/variation ECR cutover. Requires `enable_effective_configuration_runtime`. Defaults OFF; does not affect simple-product Stage 5 behaviour. Keep OFF on FLAIROC until Stage 6B. |
| `enable_blocks_adapter` | false | WooCommerce Blocks checkout not supported in V1 |
| `enable_classic_checkout_adapter` | true | Placeholder; classic checkout is the de facto path |
| Integration adapters (WPML, WCML, WoodMart, WCFM, VitePOS) | false | Detection only; no hard dependency |

### Safe Stage 5B sequencing (future; do not run in Stage 5A)

1. Deploy Stage 2–5A build with **all** customer flags and `enable_effective_configuration_runtime` OFF
2. Schema 2→3 + admin smoke
3. Enable existing customer flags in V1 order above
4. Only then enable `enable_effective_configuration_runtime` for controlled simple-product QA
5. Verify 25.00 quote parity, category-compatibility path, variable unchanged
6. Return ECR source flag OFF unless explicitly approved to leave on

---

## Recommended production enablement order

Enable **only after** admin configuration is complete (delivery offers, zones, rate cards, product rules):

1. `enable_product_delivery_selector`
2. `enable_cart_delivery_selection_capture` *(requires #1)*
3. `enable_checkout_delivery_selection_validation` *(requires #1 + #2)*
4. `enable_woocommerce_shipping_rate_calculation` *(requires #1 + #2 + #3)*
5. `enable_order_delivery_snapshot_persistence` *(requires #1–#4)*
6. `enable_customer_order_delivery_summary` *(read-only; works on historical snapshots; recommend with #5)*
7. `enable_customer_email_delivery_summary` *(read-only; recommend with #5–#6)*

### Upstream dependency chain

```
selector → capture → checkout validation → shipping calculation → snapshot persistence
                                                              ↘ customer summary (page)
                                                              ↘ customer summary (email)
```

- **Shipping calculation** requires all three upstream flags (selector, capture, checkout validation).
- **Snapshot persistence** requires full shipping runtime chain + snapshot flag.
- **Customer summaries** read stored snapshots only; they do not write or repair meta.
- **Email summary** uses the same builder as the customer page summary; diagnostics recommend enabling both customer flags when using email output.

---

## Known V1 limitations

- **Variable product capture** is deferred — selector notice shown; test simple products first.
- **Missing rate card** → no shipping rate (never free/zero fallback).
- **Unresolved destination zone** → no shipping rate.
- **Line quoted snapshot** at order time may differ from WooCommerce shipping line total in mixed carts.
- **HPOS** is supported; smoke-test order edit on HPOS-enabled stores.

---

## Quick reference: all flags off

With every runtime flag **false** (default):

- No product delivery selector
- No cart capture
- No checkout blocking
- No custom shipping rates
- No order snapshot writes
- No customer order delivery summary
- No customer email delivery summary

This is the safe default for fresh installs and staging baselines.
