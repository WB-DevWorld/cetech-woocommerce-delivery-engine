# Staff Training Manual (self-paced)

**Audience:** New and returning staff  
**Version:** CETECH Delivery Engine 1.0.0-rc.6  
**Everyday home:** Delivery Engine → Overview  
**Practice products:** Simple QA **#39705**; Variable QA **#39717** / A **#39718** / B **#39719**

Screenshots and recaptured videos are deferred. For “Watch the walkthrough”, use the matching section of [04-VISUAL-WALKTHROUGH](04-VISUAL-WALKTHROUGH.md) on the live screens.

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
Clear delivery choices and correct fees reduce checkout confusion. Shared picture of the journey: Site-wide Defaults → customer selects a Delivery option → cart/checkout → order Delivery information.

## 3. Watch the walkthrough
Read [04-VISUAL-WALKTHROUGH](04-VISUAL-WALKTHROUGH.md) sections 1–2 (admin → Overview).

## 4. Do it yourself
1. Log into WordPress admin.  
2. Open **Delivery Engine → Overview**.  
3. Note Site-wide Defaults, Product Exceptions, and Needs Attention in the left menu.  
4. If **Shipments** is in the menu, note it as the place to work delivery jobs — do not change anything yet.

## 5. Check your result
You can name the everyday home (**Overview**) and explain that customers choose a Delivery option on the product page and staff later read **Delivery information** on the order.

## 6. Common mistakes
- Treating **Settings** as the same page as **Site-wide Defaults**.  

## 7. Short quiz
1. Where do staff land for daily delivery work?  
2. What does a customer choose on the product page?  
3. Where do staff find delivery details after an order?  
4. Should normal staff change Advanced Settings without an administrator?  
5. Name the three inheritance layers.

**Answers:** (1) Overview (2) A Delivery option (3) Order → Delivery information (4) No (5) Site-wide Defaults, Product exception, Variation exception.

## 8. Practical test
Show a trainer (or write down) the click path from wp-admin to Overview and to Site-wide Defaults in under two minutes.

---

# MODULE 2 — Finding your way around the Delivery Engine menu

## 1. What you are learning
The menus you will actually use, and which ones are gone from everyday work.

## 2. Why it matters
Wrong menu = wrong changes. Options, Areas, and Charges work together; Site-wide Defaults and Product Exceptions apply them to products.

## 3. Watch the walkthrough
[04-VISUAL-WALKTHROUGH](04-VISUAL-WALKTHROUGH.md) sections 2–10. Skim [02-COMPLETE-ADMIN-GUIDE](02-COMPLETE-ADMIN-GUIDE.md).

## 4. Do it yourself
Open each everyday page once (read-only): Overview, Site-wide Defaults, Delivery Options, Delivery Areas, Delivery Charges, Pickup Locations, Product Exceptions, Needs Attention. If **Shipments** is in the menu, open the list read-only. Do not change Settings switches.

## 5. Check your result
You can say which page sets store defaults, which lists customer-facing option names, and which sets fees.

## 6. Common mistakes
Looking for a different menu than the everyday list in Start Here; changing Settings flags; confusing Delivery Areas with WooCommerce shipping zones without checking both.

## 7. Short quiz
1. Which page sets the store-standard rules products inherit?  
2. Which page defines customer-facing option names?  
3. Which page ties a fee to an area + option?  
4. Which page lists products that differ from defaults?  
5. Name one page normal staff should not change without authorisation.

**Answers:** (1) Site-wide Defaults (2) Delivery Options (3) Delivery Charges (4) Product Exceptions (5) Settings / Access / Advanced switches.

## 8. Practical test
Given a live screen, correctly identify whether you are on Site-wide Defaults, Delivery Options, or Delivery Charges.

---

# MODULE 3 — Understanding Site-wide → Product → Variation

## 1. What you are learning
Inheritance: when to leave a value inherited, when to set a different value, and that inheritance is field-by-field.

## 2. Why it matters
Most products should follow Site-wide Defaults. Overrides should be intentional. Changing Estimated delivery must not freeze Delivery Options.

## 3. Watch the walkthrough
[04-VISUAL-WALKTHROUGH](04-VISUAL-WALKTHROUGH.md) sections 3, 11–13.

## 4. Do it yourself
On QA **#39705**, open the **Delivery** tab and read **Currently using** (do not save unless a trainer asks). On **#39718**, open the variation Delivery summary and note whether it follows Product Settings.

