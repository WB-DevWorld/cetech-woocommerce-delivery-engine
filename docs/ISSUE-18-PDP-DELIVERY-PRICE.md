# Issue #18 — Authoritative PDP delivery prices

**Status:** Implementation candidate for owner review. Not accepted. Not a release.

**Identity:** `1.0.0-dev.pdp-price.3` (schema `5`)  
**Branch:** `fix/pdp-delivery-price-display`  
**Rejected predecessor:** `1.0.0-dev.pdp-price.2` @ `5379ba77c7ffdc3d3d82392fa301cbb20e383420`  
**Baseline:** tagged `v1.0.0-rc.10` @ `d1409258caf1a90675b689ab105471460de4c713` (immutable)

## Behaviour

Product-page delivery options now attach additive public price fields from the same `SelectedOfferShippingRateCalculator` quote path as cart/checkout (`quote_for_selection()` and equivalent `calculate_for_package()` for a single managed delivery group). Delivery quotes require the shipping-rate runtime gate (`is_runtime_active()`). Pickup Free remains explicit and does not imply that the delivery shipping runtime is active.

- Delivery/Pickup switch is based on unfiltered fulfilment capability, not currently quoted cards
- PDP matching-location fields do not auto-select the WooCommerce store base country unless a real saved browsing location exists
- Explicit customer-selected country-only matching remains supported (`MatchingLocation::isPresent()` unchanged)
- Delivery cards require a real configured estimate; priced options without ETA fail closed
- Estimate copy uses translation-aware singular/plural (`1 business day` / `2 business days`)
- Compact two-column matching-location grid on PDP (single column on narrow viewports)
- `price_amount`, `price_currency`, `price_text`, `price_basis`
- Pickup is explicit Free/zero without a rate-card quote
- Unquoted delivery options fail closed and are omitted
- Quantity is sent on matching-location and variation AJAX refreshes
- Public payloads do not include supplier, origin, logistics profile, rate-card id/code, or fingerprints

## Tests run (not owner acceptance)

- PHPUnit: 1022 tests, 5718 assertions, 5 pre-existing deprecations, exit 0
- Vitest: 49 tests passed
- Control plane: OK
- composer validate --no-check-publish: valid
- `php -l` on 583 non-vendor PHP files: no syntax errors
- Runtime PHP 8.1 lint of changed runtime files: no syntax errors
- Live Storefront/WoodMart PDP vs cart browser comparison: **isolated lab after package**

## Intentionally excluded

- RC.11 / any promotion of this identity
- FLAIROC/training/production install
- Multi-group cart comparisons beyond equivalent single-group PDP vs cart
- Internal cost display
