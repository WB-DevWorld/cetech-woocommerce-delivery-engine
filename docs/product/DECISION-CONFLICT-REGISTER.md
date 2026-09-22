# Decision, Conflict & Negative-Invariant Register

Status: owner accepted and frozen as `PRODUCT-TRUTH-BASELINE-1` on 2026-09-19. Decisions are reconstructed from supplied authoritative product history, repository authority documents, live GitHub chronology, and the six explicit owner resolutions recorded below.

## Authority rule

When evidence conflicts, use: newer explicit owner instruction → immutable repository/release evidence → current repository status/control-plane documents → accepted product/architecture decisions → current workstream evidence → qualification history → older project exports → assumptions. Historical material is retained with a disposition; it is not erased.

## Current decisive decisions

| Decision | Current resolution | Governing IDs |
|---|---|---|
| Product family | Standalone and Connected are two separate public products, repos, builds, releases, and engines. | `DE-FAM-*`, `DE-CONNECT-*` |
| Hard dependency | WooCommerce only; all other themes/plugins/apps are optional adapters or certification targets. | `DE-FAM-005`, `DE-COMPAT-*` |
| Product identity | A reusable fulfillment/delivery/shipping/pickup/promise/pricing/shipment-operations platform, not a flat-rate plugin. | Constitution; `DE-OFFER`, `DE-QUOTE`, `DE-SHIP` |
| Operational authority | Woo owns commerce; Delivery Engine owns delivery-domain truth. | `DE-OWN-*` |
| Configuration | Field-level `GLOBAL → PRODUCT → VARIATION` with tri-state inheritance and effective source. | `DE-CONFIG-*`, `DE-ECR-*` |
| Shipping integration | Genuine WooCommerce shipping method/rate, server monetary authority, fail closed for managed packages. | `DE-RATE-*`, `DE-CART-008` |
| Geography | Plugin-owned canonical IDs/ancestry, replaceable packs/provider mappings, coverage groups. | `DE-GEO-*`, `DE-AREA-*` |
| Policy model | Return Policy and Refund Policy are independent versioned systems. | `DE-RETURN-*`, `DE-REFUND-*` |
| Product promise | Labels and promise are current product capabilities; labels never become truth. | `DE-LABEL-*`, `DE-PROMISE-*` |
| Interface model | PHP API, WP hooks, REST/Store API/OpenAPI, WP-CLI, and events/webhooks share application services. | `DE-API-*`, `DE-CLI-*`, `DE-EVENT-*` |
| Advanced execution | POD/OTP/QR/GPS/photo/driver/live-carrier execution is deferred. | `DE-DEFER-*` |
| Release truth | Implemented, CI-green, accepted, merged, released, and deployed are separate states. | `DE-REL-*` |
| PHP production vs minimum | Supported/certified range is PHP 8.3 through 8.5.x. Minimum supported PHP is 8.3 (WordPress + WooCommerce recommended floor). Recommended production PHP is the latest qualified stable release; currently qualified latest stable is PHP 8.5.x. PHP 8.1 and 8.2 are not supported. PHP 8.6 pre-release must not be used. Historical CI job names are not commercial support. | Compatibility matrix; `docs/PHP-RUNTIME-POLICY.md` |
| Stable 1.0 policy floor | Independent Return Policy and Refund Policy foundations are required before Stable 1.0. | `DE-RETURN-*`, `DE-REFUND-*` |
| Stable 1.0 economics floor | Customer charge, promotions/subsidy, and private economics are separated at schema/contract level; deeper reconciliation, allocation, and analytics may continue after 1.0. | `DE-PROMO-*`, `DE-ECON-*`, `DE-ANALYTICS-*` |
| Commercial channel and name | Use an independent direct commercial distribution/update/licensing mechanism; WordPress.org is not required; replace the CETECH working name before the main Stable 1.0 commercial release. | `DE-REL-*`; Constitution |
| Stable 1.0 certification floor | Prioritize WooCommerce/HPOS, Classic, Blocks, Storefront, current supported WoodMart, B2BKing, and FOX/WOOCS; WPML/WCML is launch-claim conditional. | `DE-COMPAT-*` |
| First production Pilot | CETECH remains first. The geo technical-closure/physical-QA prerequisite and RC.12 publication have since completed, but CETECH Pilot is still **NOT STARTED** and requires a separate explicit deployment authorization. No RC.13 is implied. | Release scope; realignment plan; current status |

