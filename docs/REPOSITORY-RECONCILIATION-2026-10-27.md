# Repository reconciliation — 2026-10-27

This is the versioned continuation record for the 27 October 2026 repository reconciliation. `CURRENT-WORK.md`, `docs/STATUS_CURRENT.md` and `docs/AI-HANDOFF.md` point here for the branch, pull-request and checkout facts. Older sections of those files are historical.

## Product and scope

CETECH WooCommerce Delivery Engine is a WooCommerce plugin for delivery configuration, customer delivery choice, pricing, shipment records and tracking. WooCommerce remains the commerce authority. The approved standalone scope is the plugin in this repository. It does not include a new release, a live-site activation, a payment or refund action, or a database reset.

The immutable tagged release remains `v1.0.0-rc.12`, annotated tag object `89f34883a017b8bb66f98db345fbbae0d8dd72b0`, source `78594ad8962868683726373f58f4a8b1b48e4d0e`, schema 6. Do not move that tag or rebuild its ZIP. RC.2 through RC.11 stay immutable. Stage 15 has not started. FLAIROC, training and production were not changed by this reconciliation.

## What master contained before this integration

Protected `master` was `bcfebf7f22da7ea6272fddc096c2b22b67257b87`, tree `25e6d63fa9bd5ed29b6d34634f8ebab8e1eba501`, the merge of pull request 111 (W2-P06). Push run `38026222648` on that exact commit succeeded. The preceding P05 correction merge `543275af6844d649268f9ac02db518420dac9fe6` had already succeeded in run `38022281912`. Text in the old handoff that still says P06 is not integrated, or that run `38022281912` is still executing, is superseded by those two successful master runs. The earlier failed run `37995201494` on `8a7a7815b249c7bc117a64ffcc36db1dd8f73ee0` remains a failed historical receipt.

## Integrated checkpoint

Functional parent of this documentation commit:

- Commit: `8063211df8e6b79c0dfb8e1d02eb65e85d977d66`
- Tree: `228c346d45b64e697145ff0ebf7fb110eb3166e2`
- Branch tip before this documentation commit: `ws3/promotion-preparation-e09`
- Push CI: run `39687973318` on that exact commit. All eight required jobs succeeded, including PHP 8.5 WordPress/WooCommerce and CI Required Gates. GitGuardian on that commit succeeded.
- Development identity: `1.0.0-dev.promotion-preparation.1`
- Schema target: `12`

This commit adds the reconciliation record and marks the previous “current” headings historical. It does not change production PHP. After the GitHub merge, `git rev-parse HEAD^2` on the merge commit is this documentation commit when the merge is a normal merge of this branch. `git rev-parse 8063211df8e6b79c0dfb8e1d02eb65e85d977d66^{tree}` remains the functional tree.

E01 through E09 are included by ancestry, not reimplemented:

| PR | Head | Checkpoint |
|---|---|---|
| 115 | `dc413ba1f2eb2a7e04699e9f7872f02ee5b9a358` | Private quote economics facts |
| 116 | `6096611c12ffe6ee093e992eb94eff51968fa4f2` | Fixed delivery promotion plans |
| 117 | `2eb3fb4596d22e771e8b5d2cb3f68b71086c8b63` | Convert-once currency receipts |
| 118 | `ac4f18388054f7cc1c3d803acccfa2f1b4bfcc39` | Native-money verification and private evidence |
| 119 | `6a5a3b7cdb3ff658f11a19221ce59e2e7f3f78b9` | Compiled fixed-promotion quote readers |
| 120 | `a161c462088266fe9bef871fd8ce1acc213065fc` | Promotion budget lifecycle |
| 121 | `293f63e18e6b123900594865229fa1ba687ff198` | Durable promotion budget SQL |
| 122 | `0dab8962ca99c320ff7eea26efb45c3be85591a8` | Schema 12 installation and preservation |
| 123 | `8063211df8e6b79c0dfb8e1d02eb65e85d977d66` | Atomic preparation on one caller-owned transaction |

