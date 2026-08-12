# Stage 6B — FLAIROC Variable-Product ECR Verification

**Document status:** Living Stage 6B packaging + deployment plan  
**Plugin version:** `1.0.0-rc.1` (unchanged public version)  
**Schema target:** `3` (unchanged)  
**Date opened:** 2026-08-11  
**Last updated:** 2026-08-12 (Stage 6B-2 **OVERTURNED** to BLOCKED; Stage 6B-2R local autoload + admin-language repair)

---

## 1. Current verdict

**Stage 6B-2: BLOCKED** (overturned after human PHP log review)

The 2026-08-12 agent REST/storefront check recorded **READY**. That verdict is **wrong** and is preserved below as chronology only.

Human SSH log review found **two fresh PHP fatals** immediately after the Stage 6B package install (marker `2026-08-12T10:30:21Z`):

| Fatal | Timestamp | Missing interface | While loading |
|-------|-----------|-------------------|---------------|
| #1 | 12-Aug-2026 10:31:41 UTC | `CetechDeliveryEngine\Application\Runtime\VariationRelationshipInspectorInterface` | `src/Application/Runtime/WooCommerceVariationRelationshipInspector.php` line 12 |
| #2 | 12-Aug-2026 10:31:42 UTC | `CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface` | `database/migrations/20260810160000_create_scoped_configuration_tables.php` line 19 |

These are **fresh Stage 6 deployment failures**, not historical Stage 5 errors.

**Do not perform Stage 6B-3. Do not enable** `enable_effective_configuration_runtime`, `enable_variable_product_ecr_runtime`, or any Delivery Engine runtime flag.

The human administrator may restore the known-good Stage 5 fingerprint-fixed package for production safety:

- `cetech-woocommerce-delivery-engine-stage5b-fingerprint-fixed.zip`
- SHA-256 `dbddc1d7df3c1262296e9c42b05e87921066fc6f5f29b0f3c798385ad1d4cbfd`
- Schema remains **3** (no downgrade expected)

This Cursor repair task **does not modify FLAIROC**.

| Sub-stage | Status |
|-----------|--------|
| Stage 6A | **COMPLETE** |
| Stage 6B-1 original package | **FAILED deployment safety** — `cetech-woocommerce-delivery-engine-stage6b.zip` SHA-256 `cc89edf81799cdd734edf6f22d54472eaf6b2d26820a36fc70f371f4cbfa0e76` — **do not redeploy** |
| Stage 6B-2 flag-OFF install | **BLOCKED** (HTTP looked healthy; PHP fatals in logs) |
| Stage 6B-2R local repair | **this document** — repaired package + admin language + verifier gates |
| Stage 6B-3 variable QA live cutover | **NOT STARTED** |

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

Recorded after the local 6B-2R package build (see closing package identity in the Stage 6B-2R report / this section after SHA-256 is known).

Target filename: `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-stage6b-repaired.zip`

### 14.7 Administrator language

Normal-user admin language is now a **required** Stage 6B repair criterion. See `docs/ADMIN-UI-LANGUAGE-GUIDE.md`.

---

## 15. Recommended next step after 6B-2R

Human administrator first restores/installs the **repaired** Stage 6B package using the clean-replacement procedure in §14.4.

Keep **ALL** runtime flags **OFF**.

Repeat Stage 6B-2 deployment safety from the beginning, including a PHP log check using an actual time-range filter.

Only after a clean flag-OFF result may Stage 6B-3 begin.

**Do not enable ECR or variable ECR until Stage 6B-3 is explicitly approved and begun.**
