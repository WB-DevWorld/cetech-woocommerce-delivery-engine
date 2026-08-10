# Stage 4 — Admin Inheritance UX

**Document status:** Stage 4 completion record  
**Plugin version:** `1.0.0-rc.1` (unchanged)  
**Schema target:** `3` (unchanged)  
**Date:** 2026-08-10

---

## 1. Verdict

**COMPLETE**

Stage 4 delivered administrator-facing Global / Product / Variation scoped configuration UX, explicit scalar and collection controls, resolver-backed effective preview, provenance and validation presentation, transitional pre-cutover notices, legacy category quarantine warnings, capability/nonce/server validation, scoped admin assets, audit on semantic writes, accessibility baseline, and automated admin application tests. Customer runtime remains on the verified legacy RC path. No storefront cutover. No schema 4. No FLAIROC changes.

---

## 2. Scope

### Included

- Global scoped configuration admin editor (`global/0`)
- Product and variation scoped configuration editor (one coherent page with scope tabs)
- Explicit scalar modes: INHERIT / OVERRIDE / DISABLE (registry-gated)
- Explicit collection modes: INHERIT / ADD / REMOVE / REPLACE (including REPLACE `[]`)
- Entity selectors for logistics profiles, suppliers, origins, delivery offers
- Effective Configuration Preview (read-only; uses `EffectiveConfigurationResolver`)
- Field provenance and validation/reason presentation
- Slice selection / native slice creation
- Transitional pre-cutover admin notice
- Legacy category quarantine warning (admin-only)
- Audit recording for semantic scoped writes via existing audit_log
- Capability + nonce + server validation + relationship checks
- Scoped CSS/JS assets
- PHPUnit coverage for admin application/security/preview/UX mapping

### Excluded (intentional)

- Storefront / selector / cart / checkout / shipping / snapshot cutover
- Dual-write into `product_delivery_rules`
- Hard fulfilment constraint matrix implementation
- Variable-product customer capture / AJAX
- Packages, shipments, tracking, Blocks, WoodMart adapters
- Public plugin version bump / FLAIROC deploy
- Schema version 4

---

## 3. Transitional Pre-Cutover Safety

Every Stage 4 admin screen shows an administrator-only notice equivalent to:

> Scoped configuration is currently in pre-cutover mode. Changes made here are stored and can be previewed, but the current customer-facing Delivery Engine runtime continues to use the legacy RC configuration until the runtime migration is completed.

Legacy Product Rules menu label is differentiated as **Product Rules (Legacy RC)**. No customer-facing exposure of this notice. No automatic dual-write.

---

## 4. Admin Information Architecture

Under **Delivery Engine**:

| Menu | Capability | Purpose |
|------|------------|---------|
| Scoped Configuration | `manage_delivery_settings` (also reachable with `manage_product_delivery_rules`) | Global / Product / Variation editors |
| Effective Preview | `manage_product_delivery_rules` | Read-only resolver preview |
| Product Rules (Legacy RC) | `manage_product_delivery_rules` | Unchanged RC runtime CRUD |

---

## 5. Global Configuration

- Root scope `global/0`, default slice only
- No “Inherit from Global” controls
- States: Not configured / Configured / Disabled
- Unset fields remain unresolved (no invented defaults)
- Whole-scope validated save via `ScopedConfigurationRepository`

---

## 6. Product Configuration

- Product selected by positive WooCommerce product ID (validated when WC available)
- Per-field modes from registry
- Shows configured mode, effective value/state, provenance, validation messages
- Effective display comes from Stage 3 resolver

---

## 7. Variation Configuration

- Requires parent product ID + variation ID
- Variation must belong to parent (WC ownership check; injectable checker in tests)
- Does not modify WooCommerce attributes, pricing, or stock

---

## 8. Slice Handling

- Active slice shown with human-readable fulfilment label
- Existing slices selectable; migrated slices show legacy rule hint
- Native slice creation limited to fulfilment-availability keys
- Raw `slice_key` in technical details only

---

## 9. Scalar Controls

