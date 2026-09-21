# Compatibility & Certification Matrix

Status: owner-approved compatibility policy for `PRODUCT-TRUTH-BASELINE-1`. “Designed,” “implemented,” and “certified” remain deliberately separate.

## Status vocabulary

- **Core dependency** — required for the plugin to perform its primary job.
- **Designed** — architecture has an explicit boundary/adapter strategy.
- **Implemented** — relevant adapter/runtime behavior exists in the inspected source.
- **Automated evidence** — committed tests/CI exercise the boundary.
- **Physical certification** — named real versions/environment and acceptance evidence exist.
- **Separate/optional** — absence must not break core.

## Matrix

| Target | Relationship | Designed | Implemented | Automated evidence | Physical certification / current claim | Stable 1.0 disposition |
|---|---|---:|---:|---:|---|---|
| WordPress | Platform | Yes | Yes | Yes | RC.11 owner/lab evidence includes WordPress 7.1 for issue #18 | Mandatory supported-version matrix |
| WooCommerce | **Only hard app dependency** | Yes | Yes | Yes | RC.11 package/upgrade evidence; Woo 11.0.1 issue #18 and 11.1.0 package lab | Mandatory |
| HPOS | Native Woo order storage | Yes | Yes | Yes | Repository qualification claims HPOS compatibility; preserve exact tested matrix | Mandatory |
| Classic cart/checkout | Core checkout surface | Yes | Yes | Yes | Historical owner QA through RC line | Mandatory |
| Cart & Checkout Blocks / Store API | Core checkout surface | Yes | Yes | Yes | RC.9+ accepted; exact Store API/unit/JS evidence | Mandatory |
| Storefront theme | Baseline theme | Yes | Theme-neutral core | Yes | Issue #18 physically qualified on Storefront | Mandatory baseline |
| WoodMart | Priority theme target | Yes | Theme-neutral/scoped CSS; no hard adapter required | Yes | Physically qualified on WoodMart 8.4.1 for issue #18; geo.11 not physically qualified | Mandatory targeted certification for Stable 1.0, exact versions recorded |
| Generic themes | Theme independence | Yes | Yes by scoped hooks/CSS | Partial | No broad physical matrix | Mandatory design; bounded reference-theme certification |
| WPML | Optional translation adapter | Yes | Separate branch only | Extensive branch tests | Not merged or certified in RC.11 | Certify before Stable 1.0 only if advertised as launch-supported; never a hard dependency |
| WCML | Optional multilingual-commerce adapter | Yes | Detection/status only in core | Limited | Explicitly separate/not included | Same launch-claim condition as WPML |
| WCFM | Marketplace/admin isolation | Yes | Yes for admin isolation and recovered context boundary | Yes | Qualified in recovered RC.10 lineage, not a full marketplace certification | Maintain narrow claim; full marketplace behavior optional |
| B2BKing | Wholesale context | Yes | No dedicated adapter | No | Not certified | Mandatory Stable 1.0 reference certification |
| Wholesale Suite | Wholesale context | Yes | No dedicated adapter | No | Not certified | Optional |
| Barn2 wholesale | Wholesale context | Yes | No dedicated adapter | No | Not certified | Optional |
| WooPayments Multi-Currency | FX/presentment provider | Yes in product design | No | No | Not certified | Select one or more adapters only after FX contract exists |
| WPML/WCML multi-currency | FX/presentment provider | Yes | No current core adapter | No | Not certified | Optional |
| FOX/WOOCS | FX/presentment provider | Yes | No | No | Not certified | Mandatory Stable 1.0 reference certification after the provider contract exists |
| CURCY | FX/presentment provider | Yes | No | No | Not certified | Optional |
| Aelia | FX/presentment provider | Yes | No | No | Not certified | Optional |
| TIV Multi Currency | FX/presentment provider | Yes | No | No | Not certified | Optional |
| Redis/object cache | Cache infrastructure | Yes, must be safe | No special dependency | Static/session tests partially relevant | Not physically certified | Optional certification; must never leak state |
| WP Rocket | Page cache | Yes, must degrade safely | Detection/status only | No real package evidence | Explicitly not certified | Owner choice; optional certification |
| VitePOS | Independent POS peer | Boundary only | Detection/status; no adapter | Detection tests only | Explicitly outside RC.11 claim | Not a Stable core dependency; separate end-to-end certification |
| Other POS | Independent commerce channel | Boundary only | No | No | None | Connected/optional adapter work |
| External PSPs | Woo/payment-plugin owned | Boundary only | No Delivery-owned PSP logic | Normal checkout tests only | Explicitly not certified | Optional compatibility qualification, not product ownership |
| Carrier APIs | Optional provider adapters | Provider-neutral target | No advanced adapter | No | Deferred | Post-1.0/future advanced integration |
| Woo Fulfillments | Optional adapter edge | Yes | Limited adapter classes/status | Partial | No broad certification | Preserve plugin-owned shipment model |
| Action Scheduler | Background work | Yes through Woo | Yes for bulk | Yes | RC line evidence | Mandatory when background modules enabled |
| GeoNames | Geography data provider | Yes, replaceable | geo.11 importer/preflight | Unit + reported official Ghana import/real DB | geo.11 only; not physically accepted | Active geo stream; provider never business identity |
| OpenStreetMap/Nominatim | Enrichment/verification only | Yes | No live client | No | None | Optional; no public client-side abuse |
| PHP | Runtime | **Certified/recommended: PHP 8.5.x.** Supported minimum: PHP ≥8.1 until a later commercial-support decision. | Yes | CI: 8.1 minimum-compatibility; 8.3; 8.4; **8.5 blocking production target** (PHPUnit, MariaDB, WP/Woo smoke) | Plugin parse/PHPUnit historically run on local PHP 8.5; RC.11/RC.12 GitHub jobs were 8.1 lint + 8.2 PHPUnit and must not be misread as CETECH production. Full WoodMart/B2BKing/FOX stack on 8.5 remains Pilot evidence. | Mandatory: distinguish production vs minimum |
| WordPress multisite | Platform variant | Not sufficiently specified | Unverified | None | None | Explicitly uncertified and not advertised as supported for Stable 1.0 |

