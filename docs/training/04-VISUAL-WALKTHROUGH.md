# Visual Walkthrough (written screen tour)

**Audience:** New staff and trainers  
**Version:** 1.0.0-rc.12 (schema 6)  
**Screenshots:** Not captured for RC.12 in this package. Do **not** use leftover RC.2 images as teaching truth. Follow the live screens. Screenshot TODOs below are capture instructions, not fabricated pictures.

Walk this path (read-only unless a trainer authorises a QA edit):

WordPress admin → Overview → **Location Packs** → Site-wide Defaults → Delivery Options → Delivery Areas / Coverage Groups → Delivery Charges → Pickup Locations → Product Exceptions → Needs Attention → Settings → Bulk Tools (administrators only) → WooCommerce product Delivery tab → Preview Delivery → product page (Country → Region → Locality) → cart → Classic checkout **and** Cart/Checkout Blocks (whichever the store uses) → WooCommerce order Delivery information → Shipments (only if enabled).

Teaching example: Ghana pack **ready** → Greater Accra Selected Cities → Selected locations Accra, Tema, Madina, Adenta → Standard Delivery → GH₵30.

For each screen: what you are looking at, what matters, what you can safely change, what to leave alone, what happens after Save.

---

## 1. WordPress admin → Delivery Engine

**What you are looking at**  
Left menu item **Delivery Engine** (location pin icon).

**What matters**  
When setup is complete, the first screen is **Overview**. While setup is incomplete, **Setup Guide** appears in the menu instead.

**Safely change**  
Nothing here — this is navigation.

**Leave alone**  
If a menu name is not on the everyday list in [00-START-HERE](00-START-HERE.md), stop and ask an administrator.

**After Save**  
Not applicable.

---

## 2. Overview

**What you are looking at**  
Daily home: readiness banner, primary default, Needs Attention count, shortcuts.

**What matters**  
Can customers use Site-wide Defaults? Does anything need attention?

**Safely change**  
Follow links to the task you need.

**Leave alone**  
Do not treat Overview as a place to flip Advanced switches.

**After Save**  
Not a settings form.

---

## 3. Site-wide Defaults

**What you are looking at**  
Normal rules per fulfilment type (In Warehouse, In Store, International) plus the primary default.

**What matters**  
Most of the catalogue inherits these rules. Field-by-field: later default edits apply to inherited fields immediately.

**Safely change**  
Authorised edits to options, method, and estimated delivery for a type.

**Leave alone**  
Primary default and International Air/Sea constraints unless you understand the catalogue mix.

**After Save**  
**Save Changes** updates defaults. Inherited products pick up inherited fields without a mass copy. True exceptions stay.

---

## 4. Delivery Options

**What you are looking at**  
The public names customers select.

**What matters**  
The compact product selector and the checkout shipping line use this public name.

**Safely change**  
Clear public names; status (active/inactive) when authorised.

**Leave alone**  
Deleting options still used by defaults or charges. Do not put internal codes in the public name.

**After Save**  
Assigned products/defaults can show the new label.

---

## 4A. Location Packs

**What you are looking at**  
**Delivery Engine → Location Packs**. Heading **Location Packs**. Sections **Install or update a pack**, **Installed packs**, **Safe post-pack reconciliation**.

**What matters**  
This is a directory of places, not a price. Status **ready** means usable live geography. **Continue / retry** resumes pending/importing/failed work. Button **Run safe legacy reconciliation** revisits migration leftovers only.

**Safely change**  
Install/update packs for countries you serve. Wait for background import.

**Leave alone**  
Database status, rapid clicking, treating GeoNames license as a shipping contract.

**After Save**  
Import continues in the background. Locality search works after **ready**.

**Screenshot TODO (capture on training or a demo site; redact PII; do not change live business config for prettier shots without owner permission):**

- TODO-SCREENSHOT: Location Packs page (empty state “No location packs installed yet.” and/or install form). Capture: WP admin → Delivery Engine → Location Packs. Crop to the wrap. No emails, keys, or server IPs.
- TODO-SCREENSHOT: pack **ready** row (Country GH, Status ready, Provider, Version, Checksum fragment, License, Progress).
- TODO-SCREENSHOT: pack **importing** row with Progress and **Continue / retry** — only if a safe non-production import is already running; do not start a destructive import just for a picture.
- TODO-SCREENSHOT: **Run safe legacy reconciliation** heading and button.

---

## 5. Delivery Areas

**What you are looking at**  
List of Delivery Areas, overlap/charge warnings, **Test an address**, then the editor with **Coverage groups**.

**What matters**  
Live configuration is Coverage Groups, not the old text condition table. Modes: **Entire selected area**, **Selected locations**, **Entire selected area except…**. Priority: lower numbers checked first. **Review required** warning if migration could not prove old city text. **I reviewed this migrated coverage**. **Confirm replacement with entire selected area** only if widening is intended.

