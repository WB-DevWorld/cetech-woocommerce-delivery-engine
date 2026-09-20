# Complete Administrator Guide

**Audience:** Administrators and authorised configuration staff  
**Version:** CETECH Delivery Engine **1.0.0-rc.12** (schema **6**)  
**Everyday home:** Delivery Engine → **Overview**  
**Not everyday:** Technical Diagnostics, private supply screens, Advanced Settings

For each page: purpose, who, when, fields, recommended settings, steps, example, customer impact, mistakes, do-not-touch, related workflows, expected result.

Screenshots are deferred. Practice on QA products **#39705**, **#39717** / **#39718** / **#39719**.

**How the pieces fit** (connected, not the same thing): Location Pack = directory of places (not a price) → Delivery Area / Coverage Group = where you serve → Delivery Option = the service → Delivery Charge = the money → product rules (Site-wide → product → variation) → product page → cart → checkout → order snapshot.

Teaching example: Ghana pack → **Greater Accra Selected Cities** → Selected locations Accra, Tema, Madina, Adenta → Standard Delivery → **GH₵30**.

Dedicated guides: [14 — Location Packs](14-LOCATION-PACKS-AND-GEOGRAPHY.md) · [15 — Coverage Groups](15-DELIVERY-AREAS-AND-COVERAGE-GROUPS.md). Recommended order: [12](12-SETUP-CONFIGURE-AND-TEST.md).

---

## PAGE: Delivery Engine → Overview

**What this page is for**  
Daily operations home: is delivery configured, what needs attention, shortcuts to defaults and exceptions.

**Who should use it**  
Everyday staff and administrators.

**When to use it**  
Start of day, after setup, after major config changes.

**What you will see**  
Readiness banner, primary default summary, Needs Attention count, links to edit defaults / review exceptions / Preview.

**Recommended**  
Use as a hub. Do not treat it as a diagnostics console.

**Step-by-step**  
1. Open **Delivery Engine** (lands on Overview when setup is complete).  
2. Follow the banner if something still needs setup or activation.  
3. Open **Needs Attention** if the count is not zero.

**Customer experience**  
None directly.

**Common mistakes**  
Looking for a different home than **Overview**. The everyday home is Overview.

**Do not change casually**  
Nothing on Overview is a hidden feature-flag editor.

**Related**  
Site-wide Defaults, Product Exceptions, Needs Attention, Setup Guide.

**Expected result**  
You can see whether customers can use Site-wide Defaults and what still needs work.

---

## PAGE: Setup Guide

**What this page is for**  
Guided first-time (or review) setup of fulfilment types, Site-wide Defaults, areas, charges, and apply.

**Who**  
Administrators.

**When**  
New install, incomplete setup, or **Settings → Run Setup Guide Again**.

**Steps (6)**  
1. **Store Setup**  
2. **Fulfilment Types** — In Warehouse, In Store, International (choose which the store uses; pick a primary default)  
3. **Site-wide Defaults** — normal rules per selected type  
4. **Areas & Charges** — Delivery Areas and Delivery Charges  
5. **Apply to Products** — calculated catalogue counts, then **Save & Apply Site-wide**  
6. **Finish** — setup is complete only here after a successful apply

**Recommended**  
Complete the guide once. Review mode later does **not** wipe product/variation exceptions.

**Apply does**  
- Save active fulfilment types and the primary default  
- Keep true product/variation exceptions  
- Convert matching older overrides into inherit where they already match the default  

**Apply does not**  
- Copy defaults onto every product as frozen copies  
- Destroy historical order delivery details  
- Require you to re-apply after later default edits (inherited fields update immediately)

**Customer experience**  
After Finish (and required checkout features are on), eligible products use Site-wide Defaults at checkout.

**Common mistakes**  
Stopping before Finish; treating International as ordinary local delivery (Air and/or Sea only); creating extra exceptions during first setup.

**Do not change casually**  
Re-running Apply without reading the exception-protection copy.

**Related**  
Site-wide Defaults, Settings. Click-by-click for each menu: [12 — How to use each menu](12-SETUP-CONFIGURE-AND-TEST.md).

