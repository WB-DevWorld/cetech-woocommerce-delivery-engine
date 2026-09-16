# RC.11 Promotion

Status: COMPLETE / TAGGED / QUALIFIED RELEASE CANDIDATE

## Purpose
Promote the owner-accepted issue #18 baseline already integrated into protected `master` to `1.0.0-rc.11` without adding new runtime behavior.

## Final source lineage
- Prior immutable release: `v1.0.0-rc.10` → `d1409258caf1a90675b689ab105471460de4c713`
- Owner-accepted issue #18 candidate: `ecb0a69f8375712de0fb7ba53ee8abe2f9b438e5`
- Issue #18 protected-master integration: `35ff33d6788279a3ab75627f9eb9756b988ccb04`
- RC.11 release branch head: `b397489afc29d65a2b62a1afa57ba8ca6db3ef67`
- RC.11 final protected-master release source: `384f564f64a2db766ae6907392e95fb366fb8533`

## Final identity
- Version: `1.0.0-rc.11`
- Schema: `5`
- No schema migration
- No new runtime feature work beyond the owner-accepted protected-master baseline

## Included post-RC.10 correction
Issue #18 adds authoritative customer-facing delivery prices to PDP delivery options using the same server quote semantics as cart/checkout, including quantity-sensitive per-item pricing, fixed-per-shipment behavior, public-safe payloads, location/variation refresh, configured ETA requirement, Delivery/Pickup capability before quoting, no implicit store-country PDP quote, and Storefront/WoodMart/mobile qualification.

## CI evidence
- Release PR #21 exact-head CI run `35144591865`: PASS on all four required jobs.
- Protected-master post-merge CI run `35144871814`: PASS on all four required jobs.

Required jobs:
- Runtime PHP 8.1
- PHP / PHPUnit 8.2
- JavaScript / Vitest
- Control Plane

## Final qualified package
Successful final qualification run: `35145775218`.

- Filename: `cetech-woocommerce-delivery-engine-1.0.0-rc.11.zip`
- Bytes: `1,545,789`
- SHA-256: `97423a95273f6148ee855d1fb8a66c6c66e47aa20cb2c5868ecf2f84b2a9a521`
- Production package verifier: PASS
- Packaged PHP lint: `448 files / 0 failures`
- GitHub Actions qualification artifact ID: `10466617242`

The run-3 ZIP above is the canonical qualified RC.11 artifact. Earlier package builds are not release artifacts because full package + smoke qualification had not completed successfully, and ZIP byte identities are timestamp-sensitive.

## Isolated WordPress qualification
Successful run `35145775218` used an isolated GitHub Actions WordPress/WooCommerce lab and did not touch FLAIROC/training/production.

Clean install:
`clean version=1.0.0-rc.11 schema=5 tables=all_tables_ok`

RC.10 before upgrade:
`rc10 version=1.0.0-rc.10 schema=5 tables=all_tables_ok`

Upgrade/data retention:
`upgrade version=1.0.0-rc.11 schema=5 tables=all_tables_ok sentinel=1 option=keep_me`

Therefore clean install PASS and RC.10 → RC.11 upgrade/data retention PASS.

## Final tag
- Tag: `v1.0.0-rc.11`
- Annotated tag object: `acaae9bfc9758cdee1b3f2ec47e94848e83f87da`
- Peels to: `384f564f64a2db766ae6907392e95fb366fb8533`
- Object type: annotated `tag`
- Verification state: unsigned annotated tag; do not describe it as signed.
- Tag message records source, schema 5, final ZIP SHA-256, successful qualification run and release boundaries.

## Prior-release immutability
RC.10 remains unchanged:
- tag `v1.0.0-rc.10`
- tag object `506c2067f963ae7c1753fa0f32e2dc0e30b7d1c9`
- peels to `d1409258caf1a90675b689ab105471460de4c713`

## Boundaries
- No FLAIROC deployment or mutation.
- No training-site deployment or mutation.
- No production deployment or mutation.
- No POS repository changes.
- No WPML/WCML merge or certification expansion.
- No WP Rocket certification expansion.
- Stage 15 not started.

## Release meaning
`v1.0.0-rc.11` is the current tagged release candidate. It is not, by itself, production deployment approval. Any rollout/pilot requires a separate explicit owner decision.

## Ownership
Sole owner / release authority: `@wbdevworld`.