**Safely change**  
Authorised geography; **Test an address**; review-required confirmation after a pack is ready.

**Leave alone**  
Deleting review-required areas; inventing smallest-area-wins; copying the same charge onto every city.

**After Save**  
Matcher uses canonical coverage. Charges that use this area apply to matching addresses. A selected option with no more-specific charge can use a broader matching area’s charge for **that same option**.

**Screenshot TODO:**

- TODO-SCREENSHOT: Delivery Area editor — name **Greater Accra Selected Cities**, Coverage group 1.
- TODO-SCREENSHOT: Coverage dropdown showing the three modes.
- TODO-SCREENSHOT: **Selected locations** with chips Accra, Tema, Madina, Adenta (demo data).
- TODO-SCREENSHOT: **Entire selected area**.
- TODO-SCREENSHOT: **Entire selected area except…** with excluded chips (demo towns).
- TODO-SCREENSHOT: **Priority** field and help “Lower numbers are checked first…”.
- TODO-SCREENSHOT: **Review required** inline warning plus the two checkboxes.
- TODO-SCREENSHOT: **Test an address** Primary match / Also matches.

---

## 6. Delivery Charges

**What you are looking at**  
Price for Area + Option.

**What matters**  
Checkout amount. Missing charges must not become silent free shipping.

**Safely change**  
Authorised amounts and charge types (flat / per item / advanced).

**Leave alone**  
Production prices without approval.

**After Save**  
New checkouts use the new amount. Old orders do not change.

---

## 7. Pickup Locations

**What you are looking at**  
Collection points for Store pickup.

**What matters**  
Address and instructions customers may see **if** pickup extras are present.

**Safely change**  
Hours, address, instructions for real pickup points.

**Leave alone**  
Confusing this page with private supply sources.

**After Save**  
Pickup-capable options can show the updated extras.

---

## 8. Product Exceptions

**What you are looking at**  
Products that differ from Site-wide Defaults.

**What matters**  
Keep this list short. Status **Ready** vs **Needs Attention**.

**Safely change**  
View/Edit or Reset to Site-wide Defaults for the intended product only.

**Leave alone**  
Resetting products you do not own.

**After Save / Reset**  
That product inherits or keeps only remaining exceptions. Other products are untouched.

---

## 9. Needs Attention

**What you are looking at**  
To-do list for incomplete product delivery setups. When shipment records are on, it can also list delivery jobs that need a person (for example Cash on Delivery waiting for a shipment, a delayed shipment, or a refund that needs a goods review).

**What matters**  
Fix product setup before promising a customer that delivery works. Opening the list does **not** clear a shipment task — completing the work does.

**Safely change**  
Open **Fix Now** and complete the product Delivery setup.

**Leave alone**  
Closing the list without fixing items you are responsible for.

**After Save**  
Items leave the list when they have a usable setup.

---

## 10. Settings

**What you are looking at**  
Customer experience, orders, Setup Guide, Access, **WooCommerce Cart & Checkout Blocks** status, **Optional integrations** status. Advanced switches are collapsed.

**What matters**  
Administrator is a protected full-access role. Subordinate roles are configurable. Blocks and optional integrations are **status**, not experimental checkboxes.

**Safely change**  
Nothing without administrator authorisation.

**Leave alone**  
Advanced checkout/runtime switches; Access rows you do not understand; treating Optional integrations as compatibility toggles.

**After Save**  
Storefront/checkout behaviour can change for everyone. This is not a product-level edit.

---

## 11. WooCommerce product → Delivery tab

**What you are looking at**  
Summary: Currently using, customized field count, Fulfilment / Delivery method / options / estimate.

**What matters**  
Most products should say they have no special settings.

**Safely change**  
**Customize This Product** only for true exceptions.

**Leave alone**  
Customizing every field “just in case”.

**After Save**  
**Save Product Delivery Settings** writes exceptions. **Reset to Site-wide Defaults** removes them for this product only.

---

## 12. Variation Delivery summary

**What you are looking at**  
Per-variation summary on the variable product editor.

**What matters**  
Default is **Use Product Setting**.

**Safely change**  
**Customize This Variation** for one variation’s differences.

**Leave alone**  
Copying parent exceptions onto every variation.

**After Save**  
**Save Variation Delivery Settings** or **Reset this variation to Product Settings**.

---

## 13. Preview Delivery

**What you are looking at**  
Read-only effective values (Information | Result | Source).

**What matters**  
Ready vs Needs Attention. Which layer each field comes from.

**Safely change**  
Nothing — it is read-only. Go back to defaults or customize to edit.

**Leave alone**  
Using Preview as a customer-address price calculator.

