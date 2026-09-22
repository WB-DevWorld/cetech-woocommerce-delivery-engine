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

Current rule: published baseline is immutable `v1.0.0-rc.12` (schema 6, release source `78594ad8962868683726373f58f4a8b1b48e4d0e`). Protected master may contain later reviewed post-RC.12 hardening and currently sits at `88c9ec09f3cfabf73b83780c0c37d397e9acdad6` before Issue #44 docs merge. Do not move RC.12, infer RC.13, or treat green master as Pilot/production approval. WPML/WCML, WP Rocket, B2BKing, FOX/WOOCS and other certifications remain evidence-specific. Do not start Stage 15.
