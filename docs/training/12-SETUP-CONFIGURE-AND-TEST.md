# How to use each Delivery Engine menu

**Audience:** New staff — start here if you are new to this plugin  
**Plugin:** CETECH WooCommerce Delivery Engine **1.0.0-rc.5**  
**Open:** WordPress admin left menu → **Delivery Engine** (location-pin icon)

This guide is only about the plugin. Each section is one menu. For every menu you get **how to open it, how to do the work, what to type, which button to press, and how to check it**.

You do **not** need PHP, databases, or plugin architecture. Follow the live screens.

**Everyday rule:** Set normal rules once on Site-wide Defaults. Most products should inherit them. Customize a product only when it must be different.

---

## How to open the plugin

1. Log into WordPress admin.  
2. In the left menu, click **Delivery Engine**.  
3. If setup is not finished, you land on **Setup Guide**. If setup is finished, you land on **Overview**.  
4. The other plugin pages are listed under Delivery Engine. Click the name you need.

If a menu name is not in the list below, stop and ask an administrator.

### Menus you will use

1. Setup Guide (only while setup is incomplete, or from Settings later)  
2. Overview  
3. Delivery Options  
4. Delivery Areas  
5. Delivery Charges  
6. Pickup Locations  
7. Site-wide Defaults  
8. Settings  
9. Product Exceptions  
10. Needs Attention  
11. Shipments (only after you turn that setting on)

The product **Delivery** tab and **Preview Delivery** are plugin screens too. They live on the WooCommerce product editor, not in the left menu.

### First-time order

Do the work in this order so each screen has what it needs:

**Setup Guide** (if showing) → **Delivery Options** → **Delivery Areas** → **Delivery Charges** → **Pickup Locations** (only if you offer pickup) → **Site-wide Defaults** → **Settings** → **Overview** → product **Delivery** tab and **Preview** → shop check → **Product Exceptions** / **Needs Attention** as needed → **Shipments** last.

---

# 1. Setup Guide

**Menu:** **Delivery Engine → Setup Guide**  
After setup is complete, this leaves the left menu. Reopen from **Delivery Engine → Settings → Run Setup Guide Again**.

**What it is for**  
Builds the first store defaults, a first area, a first charge, then turns the engine on.

You can click **Save & finish later** on a step if you must stop. Come back to Setup Guide and continue.

## How to do Step 1 — Store Setup

1. Read **Store country** and **Store currency**. Delivery Engine does not replace WooCommerce here. If they are wrong, fix them in WooCommerce, then reload this step.  
2. Under **How will most products normally be fulfilled?**, click one card:  
   - **In Warehouse** — stored locally and delivered (best first choice for most stores)  
   - **In Store** — from a store; may include pickup  
   - **International** — Air and/or Sea only  
3. Click **Continue**.

## How to do Step 2 — Fulfilment Types

1. Under **Which fulfilment types does your store use?**, tick every type you really sell.  
2. First-time: tick **In Warehouse** only unless you truly need the others.  
3. Click **Continue**.

## How to do Step 3 — Site-wide Defaults

The subtitle looks like **Site-wide Defaults 1 of 1 — In Warehouse**. If you ticked more types, this step repeats.

### Create a Delivery Option here if the list is empty

1. Open **Create Delivery Option** (it may already be open).  
2. **Name:** type what customers should see, for example `Standard Delivery`.  
3. **Customer description:** optional. Skip it for now.  
4. **Estimated delivery:** `3–5 business days`.  
5. Leave **Active** ticked.  
6. Click **Create and select**.  
7. Tick the option in the list if it is not already ticked. You must have **at least one** ticked.

For International, use **Create Air or Sea Shipping option** and choose Air or Sea. Do not create a local option here.

### Fill the rest of this type

1. **Customer fulfilment:** In Warehouse is **Delivery only**. For In Store, choose Delivery only unless pickup is a real service.  
2. **Estimated delivery:** type `3–5 business days` if empty.  
3. If you offer pickup, add a pickup location on this step (name, address, city, ready time) and select it.  
4. Click **Save In Warehouse Default & Continue** (the button uses the type name).

## How to do Step 4 — Areas & Charges

### Add an area

1. Open **Add Delivery Area**.  
2. **Area name:** `Local delivery`.  
3. **City or region (optional):** leave blank the first time so it matches the whole store country.  
4. Click **Save area**.

### Add a charge

1. Open **Create Delivery Charge**.  
2. **Charge name:** `Standard local delivery`.  
3. Choose **Flat amount per delivery**.  
4. **Amount:** `25.00` (or your real fee).  
5. **Delivery option:** Standard Delivery.  
6. **Delivery area:** Local delivery.  
7. Click **Create charge**.  
8. Click **Continue**.

