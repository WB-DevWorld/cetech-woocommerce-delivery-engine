# Desktop Artifact Reconciliation — 2026-09-16

**Task:** Final desktop ZIP/checksum forensic accounting pass  
**Not:** product implementation, RC.10, Stage 15, merge, retag, rebuild, or deletion  
**Operator:** WS3 recovery/integration  
**Result:** `ALL DESKTOP ARTIFACTS ACCOUNTED FOR`  
**Companion hash CSV:** `docs/recovery/DESKTOP-ARTIFACT-HASH-MANIFEST-2026-09-16.csv`  
**Local evidence continuation:** `C:\Users\Jane\Desktop\Learning 2026\Cursor\delivery-engine-recovery-2026-09-15\07-desktop-artifact-reconciliation-2026-09-16\`

This report closes the remaining gap between the 15 September 2026 Git/source recovery (`docs/recovery/POST-RC9-LOCAL-RECOVERY-REPORT.md`) and every CETECH Delivery Engine ZIP / `.sha256` file currently visible on Jane’s Desktop.

---

## 1. Executive summary

Every Delivery Engine ZIP and checksum file on `C:\Users\Jane\Desktop` was inventoried, hashed, correlated to Git/package documentation, classified, and copied (not moved) into the dated recovery archive.

| Item | Count |
| --- | ---: |
| Desktop ZIP files | 8 |
| Desktop `.sha256` files | 10 |
| Unique package identities | 16 |
| Checksum mismatches | **0** |
| Same-name / different-bytes collisions | **0** |
| Genuinely unresolved identities | **0** |
| Unrecovered code streams represented by a Desktop artifact | **0** |

Classification of the **16 unique package identities**:

| Classification | Count | Identities |
| --- | ---: | --- |
| `CANONICAL_LINEAGE` | 6 | `bulk.2`, `fulfilment.4`, `rc.6-qa.2`, `rc.6`, `rc.8`, `rc.9` |
| `RECOVERED_QUALIFICATION_INPUT` | 5 | `blocks-snapshot.1`, `cartstate.1`, `peritem.1`, `wcfm.1`, `integrated.2` |
| `RECOVERED_SEPARATE_STREAM` | 1 | `wpml.1` |
| `SUPERSEDED_PRESERVE` | 3 | `fulfilment.3`, `rc.6-qa.1`, `integrated.1` |
| `FAILED_QA_EVIDENCE` | 1 | `rc.6-r1-qa.1` |
| `UNACCOUNTED_REQUIRES_INVESTIGATION` | 0 | — |

Eight Desktop identities were checksum-only at the Desktop root. Matching ZIPs were found in worktree `dist/` directories. Each located ZIP SHA-256 **matches** the Desktop sidecar **and** the historical Git documentation. That is `CHECKSUM_ONLY` at the Desktop location, not a missing package.

`SUPERSEDED` is not permission to delete. `FAILED_QA_EVIDENCE` is not permission to merge.

Nothing was deleted, rebuilt, retagged, merged, or deployed. PR `#12` (`batch/pre-rc10-qualification`) was not modified. RC.10 does not exist.

---

## 2. Exact machine / path scope

| Item | Value |
| --- | --- |
| Freeze time (UTC) | `2026-09-16T11:24:27.7530215+00:00` |
| Pass 1 fetch | `2026-09-16` after freeze; `origin/master` still `0f9c2f06f9da0e15840fe27c8b28f7d146a5dd8d` |
| Machine | Jane’s Windows desktop |
| Canonical GitHub repo | `WB-DevWorld/cetech-woocommerce-delivery-engine` |
| Canonical branch | `master` |
| Richest local Git object store | `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-woocommerce-delivery-engine` |
| Local HEAD during freeze | `feat/post-rc6-bulk-tools` @ `720b51399cf82078ae94615f89cbaf52bb1f78de` |
| Working tree | one untracked audit doc `docs/POST-RC6-TESTER-OBSERVATIONS-AUDIT.md` (not part of this pass; not committed) |
| `origin/master` | `0f9c2f06f9da0e15840fe27c8b28f7d146a5dd8d` |
| `origin/batch/pre-rc10-qualification` | `be586a454cc9a03395b981ef2b07cce445ef8f10` |
| Published release identity | `1.0.0-rc.9` / schema `5` / tag `v1.0.0-rc.9` peeling to `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5` |
| RC.10 | **does not exist** (no tag, no branch, no ZIP) |
| Stage 15 | **not started** |
| Draft PR `#12` | open, draft, DO NOT MERGE; head `be586a4` |
| September 15 recovery archive | `C:\Users\Jane\Desktop\Learning 2026\Cursor\delivery-engine-recovery-2026-09-15` |
| This pass archive | `...\delivery-engine-recovery-2026-09-15\07-desktop-artifact-reconciliation-2026-09-16\` |
| Docs worktree for this report | `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-desktop-artifact-recon` on `ws3/reconcile-desktop-artifacts` from `origin/master` |

Inventory roots searched (Delivery Engine specific; `node_modules` / `vendor` skipped):

- `C:\Users\Jane\Desktop` (root files + one-level `cetech*` names)
- `C:\Users\Jane\Desktop\cetech-de-rc6-adversarial-audit`
- `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-local-qa\packages`
- `C:\Users\Jane\Desktop\Learning 2026\Cursor\delivery-engine-recovery-2026-09-15\06-artifacts`
- Worktree `dist/` folders of the richest store and linked worktrees

Unrelated personal directories were not walked.

---

## 3. Safety actions

Performed:

- Read-only Git fetch (`git fetch origin --prune --tags`); no merge, rebase, pull, reset, gc, prune, or clean
- SHA-256 via `Get-FileHash -Algorithm SHA256`
- ZIP header inspection via .NET `ZipFile.OpenRead` (no install, no rebuild)
- Non-destructive copies into `07-desktop-artifact-reconciliation-2026-09-16\`
- Documentation-only Git branch from `origin/master`

Not performed (hard rules honoured):

- No Desktop ZIP or `.sha256` deleted or moved
- No branches/tags/stashes/worktrees deleted
- No `git clean` / `git gc` / `git prune` / `git reset --hard`
- No force-push, history rewrite, or tag move
- No merge of recovered branches or PR `#12`
- No RC.10, version change, schema change, or historical package rebuild
- No FLAIROC / training / production / POS / WooCommerce live mutation
- No `.env`, credentials, or licensed third-party plugin binaries copied into Git
- No ZIP binaries added to Git