- Explicit radio modes; override inputs enabled only for OVERRIDE
- Empty override value rejected (never becomes inherit)
- `0` accepted for `priority`
- DISABLE only when registry allows

---

## 10. Collection Controls

- Explicit INHERIT / ADD / REMOVE / REPLACE
- REPLACE `[]` stored and displayed as explicitly no values (not inherit)
- Member order preserved (no alphabetical sort)

---

## 11. Entity Selectors

- Bounded repository lists (limit 200) with human labels
- Authoritative IDs persisted
- No public AJAX/REST endpoints in Stage 4

---

## 12. Effective Configuration Preview

- Calls authoritative `EffectiveConfigurationResolver`
- Field-by-field state, value, provenance, reasons
- Limitation notice: not a final shipping quote; no destination/rate/checkout evaluation
- Hard-constraint note: passthrough still in effect
- Read-only: loading/preview never writes or audits

---

## 13. Provenance Presentation

Mapped labels: Global, Product override, Variation override, Explicitly disabled, System default. Collection mutation steps summarized as ordered human lines.

---

## 14. Validation / Reason Presentation

Distinct tones: Valid (success), Unresolved (warning), Disabled (neutral), Invalid (error). Reason codes mapped to admin explanations (no exception traces).

---

## 15. Category Legacy Warning

When active legacy category rules match a product’s categories (or site-wide on Global), admin warning explains quarantine and Stage 5 parity requirement. No migration/edit through Stage 4 editor.

---

## 16. Audit Behavior

- Semantic scoped saves append via existing `ConfigurationAuditLogger` / `audit_log`
- Payload includes scope type/id, slice, field snapshots, versions before/after
- Identical semantic save → no version bump → no audit
- Preview → no audit
- No secrets / Authorization headers / customer PII

---

## 17. Capability / Nonce / Security

- Capability enforced on render and write
- Nonce verified server-side for POSTs
- GET cannot mutate configuration
- Preview cannot mutate
- Field keys/modes/values validated via registry
- Product/variation relationship checks prevent forged ownership
- Invalid numerics rejected (never coerced to 0)

---

## 18. Accessibility

- Explicit labels, fieldset/legend mode groups, keyboard-operable radios/selects
- Status not color-only (`cetech-de-state--*` plus text)
- `aria-describedby` on value controls
- Progressive JS enhancement; forms usable without JS

---

## 19. Performance

- Admin CSS/JS enqueued only on Stage 4 pages
- Editors resolve selected product/variation/slice only
- Entity options loaded once per request (bounded)
- Preview uses Stage 3 resolver memoization

---

## 20. Tests

PHPUnit 10.5.64: Stage 2+3 retained; Stage 4 admin parser/service/authorization/UX mapping tests added. Preview proven to match resolver output.

---

## 21. Manual Admin Smoke

**NOT EXECUTED** — no disposable WordPress/WooCommerce admin environment was available in this session. FLAIROC was not used.

---

## 22. Runtime Non-Impact Proof

Static inspection: selector, cart, checkout, shipping, snapshots, customer frontend do not reference Stage 4 admin services or wire `EffectiveConfigurationResolver` into customer runtime. Expected customer runtime impact: **NONE**.

---

## 23. Deferred Risks

| Item | Status |
|------|--------|
| Rate card non-numeric → `0.0000` | DEFERRED |
| Explicit `base_amount` zero can publish free shipping | DEFERRED |
| HPOS postmeta refcount debt | DEFERRED |
| Category legacy parity | Stage 5 |
| Hard fulfilment constraints | Passthrough until later |
| Redis namespace hygiene | Deferred by owner |
| Code Snippets residual on FLAIROC | Do not reactivate |

---

## 24. Stage 5 Entry Criteria

1. Stage 4 COMPLETE (this document)
2. Schema target remains `3` with scoped storage + resolver available
3. Controlled parity plan for simple-product runtime cutover to ECR
4. Explicit handling decision for quarantined category rules
5. No dual-write shortcuts
6. Feature flags remain OFF until staging verification
7. Do not cut over variable products, packages, or shipments in Stage 5
