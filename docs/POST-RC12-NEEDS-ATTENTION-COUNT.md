# POST-RC.12 — Unified Needs Attention count (Issue #32)

**Frozen development identity:** `1.0.0-dev.attention-count.1`  
**Schema:** `6` (unchanged; no schema 7)  
**Branch:** `fix/needs-attention-count-contract`  
**Original implementation base:** protected `master` `d66e5e366612468d7e5b4874e1a4290c5d2582cc`  
**Requires PHP:** `8.3`  
**Runtime / package-source SHA:** `6e9e2ea71796170afb0508954a18d206cdc7017a`  
**Pull request:** https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/43  
**MERGED via PR #43 to protected `master` `88c9ec09f3cfabf73b83780c0c37d397e9acdad6`; Issue #32 CLOSED / COMPLETED. Not RC.13.**

Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/32

Historical pre-QA training baseline was `1.0.0-dev.shipment-order-read.1`. Training now runs the verified `1.0.0-dev.attention-count.1` package after physical QA PASS.

## Contract

Overview → Needs attention, the Needs Attention page, and the Needs Attention admin-menu badge are three views of one operational concept.

The canonical count authority is `NeedsAttentionCountQuery::unresolved_count_for_current_user()`.

Authorized sources, unchanged:

1. `NeedsAttentionQuery` — incomplete product delivery setup.
2. `BulkStaleJobQuery` — genuinely stalled Bulk Tools jobs.
3. `ShipmentCreationIssueQuery` — paid-order shipment creation failures.
4. `ShipmentOperationsIssueQuery` — shipment operational review.
5. `CodAwaitingShipmentQuery` — COD orders awaiting staff shipment creation.

The aggregate still sums those source counts. This issue does not add cross-source deduplication.

Ordinary awaiting-fulfilment, processing, in-transit, missing tracking, pickup-only orders, unpaid non-COD orders, and Shipment Activity events stay out unless an existing source query already classifies them as actionable.

## Training baseline (read-only)

Inspected on `https://training.cetechbpa.com` through the installed plugin services before changing this candidate. Customer name, address, email, and phone are not recorded. Order and shipment IDs are not recorded. Staff user id `1`.

| Fact | Value |
|------|-------|
| Installed plugin | `1.0.0-dev.shipment-order-read.1` |
| Schema | `6` |
| PHP | `8.4.24` |
| WooCommerce | `11.1.0` |
| `enable_shipment_records` | enabled |
| `manage_product_delivery_rules` | yes |
| `manage_shipments` | yes |
| Catalog `NeedsAttentionQuery::count()` | `0` |
| Bulk stale `BulkStaleJobQuery::count()` | `0` |
| Shipment creation `ShipmentCreationIssueQuery::count()` | `2` |
| Operations `ShipmentOperationsIssueQuery::count()` | `3` |
| COD `CodAwaitingShipmentQuery::count()` | `3` |
| Canonical `unresolved_count_for_current_user()` | `8` |
| Overview tile | `0 items` |
| Menu badge | `8` |

Confirmed mismatch: **yes**. Overview used catalog-only `NeedsAttentionQuery::count()`. The menu badge already used the canonical aggregate.

## Capability and source matrix

| Staff | Catalog + stale bulk | Shipment creation, operations, COD |
|-------|----------------------|-------------------------------------|
| Product rules only | visible and counted | not loaded; contribute 0 |
| Shipments only, records enabled | not loaded; contribute 0 | visible and counted |
| Both, records enabled | visible and counted | visible and counted |
| Shipments only, records disabled | no | no; Needs Attention tile, menu, and direct page denied |
| Neither attention capability | no | no; tile, menu, and direct page denied |

`NeedsAttentionCountQuery` remains the visibility authority:

- catalog: `manage_product_delivery_rules`
- shipment/COD: `enable_shipment_records` and `manage_shipments`
- either source: `current_user_can_see_needs_attention()`

Overview omits the Needs attention card when that method is false. It does not link a user into a permission denial. Other Overview cards are unchanged.

The page loads a source only when the same visibility method allows it. Direct access is denied when neither source is visible, including a shipments-only user while shipment records are disabled.

The menu badge stays `unresolved_count_for_current_user()` when the user can see Needs Attention. No second aggregate was added.

Count limits and page list limits stay independent. The badge is not the number of HTML rows.

## What was not changed

- Shipment creation, COD lifecycle, operations recovery, and Bulk stale threshold.
- Issue #31 order-read recovery.
- PDP, cart, checkout, coverage, rates, and Location Packs.
- Schema, migrations, and operational data.

## Package

Built from the clean runtime/package-source SHA `6e9e2ea71796170afb0508954a18d206cdc7017a` after GitHub CI run `35759882478` SUCCESS. Do not overwrite the frozen shipment-order-read ZIP. Do not treat this ZIP as RC.13.

- Filename: `cetech-woocommerce-delivery-engine-1.0.0-dev.attention-count.1.zip`
- Bytes: `1,848,319`
- SHA-256: `2027e5941916dff0302d7e2e545de5a2fd027f0c8f1615d97e3991dcfc5fdcb3`
- Production-package verifier: PASS (staged build and extracted ZIP)
- Packaged PHP lint: `497 files / 0 failures` (PHP 8.5.0, vendor excluded)
- GitHub CI on runtime SHA: SUCCESS (`35759882478` push) — PHP 8.3 Minimum Supported, PHP 8.4 Compatibility, PHP 8.5 CETECH Production Target, PHP 8.5 MariaDB Geography/Migrations, PHP 8.5 WordPress/WooCommerce, JavaScript / Vitest, Control Plane, CI Required Gates
- PHPUnit (local PHP 8.5.0, runtime SHA): Tests: 1403, Assertions: 8944, Deprecations: 14, Skipped: 1
- Vitest: 101 passed / 8 files
- Composer validate: PASS
- Team control plane: PASS
- Product control plane: PASS (372 Requirement IDs)


## Technical review, training QA, and merge closeout

- ChatGPT technical review: PASS.
- Final PR head: `658316f775099b0dfc43205bb07c905681a4af10`.
- Final PR-head CI run `35760847856`: SUCCESS.
- Exact physical ZIP re-hash before training install: `1,848,319` bytes; SHA-256 `2027e5941916dff0302d7e2e545de5a2fd027f0c8f1615d97e3991dcfc5fdcb3`.
- Training physical QA: PASS.
- Post-deploy source counts for administrator user 1: catalog `0`, bulk stale `0`, shipment creation `2`, operations `3`, COD `3`.
- Post-deploy canonical aggregate: `8`.
- Post-deploy Overview: `8 items`.
- Post-deploy menu badge: `8`.
- Equality contract: canonical aggregate = Overview = menu badge = `8`.
- Schema remained `6`; no migration or reconciliation.
- Operational counts/fingerprints remained stable during QA; no operational data was mutated to manufacture evidence.
- Training rollback copy recorded at `/home/cetechtraining/backups/cetech-de-attention-count-rollback-20260922T181217Z`.
- PR #43 merged at `88c9ec09f3cfabf73b83780c0c37d397e9acdad6`.
- Issue #32 auto-closed / completed.
- Post-merge protected-master CI run `35766468276`: SUCCESS across PHP 8.3, PHP 8.4, PHP 8.5 production, MariaDB geography/migrations, WordPress/WooCommerce, Vitest, Control Plane, and CI Required Gates.
- RC.12 tag and release asset remain immutable. RC.13 was not created.
- No CETECH Pilot, FLAIROC, production, or POS deployment occurred.