---

## 4. Complete Desktop inventory

Freeze listing of `C:\Users\Jane\Desktop` files matching `cetech-woocommerce-delivery-engine*`:

| Filename | Ext | Bytes | Modified (UTC) | Sibling on Desktop |
| --- | --- | ---: | --- | --- |
| `cetech-woocommerce-delivery-engine-1.0.0-dev.blocks-snapshot.1.zip` | `.zip` | 1385101 | 2026-09-02T15:54:51.6108340Z | ZIP only |
| `cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.2.zip.sha256` | `.sha256` | 121 | 2026-08-21T18:37:54.7343074Z | checksum only |
| `cetech-woocommerce-delivery-engine-1.0.0-dev.cartstate.1.zip` | `.zip` | 1401258 | 2026-09-01T18:36:10.7181991Z | ZIP only |
| `cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.3.zip.sha256` | `.sha256` | 127 | 2026-08-31T12:14:31.0591519Z | checksum only |
| `cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.4.zip.sha256` | `.sha256` | 127 | 2026-08-31T12:39:13.7885103Z | checksum only |
| `cetech-woocommerce-delivery-engine-1.0.0-dev.integrated.1.zip` | `.zip` | 1475971 | 2026-09-03T11:27:07.3924288Z | ZIP only |
| `cetech-woocommerce-delivery-engine-1.0.0-dev.integrated.2.zip` | `.zip` | 1484938 | 2026-09-04T12:03:07.1830652Z | ZIP only |
| `cetech-woocommerce-delivery-engine-1.0.0-dev.peritem.1.zip` | `.zip` | 1467494 | 2026-09-02T23:28:49.4890635Z | ZIP + sidecar |
| `cetech-woocommerce-delivery-engine-1.0.0-dev.peritem.1.zip.sha256` | `.sha256` | 124 | 2026-09-02T23:28:49.5798615Z | ZIP + sidecar |
| `cetech-woocommerce-delivery-engine-1.0.0-dev.wcfm.1.zip` | `.zip` | 1387575 | 2026-09-01T19:23:28.7798841Z | ZIP only |
| `cetech-woocommerce-delivery-engine-1.0.0-dev.wpml.1.zip` | `.zip` | 1396670 | 2026-09-01T20:05:00.2693781Z | ZIP + sidecar |
| `cetech-woocommerce-delivery-engine-1.0.0-dev.wpml.1.zip.sha256` | `.sha256` | 121 | 2026-09-01T20:05:00.4921013Z | ZIP + sidecar |
| `cetech-woocommerce-delivery-engine-1.0.0-rc.6-qa.1.zip.sha256` | `.sha256` | 120 | 2026-08-20T18:45:45.1427833Z | checksum only |
| `cetech-woocommerce-delivery-engine-1.0.0-rc.6-qa.2.zip.sha256` | `.sha256` | 120 | 2026-08-20T23:06:26.0101315Z | checksum only |
| `cetech-woocommerce-delivery-engine-1.0.0-rc.6-r1-qa.1.zip.sha256` | `.sha256` | 123 | 2026-08-22T14:10:42.1590184Z | checksum only |
| `cetech-woocommerce-delivery-engine-1.0.0-rc.6.zip.sha256` | `.sha256` | 115 | 2026-08-20T23:41:46.0032179Z | checksum only |
| `cetech-woocommerce-delivery-engine-1.0.0-rc.8.zip.sha256` | `.sha256` | 115 | 2026-08-31T14:07:24.5611800Z | checksum only |
| `cetech-woocommerce-delivery-engine-1.0.0-rc.9.zip` | `.zip` | 1380751 | 2026-09-01T11:34:22.4691760Z | ZIP only |

Exact sidecar file contents (claimed ZIP hash + filename):

```text
a3150ebaa799c5af151f0c2a981e36905b6830e0f08ec468cca66a88fb7d5e2f  cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.2.zip
6b9337bd9a8f5ffb57dde8fa4ea8bb5e7406280df52609fdb49c4845d0f30285  cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.3.zip
f31401636a7c3cadf68636bbdc9a94224782eb3fbf408696880da9d11ac67127  cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.4.zip
898db75f2baf1236bdaf216189a15c148f500b24189ba3c374e964d729b6e806  cetech-woocommerce-delivery-engine-1.0.0-dev.peritem.1.zip
98d29a132b008e3ef7f382c0f80368b196ac4bcf0f9d9f1c1ee5e283de344444  cetech-woocommerce-delivery-engine-1.0.0-dev.wpml.1.zip
0d4adbef50462d798a4ff9bf802643bed92a35cdd332ceee13a985dbda2a689d  cetech-woocommerce-delivery-engine-1.0.0-rc.6.zip
d5dbc392bf6e170e411ac19ce9bb4a14e6ef5458d55583ed56b4e07fe9e2d3ff  cetech-woocommerce-delivery-engine-1.0.0-rc.6-qa.1.zip
e4a904fd6660e5d8f88b5ce03504f7c6ab470e04802361908e47be3b1b794313  cetech-woocommerce-delivery-engine-1.0.0-rc.6-qa.2.zip
6eb0935c1046d4bd65ea017965970b7c0e4784a55d2db2758eab2be3841b5029  cetech-woocommerce-delivery-engine-1.0.0-rc.6-r1-qa.1.zip
70ae635e71741663d5084e3cccd0d2246871d7c485efb919310267d7c9d46b03  cetech-woocommerce-delivery-engine-1.0.0-rc.8.zip
```

