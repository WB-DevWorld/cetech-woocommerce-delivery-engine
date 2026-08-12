# Start here — CETECH Delivery Engine staff training (RC.2)

**Plugin:** CETECH WooCommerce Delivery Engine **1.0.0-rc.2**  
**Audience routing:** Choose your path below.  
**Everyday system:** **Delivery Engine → Delivery Settings**  
**Do not use as everyday workflow:** Legacy Delivery Rules

---

## What this plugin is (in one minute)

The Delivery Engine helps your store show the right **delivery choices**, charge the right **delivery fee**, and keep clear **delivery information** on orders.

Staff configure delivery in WordPress admin. Customers choose delivery on the product page. Cart and checkout remember that choice. After payment, staff see **Delivery information** on the order.

You do **not** need to know PHP, databases, or plugin architecture.

---

## Choose your path

### New staff

1. [01 — Quick Start](01-QUICK-START.md) (about 10 minutes)
2. [10 — Video Training Library](10-VIDEO-TRAINING-LIBRARY.md) — start with videos **01** and **12**
3. [05 — Staff Training Manual](05-STAFF-TRAINING-MANUAL.md) (self-paced course)
4. [04 — Visual Walkthrough](04-VISUAL-WALKTHROUGH.md) (screenshot tour)

### Experienced staff

1. [03 — Use-Case Playbook](03-USE-CASE-PLAYBOOK.md)
2. [07 — Troubleshooting FAQ](07-TROUBLESHOOTING-FAQ.md)
3. [10 — Video Training Library](10-VIDEO-TRAINING-LIBRARY.md) (topic videos 07–10)
4. [08 — Glossary](08-GLOSSARY.md)

### Administrators

1. [02 — Complete Administrator Guide](02-COMPLETE-ADMIN-GUIDE.md)
2. [03 — Use-Case Playbook](03-USE-CASE-PLAYBOOK.md)
3. [07 — Troubleshooting FAQ](07-TROUBLESHOOTING-FAQ.md)
4. [10 — Video Training Library](10-VIDEO-TRAINING-LIBRARY.md) (especially **02**, **05**, **11**)

### Trainers

1. [06 — Trainer Guide](06-TRAINER-GUIDE.md)
2. [10 — Video Training Library](10-VIDEO-TRAINING-LIBRARY.md) (full set + narration scripts)
3. [05 — Staff Training Manual](05-STAFF-TRAINING-MANUAL.md)
4. [04 — Visual Walkthrough](04-VISUAL-WALKTHROUGH.md)

### Technical support / developers

1. [09 — Technical Support Appendix](09-TECHNICAL-SUPPORT-APPENDIX.md)  
   **Not for normal staff.**

---

## Safe practice products (QA)

Use these dedicated QA products for training practice. Do **not** change real customer catalogue products just to practise.

| ID | What it is |
|----|------------|
| **#39705** | Simple QA product |
| **#39717** | Variable parent QA product |
| **#39718** | Variation A |
| **#39719** | Variation B |

---

## Surface map (who should use what)

| Surface | Classification |
|---------|----------------|
| Delivery Settings (Default / Product / Variation) | Everyday staff |
| Delivery Settings Preview | Everyday staff |
| Delivery Offers, Destination Zones, Rate Cards | Everyday staff / configuration |
| Logistics Profiles, Pickup Locations | Everyday when your store uses them |
| Suppliers & Origins | Administrator (private) |
| WooCommerce order **Delivery information** | Everyday staff |
| Product page / cart / checkout (customer) | Everyday (customer journey) |
| Delivery Engine → Settings (feature switches) | Administrator |
| Legacy Delivery Rules | Legacy / migration — leave alone unless authorised |
| Advanced system details / technical diagnostics | Technical support |
| Shipments / tracking / Blocks adapter | Deferred — not current release |

---

## Playwright capture suite

Reusable browser walkthroughs live in `training/playwright/` (screenshots / smoke). Deliberate **training videos** use the separate harness `training/playwright-videos/` (`video: on`). See [10 — Video Training Library](10-VIDEO-TRAINING-LIBRARY.md). Auth state is local and gitignored — never commit it.

---

## Golden rules for all roles

1. Use **Delivery Settings**, not Legacy Delivery Rules, for normal work.
2. Prefer **Use inherited setting** unless a product or variation truly needs something different.
3. Check **Delivery Settings Preview** after important changes.
4. Never invent free shipping by leaving pricing incomplete.
5. Do not change past orders’ delivery details to “fix” future settings — historical orders keep what the customer paid for.
6. Ask an administrator before changing Settings switches, suppliers/origins, or Legacy Rules.
