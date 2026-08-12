# Complete Administrator Guide

**Audience:** Administrators and authorised configuration staff  
**Version:** CETECH Delivery Engine 1.0.0-rc.2  
**Everyday system:** Delivery Engine → **Delivery Settings**  
**Not everyday:** Legacy Delivery Rules

For each page: purpose, who, when, fields, recommended settings, steps, example, customer impact, mistakes, do-not-touch, related workflows, screenshots, expected result.

Practice safely on QA products **#39705**, **#39717** / **#39718** / **#39719**.

---

## PAGE: Delivery Engine → Dashboard

**What this page is for**  
Operations overview and readiness shortcuts into everyday delivery configuration.

**Who should use it**  
Everyday staff and administrators.

**When to use it**  
Start of day, after major config changes, or to jump to Offers / Zones / Rate Cards / Settings.

**What each field means**  
Readiness / checklist wording varies by site state. Advanced system details are diagnostics.

**Recommended normal setting**  
Use as a hub; do not treat Advanced details as daily editing.

**Step-by-step**  
1. Open **Delivery Engine → Dashboard**.  
2. Follow links to the task you need.

**Example**  
Need to change a fee → open Rate Cards from the dashboard links.

**Customer experience**  
None directly.

**Common mistakes**  
Changing advanced diagnostics without understanding them.

**Do not change casually**  
Advanced system details / capability tools.

**Related**  
Delivery Settings, Offers, Zones, Rate Cards.

**Screenshots**  
`00-delivery-engine-dashboard.png`

**Expected result**  
You can reach everyday pages quickly.

---

## PAGE: Delivery Settings (Default / Product / Variation)

**What this page is for**  
Set Default Settings, Product-Specific Settings, and Variation-Specific Settings with clear inheritance.

**Who should use it**  
Everyday staff (product/variation levels as authorised); administrators for Defaults.

**When to use it**  
Whenever a product’s delivery path should follow or differ from store defaults.

**What each field means**

| Field | Meaning |
|-------|---------|
| Fulfilment availability | In store / In warehouse / International fulfilment |
| Fulfilment choice | Delivery or Store pickup |
| Logistics profile | Private handling profile for planning |
| Supplier | Private supplier reference |
| Origin | Private origin reference |
| Priority | Which setup wins if more than one could apply (lower first). Most products leave unchanged |
| Delivery offers | Which customer choices apply (inherit / add / remove / use only these) |

**Modes**

| Mode | When |
|------|------|
| Use inherited setting | Keep level above |
| Set a different value here | Replace scalar at this level |
| Turn off | Intentionally none where supported |
| Add / Remove / Use only these options | Collection controls for offers |

**Recommended normal setting**  
Strong Defaults; product/variation overrides only when needed.

**Step-by-step — Default**  
1. **Delivery Settings → Default Settings**.  
2. Set fulfilment, choice, and offers your catalogue should normally use.  
3. Save.  
4. Open Preview for a sample product.

**Step-by-step — Product**  
1. **Product-Specific Settings**.  
2. Enter product ID (QA **39705**).  
3. Leave inherit where Default is correct.  
4. Override only required fields.  
5. Save → Preview.

**Step-by-step — Variation**  
1. **Variation-Specific Settings**.  
2. Enter parent ID (**39717**) and variation ID (**39718** or **39719**).  
3. Inherit or override deliberately.  
4. Save → Preview → storefront variation test.

**Example**  
Most items In warehouse + Delivery via Default; one bulky item overrides offers on the product.

**Customer experience**  
Product page **Delivery options** reflect effective settings.

**Common mistakes**  
Editing Legacy Rules instead; empty offer lists that remove all choices; wrong variation/parent IDs.

**Do not change casually**  
Supplier/origin if not authorised; Advanced Settings flags on the other Settings page.

**Related**  
Preview, Offers, Rate Cards, customer product page.

**Screenshots**  
`01-delivery-settings-home.png`, `02-default-settings.png`, `03-product-specific-settings.png`, `04-variation-specific-settings.png`

**Expected result**  
Preview shows **Ready** (or a clear Needs configuration you can fix).

---

## PAGE: Delivery Settings Preview

**What this page is for**  
Read-only view of the values that will actually apply.

**Who**  
Everyday staff.

**When**  
After every important save; when diagnosing Needs configuration.

**Fields**  
Product / parent / variation identifiers; delivery setup; Ready / Needs configuration; Currently using.

**Recommended**  
Always verify before telling a customer “it is fixed”.

**Steps**  
Open Preview → enter IDs as prompted → read status.

**Customer experience**  
Indirect — confirms what they should see.

**Mistakes**  
Treating Preview as a shipping quote / address calculator.

**Screenshots**  
`05-delivery-preview-ready.png`

**Expected result**  
Clear Ready or actionable Needs configuration.

---

## PAGE: Delivery Offers

**What this page is for**  
Define customer-facing delivery choices (names customers select).

**Who**  
Administrators / authorised staff.

**When**  
Adding or renaming a service customers can choose.

**Key fields**  
Customer-facing name, short description, delivery type, reference code, sort order, status; advanced timing if used.

**Recommended**  
Clear public names; avoid internal codes as the only label.

