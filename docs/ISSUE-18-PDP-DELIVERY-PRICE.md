# Issue #18 — Authoritative PDP delivery prices

**Status:** Implementation candidate for owner review. Not accepted. Not a release.

**Identity:** `1.0.0-dev.pdp-price.1` (schema `5`)  
**Branch:** `fix/pdp-delivery-price-display`  
**Baseline:** tagged `v1.0.0-rc.10` @ `d1409258caf1a90675b689ab105471460de4c713` (immutable)

## Behaviour

Product-page delivery options now attach additive public price fields from the same `SelectedOfferShippingRateCalculator::quote_for_selection()` path used at cart/checkout:

- `price_amount`, `price_currency`, `price_text`, `price_basis`
- Pickup is explicit Free/zero without a rate-card quote
- Unquoted delivery options fail closed and are omitted
- Quantity is sent on matching-location and variation AJAX refreshes
- Public payloads do not include supplier, origin, logistics profile, rate-card id/code, or fingerprints

## Tests run (not owner acceptance)

- PHPUnit: 1002 tests, 5634 assertions, 5 pre-existing deprecations, exit 0
- Vitest: 44 tests passed
- `php -l` on new PHP files: no syntax errors
- Live Storefront/WoodMart PDP vs cart browser comparison: **not run** (no authorized isolated WordPress lab in this session). Owner physical QA remains required.

## Intentionally excluded

- RC.11 / any promotion of this identity
- FLAIROC/training/production install
- Multi-group cart comparisons beyond equivalent single-group PDP vs cart
- Internal cost display
