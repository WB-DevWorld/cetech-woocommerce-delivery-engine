# Post-RC Architecture Gap Analysis

**Document status:** Authoritative Stage 1 analysis artifact  
**Date:** 2026-08-10  
**Plugin:** CETECH WooCommerce Delivery Engine `1.0.0-rc.1`  
**Schema target:** `2`  
**Verified baseline commit:** `31fc0160fcf3866107c32d195a2aba35ccf8c1c9` (`docs: complete FLAIROC RC baseline verification`)  
**Scope:** Analysis only — no production code, schema, migrations, flags, or version changes in this stage

---

## 1. Executive Verdict

Stage 0B established a **verified, working classic-checkout simple-product transaction path** on FLAIROC. Stage 1 confirms that path is architecturally sound as a **shipping-integrity and privacy baseline**, but the configuration model is **not yet the intended Global → Product → Variation field-level inheritance system**.

| Question | Verdict |
|----------|---------|
| Is the RC transaction path retainable? | **Yes** — retain bootstrap, flags, repos, selector→cart→checkout→shipping→snapshot pipeline |
| Does Global → Product → Variation field inheritance exist? | **No** — current rules are whole-record winners by target specificity |
| Does `EffectiveConfigurationResolver` exist? | **No** — split across `ProductDeliveryRuleResolver`, `ProductDeliveryOptionsBuilder`, zone matcher, `RateQuoteEngine` |
| Can current `product_delivery_rules` support field inheritance cleanly? | **No without structural extension** — nullable variation rows alone are insufficient |
| Safest next work? | Stage 2: global + scoped inheritance **storage** behind flags; do not cut over runtime yet |
| Should Stage 2 touch shipping/snapshots? | **No** — Do-Not-Touch-Yet |

**Overall:** Stage 1 is **COMPLETE**. Proceed to Stage 2 only after this document is accepted as the roadmap authority for post-RC work.

---

## 2. Verified Starting Point

From `docs/POST-RC-BASELINE-VERIFICATION.md` (Stage 0B **VERIFIED**) and live FLAIROC evidence:

| Fact | State |
|------|-------|
| Plugin version | `1.0.0-rc.1` |
| Schema | target `2` |
| HPOS | Compatible; order path via WooCommerce CRUD |
| Classic checkout | Works |
| Expected vs actual shipping | `25.00` = `25.00` |
| Shipping line | Real WooCommerce method `delivery_engine_selected_offer` |
| Protected snapshot | Written (`_cetech_de_*`) |
| Snapshot immutability | PASS when QA rate later changed |
| Server authority | PASS |
| No silent replacement | PASS |
| No accidental free shipping | PASS |
| Privacy (tested customer surfaces) | PASS |
| Runtime flags | Can return OFF; currently OFF after close-out |
| Shipment tables / runtime | Absent |
| Variable-product capture | Incomplete / deferred |
| Blocks adapter | Unwired |

**Rule for all later stages:** do not redesign verified working behaviour merely because another design is aesthetically cleaner. Evolve the RC safely.

---

## 3. Source-of-Truth Interpretation

Conflict resolution followed `docs/PROJECT-GOVERNANCE.md`:

| Concern | Authority |
|---------|-----------|
| Intended product / end-state | Latest `docs/Delivery Shipping Plugin Up-To-Date Design and Expectations.md` |
| What exists today | Repository code + completed phase docs + Stage 0B verification |
| Process / invariants | `docs/PROJECT-GOVERNANCE.md` |
| Hard engineering rules | `docs/PROJECT-RULES.md` |
| Modular-monolith layout | `docs/ARCHITECTURE-PLAN.md` (unless a later approved architecture phase changes it) |

**Important reconciliation:** Design §9 describes a broad `EffectiveConfigurationResolver` that also resolves rate cards, currency, price, ETA, and consolidation. Governance and this Stage 1 analysis require **layer separation** so the resolver does not become a god-object:

1. **Configuration resolution** (inheritance + provenance + validation state)  
2. **Hard fulfilment constraints**  
3. **Offer eligibility**  
4. **Rate calculation**  
5. **Checkout validation / shipping adapter**

Design intent (one authoritative config brain) is preserved; pricing and package construction remain downstream consumers.

Older phase docs describing “variation → product → category whole-record rules” describe **current implementation**, not the intended end-state.

---

## 4. Current Architecture Map

### 4.1 Boot and container

```text
cetech-woocommerce-delivery-engine.php
  → Bootstrap\Plugin::boot()
      → FeaturesCompatibility (HPOS declare)
      → ServiceContainer bindings
      → MigrationRunner (schema → 2)
      → AdminMenu + admin pages
      → IntegrationRegistry::detect() (NullIntegration only)
      → Runtime register (flag-gated):
           Selector → Cart → Checkout → Shipping → Snapshot → Customer/Email
```

| Area | Primary classes | Retain? |
|------|-----------------|---------|
| Bootstrap | `Plugin`, `ServiceContainer`, `FeatureFlags`, `Activator`, `Deactivator`, `Uninstaller` | **Retain** |
| Migrations | `MigrationRunner`, `SchemaVersion` (TARGET=2), `database/migrations/*` | **Retain** |
| Persistence | `TableNames`, `AbstractWpdbRepository`, `Wpdb*` repos | **Retain / extend** |
| Product rules | `ProductDeliveryRuleResolver`, `ResolvedProductDeliveryRule` | **Retain as ancestor; evolve role** |
| Offers UI/runtime | `ProductDeliveryOptionsBuilder`, `ProductDeliverySelectionValidator` | **Retain / extend** |
| Destination | `DestinationZoneMatcher`, `PackageDestinationZoneResolver` | **Retain** |
| Rates | `RateQuoteEngine`, `SelectedOfferShippingRateCalculator`, `SelectedOfferShippingMethod` | **Retain** (dedupe admin tester later) |
| Cart | `CartDeliverySelectionCapture`, fingerprint/session/revalidator | **Retain** |
| Checkout | `CheckoutDeliverySelectionValidator` | **Retain** |
| Snapshots | `OrderDeliverySnapshot*`, admin/customer/email renderers | **Retain** |
| Integrations | `IntegrationRegistry`, `NullIntegration` | **Retain boundary; adapters later** |

