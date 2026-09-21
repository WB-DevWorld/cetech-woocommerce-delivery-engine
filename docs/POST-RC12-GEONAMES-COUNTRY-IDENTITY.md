# POST-RC.12 — GeoNames canonical country identity (Issue #35)

**Current candidate identity:** `1.0.0-dev.geo-country.4`  
**Schema:** `6` (unchanged; no schema 7)  
**Branch:** `fix/geonames-country-identity`  
**Base:** protected `master` `5abfab0b5078e67b158f282088022b2ac2566f22`  
**Runtime / package-source SHA:** pending committed runtime SHA  
**Frozen geo-country.3 runtime / package-source SHA:** `bae75f79d0094c78706912c9a530f09ea21b9bed` (do not rebuild or overwrite)  
**Frozen geo-country.2 runtime / package-source SHA:** `23237eedece174b2f9c4334693b137310ce5c54a` (do not rebuild or overwrite)  
**Frozen geo-country.1 runtime / package-source SHA:** `b61466ffc14c71e1a678eaf2d5b84f22bf6040cb` (do not rebuild or overwrite)  
**PR:** https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/36  
**Not RC.13. Not deployed. Awaiting owner/ChatGPT deployment review.**

Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/35

## Frozen geo-country.1 package (pre-deployment technical-review evidence)

Do not overwrite or rebuild this artifact.

- Source SHA: `b61466ffc14c71e1a678eaf2d5b84f22bf6040cb`
- Filename: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.1.zip`
- Bytes: `1,794,741`
- SHA-256: `216e3a28d37e6af5f6cf97a69ef362dc946a94b1d26d8508c3d747d582198a6c`

## Package (geo-country.2, frozen differential-review evidence)

Do not overwrite or rebuild this artifact.

- Source SHA: `23237eedece174b2f9c4334693b137310ce5c54a`
- Filename: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.2.zip`
- Bytes: `1,798,020`
- SHA-256: `6e7f76e2d218a01e2af401cc646471dc2ce27afc8a52c6ad15e671df0b88122c`

## Package (geo-country.3, frozen owner-fenced-lease evidence)

Do not overwrite or rebuild this artifact.

- Source SHA: `bae75f79d0094c78706912c9a530f09ea21b9bed`
- Filename: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.3.zip`
- Bytes: `1,797,361`
- SHA-256: `a86a940d8ebac2c9e293dad8690ed8f121b5afa66cb3f1e0537f3f4152b26c66`
- Production-package verifier: PASS (staged and extracted)
- Packaged PHP lint: `501 files / 0 failures`
- Not deployed to training

## Package (geo-country.4, after CI)

Built from a clean committed tree after GitHub CI SUCCESS on the runtime/package-source SHA. Do not treat this ZIP as RC.13 or as a replacement for RC.12, geo-country.1, geo-country.2, or geo-country.3.

- Source SHA: pending committed runtime SHA
- Filename: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.4.zip`
- Bytes: pending package build
- SHA-256: pending package build
- Production-package verifier: pending
- Packaged PHP lint: pending
- Not deployed to training

## Country-identity authority

WooCommerce country identity is the stable canonical root for this plugin.

- `country_code` and the country location key/id must not be renamed by an arbitrary GeoNames political or historical row.
- GeoNames may attach a single accepted country-identity mapping, aliases, coordinates, and descendants.
- Multiple `PCL*` rows must never compete to rename one country node.

## Accepted GeoNames country-feature contract

Exact feature codes only. These are **provider identity-enrichment** categories. WooCommerce remains canonical naming authority.

- `PCLI` independent political entity
- `PCLD` dependent political entity
- `PCLF` freely associated state
- `PCLS` semi-independent political entity
- `PCLIX` section of independent political entity

Additional fences:

- `feature_class` must be `A`
- `row.country_code` must equal the Location Pack country code
- the row must not replace the WooCommerce canonical name

## Rejected feature-code behavior

`PCLH` (historical political entity), generic `PCL`, and any other `PCL*` prefix match are **not** country features. They are not relevant gazetteer rows. They do not call `map_country_feature()`, do not attach as the country identity mapping, and do not become ADM1 children of the country.

## Training forensics (read-only; nothing mutated)

GH gazetteer file identity:

- Path: `/home/cetechtraining/htdocs/training.cetechbpa.com/wp-content/uploads/cetech-delivery-engine/geography/GH.geonames-GH-1-9db333869221df3d.c649a65fab3b.txt`
- Bytes: `2,464,452`
- SHA-256: `c649a65fab3b4027b6246022f4f65b9f658d0ac04119ef9d3bd338eaac39809a`

Canonical Ghana country row before repair (not executed):

| Field | Value |
|---|---|
| id | `1` |
| location_key | `1caaf0dc-d575-4dd3-8a9d-217d136e5548` |
| country_code | `GH` |
| parent_location_id | `NULL` |
| location_type | `country` |
| canonical_name | `Dagomba` |
| normalized_name | `ghana` |
| ascii_name | `dagomba` |
| latitude / longitude | `9.5` / `-0.25` |
| status | `active` |
| ancestry_path | `/1/` |
| generation | `0` |
| draft_json | `NULL` |
| created_at | `2026-09-20 17:33:04` |
| updated_at | `2026-09-21 01:10:52` |

GH pack remains `ready`, checksum unchanged, target/active generation `3`, `15,615` generation-3 active locations plus `18` generation-0 active roots/states.

### Dagomba source row (line 8453)

| Column | Value |
|---|---|
| geonameid | `2302058` |
| name / asciiname | `Dagomba` |
| alternatenames | `Dagomba` |
| latitude / longitude | `9.5` / `-0.25` |
| feature_class | `A` |
| feature_code | `PCLH` |
| country_code | `GH` |
| admin1 / admin2 | `06` / `2302058` |
| population | `0` |
| timezone | `Africa/Accra` |
| modification | `2019-09-01` |

