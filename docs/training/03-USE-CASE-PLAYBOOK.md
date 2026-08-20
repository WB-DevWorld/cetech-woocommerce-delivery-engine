# Use-Case Playbook

**Audience:** Experienced staff and administrators  
**Version:** 1.0.0-rc.5  
**QA fixtures:** #39705 (simple), #39717 (parent), #39718 (A), #39719 (B)  
**Everyday system:** Site-wide Defaults + Product Exceptions. When shipment records are on, also **Delivery Engine → Shipments**.

Each use case includes: Goal, When, Starting point, Steps, What you should see, Customer impact, Order impact, Common mistake, Verify success.

Screenshots are deferred. Follow the live screens.

**Shipments:** Use cases 20–30. Full how-to: [11 — Stage 14 shipments](11-STAGE-14-SHIPMENTS.md). Old menu names from older guides: [Troubleshooting FAQ](07-TROUBLESHOOTING-FAQ.md).

---

## USE CASE 1 — Use Site-wide Defaults for a normal product

**Goal:** Let a product inherit the store default.  
**When:** Product should behave like the store standard.  
**Starting point:** WooCommerce → Products → edit QA **#39705** → **Delivery** tab.  
**Steps:** Confirm **Currently using** the Site-wide Default → do **not** click Customize unless something must differ → open **Preview Delivery**.  
**What you should see:** Preview **Ready**; product has no special delivery settings.  
**Customer impact:** Standard public Delivery option + Estimated delivery.  
**Order impact:** Future orders store the selected option details.  
**Common mistake:** Creating an unnecessary product exception.  
**Verify:** Storefront shows the expected option name and estimate.

---

## USE CASE 2 — Give one product different delivery settings

**Goal:** Override only the fields that must differ.  
**When:** One product needs a different option, method, fulfilment, or estimate.  
**Starting point:** Product **Delivery** tab, or **Product Exceptions**.  
**Steps:** **Customize This Product** → keep **Use Site-wide Default** on fields that are fine → set a different value only where needed → **Save Product Delivery Settings** → Preview.  
**What you should see:** “This item has N customized setting(s)”; Product Exceptions lists it.  
**Customer impact:** That product’s options or estimate differ.  
**Order impact:** Future orders only.  
**Common mistake:** Changing Site-wide Defaults when only one product should change (or the reverse).  
**Verify:** Compare storefront with a default product.

---

## USE CASE 3 — In Warehouse delivery product

**Goal:** Product uses the In Warehouse Site-wide Default (local delivery).  
**When:** Warehouse-path products.  
**Starting point:** Site-wide Defaults for **In Warehouse**, then product Delivery tab.  
**Steps:** Confirm In Warehouse defaults include a local Delivery Option → leave the product inheriting, or set Fulfilment to In Warehouse only if it currently differs → Preview.  
**What you should see:** Ready; local delivery options (not Air/Sea).  
**Customer impact:** Public warehouse delivery choice + estimate. Customers do **not** see an “In Warehouse” heading on the product page.  
**Order impact:** Staff order panel can still show fulfilment for operations.  
**Common mistake:** Assigning International Air/Sea options to warehouse products.  
**Verify:** Product page options + Preview.

---

## USE CASE 4 — In Store product with Delivery

**Goal:** Store-stocked item that is still delivered.  
**When:** In Store fulfilment with Delivery method.  
**Steps:** Site-wide **In Store** defaults (or a product exception) → Delivery method **Delivery** → compatible options → Save → Preview.  
**What you should see:** Ready.  
**Customer impact:** In-store path with delivery options; still no fulfilment heading on the compact selector.  
**Common mistake:** Confusing In Store fulfilment with Store pickup.  
**Verify:** Preview + storefront option name.

---

## USE CASE 5 — Configure Store Pickup where supported

**Goal:** Offer Store pickup with pickup locations.  
**When:** Customer collection is allowed.  
**Steps:** Set Delivery method **Store pickup** on the relevant default or exception → ensure **Pickup Locations** exist → assign compatible options → Save → Preview → storefront check.  
**What you should see:** **Ready for pickup** timing; location/address/instructions only if present.  
**Customer impact:** Pickup choice available.  
**Common mistake:** Enabling pickup without locations.  
**Verify:** Product options + any pickup extras on thank-you.

