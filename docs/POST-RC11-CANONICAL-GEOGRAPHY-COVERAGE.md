# Post-RC.11 Canonical Geography & Delivery Area Coverage

Status: ISSUE #23 GEO.14 CAS CLOSURE / NOT MERGED / NOT RC.12

Owner: `@wbdevworld`

Branch: `feat/canonical-geography-coverage`

Starting protected master: `72fa354d52b49ffd9cbc32862a6b2e3d7117ea4e`

Immutable release anchor: `v1.0.0-rc.11` -> `384f564f64a2db766ae6907392e95fb366fb8533`

Rejected technical-review candidate: `1.0.0-dev.geo.1` (`606730e2535896cb18e415b14d62bbb57d050578`). Do not reuse that package identity or send it to physical QA.

Rejected technical-review candidate: `1.0.0-dev.geo.2` (`a52e2aafc97274b74ec14f8b451395b73c9541af`). Frozen package `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.2.zip` SHA-256 `6bde464b249ee6f17831e00d39110c8b10f89f441db3e1b453622a5d95f6b4a5`. Do not physically QA geo.2.

Rejected technical-review candidate: `1.0.0-dev.geo.3` (`fdff226cbe6837a3ac508bc6677c8e56771ec229`). Frozen package `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.3.zip` SHA-256 `1c1af4dcfbc6767402b6e7dfacde9a87270ac5ccb8a950788e130c9d130b49b8`. Do not physically QA geo.3.

Rejected technical-review candidate: `1.0.0-dev.geo.4` (`48f6a39436bc2464d4e2cfdb47221c7c59542706`). Frozen package `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.4.zip` SHA-256 `775ff1b7f31fdffb3ea246f1642693f86dd3e01fd0cb99c729add4831e535456`. Do not physically QA geo.4.

Rejected technical-review candidate: `1.0.0-dev.geo.5` (`f5da0b6b528e19ae57eedd9bc9bfee5ce05cc57b`). Frozen package `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.5.zip` SHA-256 `720a663800b57205637d02b9669eb9930b28b9dc6d1e2b180de6aea10edfa4ee`. Do not physically QA geo.5.

Rejected technical-review candidate: `1.0.0-dev.geo.6` (`730bcc2fa73fadd0da121487b7439edff5b3dfdc`). Frozen package `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.6.zip` SHA-256 `39437fb8a950e88968474eaf71e013b1b9e8a872b9e4b4710e7108d29d9a92c6`. Do not physically QA geo.6.

Rejected technical-review candidate: `1.0.0-dev.geo.7` (`389674173fbf6886cfbf615c4baa163e13cc12c8`). Frozen package `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.7.zip` SHA-256 `d167a0da7841c20bc141a7b3b294acba13291aa6e3e0f0e46cb0f98f36bf616a`. Do not physically QA geo.7.

Rejected technical-review candidate: `1.0.0-dev.geo.8` (`92cdeae1d1f9916bf38253772ec30240f3e4798b`). Frozen package `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.8.zip` SHA-256 `55e3c61297b4b1ab0ad06a3efa3c899c60fdf80f8d0fbd6bec8be5e5342d798f`. Do not physically QA geo.8.

Rejected technical-review candidate: `1.0.0-dev.geo.9` (`09df7d2337e271792f76290b062c7acba598d80a`). Frozen package `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.9.zip` SHA-256 `8ac4067ff8a9b5f1eb6bb1b8ad8224a168517160665804b0aa6b577a17029a47`. Do not physically QA geo.9.

Rejected technical-review candidate: `1.0.0-dev.geo.10` (`66be34b684c11c165617359b828ccf852fba2635`). Frozen package `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.10.zip` SHA-256 `494546cf021909b30a8f0519c816069af72c41b064cbe28b05a31409355f7f47`. Do not physically QA geo.10.

Rejected whole-branch closure-review candidate: `1.0.0-dev.geo.11` (`daef41a85662e1c1dc0aa6f5673ca9749f163a26`). Frozen package `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.11.zip` SHA-256 `5ba3eb3a6b10eba60e36735085071b628d1dc030e85f4e79f67e5753c9331400`. Do not physically QA geo.11.

