# CETECH WooCommerce Delivery Engine — Project Governance

**Document status:** Mandatory project governance
**Applies to:** All human developers, Cursor agents, AI coding agents, reviewers, maintainers, and future contributors
**Project:** CETECH WooCommerce Delivery Engine
**Repository namespace:** `CetechDeliveryEngine\`
**WooCommerce dependency:** Required
**PHP minimum:** 8.1+
**Current known implementation baseline:** `1.0.0-rc.4`, schema target `3`  
**Canonical maintained rulebook:** `docs/DELIVERY-ENGINE-GOVERNING-RULES.md`

---

# 1. Purpose of this document

This document governs how the CETECH WooCommerce Delivery Engine must be designed, developed, modified, tested, reviewed, documented, and released.

It does not replace the detailed product specification, architecture documents, implementation notes, or phase documents.

Its purpose is to define:

* which documents are authoritative;
* how conflicting information must be resolved;
* non-negotiable architectural rules;
* development sequencing rules;
* coding-agent behavior;
* safety requirements;
* testing requirements;
* documentation requirements;
* release gates;
* prohibited shortcuts.

Concrete, testable operating rules live in `docs/DELIVERY-ENGINE-GOVERNING-RULES.md`. That file is the canonical maintained rulebook. This governance document remains mandatory for process and source-of-truth hierarchy.

Every coding session must comply with this document **and** the canonical rulebook.

If a requested implementation conflicts with this governance document, stop and identify the conflict before implementing it.

---

# 2. Source-of-truth hierarchy

The project has different sources of truth for different purposes.

Do not collapse them into one category.

## 2.1 Intended product behavior and end-state

The latest:

`Delivery Shipping Plugin Up-To-Date Design and Expectations.md`

is the highest-authority specification for what the plugin is intended to become.

It governs:

* business behavior;
* fulfilment model;
* global configuration;
* product overrides;
* variation overrides;
* inheritance;
* Effective Configuration Resolver behavior;
* rate resolution;
* hard constraints;
* UI/UX expectations;
* performance expectations;
* SEO requirements;
* accessibility;
* theme independence;
* WoodMart compatibility;
* shipment architecture;
* future integrations;
* overall Definition of Done.

When an older product description conflicts with this latest specification, the latest specification wins for intended behavior.

## 2.2 Current implementation truth

Repository code and completed phase documentation are authoritative for what actually exists today.

Read:

* `docs/AI-HANDOFF.md`
* matching `docs/PHASE-*-IMPLEMENTATION.md`
* `docs/V1-RC-RELEASE-NOTES.md`
* `docs/V1-RC-FLAG-MATRIX.md`
* `docs/V1-RC-SMOKE-TEST-CHECKLIST.md`
* relevant migration/schema documentation
* current repository code

Do not claim that a feature exists merely because the design specification describes it.

## 2.3 Hard engineering rules

`docs/DELIVERY-ENGINE-GOVERNING-RULES.md` is the canonical maintained rulebook for hard invariants (architecture, privacy, shipments, language, release, testing honesty).

`docs/PROJECT-RULES.md` contains preserved detailed engineering restrictions derived from the original handoff. It must not be silently deleted. When it and the canonical rulebook appear to conflict, stop and reconcile explicitly; do not ignore either file.

`.cursor/rules/000-delivery-engine-governance.mdc` is the always-apply Cursor enforcement wrapper. It must point at the canonical rulebook and must not become a second competing rulebook.

## 2.4 Architecture

`docs/ARCHITECTURE-PLAN.md` governs the established modular-monolith organization unless a later approved architecture phase explicitly changes it.

## 2.5 Conflict resolution

When documents appear to conflict:

1. Determine whether the disagreement concerns:

   * intended future behavior; or
   * currently implemented behavior.

2. For intended behavior:

   * latest Design and Expectations specification wins.

3. For current implementation:

   * repository code plus the latest completed implementation documentation wins.

4. Preserve all hard safety/privacy rules unless an explicit later project decision supersedes them.

5. Do not silently reconcile contradictions by inventing new behavior.

Document the conflict and resolve it deliberately.

---

# 3. Current baseline

At the current known baseline:

* plugin version is `1.0.0-rc.4`;
* schema target is `3`;
* the simple-product customer path through order delivery snapshots is implemented;
* runtime customer-facing flags default off;
* WooCommerce is the only hard dependency;
* HPOS compatibility exists through WooCommerce CRUD;
* shipment tables are not yet implemented;
* shipment workspace is not yet implemented;
* tracking runtime is not yet implemented;
* customer shipment timeline is not yet implemented;
* variable-product delivery capture is not yet complete;
* WooCommerce Blocks checkout support is not yet complete;
* optional real WPML/WCML/WCFM/VitePOS/WoodMart adapters are not yet complete.

Never confuse intended future design with this implementation baseline.

Before modifying an area, inspect its actual current code.

Mandatory reading order for every implementation stage:

1. `docs/PROJECT-GOVERNANCE.md` (this file)
2. `docs/DELIVERY-ENGINE-GOVERNING-RULES.md`
3. current status block in `docs/AI-HANDOFF.md`
4. current/previous stage documentation
5. current Design and Expectations documentation where applicable
6. actual repository implementation

---

# 4. Fundamental product architecture

The project is a WooCommerce delivery and fulfilment domain extension.

WooCommerce remains the transactional commerce owner.

## WooCommerce owns

Use WooCommerce's existing authoritative concepts wherever they mean the same thing:

* products;
* variations;
* SKU;
* product price;
* weight;
* dimensions;
* native shipping classes where useful;
* customer records;
* billing/shipping addresses;
* cart;
* WooCommerce sessions;
* checkout;
* taxes;
* coupons;
* payments;
* orders;
* order items;
* shipping lines;
* refunds;
* stock;
* customer accounts;
* standard emails;
* HPOS/order persistence;
* ordinary WooCommerce commerce lifecycle.

Do not create competing authoritative copies of these concepts.

## Delivery Engine owns

The Delivery Engine owns its distinct domain concepts, including:

* Fulfilment Availability;
* Fulfilment Choice;
* Delivery Offers;
* delivery-route eligibility;
* destination service logic;
* Delivery Engine rate-card logic;
* Logistics Profiles;
* private suppliers;
* private origins;
* detailed delivery ETA logic;
* delivery selection;
* delivery-domain configuration;
* configuration inheritance;
* shipment-domain information not appropriately owned by WooCommerce;
* tracking-domain data where implemented;
* delivery-domain audit information.

One authoritative owner must exist for each piece of data.

---

# 5. Native-first doctrine

For every requested feature, use this decision order:

WooCommerce/WordPress native capability
→ supported WooCommerce/WordPress extension API
→ Delivery Engine custom domain capability only where necessary

Do not recreate native WooCommerce functionality simply because creating another custom table or setting seems easier.

Examples:

Use WooCommerce product weight rather than `delivery_engine_weight`.

Use WooCommerce dimensions rather than duplicate dimensions.

Use WooCommerce customer shipping addresses rather than a separate Delivery Engine address record.

Use WooCommerce shipping packages/rates for genuine shipping charges.

Use WooCommerce CRUD for orders.

Use Delivery Engine custom functionality only when the concept is genuinely distinct.

---

# 6. Fulfilment-domain invariants

The following are hard domain rules.

## International Fulfilment

International Fulfilment means:

* customer-facing fulfilment is Delivery Only;
* Delivery is locked/preselected;
* Air and/or Sea may be available;
* Store Pickup is not valid;
* ordinary local delivery must not be presented as if the item were already locally stocked.

## In Store

In Store means:

* local fulfilment;
* Delivery may be available;
* Store Pickup may be available;
* Delivery is normally the default where both exist;
* Air is invalid;
* Sea is invalid.

When Store Pickup is selected:

* delivery offers disappear;
* delivery charge disappears;
* delivery ETA disappears;
* pickup information becomes authoritative.

## In Warehouse

In Warehouse means:

* Delivery Only;
* local delivery offers;
* no Store Pickup unless the business model is explicitly changed through an approved specification;
* Air is invalid;
* Sea is invalid.

Configurability never outranks these domain rules.

No global setting, product override, variation override, CSV import, REST call, bulk edit, or frontend submission may create an invalid combination.

---

# 7. Delivery is not a product variation

Never implement Air Shipping, Sea Shipping, Store Pickup, or another delivery route as a WooCommerce product variation merely to simplify implementation.

Do not create combinations such as:

Colour × Size × Shipping Mode

Existing WooCommerce product variations remain product characteristics.

Delivery remains a distinct fulfilment choice.

---

# 8. Shipping charges must remain genuine WooCommerce shipping

Do not disguise Delivery Engine shipping as:

* a hidden product-price increase;
* arbitrary cart fees;
* a product add-on;
* a $0 placeholder method combined with another charge.

The selected Delivery Offer must ultimately produce the correct genuine WooCommerce shipping charge for its managed shipment/package.

Do not double-charge.

Do not allow unrelated shipping methods to conflict with a Delivery Engine-managed package unless deliberately configured.

---

# 9. Server authority

The browser is never authoritative for:

* delivery price;
* rate-card selection;
* delivery eligibility;
* destination-zone determination;
* supplier;
* origin;
* internal carrier assignment;
* Logistics Profile;
* currency conversion result;
* consolidation behavior;
* final checkout totals.

Frontend JavaScript may submit identifiers and user selections.

The server must:

* resolve current effective configuration;
* validate eligibility;
* recalculate prices;
* recalculate ETA;
* verify destination;
* reject manipulated or stale state.

Never trust client-submitted authoritative prices.

---

# 10. No silent delivery substitution

Never silently replace a customer's selected Delivery Offer.

Examples of forbidden silent replacement:

* Air → Sea;
* Sea → Air;
* named carrier → different carrier;
* Delivery → Pickup;
* Pickup → Delivery;
* selected service level → another service level.

If a previous selection becomes invalid:

* clearly tell the customer;
* require a valid replacement selection where required;
* recalculate before payment.

---

# 11. Missing configuration must never become free delivery

A missing:

* Rate Card;
* destination-zone match;
* required price;
* required rule;
* required eligible offer;

must never silently result in `0`, free shipping, or an arbitrary fallback.

If a trustworthy required price cannot be resolved:

* reject the affected offer or checkout path;
* show a customer-safe message;
* log detailed administrator diagnostics.

Correctness is more important than completing checkout with an incorrect charge.

---

# 12. Global → Product → Variation inheritance

The foundational configuration model is:

GLOBAL
→ PRODUCT
→ VARIATION

The most specific valid explicit configuration normally wins.

Variation override

> Product override
> Global/default value

However, hard constraints always outrank ordinary overrides.

Inheritance is field-by-field.

Overriding one property must not detach the product or variation from unrelated inherited values.

---

# 13. Explicit inheritance state

Never infer inheritance solely from an empty/null database field.

Applicable scalar fields must support explicit states such as:

* `INHERIT`
* `OVERRIDE`
* `DISABLE`
* `NONE`

where logically appropriate.

Applicable collection fields must support explicit semantics such as:

* `INHERIT`
* `ADD`
* `REMOVE`
* `REPLACE`

A false/empty value and an inherited value must not be treated as equivalent.

Bulk operations and imports must preserve these semantics.

---

# 14. One authoritative Effective Configuration Resolver

The system must converge on one authoritative service:

`EffectiveConfigurationResolver`

Do not implement separate inheritance/resolution logic independently in:

* product frontend;
* cart;
* checkout;
* rate calculation;
* shipping package creation;
* order snapshots;
* admin previews;
* shipment planning;
* import tools;
* optional integrations.

The resolver must combine, as applicable:

WooCommerce native product/variation data
+
global Delivery Engine configuration
+
product overrides
+
variation overrides
+
explicit disable/none states
+
collection add/remove/replace rules
+
hard domain constraints
+
Logistics Profile constraints
+
destination rules
+
private supplier/origin restrictions
+
eligible Delivery Offers
+
rate-card precedence
+
currency context
+
ETA rules
+
consolidation rules

and return a deterministic effective result.

---

# 15. Effective configuration provenance

Internally, effective values should expose their source where useful.

Examples:

Fulfilment Availability:

* value: International Fulfilment
* source: Global

Processing:

* value: 5–7 business days
* source: Product

Logistics Profile:

* value: Bulky Item
* source: Variation

This provenance is for:

* administrator preview;
* diagnostics;
* audit;
* troubleshooting;
* impact analysis.

Do not expose private/internal provenance unnecessarily to customers.

---

# 16. Hard constraints override preferences

Not every setting obeys ordinary “most specific wins” behavior.

Safety and operational constraints combine conservatively.

Examples:

Global:
Air allowed

Logistics Profile:
Air prohibited

Product:
Air enabled

Effective:
Air prohibited.

Global:
May consolidate

Profile:
Must ship separately

Variation:
May consolidate

Effective:
Must ship separately.

Hard constraints must not be weakened through inheritance.

---

# 17. Deterministic rate precedence

Rate resolution must be deterministic.

General intended precedence:

Variation explicit rate/price override
→ Product explicit rate/price override
→ most-specific valid applicable Rate Card
→ more-general valid Rate Card
→ explicitly configured fallback
→ no valid price

No valid price does not mean free.

---

# 18. Deterministic offer eligibility

Offer resolution must be deterministic.

General sequence:

Start with globally applicable offers
→ apply Fulfilment Availability constraints
→ apply destination constraints
→ apply Logistics Profile constraints
→ apply supplier/origin constraints
→ apply product add/remove/replace rules
→ apply variation add/remove/replace rules
→ enforce hard constraints
→ validate final eligible set

The same result must be used across product page, cart, checkout, shipping, and order creation for the same effective context.

---

# 19. Historical snapshots are immutable

Inheritance applies to current configuration resolution.

It must never rewrite historical transactions.

At successful checkout/order persistence, snapshot the appropriate resolved delivery state.

Historical snapshots may include:

* Fulfilment Availability;
* Fulfilment Choice;
* Delivery Offer;
* route;
* service level;
* public carrier;
* customer-paid rate;
* currency;
* applicable rate/version context;
* processing estimate;
* transit estimate;
* final-mile estimate;
* customer ETA;
* destination/service-zone context;
* Logistics Profile;
* private supplier;
* private origin;
* consolidation context;
* relevant configuration versions.

Future configuration changes must not silently recalculate historical paid-order promises or charges.

---

# 20. Configuration versioning

Design configuration changes for version-aware cache invalidation and auditability.

Applicable concepts may include:

* global configuration version;
* product-rule version;
* variation-rule version;
* Delivery Offer version;
* Rate Card version;
* zone version.

Do not indiscriminately purge or rewrite thousands of inherited records when a versioned cache-invalidation approach is safer.

---

# 21. Private operational data

Supplier, origin, internal cost, margin, internal routing, consolidation keys, and private operational notes are not customer data.

Never expose them in:

* product pages;
* cart;
* checkout;
* mini-cart;
* customer emails;
* Thank You pages;
* My Account;
* public tracking pages;
* public REST/Store API responses;
* structured data;
* JSON-LD;
* SEO metadata;
* feeds;
* customer-visible order notes;
* crawler-visible hidden markup.

Access must be capability-controlled.

---

# 22. HPOS and WooCommerce order rules

All WooCommerce order operations must use WooCommerce-supported CRUD/data APIs.

Do not write business logic that assumes orders live in:

* `wp_posts`;
* `wp_postmeta`;
* specific HPOS table names.

Custom Delivery Engine tables may use `$wpdb` with:

* the dynamic site table prefix;
* prepared statements;
* proper indexes;
* explicit validation.

---

# 23. Theme independence

The Delivery Engine is a WooCommerce plugin, not a WoodMart plugin.

WooCommerce is the platform contract.

WoodMart is a first-class compatibility target.

No core business capability may depend on WoodMart.

Core modules must not contain WoodMart-specific logic for:

* pricing;
* fulfilment;
* inheritance;
* eligibility;
* destination resolution;
* ETA;
* consolidation;
* order snapshots;
* shipping-package calculations;
* security.

Use:

standard WooCommerce APIs/hooks/events
→ generic WooCommerce fallback
→ optional theme adapter only where necessary.

---

# 24. WoodMart compatibility boundary

WoodMart-specific functionality belongs in an isolated compatibility/integration adapter.

It may support:

* swatches;
* Quick View;
* Quick Shop;
* AJAX Add to Cart;
* sticky Add to Cart;
* Buy Now;
* mini-cart lifecycle;
* WoodMart presentation adjustments.

Do not:

* modify WoodMart parent-theme files;
* scatter WoodMart detection across core classes;
* hardcode WoodMart classes into domain logic;
* use XTemos private APIs as the core platform contract.

Removing WoodMart must not corrupt Delivery Engine configuration or transactional data.

---

# 25. Generic WooCommerce compatibility

The plugin must maintain a theme-neutral implementation using proper WooCommerce extension points.

The plugin must eventually prove that, without WoodMart:

* activation works;
* simple products work;
* variable products work;
* delivery selection works;
* cart persistence works;
* shipping works;
* checkout works;
* order snapshots work;
* shipment functionality works when implemented;
* emails work;
* My Account works.

Theme-specific failures must never alter business prices or fulfilment rules.

---

# 26. Optional integrations

The following must remain optional:

* WoodMart;
* WPML;
* WCML;
* WCFM;
* VitePOS;
* WooCommerce Blocks-specific presentation;
* carrier APIs;
* external tracking providers.

Absence of an optional integration must not fatal the plugin.

Optional integrations must live behind clear adapters/contracts.

The core must provide safe fallback behavior where specified.

---

# 27. Performance is a release requirement

Do not run expensive Delivery Engine behavior on unrelated requests.

Heavy modules should initialize contextually.

Examples:

Product frontend logic:
only where relevant.

Cart logic:
cart context.

Checkout/rate logic:
checkout/shipping context.

Shipment operations:
shipment/order surfaces.

Admin management:
Delivery Engine and relevant WooCommerce admin surfaces.

Avoid unnecessary work on:

* blog pages;
* static pages;
* unrelated wp-admin pages;
* login;
* unrelated REST requests;
* unrelated cron requests.

---

# 28. Asset-loading rules

Frontend Delivery Engine CSS/JavaScript must not be globally enqueued without need.

Load only on relevant surfaces.

Admin assets must only load on relevant admin pages.

Do not:

* ship duplicate copies of libraries WordPress/WooCommerce already provides where avoidable;
* load large admin bundles everywhere;
* leak frontend assets into admin;
* leak admin assets into frontend.

CSS and JavaScript must be namespaced/scoped.

Avoid broad selectors or globals that affect the theme/site.

---

# 29. Database performance

Avoid N+1 query patterns.

Prefer:

collect IDs
→ batch-load
→ resolve in request-local collections/cache.

Repeated effective configuration resolution within one request should reuse safe already-loaded data.

Custom tables must use indexes appropriate to real query patterns.

Large admin lists must:

* paginate;
* search/filter server-side;
* avoid loading entire tables into browser memory.

Do not repeatedly full-scan large operational tables for dashboard counts.

---

# 30. Caching

Cache reusable configuration only where safe.

Possible cache candidates include:

* Delivery Offers;
* Rate Cards;
* zones;
* Logistics Profiles;
* global configuration;
* context-independent resolved configuration fragments.

Never globally cache customer-specific:

* carts;
* destinations;
* selected Delivery Offers;
* session rates;
* checkout totals;
* customer currency contexts.

A cached value must include every variable capable of changing its result.

WP Rocket, Redis/object cache, CDN/proxy caching, WooCommerce sessions, and customer-specific contexts must coexist without cross-customer leakage.

---

# 31. AJAX/network behavior

Avoid unnecessary network chatter.

Use, where appropriate:

* debouncing;
* cancellation of stale requests;
* request deduplication;
* stale-response protection;
* server authority;
* batched resolution.

Do not recalculate expensive delivery rules on every keystroke unnecessarily.

Race conditions must not allow an older response to overwrite a newer selection.

---

# 32. SEO safety

The plugin is transactional and should minimally interfere with SEO.

Do not unnecessarily modify:

* canonical URLs;
* titles;
* descriptions;
* robots;
* sitemaps;
* breadcrumbs;
* product permalinks;
* product schema;
* Open Graph;
* feeds.

Do not create indexable product duplicates for delivery state such as:

`?shipping=air`
`?shipping=sea`
`?carrier=...`

unless a future explicit SEO design requires it.

Private logistics must never be exposed through SEO surfaces.

Core product content must not become dependent on Delivery Engine JavaScript.

---

# 33. Accessibility

Customer and administrator UI must use accessible interaction patterns.

Requirements include, where applicable:

* semantic HTML;
* keyboard operation;
* visible focus;
* properly associated labels;
* accessible radio groups;
* sufficient contrast;
* no color-only meaning;
* useful error association;
* appropriate touch targets;
* screen-reader-compatible dynamic updates;
* `aria-live` or equivalent where material price/ETA state changes dynamically;
* reduced-motion respect where animation exists.

Accessibility defects are real defects.

---

# 34. UI/UX quality

The plugin must provide professional interfaces for:

* administrators;
* logistics staff;
* product staff;
* customer service;
* logged-in customers;
* guests.

Admin interfaces must use:

* progressive disclosure;
* effective-value previews;
* clear inherited-value presentation;
* actionable errors;
* useful empty states;
* sensible defaults;
* efficient common workflows;
* permission-aware controls;
* bulk tools where appropriate.

Do not expose internal logistics terminology unnecessarily to customers.

Customer-facing offers should focus on:

* what the choice is;
* price;
* relevant processing/transit information;
* estimated delivery to their address;
* pickup details where applicable.

---

# 35. Mobile behavior

Customer-facing delivery functionality must be designed and tested for mobile.

No:

* horizontal overflow;
* tiny radio controls;
* hover-only critical information;
* unreadable estimates;
* unstable Add to Cart positioning;
* inaccessible controls.

Mobile is a release surface, not a later enhancement.

---

# 36. Failure isolation

Optional integration failures should degrade gracefully where possible.

Examples:

WPML absent:
use normal site language.

WCML absent:
use WooCommerce store currency.

WoodMart absent:
use generic WooCommerce behavior.

But transactional delivery failures must fail safely.

If price or eligibility cannot be trusted:

block the affected transaction rather than guess.

---

# 37. Security requirements

Every sensitive operation requires appropriate:

* capability checks;
* nonce/CSRF protection where applicable;
* input validation;
* sanitization;
* output escaping;
* prepared SQL;
* order ownership checks;
* REST permission callbacks;
* URL validation;
* audit logging where appropriate.

Assume hostile manipulation of:

* offer IDs;
* prices;
* pickup state;
* routes;
* destination;
* currency;
* tracking updates;
* private source identifiers.

Frontend state is untrusted.

---

# 38. Capabilities and permission-aware UX

Do not rely only on Administrator role checks.

Use granular Delivery Engine capabilities.

Users should not see sensitive controls they are unauthorized to use.

Examples:

Customer Support:
must not change Rate Cards.

Product staff:
may not be allowed to view private supplier cost.

Vendor:
no global/private delivery access by default.

Authorization must be enforced server-side even if controls are hidden.

---

# 39. Migration requirements

Every database migration must be:

* versioned;
* idempotent;
* retry-safe;
* non-destructive by default;
* tested;
* documented;
* batchable where large.

Before implementing a schema change:

* inspect current schema;
* describe the migration;
* describe existing-data behavior;
* describe rollback implications;
* verify historical order snapshots are unaffected.

Never silently destroy business data.

---

# 40. Deactivation and uninstall

Deactivation must not delete business data.

Uninstall behavior must be deliberate.

Historical transactional data must not disappear merely because the plugin is temporarily deactivated.

Permanent destructive cleanup, if supported, must require explicit administrator intent and clear warnings.

---

# 41. Feature flags

Use feature flags for risky runtime functionality.

Existing feature flags must remain safe.

New functionality should not unexpectedly take over production behavior merely because the plugin updates.

Activation/update must not silently change storefront behavior without intended configuration.

Feature flags are an incident-recovery mechanism as well as a rollout mechanism.

---

# 42. Development workflow for every phase

Every substantial coding task follows:

READ
→ AUDIT
→ PLAN
→ IMPLEMENT
→ TEST
→ DOCUMENT
→ REVIEW
→ STOP

## READ

Before coding:

1. Read this `PROJECT-GOVERNANCE.md`.
2. Read `docs/DELIVERY-ENGINE-GOVERNING-RULES.md`.
3. Read the current status block in `docs/AI-HANDOFF.md`.
4. Read the immediately preceding stage report for the requested area.
5. Read `docs/ARCHITECTURE-PLAN.md` when architecture or module boundaries are relevant.
6. Read the latest Design and Expectations specification when intended product behaviour is relevant.
7. Read all phase/stage documents directly relevant to the requested area.
8. Inspect actual current code.
9. Audit branch, HEAD, remote, working tree, tags, plugin version, and schema target.

Do not assume a class/file/function exists because documentation mentions it.

## AUDIT

Determine:

* current implementation;
* relevant existing tests;
* dependencies;
* schema implications;
* customer-facing implications;
* performance implications;
* privacy/security implications;
* compatibility implications.

## PLAN

Before a large change, describe:

* exact scope;
* files/classes likely affected;
* proposed architecture;
* data migration;
* backward compatibility;
* tests;
* risks.

Avoid unrelated refactors.

## IMPLEMENT

Implement only the requested phase.

Do not opportunistically continue into the next roadmap phase.

## TEST

Run appropriate existing tests plus new tests.

Do not delete or weaken existing tests merely to get green results unless a test is genuinely obsolete due to an approved specification change.

## DOCUMENT

Update relevant implementation documentation.

Document:

* what changed;
* why;
* schema changes;
* flags;
* known limitations;
* risks;
* staging tests to repeat.

## REVIEW

Check:

* correctness;
* privacy;
* shipping totals;
* security;
* HPOS;
* compatibility;
* performance;
* UX;
* accessibility where relevant.

## STOP

After completing the requested phase, report results and stop.

Do not automatically start another phase.

---

# 43. Branching/change discipline

Do not make broad unrelated modifications to `master`.

Prefer small, reviewable development branches/phases.

Each phase should be independently understandable and testable.

Do not combine unrelated architectural changes into one enormous commit.

Preserve a known-good baseline before substantial schema/runtime changes.

---

# 44. No speculative refactoring

Do not rewrite working code merely because another style appears cleaner.

Refactor when it:

* enables the approved architecture;
* removes demonstrated duplication;
* fixes a defect;
* improves required maintainability;
* is necessary for a current phase.

Every refactor must preserve observable behavior unless behavior is deliberately changing.

---

# 45. No giant classes or duplicated domain logic

Keep modular-monolith boundaries.

Avoid:

* God classes;
* giant procedural files;
* domain calculations in templates;
* pricing logic duplicated across surfaces;
* inheritance duplicated across surfaces;
* direct integration-plugin calls spread throughout the core.

Presentation calls application/domain services.

Adapters isolate external/optional concerns.

---

# 46. Testing doctrine

A feature is not done because its happy path works manually.

Use the applicable combination of:

* unit tests;
* integration tests;
* WooCommerce tests;
* migration tests;
* permissions/security tests;
* browser/E2E tests;
* accessibility checks;
* performance regression checks;
* staging smoke tests.

Tests must include negative/error paths.

---

# 47. Required regression concerns

Every relevant phase must consider regression of:

* simple products;
* variable products where supported;
* cart-line uniqueness;
* session restoration;
* destination changes;
* shipping totals;
* mixed carts;
* Store Pickup;
* customer-safe metadata;
* HPOS order persistence;
* emails;
* My Account;
* guest checkout;
* logged-in checkout;
* theme compatibility;
* caching/session isolation.

---

# 48. Performance release gate

For substantial runtime changes, compare representative pages before and after where practical.

Relevant surfaces include:

* homepage;
* product archives;
* unmanaged product;
* managed simple product;
* managed variable product;
* cart;
* checkout;
* My Account;
* Delivery Engine admin;
* product editor;
* shipment workspace when implemented.

Investigate material regressions in:

* execution time;
* DB query count;
* slow queries;
* memory;
* asset size;
* response size;
* REST/AJAX requests;
* checkout/rate latency;
* layout stability.

Do not dismiss unexplained performance degradation as cosmetic.

---

# 49. Staging before production

Do not deploy substantial Delivery Engine changes directly to production without appropriate staging validation.

Staging should represent production closely enough to test:

* WooCommerce;
* theme;
* WoodMart where applicable;
* WPML/WCML state;
* Redis;
* WP Rocket;
* HPOS;
* checkout mode;
* payment flow;
* product/variation behavior.

Pilot rollout should precede broad catalog rollout for risky customer-facing functionality.

---

# 50. Current forward-development dependency order

Unless a later approved roadmap changes the order, new work should generally progress through these dependencies:

1. Verify/freeze known-good RC baseline.
2. Reconcile latest design against current implementation.
3. Global configuration/inheritance foundation.
4. Authoritative Effective Configuration Resolver.
5. Admin inheritance/effective-value UX.
6. Migrate current simple-product runtime to the resolver.
7. Variable-product delivery capture.
8. Generic WooCommerce frontend compatibility.
9. WoodMart compatibility adapter.
10. Correct package/consolidation planning.
11. Shipment domain/schema.
12. Idempotent shipment creation.
13. Staff shipment operations.
14. Customer shipment/tracking surfaces.
15. Bulk inheritance-aware tools/import/export.
16. Optional WPML/WCML integrations.
17. Optional WCFM/VitePOS integrations.
18. WooCommerce Blocks adapter.
19. Full quality/performance/accessibility/SEO hardening.
20. Staged release/pilot/production promotion.

Do not skip foundational dependencies merely to reach a visible feature faster.

---

# 51. Current exclusions and sequencing safeguards

Do not implement a future module merely because it appears in the full product specification if the current requested phase does not require it.

In particular, shipment/tracking features must not be used to hide unresolved checkout/package architecture defects.

Optional integrations must not be implemented before the core is independently correct.

Theme-specific behavior must not be implemented before a generic WooCommerce contract exists.

Blocks support must not be declared merely because a backend shipping hook happens to run under Blocks.

---

# 52. Coding-agent reporting format

After every implementation task, report:

## Summary

What was implemented.

## Scope

What was intentionally not implemented.

## Files changed

Exact files.

## Database/schema

Any migration or schema effect.

## Runtime behavior

What changed from the user's perspective.

## Security/privacy review

Relevant findings.

## Shipping integrity review

Rate/selection/package/snapshot implications.

## Compatibility review

WooCommerce, HPOS, theme and optional integration considerations.

## Performance review

Queries/assets/runtime implications.

## Tests

Commands/tests run and results.

## Documentation

Documents updated.

## Staging checks required

Exact manual/regression checks that should be rerun.

## Known limitations

Anything remaining.

## Recommended next phase

State it, but do not implement it automatically.

---

# 53. Coding-agent prohibited behavior

An agent must not:

* invent undocumented product requirements;
* silently change established business rules;
* implement several major roadmap phases without permission;
* bypass WooCommerce APIs for convenience;
* expose supplier/origin information publicly;
* trust browser prices;
* return zero shipping because configuration is missing;
* silently replace selected delivery;
* flatten inherited configuration into every product;
* duplicate native WooCommerce authoritative data;
* make WoodMart a hard dependency;
* edit WoodMart parent-theme files;
* globally enqueue unnecessary plugin assets;
* create broad CSS selectors that affect the site;
* use background jobs for ordinary synchronous cart/checkout decisions;
* create public shipment/order pages without explicit privacy design;
* destroy historical data during migrations;
* overwrite historical order snapshots because current configuration changed;
* enable incomplete runtime functionality by default;
* bypass capabilities/nonces/validation;
* weaken tests merely to make a build pass;
* claim support for something that has not been tested;
* claim a phase is complete when known acceptance criteria fail.

---

# 54. Definition of Done

A phase is complete only when its requested behavior:

* is functionally correct;
* preserves domain rules;
* preserves WooCommerce authority;
* preserves shipping integrity;
* preserves historical snapshot integrity;
* is secure;
* preserves private operational data;
* is HPOS-safe;
* does not introduce unacceptable performance regression;
* does not create SEO regressions;
* provides appropriate UX;
* is accessible where user-facing;
* works in its declared theme/integration scope;
* has tests;
* has updated implementation documentation;
* has clear staging validation requirements.

The whole plugin is not complete merely because delivery selection works.

The target is a reusable, production-grade WooCommerce Delivery & Fulfilment Engine.

---

# 55. Governing doctrine

Every contributor must follow:

> Configure globally once, inherit everywhere by default, override only where necessary, preserve hard restrictions, use WooCommerce core whenever it already solves the underlying problem, and snapshot the final resolved state when a transaction becomes historical.

And:

> Develop deeply against WoodMart because it is the primary real-world environment, but architect against WooCommerce because WooCommerce—not WoodMart—is the platform dependency.

And:

> Correctness before convenience. Shipping integrity before checkout completion. Server authority before frontend trust. Privacy before implementation shortcuts. Measured quality before release.

---

# 56. Final instruction to coding agents

Before changing code, follow the mandatory pre-task procedure in `docs/DELIVERY-ENGINE-GOVERNING-RULES.md`.

Understand the current implementation.

Do not assume the full product vision already exists.

Do not assume an older implementation limitation is a permanent product requirement when the latest design explicitly supersedes it.

Do not bypass foundations to reach later features.

Work one approved phase at a time.

When uncertain:

* inspect the code;
* inspect the authoritative documentation;
* identify the uncertainty;
* choose the smallest change that preserves project invariants.

Do not continue into the next phase without explicit instruction.
