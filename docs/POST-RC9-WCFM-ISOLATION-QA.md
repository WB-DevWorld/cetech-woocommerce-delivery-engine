# POST-RC.9 WCFM Isolation — `1.0.0-dev.wcfm.1` Owner QA Package

**Document status:** Packaged for owner physical QA. **Physical QA not executed by Cursor.** Not RC.9. Not deployed to FLAIROC.  
**Date:** 2026-09-01  
**Branch:** `feat/post-rc9-wcfm-isolation`  
**QA identity:** `1.0.0-dev.wcfm.1`  
**Schema:** `5`  
**Capability matrix version:** `4` (not a database schema change)  
**Protected published baseline:** tagged `v1.0.0-rc.9` **untouched**  
**FLAIROC:** not modified  
**Implementation record:** `docs/POST-RC9-WCFM-ISOLATION.md`

---

## 1. What this package is

Development candidate that keeps ordinary WCFM marketplace vendors **outside Delivery Engine administration**. It is isolation, not vendor fulfilment.

It is **not** RC.9, **not** RC.10, **not** Stage 15, and not a FLAIROC install.

---

## 2. Source and ZIP

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.wcfm.1.zip` |
| Version | `1.0.0-dev.wcfm.1` |
| Schema | `5` |
| Branch | `feat/post-rc9-wcfm-isolation` |
| Implementation/source SHA | `b3333bfb59a7b817aa784a79b640fab79d1179ac` |
| Package-source commit | `b3333bfb59a7b817aa784a79b640fab79d1179ac` |
| Bytes | `1387575` |
| SHA-256 | `fdf9f030e777c1e64c1a0a18bf649788325b61e03adae8aa19b85ed96c948c0d` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-dev.wcfm.1.zip` |
| Desktop copy | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-dev.wcfm.1.zip` |
| Build | `scripts/build-v1-rc-package.ps1 -Version 1.0.0-dev.wcfm.1 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-dev.wcfm.1.zip` (clean tree; **not** `-AllowDirty`) |
| RC.9 tag | `v1.0.0-rc.9` still peels to `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5` |

Do not install this on FLAIROC. Do not use the RC.9 ZIP for this QA pass.

---

## 3. Narrow owner QA instructions

Use a staging site that has WCFM, **not FLAIROC**. Replace/install `cetech-woocommerce-delivery-engine-1.0.0-dev.wcfm.1.zip`. Confirm Plugins shows **1.0.0-dev.wcfm.1** and schema remains **5**.

Create or use a user with role `wcfm_vendor` only (no `manage_options`). The FLAIROC audit found that role can carry `view_delivery_engine`; this package must still deny that user even if that stale cap remains.

1. Log in as a WordPress Administrator. Delivery Engine menu, Overview, Settings, and Access remain available. Settings → Access must **not** list `wcfm_vendor` as a configurable Delivery Engine role. A custom role whose slug is not `wcfm_vendor` / `disable_vendor` may still appear even if its display name contains “vendor”.
2. Log in as the WCFM vendor. There must be **no** Delivery Engine menu item.
3. Visit known Delivery Engine URLs directly (`admin.php?page=cetech-delivery-engine` and Site-wide Defaults, Delivery Options, Delivery Areas, Delivery Charges, Pickup Locations, Product Exceptions, Bulk Tools, Shipments, Needs Attention, Suppliers/Origins, Logistics Profiles, Diagnostics). Each must be denied.
4. Confirm the vendor can still use the normal WCFM/WooCommerce vendor product screens that are not Delivery Engine administration. Customer storefront delivery selection, cart, Classic checkout, Blocks checkout, and customer order/shipment summaries must still work for shoppers.
5. Log in as Shop Manager. Existing Delivery Engine access is unchanged.
6. Place a test customer order if needed only to confirm checkout/shipping still quote. Do not expect a vendor fulfilment UI.

### Fail closed

A restricted WCFM vendor must not see global Needs Attention, private suppliers/origins, or any global DE configuration. Administrators must never be locked out.

---

## 4. Explicitly out of scope

- FLAIROC
- Schema 6
- Stage 15
- Vendor-specific Delivery Engine fulfilment controls
- Retagging RC.9
- Cart-state reconciliation
