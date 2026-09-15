# Post-RC.9 Local Git Recovery Report

**Issue:** `WB-DevWorld/cetech-woocommerce-delivery-engine#2`  
**Date:** 2026-09-15  
**Operator:** WS3 recovery/integration  
**Result:** COMPLETE for preservation, classification, selective publication, and fresh-clone verification  
**Not in this issue:** RC.10, Stage 15, product implementation, FLAIROC/training/production mutation, qualification reruns

---

## Source inventory

Richest (and only independent) Git object store:

| Item | Value |
| --- | --- |
| Filesystem path | `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-woocommerce-delivery-engine` |
| Git top-level | same |
| Git common dir | `.git` |
| Initial HEAD | `720b51399cf82078ae94615f89cbaf52bb1f78de` on `feat/post-rc6-bulk-tools` |
| Remotes before recovery | `origin` = `https://github.com/wbdevworld/cetech-woocommerce-delivery-engine.git` (transfer redirect); `upstream` = `https://github.com/janelove-tech/cetech-woocommerce-delivery-engine.git` |
| Origin after normalize | `https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine.git` |

Linked worktrees of that object store:

| Path | HEAD | Branch |
| --- | --- | --- |
| `...\cetech-woocommerce-delivery-engine` | `720b51399cf82078ae94615f89cbaf52bb1f78de` | `feat/post-rc6-bulk-tools` |
| `...\cetech-de-post-rc6-bulk-r1` | `6b86e248970171d876c812a443d4086eb525d66d` | `integration/post-rc6-bulk-r1` |
| `...\cetech-de-post-rc7-fulfilment-correctness` | `c370f84b39e450dd48cedbad27b4061bf9720255` | `feat/post-rc7-fulfilment-correctness` |
| `...\cetech-de-post-rc9-integrated-candidate` | `d534d6a24390f39206f697308f0c4bc42919be46` | `feat/post-rc9-customer-ux` |
| `...\cetech-de-post-rc9-wpml` | `3b5b60d0d92b2edc00496d536774f8ac907cd6d2` | `feat/post-rc9-wpml` |
| `...\cetech-de-rc6-admin-setup-repair` | `4ebecfb92b843fd9eee2b2bb326673c2ee028d6a` | `fix/post-rc6-admin-setup-defects` |
| `...\cetech-de-rc6-tester-audit-worktree` | `8f37fe826e23406c9035312e279699b65c1e72e4` | detached (peeled `v1.0.0-rc.6`) |

Sibling directories examined that are **QA labs, not Git object stores** (no `.git`):

- `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-local-qa` (packages + evidence; contains `.env` — **not copied into Git**)
- `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-bulk-tools-qual`
- `C:\Users\Jane\Desktop\cetech-de-rc6-adversarial-audit`

POS / other `cetech-*` repositories were not treated as Delivery Engine object stores.

---

## Forensic backups

Evidence directory (outside the tracked tree, not added to Git):

`C:\Users\Jane\Desktop\Learning 2026\Cursor\delivery-engine-recovery-2026-09-15`

| Bundle | Bytes | SHA-256 | `git bundle verify` |
| --- | ---: | --- | --- |
| `05-git-bundles/delivery-engine-before-recovery.bundle` | 17059823 | `8f45be615a8fce4de1c1af0286a3c4b1bb53d2e64f2ce9a20779fef4f91fc949` | OK (40 refs, complete history) |
| `05-git-bundles/delivery-engine-recovery-complete.bundle` | 17074915 | `69d16473718ccd4108473465812d475901819a756f9daf561f50f68cc7fead60` | OK (50 refs, includes `refs/recovery/unreachable/*`) |

`git fsck --full --no-reflogs --unreachable` was run on the richest store **before** fetch. Ten unreachable commits were then pinned under `refs/recovery/unreachable/...` (local/archive only; not published). No `gc` / `prune` / `clean` / `reset --hard` was used.

---

## Recovered candidate (integrated.2)