## How to do Step 5 — Apply to Products

1. Read the counts (safe to inherit / protected exceptions / needs review).  
2. Click **Save & Apply Site-wide**.  
3. This does **not** copy frozen values onto every product. Exceptions stay.

## How to do Step 6 — Finish

1. If **WooCommerce shipping** is Action needed: click **Configure WooCommerce Shipping** → open your zone → **Add shipping method** → choose **Delivery** → save → return here and refresh.  
2. If you see **Activate Delivery Engine**, click it.  
3. Click **Go to Delivery Overview**.

**Check:** Overview is the everyday home. Setup Guide is gone from the left menu. Preview on a normal product is **Ready**.

---

# 2. Overview

**Menu:** **Delivery Engine → Overview**

**What it is for**  
Your daily home. It does not save settings.

## How to use it

1. Click **Delivery Engine → Overview**.  
2. Read the banner. It tells you if customers can use Site-wide Defaults.  
3. Note the primary fulfilment type.  
4. If Needs Attention is not zero, click through and fix those rows (section 12).  
5. Use the shortcuts when you need Defaults, Exceptions, or Preview.

**Check:** You can say “delivery is ready” or “this still needs work.” You did not change Advanced switches here.

---

# 3. Delivery Options

**Menu:** **Delivery Engine → Delivery Options**

**What it is for**  
The public names customers choose. Checkout uses this name on the shipping line.

## How to add an option

1. Click **Delivery Engine → Delivery Options**.  
2. Click **Add Delivery Option** (or **Create Delivery Option** if the list is empty).  
3. Under **What customers see**:  
   - **Customer-facing name:** `Standard Delivery` (required)  
   - **Short description:** leave blank for now (not shown on the compact product selector)  
   - **Delivery type:** choose **Local delivery** for warehouse/home delivery, **Store pickup** for collection, **Air Shipping** or **Sea Shipping** for International  
   - **Estimated delivery:** `3–6 business days`  
   - **Status:** **Active**  
4. Do not open **Advanced details** unless you need a reference code. Leave it blank to auto-generate.  
5. Click **Create Delivery Option**.  
6. Click **Back to Delivery Options** if you are not already on the list.

## How to add Air or Sea

Same as above, but **Delivery type** = **Air Shipping** or **Sea Shipping**, and name them clearly, for example `Air Shipping`.

## How to edit an option

1. On the list, click **Edit**.  
2. Change the customer-facing name or estimate.  
3. Click **Save Delivery Option**.

## How to stop offering an option

1. Click **Edit**.  
2. Set **Status** to inactive, then **Save Delivery Option**.  
   Or use **Deactivate** on the list.  
3. Do not delete an option that Site-wide Defaults or Delivery Charges still use.

**Check:** The list shows it **Active**. You can tick it on Site-wide Defaults. The shop shows that exact name.

---

# 4. Delivery Areas

**Menu:** **Delivery Engine → Delivery Areas**

**What it is for**  
Where you deliver. Together with a charge, this decides the fee for the customer’s address.

## How to add an area

1. Click **Delivery Engine → Delivery Areas**.  
2. Click **Add Delivery Area**.  
3. **Delivery area name:** `Local delivery` (required).  
4. **Customer-facing label:** optional; you can leave it the same as the name.  
5. **Status:** **Active**.  
6. Under **Where should this delivery area apply?** use the table:  
   - **Location:** choose **Country**  
   - **Value:** type the 2-letter country code that matches your store, for example `TT` or `GH` (the same country as WooCommerce store address)  
7. To narrow it later, click **+ Add another location condition** and add **State / Region**, **City**, or **Postcode**.  
8. Leave **Advanced matching** closed.  
9. Leave **Advanced details** / reference code blank.  
10. Click **Create Delivery Area**.

## How to test an address

1. Stay on **Delivery Areas** (list) or open the area.  
2. Open **Test an address**.  
3. Fill:  
   - **Country code** (same 2-letter code)  
   - **Region**, **City**, **Postcode** if you used those conditions  
4. Click **Run test**.  
5. Read **Result.** It should name this area.

## How to edit an area

1. Click **Edit**.  
2. Change the name or conditions.  
3. Click **Save Delivery Area**.  
4. Run **Test an address** again.

**Check:** The test matches. You can pick this area on a Delivery Charge.

---

# 5. Delivery Charges

**Menu:** **Delivery Engine → Delivery Charges**

**What it is for**  
The money customers pay for one area + one option.

## How to add a charge