---

## USE CASE 6 — International product (Air and/or Sea only)

**Goal:** International fulfilment with supported shipping options only.  
**When:** International-path products.  
**Steps:** Site-wide **International** defaults → Delivery Options must be **Air Shipping and/or Sea Shipping** → ensure Delivery Areas/Charges cover destinations → classify or customize the product to International if needed → Preview.  
**What you should see:** Ready; no domestic-only options listed for this type.  
**Customer impact:** Air/Sea public names + estimate.  
**Common mistake:** Assigning local warehouse options to International.  
**Verify:** Preview + destination/charge coverage.

---

## USE CASE 7 — Variable product where all variations inherit the parent

**Goal:** One parent configuration for all variations.  
**When:** Variations share the same delivery path.  
**Starting point:** Parent **#39717** Delivery tab; variations left on **Use Product Setting**.  
**Steps:** Confirm parent inherits or is customized as intended → open **#39718** / **#39719** and confirm no variation exceptions → Preview each.  
**What you should see:** Variations follow Product Settings.  
**Customer impact:** Same family of options after selecting any variation (subject to hard rules).  
**Common mistake:** Duplicating the same override on every variation.  
**Verify:** Storefront A and B.

---

## USE CASE 8 — Give one variation different settings

**Goal:** Variation-level exception.  
**When:** Only one variation needs a different path/options/estimate.  
**Steps:** Open variation **#39719** (example) → **Customize This Variation** → set different values only where needed → **Save Variation Delivery Settings** → Preview.  
**What you should see:** That variation differs; sibling may still inherit.  
**Customer impact:** Options change when that variation is selected.  
**Common mistake:** Editing the parent when only one variation should change.  
**Verify:** Switch variations on the storefront.

---

## USE CASE 9 — Turn off an inherited option at a lower level

**Goal:** This product or variation must not offer something from above.  
**When:** Lower level must exclude an inherited Delivery Option.  
**Steps:** Customize → Delivery options → choose the supported “only these” / remove behaviour shown on that screen → Save → Preview.  
**What you should see:** Effective list excludes the removed option.  
**Customer impact:** Option no longer appears.  
**Common mistake:** Clearing the list so no options remain.  
**Verify:** Storefront options.

---

## USE CASE 10 — Preview what configuration is actually being used

**Goal:** Confirm effective settings.  
**Steps:** Product **Delivery** tab → **Preview Delivery** → read Ready / Needs Attention and Source.  
**What you should see:** Clear which layer each field comes from.  
**Customer impact:** Confidence before telling a customer it is fixed.  
**Common mistake:** Skipping Preview after Save.  
**Verify:** Matches storefront.

---

## USE CASE 11 — Customer selects delivery on a simple product

**Goal:** Validate the customer path for #39705.  
**Steps:** Open the QA simple product → choose a Delivery option → Add to cart (optional) → stop before payment.  
**What you should see:** Bold public option name; **Estimated delivery:** line; selection retained in cart.  
**Customer impact:** Can buy with the chosen delivery.  
**Order impact:** Avoid placing training orders.  
**Common mistake:** Expecting a fulfilment heading or a long description on the compact selector.  
**Verify:** Cart still shows the chosen option.

---

## USE CASE 12 — Customer selects a variation and delivery

**Goal:** Variable purchase path.  
**Steps:** Open #39717 → select Variation A → wait for Delivery options → select an option.  
**What you should see:** Options load after variation selection.  
**Common mistake:** Expecting offers before product options are chosen.  
**Verify:** Selector shows options for A.

---

## USE CASE 13 — Customer changes Variation A → Variation B

**Goal:** Confirm refresh; no silent keep of an invalid choice.  
**Steps:** Select A + delivery → switch to B → confirm options refresh.  
**What you should see:** Updated Delivery options for B.  
**Common mistake:** Assuming cart keeps A’s offer for B.  
**Verify:** Repeat the switch; checkout rejects invalid selections when validation is on.

---

## USE CASE 14 — Two compatible products share one delivery fee

