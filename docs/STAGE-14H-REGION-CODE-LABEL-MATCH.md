# Region code/label matching repair (post-RC.6-qa.1)

**Status:** Packaged as owner QA `1.0.0-rc.6-qa.2` — not a replacement for tagged `v1.0.0-rc.5`; FLAIROC not deployed  
**Plugin version in tree:** `1.0.0-rc.6-qa.2`  
**Schema:** `4` (unchanged; no migration)  
**Date:** 2026-08-20  
**Package record:** `docs/STAGE-14H-RC6-QA2-OWNER-QA-PACKAGE.md`

## Defect

WooCommerce package destinations supply canonical state/region codes (Ghana Greater Accra = `AA`). Delivery Areas allowed administrators to save the human-readable State / Region label (`Greater Accra`). `DestinationZoneMatcher::rule_matches()` compared the saved region string literally against the package `state` value, so `greater accra !== aa`.

Controlled testing: `GH` / `AA` / `Accra` resolved away from the Accra area; `GH` / `Greater Accra` / `Accra` resolved to Accra. The admin address tester and checkout therefore disagreed when the stored rule used a label.

## Repair

- Add `WooCommerceStateCatalogInterface` / `WooCommerceStateCatalog` to read WooCommerce country state maps.
- Add `RegionCodeLabelMatcher` so a region rule matches either the canonical code or that **country’s** human-readable label, case-insensitively.
- `DestinationZoneMatcher` uses the matcher for Region rules only.
- Existing rules stored as codes continue to work. Existing rules stored as labels begin working at WooCommerce checkout.
- No Delivery Area rows are migrated or rewritten.

## Intentionally unchanged

Country, City, Postcode, priority, fallback, rate-card, grouping, shipping-method registry listing (QA.1), inheritance, shipments, tracking, schema 4, RC.5 history, FLAIROC, Stage 15.

## Tests actually run

Recorded in `docs/STAGE-14H-RC6-QA2-OWNER-QA-PACKAGE.md`.
