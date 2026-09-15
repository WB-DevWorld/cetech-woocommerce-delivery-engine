# CETECH Delivery Engine — Current Status

Last reconciled: 2026-09-15 (issue #2 local Git recovery).

## Canonical repository
- Organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`
- Default/canonical published branch: `master`
- Recovery baseline `master` before reconciliation PR #9: `d2ebc620762c6acd1b3a143ee120bea906c6d205`
- The exact current canonical SHA is the protected GitHub `master` branch head; do not treat the recovery-baseline SHA above as a perpetual branch tip.
- Pre-bootstrap RC.9-line `master`: `376c0896df0d85b159e8713c79aadb6c9b8a3839` (ancestor of the protected `master` lineage; not rewritten)
- Repository visibility: public during GitHub Free branch/ruleset protection use
- Composer license declaration remains `proprietary`; public visibility is not an open-source license grant.

## Published baseline
- version: `1.0.0-rc.9`
- schema: `5`
- published remote tags: `v1.0.0-rc.2`, `v1.0.0-rc.4`, `v1.0.0-rc.5`, `v1.0.0-rc.6`, `v1.0.0-rc.9`
- `v1.0.0-rc.4` GitHub Release and its ZIP/checksum survived repository transfer.
- Local-only tags `v1.0.0-rc.3`, `v1.0.0-rc.7`, `v1.0.0-rc.8` remain unpublished by design. Their peeled commits matched prior project evidence (`v1.0.0-rc.8` → `6d166227998d4b0f5047fea91944ff024b389810`; `v1.0.0-rc.9` → `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5`).

## Completed/released lineage
- RC.2–RC.6: Classic checkout/site-wide defaults/admin UX/shipments V1 lineage.
- RC.7: Bulk Tools lineage (schema 5), present in Git history; the local RC.7 tag was not published remotely.
- RC.8: fulfilment-correctness lineage; the local RC.8 tag was not published remotely.
- RC.9: Cart/Checkout Blocks + settings-honesty published baseline.
- 2026-09-15 bootstrap: team control plane, CI, release safeguards, and a narrow PHP 8.1 parsing compatibility repair on `master` (`d2ebc620`). That commit does **not** replace post-RC.9 candidate history.
- 2026-09-15 recovery reconciliation: PR #9 documented the exact recovered refs/evidence on protected `master`; it did **not** merge recovered feature history into `master`.

## Implemented but unreleased after RC.9 (now recovered onto GitHub)
Recovered as exact historical SHAs. **Not merged into `master`. Not RC.10.**

- Combined integrated.2 candidate: `feat/post-rc9-customer-ux` @ `d534d6a24390f39206f697308f0c4bc42919be46`
  - packaged runtime source: `10028a2216619f514dda3ecf7cd1cbb7d50296cc`
  - `10028a` is an ancestor of `d534d6`; the branch tip equals the recorded docs/checksum commit (it had not advanced)
- Unique WPML stream: `feat/post-rc9-wpml` @ `3b5b60d0d92b2edc00496d536774f8ac907cd6d2`
  - unique vs integrated.2: `e85b44d` (runtime) + `3b5b60d` (docs/checksum)
- Provenance recovery branches for original per-item, integrated.1, cart-state, Blocks snapshot, WCFM, and historical RC.6/RC.7/RC.8 checksum tips: see `docs/recovery/POST-RC9-LOCAL-RECOVERY-REPORT.md`

Both integrated.2 and WPML descend from peeled RC.9 `e6bc7fb`, **not** from later `376c089` staff-training merge. That divergence is expected. Do not rebase them onto the bootstrap/recovery-control-plane lineage merely to linearize history.

## integrated.2 historical qualification evidence
Qualified runtime source: `10028a2216619f514dda3ecf7cd1cbb7d50296cc`.
Docs/checksum follow-up: `d534d6a24390f39206f697308f0c4bc42919be46`.
Located ZIP `cetech-woocommerce-delivery-engine-1.0.0-dev.integrated.2.zip` SHA-256 `a16a7840f32c8aa95fde3d4ef25c97c39ec995fe1d4ee6036fbb03b8d1a1a9c9` **MATCH**.
Per-item ZIP SHA-256 `898db75f2baf1236bdaf216189a15c148f500b24189ba3c374e964d729b6e806` **MATCH**.

Recorded package gates included 994 PHPUnit tests, 41 Vitest tests, PHP lint across 428 files, package verification, Classic/Blocks multi-destination checks, pickup and WCFM-denial checks.

Those figures are **historical evidence only**. Issue #2 did not rerun product qualification.

The candidate is NOT RC.10 and is NOT a published release.

## Remaining pre-RC.10 qualification
1. Construct a qualification/integration baseline from current protected `master` plus the exact recovered candidate (later issue; not this recovery).
2. WoodMart current-candidate qualification.
3. WP Rocket + Redis session/cache isolation.
4. Paid two-destination order -> actual two shipments -> Thank You/My Account/email.
5. Deliberate WPML/WCML certification scope decision and licensed-dependency qualification if included.
6. Production rollout/pilot remains separate human-authorized work.

## Deferred / not automatically blocking current stable core
- Stage 15 is not started.
- advanced carrier APIs;
- richer customer shipment timeline/emails where not part of current V1;
- later POD/OTP/QR/GPS/photo/driver workflows unless scope is explicitly reopened.
