# Stage 6B — FLAIROC Variable-Product ECR Verification

**Document status:** Living Stage 6B packaging + deployment plan  
**Plugin version:** `1.0.0-rc.1` (unchanged public version)  
**Schema target:** `3` (unchanged)  
**Date opened:** 2026-08-11  
**Last updated:** 2026-08-11 (Stage 6B-1 package ready — **FLAIROC NOT MODIFIED**)

---

## 1. Current verdict

**Stage 6B-1: READY FOR FLAIROC DEPLOYMENT**

Production Stage 6B ZIP built and verified locally. FLAIROC has **not** been modified. All Delivery Engine runtime flags remain **OFF**. COD remains **OFF**. Do **not** enable ECR or variable ECR until Stage 6B-2 passes.

| Sub-stage | Status |
|-----------|--------|
| Stage 6B-1 package + plan | **READY** |
| Stage 6B-2 flag-OFF deployment safety | **NOT STARTED** (human install only) |
| Stage 6B-3 variable QA live cutover | **NOT STARTED** (gated on 6B-2) |

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
| Site modified by this stage? | **NOT MODIFIED** |
| DE runtime flags | **ALL OFF** (assumed continuing Stage 5B-3R-2 restore) |
| Main ECR | **OFF** |
| Variable ECR | does not yet exist on live Stage 5 package; package adds it **default OFF** |
| COD | **OFF** |
| Dormant storefront | Stage 5 final PASS preserved until 6B-2 |

---

## 11. Recommended next step

Human administrator may install `cetech-woocommerce-delivery-engine-stage6b.zip` and execute **Stage 6B-2 only**.

After install: **DO NOT** enable ECR or variable ECR until 6B-2 safety verification passes.
