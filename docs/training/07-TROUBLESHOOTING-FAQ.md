# Troubleshooting FAQ (staff)

**Audience:** Everyday staff and administrators  
**Version:** 1.0.0-rc.4  

Use this guide first. Do **not** edit PHP, run SQL, clear Redis globally, change the database by hand, install Code Snippets, change Nginx, or use SSH. Those steps belong only in the [Technical Support Appendix](09-TECHNICAL-SUPPORT-APPENDIX.md).

---

## Needs Attention (product listed, or Preview not Ready)

**What it means:** A required delivery value is missing or incomplete for this product or variation.

**Check first:**
1. Open **Delivery Engine → Needs Attention**.  
2. Open the product **Delivery** tab and **Preview Delivery**.  
3. Confirm Site-wide Defaults cover that fulfilment type (options, method, estimate).  
4. Confirm a Delivery Charge exists for the option + customer area.

**Safe fixes:**
- Complete Site-wide Defaults, or customize only the fields that must differ.  
- Prefer **Use Site-wide Default** / **Use Product Setting** when the level above is already correct.  
- Save, then re-check Preview. The product should leave Needs Attention when the setup is usable.

**Escalate when:** Preview still shows Needs Attention after defaults and the product Delivery tab look complete.

---

## No delivery option appears on the product page

**What it means:** The customer cannot choose delivery for that product/variation.

**Check first:**
- Correct product/variation selected?  
- Preview Delivery for that item?  
- Product published and visible?  
- For variable products: has a variation been selected?

**Safe fixes:**
- Complete setup until Preview is **Ready**.  
- Confirm Delivery Options are assigned (inherited or set here).  
- Ask an administrator to confirm Settings still show delivery choices on product pages.

**Escalate when:** Preview is Ready but the shop page still shows no options.

---

## Wrong delivery option appears

**What it means:** Options do not match what you expected for this product/variation.

**Check first:** Site-wide vs Product vs Variation; which layer Preview says is in use.

**Safe fixes:**
- Change the correct layer (do not fight inheritance by editing the wrong level).  
- Customize Delivery options only on the product/variation that must differ.  
- Save and Preview.

**Escalate when:** Options still wrong after the correct layer is updated. Remember International allows Air and/or Sea only.

---

## Delivery charge does not appear at checkout

**What it means:** WooCommerce is not showing the shipping fee for the customer’s choice.

**Check first:**
- Customer selected a Delivery option on the product?  
- Cart still shows that choice?  
- Destination matches a Delivery Area with a Delivery Charge for that option?

**Safe fixes:**
- Re-select delivery on the product and return to cart/checkout.  
- Ask an administrator to confirm Delivery Charges and that WooCommerce shipping still includes the Delivery Engine method.

**Escalate when:** Selection is present but the fee is missing or $0 unexpectedly. Missing configuration must never silently become free shipping.

---

## Product inherited a setting I did not expect

**What it means:** The product is using Site-wide Defaults because it has no different value of its own.

**Check first:** Product Delivery tab — does it say it has no special settings? Customize view — is the field **Use Site-wide Default**?

**Safe fixes:** Set a different value only for fields that must differ; otherwise update Site-wide Defaults intentionally (that affects all inheriting products).

---

## Variation inherited the parent (and that is surprising)

**What it means:** The variation was left on **Use Product Setting**.

**Check first:** Variation Delivery summary, parent product, Preview source.

**Safe fixes:** Customize This Variation for only the fields that must differ, or change the parent if all variations should change together.

---

## Variation should differ from parent

**Steps:** Open the variation → **Customize This Variation** → set a different value for only the fields that must differ → **Save Variation Delivery Settings** → Preview → check the product page after selecting that variation.

---

## Customer changed variation

**What it means:** Delivery choices refresh for the newly selected variation. The previous variation’s choice must not silently stay if it is invalid.

**Check first:** Select Variation A, note options; switch to Variation B; confirm options update.

**Escalate when:** Options do not refresh or an old invalid choice remains selectable at checkout.

---

## Multiple products show delivery / share a charge

**What it means:** Compatible items can share one delivery charge (fixed-per-shipment). Incompatible fulfilment paths are separated.

**Check first:** Cart shipping lines and each line’s delivery choice.

**Escalate when:** Two incompatible paths are charged as one, or two compatible paths are charged twice incorrectly.

---

## Delivery charge appears wrong

**Check first:** Selected option, Delivery Area, Delivery Charge amount, quantity, and whether items share one shipment charge. A live **250.00** (for example) can be a configured charge, not a quantity bug — confirm the Delivery Charge record.

**Safe fixes:** Confirm the charge for area + option with an administrator. Do not invent a manual $0 workaround.

---

## Customer sees fulfilment labels, a long description, or a duplicate shipping price

**What it means:** RC.4 customer surfaces should show only Delivery option + Estimated delivery (plus pickup extras when present).

**Check first:** You are looking at the live customer product / thank-you / email — not the staff order panel.

**Escalate when:** Customers still see fulfilment headings, generic “Delivery method: Delivery”, or a second shipping-price block after RC.4 is installed.

---

## Order Delivery information missing

**Check first:** Open the order → look for **Delivery information**. Confirm the order was placed after delivery saving was enabled.

**Escalate when:** Paid orders that should have delivery details show none. Do not invent details on the order.

---

## I cannot find Delivery Settings / Delivery Offers / Rate Cards / Legacy Delivery Rules

**What it means:** Those were RC.2 names or retired menus.

**What to do:**

| Old name | Use now |
|----------|---------|
| Delivery Settings / Default Settings | Site-wide Defaults |
| Product-Specific Settings | Product **Delivery** tab / Product Exceptions |
| Variation-Specific Settings | Variation Delivery summary → Customize This Variation |
| Delivery Offers | Delivery Options |
| Destination Zones | Delivery Areas |
| Rate Cards | Delivery Charges |
| Delivery Settings Preview | Preview Delivery (from the product tab or Overview) |
| Legacy Delivery Rules | Not an everyday menu — do not hunt for it |

---

## Escalation checklist (give support)

- Product / variation / order IDs  
- What the customer sees (option name + estimate, or the problem)  
- What you already tried  
- Exact wording of Ready / Needs Attention  
- Plugin version shown in WordPress (**1.0.0-rc.4** expected)
