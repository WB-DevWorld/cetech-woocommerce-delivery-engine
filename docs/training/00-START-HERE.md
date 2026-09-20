# Start here — CETECH Delivery Engine staff training

**Plugin:** CETECH WooCommerce Delivery Engine **1.0.0-rc.12** (schema **6**)
**Everyday home:** WordPress admin → **Delivery Engine → Overview**
**Screenshots / videos:** Not recaptured for RC.12 yet. Use these written guides on the live screens. Older RC.2 images or videos are out of date.

You do **not** need to know PHP, databases, or plugin architecture.

Training site `https://training.cetechbpa.com` currently runs RC.12 / schema 6 (training-site qualification: PASS). That is **not** Stable-1.0 certification. Do not treat leftover RC.9 wording, if you see it elsewhere, as current.

---

## How the system fits together

These pieces are connected. They are **not** the same thing.

```text
Product
  ↓
Delivery Rule / effective configuration
  (Site-wide Defaults → product exception → variation exception)
  ↓
Customer destination
  (Country → Region/State → Locality → Postcode when relevant)
  ↓
Canonical geography
  (Location Pack = directory of places, not a price)
  ↓
Delivery Area / Coverage Group
  (where the merchant serves, and which places qualify)
  ↓
Delivery Option
  (the service: Standard Delivery, Same Day, Pickup, …)
  ↓
Delivery Charge
  (the money / rate card for that option in that context)
  ↓
Product page → Cart → Checkout → Order snapshot
```

### Teaching example

| Piece | Example |
|-------|---------|
| Location Pack | Ghana geography |
| Delivery Area | Greater Accra Selected Cities |
| Coverage | Greater Accra · **Selected locations** · Accra, Tema, Madina, Adenta |
| Delivery Option | Standard Delivery |
| Delivery Charge | GH₵30 |

What the customer sees: Ghana → Greater Accra → Accra (or Tema, Madina, Adenta) → **Standard Delivery** at **GH₵30**.

**Location Pack** = geography data / directory of places. It does **not** set delivery prices.

**Delivery Area** = a business-defined area the merchant serves.

**Coverage Group** = which geography inside a Delivery Area qualifies.

**Delivery Option** = the service offered.

**Delivery Charge / Rate Card** = the monetary price/rule for that option in the relevant context.

**Product delivery rule / override** = product-level business configuration. Inheritance is **Global → Product → Variation**, field by field. That hierarchy is **not** the same as Coverage Groups.

Full geography teaching: [14 — Location Packs](14-LOCATION-PACKS-AND-GEOGRAPHY.md) and [15 — Delivery Areas and Coverage Groups](15-DELIVERY-AREAS-AND-COVERAGE-GROUPS.md).

---

## What this plugin is (in one minute)

The Delivery Engine helps the store:

1. Install the **places** you need (Location Packs) and describe **where** you serve (Delivery Areas + Coverage Groups).
2. Set **normal delivery rules once** (Site-wide Defaults).
3. Show customers a clear **Delivery option**, estimate, and fee.
4. Charge the correct **delivery fee** at checkout (server-authoritative; never silent GH₵0).
5. Keep **Delivery information** on the WooCommerce order after payment.
6. When shipment records are enabled, work **delivery shipments** from **Delivery Engine → Shipments**.

Most products should follow the store defaults. Only genuinely different products or variations get exceptions.

The Delivery Engine owns **delivery charges** inside its boundary. It does **not** become merchandise pricing authority. B2BKing / WoodMart / WooCommerce can remain merchandise pricing authorities. FOX/WOOCS can remain currency authority where installed. Training-site coexistence is evidence, not a broader Stable-1.0 certification.

---

## Choose your path

### First-time setup (plugin not configured yet)

1. [12 — Setup, configure, and test](12-SETUP-CONFIGURE-AND-TEST.md) (recommended order, including Location Packs)
2. [14 — Location Packs](14-LOCATION-PACKS-AND-GEOGRAPHY.md) and [15 — Delivery Areas](15-DELIVERY-AREAS-AND-COVERAGE-GROUPS.md)
3. Then [01 — Quick Start](01-QUICK-START.md) for everyday work

### New staff (store already set up)

1. [01 — Quick Start](01-QUICK-START.md)
2. [05 — Staff Training Manual](05-STAFF-TRAINING-MANUAL.md)
3. [04 — Visual Walkthrough](04-VISUAL-WALKTHROUGH.md)

### Experienced staff

