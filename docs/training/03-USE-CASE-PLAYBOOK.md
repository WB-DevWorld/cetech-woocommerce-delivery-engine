# Use-Case Playbook

**Audience:** Experienced staff and administrators  
**Version:** 1.0.0-rc.2  
**QA fixtures:** #39705 (simple), #39717 (parent), #39718 (A), #39719 (B)  
**Everyday system:** Delivery Settings — not Legacy Delivery Rules

Each use case includes: Goal, When, Starting point, Steps, What you should see, Customer impact, Order impact, Common mistake, Verify success, Screenshot(s).

---

## USE CASE 1 — Use default delivery settings for a normal product

**Goal:** Let a product inherit Defaults.  
**When:** Product should behave like the store standard.  
**Starting point:** Delivery Settings → Product-Specific Settings.  
**Steps:** Enter product ID (QA **39705**) → leave fields on **Use inherited setting** → Save (or skip save if already inherited) → open Preview.  
**What you should see:** Preview Ready; Currently using Default where inherited.  
**Customer impact:** Standard Delivery options.  
**Order impact:** Orders store the selected offer details when placed.  
**Common mistake:** Creating unnecessary product overrides.  
**Verify:** Storefront shows expected options.  
**Screenshots:** `02-default-settings.png`, `03b-product-39705-editor.png`

---

## USE CASE 2 — Give one product different delivery settings

**Goal:** Override only what must differ.  
**When:** One product needs a different path/offers.  
**Starting point:** Product-Specific Settings for that product.  
**Steps:** Open product → **Set a different value here** on required fields → adjust Delivery offers mode if needed → Save → Preview.  
**What you should see:** Currently using Product Settings for overridden fields.  
**Customer impact:** That product’s options differ.  
**Order impact:** Future orders only.  
**Common mistake:** Changing Default instead of one product (or the reverse).  
**Verify:** Compare storefront with a default product.  
**Screenshots:** `03-product-specific-settings.png`

---

## USE CASE 3 — Create an In Warehouse delivery product

**Goal:** Product fulfilled In warehouse with Delivery.  
**When:** Warehouse path products.  
**Starting point:** Default or Product-Specific Settings.  
**Steps:** Set Fulfilment availability **In warehouse** → Fulfilment choice **Delivery** → ensure warehouse-compatible offers → Save → Preview.  
**What you should see:** Ready; offers listed.  
**Customer impact:** Warehouse delivery choices.  
**Order impact:** Fulfilment shows In warehouse.  
**Common mistake:** Leaving offers empty.  
**Verify:** Product page options + Preview.  
**Screenshots:** `02-default-settings.png`

---

## USE CASE 4 — Create an In Store product with Delivery

**Goal:** In store availability with Delivery choice.  
**When:** Store-stocked items that still deliver.  
**Steps:** Fulfilment availability **In store** → choice **Delivery** → offers → Save → Preview.  
**What you should see:** Ready.  
**Customer impact:** In-store fulfilment labelling with delivery options.  
**Common mistake:** Confusing In store availability with Store pickup choice.  
**Verify:** Labels on product/cart.  
**Screenshots:** `06-simple-product-customer-view.png`

---

## USE CASE 5 — Configure Store Pickup where supported

**Goal:** Offer Store pickup with pickup locations.  
**When:** Customer collection is allowed.  
**Steps:** Set Fulfilment choice **Store pickup** where appropriate → ensure pickup locations exist → assign compatible offers → Save → Preview → storefront check.  
**What you should see:** Pickup-oriented wording (e.g. Ready for pickup) where applicable.  
**Customer impact:** Pickup choice available.  
**Common mistake:** Enabling pickup without locations/instructions.  
**Verify:** Product options + location content.  
**Screenshots:** (admin pickup page; customer selector when available)

---

## USE CASE 6 — Configure International product (supported Air/Sea choices)

