# Visual Walkthrough (written screen tour)

**Audience:** New staff and trainers  
**Version:** 1.0.0-rc.6  
**Screenshots:** Deferred. This tour is written against the live 1.0.0-rc.6 screens. Do not use leftover older images as teaching truth.

Walk this path on the live site (read-only unless a trainer authorises a QA edit):

WordPress admin → Overview → Site-wide Defaults → Delivery Options / Areas / Charges → Pickup Locations → Product Exceptions → Needs Attention → Settings → WooCommerce product Delivery tab → Preview Delivery → product page → cart → checkout → WooCommerce order Delivery information → Shipments (only if an Administrator has turned shipment records on).

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

## 5. Delivery Areas

**What you are looking at**  
Where the store delivers (condition builder).

**What matters**  
Address matching. For **State / Region**, the checkout name and that country’s short code both match (for example Ghana `Greater Accra` and `AA`). Advanced matching (mode/priority) stays collapsed until needed.

**Safely change**  
Authorised geography updates; **Test an Address**.

**Leave alone**  
Overlapping experimental rules on a live store.

**After Save**  
Charges that use this area continue to apply to matching addresses.

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
Customer experience, orders, Setup Guide, Access. Advanced switches are collapsed.

**What matters**  
Administrator is a protected full-access role. Subordinate roles are configurable.

**Safely change**  
Nothing without administrator authorisation.

**Leave alone**  
Advanced checkout/runtime switches; Access rows you do not understand.

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
Compact delivery selector.

**What matters**  
Radio + **bold public option name**. Second line: **Estimated delivery:** …  
Variable products: choose the variation first.

**Safely change**  
Nothing in admin from this screen; this is the shop.

**Leave alone**  
Expecting fulfilment group headings or a long description here.

**After Save**  
Admin saves appear here after reload (and variation re-selection if variable).

---

## 15. Cart

**What you are looking at**  
Remembered delivery choice on the line.

**What matters**  
The choice from the product page should persist. Compatible items can share one charge.

**Safely change**  
Empty the training cart when finished. Do not enable COD.

**Leave alone**  
Placing paid orders for practice.

**After Save**  
Not a Delivery Engine save.

---

## 16. Checkout

**What you are looking at**  
Classic Checkout shipping line.

**What matters**  
Amount matches the Delivery Charge. Label prefers the public Delivery Option name.

**Safely change**  
Nothing without a real purchase intent.

**Leave alone**  
Forcing a $0 workaround if the charge is missing.

**After Save**  
Not applicable.

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
