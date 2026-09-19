# Product & Capability Master Registry

Status: `PRODUCT-TRUTH-BASELINE-1`; owner accepted on 2026-09-19 and all 372 stable IDs are frozen. The structured source of truth is `CAPABILITY-REGISTRY.yaml`.

## Counts

- Atomic requirements: **372**
- Domains: **47**
- Unexplained accessible authoritative source blocks: **0**
- IDs may be split, merged, or superseded only through explicit decision records; they must not be renumbered.

| Conformance state | Count |
|---|---:|
| ALIGNED EXACT | 30 |
| ALIGNED SEMANTICALLY EQUIVALENT | 52 |
| CERTIFICATION GAP | 7 |
| CONNECTED ONLY | 7 |
| DEFERRED | 7 |
| DESIGN SUPERIOR REALIGN CODE | 3 |
| IMPLEMENTATION SUPERIOR ADOPT | 6 |
| INTENTIONALLY NON CODE | 6 |
| MISSING IMPLEMENTATION | 90 |
| PARTIAL IMPLEMENTATION | 164 |

These are evidence classifications, not completion percentages. `PARTIAL IMPLEMENTATION` includes domains with a strong current foundation but material missing contracts, and active geo.11 work that is implemented but unmerged and still blocked from physical QA.

## Domain index

| Domain | ID range | Requirements | Predominant disposition |
|---|---|---:|---|
| Product family | DE-FAM-001–DE-FAM-006 | 6 | INTENTIONALLY NON CODE |
| Authority boundaries | DE-OWN-001–DE-OWN-006 | 6 | ALIGNED SEMANTICALLY EQUIVALENT |
| Scoped configuration | DE-CONFIG-001–DE-CONFIG-008 | 8 | ALIGNED EXACT |
| Effective Configuration Resolver | DE-ECR-001–DE-ECR-006 | 6 | ALIGNED EXACT |
| Commerce context | DE-CTX-001–DE-CTX-006 | 6 | ALIGNED SEMANTICALLY EQUIVALENT |
| Logistics profiles | DE-LOG-001–DE-LOG-006 | 6 | PARTIAL IMPLEMENTATION |
| Suppliers and origins | DE-ORIGIN-001–DE-ORIGIN-006 | 6 | PARTIAL IMPLEMENTATION |
| Canonical geography | DE-GEO-001–DE-GEO-015 | 15 | PARTIAL IMPLEMENTATION |
| Delivery Areas and coverage | DE-AREA-001–DE-AREA-012 | 12 | PARTIAL IMPLEMENTATION |
| Delivery offers | DE-OFFER-001–DE-OFFER-008 | 8 | ALIGNED SEMANTICALLY EQUIVALENT |
| Service and promise | DE-PROMISE-001–DE-PROMISE-010 | 10 | PARTIAL IMPLEMENTATION |
| Quote lifecycle | DE-QUOTE-001–DE-QUOTE-012 | 12 | PARTIAL IMPLEMENTATION |
| Pickup intelligence | DE-PICKUP-001–DE-PICKUP-010 | 10 | PARTIAL IMPLEMENTATION |
| Multi-leg journeys | DE-JOURNEY-001–DE-JOURNEY-010 | 10 | MISSING IMPLEMENTATION |
| Internal economics | DE-ECON-001–DE-ECON-008 | 8 | MISSING IMPLEMENTATION |
| Rate cards | DE-RATE-001–DE-RATE-008 | 8 | ALIGNED SEMANTICALLY EQUIVALENT |
| Delivery promotions | DE-PROMO-001–DE-PROMO-007 | 7 | MISSING IMPLEMENTATION |
| Multi-currency | DE-FX-001–DE-FX-006 | 6 | MISSING IMPLEMENTATION |
| Tax boundary | DE-TAX-001–DE-TAX-004 | 4 | PARTIAL IMPLEMENTATION |
| Product page | DE-PDP-001–DE-PDP-008 | 8 | ALIGNED EXACT |
| Cart and checkout | DE-CART-001–DE-CART-008 | 8 | ALIGNED EXACT |
| Grouping and multi-destination | DE-GROUP-001–DE-GROUP-007 | 7 | ALIGNED SEMANTICALLY EQUIVALENT |
| Order snapshots | DE-SNAP-001–DE-SNAP-008 | 8 | PARTIAL IMPLEMENTATION |
| Shipment operations | DE-SHIP-001–DE-SHIP-010 | 10 | ALIGNED SEMANTICALLY EQUIVALENT |
| Delivery exceptions | DE-EXC-001–DE-EXC-005 | 5 | PARTIAL IMPLEMENTATION |
| Notifications | DE-NOTIFY-001–DE-NOTIFY-004 | 4 | PARTIAL IMPLEMENTATION |
| Return Policy | DE-RETURN-001–DE-RETURN-010 | 10 | MISSING IMPLEMENTATION |
| Refund Policy | DE-REFUND-001–DE-REFUND-010 | 10 | MISSING IMPLEMENTATION |
| Fulfillment labels | DE-LABEL-001–DE-LABEL-009 | 9 | MISSING IMPLEMENTATION |
| Bulk tools | DE-BULK-001–DE-BULK-008 | 8 | ALIGNED SEMANTICALLY EQUIVALENT |
| Business rules | DE-RULE-001–DE-RULE-007 | 7 | PARTIAL IMPLEMENTATION |
| Manual overrides | DE-OVR-001–DE-OVR-006 | 6 | MISSING IMPLEMENTATION |
| Analytics | DE-ANALYTICS-001–DE-ANALYTICS-005 | 5 | MISSING IMPLEMENTATION |
| REST/OpenAPI/PHP/Store API | DE-API-001–DE-API-012 | 12 | MISSING IMPLEMENTATION |
| WP-CLI | DE-CLI-001–DE-CLI-009 | 9 | PARTIAL IMPLEMENTATION |
| Events and webhooks | DE-EVENT-001–DE-EVENT-007 | 7 | MISSING IMPLEMENTATION |
| Diagnostics | DE-DIAG-001–DE-DIAG-007 | 7 | PARTIAL IMPLEMENTATION |
| Feature flags | DE-FLAG-001–DE-FLAG-005 | 5 | PARTIAL IMPLEMENTATION |
| Data lifecycle | DE-DATA-001–DE-DATA-007 | 7 | PARTIAL IMPLEMENTATION |
| Release/productization | DE-REL-001–DE-REL-009 | 9 | PARTIAL IMPLEMENTATION |
| Compatibility | DE-COMPAT-001–DE-COMPAT-012 | 12 | CERTIFICATION GAP |
| Security/privacy | DE-SEC-001–DE-SEC-010 | 10 | PARTIAL IMPLEMENTATION |
| Performance/scalability | DE-PERF-001–DE-PERF-009 | 9 | PARTIAL IMPLEMENTATION |
| UX/accessibility/SEO | DE-UX-001–DE-UX-008 | 8 | PARTIAL IMPLEMENTATION |
| Country neutrality | DE-GLOBAL-001–DE-GLOBAL-005 | 5 | PARTIAL IMPLEMENTATION |
| Connected-only ownership | DE-CONNECT-001–DE-CONNECT-007 | 7 | CONNECTED ONLY |
| Deferred advanced scope | DE-DEFER-001–DE-DEFER-006 | 6 | DEFERRED |

