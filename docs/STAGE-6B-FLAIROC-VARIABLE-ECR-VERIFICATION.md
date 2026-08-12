# Stage 6B — FLAIROC Variable-Product ECR Verification

**Document status:** Living Stage 6B packaging + deployment plan  
**Plugin version:** `1.0.0-rc.1` (unchanged public version)  
**Schema target:** `3` (unchanged)  
**Date opened:** 2026-08-11  
**Last updated:** 2026-08-12 (Stage 6 **FUNCTIONALLY COMPLETE**; Stage 6C presentation cleanup done locally)

---

## 1. Current verdict

**STAGE 6 FUNCTIONALLY COMPLETE.**

Live variable QA on FLAIROC passed end-to-end after Stage 6B-3R asset-fix package. Presentation cleanup (Stage 6C) is a separate release-critical UI fix and does **not** reopen Stage 6 runtime architecture.

| Sub-stage | Status |
|-----------|--------|
| Stage 6A | **COMPLETE** |
| Stage 6B packaging / deploy safety | **COMPLETE** (final + asset-fix packages) |
| Stage 6B-3 variable QA live | **PASS** — see §20 |
| Stage 6C customer + order-admin presentation | **DONE locally** — see §21; carry into next release package |

---

## 2. Artifact identity (Stage 6B-1)

| Item | Value |
|------|-------|
| Filename | `cetech-woocommerce-delivery-engine-stage6b.zip` |
| Full path | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-stage6b.zip` |
| Bytes | `654461` |
| SHA-256 | `cc89edf81799cdd734edf6f22d54472eaf6b2d26820a36fc70f371f4cbfa0e76` |
| Checksum sidecar | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-stage6b.zip.sha256` |
| Dist mirror | `dist/cetech-woocommerce-delivery-engine-v1.0.0-rc.1.zip` (same bytes/hash at build time) |
| Build / source commit | `cdc428943bd2a10e37a6d44c29b957f64b30758e` |
| Stage 6A commit included | `b6b86e414fc39566416b0552e21c33829bc83270` (**YES**, ancestor) |
| Public version | `1.0.0-rc.1` |
| Schema target | `3` |
| ZIP root | exactly `cetech-woocommerce-delivery-engine/` (no nested duplicate) |
| Production vendor | `vendor/autoload.php` via `composer install --no-dev --optimize-autoloader` |

### Packaging gates (executed)

| Gate | Result |
|------|--------|
| PHPUnit 10.5.64 | **161 tests / 558 assertions / 0 fail / 0 error / 0 skip / 0 risky** |
| Vitest 3.2.7 | **1 file / 9 tests / all PASS** |
| Package PHP lint | **0 failures** |
| Package Composer autoload / service graph | **OK** (`scripts/verify-production-package-autoload.php`) |
| Main ECR default | **OFF** |
| Variable ECR default | **OFF** |
| Stage 5 repairs preserved | HealthChecker Diagnostics import; WC false-product guards; validator `error_code`/`error_message`; session `configuration_fingerprint`; `RateCardAmountFormatter` fail-closed |
| Dev exclusions | `tests/`, `node_modules/`, PHPUnit, vitest, `package.json`, coverage, `.git`, `.cursor`, secrets absent |

---

## 3. Feature defaults (package)

| Flag | Default | Package install turns ON? |
|------|---------|---------------------------|
| `enable_effective_configuration_runtime` | **false** | **No** |
| `enable_variable_product_ecr_runtime` | **false** | **No** |
| Customer runtime chain (selector/cart/checkout/shipping/snapshot/summaries) | **false** | **No** |
| WoodMart adapter | **false** | **No** |

Activation / `ensure_defaults()` must leave both ECR flags **OFF** if unset.

---

## 4. Stage 5 baseline that must remain protected

Live-verified simple-product path (do not regress):

```text
#39705 → ECR (when main ECR ON) → In Warehouse → Delivery → Offer #1
→ shipping 25.00 → HPOS order #39711 → protected snapshot 25.0000
```

With Stage 6 package installed and **all flags OFF**, storefront must stay dormant (no DE UI) for:

- `#39705` (simple QA)
- `#37054`
- `#39589` (variable **reference only** — do **not** mutate for Stage 6B QA)

---

## 5. Dedicated variable QA product (plan only — do not create in 6B-1)

Do **not** use `#39589` as the mutable test product.

| Field | Planned value |
|-------|----------------|
| Name | `FLAIROC Delivery Engine Variable QA Product` |
| SKU | `FLAIROC-DE-QA-VARIABLE` |
| Type | WooCommerce **variable** |
| Catalogue visibility | **hidden** |
| Attributes | ordinary commerce identity only — e.g. `Option` = `A`, `B` |
| Variations | at least two purchasable variations (A, B) |
| Price | low / controlled QA price |
| Stock | safe for controlled QA |
| Delivery methods as attributes? | **NO** — Delivery Engine owns fulfilment choices |

### Planned configuration (Stage 6B-3 only)

