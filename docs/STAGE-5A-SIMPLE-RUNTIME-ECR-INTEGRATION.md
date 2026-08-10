# Stage 5A — Simple Runtime ECR Integration

**Document status:** Stage 5A completion record  
**Plugin version:** `1.0.0-rc.1` (unchanged)  
**Schema target:** `3` (unchanged)  
**Date:** 2026-08-10

---

## 1. Verdict

**COMPLETE**

Stage 5A prepared the verified simple-product customer runtime to consume Global → Product → Variation → `EffectiveConfigurationResolver` configuration **locally**, behind a cutover flag that defaults **OFF**. Legacy runtime behaviour remains the default. Variable products and category-dependent winners remain explicitly outside ECR cutover. Hard fulfilment constraints are implemented. Non-numeric rate amounts fail closed. FLAIROC was not deployed or modified.

---

## 2. Scope

### Included

- Runtime configuration source abstraction (legacy + ECR + router)
- Cutover flag `enable_effective_configuration_runtime` (default OFF)
- Simple-product-only ECR routing
- Legacy category compatibility guard (explicit LEGACY route)
- Slice-aware ECR → runtime adapter
- Hard `FulfilmentConstraintService` for representable canonical rules
- Cart fingerprint extension for ECR configuration fingerprint
- Quote dimension resolution for ECR without legacy `rule_id`
- Non-numeric / missing rate amount fail-closed fix
- Parity comparator (developer/test only)
- PHPUnit coverage for routing, constraints, parity, rate safety, fingerprints
- Stage 5B deployment plan (documented, not executed)

### Excluded (intentional)

- FLAIROC deploy / schema 2→3 on FLAIROC / flag enablement
- Variable-product customer ECR cutover (Stage 6)
- New customer pickup workflow redesign
- Schema 4 / public version bump / release tag
- Silent ECR→legacy error fallback
- Live carrier APIs

---

## 3. Existing Verified Runtime Spine

Preserved end-to-end:

```text
simple product → selector → cart selection → cart/session restoration
→ checkout validation → WooCommerce shipping method/rate
→ HPOS order → protected snapshot → customer-safe projections
```

Stage 5A changes the **configuration source** behind this spine, not the spine itself.

---

## 4. Runtime Configuration Source Abstraction

| Component | Path | Responsibility |
|-----------|------|----------------|
| `ProductDeliveryConfigurationSourceInterface` | `src/Application/Runtime/` | Storage-agnostic resolve API |
| `LegacyProductDeliveryConfigurationSource` | same | Wraps verified `ProductDeliveryRuleResolver` |
| `EcrProductDeliveryConfigurationSource` | same | Uses Stage 3 resolver + adapter |
| `ProductDeliveryRuntimeConfigurationRouter` | same | Deterministic source selection |
| `ProductDeliveryRuntimeResolution` | same | Result + source + fingerprint + quote dims |
| `EcrToRuntimeConfigurationAdapter` | same | Constrained ECR → `ProductRuleResolutionResult` |
| `LegacyCategoryRuntimeCompatibilityGuard` | same | Category-winner detection |
| `RuntimeConfigurationParityComparator` | same | Test/dev MATCH/MISMATCH only |

`ProductDeliveryOptionsBuilder` continues to consume `ProductRuleResolutionResult` only.

---

## 5. Legacy Source

`LegacyProductDeliveryConfigurationSource` calls `ProductDeliveryRuleResolver::resolve()` unchanged and labels source `legacy` or `legacy_category_compatibility`.

---

## 6. ECR Source

`EcrProductDeliveryConfigurationSource`:

1. `EffectiveConfigurationResolver::resolveAll(product_id)`
2. Hard constraints already applied inside resolver
3. `EcrToRuntimeConfigurationAdapter` maps valid slices to chosen rules
4. Invalid/unresolved overall → fail-closed resolution failure (no legacy fallback)

---

## 7. Runtime Router

Exact decision order:

1. **Flag OFF** → `LEGACY`
2. **Variation target / non-simple product / variable** → `LEGACY` (Stage 6)
3. **Category compatibility guard YES** → `LEGACY_CATEGORY_COMPATIBILITY`
4. **Else** → `ECR`
5. **ECR invalid/unresolved** → fail closed as ECR (never silent legacy fallback)

---

## 8. Cutover Feature Flag

| Item | Value |
|------|-------|
| Canonical name | `enable_effective_configuration_runtime` |
| Option | `cetech_de_enable_effective_configuration_runtime` |
| Default | **false** |
| Customer effect while OFF | Exact legacy path; new code dormant for source selection |
| Independence | Does not enable selector/cart/checkout/shipping by itself |

---

## 9. Category Compatibility Policy

**Detection:** Legacy resolver winners inspected; category target type or specificity `1` ⇒ dependent.

**Routing:** Flag ON + dependent ⇒ `LEGACY_CATEGORY_COMPATIBILITY`.

**Parity:** Existing category-derived customer behaviour preserved; ECR Global must not replace it during Stage 5.

Product rules that outrank category winners → not category-dependent → ECR eligible after migration.

---

## 10. Slice Mapping

- Per `slice_key` independently (no cross-slice merge)
- Slice key / fulfilment_availability drives runtime availability key
- Each valid constrained slice → one `ResolvedProductDeliveryRule`
- Invalid slices skipped; all-invalid/unresolved → failure

---

## 11. Hard Fulfilment Constraints

Implemented in `HardFulfilmentConstraintService` (replaces passthrough in production DI):

| Slice | Choice | Allowed offer routes |
|-------|--------|----------------------|
| International | Delivery only (pickup → INVALID) | Air, Sea (local filtered out) |
| In Store | Delivery and/or Store Pickup allowed | Local delivery (Air/Sea filtered) |
| In Warehouse | Delivery only (pickup → INVALID) | Local delivery (Air/Sea filtered) |

- Hard constraints beat lower-scope overrides at runtime interpretation
- No persistence mutation; no audit writes; scope versions unchanged
- Does not calculate price, inspect postcode, write cart/order, or select for customer
- “Delivery default where both available” remains existing selector behaviour (single-choice model)
- Same-Day Express: still configured rate cards; no live carrier API

Pickup boundary: represent pickup via `fulfilment_choice=store_pickup`; impossible modes filtered; no new pickup UX.

---

## 12. ECR-to-Runtime Adapter

Maps constrained effective fields to runtime rule fields:

- fulfilment_availability / choice
- delivery_offer_ids (order preserved)
- logistics_profile_id / supplier_id / origin_id (disabled → null)
- priority (including 0)
- synthetic `rule_id = 0` (quote dims carried separately)

Does not expose provenance, fingerprints, or private labels to customer options.

---

## 13. Legacy / ECR Parity

`RuntimeConfigurationParityComparator` compares normalized:

- success / chosen slices
- offer IDs / order
- logistics / supplier / origin refs
- priority
- customer option public fields

Tests prove MATCH for equivalent migrated-style configuration.

---

## 14. Offer Parity

`ProductDeliveryOptionsBuilder` outputs for ECR-mapped rules match expected public labels, availability, ordering, and ETA text inputs; private fields absent from `toArray()`.

---

## 15. Rate Parity

`RateQuoteEngine` remains monetary authority. Fixture `25.00` → quote `25.0000`. ECR supplies dimensions; engine quotes.

---

## 16. Non-Numeric Rate Safety Fix

**Before:** `WpdbRateCardRepository::format_decimal` coerced non-numeric → `0.0000`.

**After:** `RateCardAmountFormatter` rejects non-numeric / missing; repository save throws; quote engine returns `invalid_amount` (never free).

---

## 17. Explicit Zero Rate Semantics

Numeric `0` remains valid configured free shipping where intentionally set. Distinct from invalid/missing.

