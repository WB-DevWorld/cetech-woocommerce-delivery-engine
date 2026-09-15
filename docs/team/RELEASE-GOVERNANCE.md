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

Current RC.10 rule: no RC.10 promotion until the currently declared pre-RC.10 qualification campaign and scope decision are complete, unless a newer explicit human decision changes that gate.
