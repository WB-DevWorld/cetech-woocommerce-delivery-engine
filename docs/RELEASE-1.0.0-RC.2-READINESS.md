# CETECH WooCommerce Delivery Engine 1.0.0-rc.2

**Audience:** Store owner / administrator  
**Schema:** 3  
**Package:** `cetech-woocommerce-delivery-engine-1.0.0-rc.2.zip`  
**SHA-256:** `f85af02c5b02a88dcbb0fb061108a9ec02aff72569fcfd4e34cbc27af4cc4e3b`  
**Release-candidate commit:** `e75b80c`  
**Date:** 2026-08-12

---

## Release Status

**LIVE AND USABLE — CURRENT CLASSIC CHECKOUT ENVIRONMENT**

Final live release smoke on FLAIROC: **PASS**.

Required production Delivery Engine features are **ON**. Deferred/future features remain **OFF**. Cash on Delivery remains **OFF**.

---

## What Staff Can Do

Staff manage delivery through **Delivery Settings** as the everyday system:

- set **Default Settings** for the store  
- set **Product-Specific Settings** when a product needs different delivery behaviour  
- set **Variation-Specific Settings** when a variation needs its own values  
- preview what customers will get (**Delivery Settings Preview**)  
- manage delivery offers, destination zones, rate cards, logistics profiles, and pickup locations  
- see clear order **Delivery information** after checkout  

Customers choose delivery on the product page. That choice stays with the cart and checkout. WooCommerce charges the correct shipping amount. Compatible products that share the same delivery path share one fixed-per-shipment charge when configured that way. Each order keeps the delivery details staff need.

---

## What Customers Experience

1. Open a product (simple or variable) and choose fulfilment / delivery option.  
2. Add to cart — the choice is remembered.  
3. Classic Checkout shows a clear **Delivery** shipping line with the correct charge (known QA rate **25.00**).  
4. After ordering, wording stays understandable: Fulfilment, Delivery method, Delivery option, Estimated delivery.

WoodMart works with the normal WooCommerce variation flow. **No WoodMart adapter is required.**

---

## What Has Been Verified Live

| Area | Result |
|------|--------|
| RC.2 clean installation | PASS |
| Flags-OFF site smoke | PASS |
| Simple products | PASS |
| Variable products (parent `#39717`, A `#39718`, B `#39719`) | PASS |
| Variable switching / isolation / rapid switch / reset | PASS |
| WoodMart | PASS — no adapter required |
| Cart persistence + reload | PASS |
| Checkout validation | PASS |
| Native WooCommerce shipping | PASS |
| No accidental free shipping | PASS |
| No duplicate shipping | PASS |
| Variable QA order `#39721` (19.99 + 25.00 = 44.99) | PASS; delivery info saved |
| Multi-product order `#39724` (39.98 + 25.00 = 64.98) | PASS; delivery info saved |
| Fixed-per-shipment once (not 50.00); qty 2 still 25.00 | PASS |
| Customer wording | PASS |
| Staff **Delivery information** panel; technical meta hidden | PASS |
| Stage 8C presentation cleanup | PASS |
| Final PHP log (marker 546 → line 548) | PASS — no new Delivery Engine fatals |

Unrelated site noise (WCFM currency dashboard query; prior WoodMart theme warnings) is **not** a Delivery Engine release blocker.

---

## Required Production Settings

Install defaults stay safe/OFF. The live site now uses these intentional states:

### Required ON

| Setting | State | Purpose |
|---------|-------|---------|
| Use the New Delivery Settings System | **ON** | Delivery Settings as live source |
| Use New Delivery Settings for Product Variations | **ON** | Variation inheritance/overrides |
| Show delivery choices on product pages | **ON** | Customer selection |
| Remember customer's delivery choice in cart | **ON** | Persist through checkout |
| Validate delivery choice at checkout | **ON** | Block invalid selections |
| Show delivery fees at checkout | **ON** | Real WooCommerce shipping |
| Save delivery information on orders | **ON** | Staff Delivery information |

### Optional

| Setting | State | Purpose |
|---------|-------|---------|
| Customer order-page delivery summary | ON if desired | Thank-you / My Account |
| Customer email delivery summary | ON if desired | Order emails |

### Deferred / OFF

| Setting | State |
|---------|-------|
| Shipment records | **OFF** |
| Shipment events / tracking / customer tracking timeline | **OFF** |
| WooCommerce Blocks checkout | **OFF** |
| WoodMart adapter | **OFF** (not required) |
| Future marketplace / carrier integrations | **OFF** |
| Cash on Delivery (site payment method) | **OFF** unless the business uses COD |

---

## Known Deferred Features

Not included in RC.2:

- shipment records and shipment events  
- tracking links and customer tracking timeline  
- WooCommerce Blocks checkout  
- live carrier integrations  
- advanced warehouse optimization  
- marketplace integrations  
- final retirement of legacy runtime compatibility  

---

## Legacy Delivery Rules

**Legacy Delivery Rules** remain temporarily for older configuration compatibility and support.

Normal staff should use **Delivery Settings** for day-to-day work. Legacy rules are not a second everyday delivery system. Do not delete legacy compatibility solely as part of this release.

---

## Stage status

| Stage | Status |
|-------|--------|
| 0B–5 | COMPLETE |
| 6 | FUNCTIONALLY COMPLETE |
| 6C | COMPLETE |
| 7 | COMPLETE — NO WOODMART ADAPTER REQUIRED |
| 8 | FUNCTIONALLY COMPLETE |
| 8C | COMPLETE |

---

## Rollback

If a known-good previous package must be restored:

1. Deactivate the current plugin in WordPress.  
2. Move the current plugin folder out of `wp-content/plugins` (keep a backup).  
3. Install the known-good previous package.  
4. Schema remains **3** unless a future migration explicitly changes it.  
5. Do **not** use Code Snippets as a substitute for the plugin.  
6. Do not delete historical order delivery information to “clean up.”

Do not put server credentials or Application Passwords in documentation.

---

## Package identity

- Version: `1.0.0-rc.2`  
- Schema: `3`  
- SHA-256: `f85af02c5b02a88dcbb0fb061108a9ec02aff72569fcfd4e34cbc27af4cc4e3b`  
- Not labeled stable `1.0.0` in this closure (RC.2 is the live-verified Classic Checkout candidate)
