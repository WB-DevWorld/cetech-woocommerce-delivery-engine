# V1 Release Candidate — Feature Flag Matrix

**Plugin:** CETECH WooCommerce Delivery Engine  
**Version:** `1.0.0-rc.2`  
**Schema target:** `3`

This document describes **runtime and customer-facing feature flags**. All flags are stored as `cetech_de_<flag_name>` in `wp_options` and default to **off** unless noted.

Install never silently turns production runtime on. Enable intentionally after configuration and smoke.

---

## V1 boundary (this Classic Checkout release)

Includes configuration, product delivery selection (simple + variable), cart capture, checkout validation, quoted WooCommerce shipping, multi-product delivery grouping, protected order snapshots, admin Delivery information, optional customer/email summaries, WoodMart-compatible Classic Checkout.

**Not enabled in this release:**

- Shipment records (`enable_shipment_records` — reserved, no runtime)
- Tracking links / timeline (`enable_tracking_links` / `enable_customer_timeline` — reserved)
- Carrier APIs, driver flows, OTP/QR/GPS/POD
- Public REST/Store API
- WooCommerce Blocks checkout (`enable_blocks_adapter` — default off, no adapter wired)
- Automatic order completion from delivery events
- Speculative WoodMart adapter (`enable_woodmart_adapter` — keep off; not required)

---

## Runtime / customer-facing flags

| # | Flag | Default | Meaning |
|---|------|---------|---------|
| 1 | `enable_product_delivery_selector` | **false** | Product-page delivery selector |
| 2 | `enable_cart_delivery_selection_capture` | **false** | Validates and stores delivery selection on add-to-cart |
| 3 | `enable_checkout_delivery_selection_validation` | **false** | Checkout preflight: blocks stale/invalid/missing selections |
| 4 | `enable_woocommerce_shipping_rate_calculation` | **false** | Registers shipping method and quotes package rates |
| 5 | `enable_order_delivery_snapshot_persistence` | **false** | Writes protected delivery snapshots at checkout |
| 6 | `enable_customer_order_delivery_summary` | **false** | Thank-you / My Account delivery summary |
| 7 | `enable_customer_email_delivery_summary` | **false** | Customer order email delivery summary |

### Cutover flags (Delivery Settings system)

| Flag | Default | Production cutover |
|------|---------|--------------------|
| `enable_effective_configuration_runtime` | **false** | **ON** for production using Delivery Settings |
| `enable_variable_product_ecr_runtime` | **false** | **ON** with the main ECR flag for variations |

### Reserved / keep off

| Flag | Default | Notes |
|------|---------|-------|
| `enable_shipment_records` | false | Post-release |
| `enable_tracking_links` | false | Post-release |
| `enable_customer_timeline` | false | Post-release |
| `enable_blocks_adapter` | false | Post-release |
| `enable_woodmart_adapter` | false | Not required for current WoodMart site |
| Integration adapters (WPML, WCML, WCFM, VitePOS) | false | Detection only |

`enable_classic_checkout_adapter` defaults **true** (classic checkout is the supported path).

---

## Final recommended production states

| Setting (admin label) | Final recommended state | Purpose |
|-----------------------|-------------------------|---------|
| Use the New Delivery Settings System | **ON** | Delivery Settings as live source |
| Use New Delivery Settings for Product Variations | **ON** | Variation inheritance/overrides |
| Show delivery choices on product pages | **ON** | Customer selection |
| Remember customer's delivery choice in cart | **ON** | Persist to checkout |
| Validate delivery choice at checkout | **ON** | Fail closed on invalid selections |
| Show delivery fees at checkout | **ON** | Real WC shipping |
| Save delivery information on orders | **ON** | Staff Delivery information |
| Customer order-page summary | **ON** if desired | Ready |
| Customer email summary | **ON** if desired | Ready |
| Shipment / tracking / Blocks / WoodMart adapter | **OFF** | Deferred |
| Site COD payment method | **OFF** unless used | Not a Delivery Engine flag |

After a successful production cutover smoke, leave the required Delivery Engine switches **ON**.

---

## Recommended enablement order

1. Configure Delivery Settings / offers / zones / rate cards  
2. `enable_product_delivery_selector`  
3. `enable_cart_delivery_selection_capture`  
4. `enable_checkout_delivery_selection_validation`  
5. `enable_woocommerce_shipping_rate_calculation`  
6. `enable_order_delivery_snapshot_persistence`  
7. Optional: customer order + email summaries  
8. `enable_effective_configuration_runtime` then `enable_variable_product_ecr_runtime`

### Upstream dependency chain

```
selector → capture → checkout validation → shipping calculation → snapshot persistence
                                                              ↘ customer summary (page)
                                                              ↘ customer summary (email)
```

---

## Quick reference: all flags off

Safe default for fresh installs and flag-OFF install smoke:

- No product delivery selector  
- No cart capture  
- No checkout blocking  
- No custom shipping rates  
- No order snapshot writes  
- No customer summaries  

See also: `docs/CLASSIC-CHECKOUT-RELEASE-CANDIDATE-READINESS.md`.
