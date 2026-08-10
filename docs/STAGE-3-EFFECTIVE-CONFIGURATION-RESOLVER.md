# Stage 3 — Effective Configuration Resolver

**Document status:** Stage 3 completion record  
**Plugin version:** `1.0.0-rc.1` (unchanged)  
**Schema target:** `3`  
**Date:** 2026-08-10

---

## 1. Verdict

**COMPLETE**

Stage 3 delivered the authoritative `EffectiveConfigurationResolver` with field-by-field GLOBAL → PRODUCT → VARIATION inheritance, scalar/collection modes, provenance, validation reason codes, deterministic fingerprints, per-slice resolution, bounded repository reads, request-local memoization, and golden PHPUnit coverage. Controlled schema 2→3 dry-run passed on disposable Docker MySQL. Legacy RC storefront/runtime path remains untouched.

---

## 2. Scope

### Included

- Effective configuration domain types and resolver
- Validator + reason codes
- Fingerprint / version descriptor
- Pass-through `FulfilmentConstraintServiceInterface` boundary
- ServiceContainer registration (not wired into storefront)
- Golden / migration-parity / purity / query-bound tests
- Disposable schema 2→3 dry-run script

### Excluded (intentional)

- Storefront / selector / cart / checkout / shipping / snapshot cutover
- Admin inheritance UX (Stage 4)
- Hard fulfilment constraint matrix implementation (interface + passthrough only)
- Destination / offer eligibility / rate quoting
- Public REST/AJAX endpoints
- Plugin version bump / FLAIROC deploy

---

## 3. Controlled Schema 2→3 Migration Verification

| Item | Result |
|------|--------|
| Environment | Disposable Docker MySQL 8 container `cetech-de-stage3-mysql` on `127.0.0.1:3307`, database `cetech_de_stage3` (**not FLAIROC**) |
| Harness | `scripts/stage3-schema-2-to-3-dry-run.php` (minimal wpdb/PDO stub; generated stubs under `scripts/stage3-wp-stubs/` gitignored) |
| Baseline | Schema option forced to `2`; legacy `product_delivery_rules` fixture seeded |
| First upgrade | Tables created; global scope ensured; legacy product/variation migrated; `verify()` passed; schema set to `3` |
| Repeat upgrade | Idempotent — no duplicate scopes/fields/collections; versions unchanged |
| Legacy data | Row count preserved; table intact |
| Category quarantine | Category fixture not written to v3 scopes; report reason `category_quarantined` |
| Failure safety | Induced migration failure left schema version at `2` (not marked `3` prematurely) |

---

## 4. Resolver Boundary

`EffectiveConfigurationResolver` determines **what configuration applies**.

It does **not**:

- match destinations
- calculate rates / quotes
- write cart/checkout/order data
- render HTML
- read customer selection
- call `RateQuoteEngine`
- read legacy `product_delivery_rules` or category targets

Downstream pipeline (Stage 1):

```text
EffectiveConfigurationResolver
  → FulfilmentConstraintService (passthrough in Stage 3)
  → OfferEligibility / Destination / Rate (later)
```

---

## 5. Inputs

`EffectiveConfigurationRequest`:

| Field | Rules |
|-------|-------|
| `product_id` | Required; must be `> 0` |
| `variation_id` | Optional; if set must be `> 0` |
| `slice_key` | Optional; default `''` |
| `parent_product_id` | Optional ownership hint |

No `$_POST`, cart, customer, postcode, or URL dependencies.

APIs:

- `resolve(request)` → one `EffectiveConfiguration` for one slice
- `resolveAll(product_id, variation_id?)` → `EffectiveConfigurationSet` keyed by discovered slices

---

## 6. EffectiveConfiguration Output

Immutable aggregate containing:

- resolved scalar fields (`EffectiveScalarField`)
- resolved collections (`EffectiveCollectionField`)
- field provenance
- overall `EffectiveFieldState` + reason codes
- `EffectiveConfigurationVersionDescriptor` (versions + fingerprint)

