# Quick Start (about 10 minutes)

**Audience:** New staff  
**Plugin version:** 1.0.0-rc.6  
**Goal:** Do the everyday tasks safely without technical detail.

Screenshots are not included in this revision. Follow the live WordPress screens.

---

## 1. Where to go

In WordPress admin open:

**Delivery Engine → Overview**

That is the daily home. From there you can see whether delivery is ready, jump to Site-wide Defaults, and open items that need attention.

Also know:

- **Site-wide Defaults** — the normal rules every eligible product can inherit  
- **WooCommerce → Products** — product **Delivery** tab (exceptions)  
- **WooCommerce → Orders** — **Delivery information** on a paid order  
- **Preview Delivery** — from a product’s Delivery tab or Overview, to see what will actually apply  

---

## 2. How settings inherit

Think of three layers:

**Site-wide Defaults → Product exception → Variation exception**

- If a product has no special setting, it uses the **Site-wide Default** for its fulfilment type (In Warehouse, In Store, or International).
- If a variation has no special setting, it uses the **product**.
- Changing one field (for example Estimated delivery) does **not** freeze the other fields. Inheritance is field-by-field.

Modes you will see when you customize:

| Label | Meaning |
|-------|---------|
| **Use Site-wide Default: …** | Keep the store default for this field |
| **Use Product Setting: …** | Variation keeps the parent product value |
| **Set a different fulfilment / delivery method / estimate** | Use a different value here |
| **Reset to Site-wide Defaults** / **Reset to Product Settings** | Remove this item’s exceptions |

---

## 3. Configure a normal product

Most products need **no product-level work**. They inherit Site-wide Defaults automatically.

When one product must differ:

1. Open **WooCommerce → Products** and edit the product (practice on QA **#39705**).
2. Open the **Delivery** tab.
3. Confirm **Currently using** (usually the Site-wide Default).
4. Click **Customize This Product**.
5. Change **only** the fields that must differ.
6. Click **Save Product Delivery Settings**.

You can also find customized products under **Delivery Engine → Product Exceptions**.

---

## 4. Configure a variation

1. Edit the variable parent (QA **#39717**).
2. Open the variation (QA **#39718** or **#39719**).
3. In the variation **Delivery** summary, click **Customize This Variation**.
4. Leave **Use Product Setting** if the parent is correct.
5. Change only the fields that must differ for that variation.
6. Click **Save Variation Delivery Settings**.

---

## 5. Check Preview

From the product **Delivery** tab, click **Preview Delivery**.

Confirm the product/variation shows **Ready** (or a clear **Needs Attention** message you can act on).

---

## 6. Save

After editing, always use the Delivery Engine **Save** button on that screen. If you leave without saving, changes are not applied.

Later Site-wide Default edits apply immediately to inherited fields. You do not re-apply the whole catalogue.

---

## 7. Check the customer-facing result

Open the product on the shop.

Customers should see:

- the public **Delivery option** name (radio + bold label)
- **Estimated delivery:** … (or **Ready for pickup** when pickup applies)

They should **not** see fulfilment labels, internal delivery-method wording, supplier/origin names, or a long internal description on the compact selector.

For a variable product, select the variation first, then the delivery choice.

---

## 8. Where orders show delivery

**WooCommerce → Orders →** open the order → find **Delivery information**.

This is what was saved when the customer ordered. Practice read-only on QA orders **#39721** or **#39724**.

At checkout, the shipping line should show the public Delivery Option name and the configured charge.

---

## 9. When to ask an administrator

Stop and escalate if you need to:

- change **Delivery Engine → Settings** (especially Advanced switches)  
- change **Settings → Access**  
- open a screen that is not in the everyday menu  
- “fix” delivery details on an old paid order  
- change store-wide pricing or areas without authorisation  

Also escalate if **Needs Attention** still lists a product after Site-wide Defaults and the product Delivery tab look complete.

---

## Next

If the plugin is **not** set up yet (Setup Guide is still in the menu, or Preview is not Ready), stop and follow [12 — How to use each menu](12-SETUP-CONFIGURE-AND-TEST.md) first.

Continue with the [Staff Training Manual](05-STAFF-TRAINING-MANUAL.md) or the [Visual Walkthrough](04-VISUAL-WALKTHROUGH.md).

If **Delivery Engine → Shipments** is in the menu, also read [11 — Stage 14 shipments](11-STAGE-14-SHIPMENTS.md) and playbook use cases 20–30.
