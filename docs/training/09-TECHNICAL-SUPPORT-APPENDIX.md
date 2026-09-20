# Technical Support Appendix

**Audience:** Technical support and developers **only**  
**Normal staff should not require this document.**

**Plugin:** CETECH WooCommerce Delivery Engine **1.0.0-rc.12** (schema **6**). Canonical geography, Coverage Groups, Location Packs, Cart/Checkout Blocks, overlapping Delivery Area pricing, constrained fallback.  
**Schema target:** `6`  
**Release identity:** Git tag `v1.0.0-rc.12` peels to `78594ad8962868683726373f58f4a8b1b48e4d0e`. Annotated tag object `89f34883a017b8bb66f98db345fbbae0d8dd72b0`. Do not rewrite. Tags `v1.0.0-rc.11` through `v1.0.0-rc.2` remain untouched.

If WordPress shows a different plugin version, stop and confirm which package is installed before following this appendix.

Do not put site credentials, API keys, or backup secrets in tickets copied from this guide.

---

## THIS DOCUMENT IS FOR TECHNICAL SUPPORT / DEVELOPERS

If you are store staff configuring products day to day, use:

- [00-START-HERE](00-START-HERE.md)
- [12-SETUP-CONFIGURE-AND-TEST](12-SETUP-CONFIGURE-AND-TEST.md) (each menu, including how to do the work)
- [05-STAFF-TRAINING-MANUAL](05-STAFF-TRAINING-MANUAL.md)
- [03-USE-CASE-PLAYBOOK](03-USE-CASE-PLAYBOOK.md)
- [07-TROUBLESHOOTING-FAQ](07-TROUBLESHOOTING-FAQ.md)
- [11-STAGE-14-SHIPMENTS](11-STAGE-14-SHIPMENTS.md) (when **Shipments** is in the menu)
- [13-FEATURE-COVERAGE-AND-CONFIRMATION](13-FEATURE-COVERAGE-AND-CONFIRMATION.md)
- [14-LOCATION-PACKS-AND-GEOGRAPHY](14-LOCATION-PACKS-AND-GEOGRAPHY.md)
- [15-DELIVERY-AREAS-AND-COVERAGE-GROUPS](15-DELIVERY-AREAS-AND-COVERAGE-GROUPS.md)

---

## Feature flags (operational names)

Required production switches (Classic Checkout **and** Cart/Checkout Blocks):

- Use Site-wide Defaults at checkout — ON  
- Use Site-wide Defaults for product variations — ON  
- Show delivery choices on product pages — ON  
- Remember the customer’s delivery choice in the cart — ON  
- Validate delivery choice at checkout — ON  
- Show delivery fees at checkout — ON  
- Save delivery details on orders — ON  

Shipment records and customer tracking links stay **OFF** until an Administrator turns them on in Settings. Not in this release: customer timeline, carrier APIs, automatic tracking updates, Delivery Engine shipment emails. Cart/Checkout Blocks **are** implemented. Settings shows Blocks and optional integrations as **status**, not experimental checkboxes.

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
| Shipping method `delivery_engine_selected_offer` | WC method listed in Add shipping method while the plugin is active; rates stay flag-gated; label prefers public Delivery Option | Checkout shipping line |
| Region State / Region rules | Match WooCommerce state **code or** that country’s label (Ghana `AA` / Greater Accra); no area-data migration | Delivery Areas tester vs checkout |
| Shipment records (default OFF) | Schema-4 shipment / item / event tables; built from the **saved order** delivery groups | **Delivery Engine → Shipments** after an Administrator enables shipment records |
| Customer tracking links (default OFF) | Safe `http` / `https` URL only; no carrier polling | **Track shipment** on View Order when a safe URL is saved |
| AdministratorAccessRecovery | `manage_options` + nonce; independent of diagnostics | Restore Administrator Access notice |
| Schema 6 geography | Tables `geography_packs`, `geography_locations`, `geography_location_aliases`, `geography_provider_mappings` | Location Packs UI |
| Schema 6 coverage | Tables `destination_coverage_groups`, `destination_coverage_members`, `destination_coverage_postcodes` | Delivery Area Coverage Groups |
| Pack statuses | `pending`, `importing`, `ready`, `failed`. Usable dataset ≈ **ready** (or last successful checksum) | Installed packs Status column |
| Review required | Group not usable until `review_required` is cleared after human confirm (`isUsable` = active + not review_required + root) | Inline warning + **I reviewed this migrated coverage** |
| Safe reconciliation | Revisits empty / migration review-required / unresolved records; skips protected manual canonical coverage | **Run safe legacy reconciliation** |
| Action Scheduler | Batched pack import / upgrade ticks | Progress column; **Continue / retry** |