Those pull requests targeted their parent branches. Closing them after this merge records that the commits are integrated through this branch. It is not a claim that a later squash replaced them.

Reviewed guards on `8063211`, compared with `bcfebf7`:

- `src/Bootstrap/FeatureFlags.php` and `src/Bootstrap/Plugin.php` are unchanged. No new storefront mount was added.
- Schema 12 migration `database/migrations/20261021213000_create_promotion_budget_tables.php` is additive. It seeds no account, reservation, merchant value or adoption option.
- New budget tables are registered for preservation, with no cleanup, release, redemption or rewrite grant.
- Format 3 is accepted only when the fixed-promotion provider identity, versions and capture digest match. A mixed legacy/format-3 quote is refused.
- The 23 feature-flag defaults and 372 registry classifications are unchanged by this checkpoint. The pre-existing classic-checkout adapter default is untouched.
- This documentation commit does not install the plugin, boot WordPress, or migrate a database. An ordinary boot of an installed copy of this source can run the existing forward migration to schema 12. Do not deploy it to FLAIROC, training or production under this reconciliation.

The in-file E09 freeze that says physical CI was still pending is superseded for commit `8063211` by run `39687973318`. That run is push evidence on the branch tip. The merge pull request’s own required checks are a separate gate and are recorded on that pull request.

## Not merged

### Staff workflow — pull request 114

- Branch: `ws3/admin-promise-staff-workflow`
- Head: `d2f22ae58ac407bc2f3930ff7c33788c5e5d6730`
- Tree at the earlier local worktree commit `5b893862b70a807b509b734f3ce66e5a3febd1c1` was `f7806bd208469ac202f9dc50f98d24464c14cdcf`
- Draft. Core CI on `d2f22ae` passed in run `39636107169`. The pull request states that this is not an independent original-receipt audit or physical browser/keyboard acceptance, and it is not a merge authorization.
- Local worktree fast-forwarded from clean `5b89386` to `d2f22ae`. No unique dirty work was present.

### Economics and policy stack from E10 onward

Pull requests 124–138 remain open drafts. Their commits are not in this integration.

GitGuardian is red on two pulls even though core CI is green:

- PR 124, commit `64c02510f8092811da2e91476e14cf0768fdb19c`, one Generic High Entropy Secret in `tests/Unit/DeliveryQuote/FixedPromotionIssueContractsTest.php`. Incident `38833021`, status Triggered.
- PR 132, commit `a8993c7439e7516c1a3f7c56ce8c9faec71847f0`, four Generic High Entropy Secret occurrences in `tests/Unit/Integrations/DeliveryQuote/FixedPromotionReviewTransportTest.php`, `tests/Unit/DeliveryQuote/FixedPromotionCartSessionEnvelopeTest.php` and `tests/Unit/DeliveryQuote/FixedPromotionCartServiceTest.php`. Incidents `38911283`, `38911284` and `38911285`, status Triggered.

Inspection of those lines, with the values withheld, shows synthetic test material: expected digests, fixture tokens and test identifiers. The same literals are still present at `batch/customer-policy-history-r24` (`2a8dc5c6d7c3efe4f4411d8ba302343870822f58`). Later pulls 133–138 report GitGuardian success only because their own diffs do not reintroduce the lines. That does not clear the earlier incidents. These pulls were not merged. No secret value is recorded in this document.

The blocked stack, each based on the previous head, is:

| PR | Branch | Head | Blocker |
|---|---|---|---|
| 124 | `ws3/promotion-issue-e10` | `64c02510f8092811da2e91476e14cf0768fdb19c` | GitGuardian incident 38833021 |
| 125 | `ws3/promotion-accept-e11` | `fc5e76b3a66f1e6c96ac4d87eb1e9d2422e31627` | Depends on 124 |
| 126 | `ws3/promotion-placement-e12` | `42e5e916d19e113e1fa242673e356cf613e87fa6` | Depends on 124 |
| 127 | `ws3/promotion-bind-e13` | `06409a61cbdf3305268728839f04985bc6c61de3` | Depends on 124 |
| 128 | `ws3/promotion-verify-e14` | `4be120328684343bd024d5d5781eaab7a5ec908a` | Depends on 124 |
| 129 | `ws3/promotion-seal-e15` | `dbee193b8e0aee8b0b01f498d3fe8b248bf2abfc` | Depends on 124 |
| 130 | `ws3/promotion-sealed-read-e16` | `53248ea9f975fd604d7d067e3ff532c6376689a0` | Depends on 124 |
| 131 | `batch/promotion-native-handoff-n17` | `9bb5045f1abaa4fcc41f03085ff74acd2e1ffc0c` | Depends on 124 |
| 132 | `batch/promotion-native-cart-n18` | `855c49276c63367824cb60af9c9faebe5a552e32` | GitGuardian incidents 38911283–38911285, and 124 |
| 133 | `batch/return-policy-contract-r19` | `8c997a4cf9489f0f4020445fd50b8402be0201c0` | Depends on 132 |
| 134 | `batch/return-policy-authoring-r20` | `4562341569476d0c5af0e78966c474ce77035ee2` | Depends on 132 |
| 135 | `batch/return-policy-editor-r21` | `f8343a927c4c86f171db2c7018670ad0479e039d` | Depends on 132 |
| 136 | `batch/refund-policy-editor-r22` | `34602c3276eff2b2f3f67689bb2d8e333f507f30` | Depends on 132 |
| 137 | `batch/policy-assignments-r23` | `75435edb339bb831673f76357fc2496124ba66b6` | Depends on 132 |
| 138 | `batch/customer-policy-history-r24` | `2a8dc5c6d7c3efe4f4411d8ba302343870822f58` | Depends on 132 |

Return and refund policy work is not activated by leaving these pulls open. Do not mark the capability registry promoted because these branches exist.

### Tester documentation — pull request 49

- Branch: `docs/testing-rapid-2026-09-24` at `f3d7112922ba2130dafdd1555a60c744d9ac4ea2`
- Open, behind `master`, review required. Core CI on its own head succeeded.
- Unresolved review finding: the pack still presents the RC.12 campaign as the current test entry point and has at least one test-ID mismatch between the index and a module. It was not updated or merged.

## Open issues

- #113 — P06 non-live staging and recovery rehearsal. Still open. Not performed here.
- #54 — Historical PHP 8.5.11 listener SIGSEGV diagnostic. Still open. Not reinvestigated.
- #50 — Admin clean-slate reset with Location Pack preservation. Still open. Not performed.
- #47 — Closed on 2026-10-27 after the branch retirement and MariaDB check realignment below.

## Repository identity

- Canonical repository: `https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine`
- Canonical branch: protected `master`
- `origin` on the canonical checkout points at that repository.
- The `upstream` remote is the `janelove-tech` fork. At this reconciliation it was 421 commits behind `origin/master` and had no commits that `origin/master` lacks. It is not a second source of record. It was left configured.
- No `.gitmodules` file is present. No Git LFS payload was required for this reconciliation.
- Line endings and file modes were not rewritten. Global Git configuration was not changed.

Protected `master` ruleset `master-2` still requires one approving review, strict status checks, and the eight CI contexts. The MariaDB context was updated from the stale name `PHP 8.5 MariaDB Geography/Migrations` to the live job name `PHP 8.5 MariaDB Geography/Operations/Rule Lifecycle/Migrations`. The pull-request rule, dismissal setting and allowed merge methods were not otherwise changed. `master-1` still blocks branch deletion and non-fast-forward updates of `master`. Tag ruleset `v*` was not changed.

## Canonical checkout and worktrees