No other Desktop-root Delivery Engine ZIP/checksum names were found. The only other Desktop `cetech*` entry is the adversarial QA directory (section 11).

---

## 5. Complete hash table (Desktop ZIPs independently recalculated)

| Artifact | Bytes | Calculated SHA-256 | Sibling / recorded SHA | Match |
| --- | ---: | --- | --- | --- |
| `blocks-snapshot.1.zip` | 1385101 | `092f3144e2a4b4df474c91513b6a282af08ccc817a5fa007f1f94d5d5229c401` | Git `4d0fe4d` / `docs/POST-RC9-BLOCKS-LINE-SNAPSHOT-QA.md` | MATCH |
| `cartstate.1.zip` | 1401258 | `5c72a87bdba7f77d2d2b66f7812f12fc448a2f84dc51748ab6c266d6a3175ecc` | Git `ad16df2` | MATCH |
| `integrated.1.zip` | 1475971 | `571f738aa263676d64be6d285686a10a5fea2c48245449b55fe2240466a2d247` | Sep 15 recovery + `51ccd3e` bytes | MATCH |
| `integrated.2.zip` | 1484938 | `a16a7840f32c8aa95fde3d4ef25c97c39ec995fe1d4ee6036fbb03b8d1a1a9c9` | Git `d534d6a` / Sep 15 recovery | MATCH |
| `peritem.1.zip` | 1467494 | `898db75f2baf1236bdaf216189a15c148f500b24189ba3c374e964d729b6e806` | Desktop sidecar + Git `093a164` | MATCH |
| `wcfm.1.zip` | 1387575 | `fdf9f030e777c1e64c1a0a18bf649788325b61e03adae8aa19b85ed96c948c0d` | Git `31ea8ab` | MATCH |
| `wpml.1.zip` | 1396670 | `98d29a132b008e3ef7f382c0f80368b196ac4bcf0f9d9f1c1ee5e283de344444` | Desktop sidecar + Git `3b5b60d` | MATCH |
| `rc.9.zip` | 1380751 | `08862b8c048b92dd0ff42ded7eff29c3a5e42a42c984ac43e0a603cef50d96fd` | Git `0f02c36` / `docs/RC9-FINALIZATION.md` | MATCH |

Located ZIPs for Desktop checksum-only identities (not present as ZIP on Desktop; hashes of the physical dist copies):

| Artifact | Located bytes | Calculated SHA-256 | Desktop sidecar claim | Match |
| --- | ---: | --- | --- | --- |
| `bulk.2.zip` | 1159232 | `a3150ebaa799c5af151f0c2a981e36905b6830e0f08ec468cca66a88fb7d5e2f` | same | MATCH |
| `fulfilment.3.zip` | 1303042 | `6b9337bd9a8f5ffb57dde8fa4ea8bb5e7406280df52609fdb49c4845d0f30285` | same | MATCH |
| `fulfilment.4.zip` | 1303884 | `f31401636a7c3cadf68636bbdc9a94224782eb3fbf408696880da9d11ac67127` | same | MATCH |
| `rc.6-qa.1.zip` | 1049447 | `d5dbc392bf6e170e411ac19ce9bb4a14e6ef5458d55583ed56b4e07fe9e2d3ff` | same | MATCH |
| `rc.6-qa.2.zip` | 1056340 | `e4a904fd6660e5d8f88b5ce03504f7c6ab470e04802361908e47be3b1b794313` | same | MATCH |
| `rc.6.zip` | 1059918 | `0d4adbef50462d798a4ff9bf802643bed92a35cdd332ceee13a985dbda2a689d` | same | MATCH |
| `rc.6-r1-qa.1.zip` | 1081009 | `6eb0935c1046d4bd65ea017965970b7c0e4784a55d2db2758eab2be3841b5029` | same | MATCH |
| `rc.8.zip` | 1307532 | `70ae635e71741663d5084e3cccd0d2246871d7c485efb919310267d7c9d46b03` | same | MATCH |

ZIP plugin-header inspection (`CETECH_DE_VERSION` inside the archive; ZIP not modified):

| Package | ZIP entries | Declared version |
| --- | ---: | --- |
| `blocks-snapshot.1` | 542 | `1.0.0-dev.blocks-snapshot.1` |
| `cartstate.1` | 550 | `1.0.0-dev.cartstate.1` |
| `integrated.1` | 578 | `1.0.0-dev.integrated.1` |
| `integrated.2` | 581 | `1.0.0-dev.integrated.2` |
| `peritem.1` | 574 | `1.0.0-dev.peritem.1` |
| `wcfm.1` | 544 | `1.0.0-dev.wcfm.1` |
| `wpml.1` | 550 | `1.0.0-dev.wpml.1` |
| `rc.9` | 541 | `1.0.0-rc.9` |
| `bulk.2` | 490 | `1.0.0-dev.bulk.2` |
| `fulfilment.3` | 518 | `1.0.0-dev.fulfilment.3` |
| `fulfilment.4` | 518 | `1.0.0-dev.fulfilment.4` |
| `rc.6-qa.1` | 433 | `1.0.0-rc.6-qa.1` |
| `rc.6-qa.2` | 438 | `1.0.0-rc.6-qa.2` |
| `rc.6` | 439 | `1.0.0-rc.6` |
| `rc.6-r1-qa.1` | 440 | `1.0.0-rc.6-r1-qa.1` |
| `rc.8` | 519 | `1.0.0-rc.8` |

