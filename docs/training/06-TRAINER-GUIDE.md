# Trainer Guide

**Audience:** People teaching staff to use CETECH Delivery Engine **1.0.0-rc.8** (schema **5**; training-site package `1.0.0-dev.blocks.4`)  
**Companion course:** [05-STAFF-TRAINING-MANUAL](05-STAFF-TRAINING-MANUAL.md)  
**Coverage register:** [13-FEATURE-COVERAGE-AND-CONFIRMATION.md](13-FEATURE-COVERAGE-AND-CONFIRMATION.md) — tick **every** implemented feature Pass / Fail / N/A. Reading the guides is not confirmation.

Reading the guides is not enough. Trainees must demonstrate skills. Prefer: walk the [Visual Walkthrough](04-VISUAL-WALKTHROUGH.md) on live screens → practise on QA products.

Screenshots and recaptured videos are deferred. Teach from the live screens and these written guides. Do not teach from leftover older images.

For shipment staff, add playbook use cases 20–30 and [11 — Stage 14 shipments](11-STAGE-14-SHIPMENTS.md) after catalogue training. Old menu names from older guides belong only in the [Troubleshooting FAQ](07-TROUBLESHOOTING-FAQ.md) — do not make them a teaching topic.

---

## Recommended training order

1. Module 1–2 (what it is + menu)  
2. Module 3 (inheritance, field-by-field)  
3. Module 4 (simple product) live demo  
4. Module 5 (variable) live demo  
5. Modules 6–7 (fulfilment + charges) discussion  
6. Modules 8–9 (customer + cart) live demo without payment  
7. Module 13 (Classic and/or Blocks checkout, mixed pickup if offered)  
8. Module 14 (Test an address Primary / Also matches)  
9. Module 10 (orders) using existing QA orders  
10. Modules 11–12 (troubleshooting + boundaries)  
11. If Shipments is on: playbook 20–30 on the live Shipments screen (authorised staff only)  
12. Administrators: Module 15 Bulk Tools (Preview only unless a change window is authorised)  
13. Close the session by ticking [13 — Feature coverage](13-FEATURE-COVERAGE-AND-CONFIRMATION.md)

For a store that is not configured yet, start with [12 — How to use each menu](12-SETUP-CONFIGURE-AND-TEST.md) before the staff course.

Quick Start can be handed out before day one once setup is complete.

---

## What to demonstrate live

| Demo | Use |
|------|-----|
| Overview + everyday menu | Everyday home |
| Site-wide Defaults → product with no exception | Modules 3–4 |
| Product Delivery tab → Customize This Product | Module 4 |
| Parent #39717 + variation #39718/#39719 | Module 5 |
| Preview Delivery Ready / Needs Attention | Modules 4, 11 |
| Storefront compact selector (option + estimate only) | Module 8 |
| Checkout shipping line uses public option label (Classic **and** Blocks if used) | Modules 7–8, 13 |
| Delivery Areas **Test an address** with region name and checkout short code; **Primary match** / **Also matches** | Modules 7, 14 |
| Mixed In Store Delivery + Pickup (fee + FREE; no false missing price) | Module 13, playbook 34 |
| International Air/Sea only; In Warehouse local only | Module 6, playbook 36–37 |
| Broader-area pricing without copying charges onto every city | Module 14, playbook 32 |
| Order Delivery information on #39721 or #39724 | Module 10 |
| Everyday menu only; Settings / Bulk Tools need authorisation | Module 12 |
| Shipments list + one detail screen (when enabled) | Playbook 21–22 |
| Bulk Tools five tabs, Preview before Apply (administrators) | Module 15 |

---

## QA products to use

| ID | Use in training |
|----|-----------------|
| #39705 | Simple product configuration & storefront |
| #39717 | Variable parent |
| #39718 | Variation A inherit path |
| #39719 | Variation B exception / switch demos |
| Orders #39721 / #39724 | Read-only Delivery information |

Do not modify real customer catalogue products for demos. Restore QA configuration if you change it. Do not create extra paid orders just to practise shipments.

---

## Questions to ask trainees