## 5. Check your result
You can explain: product without exceptions uses the Site-wide Default; variation without exceptions uses the product.

## 6. Common mistakes
Setting the same override on every product instead of fixing Site-wide Defaults; editing a variation when all variations should change at the parent; customizing every field when only one should differ.

## 7. Short quiz
1. What happens if a product has no special delivery settings?  
2. What label means a variation keeps the parent value?  
3. If only Estimated delivery is customized, do Delivery Options still inherit?  
4. Which level wins if Site-wide, Product, and Variation all set a value?  
5. True/False: you must re-apply Site-wide Defaults after every later default edit.

**Answers:** (1) It uses the Site-wide Default (2) Use Product Setting (3) Yes — field-by-field (4) Variation (5) False — inherited fields update immediately.

## 8. Practical test
Explain to a trainer, with Preview Delivery open, which level a QA product is currently using for fulfilment.

---

# MODULE 4 — Configuring a simple product

## 1. What you are learning
How to leave a normal simple product on Site-wide Defaults, or create a deliberate product exception.

## 2. Why it matters
Simple products are the most common staff task.

## 3. Watch the walkthrough
Use cases 1–2 in [03-USE-CASE-PLAYBOOK](03-USE-CASE-PLAYBOOK.md) and Visual Walkthrough section 11.

## 4. Do it yourself
Practice on QA **#39705** only. Prefer leaving values inherited. If your trainer authorises a temporary exception, set it, Preview, then **Reset to Site-wide Defaults**.

## 5. Check your result
Preview for #39705 shows Ready. Storefront shows a public Delivery option + Estimated delivery.

## 6. Common mistakes
Editing a live catalogue product instead of QA; customizing every field; forgetting Save.

## 7. Short quiz
1. Where is the everyday product delivery summary?  
2. What button starts an exception?  
3. When should you leave the product alone?  
4. What do you open after Save to verify?  
5. What two things should the customer see on the product page?

**Answers:** (1) WooCommerce product → Delivery tab (2) Customize This Product (3) When Site-wide Defaults are correct (4) Preview Delivery (5) Delivery option name + Estimated delivery.

## 8. Practical test
Show Preview Ready for #39705 using only the product **Delivery** tab and **Preview Delivery**.

---

# MODULE 5 — Configuring variable products

## 1. What you are learning
Parent product settings vs variation exceptions; how customers switching variations changes delivery choices.

## 2. Why it matters
Variable products fail in support when staff edit the wrong item or expect parent changes to appear without selecting a variation on the shop.

## 3. Watch the walkthrough
Use cases 7–8 and 12–13 in the playbook. Visual Walkthrough sections 12 and 14.

## 4. Do it yourself
Open parent **#39717** Delivery tab and variations **#39718** / **#39719**. Compare inheritance. On the storefront, select A then B and watch Delivery options update. Do not create orders.

## 5. Check your result
You can state which variation inherits vs differs (as currently configured) and show the customer selector updating.

## 6. Common mistakes
Testing the parent page without selecting a variation; assuming cart keeps an invalid old choice after a variation change.

## 7. Short quiz
1. Parent QA ID?  
2. Variation A / B IDs?  
3. If a variation uses Product Setting, whose value applies?  
4. What must a customer do before delivery choices appear on many variable products?  
5. Should checkout keep Variation A’s option after switching to incompatible Variation B?

**Answers:** (1) 39717 (2) 39718 / 39719 (3) Parent product (4) Select product options/variation (5) No.

## 8. Practical test
Demonstrate Variation A → Variation B delivery refresh on the QA variable product for a trainer.

---

# MODULE 6 — Understanding fulfilment and Delivery Options

## 1. What you are learning
Fulfilment types (In Warehouse / In Store / International), Delivery method (Delivery / Store pickup), and Delivery Options customers select. International = Air and/or Sea only.

## 2. Why it matters
These fields decide which paths are allowed. Wrong combinations show as Needs Attention or missing options. Customers do not see fulfilment headings on the compact product selector.

## 3. Watch the walkthrough
Visual Walkthrough sections 3–4. Playbook use cases 3–6. [08-GLOSSARY](08-GLOSSARY.md).

## 4. Do it yourself
On Site-wide Defaults (read-only unless authorised), locate each fulfilment type and its Delivery Options. Match labels to the glossary.

## 5. Check your result
You can explain each label in business language and give one example path (for example In Warehouse + local Delivery Option).

## 6. Common mistakes
Mixing up fulfilment with the public option name; putting local options on International; expecting customers to see “In Warehouse” on the product page.

