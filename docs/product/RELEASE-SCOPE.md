# Stable Standalone 1.0 Scope Reconciliation

Status: owner-approved `STABLE-1.0-SCOPE-1`. It does not declare RC.12, current protected master, or any development build to be Stable 1.0. The 2026-09-19 product-scope decisions remain frozen; execution status below is reconciled to 2026-09-22.

## Executive boundary

Stable Standalone 1.0 is a commercially shippable WooCommerce delivery product with truthful product/cart/checkout/order behavior, canonical destination coverage, durable configuration and snapshots, core shipment operations, versioned quote/promise/policy contracts, governed automation interfaces, migration/data safety, and an explicit distribution/support posture.

Stable 1.0 is **not** the complete ultimate platform. Rich multi-leg execution, advanced operations/analytics, full carrier automation, POD/OTP/QR/GPS/photo/driver workflows, and Connected peer orchestration do not all belong before 1.0.

## 1. Completed prerequisite — canonical geography/coverage closure

Applies to `DE-GEO-*` and `DE-AREA-*`. The following was the audit-time closure checklist. It was subsequently completed through the accepted geo.16 line, PR #24, RC.12 promotion, and follow-up geography hardening; retain it as acceptance provenance:

- build `1.0.0-dev.geo.12` from the finite PR #24 closure list;
- correct the schema-6 upgrade/reconciliation coordinator and prove owner-fenced bounded execution on real MariaDB;
- close transitive future-parent planning and deep/ambiguous exact Woo text resolution;
- make indeterminate pack preflight resumable rather than failed;
- implement real storefront/admin child pagination including saved selections beyond page one;
- remove >500 admin/rate/diagnostic false-truth paths;
- fix real-form last-group confirmation, cached diagnostics, and Ghana defaults;
- execute the >10,000-draft and >1,000-root cases on a performant real database;
- run one differential and one whole-branch closure review;
- if technically clean, perform physical Storefront/WoodMart/mobile, Classic/Blocks and RC.11-upgrade QA on the exact package;
- obtain explicit owner acceptance before merge.

No policy/API/label/economics realignment should be added to PR #24.

## 2. Next production-facing gate — controlled CETECH Pilot

The geography technical-closure and physical-QA prerequisite is complete and RC.12 has already been published. CETECH Pilot is still **NOT STARTED**. A Pilot build/deployment requires a separate explicit owner/release-control authorization; this scope document does not create RC.13 or authorize deployment.

- branch the Pilot candidate only from an explicitly authorized protected-master source;
- reconcile current-status/readme/version-support documentation for that exact Pilot candidate;
- rerun clean-install and retained-data upgrade qualification from the supported predecessor path;
- verify package contents, checksum, lint, CI, exact source SHA, rollback, and environment preconditions;
- record the Pilot’s deliberately included scope and certification boundaries;
- do not infer a new release or RC.13 merely because current master is green.

Broader product realignment may continue after the controlled geo-based Pilot/release candidate. That candidate is not Stable 1.0, and deployment remains a separately controlled action.

## 3. Required before Stable 1.0

### Preserve and qualify the implemented foundation

- `DE-CONFIG-*`, `DE-ECR-*`: scoped configuration and single resolver;
- `DE-CTX-*`, `DE-GROUP-*`: normalized/per-item commerce context and grouping;
- `DE-OFFER-*`, `DE-RATE-*`: delivery offers, deterministic Rate Cards, native Woo rates and fail-closed behavior;
- `DE-PDP-*`, `DE-CART-*`: authoritative PDP price and Classic/Blocks cart/checkout parity;
- `DE-SHIP-*`: provider-neutral shipment V1;
- `DE-BULK-*`: durable bulk operations;
- `DE-GEO-*`, `DE-AREA-*`: accepted canonical geography/coverage;
- complete immutable snapshot coverage for every included 1.0 contract.

### Complete currently accepted product foundations

| Scope | Requirement IDs | Minimum Stable 1.0 outcome |
|---|---|---|
| First-class quote lifecycle | `DE-QUOTE-*` | Quote ID/fingerprint, material context, created/expiry/status, TTL, freeze/revalidation, policy/version and snapshot contract; reuse current rate calculator |
| Service/promise policy | `DE-PROMISE-*` | Versioned service policy, processing/transit/final-mile, calendars/closures/cutoffs, truthful localized ETA; capacity contract may be bounded initially |
| Return Policy | `DE-RETURN-*` | Independent versioned records, inheritance, admin, summary/details surfaces, optional acknowledgement contract, immutable snapshot |
| Refund Policy | `DE-REFUND-*` | Same independent foundation, never collapsed into Return Policy |
| Fulfillment Labels & Product Promise | `DE-LABEL-*` | Taxonomy, multiple labels, inheritance, rules/schedule, configured surfaces, structured wait data, snapshot |
| Promotions/economics foundation | `DE-PROMO-*`, `DE-ECON-*` | Schema/contract separation of base charge, promotion/subsidy, and private cost/payable/margin components; deeper reconciliation and allocation may follow post-1.0 |
| Pickup/logistics/origin completion | `DE-PICKUP-*`, `DE-LOG-*`, `DE-ORIGIN-*` | Stable endpoint schedules/readiness/disclosure and the constraint/privacy facts used by included quote/promise flows |
| Multi-currency boundary | `DE-FX-*`, `DE-TAX-*` | Provider contract, convert-once invariant, currency provenance/snapshot, explicit tax/duty boundary; not every provider certified |
| Governed interfaces | `DE-API-*`, `DE-CLI-*`, `DE-EVENT-*` | Versioned core OpenAPI/JSON Schema, broad operational WP-CLI, stable hooks/events; webhook framework for externally delivered events included in 1.0 scope |
| Rules and safety | `DE-RULE-*`, `DE-DIAG-*`, `DE-FLAG-*`, `DE-DATA-*`, `DE-SEC-*`, `DE-PERF-*` | Versioned/effective rule lifecycle, truthful diagnostics, emergency disable, retention/cleanup, public-interface protection, bounded scale |
| Productization | `DE-REL-*` | Chosen distribution/update/licensing posture, support matrix, immutable reproducible package/checksum, rollback and release channels |