No customer rendering methods.

---

## 7. Scalar Resolution

Precedence is field-by-field:

```text
GLOBAL → PRODUCT → VARIATION
```

| Mode | Effect |
|------|--------|
| INHERIT | Keep prior effective value/state |
| OVERRIDE | Replace with typed value (`0` preserved) |
| DISABLE | Explicit DISABLED/NONE (not missing, not inherit) |

Global root missing → `UNRESOLVED` (`UNRESOLVED_GLOBAL_VALUE`). No invented defaults.

Current registry has no boolean fields; zero-safety is covered by `priority = 0`.

---

## 8. Collection Resolution

Starting from inherited members:

| Mode | Effect |
|------|--------|
| INHERIT | Unchanged |
| ADD | Append members not already present (order preserved) |
| REMOVE | Remove configured members (remaining order preserved) |
| REPLACE | Exact configured list |
| REPLACE `[]` | Exact empty list — **not** INHERIT |

ADD/REMOVE against an unresolved root → `INVALID_COLLECTION_OPERATION`.

Examples:

| Scenario | Effective |
|----------|-----------|
| Global `[10,20]` + Product ADD `[30]` + Variation REMOVE `[20]` | `[10,30]` |
| Global `[10,20]` + Product REPLACE `[]` + Variation INHERIT | `[]` |
| Global `[10,20]` + Product REMOVE `[10]` + Variation ADD `[10]` | `[20,10]` |

---

## 9. Provenance

Internal only (never public REST/HTML/JS/email).

Scalars: `source_label` ∈ `global` \| `product` \| `variation` \| `system_default` \| `explicit_disable` (+ optional scope type / row id).

Collections: same source labels plus ordered `CollectionMutationStep` list describing contributing ADD/REMOVE/REPLACE operations across scopes.

---

## 10. Validation / Reason Codes

States: `valid` \| `unresolved` \| `disabled` \| `invalid`.

| Code | Meaning |
|------|---------|
| `UNRESOLVED_GLOBAL_VALUE` | Root value never configured |
| `INVALID_COLLECTION_OPERATION` | ADD/REMOVE without resolvable base |
| `INVALID_SCOPE_RELATIONSHIP` | Variation parent mismatch |
| `MISSING_REQUIRED_FIELD` | Reserved for completeness rules |
| `UNSUPPORTED_DISABLE` | Reserved |
| `INVALID_REFERENCE` | Reserved (no N+1 entity existence checks in Stage 3) |
| `INVALID_REQUEST` | Reserved for request-level faults |

Ordinary incomplete configuration returns typed unresolved/invalid results — it does not throw `RuntimeException`.

---

## 11. Global / Product / Variation Precedence

Field-by-field merge only. Never whole-record winner.

Variation may override a single field while others continue inheriting product/global values.

---

## 12. Slice Semantics

| Topic | Decision |
|-------|----------|
| What a slice is | One `slice_key` on a product/variation scope. Migrated legacy rows use `fulfilment_availability` as `slice_key`. Native/global use `''`. |
| Singular vs grouped | `EffectiveConfiguration` is **singular per slice**. `resolveAll` returns a grouped set. |
| Selection | Exact slice match only. Global (`''`) is the inheritance root for every slice. |
| Cross-slice merge | **Forbidden.** Multi-FA products remain independent effective configs. |
| Future hard constraints | Later stages consume each slice’s effective config independently before offer/rate eligibility. |

Stage 1 still has an open product-model question (one FA vs many). Stage 3 storage/resolver preserves both paths without collapsing slices.

---

## 13. Legacy Migration Compatibility

| Migrated Stage 2 meaning | Effective Stage 3 result |
|--------------------------|--------------------------|
| ID null/0 → DISABLE | Effective DISABLED even if global OVERRIDE exists |
| Offers list → REPLACE | Exact list; no merge with global |
| Offers empty → REPLACE `[]` | Effective `[]`, not global offers |
| Category | Quarantined; not readable by resolver (`ConfigurationScopeType` has no category) |

