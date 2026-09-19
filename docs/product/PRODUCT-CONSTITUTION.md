# CETECH Delivery Standalone — Product Constitution

Status: `PRODUCT-TRUTH-BASELINE-1` — owner accepted and frozen on 2026-09-19; repository authority upon merge of its dedicated control-plane PR.
Audit date: 2026-09-19.

## 1. Product identity

CETECH Delivery Standalone is an independently installable, commercially distributable WooCommerce fulfillment, delivery, shipping, pickup, promise, pricing, and shipment-operations platform. It is a modular WordPress plugin, not a flat-rate add-on and not a CETECH V4 component.

WooCommerce is its only hard application dependency. WordPress and WooCommerce remain authoritative for products, variations, carts, checkout, orders, customers, merchandise prices, taxes, payments, refunds, and accounts. The plugin owns delivery-domain truth: fulfillment choices, delivery offers, delivery areas, logistics profiles, origins, pickup locations, delivery charges, promises, delivery quotes, delivery-specific promotions, shipment records, and immutable delivery snapshots.

The product must remain useful to an ordinary WooCommerce merchant with no CETECH ecosystem services installed.

## 2. Product family

There are two separate public products, not one plugin with modes:

- **Standalone** — local WooCommerce-native operational data and execution; this audit concerns this product.
- **Connected** — a separate repository, build, release, and engine that consumes peer applications through contracts.

They may share behavioral specifications and contract packages, but not runtime identity, storage authority, dependency requirements, or release artifacts. CETECH V4 may consume either product; it is not a dependency of either. Connected architecture must not leak peer dependencies or UI into Standalone.

## 3. Core product model

The engine must model and keep distinct:

- commerce context, fulfillment availability, fulfillment choice, delivery service/offer, promise, shipment, carrier, supplier, origin, pickup endpoint, logistics profile, delivery group, route/journey leg, quote, rate, promotion, and customer-facing label;
- customer delivery charge, estimated internal cost, actual internal cost, provider payable, subsidy, shared-leg allocation, and margin;
- Return Policy and Refund Policy as independent, separately versioned policy systems;
- business configuration and the immutable order-time snapshot of what the customer chose and was promised.

Air, sea, local delivery, store pickup, warehouse pickup, supplier dispatch, and similar concepts are service/route/fulfillment facts—not product variations and not labels masquerading as operational truth.

## 4. Configuration and authority

Configuration resolves field by field through `GLOBAL → PRODUCT → VARIATION`, with explicit inheritance, override, clear/reset, and effective-value/source visibility. Collections and scalar fields must preserve tri-state semantics. Bulk tools must use the same application services and validation rules as interactive administration.

Rules and policies must be versioned, effective-dated where appropriate, auditable, and explainable. Manual overrides must record actor, time, before/after values, reason, scope, and any approval requirement. No alternative UI, API, CLI, background job, or compatibility adapter may create a second business-logic fork.

## 5. Customer journey

The customer sees only valid fulfillment choices and delivery offers for the effective product/variation, destination, cart context, inventory/availability context, service policy, and current business rules.

The product page may collect only the minimum location needed to present a truthful indicative offer. Cart and checkout remain authoritative for the final address. Selections persist through cart and checkout, are revalidated at material lifecycle transitions, fail closed when no authoritative price or promise exists, and are snapshotted immutably to the order. JavaScript never calculates authoritative money, eligibility, or promise results.

Classic and Blocks experiences must be behaviorally equivalent. Theme presentation may adapt, but business logic must remain theme-independent. WoodMart is a priority qualification target, never a required runtime owner.

## 6. Delivery topology and promise

A fulfillment journey may contain zero, one, or many sequential or parallel legs. The customer normally receives one coherent offer, price, and promise while internal operations preserve per-leg responsibilities and economics. Pickup is a real fulfillment endpoint and may have a fee; it is not implemented as an accidental zero-cost shipping shortcut.