---

## Schema 6 / RC.11 → RC.12 migration (support)

- RC.11 = schema **5**. RC.12 = schema **6**.  
- Normal plugin upgrade creates canonical geography/coverage structures. Delivery Areas, rate cards, rules, and business references are retained.  
- Some city rules become `review_required` / `unmapped_city` when no locality pack is installed.  
- **Do not** manually invoke migration classes. **Do not** downgrade schema by hand. **Do not** edit pack or coverage rows to “force ready.”  
- Backup before major upgrades (human-controlled). Git rollback does not undo WordPress database side effects.  
- Evidence of isolated RC.11→RC.12 upgrade retention lives in `docs/RC12-PROMOTION.md`. Training-site qualification: PASS on RC.12 / schema 6. Do not expose server IPs in staff copies.

## What NOT to change manually

- Pack `status` in SQL  
- Coverage `review_required` flags without the admin checkboxes  
- Schema option / migration leases  
- Historical order `_cetech_de_*` snapshots  
- Production Delivery Charges to “make a screenshot prettier”

## Safe evidence collection

- Plugin version and schema from Settings / Overview  
- Screenshot of Location Packs row (status, progress, last error) — redact URLs that reveal internals if asked  
- Screenshot of Coverage Group review-required warning  
- **Test an address** Primary / Also matches  
- Preview Delivery for QA products  
- WooCommerce System Status (no credentials)  
- Do not dump database, wp-config, or backup archives into chat

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
- Classic Checkout remains available. Cart/Checkout Blocks use the same Delivery Engine pricing, validation, and order snapshots.  
- Core must not depend on WoodMart.  
- Never trust browser-submitted authoritative delivery prices.  
- Missing / malformed / nonnumeric rates fail closed — never silent free shipping. Explicit configured numeric zero is allowed (including Store pickup).  
- Never silently replace a customer’s selected Delivery Option.  
- International Delivery = Air and/or Sea only. Air and Sea are separate delivery groups / shipments.  
- In Warehouse = local delivery only (no Air/Sea/pickup leak).  
- Matched overlapping Delivery Areas: quote the same selected option against broader matches only when the more-specific area has **no matching charge** for that option. Invalid more-specific charges fail closed.  
- Store pickup is not a delivery shipment.  
- Suppliers, origins, and private logistics stay off customer surfaces.  
- Historical order delivery snapshots remain immutable. Shipments are built from that saved order data, not from today’s product settings.  
- Do not start Stage 15, carrier APIs, or WPML/WCML/WCFM/VitePOS adapters from this appendix.

---

## Rollback / release identity

- Protected published baseline: tagged `v1.0.0-rc.12`, schema `6`. Do not retag RC.12 or earlier. Later documentation commits on `master` are **not** the RC.12 ZIP source.  
- Immutable prior: `v1.0.0-rc.11` (schema `5`).  
- Historical tagged `v1.0.0-rc.10` through `v1.0.0-rc.2` remain untouched.  
- Do not overwrite historical QA ZIPs.

Staff training markdown lives in `docs/training/` in the plugin repository. It is **not** gitignored. Screenshot/video binaries and `training/playwright/` auth stay gitignored. Training docs are **not** part of the production plugin ZIP. After merge to `master`, `.github/workflows/sync-training-docs.yml` publishes `docs/training/**` to `wbdevworld/cetech-woocommerce-delivery-engine-training`.

---

## Playwright documentation harness

`training/playwright/` was built for older label/screenshot capture. It has **not** been rewritten for current menus. Auth storage is gitignored. Do not claim visual capture from that harness until it is updated.

---

## Related engineering docs

- `docs/PROJECT-GOVERNANCE.md`
- `docs/DELIVERY-ENGINE-GOVERNING-RULES.md`
- `docs/ADMIN-UI-LANGUAGE-GUIDE.md` (some page names still describe earlier stages; **live menus win**)  
- `docs/AI-HANDOFF.md` (current implementation status)  
- `docs/RC8-FINALIZATION.md`  
- `docs/POST-RC8-BLOCKS-3-MATCHED-AREA-PRICING.md`
- `docs/STAGE-14H-FINAL.md`
- `docs/STAGE-14F-SHIPMENT-OPERATIONS-WORKFLOW.md`
- Stage/phase implementation records under `docs/`