---

## 6. Git / package provenance

Original post-RC.9 packaged SHAs are **not** ancestors of `origin/batch/pre-rc10-qualification` because WS3 cherry-picked them (`-x`) onto protected `master`, producing new commit IDs. Equivalent runtime **is** present on the qualification branch. That is expected and is not a recovery gap.

| Identity | Source SHA | Docs/checksum SHA | QA state | Git relationship | Later lineage |
| --- | --- | --- | --- | --- | --- |
| `1.0.0-dev.blocks-snapshot.1` | `54c9894f492906a24d30c939f831f4538d6b0255` | `4d0fe4dc39af4be567935eca8c836a804078b7a7` | LOCAL QA recorded; not FLAIROC | `recovery/post-rc9-blocks-line-snapshot`; cherry-pick `b31ecc2` on qual | → peritem → integrated.2 → `1.0.0-dev.qual.1` |
| `1.0.0-dev.cartstate.1` | `39f61c1bc79e6248f1e64cdb945f62edf61b357b` | `ad16df20692500e758b1d64d16ebf14206025f48` | Packaged; Cursor did not run owner physical QA | `recovery/post-rc9-cart-state`; cherry-pick `ed6b68a` on qual | → integrated.2 → qual.1 |
| `1.0.0-dev.peritem.1` | `72cb7ddfcb5ed85765ed167831736ba04a3cdf89` | `093a16460f5948bfd9a1b9fa802df22e46795f76` | Classic+Blocks CLOSED PASS (lab) | `recovery/post-rc9-per-item-context`; ancestor of integrated.2 | → integrated.2 → qual.1 |
| `1.0.0-dev.wcfm.1` | `b3333bfb59a7b817aa784a79b640fab79d1179ac` | `31ea8abda4e4a502b121a671031c27fb886973bf` | Packaged; Cursor did not run owner physical QA | `recovery/post-rc9-wcfm-isolation`; later WCFM commits in integrated.2 / qual | → integrated.2 → qual.1 |
| `1.0.0-dev.integrated.1` | `ac2bc94056ebc73f1c6f8ab4a0434f4e68ce7300` | `51ccd3e42720d66c4db5be9c2a52290042680229` | Local combined QA PASS; historical candidate | `recovery/post-rc9-integrated-candidate`; **not** ancestor of integrated.2 | superseded by integrated.2 |
| `1.0.0-dev.integrated.2` | `10028a2216619f514dda3ecf7cd1cbb7d50296cc` | `d534d6a24390f39206f697308f0c4bc42919be46` | Local combined QA; frozen immutable ZIP | GitHub `feat/post-rc9-customer-ux`; cherry-pick `38646a4` on qual | → `1.0.0-dev.qual.1` (not on `master`) |
| `1.0.0-dev.wpml.1` | `e85b44d256852666a9c46b673c7903c0f56388ce` | `3b5b60d0d92b2edc00496d536774f8ac907cd6d2` | Packaged overlay; not in core qual baseline | GitHub `feat/post-rc9-wpml` | separate stream; **not** merged |
| `1.0.0-dev.bulk.2` | `8c0d872fa41d16f6a3eaccdbd2b87a0fcfa2ba53` | `7fad1b9` | Automated qual PASS; untagged owner-QA package | ancestor of `origin/master` | bulk.3–9 → RC.7 → RC.8 → RC.9 |
| `1.0.0-dev.fulfilment.3` | `6a2091c28b7d32bae5e857dfb4ef4032dae5922b` | `9e929d1` | Replaced after mixed-cart destination-copy defect | ancestor of `origin/master` | → fulfilment.4 → RC.8 → RC.9 |
| `1.0.0-dev.fulfilment.4` | `844544ad4020e2c17616145de31a6d6b67bb5128` | `af5cb25` | OWNER PHYSICAL PASS (3 scenarios) | ancestor of `origin/master` | → RC.8 → RC.9 |
| `1.0.0-rc.6-qa.1` | `116f67d88c108fe2ce51bf1cbde7af405fc6445d` | `d30a088` | Shipping-method registry package; not the RC.6 promotion baseline | ancestor of `origin/master` | → qa.2 → RC.6 → … → RC.9 |
| `1.0.0-rc.6-qa.2` | `132afcb746ab9aeaa65d489e7f60d4ee0cde2887` | `cfcfa32` | OWNER PHYSICAL PASS 2026-08-20 | ancestor of `origin/master` | → RC.6 → … → RC.9 |
| `1.0.0-rc.6` | `7e52525cc126f0c4c1841c9bf4b3d7ea9b0bb03f` | tag `v1.0.0-rc.6` peels to checksum commit `8f37fe826e23406c9035312e279699b65c1e72e4` | OWNER-accepted final RC.6 | GitHub tag `v1.0.0-rc.6` | → RC.7 → RC.8 → RC.9 |
| `1.0.0-rc.6-r1-qa.1` | `41ca3e0afd246636837a2b82e0ac23775e6f2a84` | `70e7e7d` | **OWNER PHYSICAL FAIL** | `recovery/post-rc6-admin-setup-defects`; source **not** ancestor of `master` | repaired as r1-qa.2 then Bulk/RC.7 stream |
| `1.0.0-rc.8` | `6d166227998d4b0f5047fea91944ff024b389810` | `c370f84` | Identity promotion of accepted fulfilment.4 | source **is** on `master`; tag `v1.0.0-rc.8` **local only** (intentionally unpublished) | → RC.9 |
| `1.0.0-rc.9` | `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5` | `0f02c36` | Current published identity | GitHub tag `v1.0.0-rc.9` | current canonical release |