**Expected result**  
Overview shows setup complete. Setup Guide leaves the everyday menu and is reopenable from Settings.

---

## PAGE: Site-wide Defaults

**What this page is for**  
Set the normal delivery rules once per fulfilment type.

**Who**  
Administrators / authorised configuration staff.

**When**  
Changing the store-standard path, options, method, or estimate.

**What each area means**

| Area | Meaning |
|------|---------|
| Fulfilment types in use | Which of In Warehouse / In Store / International this store sells |
| Primary default | What unclassified products inherit |
| Delivery Options | Customer choices allowed for that type |
| Delivery method | Delivery or Store pickup |
| Estimated delivery | Customer-facing timing text |
| Save Changes | Store the defaults |
| Save & Apply Site-wide | Opens apply when setup is still incomplete |

Hard constraints: In Warehouse lists **local delivery** options. International lists **Air and/or Sea** only. In Store may include pickup with no delivery charge when that is the store policy.

**Recommended**  
Strong defaults; few product exceptions.

**Step-by-step**  
1. **Delivery Engine → Site-wide Defaults**.  
2. Confirm types and primary default.  
3. For each type, set options, method, and estimate.  
4. **Save Changes**.  
5. Preview a sample product (QA **#39705**).

**Example**  
Most of the catalogue is In Warehouse + one standard local Delivery Option. International products use Air/Sea options only.

**Customer experience**  
Inherited products show those public option names and estimates.

**Common mistakes**  
Putting every product on an exception instead of fixing the default; using International options for local warehouse delivery.

**Do not change casually**  
Primary default if the catalogue mix is not understood.

**Related**  
Delivery Options, Product Exceptions, Preview Delivery.

**Expected result**  
A default product’s Preview is **Ready** and the shop shows the expected option + estimate.

---

## PAGE: Delivery Options

**What this page is for**  
Reusable customer-facing delivery choices.

**Who**  
Administrators / authorised staff.

**When**  
Adding or renaming a service customers can choose.

**Key fields**  
Public name (what customers see), short description (admin/data; **not** shown on the compact product selector), delivery type, status. Reference code and extra timing sit under **Advanced**.

**Recommended**  
Clear public names. The checkout shipping line can show this public name.

**Steps**  
Add/edit option → Save → assign it on Site-wide Defaults or a product exception as needed.

**Customer experience**  
They select the public name. Checkout may show `$25.00 via FLAIROC QA Standard Delivery` (example) instead of a generic “via Delivery”.

**Mistakes**  
Renaming a live option without checking Delivery Charges; putting internal codes in the public name.

**Do not change casually**  
Deleting options still used by defaults or charges.

**Related**  
Site-wide Defaults, Delivery Charges.

**Expected result**  
The option appears where assigned and is the label customers see.

---

## PAGE: Delivery Engine → Location Packs

**What this page is for**  
Install country locality data used by Delivery Areas and the product-page city selector. Shopper requests never call GeoNames. Packs are stored in WordPress uploads, not inside the plugin.

**Who should use it**  
Administrators / authorised configuration staff (same access as Delivery Areas). Salespeople do not need this page daily.

**When to use it**  
Before locality-level Coverage Groups; after an RC.11→RC.12 upgrade that shows **Review required**; when adding a new country later.

**What it is not**  
Not a delivery price, Delivery Area, Delivery Option, carrier, or customer address book. Installing Ghana geography does not create GH₵30.

**What you will see**  
Heading **Location Packs**. Attribution: GeoNames gazetteer data licensed under CC BY 4.0.

**Install or update a pack**

- **Country** — two-letter code (`GH`). Often pre-filled from the WooCommerce store country.
- **Upload gazetteer file** — GeoNames country `.txt` or `.zip`.
- **Official download** — tick **Fetch the official GeoNames country ZIP in the background (download.geonames.org only).**
- **Operation** — **Install or continue** / **Update with a new dataset** / **Retry / resume current dataset**.
- Button: **Install / update pack**.

**Installed packs** columns: Country, Provider, Version, Checksum, Status, Installed, License, Progress, Actions.

Statuses: **pending**, **importing**, **ready**, **failed**. **Ready** = usable live geography dataset.

**Continue / retry** appears for pending, importing, or failed rows. Use it to resume. Do not click rapidly because import is batched in the background.

**Safe post-pack reconciliation**

Button: **Run safe legacy reconciliation**.

Revisits Delivery Areas with no coverage, migration-generated review-required groups, or unresolved migration records. Active manually created canonical coverage is never replaced. It is not “reset all Delivery Areas.” Ambiguous cases may still need a person.

Notice after run: scanned, skipped manual, reconciled, still review required, activated.

**Recommended**  
Install packs only for countries you serve with locality-level delivery. Ghana is the training/reference market, not a hard-coded limit. Wait for **ready** before building Selected locations coverage.

**Customer experience**  
Without a usable pack, WooCommerce can still show Country → Region, but locality search has no pack-backed results. That is expected, not a plugin failure. Changing a parent location clears child selections. Postcode appears only when relevant. Country-wide delivery does not force a locality.

**Common mistakes**  
Treating the pack as a price; deleting Delivery Areas because locality search is empty; editing pack status in the database; installing every country.

**Do not change casually**  
Do not run migration classes by hand. Do not downgrade schema.

**Related**  
[14](14-LOCATION-PACKS-AND-GEOGRAPHY.md); Delivery Areas; review-required workflow in [15](15-DELIVERY-AREAS-AND-COVERAGE-GROUPS.md#review-required).

**Expected result**  
The country row shows **ready**. Coverage builder locality search can find Accra, Tema, Madina, Adenta once those exist in the pack. Delivery Charges still have to be created separately.

**Training-site observation**  
After RC.11 → RC.12 the training site had **0** installed Location Packs, a small geography location count from migration/bootstrap, and WooCommerce still supplied Ghana’s regions. Locality search had no pack-backed data. Expected architecture.

---

## PAGE: Delivery Areas

**What this page is for**  
Business groupings of destinations that share intended delivery treatment, plus Coverage Groups that name the canonical places inside each area.

**Who**  
Administrators / authorised staff.

**When**  
After Location Packs needed by the business are **ready** (for locality-level work), and before Delivery Charges.

**Key fields**  
- **Delivery area name**, **Reference code**, **Customer-facing label**, **Status**  
- **Coverage groups** (live RC.12 geography)  
- **Priority** — “Lower numbers are checked first when more than one delivery area could match.”  
- **Use as fallback for unmatched addresses**  
- **Remote area**  
- List tool: **Test an address** → **Primary match** / **Also matches**

Exact Coverage dropdown labels:

- **Entire selected area** — all of Greater Accra (or the selected country).
- **Selected locations** — only Accra, Tema, Madina, Adenta (teaching example).
- **Entire selected area except…** — all Greater Accra except Ada Foah and Prampram.

Help text on the builder: different levels = **AND**; several places at the same level = **OR**; extra groups = **OR**.

Exclusions override inclusion **inside that group**. The excluded town may still match another Delivery Area.

**Priority and overlaps**  
Preferred business rule gets the lower number (example: Accra special service **8**, Greater Accra general **25**). The engine then uses geographic specificity when priority is equal. Do **not** invent “smallest area automatically wins.” Overlap on the list is normal. If overlap could not be fully proven, test specific addresses rather than treating areas as uncovered.

**Review required**  
Does **not** mean data was destroyed. The upgrade refused to guess which canonical locality old text (`"Accra"`) meant. Saving other fields will not clear the warning. Tick **I reviewed this migrated coverage** only after you confirm. If the screen warns that **Confirm replacement with entire selected area** would widen coverage, do not tick it unless that is intended. Do not delete/recreate the area. After a pack is **ready**, use **Run safe legacy reconciliation** on Location Packs.

Legacy conditions, once coverage is active, appear under **Legacy location conditions (compatibility evidence only)**.

Removing every coverage group requires the confirmation checkbox that you will **not** silently fall back to hidden legacy conditions.

**Fallback**  
Empty geography + fallback = true Everywhere else. Geography + fallback stays constrained (Greater Accra fallback never matches the United States). Native WooCommerce shipping is never the fallback. No match stays unmatched — fail closed, not free shipping.

**Recommended**  
One Delivery Area with a **Selected locations** group for the teaching example — not four city areas. Every sellable area + option still needs a Delivery Charge. Broader matching areas can supply a missing charge for the **same** selected option; a different option is never substituted. Invalid more-specific charges fail closed.

**Steps**  
Add/edit area → add Coverage Group → choose mode → save → **Test an address** → add Delivery Charge → test product page → cart → checkout.

**Customer experience**  
Correct fee for their destination together with Delivery Charges. Cascading location: Country → Region → Locality → postcode when relevant.

**Mistakes**  
One area per city for one shared price; treating Location Packs as prices; assuming smallest area wins; deleting review-required data; confusing Coverage Groups with product inheritance.

**Related**  
[15](15-DELIVERY-AREAS-AND-COVERAGE-GROUPS.md); Delivery Charges; WooCommerce **Delivery** shipping method: [12](12-SETUP-CONFIGURE-AND-TEST.md#how-to-add-the-delivery-shipping-method-in-woocommerce). Playbook geography cases.

**Expected result**  
Address tests match the intended Primary area; Also matches lists other legitimate coverage; Accra in the teaching example quotes **GH₵30** for Standard Delivery.

---

## PAGE: Delivery Charges

**What this page is for**  
How much customers pay.

**Who**  
Administrators / authorised staff.

**When**  
Pricing changes; new area + option combinations.

**Key fields**  
Delivery Area, Delivery Option, charge type (plain language: flat / per item / advanced), amount in the store currency. Internal charge-type codes are not the everyday label.

**Recommended**  
Every sellable area + option pair has an explicit charge. Never rely on silent $0. A configured numeric **0.00** is allowed only when you intentionally priced it that way.

**Steps**  
Add/edit charge → Save → verify checkout with a QA product (stop before payment).

**Customer experience**  
Checkout shipping amount. WooCommerce already shows this total — do not expect a second custom “Shipping summary” on thank-you.

Teaching example: Standard Delivery in **Greater Accra Selected Cities** = **GH₵30**. The Location Pack does not create this amount.

The Delivery Engine owns delivery charges inside its boundary. It does **not** become merchandise pricing authority. B2BKing / WoodMart / WooCommerce can remain merchandise pricing authorities. FOX/WOOCS can remain currency authority where installed. Training-site coexistence is evidence, not broader Stable-1.0 certification.

**Mistakes**  
Missing charges; assuming free shipping; confusing one shared delivery fee with a per-item fee.

**Do not change casually**  
Production prices without approval.

**Related**  
Delivery Areas, Delivery Options, checkout.

**Expected result**  
Known QA fee behaviour (documented live QA often **25.00** on the standard QA path).

---

## PAGE: Pickup Locations

**What this page is for**  
Customer collection points when Store pickup is offered.

**Who**  
Staff who manage pickup.

**When**  
Adding/updating pickup addresses, hours, instructions.

**Customer experience**  
Pickup extras (location / address / instructions) appear **only if** they are present on the public summary. Timing uses **Ready for pickup**.

**Do not confuse with**  
Suppliers & Origins (private; not an everyday menu).

---

## PAGE: Product Exceptions

**What this page is for**  
Which products differ from Site-wide Defaults.

**Who**  
Everyday catalog staff.

**When**  
Finding, editing, or resetting exceptions.

**Columns / badges**  
Product, Fulfilment, Exception summary, Customized, Status (**Ready** / **Needs Attention**), Actions.

**Steps**  
Open **Product Exceptions** → View/Edit a product → or **Reset to Site-wide Defaults** for that product only.

**Recommended**  
Keep this list short. If many products need the same change, fix Site-wide Defaults instead.

**Customer experience**  
Only listed products differ from the store standard.

**Common mistakes**  
Resetting the wrong product; using this page to edit every product in the catalogue.

**Related**  
WooCommerce product **Delivery** tab, Needs Attention.

**Expected result**  
Exceptions are intentional and Ready.

---

## PAGE: Needs Attention

**What this page is for**  
Operational to-do list for products missing a usable delivery setup.

**Who**  
Everyday staff.

**When**  
Daily, and after default or option changes.

**Steps**  
Open **Needs Attention** → **Fix Now** on a product → complete Site-wide classification or the product Delivery customization → Preview.

**Empty state**  
“Nothing needs attention” means listed products currently have a usable setup.

**Escalate when**  
Items remain after defaults and the product Delivery tab look complete.

---

## PAGE: WooCommerce product / variation → Delivery tab

**What this page is for**  
See whether a product follows Site-wide Defaults, then customize only if needed.

**Who**  
Catalog staff.

**When**  
Editing a product that must differ, or checking what it currently uses.

**What you will see**  
- **Currently using:** Site-wide Default / Product Settings / customized fields  
- Summary of Fulfilment, Delivery method, Delivery options, Estimated delivery  
- **Preview Delivery**  
- **Customize This Product** or **Customize This Variation**  
- **Reset to Site-wide Defaults** / **Reset this variation to Product Settings** when exceptions exist  

**Customize fields**  
Fulfilment, Delivery method, Estimated delivery, Delivery options — each can inherit or be set differently.

**Variation inherit copy**  
**Use Product Setting: …**

**Steps — product**  
1. Edit QA **#39705**.  
2. Open **Delivery**.  
3. If the default is correct, stop.  
4. Otherwise Customize → change only required fields → **Save Product Delivery Settings**.

**Steps — variation**  
1. Edit parent **#39717**.  
2. Open variation **#39718** or **#39719**.  
3. Customize This Variation → inherit or override field-by-field → **Save Variation Delivery Settings**.

**Customer experience**  
Product-page selector uses the effective public options and estimate.

**Common mistakes**  
Customizing every field when only ETA should differ; editing the parent when only one variation should change.

**Expected result**  
Preview Ready; shop shows the intended option + estimate.

---

## PAGE: Preview Delivery

**What this page is for**  
Read-only view of the values that will actually apply.

**Who**  
Everyday staff.

**When**  
After every important save; when diagnosing Needs Attention.

**How to open it**  
Product/variation **Preview Delivery**, or contextual links from Overview. It is **not** a normal left-menu item.

**What you will see**  
Product/variation selectors, Information | Result | Source, Ready / Needs Attention. Supplier / origin / logistics / priority stay out of the ordinary table.

**Mistakes**  
Treating Preview as a live shipping-price calculator for a customer address.

**Expected result**  
Clear Ready or an actionable Needs Attention reason.

---

## PAGE: Delivery Engine → Settings

**What this page is for**  
Customer/order behaviour, Setup Guide, Access, and collapsed Advanced switches.

**Who**  
Administrators.

**When**  
Controlled change windows — not daily product edits.

**Primary sections**  
General / Customer experience / Orders / Setup Guide / Access / **Optional integrations** (status) / **WooCommerce Cart & Checkout Blocks** (status).

**Access**  
- **Administrator** is a protected full-access role (lock icon). It is not an editable permission row.  
- Subordinate real WordPress roles (Shop Manager, Editor, …) can be granted Delivery Engine capabilities.  
- If Administrator access needs repair, a **Restore Administrator Access** notice can appear for users who can manage WordPress options. That repair does **not** live inside Technical Diagnostics.

**Recommended production**  
Required customer/checkout features stay **ON**. Classic Checkout and Cart/Checkout Blocks are both supported. Settings shows Blocks as a **status row**, not an experimental checkbox. Shipment records and customer tracking links stay **OFF** until an Administrator chooses to turn them on. Customer timeline and carrier APIs are **not** in this release. Storefront Cash on delivery policy remains a store decision.

**Optional integrations**  
Detected plugins and whether a Delivery Engine adapter exists. These are **not** compatibility switches. Core delivery does not require WoodMart, WPML, WCML, WCFM, or VitePOS.

Shipment staff training: [11 — Stage 14 shipments](11-STAGE-14-SHIPMENTS.md). Practice jobs: playbook use cases 20–30. Tick every feature: [13 — Feature coverage](13-FEATURE-COVERAGE-AND-CONFIRMATION.md).

**Do not change casually**  
Advanced switches, Access matrix for subordinate roles without a policy decision.

**Related**  
WooCommerce **Delivery** shipping method on the zones where this plugin should operate (Rest of the World optional): [12 — How to use each menu](12-SETUP-CONFIGURE-AND-TEST.md#how-to-add-the-delivery-shipping-method-in-woocommerce). Technical Support Appendix.

---

## PAGE: Delivery Engine → Bulk Tools

**What this page is for**  
Preview large catalog or Delivery Charge changes, then apply them in the background. Validation scan. Import / Export. Jobs / History and rollback.

**Who**  
Administrators and authorised catalog staff only.

**When**  
A planned change window. Not everyday product edits.

**What you will see**  
Tabs: **Catalog**, **Import / Export**, **Validation & Cleanup**, **Jobs / History**, **Charges**.

**Recommended**  
Always **Preview** before Apply. Do not Apply a failed preview. Wait for the job; do not click Apply repeatedly. Prefer QA targets in training.

**Do not change casually**  
Production catalogue Apply, configuration import, or charge amount jobs without approval.

**Related**  
Playbook 41–42; [12 — Bulk Tools](12-SETUP-CONFIGURE-AND-TEST.md#how-to-use-bulk-tools).

**Expected result**  
Preview counts are understandable. Jobs appear in Jobs / History. Rollback restores the previous QA state when used.

---

## PAGE: Customer product / cart / checkout / thank-you

**What customers see**

| Surface | Customer sees |
|---------|----------------|
| Product page | Delivery option (bold public name) + Estimated delivery |
| Cart | Remembered delivery choice (when enabled). Pickup is not shown as shipping to the customer address. |
| Classic Checkout | WooCommerce shipping using the public Delivery Option label and configured charge |
| Cart/Checkout Blocks | Same selected option, same fee, same public label as Classic |
| Mixed Delivery + Pickup | Delivery fee stays real; pickup may be FREE / 0.00; no false “pricing is not available” |
| Thank-you / My Account / email | Compact **Delivery details**: option + estimate (pickup extras only if present) |

**Explicitly omitted from customer output**  
Fulfilment labels, generic “Delivery method: Delivery”, compact-selector public description, duplicate delivery-charge blocks, supplier / origin / logistics / priority / IDs.

**Staff role**  
Configure in admin; verify on QA storefront; do not complete unnecessary paid orders.

Cascading location on the product page: **Country → Region/State → Locality → Postcode when relevant**. Changing a parent clears children. Locality uses canonical identity. Without a Location Pack, Country/Region can still appear while locality search is empty — expected.

Delivery card order: service name → ETA/timeframe → prominent fee.

---

## PAGE: WooCommerce order → Delivery information

**What this page is for**  
Staff view of what was saved when the customer ordered.

**Who**  
Everyday order staff.

**When**  
Fulfilment and support.

**Fields staff may see**  
Product(s), Fulfilment, Delivery method, Delivery option, Estimated delivery / Ready for pickup, charge as stored. Technical group keys stay hidden.

**Do not change casually**  
Rewriting historical delivery facts after Site-wide Defaults change.

**Expected result**  
Staff can fulfil from the panel. Customers on thank-you still see only the compact public contract.

---

## Upgrading from RC.11 (schema 5) to RC.12 (schema 6)

Normal plugin upgrade creates canonical geography/coverage structures. Existing Delivery Areas, Delivery Charges, rules, and business references are retained.

Some migrated city rules can become **Review required** when a Location Pack was not installed (training: Accra and Kumasi / unmapped city). Follow Location Packs → **Run safe legacy reconciliation** → inspect the area → confirm geography. Do not invoke migration classes. Do not downgrade schema by hand. Backup before major upgrades.

Details: [14](14-LOCATION-PACKS-AND-GEOGRAPHY.md), [15](15-DELIVERY-AREAS-AND-COVERAGE-GROUPS.md#review-required), [12](12-SETUP-CONFIGURE-AND-TEST.md).