**After Save**  
Not applicable; refresh after you save elsewhere.

---

## 14. Customer product page

**What you are looking at**  
Cascading location + compact delivery selector.

**What matters**  
Country → Region/State → Locality (pack-backed) → Postcode when relevant. Changing a parent clears children. Delivery card: **service name → ETA/timeframe → prominent fee**. Radio + **bold public option name**. Variable products: choose the variation first. Without a pack, Country/Region can still appear while locality results are empty — expected.

**Safely change**  
Nothing in admin from this screen; this is the shop.

**Leave alone**  
Expecting fulfilment group headings or a long description here. Treating empty locality search as a crash when no pack is installed.

**After Save**  
Admin saves appear here after reload (and variation re-selection if variable).

**Screenshot TODO:**

- TODO-SCREENSHOT: PDP country selector (Ghana).
- TODO-SCREENSHOT: PDP region (Greater Accra).
- TODO-SCREENSHOT: PDP locality search (Accra) with pack **ready**.
- TODO-SCREENSHOT: delivery card showing Standard Delivery, estimate, GH₵30 (or the demo fee). Use QA product **#39705**. Redact customer account email if visible.

---

## 15. Cart

**What you are looking at**  
Remembered delivery choice on the line.

**What matters**  
The choice from the product page should persist. Compatible items can share one charge. Pickup is not presented as shipping to the customer destination. Mixed Delivery + Pickup: delivery keeps its fee; pickup may be FREE / 0.00.

**Safely change**  
Empty the training cart when finished. Do not enable COD.

**Leave alone**  
Placing paid orders for practice.

**After Save**  
Not a Delivery Engine save.

**Screenshot TODO:** Cart line with Standard Delivery and GH₵30 (QA cart, no customer PII).

---

## 16. Checkout (Classic and Blocks)

**What you are looking at**  
WooCommerce shipping line on **Classic Checkout** and, if the store uses them, **Cart/Checkout Blocks**.

**What matters**  
Amount matches the Delivery Charge. Label prefers the public Delivery Option name. Both checkout types use the same Delivery Engine fees. Store pickup at 0.00 is valid. A missing charge must not become silent free shipping.

**Safely change**  
Nothing without a real purchase intent.

**Leave alone**  
Forcing a $0 workaround if the charge is missing; looking for a Delivery Engine “enable Blocks” checkbox.

**After Save**  
Not applicable.

**Screenshot TODO:** Checkout shipping line with public option name and GH₵30. Stop before payment. Redact emails and payment fields.

---

## 17. Thank-you / My Account / email

**What you are looking at**  
Compact **Delivery details**: Delivery option + Estimated delivery (pickup extras only if present).

**What matters**  
No fulfilment label, no generic “Delivery method: Delivery”, no duplicate shipping-price block.

**Safely change**  
Nothing.

**Leave alone**  
Trying to add staff-only fields to customer emails.

---

## 18. WooCommerce order → Delivery information

**What you are looking at**  
Staff panel on the order.

**What matters**  
What the customer paid for. Historical orders stay as purchased.

**Safely change**  
Nothing for training. Use QA orders **#39721** / **#39724** read-only.

**Leave alone**  
Rewriting the panel after Site-wide Defaults change; technical meta.

**After Save**  
Order delivery details are not updated by later default edits.

---

## 19. Delivery Engine → Shipments (when enabled)

**What you are looking at**  
The staff work list and detail screen for delivery shipments. It appears only after an Administrator turns on shipment records.

**What matters**  
A shipment is the job for moving the goods. It is built from the delivery details already saved on the WooCommerce order. It is not a new order and not a live carrier feed.

**Safely change**  
Authorised status actions, current estimated delivery, and tracking fields. **Create shipment from order** for a genuine Cash on Delivery (or similar) job after you have read the preview.

**Leave alone**  
Typing delivery prices, regrouping items, creating a fake Store pickup shipment, merging Air and Sea by hand, or marking an order paid just to force a shipment.

**After Save**  
History records who did the work. Customers may see status, current estimate, and **Track shipment** only when tracking links are on and the URL is a safe `http` or `https` address.

Full how-to: [11 — Stage 14 shipments](11-STAGE-14-SHIPMENTS.md). Practice jobs: playbook use cases 20–30.

---

## 20. Delivery Engine → Bulk Tools (administrators)

**What you are looking at**  
Catalog, Import / Export, Validation & Cleanup, Jobs / History, Charges.

**What matters**  
Preview before Apply. Background jobs. Rollback from Jobs / History.

**Safely change**  
QA preview only in training unless a change window is authorised.

**Leave alone**  
Apply on a failed preview; importing a package onto production without a window.

**After Save**  
Jobs appear in Jobs / History. Playbook 41–42.