### 4.2 Canonical implemented pipeline

```text
Product delivery rule (whole-record)
→ ProductDeliveryOptionsBuilder (offer list / pickup)
→ ProductDeliverySelectorRenderer (simple product)
→ CartDeliverySelectionCapture + fingerprint
→ CartDeliverySelectionRevalidator (warn; no silent replace)
→ CheckoutDeliverySelectionValidator
→ PackageDestinationZoneResolver + RateQuoteEngine
→ SelectedOfferShippingMethod (label “Delivery”)
→ OrderDeliverySnapshotPersister (_cetech_de_* meta)
→ Admin / customer / email summaries
```

### 4.3 Architectural relationships (not a file tree)

- **Admin CRUD** writes configuration tables and audit_log; does not participate in storefront pricing.
- **ProductDeliveryRuleResolver** is the only product-rule hierarchy engine; selector, cart, and selection validator all call it (good concentration; wrong abstraction vs latest design).
- **Options builder** maps chosen rules → public options; it does **not** enforce Air/Sea vs fulfilment-state hard constraints beyond whatever offer IDs staff attached.
- **Shipping calculator** revalidates cart selection, resolves destination zone, quotes per managed line, sums amounts; missing quote → blocked (no `add_rate`).
- **Snapshot builder** re-quotes at order time into protected meta; customer projections strip private fields.
- **No** `woocommerce_cart_shipping_packages` splitter / consolidation planner exists.

---

## 5. Current Data Model

Prefix: `{$wpdb->prefix}delivery_engine_*`. No DB-level FOREIGN KEYs (logical FKs + indexes only).

| Table | Purpose | PK | Notable columns / rules | Runtime usage |
|-------|---------|----|-------------------------|---------------|
| `delivery_offers` | Reusable customer offers | `id` | UNIQUE `internal_code`; `route`, `service_level`, `public_label`, ETA defaults, `status` | Options builder + snapshots |
| `destination_zones` | Geographic contexts | `id` | UNIQUE code; `is_fallback`, `priority`, `status` | Zone matcher |
| `destination_rules` | Zone match rules | `id` | `zone_id`, `rule_type`, `rule_value`, `match_mode`, `priority` | Zone matcher |
| `logistics_profiles` | Operational profile | `id` | `route_eligibility`, consolidation/dispatch fields | Optional rate-card dimension; not full constraint engine |
| `suppliers` | Private supplier | `id` | contact + notes; `status` | Admin + rule FK; never customer |
| `origins` | Private origin | `id` | `supplier_id`, address, lead days | Admin + rule FK |
| `pickup_locations` | Public pickup info | `id` | public address/hours/instructions | Admin; limited runtime wiring |
| `rate_cards` | Manual prices | `id` | `delivery_offer_id`, `destination_zone_id`, optional LP/supplier/origin, `charge_type`, `base_amount` default **0.0000**, unused columns (`free_shipping_threshold`, weight bands, etc.) | `RateQuoteEngine` |
| `rate_card_rules` | Extensible rule KV | `id` | `rate_card_id`, `rule_key`, `rule_value` | **Unused** (no repository writers) |
| `audit_log` | Config change audit | `id` | actor, action, entity, previous/new JSON | Admin saves |
| `product_delivery_rules` | Product/variation/category rules | `id` | `target_type`, `target_id`, fulfilment fields, JSON `delivery_offer_ids`, LP/supplier/origin, `priority`, `status` | Resolver + runtime |

**Not present:** `shipments`, `shipment_items`, `shipment_events`, global configuration table, scoped field-override tables, configuration version table.

### 5.1 Inheritance structural fitness

Current `product_delivery_rules` stores a **complete rule row** per target. Resolution picks **one winning row per fulfilment_availability** by specificity (variation > product > category), priority, then ID.

That is **whole-record replacement**, not field-by-field inheritance.

| Requirement | Supported today? |
|-------------|------------------|
| Explicit scalar INHERIT / OVERRIDE / DISABLE | **No** — absence of a variation row means inherit whole parent rule; empty JSON vs DISABLE indistinguishable for collections |
| Collection ADD / REMOVE / REPLACE | **No** — only a full `delivery_offer_ids` list on the winning row |
| Global defaults with zero product config | **No** — no global config store; unconfigured product → no match |
| Variation overrides one field only | **No** — variation rule must restate full rule semantics |
| Provenance per field | **No** — only “which rule ID won” |

**Conclusion:** Adding nullable `variation_id` columns to the existing row shape is **not sufficient**. A scoped field/collection override model (or equivalent hybrid) is required.

---

## 6. Requirement-to-Code Gap Matrix