The canonical development checkout is the working tree whose `origin` is the canonical repository and whose `master` tracks `origin/master`. Host-specific paths are in `.git/cetech-local-checkout.txt` inside that clone. That file is not part of the source.

Active worktrees at reconciliation:

- Canonical checkout: `master`, to be fast-forwarded to the merged integration commit. Do not force it onto another branch.
- Staff checkout: `ws3/admin-promise-staff-workflow` at `d2f22ae58ac407bc2f3930ff7c33788c5e5d6730`. This is pull request 114. Leave it on that branch.
- This integration worktree: `batch/reconciliation-2026-10-27`, created from `8063211`. Remove it with `git worktree remove` only after `master` contains this commit and the worktree is clean.

Sibling folders seen beside the checkout and not deleted, because they are not duplicate registered worktrees and their contents were not re-audited: `cetech-de-local-qa`, `cetech-de-owner-qa-rc10`, `delivery-engine-recovery-2026-09-15`, `audit-output-delivery-engine-2026-09-28`. The first two and the recovery and audit folders had no `.git` directory at their roots. Evidence folders named `CETECH-FOR-*` were not opened and were not deleted.

## Preserved refs

These tips were checked and kept:

| Ref | Tip | Why it remains |
|---|---|---|
| `docs/staff-training-rc2` | `27ebf93af811373d7a0ded34be16c1d185ea7ddc` | Unique training and capture lineage |
| `feat/post-rc9-customer-ux` | `d534d6a24390f39206f697308f0c4bc42919be46` | Unique historical customer-UX commits |
| `feat/post-rc9-wpml` | `3b5b60d0d92b2edc00496d536774f8ac907cd6d2` | Unique WPML overlay |
| `ws3/rc10-closeout-truth` | `3a85d52d898c223519deeb89855878a1193ba859` | Unique historical docs commits |
| `ws3/sync-current-status-2026-09-16` | `dbf90ca4d892ce2c7dc12f0c92343321888b3135` | Unique superseded status commit; do not merge |
| `docs/post-issue32-truth-sync` | `6582074482d04bbf86a08be7c3219300547b861e` | Unique commits; not on the Issue 47 delete list |
| `ws3/wave2-p05-payment-transport-diagnostic` | `0f17e6b90ff3ce80642963ae35c7d3dab1a4c0e4` | One unique diagnostic commit |
| `ws3/wave2-p06-transfer` | `8ff811fe78a81c8c0ec6da7215157b33d163fb73` | Unique transfer commit, not in master |
| `ws3/p05-source-read-control/20261010` | `82bc03a8f570640ec497874b65f058146a74b75a` | Unique diagnostic |
| `ws3/p05-source-read-control/20261010-fixture-provenance` | `55612a92e846abcc1eaf9079cec66c231a5b37f9` | Unique diagnostic |
| `ws3/p05-source-read-control/20261010-label-repair` | `c9046055947c338da648352436b8b030da700861` | Unique diagnostic |
| `batch/promotion-native-cart-n18-sql-proof` | `c2448c4f1959df88f9b7859f5223a6899e621369` | Contained in the unmerged R24 history; named diagnostic ref kept |
| `ws3/r24-quote-receipt-diag-67c3ecc` | `0f7cc4afd7b58d51b9443682d087482a7d6ad45d` | Unique diagnostic ahead of the policy stack |
| `wip/rc6-adversarial-security-audit` | `a88f28048afcc84456e6933351c71b01a9838c5a` | Local ref only; not on `origin` |

Local stashes were left in place:

- `stash@{0}` on `fix/cart-checkout-address-ux`
- `stash@{1}` on `fix/geonames-country-identity`
- `stash@{2}` on `release/rc12`
- `stash@{3}` on `feat/post-rc9-per-item-context`

## Retired remote branches

