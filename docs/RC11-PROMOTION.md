# RC.11 Promotion

Status: PREPARED FOR QUALIFICATION / NOT YET TAGGED

## Purpose
Promote the owner-accepted issue #18 baseline already integrated into protected `master` to `1.0.0-rc.11` without adding new runtime behavior.

## Source lineage
- Prior immutable release: `v1.0.0-rc.10` → `d1409258caf1a90675b689ab105471460de4c713`
- Owner-accepted issue #18 candidate: `ecb0a69f8375712de0fb7ba53ee8abe2f9b438e5`
- Protected master integration commit: `35ff33d6788279a3ab75627f9eb9756b988ccb04`
- RC.11 release branch: `release/rc11`

## Target identity
- Version: `1.0.0-rc.11`
- Schema: `5`
- No schema migration
- No new runtime feature work beyond protected master baseline

## Included post-RC.10 correction
Issue #18 adds authoritative customer-facing delivery prices to PDP delivery options using the same server quote semantics as cart/checkout, including quantity-sensitive per-item pricing, fixed-per-shipment behavior, public-safe payloads, location/variation refresh, configured ETA requirement, Delivery/Pickup capability before quoting, no implicit store-country PDP quote, and Storefront/WoodMart/mobile qualification.

## Required release evidence
Before `v1.0.0-rc.11` is created:
1. Release PR source must pass all four required CI jobs.
2. Protected master after merge must pass the same four required CI jobs.
3. Production package must be built from the exact final protected-master release commit.
4. Production package verifier must pass on the extracted plugin root.
5. Packaged PHP lint must pass.
6. Clean install smoke must pass.
7. RC.10 → RC.11 upgrade smoke must pass with schema remaining 5 and historical data retained.
8. Final ZIP filename, bytes, SHA-256, tag object and peeled commit must be recorded.

## Boundaries
- RC.10 tag/package remain immutable.
- No FLAIROC/training/production deployment.
- No POS repository changes.
- No WPML/WCML merge.
- No WP Rocket certification expansion.
- Stage 15 not started.

## Ownership
Sole owner / release authority: `@wbdevworld`.
