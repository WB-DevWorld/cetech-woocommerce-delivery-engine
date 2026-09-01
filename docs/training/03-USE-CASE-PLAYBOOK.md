# Use-Case Playbook

**Audience:** Experienced staff and administrators  
**Version:** 1.0.0-rc.9 (schema 5)  
**QA fixtures:** #39705 (simple), #39717 (parent), #39718 (A), #39719 (B)  
**Everyday system:** Site-wide Defaults + Product Exceptions. When shipment records are on, also **Delivery Engine → Shipments**.

Each use case includes: Goal, When, Starting point, Steps, What you should see, Customer impact, Order impact, Common mistake, Verify success.

Screenshots are deferred. Follow the live screens.

**Shipments:** Use cases 20–30. Full how-to: [11 — Stage 14 shipments](11-STAGE-14-SHIPMENTS.md).  
**Checkout, overlapping areas, mixed carts:** Use cases 31–40.  
**Bulk Tools (administrators):** Use cases 41–42.  
**Tick every feature:** [13 — Feature coverage and confirmation](13-FEATURE-COVERAGE-AND-CONFIRMATION.md).  
Old menu names from older guides: [Troubleshooting FAQ](07-TROUBLESHOOTING-FAQ.md).

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

---

## USE CASE 31 — Test an address: Primary match and Also matches

**Goal:** See every Delivery Area that covers a sample address.  
**When:** A city sits inside a region (for example Accra inside Greater Accra), or staff need to confirm coverage.  
**Starting point:** **Delivery Engine → Delivery Areas** → **Test an address**.  
**Steps:** Enter country `GH` (or the store country), region `AA` or `Greater Accra`, city `Accra` if that area exists. Click **Run test**. Repeat once with the region name and once with the short code.  
**What you should see:** **Primary match** is the more-specific area (usually the city). **Also matches** lists the broader area (usually the region). Nested overlap is normal, not an error.  
**Customer impact:** Checkout can still quote the selected Delivery Option using a broader area’s charge when the city has none for that option.  
**Common mistake:** Treating Also matches as a warning, or rewriting areas so only one can match.  
**Verify:** Both the region name and the short code name the same areas.

---

## USE CASE 32 — City has no charge; broader area supplies the selected option

**Goal:** Confirm overlapping-area pricing without duplicating charges onto every city.  
**When:** Air (or another option) is priced on Greater Accra (region) but not on Accra (city).  
**Starting point:** Delivery Charges for the region option, then Classic or Blocks checkout with an Accra address.  
**Steps:** Select that Delivery Option on a QA product. Checkout to Accra / Greater Accra / Ghana. Do **not** copy the region charge onto the city area “to make it work.”  
**What you should see:** The selected option still shows the region’s configured fee. The option name does not change.  
**Customer impact:** Correct fee for the option they chose.  
**Common mistake:** Duplicating Air onto every city Delivery Area.  
**Verify:** Fee matches the broader area’s Delivery Charge for that same option.

---

## USE CASE 33 — Invalid city charge does not silently inherit

**Goal:** Fail closed when the more-specific area has a broken charge for the selected option.  
**When:** A city Delivery Charge for that option is missing amount, negative, or unusable, while the region still has a valid charge.  
**Steps:** Do **not** create this on production. On QA only, an administrator may demonstrate that checkout does **not** take the region fee when the city charge for the same option is invalid. Then restore.  
**What you should see:** Checkout does not invent a fee. Missing or invalid configuration never becomes silent free shipping.  
**Common mistake:** Assuming any overlapping area can always fill in.  
**Verify:** The selected Delivery Option is not replaced with a different option.

---

## USE CASE 34 — Mixed In Store Delivery and Store pickup in one cart

**Goal:** Delivery and pickup can sit in the same cart without a false pricing error.  
**When:** One In Store item uses Delivery; another uses Store pickup.  
**Starting point:** QA products configured for each path. Empty the training cart first.  
**Steps:** Add the Delivery item (select its Delivery option). Add the Pickup item (select Store pickup). Open cart, then Classic and/or Blocks checkout.  
**What you should see:** Delivery line shows the real fee (example GHS 50). Pickup shows FREE / 0.00. No message that “Delivery pricing is not available.” Pickup is not presented as shipping to the customer address.  
**Customer impact:** Both choices stay as selected.  
**Common mistake:** Forcing both lines onto one shipping method, or treating pickup 0 as a missing rate.  
**Verify:** Both Classic and Blocks if the store uses both.

---

## USE CASE 35 — Store pickup at 0.00 is valid

