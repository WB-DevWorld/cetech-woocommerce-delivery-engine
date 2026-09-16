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

Current RC.10 rule: owner QA passed. RC.10 is the authorized core collaboration baseline on `release/rc10`. Tag `v1.0.0-rc.10` only after protected merge. WPML/WCML and WP Rocket certification remain separate. Do not start Stage 15.
