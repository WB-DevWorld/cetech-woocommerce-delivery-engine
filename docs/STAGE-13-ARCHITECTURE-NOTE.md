# Stage 13 — Architecture Note (pre-implementation audit)

**Date:** 2026-08-13  
**Baseline:** `1.0.0-rc.2` (tag `v1.0.0-rc.2` — **do not modify**)  
**Branch:** `feat/site-wide-delivery-defaults`  
**Schema target after this stage:** remains `3` (no new tables)

This note records **current RC.2 behaviour** before Stage 13 changes. It is the audit required by Part 1.

---

## 1. What the current Default scope represents

RC.2 stores one Global root:

| Property | Value |
|----------|--------|
| `scope_type` | `global` |
| `scope_id` | `0` |
| `slice_key` | `''` (forced) |
| Factory | `ConfigurationScope::global()` / `ensureGlobalScope()` |

`ConfigurationScope` currently **rejects** any non-empty global `slice_key`. The admin Global editor also forces `slice_key = ''`.

The Global root is **not** a fulfilment-specific policy. It is a single untyped default. On live RC.2 / fresh schema-3 installs it is typically **empty** (no scalar/collection instructions). Empty Global does **not** invent free shipping; resolver fields stay `UNRESOLVED`.

Admin label: **Default Settings** (tab on Delivery Settings).

There is **no** Primary Default Fulfilment concept.

---

## 2. Do existing products without product configuration inherit it?

**Yes, field-by-field, when ECR runtime is on** — but only from that single empty/untyped Global.

`EffectiveConfigurationResolver` always loads `getGlobalConfiguration()` (empty slice) as the root, then overlays the product/variation scope for the requested `slice_key`.

If a product has **no** product scope:

- `resolveAll()` uses slice `''`
- every field is whatever Global contains
- if Global is empty → overall state `UNRESOLVED` → ECR runtime **fail-closed** (no invented `$0` rate)

So “inherit site-wide defaults” is already the storage/resolver model. It does **not** currently produce useful delivery behaviour for unconfigured products because Global is empty and there is no per-fulfilment default.

---

## 3. Do migrated products commonly mask the Global default?

**Yes.** This is the main reason Default Settings does not feel like a site-wide policy.

`LegacyConfigurationMigrator` + `LegacyProductRuleMigrationMapper`:

- one legacy `product_delivery_rules` row → one product/variation scope
- `slice_key` = that row’s `fulfilment_availability`
- **every** mapped scalar is written as `OVERRIDE` (or `DISABLE` for null supplier/origin/profile)
- `delivery_offer_ids` is written as `REPLACE`

Migrated products therefore **do not inherit later Global edits**. Changing Default Settings does not update them. That matches “too many configuration surfaces” and “defaults don’t apply to the catalog.”

Native Stage 4 product saves can use `INHERIT` per field. Migration-generated scopes generally cannot.

---

## 4. How `fulfilment_availability` participates

Two related but different uses:

1. **Field** `fulfilment_availability` — scalar `INHERIT` / `OVERRIDE`. Resolved GLOBAL → PRODUCT → VARIATION.
2. **Slice key** — migrated scopes use the availability slug as `slice_key` so multiple legacy rows per product stay unique.

Runtime ECR adapts each resolved slice into a legacy-shaped rule keyed by availability. Hard constraints then lock International / In Store / In Warehouse route and pickup rules.

There is no separate “fulfilment profile” entity. Availability **is** the operational classification, but it is not a site-wide default profile.

---

## 5. Product and variation field override

Already field-by-field (preserve this):

| Kind | Modes |
|------|--------|
| Scalar | `INHERIT` / `OVERRIDE` / `DISABLE` (DISABLE only where registry allows) |
| Collection | `INHERIT` / `ADD` / `REMOVE` / `REPLACE` (`REPLACE []` ≠ inherit) |

Empty / `0` / `false` are never inferred as inherit.

Current registry keys:

- `fulfilment_availability`
- `fulfilment_choice`
- `logistics_profile_id`
- `supplier_id`
- `origin_id`
- `priority`
- `delivery_offer_ids`

