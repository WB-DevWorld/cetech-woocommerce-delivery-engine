# POST-RC.9 WCFM Administrative Isolation

**Document status:** Implementation record for development candidate `1.0.0-dev.wcfm.1`  
**Date:** 2026-09-01  
**Branch:** `feat/post-rc9-wcfm-isolation`  
**Rooted from:** tagged `v1.0.0-rc.9` (`e6bc7fba16d9d7b96682f2945c518a33a9a16cd5`) — **immutable; do not retag**  
**Development identity:** `1.0.0-dev.wcfm.1`  
**Schema target:** `5` (unchanged; capability matrix version `4`; not a schema change)  
**FLAIROC:** not modified  
**Package:** see `docs/POST-RC9-WCFM-ISOLATION-QA.md`. Not RC.9. Not RC.10. Not deployed. Not vendor fulfilment.

```text
READ → AUDIT → PLAN → IMPLEMENT → TEST → DOCUMENT → REVIEW → STOP
```

## Root cause

RC.9 `Capabilities::VIEW` is `view_delivery_engine`. `OverviewPage::entry_capability()` returns only that capability. `AdminPageAccess` previously checked `current_user_can()` only.

A WordPress role that holds only `view_delivery_engine` (observed on FLAIROC as `wcfm_vendor`) can therefore open the Delivery Engine overview and see store-wide operational state, profiles, methods, estimates, exception counts, Needs Attention, and links into global administration.

Write pages require stronger capabilities, so this is an **administrative visibility / marketplace isolation** defect. Settings → Access also listed every WordPress role, including `wcfm_vendor`, so an administrator could grant further Delivery Engine capabilities to marketplace vendors.

`RoleAccessService` had no WCFM-aware exclusion. There was no runtime vendor denial, so leftover or later-recreated `wcfm_vendor` capability metadata remained sufficient to enter administration.

## Precise isolation policy

| Actor | Behaviour |
|--------|-----------|
| User with `manage_options` | Never restricted. Administrator recovery remains intact even if WCFM reports the user as a vendor. |
| WCFM not active / `wcfm_is_vendor` unavailable | Isolation predicate returns false. Ordinary DE role behaviour is unchanged. |
| WCFM reports current user as vendor | Restricted. No Delivery Engine wp-admin menu. Direct `admin.php?page=…` URLs denied. Access matrix does not offer WCFM vendor roles. |
| Customer storefront, checkout, shipping, Blocks, order summaries | Unchanged. Isolation does not run on those surfaces. |

Customer-owned storefront products are not hidden because their author is a WCFM vendor. Product wp-admin Delivery tabs remain Delivery Engine administration and are denied for restricted vendors.

## Implementation

- `Integrations/WCFM/WcfmVendorIsolation` — `is_restricted_vendor_user()`, explicit role exclusion (`wcfm_vendor`, and WCFM’s `disable_vendor` when WCFM is present), idempotent `Capabilities::ALL` strip.
- Current-user identity uses `wcfm_is_vendor()` / `wcfm_is_vendor(get_current_user_id())`. Installation detection uses `WCFM_VERSION` / `class_exists('WCFM')` / `function_exists('wcfm_is_vendor')`.
- `AdminPageAccess` is the common authorization boundary for every Delivery Engine admin page. Restricted vendors fail closed even with stale caps.
- `AdminMenu` registers no parent menu for restricted vendors.
- `RoleAccessService` excludes explicit WCFM vendor slugs. It does not guess from display names containing “vendor”.
- Capability matrix version bumped **3 → 4**. Schema remains **5**. Strip also runs on every boot and on activation so a later WCFM install is still protected.
- `enable_wcfm_adapter` remains a reserved stored key and **does not** control isolation.
- Integrations status: “Supported for administrative isolation. WCFM vendors are kept outside Delivery Engine administration. Vendor-specific Delivery Engine fulfilment controls are not provided.”

## Intentionally excluded

- Vendor fulfilment / vendor Delivery Engine UI
- A general WCFM adapter
- Stage 15
- Schema 6
- FLAIROC / training deploy
- Retagging or rebuilding RC.9
- Cart-state reconciliation (separate stream)