| Requirement / concept | Classification | Evidence | Current behaviour | Target behaviour |
|----------------------|----------------|----------|-------------------|------------------|
| Classic simple-product checkout path | **IMPLEMENTED + VERIFIED** | Stage 0B; shipping/snapshot classes | End-to-end works | Preserve behaviour while migrating internals |
| Server authority | **IMPLEMENTED + VERIFIED** | Capture/validator/shipping/quote | Client IDs only; server re-resolves | Keep |
| No silent offer replacement | **IMPLEMENTED + VERIFIED** | Revalidator + checkout block | Warn / block | Keep |
| No accidental free shipping on missing rate | **IMPLEMENTED + VERIFIED** | `RateQuoteEngine` failure; shipping no `add_rate` | Block | Keep; distinguish configured $0 |
| Protected order snapshots + immutability | **IMPLEMENTED + VERIFIED** | `_cetech_de_*`; Stage 0B | Immutable after write | Extend carefully; never rewrite history |
| HPOS declare + CRUD order meta | **IMPLEMENTED + VERIFIED** | `FeaturesCompatibility`; snapshot CRUD | Works | Keep; fix postmeta-only delete guard later |
| Admin config CRUD (offers/zones/LP/suppliers/origins/pickup/rate cards/rules) | **IMPLEMENTED BUT NOT LIVE-VERIFIED** as full matrix | Admin pages | CRUD works; Stage 0B exercised subset | Retain; inheritance UX later |
| Feature flags default OFF | **IMPLEMENTED + VERIFIED** | `FeatureFlags`; Stage 0B dormant PASS | Safe | Keep for cutovers |
| Product rule hierarchy (var→product→category) | **PARTIAL** | `ProductDeliveryRuleResolver` | Whole-record | Replace semantics with field inheritance under new model |
| Global configuration | **MISSING** | No table/options model for DE globals | Products must be fully configured | Store-wide defaults |
| Field-level inheritance modes | **MISSING** | No INHERIT/OVERRIDE/DISABLE storage | Infer from winning row | Explicit modes |
| EffectiveConfigurationResolver | **MISSING** | Split services | Multiple entry points share rule resolver only | One config resolver + layered consumers |
| Hard fulfilment constraints engine | **PARTIAL / CONFLICTS WITH LATEST DESIGN** | Options built from staff-attached offer IDs | Invalid combos possible if misconfigured | Hard constraints outrank overrides |
| Deterministic offer eligibility pipeline | **PARTIAL** | Options builder filters active offers by ID list | Destination/LP constraints incomplete at option build | A→F pipeline (§10) |
| Rate quote engine | **IMPLEMENTED + VERIFIED** (happy path) | `RateQuoteEngine` | Fixed per shipment/item | Keep; expand charge types later |
| AdminRateCardTester duplication | **PARTIAL** | Parallel matcher | Weaker validation than engine | Converge on engine |
| Variable product admin rules | **IMPLEMENTED BUT NOT LIVE-VERIFIED** | `ProductTargetType::Variation` | Admin can store variation rules | Needed for Stage 6 |
| Variable product storefront capture | **STUB / RESERVED** | Selector notice; `should_apply_capture` false for variable | No radios/AJAX | Stage 6 after simple cutover |
| Package consolidation / split | **MISSING** | No `woocommerce_cart_shipping_packages` hook | Single WC package behaviour | Stage 8 |
| Shipment domain | **STUB / RESERVED** | Flags only | No tables | After packages + resolver stable |
| WoodMart adapter | **STUB / RESERVED** | Detection only | Generic WC hooks | Stage 7 |
| WPML/WCML/WCFM/VitePOS | **STUB / RESERVED** | Detection + Null | Core independent | Stage 10 |
| Blocks adapter | **STUB / RESERVED** | Flag only | Unwired | Stage 10 |
| Automated tests / CI | **MISSING** | Empty tests; no PHPCS/PHPStan/CI | Manual smoke only | Staged quality ladder |
| Frontend asset pipeline | **MISSING** | No `assets/` build | PHP-rendered HTML | Add when JS variation UX needs it |
| `rate_card_rules` table | **DEPRECATED BY LATEST DESIGN?** / unused | Schema present, no writers | Dead schema | Keep until design decides; do not build on it blindly |
| Category rules as inheritance layer | **DESIGN DECISION REQUIRED** | Resolver walks categories; flag unused as gate | Competing with Global model | Decide category vs global relationship |

---

## 7. Inheritance Architecture Gap

### 7.1 Latest required model

```text
GLOBAL → PRODUCT → VARIATION
```

- Field-by-field  
- Scalar: `INHERIT` | `OVERRIDE` | `DISABLE` / `NONE` where applicable  
- Collections: `INHERIT` | `ADD` | `REMOVE` | `REPLACE`  
- Hard constraints outrank ordinary overrides  

### 7.2 Concept participation map

| Concept | Inheritance role | Notes |
|---------|------------------|-------|
| Fulfilment availability | Scalar OVERRIDE/INHERIT | One effective value |
| Delivery enabled / Delivery Only | Scalar + constraint | International/Warehouse imply delivery-only |
| Store pickup availability | Scalar DISABLE meaningful | Empty ≠ inherit |
| Default fulfilment choice | Scalar | Delivery default when both available (In Store) |
| Allowed offers | Collection ADD/REMOVE/REPLACE | Primary collection |
| Logistics profile | Scalar / ID OVERRIDE | Constraint source for routes/consolidation |
| Supplier / origin | Scalar ID OVERRIDE | Private; never customer |
| Pickup location | Scalar ID OVERRIDE | Public label/address only to customer |
| ETA/timing overrides | Scalar ranges OVERRIDE | Offer defaults + scoped overrides |
| Customer labels | Prefer offer public_label; scoped presentation overrides optional | **DESIGN DECISION REQUIRED** for product-specific label overrides |
| Rate-card relationship | Prefer global rate cards matched by offer+zone+dimensions | Product/variation **price override** is separate scalar if retained |
| Destination applicability | Mostly global zones/rules; product may restrict offers | Not per-product zone tables |
| International Air/Sea | Constrained by fulfilment availability + LP | Hard layer |
| Same-day express | Offer + manual rate card | No live carrier API |

### 7.3 DESIGN DECISION REQUIRED

1. **Category layer:** Keep as optional soft layer under Global, deprecate in favour of Global, or retain only for bulk targeting tools?  
2. **Product-specific public label overrides:** Needed or always use offer `public_label`?  
3. **Multiple fulfilment_availability rows today:** Current model can choose one rule per availability enum value simultaneously — does end-state allow one product to expose multiple availability states, or exactly one? Design examples imply one effective Fulfilment Availability.  
4. **Pickup location collection vs single default:** Single default + optional overrides vs location set mutations.

---

## 8. EffectiveConfigurationResolver Target

### 8.1 Where resolution lives today

| Concern | Current owner |
|---------|---------------|
| Product/variation/category rule pick | `ProductDeliveryRuleResolver::resolve` |
| Customer options | `ProductDeliveryOptionsBuilder::buildFromResolution` |
| Selection validity | `ProductDeliverySelectionValidator` |
| Destination zone | `DestinationZoneMatcher` / `PackageDestinationZoneResolver` |
| Rate | `RateQuoteEngine::quote` (+ duplicate `AdminRateCardTester`) |
| Defaults | Schema defaults / empty lists / missing → failure or unavailable option |
| Fallbacks | Zone `is_fallback`; no site-wide fulfilment fallback wired (`enable_site_fallback_rule` unused) |

