# POST-RC.8 Blocks.3 — Matched Delivery Area pricing fallback

**Document status:** Repair record. Not RC.9. Schema 5.  
**Date:** 2026-08-31  
**Identity:** `1.0.0-dev.blocks.3`  
**Branch:** `feat/post-rc8-integrations`  
**Physical QA evidence:** training.cetechbpa.com International Air `no_matching_rate_card` after Blocks.2 mixed-cart PASS

## Root cause

`DestinationZoneMatcher::match()` returned **one** Delivery Area.

For GH / AA / Accra:

- Area Accra matched country GH + city Accra.
- Area Greater Accra matched country GH + region Greater Accra.
- Both used the same configured priority.
- Equal-priority candidates were sorted by database ID, so Accra won because its ID was lower.

`SelectedOfferShippingRateCalculator` then quoted the **selected** Delivery Option against that one zone ID. The genuine Air rate existed on Greater Accra, so quote lookup returned `no_matching_rate_card`. Adding a duplicate Air rate to Accra made checkout work, which proved destination/rate resolution — not Blocks, not native WooCommerce fallback.

Administrators must not duplicate every regional Delivery Option price into every overlapping city area.

## Required behaviour

For a customer address, determine **all** matching active Delivery Areas and order them by:

1. configured area priority where it is intentionally different (lower number first, unchanged);
2. geographic specificity for otherwise competing matches: postcode > city > region > country > fallback;
3. deterministic name/code tie-break. Database creation order is not a business ranking.

Quoting tries that ordered list for the **same selected Delivery Option**:

- Standard Delivery with an Accra rate uses Accra.
- Air Shipping with no Accra rate and a Greater Accra rate uses Greater Accra.
- Air Shipping with rates in both uses Accra.
- Continue to a broader matched area **only** on `RateQuoteEngine::ERROR_NO_MATCHING_RATE_CARD`.
- A malformed, invalid, negative, or unsupported rate in the more-specific area remains fail-closed. It does not inherit the broader rate.
- A different Delivery Option is never substituted.
- Managed packages never expose native WooCommerce Flat Rate / Local Pickup as a fallback.
- Pickup remains explicit zero and does not enter delivery-area rate fallback.
- International remains Air/Sea only; In Warehouse remains local-delivery only (existing hard constraints).
- Classic Checkout uses the same calculator path.
- Schema stays 5.

`match()` remains the single-match API and returns `match_all()[0]` so snapshots and other primary-zone callers stay compatible. Pricing uses `match_all()` / `resolve_zone_ids()`.

## Architecture

Not a BlocksCheckoutValidation change.

- `DestinationZoneMatcher::match_all(...)` returns ordered matching active zones.
- `DestinationZoneMatcher::match(...)` returns the primary match.
- `PackageDestinationZoneResolver::resolve_zone_ids(...)` exposes the ordered IDs.
- `SelectedOfferShippingRateCalculator` quotes the selected offer against each matched zone ID in order.
- `RateQuoteEngine` is unchanged: optional dimensions, currency, effective dates, and rate-card precedence remain authoritative inside one zone.

## Admin usability

- **Test an address** shows `Primary match` and `Also matches`, plus an explanation that pricing can inherit from a broader matching Delivery Area when the selected Delivery Option has no charge in the more-specific area.
- Delivery Areas list shows an informational notice when nested overlaps exist.
- Needs Attention-style warnings are reserved for true problems:
  - equal-specificity, equal-priority overlapping areas (ambiguous ranking);
  - a more-specific area whose selected-offer charge is invalid while a broader overlapping area has a valid charge (fail-closed; will not inherit).
- Nested Accra + Greater Accra with Air priced only on Greater Accra is **not** a warning.

## Tests

Focused matcher / quote / overlap / Blocks / Classic / warehouse / international: **99 tests, 589 assertions, OK**.

Full PHPUnit: **828 tests, 4760 assertions, OK** (5 pre-existing deprecations). Previous blocks.2 baseline was 811 / 4717.

JS: **32 / 32**.

Production PHP lint (`src/`, `database/`, plugin root PHP): **399 OK / 0 FAIL**.

Composer: `composer.json` valid.

A. Same-priority city + region overlap: city ordered before region regardless of database ID.  
B. Selected Standard Delivery with Accra and Greater Accra rates: Accra-specific rate wins.  
C. Selected Air with no Accra rate and a Greater Accra rate: Greater Accra Air is returned.  
D. Selected Air with rates in both: Accra-specific Air wins.  
E. No matched area has the selected offer: fail closed.  
F. Specific area contains an invalid selected-offer rate: do not fall through.  
G. Managed package never exposes native WC fallback.  
H. Pickup remains zero.  
I. In Warehouse selected local delivery does not use an Air rate.  
J. International Air does not substitute Standard Delivery.  
K. Existing Classic shipping tests remain green.  
L. Existing Blocks tests remain green.

## Out of scope

- RC.9 / retag RC.8
- Schema 6
- FLAIROC
- WPML / WCML / WCFM / VitePOS adapters
- Return/Refund
- Per-item locations
- Stage 15
- Training data changes from code