**Steps**  
Add/edit offer → save → assign via Delivery Settings / rate cards as needed.

**Customer experience**  
They see the public name under Delivery options.

**Mistakes**  
Renaming live offers without checking rate cards and product assignments.

**Do not change casually**  
Deleting offers still referenced by products/rates.

**Related**  
Delivery Settings offers field; Rate Cards.

**Expected result**  
Offer appears in selectors where assigned.

---

## PAGE: Destination Zones

**What this page is for**  
Define where delivery pricing/coverage rules apply.

**Who**  
Administrators / authorised staff.

**When**  
Expanding coverage or fixing address matching.

**Key fields**  
Zone name, reference code, customer-facing label, matching rules; optional address tester.

**Recommended**  
Zones that match how you sell geographically; test tricky addresses.

**Steps**  
Create/edit zone → define match rules → test address if unsure → ensure rate cards exist.

**Customer experience**  
Correct fee for their destination (with rate cards).

**Mistakes**  
Overlapping unclear zones; zones without rate cards.

**Related**  
Rate Cards; WooCommerce shipping zones (method availability).

**Expected result**  
Address tests match the intended zone.

---

## PAGE: Rate Cards

**What this page is for**  
Set the delivery fee for zone + offer (and related matching).

**Who**  
Administrators / authorised staff.

**When**  
Pricing changes; new offer/zone combinations.

**Key fields**  
Delivery zone, delivery offer, charge type, fee amount, currency; optional logistics/supplier/origin/priority matching.

**Recommended**  
Every sellable offer+zone pair has an explicit card. Never rely on silent $0.

**Steps**  
Add/edit card → save → verify checkout with QA product (stop before payment).

**Customer experience**  
Checkout **Delivery** line amount.

**Mistakes**  
Missing cards; assuming free shipping; confusing fixed-per-shipment with per-item.

**Do not change casually**  
Production prices without approval.

**Related**  
Zones, Offers, checkout.

**Expected result**  
Known QA fee behaviour (documented live QA often **25.00** for the QA path).

---

## PAGE: Logistics Profiles

**What this page is for**  
Private fulfilment-planning profiles.

**Who**  
Administrators / ops configuration.

**When**  
You need named handling profiles referenced from Delivery Settings.

**Recommended**  
Clear internal names; not customer-facing marketing copy.

**Customer experience**  
Indirect via which paths work.

**Do not change casually**  
Profiles in active use without checking product settings.

---

## PAGE: Pickup Locations

**What this page is for**  
Store pickup points when Store pickup is offered.

**Who**  
Staff who manage pickup.

**When**  
Adding/updating pickup addresses, hours, instructions.

**Customer experience**  
Pickup instructions / ready-for-pickup style messaging where applicable.

---

## PAGE: Suppliers & Origins

**What this page is for**  
Private supply sources.

**Who**  
Administrators only.

**When**  
Internal logistics setup.

**Customer experience**  
None — keep private.

**Do not change casually**  
Any public exposure of supplier/origin identities.

---

## PAGE: Delivery Engine → Settings (feature switches)

**What this page is for**  
Checkout/pipeline feature switches and advanced cutover controls.

**Who**  
Administrators only.

**When**  
Controlled change windows — not daily product edits.

**Recommended RC.2 production**  
Required ON switches per release readiness (New Delivery Settings System; variations; product choices; cart remember; checkout validate; show fees; save order delivery info). Deferred features OFF. COD remains OFF.

**Do not change casually**  
Advanced cutover switches.

**Related**  
Technical Support Appendix.

---

## PAGE: Legacy Delivery Rules

**What this page is for**  
Older product-rule compatibility UI.

**Who**  
Authorised support/administrators only.

**When**  
Specific migration/support cases — **not** normal configuration.

**Recommended**  
Use **Delivery Settings** instead.

**Screenshots**  
`10-legacy-delivery-rules.png`

**Expected result**  
Staff recognise the page and leave it alone.

---

## PAGE: WooCommerce product / variation editors

**RC.2 reality**  
There is **no** Delivery tab inside the WooCommerce product editor.  
Configure delivery under **Delivery Engine → Delivery Settings** using WooCommerce IDs.

---

## PAGE: Customer product / cart / checkout

**What customers see**  
**Delivery options** on the product; summaries in cart; **Delivery** shipping charge at Classic Checkout.

**Staff role**  
Configure in admin; verify on QA storefront; do not complete unnecessary paid orders.

**Screenshots**  
`06-*.png`, `07-cart-delivery-information.png`, `08-checkout-delivery-charge.png`

---

## PAGE: WooCommerce order → Delivery information

**What this page is for**  
Immutable-enough operational snapshot of what the customer purchased for delivery.

**Who**  
Everyday order staff.

**When**  
Fulfilment and support.

**Fields**  
Product(s), Fulfilment, Delivery method/Method, Delivery option, Estimated delivery / Ready for pickup, Delivery charge, Status as shown.

**Do not change casually**  
Rewriting historical delivery facts after Defaults change.

**Screenshots**  
`09-order-delivery-information.png`

**Expected result**  
Staff can fulfil from the panel without technical meta.
