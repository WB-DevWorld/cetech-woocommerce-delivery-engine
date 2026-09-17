# Ghana authoritative geography qualification manifest

Qualification evidence only. This is not Ghana-only runtime architecture.
Runtime geography remains provider-neutral canonical locations with WooCommerce and GeoNames mappings.

## Source authority

| Field | Record |
| --- | --- |
| Preferred authority | Ghana Statistical Service (GSS), official government administrative geography |
| Source title | 2021 Population and Housing Census — General Report Volume 3A: Population of Regions and Districts |
| Document identifier | GSS PHC 2021 General Report Vol 3A |
| Official URL | https://statsghana.gov.gh/gssmain/fileUpload/pressrelease/2021%20PHC%20General%20Report%20Vol%203A_Population%20of%20Regions%20and%20Districts_181121.pdf |
| Issuing body URL | https://statsghana.gov.gh/ |
| Publication / version date | 18 November 2021 (document filename date `_181121`; 2021 PHC regional/district population report) |
| Retrieval date | 2026-09-17 |
| Geography level used | Country → Region (ADM1) → selected localities used in Delivery Engine QA |
| Mapping / version | WooCommerce country/state codes (`GH`, `GH:AA`, `GH:AH`) reconciled to GeoNames gazetteer ADM1/locality rows. Canonical identity is the Delivery Engine location key, not a GeoNames ID. |

Supporting official context (not used to silently rewrite GeoNames):

- Ghana Statistical Service home: https://statsghana.gov.gh/
- 2021 PHC publications index: https://statsghana.gov.gh/gssmain/fileUpload/pressrelease/
- Local Government (Ghana) regional structure as used in national statistics: 16 regions including Greater Accra and Ashanti.

## Method

Compare customer-safe names used in CETECH Delivery Engine against GSS/government region and locality names.
Do not silently rewrite GeoNames when a source is ambiguous. Record disagreements explicitly.

## Sample comparisons

| Place | Engine canonical (after Woo + GeoNames reconcile) | GSS / official government name | Geography level | Agreement |
| --- | --- | --- | --- | --- |
| Country | Ghana | Ghana | Country | Agree |
| ADM1 | Greater Accra | Greater Accra Region | Region | Agree on core name; GeoNames/GSS often append “Region”. Engine keeps one canonical ADM1 holding both Woo `GH:AA` and GeoNames ADM1 `01`. |
| ADM1 | Ashanti | Ashanti Region | Region | Agree on core name; same type-neutral “Region” suffix rule. |
| Locality | Accra | Accra | Locality in Greater Accra | Agree; descendant of the same Greater Accra canonical node. |
| Locality | Tema | Tema | Locality in Greater Accra | Agree; descendant of the same Greater Accra canonical node. |
| Locality | Madina | Madina | Locality in Greater Accra | Agree as a Greater Accra locality used in QA. |
| Locality | Kumasi | Kumasi | Locality in Ashanti | Agree; descendant of Ashanti. |
| Locality | Ejisu | Ejisu | Locality in Ashanti | Agree; descendant of Ashanti. |
| Locality | Mampong | Mampong | Locality in Ashanti | Agree; Ashanti locality. Disambiguation against any same-name locality is via customer-safe breadcrumb, not provider IDs. |

## Explicit disagreements / caveats

- GeoNames labels many Ghana ADM1 features as “{Name} Region”. GSS Vol 3A also commonly uses “Region”. WooCommerce’s Ghana state label is the core name (for example “Greater Accra”). The engine reconciles these as one administrative node using country-neutral type-token stripping, not a Ghana-specific rewrite of GeoNames.
- District/municipal names (for example Accra Metropolitan, Tema Metropolitan) are not treated as a second Greater Accra ADM1.
- This manifest does not authorise substituting GSS IDs for canonical location keys.
- Physical QA of live Ghana packs is out of scope for this technical-correction pass.

## Later physical QA

This file accompanies later physical QA. It must travel with the qualified package evidence and must not be treated as a runtime Ghana-only code path.