1. Click **Delivery Engine → Delivery Charges**.  
2. Click **Add Delivery Charge**.  
3. Under **How should this delivery charge work?**, choose **Flat amount per delivery** the first time.  
   - **Amount per item** = the fee grows with quantity  
4. Under **Delivery area and option**:  
   - **Delivery area:** Local delivery  
   - **Delivery option:** Standard Delivery  
5. Under **Delivery fee**, type `25.00` next to the store currency.  
6. Leave **Advanced Details** closed (code, supplier, origin, dates).  
7. Click **Create Delivery Charge**.

The list **Charge Name** is built from the area and option. You do not type a separate name on this full page (Setup Guide does have a **Charge name** field).

A typed **0.00** means you *meant* free shipping. A missing charge must not become silent free shipping.

## How to check a price on this screen

1. On the Delivery Charges list, find **Check a delivery price**.  
2. Fill the test address and the same delivery option.  
3. Click **Check delivery price**.  
4. Read **Quote result.** It should show `25.00` (or your fee).  

Leave **Preview a rate card match** alone until you are comfortable. It uses extra internal fields.

## How to deactivate a charge

On the list, click **Deactivate** and confirm. Prefer deactivate over delete if the charge was used.

## How to edit a charge

1. Click **Edit**.  
2. Change the fee or how it works.  
3. Click **Save Delivery Charge**.

**Check:** The list shows area + option + amount, Active. After Settings are on, checkout shows that amount and the public option name.

---

# 6. Pickup Locations

**Menu:** **Delivery Engine → Pickup Locations**

Skip this whole menu if customers cannot collect orders.

## How to add a pickup location

1. Click **Delivery Engine → Pickup Locations**.  
2. Click **Add Pickup Location**.  
3. **Location name:** `Main store pickup`.  
4. **Reference code:** you can type `main-store-pickup` or follow the on-screen example.  
5. **Status:** **Active**.  
6. **Address:** fill Address line 1, City, and **Country code** (2 letters). Add line 2, region, postcode if you have them.  
7. **Phone**, **Email**, **Opening hours** (example `Mon–Fri 9:00 AM – 5:00 PM`), **Pickup instructions**.  
8. Click **Create Location**.

## How to use it

On **Site-wide Defaults** for **In Store**, set customer fulfilment to pickup (or delivery + pickup), tick a **Store pickup** Delivery Option, and select this location. Or customize one product the same way.

**Check:** Preview shows **Ready for pickup**. Thank-you shows address/instructions only if you filled them in.

---

# 7. Site-wide Defaults

**Menu:** **Delivery Engine → Site-wide Defaults**

**What it is for**  
The normal rules each fulfilment type uses. Products with no exception inherit these, field by field.

## How to set defaults for In Warehouse

1. Click **Delivery Engine → Site-wide Defaults**.  
2. Click the **In Warehouse** tab if you also use other types.  
3. Confirm **Fulfilment** shows In Warehouse and **Customer fulfilment** is **Delivery only**.  
4. Under **Delivery options**, tick **Standard Delivery**.  
5. **Estimated delivery:** `3–5 business days` (or leave blank to use the option’s own estimate).  
6. Click **Save Changes**.  
7. Repeat for **In Store** or **International** tabs if those types are in use. International: tick only Air and/or Sea options.

If setup is still incomplete, you may also see **Save & Apply Site-wide**. That opens apply. It does not copy frozen values onto every product.

## How to change the primary default

Only if you understand the catalogue. On Site-wide Defaults, set which type ordinary unclassified products inherit (In Warehouse / In Store / International). Save.

**Check:** Edit a product with no exception → **Delivery** tab says it uses the Site-wide Default. **Preview Delivery** is **Ready**. The shop shows the option name + estimate. Later **Save Changes** update inherited fields immediately.

---

# 8. Settings

**Menu:** **Delivery Engine → Settings**

**What it is for**  
Store-wide switches. Not for editing one product.

Scroll to the bottom of the Settings form and click **Save Changes** after you tick boxes (except **Activate Delivery Engine**, which is its own button).

## How to read General

1. Open **Settings**.  
2. Read **Delivery Engine status**, **Setup**, **WooCommerce shipping**.  
3. If shipping is Action needed: click **Configure WooCommerce Shipping** → your zone → **Add shipping method** → **Delivery** → save. Return to Settings and refresh.  
4. If setup and shipping are ready and you see **Activate Delivery Engine**, click that button. It turns on the supported customer path in one go (product choices, cart memory, checkout check, fees, saving details on orders, Site-wide Defaults for simple and variable products).

## How to turn on customer order and email details