## Certification gaps that must not be described as support proof

1. Detection is not compatibility. The Integration Status screen truthfully distinguishes installed, active, implemented and in-use states; canonical documentation must also distinguish certification.
2. geo.11 green CI and real-database evidence do not constitute Storefront/WoodMart/mobile physical acceptance or an RC release.
3. The WPML branch’s code/tests do not make WPML/WCML part of RC.11.
4. WCFM administrative isolation is not full WCFM marketplace/order/vendor certification.
5. A currency code on a Rate Card is not a certified multi-currency integration.
6. VitePOS detection is not POS checkout, order, stock or refund compatibility.
7. WooCommerce owning PSP interaction means the Delivery Engine should not claim PSP certification without a payment-adjacent checkout matrix.
8. Public repository CI does not certify proprietary plugins that were absent from the job.

## Approved Stable 1.0 certification floor

Required floor:

- supported WordPress/PHP/WooCommerce version matrix;
- HPOS;
- Classic and Blocks parity;
- Storefront plus current supported WoodMart reference version;
- B2BKing as the wholesale reference target;
- FOX/WOOCS as the multi-currency reference target after the FX contract exists;
- desktop/mobile, keyboard and screen-reader critical flows;
- clean install and RC.11 upgrade retaining data;
- no-cache and at least one representative cache/object-cache configuration if commercially feasible;
- geography stream physical qualification after geo.12 on the exact accepted package.

Conditional/optional certification set:

- WPML/WCML only if advertised as supported at launch;
- additional wholesale/B2B plugins beyond B2BKing;
- additional multi-currency providers beyond FOX/WOOCS;
- WP Rocket;
- WCFM beyond the narrow isolation claim.

POS, carrier execution, PSP-specific certification, and the whole optional-plugin universe should not block Stable 1.0 unless the owner makes a specific commercial commitment.