## Atomic requirements

Release-intent and conformance values are machine-stable enum-like labels from the YAML registry.

### Product family (FAM)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-FAM-001 | Standalone is an independent WooCommerce product, repository, build, release, and commercial identity. | REQUIRED BEFORE STABLE 1 0 | INTENTIONALLY NON CODE |
| DE-FAM-002 | Connected is a separate public product, repository, build, release, and engine. | REQUIRED BEFORE STABLE 1 0 | INTENTIONALLY NON CODE |
| DE-FAM-003 | Standalone and Connected may share behavioral specifications and versioned contracts but not runtime identity or storage authority. | REQUIRED BEFORE STABLE 1 0 | INTENTIONALLY NON CODE |
| DE-FAM-004 | CETECH V4 may consume Standalone but must never be a Standalone dependency. | REQUIRED BEFORE STABLE 1 0 | INTENTIONALLY NON CODE |
| DE-FAM-005 | Standalone core must operate with WooCommerce as its only hard application dependency. | REQUIRED BEFORE STABLE 1 0 | INTENTIONALLY NON CODE |
| DE-FAM-006 | Standalone must remain commercially and operationally useful outside the CETECH ecosystem. | REQUIRED BEFORE STABLE 1 0 | INTENTIONALLY NON CODE |

### Authority boundaries (OWN)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-OWN-001 | WooCommerce owns products, variations, carts, checkout, orders, customers, payments, refunds, taxes, and accounts. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-OWN-002 | The Delivery Engine owns fulfillment choices, delivery offers, delivery areas, delivery-specific prices and promises. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-OWN-003 | The Delivery Engine must not duplicate merchandise pricing, stock, payment, tax, identity, or product-master authority. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-OWN-004 | One canonical owner must exist for each business truth. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-OWN-005 | External products and plugins must be normalized through boundary adapters. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-OWN-006 | Customer-facing labels must never become operational or transactional truth. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |

### Scoped configuration (CONFIG)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-CONFIG-001 | Configuration must resolve field by field through global, product, then variation scope. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-CONFIG-002 | Each field must support inherit, explicit override, and clear/reset semantics. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-CONFIG-003 | Effective configuration must expose both the value and its source scope. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-CONFIG-004 | Scalar and collection fields must preserve tri-state semantics. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-CONFIG-005 | Disabling a module must preserve its configuration and history. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-CONFIG-006 | Product and variation administration must show inherited versus customized values. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-CONFIG-007 | Configuration writes must be audited and versioned. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-CONFIG-008 | Legacy configuration must migrate non-destructively into scoped configuration. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |

### Effective Configuration Resolver (ECR)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-ECR-001 | All storefront and operational consumers must use the same Effective Configuration Resolver. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-ECR-002 | Simple and variable products must use semantically identical resolution rules. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-ECR-003 | Variation overrides must take precedence only for explicitly overridden fields. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-ECR-004 | Runtime fingerprints must change when effective business configuration changes. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-ECR-005 | Resolver failure or incomplete required configuration must fail closed. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-ECR-006 | Preview and diagnostics must use the production resolver rather than a separate interpretation. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |

### Commerce context (CTX)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-CTX-001 | A normalized commerce context must carry only delivery-relevant customer, channel, role, group, quantity, and currency facts. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-CTX-002 | Wholesale or marketplace plugins retain ownership of groups, roles, visibility, MOQ, and product price. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-CTX-003 | Quantity may affect delivery logistics independently of merchandise-price rules. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-CTX-004 | Per-item destinations and pickup endpoints must be preserved without leaking between cart lines. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-CTX-005 | Cart/session identity must isolate customer and destination context. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-CTX-006 | Adapters must not make a supported commerce model a hard dependency. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |

### Logistics profiles (LOG)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-LOG-001 | A Logistics Profile must represent weight, dimensions, handling, packaging, class, and transport constraints. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-LOG-002 | Native WooCommerce weight and dimension fields are the default product measurements. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-LOG-003 | Volumetric weight must be provider-neutral and policy driven. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-LOG-004 | Product and variation scopes may inherit or override Logistics Profile assignment. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-LOG-005 | Quote and journey planning must consume the effective Logistics Profile. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-LOG-006 | Logistics Profiles must not become product taxonomy or inventory truth. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |

### Suppliers and origins (ORIGIN)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-ORIGIN-001 | Suppliers and origins are separate domain concepts. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-ORIGIN-002 | Origins must carry operational location and dispatch facts without exposing private details publicly. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-ORIGIN-003 | Products and variations may inherit or override an origin. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-ORIGIN-004 | Supplier identity must not replace product or inventory ownership. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-ORIGIN-005 | Multi-origin grouping must be deterministic and visible to operations. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-ORIGIN-006 | Public payloads and shared caches must not reveal private supplier or origin data. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |

