# Post-RC.9 combined candidate — `1.0.0-dev.integrated.1`

**Document status:** Combined development candidate record  
**Identity:** `1.0.0-dev.integrated.1`  
**Branch:** `feat/post-rc9-integrated-candidate`  
**Schema:** `5` (unchanged)  
**Capability matrix:** `4`  
**Not:** RC.10, Stage 15, WPML, FLAIROC, training

```text
READ → AUDIT → PLAN → IMPLEMENT → TEST → DOCUMENT → REVIEW → STOP
```

## Provenance

Rooted from qualified per-item packaged source:

`72cb7ddfcb5ed85765ed167831736ba04a3cdf89` (`1.0.0-dev.peritem.1`)

Cherry-picks (`-x`):

| Commit | Role |
|--------|------|
| `f09d0b67a218f6d450f02a5bd9f51222c058f773` | WCFM vendor administrative isolation |
| `b3333bfb59a7b817aa784a79b640fab79d1179ac` | Packaged-source verifier expects capability matrix version 4 |

`b3333bf` alone is a 4-line verifier follow-up. The isolation implementation is `f09d0b6`. Both are required to reproduce the qualified WCFM tree. WPML was not merged.

## Targeted security pass

Reviewed: DE admin page/action capability + nonce paths, WCFM isolation, Store API cart-context commands, customer AJAX, public payloads, order/shipment admin display.

**Defect found (medium):** Admin mutation paths checked WordPress capabilities only. A WCFM vendor with stale Delivery Engine capabilities could still POST a nonce-bearing admin action, advance a Bulk Tools job, preview variations, or use Setup Wizard save-later, even though menus and page render were denied.

**Fix:** deny restricted vendors on `AdminActionHandler::verify_post()`, System Status resync, Bulk Job AJAX, Preview Variations AJAX, and Setup Wizard verification. Regression: `WcfmAdminIsolationTest`.

No high-impact Store API, privacy, or order-access defects found in this pass. Browser-submitted prices/currency/private IDs remain non-authoritative. Order admin snapshot display is customer/order fulfilment data for the authorized WooCommerce order, not global supplier/origin costs.

WooCommerce still owns one tax/customer-location model. Per-item destinations are not per-destination tax.

## Intentionally excluded

- WPML
- Stage 15
- Schema 6
- Vendor fulfilment / vendor DE UI
- Re-running the full per-item A–K campaign
- Retagging RC.9 / RC.8
- Remote deploy
