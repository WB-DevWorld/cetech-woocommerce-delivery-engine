# Complete Administrator Guide

**Audience:** Administrators and authorised configuration staff  
**Version:** CETECH Delivery Engine 1.0.0-rc.6  
**Everyday home:** Delivery Engine → **Overview**  
**Not everyday:** Technical Diagnostics, private supply screens, Advanced Settings

For each page: purpose, who, when, fields, recommended settings, steps, example, customer impact, mistakes, do-not-touch, related workflows, expected result.

Screenshots are deferred. Practice on QA products **#39705**, **#39717** / **#39718** / **#39719**.

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

## PAGE: Delivery Areas

**What this page is for**  
Where the business delivers.

**Who**  
Administrators / authorised staff.

**When**  
Expanding coverage or fixing address matching.

**Key fields**  
Area name, geography (countries / states / postcodes via the condition builder). For **State / Region**, the WooCommerce checkout name and that country’s short code both match (for example Ghana `Greater Accra` and `AA`). Match mode and priority stay under **Advanced matching**. Optional **Test an Address**.

**Recommended**  
Areas that match how you sell; test tricky addresses with both the region name and the checkout short code when a State / Region condition is used.

**Steps**  
Add/edit area → define conditions → test an address → ensure a Delivery Charge exists for each sellable option in that area.

**Customer experience**  
Correct fee for their destination (together with Delivery Charges).

**Mistakes**  
Overlapping unclear areas; areas with no charge defined.

**Related**  
Delivery Charges; WooCommerce shipping zones (method availability). Add **Delivery** only to the zones where this plugin should operate; Rest of the World is optional: [12 — How to use each menu](12-SETUP-CONFIGURE-AND-TEST.md#how-to-add-the-delivery-shipping-method-in-woocommerce).

**Expected result**  
Address tests match the intended area.

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
General / Customer experience / Orders / Setup Guide / Access.

**Access**  
- **Administrator** is a protected full-access role (lock icon). It is not an editable permission row.  
- Subordinate real WordPress roles (Shop Manager, Editor, …) can be granted Delivery Engine capabilities.  
- If Administrator access needs repair, a **Restore Administrator Access** notice can appear for users who can manage WordPress options. That repair does **not** live inside Technical Diagnostics.

**Recommended production (Classic Checkout)**  
Required customer/checkout features stay **ON**. Shipment records and customer tracking links stay **OFF** until an Administrator chooses to turn them on in Settings. Customer timeline, Blocks checkout, and carrier APIs are **not** in this release. Storefront Cash on delivery policy remains a store decision.

Shipment staff training: [11 — Stage 14 shipments](11-STAGE-14-SHIPMENTS.md). Practice jobs: playbook use cases 20–30.

**Do not change casually**  
Advanced switches, Access matrix for subordinate roles without a policy decision.

**Related**  
WooCommerce **Delivery** shipping method on the zones where this plugin should operate (Rest of the World optional): [12 — How to use each menu](12-SETUP-CONFIGURE-AND-TEST.md#how-to-add-the-delivery-shipping-method-in-woocommerce). Technical Support Appendix.

---

## PAGE: Customer product / cart / checkout / thank-you

**What customers see**

| Surface | Customer sees |
|---------|----------------|
| Product page | Delivery option (bold public name) + Estimated delivery |
| Cart | Remembered delivery choice (when enabled) |
| Checkout | WooCommerce shipping using the public Delivery Option label and configured charge |
| Thank-you / My Account / email | Compact **Delivery details**: option + estimate (pickup extras only if present) |

**Explicitly omitted from customer output**  
Fulfilment labels, generic “Delivery method: Delivery”, compact-selector public description, duplicate delivery-charge blocks, supplier / origin / logistics / priority / IDs.

**Staff role**  
Configure in admin; verify on QA storefront; do not complete unnecessary paid orders.

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
