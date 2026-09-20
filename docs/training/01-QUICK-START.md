# Quick Start

**Audience:** New staff
**Plugin version:** 1.0.0-rc.12 (schema 6)
**Goal:** Answer the geography and pricing questions you will hit on day one, then do everyday product work safely.

Screenshots are not included in this revision. Follow the live WordPress screens.

Deep guides: [14 — Location Packs](14-LOCATION-PACKS-AND-GEOGRAPHY.md) · [15 — Delivery Areas and Coverage Groups](15-DELIVERY-AREAS-AND-COVERAGE-GROUPS.md)

---

## How the pieces fit (read this first)

```text
Location Pack          = directory of places (not a price)
Delivery Area          = where the merchant serves
Coverage Group         = which places inside that area qualify
Delivery Option        = the service (Standard Delivery, Same Day, Pickup)
Delivery Charge        = the money (example GH₵30)
Product / variation    = extra rules only where you set them
```

**Worked example used everywhere in this training set**

- Location Pack: Ghana geography
- Delivery Area: Greater Accra Selected Cities
- Coverage: Greater Accra · **Selected locations** · Accra, Tema, Madina, Adenta
- Delivery Option: Standard Delivery
- Delivery Charge: GH₵30

Customer sees Ghana → Greater Accra → Accra (or Tema, Madina, Adenta) → Standard Delivery at GH₵30.

---

## Do I need a Location Pack?

A Location Pack is needed when the business requires **deeper locality / city / town directory data that WooCommerce does not already provide**, or when old city/town text needs safe canonical mapping / **Review required** reconciliation.

Country / region coverage can work **without** a locality pack when WooCommerce already provides those administrative locations. Ghana **Country + Region** can work without a Ghana pack.

Deeper city/town selection **normally** requires the pack. Locality search needs pack-backed locality data **where WooCommerce does not already supply those places**.

A Location Pack is **not** required merely because a Coverage Group uses **Selected locations** or **Entire selected area except…**. Those modes use whatever canonical places exist. If you only select WooCommerce countries/regions, no GeoNames pack is required. If you need Accra, Tema, Madina, Adenta as towns, install the Ghana pack.

You do **not** install every country’s pack. Install packs only where you need that extra city/town directory.

Ghana is the training/reference market, not a product limitation.

---

## Where do I install it?

**Delivery Engine → Location Packs**

1. **Country:** `GH` for Ghana.
2. Upload the GeoNames country `.txt`/`.zip`, **or** tick official download from download.geonames.org.
3. **Operation:** **Install or continue** (first time).
4. Click **Install / update pack**.
5. Wait. Import continues in the background.

Details: [14 — Location Packs](14-LOCATION-PACKS-AND-GEOGRAPHY.md).

---

## What does Ready mean?

On **Installed packs**, **ready** means the geography dataset is **usable live**.

- **pending** / **importing** — still working. Do not mash buttons. Use **Continue / retry** only to resume.
- **failed** — read the displayed error, then retry/fix the source.
- Do not edit database status.

Installing a pack does **not** create GH₵30. Prices live on **Delivery Charges**.

---

## What is a Delivery Area?

A business grouping of destinations that share intended delivery treatment.

Example name: **Greater Accra Selected Cities**.

Menu: **Delivery Engine → Delivery Areas** → **Add Delivery Area**.

---

## What is a Coverage Group?

The geography rules **inside** a Delivery Area.

On the area editor the help text says: different levels combine with **AND**; several places at the same level combine with **OR**; extra groups combine with **OR**.

Example: Ghana **AND** Greater Accra **AND** (Accra **OR** Tema **OR** Madina **OR** Adenta).

Details: [15 — Coverage Groups](15-DELIVERY-AREAS-AND-COVERAGE-GROUPS.md).

---

## Which coverage mode should I choose?

Exact RC.12 labels:

| Choose | When |
|--------|------|
| **Entire selected area** | Whole region/country (all of Greater Accra) |
| **Selected locations** | Only named towns (Accra, Tema, Madina, Adenta) — **this is the teaching example** |
| **Entire selected area except…** | Whole region minus named towns (except Ada Foah and Prampram) |

