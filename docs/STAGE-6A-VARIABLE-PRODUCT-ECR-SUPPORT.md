# Stage 6A — Variable Product ECR Support

**Document status:** Stage 6A completion record  
**Plugin version:** `1.0.0-rc.1` (unchanged)  
**Schema target:** `3` (unchanged)  
**Date:** 2026-08-10  
**Scope:** Local implementation + automated parity tests only. **No FLAIROC deployment.**

---

## 1. Verdict

**COMPLETE**

Stage 6A adds variable-product / variation ECR runtime behind a dedicated cutover flag that defaults **OFF**. Simple-product Stage 5 behaviour is preserved. Standard WooCommerce `found_variation` / `reset_data` drive the product-page selector. Server remains authoritative. Schema remains `3`. FLAIROC was not modified.

---

## 2. Scope

### Included

- Dedicated flag `enable_variable_product_ecr_runtime` (default OFF)
- Variable/variation ECR routing (requires main ECR flag AND variable flag)
- Global → Product → Variation effective configuration consumption
- Category compatibility route for variation contexts
- Customer-safe variation options AJAX endpoint
- Theme-independent variation selector JS/CSS
- Cart/session variation identity + fingerprint preservation
- Checkout revalidation via existing validator + capture gate
- PHPUnit + Vitest coverage
- Developer documentation

### Excluded (intentional)

- Stage 6B FLAIROC package/deploy/verification
- Stage 7 WoodMart adapter
- Stage 8 package consolidation
- Stage 9 shipments / tracking
- Stage 10 Blocks checkout
- Schema 4 / public version bump / release tag

---

## 3. Stage 5 Baseline Protected

Live-verified simple-product path remains the protected baseline:

```text
#39705 In Warehouse → selector → cart → fingerprint → checkout
→ WooCommerce shipping 25.00 → HPOS order #39711 → protected snapshot
```

With `enable_variable_product_ecr_runtime` OFF (default), variable products stay on LEGACY/deferred behaviour exactly as Stage 5 left them.

---

## 4. Variable Runtime Flag

| Item | Value |
|------|-------|
| Canonical name | `enable_variable_product_ecr_runtime` |
| Option | `cetech_de_enable_variable_product_ecr_runtime` |
| Default | **false** |
| Activation behaviour | `ensure_defaults()` writes OFF if unset |
| Alone | Exposes no customer UI |
| Dependency | Variable ECR requires **also** `enable_effective_configuration_runtime` |
| Simple products | Unaffected by this flag |

---

## 5. Routing

Exact decision order (`ProductDeliveryRuntimeConfigurationRouter`):

1. Main ECR flag OFF → **LEGACY**
2. Category target → **LEGACY**
3. Variation target + variable ECR flag OFF → **LEGACY**
4. Variation target + invalid relationship → **ECR** (fail-closed in source; never silent legacy)
5. Variation/product category-dependent legacy winner → **LEGACY_CATEGORY_COMPATIBILITY**
6. Simple product without category dependency → **ECR** (Stage 5)
7. Variable parent without selected variation context → **LEGACY**
8. ECR invalid/unresolved → fail closed as ECR (no silent legacy fallback)

```text
main ECR OFF
  └─ LEGACY

main ECR ON
  ├─ simple product
  │    ├─ category winner → LEGACY_CATEGORY_COMPATIBILITY
  │    └─ else → ECR
  ├─ variable parent (no variation target) → LEGACY
  └─ variation target
       ├─ variable flag OFF → LEGACY
       ├─ category winner → LEGACY_CATEGORY_COMPATIBILITY
       └─ else → ECR (fail-closed on bad relationship / INVALID / UNRESOLVED)
```

---

## 6. Parent / Variation Identity

Every variable resolution distinguishes:

- `product_id` = parent variable product
- `variation_id` = selected variation

Server validates via `VariationRelationshipInspectorInterface`:

- variation exists and is `WC_Product_Variation`
- parent exists and is variable
- claimed parent matches `get_parent_id()`

Never trusts JS-submitted pairing alone.

---

## 7. Global → Product → Variation Resolution

`EcrProductDeliveryConfigurationSource` for variation targets:

1. Inspect relationship → parent ID
2. `EffectiveConfigurationResolver::resolveAll(parent_id, variation_id)`
3. `EcrToRuntimeConfigurationAdapter::adapt(..., variation, variation_id)`
4. Hard constraints remain inside the resolver pipeline

Variation scopes override only fields they explicitly change. No whole-record copy of product config into variation storage.

---

## 8. Category Compatibility

For a selected variation, legacy resolver hierarchy (variation → product → category) is inspected.

