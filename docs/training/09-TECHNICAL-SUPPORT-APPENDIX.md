# Technical Support Appendix

**Audience:** Technical support and developers **only**  
**Normal staff should not require this document.**

**Plugin:** CETECH WooCommerce Delivery Engine **1.0.0-rc.2**  
**Release identity:** Git tag `v1.0.0-rc.2` (do not rewrite). Closure commit documented in project release notes.

---

## THIS DOCUMENT IS FOR TECHNICAL SUPPORT / DEVELOPERS

If you are store staff configuring products day to day, use:

- [00-START-HERE](00-START-HERE.md)
- [05-STAFF-TRAINING-MANUAL](05-STAFF-TRAINING-MANUAL.md)
- [07-TROUBLESHOOTING-FAQ](07-TROUBLESHOOTING-FAQ.md)

---

## Feature flags (operational names)

Required production switches (live RC.2 Classic Checkout environment):

- Use the New Delivery Settings System — ON  
- Use New Delivery Settings for Product Variations — ON  
- Show delivery choices on product pages — ON  
- Remember the customer’s delivery choice in the cart — ON  
- Validate delivery choice at checkout — ON  
- Show delivery fees at checkout — ON  
- Save delivery details on orders — ON  

Deferred / OFF examples: shipment records, timeline, tracking links, Blocks adapter, WoodMart adapter (not required for RC.2).

Do not flip Advanced cutover switches without a change window and owner approval.

---

## Internal concepts (support only)

| Concept | Why support cares | Staff should see |
|---------|-------------------|------------------|
| Configuration scopes / fields / collections | Schema target 3 storage for Default→Product→Variation | Delivery Settings UI |
| Effective configuration / fingerprint | Server resolves what applies; fingerprint helps parity/debug | Preview “Currently using” / Ready |
| Order delivery snapshots | Immutable post-payment delivery facts | Delivery information panel |
| Protected order metadata | Must not be casually overwritten | Hidden technical meta |
| Legacy product delivery rules | Compatibility / migration path | Legacy Delivery Rules menu |
| Shipping method `delivery_engine_selected_offer` | WC method title **Delivery** | Checkout “Delivery” line |

---

## Diagnostics (support)

- Dashboard **Advanced system details** (environment, readiness, flag table)  
- Rate Cards **Check a delivery price** (read-only diagnostic)  
- Destination Zones address tester  
- Legacy technical diagnostic tools (collapsed)  
- PHP error log review for Delivery Engine fatals (marker-based release smoke process)

Do not instruct ordinary staff to run WP-CLI, SQL, Redis FLUSHALL, Nginx edits, or Code Snippets.

---

## Runtime compatibility notes

- WooCommerce is authoritative for commerce workflows; HPOS-compatible order CRUD.  
- Classic Checkout is the verified RC.2 path.  
- Core must not depend on WoodMart; RC.2 verified without a WoodMart adapter.  
- Never trust browser-submitted authoritative delivery prices.  
- Missing configuration must never silently become free/$0 shipping.  
- Never silently replace a customer’s selected delivery option.

---

## Rollback / release identity

- Package and SHA are recorded in `docs/RELEASE-1.0.0-RC.2-READINESS.md`.  
- Do not alter tag `v1.0.0-rc.2`.  
- Training documentation lives on branch `docs/staff-training-rc2` and must not rewrite release history.

---

## Playwright documentation harness

`training/playwright/` validates that training labels/screens still match. Auth storage is gitignored. Cloudflare may block automated `wp-login.php`; support may need a manually exported local storage state for admin captures.

---

## Related engineering docs

- `docs/PROJECT-GOVERNANCE.md`
- `docs/ADMIN-UI-LANGUAGE-GUIDE.md`
- `docs/AI-HANDOFF.md` (current implementation status)
- Stage/phase implementation records under `docs/`
