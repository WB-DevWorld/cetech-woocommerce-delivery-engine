# Design ↔ Code Traceability and Implementation Inventory

Status: independent Codex read-only mapping against `PRODUCT-TRUTH-BASELINE-1`. No repository or runtime mutation was performed.

## 1. Frozen implementation anchors

| Surface | Exact state | Interpretation |
|---|---|---|
| Canonical repository | `WB-DevWorld/cetech-woocommerce-delivery-engine` | Public repository; connected account `wbdevworld` has admin permission |
| Immutable release | `v1.0.0-rc.11` annotated tag object `acaae9…`, source `384f564…`, schema 5 | Qualified release anchor; must not move or be rebuilt under the same identity |
| Protected master | `72fa354d52b49ffd9cbc32862a6b2e3d7117ea4e` | Same 829-path runtime tree as RC.11; differs only in three closeout/status documents |
| Active branch | `feat/canonical-geography-coverage` at `daef41a85662e1c1dc0aa6f5673ca9749f163a26` | 18 commits and 129 changed paths versus master; 903-path tree |
| Active identity | `1.0.0-dev.geo.11`, schema 6 | Implemented, CI-green, Draft/unmerged, rejected from physical QA |
| Active governance | issue #23; Draft PR #24 | Sole owner `@wbdevworld`; PR mergeable mechanically but blocked/governance-Draft |
| Next named work | `1.0.0-dev.geo.12`, schema 6 | Finite closure-correction pass; no RC.12 and no Stage 15 |

## 2. Release/master/active-branch delta

### RC.11 and master

RC.11 and current master contain the same runtime, schema, tests, and package logic. Master adds/updates only `CURRENT-WORK.md`, `docs/RC11-PROMOTION.md`, and `docs/STATUS_CURRENT.md`. It therefore does not constitute a new runtime candidate.

The RC.11 tree contains:

- 427 PHP source files under `src/`;
- 20 plugin-owned schema-5 tables through five forward migrations;
- 121 PHP test files with approximately 1,018 test methods;
- six Vitest files with 49 test cases;
- three Playwright specifications with 11 test declarations;
- 829 tracked paths overall.

### Active geo.11 branch

geo.11 adds 47 PHP source files, 17 PHP test files, one Vitest file, schema-6 migration and seven tables. The branch reports approximately 23,880 additions and 276 deletions across 129 paths. Its tree has 474 PHP source files, 138 PHP test files/approximately 1,190 test methods, seven Vitest files/72 cases, six migrations, 27 plugin tables, and 903 tracked paths.

Its current architecture adds:

- canonical location, alias, provider-mapping, pack, ancestry and resolution domains;
- geography pack preflight/import/staging/promotion services;
- coverage groups, members, postcode constraints, validation, matching and cleanup;
- Woo country/state bootstrap and legacy destination-rule reconciliation;
- admin and storefront geography endpoints and progressive selector changes;
- real-MariaDB test support and Ghana pack fixtures/evidence.

The owner/architect closure review confirms substantial correctness gains but blocks physical QA on the schema-6 coordinator, transitive future-parent planning, deep/ambiguous Woo text resolution, indeterminate preflight lifecycle, actual JS pagination, >500 admin/diagnostic truth, real-form last-group confirmation, cached diagnostics, two Ghana defaults, and unexecuted large scale cases.

## 3. Branch-wide inventory and classification