**Rate and customer ETA are not scoped fields.** Rate lives on Rate Cards. Offer ETA lives on Delivery Offers (`default_processing_*`, `default_transit_*`, `default_final_mile_*`). A product cannot today override ETA without replacing offers.

---

## 6. Collections

Documented Stage 3 behaviour is already correct and must be preserved:

- `INHERIT` — keep prior members
- `ADD` — append missing, preserve order
- `REMOVE` — drop listed members
- `REPLACE` — exact list
- `REPLACE []` — explicit empty, not inherit
- ADD/REMOVE against unresolved root → `INVALID_COLLECTION_OPERATION`

---

## 7. How Legacy Delivery Rules interfere

Two live paths:

| Flag | Runtime |
|------|---------|
| `enable_effective_configuration_runtime` off | Legacy `product_delivery_rules` (including category targets) |
| on, product has category-only legacy dependency | `LEGACY_CATEGORY_COMPATIBILITY` (quarantined from v3 scopes) |
| on, simple/variable ECR eligible | ECR scopes only; **no** silent fallback to legacy |

RC.2 live has ECR flags **on**. Category-dependent products still use the compatibility route.

Legacy rows were **copied** into v3 as full overrides; they were not retired. “Save Default Settings” never rewrites legacy rows and does not convert migrated OVERRIDE fields back to INHERIT.

---

## 8. Current admin information architecture

Everyday:

- Delivery Settings (Default / Product / Variation tabs) — technical inheritance editor
- Delivery Settings Preview
- Settings (feature flags)
- Delivery Offers, Destination Zones, Rate Cards, Pickup Locations
- Logistics Profiles, Suppliers & Origins
- Dashboard / System Status

Secondary:

- Legacy Delivery Rules

Staff still see INHERIT-adjacent wording (“Use inherited setting”) and scope/tab architecture rather than a task-based site-wide policy.

No: guided first-run, Save & Apply Site-wide, product-exceptions list, needs-attention list, fulfilment-profile cards, or reset-to-defaults action.

---

## 9. Fresh install

Activation/schema 3:

- creates empty Global scope
- does **not** seed offers, rates, or fulfilment defaults
- existing WooCommerce products get **no** product scopes
- until Global is filled, ECR products are unresolved / not offered

There is no “apply to catalog” step because inheritance from an empty Global is already the storage model — it just has nothing useful to inherit.

---

## 10. Chosen Stage 13 design (smallest safe change)

Do **not** copy defaults onto every product.

1. **FulfilmentProfileRegistry** — built-in In Warehouse / In Store / International; `register()` for a later supported profile. Not a plugin marketplace.
2. **Per-profile site-wide defaults** — reuse `configuration_scopes` as `global/0/{profile_key}`. Relax the “global must use empty slice” invariant. Keep empty `global/0/''` as fallback so existing resolver tests stay valid.
3. **Primary Default Fulfilment** — option `cetech_de_sitewide_defaults`. Unclassified products inherit that profile’s defaults.
4. **Product classification** — store only `fulfilment_availability` OVERRIDE when the product is not on the primary profile; otherwise store nothing. Do not flatten other fields.
5. **Optional `estimated_delivery` field** — inheritable ETA text so a product can override ETA without freezing rate/offers. Rate remains on Rate Cards (changing 30→35 updates inheriting products automatically).
6. **Save & Apply Site-wide** — activate policy + optionally convert *matching* migrated OVERRIDE fields to INHERIT after preview. Never mass-write product rows. Never overwrite real exceptions. Never touch legacy-only rows without review. Never rewrite order snapshots.
7. **Resolver** — select the profile Global slice (or primary) as the inheritance root; fall back to empty Global when the profile slice is absent (RC.2 non-regression).
8. **Schema** — no new tables; schema target stays `3`.
9. **UI** — task-based Delivery Settings landing, profile cards, guided setup, simplified product/variation disclosure, exceptions + needs-attention lists, operational menu labels.
10. **Training docs** — not rewritten in this implementation pass (Stage 12 remains RC.2).

---

## 11. Non-goals (this stage)

- Shipments, tracking, Blocks, carrier APIs
- Overwrite-all catalog button
- Automatic legacy retirement
- Public version bump until RC.3 is packaged
- Touching tag `v1.0.0-rc.2`