Callers of product-rule resolution: selector, cart capture, selection validator, admin rules page. They share one class (good) but that class is **not** the intended ECR.

### 8.2 Target responsibility boundary

**Owns:**

- Load global scoped configuration  
- Apply product field/collection mutations  
- Apply variation field/collection mutations  
- Resolve explicit DISABLE/NONE  
- Produce effective scalars + collections  
- Attach provenance per field  
- Attach configuration version / fingerprint inputs  
- Emit validation state + reason codes for invalid config  
- Expose resolved IDs (LP, supplier, origin, pickup, offer set) for downstream services  

**Must NOT own:**

- Customer HTML/JS rendering  
- Cart session writes  
- WooCommerce `add_rate`  
- Order meta persistence  
- Live currency conversion policy beyond providing base amounts / version hooks  
- Shipment creation  
- Package consolidation algorithms (may expose consolidation **inputs**)  
- Destination geo-matching (consume zone context; do not reimplement matcher)  
- Final monetary quote math (delegate to `RateQuoteEngine` / future `DeliveryQuoteService`)

### 8.3 Proposed inputs

| Input | Required? |
|-------|-----------|
| Product ID | Yes |
| Variation ID (nullable) | Yes when variation context |
| Global configuration snapshot / version | Yes |
| Optional destination context | Only when caller needs destination-sensitive effective fragments; prefer separate eligibility pass |
| Request/runtime flags | Minimal — prefer callers gate features |

### 8.4 Proposed outputs (`EffectiveConfiguration`)

| Output | Purpose |
|--------|---------|
| Effective scalars | Fulfilment, timings, IDs, flags |
| Effective collections | Offers, allowed routes, etc. |
| Provenance map | Per-field source |
| Resolved entity IDs | LP/supplier/origin/pickup |
| Configuration version / fingerprint | Cache + snapshot |
| Validation state + reason codes | Admin + runtime gates |

### 8.5 Layer split (anti-god-object)

```text
EffectiveConfigurationResolver
        ↓ EffectiveConfiguration
FulfilmentConstraintService   (hard domain rules)
        ↓ constrained config
OfferEligibilityService       (+ destination, LP, supplier filters)
        ↓ eligible offers
RateResolver / RateQuoteEngine
        ↓ quote result (or unresolved)
DeliveryQuoteService / Shipping adapter / Checkout validation
```

Design doc’s long ECR diagram is the **logical pipeline**; code should keep these as collaborating services with one config entry point.

---

## 9. Provenance and Versioning

### 9.1 Provenance model (minimum useful)

For each effective field:

```text
value
mode (inherit|override|disable|none|add|remove|replace)
source_scope (global|product|variation|system_default|constraint)
source_ref (optional entity/rule id)
```

| Concern | Recommendation |
|---------|----------------|
| Generation | **Dynamic** at resolve time |
| Persist in config tables | Store **modes + override payloads**, not computed provenance |
| Order snapshots | Persist **resolved values** + versions; optionally compact provenance for ops; never customer-visible |
| Admin preview | Show provenance |
| Customer surfaces | **Exclude** |

Safest model: dynamic provenance + persisted override modes + snapshot of resolved customer/ops values at checkout.

### 9.2 Configuration versioning

| Mechanism | Recommendation |
|-----------|----------------|
| Global config version | Monotonic integer (or timestamp+counter) in options/table row |
| Scoped product/variation versions | Bump on that scope’s save |
| Entity versions (offer/rate/zone/LP) | Bump on entity save |
| Effective fingerprint | Hash of (product, variation, global_ver, product_ver, variation_ver, relevant entity vers, destination zone id when included) |
| Persist hash in customer HTML/JS | **Never** |
| Snapshot | Store version numbers + customer-safe resolved fields; internal fingerprint optional in protected meta |

**Invalidation:** bump versions on writes; cache keys include versions — **no routine global flush**.

Avoid forcing every product recompute on global change: request-time resolve + versioned cache entries expire naturally.

---

## 10. Constraint / Offer / Rate Resolution

### 10.1 Hard constraint precedence (deterministic)

Examples from governance/design:

| Fulfilment | Customer modes | Forbidden |
|------------|----------------|-----------|
| International | Delivery only; Air and/or Sea | Store pickup; local-as-stocked presentation |
| In Store | Delivery and/or pickup; Delivery default if both | Air/Sea |
| In Warehouse | Delivery only; local offers | Air/Sea; pickup unless approved change |
| Store Pickup selected | Remove delivery offers, delivery charge, delivery ETA; show pickup readiness/location | Delivery shipment for that line |
| Same-day express | Manual configured checkout price | Live carrier quote requirement |

**Enforcement placement:**

| Layer | Responsibility |
|-------|----------------|
| Resolver | Produce candidate effective settings; mark illegal override attempts as invalid |
| FulfilmentConstraintService | Apply hard domain clamps (cannot weaken LP/route bans) |
| OfferEligibilityService | Filter offers to legal set |
| Rate service | Price eligible offers only; unresolved ≠ $0 |
| Checkout validation | Block pay if selection illegal/stale |

Business truth for hard rules lives primarily in **FulfilmentConstraintService**; other layers consume results without re-encoding the matrix.

### 10.2 Offer eligibility pipeline (target)

```text
A. Effective configuration
B. Hard fulfilment constraints
C. Destination eligibility
D. Offer eligibility
E. Rate eligibility
F. Customer-visible offer construction
```