If any chosen winner is category-derived (`target_type=category` or specificity `1`):

→ route **LEGACY_CATEGORY_COMPATIBILITY**

Do **not** silently substitute Global ECR configuration. Category rules are not migrated in Stage 6A.

---

## 9. Product Page Lifecycle

| State | Behaviour |
|-------|-----------|
| No variation selected | Shell + “Select your product options…” |
| `found_variation` | Invalidate prior selection; AJAX load options for that variation |
| Options OK | Render radios bound to variation ID |
| No options / INVALID | Unavailable message |
| AJAX error | Safe retry message; loading ends |
| `reset_data` | Clear options, clear hidden variation binding, restore select-options state |

Initial page load does **not** resolve every variation.

---

## 10. WooCommerce Variation Events

Core JS targets standard WooCommerce:

- `.variations_form`
- `found_variation`
- `reset_data` / `hide_variation`

No WoodMart globals, templates, or APIs. Stage 7 owns optional WoodMart adapter work.

---

## 11. Variation Options Endpoint

| Item | Value |
|------|-------|
| Action | `cetech_de_variation_delivery_options` |
| Hooks | `wp_ajax_` + `wp_ajax_nopriv_` |
| Inputs | `product_id`, `variation_id`, `nonce` |
| Auth | Public shoppers allowed; nonce + relationship + flags validated server-side |
| Flags required | selector + main ECR + variable ECR |

---

## 12. Customer-Safe Payload

Returned option fields (subset of selector DTO):

- `display_key`
- public labels / description / estimate
- `is_available` / customer `unavailable_reason`

**Never returned:** supplier, origin, logistics profile, rate-card IDs, provenance, configuration fingerprint, costs, margins, private notes, raw scoped configuration.

---

## 13. Variation Switching

Selecting Variation B immediately:

- clears Variation A radios / labels
- clears hidden `cetech_de_delivery_variation_id` until B options succeed
- requests B from server
- never submits A’s offer for B

---

## 14. Out-of-Order Request Protection

Monotonic `requestToken` + current variation identity check. Late response for A is ignored after B is current. Previous XHR aborted when a new request starts.

---

## 15. Selection Intent

Intent continues to carry:

- parent `product_id`
- `variation_id`
- `target_type` / `target_id` (variation)
- `display_key` / fulfilment / offer
- `configuration_fingerprint` (ECR additive)

Hidden form field `cetech_de_delivery_variation_id` must match WooCommerce `variation_id` on add-to-cart or capture fails closed.

---

## 16. Cart / Session Lifecycle

Canonical intent keys (`CartDeliverySelectionSessionData::CANONICAL_INTENT_KEYS`) include `variation_id` and `configuration_fingerprint`.

Capture → session serialize → normalize → restore preserves both. Two variations of the same parent keep isolated hashes. Simple + variable lines do not overwrite each other.

---

## 17. Fingerprinting

Cart fingerprint parts already include `variation_id` before the optional ECR configuration fingerprint component.

- Same parent, different variation → different cart fingerprint (variation binding)
- Variation config change → stale
- Unrelated variation change → does not stale another variation’s selection

---

## 18. Checkout Revalidation

Existing `CheckoutDeliverySelectionValidator` + `CartDeliverySelectionRevalidator` now apply to variable ECR lines because `should_apply_capture` opens for variable+variation when both flags are ON.

Mismatch / stale fingerprint / removed offer → fail closed. No silent substitution.

---

## 19. Shipping

Unchanged genuine WooCommerce shipping method path:

variation ECR config → constrained option → `RateQuoteEngine` → WC rate

No JS rate calculation. No cart fees. Package consolidation remains Stage 8.

Known limitation (pre-Stage 8): mixed-cart WooCommerce shipping line totals may not equal the sum of independent line quotes.

---

## 20. Snapshot

Existing protected snapshot contract already stores `product_id` + `variation_id` + offer/quote semantics via WooCommerce CRUD / HPOS. No schema 4. Historical orders untouched.

---

## 21. Privacy

Product AJAX, cart summary, checkout messages, and customer order projections remain public-label only. Automated endpoint/privacy tests assert absence of supplier/origin/fingerprint/provenance fields in customer payloads.

---

## 22. Accessibility

- Radios with associated labels
- Status region `role="status"` + `aria-live="polite"`
- Status not colour-only (`data-state` + text)
- Focus not stolen on variation change
- Reset returns understandable select-options wording

---

## 23. Performance

| Concern | Behaviour |
|---------|-----------|
| Initial page | No per-variation ECR resolve |
| Selected variation | One bounded AJAX → one `resolveAll(parent, variation)` |
| Repeat same variation | Optional request-local client cache keyed by `product:variation` |
| DB | Scoped reads for global + product + that variation only |

