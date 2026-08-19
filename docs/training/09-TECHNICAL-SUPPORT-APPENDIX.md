# Technical Support Appendix

**Audience:** Technical support and developers **only**  
**Normal staff should not require this document.**

**Plugin:** CETECH WooCommerce Delivery Engine **1.0.0-rc.4**  
**Release identity:** Git tag `v1.0.0-rc.4` (do not rewrite). RC.3 tag `v1.0.0-rc.3` and RC.2 tag `v1.0.0-rc.2` remain untouched.

---

## THIS DOCUMENT IS FOR TECHNICAL SUPPORT / DEVELOPERS

If you are store staff configuring products day to day, use:

- [00-START-HERE](00-START-HERE.md)
- [05-STAFF-TRAINING-MANUAL](05-STAFF-TRAINING-MANUAL.md)
- [07-TROUBLESHOOTING-FAQ](07-TROUBLESHOOTING-FAQ.md)

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

Deferred / OFF until an Administrator enables them: shipment records, customer tracking links. Not in this release: customer timeline, Blocks adapter, carrier APIs.

Do not flip Advanced cutover switches without a change window and owner approval. COD remains OFF.

---

## Internal concepts (support only)

| Concept | Why support cares | Staff should see |
|---------|-------------------|------------------|
| Configuration scopes / fields / collections | Schema target 3; per-profile `global/0/{profile_key}` Site-wide Defaults | Site-wide Defaults / Product Exceptions UI |
| EffectiveConfigurationResolver | GLOBAL → PRODUCT → VARIATION field inheritance + hard constraints | Preview Delivery “Currently using” / Ready |
| Order delivery snapshots | Immutable post-payment delivery facts (`_cetech_de_*`) | Delivery information panel |
| Public presentation contract | Compact option + estimate; shipping `rate_label` prefers public option name | Customer product / thank-you / email |
| Legacy product delivery rules | Hidden compatibility storage; no normal menu | Not an everyday workflow |
| Shipping method `delivery_engine_selected_offer` | WC method; label prefers public Delivery Option | Checkout shipping line |
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
- International Delivery = Air and/or Sea only.  
- Suppliers, origins, and private logistics stay off customer surfaces.  
- Historical order delivery snapshots remain immutable.

---

## Rollback / release identity

- Final package: `cetech-woocommerce-delivery-engine-1.0.0-rc.4.zip`  
- SHA-256 and byte size: `docs/STAGE-13G-RC4-FINALIZATION.md`  
- Immediate prior production rollback: tagged `v1.0.0-rc.3`  
- Do not alter tags `v1.0.0-rc.4`, `v1.0.0-rc.3`, or `v1.0.0-rc.2`.

Staff training markdown in `docs/training/` is local/gitignored and is **not** part of the production ZIP.

---

## Playwright documentation harness

`training/playwright/` was built for RC.2 label/screenshot capture. It has **not** been rewritten for RC.4 menus. Auth storage is gitignored. Do not claim RC.4 visual capture from that harness until it is updated.

---

## Related engineering docs

- `docs/PROJECT-GOVERNANCE.md`
- `docs/ADMIN-UI-LANGUAGE-GUIDE.md` (some page names still describe earlier stages; **live RC.4 menus win**)
- `docs/AI-HANDOFF.md` (current implementation status)
- `docs/STAGE-13G-RC4-FINALIZATION.md`
- `docs/STAGE-13F-CUSTOMER-PRESENTATION-POLISH.md`
- Stage/phase implementation records under `docs/`