Category parity remains a **Stage 5** cutover concern.

---

## 14. Configuration Version / Fingerprint

Descriptor inputs:

- product id, variation id, slice key
- global / product-slice / variation-slice `config_version` (0 if scope absent)
- canonical resolved scalar/collection semantic state (no secrets, no destination/rate data, no internal `scope_row_id`)

Properties:

- deterministic SHA-256 hex
- same semantic effective config → same fingerprint
- unrelated product scope writes do not change another product’s fingerprint
- never customer-visible

---

## 15. Hard Constraint Boundary

`FulfilmentConstraintServiceInterface` + `PassthroughFulfilmentConstraintService` registered.

Stage 3 does **not** implement International / In Store / In Warehouse clamp matrix. Full constraint service belongs after Stage 3 when offer eligibility is built. Pickup customer-selection behavior remains out of resolver scope.

---

## 16. Query / Performance Characteristics

Per resolve (cold context):

| Read | Count |
|------|-------|
| `getGlobalConfiguration` | 1 |
| `findByScope(product)` | 1 |
| `findByScope(variation)` | 0 or 1 |

Slice selection is in-memory. No per-field repository fetch. Request-local memoization keys include product, variation, slice, and scope versions. No Redis requirement.

---

## 17. Security / Privacy

- No public endpoints
- Provenance/fingerprint internal only
- No customer-submitted effective values accepted
- Variation/product mismatch → invalid result
- Immutable effective objects after construction
- Constraint service must return separate result (passthrough returns same instance only because it does not mutate)

---

## 18. Tests

PHPUnit suite `Stage2-Stage3` under `tests/Unit`.

Coverage includes:

- scalar inheritance matrix + zero preservation
- unresolved root
- collection ADD/REMOVE/REPLACE/`[]`
- provenance / mutations
- slice isolation + `resolveAll`
- fingerprints / global inheritance effects
- migrated DISABLE / REPLACE parity
- bounded reads + write purity
- category quarantine static assertions

Known deferred characterization (not fixed): `WpdbRateCardRepository::format_decimal` non-numeric → `0.0000` remains Stage 5 hardening risk.

---

## 19. Runtime Non-Impact Proof

Stage 3 diff does **not** modify:

- `ProductDeliveryOptionsBuilder` / selector
- cart capture / fingerprints
- checkout validation
- shipping calculator / WC method
- `RateQuoteEngine`
- snapshot persistence
- customer summaries / assets

Container gains resolver bindings only. Runtime still uses `ProductDeliveryRuleResolver` + legacy table.

**RUNTIME IMPACT = NONE**

---

## 20. Deferred Risks

| Risk | Status |
|------|--------|
| `WpdbRateCardRepository::format_decimal` non-numeric → `0.0000` | **HIGH / DEFERRED** |
| Explicit `base_amount` zero can intentionally produce free shipping | **MEDIUM / DEFERRED** |
| `countOrderSnapshotReferences` uses postmeta under HPOS | **MEDIUM / DEFERRED** |
| Legacy category rule parity at cutover | **CATEGORY / Stage 5** |
| Redis namespace hygiene | **DEFERRED** (ops) |
| Disabled Code Snippets residual warning on FLAIROC | **DEFERRED** (ops) |
| Full fulfilment hard-constraint matrix | **Deferred** past Stage 3 passthrough |

---

## 21. Stage 4 Entry Criteria

1. Stage 3 COMPLETE (this document)
2. Controlled schema 2→3 dry-run evidence available for target environments
3. Resolver golden tests green
4. Explicit instruction to build **admin inheritance UX + effective configuration preview**
5. Do not cut over storefront runtime
6. Do not enable customer feature flags for ECR
7. Do not begin variable capture / shipments

**Recommended next step:** Stage 4 — Admin Inheritance UX and Effective Configuration Preview.
