# Release Governance

State machine:
RECOVERED -> IMPLEMENTED -> LOCALLY TESTED -> INTEGRATED -> CI GREEN -> QUALIFIED ON TARGET STACK -> HUMAN ACCEPTED -> RELEASE CANDIDATE -> PRODUCTION APPROVED.

Rules:
- release identity does not prove runtime qualification;
- never move/recreate an accepted historical tag;
- never overwrite an artifact under the same version with different bytes;
- build from a clean committed SHA;
- verify the extracted ZIP, not merely source checkout;
- record packaged source SHA, docs/checksum SHA, ZIP filename, bytes and SHA-256;
- licensed integrations must be marked PASS/BLOCKED/NOT CERTIFIED honestly;
- production promotion remains a human decision.

## Current release truth — 2026-09-22
- Current immutable published release candidate: `v1.0.0-rc.12`.
- RC.12 release source: `78594ad8962868683726373f58f4a8b1b48e4d0e`; schema `6`.
- RC.12 must not be retagged, moved, rebuilt, or overwritten.
- Current protected integration branch: `master` at `88c9ec09f3cfabf73b83780c0c37d397e9acdad6`.
- Post-RC.12 fixes #29, #33, #35, #38, #39, #31, and #32 are merged on master but are not automatically RC.13.
- Training currently runs physically qualified development identity `1.0.0-dev.attention-count.1`, schema `6`; training is not production promotion.
- RC.13 does not exist.
- CETECH Pilot is not started.
- FLAIROC/production promotion requires separate explicit authorization.
- WPML/WCML remains a separate optional/certification stream unless explicitly brought into a future milestone.
- Do not start Stage 15 merely because post-RC.12 cleanup is complete.