1. [03 — Use-Case Playbook](03-USE-CASE-PLAYBOOK.md)
2. [11 — Stage 14 shipments](11-STAGE-14-SHIPMENTS.md) (only if **Shipments** is in the menu)
3. [13 — Feature coverage and confirmation](13-FEATURE-COVERAGE-AND-CONFIRMATION.md)
4. [07 — Troubleshooting FAQ](07-TROUBLESHOOTING-FAQ.md)
5. [08 — Glossary](08-GLOSSARY.md)

### Administrators

1. [12 — Setup, configure, and test](12-SETUP-CONFIGURE-AND-TEST.md)
2. [02 — Complete Administrator Guide](02-COMPLETE-ADMIN-GUIDE.md)
3. [14](14-LOCATION-PACKS-AND-GEOGRAPHY.md) and [15](15-DELIVERY-AREAS-AND-COVERAGE-GROUPS.md)
4. [13 — Feature coverage](13-FEATURE-COVERAGE-AND-CONFIRMATION.md)
5. [11 — Stage 14 shipments](11-STAGE-14-SHIPMENTS.md)
6. [03 — Use-Case Playbook](03-USE-CASE-PLAYBOOK.md)
7. [07 — Troubleshooting FAQ](07-TROUBLESHOOTING-FAQ.md)

### Trainers

1. [06 — Trainer Guide](06-TRAINER-GUIDE.md)
2. [13 — Feature coverage](13-FEATURE-COVERAGE-AND-CONFIRMATION.md)
3. [05 — Staff Training Manual](05-STAFF-TRAINING-MANUAL.md)
4. [04 — Visual Walkthrough](04-VISUAL-WALKTHROUGH.md)
5. [10 — Video Training Library](10-VIDEO-TRAINING-LIBRARY.md)

### Technical support / developers

1. [09 — Technical Support Appendix](09-TECHNICAL-SUPPORT-APPENDIX.md)
   **Not for normal staff.**

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
- **Location Packs**
- Delivery Charges
- Pickup Locations
- Product Exceptions
- **Bulk Tools** (authorised administrators only — not everyday catalog work)
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
| Location Packs, Delivery Areas, Coverage Groups | Administrator / authorised configuration |
| Delivery Options, Delivery Charges, Pickup Locations | Everyday configuration |
| Product Exceptions / Needs Attention | Everyday staff |
| **Shipments** (when enabled) | Authorised shipment staff — [11](11-STAGE-14-SHIPMENTS.md) |
| WooCommerce product editor → **Delivery** tab | Everyday catalog staff |
| Preview Delivery | Everyday staff (contextual) |
| Customer product page / cart / checkout | Everyday (customer journey) |
| WooCommerce order → **Delivery information** | Everyday staff |
| Settings | Administrator |
| Settings → Advanced switches | Administrator only |
| **Bulk Tools** | Administrator / authorised catalog staff |
| Classic Checkout **and** Cart/Checkout Blocks | Everyday (customer journey) |
| Technical diagnostics / private sources | Technical support |
| Customer timeline / carrier APIs / WPML-WCML adapters | Not this release / separate certification |

---

## Golden rules for all roles

1. A Location Pack is a **directory of places**, not a price. Install a pack when you need city/town data WooCommerce does not already provide, or when old locality text needs reconciliation — not merely because a Coverage Group uses **Selected locations** or **Entire selected area except…**.
2. Configure **normal rules once** on Site-wide Defaults. Most products should inherit them.
3. Customize a product or variation **only** for the fields that must differ. Inheritance is field-by-field. Explicit **0.00** is a valid price when you meant it.
4. Coverage Groups (AND/OR geography) are **not** the same as Global → Product → Variation inheritance.
5. Check **Preview Delivery**, then **Test an address**, then the product page, cart, and checkout.
6. Never invent free shipping by leaving pricing incomplete.
7. **Review required** means the upgrade refused to guess. Data was not destroyed. Do not delete areas to “fix” it.
8. Do not change past orders’ delivery details to “fix” future settings.
9. Ask an administrator before changing Settings, Access, Location Packs, or anything that is not in the everyday menu for your role.
10. Customers should see a public **Delivery option**, estimate, and fee — never supplier, origin, or internal codes.
11. Classic Checkout and Cart/Checkout Blocks are both supported. Confirm the fee on the checkout type this store actually uses.
12. Priority is a business number (**lower is checked first**). Do not assume “smallest area automatically wins.”