| Existing concept | Classification | Justification |
|------------------|----------------|---------------|
| `delivery_offers` | **RETAIN WITH EXTENSION** | Core reusable offer entity; may gain eligibility metadata |
| `destination_zones` / `destination_rules` | **RETAIN AS-IS** (extend matching later) | Sound geo layer |
| `logistics_profiles` | **RETAIN WITH EXTENSION** | Must feed hard constraints, not only rate specificity |
| `rate_cards` | **RETAIN WITH EXTENSION** | Runtime uses subset; unused columns are debt |
| `rate_card_rules` | **DEPRECATE** until a concrete need | No writers; avoid parallel rule language |
| `product_delivery_rules` whole-row winner | **REFACTOR → scoped inheritance** | Conflicts with field-level design |
| `ProductDeliveryOptionsBuilder` | **RETAIN WITH EXTENSION** | Becomes step F consumer of eligibility |
| `AdminRateCardTester` | **REFACTOR** | Delegate to `RateQuoteEngine` |

### 10.3 Rate resolution

**Current precedence (RateQuoteEngine):**

1. Active cards for offer + zone + currency  
2. Effective date window  
3. Optional LP/supplier/origin soft match  
4. Specificity score → priority → ID  
5. Charge type fixed per shipment / per item  
6. `base_amount >= 0` numeric → success (including configured 0)  
7. No candidates → `ERROR_NO_MATCHING_RATE_CARD` (not $0)

**Target precedence (design):**

```text
Variation explicit price override
→ Product explicit price override
→ Most-specific valid rate card
→ More-general valid rate card
→ Explicitly configured fallback
→ Unresolved (never silent free)
```

**Explicit outcomes required:**

| Outcome | Meaning |
|---------|---------|
| Valid quoted rate | Money > 0 or configured |
| Explicitly free configured rate | Money = 0 because configured |
| Unresolved rate | No trustworthy price |
| Invalid configuration | Config fails validation |
| Unavailable offer | Offer not eligible |

### 10.4 Zero vs missing — evidence-backed risks

| Location | Risk | Severity |
|----------|------|----------|
| `RateQuoteEngine::quote` empty candidates → failure | Correct | — |
| `RateQuoteEngine` accepts `base_amount == 0` as success | Correct for configured free; ensure admin UX labels it | LOW (by design) |
| `SelectedOfferShippingRateCalculator` / method: blocked → no `add_rate` | Correct | — |
| `WpdbRateCardRepository::format_decimal` non-numeric → **`0.0000`** | Can coerce bad input into configured free rate | **HIGH** |
| Schema default `base_amount` `0.0000` | New cards may be free unless admin sets amount | **MEDIUM** |
| `AdminRateCardTester` weaker validation | Admin false confidence | **MEDIUM** |
| Unused `free_shipping_threshold` | Future misuse risk if wired without gates | **LOW** (unused) |

---

## 11. Storage and Schema Evolution

### 11.1 Global settings — preferred target

**Recommendation: dedicated repository-backed global configuration table** (single-row or versioned rows), **not** WordPress options blobs for the full fulfilment model, and **not** reuse of `product_delivery_rules` with a fake target.

| Criterion | Dedicated table | wp_options JSON | product_delivery_rules reuse |
|-----------|-----------------|-----------------|------------------------------|
| Normalization | Good | Weak | Confusing |
| Migrations | Clear versioned SQL | Opaque | Overloads product semantics |
| Audit | Entity-typed audit | Possible | Misleading entity_type |
| Inheritance | Clean root scope | Possible | Wrong abstraction |
| Performance | One read + version | Autoload risk | Extra hierarchy noise |
| Admin UX | First-class screen | Settings dump | Confusing “product” |
| Extensibility | Strong | Weak at scale | Poor |

Options may still hold **flags**, kill-switches, and **global_configuration_version** counter.

### 11.2 Product + variation storage — preferred target

**Hybrid scoped configuration model:**

1. **`delivery_engine_configuration_scopes`** (or evolve `product_delivery_rules` into scopes):  
   `scope_type` ∈ {`global`,`product`,`variation`} (+ optional later `category`), `scope_id`, status, version, timestamps  
2. **`delivery_engine_configuration_fields`**: scoped scalar overrides with explicit `mode` + typed value  
3. **`delivery_engine_configuration_collections`**: collection mutations with `mode` ∈ {INHERIT,ADD,REMOVE,REPLACE} + JSON members  

**Why hybrid vs pure JSON document per product:**

- Field-level audit and partial updates  
- Efficient resolve without loading unrelated catalog JSON  
- Variation scale: store only overrides  
- Migration: map each existing product rule row → product scope with OVERRIDE on present fields  

**Avoid:** flattening globals into every product; making delivery methods WC variation attributes.

### 11.3 Schema evolution order (no SQL here)

Current schema version: **2**.

| Next schema steps (conceptual) | Stage |
|--------------------------------|-------|
| 3 — Global config + scope/field/collection tables; migrate product rules → product scopes | Stage 2 |
| Resolver/runtime still on old path behind flags | Stage 3–5 |
| Variation scopes already migratable; storefront later | Stage 6 |
| Package metadata only if needed | Stage 8 |
| Shipment tables | Stage 9 **after** packages + resolver stable |

---

## 12. Migration of Existing RC Configuration

### 12.1 Invariants

1. Existing simple-product storefront behaviour must not silently change when Stage 2 storage lands.  
2. Historical `_cetech_de_*` snapshots untouched.  
3. Migrations idempotent, retry-safe, non-destructive.  
4. Feature flags keep new resolver path off until Stage 5 cutover.  
5. Rollback: keep old `product_delivery_rules` readable until cutover proven; dual-read if needed.

### 12.2 Mapping strategy

| Current | New interpretation |
|---------|-------------------|
| Active `product_delivery_rules` row for `target_type=product` | Product scope with **OVERRIDE** on each populated scalar/collection |
| Missing fields on that row | **Not inherit from global** until globals exist — treat as explicit values from RC row (behavioural compatibility) |
| Variation rule rows | Variation scope OVERRIDEs |
| Category rule rows | **DESIGN DECISION REQUIRED** — quarantine; do not auto-promote to Global |
| `delivery_offer_ids` JSON | Collection mode **REPLACE** at that scope (matches today’s whole-list semantics) |
| Offer/rate/zone FKs | Unchanged entity IDs |

After globals are introduced, **new** products may INHERIT; migrated RC products should remain OVERRIDE until an explicit admin “convert to inherit” tool exists (Stage 10 bulk).

### 12.3 Schema version progression