**Goal:** International fulfilment with supported offers only.  
**When:** International path products.  
**Steps:** Set **International fulfilment** → Delivery → only offers your store supports for that path → ensure zones/rate cards cover destinations → Save → Preview.  
**What you should see:** Ready; no unsupported combinations.  
**Customer impact:** International delivery choices.  
**Common mistake:** Assigning domestic-only offers.  
**Verify:** Preview + destination/rate coverage.  
**Screenshots:** `02-default-settings.png`

---

## USE CASE 7 — Variable product where all variations inherit parent

**Goal:** One parent configuration for all variations.  
**When:** Variations share the same delivery path.  
**Starting point:** Product-Specific Settings for **#39717**; variations left inherited.  
**Steps:** Configure parent → open **#39718** / **#39719** and confirm **Use inherited setting** → Preview each.  
**What you should see:** Variations Currently using product settings.  
**Customer impact:** Same family of options after selecting any variation (subject to hard rules).  
**Common mistake:** Duplicating the same override on every variation.  
**Verify:** Storefront A and B.  
**Screenshots:** `04-variation-specific-settings.png`, `06b-variable-product-customer-view.png`

---

## USE CASE 8 — Give one variation different settings

**Goal:** Variation-level override.  
**When:** Only one option needs a different path/offers.  
**Steps:** Variation-Specific Settings → parent **39717** + variation **39719** (example) → set different values → Save → Preview.  
**What you should see:** That variation differs; sibling may still inherit.  
**Customer impact:** Options change when that variation is selected.  
**Common mistake:** Editing parent when only one variation should change.  
**Verify:** Switch variations on storefront.  
**Screenshots:** `04b-variation-39718-editor.png`

---

## USE CASE 9 — Disable an inherited option at a lower level

**Goal:** Turn off or remove an inherited value/offer at product or variation.  
**When:** Lower level must not offer something from above.  
**Steps:** Open lower level → **Turn off** (scalars) or Remove / Use only these options (offers) → Save → Preview.  
**What you should see:** Effective list excludes the disabled/removed item.  
**Customer impact:** Option no longer appears.  
**Common mistake:** Empty replace list accidentally removing all offers.  
**Verify:** Storefront options.  
**Screenshots:** `03-product-specific-settings.png`

---

## USE CASE 10 — Preview what configuration is actually being used

**Goal:** Confirm effective settings.  
**Steps:** Delivery Settings Preview → enter IDs → read Ready / Needs configuration / Currently using.  
**What you should see:** Clear provenance language.  
**Customer impact:** Confidence before go-live of a change.  
**Common mistake:** Skipping Preview after Save.  
**Verify:** Matches storefront.  
**Screenshots:** `05-delivery-preview-ready.png`

---

## USE CASE 11 — Customer selects delivery on a simple product

**Goal:** Validate customer path for #39705.  
**Steps:** Open storefront QA simple product → choose Delivery option → Add to cart (optional) → stop before payment.  
**What you should see:** Delivery options group; selection retained in cart.  
**Customer impact:** Can buy with chosen delivery.  
**Order impact:** If an order is placed (avoid for training), Delivery information saved.  
**Common mistake:** Placing repeated paid QA orders for screenshots.  
**Verify:** Cart shows fulfilment/option labels.  
**Screenshots:** `06-simple-product-customer-view.png`, `07-cart-delivery-information.png`

---

## USE CASE 12 — Customer selects a variation and delivery

**Goal:** Variable purchase path.  
**Steps:** Open #39717 → select Variation A → wait for Delivery options → select offer.  
**What you should see:** Options load after variation selection.  
**Common mistake:** Expecting offers before options are chosen.  
**Verify:** Selector shows offers for A.  
**Screenshots:** `06b-variable-product-customer-view.png`

---

## USE CASE 13 — Customer changes Variation A → Variation B

