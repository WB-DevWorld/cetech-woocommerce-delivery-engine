# Location Packs and geography

**Audience:** Administrators and authorised configuration staff
**Plugin:** CETECH WooCommerce Delivery Engine **1.0.0-rc.12** (schema **6**)
**Open:** WordPress admin → **Delivery Engine → Location Packs**

You do not need to know databases, PHP, or migration internals.

Companion: [15 — Delivery Areas and Coverage Groups](15-DELIVERY-AREAS-AND-COVERAGE-GROUPS.md).

---

## One-sentence answer

A **Location Pack** is a directory of real places for one country. It tells the Delivery Engine which towns, cities, administrative areas, and localities exist. **It does not set a delivery price.**

---

## The teaching example used in this set

| Piece | What it is | What it is not |
|-------|------------|----------------|
| **Location Pack** | Ghana geography (the list of places) | A GH₵30 fee |
| **Delivery Area** | Greater Accra Selected Cities | A shipping carrier |
| **Coverage Group** | Greater Accra, **Selected locations**: Accra, Tema, Madina, Adenta | A Delivery Option |
| **Delivery Option** | Standard Delivery (the service customers choose) | A map of towns |
| **Delivery Charge** | GH₵30 for Standard Delivery in that area | The list of places |

These are **connected**. They are **not the same thing**.

What the customer should see after this example is live: they choose Ghana → Greater Accra → Accra (or Tema, Madina, Adenta) and Standard Delivery at **GH₵30**.

---

## What is it?

A Location Pack is country geography data the plugin installs and keeps in WordPress uploads (not inside the plugin files).

In RC.12 the live source is a **GeoNames** country gazetteer (a published list of places). Shoppers never call GeoNames. Only staff install or update a pack.

The directory is **canonical**: each place has a stable identity the plugin uses. Matching is not “whatever spelling the customer typed.”

Typical hierarchy:

**Country → Administrative area (Region / State / Province, depending on the country) → Locality (city / town)**

For Ghana, the administrative-area label on screen is **Region**.

---

## Why do I need it?

WooCommerce already knows **countries** and, for many countries, **states / regions**. That is why Ghana + Greater Accra can appear even when no Location Pack is installed.

WooCommerce does **not** ship a complete, searchable list of every locality the business may serve. The Location Pack supplies that deeper list.

You need a pack when you want any of these:

- customers to search and pick a **locality** (Accra, Tema, Madina, …)
- Coverage Groups that use **Selected locations** or **Entire selected area except…**
- upgraded stores to map old text city names (for example `"Accra"`) onto the exact place in the directory

You do **not** need a pack for every country on earth. Install packs for countries you actually serve with locality-level delivery.

Country-wide delivery (the whole country, no city selector) can still work without forcing a locality.

---

## What it is not

A Location Pack is **not**:

- a delivery price
- a Delivery Area
- a Coverage Group
- a Delivery Option
- a shipping carrier
- a customer address book
- a list of products
- an automatic GH₵30 charge for Accra

Installing Ghana geography does **nothing** to Delivery Charges until you create areas, coverage, options, and charges.

---

## Where do I find it?

**Delivery Engine → Location Packs**

You need the same permission used for Delivery Areas. If you cannot see the menu, ask an administrator.

The page heading is **Location Packs**. The intro on the live screen says packs are used by Delivery Areas and the product-page city selector, that shopper requests never call GeoNames, and that packs are stored in WordPress uploads.

Attribution on the page:

> This product uses GeoNames gazetteer data (https://www.geonames.org/) licensed under CC BY 4.0.

**Provider** on the installed-packs table is the data source (GeoNames). **License** is the published licence name (CC BY 4.0). That is credit and legal information for the place list. It is not a CETECH product licence and it is not a shipping contract.

---

## What do I click?

### Install or update a pack

1. Open **Delivery Engine → Location Packs**.
2. Under **Install or update a pack**, set **Country** to the two-letter code (Ghana = `GH`). The field often pre-fills from the WooCommerce store country.
3. Choose **one** source:
   - **Upload gazetteer file:** the official GeoNames country `.txt` or `.zip`.
   - **Official download:** tick **Fetch the official GeoNames country ZIP in the background (download.geonames.org only).**
4. Set **Operation**:
   - **Install or continue** — first install, or continue a pack that is not finished.
   - **Update with a new dataset** — replace with a newer gazetteer file.
   - **Retry / resume current dataset** — resume the same dataset after a failure or interruption.
5. Click **Install / update pack**.

Expect a success notice such as “Location pack for GH queued. Import continues in the background.” Official download uses: “Official GeoNames pack for GH queued for background download.”

### Installed packs table

Columns you will see: **Country**, **Provider**, **Version**, **Checksum**, **Status**, **Installed**, **License**, **Progress**, **Actions**.

**Checksum** is a fingerprint of the file so staff can tell datasets apart. You do not type it.

**Progress** shows imported / processed counts and a phase name while work is running.

If the list is empty: **No location packs installed yet.** That is a real empty state, not a broken plugin.

### Continue / retry

For status **pending**, **importing**, or **failed**, the row offers **Continue / retry**.

Use it to resume or recover. Do **not** click it repeatedly because the import is not instant.

---

## Status model

The **Status** column uses these values:

| Status | Meaning | What you should do |
|--------|---------|--------------------|
| **pending** | Queued, not finished importing | Wait. Use **Continue / retry** if it sits too long. |
| **importing** | Batches are still processing | Wait. Refresh the page later. Do not mash buttons. |
| **ready** | Usable live geography dataset | You may build Coverage Groups and locality search for that country. |
| **failed** | Validation or import did not complete | Read the error under the row. Fix the file/source. Use **Retry / resume current dataset** or **Continue / retry**. |

**Ready = usable live geography dataset.**

Do **not** edit pack status in the database. Do **not** invent a fifth status.

A country can still show WooCommerce regions while the pack is missing or not ready. That is expected.

---

## Background processing

Large country files continue in batches / background jobs. RC.12 does not require you to sit on the page until every town is imported.

What staff should expect:

- first click queues the work
- refresh later to see **Progress** move
- **Continue / retry** exists for recovery/resumption
- repeatedly clicking **Install / update pack** does not make GeoNames faster

If status stays **importing** for a long time, refresh once, wait for the next batch, then use **Continue / retry** once. If it still does not move, escalate with a screenshot of the row (status, progress, last error). Do not paste server passwords into that request.

---

## Safe post-pack reconciliation

Below the table:

**Safe post-pack reconciliation**

Button: **Run safe legacy reconciliation**

### What is it?

After an upgrade from older text-based city/region rules, some Coverage Groups may say **Review required** because the plugin could not prove which canonical place the old text meant.

When a Location Pack later becomes **ready**, this button revisits only:

- Delivery Areas with no coverage yet
- migration-generated review-required groups
- unresolved migration records

### What it is not

- It is **not** “reset all Delivery Areas.”
- It does **not** overwrite valid manually created canonical coverage. The live screen says: “Active manually created canonical coverage is never replaced.”
- It does **not** mean every review-required item will automatically become active.
- Unresolved or ambiguous cases may still need a person.

After it runs, the notice reports counts: scanned, skipped manual, reconciled, still review required, activated.

Then open the affected Delivery Area and confirm the intended places. See [15 — Review required](15-DELIVERY-AREAS-AND-COVERAGE-GROUPS.md#review-required).

---

## Country neutrality

Ghana is the first reference and training market. It is **not** a hard-coded product limit.

Other countries can have their own Location Packs (`NG`, `KE`, `GB`, …). Install only what the business serves.

The administrative-area label follows the country (Region, State, Province, County, Prefecture, and so on).

---

## Customer cascading location (what shoppers see)

On the product page, location selection is progressive:

1. **Country**
2. **Administrative area / Region / State** (WooCommerce can supply this even without a pack)
3. **Locality** (needs pack-backed directory data)
4. **Postcode** only when it is relevant for that destination

Changing a parent **clears** child selections. If the customer changes Country, Region and Locality reset. If they change Region, Locality and Postcode reset.

Locality matching uses the canonical identity from the pack, not arbitrary spelling.

Country-wide delivery still works without forcing a locality.

### Training-site observation (expected, not a failure)

On the training site after RC.11 → RC.12:

- WooCommerce still supplied Ghana and Ghana’s regions
- **0** Location Packs were installed
- locality search had **no pack-backed locality data**
- a small geography location count existed from migration/bootstrap, not from a full Ghana pack

That is expected architecture. Do **not** present “I see Country and Region but no city list” as a plugin crash. Install the Ghana pack, wait until **ready**, then test locality search again.

---

## What can go wrong?

| Symptom | Likely cause | What to do |
|---------|--------------|------------|
| Country + Region, no Locality results | No usable pack for that country | Install the pack; wait for **ready** |
| Status stuck on **importing** | Background batch still running, or interrupted | Wait, then **Continue / retry** once |
| Status **failed** | File invalid, upload failed, or import error | Read the displayed error. Re-upload or retry. Do not edit the database. |
| “Enter a two-letter country code.” | Country field empty or not `GH`-style | Use `GH`, not `Ghana` |
| “The uploaded pack could not be stored.” | Wrong file type | Use the country `.txt` or `.zip` |
| Review required on a Delivery Area | Old city text could not be proven | Install pack, run **Run safe legacy reconciliation**, then review the area |
| Prices did not appear after install | Pack is not a price | Create/confirm Delivery Area, Coverage, Option, and Delivery Charge |

---

## What should I not do?

- Do not treat Location Packs as Delivery Charges.
- Do not delete Delivery Areas because a pack was missing.
- Do not manually change pack status in the database.
- Do not run migration PHP classes by hand.
- Do not downgrade schema by hand.
- Do not paste API keys, server IPs, or backup credentials into staff notes.
- Do not install every country’s pack “just in case.”
- Do not click **Install / update pack** and **Continue / retry** in a rapid loop.

---

## How do I test it?

1. Confirm the Ghana (or other) row shows **ready**.
2. Open **Delivery Engine → Delivery Areas** → add or edit **Greater Accra Selected Cities**.
3. Add a Coverage Group: Ghana → Greater Accra → **Selected locations** → Accra, Tema, Madina, Adenta.
4. Confirm a **Standard Delivery** option and a **GH₵30** Delivery Charge exist for that area.
5. On a QA product page: Country Ghana → Region Greater Accra → search Accra. The locality should appear.
6. Confirm the delivery card shows **Standard Delivery**, the estimate, and **GH₵30**.
7. Add to cart, open checkout, confirm the same fee.
8. If you upgraded from RC.11, also open any area still marked review-required and follow the staff workflow in [15](15-DELIVERY-AREAS-AND-COVERAGE-GROUPS.md#review-required).

---

## Related guides

- [00 — Start here](00-START-HERE.md)
- [01 — Quick Start](01-QUICK-START.md)
- [02 — Complete Administrator Guide](02-COMPLETE-ADMIN-GUIDE.md)
- [12 — Setup, configure, and test](12-SETUP-CONFIGURE-AND-TEST.md)
- [15 — Delivery Areas and Coverage Groups](15-DELIVERY-AREAS-AND-COVERAGE-GROUPS.md)
- [07 — Troubleshooting FAQ](07-TROUBLESHOOTING-FAQ.md)
- [08 — Glossary](08-GLOSSARY.md)