---

## 18. Cart Fingerprint / Revalidation

- Legacy intents without `configuration_fingerprint` → same 7-part hash as before
- ECR intents include additive fingerprint part when present
- Relevant ECR config change → stale selection
- Customer cannot supply authoritative fingerprint
- No cart UX redesign

---

## 19. Checkout / Shipping Integration

- Validation still uses selection validator → now via runtime source router
- Shipping calculator re-resolves ECR quote dimensions when source is ECR
- Same WooCommerce shipping method path; no fake fees

---

## 20. Privacy

Customer options / summaries exclude supplier, origin, logistics identity, rate-card IDs, provenance, fingerprints, margins. Regression covered for ECR path.

---

## 21. Snapshot Non-Regression

Snapshot persistence architecture unchanged. ECR feeds normal quote/checkout pipeline. Customer projections unchanged. Immutability preserved.

---

## 22. Performance

Per simple-product ECR resolve:

- bounded global + product scope reads (Stage 3 memoization)
- bounded offer/rate lookups downstream
- no per-field DB queries
- no per-provenance DB calls

---

## 23. Tests

PHPUnit expanded under `tests/Unit/Runtime/`:

- routing / flag default / variable exclusion / category compatibility
- no silent ECR→legacy fallback
- hard constraints + no-write proof
- ECR mapping / parity / offer privacy
- fingerprint stability / change detection
- rate safety (banana / zero / missing / 25.00)
- OptionsBuilder unavailable characterization

All Stage 2–4 tests remain green.

---

## 24. Runtime Flag Safety

With `enable_effective_configuration_runtime` OFF:

- Router always selects LEGACY
- Customer behaviour matches pre-Stage-5A verified path
- Hard constraints still apply inside ECR resolver (admin preview / harness) but do not affect storefront because storefront never enters ECR source

---

## 25. FLAIROC Non-Deployment Proof

This Stage 5A task:

- did **not** upload plugin to FLAIROC
- did **not** run schema 2→3 on FLAIROC
- did **not** change FLAIROC flags, products, orders, or COD
- did **not** modify #39705 / #39706 remotely

---

## 26. Stage 5B Deployment Plan

**DO NOT EXECUTE in Stage 5A.** Controlled FLAIROC sequence:

1. Pre-deployment backup / rollback package
2. Verify all existing runtime flags OFF
3. Deploy accumulated Stage 2–5A build
4. Run schema 2→3
5. Verify migration/backfill report
6. Stage 4 admin smoke (scoped editors + preview)
7. Verify migrated QA configuration
8. ECR preview for QA product
9. Verify `enable_effective_configuration_runtime` defaults OFF
10. Legacy runtime safety check (flags as previously verified)
11. Controlled runtime flag sequence (selector → capture → checkout → shipping → snapshot)
12. Enable ECR source flag for QA only
13. Simple QA product ECR test; expected shipping **25.00**
14. Cart / checkout / order / snapshot / privacy checks
15. Category-dependent product → legacy-compatibility check
16. Variable product unchanged
17. Final all flags OFF (or leave only approved staging set)
18. Rollback criteria: any quote/selection/privacy mismatch → disable ECR source flag immediately; if needed restore prior package

---

## 27. Deferred Risks

| Risk | Status |
|------|--------|
| `countOrderSnapshotReferences` postmeta under HPOS | Deferred (unchanged) |
| Variable product ECR cutover | Stage 6 |
| Category rule migration into Global/Product | Explicitly not done; compatibility route instead |
| Dual delivery+pickup in one slice | Current single-choice model; design defaulting deferred |
| Redis namespace hygiene / Code Snippets residual | Ops deferred |
| Disposable WP smoke | Not executed in this environment |

---

## Stage 5B Entry Criteria

- Stage 5A COMPLETE commit on `master`
- Schema target remains 3
- PHPUnit green
- FLAIROC backup ready
- Explicit human approval to begin Stage 5B
