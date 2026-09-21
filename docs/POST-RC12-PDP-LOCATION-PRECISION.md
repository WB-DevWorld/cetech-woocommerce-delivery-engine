# POST-RC.12 — PDP location precision / exact delivery quote (Issue #38)

**Current candidate identity:** `1.0.0-dev.pdp-precision.2`  
**Schema:** `6` (unchanged; no schema 7)  
**Branch:** `fix/pdp-location-precision`  
**Base:** protected `master` `514ddfc1b4d10b2171d3d3bae020381198c59d2b`  
**Runtime / package-source SHA:** `05b9b393995c884d30f9d761513fcd3beb622587`  
**PR:** https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/40  
**Not RC.13. Not deployed. Do not merge until owner/ChatGPT final deployment review.**

Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/38

Frozen predecessor: `1.0.0-dev.pdp-precision.1` / `dd078712bd97024d49ccd208a0b9d7e82d25f857`. Do not overwrite or rebuild that ZIP.

## Defect

On initial PDP load, Ghana + Greater Accra could be prefilled while City / Town stayed hidden until the shopper changed Region away and back. The same render path could present Greater Accra Standard Delivery (GH₵30) as a final fee even though Accra PPLC is GH₵50.

## Root causes

1. **Client hydration:** `assets/frontend/product-delivery-selector.js` `loadChildren('administrative')` hid locality while rebuilding Region options and did not re-reveal after restoring the saved Region. Manual `onRegionChange()` did reveal. The same pattern exists in immutable RC.12 (`78594ad8962868683726373f58f4a8b1b48e4d0e`) and is not modified there.
2. **Server quoting too early:** `ProductDeliverySelectorRenderer` → `LocationAwareDeliveryOptions` → `ProductPageDeliveryPriceQuote` could quote remembered Ghana + Greater Accra before a locality existed.

## Implementation (pdp-precision.1, retained)

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

## pdp-precision.2 narrow technical corrections

1. **SSR fulfilment choice.** When Delivery exists and precision is incomplete, SSR keeps active fulfilment = Delivery, leaves Delivery `display_key` empty, keeps the outer location panel visible, keeps the precision prompt and City/Town visible, and does not auto-check Pickup. Explicit Pickup selection may hide the location panel normally. Pickup-only products are unchanged. Variable ECR AJAX follows the same rule.
2. **Differential quote proof.** Test fixture `PdpPrecisionTestKit::location_aware_quote()` composes `MatchingLocationOptionsEndpoint` → `LocationAwareDeliveryOptions` → `ProductPageDeliveryPriceQuote` → `SelectedOfferShippingRateCalculator` / `DestinationZoneMatcher` / `CoverageGroupMatcher`. Accra-specific zone GH₵50 then Greater Accra GH₵30. Amounts exist only in tests. Greater Accra broad → `need_precision` with no Delivery price or `default_key`. Accra PPLC → GH₵50. Tema → GH₵30.
3. **Next selectable hierarchy.** `ShopperDeliveryLocationPrecision::next_level()` walks parent ancestry of explicitly configured coverage nodes (`find_by_id`, depth ≤ 16). Country + nested locality with an ADM ancestor below country → `required_level=region`. Country + locality that is a direct child of country → `locality`. Region + nested locality → `locality`. Greater Accra / Accra remains `locality`.
4. **Public precision object.** `ShopperLocationPrecision::toArray()` exposes `sufficient`, `required_level`, `reason`, and `message_key`. Endpoint `message` remains the localized customer text. Localized `public_message` is not duplicated inside `precision`.

## Frozen package (pdp-precision.1)

Do not overwrite or rebuild this ZIP.

- Source SHA: `dd078712bd97024d49ccd208a0b9d7e82d25f857`
- Filename: `cetech-woocommerce-delivery-engine-1.0.0-dev.pdp-precision.1.zip`
- Bytes: `1,823,157`
- SHA-256: `5773b5b2eb9d63bdc5e8b92bbbd156cb78ba4bc4deb74f34db105673542d3fda`
- Production-package verifier: PASS (staged and extracted)
- Packaged PHP lint: `494 files / 0 failures` (PHP 8.5.0, vendor excluded)
- GitHub CI on runtime SHA: SUCCESS (`35632365005` pull_request; `35632307721` push)
- PHPUnit (PHP 8.5 CETECH Production Target): Tests: 1340, Assertions: 8479, Deprecations: 14, Skipped: 1
- Vitest: 87 passed / 7 files
- Real MariaDB / geography: Tests: 26, Assertions: 1739, Deprecations: 2, failures=0
- Not deployed to training

## Package (pdp-precision.2, after CI)

Built from a clean committed tree after GitHub CI SUCCESS on the runtime/package-source SHA. Do not treat this ZIP as RC.13 or as a replacement for RC.12, geo-country, or frozen pdp-precision.1.

- Source SHA: `05b9b393995c884d30f9d761513fcd3beb622587`
- Filename: `cetech-woocommerce-delivery-engine-1.0.0-dev.pdp-precision.2.zip`
- Bytes: `1,824,330`
- SHA-256: `1b0c02dcc88370b5f8535c59acb00ad3773a95b2182539b333778046a913557e`
- Production-package verifier: PASS (staged and extracted)
- Packaged PHP lint: `494 files / 0 failures` (PHP 8.5.0, vendor excluded)
- GitHub CI on runtime SHA: SUCCESS (`35638605797` pull_request; `35638601485` push) — PHP 8.3 Minimum Supported, PHP 8.4 Compatibility, PHP 8.5 CETECH Production Target, PHP 8.5 MariaDB Geography/Migrations, PHP 8.5 WordPress/WooCommerce, CI Required Gates, JavaScript / Vitest, Control Plane
- PHPUnit (PHP 8.5 CETECH Production Target): Tests: 1344, Assertions: 8509, Deprecations: 14, Skipped: 1
- Vitest: 88 passed / 7 files
- Real MariaDB / geography: Tests: 26, Assertions: 1764, Deprecations: 2, skipped=0, failures=0
- Not deployed to training

## Non-scope

Issue #39 cart UX, Issues #31/#32, Ashanti Region rename, Accra/Kumasi coverage mutation, GH pack mutation, schema/migration, RC.13, RC.12 mutation, training/Pilot/FLAIROC/production/POS deploy.
