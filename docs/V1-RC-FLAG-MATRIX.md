# V1 Release Candidate — Feature Flag Matrix

**Plugin:** CETECH WooCommerce Delivery Engine  
**Version:** `1.0.0-rc.2`  
**Schema target:** `3`  
**Live status:** **VERIFIED ON** for required production features (FLAIROC Classic Checkout)

Install defaults stay **safe/OFF**. Production use is intentional. After the RC.2 final live smoke, required features remain **ON** on the live site.

---

## Normal-language production states (RC.2 live)

### Required production ON

| Setting | Live state | Purpose |
|---------|------------|---------|
| Use the New Delivery Settings System | **ON** | Delivery Settings as the live source |
| Use New Delivery Settings for Product Variations | **ON** | Variation inheritance and overrides |
| Show delivery choices on product pages | **ON** | Customers can choose delivery |
| Remember customer's delivery choice in cart | **ON** | Choice persists to checkout |
| Validate delivery choice at checkout | **ON** | Block stale or invalid selections |
| Show delivery fees at checkout | **ON** | Real WooCommerce shipping charges |
| Save delivery information on orders | **ON** | Staff Delivery information panel |

### Optional

| Setting | Recommendation | Purpose |
|---------|----------------|---------|
| Customer order-page delivery summary | ON if desired | Thank-you / My Account wording |
| Customer email delivery summary | ON if desired | Order email wording |

### Deferred / OFF

| Setting | Live state | Notes |
|---------|------------|-------|
| Shipment records | **OFF** | Post-RC.2 |
| Tracking / customer tracking timeline | **OFF** | Post-RC.2 |
| WooCommerce Blocks checkout | **OFF** | Post-RC.2 |
| WoodMart adapter | **OFF** | Not required |
| Future marketplace / carrier integrations | **OFF** | Post-RC.2 |
| Cash on Delivery (site payment) | **OFF** | Not a Delivery Engine switch; keep off unless the business uses COD |

---

## V1 boundary (this Classic Checkout release)

Includes configuration, product delivery selection (simple + variable), cart capture, checkout validation, quoted WooCommerce shipping, multi-product delivery grouping, protected order snapshots, admin Delivery information, optional customer/email summaries, WoodMart-compatible Classic Checkout.

**Not included in RC.2:** shipment records/events, tracking timeline, Blocks checkout, live carrier APIs, marketplace integrations, final legacy-runtime retirement.

---

## Technical reference — option keys

Flags are stored as `cetech_de_<flag_name>` in `wp_options`. Defaults are **false** unless noted.

### Runtime / customer-facing

| # | Flag | Default | Meaning |
|---|------|---------|---------|
| 1 | `enable_product_delivery_selector` | **false** | Product-page delivery selector |
| 2 | `enable_cart_delivery_selection_capture` | **false** | Cart selection persistence |
| 3 | `enable_checkout_delivery_selection_validation` | **false** | Checkout validation |
| 4 | `enable_woocommerce_shipping_rate_calculation` | **false** | Shipping rates |
| 5 | `enable_order_delivery_snapshot_persistence` | **false** | Order delivery saving |
| 6 | `enable_customer_order_delivery_summary` | **false** | Optional customer page summary |
| 7 | `enable_customer_email_delivery_summary` | **false** | Optional email summary |

### Delivery Settings cutover

| Flag | Default | Production |
|------|---------|------------|
| `enable_effective_configuration_runtime` | **false** | **ON** (required) |
| `enable_variable_product_ecr_runtime` | **false** | **ON** with main cutover (required) |

### Reserved / keep off

| Flag | Default | Notes |
|------|---------|-------|
| `enable_shipment_records` | false | Deferred |
| `enable_tracking_links` | false | Deferred |
| `enable_customer_timeline` | false | Deferred |
| `enable_blocks_adapter` | false | Deferred |
| `enable_woodmart_adapter` | false | Not required |
| Integration adapters (WPML, WCML, WCFM, VitePOS) | false | Detection only |

`enable_classic_checkout_adapter` defaults **true** (classic checkout is the supported path).

---

## Recommended enablement order

1. Configure Delivery Settings / offers / zones / rate cards  
2. Product-page choices  
3. Cart persistence  
4. Checkout validation  
5. Shipping fees  
6. Save delivery information on orders  
7. Optional: customer order + email summaries  
8. New Delivery Settings System, then variations  

### Upstream dependency chain

```
selector → capture → checkout validation → shipping calculation → snapshot persistence
                                                              ↘ customer summary (page)
                                                              ↘ customer summary (email)
```

---

## Quick reference: all flags off

Safe default for fresh installs and flag-OFF install smoke only. The live RC.2 site uses the **Required production ON** set above after smoke.

See also: `docs/RELEASE-1.0.0-RC.2-READINESS.md`.
