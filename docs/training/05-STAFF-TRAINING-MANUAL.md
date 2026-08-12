# Staff Training Manual (self-paced)

**Audience:** New and returning staff  
**Version:** CETECH Delivery Engine 1.0.0-rc.2  
**Everyday system:** Delivery Settings  
**Practice products:** Simple QA **#39705**; Variable QA **#39717** / A **#39718** / B **#39719**

How every module works:

1. What you are learning  
2. Why it matters  
3. Watch the walkthrough  
4. Do it yourself  
5. Check your result  
6. Common mistakes  
7. Short quiz  
8. Practical test  

Complete modules in order unless a trainer assigns a different path.

---

# MODULE 1 — Understanding the Delivery Engine

## 1. What you are learning
What the Delivery Engine does for the store, customers, and staff — without technical jargon.

## 2. Why it matters
Clear delivery choices and correct fees reduce checkout confusion and support tickets. Staff need a shared picture of the journey: configure → customer selects → cart/checkout → order Delivery information.

## 3. Watch the walkthrough
Watch [Video 01 — Getting started](10-VIDEO-TRAINING-LIBRARY.md#01--getting-started-overview) (or the matching portion of [Video 12](10-VIDEO-TRAINING-LIBRARY.md#12--complete-staff-walkthrough)).  
Read [04-VISUAL-WALKTHROUGH](04-VISUAL-WALKTHROUGH.md) sections 1–3 (admin home → Delivery Settings overview). Screenshot refs: `00-delivery-engine-dashboard.png`, `01-delivery-settings-home.png`.

## 4. Do it yourself
1. Log into WordPress admin.  
2. Open **Delivery Engine**.  
3. Open **Delivery Settings** and note the three tabs: Default / Product-Specific / Variation-Specific.  
4. Open **Delivery Settings Preview** (read-only).  
5. Do **not** open Legacy Delivery Rules for practice editing.

## 5. Check your result
You can name the everyday menu (**Delivery Settings**) and explain that customers choose delivery on the product page and staff later read **Delivery information** on the order.

## 6. Common mistakes
- Treating **Settings** (feature switches) as the same page as **Delivery Settings**.  
- Using Legacy Delivery Rules as the normal editor.

## 7. Short quiz
1. Where do staff normally manage delivery values?  
2. What does a customer do on the product page?  
3. Where do staff find delivery details after an order?  
4. Should normal staff edit Legacy Delivery Rules day to day?  
5. Name the three Delivery Settings levels.

**Answers:** (1) Delivery Settings (2) Choose a delivery option (3) Order → Delivery information (4) No (5) Default, Product-Specific, Variation-Specific.

## 8. Practical test
Show a trainer (or write down) the click path from wp-admin to Delivery Settings and to Preview in under two minutes.

---

# MODULE 2 — Finding your way around Delivery Settings

## 1. What you are learning
The Delivery Engine menus you will actually use, and which ones to leave alone.

## 2. Why it matters
Wrong menu = wrong changes. Offers, zones, and rate cards work together; Delivery Settings applies them to products.

## 3. Watch the walkthrough
Watch [Video 01](10-VIDEO-TRAINING-LIBRARY.md#01--getting-started-overview) (menus portion).  
[04-VISUAL-WALKTHROUGH](04-VISUAL-WALKTHROUGH.md) Delivery Settings home. Also skim administrator guide pages for Offers, Zones, Rate Cards in [02-COMPLETE-ADMIN-GUIDE](02-COMPLETE-ADMIN-GUIDE.md).

## 4. Do it yourself
Open each everyday page once (read-only): Dashboard, Delivery Settings, Delivery Offers, Destination Zones, Rate Cards, Delivery Settings Preview. Glance at Logistics Profiles / Pickup Locations if your store uses them. Do not change Settings switches.

## 5. Check your result
You can say which page edits product inheritance vs which page lists customer-facing offers vs which page sets fees.

## 6. Common mistakes
Editing Suppliers & Origins casually; changing Settings flags; confusing Destination Zones with WooCommerce shipping zones without checking both.

## 7. Short quiz
1. Which page sets Default → Product → Variation values?  
2. Which page defines customer-facing offer names?  
3. Which page ties a fee to a zone + offer?  
4. Which page is read-only for “what will apply”?  
5. Name one page normal staff should not change without authorisation.

**Answers:** (1) Delivery Settings (2) Delivery Offers (3) Rate Cards (4) Preview (5) Settings switches / Legacy Rules / Suppliers & Origins (any).

## 8. Practical test
Given a screenshot or live screen, correctly identify whether you are on Delivery Settings, Offers, or Rate Cards.

---

# MODULE 3 — Understanding Default → Product → Variation

## 1. What you are learning
Inheritance: when to leave a value inherited, when to set a different value, when to turn something off.

## 2. Why it matters
Most products should follow Defaults. Overrides should be intentional. Wrong-level edits create confusing behaviour.

## 3. Watch the walkthrough
Watch [Video 05 — Inheritance / overrides](10-VIDEO-TRAINING-LIBRARY.md#05--variation-inheritance-and-overrides) and [Video 02 — Defaults](10-VIDEO-TRAINING-LIBRARY.md#02--default-delivery-settings).  
[04-VISUAL-WALKTHROUGH](04-VISUAL-WALKTHROUGH.md) Default / Product / Variation screens. Screenshots: `02-default-settings.png`, `03-product-specific-settings.png`, `04-variation-specific-settings.png`.

## 4. Do it yourself
On QA **#39705**, open Product-Specific Settings and read each field’s mode (do not save changes unless a trainer asks). On **#39718**, open Variation-Specific Settings and note which fields say **Use inherited setting**.

## 5. Check your result
You can explain: product without overrides uses Default; variation without overrides uses product.

## 6. Common mistakes
Setting the same override on every product instead of fixing Default; editing variation when all variations should change at parent; confusing empty delivery offers with “inherit”.

## 7. Short quiz
1. What happens if a product field is “Use inherited setting”?  
2. What label means replace the inherited scalar?  
3. What label means intentionally none (where supported)?  
4. Which level wins if Default, Product, and Variation all set a value?  
5. True/False: empty delivery-offer list always means inherit.

**Answers:** (1) Uses Default (2) Set a different value here (3) Turn off (4) Variation (5) False — empty can mean no options when explicitly configured that way.

## 8. Practical test
Explain to a trainer, with Preview open, which level a QA product is Currently using for fulfilment.

---

# MODULE 4 — Configuring a simple product

## 1. What you are learning
How to configure a normal simple product using inheritance or a deliberate product override.

## 2. Why it matters
Simple products are the most common staff task.

## 3. Watch the walkthrough
Watch [Video 03 — Configure a simple product](10-VIDEO-TRAINING-LIBRARY.md#03--configure-a-simple-product).  
Use cases 1–2 in [03-USE-CASE-PLAYBOOK](03-USE-CASE-PLAYBOOK.md). Screenshot `03b-product-39705-editor.png` if present.

## 4. Do it yourself
Practice on QA **#39705** only. Prefer leaving values inherited. If your trainer authorises a temporary override, set it, Preview, then restore to the prior state.

## 5. Check your result
Preview for #39705 shows Ready (or a clear Needs configuration you can explain). Storefront product shows Delivery options.

## 6. Common mistakes
Editing a live catalogue product instead of QA; saving incomplete offer lists; forgetting Save.

## 7. Short quiz
1. Which tab for product #39705?  
2. What ID do you enter?  
3. When should you leave “Use inherited setting”?  
4. What do you open after Save to verify?  
5. What customer UI should appear?

**Answers:** (1) Product-Specific Settings (2) 39705 (3) When Default is correct (4) Preview (5) Delivery options.

## 8. Practical test
Configure (or correctly leave inherited) #39705 and show Preview Ready without using Legacy Rules.

---

# MODULE 5 — Configuring variable products

## 1. What you are learning
Parent product settings vs variation overrides; how customers switching variations changes delivery choices.

## 2. Why it matters
Variable products fail in support when staff edit the wrong ID or expect parent changes to appear without selecting a variation on the shop.

## 3. Watch the walkthrough
Watch [Video 04 — Configure a variable product](10-VIDEO-TRAINING-LIBRARY.md#04--configure-a-variable-product).  
Use cases 7–8 and 12–13 in the playbook. Screenshots `04-variation-specific-settings.png`, `06b-variable-product-customer-view.png`.

## 4. Do it yourself
Open parent **#39717** product settings and variations **#39718** / **#39719**. Compare inheritance. On the storefront, select A then B and watch Delivery options update. Do not create orders.

## 5. Check your result
You can state which variation inherits vs differs (as currently configured) and show the customer selector updating.

## 6. Common mistakes
Entering variation ID without parent ID; testing the parent page without selecting a variation; assuming cart keeps an invalid old choice after a variation change.

## 7. Short quiz
1. Parent QA ID?  
2. Variation A / B IDs?  
3. If variation uses inherited setting, whose value applies?  
4. What must a customer do before delivery choices appear on many variable products?  
5. Should checkout keep Variation A’s offer after switching to incompatible Variation B?

**Answers:** (1) 39717 (2) 39718 / 39719 (3) Parent product (4) Select product options/variation (5) No.

## 8. Practical test
Demonstrate Variation A → Variation B delivery refresh on the QA variable product for a trainer.

---

# MODULE 6 — Understanding fulfilment and delivery options

## 1. What you are learning
Fulfilment availability (In store / In warehouse / International fulfilment), fulfilment choice (Delivery / Store pickup), and Delivery offers customers select.

## 2. Why it matters
These fields decide which paths are allowed. Wrong combinations show as Needs configuration or missing options.

## 3. Watch the walkthrough
Watch [Video 02](10-VIDEO-TRAINING-LIBRARY.md#02--default-delivery-settings) and [Video 05](10-VIDEO-TRAINING-LIBRARY.md#05--variation-inheritance-and-overrides) (fulfilment portion).  
Administrator guide field explanations + playbook use cases 3–6. Screenshot Default Settings fields.

## 4. Do it yourself
On Default Settings (read-only unless authorised), locate **Fulfilment availability**, **Fulfilment choice**, and **Delivery offers**. Match labels to [08-GLOSSARY](08-GLOSSARY.md).

## 5. Check your result
You can explain each label in business language and give one example product path (for example In warehouse + Delivery).

## 6. Common mistakes
Mixing up fulfilment availability with delivery offer name; enabling International paths without supported offers; turning off required fields accidentally.

## 7. Short quiz
1. Name three fulfilment availability options shown in UI.  
2. Name two fulfilment choices.  
3. What is a Delivery Offer?  
4. Who sees the offer’s customer-facing name?  
5. Where do staff assign offers to a product path?

**Answers:** (1) In store, In warehouse, International fulfilment (2) Delivery, Store pickup (3) Customer delivery choice (4) Customers (5) Delivery Settings (offers field) / related offer list.

## 8. Practical test
Point to each field on Default Settings and explain it aloud in one sentence each.

---

# MODULE 7 — Delivery charges and rate cards

## 1. What you are learning
How Destination Zones and Rate Cards produce the checkout Delivery fee.

## 2. Why it matters
Customers pay the fee shown at checkout. Missing rate cards must not become silent free shipping.

## 3. Watch the walkthrough
Watch [Video 07](10-VIDEO-TRAINING-LIBRARY.md#07--customer-product-cart-and-checkout) (charge portion) and [Video 08](10-VIDEO-TRAINING-LIBRARY.md#08--multi-product-shipping).  
Admin guide Rate Cards + Zones sections. Playbook use cases 14 and 16.

## 4. Do it yourself
Open Destination Zones and Rate Cards read-only. Find how a zone and an offer pair to a fee. Do not edit production rates without authorisation.

## 5. Check your result
You can describe: customer address → zone match → rate card for selected offer → Delivery shipping line.

## 6. Common mistakes
Changing fees on the order instead of rate cards; assuming quantity always multiplies fixed-per-shipment fees; leaving zone coverage incomplete.

## 7. Short quiz
1. What two things does a typical rate card connect?  
2. Where does the customer see the fee?  
3. Should missing config become $0 shipping?  
4. What shipping method title do customers often see?  
5. Who should approve rate changes?

**Answers:** (1) Zone + offer (and related matching) (2) Checkout shipping (3) No (4) Delivery (5) Administrator / authorised staff.

## 8. Practical test
Explain why two compatible items might share one 25.00 charge in a fixed-per-shipment setup without inventing technical IDs.

---

# MODULE 8 — What customers experience

## 1. What you are learning
Product page Delivery options, wording customers see, and what “good” looks like.

## 2. Why it matters
Staff configuration is successful only if the customer journey is clear.

## 3. Watch the walkthrough
Watch [Video 07 — Customer journey](10-VIDEO-TRAINING-LIBRARY.md#07--customer-product-cart-and-checkout).  
Visual walkthrough product → cart sections. Screenshots `06-simple-product-customer-view.png`, `06b-variable-product-customer-view.png`.

## 4. Do it yourself
Open QA simple product storefront. Confirm **Delivery options**. Open variable QA, select options, confirm choices appear. Do not complete payment.

## 5. Check your result
You can describe the customer steps: open product → (select variation) → choose delivery → add to cart.

## 6. Common mistakes
Judging variable products before a variation is selected; using personal customer accounts; placing real paid orders for training.

## 7. Short quiz
1. What heading do customers see for choices?  
2. Must delivery be chosen before add to cart when enabled?  
3. Where do variable delivery choices often appear only after?  
4. Should staff create customer accounts for this module?  
5. Name one label customers may see for timing.

**Answers:** (1) Delivery options (2) Yes when the store requires selection (3) Selecting product options (4) No (5) Estimated delivery or Ready for pickup.

## 8. Practical test
Show a trainer the QA simple product delivery selector live.

---

# MODULE 9 — Cart and multi-product delivery

## 1. What you are learning
How delivery selections appear in cart, and how multiple products can share or separate delivery charges.

## 2. Why it matters
Multi-item carts are a common source of “wrong shipping” tickets.

## 3. Watch the walkthrough
Watch [Video 07](10-VIDEO-TRAINING-LIBRARY.md#07--customer-product-cart-and-checkout) and [Video 08 — Multi-product shipping](10-VIDEO-TRAINING-LIBRARY.md#08--multi-product-shipping).  
Playbook use cases 14–16. Screenshot `07-cart-delivery-information.png` when available.

## 4. Do it yourself
If authorised, add QA products to cart only, review fulfilment / delivery option lines, then empty the cart. Do not checkout to payment. Prefer existing documented behaviour if live cart capture is restricted.

## 5. Check your result
You can explain compatible shared charge vs incompatible separated paths in plain language.

## 6. Common mistakes
Creating many QA orders; enabling COD; changing payment methods for screenshots.

## 7. Short quiz
1. Does cart remember the delivery choice when enabled?  
2. Can two compatible items share one fixed-per-shipment fee?  
3. What should happen for incompatible fulfilment paths?  
4. Should training enable COD?  
5. Where is fee finally charged?

**Answers:** (1) Yes (2) Yes (3) Separated (4) No (5) Checkout shipping / order totals.

## 8. Practical test
Describe use cases 14 and 15 to a trainer without using developer terms.

---

# MODULE 10 — Processing orders

## 1. What you are learning
How to read **Delivery information** on a WooCommerce order and what must not be changed casually.

## 2. Why it matters
Fulfilment teams rely on the order snapshot. Historical orders stay as purchased.

## 3. Watch the walkthrough
Watch [Video 09 — Order Delivery information](10-VIDEO-TRAINING-LIBRARY.md#09--order-delivery-information).  
Visual walkthrough order section. Screenshot `09-order-delivery-information.png`. Playbook use cases 17–18.

## 4. Do it yourself
Open an existing QA order if available (**#39721** or **#39724**) read-only. Locate Delivery information: fulfilment, method/option, estimate, charge. Do not edit protected details.

## 5. Check your result
You can find and explain each Delivery information row on a sample order.

## 6. Common mistakes
Trying to “fix” old orders after changing Defaults; ignoring missing panels instead of escalating; exposing customer personal data in training screenshots.

## 7. Short quiz
1. What is the meta box called?  
2. Do past orders automatically change when Defaults change?  
3. Name two fields you should be able to read.  
4. Should you blur customer email in training screenshots?  
5. Who authorises unusual order edits?

**Answers:** (1) Delivery information (2) No (3) Fulfilment / Delivery option / charge / estimate (any two) (4) Yes (5) Administrator / policy owner.

## 8. Practical test
On a sample order, narrate the Delivery information panel to a trainer in under one minute.

---

# MODULE 11 — Common mistakes and troubleshooting

## 1. What you are learning
How to respond to Needs configuration and other everyday failures using the staff FAQ.

## 2. Why it matters
Fast, safe first checks reduce downtime without risky technical steps.

## 3. Watch the walkthrough
Watch [Video 10 — Staff troubleshooting](10-VIDEO-TRAINING-LIBRARY.md#10--staff-troubleshooting).  
Read [07-TROUBLESHOOTING-FAQ](07-TROUBLESHOOTING-FAQ.md) end to end.

## 4. Do it yourself
Pick three FAQ topics. For each, write your first three checks. Practice finding Preview for #39705.

## 5. Check your result
Your checks stay inside admin Delivery Settings / Preview / offers-zones-rates awareness — no PHP/SQL/SSH.

## 6. Common mistakes
Jumping to Legacy Rules; asking hosting to flush Redis as a first step; guessing $0 shipping.

## 7. Short quiz
1. First place to check for Needs configuration?  
2. Name one action that is forbidden for ordinary staff.  
3. What status word means incomplete setup?  
4. What status word means OK?  
5. When do you escalate?

**Answers:** (1) Delivery Settings + Preview (2) Edit PHP / SQL / etc. (3) Needs configuration (4) Ready (5) When safe fixes fail or authorisation boundaries are hit.

## 8. Practical test
Role-play: trainer says “no delivery options on product”. You list safe checks aloud.

---

# MODULE 12 — Legacy Rules and things normal staff should not touch

## 1. What you are learning
What Legacy Delivery Rules are for, why they exist, and why Delivery Settings is the normal system. Boundaries for Settings switches and private logistics data.

## 2. Why it matters
Editing the wrong system causes conflicting behaviour and hard-to-diagnose live issues.

## 3. Watch the walkthrough
Watch [Video 11 — Legacy Rules explained](10-VIDEO-TRAINING-LIBRARY.md#11--legacy-delivery-rules-explained).  
Screenshot `10-legacy-delivery-rules.png`. Read Legacy section in the administrator guide and Module boundaries in Start Here.

## 4. Do it yourself
Open Legacy Delivery Rules once, read any warning that points you to Delivery Settings, then leave without saving. Confirm you know who to ask before any Legacy edit.

## 5. Check your result
You can explain: everyday = Delivery Settings; Legacy = authorised/migration/support only.

## 6. Common mistakes
Training others to use Legacy as default; changing Advanced Settings flags; publishing supplier/origin details to customers.

## 7. Short quiz
1. Everyday editor name?  
2. Should Legacy be the normal workflow?  
3. Name one Settings change that needs an administrator.  
4. Are suppliers customer-facing?  
5. Where do you send technical deep detail?

**Answers:** (1) Delivery Settings (2) No (3) Feature switches / cutover (4) No — private (5) Technical Support Appendix / CETECH support.

## 8. Practical test
Pass/fail oral exam: explain the difference between Delivery Settings and Legacy Delivery Rules without mentioning databases or PHP.

---

## Course completion

You are trained when you can demonstrate Modules 4, 5, 10, and 12 practical tests plus inheritance explanation (Module 3). Reading alone is not enough — see [06-TRAINER-GUIDE](06-TRAINER-GUIDE.md).