`2 → 3` (storage) without runtime behaviour change → later stages bump only when required → never require shipment schema for inheritance.

---

## 13. Admin UX

| Screen | Current | Target action |
|--------|---------|---------------|
| Delivery Settings / flags | Flag matrix | **Extend** — keep flags; add global config entry points carefully |
| Product Delivery Rules | Whole-row CRUD + resolver test | **Redesign** toward inheritance UX + effective preview |
| Delivery Offers / Zones / Rate Cards / LP / Suppliers / Origins / Pickup | Entity CRUD | **Retain** (+ minor extensions) |
| System Status / health | Diagnostics | **Extend** for inheritance/version health |
| Order snapshot meta box | Read-only resolved | **Retain** |
| Global Configuration | Absent | **Create** (Stage 4 after storage) |
| Variation editor inheritance UI | Absent (variation via rules page) | **Create** |
| Effective Configuration Preview | Absent (resolver explanation text only) | **Create** |

Target admin must show: inherited value, override, disabled, effective value, provenance, collection mutations, validation errors.

---

## 14. Simple Product Runtime Migration

### 14.1 Map current → future

| Current step | Future step |
|--------------|-------------|
| Product rule win | ECR effective config |
| Options builder | Eligibility → customer options |
| Selector | Same UX; data from ECR |
| Cart capture / fingerprint | Same; fingerprint may include config version |
| Checkout validation | Validate against ECR + eligibility |
| Rate + WC method | Unchanged authority pattern |
| Snapshot | Add versions/provenance internals; keep customer projection |
| Summaries | Unchanged privacy rules |

### 14.2 Safest cutover sequence

1. Stage 2: dual-write/migrate storage; runtime still old resolver  
2. Stage 3: implement ECR; admin/test harness only  
3. Stage 4: admin inheritance UX / preview  
4. Stage 5: flag `use_effective_configuration_resolver` (name TBD) — simple products only  
5. Parity tests: same offers, same prices, same snapshots on fixtures  
6. Only then disable legacy whole-record path for simple products  

**Do not** migrate variable products first.

---

## 15. Variable Product Gap

| Capability | Status |
|------------|--------|
| Admin variation target rules | Works (`ProductTargetType::Variation`) |
| Resolver hierarchy variation→product→category | Works (whole-record) |
| Selection validator API with variation id | Exists |
| Storefront radios for variable parent | **No** — notice only (`ProductDeliverySelectorRenderer::render_variable_notice`) |
| Cart capture on variable | **Disabled** (`CartDeliverySelectionCapture::should_apply_capture`) |
| `found_variation` / `reset_data` AJAX refresh | **Missing** (no frontend JS assets) |
| Stale selection invalidation on variation change | Not applicable until capture exists |

**Distinction:**

- WooCommerce variations = commerce attributes (colour/size)  
- Delivery Engine fulfilment = separate choice  

Never encode Air/Sea as WC attributes.

After ECR supports variation scope, Stage 6 can: resolve on selected variation_id, refresh options via AJAX, fingerprint includes variation_id (already in fingerprint parts), invalidate on variation change.

---

## 16. Theme / WoodMart Boundary

| Classification | Current evidence |
|----------------|------------------|
| CORE | Domain/application/shipping/snapshot services |
| GENERIC WOOCOMMERCE ADAPTER | Selector hooks `woocommerce_before_add_to_cart_button`, `woocommerce_single_product_summary`; cart/checkout/shipping hooks |
| WOODMART ADAPTER | **None** — only `IntegrationRegistry` theme detection + flag |
| INCORRECT COUPLING | **None found** in core business logic |

Target: keep core → generic WC; optional WoodMart adapter for swatches/quick view/mini-cart/Buy Now later (Stage 7). Do not edit WoodMart parent theme files.

---

## 17. Optional Integration Boundaries

| Integration | Current state | Desired responsibility | Dependency direction |
|-------------|---------------|------------------------|----------------------|
| WoodMart | Detect only | Presentation compatibility | Adapter → core events; core never calls WoodMart |
| WPML | Detect / Null | Translate public strings; copy operational IDs | Adapter → core |
| WCML | Detect / Null | Display currency; snapshot conversion context | Adapter → core |
| WCFM | Detect / Null | Default deny vendor private data | Adapter → core |
| VitePOS / POS | Detect / Null | POS selection flow without product-page UI | Adapter → core |
| Blocks | Flag only | Blocks checkout adapter | Adapter → core shipping contracts |

Core must activate and run the classic path with none of these present.

---

## 18. Shipping Package Architecture

**Current:** No custom `woocommerce_cart_shipping_packages` splitting. Calculator iterates package contents, skips non-managed lines, quotes managed lines, sums into one method rate. Known limitation: mixed-cart line quotes may diverge from WC shipping-line totals.

**Decisions required before shipments (Stage 8 first):**

1. Package = shipment group key definition (supplier/origin/route/service/zone/dispatch/LP/restrictions)  
2. Mixed DE / non-DE cart handling (exclusive vs coexist — settings exist conceptually; prove behaviour)  
3. Multiple DE selections same/different offers → one vs many packages  
4. Pickup + delivery coexistence → pickup lines must not add delivery shipping cost  
5. Aggregation rule for one WC shipping method across packages  
6. What shipment creation snapshots from package metadata  

Do not invent shipment tables to hide package defects.

---

## 19. Shipment Readiness

**Prerequisites before shipment tables/workflow:**

1. Stable ECR + simple (+ ideally variable) runtime  
2. Deterministic package/consolidation planning  
3. Clear snapshot contract for fulfilment method, offer, rate, destination, public labels, private LP/supplier/origin  
4. Idempotent creation keys  
5. Privacy classification for events/tracking  
6. Feature flags remain default OFF  

**Dependency order:**

```text
Resolver + eligibility + rates (Stages 2–6)
→ Package architecture (Stage 8)
→ shipments / shipment_items / shipment_events (Stage 9)
→ staff workspace + customer timeline + tracking links
```

---

## 20. Order Snapshot Evolution

Verified protected meta must remain.