| Question | Answer |
| --- | --- |
| Does `10028a2216619f514dda3ecf7cd1cbb7d50296cc` exist? | FOUND |
| Does `d534d6a24390f39206f697308f0c4bc42919be46` exist? | FOUND |
| Is `10028a` an ancestor of `d534d6`? | YES |
| `feat/post-rc9-customer-ux` tip | `d534d6a24390f39206f697308f0c4bc42919be46` |
| Tip equals recorded integrated.2 docs commit? | YES (branch had not advanced) |
| Branches containing `d534d6` | `feat/post-rc9-customer-ux` only |
| `10028a` subject | `feat(storefront): simplify delivery customer experience` (freeze as `1.0.0-dev.integrated.2`, schema 5) |
| `d534d6` subject | `docs: record integrated.2 package checksum` (packaged source remains `10028a`; ZIP not rebuilt) |
| Relationship to peeled RC.9 `e6bc7fb` | `e6bc7fb` **is** an ancestor of `d534d6` |
| Relationship to `376c089` / `d2ebc620` | `376c089` is **not** an ancestor of `d534d6` (candidate predates the staff-training merge on `master`). Expected. Do not rebase onto bootstrap `master`. |

Per-item runtime `72cb7ddfcb5ed85765ed167831736ba04a3cdf89` **is** an ancestor of integrated.2. Per-item docs/checksum `093a16460f5948bfd9a1b9fa802df22e46795f76` is **not** (docs-only follow-up left on the per-item branch).

---

## WPML

| Item | Value |
| --- | --- |
| Branch | `feat/post-rc9-wpml` |
| Exact tip | `3b5b60d0d92b2edc00496d536774f8ac907cd6d2` |
| Unique commits vs integrated.2 | `e85b44d feat(wpml): translate customer-facing Delivery Engine copy via WPML as 1.0.0-dev.wpml.1`; `3b5b60d docs: record 1.0.0-dev.wpml.1 owner-QA package checksum` |
| Reachable from integrated.2? | NO (intentionally separate stream) |
| Ancestor RC.9 peeled `e6bc7fb`? | YES |
| Licensed WPML/WCML binaries in the Git tree? | NO (adapter/source + tests + docs only) |
| Published ref | `feat/post-rc9-wpml` @ `3b5b60d` |

---

## Other local branches (classification)

Unique vs current protected `master` `d2ebc620` means the tip is not an ancestor of that commit.