The Delivery Service & Promise Policy Engine must support service levels such as Express, Same Day, Next Day, Standard, and Relaxed; processing/transit/final-mile components; business calendars, closures, cutoffs, capacity, and deterministic fallback. Customer-facing names and labels summarize policy; they never become the underlying transactional truth.

## 7. Quote, price, and money

A first-class delivery quote has an identifier, request/cart/group fingerprint, origin/destination, selected service, route facts where available, pricing-policy version, estimated cost, list price, promotion, final price, currency/tax context, creation/expiry, provider/version, and status. Quote TTL is distinct from capacity-reservation TTL. An accepted quote is frozen for its validity period and snapshotted; historical results are not reconstructed from current configuration.

Delivery promotions and subsidies are separate from base rates. Unknown-price-after-payment is not an acceptable normal flow. The plugin does not become a merchandise pricing, tax, payment, or general FX engine. Currency conversion occurs exactly once through a provider-neutral boundary and base/presentment/charged currency plus the applied rate are preserved when conversion occurs.

## 8. Geography, areas, and pickup

Delivery Areas are deterministic business groupings, not arbitrary free-text labels. Canonical geography uses stable internal identity, hierarchy, aliases, provider mappings, and replaceable country/location packs. Provider IDs never become permanent business identity. Runtime storefront selection uses local/cached data and exposes only customer-safe geography.

Coverage groups express AND across hierarchy levels, OR within a level, and OR between groups; they support whole area, selected descendants, whole area except exclusions, and postcode constraints. Priority, specificity, overlap behavior, and fallback remain deterministic and fail closed.

Pickup locations are versioned operational endpoints with type, customer/internal names, contact and directions, hours/closures/time zone, eligibility, readiness, capacity, visibility, and configurable pre-/post-checkout disclosure. The selected pickup location is snapshotted immutably.

## 9. Policies, labels, and snapshots

Return Policy and Refund Policy each have independent global defaults and fulfillment/product/variation overrides; name/title, customer summary, full text, exceptions, status, version, effective dates, and audit history; cart/checkout/order presentation; optional acknowledgement; and immutable order snapshots.

Fulfillment Labels & Product Promise is a current product capability. Multiple scheduled/rule-driven labels may be shown at configured surfaces with structured wait data, accessible markup, and efficient evaluation. Labels communicate promises; labels do not determine fulfillment, price, inventory, or shipment truth.

The immutable order snapshot preserves the selected item/variation, quantity, destination or pickup endpoint, offer, charge and tax context, timing/promise, labels, return/refund policy versions and content, delivery group/journey, and material customer selections.

## 10. Shipment operations

The plugin owns provider-neutral shipment aggregates, shipment items, events, tracking references, status transitions, staff operations, customer visibility, and idempotent creation. Shipment status is distinct from WooCommerce order status. Cancellation, refund review, reattempt/failed-delivery handling, and notifications must not silently rewrite order, payment, refund, or inventory truth.

Rich proof-of-delivery, buyer confirmation, OTP, QR, GPS/photo capture, driver accounts/apps, live carrier dispatch, and automatic carrier sync are explicitly deferred advanced capabilities unless separately reopened.

## 11. Interfaces

The same application services must support five governed surfaces:

1. internal/public PHP application API;
2. stable WordPress actions and filters;
3. REST and Woo Store API described by versioned OpenAPI/JSON Schema;
4. broad WP-CLI administration, operations, diagnostics, maintenance, import/export, and simulation commands;
5. domain events, signed/replay-protected webhooks, and event schemas.

These surfaces require structured errors, correlation/request IDs, authorization, idempotency where effects can repeat, pagination, compatibility policy, and explicit public/experimental/internal stability classification.

## 12. Compatibility

The core must not require WoodMart, WPML/WCML, WCFM, POS, B2B/wholesale, FX, cache, PSP, carrier, or CETECH peer software. Adapters normalize external context at the boundary. The Stable 1.0 certification floor prioritizes WooCommerce/HPOS, Classic and Blocks checkout, Storefront, a current supported WoodMart version, B2BKing, and FOX/WOOCS. WPML/WCML must be certified before Stable 1.0 only if advertised as supported at launch. Other adapters remain optional and explicitly uncertified until evidence is recorded; compatibility design and physical certification are separate claims.