- Where do you start everyday delivery work?  
- What does Use Site-wide Default / Use Product Setting mean?  
- How do you open Preview Delivery?  
- What should you do when Needs Attention lists a product?  
- What two things should a customer see on the product page?  
- Does this store use Classic Checkout, Cart/Checkout Blocks, or both?  
- What does Test an address **Also matches** mean?  
- Why must you not copy Air onto every city Delivery Area?  
- Is pickup 0.00 the same as a missing charge?  
- What is the difference between Site-wide Defaults and a Product Exception?  
- What must you never change on a paid historical order without authorisation?  
- (Shipment staff) Where do you work a delivery job, and what must you never type when creating a shipment from an order?

---

## Practice exercises

1. Leave #39705 on inherited defaults; prove via Preview Delivery.  
2. Explain (or apply and restore) one product-level exception on a single field.  
3. Show variation switch A→B on storefront.  
4. Find Delivery information on a sample order.  
5. Triage a “no delivery options” report using the staff FAQ only.  
6. Confirm checkout on the type this store uses (Classic and/or Blocks).  
7. Run Test an address and explain Primary / Also matches.  
8. (Shipment staff) Open one shipment read-only and name status, delivery option, and whether tracking is present.  
9. (Administrators) Open Bulk Tools and name Preview vs Apply.

---

## Expected answers (summary)

- Everyday home = Overview  
- Defaults editor = Site-wide Defaults  
- Product exceptions = WooCommerce Delivery tab / Product Exceptions  
- Inheritance Site-wide → Product → Variation, field-by-field  
- Preview Delivery confirms Ready / Currently using  
- Customers see option + estimate only  
- Classic and Blocks both use Delivery Engine fees  
- Pickup 0.00 is valid; missing charges fail closed  
- Nested city-in-region overlap is normal; do not duplicate city charges  
- Settings / Access / Bulk Tools / hidden support screens need an administrator  
- No PHP/SQL/SSH for ordinary staff  
- Past orders keep purchased delivery details  
- Shipments (when on) = the delivery work record; built from saved order details

---

## Pass / fail competency criteria

A trainee **passes** only if they can demonstrate all everyday items in [13 — Feature coverage](13-FEATURE-COVERAGE-AND-CONFIRMATION.md) for their role (A + the C checks this store uses). Administrators also pass B. Shipment staff also pass D (or N/A). Bulk operators also pass E.

Minimum live demonstration (all roles):

1. Leave a simple product on Site-wide Defaults, or create a correct single-field exception  
2. Explain inheritance in plain language (including field-by-field)  
3. Open and interpret a variation vs parent  
4. Identify Needs Attention and first safe checks  
5. Find effective settings via Preview Delivery  
6. Read an order’s Delivery information  
7. Stay inside the everyday menu; ask before Settings / Access / Bulk Tools  
8. Describe the compact customer product/thank-you presentation  
9. Show the checkout shipping line for this store’s checkout type  
10. Explain Test an address Primary / Also matches if the store uses overlapping areas  

Shipment staff also pass only if they can find a shipment, explain that it is not a new order, and know not to re-type delivery prices.

Fail if they invent $0 shipping, copy Air onto every city to “fix” overlap, change Settings without authorisation, or propose developer-only fixes as first steps.

---

## Areas requiring administrator authorisation

- Delivery Engine → Settings (especially Advanced)  
- Settings → Access  
- **Bulk Tools** Apply / import  
- Technical Diagnostics  
- Private logistics / supplier / origin screens  
- Delivery Charge / Area structural changes beyond assigned work  
- Payment method / COD changes  
- Turning shipment records or tracking links on or off  
- Any production catalogue product outside QA scope  

---

## Suggested refresher training

- 30-minute refresher after first month: Modules 3, 5, 8, 10, 12  
- After any major defaults change: Preview + storefront smoke on QA products (Classic and Blocks if both are in use)  
- After staff open a screen that is not in the everyday menu: Module 12 practical test again  
- After Shipments is turned on: playbook 20–30  
- After overlapping areas or International charges change: playbook 31–33 and 36
