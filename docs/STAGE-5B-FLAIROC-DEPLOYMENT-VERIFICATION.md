# Stage 5B — FLAIROC Deployment Verification

**Document status:** Living Stage 5B deployment record  
**Plugin version:** `1.0.0-rc.1` (unchanged public version)  
**Schema target:** `3` (unchanged)  
**Date opened:** 2026-08-10  
**Last updated:** 2026-08-10 (Stage 5B-3 controlled ECR parity — **BLOCKED**)

---

## 1. Current verdict

**Stage 5B-3: BLOCKED**

Controlled live ECR enablement on FLAIROC for QA product `#39705` proved:

- ECR routing works (`#39705` → `ecr`; `#39589` → `legacy`)
- `in_warehouse` effective config VALID with legacy-equivalent semantics
- Selector customer UI parity PASS (In warehouse + FLAIROC QA Standard Delivery; no Air/Sea; no privacy leak)
- ECR-alone does **not** expose customer UI
- Cart capture stores ECR selection + configuration fingerprint

**Blocker (single corrective task):**

`CartDeliverySelectionSessionData::normalizeIntent()` dropped `configuration_fingerprint`, so checkout revalidation / shipping hash checks treated a fresh ECR selection as invalid/stale. Live cart_shipping probe therefore returned:

- checkout validation invalid (“no longer available”)
- no Delivery Engine shipping rate (empty rates; cart shipping total `0` while product subtotal `19.99`)

**Do not create an ECR QA order until the fix is packaged, deployed to FLAIROC, and Stage 5B-3 re-run past shipping = 25.00.**

Local fix landed in-repo (preserve fingerprint on normalize; regression PHPUnit). Public version not bumped. FLAIROC runtime flags and COD restored **OFF**; dormant storefront PASS after test.

Human Stage 5B-2 admin smoke and Stage 5B-2A slice audit remain valid background.

ECR remains **OFF** on FLAIROC until Stage 5B-3 re-verification after deploy.

---

## 1A. Confirmed FLAIROC failure evidence (authoritative)

### Fatal #2 — bootstrap (deployed `d5fefa2`, ~18:12 UTC)

```text
PHP Fatal error: Uncaught Error:
Class "CetechDeliveryEngine\Bootstrap\ConfigurationHealthChecker" not found
in src/Bootstrap/Plugin.php:416
```

- **Root cause:** missing Diagnostics import.
- **Local fix:** `325e252` (preserved).

### Fatal #1 — Effective Configuration Preview (~21:05 UTC)

```text
PHP Fatal error: Uncaught Error:
Call to a member function get_category_ids() on false
in src/Presentation/Admin/EffectiveConfigurationPreviewPage.php:308
```

- **Root cause:** `wc_get_product()` returns `false`; null-only guard.
- **Local fix:** `4e8f503`.

### Warning — selector validator (~14:55 UTC)

```text
Undefined array key "error_code"
```

- **Local fix:** `4e8f503` (success context now includes `error_code => null`).

---

## 2. Stage 5B-1 original package (failed)

| Item | Value |
|------|-------|
| Artifact | `cetech-woocommerce-delivery-engine-stage5b.zip` |
| Build commit | `d5fefa2d8c34ea3a5e764f93be3c2457bc3290ef` |
| SHA-256 | `973c020927577fc53b1f5d195881a83ca13e17107207fd1b6bdb100b7769e4cc` |
| Public version | `1.0.0-rc.1` |
| Schema target | `3` |
| ECR flag default | OFF |

---

## 3. Failed deployment + recovery (history)