### Legitimate Ghana country source row (line 7060)

| Column | Value |
|---|---|
| geonameid | `2300660` |
| name / asciiname | `Republic of Ghana` |
| feature_class | `A` |
| feature_code | `PCLI` |
| country_code | `GH` |
| admin1 | `00` |
| latitude / longitude | `8.1` / `-1.2` |
| population | `29767108` |
| modification | `2024-09-05` |

### All GH `A/PCL*` source rows

| Line | geonameid | name | feature_code |
|---|---|---|---|
| 7060 | `2300660` | Republic of Ghana | `PCLI` |
| 8453 | `2302058` | Dagomba | `PCLH` |

### Proven import sequence

1. WooCommerce bootstrap created country id `1` as Ghana / `ghana`.
2. Gazetteer order processed `Republic of Ghana` `PCLI` first (line 7060). `is_country_feature()` treated it as a country row and `map_country_feature()` applied it to id `1`, attaching GeoNames `2300660`.
3. Later line 8453 `Dagomba` `PCLH` matched `str_starts_with($feature_code, 'PCL')`, so it was also classified `is_country=true`.
4. `map_country_feature()` last-write-wins onto the single country node. The staged draft set `canonical_name=Dagomba` and `ascii_name=dagomba` but omitted `normalized_name`.
5. Set-based promotion applied the draft canonical/ascii names while `COALESCE` left `normalized_name=ghana`.
6. GeoNames mapping `2302058` `PCLH` was attached directly to country id `1`. Country aliases are Ghana language variants from the PCLI row; Dagomba itself became the canonical name rather than an alias.

### Other training country roots (read-only)

| code | id | canonical_name | normalized_name | status | GeoNames country mapping |
|---|---|---|---|---|---|
| BB | 36 | Barbados | barbados | active | none (WooCommerce only) |
| DE | 19 | Germany | germany | active | none |
| GE | 15707 | Georgia | georgia | active | none |
| GH | 1 | **Dagomba** | ghana | active | `2300660` PCLI **and** `2302058` PCLH |
| US | 37 | United States (US) | united states (us) | active | none |

Only GH has a canonical/normalized split and a wrong identity.

## Product repair (not executed on training)

`CountryIdentityReconciler` is idempotent and country-agnostic:

- Restores WooCommerce country label in place (same id, location_key, country_code, generation, ancestry).
- Detaches GeoNames mappings whose feature code is not an accepted country-identity code (Dagomba `2302058` `PCLH`).
- Keeps the legitimate PCLI mapping (`2300660`) and WooCommerce `GH` mapping.
- Removes aliases whose normalized form matches a detached invalid mapping name (`dagomba`). Does not wipe Ghana language aliases.
- Restores coordinates from the accepted identity row in the existing pack file **only when the root already needs repair**.
- Historical kickoff (`CountryIdentityKickoff` / `maybe_repair()`) persists `cetech_de_country_identity_repair_revision` = `REPAIR_REVISION` `1` (`geo-country-identity-v1`). Plugin version changes do not rerun it.
- Sequence: revision check → owner-fenced lease acquire → **revision recheck while the lease is owned** → `repair_all()` only if still incomplete → persist revision → release lease. A delayed contender that acquires after the first owner finishes must return `revision_complete` and must not call `repair_all()`.
- The repair lock is an **owner-fenced renewable lease** (`cetech_de_country_identity_repair_lock`): `{owner, expires_at, acquired_at, revision}`, TTL 60s. Initial acquisition uses atomic `add_option` / options-table insert. Stale takeover is a `$wpdb` compare-and-swap of the exact encoded `option_value` (no unconditional `delete_option`). The owner renews `expires_at` before and after each country root. Release deletes only the held lease value. Lost ownership stops the historical pass without persisting the revision. Cache groups `options` / `alloptions` / `notoptions` are invalidated after CAS.
- `GeoNamesPackImporter` still calls `repair_country_code()` after successful promotion. That path is **not** gated by the historical revision or the historical lease.
- Live identity is repaired even while a pack is Importing/Pending. Staged `generation_token` mappings and aliases are not listed or deleted. Pack cursor/status/generation are never written by the reconciler.
- Clean roots return unchanged with source-scan count 0.

This is **not** a Ghana-only migration, **not** a schema 7 change, **not** a pack reset, and **not** a delete/recreate of country id 1.

## Training physical-QA plan — DO NOT EXECUTE YET

BEFORE: country id 1 = Dagomba / normalized ghana. Training plugin remains `1.0.0-dev.geo-live.2`.

AFTER a later authorized deploy of the exact `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.4.zip` (not this task; backup first):

- same id `1`, same location_key `1caaf0dc-d575-4dd3-8a9d-217d136e5548`, same `GH`
- canonical_name `Ghana`, normalized_name `ghana`, ascii consistent
- coordinates restored from PCLI `2300660` (`8.1`, `-1.2`)
- PCLH mapping `2302058` removed; PCLI `2300660` retained
- GH pack stays `ready`, generation remains `3`, checksum unchanged
- 15,615 generation-3 locations preserved; no import restart; no generation 4
- Coverage Groups unchanged; Accra/Kumasi remain `review_required`
- no Delivery Charge or Delivery Option changes
- storefront/admin breadcrumbs use Ghana
- repair revision becomes `1`; repair lease absent afterward
- a second normal request returns `revision_complete` and performs no repair work

Do not deploy. Do not confirm migrated Accra/Kumasi coverage.

## Non-scope

Issue #31, Issue #32, Accra/Kumasi human review, admin-notice overwrite (later P3), RC.13, Pilot, FLAIROC, production, POS.
