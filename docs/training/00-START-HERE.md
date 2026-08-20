# Start here — CETECH Delivery Engine staff training (RC.5)

**Plugin:** CETECH WooCommerce Delivery Engine **1.0.0-rc.5**  
**Everyday home:** WordPress admin → **Delivery Engine → Overview**  
**Screenshots / videos:** Not recaptured for RC.5 yet. Use these written guides on the live screens. Older images or videos, if you still have them, are out of date.

You do **not** need to know PHP, databases, or plugin architecture.

---

## What this plugin is (in one minute)

The Delivery Engine helps the store:

1. Set **normal delivery rules once** (Site-wide Defaults).
2. Show customers a clear **Delivery option** and **Estimated delivery**.
3. Charge the correct **delivery fee** at checkout.
4. Keep **Delivery information** on the WooCommerce order after payment.
5. When shipment records are enabled, work **delivery shipments** from **Delivery Engine → Shipments**.

Most products should follow the store defaults. Only genuinely different products or variations get exceptions.

---

## Choose your path

### First-time setup (plugin not configured yet)

1. [12 — How to use each menu](12-SETUP-CONFIGURE-AND-TEST.md) (each screen, with how to do the work)
2. Then [01 — Quick Start](01-QUICK-START.md) for everyday work

### New staff (store already set up)

1. [01 — Quick Start](01-QUICK-START.md) (about 10 minutes)
2. [05 — Staff Training Manual](05-STAFF-TRAINING-MANUAL.md) (self-paced course)
3. [04 — Visual Walkthrough](04-VISUAL-WALKTHROUGH.md) (written screen tour)

### Experienced staff

1. [03 — Use-Case Playbook](03-USE-CASE-PLAYBOOK.md)
2. [11 — Stage 14 shipments](11-STAGE-14-SHIPMENTS.md) (only if **Shipments** is in the menu)
3. [07 — Troubleshooting FAQ](07-TROUBLESHOOTING-FAQ.md)
4. [08 — Glossary](08-GLOSSARY.md)

### Administrators

1. [12 — How to use each menu](12-SETUP-CONFIGURE-AND-TEST.md) if you need each screen explained, including how to do the work
2. [02 — Complete Administrator Guide](02-COMPLETE-ADMIN-GUIDE.md)
3. [11 — Stage 14 shipments](11-STAGE-14-SHIPMENTS.md)
4. [03 — Use-Case Playbook](03-USE-CASE-PLAYBOOK.md)
5. [07 — Troubleshooting FAQ](07-TROUBLESHOOTING-FAQ.md)

### Trainers

1. [06 — Trainer Guide](06-TRAINER-GUIDE.md)
2. [05 — Staff Training Manual](05-STAFF-TRAINING-MANUAL.md)
3. [04 — Visual Walkthrough](04-VISUAL-WALKTHROUGH.md)

### Technical support / developers

1. [09 — Technical Support Appendix](09-TECHNICAL-SUPPORT-APPENDIX.md)  
   **Not for normal staff.**

Videos: [10 — Video Training Library](10-VIDEO-TRAINING-LIBRARY.md) lists the intended walkthroughs. Recapture is deferred.

---

## Safe practice products (QA)

Use these dedicated QA products. Do **not** change real customer catalogue products just to practise.

| ID | What it is |
|----|------------|
| **#39705** | Simple QA product |
| **#39717** | Variable parent QA product |
| **#39718** | Variation A |
| **#39719** | Variation B |

Read-only order examples: **#39721** (variable), **#39724** (multi-product). Do not edit historical delivery details.

---

## Everyday Delivery Engine menu

When setup is complete, staff should see:

- Overview
- Site-wide Defaults
- Delivery Options
- Delivery Areas
- Delivery Charges
- Pickup Locations
- Product Exceptions
- **Shipments** (only after an Administrator turns on shipment records)
- Needs Attention
- Settings

**Setup Guide** appears in the menu only while first-time setup is incomplete. After that, reopen it from **Settings → Run Setup Guide Again**.

**Preview Delivery** is not a left-menu item. Open it from a product’s **Delivery** tab or from Overview.

If a menu item is **not** on the everyday list above, do not use it. Ask an administrator. Old names from older guides are listed only in the [Troubleshooting FAQ](07-TROUBLESHOOTING-FAQ.md).

---

## Surface map (who should use what)

| Surface | Classification |
|---------|----------------|
| Overview | Everyday staff |
| Site-wide Defaults | Administrator / authorised configuration |
| Delivery Options, Delivery Areas, Delivery Charges, Pickup Locations | Everyday configuration |
| Product Exceptions / Needs Attention | Everyday staff |
| **Shipments** (when enabled) | Authorised shipment staff — [11 — Stage 14 shipments](11-STAGE-14-SHIPMENTS.md) |
| WooCommerce product editor → **Delivery** tab | Everyday catalog staff |
| Preview Delivery | Everyday staff (contextual) |
| Customer product page / cart / checkout | Everyday (customer journey) |
| WooCommerce order → **Delivery information** | Everyday staff |
| Settings (customer experience / Access) | Administrator |
| Settings → Advanced switches | Administrator only |
| Technical diagnostics / private sources | Technical support |
| Customer timeline / Blocks checkout / carrier APIs | Not this release |

---

## Golden rules for all roles

1. Configure **normal rules once** on Site-wide Defaults. Most products should inherit them.
2. Customize a product or variation **only** for the fields that must differ. Inheritance is field-by-field.
3. Check **Preview Delivery** after important changes.
4. Never invent free shipping by leaving pricing incomplete.
5. Do not change past orders’ delivery details to “fix” future settings — historical orders keep what the customer paid for.
6. Ask an administrator before changing Settings switches, Access, or anything that is not in the everyday menu.
7. Customers should see a public **Delivery option** and **Estimated delivery** — never supplier, origin, or internal codes.
8. When **Shipments** is on, work the delivery job there. Do not rewrite the delivery details already saved on a paid order.
