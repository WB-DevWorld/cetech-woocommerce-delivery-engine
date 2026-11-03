# 1.0.0-m2.5 amendment — catalog count off the admin request path (2026-11-03)

The contract below still holds: Overview, the Needs Attention page and the menu badge are one concept, `NeedsAttentionCountQuery::unresolved_count_for_current_user()` is the count authority, the five sources and the capability matrix are unchanged, and nothing new counts as an issue. What changed is where the catalog source (`NeedsAttentionQuery`) is computed.

- **Why.** On staging (3,763 published products) one catalog count took about 11 s and 18,815 queries, and every wp-admin page paid it through the menu badge. The catalog result does not depend on the viewer; only visibility does.
- **Cheaper scan, same result.** All published products in batches of 250 (the old silent 5,000 cap is gone); no labels or URLs for a count; the product-scope check before the resolve when the catalog is not scanned as customer problems; one presence query per batch. Equivalence with the m2.4 algorithm is tested (`NeedsAttentionCatalogScanTest`).
- **Site-wide snapshot.** The catalog count lives in non-autoloaded options (`cetech_de_attention_catalog_snapshot` and its generation, cursor and lock). The menu badge, Overview, Settings landing and Setup Wizard read it and never scan. Bulk stale, shipment creation, operations and COD stay live and per user exactly as before.
- **Bounded staleness.** A tracked write (scoped configuration, delivery options, pickup locations, site-wide defaults, runtime flags, product publish/unpublish/trash/delete/type change) moves the generation; a debounced WP-Cron job (`cetech_de_refresh_attention_snapshot`) recounts in resumable 20-second runs and refuses to save a count that an invalidation overtook. The badge equals the queue after a refresh. It may lag by at most 30 s + one cron interval + scan time after a tracked write, and by at most the 15-minute TTL + one cron interval + scan time after an untracked one.
- **No snapshot yet** (fresh install, upgrade to a new version): the badge shows the live sources only; Overview, Settings and the Setup Wizard say "Updating…" rather than 0. A refresh is queued.
- **The Needs Attention page** is still the live, authoritative product list. When its scan covers the whole catalog it refreshes the snapshot, so the badge catches up at once; when it stops at its 200-row page it says "Showing the first 200 of M".
- **Cleanup.** Deactivation clears the job and deletes no option (the Deactivator never deletes options; the lock expires by itself). Activation starts a new generation, because catalog changes made while the plugin was inactive were not tracked. Uninstall removes all four options and the job even when merchant data is kept: they are derived cache, not merchant data, so the keep-data default is not affected.
- **Not changed.** Delivery pricing, cart, checkout, shipments, COD, Bulk Tools, schema (6).

# Final closeout — 2026-09-22

Issue #32 is **CLOSED / COMPLETED** and PR #43 is **MERGED**.

- protected-master merge commit: `88c9ec09f3cfabf73b83780c0c37d397e9acdad6`
- runtime/package-source SHA: `6e9e2ea71796170afb0508954a18d206cdc7017a`
- final PR head: `658316f775099b0dfc43205bb07c905681a4af10`
- runtime CI `35759882478`: SUCCESS
- final-head PR CI `35760847856`: SUCCESS
- post-merge master CI `35766468276`: SUCCESS
- exact frozen package: `cetech-woocommerce-delivery-engine-1.0.0-dev.attention-count.1.zip`
- package bytes: `1,848,319`
- SHA-256: `2027e5941916dff0302d7e2e545de5a2fd027f0c8f1615d97e3991dcfc5fdcb3`
- schema: `6`
- training physical QA: PASS
- pre-deploy mismatch: canonical/menu `8`, Overview `0`
- post-deploy truth: canonical `8`, Overview `8`, menu `8`
- post-deploy sources: catalog `0`, stale bulk `0`, shipment creation `2`, operations `3`, COD `3`
- no operational data was mutated to manufacture QA results
- RC.12 unchanged; RC.13 absent; Pilot / FLAIROC / production / POS unchanged

Training currently runs `1.0.0-dev.attention-count.1`. This development identity is qualification evidence only and is not a release promotion.

---

# POST-RC.12 — Unified Needs Attention count (Issue #32)

**Current candidate identity:** `1.0.0-dev.attention-count.1`  
**Schema:** `6` (unchanged; no schema 7)  
**Branch:** `fix/needs-attention-count-contract`  
**Base:** protected `master` `d66e5e366612468d7e5b4874e1a4290c5d2582cc`  
**Requires PHP:** `8.3`  
**Runtime / package-source SHA:** `6e9e2ea71796170afb0508954a18d206cdc7017a`  
**Pull request:** https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/43  
**Not RC.13. Not deployed. Do not merge until owner/ChatGPT technical review.**

Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/32

Training remains `1.0.0-dev.shipment-order-read.1`. Do not install attention-count.1 until owner/ChatGPT technical review.

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
