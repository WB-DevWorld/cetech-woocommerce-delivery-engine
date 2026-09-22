# Stable Standalone 1.0 Scope Reconciliation

Status: owner-approved `STABLE-1.0-SCOPE-1`. It does not declare RC.11, RC.12, any post-RC.12 development build, or any future build to be Stable 1.0.

**Current execution note — 2026-09-22:** Sections 1–2 below record the pre-RC.12 gates that existed when this scope was frozen. Those geography/RC.12 gates are now complete: Issue #23/PR #24 closed, RC.12 published and immutable, and post-RC.12 fixes through Issue #32 are merged on protected master. CETECH Pilot remains not started. The Stable-1.0 capability requirements in Section 3 and later remain authoritative unless separately amended by the owner.

## Executive boundary

Stable Standalone 1.0 is a commercially shippable WooCommerce delivery product with truthful product/cart/checkout/order behavior, canonical destination coverage, durable configuration and snapshots, core shipment operations, versioned quote/promise/policy contracts, governed automation interfaces, migration/data safety, and an explicit distribution/support posture.

Stable 1.0 is **not** the complete ultimate platform. Rich multi-leg execution, advanced operations/analytics, full carrier automation, POD/OTP/QR/GPS/photo/driver workflows, and Connected peer orchestration do not all belong before 1.0.

## 1. Required before the current active stream closes

Applies to `DE-GEO-*` and `DE-AREA-*`:

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

## 2. Required before the controlled CETECH Pilot/release candidate

After geo.12 technical closure and physical owner QA, create a controlled CETECH production Pilot/release candidate before waiting for all Stable 1.0 realignment work. The exact version name remains a separate release-control choice; this decision does not assume or create RC.12.

- merge only an accepted geo package through normal protected-branch governance;
- reconcile current-status/readme/version-support documentation;
- rerun full clean-install and RC.11 retained-data upgrade qualification;
- verify package contents, checksum, lint, CI, and exact source SHA;
- record the release’s deliberately included scope and certification boundaries;
- do not promote a release merely because PR #24 merges.

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

## 9. What RC.11 already satisfies

RC.11 is a strong release-candidate baseline for:

- Woo-only modular plugin bootstrap and governance;
- schema-5 forward migrations and explicit uninstall;
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
6. Create a controlled CETECH production Pilot/release candidate after geo.12 technical closure and physical owner QA, without assuming RC.12.

Future scope changes require an explicit decision record and corresponding registry update without renumbering Requirement IDs.