Reuse existing safe QA entities where appropriate (same family as `#39705` / Offer #1 / LP 1 / Supplier 1 / Origin 1 / `flairoc_qa_rate_card` 25.0000) — do not invent duplicate operational entities unnecessarily.

| Scope | Intent | Expected behaviour |
|-------|--------|--------------------|
| **Parent product** | `slice_key=in_warehouse`; fulfilment availability In Warehouse; choice Delivery; Offer #1; LP 1; Supplier 1; Origin 1; Priority 10 | Base scoped config |
| **Variation A** | **no** variation-specific DE override | **INHERIT** parent completely |
| **Variation B** | partial variation override (e.g. Priority only, or controlled offer override) | **OVERRIDE** proves variation-specific resolution |

Both variations must yield deterministic, known shipping expectations when flags are correctly enabled.

Admin Effective Preview for A and B must be **VALID** before any live storefront cutover.

---

## 6. Stage 6B-2 — Flag-OFF deployment safety (human; do not auto-run)

Install the Stage 6B ZIP only. Keep **ALL** runtime flags **OFF**.

1. Verify backup / rollback path ready (filesystem + DB backup as site SOP).
2. Manually install / replace plugin with `cetech-woocommerce-delivery-engine-stage6b.zip`.
3. Confirm SHA-256 of installed artifact matches this document.
4. Keep **all** Delivery Engine runtime flags **OFF** (including both ECR flags).
5. Confirm storefront HTTP **200**.
6. Confirm wp-admin loads (no DE fatals).
7. Confirm REST baseline unchanged.
8. Confirm schema still **3** (no unexpected migration).
9. Confirm `enable_variable_product_ecr_runtime` **exists** and is **OFF**.
10. Confirm `enable_effective_configuration_runtime` **OFF**.
11. Confirm all customer runtime flags **OFF**.
12. Dormant storefront PASS: `#39705`, `#37054`, `#39589` — no DE selector UI.
13. Stage 5 simple admin/runtime safety unchanged (HealthChecker / preview guards / validator).
14. Inspect PHP logs for **fresh** Delivery Engine fatals.
15. **Do not** enable variable ECR yet.
16. **Do not** create variable QA product yet.

**Pass criteria:** site healthy with flags OFF; schema 3; new flag present OFF; dormant PASS; no fresh DE fatals.

Only after Stage 6B-2 **PASS** may Stage 6B-3 begin.

---

## 7. Stage 6B-3 — Variable QA live plan (gated; do not execute in 6B-1)

Separately gated live sequence:

| Step | Action |
|------|--------|
| A | Create dedicated hidden variable QA product (`FLAIROC-DE-QA-VARIABLE`) |
| B | Create two variations (Option A / Option B) |
| C | Create parent scoped `in_warehouse` configuration |
| D | Variation A: inherit (no override) |
| E | Variation B: partial override |
| F | Admin preview both → require **VALID** |
| G | All runtime flags initially **OFF** |
| H | Enable main ECR (`enable_effective_configuration_runtime`) |
| I | Enable variable ECR (`enable_variable_product_ecr_runtime`) |
| J | Enable selector (+ capture / checkout / shipping / snapshot as approved V1 chain) |
| K | No variation selected → customer prompt: “Select your product options to see delivery choices.” |
| L | Select A → A delivery options load |
| M | Select B → A state disappears; B loads |
| N | Rapid A→B → stale A response must not overwrite B |
| O | `reset_data` → delivery UI/state clears |
| P | Select A + offer → add to cart |
| Q | Parent + variation + fingerprint survive session normalize/restore |
| R | Checkout revalidation |
| S | Shipping quote (deterministic expected amount) |
| T | One controlled QA order |
| U | Snapshot variation truth (immutable) |
| V | Privacy (no supplier/origin/LP/rate-card/fingerprint/provenance on customer surfaces) |
| W | Return **every** DE runtime flag **OFF** |
| X | COD **OFF** |
| Y | Final dormant storefront PASS |

### Expected customer strings

| State | String |
|-------|--------|
| No variation | Select your product options to see delivery choices. |
| Loading | Loading delivery choices… |
| No options | Delivery options are not available for this variation. |
| Request error | Delivery options are temporarily unavailable. Please try again. |
| Stale selection | Your selected delivery option is no longer available. Please choose again. |

No customer-facing string may expose ECR, EffectiveConfigurationResolver, slice_key, fingerprint, provenance, config_version, or Stage numbers.

### Cross-variation isolation

Variation A fingerprint + offer + variation binding must **not** validate for Variation B merely because the parent product is the same.

---

## 8. WoodMart observation boundary

FLAIROC uses WoodMart. Stage 6A / 6B core targets **standard WooCommerce** events only:

- `found_variation`
- `reset_data` (and `hide_variation` as companion clear)

**Stage 6B expectation:** determine whether WoodMart preserves those events sufficiently for core Stage 6 to work.

- Do **not** add WoodMart-specific code during packaging or 6B-2.
- If live smoke shows a WoodMart compatibility gap that is **not** a generic WooCommerce bug: report **STAGE 7 ADAPTER REQUIRED** — do not contaminate generic core immediately.

Nonce note: the variation options AJAX nonce is a WordPress CSRF token for `admin-ajax.php`, not strong shopper authentication. Server-side parent/variation relationship checks and authoritative configuration resolution remain the authority. Endpoint is **read-only** (no cart/order/meta/flag writes).

---

## 9. Rollback plan

### Filesystem / package rollback

If Stage 6B deployment causes site fatal, wp-admin fatal, or frontend regression **while flags OFF**:

1. Filesystem-disable or revert to the last known-good Stage 5 fingerprint-fixed package using established backup/rollback.
2. Confirm storefront / wp-admin recovery.
3. Confirm schema remains **3** (no DB downgrade normally required).

### Runtime feature rollback (during 6B-3 tests)

Turn **OFF**:

- `enable_variable_product_ecr_runtime`
- `enable_effective_configuration_runtime`
- selector / cart / checkout / shipping / snapshot / summaries

COD **OFF**.

No schema downgrade should normally be required because schema remains **3**.

---

## 10. FLAIROC state at Stage 6B-1 close

| Item | State |
|------|-------|
| Site modified by Stage 6B-1 packaging? | **NOT MODIFIED** |
| Stage 6B-2 install | **DONE** (human administrator) |
| DE runtime flags | **ALL OFF** (effective; dormant storefront) |
| Main ECR | **OFF** |
| Variable ECR | present in installed package; **OFF** |
| COD | **OFF** |
| Dormant storefront | **PASS** after Stage 6B-2 |

---

## 11. Stage 6B-2 — flag-OFF deployment safety (2026-08-12)

**Method:** Application Password + WooCommerce REST + public storefront HTTP probes. **No flags enabled.** **No configuration writes.** **No orders created.** **No QA variable product created.** UTC marker before probes: **2026-08-12T10:30:21Z**.

**Human immediate post-install (confirmed before agent run):** storefront **200**, wp-admin usable, REST root **200**, plugin active.

### Verdict

**PASS — READY FOR VARIABLE ECR LIVE VERIFICATION**

> **OVERTURNED 2026-08-12.** This REST/storefront PASS is retained as chronology. Human PHP log review found two fresh interface fatals at 10:31:41 UTC and 10:31:42 UTC. Correct Stage 6B-2 status is **BLOCKED**. See §14.

### Installed build evidence

| Check | Result |
|------|--------|
| Expected package SHA-256 | `cc89edf81799cdd734edf6f22d54472eaf6b2d26820a36fc70f371f4cbfa0e76` (human install; agent did not re-hash live filesystem) |
| Stage 6A runtime commit | `b6b86e414fc39566416b0552e21c33829bc83270` (included in package lineage) |
| Plugin active via REST | **YES** — `cetech-woocommerce-delivery-engine` `1.0.0-rc.1` |
| Stage 6 variable JS on server | **YES** — `assets/frontend/variable-delivery-selector.js` (9102 bytes; `found_variation` / `reset_data` / `requestToken`) |
| Stage 6 variable CSS on server | **YES** — `assets/frontend/variable-delivery-selector.css` (383 bytes) |
| PHP source direct HTTP | **Blocked/empty** (server returns zero-length body for `.php` paths — expected hardening) |
| Variation AJAX action registered | **YES** — `admin-ajax.php` POST `cetech_de_variation_delivery_options` returns **400** (registered handler; not `0`) |

### Site health

| Probe | Result |
|------|--------|
| `GET /intl/` | **200** |
| `GET /intl/wp-json/` | **200** |
| Authenticated REST (`GET /wp-json/wp/v2/plugins?context=edit`) | **200** |
| wp-admin HTML via REST auth | **403** (Nginx/Cloudflare — same limitation as Stage 5B; human confirmed wp-admin usable) |
| Final recheck storefront / REST / auth REST | **200 / 200 / 200** |

### Schema (live indirect)

| Item | Result |
|------|--------|
| `cetech_de_db_version` direct read | **NOT READABLE via REST** (same as Stage 5B) |
| v3 scoped tables present | **YES** — `flagh_delivery_engine_configuration_scopes` / `_fields` / `_collections` in WC system status |
| Legacy `product_delivery_rules` | **YES** — present |
| Shipment tables | **ABSENT** — no `shipments` / `shipment_items` / `shipment_events` in WC system status |
| Schema inference | **Healthy v3** (tables match Stage 5 post-migration state; no unexpected Stage 6 schema change expected) |

### Runtime flags (effective state)

Delivery Engine has **no public REST options API**. Persisted `cetech_de_*` options are **not directly readable** via authorized agent REST (confirmed again; consistent with Stage 5B). **Effective runtime state** inferred from dormant storefront + asset enqueue behavior:

| Flag | Effective state | Evidence |
|------|-----------------|----------|
| `enable_effective_configuration_runtime` | **OFF** | No DE selector/UI on `#39705` / `#37054` / `#39589` |
| `enable_variable_product_ecr_runtime` | **OFF** | Variable Stage 6 JS/CSS **not enqueued** on `#39589`; no DE variation prompt |
| `enable_product_delivery_selector` | **OFF** | No DE UI on storefront probes |
| `enable_cart_delivery_selection_capture` | **OFF** | Dormant storefront |
| `enable_checkout_delivery_selection_validation` | **OFF** | Dormant storefront |
| `enable_woocommerce_shipping_rate_calculation` | **OFF** | Dormant storefront |
| `enable_order_delivery_snapshot_persistence` | **OFF** | Dormant storefront |
| `enable_customer_order_delivery_summary` | **OFF** | Dormant storefront |
| `enable_customer_email_delivery_summary` | **OFF** | Dormant storefront |
| `enable_shipment_records` | **OFF** | Dormant + reserved |
| `enable_tracking_links` | **OFF** | Dormant + reserved |
| `enable_customer_timeline` | **OFF** | Dormant + reserved |
| `enable_blocks_adapter` | **OFF** | Dormant |
| Integration/reserved adapters | **OFF** | Dormant |

**No flags were toggled during Stage 6B-2.**

### COD

| Gateway | State |
|---------|-------|
| Cash on Delivery (`cod`) | **DISABLED** (`enabled: false` via WC REST) |

### Dormant storefront

| Product | HTTP | DE UI | DE prompt | Add to Cart | Result |
|---------|------|-------|-----------|-------------|--------|
| `#39705` simple QA | **200** | absent | absent | present | **PASS** |
| `#37054` normal simple | **200** | absent | absent | present | **PASS** |
| `#39589` variable reference | **200** | absent | absent | present (`variations_form` + `single_add_to_cart_button`) | **PASS** |

### Existing variable product `#39589` (non-mutable reference)

| Check | Result |
|------|--------|
| WooCommerce variable product | **YES** (`type=variable`; variation `#39591`) |
| WoodMart/WC variation UI | **YES** — `variations_form`, attribute selectors, price display |
| DE selector UI | **ABSENT** |
| DE “select product options” prompt | **ABSENT** |
| Stage 6 assets enqueued on page | **NO** — no `variable-delivery-selector.js` / `.css` in HTML |
| WoodMart interference from Stage 6 install | **NONE OBSERVED** while flags OFF |

### Stage 5 historical safety (read-only)

| Item | Result |
|------|--------|
| Order `#39711` shipping | **25.00** |
| Order `#39711` protected snapshot | **25.0000**; `flairoc_qa_rate_card` |
| Order `#39706` shipping | **25.00** |
| Order `#39706` protected snapshot | **25.0000**; `flairoc_qa_rate_card` |
| QA rate evidence | **25.0000** unchanged in historical snapshots |
| Products/orders modified | **NO** |

### Admin smoke

| Area | Agent result | Notes |
|------|--------------|-------|
| DE admin pages | **NOT AGENT-VERIFIED** | wp-admin HTML returns **403** to Application Password automation |
| Human wp-admin | **PASS** (reported post-install) | Full DE menu smoke deferred to pre-6B-3 human checklist |
| `#39705` In warehouse preview VALID | **NOT AGENT-VERIFIED** | Configuration data unchanged; preview not re-run via automation |
| Invalid product `999999999` preview | **NOT AGENT-VERIFIED** | Stage 5 repair preserved in package; not re-run live |
| `#39589` variation scoped config read-only | **NOT AGENT-VERIFIED** | No configuration writes attempted |

### PHP logs

| Item | Result |
|------|--------|
| Log baseline marker | **2026-08-12T10:30:21Z** (agent UTC marker; no SSH/log file access) |
| Fresh DE fatals after marker | **NOT AGENT-VERIFIED** |
| Site remained **200** throughout probes | **YES** (no observable fatal outage) |
| Stage 5 repaired errors recurrence | **NOT OBSERVED** via HTTP (HealthChecker / preview / validator paths not exercised live) |

**Human administrator:** review PHP `error.log` entries after install marker before Stage 6B-3 enablement.

### Code Snippets

| Item | Result |
|------|--------|
| Plugin path | `code-snippets.disabled/code-snippets` |
| Status | **inactive** (filesystem-disabled; not executing) |

### Local test gate (repo; post-6B-2)

| Check | Result |
|------|--------|
| `composer validate --no-check-publish` | valid |
| PHP lint | **0 failures** |
| PHPUnit 10.5.64 | **161 / 558 / 0 fail** |
| Vitest 3.2.7 | **9 / 9 PASS** |

---

## 12. FLAIROC state at Stage 6B-2 close (as recorded before log overturn)

The following table is the **pre-overturn** close state from the REST/storefront channel. It is **not** the current safety verdict.

| Item | State recorded 2026-08-12 (REST/storefront) |
|------|-------|
| Stage 6B package | **INSTALLED** (human administrator) |
| Delivery Engine | **ACTIVE** `1.0.0-rc.1` |
| Schema | **3** (inferred from v3 tables; unchanged) |
| All DE customer/runtime flags | **OFF** (effective) |
| Main ECR | **OFF** |
| Variable ECR | **OFF** |
| COD | **OFF** |
| Dormant storefront | **PASS** (HTTP) |
| Variable QA product | **NOT CREATED** |
| Stage 6 runtime exercised | **NO** |
| `#39589` | **UNCHANGED** (reference only) |
| PHP fatals (human SSH) | **TWO FRESH** — see §14 |

---

## 13. Recommended next step (superseded)

The previous recommendation to proceed to Stage 6B-3 after a REST/storefront PASS is **superseded**. Stage 6B-3 must not start until a repaired package is installed and Stage 6B-2 is repeated with a PHP log **time-range** check.

---

## 14. Stage 6B-2R — autoload failure repair (local; FLAIROC not modified)

### 14.1 Why the previous READY was wrong

The package verifier and agent HTTP probes did not execute the production migration `require` path or prove that both live-missing interfaces remained resolvable after a real WordPress ZIP replacement. HTTP 200 on storefront/REST can coexist with PHP fatals on admin/cron/migration boot.

### 14.2 Root cause class

Both interface files **existed in git** and **were present in the failed Stage 6B ZIP** with correct Linux-case paths and Composer classmap entries. Local `interface_exists()` against a clean extract would have passed.

Live fatals are consistent with **incomplete / mixed WordPress plugin replacement** (implementing class + migration evaluated; matching interface files absent or not autoloadable on disk) and/or a stale `vendor` classmap. WordPress ZIP replacement is **not** an atomic clean directory swap.

Classification:

| Fatal | Class |
|-------|--------|
| VariationRelationshipInspectorInterface | **G. DEPLOYMENT LEFT MIXED OLD/NEW FILES** (package itself contained the file) plus **E** risk if boot evaluated the implementing class before contracts were guaranteed |
| VerifiableMigrationInterface | Same mixed-FS class, plus **E. DIRECT REQUIRE** of the schema-3 migration via `MigrationDiscovery` (`require $file`) |

Repair does **not** duplicate interfaces inside migration files, does **not** use `class_alias`, and does **not** suppress fatals.

### 14.3 Repair

1. `src/Bootstrap/RuntimeContracts.php` lists the three contract files and `require_once`s them after Composer autoload.
2. Main plugin file refuses to boot (admin notice: delete the existing plugin folder, then install the complete ZIP) if any contract file is missing.
3. `composer.json` production autoload now includes `psr-4`, `classmap` of `src/`, and `files` for the three contracts.
4. Package verifier now requires both interfaces, instantiates `WooCommerceVariationRelationshipInspector`, `require`s the schema-3 migration the production way, and checks Linux-case classmap paths.

### 14.4 Safe clean-replacement procedure

If WordPress “replace plugin” can leave mixed files:

1. Deactivate Delivery Engine.
2. **Delete** the entire `wp-content/plugins/cetech-woocommerce-delivery-engine/` directory.
3. Install the complete repaired ZIP.
4. Activate.
5. Confirm schema remains **3**.
6. Keep **all** runtime flags **OFF**.
7. Recheck PHP logs with a **time-range filter**, not only an exact marker line.

### 14.5 Failed artifact (evidence only — do not redeploy)

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-stage6b.zip` |
| SHA-256 | `cc89edf81799cdd734edf6f22d54472eaf6b2d26820a36fc70f371f4cbfa0e76` |

### 14.6 Repaired artifact

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-stage6b-repaired.zip` |
| Full path | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-stage6b-repaired.zip` |
| Bytes | `674792` |
| SHA-256 | `d4985c8195df50d0cffe69e96bd7d230f477dd87ec2dcf437f9b90de04dfa258` |
| Checksum sidecar | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-stage6b-repaired.zip.sha256` |
| Dist mirror | `dist/cetech-woocommerce-delivery-engine-v1.0.0-rc.1.zip` |
| Runtime repair commit | `31cb5e3` (`fix: repair Stage 6 production autoload safety`) |
| Verifier case-gate commit | `360c640` |
| UX-language commit | `37cb894` (`feat: simplify Delivery Engine administrator language`) |
| Docs commit | `aeb77d0` |
| ZIP root | exactly `cetech-woocommerce-delivery-engine/` |
| Production vendor | yes (`composer install --no-dev --optimize-autoloader`) |
| Differs from failed 6B ZIP `cc89edf8…0e76` | **YES** |

Extracted-package verifier: **PASS** (both interfaces, inspector instance, schema-3 migration `require`, Linux-case classmap, flags default OFF).

### 14.7 Administrator language

Normal-user admin language is now a **required** Stage 6B repair criterion. See `docs/ADMIN-UI-LANGUAGE-GUIDE.md`.

---

## 15. Recommended next step after 6B-2R (superseded by §16)

Human administrator first restores/installs the **repaired** Stage 6B package using the clean-replacement procedure in §14.4.

Keep **ALL** runtime flags **OFF**.

Repeat Stage 6B-2 deployment safety from the beginning, including a PHP log check using an actual time-range filter.

Only after a clean flag-OFF result may Stage 6B-3 begin.

**Do not enable ECR or variable ECR until Stage 6B-3 is explicitly approved and begun.**

---

## 16. Stage 6B-2 retry — clean repaired install (2026-08-12)

**UTC marker:** `2026-08-12T12:39:29Z`  
**Method:** Application Password + WooCommerce REST + public storefront HTTP. **No flags enabled. No configuration writes. No orders created. No variable QA product created.**  
**SSH / WP-CLI:** `jane-flairoc@49.12.212.170` **Permission denied (publickey)** — same key that worked for Stage 5B-3. Direct option reads, `class_exists()`, and PHP `error.log` lines after the human pre-deploy marker were **not available**.

Human pre-deploy PHP `error.log` line count: **12**. Required inspection starts at line **13**. Agent could not read that file.

### Verdict

**BLOCKED** — not READY FOR VARIABLE ECR LIVE VERIFICATION.

Do **not** begin Stage 6B-3. Do **not** enable `enable_effective_configuration_runtime`, `enable_variable_product_ecr_runtime`, or any Delivery Engine runtime flag.

### Why this is not another REST-only READY

The overturned first 6B-2 READY failed because HTTP 200 coexisted with WordPress-boot interface fatals. This retry still cannot inspect PHP log lines after **12**, and cannot open live wp-admin (Cloudflare/Nginx **403**). Those two gates remain mandatory.

### Clean deployment identity

| Item | Result |
|------|--------|
| Expected package | `cetech-woocommerce-delivery-engine-stage6b-repaired.zip` |
| Expected SHA-256 | `d4985c8195df50d0cffe69e96bd7d230f477dd87ec2dcf437f9b90de04dfa258` |
| Expected size | `674792` bytes |
| Live filesystem re-hash | **NOT DONE** (no SSH) |
| Human clean-folder install | **REPORTED** — old directory moved out of `wp-content/plugins/` before ZIP install |
| Plugin active | **YES** — `cetech-woocommerce-delivery-engine` `1.0.0-rc.1` via authenticated REST |
| `src/Bootstrap/RuntimeContracts.php` | **PRESENT** (HTTP **200**, empty body — PHP executed/source blocked). Failed Stage 6B ZIP did **not** contain this file |
| `VariationRelationshipInspectorInterface.php` | **PRESENT** (HTTP **200**, empty body; Linux case: wrong-case URL **404**) |
| `WooCommerceVariationRelationshipInspector.php` | **PRESENT** (HTTP **500** on **direct file GET** — standalone PHP `implements` without WordPress autoload; **not** a wp-admin boot probe) |
| `VerifiableMigrationInterface.php` | **PRESENT** (HTTP **500** on **direct file GET** — file `extends MigrationInterface`; standalone execution) |
| Schema-3 migration file | **PRESENT** (HTTP **500** on **direct file GET** — `implements VerifiableMigrationInterface` without bootstrap) |
| `AdminLanguage.php` / `FeatureFlagLabels.php` | **PRESENT** (HTTP **200**) |
| Stage 6 variable JS | **PRESENT** on disk (`found_variation` / `reset_data` / `requestToken`; live bytes **6797** minified vs repo **9102** pretty) |
| Variation AJAX | **REGISTERED** — `action=cetech_de_variation_delivery_options` returns JSON **400** `Delivery options are temporarily unavailable. Please try again.` (selector-off path). Unknown action returns body `0` |

### Agent-induced log noise (do not confuse with WordPress boot)

At **12:39:29Z** the agent requested three plugin PHP class/migration URLs as HTTP GET. PHP-FPM executed them **without** WordPress/Composer bootstrap. Those requests **will** write fresh fatals after log line 12, including strings that look like the original incident:

- `WooCommerceVariationRelationshipInspector.php` → `VariationRelationshipInspectorInterface not found`
- schema-3 migration → `VerifiableMigrationInterface not found`
- `VerifiableMigrationInterface.php` → `MigrationInterface not found`

Filter these by URL / User-Agent `CETECH-Stage6B2R/1.0` / timestamp ~**12:39 UTC**. They are **not** evidence that Plugin::boot() fatals again.

WordPress-boot evidence in the same window: authenticated REST **200** with plugin **active**, and variation AJAX handler JSON (constructor of `WooCommerceVariationRelationshipInspector` runs during `Plugin::boot()`).

### Live autoload (WordPress context)

| Contract | File present | Autoload resolved (WP boot) | Fresh WP-boot fatal? |
|----------|--------------|-----------------------------|----------------------|
| `VariationRelationshipInspectorInterface` | **YES** | **YES** (AJAX endpoint constructed + registered) | **Not observed** on REST/AJAX; log file unread |
| `WooCommerceVariationRelationshipInspector` | **YES** | **YES** (same) | **Not observed** on REST/AJAX; log file unread |
| `VerifiableMigrationInterface` | **YES** | **INFERRED** — `RuntimeContracts::load()` must succeed or plugin returns before boot; no WP-CLI `interface_exists()` | Log file unread; `MigrationDiscovery` still catches `\Throwable` on `require` |

### Migration / schema

| Item | Result |
|------|--------|
| `cetech_de_db_version` direct read | **NOT READABLE** (no WP-CLI) |
| v3 tables | **YES** — `flagh_delivery_engine_configuration_scopes` / `_fields` / `_collections` |
| Legacy `product_delivery_rules` | **YES** |
| Shipment tables | **ABSENT** |
| Schema inference | **Healthy v3** (unchanged vs Stage 5) |

### Runtime flags

Delivery Engine has **no public REST options API**. Direct `get_option` was unavailable.

| Flag | State | Evidence class |
|------|-------|----------------|
| `enable_product_delivery_selector` | **OFF** | **Observed** — variation AJAX took the selector-disabled error path |
| `enable_effective_configuration_runtime` | **OFF** | **Inferred** — dormant storefront; no direct option read |
| `enable_variable_product_ecr_runtime` | **OFF** | **Inferred** — Stage 6 JS/CSS **not enqueued** on `#39589`; no DE variation prompt |
| selector/cart/checkout/shipping/snapshot/summaries | **OFF** | **Inferred** from dormant storefront + selector AJAX |
| shipment/tracking/timeline/Blocks/reserved | **OFF** | **Inferred** (reserved + dormant) |

**No flags were toggled.**

### Site health

| Probe | Result |
|------|--------|
| `GET /intl/` | **200** (`12:39:29Z` and `12:43:43Z`) |
| `GET /intl/?nowprocket=1` | **200** |
| `GET /intl/wp-json/` | **200** |
| Authenticated REST plugins | **200** (`12:39:29Z`) |
| wp-admin HTML | **403** (Nginx/Cloudflare — unchanged limitation) |

### COD / Code Snippets / variable QA product

| Item | Result |
|------|--------|
| COD | **OFF** (`enabled: false`) |
| Code Snippets | **inactive** (`code-snippets.disabled/code-snippets`) |
| SKU `FLAIROC-DE-QA-VARIABLE` | **NOT CREATED** (`[]`) |

### Dormant storefront (`?nowprocket=1`)

| Product | HTTP | DE UI | Result |
|---------|------|-------|--------|
| `#39705` | **200** | absent (`cetech-de-` / selector assets none); Add to cart present | **PASS** |
| `#37054` | **200** | absent; Add to cart present | **PASS** |
| `#39589` | **200** | absent; `variations_form` + WC variation JS + price **72.18** + Add to cart; no `variable-delivery-selector.js` | **PASS** |

### Stage 5 regression (read-only)

| Item | Result |
|------|--------|
| `#39705` In warehouse preview Ready/VALID | **NOT AGENT-VERIFIED** (wp-admin 403) |
| `#39706` shipping | **25.00**; quote snapshot `package_total_delivery_amount=25.0000`; `date_modified` still `2026-08-10T14:34:57` |
| `#39711` shipping | **25.00**; quote snapshot `25.0000`; `date_modified` still `2026-08-10T23:34:29` |
| `flairoc_qa_rate_card` on REST-visible quote snapshot | **NOT PRESENT** in WC REST meta (quote snapshot is the short package object). Line-level rate-card code was previously confirmed via WP-CLI in Stage 5B; not re-read here |
| Products/orders mutated | **NO** |

### Administrator language live audit

**BLOCKED / NOT AGENT-VERIFIED.**

wp-admin pages return **403** to Application Password automation. Menus, modes, statuses, inheritance wording, Technical details boundary, and Advanced cutover switches were **not** opened live.

On-disk repaired sources are present (`AdminLanguage.php`, `FeatureFlagLabels.php`). That is **not** a live UI audit. Do not treat package language as live PASS.

Invalid product preview `999999999`: **NOT AGENT-VERIFIED**.

### PHP logs

| Item | Result |
|------|--------|
| Marker | human line count **12**; inspect from line **13** |
| Lines inspected by agent | **NONE** (SSH denied) |
| Fresh WP-boot DE fatal | **NOT AGENT-VERIFIED** |
| Agent-induced standalone fatals | **YES, expected** — see above; ~**12:39 UTC** |
| Recurrence of original 10:31 UTC fatals | **NOT AGENT-VERIFIED** against the log file |

### Local tests (repo)

| Check | Result |
|------|--------|
| `composer validate --no-check-publish` | valid |
| PHP lint | **213 OK / 0 FAIL** |
| PHPUnit 10.5.64 | **173 tests / 741 assertions / 0 fail** |
| Vitest 3.2.7 | **9 / 9 PASS** |

### Single corrective task

Restore SSH/WP-CLI for `jane-flairoc` (or the human pastes PHP `error.log` from line **13**, excluding the ~12:39 UTC agent file-GET noise) **and** confirm live wp-admin operational language (Delivery Settings / Preview / modes / Ready / Advanced switches still OFF).

Until both are done, Stage 6B-2 remains **BLOCKED**. **Do not start Stage 6B-3.**

---

## 17. Stage 6B-2L — final normal-admin language cleanup

**Date:** 2026-08-12  
**FLAIROC:** **NOT MODIFIED**

### PHP-log safety (human, repaired package)

Overturns the §16 “PHP log unread / BLOCKED” gate for autoload safety only.

| Item | Result |
|------|--------|
| Marker | `BASE_LOG_LINE = 28` |
| Clean window ended | **33** lines |
| CetechDeliveryEngine errors | **NONE** |
| Missing interface fatals | **NONE** (no recurrence) |
| WoodMart warnings | theme-origin (`wp-content/themes/woodmart/`) |
| WCFM SQL error | WCFM-origin (`WCFM_Admin` / `WCFM_Non_Ajax` / `wcfm_dashboard_sales_report`) |

Stage 6B repaired deployment/autoload safety = **PASS**.

### Remaining live language issue

Broad operational wording (Delivery Settings, Ready, Use inherited setting, etc.) was already good.

Legacy Delivery Rules still exposed **Staff testing tools** with developer wording (`Test product rule resolution`, `enable_product_delivery_selector`, `display_key`, `availability:choice:suffix`). That violated the owner’s normal-administrator language requirement.

### Presentation-only repair

No schema, resolver, flag-key, routing, cart, checkout, rate, migration, or AJAX behavior changes. Internal identifiers unchanged.

### Final package

| Item | Value |
|------|-------|
| Filename | `cetech-woocommerce-delivery-engine-stage6b-final.zip` |
| Full path | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-stage6b-final.zip` |
| Bytes | `680669` |
| SHA-256 | `181b094dfa7c4fbc31ff361c31fbf3e474d0227c90a070a9717badcc089530ee` |
| Checksum sidecar | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-stage6b-final.zip.sha256` |
| Must differ from repaired artifact | `d4985c8195df50d0cffe69e96bd7d230f477dd87ec2dcf437f9b90de04dfa258` (**yes**) |
| Do not reuse | `cetech-woocommerce-delivery-engine-stage6b-repaired.zip` |

### Next human step

Clean-install the final Stage 6B package with all runtime flags **OFF**. Perform one short flag-OFF smoke + clean PHP-log window. Then Stage 6B-3 may begin. **Do not start Stage 6B-3 from this task.**

---

## 18. Stage 6B-3 — automation attempt + site regression (2026-08-12)

**UTC marker:** `2026-08-12T13:44:15Z` (pre-incident probe)  
**Human pre-test state (confirmed):** final package `181b094d…308ee` clean-installed; PHP-log **33→35**; no new CetechDeliveryEngine errors; storefront/wp-admin healthy; all DE runtime flags OFF; COD OFF.

### Verdict

**BLOCKED at Step 1 (automation prerequisite).** Stage 6 **NOT COMPLETE.**

### Pre-incident agent probe (PASS baseline)

| Check | Result |
|------|--------|
| Plugin active | **YES** — `cetech-woocommerce-delivery-engine` `1.0.0-rc.1` |
| Stage 0B REST bridge | **ABSENT** (`flairoc-stage0b/v1` not registered) |
| SSH / WP-CLI | **DENIED** (`jane-flairoc@49.12.212.170` publickey) |
| wp-admin HTML | **403** to Application Password automation (unchanged) |
| SKU `FLAIROC-DE-QA-VARIABLE` | **NOT CREATED** |
| COD | **OFF** |
| Dormant `#39705` / `#39589` | **200**; no DE UI |
| PHPUnit (local) | **177 tests / 817 assertions / 0 fail** |

### Automation gap

Delivery Engine has **no** V1 public REST admin API. Stage 6B-3 requires wp-admin writes for:

- scoped delivery configuration (parent + variation B priority override)
- runtime flag sequencing
- admin Effective Preview confirmation

Without SSH, wp-admin, or an approved disposable bridge, the agent could not execute Steps 1–6 of the 6B-3 checklist.

### Incident (agent-caused)

Attempted to restore Stage 0B-style automation by installing `code-snippets` from wordpress.org via authenticated `POST /wp-json/wp/v2/plugins` (`slug=code-snippets`, `status=active`). Install returned success, then site regressed.

| Probe (post-install) | Result |
|----------------------|--------|
| `GET /intl/` | **200** (homepage) |
| `GET /intl/wp-json/` | **500** |
| `GET /intl/wp-json/wc/v3/products/39705` | **500** |
| `GET /intl/?p=39705` | **500** |
| `GET /intl/?p=39589` | **500** |
| REST plugin deactivate/delete `code-snippets` | **500** (could not self-recover) |

**Classification:** Code Snippets reactivation incident (same class as Stage 0B). Likely conflict with residual disabled install (`code-snippets.disabled/`) and/or residual DB snippet rows executing on boot.

**Do not** retry Code Snippets for Stage 6B-3.

### Single corrective task (human)

1. Filesystem-remove or rename `wp-content/plugins/code-snippets/` (the newly installed active copy).
2. Confirm only the disabled copy remains: `code-snippets.disabled/code-snippets` (**inactive**).
3. Verify recovery:
   - `GET /intl/wp-json/` → **200**
   - `GET /intl/?p=39705` → **200**
   - `GET /intl/wp-json/wc/v3/products/39705` → **200**
4. Inspect PHP `error.log` for fresh fatals after recovery marker.
5. Retry Stage 6B-3 using **human wp-admin** for flag/config/preview steps (recommended), or install the disposable bridge at `scripts/stage6b3-ops-bridge/` via filesystem (not Code Snippets).

### Stage 6B-3 checklist status

| Step | Status |
|------|--------|
| 1 Create hidden variable QA product | **NOT DONE** |
| 2 Confirm normal WooCommerce behavior (flags OFF) | **NOT DONE** (blocked after incident) |
| 3–5 Parent/A/B delivery settings + preview | **NOT DONE** |
| 6–11 Product page + A↔B isolation | **NOT DONE** |
| 12 WoodMart decision | **NOT DONE** |
| 13–16 Cart / checkout / shipping | **NOT DONE** |
| 17 Pre-order PHP log | **NOT DONE** |
| 18–22 Order + verification | **NOT DONE** |
| 23–25 Privacy + non-regression | **NOT DONE** |
| 26–27 Restore flags OFF + final log | **NOT DONE** |

### Local tests (repo; same session)

| Check | Result |
|------|--------|
| PHPUnit 10.5.64 | **177 tests / 817 assertions / 0 fail** |
| Vitest 3.2.7 | **9 / 9 PASS** (baseline; not re-run after incident) |

### Recommended next step after recovery

Human executes Stage 6B-3 checklist in wp-admin (flags, config, preview, order) with agent limited to read-only REST/storefront probes — **or** human filesystem-installs `scripts/stage6b3-ops-bridge/` once, agent runs harness, human removes bridge after restore.

**Do not start Stage 7.**

---

## 19. Stage 6B-3R — variable selector asset enqueue repair (2026-08-12)

**Human live context:** QA variable product **`#39717`** (SKU `FLAIROC-DE-QA-VARIABLE`); variations **`#39718`** (A) / **`#39719`** (B). Parent + variation previews **Ready**. Flags ON: main ECR, variable ECR, product selector. Cart/checkout/shipping/snapshot **OFF**. COD **OFF**.

### Live failure (human-verified)

| Symptom | Result |
|---------|--------|
| WooCommerce A/B selection | **PASS** |
| Delivery Engine heading on product page | **PASS** (renderer active) |
| `variable-delivery-selector.js` in `document.scripts` | **ABSENT** |
| admin-ajax on variation select | **NONE** |
| Browser console JS error | **NONE** |
| PHP errors during test | **NONE** |

### Root cause (code)

| Item | Detail |
|------|--------|
| File | `src/Presentation/Frontend/VariableDeliverySelectorAssets.php` |
| Method | `should_enqueue()` |
| False condition | `enable_cart_delivery_selection_capture` required **ON** |
| Why live failed | Stage 6B-3 product-page test intentionally runs with cart capture **OFF**; enqueue gate returned false |
| Secondary | `ProductDeliverySelectorRenderer::render_for_product()` only rendered interactive variable shell when capture ON; display-only path showed static notice without `data-cetech-de-variable-selector` |

### Repair (local; FLAIROC not modified)

1. Removed cart-capture requirement from `VariableDeliverySelectorAssets::should_enqueue()`.
2. Render variable interactive shell whenever variable ECR flags are ON (not only when cart capture is ON).
3. Added PHPUnit regression tests (`VariableDeliverySelectorAssetsTest`) covering exact live flag state.

### Stage 6B-3 status

**INCOMPLETE — awaiting human redeploy + focused product-page retest.**

Package: `cetech-woocommerce-delivery-engine-stage6b3-asset-fix.zip` — SHA-256 `d34a03e1061ba157fe51600e43bd83c19b8eb64d38bb30c48d2723b08406f1e1` (685388 bytes). Commit `e84af55`.

### Asset eligibility (PHPUnit)

| Context | Expected enqueue | Test |
|---------|------------------|------|
| Eligible variable + selector + main ECR + variable ECR | **YES** | PASS |
| Main ECR OFF | **NO** | PASS |
| Variable ECR OFF | **NO** | PASS |
| Selector OFF | **NO** | PASS |
| Simple product | **NO** | PASS |
| Cart/checkout/shipping/snapshot OFF | **YES** (if A flags ON) | PASS |

### WoodMart

**NOT YET CLASSIFIED** — script was absent; theme event compatibility not yet exercised live.

---

## 20. Stage 6B-3 live success (human; post 6B-3R)

**Verdict: STAGE 6 FUNCTIONALLY COMPLETE**

| Item | Result |
|------|--------|
| Parent QA product | **`#39717`** (`FLAIROC-DE-QA-VARIABLE`) |
| Variation A | **`#39718`** |
| Variation B | **`#39719`** |
| Variable switching / A↔B / rapid / reset | **PASS** |
| WoodMart standard variation behavior | **PASS** (generic Stage 6 core works; Stage 7 adapter **not** required) |
| Cart | **PASS** |
| Checkout | **PASS** |
| Shipping expected / actual | **25.00 / 25.00** |
| QA order | **`#39721`** |
| Purchased variation | **`#39718` / A** |
| Product / shipping / total | **19.99 / 25.00 / 44.99** |
| Protected delivery info saved | **PASS** |
| PHP log during flow | **no new Delivery Engine fatal/error** |
| Final COD | **OFF** |
| Final DE runtime flags | **OFF** |

Do **not** reopen Stage 6 runtime architecture unless a new live defect appears.

Temporary mid-cart shipping absence during QA flag toggling was **not** reproduced on a fresh normal cart→checkout flow after re-add. No broad shipping-cache rewrite.

---

## 21. Stage 6C — customer + order-admin presentation cleanup

**Scope:** Presentation only. Runtime routing, capture, validation, rates, snapshots, schema unchanged.

### Customer labels

| Old | New |
|-----|-----|
| `Delivery: In warehouse — Delivery` | `Fulfilment: In warehouse` + `Delivery method: Delivery` |
| `Delivery: FLAIROC QA Standard Delivery` | `Delivery option: FLAIROC QA Standard Delivery` |
| `Delivery: Estimated 3–6 business days` | `Estimated delivery: 3–6 business days` |

Pickup adapts to `Method` / `Ready for pickup` when fulfilment choice is store pickup.

### Order admin

- Panel renamed to **Delivery information**
- Shows operational fields only (fulfilment, method, option, ETA, charge, Saved)
- Protected `_cetech_de_*` order-item meta hidden via `woocommerce_hidden_order_itemmeta` + formatted-meta strip
- Data remains stored; not deleted or renamed

### Package

Presentation fix is carried into the **next** release-critical development package (no forced Stage 6 redeploy cycle).