1. Under **Customer experience**, tick:  
   - **Show delivery summary on customer order pages**  
   - **Include delivery summary in customer emails**  
2. Save Settings.

## How to leave order saving on

Under **Orders**, **Save delivery details on orders** should already be on after Activate. Leave it on.

## How to turn shipments on (later)

Only after checkout already works.

1. Under **Shipments**, tick **Enable shipment records**.  
2. Optionally tick **Enable customer tracking links** if customers should get **Track shipment** when a safe `http`/`https` URL is saved.  
3. Save Settings.  
4. **Delivery Engine → Shipments** appears for authorised staff.

Tracking links alone do **not** create shipments and do not call carriers.

## How to reopen Setup Guide

Under Setup Guide, click **Run Setup Guide Again**. Review mode does not wipe exceptions.

## How to use Access

**Administrator** has a lock. Do not try to edit that row. To let Shop Manager (or another real WordPress role) work in the plugin, tick only the capabilities that role should have, then Save. For shipment staff you may grant **Manage shipments** and, separately, **Update shipment status**.

## Advanced

Leave it collapsed. Do not turn on Checkout Blocks.

**Check:** General shows Active, Complete, Ready. A product on the shop shows delivery choices.

---

# 9. Product Delivery tab

**Where:** **WooCommerce → Products** → edit the product → **Delivery** tab  
For variations: expand the variation → Delivery summary.

This is plugin work. It is just not in the Delivery Engine left menu.

## How to leave a product on defaults (usual case)

