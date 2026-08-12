# Troubleshooting FAQ (staff)

**Audience:** Everyday staff and administrators  
**Version:** 1.0.0-rc.2  

Use this guide first. Companion video: [Video 10 — Staff troubleshooting](10-VIDEO-TRAINING-LIBRARY.md#10--staff-troubleshooting). Do **not** edit PHP, run SQL, clear Redis globally, change the database by hand, install Code Snippets, change Nginx, or use SSH. Those steps belong only in the [Technical Support Appendix](09-TECHNICAL-SUPPORT-APPENDIX.md).

---

## “Needs configuration”

**What it means:** A required delivery value is missing or incomplete for this Default / Product / Variation view.

**Check first:**
1. Open **Delivery Settings** for the same product/variation.
2. Open **Delivery Settings Preview**.
3. Confirm Default Settings cover fulfilment, delivery choice, and delivery offers.

**Safe fixes:**
- Set missing Default values, or set product/variation values intentionally.
- Prefer **Use inherited setting** when the parent level is already correct.
- Save, then re-check Preview.

**Escalate when:** Preview still shows Needs configuration / Configuration problem after Defaults and product settings look complete, or Legacy warnings appear that you do not understand.

---

## No delivery option appears on the product page

**What it means:** The customer cannot choose delivery for that product/variation.

**Check first:**
- Correct product/variation selected?
- Delivery Settings Preview for that ID?
- Product published and visible?
- For variable products: has a variation been selected?

**Safe fixes:**
- Complete Delivery Settings until Preview is **Ready**.
- Confirm delivery offers are assigned (inherited or set here).
- Ask an administrator to confirm Delivery Engine → Settings still has product-page choices enabled.

**Escalate when:** Preview is Ready but the shop page still shows no options.

---

## Wrong delivery option appears

**What it means:** Offers do not match what you expected for this product/variation.

**Check first:** Default vs Product vs Variation layers; which level Preview says is **Currently using**.

**Safe fixes:**
- Change the correct layer (do not fight inheritance by editing the wrong level).
- Use **Remove from inherited options** / **Use only these options** carefully on Delivery offers.
- Save and Preview.

**Escalate when:** Offers still wrong after the correct layer is updated.

---

## Delivery charge does not appear at checkout

**What it means:** WooCommerce is not showing the Delivery shipping fee for the customer’s choice.

**Check first:**
- Customer selected a delivery option on the product?
- Cart still shows that delivery choice?
- Destination address matches a destination zone with a rate card for that offer?

**Safe fixes:**
- Re-select delivery on the product and return to cart/checkout.
- Ask an administrator to confirm rate cards and WooCommerce shipping zones include the **Delivery** method.

**Escalate when:** Selection is present but fee is missing or $0 unexpectedly. Missing configuration must never silently become free shipping.

---

## Product inherited a setting I did not expect

**What it means:** The product is using Default Settings (or another upper level) because it has no different value of its own.

**Check first:** Product-Specific Settings mode for that field — is it **Use inherited setting**?

**Safe fixes:** Switch to **Set a different value here** only for fields that must differ; otherwise update the Default intentionally.

---

## Variation inherited the parent (and that is surprising)

**What it means:** Variation-Specific Settings were left as inherited.

**Check first:** Variation ID, parent ID, Preview provenance (“Currently using”).

**Safe fixes:** Set a different value on the variation, or change the parent if all variations should change together.

---

## Variation should differ from parent

**Steps:** Variation-Specific Settings → enter parent + variation IDs → **Set a different value here** for only the fields that must differ → Save → Preview → check the product page after selecting that variation.

---

## Customer changed variation

**What it means:** Delivery choices refresh for the newly selected variation. The previous variation’s choice must not silently stay if it is invalid.

**Check first:** Select Variation A, note options; switch to Variation B; confirm options update.

**Escalate when:** Options do not refresh or an old invalid choice remains selectable at checkout.

---

## Multiple products show delivery / share a charge

**What it means:** Compatible items can share one delivery charge (fixed-per-shipment). Incompatible fulfilment paths are separated.

**Check first:** Cart shipping lines and each line’s delivery summary.

**Escalate when:** Two incompatible paths are charged as one, or two compatible paths are charged twice incorrectly.

---

## Delivery charge appears wrong

**Check first:** Selected offer, destination zone, rate card amount, quantity, and whether items share one shipment charge.

**Safe fixes:** Confirm rate card for zone + offer with an administrator. Do not invent a manual $0 workaround.

---

## Order Delivery information missing

**Check first:** Open the order → look for **Delivery information**. Confirm the order was placed after delivery saving was enabled.

**Escalate when:** Paid orders that should have delivery details show none. Do not invent details on the order.

---

## Legacy Delivery Rules confusion

**What it means:** You opened the older rules screens.

**What to do:** Go back to **Delivery Settings**. Do not edit Legacy Rules unless an administrator authorised that change for a specific support case.

---

## Escalation checklist (give support)

- Product / variation / order IDs  
- Screenshot of Delivery Settings and Preview (no passwords)  
- What the customer sees  
- What you already tried  
- Exact wording of any warning (Ready / Needs configuration / Configuration problem)
