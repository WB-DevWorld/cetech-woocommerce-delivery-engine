# POST-RC.9 Cart-State — `1.0.0-dev.cartstate.1` Owner QA Package

**Document status:** Packaged for owner physical QA. **Physical QA not executed by Cursor.** Not RC.9. Not deployed to FLAIROC.  
**Date:** 2026-09-01  
**Branch:** `feat/post-rc9-cart-state`  
**QA identity:** `1.0.0-dev.cartstate.1`  
**Schema:** `5`  
**Protected published baseline:** tagged `v1.0.0-rc.9` **untouched**  
**FLAIROC:** not modified  
**Implementation record:** `docs/POST-RC9-CART-STATE-RECONCILIATION.md`

---

## 1. What this package is

Development candidate that makes **cart = live customer state**. Administrative Delivery Engine configuration changes refresh existing cart lines before shipping/checkout. Historical orders remain immutable snapshots.

It is **not** RC.9, **not** RC.10, **not** Stage 15, and not a FLAIROC install.

---

## 2. Source and ZIP

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.cartstate.1.zip` |
| Version | `1.0.0-dev.cartstate.1` |
| Schema | `5` |
| Branch | `feat/post-rc9-cart-state` |
| Implementation/source SHA | `39f61c1bc79e6248f1e64cdb945f62edf61b357b` |
| Package-source commit | `39f61c1bc79e6248f1e64cdb945f62edf61b357b` |
| Bytes | `1401258` |
| SHA-256 | `5c72a87bdba7f77d2d2b66f7812f12fc448a2f84dc51748ab6c266d6a3175ecc` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-dev.cartstate.1.zip` |
| Desktop copy | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-dev.cartstate.1.zip` |
| Build | `scripts/build-v1-rc-package.ps1 -Version 1.0.0-dev.cartstate.1 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-dev.cartstate.1.zip` (clean tree; **not** `-AllowDirty`) |
| RC.9 tag | `v1.0.0-rc.9` still peels to `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5` |

Do not install this on FLAIROC. Do not use the RC.9 ZIP for this QA pass.

---

## 3. Narrow owner QA instructions

Use a staging/training site, **not** FLAIROC. Replace/install `cetech-woocommerce-delivery-engine-1.0.0-dev.cartstate.1.zip`. Confirm Plugins shows **1.0.0-dev.cartstate.1** and schema remains **5**.

Runtime flags already required for storefront delivery must stay on (Activate Delivery Engine path). Test both **Classic Cart/Checkout** and **native Cart/Checkout Blocks**.

### Reproduction (the beta finding)

1. Configure a product with Delivery Option A. Add it to cart. Confirm one shipping/delivery group for A.
2. In admin, change only the public label/ETA of A (non-semantic). Reload cart **without** removing the product.
   - Expected: existing line shows the new label/ETA. No second ghost group. No “remove and re-add”.
3. Remove Option A from the product (or replace A with a different option that is not the same offer ID). Reload cart.
   - Expected: the original line is identified by product name, stale A is **not** shown or quoted, checkout is blocked, and an **Update delivery option** control is offered. The product stays in cart.
4. Choose a current option on that line. Checkout can proceed. Shipping uses the new choice only.
5. After a configuration change, add the same product again with the same customer choice.
   - Expected: quantities consolidate. No duplicate shipping groups from leftover admin fingerprints.
6. Add the same product with a **different** fulfilment choice (Delivery vs Store Pickup). Lines stay separate. Pickup remains GHS 0 and is not a delivery shipment.
7. Variable product: two variations stay separate even with the same Delivery Option.
8. International product: Air/Sea only; local delivery is not silently substituted. In Warehouse: local delivery only; Air/Sea not substituted.
9. Place an order after reselection. The order snapshot matches the **final reconciled** choice. Do **not** change product configuration afterwards and expect that paid order to update — it must stay frozen.
10. Restore a session (leave cart, return later) after an admin config change. Existing lines reconcile the same way.

### Fail closed

Checkout (Classic and Blocks) must not place an order while any managed line still needs reselection or still carries an invalid/stale managed selection.

---

## 4. Explicitly out of scope

- FLAIROC
- Schema 6
- Stage 15
- Building a global product-level location state
- Rewriting historical orders
- Retagging RC.9