| Branch | Head | Classification |
|---|---|---|
| `master` | `72fa354…` | Canonical protected development baseline |
| `feat/canonical-geography-coverage` | `daef41a…` | **Active implementation**; unmerged geo.11; next geo.12 |
| `feat/post-rc9-wpml` | `3b5b60d…` | **Separate optional overlay**; 36-path WPML delta; unmerged/uncertified |
| `feat/post-rc9-customer-ux` | `d534d6a…` | Historical recovered lineage; material behavior reconciled into RC.10/RC.11 |
| `recovery/post-rc9-integrated-candidate` | `51ccd3e…` | Preserved historical integrated candidate |
| `recovery/post-rc9-cart-state` | `ad16df2…` | Preserved history; reconciled into release lineage |
| `recovery/post-rc9-blocks-line-snapshot` | `4d0fe4d…` | Preserved history; reconciled into release lineage |
| `recovery/post-rc9-per-item-context` | `093a164…` | Preserved history; reconciled into release lineage |
| `recovery/post-rc9-wcfm-isolation` | `31ea8ab…` | Preserved history; reconciled into release lineage |
| `recovery/post-rc6-admin-setup-defects` | `4ebecfb…` | Preserved historical repair lineage |
| `recovery/post-rc6-bulk-r1` | `6b86e24…` | Preserved historical bulk lineage |
| `recovery/post-rc7-fulfilment-correctness` | `c370f84…` | Preserved historical fulfillment lineage |
| `batch/pre-rc10-qualification` | `be586a4…` | Historical qualification provenance; ancestor of current line |
| `release/rc10` | `c19f23e…` | Historical release-promotion branch |
| `release/rc11` | `b397489…` | Historical release-promotion branch |
| `ops/rc11-package` | `b0eac40…` | Historical package/publication operations |
| `fix/pdp-delivery-price-display` | `ecb0a69…` | Accepted issue #18 source; incorporated into RC.11 |
| `feat/site-wide-delivery-defaults` | `5d30616…` | Historical ancestor/provenance |
| `feat/post-rc8-integrations` | `376c089…` | Historical RC.9-era ancestor |
| `docs/staff-training-rc2` | `27ebf93…` | Old divergent training-doc branch; not product authority |
| `ws3/bootstrap-control-plane` | `08a610a…` | Historical control-plane lineage |
| `ws3/reconcile-post-rc9-recovery` | `d059948…` | Historical recovery documentation |
| `ws3/fix-recovery-status-baseline` | `9e071ea…` | Historical recovery documentation |
| `ws3/reconcile-desktop-artifacts` | `c5c6395…` | Historical artifact-reconciliation documentation |
| `ws3/rc10-closeout-truth` | `3a85d52…` | Superseded RC.10 closeout branch; PR #17 closed |
| `ws3/rc11-closeout-truth` | `bc485a6…` | Incorporated RC.11 closeout source |
| `ws3/sync-current-status-2026-09-16` | `dbf90ca…` | Stale open PR #15; do not merge into current master |

No side branch contains a more authoritative current Standalone runtime than master plus the explicitly active geo.11 stream. Recovery branches are valuable provenance, not candidates to merge wholesale.

## 4. Source architecture inventory

| Layer/domain | Material implementation |
|---|---|
| Bootstrap/control | `Bootstrap/Plugin.php`, service container, runtime contracts, feature flags, activator/deactivator/uninstaller |
| Domain | Configuration, customer context, offers, fulfillment/logistics, pickup, product rule, rate card, shipments, suppliers/origins, values, zones; geo.11 adds geography/coverage |
| Application | Resolver/configuration, selectors, cart/checkout, destination match, rate quote, shipping, order snapshot, shipment operations, bulk jobs, diagnostics; geo.11 adds pack/coverage services |
| Persistence | wpdb repositories, verified forward migrations, schema descriptions, in-memory test repositories |
| Woo integration | Native selected-offer shipping method, Woo addresses/country/state, HPOS declaration, Woo fulfillments adapter edge |
| Blocks | Store API endpoint data, add-to-cart bridge, checkout validation/update, public payload, cart reselection callback |
| Presentation | WordPress-native admin pages, PDP/cart/checkout/order/email/shipment/customer renderers, scoped JS/CSS |
| Compatibility | Detection/status catalog, WCFM admin isolation; WPML remains a separate branch |

The layering is a sound modular-monolith foundation. The main architecture deficit is missing accepted domains and public contracts, not a need to replace the existing core.

## 5. Persistence and migration inventory

### Schema 5 / RC.11 tables

| Group | Tables |
|---|---|
| Configuration | `delivery_offers`, `destination_zones`, `destination_rules`, `logistics_profiles`, `suppliers`, `origins`, `pickup_locations`, `rate_cards`, `rate_card_rules`, `audit_log` |
| Legacy product rules | `product_delivery_rules` |
| Scoped configuration | `configuration_scopes`, `configuration_fields`, `configuration_collections` |
| Shipments | `shipments`, `shipment_items`, `shipment_events` |
| Bulk | `bulk_jobs`, `bulk_job_items`, `bulk_recipes` |

Migrations v1–v5 create configuration, product rules, scoped configuration, shipment and bulk tables. The runner is forward/idempotent and supports post-migration verification before bumping the stored schema version.

### Schema 6 / geo.11 additions

`geography_packs`, `geography_locations`, `geography_location_aliases`, `geography_provider_mappings`, `destination_coverage_groups`, `destination_coverage_members`, and `destination_coverage_postcodes` are additive. Existing Delivery Area IDs remain Rate Card identity. Schema-5 `destination_rules` remain compatibility authority until safe per-zone conversion.

The schema design is directionally aligned, but the geo.11 orchestration defects in PR comment `5732836980` prevent claiming migration conformance or physical-QA readiness.

### Options/background work

Options store schema/migration status, feature flags, setup progress, site defaults, configuration revision, failure/attention indexes, uninstall intent, and geo.11 upgrade/pack state. Action Scheduler backs bulk work; geo.12 may reuse it for bounded schema-6 reconciliation. Deactivation is non-destructive; uninstall deletion is explicit.

## 6. Interface inventory