Qualification cherry-pick map (already documented in INTEG-01; reconfirmed by `git log` on `origin/batch/pre-rc10-qualification`):

1. `f6b5c12` → `ed6b68a` cartstate  
2. `729f4e8` → `b31ecc2` blocks snapshot  
3. `78d0808` → `d1b597c` per-item foundation  
4. `72b979c` → `4b3770b` Classic per-item UX  
5. `2ae5a95` → `918dcab` Classic multi-destination  
6. `7cd880b` → `7cd3d3e` Blocks per-item UX  
7. `72cb7dd` → `839c744` Blocks DOM/pickup hardening  
8. `47c090b` → `5919fb4` WCFM isolation  
9. `ec31d6d` → `e0c9173` packaged-source capability-matrix  
10. `ac2bc94` → `458e504` WCFM restricted-vendor hardening  
11. `10028a2` → `38646a4` integrated.2 storefront cleanup  

Then identity `7da43bf` (`1.0.0-dev.qual.1`), packager `c0000ab`, docs `8745233`, CI skip `be586a4`.

---

## 7. Duplicate analysis

Classification is by **bytes**, not filename.

### BYTE_IDENTICAL_DUPLICATE (Desktop ZIP vs other local copies)

| Identity | SHA-256 | Also found (same bytes) |
| --- | --- | --- |
| `blocks-snapshot.1` | `092f3144…` | `cetech-de-local-qa\packages\` |
| `cartstate.1` | `5c72a87b…` | `cetech-de-local-qa\packages\` |
| `integrated.1` | `571f738a…` | local-qa; `06-artifacts`; integrated-candidate `dist/` |
| `integrated.2` | `a16a7840…` | local-qa; `06-artifacts`; integrated-candidate `dist/` |
| `peritem.1` | `898db75f…` | local-qa; `06-artifacts` |
| `wcfm.1` | `fdf9f030…` | `cetech-de-local-qa\packages\` |
| `wpml.1` | `98d29a13…` | local-qa; `06-artifacts`; WPML worktree `dist/` |
| `rc.9` | `08862b8c…` | `cetech-de-local-qa\packages\` |

Desktop checksum-only ZIPs located in `dist/` are **unique physical copies relative to Desktop** (ZIP was never on Desktop) and **byte-identical to the sidecar claim**. After this pass they are also copied under `07-…\located-zips-for-desktop-checksum-only\`.

### SAME_NAME_DIFFERENT_BYTES

**None found** for any Desktop identity.

### CHECKSUM_ONLY (Desktop location)

Eight Desktop files. Matching ZIP found in every case (section 5).

### UNIQUE_PHYSICAL_COPY

The Desktop originals themselves. Copies in `07-desktop-artifact-reconciliation-2026-09-16\desktop-originals\` are verified byte-identical archives of those originals.

---

## 8. QA acceptance / failure interpretation

### RC.6 R1 QA.1 — do not promote

`docs/POST-RC6-ADMIN-SETUP-REPAIR-R1-QA1.md` on `origin/recovery/post-rc6-admin-setup-defects`:

- Owner physical QA on training.cetechbpa.com: **FAIL**
- ZIP is an **immutable FAILED** artifact
- Source `41ca3e0afd246636837a2b82e0ac23775e6f2a84`
- SHA-256 `6eb0935c1046d4bd65ea017965970b7c0e4784a55d2db2758eab2be3841b5029` — independently reconfirmed on the dist ZIP

Documented defects included nested/implicit entity-form ownership, draft-restored blank numeric values causing `TypeError` in `AdminFormHelper::number_field`, a WordPress critical error in Delivery Option Advanced details, and unverified responsive fulfilment cards.

Repairs were packaged as `1.0.0-rc.6-r1-qa.2` (not present on Desktop). Accepted R1 request-path repairs then entered the Bulk combined stream and were frozen as RC.7. **Do not merge or reinstall r1-qa.1** merely because the checksum still sits on the Desktop.

Classification: `FAILED_QA_EVIDENCE`.

### Other QA notes

| Identity | Interpretation |
| --- | --- |
| `rc.6-qa.1` | Historical registry-repair package. Not recorded as owner FAIL. Superseded by qa.2 which became RC.6. `SUPERSEDED_PRESERVE`. |
| `rc.6-qa.2` | Owner PASS 2026-08-20. Promoted to tagged RC.6. `CANONICAL_LINEAGE`. |
| `fulfilment.3` | Replaced after mixed-cart destination-copy defect. `SUPERSEDED_PRESERVE`. |
| `fulfilment.4` | Owner PASS all three scenarios. Promoted to RC.8. `CANONICAL_LINEAGE`. |
| `bulk.2` | Early untagged Bulk owner-QA package. Later bulk.3–9 / RC.7 accepted. Runtime lineage is on `master`. `CANONICAL_LINEAGE`. |
| `integrated.1` | Local combined QA PASS, then superseded by integrated.2. Preserve-only. |
| post-RC.9 cartstate / blocks / peritem / wcfm / integrated.2 | Recovered and replayed into qualification; **not** owner-promoted to `master`. Cursor did not treat packaging as FLAIROC install. |

---

## 9. Current canonical / recovered / qualification relationship

| Surface | SHA | Meaning |
| --- | --- | --- |
| Canonical `origin/master` | `0f9c2f06f9da0e15840fe27c8b28f7d146a5dd8d` | Protected published tree. Runtime identity remains `1.0.0-rc.9` / schema `5`. Includes RC.6→RC.9 accepted lineage (Bulk, fulfilment.4, Blocks.4 identity promotion, staff-training/control-plane docs). Does **not** include post-RC.9 integrated.2 runtime. |
| Qualification `origin/batch/pre-rc10-qualification` | `be586a454cc9a03395b981ef2b07cce445ef8f10` | Neutral branch from `master` + 11-commit integrated.2 replay + `1.0.0-dev.qual.1`. Draft PR `#12`. **DO NOT MERGE.** WPML not included. No RC.10 tag. |
| `feat/post-rc9-customer-ux` | `d534d6a24390f39206f697308f0c4bc42919be46` | Historical integrated.2 docs/checksum tip. Immutable evidence. |
| `feat/post-rc9-wpml` | `3b5b60d0d92b2edc00496d536774f8ac907cd6d2` | Separate WPML overlay. Not in core qualification baseline. |
| Local-only tags | `v1.0.0-rc.3`, `v1.0.0-rc.7`, `v1.0.0-rc.8` | Preserve-only; not on GitHub. RC.7/RC.8 **source** is already in canonical history. |