Each branch below was deleted only after `git rev-parse` matched the recorded tip and that tip was an ancestor of `bcfebf7`. No open pull request, worktree or stash required the ref. The commits remain reachable from `master`. This list is the recovery record.

| Deleted ref | Recoverable tip |
|---|---|
| `batch/opening-corrections-2026-10-06` | `c133f04243a2f53082347c48a8ca134cc4c5791a` |
| `codex/w2-q05-http-fixture-recovery` | `559392dafd6d51e1aaab81852775b687d592a020` |
| `docs/post-issue32-repository-truth` | `191c1bc7968ebfec313e32d071b0a1a6eb7bb716` |
| `docs/rc12-training-realignment` | `3d171f841949cd5e4058cb4f4b9d6843119f6a0d` |
| `fix/audit-opening-authority-migration` | `d5c8e2f3c467a7dc38637bb2cdc3e881fc1c24f8` |
| `fix/cart-checkout-address-ux` | `000c5b3350de0508ae1cd5009ee53ac114a1ffa9` |
| `fix/checkout-multi-destination-preservation` | `41c80300e1ec7874d6d817d29df2196b935ceacc` |
| `fix/cor-004-006-catalog-worker` | `1d62967b6559ee8390ddd8f787cc58ce2729c005` |
| `fix/cor-005-007-scan-save` | `89d8d3466f3636989bc44d004d3a52579c10776f` |
| `fix/geography-pack-liveness` | `be2187f75d5dabdddc79060ebf37766387d5b041` |
| `fix/geonames-country-identity` | `5b1749c7226a4a6666d419da1d20d53a998b3849` |
| `fix/needs-attention-count-contract` | `658316f775099b0dfc43205bb07c905681a4af10` |
| `fix/pdp-location-precision` | `d1ef43027035d958f2a53272ae59691e790563a4` |
| `fix/pr55-jit-disable-comparison` | `85800c5590c44ac9ad916baa86d772e1be276392` |
| `fix/shipment-workspace-order-read` | `1da738eeac3d160d78e54dfa84556d4d45c4af9a` |
| `fix/wave2-p05-bounded-source-integration` | `e4340bfbeb75e8e96444ee240fff873748c36449` |
| `ws1/ui-ux-refinement-2026-10` | `ea263e880850e7642a5eda8eeab449f377b39a3f` |
| `ws3/opening-integration-closeout-2026-10-06` | `82a6f2e9dc514374d00fa72393c951a707d4800c` |
| `ws3/php-85-runtime-realignment` | `bab46d0a1317cf6fbf5d632abcf96fe06cdcd538` |
| `ws3/wave1-c01-internal-contracts` | `258c746645c8a4282831a3638c1d0820f108f102` |
| `ws3/wave1-c02-safe-explanations` | `f6f224b0e0c7e047cf93e97496b810a7135bf386` |
| `ws3/wave1-c03-operation-store` | `20392bcb852b31451ec703ec0eed45067fb6be2c` |
| `ws3/wave1-c03-storage-design` | `c8b30a269e62287a702606633766f63fa121ccd5` |
| `ws3/wave1-c04-rule-lifecycle` | `4fbb998e4684ad4be92c44167389e06e5cd3c3ef` |
| `ws3/wave1-c04-rule-lifecycle-design` | `7e10f268fcd93993db310bd1c030bdee1817856f` |
| `ws3/wave1-c05-snapshot-reader` | `d330dd40b45fac94dc98a22caa1af2836a41ef04` |
| `ws3/wave1-c06-data-lifecycle` | `0ebeca8d33b17fec9fd70cb7706a50d447ad8d91` |
| `ws3/wave1-c06-data-lifecycle-design` | `c260514ab696888e3354371770d7ea9fb4150f8c` |
| `ws3/wave1-c07-emergency-control` | `19070d307f9e188c146835b67a39a2dd66395cd0` |
| `ws3/wave1-c07-emergency-design` | `9679cca8c171e838e6583af3b0683b01ade0e90c` |
| `ws3/wave1-c07-integration-record` | `2b31496cc9f1cd9305ec097585eb0883b8c2eec7` |
| `ws3/wave1-contract-plan-2026-10-06` | `5da4d240c055dc2cbefa0da1947272d4968b7498` |
| `ws3/wave2-deliveryquote-design` | `3d34c7bc90771176ba99c248dfe26459f148b90b` |
| `ws3/wave2-p01-contracts` | `2633b128bf1d713b94e770ecbae90e53cfaf0963` |
| `ws3/wave2-p02-persistence` | `9ab3d9453306c7c1bd6e9d644d304e35b3dd74c1` |
| `ws3/wave2-p03-calculation` | `bb808d42615c69fbf6868de8751b40a6c7a17dca` |
| `ws3/wave2-p04-candidate-check` | `0c70ba6d44a4b428892014fe5b750f67cb03cce2` |
| `ws3/wave2-p04-handoff` | `fc5bc07feba968f8a75502c0551c36fe62a17319` |
| `ws3/wave2-p05-native` | `7000211f16ddce6317dbe010bec5581082c5a9d6` |
| `ws3/wave2-p06-qualification` | `adc2c58fbd6413d8cf5bc3cd43e62ca39b21b4d1` |
| `ws3/wave2-promise-policy-design` | `9044301c8551c24d0347b5637decf76091a04875` |
| `ws3/wave2-q01-quote-contract` | `de7965bb8fb1972ed0347ea506b90c9860a9ad7c` |
| `ws3/wave2-q02-quote-storage` | `99e3626a386a5fe7012fe6374d7ed5b410fa2b6e` |
| `ws3/wave2-q03-durable-lifecycle` | `825cb7049569402552c80cee34242f8342cab028` |
| `ws3/wave2-q04-legacy-price-provider` | `108853048a42e119a0ed01395657ee63f9c3d9a7` |
| `ws3/wave2-q05-cart-readers` | `0ca2574321d20d31af6a29616ee5d5d4d88da008` |
| `ws3/wave2-q06-placement` | `9d00d5c7adbbcae276520dd7758d051fd757a093` |
| `ws3/wave2-quote-closeout` | `ab700dde1e5b3ffd8007ad8ec4bfe7b227ab1a55` |

