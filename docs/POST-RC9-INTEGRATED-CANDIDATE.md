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

## Automated results

| Gate | Result |
|------|--------|
| PHPUnit | 990 tests, 5577 assertions, OK (5 pre-existing deprecations) |
| JS (`npm run test:js`) | 41 passed |
| PHP lint | 558 files, 0 failures |
| `composer validate --no-check-publish` | valid |
| Schema | `5` |
| Capability matrix | `4` |

## Local combined QA

Lab: `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-local-qa` (`http://localhost:8088`). One reset to RC.9, then candidate replacement **without** a database reset.

- Administrator Delivery Engine menu: PASS
- Shop Manager Delivery Engine access: PASS
- WCFM vendor: no DE menu, all direct DE URLs denied, Store Manager works, stale caps stripped: PASS
- Classic Chair Accra + Kumasi: ₵15.00 + ₵22.00, two lines: PASS
- Blocks: `total_shipping` `3700` (GHS 37): PASS
- Pickup: ₵0 Delivery Engine shipping, pickup not presented as a destination delivery: PASS
- Blocks two-destination order **#30**: v2 line snapshots, Accra + Kumasi, two shipment plans, historical data intact: PASS

## RC.9 upgrade

RC.9 `1.0.0-rc.9` / schema `5` activated first. Config (Options/Areas/Rate Cards/Pickups/product rules) present. Historical RC.9 Blocks order **#25** created on RC.9 (`snapshot_version` 1 at order level). After ZIP replacement: plugin `1.0.0-dev.integrated.1`, schema still `5`, order #25 quote snapshot unchanged, shipment-plan error `missing_order_item` identical to RC.9 (RC.9 Blocks had no line snapshot). Administrator access intact. New per-item cart created after upgrade.

## Package

Packaged from exact tested source `ac2bc94056ebc73f1c6f8ab4a0434f4e68ce7300`.

| Artifact | Value |
|----------|--------|
| File | `cetech-woocommerce-delivery-engine-1.0.0-dev.integrated.1.zip` |
| Bytes | `1475971` |
| SHA256 | `571f738aa263676d64be6d285686a10a5fea2c48245449b55fe2240466a2d247` |

Extracted packaged-source verification: OK. ZIP reinstall smoke: version/schema, Classic, Blocks, WCFM vendor denial all PASS.

This is **not** RC.10.

## Intentionally excluded

- WPML
- Stage 15
- Schema 6
- Vendor fulfilment / vendor DE UI
- Re-running the full per-item A–K campaign
- Retagging RC.9 / RC.8
- Remote deploy