**Goal:** Explicit free pickup is allowed.  
**When:** Pickup is configured with amount 0.  
**Steps:** Checkout a pickup-only QA cart.  
**What you should see:** Shipping amount 0.00 / FREE for pickup. Place Order is not blocked for “missing price.”  
**Common mistake:** Treating 0 as the same as a missing Delivery Charge.  
**Verify:** A genuine missing Delivery option charge still fail-closes; pickup 0 does not.

---

## USE CASE 36 — International Air or Sea only, including a single option

**Goal:** International checkout never offers local delivery or pickup.  
**When:** International Site-wide Defaults list Air and/or Sea only.  
**Steps:** Open an International QA product. Confirm only Air and/or Sea. If only one of those is assigned, checkout uses that option (no extra customer choice required for a second mode that does not exist).  
**What you should see:** No Standard Delivery, no Store pickup, no warehouse-only option.  
**Common mistake:** Adding a local Delivery Option to International “so checkout has something.”  
**Verify:** Preview Ready; checkout fee matches the Air or Sea Delivery Charge for the destination.

---

## USE CASE 37 — In Warehouse stays on local delivery

**Goal:** Warehouse products do not leak Air, Sea, or pickup.  
**When:** In Warehouse Site-wide Defaults.  
**Steps:** Open a warehouse-path QA product. Confirm local Delivery Options only.  
**What you should see:** Ready; local delivery; customers still do not see an “In Warehouse” heading.  
**Common mistake:** Assigning International Air/Sea or Store pickup to warehouse defaults.  
**Verify:** Product page + Preview.

---

## USE CASE 38 — Classic Checkout shows the Delivery Engine fee

**Goal:** Prove Classic Checkout on the live theme/pages.  
**When:** The store still has Classic cart/checkout, or you are regression-checking Classic.  
**Steps:** QA product → select option → Classic cart → Classic checkout with a matching address. Stop before paying unless authorised.  
**What you should see:** Shipping line uses the public Delivery Option name and the configured charge.  
**Common mistake:** Switching the whole store to Blocks to “fix” Classic, or the reverse.  
**Verify:** Amount matches Delivery Charges.

---

## USE CASE 39 — Cart/Checkout Blocks shows the same fee

**Goal:** Prove WooCommerce Cart and Checkout **Blocks**.  
**When:** The cart or checkout page uses Blocks (Settings may show Blocks currently in use).  
**Steps:** Repeat use case 38 on the Blocks cart and Blocks checkout. Confirm mixed pickup (use case 34) on Blocks as well if pickup is offered.  
**What you should see:** Same selected option, same fee, same public label as Classic. No experimental Settings checkbox is required to “turn Blocks on.”  
**Common mistake:** Looking for a Delivery Engine “enable Checkout Blocks” switch, or treating a WooCommerce Blocks warning as proof the adapter is missing.  
**Verify:** Settings **WooCommerce Cart & Checkout Blocks** status row; live Blocks checkout fee.

---

## USE CASE 40 — Leftover WooCommerce Flat rate / Local pickup must not replace Delivery Engine

**Goal:** Managed Delivery Engine packages fail closed instead of quoting native leftovers.  
**When:** A WooCommerce zone still has Flat rate or Local pickup beside **Delivery**.  
**Steps:** Put a Delivery Engine item in the cart. Checkout.  
**What you should see:** The customer is not quietly charged the native Flat rate / Local pickup instead of the Delivery Engine fee. Missing Delivery Engine pricing does not become $0.  
**Common mistake:** Adding Flat rate “as a backup.”  
**Verify:** Ask an administrator before disabling leftover methods on a live zone.

---

## USE CASE 41 — Bulk Tools catalog Preview before Apply (Administrators)

**Goal:** Change many products only after a Preview.  
**When:** Authorised catalog work during a change window. Prefer QA targets.  
**Starting point:** **Delivery Engine → Bulk Tools → Catalog**.  
**Steps:** Choose targets → choose the action → **Preview**. Read Would fail vs ready. Apply **only** when the preview is safe. Watch **Jobs / History**.  
**What you should see:** Preview counts. Background job, not an instant freeze of the whole catalogue.  
**Common mistake:** Clicking Apply on a failed preview, or Apply repeatedly.  
**Verify:** Jobs / History shows the job. Restore QA with rollback if you applied.

---

## USE CASE 42 — Bulk Tools Validation Scan and charge rollback (Administrators)

**Goal:** Scan for unsafe catalog states; preview a charge change.  
**Starting point:** **Bulk Tools → Validation & Cleanup** and **Charges**.  
**Steps:** Run Validation Scan. It reports problems; it does not invent free shipping. On **Charges**, preview an amount change on QA only. Apply and rollback only with authorisation.  
**What you should see:** A report, then (if authorised) a reversible charge job.  
**Common mistake:** Importing a configuration package onto production without a window.  
**Verify:** After rollback, the QA charge is the previous amount.