**Goal:** Confirm isolation/refresh.  
**Steps:** Select A + delivery → switch to B → confirm options refresh; invalid old choice does not silently remain.  
**What you should see:** Updated Delivery options for B.  
**Common mistake:** Assuming cart keeps A’s offer for B.  
**Verify:** Repeat switch; checkout would reject invalid selections when validation is on.  
**Screenshots:** `06b-variable-product-customer-view.png`

---

## USE CASE 14 — Two compatible products share one delivery charge

**Goal:** Understand fixed-per-shipment grouping.  
**When:** Compatible fulfilment paths in one cart.  
**Steps:** Prefer documented QA behaviour (order **#39724** showed shipping **25.00** not 50.00) rather than creating new orders.  
**What you should see:** One Delivery charge for compatible lines when configured that way.  
**Common mistake:** Expecting per-line doubling for fixed-per-shipment.  
**Verify:** Cart/checkout shipping total.  
**Screenshots:** `07-cart-delivery-information.png`, `08-checkout-delivery-charge.png`

---

## USE CASE 15 — Two incompatible fulfilment paths are separated

**Goal:** Incompatible paths do not wrongly share one charge.  
**Steps:** Observe/admin-explain with configured incompatible examples; avoid destructive live edits.  
**What you should see:** Separated shipping treatment.  
**Common mistake:** Forcing incompatible items into one offer.  
**Verify:** Checkout shipping structure.  
**Screenshots:** `08-checkout-delivery-charge.png`

---

## USE CASE 16 — Quantity 2 with fixed-per-shipment delivery

**Goal:** Qty increases product total, not necessarily delivery fee.  
**Steps:** Use documented QA expectation (qty 2 still **25.00** delivery on verified path) or carefully test on QA without placing payment.  
**What you should see:** Delivery fee once for that shipment type.  
**Common mistake:** Expecting delivery to always multiply by quantity.  
**Verify:** Checkout totals.  
**Screenshots:** `08-checkout-delivery-charge.png`

---

## USE CASE 17 — Process a WooCommerce order and read Delivery information

**Goal:** Staff can fulfil from the order panel.  
**Steps:** WooCommerce → Orders → open existing QA order **#39721** or **#39724** → find **Delivery information**.  
**What you should see:** Fulfilment, method/option, estimate, charge.  
**Order impact:** Read-only for training.  
**Common mistake:** Editing historical delivery to match new Defaults.  
**Verify:** Panel readable without technical meta.  
**Screenshots:** `09-order-delivery-information.png`

---

## USE CASE 18 — Change future delivery settings without changing historical orders

**Goal:** Understand immutability of purchased delivery details.  
**Steps:** Note an old order’s Delivery information → change a future Default (on QA only, then restore) → reopen old order.  
**What you should see:** Old order unchanged.  
**Common mistake:** Trying to “fix” history.  
**Verify:** Side-by-side old order vs Preview for future product.  
**Screenshots:** `09-order-delivery-information.png`, `02-default-settings.png`

---

## USE CASE 19 — Troubleshoot “Needs configuration”

**Goal:** Safe first response.  
**Steps:** Follow [07-TROUBLESHOOTING-FAQ](07-TROUBLESHOOTING-FAQ.md) — Delivery Settings → Preview → Defaults/offers → escalate if needed.  
**What you should see:** Either Ready after fix or clear escalation notes.  
**Common mistake:** Editing Legacy Rules or asking for SQL.  
**Verify:** Preview Ready + storefront options.  
**Screenshots:** `05-delivery-preview-ready.png`

---

## USE CASE 20 — Understand Legacy Delivery Rules without using them as normal

**Goal:** Recognise and avoid everyday use.  
**Steps:** Open Legacy Delivery Rules → read any pointer to Delivery Settings → leave without saving.  
**What you should see:** Legacy labelling; path back to Delivery Settings.  
**Common mistake:** Training others to use Legacy as default.  
**Verify:** Trainee states everyday system correctly.  
**Screenshots:** `10-legacy-delivery-rules.png`
