# Quick Start (about 10 minutes)

**Audience:** New staff  
**Plugin version:** 1.0.0-rc.2  
**Goal:** Do the everyday tasks safely without technical detail.

**Watch (optional):** [Video 01 — Getting started overview](10-VIDEO-TRAINING-LIBRARY.md#01--getting-started-overview) · [Video 12 — Complete walkthrough](10-VIDEO-TRAINING-LIBRARY.md#12--complete-staff-walkthrough)

---

## 1. Where to go

In WordPress admin open:

**Delivery Engine → Delivery Settings**

That is the normal place to manage delivery for the store, a product, or a variation.

![Delivery Settings home](assets/screenshots/01-delivery-settings-home.png)

*Full captioned tour:* [04 — Visual Walkthrough](04-VISUAL-WALKTHROUGH.md).

Also know:

- **Delivery Settings Preview** — see what will actually apply  
- **WooCommerce → Orders** — open an order and find **Delivery information**

---

## 2. How settings inherit

Think of three layers:

**Default Settings → Product-Specific Settings → Variation-Specific Settings**

- If a product has no special setting, it uses the **Default**.
- If a variation has no special setting, it uses the **product**.
- Only set a different value when that product or variation truly needs it.

Modes you will see:

| Label | Meaning |
|-------|---------|
| **Use inherited setting** | Keep the value from the level above |
| **Set a different value here** | Use a different value for this product/variation |
| **Turn off** | Intentionally none for this item (where supported) |

---

## 3. Configure a normal product

1. Go to **Delivery Settings → Product-Specific Settings**.
2. Enter the WooCommerce **product ID** (practice on QA **#39705**).
3. Leave fields on **Use inherited setting** if the default is correct.
4. Only change fields that must differ from the default.
5. Click **Save**.

---

## 4. Configure a variation

1. Go to **Variation-Specific Settings**.
2. Enter **parent product ID** (QA **#39717**) and **variation ID** (QA **#39718** or **#39719**).
3. Leave **Use inherited setting** if the parent product is correct.
4. Change only the fields that must differ for that variation.
5. Save.

---

## 5. Check Preview

Open **Delivery Engine → Delivery Settings Preview**.

Confirm the product/variation shows **Ready** (or a clear message if something still needs configuration).

![Delivery Settings Preview Ready](assets/screenshots/05-delivery-preview-ready.png)

---

## 6. Save

After editing, always **Save** on Delivery Settings. If you leave the page without saving, your changes are not applied.

---

## 7. Check the customer-facing result

Open the product on the shop (QA simple: `/buy/flairoc-delivery-engine-qa-product/`).

You should see **Delivery options**. For a variable product, select the variation first, then the delivery choice.

---

## 8. Where orders show delivery

**WooCommerce → Orders →** open the order → find **Delivery information**.

Look for fulfilment, delivery method/option, estimate, and charge. This is what was saved when the customer ordered.

Practice on existing QA orders **#39721** or **#39724** (read-only). See walkthrough shot `13-order-delivery-information.png`.

---

## 9. When to ask an administrator

Stop and escalate if you need to:

- change **Delivery Engine → Settings** switches  
- edit **Legacy Delivery Rules**  
- change **Suppliers & Origins**  
- change store-wide pricing or zones without authorisation  
- “fix” delivery details on an old paid order  

Also escalate if Preview shows **Needs configuration** or **Configuration problem** and you cannot resolve it with product/variation settings.

---

## Next

Continue with the [Staff Training Manual](05-STAFF-TRAINING-MANUAL.md) or the [Visual Walkthrough](04-VISUAL-WALKTHROUGH.md).
