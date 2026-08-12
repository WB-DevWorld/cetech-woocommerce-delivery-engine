# Trainer Guide

**Audience:** People teaching staff to use CETECH Delivery Engine 1.0.0-rc.2  
**Companion course:** [05-STAFF-TRAINING-MANUAL](05-STAFF-TRAINING-MANUAL.md)  
**Video library:** [10-VIDEO-TRAINING-LIBRARY](10-VIDEO-TRAINING-LIBRARY.md) · narration scripts in `video-scripts/`

Reading the guides is not enough. Trainees must demonstrate skills. Prefer: watch the matching topic video → follow screenshots in the Visual Walkthrough → practise on QA products.

---

## Recommended training order

1. Module 1–2 (what it is + navigation)  
2. Module 3 (inheritance)  
3. Module 4 (simple product) live demo  
4. Module 5 (variable) live demo  
5. Modules 6–7 (fulfilment + charges) discussion  
6. Modules 8–9 (customer + cart) live demo without payment  
7. Module 10 (orders) using existing QA orders  
8. Modules 11–12 (troubleshooting + Legacy boundaries)

Quick Start can be handed out before day one.

---

## What to demonstrate live

| Demo | Use |
|------|-----|
| Delivery Settings tabs | Everyday home |
| Default → Product inheritance | Module 3–4 |
| Parent #39717 + variation #39718/#39719 | Module 5 |
| Preview Ready / Needs configuration | Modules 4, 11 |
| Storefront Delivery options | Module 8 |
| Order Delivery information on #39721 or #39724 | Module 10 |
| Open Legacy Rules and leave without editing | Module 12 |

---

## QA products to use

| ID | Use in training |
|----|-----------------|
| #39705 | Simple product configuration & storefront |
| #39717 | Variable parent |
| #39718 | Variation A inherit path |
| #39719 | Variation B override / switch demos |
| Orders #39721 / #39724 | Read-only Delivery information |

Do not modify real customer catalogue products for demos. Restore QA configuration if you change it.

---

## Questions to ask trainees

- Where do you edit everyday delivery values?  
- What does Use inherited setting mean?  
- How do you open the effective Preview?  
- What should you do when you see Needs configuration?  
- What is the difference between Delivery Settings and Legacy Delivery Rules?  
- What must you never change on a paid historical order without authorisation?

---

## Practice exercises

1. Leave #39705 on inherited defaults; prove via Preview.  
2. Explain (or apply and restore) one product-level override.  
3. Show variation switch A→B on storefront.  
4. Find Delivery information on a sample order.  
5. Triage a “no delivery options” report using staff FAQ only.

---

## Expected answers (summary)

- Everyday editor = Delivery Settings  
- Inheritance Default → Product → Variation  
- Preview confirms Ready / Currently using  
- Legacy = not normal workflow  
- No PHP/SQL/SSH for ordinary staff  
- Past orders keep purchased delivery details  

---

## Pass / fail competency criteria

A trainee **passes** only if they can demonstrate all of:

1. Configure (or correctly inherit) a simple product in Delivery Settings  
2. Explain inheritance in plain language  
3. Open and interpret a variation setting vs parent  
4. Identify Needs configuration and first safe checks  
5. Find effective setting via Preview  
6. Read an order’s Delivery information  
7. Explain Delivery Settings vs Legacy Rules  

Fail if they rely on Legacy Rules for normal edits, invent $0 shipping, or propose developer-only fixes as first steps.

---

## Areas requiring administrator authorisation

- Delivery Engine → Settings feature switches  
- Legacy Delivery Rules edits  
- Suppliers & Origins changes  
- Rate card / zone structural changes beyond assigned work  
- Payment method / COD changes  
- Any production catalogue product outside QA scope  

---

## Suggested refresher training

- 30-minute refresher after first month: Modules 3, 5, 10, 12  
- After any major settings change: Preview + storefront smoke on QA products  
- After staff mistakes with Legacy Rules: Module 12 practical test again
