# Trainer Guide

**Audience:** People teaching staff to use CETECH Delivery Engine 1.0.0-rc.4  
**Companion course:** [05-STAFF-TRAINING-MANUAL](05-STAFF-TRAINING-MANUAL.md)

Reading the guides is not enough. Trainees must demonstrate skills. Prefer: walk the [Visual Walkthrough](04-VISUAL-WALKTHROUGH.md) on live screens → practise on QA products.

RC.4 screenshots and recaptured videos are deferred. Do not teach from leftover RC.2 images.

---

## Recommended training order

1. Module 1–2 (what it is + menu)  
2. Module 3 (inheritance, field-by-field)  
3. Module 4 (simple product) live demo  
4. Module 5 (variable) live demo  
5. Modules 6–7 (fulfilment + charges) discussion  
6. Modules 8–9 (customer + cart) live demo without payment  
7. Module 10 (orders) using existing QA orders  
8. Modules 11–12 (troubleshooting + boundaries)

Quick Start can be handed out before day one.

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
| Checkout shipping line uses public option label | Modules 7–8 |
| Order Delivery information on #39721 or #39724 | Module 10 |
| Confirm Legacy is **not** in the normal menu | Module 12 |

---

## QA products to use

| ID | Use in training |
|----|-----------------|
| #39705 | Simple product configuration & storefront |
| #39717 | Variable parent |
| #39718 | Variation A inherit path |
| #39719 | Variation B exception / switch demos |
| Orders #39721 / #39724 | Read-only Delivery information |

Do not modify real customer catalogue products for demos. Restore QA configuration if you change it.

---

## Questions to ask trainees

- Where do you start everyday delivery work?  
- What does Use Site-wide Default / Use Product Setting mean?  
- How do you open Preview Delivery?  
- What should you do when Needs Attention lists a product?  
- What two things should a customer see on the product page?  
- What is the difference between Site-wide Defaults and a Product Exception?  
- Why is Legacy Delivery Rules not the normal workflow?  
- What must you never change on a paid historical order without authorisation?

---

## Practice exercises

1. Leave #39705 on inherited defaults; prove via Preview Delivery.  
2. Explain (or apply and restore) one product-level exception on a single field.  
3. Show variation switch A→B on storefront.  
4. Find Delivery information on a sample order.  
5. Triage a “no delivery options” report using the staff FAQ only.

---

## Expected answers (summary)

- Everyday home = Overview  
- Defaults editor = Site-wide Defaults  
- Product exceptions = WooCommerce Delivery tab / Product Exceptions  
- Inheritance Site-wide → Product → Variation, field-by-field  
- Preview Delivery confirms Ready / Currently using  
- Customers see option + estimate only  
- Legacy = not a normal menu or workflow  
- No PHP/SQL/SSH for ordinary staff  
- Past orders keep purchased delivery details  

---

## Pass / fail competency criteria

A trainee **passes** only if they can demonstrate all of:

1. Leave a simple product on Site-wide Defaults, or create a correct single-field exception  
2. Explain inheritance in plain language (including field-by-field)  
3. Open and interpret a variation vs parent  
4. Identify Needs Attention and first safe checks  
5. Find effective settings via Preview Delivery  
6. Read an order’s Delivery information  
7. Explain why Legacy is not the everyday system  
8. Describe the compact customer product/thank-you presentation  

Fail if they hunt for Legacy as the normal editor, invent $0 shipping, or propose developer-only fixes as first steps.

---

## Areas requiring administrator authorisation

- Delivery Engine → Settings (especially Advanced)  
- Settings → Access  
- Technical Diagnostics  
- Private logistics / supplier / origin screens  
- Delivery Charge / Area structural changes beyond assigned work  
- Payment method / COD changes  
- Any production catalogue product outside QA scope  

---

## Suggested refresher training

- 30-minute refresher after first month: Modules 3, 5, 8, 10, 12  
- After any major defaults change: Preview + storefront smoke on QA products  
- After staff look for retired menus: Module 12 practical test again
