# POST-RC.8 Blocks.4 — Constrained Delivery Area fallback

**Document status:** Repair record. Not RC.9. Schema 5.  
**Date:** 2026-09-01  
**Identity:** `1.0.0-dev.blocks.4`  
**Branch:** `feat/post-rc8-integrations`  
**Protected published baseline:** tagged `v1.0.0-rc.8` **untouched**  
**FLAIROC:** not modified

## Root cause

`DestinationZoneMatcher::match_all()` stored any area with `is_fallback` as a catch-all. When no rule-based area matched, that catch-all was returned even if the area had location rules.

Example: Greater Accra marked Fallback, with Ghana + Greater Accra rules, was used for USA / New York. Administrators reasonably expected Fallback to mean “use this area when a more-specific area does not match,” not “ignore geography.”

Matched-area **pricing** fallback (Accra city → Greater Accra region for the same selected Delivery Option) was already correct in `1.0.0-dev.blocks.3` and is unchanged.

## Required behaviour

- A Delivery Area marked Fallback that still has location rules remains constrained by those rules. Greater Accra never matches USA / New York merely because Fallback is ticked.
- Only an explicitly unrestricted / ruleless fallback may act as a true Everywhere else area.
- If no normal area matches and no eligible global fallback exists, remain fail closed (`destination_unresolved`). Never substitute another Delivery Option. Never expose native WooCommerce Flat Rate / Local Pickup on a managed package.
- Specific-area → broader genuinely matching area same-offer pricing inheritance stays as in blocks.3.
- Pickup remains explicit zero. International remains Air/Sea only. In Warehouse remains local delivery only.
- Classic Checkout and native Blocks continue to use the same matcher / calculator path.
- Schema stays 5. No RC.9.

## Admin usability

- **Test an address → Country** is the normal WooCommerce country selector (Ghana, United States, …). The posted value is stored and matched as an ISO-2 code.
- Fallback checkbox copy distinguishes:
  - **Global fallback:** no location rules; Everywhere else; used only when no other area matches.
  - **Constrained fallback:** location rules still apply; cannot escape its geography.
- The Delivery Areas list labels those two cases so staff can see the difference without reading the form help text.

## Architecture

No new schema. No second resolver.

- `DestinationZoneMatcher::is_unrestricted_fallback()` is the single test for a true Everywhere else area.
- `geographic_specificity_rank()` keeps the rank of location rules when a fallback also has rules. Only a ruleless fallback ranks as fallback specificity.
- `OverlappingDeliveryAreaCoverage` includes constrained fallbacks in nested-overlap analysis and still ignores ruleless global fallbacks.
- Diagnostics warn on more than one unrestricted fallback, not on a constrained fallback plus one global fallback.

## Tests

Focused matcher / quote / overlap / Blocks / Classic:

| Suite | Result |
|-------|--------|
| `ConstrainedFallbackGeographyTest` | **5 tests, OK** |
| `ConstrainedFallbackAdminUsabilityTest` | **2 tests, OK** |
| `MatchedAreaPricingFallbackTest` | **14 tests, OK** (includes native WC prohibition, Accra overlap, Air inheritance, invalid-rate block, constrained/global/no-fallback) |
| `DestinationZone*` matcher tests | **14 tests, OK** |
| `OverlappingDeliveryAreaCoverageTest` | **4 tests, OK** |
| `BlocksCheckoutAdapterTest` | **17 tests, OK** |
| `ClassicCheckoutRuntimeActivationTest` | green in full suite |

Full PHPUnit: **840 tests, 4798 assertions, OK** (5 pre-existing deprecations). Previous blocks.3 baseline was 828 / 4760.

JS: **32 / 32**.

Production PHP lint (`src/`, `database/`, plugin root PHP): **399 OK / 0 FAIL**.

Composer: `composer.json` valid.

Required cases:

- Constrained Greater Accra fallback does not match USA / New York.
- Ruleless fallback catches unmatched addresses for the selected offer.
- No fallback → unresolved / fail closed.
- Accra + Greater Accra overlap still orders city before region.
- Air still inherits Greater Accra pricing, including when Greater Accra is a constrained fallback.
- Invalid specific rate still blocks; it does not fall through.
- Managed packages never expose native WooCommerce fallback.
- Existing Classic and Blocks suites remain green.

## Out of scope

- RC.9 / retag RC.8
- Schema 6
- FLAIROC
- WPML / WCML / WCFM / VitePOS adapters
- Return/Refund
- Per-item locations
- Stage 15
- Changing matched-area pricing inheritance accepted in blocks.3