| Local ref | Tip SHA | Unique vs `d2ebc620`? | Reachable from integrated.2? | Reachable from WPML? | Dirty? | Classification | Publish? |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `docs/staff-training-rc2` | `27ebf93af811373d7a0ded34be16c1d185ea7ddc` | already on origin | NO | NO | no | CANONICAL_REMOTE | NO |
| `feat/post-rc6-bulk-tools` | `720b51399cf82078ae94615f89cbaf52bb1f78de` | NO (ancestor of `376c089` and of GitHub `master`) | YES | YES | untracked audit doc | REDUNDANT_POINTER | NO |
| `feat/post-rc7-fulfilment-correctness` | `c370f84b39e450dd48cedbad27b4061bf9720255` | YES (1) | NO | NO | no | PRESERVE_ONLY | YES `recovery/post-rc7-fulfilment-correctness` |
| `feat/post-rc8-integrations` | `376c0896df0d85b159e8713c79aadb6c9b8a3839` | NO | NO | NO | no | REDUNDANT_POINTER (already `origin/feat/post-rc8-integrations`) | NO |
| `feat/post-rc9-blocks-line-snapshot` | `4d0fe4dc39af4be567935eca8c836a804078b7a7` | YES (2) | NO | NO | no | PRESERVE_ONLY (original SHAs; equivalent work cherry-picked into integrated.2) | YES `recovery/post-rc9-blocks-line-snapshot` |
| `feat/post-rc9-cart-state` | `ad16df20692500e758b1d64d16ebf14206025f48` | YES (2) | NO | NO | no | PRESERVE_ONLY (original SHAs; equivalent work cherry-picked into integrated.2) | YES `recovery/post-rc9-cart-state` |
| `feat/post-rc9-customer-ux` | `d534d6a24390f39206f697308f0c4bc42919be46` | YES (12) | YES (is tip) | NO | no | ACTIVE_CANDIDATE | YES `feat/post-rc9-customer-ux` |
| `feat/post-rc9-integrated-candidate` | `51ccd3e42720d66c4db5be9c2a52290042680229` | YES | NO | NO | no | PRESERVE_ONLY (integrated.1 docs checksum; not ancestor of `d534d6`) | YES `recovery/post-rc9-integrated-candidate` |
| `feat/post-rc9-per-item-context` | `093a16460f5948bfd9a1b9fa802df22e46795f76` | YES | NO | NO | no | PRESERVE_ONLY | YES `recovery/post-rc9-per-item-context` |
| `feat/post-rc9-wcfm-isolation` | `31ea8abda4e4a502b121a671031c27fb886973bf` | YES (3) | NO | NO | no | PRESERVE_ONLY (original SHAs; equivalent work in integrated.2 as different commit IDs) | YES `recovery/post-rc9-wcfm-isolation` |
| `feat/post-rc9-wpml` | `3b5b60d0d92b2edc00496d536774f8ac907cd6d2` | YES (2) | NO | YES (is tip) | no | UNIQUE_STREAM | YES `feat/post-rc9-wpml` |
| `feat/site-wide-delivery-defaults` | `5d30616ec90e5116b12f8f1fc5e3d201ca198730` | NO | YES | YES | no | CANONICAL_REMOTE | NO |
| `fix/post-rc6-admin-setup-defects` | `4ebecfb92b843fd9eee2b2bb326673c2ee028d6a` | YES (7) | NO | NO | no | PRESERVE_ONLY | YES `recovery/post-rc6-admin-setup-defects` |
| `integration/post-rc6-bulk-r1` | `6b86e248970171d876c812a443d4086eb525d66d` | YES (1) | NO | NO | no | PRESERVE_ONLY | YES `recovery/post-rc6-bulk-r1` |
| `master` (local) | `376c0896df0d85b159e8713c79aadb6c9b8a3839` | NO vs GitHub `master` (ancestor) | NO | NO | no | CANONICAL_REMOTE pre-bootstrap local tip | NO — **local `master` was not moved** |
| `wip/rc6-adversarial-security-audit` | `a88f28048afcc84456e6933351c71b01a9838c5a` | YES (1) | NO | NO | no | PRESERVE_ONLY (audit artifacts / HTTP evidence) | NO — local/archive only |

`feat/post-rc9-customer-ux` and `feat/post-rc9-wpml` trees were scanned for `*.zip` / WoodMart / WPML plugin binaries / `.env`; none were present.

---

## Tags

| Tag | Present locally | Present on GitHub | Object | Peeled commit | Decision |
| --- | --- | --- | --- | --- | --- |
| `v1.0.0-rc.2` | yes | yes | `ae08491978b6f07fff0b038a4ff0f2586c0e1d26` | `6b0420569500e6f217923306c331a5c9951deb92` | untouched |
| `v1.0.0-rc.3` | yes | **no** | `e395a479e68c5a5eaa631aa36973f4e4155cef1d` | `f91da5dad677410ca05fbcb86ef8fd83596b3871` | PRESERVE_ONLY (bundle) |
| `v1.0.0-rc.4` | yes | yes | `69a167432a2b895c9bae97aecb98d8d0abe1b16e` | `6b70c29b31362d1dad1c41e22fa30fa50e4ad559` | untouched |
| `v1.0.0-rc.5` | yes | yes | `aa04f147138d0b78c5f7df91366765388b487b6e` | `0a98e1f7dd2ac053840c5c34015958af20ba2709` | untouched |
| `v1.0.0-rc.6` | yes | yes | `1d3252199f45371ec4229808155f6017ee3da9ea` | `8f37fe826e23406c9035312e279699b65c1e72e4` | untouched |
| `v1.0.0-rc.7` | yes | **no** | `8cba2e877d949c83fd18a0d67b4831d2012d47cd` | `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6` | PRESERVE_ONLY (bundle) |
| `v1.0.0-rc.8` | yes | **no** | `2c211eb5b8a3a6af23e67b4d254a6c19c4e4b8ae` | `6d166227998d4b0f5047fea91944ff024b389810` | PRESERVE_ONLY (bundle); peeled SHA **matches** prior evidence |
| `v1.0.0-rc.9` | yes | yes | `e6f98d91fc14585bf85a62f9bbee555b0be7b71e` | `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5` | untouched; peeled SHA **matches** prior evidence |

