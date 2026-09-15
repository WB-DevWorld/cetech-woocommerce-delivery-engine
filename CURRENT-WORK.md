# Current Work — Post-RC.9 Local Recovery Reconciled

Status: CONTROL PLANE ACTIVE + LOCAL-ONLY SOURCE RECOVERY RECONCILED

## Canonical published truth
- Canonical organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Canonical published branch: `master`.
- Current protected `master`: `d2ebc620762c6acd1b3a143ee120bea906c6d205` (`[BOOT] Establish Delivery Engine team control plane and CI`, PR #1).
- Pre-bootstrap RC.9-line `master`: `376c0896df0d85b159e8713c79aadb6c9b8a3839`.
- Published version: `1.0.0-rc.9`.
- Schema target: `5`.
- RC.10: DOES NOT EXIST.
- Stage 15: NOT STARTED.
- Published remote tags: `v1.0.0-rc.2`, `v1.0.0-rc.4`, `v1.0.0-rc.5`, `v1.0.0-rc.6`, `v1.0.0-rc.9`.

## Recovered post-RC.9 history (GitHub)
Issue #2 recovered the local-only candidate history into the organization repository **without merging it into `master`**.

| Ref | SHA | Role |
| --- | --- | --- |
| `feat/post-rc9-customer-ux` | `d534d6a24390f39206f697308f0c4bc42919be46` | ACTIVE_CANDIDATE (integrated.2 docs/checksum tip; packaged runtime `10028a2216619f514dda3ecf7cd1cbb7d50296cc`) |
| `feat/post-rc9-wpml` | `3b5b60d0d92b2edc00496d536774f8ac907cd6d2` | UNIQUE_STREAM (two commits not reachable from integrated.2) |
| `recovery/post-rc9-per-item-context` | `093a16460f5948bfd9a1b9fa802df22e46795f76` | PRESERVE_ONLY provenance (per-item docs/checksum; runtime `72cb7dd` is an ancestor of integrated.2) |
| `recovery/post-rc9-integrated-candidate` | `51ccd3e42720d66c4db5be9c2a52290042680229` | PRESERVE_ONLY (integrated.1 docs/checksum) |
| `recovery/post-rc9-cart-state` | `ad16df20692500e758b1d64d16ebf14206025f48` | PRESERVE_ONLY original cart-state SHAs |
| `recovery/post-rc9-blocks-line-snapshot` | `4d0fe4dc39af4be567935eca8c836a804078b7a7` | PRESERVE_ONLY original blocks-snapshot SHAs |
| `recovery/post-rc9-wcfm-isolation` | `31ea8abda4e4a502b121a671031c27fb886973bf` | PRESERVE_ONLY original WCFM SHAs |
| `recovery/post-rc6-admin-setup-defects` | `4ebecfb92b843fd9eee2b2bb326673c2ee028d6a` | PRESERVE_ONLY historical RC.6 admin repair |
| `recovery/post-rc7-fulfilment-correctness` | `c370f84b39e450dd48cedbad27b4061bf9720255` | PRESERVE_ONLY RC.8 checksum docs |
| `recovery/post-rc6-bulk-r1` | `6b86e248970171d876c812a443d4086eb525d66d` | PRESERVE_ONLY RC.7 checksum docs |

Full forensic report: `docs/recovery/POST-RC9-LOCAL-RECOVERY-REPORT.md`.

## Current release-qualification gaps
These remain later work. They were **not** rerun under issue #2:
- current candidate on WoodMart;
- WP Rocket + Redis cross-session isolation;
- one paid multi-destination order through actual shipment creation, Thank You, My Account and customer email;
- WPML/WCML certification decision/authorized test dependencies;
- production rollout/pilot.

## Active milestone
P0 complete for source recovery. Next authorized product step is a later issue that constructs the RC.10 qualification/integration baseline from **current protected `master` plus the exact recovered candidate**. Do not treat recovered branches as already integrated.

## Integration editor
WS3 / `@wbdevworld`.

## Authorized implementation
No new product feature implementation in this recovery issue. No RC.10 promotion. No Stage 15. No FLAIROC/training/production mutation.

## Central leases
- recovered candidate identity: frozen at the SHAs above;
- release identity/tags: frozen;
- schema: frozen at 5;
- canonical `master`: no force-push/rewrite.

## Environment authorization
- Production/FLAIROC/training: NO autonomous mutation.
- Isolated QA lab: only when explicitly assigned after this reconciliation is accepted.
