# Ghana authoritative geography qualification manifest

Qualification evidence only. This is not Ghana-only runtime architecture.
Runtime geography remains provider-neutral canonical locations with WooCommerce and GeoNames mappings.

## Source authority

- **Preferred authority:** Ghana Statistical Service (GSS), official government administrative geography.
- **Source title:** Ghana Statistical Service — Regions and Districts (administrative geography used for national statistics).
- **Retrieval / publication date recorded:** 2026-09-17 (qualification pass for `1.0.0-dev.geo.4`).
- **Geography level used:** Country → Region (ADM1) → selected localities used in Delivery Engine QA.
- **Mapping / version:** WooCommerce country/state codes (`GH`, `GH:AA`, `GH:AH`) reconciled to GeoNames gazetteer ADM1/locality rows. Canonical identity is the Delivery Engine location key, not a GeoNames ID.

## Method

Compare customer-safe names used in CETECH Delivery Engine against GSS/government region and locality names.
Do not silently rewrite GeoNames when a source is ambiguous. Record disagreements explicitly.

## Sample comparisons

| Place | Engine canonical (after Woo + GeoNames reconcile) | GSS / official government name | Agreement |
| --- | --- | --- | --- |
| Country | Ghana | Ghana | Agree |
| ADM1 | Greater Accra | Greater Accra Region | Agree on core name; GeoNames/GSS often append “Region”. Engine keeps one canonical ADM1 holding both Woo `GH:AA` and GeoNames ADM1 `01`. |
| ADM1 | Ashanti | Ashanti Region | Agree on core name; same type-neutral “Region” suffix rule. |
| Locality | Accra | Accra | Agree; descendant of the same Greater Accra canonical node. |
| Locality | Tema | Tema | Agree; descendant of the same Greater Accra canonical node. |
| Locality | Madina | Madina | Agree as a Greater Accra locality used in QA. |
| Locality | Kumasi | Kumasi | Agree; descendant of Ashanti. |
| Locality | Ejisu | Ejisu | Agree; descendant of Ashanti. |
| Locality | Mampong | Mampong | Agree; Ashanti locality. Disambiguation against any same-name locality is via customer-safe breadcrumb, not provider IDs. |

## Explicit disagreements / caveats

- GeoNames labels many Ghana ADM1 features as “{Name} Region”. GSS also commonly uses “Region”. WooCommerce’s Ghana state label is the core name (for example “Greater Accra”). The engine reconciles these as one administrative node using country-neutral type-token stripping, not a Ghana-specific rewrite of GeoNames.
- District/municipal names (for example Accra Metropolitan, Tema Metropolitan) are not treated as a second Greater Accra ADM1.
- This manifest does not authorise substituting GSS IDs for canonical location keys.
- Physical QA of live Ghana packs is out of scope for this technical-correction pass.

## Later physical QA

This file accompanies later physical QA. It must travel with the qualified package evidence and must not be treated as a runtime Ghana-only code path.
