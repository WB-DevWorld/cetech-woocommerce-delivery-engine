# POST-RC.12 — PDP location precision / exact delivery quote (Issue #38)

**Current candidate identity:** `1.0.0-dev.pdp-precision.1`  
**Schema:** `6` (unchanged; no schema 7)  
**Branch:** `fix/pdp-location-precision`  
**Base:** protected `master` `514ddfc1b4d10b2171d3d3bae020381198c59d2b`  
**Not RC.13. Not deployed. Do not merge until owner/ChatGPT technical review.**

Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/38

## Defect

On initial PDP load, Ghana + Greater Accra could be prefilled while City / Town stayed hidden until the shopper changed Region away and back. The same render path could present Greater Accra Standard Delivery (GH₵30) as a final fee even though Accra PPLC is GH₵50.

## Root causes

1. **Client hydration:** `assets/frontend/product-delivery-selector.js` `loadChildren('administrative')` hid locality while rebuilding Region options and did not re-reveal after restoring the saved Region. Manual `onRegionChange()` did reveal. The same pattern exists in immutable RC.12 (`78594ad8962868683726373f58f4a8b1b48e4d0e`) and is not modified there.
2. **Server quoting too early:** `ProductDeliverySelectorRenderer` → `LocationAwareDeliveryOptions` → `ProductPageDeliveryPriceQuote` could quote remembered Ghana + Greater Accra before a locality existed.

## Implementation

### Part A — saved-region hydration

After administrative children restore the saved Region, City / Town is revealed immediately, saved city text and canonical locality key are preserved, pagination continues until the saved Region is actually found, skip_admin still shows locality, and stale tokens cannot reveal locality for an obsolete country.

### Part B — server-authoritative precision policy

- Domain result: `CetechDeliveryEngine\Domain\CustomerContext\ShopperLocationPrecision` (`sufficient`, `required_level`, `reason`, `message_key`, `public_message`, `toArray()`).
- Application policy: `CetechDeliveryEngine\Application\CustomerContext\ShopperDeliveryLocationPrecision`.
- Canonical resolution uses `CanonicalLocationResolver` + `CanonicalResolutionContext::ShopperSelector`.
- Usable Location Pack countries require enough precision when active usable Coverage Groups beneath the resolved location could change matching. Coverage shapes considered: `entire_area` rooted at a strict descendant; `selected_descendants` included members beneath the current location; `entire_except` / exclusions that make a broad location unsafe.
- Inactive zones, inactive groups, and `review_required` groups are ignored.
- Evaluation walks active Delivery Areas and usable Coverage Groups only (explicit roots/members + ancestry). It does not scan national geography tables.
- No Ghana / Accra / fee / database-id hard-coding.
- No usable pack: legacy compatibility (`sufficient`).

### Parts C–F

- Initial SSR (`ProductDeliverySelectorRenderer`) evaluates precision before rendering fees. Incomplete Delivery quotes are omitted; City / Town is forced visible; status copy is the exact-fee prompt; stale Delivery radios are not checked. Store Pickup remains independently selectable.
- `MatchingLocationOptionsEndpoint` (and variable `VariationDeliveryOptionsEndpoint`) return `status: need_precision` plus an explicit `precision` object; no Delivery cards; no Delivery `default_key`; pickup preserved.
- JavaScript consumes the server payload. It does not decide Ghana geography itself.
- `CartDeliverySelectionCapture` rejects Classic and Store API/Blocks Delivery submissions whose matching location is precision-incomplete. `add_cart_item_data()` fail-closes and will not persist an ambiguous Delivery context if validation is bypassed. Pickup is unaffected.

## Non-scope

Issue #39 cart UX, Issues #31/#32, Ashanti Region rename, Accra/Kumasi coverage mutation, GH pack mutation, schema/migration, RC.13, RC.12 mutation, training/Pilot/FLAIROC/production/POS deploy.

## Local verification (pre-CI)

Recorded after implementation. GitHub CI on the committed SHA is the release-gate evidence.

## Package

Built only after the runtime/package-source SHA is committed and GitHub CI SUCCESS. Do not overwrite previous dev ZIPs.
