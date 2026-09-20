# RC.12 Promotion

Status: PREPARED FOR QUALIFICATION / NOT YET TAGGED

## Purpose
Promote the owner-accepted Issue #23 geography baseline already integrated into protected `master` to `1.0.0-rc.12` without adding new runtime behavior.

## Source lineage
- Prior immutable release: `v1.0.0-rc.11` → annotated tag object `acaae9bfc9758cdee1b3f2ec47e94848e83f87da` → `384f564f64a2db766ae6907392e95fb366fb8533`
- Owner-accepted geo.16 package-source SHA: `7aeb4c573d04d12d8101c0e05bc8858ff332d63f`
- Geo.16 qualified ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.16.zip` (`1,764,171` bytes, SHA-256 `500b09878ba08b879e5bdfdb0ea6e58726a71fe90a6994a83bb96b1e314a3925`)
- Protected master integration / PR #24 merge commit: `3d786ba6440a5f6f850d736bda0da5a5f5236c1f`
- Protected-master post-merge CI run `35519854001`: SUCCESS
- Issue #23: CLOSED / COMPLETED
- RC.12 release branch: `release/rc12`
- GitHub issue: `#26`

## Target identity
- Version: `1.0.0-rc.12`
- Schema: `6`
- No schema 7
- No new runtime feature work beyond the owner-accepted protected-master baseline

## Included post-RC.11 correction
Issue #23 adds canonical geography, Delivery Area coverage groups, cascading location UX, and the geo.16 Location Packs admin-form contract. RC.12 is an identity-only promotion of that already merged runtime. Recorded P3 items remain later cleanup.

## Required release evidence
Before `v1.0.0-rc.12` is created:
1. Release PR source must pass all four required CI jobs.
2. Owner/ChatGPT merge-safety authorization is required before protected merge.
3. Protected master after merge must pass the same four required CI jobs.
4. Production package must be built from the exact final protected-master release commit.
5. Production package verifier must pass on the extracted plugin root.
6. Packaged PHP lint must pass.
7. Clean install smoke must pass.
8. RC.11 → RC.12 upgrade smoke must pass with schema remaining 6 and historical data retained.
9. Optionally geo.16 → RC.12 identity-upgrade smoke if the existing qualification harness supports it cheaply.
10. Final ZIP filename, bytes, SHA-256, tag object and peeled commit must be recorded.

## Boundaries
- RC.11 tag/package remain immutable.
- No CETECH Pilot deployment.
- No FLAIROC/training/production deployment.
- No POS repository changes.
- No WPML/WCML merge.
- No WP Rocket certification expansion.
- No unrelated Stable-1.0 implementation.
- No P3 cleanup in this promotion.
- No schema 7.
- Stage 15 not started.

## Ownership
Sole owner / release authority: `@wbdevworld`.