## Setup and checks

Supported PHP is 8.3 through 8.5.x, as stated in `docs/PHP-RUNTIME-POLICY.md`. The authoritative CI pins are `.github/workflows/ci.yml`. The WordPress job restores PHP 8.5.11 and uses MariaDB 11.4.13 and Composer 2. Do not treat a local PHP patch as a new certification.

From a checkout of the integration commit:

```text
composer install
composer test
npm install
npm run test:js
```

`composer test` runs PHPUnit. `npm run test:js` runs Vitest. Full qualification is the eight-job workflow, not a partial local run. This reconciliation did not re-run that suite locally; it reused run `39687973318` for functional parent `8063211` and relies on the merge pull request’s required checks for this documentation commit.

Package and release commands stay in the repository scripts and `docs/RC12-PROMOTION.md`. Do not rebuild or retag `v1.0.0-rc.12`.

Private configuration is not stored in Git. `.env` and `.env.local` are ignored. Operators use their existing GitHub and WordPress credential stores. This document contains no tokens, customer records or package bytes.

## Next task

Clear the GitGuardian disposition on the E10 and N18 test literals without printing the values and without rewriting the published commits `64c02510` and `a8993c74`. The smallest safe step is a new branch from `8063211` that carries the E10 change with low-entropy fixtures, or a GitGuardian incident resolution that records these occurrences as synthetic test vectors. Do not merge pull requests 124–138 while incidents `38833021` and `38911283`–`38911285` stay Triggered. Do not mount promotion, return or refund behavior on a live store. Pull request 114 stays a separate draft until its own browser and receipt acceptance is actually complete.