The plugin does not own wholesale groups, product visibility, tier merchandise price, MOQ, tax exemption, RFQ, marketplace vendor accounting, subscription contracts, rentals, procurement, or POS registers. It consumes the minimum normalized context needed to calculate delivery correctly.

## 13. Non-functional constitution

The product is server-authoritative, native-first, fail-closed, private by design, secure, scalable, accessible, internationalized, country-neutral, theme-independent, migration-safe, and historically auditable. It must avoid N+1 and unbounded scans; use deterministic cache keys and versioned invalidation; isolate carts/sessions/users/currencies/locales; redact logs; guard public quote/geography APIs from abuse; and load assets only where needed.

Precise addresses and coordinates are sensitive. Shared caches and public payloads must never expose supplier, origin, rate-card, margin, policy-internal, or other private operational data. Data classes have explicit retention: short-lived quotes, medium-lived traces/logs, and permanent contractual snapshots/policy history. Deactivation is non-destructive; uninstall deletion is explicit and bounded.

## 14. Productization and release

The plugin uses forward, idempotent, verified migrations; non-destructive updates; explicit rollback planning; immutable tags and artifacts; reproducible clean packages; dependency/license checks; checksums; an SBOM where practical; release channels; a central compatibility registry; and evidence-based qualification.

The commercial product must use an independent direct commercial distribution, update, and licensing mechanism. WordPress.org is not a required dependency. Public repository visibility does not grant an open-source license.

“CETECH WooCommerce Delivery Engine” and “CETECH Delivery Standalone” are internal/working names. The externally commercialized product must be renamed independently of CETECH before the main Stable 1.0 commercial release. CETECH is the first production Pilot customer after geo.12 technical closure and physical owner QA; that sequencing does not itself authorize a deployment.

Implemented, tested, CI-green, reviewed, owner-accepted, merged, staging-qualified, released, and production-approved are distinct states.

## 15. Negative invariants

The Standalone product must never:

- require CETECH V4, Connected peers, POS, WoodMart, WPML/WCML, WCFM, a wholesale plugin, an FX plugin, or a carrier API to perform its core job;
- create a second merchandise pricing, tax, payment, refund, inventory, identity, or product-master authority;
- treat a customer-facing label as operational truth;
- silently substitute free delivery or another shipping method when a managed quote is absent or invalid;
- expose private origins, suppliers, internal costs, margins, rate cards, or precise data in public payloads/caches;
- hard-code Ghana, GHS, a single provider, a single theme, or a single commerce model as universal product behavior;
- trust browser-calculated money, promise, eligibility, canonical location IDs, or provider IDs;
- force internal machinery or multi-leg complexity into customer language;
- rewrite historical snapshots from current configuration;
- destructively reset configuration or history during activation, update, deactivation, or ordinary migration;
- make an unbounded HTTP request the sole path for national-scale imports or maintenance;
- add Connected UI, credentials, storage, or runtime dependencies to the Standalone build.

## 16. Release-intent constitution

Stable 1.0 must include a coherent, supportable, commercially shippable core rather than the entire ultimate product. It includes the proven Woo-native fulfillment/price/snapshot/shipment baseline, completion of the active canonical-geography stream, first-class quote lifecycle, service/promise foundations, label and independent return/refund policy foundations, interface contracts, safety/retention/diagnostic controls, an independent direct commercial distribution/update/licensing posture, and schema/contract separation of customer charge, promotion/subsidy, and private delivery economics. Deeper cost reconciliation, shared-leg allocation, analytics, rich carrier execution, POD/OTP/QR/GPS/photo/driver workflows, and Connected peer orchestration do not block Stable 1.0.

The exact Stable 1.0 classification is maintained in `RELEASE-SCOPE.md`; this constitution defines product identity and invariants, not implementation acceptance.