## Material chronology and supersessions

| Earlier position | Later/current evidence | Disposition |
|---|---|---|
| Air/Sea were sometimes represented through product or variation modeling. | Later architecture defines route/service/offer as separate delivery-domain facts. | **Superseded**; never revive variation-as-transport-mode. |
| Early releases described a skeleton or Classic-only scope. | RC.11 includes Classic, Blocks, scoped configuration, rates, per-item grouping, snapshots, bulk and shipments. | Historical status only; `readme.txt` retains stale introductory copy. |
| Early V1 exclusions listed shipments/tracking/Blocks/bulk as out of scope. | These capabilities were later implemented, owner-qualified, and promoted through RC.5–RC.11. | **Superseded by accepted implementation**; documentation must be reconciled. |
| A monolithic Connected/default ecosystem concept appeared in older history. | Explicit product-family decision establishes two independent public products. | **Superseded**. |
| Generic “REST/CLI later” treatment. | Later explicit API/OpenAPI, WP-CLI, events/webhooks requirements mark them current product requirements. | **Superseded**; these are Stable 1.0 foundation gaps. |
| Return/refund text as generic delivery copy. | Later addendum establishes independent versioned Return Policy and Refund Policy engines. | **Superseded**. |
| Fulfillment labels treated as optional presentation polish. | Later Standalone handover makes Fulfillment Labels & Product Promise explicit current scope. | **Superseded**. |
| Customer delivery cost was the main monetary model. | Later economics decision separates charge, estimate, actual cost, payable, subsidy, margin, allocation. | Earlier model is incomplete, not wrong where used. |
| PR #24 original body said architecture baseline only. | PR has 18 commits, 129 changed files, `geo.11` head and a full closure review. | Historical body statement; current comments/branch win. |
| geo.1–geo.10 were successive green candidates. | Each was explicitly rejected from physical QA by later technical review. | Frozen rejected evidence; identities must not be reused. |
| geo.11 handoff claimed technical stabilization complete. | Owner/architect whole-branch comment `5732836980` found a finite geo.12 correction set. | **Superseded for readiness**; geo.11 remains implemented/unaccepted. |
| PR #15 proposed status synchronization from an older baseline. | Master and later RC.10/RC.11 closeout evidence supersede it. | Stale; do not merge. |
| WPML branch contains a substantial adapter. | Current RC.11 certification boundary says WPML/WCML is separate and unmerged. | Valid optional side stream, not current release truth. |
| Public repository visibility might imply open source. | Composer/license and status docs retain proprietary licensing. | Public visibility is not a license grant. |

## Superior implementation register

These are later implementations that should be adopted into canonical design wording rather than regressed to older prose.

