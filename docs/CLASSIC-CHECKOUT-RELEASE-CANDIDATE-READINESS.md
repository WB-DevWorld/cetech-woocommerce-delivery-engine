# CETECH Delivery Engine — Classic Checkout Release Candidate Readiness

**Package:** `1.0.0-rc.2`  
**Schema:** `3`  
**Date:** 2026-08-12  
**Audience:** Store owner / administrator (business language first)

---

## What the plugin now does

The Delivery Engine is ready for everyday Classic Checkout use on the current site.

Shoppers can choose delivery options on product pages (simple and variable). Their choice stays with the cart, is checked at checkout, and becomes a real WooCommerce shipping charge. Compatible products that share the same delivery path are charged once for fixed-per-shipment rates. Order screens save clear delivery information for staff. WoodMart works with the normal WooCommerce variation flow — no special WoodMart adapter is required.

Install defaults stay **safe/OFF**. Production use requires intentional activation (see below).

---

## What has been verified live

| Check | Result |
|-------|--------|
| Ordinary products | PASS |
| Variable products | PASS (orders `#39717`–`#39721`) |
| Multiple compatible cart items | PASS (order `#39724`, shipping **25.00**) |
| Cart/session | PASS |
| Classic Checkout | PASS |
| Correct WooCommerce shipping | PASS |
| Order creation + saved delivery information | PASS |
| WoodMart | PASS |
| No new Delivery Engine PHP fatals during Stage 8 QA | PASS |

Stage status:

- Stage 6 — functionally complete  
- Stage 6C — presentation cleanup complete  
- Stage 7 — complete; no WoodMart adapter required  
- Stage 8 — functionally complete  
- Stage 8C — final technical order-admin presentation cleanup  

---

## Required production settings

Turn features on **intentionally** after clean install and a short smoke. Do not assume install turns them on.

| Setting | Final recommended state | Purpose |
|---------|-------------------------|---------|
| Use the New Delivery Settings System | **ON** | Use Delivery Settings (inheritance) as the live source for covered products |
| Use New Delivery Settings for Product Variations | **ON** | Variation-aware inheritance/overrides |
| Show delivery choices on product pages | **ON** | Customer can select delivery |
| Remember customer's delivery choice in cart | **ON** | Selection persists to checkout |
| Validate delivery choice at checkout | **ON** | Block stale/invalid selections |
| Show delivery fees at checkout | **ON** | Real WooCommerce shipping rates |
| Save delivery information on orders | **ON** | Staff Delivery information panel |
| Show delivery summary on customer order pages | **ON** if desired (ready) | Thank-you / My Account wording |
| Show delivery summary in customer emails | **ON** if desired (ready) | Email wording |
| Shipment records / tracking / timeline | **OFF** | Not in this release |
| WooCommerce Blocks adapter | **OFF** | Not in this release |
| WoodMart adapter switch | **OFF** | Not required |
| Cash on Delivery (site payment) | **OFF** unless the site genuinely uses COD | Unrelated to Delivery Engine; keep off unless needed |

**Cash on Delivery** is a WooCommerce payment method, not a Delivery Engine switch. Leave it off unless the business uses it.

After a successful live cutover smoke, **leave the required Delivery Engine runtime switches ON**. Do not turn them back off as we did during temporary QA.

---

## Normal staff experience

Staff operate **one** everyday delivery system:

1. **Delivery Settings** — primary place to manage default / product / variation delivery values and feature switches  
2. Supporting setup: Delivery Offers, Destination Zones, Rate Cards, Logistics Profiles, Pickup Locations  
3. On each order: **Delivery information** (and order delivery summary when useful)

**Legacy Delivery Rules** remain only for migration/support while older rules still apply to some products. They are not presented as an equal second daily system.

---

## Customer experience

1. Open a product → choose fulfilment / delivery option (clear labels)  
2. Add to cart → choice remembered  
3. Classic Checkout → shipping shows **Delivery** (or Delivery N / Store pickup when split) with the correct charge  
4. Place order → delivery details saved for staff; optional customer/email summaries when enabled  

---

## Multi-product shipping (simple)

Products that share the same fulfilment path and selected delivery option travel together and share one fixed-per-shipment charge when configured that way.

Different paths (for example pickup vs delivery, or local vs international) split into separate shipping charges.

Quantity of the same compatible shipment does **not** invent extra fixed-per-shipment charges by itself.

---

## Deferred until after this release

- Shipment records and shipment events  
- Tracking links and customer tracking timeline  
- WooCommerce Blocks checkout  
- Live carrier API quoting  
- Advanced warehouse optimization  
- Optional marketplace integrations  
- Speculative WoodMart adapter  

---

## Rollback procedure

1. In **Delivery Settings**, turn Delivery Engine runtime switches **OFF** (safe dormant mode).  
2. If needed, deactivate the plugin from WordPress → Plugins.  
3. Historical orders keep their saved delivery snapshots; do not delete order meta to “clean up.”  
4. Schema remains at **3**; do not attempt to downgrade schema.  
5. Restore the previous plugin ZIP only if a clean reinstall is required; keep a copy of this RC package.

---

## Final live smoke (human)

After clean-install of `1.0.0-rc.2`:

A. Flags OFF — short site/log smoke  
B. Enable the required production switches above  
C. Cart: product `#39717` Variation A + `#39705` → expect **25.00** once (if still compatible)  
D. Quantity 2 on a fixed-per-shipment path → still **25.00**  
E. Checkout labels + shipping correct  
F. Order admin: clean Delivery information; no technical group metadata  
G. No extra order required unless something fails  
H. PHP log: no new Delivery Engine fatal  
I. Leave required switches **ON** for live cutover; COD stays OFF unless used  

---

## Package identity

- Version: `1.0.0-rc.2` (bumped from `1.0.0-rc.1` to include Stage 6C + Stage 8 + Stage 8C)  
- Schema: `3`  
- Not yet labeled stable `1.0.0` until final live smoke passes  