### Canonical geography (GEO)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-GEO-001 | Canonical locations must use immutable plugin-owned internal IDs. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-GEO-002 | Canonical locations must retain country, parent, type or administrative level, display name, status, and timestamps. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-GEO-003 | Aliases and provider mappings must be replaceable without changing canonical business identity. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-GEO-004 | Provider IDs must never be permanent business identity. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-GEO-005 | Locations may retain coordinates and data-pack provenance. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-GEO-006 | Frontend and administration must reference the same canonical records. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-GEO-007 | Matching must use canonical identity and ancestry, not spelling. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-GEO-008 | Country/location packs must install and update independently of the core ZIP. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-GEO-009 | Only merchant-needed packs should be stored locally. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-GEO-010 | Runtime selection must use local or cached data rather than a public geocoder on every request. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-GEO-011 | Pack imports must be idempotent, resumable, bounded, and safe on retry. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-GEO-012 | Pack promotion must be atomic, fenced, and invisible until complete. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | DESIGN SUPERIOR REALIGN CODE |
| DE-GEO-013 | Woo country/state data should seed authoritative country/subdivision facts where appropriate. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-GEO-014 | Locality import must be provider-neutral; GeoNames is the initial supported source. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-GEO-015 | Public geography payloads must expose only customer-safe IDs and names. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |

### Delivery Areas and coverage (AREA)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-AREA-001 | A Delivery Area represents destinations sharing delivery treatment. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-AREA-002 | Different hierarchy levels within a coverage group combine with AND. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-AREA-003 | Multiple selected members at the same hierarchy level combine with OR. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-AREA-004 | Separate coverage groups combine with OR. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-AREA-005 | Coverage must preserve parent-child relationships and reject nonsense combinations. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-AREA-006 | Coverage modes must include whole area, selected descendants, and whole area except exclusions. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-AREA-007 | Exclusions override inherited inclusion within the same group. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-AREA-008 | Postcode exact and prefix constraints must remain available where relevant. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-AREA-009 | Area priority, matched-constraint specificity, overlap order, and fallback must be deterministic. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-AREA-010 | Only a truly unconstrained fallback may act as everywhere else. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-AREA-011 | No valid matching coverage must fail closed. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |
| DE-AREA-012 | Legacy schema-5 rules must remain authoritative until each zone is safely converted. | REQUIRED BEFORE CURRENT ACTIVE STREAM CLOSES | PARTIAL IMPLEMENTATION |

### Delivery offers (OFFER)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-OFFER-001 | A Delivery Offer is the selectable customer-facing fulfillment service contract. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-OFFER-002 | Offers must have stable internal identity distinct from mutable public labels. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-OFFER-003 | Offers must separate route, service level, carrier visibility, description, tax class, and priority. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-OFFER-004 | Air and Sea are route or service facts, never product variations. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-OFFER-005 | Customer-visible offers must be filtered by effective product, fulfillment, destination, and price validity. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-OFFER-006 | An offer may be automatic when only one valid choice exists. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-OFFER-007 | Multiple offers must remain selectable when business policy permits. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-OFFER-008 | Offer labels and descriptions must be snapshot-safe and translatable. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |

### Service and promise (PROMISE)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-PROMISE-001 | Promise calculation must separate processing, transit, and final-mile components. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PROMISE-002 | Promise policy must support Express, Same Day, Next Day, Standard, Relaxed, and merchant-defined service levels. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PROMISE-003 | Promise calculation must use business calendars and closure dates. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PROMISE-004 | Promise calculation must enforce service cutoffs. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PROMISE-005 | Promise calculation must consider capacity where configured. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PROMISE-006 | Sequential and parallel legs must aggregate promises correctly. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PROMISE-007 | Promise results must identify the governing policy version. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PROMISE-008 | Customer-facing ETA must be explainable and localized. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PROMISE-009 | Promise failure must not silently display an invented date. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PROMISE-010 | Accepted promise facts must be frozen into the order snapshot. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |

### Quote lifecycle (QUOTE)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-QUOTE-001 | A first-class DeliveryQuote must have a stable quote ID. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-QUOTE-002 | A quote must bind cart or delivery-group fingerprint, origin, destination, service, and quantity. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-QUOTE-003 | A quote must record list price, promotion, final customer price, currency, and tax context. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-QUOTE-004 | A quote must record estimated cost and pricing-policy version. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-QUOTE-005 | A quote may record route distance, duration, traffic, vehicle requirement, provider, and provider version. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-QUOTE-006 | A quote must have created, expiry, and status fields. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-QUOTE-007 | A valid quote price must remain frozen until expiry. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-QUOTE-008 | Quote TTL must be distinct from capacity-reservation TTL. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-QUOTE-009 | Material cart, destination, service, currency, inventory, or policy changes must trigger revalidation. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-QUOTE-010 | Expired or invalid quotes must fail closed with recoverable customer UX. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-QUOTE-011 | Accepted quote facts must be snapshotted and never reconstructed from current rules. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-QUOTE-012 | Quote creation and acceptance must be idempotent where requests can repeat. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |

### Pickup intelligence (PICKUP)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-PICKUP-001 | Pickup locations must have stable identity, type, internal name, and customer name. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PICKUP-002 | Pickup locations must support store, warehouse, partner, agent, supplier, locker, third-party, and custom types. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PICKUP-003 | Pickup locations must retain address, coordinates, map link, contact, instructions, and landmarks. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PICKUP-004 | Pickup locations must model hours, time zone, closures, readiness, capacity, and status. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PICKUP-005 | Eligibility may vary by product, service, and scope. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PICKUP-006 | A default location and customer selection among allowed locations must both be supported. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PICKUP-007 | Pre-checkout disclosure may be general while post-checkout details are complete and configurable. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PICKUP-008 | Pickup may carry a customer fee and must not be implemented as an accidental zero-cost shipping hack. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PICKUP-009 | The selected pickup endpoint must be snapshotted immutably. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PICKUP-010 | Pickup customer copy must never present pickup as shipping to the customer's address. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |

### Multi-leg journeys (JOURNEY)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-JOURNEY-001 | A fulfillment journey may contain zero, one, or many legs. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-JOURNEY-002 | Journey legs may execute sequentially or in parallel. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-JOURNEY-003 | Each leg must retain origin, destination, mode, service, responsible provider, cost, and promise facts. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-JOURNEY-004 | Customer presentation should normally consolidate a multi-leg journey into one coherent offer. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-JOURNEY-005 | Internal operations must preserve per-leg status and economics. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-JOURNEY-006 | Composite names such as Air Shipping and Delivery must be presentation derived from journey facts. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-JOURNEY-007 | Public presentation may be hidden, summary, or explicit without changing operational truth. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-JOURNEY-008 | Journey grouping must support supplier, warehouse, and customer or pickup endpoints. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-JOURNEY-009 | Shared-leg costs must be allocated deterministically. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-JOURNEY-010 | Journey history must remain stable after configuration changes. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |

### Internal economics (ECON)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-ECON-001 | Customer delivery charge must be stored separately from estimated internal cost. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-ECON-002 | Actual internal cost must be stored separately from estimate and customer charge. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-ECON-003 | Provider payable must be a distinct amount. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-ECON-004 | Delivery subsidy must be explicit rather than hidden in a modified base rate. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-ECON-005 | Delivery margin must be derived from authoritative economic components. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-ECON-006 | Shared-leg cost allocation must be deterministic and auditable. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-ECON-007 | Economic fields must carry currency and source or policy version. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-ECON-008 | Private economics must never appear in customer payloads. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |

### Rate cards (RATE)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-RATE-001 | Rate Cards must use stable identity and explicit status. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-RATE-002 | Rate Cards must bind offer, destination area, currency, charge type, and priority. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-RATE-003 | Rate Cards may narrow by logistics profile, supplier, and origin. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-RATE-004 | Rates must support effective-from and effective-to dates. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-RATE-005 | Fixed per shipment and fixed per item calculation must be server-authoritative. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-RATE-006 | Invalid, negative, unsupported, or absent rate configuration must fail closed. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-RATE-007 | Matched-card selection must be deterministic and explainable. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-RATE-008 | One authoritative WooCommerce rate must represent the selected offer per managed package. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |

### Delivery promotions (PROMO)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-PROMO-001 | Delivery promotions must be separate from base Rate Cards. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-PROMO-002 | Promotion eligibility must be rule driven and versioned. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-PROMO-003 | Promotion application must record list price, discount, subsidy source, and final price. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-PROMO-004 | Budgets and limits must fail closed when exhausted or indeterminate. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-PROMO-005 | Promotion stacking and precedence must be deterministic. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-PROMO-006 | Promotion changes must revalidate active quotes. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-PROMO-007 | Order snapshots must preserve applied delivery promotion facts. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |

### Multi-currency (FX)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-FX-001 | The plugin must not become an FX engine. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-FX-002 | A provider-neutral currency adapter must determine presentment and conversion facts. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-FX-003 | Currency conversion must occur exactly once. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-FX-004 | Quote cache keys must include currency and provider context. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-FX-005 | Snapshots must preserve base, presentment, and charged currency plus applied rate and timestamp. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-FX-006 | WCML, WooPayments Multi-Currency, FOX/WOOCS, CURCY, Aelia, and TIV are priority adapter targets without becoming hard dependencies. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |

### Tax boundary (TAX)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-TAX-001 | WooCommerce remains tax authority for the checkout transaction. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-TAX-002 | Delivery offers and charges must expose the correct Woo tax class and taxability. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-TAX-003 | International duty or landed-cost estimates must be separately identified from authoritative tax. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-TAX-004 | Historical snapshots must retain charged delivery tax context without recalculating from current tax rules. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |

### Product page (PDP)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-PDP-001 | Product pages must show only valid delivery or pickup choices. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-PDP-002 | Product-page prices must be server-authoritative and quantity aware. | REQUIRED BEFORE STABLE 1 0 | IMPLEMENTATION SUPERIOR ADOPT |
| DE-PDP-003 | A delivery option must display service name, ETA, then prominent fee. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-PDP-004 | Zero-price pickup must display Free clearly. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-PDP-005 | No implicit store-country quote may masquerade as a shopper location. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-PDP-006 | Unquoted managed delivery must fail closed. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-PDP-007 | Simple and variable products must both be supported. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-PDP-008 | Radio controls, full-card click targets, focus, labels, and responsive tap targets must be preserved. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |

### Cart and checkout (CART)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-CART-001 | Delivery selections must persist in cart item data and session state. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-CART-002 | Stale configuration must reconcile existing cart lines against live rules. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-CART-003 | Invalidated selections must request reselection rather than silently substitute. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-CART-004 | Cart identity must distinguish materially different delivery contexts. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-CART-005 | Checkout must revalidate the selected delivery choice and authoritative quote. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-CART-006 | Classic checkout and Blocks checkout must produce equivalent business results. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-CART-007 | Checkout address remains final authority over preliminary product-page location. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-CART-008 | Managed packages with no valid Delivery Engine rate must not fall back to unrelated native rates. | REQUIRED BEFORE STABLE 1 0 | IMPLEMENTATION SUPERIOR ADOPT |