## 7. Short quiz
1. Name three fulfilment types.  
2. Name two delivery methods.  
3. What is a Delivery Option?  
4. Who sees the option’s public name?  
5. Which shipping types are allowed for International?

**Answers:** (1) In Warehouse, In Store, International (2) Delivery, Store pickup (3) Customer delivery choice (4) Customers (and checkout shipping label) (5) Air and/or Sea only.

## 8. Practical test
Point to each fulfilment type on Site-wide Defaults and explain it aloud in one sentence each.

---

# MODULE 7 — Delivery Areas and Delivery Charges

## 1. What you are learning
How Delivery Areas and Delivery Charges produce the checkout shipping fee.

## 2. Why it matters
Customers pay the fee shown at checkout. Missing charges must not become silent free shipping.

## 3. Watch the walkthrough
Visual Walkthrough sections 5–6 and 16. Playbook use cases 14 and 16.

## 4. Do it yourself
Open Delivery Areas and Delivery Charges read-only. Find how an area and an option pair to a fee. If an area uses **State / Region**, notice that the checkout name and the short code (for example `Greater Accra` and `AA`) are the same place. Do not edit production rates without authorisation.

## 5. Check your result
You can describe: customer address → area match → charge for selected option → shipping line (public option label + amount).

## 6. Common mistakes
Changing fees on the order instead of Delivery Charges; assuming quantity always multiplies a shared delivery fee.

## 7. Short quiz
1. What two things does a typical Delivery Charge connect?  
2. Where does the customer see the fee?  
3. Should missing config become $0 shipping?  
4. What label may the shipping line show?  
5. Who should approve charge changes?

**Answers:** (1) Area + Delivery Option (2) Checkout shipping (3) No (4) The public Delivery Option name (fallback Delivery / Store pickup) (5) Administrator / authorised staff.

## 8. Practical test
Explain why two compatible items might share one 25.00 charge without using developer terms.

---

# MODULE 8 — What customers experience

## 1. What you are learning
The compact product selector, thank-you / My Account / email **Delivery details**, and what “good” looks like.

## 2. Why it matters
Staff configuration is successful only if the customer journey is clear and non-technical.

## 3. Watch the walkthrough
Visual Walkthrough sections 14–17.

## 4. Do it yourself
Open the QA simple product storefront. Confirm the public option name and **Estimated delivery**. Open the variable QA, select options, confirm choices appear. Do not complete payment.

## 5. Check your result
You can describe: open product → (select variation) → choose Delivery option → add to cart. You can name what customers must **not** see (fulfilment headings, generic delivery method, internal IDs).

## 6. Common mistakes
Judging variable products before a variation is selected; placing real paid orders for training.

## 7. Short quiz
1. What two lines should the compact product selector show?  
2. Must a variation be selected first on many variable products?  
3. Do customers see “In Warehouse” on the product page?  
4. Should staff create customer accounts for this module?  
5. What heading appears on thank-you delivery details?

**Answers:** (1) Delivery option name + Estimated delivery (2) Yes (3) No (4) No (5) Delivery details.

## 8. Practical test
Show a trainer the QA simple product delivery selector live and point out the two customer lines.

---

# MODULE 9 — Cart and multi-product delivery

## 1. What you are learning
How delivery selections appear in cart, and how multiple products can share or separate delivery charges.

## 2. Why it matters
Multi-item carts are a common source of “wrong shipping” tickets.

## 3. Watch the walkthrough
Playbook use cases 14–16. Visual Walkthrough sections 15–16.

## 4. Do it yourself
If authorised, add QA products to cart only, review the remembered option, then empty the cart. Do not checkout to payment. Prefer existing order **#39724** if live cart capture is restricted.

## 5. Check your result
You can explain compatible shared charge vs incompatible separated paths in plain language.

## 6. Common mistakes
Creating many QA orders; enabling COD; changing payment methods for training.

## 7. Short quiz
1. Does cart remember the delivery choice when enabled?  
2. Can two compatible items share one delivery fee?  
3. What should happen for incompatible fulfilment paths?  
4. Should training enable COD?  
5. Where is the fee finally charged?

**Answers:** (1) Yes (2) Yes (3) Separated (4) No (5) Checkout shipping / order totals.

## 8. Practical test
Describe use cases 14 and 15 to a trainer without using developer terms.

---

# MODULE 10 — Processing orders

## 1. What you are learning
How to read **Delivery information** on a WooCommerce order and what must not be changed casually.

