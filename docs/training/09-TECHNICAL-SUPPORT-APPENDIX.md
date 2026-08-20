# Technical Support Appendix

**Audience:** Technical support and developers **only**  
**Normal staff should not require this document.**

**Plugin:** CETECH WooCommerce Delivery Engine **1.0.0-rc.5**  
**Schema target:** `4`  
**Release identity:** Git tag `v1.0.0-rc.5` (do not rewrite). Tags `v1.0.0-rc.4`, `v1.0.0-rc.3`, and `v1.0.0-rc.2` remain untouched.

If WordPress shows a different plugin version, stop and confirm which package is installed before following this appendix.

---

## THIS DOCUMENT IS FOR TECHNICAL SUPPORT / DEVELOPERS

If you are store staff configuring products day to day, use:

- [00-START-HERE](00-START-HERE.md)
- [12-SETUP-CONFIGURE-AND-TEST](12-SETUP-CONFIGURE-AND-TEST.md) (each menu, including how to do the work)
- [05-STAFF-TRAINING-MANUAL](05-STAFF-TRAINING-MANUAL.md)
- [03-USE-CASE-PLAYBOOK](03-USE-CASE-PLAYBOOK.md)
- [07-TROUBLESHOOTING-FAQ](07-TROUBLESHOOTING-FAQ.md)
- [11-STAGE-14-SHIPMENTS](11-STAGE-14-SHIPMENTS.md) (when **Shipments** is in the menu)

---

## Feature flags (operational names)

Required production switches (Classic Checkout environment):

- Use Site-wide Defaults at checkout — ON  
- Use Site-wide Defaults for product variations — ON  
- Show delivery choices on product pages — ON  
- Remember the customer’s delivery choice in the cart — ON  
- Validate delivery choice at checkout — ON  
- Show delivery fees at checkout — ON  
- Save delivery details on orders — ON  

Shipment records and customer tracking links stay **OFF** until an Administrator turns them on in Settings. Not in this release: customer timeline, Checkout Blocks, carrier APIs, automatic tracking updates, shipment emails.

Do not flip Advanced cutover switches without a change window and owner approval.

**Cash on Delivery:** production storefront policy remains **OFF**. If COD is used, Delivery Engine does **not** create a shipment until payment is confirmed. Staff create that shipment from the Order ID. Do not mark an order paid just to force a shipment.

---

## Internal concepts (support only)

| Concept | Why support cares | Staff should see |
|---------|-------------------|------------------|
| Configuration scopes / fields / collections | Schema target **4**; per-profile `global/0/{profile_key}` Site-wide Defaults | Site-wide Defaults / Product Exceptions UI |
| EffectiveConfigurationResolver | GLOBAL → PRODUCT → VARIATION field inheritance + hard constraints | Preview Delivery “Currently using” / Ready |
| Order delivery snapshots | Immutable post-payment delivery facts (`_cetech_de_*`) | Delivery information panel |
| Public presentation contract | Compact option + estimate; shipping `rate_label` prefers public option name | Customer product / thank-you / email |
| Older product-rule storage (hidden) | Compatibility rows may still exist; not a staff menu | Staff use Site-wide Defaults / Product Exceptions |
| Shipping method `delivery_engine_selected_offer` | WC method; label prefers public Delivery Option | Checkout shipping line |
| Shipment records (default OFF) | Schema-4 shipment / item / event tables; built from the **saved order** delivery groups | **Delivery Engine → Shipments** after an Administrator enables shipment records |
| Customer tracking links (default OFF) | Safe `http` / `https` URL only; no carrier polling | **Track shipment** on View Order when a safe URL is saved |
| AdministratorAccessRecovery | `manage_options` + nonce; independent of diagnostics | Restore Administrator Access notice |

---

## Diagnostics (support)

- Hidden **Technical diagnostic tools** (`view_delivery_diagnostics`) — not a normal submenu  
- Delivery Charges test / Delivery Areas address tester  
- Preview Delivery (contextual)  
- PHP error log review for Delivery Engine fatals  

Do not instruct ordinary staff to run WP-CLI, SQL, Redis FLUSHALL, Nginx edits, or Code Snippets.

Administrator lockout repair is **Restore Administrator Access**, authorised by native `manage_options`. It must not depend on diagnostics capability.

---

## Runtime compatibility notes

- WooCommerce is authoritative for commerce workflows; HPOS-compatible order CRUD.  
- Classic Checkout is the verified path.  
- Core must not depend on WoodMart.  
- Never trust browser-submitted authoritative delivery prices.  
- Missing / malformed / nonnumeric rates fail closed — never silent free shipping. Explicit configured numeric zero is allowed.  
- Never silently replace a customer’s selected Delivery Option.  
- International Delivery = Air and/or Sea only. Air and Sea are separate delivery groups / shipments.  
- Store pickup is not a delivery shipment.  
- Suppliers, origins, and private logistics stay off customer surfaces.  
- Historical order delivery snapshots remain immutable. Shipments are built from that saved order data, not from today’s product settings.  
- Do not start Stage 15, Checkout Blocks, carrier APIs, or other future work from this appendix.

---

## Rollback / release identity

- Current package: `cetech-woocommerce-delivery-engine-1.0.0-rc.5.zip`  
- ZIP bytes: `1043995`  
- SHA-256: `7f9ad300a2d7198b7f0e70f1cd0819351e35430fd5ca843dac7c24ce9f96d66c`  
- Package source commit: `e0b4613`  
- Full record: `docs/STAGE-14H-FINAL.md`  
- Immediate prior production rollback: tagged `v1.0.0-rc.4` (RC.4 ZIP remains available)  
- Do not alter tags `v1.0.0-rc.5`, `v1.0.0-rc.4`, `v1.0.0-rc.3`, or `v1.0.0-rc.2`.  
- Do not retag RC.4 or earlier.

Staff training markdown lives in `docs/training/` in the plugin repository. It is **not** gitignored. Screenshot/video binaries and `training/playwright/` auth stay gitignored. Training docs are **not** part of the production plugin ZIP.

---

## Playwright documentation harness

`training/playwright/` was built for older label/screenshot capture. It has **not** been rewritten for RC.5 menus. Auth storage is gitignored. Do not claim RC.5 visual capture from that harness until it is updated.

---

## Related engineering docs

- `docs/PROJECT-GOVERNANCE.md`
- `docs/DELIVERY-ENGINE-GOVERNING-RULES.md`
- `docs/ADMIN-UI-LANGUAGE-GUIDE.md` (some page names still describe earlier stages; **live RC.5 menus win**)
- `docs/AI-HANDOFF.md` (current implementation status)
- `docs/STAGE-14H-FINAL.md`
- `docs/STAGE-14F-SHIPMENT-OPERATIONS-WORKFLOW.md`
- Stage/phase implementation records under `docs/`