### Grouping and multi-destination (GROUP)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-GROUP-001 | Cart lines must group only when fulfillment choice, endpoint, origin, service, and destination policy allow. | REQUIRED BEFORE STABLE 1 0 | IMPLEMENTATION SUPERIOR ADOPT |
| DE-GROUP-002 | Per-item destinations must produce distinct delivery groups when required. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-GROUP-003 | Pickup and delivery groups must never be conflated. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-GROUP-004 | Grouping fingerprints must exclude stale administrative configuration noise. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-GROUP-005 | One customer rate per managed package must correspond to the group truth. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-GROUP-006 | Split and merge operations must be deterministic and revalidated. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-GROUP-007 | Customer presentation must use clear Delivery or Pickup group language. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |

### Order snapshots (SNAP)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-SNAP-001 | Each order line must retain immutable product, variation, quantity, fulfillment, and selected-offer facts. | REQUIRED BEFORE STABLE 1 0 | IMPLEMENTATION SUPERIOR ADOPT |
| DE-SNAP-002 | Snapshots must retain destination or pickup endpoint and delivery-group identity. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-SNAP-003 | Snapshots must retain quoted amount, currency, rate-card identity, and quote status. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-SNAP-004 | Snapshots must retain customer-facing offer label, description, and estimate. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-SNAP-005 | Snapshots must retain applied DeliveryQuote identity and pricing-policy version. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-SNAP-006 | Snapshots must retain fulfillment labels and promise-policy version. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-SNAP-007 | Snapshots must retain Return Policy and Refund Policy versions, summaries, full text, and exceptions. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-SNAP-008 | Historical snapshots must never be rewritten from current configuration. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |

### Shipment operations (SHIP)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-SHIP-001 | The plugin must own provider-neutral shipment aggregates separate from Woo order status. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-SHIP-002 | Shipment creation must be idempotent. | REQUIRED BEFORE STABLE 1 0 | IMPLEMENTATION SUPERIOR ADOPT |
| DE-SHIP-003 | Shipment items must retain order-item and quantity relationships. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-SHIP-004 | Shipment events must be append-oriented and historically attributable. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-SHIP-005 | Status transitions must be validated and timestamped. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-SHIP-006 | Paid-order and COD creation paths must remain distinct and safe. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-SHIP-007 | Cancellation and item-quantity refund handling must preserve shipment truth. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-SHIP-008 | Staff must have an operational shipments workspace and needs-attention path. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-SHIP-009 | Customers may see shipment status and tracking without private operational data. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-SHIP-010 | Carrier adapters must not own the core shipment model. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |

### Delivery exceptions (EXC)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-EXC-001 | Failed delivery must have explicit exception reasons and status. | ACCEPTED POST 1 0 PRODUCT COMPLETION | PARTIAL IMPLEMENTATION |
| DE-EXC-002 | Reattempt eligibility, fee, and promise must be policy driven. | ACCEPTED POST 1 0 PRODUCT COMPLETION | PARTIAL IMPLEMENTATION |
| DE-EXC-003 | Return-to-origin must be distinct from refund and order cancellation. | ACCEPTED POST 1 0 PRODUCT COMPLETION | PARTIAL IMPLEMENTATION |
| DE-EXC-004 | Operational exceptions must surface in Needs Attention with recovery actions. | ACCEPTED POST 1 0 PRODUCT COMPLETION | PARTIAL IMPLEMENTATION |
| DE-EXC-005 | Exception history must be auditable and customer disclosure configurable. | ACCEPTED POST 1 0 PRODUCT COMPLETION | PARTIAL IMPLEMENTATION |

### Notifications (NOTIFY)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-NOTIFY-001 | Delivery and shipment notifications must be policy driven rather than hard-coded side effects. | ACCEPTED POST 1 0 PRODUCT COMPLETION | PARTIAL IMPLEMENTATION |
| DE-NOTIFY-002 | Notifications must use immutable order or shipment facts. | ACCEPTED POST 1 0 PRODUCT COMPLETION | PARTIAL IMPLEMENTATION |
| DE-NOTIFY-003 | Delivery channels, retries, suppression, and failure status must be auditable. | ACCEPTED POST 1 0 PRODUCT COMPLETION | PARTIAL IMPLEMENTATION |
| DE-NOTIFY-004 | Customer messages must not disclose private provider or internal economic data. | ACCEPTED POST 1 0 PRODUCT COMPLETION | PARTIAL IMPLEMENTATION |

### Return Policy (RETURN)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-RETURN-001 | Return Policy must be an independent policy system, not a Refund Policy field. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-RETURN-002 | Return Policy records need name, customer title, summary, full text, exceptions, and status. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-RETURN-003 | Return Policy records need version, effective dates, author, and audit history. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-RETURN-004 | A separate global Return Policy default must exist. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-RETURN-005 | Fulfillment-specific Return Policy overrides must be supported. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-RETURN-006 | Product and variation Return Policy overrides must resolve independently. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-RETURN-007 | Cart and checkout must show the applicable summary with details on demand. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-RETURN-008 | Order and post-purchase surfaces must show the frozen policy. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-RETURN-009 | Optional customer acknowledgement must be recorded when enabled. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-RETURN-010 | Order snapshots must preserve the complete applied Return Policy version and content. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |

### Refund Policy (REFUND)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-REFUND-001 | Refund Policy must be an independent policy system, not a Return Policy field. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-REFUND-002 | Refund Policy records need name, customer title, summary, full text, exceptions, and status. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-REFUND-003 | Refund Policy records need version, effective dates, author, and audit history. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-REFUND-004 | A separate global Refund Policy default must exist. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-REFUND-005 | Fulfillment-specific Refund Policy overrides must be supported. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-REFUND-006 | Product and variation Refund Policy overrides must resolve independently. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-REFUND-007 | Cart and checkout must show the applicable summary with details on demand. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-REFUND-008 | Order and post-purchase surfaces must show the frozen policy. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-REFUND-009 | Optional customer acknowledgement must be recorded when enabled. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-REFUND-010 | Order snapshots must preserve the complete applied Refund Policy version and content. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |

### Fulfillment labels (LABEL)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-LABEL-001 | The product must support multiple fulfillment labels per product or variation. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-LABEL-002 | Labels require stable internal identity, public text, status, and schedule. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-LABEL-003 | Global enablement and product or variation inheritance must be supported. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-LABEL-004 | Rule-driven labels must consume authoritative delivery facts. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-LABEL-005 | Display positions must be configurable across product, cart, checkout, and order surfaces. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-LABEL-006 | Structured wait or availability data must accompany relevant labels. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-LABEL-007 | Labels must be accessible, localized, and efficiently evaluated. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-LABEL-008 | Labels communicate product promise but never determine price, inventory, fulfillment, or shipment truth. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-LABEL-009 | Applied labels and rule versions must be frozen in the order snapshot. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |

### Bulk tools (BULK)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-BULK-001 | Bulk operations must preview and validate before applying changes. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-BULK-002 | Bulk jobs must use the same application services and validation as interactive writes. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-BULK-003 | Large jobs must be resumable and bounded through Action Scheduler or safe continuation. | REQUIRED BEFORE STABLE 1 0 | IMPLEMENTATION SUPERIOR ADOPT |
| DE-BULK-004 | Each target result and error must be durably recorded. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-BULK-005 | CSV and configuration import/export must be versioned and validated. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-BULK-006 | Rollback must be bounded by fingerprints and must not overwrite newer conflicting edits. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-BULK-007 | Dry-run and read-only validation must perform genuine scans. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-BULK-008 | Machine-readable job status and recovery for waiting or stale jobs must exist. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |

### Business rules (RULE)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-RULE-001 | Business rules must have draft, published, retired, and scheduled lifecycle states. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-RULE-002 | Rules must retain version, effective-from, effective-until, author, and change reason. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-RULE-003 | Only published and currently effective rules may drive customer results. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-RULE-004 | Rule precedence and conflicts must be deterministic and diagnosable. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-RULE-005 | Historical orders must retain the applied rule or policy version. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-RULE-006 | Rule changes must support impact preview or dry-run. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-RULE-007 | Emergency disable must not destroy rule history. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |

### Manual overrides (OVR)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-OVR-001 | Manual overrides may target service availability, origin, fee, waiver, tier, vehicle, grouping, or consolidation. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-OVR-002 | Every override must record actor, time, old value, new value, and reason. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-OVR-003 | Sensitive overrides may require role separation or approval. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-OVR-004 | Overrides must be scope and time bounded. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-OVR-005 | Override effects must be visible in diagnostics and snapshots. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-OVR-006 | Expired or revoked overrides must preserve history. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |

### Analytics (ANALYTICS)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-ANALYTICS-001 | Operational analytics must distinguish quotes, selections, orders, shipments, failures, and completions. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-ANALYTICS-002 | Profitability must derive from customer charge, subsidy, actual cost, provider payable, and allocation. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-ANALYTICS-003 | Analytics must not become an alternative transactional authority. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-ANALYTICS-004 | Exports must protect personal and precise-location data. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |
| DE-ANALYTICS-005 | Connected DataPlane integration may consume events but must not become a Standalone dependency. | ACCEPTED POST 1 0 PRODUCT COMPLETION | MISSING IMPLEMENTATION |

### REST/OpenAPI/PHP/Store API (API)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-API-001 | Standalone must expose versioned shopper, admin, integration, webhook, and diagnostic API surfaces as appropriate. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-API-002 | REST and Store API handlers must call the same application services as internal flows. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-API-003 | OpenAPI must describe routes, schemas, authentication, errors, examples, pagination, and versioning. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-API-004 | JSON Schema must define public request and response contracts. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-API-005 | Mutating endpoints must support idempotency keys where repetition is possible. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-API-006 | Responses and logs must carry correlation or request IDs. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-API-007 | Concurrent resource changes should support ETag or equivalent optimistic concurrency where appropriate. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-API-008 | Authorization must be capability and object scoped. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-API-009 | Public responses must expose only customer-safe fields. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-API-010 | Errors must be structured, stable, localized at presentation boundaries, and fail closed. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-API-011 | Public contracts must be classified stable, experimental, or internal with deprecation policy. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-API-012 | API rate and abuse protection must be appropriate to quote and geography cost. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |

### WP-CLI (CLI)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-CLI-001 | A first-class WP-CLI namespace must cover administration, operations, diagnostics, maintenance, import/export, simulation, configuration, and development support. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-CLI-002 | CLI commands must reuse application services and never fork business logic. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-CLI-003 | CLI must support status and doctor diagnostics. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-CLI-004 | CLI must support location and geography-pack operations. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-CLI-005 | CLI must support fulfillment, quote, order, shipment, and policy operations. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-CLI-006 | CLI must support jobs, cache, database, and OpenAPI maintenance. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-CLI-007 | Commands must support non-interactive and machine-readable output. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-CLI-008 | Destructive commands must require confirmation and offer dry-run where practical. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-CLI-009 | Exit codes, errors, authorization assumptions, and secrets handling must be automation safe. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |

### Events and webhooks (EVENT)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-EVENT-001 | Material quote, selection, order-snapshot, shipment, policy, and pack lifecycle changes must emit governed domain events. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-EVENT-002 | Event schemas must be versioned and documented. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-EVENT-003 | Webhook subscriptions and delivery status must be persisted. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-EVENT-004 | Webhooks must be signed and protected against replay. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-EVENT-005 | Webhook delivery must be idempotent, retried with backoff, and dead-lettered or surfaced when exhausted. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-EVENT-006 | AsyncAPI or equivalent event documentation must describe public event contracts. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |
| DE-EVENT-007 | Internal WordPress hooks must be stable, minimal, documented, and must not expose private data. | REQUIRED BEFORE STABLE 1 0 | MISSING IMPLEMENTATION |