| Interface | Current evidence | Conformance |
|---|---|---|
| PHP application services | Broad internal service graph | Strong internal foundation; public stability/deprecation contract absent |
| WordPress/Woo hooks | Extensive registration for product/cart/checkout/order/shipment/admin | Operationally strong; only a handful of plugin-namespaced public filters and no cataloged public contract |
| Store API | Blocks endpoint data, validation, cart updates, order snapshots | Implemented for current checkout use cases |
| General REST API | No `register_rest_route` under `src/` | Missing |
| OpenAPI/JSON Schema | None | Missing |
| WP AJAX | PDP variations/location, admin preview/progress, notice dismissal; geo adds geography endpoints | Implemented internal endpoints; not a substitute for first-class API |
| WP-CLI | `BulkJobCliCommand` for job lifecycle/import/export | Partial; broad required CLI absent |
| Domain events | Woo lifecycle subscribers only | Missing governed versioned domain-event catalog |
| Webhooks/AsyncAPI | None | Missing |

## 7. Admin and customer surfaces

RC.11 exposes Overview, Setup Guide, Delivery Settings, Site-wide Defaults, Product Exceptions, Delivery Options, Delivery Areas, Pickup Locations, Suppliers & Origins, Logistics Profiles, Delivery Charges/Rate Cards, Bulk Tools, Needs Attention, Shipments, and System Status/diagnostics. It also provides product/variation configuration panels, effective preview, admin order snapshot display, PDP selector, cart context/reselection, checkout plan, order/email summaries, and customer shipment cards.

This is materially more complete and better structured than early design prose. Missing product domains—policies, labels, promise policy, promotions/economics, API/CLI/events—lack corresponding admin/customer surfaces.

## 8. Test and qualification inventory

The committed suite covers unit behavior, limited integration harnesses, JavaScript, Playwright admin fixtures, migration inspection, Blocks/Store API, package verification, PHP 8.1 **minimum-compatibility** CI, PHP 8.5 **CETECH production-target** CI, and historical physical owner QA. geo.11 adds real-MariaDB and Ghana pack evidence. See `docs/PHP-RUNTIME-POLICY.md`.

Important boundary: this audit container could not execute PHP or JavaScript suites because PHP, Composer vendor binaries and Vitest dependencies were absent. The attempt failed before tests ran. This mapping relies on source inspection and exact-head GitHub CI evidence; it does not falsely claim a new local pass.

Current certification boundaries remain: RC.11 has no production deployment claim; WPML/WCML, WP Rocket, external PSP and POS certification are separate; geo.11 has not entered physical QA.

## 9. Requirements-to-code traceability

Every registry ID maps through its domain range below; the structured registry carries the per-ID conformance value. A range does not mean every item has identical implementation depth—the YAML record is decisive.