No tags were pushed or moved.

---

## Stashes / detached / untracked

| Item | Status |
| --- | --- |
| `stash@{0}` `54bfc20e147beeb15068efef25f55b7638360f26` | WIP on `feat/post-rc9-per-item-context` (`f6b5c12`) — Blocks line-snapshot related tracked diffs. Preserved in both bundles and `03-diffs-and-untracked/stash-0.patch`. **Not published, not committed.** |
| Detached tester-audit worktree HEAD `8f37fe8` | Identical to peeled `v1.0.0-rc.6`. Durable via the published tag. |
| Untracked `docs/POST-RC6-TESTER-OBSERVATIONS-AUDIT.md` | Found in the main worktree. Copied to the evidence archive. Secret-scan clean. **Not committed** (audit-only; belongs with RC.6 evidence, not Bulk Tools / recovered candidate). |
| Untracked `tests/Unit/Audit/PostRc6TesterObservationsEvidenceTest.php` | Found in the detached RC.6 worktree. Copied to the evidence archive. Secret-scan clean. **Not committed.** |
| Ten previously unreachable stash-like / index commits | Pinned locally as `refs/recovery/unreachable/<shortsha>-...`. Included in bundle 2. **Not published.** |

---

## Artifacts

| Artifact | Found | Calculated SHA-256 | Expected | Result |
| --- | --- | --- | --- | --- |
| `cetech-woocommerce-delivery-engine-1.0.0-dev.integrated.2.zip` | yes (integrated-candidate worktree `dist/` and `cetech-de-local-qa/packages/`) | `a16a7840f32c8aa95fde3d4ef25c97c39ec995fe1d4ee6036fbb03b8d1a1a9c9` | `a16a7840f32c8aa95fde3d4ef25c97c39ec995fe1d4ee6036fbb03b8d1a1a9c9` | MATCH |
| `cetech-woocommerce-delivery-engine-1.0.0-dev.peritem.1.zip` | yes (`cetech-de-local-qa/packages/`) | `898db75f2baf1236bdaf216189a15c148f500b24189ba3c374e964d729b6e806` | `898db75f2baf1236bdaf216189a15c148f500b24189ba3c374e964d729b6e806` | MATCH |
| `cetech-woocommerce-delivery-engine-1.0.0-dev.integrated.1.zip` | yes | `571f738aa263676d64be6d285686a10a5fea2c48245449b55fe2240466a2d247` | (none recorded in the issue prompt) | archived locally |
| `cetech-woocommerce-delivery-engine-1.0.0-dev.wpml.1.zip` | yes | `98d29a132b008e3ef7f382c0f80368b196ac4bcf0f9d9f1c1ee5e283de344444` | matches WPML docs commit message | archived locally |

ZIPs were copied into the local evidence `06-artifacts/` directory. **They were not uploaded to GitHub.** Licensed WoodMart / WCFM / WooCommerce vendor ZIPs in QA labs were not copied into Git.

---

## Publication

Non-fast-forward force was **not** used. `origin/master` remained `d2ebc620762c6acd1b3a143ee120bea906c6d205`. Remote did not already contain conflicting `feat/post-rc9-*` tips.

Pushed refs:

```text
feat/post-rc9-customer-ux                      -> d534d6a24390f39206f697308f0c4bc42919be46
feat/post-rc9-wpml                             -> 3b5b60d0d92b2edc00496d536774f8ac907cd6d2
recovery/post-rc9-per-item-context             -> 093a16460f5948bfd9a1b9fa802df22e46795f76
recovery/post-rc9-integrated-candidate         -> 51ccd3e42720d66c4db5be9c2a52290042680229
recovery/post-rc9-cart-state                   -> ad16df20692500e758b1d64d16ebf14206025f48
recovery/post-rc9-blocks-line-snapshot         -> 4d0fe4dc39af4be567935eca8c836a804078b7a7
recovery/post-rc9-wcfm-isolation               -> 31ea8abda4e4a502b121a671031c27fb886973bf
recovery/post-rc6-admin-setup-defects          -> 4ebecfb92b843fd9eee2b2bb326673c2ee028d6a
recovery/post-rc7-fulfilment-correctness       -> c370f84b39e450dd48cedbad27b4061bf9720255
recovery/post-rc6-bulk-r1                      -> 6b86e248970171d876c812a443d4086eb525d66d
```