### Diagnostics (DIAG)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-DIAG-001 | System status must report plugin, schema, WooCommerce, HPOS, checkout, theme, language, currency, cache, and scheduler state. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-DIAG-002 | Diagnostics must cover configuration completeness, failed quotes, jobs, cleanup, API, and database health. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-DIAG-003 | Site Health integration should expose actionable non-secret checks. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-DIAG-004 | Logs must use WooCommerce logging, correlation IDs, severity, and redaction. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-DIAG-005 | Diagnostics must remain truthful when an analysis is bounded or incomplete. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-DIAG-006 | Cached and uncached matching must preserve equivalent explainability. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-DIAG-007 | Monitoring must not expose private commercial or customer data. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |

### Feature flags (FLAG)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-FLAG-001 | Major runtime modules and adapters must support controlled enablement. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-FLAG-002 | Feature flags must preserve configuration and history when disabled. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-FLAG-003 | A global emergency disable must stop Delivery Engine checkout effects safely. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-FLAG-004 | Flags must support gradual rollout or bounded activation where appropriate. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-FLAG-005 | Flag state and operational impact must be visible in diagnostics. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |

### Data lifecycle (DATA)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-DATA-001 | Every data class must have an explicit retention and cleanup policy. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-DATA-002 | Quotes and transient traces must expire without deleting contractual snapshots. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-DATA-003 | Logs must be configurable and redacted. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-DATA-004 | Order snapshots and policy history must not be garbage collected. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-DATA-005 | Deactivation must not delete data. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-DATA-006 | Uninstall deletion must require explicit merchant intent and remain bounded to plugin-owned data. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-DATA-007 | Background cleanup must use bounded scheduled work and must not delete shared Action Scheduler tables. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |

### Release/productization (REL)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-REL-001 | Release artifacts must be built from a clean committed SHA. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-REL-002 | Tags and qualified artifacts are immutable and version identities are never reused. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-REL-003 | Clean install and prior-version upgrade with data retention must be qualified. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-REL-004 | Release packages must exclude tests, development evidence, secrets, and unrelated binaries. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-REL-005 | Dependencies and licenses must be locked and scanned. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-REL-006 | Checksums and preferably an SBOM must accompany commercial artifacts. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-REL-007 | Release channels and rollback procedure must be explicit. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-REL-008 | Compatibility claims must be centrally registered and evidence linked. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-REL-009 | Commercial distribution, licensing, signing, and updates must use an independent direct mechanism; WordPress.org is not required. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |

### Compatibility (COMPAT)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-COMPAT-001 | HPOS compatibility is mandatory and must be declared and qualified. | REQUIRED BEFORE STABLE 1 0 | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-COMPAT-002 | Classic checkout is a mandatory supported path. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-COMPAT-003 | Cart and Checkout Blocks are a mandatory supported path. | REQUIRED BEFORE STABLE 1 0 | ALIGNED EXACT |
| DE-COMPAT-004 | Storefront and a current supported WoodMart version are mandatory Stable 1.0 reference-theme certifications; neither is a hard dependency. | REQUIRED BEFORE STABLE 1 0 | CERTIFICATION GAP |
| DE-COMPAT-005 | WPML and WCML certification is required before Stable 1.0 only if advertised as supported at launch; otherwise it remains optional. | OPTIONAL CERTIFICATION | CERTIFICATION GAP |
| DE-COMPAT-006 | WCFM support is limited to explicit delivery-context and administrative-isolation contracts. | OPTIONAL CERTIFICATION | ALIGNED SEMANTICALLY EQUIVALENT |
| DE-COMPAT-007 | B2BKing is the Stable 1.0 wholesale reference target; other wholesale adapters remain optional certification targets. | REQUIRED BEFORE STABLE 1 0 | CERTIFICATION GAP |
| DE-COMPAT-008 | FOX/WOOCS is the Stable 1.0 multi-currency reference target; other currency providers remain optional adapter-specific certifications. | REQUIRED BEFORE STABLE 1 0 | CERTIFICATION GAP |
| DE-COMPAT-009 | VitePOS or any POS is outside the core dependency boundary and requires separate end-to-end certification. | OPTIONAL CERTIFICATION | CERTIFICATION GAP |
| DE-COMPAT-010 | Redis and page-cache plugins must not leak cart, address, currency, locale, or quote state. | OPTIONAL CERTIFICATION | CERTIFICATION GAP |
| DE-COMPAT-011 | External PSPs are owned by WooCommerce/payment plugins and require only compatibility qualification. | OPTIONAL CERTIFICATION | CERTIFICATION GAP |
| DE-COMPAT-012 | Carrier APIs are optional future adapters and must not define the core model. | FUTURE ADVANCED INTEGRATION | DEFERRED |

### Security/privacy (SEC)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-SEC-001 | Every administrative write must enforce capability, nonce, validation, and object scope. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-SEC-002 | Public canonical IDs and selection payloads must be treated as untrusted input. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-SEC-003 | Money, eligibility, promise, and shipment transitions must be server-authoritative. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-SEC-004 | Public payloads must exclude supplier, origin, rate-card, internal-cost, margin, and secret data. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-SEC-005 | Logs and diagnostics must redact personal, address, coordinate, credential, and token data. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-SEC-006 | Precise addresses and coordinates must have purpose-limited storage and disclosure. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-SEC-007 | Quote, geography, webhook, and diagnostic interfaces must be rate and abuse protected. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-SEC-008 | Secrets must never be committed, exported, logged, or shipped in packages. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-SEC-009 | Imports and uploads must resist path traversal, SSRF, archive abuse, and malicious content. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-SEC-010 | Security qualification must include runtime behavior, not only static tool output. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |

### Performance/scalability (PERF)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-PERF-001 | Customer request paths must avoid unbounded scans and N+1 queries. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PERF-002 | National-scale pack import must be bounded, resumable, and background-capable. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PERF-003 | Large catalogs and more than 500 delivery areas must remain truthfully operable. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PERF-004 | Cache keys must include every material product, variation, quantity, destination, offer, group, currency, locale, rule, and inventory dimension. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PERF-005 | Shared cache keys must never contain raw private addresses. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PERF-006 | Cache invalidation must be versioned and concurrency safe. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PERF-007 | Cart, session, user, locale, and currency state must never leak across customers. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PERF-008 | Assets must load only on relevant admin or storefront surfaces. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-PERF-009 | Emergency cache cleanup must never issue broad destructive commands such as Redis FLUSHALL. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |

### UX/accessibility/SEO (UX)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-UX-001 | Staff administration must be WordPress-native, task-oriented, and progressively disclose advanced controls. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-UX-002 | Customer language must hide internal machinery while preserving truthful choices and prices. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-UX-003 | Desktop and mobile layouts must remain usable without excessive nesting or density. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-UX-004 | Keyboard, focus, labels, contrast, status semantics, and screen-reader behavior are release requirements. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-UX-005 | Color must not be the sole differentiator. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-UX-006 | Theme adaptation must use tokens and scoped CSS rather than editing parent themes. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-UX-007 | SEO-sensitive product content must remain crawl-safe and not depend on private dynamic state. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-UX-008 | Errors must explain recovery without exposing internals or implying false availability. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |

### Country neutrality (GLOBAL)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-GLOBAL-001 | Core behavior must not hard-code Ghana, GHS, or one country's address hierarchy. | REQUIRED BEFORE STABLE 1 0 | DESIGN SUPERIOR REALIGN CODE |
| DE-GLOBAL-002 | Country-specific labels, postcode relevance, and administrative levels must come from data or adapters. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-GLOBAL-003 | Ghana may be a qualification/reference pack but not the commercial default. | REQUIRED BEFORE STABLE 1 0 | DESIGN SUPERIOR REALIGN CODE |
| DE-GLOBAL-004 | Units, time zones, currencies, locales, names, and calendars must be explicit and internationalized. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |
| DE-GLOBAL-005 | Country-specific data packs must be replaceable without changing business configuration identity. | REQUIRED BEFORE STABLE 1 0 | PARTIAL IMPLEMENTATION |

### Connected-only ownership (CONNECT)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-CONNECT-001 | Connected inventory and fulfillment orchestration belongs to Invordex. | CONNECTED ONLY | CONNECTED ONLY |
| DE-CONNECT-002 | Connected transport execution belongs to Alcide. | CONNECTED ONLY | CONNECTED ONLY |
| DE-CONNECT-003 | Connected warehouse execution belongs to HubLoft. | CONNECTED ONLY | CONNECTED ONLY |
| DE-CONNECT-004 | Connected product master belongs to AIM PIM and supplier/procurement belongs to Suproma. | CONNECTED ONLY | CONNECTED ONLY |
| DE-CONNECT-005 | Connected geography belongs to GeoMesh where adopted; Standalone retains local canonical geography. | CONNECTED ONLY | CONNECTED ONLY |
| DE-CONNECT-006 | Connected return execution belongs to ReLoop while policy presentation may remain in the delivery contract. | CONNECTED ONLY | CONNECTED ONLY |
| DE-CONNECT-007 | AccessLobby, MoneyMove, DonLoft, DataPlane, and other peers remain optional Connected owners, never Standalone hard dependencies. | CONNECTED ONLY | CONNECTED ONLY |

### Deferred advanced scope (DEFER)

| ID | Atomic requirement | Release intent | Conformance |
|---|---|---|---|
| DE-DEFER-001 | Proof-of-delivery execution is deferred. | EXPLICITLY DEFERRED | DEFERRED |
| DE-DEFER-002 | OTP, QR, GPS, photo capture, and buyer-confirmation workflows are deferred. | EXPLICITLY DEFERRED | DEFERRED |
| DE-DEFER-003 | Driver accounts and driver applications are deferred. | EXPLICITLY DEFERRED | DEFERRED |
| DE-DEFER-004 | Live carrier quoting, dispatch, label purchase, and automatic tracking synchronization are deferred advanced adapters. | EXPLICITLY DEFERRED | DEFERRED |
| DE-DEFER-005 | Automatic order completion from delivery events is deferred. | EXPLICITLY DEFERRED | DEFERRED |
| DE-DEFER-006 | Courier marketplace, supplier portal, and warehouse scanning are outside the current Standalone Stable 1.0 scope. | EXPLICITLY DEFERRED | DEFERRED |

## Reading the classifications

- **Aligned — exact**: the evidence implements the current requirement directly.
- **Aligned — semantically equivalent**: behavior is compliant even if names or structure differ.
- **Implementation superior — adopt**: a later implementation is materially safer or clearer than older design wording and is part of the owner-approved product truth.
- **Design superior — realign code**: current code conflicts with a governing invariant or accepted design.
- **Partial implementation**: material implementation exists but one or more atomic contract elements or acceptance gates are absent.
- **Missing implementation**: accepted product intent has no material implementation evidence in RC.11/master/geo.11.
- **Certification gap**: design or code may exist, but the claimed environment/integration has not been physically qualified.
- **Connected only / Deferred**: not a Standalone Stable 1.0 implementation requirement.
- **Intentionally non-code**: product identity or governance truth whose evidence is constitutional rather than executable.

## Highest-impact findings

1. The implemented Woo-native core is substantial and fundamentally sound: scoped configuration, resolver, offers, deterministic rate calculation, native shipping, PDP/cart/checkout, immutable snapshots, per-item grouping, shipment records, bulk jobs, admin recovery, and fail-closed behavior.
2. The largest lost current scope is first-class quote lifecycle, Return Policy, Refund Policy, Fulfillment Labels & Product Promise, service/promise policy, delivery promotions, internal economics, first-class APIs/OpenAPI, broad WP-CLI, and governed events/webhooks.
3. geo.11 is a substantial partial implementation of canonical geography and coverage, not an accepted or merge-ready completion; the finite geo.12 closure list remains governing.
4. Multi-leg journey execution, advanced overrides, exception depth, notification orchestration, and analytics are accepted product completion work but need not all block Stable 1.0.
5. POD/OTP/QR/GPS/photo/driver/live-carrier execution remains explicitly deferred.