| Requirement IDs | Primary code/schema/tests | Mapping result and limitation |
|---|---|---|
| `DE-FAM-*`, `DE-OWN-*` | bootstrap, composer, repo/product docs, service boundaries | Aligned constitutional boundary; stale docs still mention outdated scope |
| `DE-CONFIG-*`, `DE-ECR-*` | Configuration domain/application, scoped tables, resolver/router, preview and parity tests | Aligned; protect |
| `DE-CTX-*`, `DE-GROUP-*` | CustomerContext, MatchingLocation, ShippingPackageBuilder, DeliveryGroupIdentity, per-item tests | Aligned semantic implementation; protect |
| `DE-LOG-*`, `DE-ORIGIN-*` | LogisticsProfile/Supplier/Origin domain, repositories and admin | Partial: records/assignments exist; rich constraints, privacy policy and multi-origin operations incomplete |
| `DE-GEO-*`, `DE-AREA-*` | geo.11 geography/coverage domains, seven tables, endpoints, import/migration/matcher tests | Substantial partial; unmerged and blocked by finite geo.12 list |
| `DE-OFFER-*` | DeliveryOffer domain/repository/admin, option builder/selector | Aligned core |
| `DE-PROMISE-*` | offer duration fields, estimate formatter | Partial: no complete calendars/cutoffs/capacity/policy-version engine |
| `DE-QUOTE-*` | RateQuoteRequest/Engine/Result, PDP probe, checkout rate calculator | Partial: calculation exists; lifecycle identity/fingerprint/TTL/status/economics/snapshot absent |
| `DE-PICKUP-*` | PickupLocation domain/repository/admin, cart/group/snapshot presentation | Partial: basic endpoints work; full hours/closures/capacity/disclosure/eligibility depth absent |
| `DE-JOURNEY-*` | no journey/leg aggregate | Missing accepted post-1.0 product completion |
| `DE-ECON-*`, `DE-PROMO-*` | rate-card customer amount only | Missing private economics and separate promotions |
| `DE-RATE-*` | RateCard repositories/admin, RateQuoteEngine, native Woo shipping method | Aligned current supported rate types; protect fail-closed behavior |
| `DE-FX-*`, `DE-TAX-*` | currency-code matching, Money value, offer tax class, Woo rate | FX missing; tax boundary partial |
| `DE-PDP-*` | ProductDeliverySelectorRenderer, ProductPageDeliveryPriceQuote, JS/tests, RC.11 issue #18 | Aligned and owner accepted |
| `DE-CART-*` | capture/reconcile/revalidate/reselect, Classic/Blocks validation and Store API | Aligned and owner accepted |
| `DE-SNAP-*` | OrderDeliverySnapshot V1/V2, builder/persister/presentation | Strong partial: missing first-class quote, policy and label snapshot facts |
| `DE-SHIP-*` | shipment aggregate/repositories/services/subscribers/admin/customer views, schema 4 | Aligned semantic V1 shipment foundation |
| `DE-EXC-*`, `DE-NOTIFY-*` | issue/failure stores, Needs Attention, customer email summary | Partial; full exception/reattempt and notification orchestration post-1.0 |
| `DE-RETURN-*`, `DE-REFUND-*` | no policy domains/tables/UI/contracts | Missing Stable 1.0 product foundations |
| `DE-LABEL-*` | no label taxonomy/domain/rules/snapshot | Missing Stable 1.0 product foundation |
| `DE-BULK-*` | schema 5, job service/runner/queue/admin/CLI/import/export/rollback | Aligned semantic implementation; reuse |
| `DE-RULE-*`, `DE-OVR-*` | scattered status/effective/audit fields | General rule lifecycle partial; governed override aggregate missing |
| `DE-ANALYTICS-*` | no analytics/profitability domain | Missing accepted post-1.0 capability |
| `DE-API-*` | Store API and AJAX only | First-class REST/OpenAPI missing |
| `DE-CLI-*` | BulkJobCliCommand | Partial |
| `DE-EVENT-*` | Woo subscribers and a few filters | Governed events/webhooks missing |
| `DE-DIAG-*` | ConfigurationHealthChecker, SystemStatusPage, IntegrationStatusCatalog | Strong partial; bounded/incomplete truth and public-interface monitoring gaps |
| `DE-FLAG-*` | FeatureFlags and module gates | Partial; no single documented global emergency contract/rollout model |
| `DE-DATA-*` | migrations, options, Action Scheduler, Uninstaller | Partial; comprehensive retention/GC policy absent |
| `DE-REL-*` | CI, package scripts/verifiers, immutable tags/checksums | Strong partial; commercial channel/updater/signing/SBOM decision absent |
| `DE-COMPAT-*` | integration detection, Blocks, WCFM, separate WPML branch | Design/implementation mixed; most optional physical certifications open |
| `DE-SEC-*`, `DE-PERF-*` | capabilities/nonces/validation/public-payload tests; indexes/bounded bulk/cache care | Strong foundation; geo.11 scale/orchestration and future public API abuse controls remain |
| `DE-UX-*`, `DE-GLOBAL-*` | native admin/customer renderers, i18n/scoped CSS; geo.11 selectors | Partial: policy/label surfaces absent and two GH defaults violate country neutrality |
| `DE-CONNECT-*` | no Connected runtime | Correctly Connected-only |
| `DE-DEFER-*` | no advanced execution | Correctly deferred |

## 10. Material code with no prior product requirement

No material current runtime behavior is unexplained. Later implementations that exceeded old prose were classified as `IMPLEMENTATION SUPERIOR — ADOPT` in the decision register or as compatible implementation detail:

- administrator access-recovery/self-heal;
- integration status state machine;
- detailed Needs Attention badges/indexes;
- WCFM administrative isolation;
- exact package/control-plane verifiers;
- robust per-item delivery-group identity;
- constrained fallback and selected-offer broader-area fallback.

These are compatible safeguards or UX/operations improvements, not scope drift to remove.

## 11. Documentation drift

`readme.txt` still contains early skeleton/Classic-only statements, a stale `Stable tag: 1.0.0-rc.10`, and exclusions later implemented and accepted. Several stage documents are valuable history but must not compete with the owner-approved product-control files. PR #15 is stale. Canonical documentation must preserve history while treating this product constitution, structured registry, release scope, traceability and compatibility matrix as current authority for their assigned truth classes.

## 12. Codex completion statement

Codex performed the Phase 4 mapping directly in read-only mode: repository trees, diffs, schema, services, hooks, interfaces, UI surfaces and tests were inspected across RC.11/master, geo.11 and the material WPML/provenance streams. No code or GitHub mutations occurred. Mappings are complete at stable-ID domain granularity with per-ID status in `CAPABILITY-REGISTRY.yaml`; ambiguous implementation claims are represented as partial or certification gaps rather than guessed as complete.