---

## Preserved only (intentionally not on GitHub as new refs)

- Local-only tags `v1.0.0-rc.3`, `v1.0.0-rc.7`, `v1.0.0-rc.8`
- `wip/rc6-adversarial-security-audit` @ `a88f280` (audit HTTP evidence / leftover RC.6 adversarial artifacts)
- `refs/recovery/unreachable/*` (stash-like unreachable commits)
- `stash@{0}`
- Untracked RC.6 tester-audit markdown + PHPUnit evidence file
- Local `master` left at `376c089` in the original worktree (GitHub `master` is the bootstrap commit)
- QA-lab `.env` files and licensed third-party ZIPs
- Delivery Engine ZIP artifacts (local evidence archive only)

---

## Missing or contradictory evidence

| Claim | Finding |
| --- | --- |
| Multiple independent Delivery Engine Git clones with unique objects | Not found. One object store + seven worktrees; QA labs have no `.git`. |
| `d2ebc620` present before fetch | MISSING locally until `git fetch origin` (expected). After fetch: MATCH. |
| `feat/post-rc9-integrated-candidate` = integrated.2 | Contradicted: that branch tip is integrated.1 docs `51ccd3e`. Integrated.2 lives on `feat/post-rc9-customer-ux` @ `d534d6a`. |
| Candidate ancestry vs `376c089` | `376c089` is not an ancestor of integrated.2/WPML. Both descend from peeled RC.9 `e6bc7fb`. Not a rewrite; staff-training merge landed on `master` after those branches forked. |
| Direct `git clone` of GitHub over HTTPS without a local reference | Stalled / `curl 56` on this machine. Verification clone used `git clone --dissociate --reference-if-able <local-store> https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine.git`, then `git fetch origin --prune --tags`. Remote URL, `master` SHA, recovered refs, and `git fsck --full` were still taken from that GitHub-origin clone. |

Nothing required to prove integrated.2 or WPML identity was missing.

---

## Fresh clone verification

| Item | Value |
| --- | --- |
| Path | `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-fresh-verify-c-2026-09-15` |
| Remote | `https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine.git` |
| `master` | `d2ebc620762c6acd1b3a143ee120bea906c6d205` |
| `origin/feat/post-rc9-customer-ux` | `d534d6a24390f39206f697308f0c4bc42919be46` |
| `origin/feat/post-rc9-wpml` | `3b5b60d0d92b2edc00496d536774f8ac907cd6d2` |
| Critical objects via `git cat-file` | `10028a`, `d534d6`, `72cb7dd`, `093a164`, `3b5b60d`, `e6bc7fb`, `d2ebc620` all FOUND |
| Published tags in the clone | `v1.0.0-rc.2`, `.4`, `.5`, `.6`, `.9` only (local-only RC.3/7/8 **absent**) |
| `git fsck --full` | exit 0, no reported errors |

---

## Remaining blockers for issue #2

None for the recovery/reconciliation definition of done, once this documentation PR is reviewed and merged to protected `master`.

Still **out of scope** (later issues):

- merging recovered history into `master`;
- RC.10 identity/package;
- Stage 15;
- qualification reruns;
- FLAIROC/training/production work.

---

## Safety record

- No `git reset --hard`, `git clean`, `git gc`, `git prune`, `git worktree prune`, branch/tag/stash/worktree deletion, rebase, amend, force-push, or `git push --mirror`.
- Local `master` not moved. Historical published tags not moved.
- Plugin version and schema not changed.
- No licensed WoodMart/WPML/WCFM binaries added to Git.
- No secrets copied into the public repository (untracked/stash/audit-doc secret scan clean; QA `.env` not copied).