### Stable 1.0 certification floor

- HPOS, Classic and Blocks;
- supported PHP/WordPress/WooCommerce versions;
- Storefront and an owner-selected current WoodMart reference;
- B2BKing wholesale reference certification;
- FOX/WOOCS multi-currency reference certification after the FX contract exists;
- critical desktop/mobile/accessibility flows;
- fresh install and RC.11-to-final upgrade/data retention;
- geo accepted package and migration/physical evidence;
- precise compatibility claims from `COMPATIBILITY-CERTIFICATION.md`.

WPML/WCML joins this floor only if it will be advertised as supported at launch.

## 4. Accepted post-1.0 product completion

These are part of the ultimate Standalone product but should not automatically block a coherent 1.0:

- rich zero/one/many-leg journey execution and per-leg operations (`DE-JOURNEY-*`);
- advanced delivery exception, reattempt and return-to-origin workflows (`DE-EXC-*`);
- multi-channel notification orchestration with durable retries (`DE-NOTIFY-*`);
- general governed manual-override/approval workflows (`DE-OVR-*`);
- operational analytics and profitability dashboards (`DE-ANALYTICS-*`);
- advanced capacity reservation and route/provider detail beyond the 1.0 quote contract;
- deeper economics reconciliation and shared-leg allocation UI;
- additional adapters and compatibility breadth after the first supported references.

This classification does not permit the 1.0 schema/contracts to make these capabilities impossible. Foundations must be extensible.

## 5. Optional modules

- additional carrier/provider adapters;
- map/routing enrichment;
- optional detailed multi-leg customer presentation;
- extended analytics/export packages;
- environment-specific operational connectors;
- licensed plugin adapters not chosen for the certification floor.

Optional modules must not become core dependencies.

## 6. Optional certifications

- WPML/WCML when it is not advertised as launch-supported;
- WP Rocket and additional cache stacks;
- Wholesale Suite, Barn2, and other wholesale targets beyond B2BKing;
- WCFM beyond the current narrow isolation/context claim;
- individual multi-currency providers beyond FOX/WOOCS;
- POS and external PSP end-to-end combinations;
- broader theme matrix.

## 7. Future advanced integrations / explicitly deferred

`DE-DEFER-*`:

- POD execution and buyer confirmation;
- OTP, QR, GPS, photo capture;
- driver accounts and driver apps;
- live carrier quotes, booking/dispatch, labels and automatic tracking sync;
- automatic Woo order completion from delivery events;
- courier marketplace, supplier portal, and warehouse scanning.

These do not block Stable 1.0 and must not be pulled into the active geo stream.

## 8. Connected-only

`DE-CONNECT-*` belongs only to the separate Connected product: Invordex, Alcide, HubLoft, AIM PIM, Suproma, GeoMesh, ReLoop, AccessLobby, MoneyMove, DonLoft, DataPlane and other peer orchestration. Shared contracts may be developed independently, but peer runtime dependencies/UI do not enter Standalone.

## 9. What RC.12 and the current post-RC.12 baseline already satisfy

RC.12, plus merged post-RC.12 hardening through Issue #32, is a strong non-Stable-1.0 baseline for:

- Woo-only modular plugin bootstrap and governance;
- schema-6 forward migrations, canonical geography/coverage foundations, and explicit uninstall;
- scoped configuration and effective resolver;
- core delivery offers, legacy delivery areas, logistics/supplier/origin/pickup records and Rate Cards;
- authoritative product-page price, cart persistence/reselection, Classic and Blocks validation;
- per-item/multi-destination grouping and native Woo shipping rates;
- immutable delivery snapshot foundation;
- shipment records, staff/customer status and tracking V1;
- durable bulk tools;
- WordPress-native admin, diagnostics, feature flags and access recovery;
- fail-closed and public-payload privacy safeguards.

It does **not** satisfy Stable 1.0 merely because those areas are mature. The missing accepted foundations above remain material.

## 10. Resolved scope decisions

The owner froze `STABLE-1.0-SCOPE-1` on 2026-09-19:

1. Include the minimum independent Return Policy and Refund Policy foundations.
2. Require economics separation at schema/contract level; allow deeper reconciliation, shared-leg allocation, and analytics after 1.0.
3. Use an independent direct commercial distribution/update/licensing mechanism; WordPress.org is not required.
4. Certify WooCommerce/HPOS, Classic, Blocks, Storefront, current supported WoodMart, B2BKing, and FOX/WOOCS; make WPML/WCML conditional on a launch support claim.
5. Replace the CETECH working name before the main Stable 1.0 commercial release.
6. CETECH remains the first controlled production Pilot. Geography closure and RC.12 publication are complete; Pilot itself remains separately authorized and must not be inferred from RC.12 or current master. Any RC.13 decision is separate.

Future scope changes require an explicit decision record and corresponding registry update without renumbering Requirement IDs.