Already in canonical `master`: RC.6 registry/region work, Bulk Tools through RC.7, fulfilment.4/RC.8, Blocks.4/RC.9.

Only in the qualification branch (plus recovered feature/recovery refs): cartstate, blocks line snapshot, per-item, WCFM isolation, integrated.2 storefront cleanup — as `1.0.0-dev.qual.1`.

Intentionally separate: WPML.

Archive-only: Desktop ZIPs, failed r1-qa.1 ZIP, adversarial QA lab, unpublished local tags, `wip/rc6-adversarial-security-audit`, `refs/recovery/unreachable/*`.

RC.10: **does not exist**. Nothing was merged or released in this pass.

---

## 10. Archive-copy manifest

Path:

`C:\Users\Jane\Desktop\Learning 2026\Cursor\delivery-engine-recovery-2026-09-15\07-desktop-artifact-reconciliation-2026-09-16\`

| Subdir | Contents |
| --- | --- |
| `desktop-originals\` | 18 files copied from Desktop (8 ZIP + 10 `.sha256`) |
| `located-zips-for-desktop-checksum-only\` | 8 ZIPs found in worktree `dist/` for Desktop checksum-only identities |
| `COPY-MANIFEST.csv` | source, dest, bytes, SHA-256 before/after, identical flag |
| `HASH-MANIFEST.txt` | human-readable copy of the same rows |

All 26 copy rows reported `identical=True`. Desktop originals were **not moved**.

Previously present in `06-artifacts\` (15 September): `integrated.1`, `integrated.2`, `peritem.1`, `wpml.1`. Newly archived as Desktop freeze / located ZIP in `07-`: `blocks-snapshot.1`, `cartstate.1`, `wcfm.1`, `rc.9.zip`, and all eight checksum-only ZIPs.

Not copied (correctly excluded): `.env`, credentials, `storefront.zip`, `woocommerce.zip`, licensed vendor ZIPs, adversarial HTTP evidence.

---

## 11. Adversarial-audit-folder findings

Path: `C:\Users\Jane\Desktop\cetech-de-rc6-adversarial-audit`

| Question | Finding |
| --- | --- |
| Independent Git object store? | **No.** No `.git`. |
| Delivery Engine ZIP / `.sha256`? | **None.** |
| Classification | QA lab, matching the 15 September recovery report |
| Secret-looking filenames | No `.env` / `.pem` / `id_rsa` / `wp-config` names observed in a filename scan |
| Licensed / third-party binaries present | `storefront.zip`, `woocommerce.zip`, `vendor-zips\` copies — **not** copied into Git or into `07-` |
| Historical Git ref | `wip/rc6-adversarial-security-audit` remains local/archive-only |

Do not publish HTTP evidence from this folder.

---

## 12. Genuine gaps

None for Desktop Delivery Engine ZIP/checksum artifacts.

Explicit non-gaps:

- Desktop checksum-only files are **not** missing packages; ZIPs exist in `dist/` and now in `07-`.
- Original post-RC.9 SHAs not being ancestors of the qualification branch is **cherry-pick provenance**, not a lost stream.
- Unpublished local tags `v1.0.0-rc.3` / `rc.7` / `rc.8` are intentional preserve-only; RC.8/RC.9 runtime is still accounted for.
- WPML is accounted for as a separate stream, not a recovery failure.

Items **outside this Desktop inventory** (not claimed as Desktop artifacts, not searched as a full historical `dist/` census): `bulk.3`–`bulk.9`, `fulfilment.1`–`fulfilment.2`, `rc.6-r1-qa.2`, `rc.7.zip`, `blocks.1`–`blocks.4`, `qual.1.zip`. Those remain in worktree `dist/` / qualification dist as previously built packages. This pass scoped Jane’s Desktop plus files needed to resolve Desktop checksum-only identities.

---

## 13. Master artifact table

Action values never include `DELETE`.

| Artifact | Full Path | Bytes | Calculated SHA-256 | Sibling/Recorded SHA | Match? | Source SHA | Docs/Checksum SHA | QA State | Git/Branch/Tag Relationship | Later/Superseding Lineage | Classification | GitHub? | Recovery Archive? | Action |
| --- | --- | ---: | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `blocks-snapshot.1.zip` | `C:\Users\Jane\Desktop\…blocks-snapshot.1.zip` | 1385101 | `092f3144…5229c401` | `092f3144…` (`4d0fe4d`) | MATCH | `54c9894` | `4d0fe4d` | LOCAL QA | `recovery/post-rc9-blocks-line-snapshot`; qual `b31ecc2` | → peritem → integrated.2 → qual.1 | `RECOVERED_QUALIFICATION_INPUT` | recovery ref yes | `07/` + local-qa | KEEP — RECOVERED CANDIDATE EVIDENCE |
| `cartstate.1.zip` | Desktop | 1401258 | `5c72a87b…a3175ecc` | `5c72a87b…` (`ad16df2`) | MATCH | `39f61c1` | `ad16df2` | NOT PHYSICALLY TESTED BY CURSOR | `recovery/post-rc9-cart-state`; qual `ed6b68a` | → integrated.2 → qual.1 | `RECOVERED_QUALIFICATION_INPUT` | recovery ref yes | `07/` + local-qa | KEEP — RECOVERED CANDIDATE EVIDENCE |
| `integrated.1.zip` | Desktop | 1475971 | `571f738a…46a2d247` | Sep 15 calc + `51ccd3e` bytes | MATCH | `ac2bc94` | `51ccd3e` | LOCAL COMBINED QA PASS | `recovery/post-rc9-integrated-candidate` | superseded by integrated.2 | `SUPERSEDED_PRESERVE` | recovery ref yes | `06/` + `07/` | KEEP — CANONICAL HISTORICAL EVIDENCE |
| `integrated.2.zip` | Desktop | 1484938 | `a16a7840…d1a1a9c9` | `a16a7840…` (`d534d6a`) | MATCH | `10028a2` | `d534d6a` | LOCAL COMBINED; frozen ZIP | `feat/post-rc9-customer-ux`; qual `38646a4` | → qual.1 (not master) | `RECOVERED_QUALIFICATION_INPUT` | yes | `06/` + `07/` | KEEP — RECOVERED CANDIDATE EVIDENCE |
| `peritem.1.zip` | Desktop | 1467494 | `898db75f…29b6e806` | sidecar + `093a164` | MATCH | `72cb7dd` | `093a164` | CLOSED PASS (lab) | `recovery/post-rc9-per-item-context`; ancestor of integrated.2 | → integrated.2 → qual.1 | `RECOVERED_QUALIFICATION_INPUT` | recovery ref yes | `06/` + `07/` | KEEP — RECOVERED CANDIDATE EVIDENCE |
| `peritem.1.zip.sha256` | Desktop | 124 | sidecar file `77e98951…818ffcef` | claims `898db75f…` | MATCH vs ZIP | `72cb7dd` | `093a164` | sidecar | same as ZIP | same | `RECOVERED_QUALIFICATION_INPUT` | n/a | `07/` | KEEP — RECOVERED CANDIDATE EVIDENCE |
| `wcfm.1.zip` | Desktop | 1387575 | `fdf9f030…6c948c0d` | `fdf9f030…` (`31ea8ab`) | MATCH | `b3333bf` | `31ea8ab` | NOT PHYSICALLY TESTED BY CURSOR | `recovery/post-rc9-wcfm-isolation`; later WCFM in integrated.2 | → integrated.2 → qual.1 | `RECOVERED_QUALIFICATION_INPUT` | recovery ref yes | `07/` + local-qa | KEEP — RECOVERED CANDIDATE EVIDENCE |
| `wpml.1.zip` | Desktop | 1396670 | `98d29a13…de344444` | sidecar + `3b5b60d` | MATCH | `e85b44d` | `3b5b60d` | packaged overlay | `feat/post-rc9-wpml` | separate; not in qual | `RECOVERED_SEPARATE_STREAM` | yes | `06/` + `07/` | KEEP — SEPARATE WPML STREAM |
| `wpml.1.zip.sha256` | Desktop | 121 | sidecar file `079a1557…e7ede875` | claims `98d29a13…` | MATCH vs ZIP | `e85b44d` | `3b5b60d` | sidecar | same as ZIP | same | `RECOVERED_SEPARATE_STREAM` | n/a | `07/` | KEEP — SEPARATE WPML STREAM |
| `rc.9.zip` | Desktop | 1380751 | `08862b8c…cef50d96fd` | `0f02c36` / RC9-FINALIZATION | MATCH | `e6bc7fb` | `0f02c36` | published | tag `v1.0.0-rc.9` on GitHub | current published | `CANONICAL_LINEAGE` | yes | `07/` + local-qa | KEEP — CANONICAL HISTORICAL EVIDENCE |
| `bulk.2.zip.sha256` | Desktop | 121 | checksum file `15235026…39bdc8c9` | claims `a3150eba…` | MATCH located ZIP | `8c0d872` | `7fad1b9` | automated PASS | ancestor of master | → bulk.9 → RC.7 → RC.9 | `CANONICAL_LINEAGE` + Desktop `CHECKSUM_ONLY` | source yes | `07/` + dist ZIP | KEEP — CANONICAL HISTORICAL EVIDENCE |
| `fulfilment.3.zip.sha256` | Desktop | 127 | checksum file `119c9298…e997bcd` | claims `6b9337bd…` | MATCH located ZIP | `6a2091c` | `9e929d1` | HISTORICAL / replaced | ancestor of master | → fulfilment.4 → RC.8 | `SUPERSEDED_PRESERVE` + Desktop `CHECKSUM_ONLY` | source yes | `07/` + dist ZIP | KEEP — CANONICAL HISTORICAL EVIDENCE |
| `fulfilment.4.zip.sha256` | Desktop | 127 | checksum file `8f671404…ddb0d05` | claims `f3140163…` | MATCH located ZIP | `844544a` | `af5cb25` | OWNER PASS | ancestor of master | → RC.8 → RC.9 | `CANONICAL_LINEAGE` + Desktop `CHECKSUM_ONLY` | source yes | `07/` + dist ZIP | KEEP — CANONICAL HISTORICAL EVIDENCE |
| `rc.6-qa.1.zip.sha256` | Desktop | 120 | checksum file `dd07414e…85df8079` | claims `d5dbc392…` | MATCH located ZIP | `116f67d` | `d30a088` | HISTORICAL | ancestor of master | → qa.2 → RC.6 | `SUPERSEDED_PRESERVE` + Desktop `CHECKSUM_ONLY` | source yes | `07/` + dist ZIP | KEEP — CANONICAL HISTORICAL EVIDENCE |
| `rc.6-qa.2.zip.sha256` | Desktop | 120 | checksum file `8e6ea283…fe4b3848` | claims `e4a904fd…` | MATCH located ZIP | `132afcb` | `cfcfa32` | OWNER PASS | ancestor of master | → RC.6 | `CANONICAL_LINEAGE` + Desktop `CHECKSUM_ONLY` | source yes | `07/` + dist ZIP | KEEP — CANONICAL HISTORICAL EVIDENCE |
| `rc.6.zip.sha256` | Desktop | 115 | checksum file `a2632877…062dca2e` | claims `0d4adbef…` | MATCH located ZIP | `7e52525` | tag peel `8f37fe8` | OWNER-ACCEPTED | GitHub `v1.0.0-rc.6` | → RC.7 → RC.9 | `CANONICAL_LINEAGE` + Desktop `CHECKSUM_ONLY` | yes | `07/` + dist ZIP | KEEP — CANONICAL HISTORICAL EVIDENCE |
| `rc.6-r1-qa.1.zip.sha256` | Desktop | 123 | checksum file `3f40ee08…a8eb3b79` | claims `6eb0935c…` | MATCH located ZIP | `41ca3e0` | `70e7e7d` | **OWNER FAIL** | `recovery/post-rc6-admin-setup-defects` | repaired in r1-qa.2 / RC.7; **do not merge this ZIP** | `FAILED_QA_EVIDENCE` + Desktop `CHECKSUM_ONLY` | recovery ref yes | `07/` + dist ZIP | KEEP — FAILED QA EVIDENCE |
| `rc.8.zip.sha256` | Desktop | 115 | checksum file `cc47779f…22e876c3` | claims `70ae635e…` | MATCH located ZIP | `6d16622` | `c370f84` | identity of accepted fulfilment.4 | source on master; tag local-only | → RC.9 | `CANONICAL_LINEAGE` + Desktop `CHECKSUM_ONLY` | source yes; tag no | `07/` + dist ZIP | KEEP — CANONICAL HISTORICAL EVIDENCE |

Full hashes are in `docs/recovery/DESKTOP-ARTIFACT-HASH-MANIFEST-2026-09-16.csv`.

---

## 14. Freshness protocol

### Pass 1

Checkpointed Desktop hashes and archive copies. Fetched `origin --prune --tags`.

| Ref | SHA after Pass 1 |
| --- | --- |
| `origin/master` | `0f9c2f06f9da0e15840fe27c8b28f7d146a5dd8d` |
| `origin/batch/pre-rc10-qualification` | `be586a454cc9a03395b981ef2b07cce445ef8f10` |
| `origin/feat/post-rc9-customer-ux` | `d534d6a24390f39206f697308f0c4bc42919be46` |
| `origin/feat/post-rc9-wpml` | `3b5b60d0d92b2edc00496d536774f8ac907cd6d2` |

No repository-truth change affecting artifact classification.

### Pass 2

Second `git fetch origin --prune --tags` after the report draft:

| Ref | SHA after Pass 2 |
| --- | --- |
| `origin/master` | `0f9c2f06f9da0e15840fe27c8b28f7d146a5dd8d` |
| `origin/batch/pre-rc10-qualification` | `be586a454cc9a03395b981ef2b07cce445ef8f10` |
| `origin/feat/post-rc9-customer-ux` | `d534d6a24390f39206f697308f0c4bc42919be46` |
| `origin/feat/post-rc9-wpml` | `3b5b60d0d92b2edc00496d536774f8ac907cd6d2` |

Identical to Pass 1. No repository-truth change. No Pass 3. No pull/rebase.

---

## 15. Conclusion

**Every Desktop Delivery Engine ZIP and `.sha256` file is accounted for.**

There is no unrecovered Delivery Engine code stream hiding in these Desktop artifacts. Failed QA (`rc.6-r1-qa.1`) is accounted for as immutable failure evidence, not as accepted runtime. WPML is accounted for as a separate overlay. Post-RC.9 integrated work is accounted for as recovered qualification input, not as canonical `master`.

Do not delete anything on the strength of this report.

---

## 16. Later archival guidance (human approval required; not deletion now)

Safer to move off Desktop later, after human archive confirmation (keep at least one byte-identical copy in `delivery-engine-recovery-2026-09-15`):

- BYTE_IDENTICAL Desktop duplicates of packages already in `06-artifacts` and `07-` (`integrated.1`, `integrated.2`, `peritem.1`, `wpml.1`)
- Desktop checksum sidecars whose matching ZIP is archived in `07-\located-zips-…`
- BYTE_IDENTICAL copies under `cetech-de-local-qa\packages\` once the recovery archive is the owner’s chosen store

Remain especially protected (do not treat as clutter):

- `integrated.2.zip` (`a16a7840…`) — frozen recovered candidate
- `rc.9.zip` (`08862b8c…`) — current published package
- `rc.6-r1-qa.1` checksum + located ZIP (`6eb0935c…`) — failed QA evidence
- `wpml.1.zip` — separate stream
- The entire `07-desktop-artifact-reconciliation-2026-09-16` directory
- Local-only tags `v1.0.0-rc.3` / `rc.7` / `rc.8` (Git objects, not Desktop files)

---

## 17. Schema / runtime / compatibility notes for this documentation-only pass

- Schema unchanged (`5` on RC.9 / qualification; historical RC.6 packages remain schema `4`)
- No shipping-integrity change
- No WooCommerce/HPOS/theme change
- No FLAIROC/training/production effect
- Tests: not re-run for this accounting pass; no product tests were required
- Known limitation: this is Desktop-scope accounting, not a full historical `dist/` census of every QA ZIP ever built