| Implementation | Why superior | Evidence | Action |
|---|---|---|---|
| Genuine Woo shipping method plus managed-package exclusivity | Preserves Woo zone/rate/tax/order compatibility and prevents silent method substitution. | `SelectedOfferShippingIntegration`, `SelectedOfferShippingMethod`, shipping tests | Protect; update older flat/custom-fee wording. |
| Server-authoritative PDP price with quantity parity | Gives useful product-page choice without moving price authority into JS. | issue #18, RC.11, `ProductPageDeliveryPriceQuote` | Protect. |
| Versioned immutable order snapshot V2 with per-item context | Preserves historical customer choice and multi-destination facts better than older package-only prose. | `OrderDeliverySnapshot*`, Blocks/Classic tests | Adopt into canonical snapshot spec; extend for policy/label/quote IDs. |
| Real per-item customer context and delivery-group identity | Supports mixed destinations/pickup and avoids cart-line leakage. | `CustomerCartContext`, `DeliveryGroupIdentity`, recovered RC.10 lineage | Protect. |
| Constrained fallback and broader-area selected-offer pricing fallback | Preserves fail-closed geography while allowing legitimate broader pricing. | destination matcher and shipping fallback tests | Protect and document precisely. |
| Shipment aggregate with idempotent creation, events, COD/refund attention paths | Stronger provider-neutral operational foundation than early V1 exclusions. | schema 4 and shipment service/test suite | Protect; extend, do not replace. |
| Durable bulk job engine with Action Scheduler, preview, target results, rollback fingerprints | Better operational safety and scale than synchronous import prose. | schema 5 bulk system/tests | Protect and reuse for packs/policies. |
| Administrator access recovery independent of subordinate capability configuration | Prevents lockout without broadening ordinary permissions. | `AdministratorAccessRecovery` and tests | Protect. |
| Integration status states instead of blind enable toggles | Separates detected/installed/implemented/in-use/certified truth. | `IntegrationStatusCatalog` | Adopt into compatibility registry. |
| Canonical geography staged-generation architecture | Provider-neutral identity and atomic activation are superior to free-text city matching. | geo.11 schema/domain and issue #23 | Protect architecture; complete geo.12 correctness fixes. |

## Design-wins realignment register

| Gap/conflict | Governing requirements | Required direction |
|---|---|---|
| Computed `RateQuoteResult` is not a first-class lifecycle quote. | `DE-QUOTE-*` | Add quote identity/fingerprint/TTL/status/policy/economics/snapshot contracts without replacing the rate calculator. |
| No independent Return Policy or Refund Policy domains. | `DE-RETURN-*`, `DE-REFUND-*` | Add separate versioned systems with inheritance and snapshots. |
| No Fulfillment Labels & Product Promise module. | `DE-LABEL-*` | Add label taxonomy, rules/schedule, surfaces, structured wait data and snapshots. |
| ETA fields lack a full promise policy engine. | `DE-PROMISE-*` | Add calendars, cutoffs, capacity, policy versions and multi-component calculation. |
| Only customer charges are materially modeled. | `DE-ECON-*`, `DE-PROMO-*` | Add private economics and separate promotions/subsidies. |
| Store API/AJAX exist but first-class REST/OpenAPI does not. | `DE-API-*` | Define contracts and route all interfaces through shared services. |
| WP-CLI covers bulk jobs only. | `DE-CLI-*` | Expand governed CLI across diagnostics, quote, geography, policies, shipments, jobs and maintenance. |
| No governed events/webhooks. | `DE-EVENT-*` | Introduce versioned events and signed, retryable webhooks. |
| No provider-neutral FX conversion/provenance. | `DE-FX-*` | Add adapter contract and convert-once snapshot semantics. |
| geo.11 known closure defects. | `DE-GEO-*`, `DE-AREA-*` | Historical correction boundary. The bounded geography closure later completed through accepted geo.16 / PR #24 and RC.12 promotion; broader Stable 1.0 realignment remains separate. |

## Negative-invariant register and audit result