1. Human installed Stage 5B-1 ZIP (`d5fefa2`).
2. wp-admin critical error on plugin boot (Fatal #2).
3. Plugin filesystem-disabled → wp-admin recovered.
4. Later Fatal #1 observed on Effective Preview with invalid product.
5. Local repairs: `325e252` then `4e8f503`.
6. Human installed repaired ZIP `cetech-woocommerce-delivery-engine-stage5b-repaired.zip`.

---

## 4. Historical log separation

| Log cluster | Approx time | Nature | Stage 5B relevance |
|-------------|-------------|--------|--------------------|
| `RateCardRepositoryInterface` not registered | ~14:35 UTC | Code Snippets `eval()` | Stage 0B history |
| `error_code` undefined key | ~14:55 UTC | Validator contract | Fixed in `4e8f503` |
| `ConfigurationHealthChecker` not found | ~18:12 UTC | Boot DI | Fixed in `325e252` |
| `get_category_ids()` on false | ~21:05 UTC | Preview invalid product | Fixed in `4e8f503` |

Agent could **not** read live PHP logs this session (no public debug.log; no SSH). Fresh-log recurrence for admin fatals is therefore **NOT AGENT-VERIFIED** (requires human log check after admin smoke).

---

## 5. Repair artifacts

| Item | Detail |
|------|--------|
| Bootstrap repair | `325e252` |
| Admin harden repair | `4e8f5031030ec4064ca570e748f0f025aa19800c` |
| Docs checksum follow-up | `2a49dbd` |
| Repaired ZIP | `cetech-woocommerce-delivery-engine-stage5b-repaired.zip` |
| Repaired SHA-256 | `1d672dae1743bc46c497fa4de2d1e44d3cce5df7faafe84df166efca058ca59a` |
| Bytes | `634795` |

### Artifact traceability (Stage 5B packages)

| Artifact | Commit / basis | ZIP | SHA-256 | Status |
|----------|----------------|-----|---------|--------|
| 1 | `d5fefa2` | `…-stage5b.zip` | `973c0209…e4cc` | **FAILED** admin deployment |
| 2 | `4e8f503` admin/bootstrap repaired | `…-stage5b-repaired.zip` | `1d672dae…a59a` | Stage 5B-2 safety/admin **PASS** |
| 3 | `f300390` fingerprint repair (HEAD docs `7fe41c3`) | `cetech-woocommerce-delivery-engine-stage5b-fingerprint-fixed.zip` | `dbddc1d7df3c1262296e9c42b05e87921066fc6f5f29b0f3c798385ad1d4cbfd` | **Packaged locally — NOT deployed**; pending Stage 5B-3 retry |

Artifact 3 absolute path (local): `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-stage5b-fingerprint-fixed.zip` (636893 bytes). Do **not** claim Artifact 3 was installed on FLAIROC.

---

## 6. Stage 5B-2 agent verification (2026-08-10 ~22:35–22:37 UTC)

Authenticated REST Application Password probe: **HTTP 200**.

### 6.1 Site / plugin health

| Check | Result |
|------|--------|
| Storefront `GET /intl/` | **200** |
| `GET /intl/wp-json/` | **200** |
| Authenticated `wp/v2/posts?context=edit` | **200** |
| Delivery Engine plugin | **active** `cetech-woocommerce-delivery-engine/…` `1.0.0-rc.1` |
| Active DE copies | **1** |
| Code Snippets | `code-snippets.disabled/code-snippets` **inactive** |
| COD | **DISABLED** |
| Final recheck storefront/REST/auth | **200 / 200 / 200** |

### 6.2 Dormant storefront (PASS)

| Product | HTTP | DE selector / unavailable notice | ATC / variations | Verdict |
|---------|------|----------------------------------|------------------|---------|
| `#39705` QA simple | 200 | Absent | Add to cart present | PASS |
| `#37054` normal simple | 200 | Absent | Add to cart present | PASS |
| `#39589` variable | 200 | Absent | Variations form present | PASS |

Contrast with prior intermediate-fixed Stage 5B-2 attempt: `#37054` previously showed DE selector UI (flags ON). **No longer present.**

### 6.3 Historical order / QA rate evidence

| Check | Result |
|------|--------|
| Order `#39706` shipping | **25.00** |
| Protected package snapshot amount | **25.0000** |
| Line snapshot `rate_card_code` | `flairoc_qa_rate_card` |
| Order mutation this session | **NONE** |
| Live admin read of rate card row | **NOT AVAILABLE VIA REST** (shipping zone methods empty via WC REST; no DE rate-card endpoint) |

### 6.4 Runtime flags

| Flag | Agent-observed state |
|------|----------------------|
| `enable_product_delivery_selector` | **Inferred OFF** (no storefront selector UI) — direct option **NOT READABLE** |
| `enable_cart_delivery_selection_capture` | **Inferred OFF** — direct option **NOT READABLE** |
| `enable_effective_configuration_runtime` | **Not enabled by this task**; direct option **NOT READABLE**; source default remains false |
| Other reserved/customer runtime flags | **NOT READABLE via REST** (`wp/v2/settings` has no `cetech_*` keys; no DE options API) |

### 6.5 Schema / migration / Stage 4 admin / preview

| Area | Agent result |
|------|--------------|
| `cetech_de_db_version` | **NOT VERIFIED** |
| v3 tables | **NOT VERIFIED** |
| Legacy `product_delivery_rules` | **NOT VERIFIED** live |
| Shipment tables absent | **NOT VERIFIED** live |
| `#39705` migration backfill | **NOT VERIFIED** live |
| Category quarantine | **NOT OBSERVED** live |
| Stage 4 Global/Product/Variation/Preview | **NOT EXECUTED** — all `wp-admin/admin.php?page=cetech-*` probes **Cloudflare 403** |
| Invalid product preview regression | **NOT EXECUTED** (same CF block) |
| HealthChecker boot recurrence via logs | **NOT AGENT-VERIFIED** (no log access) |
| Validator `error_code` warning live | **NOT RUNTIME-EXERCISED IN THIS FLAG-OFF STAGE** (local regression tests cover contract) |
| No-op save | **NO-OP SAVE NOT EXECUTED — mutation risk avoided / admin unreachable** |

### 6.6 Rate safety / privacy (static)

| Check | Result |
|------|--------|
| `RateCardAmountFormatter` + fail-closed quote path | Present in repository/repair package source; local PHPUnit green |
| Live ECR privacy | **N/A** — ECR OFF; deferred to Stage 5B-3 |

### 6.7 What this task did NOT do

- Did **not** enable ECR or any runtime flag
- Did **not** place orders / alter `#39706` / alter QA rate / alter products
- Did **not** reactivate Code Snippets
- Did **not** modify Redis / Nginx / Cloudflare
- Did **not** begin Stage 5B-3

### 6.8 Local automated checks

| Check | Result |
|------|--------|
| PHPUnit | **10.5.64** — **113 tests / 435 assertions / 0 failures** |
| `composer validate --no-check-publish` | valid |
| Production package verifier against repo `vendor/` | expected fail (dev PHPUnit present); repaired ZIP previously verified OK at build time |

---

## 7. Blocker (single corrective task) — Stage 5B-2 human checklist (historical)

**Human administrator completed** Stage 5B-2 wp-admin smoke after repaired package install (Global / Product / Variation / Effective Preview / invalid product). Schema `3` and flags OFF confirmed via Stage 5B-2A WP-CLI audit.

**Do not enable ECR until Stage 5B-3 is explicitly started.**

---

## 8. Stage 5B-2A — live QA migration / slice audit (read-only, 2026-08-10)

**Method:** SSH + WP-CLI `eval-file` against `/home/flaimainroc/htdocs/flairoc.com/intl`. No config writes, no flag changes, no DB mutation, no ECR enablement. Temp scripts removed after run.

### Verdict

**READY — WRONG SLICE SELECTED** (classification A / expected preview-selection issue — not a migration failure)

### Schema

| Item | Live result |
|------|-------------|
| Prefix | `flagh_` |
| `cetech_de_db_version` | `3` |
| Scoped tables | `flagh_delivery_engine_configuration_scopes` / `_fields` / `_collections` |
| Legacy rules | `flagh_delivery_engine_product_delivery_rules` present |
| Shipments | `shipments` / `shipment_items` / `shipment_events` **absent** |

### Legacy `#39705`

| Rule | Target | Slice/FA | Offers | LP | Supplier | Origin | Priority | Enabled |
|------|--------|----------|--------|----|----------|--------|----------|---------|
| 1 | product / 39705 | `in_warehouse` | `[1]` | 1 | 1 | 1 | 10 | active |

**Winning rule:** legacy `#1` (sole product rule; category rules for product category `20`: **none**).

### V3 `#39705` scopes

| Scope ID | Slice Key | Version | Source | Legacy Rule |
|----------|-----------|---------|--------|-------------|
| 2 | `in_warehouse` | 1 | migrated | 1 |

**No** product scope with `slice_key=''` for `#39705`.

### V3 fields (scope 2)

All OVERRIDE: `fulfilment_availability=in_warehouse`, `fulfilment_choice=delivery`, `logistics_profile_id=1`, `supplier_id=1`, `origin_id=1`, `priority=10`.

### V3 collections (scope 2)

`delivery_offer_ids` REPLACE `[1]`.

### Migration traceability

Option `cetech_de_v3_config_migration_report`: `completed_at=2026-08-10T18:12:27+00:00`; **migrated `[1]`**; quarantined/skipped/unchanged empty. Matches Stage 2 contract.

### Global scope

Scope id `1`, version `1`, source `native`, **no fields**, **no collections** — expected empty pre-cutover root created by `ensureGlobalScope()`.

### Why Default preview is unresolved

Preview defaulted to `slice_key=''` (“Default (native root slice)”). No product overrides exist on that slice; empty Global leaves required fields `UNRESOLVED_GLOBAL_VALUE` (“A required root (global) value is not configured.”). Read-only ECR resolve for `''`: unresolved; for `in_warehouse`: **valid** with legacy-equivalent values.

### Correct preview slice

**In warehouse** (`in_warehouse`).

### Category compatibility

Does **not** depend on a legacy category rule. With ECR ON (future), `#39705` is intended to route **ECR** (simple + non-category). Current router decide with flag OFF: `legacy`.

### Runtime state (audit)

All FeatureFlags defaults/options OFF; `enable_effective_configuration_runtime` raw option `null` → default **OFF**. No remote mutation.

### Stage 5B-3

**Not started in 5B-2A.** Ready for controlled Stage 5B-3 only after human confirms In-warehouse preview.

---

## 9. Stage 5B-3 — controlled live simple-product ECR parity (2026-08-10 ~23:04–23:15 UTC)

**Method:** SSH + WP-CLI harness against `/home/flaimainroc/htdocs/flairoc.com/intl`; storefront HTTP probes; flags sequenced then restored OFF. Human confirmed Effective Preview `#39705` / In warehouse = VALID before start. Temp remote harness files removed after restore.

**Pre-test marker:** PHP `error.log` line count **889** at **2026-08-10T23:09:14Z**. Site health: storefront/REST/auth REST **200**. COD **OFF**. All Delivery Engine runtime flags **OFF**.

### Runtime flag sequence

| Flag | Initial | During test | Final |
|------|---------|-------------|-------|
| `enable_effective_configuration_runtime` | OFF | ON (step 7) | OFF |
| `enable_product_delivery_selector` | OFF | ON (step 9) | OFF |
| `enable_cart_delivery_selection_capture` | OFF | ON (step 11) | OFF |
| `enable_checkout_delivery_selection_validation` | OFF | ON (step 13) | OFF |
| `enable_woocommerce_shipping_rate_calculation` | OFF | ON (step 14) | OFF |
| `enable_order_delivery_snapshot_persistence` | OFF | never ON | OFF |
| `enable_customer_order_delivery_summary` | OFF | NOT EXECUTED | OFF |
| `enable_customer_email_delivery_summary` | OFF | NOT EXECUTED | OFF |
| shipment/tracking/timeline/Blocks reserved | OFF | OFF | OFF |

### What passed before the blocker

| Area | Evidence | Result |
|------|----------|--------|
| Resolve `#39705` / `in_warehouse` | VALID; FA/choice/offers/LP/supplier/origin/priority match legacy | PASS |
| ECR-only storefront | Selector absent; ATC present; HTTP 200 | PASS |
| Routing | `#39705` → `ecr`; `#39589` → `legacy`; not category-compat | PASS |
| Selector parity | Customer: In warehouse + Delivery + FLAIROC QA Standard Delivery; no Default/unresolved; no Air/Sea; no supplier/origin/LP/rate-card/fingerprint | PASS |
| Cart capture | Selection retained; summary customer-safe; fingerprint part present in hash parts | PASS (capture) |
| Hard constraint | In warehouse delivery-only; no Air/Sea | PASS |
| Privacy (product/selector/cart summary) | No private internals in customer surfaces exercised | PASS (partial) |
| Variable safety | `#39589` decide `legacy` with ECR ON | PASS |
| Category compatibility | No live category-winning simple product | NOT OBSERVED |
| PHP logs | Line count remained **889**; no fresh DE fatal/warning after marker | PASS |
| Historical `#39706` | Shipping 25; snapshots unchanged | PASS |
| QA rate / scope | `flairoc_qa_rate_card` **25.0000**; scope v1 `in_warehouse` unchanged | PASS |
| Final dormant | `#39705`/`#37054`/`#39589` no DE UI; HTTP 200 | PASS |
| COD | Initial OFF; never enabled (order not placed); final OFF | PASS |

### Blocker detail

Cart shipping probe after flags ON (ECR+selector+capture+checkout+shipping):

- `source=ecr`, display_key `in_warehouse:delivery:1`, fingerprint `48ca5985…4734`
- Checkout validation **invalid** (stale/unavailable customer message)
- `de_shipping_rate=null`, `shipping_rates=[]`, `cart_shipping_total=0` (product 19.99 only; no fee line)
- Root cause: session `normalizeIntent()` stripped `configuration_fingerprint`, so hash mismatch / stale compare failed closed

**Pre-order gate failed → no ECR QA order created. Snapshot / HPOS order path NOT EXECUTED.**

### Local fix (repo; not yet on FLAIROC)

Preserve `configuration_fingerprint` in `CartDeliverySelectionSessionData::normalizeIntent()` + PHPUnit regression.

### Local automated checks (post-fix)

| Check | Result |
|------|--------|
| `composer validate --no-check-publish` | valid |
| PHP lint (changed file) | clean |
| PHPUnit | **114 tests / 442 assertions / OK** |

### Explicit non-claims (Stage 5B-3)

- Stage 5B-3 is **not** VERIFIED
- Shipping **25.00** ECR parity **not** proven live (blocked before quote)
- No new order; `#39706` untouched
- Fingerprint fix **not** deployed to FLAIROC yet
- Stage 6 not started

---

## 10. Explicit non-claims

- Stage 5B is **not** complete (Stage 5B-3 live ECR parity blocked on fingerprint session normalize)
- Stage 5B-2A resolves the `#39705` Default-slice unresolved mystery as **wrong slice**, not missing backfill
- Empty Global remains intentionally unpopulated (do not fill Global merely to green Default preview)
- UX wording notes for later: none blocking; customer labels used “In warehouse” / “Delivery options” (acceptable)

### Recommended next step

1. Human administrator installs Artifact 3 (`…-stage5b-fingerprint-fixed.zip`) replacing the active Delivery Engine plugin.
2. Confirm storefront / wp-admin / REST health **before** enabling any runtime flags.
3. Re-run Stage 5B-3 with the controlled flag sequence (shipping must be **25.00**, then one COD QA order, restore OFF).
4. Do **not** begin Stage 6 until Stage 5B-3 is VERIFIED.

FLAIROC was **not** modified during Stage 5B-3R-1 packaging.