| Bucket | Examples | Visibility |
|--------|----------|------------|
| A. Historical customer truth | Offer label, choice, paid rate, currency, ETA, pickup public info | Customer-safe projections |
| B. Operational fulfilment | Offer ID, zone ID, LP/supplier/origin IDs, route codes | Staff / protected meta |
| C. Internal provenance/audit | Scope versions, optional provenance compact | Admin/diagnostics |
| D. Never customer-visible | Costs, margins, private notes, internal hashes | Strip always |

**Recommendation:** extend `_cetech_de_*` structured JSON via versioned schema fields (already versioned) rather than inventing a parallel store. Keep WooCommerce CRUD/HPOS. Never rewrite old orders when global config changes.

---

## 21. Privacy and Data Classification

| Field / concept | Class |
|-----------------|-------|
| Offer public label, public description | PUBLIC CUSTOMER-SAFE |
| Shipping price paid, ETA text | PUBLIC CUSTOMER-SAFE |
| Pickup public label/address/hours/instructions | PUBLIC CUSTOMER-SAFE |
| Fulfilment choice (Delivery / Pickup) | PUBLIC CUSTOMER-SAFE |
| Supplier, origin, internal notes | STAFF/ADMIN INTERNAL (capability-gated) |
| Logistics profile name/code | STAFF/ADMIN INTERNAL |
| Rate card id/code, rule internals | STAFF/ADMIN INTERNAL |
| Internal cost / margin | STAFF/ADMIN INTERNAL (stricter caps) |
| Provenance, configuration version, internal hash | SYSTEM INTERNAL |
| Selection fingerprint hash | SYSTEM INTERNAL (not customer HTML) |
| Shipment internal events / private notes | STAFF/ADMIN INTERNAL |
| Tracking number/URL when published | PUBLIC CUSTOMER-SAFE for that shipment only |

| Surface | Rule |
|---------|------|
| REST/AJAX | No public DE REST today; future must capability-gate and sanitize |
| HTML/JS localize | Public labels only |
| Cart metadata | May hold IDs server-side; display summary public-only |
| Order meta | Protected `_cetech_de_*`; customer renderers project safely |
| Email / thank-you / My Account | Customer builder only |

---

## 22. Performance and Caching

### Risks

- N+1 offer fetches in options builder (`findById` per ID)  
- Per-variation resolve without request memoization (future Stage 6)  
- Repeated resolve across cart/checkout/shipping hooks  
- Destination matcher scans  
- Admin assets globally (currently mostly page-local PHP admin)  
- Autoloaded options growth if globals stuffed into options  

### Target cache layers

| Layer | Use |
|-------|-----|
| Request-local memoization | Same product/variation effective config within one request |
| Persistent object cache (Redis if present) | Versioned config fragments; never customer quotes |
| Browser cache | Static assets only; never personalized quotes |

Correctness must not depend on Redis.

---

## 23. Auditability

Current `audit_log` + `ConfigurationAuditLogger` records entity save/delete with previous/new payloads (strips `internal_notes`).

**Extension needed for inheritance:**

- scope type/id  
- field key  
- old mode/value → new mode/value  
- version impact (global/product/variation version bump)  

Do not log secrets, payment data, or supplier cost payloads in public logs.

---

## 24. Security

Evidence-backed findings only:

| Finding | Severity | Evidence |
|---------|----------|----------|
| `WpdbRateCardRepository::format_decimal` coerces non-numeric to `0.0000` | **HIGH** | Can create unintended free rates |
| `countOrderSnapshotReferences` uses `$wpdb->postmeta` | **MEDIUM** | Incomplete under HPOS — delete guards may under-count |
| Rate card schema / UI default amount 0 | **MEDIUM** | Easy to publish free shipping unintentionally |
| `AdminRateCardTester` weaker than `RateQuoteEngine` | **MEDIUM** | Admin may trust unsafe cards |
| No public REST for DE config | Positive control | Health asserts absence |
| Server rejects client prices | Positive | Quote engine authoritative |
| Caps + nonces on admin forms | Positive | AdminActionHandler pattern |
| Single AJAX dismiss notice | Low surface | Cap + nonce |

No CRITICAL IDOR/public price-trust bug found in reviewed runtime path. Do not treat absence of tests as a vulnerability by itself.

---

## 25. Accessibility / SEO / Assets

| Topic | Current | Gap |
|-------|---------|-----|
| Selector markup | PHP radios when capture on | Need audit for fieldset/legend, `aria-live` on dynamic price later |
| Variable UX | Notice only | Future JS must keep keyboard/focus |
| Assets | No global frontend bundle | Good for CWV today; Stage 6 must conditional-enqueue |
| SEO | No delivery query-var duplicates observed | Keep; never index `?shipping=` variants |
| Theme independence | WC hooks | Preserve |

---

## 26. Automated Quality Strategy

Practical ladder (do not install everything at once):

| When | Introduce |
|------|-----------|
| Stage 2–3 | PHPUnit bootstrap; unit tests for inheritance merge + migration mapping |
| Stage 3 | Resolver golden tests; provenance tests |
| Stage 5 | Simple-product parity tests (legacy vs ECR); rate selection; no-free-on-missing; snapshot immutability fixtures |
| Stage 6 | JS tests for variation selector refresh/stale handling |
| Stage 8–9 | Package grouping + shipment idempotency tests |
| Stage 11 | PHPCS (WP security subset), PHPStan level incremental, CI on PRs, privacy assertion tests |

WooCommerce full integration tests when harness cost is justified (Stage 5+).

---

## 27. Target Component Architecture

Prefer extending existing sound classes over parallel duplicates.

```text
GlobalConfigurationRepository
ScopedConfigurationRepository
ConfigurationVersionService
        ↓
EffectiveConfigurationResolver → EffectiveConfiguration (+ Provenance)
        ↓
FulfilmentConstraintService
        ↓
OfferEligibilityService
        ↓
RateQuoteEngine (existing) / RateResolver façade
        ↓
ProductDeliveryOptionsBuilder (existing, thin)
ProductDeliverySelectorRenderer (existing)
CartDeliverySelectionCapture (existing)
CheckoutDeliverySelectionValidator (existing)
SelectedOfferShipping* (existing)
OrderDeliverySnapshot* (existing)
        ↓ later
PackageConsolidationService
Shipment* services
Integration adapters (WoodMart/WPML/…)
```