| Invariant | Current result | Evidence/qualification |
|---|---|---|
| No CETECH V4 or Connected dependency | PASS | No peer runtime dependencies in RC.11/geo.11. |
| WooCommerce only hard application dependency | PASS | Plugin bootstrap/composer; optional integration registry. |
| No second merchandise price/tax/payment/refund/inventory authority | PASS WITH BOUNDARY DOC GAP | Delivery rate is separate; tax/FX boundary needs canonical docs. |
| No label as transactional truth | PASS BY ABSENCE | Label module missing; future work must preserve invariant. |
| No silent free delivery or method substitution | PASS | Managed-package exclusivity and fail-closed rate paths. |
| No browser monetary authority | PASS | Server quote/rate services; JS renders results. |
| No private supplier/origin/rate/margin leakage | PASS IN INSPECTED PUBLIC PAYLOADS | Existing payload tests; future APIs require fresh threat review. |
| No hard-coded Ghana/global provider assumption | **VIOLATION IN GEO.11** | Location Packs default and migration warning fallback to `GH`; geo.12 item. |
| No hard WoodMart/WPML/WCFM/B2B/FOX/POS dependency | PASS | Optional detection/adapters; WPML unmerged. |
| No internal multi-leg machinery forced into customer UI | PASS FOR CURRENT CORE | Multi-leg engine not implemented; customer grouping language is simplified. |
| No unknown price after payment | PASS FOR CURRENT CORE | Checkout validates/fails closed. |
| No historical snapshot reconstruction | PASS FOR IMPLEMENTED FIELDS | Snapshot fields immutable; missing policy/label/quote completeness remains. |
| No destructive update/deactivation | PASS WITH RETENTION GAP | Forward migrations, non-destructive deactivation; full retention/GC policy missing. |
| No unbounded large import/request | **PARTIAL/ACTIVE DEFECT** | Bulk jobs bounded; geo.11 migration coordinator and UI pagination remain incomplete. |
| No Connected UI/credentials/storage in Standalone | PASS | No Connected implementation in repo. |

## Owner decisions resolved on 2026-09-19

| # | Decision | Recorded resolution |
|---:|---|---|
| 1 | Stable 1.0 policy depth | Include the minimum independent Return Policy and Refund Policy foundations defined by the registry. |
| 2 | Stable 1.0 economics depth | Require schema/contract separation of customer delivery charge, promotions/subsidy, and private delivery economics. Deeper cost reconciliation, allocation, and analytics may continue after 1.0. |
| 3 | Distribution/update channel | CETECH is the first production Pilot customer. The commercial product will use an independent direct commercial distribution/update/licensing mechanism; WordPress.org is not required. |
| 4 | Compatibility certification | Before Stable 1.0 prioritize WooCommerce/HPOS, Classic, Blocks, Storefront, current supported WoodMart, B2BKing, and FOX/WOOCS. Certify WPML/WCML before 1.0 only if advertised as supported at launch. Keep other optional targets explicitly uncertified. |
| 5 | Brand and public naming | “CETECH WooCommerce Delivery Engine” remains an internal/working name. Rename the externally commercialized product independently of CETECH before the main Stable 1.0 commercial release. |
| 6 | Post-geo release | Original decision: after geography technical closure and physical QA, proceed to a separately controlled CETECH Pilot/release candidate before all Stable 1.0 realignment. Execution update: geo closure and RC.12 publication are complete; Pilot remains not started and any future RC.13/release/deployment requires a separate explicit control decision. |
| 7 | PHP production vs minimum (2026-09-21, superseded by decision 8) | Historical first draft: CETECH production PHP 8.5.x with PHP 8.1 retained as commercial minimum. **Superseded the same day.** Do not treat this row as current support. |
| 8 | PHP recommended-floor support range (2026-09-21) | Supported/certified PHP is 8.3 through 8.5.x. Minimum supported PHP is 8.3 — the oldest version currently recommended by both WordPress and WooCommerce, not the oldest those products can still boot. CETECH production / currently qualified latest stable is PHP 8.5.x (latest stable patch at deploy; 8.5.10 as of this decision). PHP 8.1 and 8.2 are not supported and must not be advertised. PHP 8.6 pre-release must not be used as production. A later stable PHP line is added only after WordPress, WooCommerce, Delivery Engine, and CETECH stack qualification. Durable required CI checks must use truthful names. |

No frozen Requirement IDs were renumbered. Decision 8 (PHP recommended-floor support range, 2026-09-21) is a later explicit owner instruction recorded after `PRODUCT-TRUTH-BASELINE-1` and supersedes decision 7 from the same day.