## 2. Why it matters
Fulfilment teams rely on what was saved at purchase. Historical orders stay as purchased.

## 3. Watch the walkthrough
Visual Walkthrough section 18. Playbook use cases 17–18.

## 4. Do it yourself
Open an existing QA order if available (**#39721** or **#39724**) read-only. Locate Delivery information. Do not edit protected details.

## 5. Check your result
You can find and explain the Delivery information panel on a sample order, and contrast it with the compact customer **Delivery details**.

## 6. Common mistakes
Trying to “fix” old orders after changing Site-wide Defaults; exposing customer personal data in training notes.

## 7. Short quiz
1. What is the staff meta box called?  
2. Do past orders automatically change when defaults change?  
3. Name two fields you should be able to read.  
4. What do customers see on thank-you instead of staff fulfilment labels?  
5. Who authorises unusual order edits?

**Answers:** (1) Delivery information (2) No (3) Delivery option / estimate / charge / fulfilment (any two) (4) Compact Delivery details (option + estimate) (5) Administrator / policy owner.

## 8. Practical test
On a sample order, narrate the Delivery information panel to a trainer in under one minute.

---

# MODULE 11 — Common mistakes and troubleshooting

## 1. What you are learning
How to respond to Needs Attention and other everyday failures using the staff FAQ.

## 2. Why it matters
Fast, safe first checks reduce downtime without risky technical steps.

## 3. Watch the walkthrough
Read [07-TROUBLESHOOTING-FAQ](07-TROUBLESHOOTING-FAQ.md) end to end.

## 4. Do it yourself
Pick three FAQ topics. For each, write your first three checks. Practice opening Needs Attention and Preview for #39705.

## 5. Check your result
Your checks stay inside Overview / Needs Attention / product Delivery / Preview / Options-Areas-Charges — no PHP/SQL/SSH.

## 6. Common mistakes
Hunting for a screen that is not in the everyday menu; asking hosting to flush caches as a first step; guessing $0 shipping.

## 7. Short quiz
1. First place to look for incomplete products?  
2. Name one action that is forbidden for ordinary staff.  
3. What list name means incomplete setup?  
4. What Preview status word means OK?  
5. When do you escalate?

**Answers:** (1) Needs Attention (and the product Delivery tab) (2) Edit PHP / SQL / etc. (3) Needs Attention (4) Ready (5) When safe fixes fail or authorisation boundaries are hit.

## 8. Practical test
Role-play: trainer says “no delivery options on product”. You list safe checks aloud.

---

# MODULE 12 — Boundaries: what normal staff should not touch

## 1. What you are learning
Settings Advanced switches, Access, Technical Diagnostics, and private supply screens are administrator or support territory. Everyday work is Overview, Site-wide Defaults, Product Exceptions, and (when enabled) Shipments.

## 2. Why it matters
Editing the wrong screen can confuse checkout and is hard to undo.

## 3. Watch the walkthrough
Visual Walkthrough section 10. Start Here golden rules. Administrator guide Settings section.

## 4. Do it yourself
Confirm the left Delivery Engine menu matches the everyday list in [00-START-HERE](00-START-HERE.md). Do not open hidden support destinations. Confirm you know who to ask before any Settings / Access change.

## 5. Check your result
You can explain: everyday = Site-wide Defaults + Product Exceptions; Settings / Access need an administrator; Administrator access is protected.

## 6. Common mistakes
Changing Advanced Settings flags; publishing supplier/origin details to customers; rewriting old paid orders.

## 7. Short quiz
1. Everyday defaults page name?  
2. Should you use a screen that is not on the everyday menu?  
3. Name one Settings change that needs an administrator.  
4. Are suppliers customer-facing?  
5. Where do you send technical deep detail?

**Answers:** (1) Site-wide Defaults (2) No — ask an administrator (3) Feature switches / Access / Advanced (4) No — private (5) Technical Support Appendix / CETECH support.

## 8. Practical test
Pass/fail oral exam: explain Site-wide Defaults vs a Product Exception, without mentioning databases or PHP.

---

## Course completion

You are trained when you can demonstrate Modules 4, 5, 10, and 12 practical tests plus inheritance explanation (Module 3). Reading alone is not enough — see [06-TRAINER-GUIDE](06-TRAINER-GUIDE.md).

If your role includes fulfilling orders, continue with [11 — Stage 14 shipments](11-STAGE-14-SHIPMENTS.md) and playbook use cases 20–30 after an Administrator has enabled shipment records.