---

## 24. PHP Tests

| Item | Result |
|------|--------|
| PHPUnit | 10.5.64 |
| Tests | 161 |
| Assertions | 558 |
| Failures / errors / skips / risky | 0 |

Baseline Stage 5: 114 / 442. Stage 6A added routing, ECR variation, endpoint, cart lifecycle, and simple non-regression coverage.

---

## 25. JS Tests

| Item | Result |
|------|--------|
| Runner | Vitest |
| Version | 3.2.7 |
| File | `tests/js/variable-delivery-selector.test.js` |
| Tests | 9 passed |
| Command | `npm run test:js` (dev-only) |

Covers: initial state, found_variation, reset_data, A→B switch, stale AJAX, error, unavailable, variation binding, no technical leakage.

---

## 26. Disposable WP Smoke

**DISPOSABLE VARIABLE WP SMOKE NOT EXECUTED**

No disposable WordPress + WooCommerce environment was available in this Stage 6A run. FLAIROC was intentionally not used.

---

## 27. User-Friendly Language

| Situation | Customer-facing wording |
|-----------|-------------------------|
| No variation yet | Select your product options to see delivery choices. |
| Loading | Loading delivery choices… |
| No delivery options | Delivery options are not available for this variation. |
| Request failure | Delivery options are temporarily unavailable. Please try again. |
| Stale / mismatch selection | Your selected delivery option is no longer available. Please choose again. |
| Missing selection on add-to-cart | Please select a delivery option for this product. |

---

## 28. Generic Theme Boundary

Assumptions for core Stage 6A:

- WooCommerce variable product form (`.variations_form`)
- `found_variation` provides `variation.variation_id`
- `reset_data` clears incomplete selection

WoodMart is **not** claimed verified.

---

## 29. Stage 7 WoodMart Boundary

Deferred:

- WoodMart swatches / quick view / template quirks
- Optional adapter behind `enable_woodmart_adapter`
- No WoodMart hard dependency in core Stage 6 assets

---

## 30. Stage 6B Entry Criteria

Stage 6B (package + controlled FLAIROC variable ECR verification) may begin only when:

1. Stage 6A COMPLETE on `master`
2. PHPUnit + JS gates green
3. FLAIROC remains at Stage 5 safe dormant state (all DE runtime flags OFF, ECR OFF, COD OFF)
4. Explicit human approval to package/deploy and enable flags on FLAIROC for variable QA
5. Variable QA product configured without mutating protected simple `#39705` / order `#39711` history
6. Rollback plan restores all flags OFF

---

## 31. Deferred Risks

| Risk | Owner |
|------|-------|
| WoodMart event/template quirks | Stage 7 |
| Mixed-cart shipping total vs line quotes | Stage 8 |
| Shipments / tracking / timeline | Stage 9 |
| Blocks checkout | Stage 10 |
| Admin terminology still technical on Stage 4 screens | Release-quality backlog (“Admin terminology simplification required before next RC.”) |
| Redis namespace hygiene | Ops backlog |
| Code Snippets residual warning | Ops backlog |
| HPOS `countOrderSnapshotReferences` postmeta debt | Stage 1 deferred debt |
| Client cache correctness if admin changes config mid-page | Prefer re-select variation; cache is request-local only |

---

## Runtime architecture (quick map)

| Component | Path | Responsibility |
|-----------|------|----------------|
| Feature flag | `FeatureFlags` | `enable_variable_product_ecr_runtime` default OFF |
| Router | `ProductDeliveryRuntimeConfigurationRouter` | Deterministic LEGACY / CATEGORY / ECR |
| Variation inspector | `WooCommerceVariationRelationshipInspector` | Parent/variation relationship |
| ECR source | `EcrProductDeliveryConfigurationSource` | Simple + variation resolveAll |
| Adapter | `EcrToRuntimeConfigurationAdapter` | Constrained ECR → runtime rules |
| Endpoint | `VariationDeliveryOptionsEndpoint` | Customer-safe AJAX options |
| Renderer | `ProductDeliverySelectorRenderer` | Variable shell + simple radios |
| Assets | `VariableDeliverySelectorAssets` | Conditional enqueue |
| JS/CSS | `assets/frontend/variable-delivery-selector.*` | WC variation events |
| Capture | `CartDeliverySelectionCapture` | Variable gate + variation mismatch |
| Session | `CartDeliverySelectionSessionData` | Canonical intent keys |

STOP — do not begin Stage 6B or Stage 7 unless explicitly tasked.