**Dependency rule:** Core/Application never depend on WoodMart/WPML/WCFM/POS/Blocks adapters.

`ProductDeliveryRuleResolver` becomes a **legacy adapter** during dual-run, then shrinks or becomes a thin wrapper over scoped repos.

---

## 28. Recommended Implementation Stages

Adjusted only where evidence demands; broad plan retained.

| Stage | Objective | Schema? | Runtime impact | Exit gate |
|-------|-----------|---------|----------------|-----------|
| **2** | Global + scoped inheritance storage; migrate RC product rules → OVERRIDE scopes; dual-read capable | Yes (v3) | None if flagged off | Migrated data round-trips; old path unchanged |
| **3** | EffectiveConfigurationResolver + provenance + versions (admin/test harness) | Maybe minor | None storefront | Golden resolve tests; no god-object |
| **4** | Admin inheritance UX + effective preview | No/minimal | Admin only | Staff can see inherit/override/effective |
| **5** | Cut over **simple-product** runtime to ECR behind flag | No | Flagged | Parity with Stage 0B fixtures; shipping/snapshot PASS |
| **6** | Variable-product capture + AJAX | No/minimal | Flagged | Variation select refresh; fingerprint OK |
| **7** | Generic theme hardening + WoodMart adapter | No | Optional flag | Core works without WoodMart |
| **8** | Shipping packages / consolidation | Maybe package meta | Flagged | Mixed-cart totals coherent |
| **9** | Shipment domain + ops + tracking | Yes | Flagged off default | Idempotent creation; privacy PASS |
| **10** | Bulk/import + optional integrations + Blocks | Per integration | Flags | Core still independent |
| **11** | Quality hardening / next RC | No | Tooling | CI + smoke + a11y/perf gates |

### Per-stage essentials (condensed)

**Stage 2:** Touch persistence + admin save paths carefully; no shipping changes; migration invariants §12; rollback keep old table.  
**Stage 3:** New application services; wire admin test only; PHPUnit resolver tests.  
**Stage 4:** Presentation/Admin only.  
**Stage 5:** Selector/cart/checkout/shipping consumers switch behind flag; re-run Stage 0B critical rows.  
**Stage 6:** Frontend JS enqueue on variable products only.  
**Stage 7–11:** As table; shipments last among domain features that need packages.

---

## 29. Risks and Migration Hazards

1. Treating empty DB fields as INHERIT after globals exist → silent behaviour change for migrated OVERRIDE rows if mis-mapped.  
2. Cutting over variable products before simple ECR parity.  
3. Expanding ECR into quoting/packages (god-object).  
4. `format_decimal` → free shipping hazard remaining during Stage 2+.  
5. Building shipment schema before package architecture.  
6. Rewriting verified snapshot/shipping code “while we’re here.”  
7. Category-rule ambiguity vs Global.  
8. Dual rate matchers drifting further.

---

## 30. Do-Not-Touch-Yet Areas

Until their dependency stage explicitly opens them:

| Area | Preserve because |
|------|------------------|
| `SelectedOfferShippingMethod` / calculator happy path | Stage 0B shipping 25.00 verified |
| `RateQuoteEngine` failure → no rate | No accidental free shipping PASS |
| Snapshot persist/read/immutability | Historical truth PASS |
| HPOS CRUD usage for orders | Compatibility PASS |
| Simple-product capture/fingerprint/revalidate | Verified transaction spine |
| Customer summary privacy stripping | Privacy PASS |
| Feature-flag default-OFF posture | Safe rollout |
| Server authority (ignore client prices) | Security invariant |
| Absence of WoodMart in core domain | Theme independence |

Agents must not “clean up” these into new abstractions without an approved stage.

---

## 31. Stage 2 Entry Criteria

Stage 2 may begin only when all are true:

1. Stage 0B remains **VERIFIED** at baseline commit `31fc016` (or documented successor that does not alter runtime).  
2. This document (`docs/POST-RC-ARCHITECTURE-GAP-ANALYSIS.md`) is accepted as Stage 1 authority.  
3. `docs/AI-HANDOFF.md` records Stage 1 complete and Stage 2 next.  
4. Working tree for Stage 2 starts **documentation-clean** except intentional Stage 2 implementation commits.  
5. Scope lock: Stage 2 = **storage + migration of configuration only** — no ECR cutover, no variable capture, no shipments, no checkout/shipping behaviour change, no version bump to stable, no flag defaults flipped ON.  
6. Explicit non-goals restated in Stage 2 tasking: no EffectiveConfigurationResolver implementation beyond what storage needs; no admin inheritance UX redesign beyond minimal persistence forms if absolutely required for saving globals (prefer defer UX polish to Stage 4).

---

## Appendix A — Inventory index (paths)

| Concern | Path |
|---------|------|
| Bootstrap | `cetech-woocommerce-delivery-engine.php`, `src/Bootstrap/*` |
| Schema | `database/migrations/20260705160000_create_configuration_tables.php`, `...product_delivery_rules_table.php` |
| Rule resolver | `src/Application/ProductRule/ProductDeliveryRuleResolver.php` |
| Options | `src/Application/Selector/ProductDeliveryOptionsBuilder.php` |
| Cart | `src/Application/Cart/*` |
| Checkout | `src/Application/Checkout/*` |
| Rates | `src/Application/RateQuote/RateQuoteEngine.php` |
| Shipping | `src/Application/Shipping/*`, `src/Infrastructure/WooCommerce/Shipping/*` |
| Snapshots | `src/Application/Order/*` |
| Flags | `src/Bootstrap/FeatureFlags.php` |
| Integrations | `src/Integrations/Registry/*` |

---

*End of Stage 1 analysis. Do not begin Stage 2 implementation in the same task that only requested analysis unless explicitly instructed after this document is committed.*