**Goal:** Understand when two items can share one delivery fee.  
**When:** Compatible fulfilment paths in one cart.  
**Steps:** Prefer documented QA behaviour (order **#39724** showed shipping **25.00**, not 50.00) rather than creating new orders.  
**What you should see:** One delivery charge for compatible lines when the store is set up that way.  
**Common mistake:** Expecting the fee to double just because there are two items.  
**Verify:** Cart/checkout shipping total; shipping line uses the public option label.

---

## USE CASE 15 — Two incompatible fulfilment paths are separated

**Goal:** Incompatible paths do not wrongly share one charge.  
**Steps:** Explain with configured incompatible examples; avoid destructive live edits.  
**What you should see:** Separated shipping treatment.  
**Common mistake:** Forcing incompatible items into one option.  
**Verify:** Checkout shipping structure.

---

## USE CASE 16 — Quantity 2 with one shared delivery fee

**Goal:** Quantity increases the product total, not necessarily the delivery fee.  
**Steps:** Use documented QA expectation (qty 2 still **25.00** delivery on the verified path) or carefully test on QA without payment.  
**What you should see:** Delivery fee once for that delivery group.  
**Common mistake:** Expecting delivery to always multiply by quantity.  
**Verify:** Checkout totals.

---

## USE CASE 17 — Process a WooCommerce order and read Delivery information

**Goal:** Staff can fulfil from the order panel.  
**Steps:** WooCommerce → Orders → open existing QA order **#39721** or **#39724** → find **Delivery information**.  
**What you should see:** Staff operational details (option, estimate, charge; fulfilment/method as shown). Thank-you for the customer remains the compact public contract.  
**Order impact:** Read-only for training.  
**Common mistake:** Editing historical delivery to match new defaults.  
**Verify:** Panel readable without technical codes.

---

## USE CASE 18 — Change future delivery settings without changing historical orders

**Goal:** Understand that purchased delivery details stay as paid.  
**Steps:** Note an old order’s Delivery information → change a future Site-wide Default on QA only, then restore → reopen the old order.  
**What you should see:** Old order unchanged.  
**Common mistake:** Trying to “fix” history.  
**Verify:** Side-by-side old order vs Preview for a future product.

---

## USE CASE 19 — Troubleshoot Needs Attention

**Goal:** Safe first response.  
**Steps:** Follow [07-TROUBLESHOOTING-FAQ](07-TROUBLESHOOTING-FAQ.md) — Needs Attention list → product Delivery tab → Preview → Site-wide Defaults / Options / Charges → escalate if needed.  
**What you should see:** Either Ready after the fix, or clear escalation notes.  
**Common mistake:** Opening a screen that is not in the everyday menu, or asking for SQL.  
**Verify:** Product leaves Needs Attention; storefront shows options.

---

## USE CASE 20 — Turn on shipment records (Administrators only)

**Goal:** Show the Shipments menu and start using shipment records for delivery jobs.  
**When:** An Administrator decides the store is ready to work deliveries as shipments.  
**Starting point:** **Delivery Engine → Settings**.  
**Steps:** Open Settings → turn on **Enable shipment records** → optionally turn on **Enable customer tracking links** if customers should get a Track button when a safe tracking web address is saved → Save.  
**What you should see:** **Delivery Engine → Shipments** appears for authorised staff. Eligible prepaid delivery orders get a shipment. Store pickup does not.  
**Customer impact:** None until tracking links are on **and** a safe `http` or `https` tracking address is saved on the shipment.  
**Order impact:** Does not change what the customer already paid. The shipment is built from the delivery details already saved on the order.  
**Common mistake:** Expecting tracking links alone to create shipments, or expecting the plugin to call a carrier.  
**Verify:** Shipments is in the left menu. Full how-to: [11 — Stage 14 shipments](11-STAGE-14-SHIPMENTS.md).

---

## USE CASE 21 — Find a shipment and read the job

**Goal:** Open the right shipment and understand the work record.  
**When:** You need to fulfil, track, or answer a customer about a delivery.  
**Starting point:** **Delivery Engine → Shipments**.  
**Steps:** Search or open the shipment → read the reference (for example `39737-D1`), linked WooCommerce order, destination summary, delivery option, items, original estimate, current estimate, tracking, and staff History.  
**What you should see:** One work record for that delivery group. It is not a new WooCommerce order.  
**Customer impact:** None from viewing.  
**Order impact:** Read-only unless you use a supported action on this screen.  
**Common mistake:** Trying to type a new delivery price or regroup items.  
**Verify:** You can name the order, the delivery option, and whether tracking is present.

---

## USE CASE 22 — Move a shipment along the normal statuses

**Goal:** Use only the supported status actions on the happy path.  
**When:** Staff are preparing, sending, and completing a delivery.  
**Starting point:** The shipment detail screen.  
**Steps:** Use the supported actions: **Awaiting fulfilment → Processing → Dispatched → In transit → Delivered**. If the job is late, use **Delayed**, then recover to a progressed status when it is moving again.  
**What you should see:** Status matches the real physical job. Saving a tracking number does **not** change status by itself. **Dispatched** does not invent a dispatch date.  
**Customer impact:** Customers may see the corrected current status. They never see internal reasons.  
**Order impact:** WooCommerce payment and totals stay as they were.  
**Common mistake:** Inventing a status that is not on the screen, or leaving **Delayed** as a parking place for finished work.  
**Verify:** History shows who changed the status.

---

## USE CASE 23 — Add tracking so the customer can use Track shipment

**Goal:** Save carrier name, tracking number, and a safe tracking web address.  
**When:** You have tracking details from the carrier.  
**Starting point:** The shipment detail screen. Tracking links must already be on in Settings.  
**Steps:** Enter the public carrier name, tracking number, and a tracking URL that starts with `http` or `https` → Save.  
**What you should see:** Tracking on the staff screen. The customer **Track shipment** button appears only when shipment records are on, tracking links are on, and the URL is safe.  
**Customer impact:** Track button on View Order when all three conditions are met. No URL, or an unsafe URL, means **no** button.  
**Order impact:** None to money or saved delivery details.  
**Common mistake:** Pasting a `javascript:` link, or expecting tracking to update itself from the carrier.  
**Verify:** Staff see the number; a test customer view shows Track only with a safe URL.

---

## USE CASE 24 — Create a shipment for a Cash on Delivery order

**Goal:** Create the delivery shipment without marking the order paid just to force it.  
**When:** The customer used Cash on Delivery, Thank You looks normal, but no shipment was created automatically.  
**Starting point:** **Needs Attention** (Cash on Delivery order awaiting shipment creation) or **Delivery Engine → Shipments**.  
**Steps:** Open **Create shipment from order** (or the Needs Attention link) → enter the WooCommerce Order ID → **Find order** → read the preview (order, customer, payment method, destination, saved delivery option, items) → confirm only if it is the right historical job.  
**What you should see:** One shipment per eligible **delivery** group. The COD Needs Attention row clears. Later payment must **not** create a second shipment.  
**Customer impact:** Thank You was already normal. They may later see a delivery shipment card on View Order.  
**Order impact:** Do **not** mark the order paid just to create a shipment. Do not re-type delivery prices or options.  
**Common mistake:** Expecting automatic COD creation, or creating the same order twice (the screen should show the existing shipment).  
**Verify:** The shipment exists once. Pickup groups are skipped.

---

## USE CASE 25 — Treat Air and Sea as two separate jobs

**Goal:** Work International Air and International Sea as separate shipments.  
**When:** One order has both Air and Sea delivery groups.  
**Starting point:** **Delivery Engine → Shipments**.  
**Steps:** Open each shipment (for example `39744-D1` Air and `39744-D2` Sea) → progress, track, and estimate each one on its own.  
**What you should see:** Two shipment records for the same order.  
**Customer impact:** One card per shipment on View Order.  
**Order impact:** Do not merge the two jobs by hand.  
**Common mistake:** Updating only one shipment and assuming the other is done.  
**Verify:** Each shipment has its own status and tracking.

---

## USE CASE 26 — Update the current estimated delivery

**Goal:** Change the operational estimate without overwriting what the customer was first told.  
**When:** The delivery timing has changed after the order.  
**Starting point:** The shipment detail screen.  
**Steps:** Update **Current estimated delivery** only. Leave **Original estimated delivery** as it was at checkout. Internal reasons stay internal.  
**What you should see:** Original estimate still on the record. Current estimate updated.  
**Customer impact:** They may see that the estimate was updated. They must not see the internal reason.  
**Order impact:** Does not change the amount they paid.  
**Common mistake:** Editing the original estimate, or changing Site-wide Defaults and expecting old shipments to rewrite themselves.  
**Verify:** Original and current values are both visible to staff.

---

## USE CASE 27 — WooCommerce order is cancelled

**Goal:** Understand what the shipment does when the order is cancelled.  
**When:** WooCommerce owns the cancel. Delivery Engine follows conservatively.  
**Starting point:** The shipment for that order.  
**Steps:** Check whether the shipment has already been dispatched. If it has **not** been dispatched, cancelling the order can automatically cancel the shipment. If it is already **Dispatched**, **In transit**, **Delayed**, or **Delivered**, the shipment status stays and **Needs Attention** asks staff to review the physical goods.  
**What you should see:** Either an automatic cancel before dispatch, or an open review task after progress.  
**Customer impact:** Depends on the WooCommerce cancel. Do not pretend a parcel already in transit was never sent.  
**Order impact:** WooCommerce owns the cancel. Do not rewrite historical delivery details.  
**Common mistake:** Manually inventing a cancel after the parcel has gone out, just to make the lists look tidy.  
**Verify:** Needs Attention matches the physical reality.

---

## USE CASE 28 — A refund needs a fulfilment review

**Goal:** Money refunds stay in WooCommerce. Staff still check whether goods must move.  
**When:** There is a refund on an order that has a shipment.  
**Starting point:** **Needs Attention** and the shipment.  
**Steps:**  
1. **Full item-quantity refund** of every item, and the shipment is not yet dispatched → the shipment can be auto-cancelled.  
2. **Amount-only refund** (money refunded, no refund line quantities) → shipment status stays. Needs Attention asks for a physical review.  
3. Partial, mixed, unclear, or any refund after dispatch / in transit / delivered → do not auto-cancel. Review required.  
**What you should see:** Shipping money on the order does not change. A review row appears when the goods still need a human decision.  
**Customer impact:** Their refund is a WooCommerce money action. They may still see an active shipment until staff finish the review.  
**Order impact:** Never treat “order status = refunded” or a refund amount alone as proof that the goods came back.  
**Common mistake:** Cancelling a shipment that is already in transit because a refund was issued.  
**Verify:** Needs Attention lists refund review when status correctly stayed.

---

## USE CASE 29 — What the customer sees on View Order

**Goal:** Confirm the public shipment card is safe.  
**When:** Shipment records are on and the customer opens the order.  
**Starting point:** Customer View Order (use a QA customer view if you have one; do not expose real personal data in training notes).  
**Steps:** Find **Delivery shipments** below WooCommerce **Order Again**. Check status, current estimate, items, tracking number, and **Track shipment** only when allowed.  
**What you should see:** One card per shipment. No staff names, supplier/origin, internal cost, private notes, correction reasons, or technical IDs.  
**Customer impact:** This is the customer view.  
**Order impact:** None.  
**Common mistake:** Confusing **Order Again** with the Delivery shipments section.  
**Verify:** The card matches the staff shipment, minus private fields.

---

## USE CASE 30 — Store pickup is not a delivery shipment

**Goal:** Do not create a fake pickup shipment.  
**When:** The order is pickup-only, or mixed pickup plus delivery.  
**Starting point:** **Delivery Engine → Shipments** and the order’s saved delivery details.  
**Steps:** Confirm pickup-only orders have no delivery shipment task. On a mixed order, create or expect a shipment **only** for the genuine delivery group.  
**What you should see:** Pickup stays on the order as pickup. No extra shipment so the counts “look even.”  
**Customer impact:** Pickup extras stay on the compact delivery details when present. They do not get a fake Track button for pickup.  
**Order impact:** Creating the same delivery group twice does not duplicate it.  
**Common mistake:** Inventing a pickup shipment.  
**Verify:** Shipment list matches delivery groups only.