1. Edit the product (practice on QA **#39705** if you have it).  
2. Open **Delivery**.  
3. If **Currently using** is the Site-wide Default, **stop**. Do not click Customize.

## How to give one product a different estimate

1. On the **Delivery** tab, click **Customize This Product**.  
2. For **Estimated delivery**, leave **Use Site-wide Default: …** on other fields.  
3. For Estimated delivery, choose **Use a different estimate** and type the new text, for example `5–8 business days`.  
4. Click **Save Product Delivery Settings**.  
5. Click **Preview Delivery** and confirm **Ready**.  
6. This product now appears under **Product Exceptions**.

To undo: click **Reset to Site-wide Defaults**, confirm, then Preview again.

## How to change only one variation

1. Edit the variable parent (QA **#39717**).  
2. Open the variation (QA **#39719** if only B should differ).  
3. Click **Customize This Variation**.  
4. Leave **Use Product Setting: …** on fields that should stay with the parent.  
5. For the field that must differ, choose the “set a different …” option and fill it.  
6. Click **Save Variation Delivery Settings**.  
7. Preview that variation. On the shop, select that variation first, then the delivery choice.

To undo that variation only: **Reset to Product Settings**.

**Check:** Shop shows the public option + estimate. Variable products update when the variation changes.

---

# 10. Preview Delivery

**Where:** product **Delivery** tab → **Preview Delivery**, or a link from Overview. Not a left-menu item.

## How to use it

1. Save the product or defaults first.  
2. Click **Preview Delivery**.  
3. Read **Ready** or **Needs Attention**.  
4. Read the table: Information | Result | Source (Site-wide, Product, or Variation).  
5. If Needs Attention, go back to Site-wide Defaults or Customize, save, then Preview again.

**Check:** Ready, and the shop matches Result. You cannot type prices here.

---

# 11. Product Exceptions

**Menu:** **Delivery Engine → Product Exceptions**

**What it is for**  
Products that differ from Site-wide Defaults. Keep this list short.

## How to find and edit an exception

1. Click **Product Exceptions**.  
2. Find the product. Note **Ready** or **Needs Attention**.  
3. Click **View / Edit**. That opens the product **Delivery** tab.  
4. Change only the fields that must differ. **Save Product Delivery Settings**. Preview.

## How to put a product back on defaults

1. On Product Exceptions, click **Reset to Site-wide Defaults** for **that product only**, and confirm.  
2. Or do the same reset on the product Delivery tab.  
3. Other products are not changed.

**Check:** Only intentional exceptions remain, all Ready.

---

# 12. Needs Attention

**Menu:** **Delivery Engine → Needs Attention**

Opening this page does **not** clear the badge. Finishing the work does.

## How to fix a product row

1. Click **Needs Attention**.  
2. Click **Fix Now**.  
3. Complete Site-wide Defaults for that type, or Customize only the missing product field.  
4. Save.  
5. **Preview Delivery** until **Ready**.  
6. Return to Needs Attention. The row should be gone.

## How to fix a shipment row (only if Shipments is on)

Rows can include: Cash on Delivery waiting for a shipment, delayed shipment, cancel after the parcel already moved, refund that needs a goods review.

1. Click the order or shipment link.  
2. For COD: **Create shipment from order** (section 13) — do not mark the order paid just to force it.  
3. For delayed: investigate, then use a **Resume as …** status button.  
4. For refund/cancel review: decide the physical goods; do not auto-cancel a parcel already in transit.

**Check:** The row disappears. The menu badge drops.

---

# 13. Shipments

**Menu:** **Delivery Engine → Shipments**  
Shows only after **Settings → Enable shipment records**.

**What it is for**  
The work record for a delivery group already saved on the order. Not a new order. Not a carrier login.

## How to open a shipment

1. Click **Shipments**.  
2. Search by order or reference (example `39737-D1`).  
3. Click the shipment.  
4. Read: order, destination, delivery option, items, original estimate, current estimate, tracking, History.

## How to move status

Use only the buttons on the screen, in this happy path:

**Awaiting fulfilment** → click **Mark as Processing** → **Mark as Dispatched** → **Mark as In transit** → **Mark as Delivered**.

If it is late: **Mark as Delayed / issue** and fill **Reason for delay or issue**. When it is moving again, use **Resume as In transit** (or the resume button shown).

**Cancel shipment** needs a reason. Customers never see internal reasons.

Saving tracking does **not** change status.

## How to add tracking

1. On the shipment, find **Tracking details**.  
2. **Carrier:** public name, for example `DHL`.  
3. **Tracking number:** the carrier’s number (not the shipment reference).  
4. **Tracking URL:** full address starting with `http` or `https`.  
5. **Dispatch date:** optional. Filling it does not mark Dispatched.  
6. **Public shipment note:** optional; customers can see this.  
7. Click **Save tracking**.

Customers get **Track shipment** only if shipment records are on, tracking links are on, and the URL is safe.

## How to update the current estimate

1. Change **Current estimate** only.  
2. Fill **Reason for updating the estimate** (required when the text actually changes). That reason is internal.  
3. Click **Update current estimate**.  
4. Original estimate stays as at checkout.

## How to create a shipment for Cash on Delivery

1. On **Shipments**, find **Create shipment from order**.  
2. Type the WooCommerce **Order ID**.  
3. Click **Find order**.  
4. Read the preview (order, payment method, destination, saved delivery option, items).  
5. If it is the right historical job, confirm **Create shipment**.  
6. Do not type a new price or regroup items.  
7. Creating the same order twice shows the existing shipment.

Pickup groups are skipped. Air and Sea stay as separate shipments.

**Check:** One shipment per delivery group. History shows who did the work. View Order shows a customer card without staff names or private notes.

More jobs: [11 — Stage 14 shipments](11-STAGE-14-SHIPMENTS.md) and playbook use cases 20–30.

---

# 14. How to check what customers see

This is still the plugin. It is not a left-menu item.

1. Open a product that inherits Site-wide Defaults (**View** on the product).  
2. Confirm: radio, **bold** public option name, **Estimated delivery:** line.  
3. Select the option. Add to cart. Confirm the cart still shows it.  
4. Go to checkout. Use an address in the same country (and city if your area used a city).  
5. Confirm the shipping line amount matches the Delivery Charge and the label prefers the option name.  
6. Stop before paying unless an administrator authorised a test order.  
7. For a variable product, select the variation **first**, then the delivery choice, then switch variations and confirm the options refresh.

Customers must not see “In Warehouse”, supplier names, or internal codes.

---

# Done checklist

- [ ] You can open each everyday Delivery Engine menu and say what it does  
- [ ] You can add a Delivery Option, Area, and Charge and save them  
- [ ] Test an address matches your area  
- [ ] Site-wide Defaults are saved; Preview is **Ready**  
- [ ] Settings show Active / shipping Ready; Advanced left alone  
- [ ] Shop shows option + estimate; checkout shows the real fee  
- [ ] You can customize one product field and reset it  
- [ ] Needs Attention is empty or you know how to Fix Now  
- [ ] You only open Shipments after that setting is on  

If something fails: [07 — Troubleshooting FAQ](07-TROUBLESHOOTING-FAQ.md). Stay in these menus. Do not edit PHP. Do not change delivery details on an old paid order.

---

## Related guides

- [00 — Start here](00-START-HERE.md)  
- [01 — Quick Start](01-QUICK-START.md)  
- [02 — Complete Administrator Guide](02-COMPLETE-ADMIN-GUIDE.md)  
- [03 — Use-Case Playbook](03-USE-CASE-PLAYBOOK.md)  
- [04 — Visual Walkthrough](04-VISUAL-WALKTHROUGH.md)  