Exclusions override inclusion **inside that group**. The town might still match another Delivery Area.

---

## Where is the delivery price configured?

**Delivery Engine → Delivery Charges** (not Location Packs).

Pick the Delivery Area + Delivery Option, enter the amount (teaching example **GH₵30**). A typed **0.00** means you meant free. A missing charge must never become silent free shipping.

---

## What do I do if something says Review Required?

It does **not** mean data was destroyed. The upgrade refused to guess which canonical place an old text city (for example `"Accra"`) meant.

1. Install/verify the Location Pack until **ready**.
2. **Location Packs** → **Run safe legacy reconciliation**.
3. Open the Delivery Area, confirm the intended places.
4. Tick **I reviewed this migrated coverage** when it is correct.
5. Test the destination.

Do not delete/recreate the area to “clean up.” Reconciliation does not reset all areas and does not overwrite valid manual canonical coverage.

---

## How do I test before customers use it?

1. **Delivery Areas → Test an address** (Primary match / Also matches).
2. Product **Delivery** tab → **Preview Delivery** should be **Ready**.
3. QA product page: country → region → locality → option + fee.
4. Cart, then checkout (Classic and/or Blocks, whichever this store uses).
5. Authorised safe test order only. Read **Delivery information** on the order. Do not rewrite historical snapshots.

---

## Everyday product work (after geography/prices exist)

### Where to go

**Delivery Engine → Overview** is the daily home.

Also know:

- **Site-wide Defaults** — normal rules every eligible product can inherit
- **WooCommerce → Products** — product **Delivery** tab (exceptions)
- **WooCommerce → Orders** — **Delivery information** on a paid order
- **Preview Delivery** — from a product’s Delivery tab or Overview

### How settings inherit

**Site-wide Defaults → Product exception → Variation exception**

- If a product has no special setting, it uses the **Site-wide Default** for its fulfilment type (In Warehouse, In Store, or International).
- If a variation has no special setting, it uses the **product**.
- Changing one field does **not** freeze the other fields. Inheritance is field-by-field.
- Explicit zero is a valid override.

This inheritance is **not** Coverage Group AND/OR.

| Label | Meaning |
|-------|---------|
| **Use Site-wide Default: …** | Keep the store default for this field |
| **Use Product Setting: …** | Variation keeps the parent product value |
| **Set a different fulfilment / delivery method / estimate** | Use a different value here |
| **Reset to Site-wide Defaults** / **Reset to Product Settings** | Remove this item’s exceptions |

### Configure a normal product

Most products need **no product-level work**.

When one product must differ (practice on QA **#39705**):

1. **WooCommerce → Products** → **Delivery** tab.
2. Confirm **Currently using**.
3. **Customize This Product**.
4. Change **only** the fields that must differ.
5. **Save Product Delivery Settings**.

Customized products also appear under **Delivery Engine → Product Exceptions**.

### Configure a variation

1. Edit parent **#39717**.
2. Open variation **#39718** or **#39719**.
3. **Customize This Variation**.
4. Leave **Use Product Setting** when the parent is correct.
5. **Save Variation Delivery Settings**.

### Save and check the shop

Always use the Delivery Engine **Save** button on that screen.

Customers should see:

- public **Delivery option** name
- **Estimated delivery:** … (or **Ready for pickup** when pickup applies)
- the fee when a destination is selected (server price, not a typed customer price)

### Where orders show delivery

WooCommerce order → **Delivery information**. That snapshot stays as purchased.

### When to ask an administrator

- Location Packs, Coverage Groups, review-required coverage, Settings, Access, Bulk Tools
- Missing charges, unexpected GH₵0, or “no locality” after a pack should be ready
- Anything not on the everyday menu for your role

---

## Next

- Full admin pages: [02](02-COMPLETE-ADMIN-GUIDE.md)
- Setup order: [12](12-SETUP-CONFIGURE-AND-TEST.md)
- Problems: [07](07-TROUBLESHOOTING-FAQ.md)
- Words: [08](08-GLOSSARY.md)
