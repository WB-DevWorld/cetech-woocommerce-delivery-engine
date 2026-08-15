# Use-Case Playbook

**Audience:** Experienced staff and administrators  
**Version:** 1.0.0-rc.4  
**QA fixtures:** #39705 (simple), #39717 (parent), #39718 (A), #39719 (B)  
**Everyday system:** Site-wide Defaults + Product Exceptions — not Legacy Delivery Rules

Each use case includes: Goal, When, Starting point, Steps, What you should see, Customer impact, Order impact, Common mistake, Verify success.

Screenshots are deferred. Follow the live screens.

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

## USE CASE 14 — Two compatible products share one delivery charge

**Goal:** Understand fixed-per-shipment grouping.  
**When:** Compatible fulfilment paths in one cart.  
**Steps:** Prefer documented QA behaviour (order **#39724** showed shipping **25.00**, not 50.00) rather than creating new orders.  
**What you should see:** One delivery charge for compatible lines when configured that way.  
**Common mistake:** Expecting per-line doubling for fixed-per-shipment.  
**Verify:** Cart/checkout shipping total; shipping line uses the public option label.

---

## USE CASE 15 — Two incompatible fulfilment paths are separated

**Goal:** Incompatible paths do not wrongly share one charge.  
**Steps:** Explain with configured incompatible examples; avoid destructive live edits.  
**What you should see:** Separated shipping treatment.  
**Common mistake:** Forcing incompatible items into one option.  
**Verify:** Checkout shipping structure.

---

## USE CASE 16 — Quantity 2 with fixed-per-shipment delivery

**Goal:** Quantity increases product total, not necessarily the delivery fee.  
**Steps:** Use documented QA expectation (qty 2 still **25.00** delivery on the verified path) or carefully test on QA without payment.  
**What you should see:** Delivery fee once for that shipment type.  
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
**Common mistake:** Looking for Legacy Delivery Rules or asking for SQL.  
**Verify:** Product leaves Needs Attention; storefront shows options.

---

## USE CASE 20 — Understand Legacy is not a normal workflow

**Goal:** Recognise that Legacy Delivery Rules are retired from the everyday menu.  
**Steps:** Confirm the left menu has Overview, Site-wide Defaults, Options, Areas, Charges, Pickup, Product Exceptions, Needs Attention, Settings — and **no** Legacy item. Do not hunt for hidden support screens.  
**What you should see:** Everyday menu only.  
**Common mistake:** Training others to open Legacy as the default editor.  
**Verify:** Trainee can name the everyday system (Site-wide Defaults + Product Exceptions).