Rejected technical-review candidate: `1.0.0-dev.geo.12` (`41861d261fe9c6ca4dead778464de19e5c03ac40`). Frozen package `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.12.zip` SHA-256 `e66ab64a4869dd6262fe2cea16f2a7b35949e487e34fbafc5c029bd3cb1203c2`. Do not physically QA geo.12 or reuse its package identity.

Rejected technical-review candidate: `1.0.0-dev.geo.13` (`31147df82a819416f15a2729ed87647c6ef80f9e`). Frozen package `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.13.zip` SHA-256 `c15c44c31ffee4c1baeb1ea6fdeac4fd1255a3ba86cca223ad793fe5c445797a`. Do not physically QA geo.13 or reuse its package identity.

Current technical-correction candidate identity: `1.0.0-dev.geo.14` (`ea53d0486593199898269479be624de262127692`). Package `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.14.zip` SHA-256 `cc3f320e8f9856c08d0e5c50ee05f41363db8a8ba79135bde23befdeaa212cc7`. Do not physically QA until independent geo.13→geo.14 CAS differential review.

geo.11 technical corrections (PR #24 comment `5730679997` / issue #23 A–O): nested hierarchy ownership via `prepared_hierarchy_root_id`; complete keyset iteration for zones/matcher/admin children; atomic schema-6 lease; inactive canonical members excluded from live authority; stale-response request tokens; truthful postcode relevance; unique geography cache revision; schema-6 verify of current columns; preflight MAX_SCAN is indeterminate rather than false invalidity.

Target schema: `6`

## Non-negotiable product decisions

1. Delivery option cards render: service name -> ETA/timeframe -> prominent delivery fee.
2. PDP location selection is progressive/cascading, not four always-visible fields.
3. Customer locality matching must use canonical place identity, not arbitrary free-text spelling.
4. Admin Delivery Area configuration and storefront selection use the same canonical location directory.
5. Postcode remains supported but is shown/required only where relevant.
6. A Delivery Area represents a set of destinations that share delivery treatment; one city does not equal one Delivery Area.
7. Coverage semantics are:
   - different hierarchy levels inside one group = AND;
   - multiple members at the same level = OR;
   - multiple coverage groups = OR.
8. Coverage groups support entire area, selected descendants, and entire area except selected descendants.
9. Canonical geography is provider-neutral. Provider IDs are mappings, never permanent business identity.
10. Location datasets are installed per country/market; the global gazetteer is not shipped in the core plugin ZIP.
11. RC.11 remains immutable. This work is post-RC.11 and does not imply RC.12 promotion.

## Why schema 6 is required

Schema 5 stores `destination_rules` as text values. `DestinationZoneMatcher::zone_matches_address()` currently requires every attached rule to match, so multiple same-type rules produce AND semantics. This cannot faithfully represent a scalable set-of-destinations model or canonical locality identity.

Schema 6 introduces persisted geography, provider mappings, coverage groups and coverage membership while preserving schema-5 rules for migration/compatibility evidence.

## Proposed schema-6 tables

Final names must follow `TableNames`/repository conventions, but the model must contain these concepts.

### `geography_packs`

Tracks installed/updateable datasets.

Minimum fields:
- id
- country_code
- provider
- dataset_name
- dataset_version
- source_url/source_reference
- checksum where available
- license_name / license_url / attribution text
- status (`pending`, `importing`, `ready`, `failed`)
- import cursor/progress metadata
- last_error
- installed_at / updated_at

### `geography_locations`

Canonical business-facing place identity.

Minimum fields:
- numeric PK
- immutable internal UUID/key
- country_code
- parent_location_id nullable
- location_type
- administrative_level nullable
- canonical_name
- normalized_name
- latitude/longitude nullable
- status
- created_at / updated_at

Important: the internal UUID/key survives provider refreshes and must not be derived solely from GeoNames or any other provider ID.

### `geography_location_aliases`

Minimum fields:
- id
- location_id
- alias
- normalized_alias
- language_code nullable
- alias_type/preferred flag
- status

### `geography_provider_mappings`

Maps canonical location to external provider identity.

Minimum fields:
- id
- location_id
- provider
- external_id
- pack_id/dataset_version
- provider_parent_reference nullable
- provider metadata needed for reconciliation
- unique provider + external ID

### `destination_coverage_groups`

Minimum fields:
- id
- zone_id
- root_location_id
- coverage_mode (`entire_area`, `selected_descendants`, `entire_except`)
- priority/order within zone
- status
- review_required flag
- legacy_migration_metadata/reason nullable
- created_at / updated_at

The root location carries its ancestry. Example: root `Greater Accra` already implies Ghana.

### `destination_coverage_members`

Minimum fields:
- id
- coverage_group_id
- location_id
- membership (`include` or `exclude`)
- created_at
- unique group + location + membership

Members must be validated as descendants of the group's root unless the group's mode explicitly permits the root itself.

### `destination_coverage_postcodes`

Keeps postcode constraints first-class without abusing locality identity.

Minimum fields:
- id
- coverage_group_id
- postcode_value
- match_mode (`exact`, `prefix`)
- priority/status as needed

## Runtime matching model

Introduce one canonical coverage matcher. Do not duplicate geography rules between PDP and cart/checkout.

Conceptual algorithm per active Delivery Area:

1. Load active coverage groups.
2. For each group:
   - validate destination canonical node belongs to root ancestry;
   - apply mode:
     - `entire_area`: any descendant/self under root matches;
     - `selected_descendants`: destination must equal/be represented by one selected include member according to permitted descendant semantics;
     - `entire_except`: root ancestry must match and destination must not be excluded;
   - apply postcode constraints when configured;
   - produce explainable match result including matched group and geographic specificity.
3. A zone matches when ANY coverage group matches.
4. Order matched zones using existing zone priority and geographic specificity rules.
5. Preserve matched-zone fallback behavior used by selected-offer pricing.
6. Unrestricted fallback remains last.
7. No match = fail closed.

Specificity must remain conceptually:
postcode > locality > narrower administrative area > broader administrative area > country > unrestricted fallback.

## Legacy schema-5 compatibility/migration

Migration must be additive and verifiable.

1. Create schema-6 geography/coverage tables first.
2. Do not drop `destination_rules` during migration.
3. Bootstrap canonical countries/admin areas from WooCommerce catalogs where available.
4. For each schema-5 zone:
   - ordinary one-value-per-level rules: map to one equivalent coverage group when canonical resolution is unambiguous;
   - repeated rules at the same level: migrate them as OR members, because schema-5 AND semantics made such areas impossible or misleading;
   - record `review_required` and audit/admin notice for any semantic change or ambiguous mapping;
   - unresolved text geography must remain review-required/compatibility-backed rather than silently mapped to the wrong location.
5. Preserve zone IDs so existing Rate Cards continue to point to the same Delivery Areas.
6. Verify before advancing schema version:
   - zone counts and IDs;
   - Rate Card references;
   - coverage groups/members;
   - unresolved/review-required counts;
   - idempotent second migration run.

A schema-5 RC.11 -> schema-6 upgrade smoke with seeded zones/rates is mandatory.

## Canonical resolution from WooCommerce addresses

WooCommerce checkout/cart may still provide country/state/city/postcode strings. Do not require replacing WooCommerce's entire address form in this stream.

Create a server-side canonical resolver that:
- resolves country/state using known codes/mappings;
- resolves locality by exact normalized canonical name/alias within the selected parent hierarchy;
- validates ancestry;
- never uses fuzzy matching for authoritative pricing without explicit deterministic rules;
- can use the canonical PDP selection stored in the cart intent when available;
- fails closed or uses explicit legacy compatibility only when canonical resolution is not safe.

The PDP itself must not offer arbitrary free-text locality fallback.

## Location-pack provider architecture

### Core rules
- Provider-neutral interface.
- Per-country install/update.
- Background/batched/idempotent import.
- Retry/resume after interruption.
- Local runtime reads; no public third-party API dependency for shopper requests.
- Attribution/license metadata retained.
- Rebuildable provider indexes must remain separate from Delivery Area business truth.

### WooCommerce provider
Use WooCommerce countries/states as the first source for country/admin-area nodes where available. Map them into canonical internal locations; do not use Woo labels as immutable IDs.

### GeoNames provider
Initial locality importer may use GeoNames country gazetteer files (`XX.zip`) and related admin/alternate-name datasets. Filter/import only features needed for delivery geography; do not blindly import irrelevant POIs/features.

Importer should understand at minimum:
- GeoNames ID as provider mapping only;
- country code;
- admin1/admin2/... codes where useful;
- feature class/code;
- canonical/ascii/alternate names;
- latitude/longitude;
- modification date/version provenance.

Prefer populated-place features for customer locality selection, plus administrative features needed to construct hierarchy.

### Ghana validation
Ghana is the first operational pack, but the model stays global. Ghana locality/admin mappings should be checked against authoritative Ghana geography sources where practical; do not hard-code Ghana-only schema assumptions.

### OpenStreetMap
May be used later for enrichment/self-hosted workflows. Do not implement shopper autocomplete against the public Nominatim service.

## Admin UX

Delivery Area coverage becomes a merchant-facing coverage builder.

Required controls:
- country
- administrative area
- coverage mode
- searchable locality multi-select where relevant
- selected locality chips/list
- count
- select all / clear where safe
- exclusions for `entire_except`
- add another coverage group
- readable group summary

Large locality sets use server-side search/pagination/debounce. Do not render thousands of HTML options.

## Storefront UX

No saved location:
1. Country only.
2. Reveal state/region after country.
3. Reveal searchable locality after parent selection.
4. Reveal postcode only if applicable/configured.
5. Changing parent clears descendants and invalidates stale quote state.

Delivery card hierarchy:
- option name
- ETA/timeframe
- `Delivery fee: <formatted amount>` or `Free`

Use current Woo/customer currency formatting and theme-friendly CSS variables/tokens.

## Security/privacy

- Validate all canonical IDs server-side.
- Validate parent-child ancestry server-side.
- Nonces/capabilities on admin and AJAX endpoints.
- Do not expose internal rate-card/supplier/origin/logistics-profile/config-fingerprint data.
- Escape imported provider labels before output.
- Sanitize imported data at persistence boundaries.
- Search endpoints must be paginated/cached and bounded.

## Performance

Indexes must support:
- country
- parent location
- type/admin level
- status
- normalized canonical name
- alias lookup
- provider/external ID
- coverage group by zone
- members by group/location

Never load an entire country pack into PHP memory for normal storefront requests.

## Qualification invariants

Before owner acceptance, prove:
- one zone with 20 included localities and one Rate Card;
- at least three member localities quote the same configured service/price;
- non-member behavior;
- entire-area mode;
- entire-except mode;
- multiple groups OR;
- hierarchy AND;
- repeated same-level members OR;
- country-only coverage;
- postcode exact/prefix;
- simple + variable PDP;
- Storefront + legitimate WoodMart;
- mobile;
- Classic + completed-address Blocks parity;
- RC.11 schema-5 -> schema-6 retained-data upgrade;
- package/privacy/runtime-gate regressions.

See GitHub issue #23 for the complete acceptance list. Issue #23 is the governing scope for this implementation stream.

## Location pack update and provider-row lifecycle

- `install` creates or continues the current dataset.
- `update` records a new source path, checksum and dataset version, resets the import cursor/phase, and preserves canonical internal IDs where provider mappings already exist.
- `retry` resumes the current dataset from its persisted cursor. A different file checksum is treated as `update`, never as a resume of the previous EOF.
- Provider name/parent/coordinate/alias changes update provider-derived canonical metadata without changing the internal location identity. Former names are kept as aliases.
- If a later dataset omits a previously imported place, the canonical location is **not** deleted or deactivated. Delivery Areas that already reference that internal ID keep working. Removal/deprecation is an explicit merchant review action, not an implicit import side-effect.
