# CETECH WooCommerce Delivery Engine — Post-RC Baseline Verification

## Date

2026-08-10

## Repository baseline

| Item | Value |
|------|-------|
| Repository root | `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-woocommerce-delivery-engine` |
| Branch | `master` |
| HEAD | `d68d2af64bca5ee42dd3e2cd2fed28e93a208a54` — *V1 RC: document current implementation status in AI handoff* |
| Runtime / code baseline commit | `8951adb677919e39a5143d31aeae1bcd9673e04a` — *V1 RC: add guarded admin delete actions* |
| Diff `8951adb..HEAD` (runtime) | **None** — only `docs/AI-HANDOFF.md` changed |
| Remote | `origin` → tracks `origin/master` |
| Ahead / behind | `0 / 0` (in sync) |
| Working tree at verification start | Dirty with **known untracked docs/governance only** (no unknown runtime code edits): `.cursor/rules/project-governance.mdc`, `docs/PROJECT-GOVERNANCE.md`, `docs/Delivery Shipping Plugin Up-To-Date Design and Expectations.md` |
| `vendor/` | Present (gitignored); Composer PSR-4 maps `CetechDeliveryEngine\` → `src/` |
| `dist/` | Present (gitignored); contains `cetech-woocommerce-delivery-engine-v1.0.0-rc.1.zip` (+ sha256) dated 2026-07-06 |

**Material runtime drift from historical `1.0.0-rc.1` / `8951adb`:** None. HEAD is documentation-only beyond that commit.

## Plugin identity

| Property | Actual value |
|----------|--------------|
| Plugin name | CETECH WooCommerce Delivery Engine |
| Version | `1.0.0-rc.1` (`CETECH_DE_VERSION` + plugin header + `readme.txt` Stable tag) |
| Slug / text domain | `cetech-woocommerce-delivery-engine` |
| Namespace | `CetechDeliveryEngine\` |
| PHP minimum | 8.1 |
| WooCommerce | Hard dependency; header `WC requires at least: 8.0`, `WC tested up to: 10.9` |
| HPOS | Declared via `FeaturesCompatibility::declare_compatibility('custom_order_tables', …, true)` |
| Schema option | `cetech_de_db_version` |
| Schema target | `2` (`SchemaVersion::TARGET`) |
| Composer | `composer.json` valid; optimized autoload generated (132 classes) |

## Current implemented scope

V1 RC supports a **feature-flagged** simple-product path:

```text
Product delivery rule
→ eligible Delivery Offers
→ product-page selector (public-safe)
→ Add to Cart capture + cart/session persistence
→ cart revalidation (warn; no silent replace)
→ classic checkout validation
→ destination zone + rate-card quote
→ WC shipping method `delivery_engine_selected_offer` (label “Delivery”)
→ protected order/item delivery snapshots (HPOS CRUD)
→ admin order snapshot meta box
→ customer Thank You / My Account summary
→ customer email summary
```

Admin configuration CRUD for offers, zones/rules, logistics profiles, suppliers, origins, pickup locations, rate cards, audit log, and product delivery rules is implemented.

**Not implemented:** shipment tables/runtime, tracking, customer timeline, Blocks adapter wiring, variable-product capture, real optional-integration adapters (WPML/WCML/WoodMart/WCFM/VitePOS), `EffectiveConfigurationResolver`, inheritance redesign.

## Current database tables

Option key: `cetech_de_db_version` → target **`2`**.

Migrations (idempotent `dbDelta` + `VerifiableMigrationInterface`; retry-safe skip when `version <= current`; failed migrations do not bump version):

| Migration | Version | Tables |
|-----------|---------|--------|
| `20260705160000_create_configuration_tables` | `1` | `delivery_offers`, `destination_zones`, `destination_rules`, `logistics_profiles`, `suppliers`, `origins`, `pickup_locations`, `rate_cards`, `rate_card_rules`, `audit_log` |
| `20260705170000_create_product_delivery_rules_table` | `2` | `product_delivery_rules` |

All use prefix `{$wpdb->prefix}delivery_engine_*` via `TableNames`.

**Confirmed absent from schema code:** `shipments`, `shipment_items`, `shipment_events`.

**Activation:** capabilities + feature-flag defaults + migrations (`Activator`).  
**Deactivation:** does **not** delete data (`Deactivator` flushes rewrites / notice transient only).  
**Uninstall:** data retained unless `cetech_de_delete_data_on_uninstall` is explicitly enabled.

Indexes: primary keys, unique `internal_code` where applicable, status/route/zone/target lookup keys as defined in migration SQL (verified in migration files).

## Feature flags

Source: `src/Bootstrap/FeatureFlags.php` (`cetech_de_<flag>` in `wp_options`).

| Flag | Default | Runtime implemented? | Notes |
|------|---------|----------------------|-------|
| `enable_product_delivery_selector` | false | Yes | Product-page selector |
| `enable_cart_delivery_selection_capture` | false | Yes | Requires selector; simple products |
| `enable_checkout_delivery_selection_validation` | false | Yes | Classic `woocommerce_after_checkout_validation` |
| `enable_woocommerce_shipping_rate_calculation` | false | Yes | Requires upstream selector+capture+checkout flags |
| `enable_order_delivery_snapshot_persistence` | false | Yes | Requires shipping runtime chain |
| `enable_customer_order_delivery_summary` | false | Yes | Read-only thank-you / My Account |
| `enable_customer_email_delivery_summary` | false | Yes | Customer emails only |
| `enable_shipment_records` | false | No (reserved) | No runtime wiring |
| `enable_tracking_links` | false | No (reserved) | No runtime wiring |
| `enable_customer_timeline` | false | No (reserved) | No runtime wiring |
| `enable_blocks_adapter` | false | No | Flag only; no Blocks adapter classes |
| `enable_classic_checkout_adapter` | **true** | Placeholder | Settings/display only; classic path is de facto |
| `enable_wpml_adapter` | false | Detection/stub only | `NullIntegration` |
| `enable_wcml_adapter` | false | Detection/stub only | |
| `enable_woodmart_adapter` | false | Detection/stub only | |
| `enable_wcfm_adapter` | false | Detection/stub only | |
| `enable_vitepos_adapter` | false | Detection/stub only | |
| `enable_bulk_import` | false | Not a storefront takeover | |
| `enable_category_rules` | false | Partial / settings | Resolver still walks categories; flag not a hard gate in resolver |
| `enable_site_fallback_rule` | false | Not fully wired as resolver fallback | |
| `demo_data_on_activation` | false | Off | No demo seeding observed on activation |

**Storefront/runtime-impacting flags default OFF** (except the non-operative classic-checkout placeholder default **true**). Activation calls `ensure_defaults()` and does not enable customer runtime flags.

### Drift vs `docs/V1-RC-FLAG-MATRIX.md`

- Flag matrix header still says **Version: 0.1.0**; plugin is **`1.0.0-rc.1`**.
- Matrix flag list otherwise matches code defaults for V1 runtime/reserved flags.

## Runtime pipeline map (code)

| Step | Principal class(es) | Hooks / filters | Persistence | Flag |
|------|---------------------|-----------------|-------------|------|
| Product rule | `ProductDeliveryRuleResolver` | (called by consumers) | `delivery_engine_product_delivery_rules` | — |
| Eligible offers | `ProductDeliveryOptionsBuilder` | — | `delivery_engine_delivery_offers` | via selector |
| Product selector | `ProductDeliverySelectorRenderer` | `woocommerce_single_product_summary` / before ATC | none | `enable_product_delivery_selector` |
| ATC capture | `CartDeliverySelectionCapture`, `ProductDeliverySelectionValidator` | `woocommerce_add_to_cart_validation` | POST key only | capture + selector |
| Cart/session | same + fingerprint/session helpers | `woocommerce_add_cart_item_data`, `get_cart_item_from_session`, `get_item_data` | WC cart/session keys `cetech_de_delivery_selection*` | capture + selector |
| Cart revalidation | `CartDeliverySelectionRevalidator` | `woocommerce_before_cart` | read-only | capture + selector |
| Checkout validation | `CheckoutDeliverySelectionValidator` | `woocommerce_after_checkout_validation` | none | checkout + upstream |
| Destination/rate | `PackageDestinationZoneResolver`, `RateQuoteEngine` | via shipping | zones/rules/rate cards | via shipping |
| WC shipping | `SelectedOfferShippingMethod`, `SelectedOfferShippingRateCalculator`, `ShippingRateCalculationGate` | `woocommerce_shipping_methods`, method `calculate_shipping` | WC rates | `enable_woocommerce_shipping_rate_calculation` + upstream |
| Order snapshot | `OrderDeliverySnapshotPersister`, `OrderDeliverySnapshotBuilder`, `OrderDeliverySnapshotGate` | `woocommerce_checkout_create_order_line_item`, `woocommerce_checkout_order_created` | `_cetech_de_*` meta | snapshot + shipping chain |
| Admin order | `OrderDeliverySnapshotAdminDisplay` | `add_meta_boxes` | read meta | always registered in admin when WC active |
| Thank You / My Account | `CustomerOrderDeliverySummaryBuilder` / `Renderer` | `woocommerce_order_details_after_order_table` | read meta | `enable_customer_order_delivery_summary` |
| Email | `CustomerOrderDeliveryEmailSummaryRenderer` | `woocommerce_email_after_order_table` | read meta | `enable_customer_email_delivery_summary` |

**Automated tests covering pipeline:** none in repository.

## Automated verification

| Test / check | Command | Result |
|--------------|---------|--------|
| Composer validate | `composer validate --no-check-publish` | **PASS** (exit 0; `composer.json` valid) |
| Composer dump-autoload | `composer dump-autoload -o` | **PASS** (exit 0; 132 classes) |
| PHP syntax lint (root) | `php -l` on bootstrap, uninstall, FeatureFlags | **PASS** |
| PHP syntax lint (tree) | `php -l` on all `src/**/*.php` + `database/**/*.php` | **PASS** (133 OK / 0 FAIL) |
| PHPUnit | (none configured) | **NOT APPLICABLE** — no `tests/`, no `phpunit.xml`, no Composer test scripts |
| PHPCS / PHPStan / Psalm | (none configured) | **NOT APPLICABLE** |
| CI workflows | (none) | **NOT APPLICABLE** — no `.github/` workflows |
| JS lint/build | (none) | **NOT APPLICABLE** — no `package.json` frontend test suite |
| Packaging script version default | `scripts/build-v1-rc-package.ps1` | **PASS** — default `-Version 1.0.0-rc.1` |

## Staging environment

| Item | Result |
|------|--------|
| Documented staging URL in tracked docs | **Not present** (generic “staging” only) |
| Local `.env.local` (gitignored) | Present; keys include `WP_SITE_URL`, admin user, app password, WC API keys (**secrets not recorded here**) |
| Resolved site URL from local env | `https://training.cetechbpa.com` |
| Production distinction | Hostname `training.*` is consistent with historical staging naming; **live site identity could not be confirmed** because HTTPS failed |
| Reachability | DNS resolves (Cloudflare). HTTPS returns **HTTP 525** from Cloudflare (origin SSL handshake failure) for both normal and insecure client probes |
| Plugin version / WC / HPOS / theme / flags / DB version on staging | **NOT VERIFIED** — site unreachable |
| Test product `#30118` | Referenced historically in agent notes only; **not in tracked docs**; unreachable |

**No staging configuration flags were changed** (site unreachable).

## Smoke-test results

Authoritative checklist: `docs/V1-RC-SMOKE-TEST-CHECKLIST.md`.

All live storefront/admin/order/email rows are **BLOCKED** by staging HTTPS 525. Code-only rows that do not require staging are noted.

| Checklist section | Result | Notes / evidence |
|-------------------|--------|------------------|
| Baseline — all flags off (activate, no storefront takeover, no rates/snapshots/summaries) | **BLOCKED** | Cannot reach staging admin/storefront |
| Selector only | **BLOCKED** | |
| Selector + capture | **BLOCKED** | |
| Cart restore / revalidation | **BLOCKED** | |
| Checkout validation | **BLOCKED** | |
| Shipping method + rate card | **BLOCKED** | |
| Missing rate card → no free shipping | **BLOCKED** (runtime); code path reviewed → calculator returns `blocked`, method does not `add_rate` on failure | Code supports expected behaviour; not live-proven |
| Unresolved destination → no rate | **BLOCKED** (runtime); code: `BLOCK_DESTINATION_UNRESOLVED` | Same |
| Order snapshot persistence | **BLOCKED** | |
| Admin order snapshot display | **BLOCKED** | |
| Customer order summary | **BLOCKED** | |
| Customer email summary | **BLOCKED** | |
| HPOS smoke test | **BLOCKED** | Code declares HPOS compatibility |
| Privacy scan | **BLOCKED** | Customer renderers filter private fields in code |
| System Status / diagnostics | **BLOCKED** | |
| Sign-off | **Fail / incomplete** | Staging unavailable |

**Counts:** PASS `0` · FAIL `0` · BLOCKED `all executable staging rows` · NOT APPLICABLE `automated PHPUnit suite (absent by design of current repo)`

## Hard-invariant verification

| Invariant | Verdict | Evidence |
|-----------|---------|----------|
| **A. Server authority** | **PASS** | Capture/validator re-resolve options server-side; shipping uses `RateQuoteEngine`; no trusted client price path found |
| **B. No silent Delivery Offer replacement** | **PASS** | Revalidator warns; checkout blocks; shipping/snapshot skip/block stale — no auto-swap to another offer |
| **C. No accidental free shipping** | **PASS** | Missing rate / unresolved zone / quote failure → `SelectedOfferShippingRateResult::blocked` → shipping method returns without `add_rate`. Note: admin may intentionally configure `base_amount >= 0` (including $0); that is configured free shipping, not silent fallback on missing config |
| **D. Customer privacy** | **PARTIAL** | Customer summary/email/shipping label omit supplier/origin/LP/rate-card IDs/hashes. Residual: product selector display keys encode offer ID suffix in HTML/POST; protected cart intent + snapshot JSON retain internal IDs for ops (underscore meta / session) |
| **E. HPOS** | **PASS** | `FeaturesCompatibility` declares `custom_order_tables`; snapshot read/write use WC order CRUD |
| **F. Protected snapshot metadata** | **PARTIAL** | Keys use `_cetech_de_*` convention (`OrderDeliverySnapshot` constants). No plugin registration of `is_protected_meta` / `woocommerce_hidden_order_itemmeta` found |
| **G. Feature-flag safety** | **PASS** | Runtime customer flags default false; activation ensures defaults; shipping/snapshot gated on upstream chain |

## Confirmed limitations

| Limitation | Status |
|------------|--------|
| 1. Variable-product delivery capture incomplete | **Confirmed** — selector notice; capture excludes variable |
| 2. Mixed-cart line quotes may diverge from WC shipping-line totals | **Confirmed** — per-line quotes summed into package rate; known RC note |
| 3. Classic checkout is supported path | **Confirmed** |
| 4. Blocks adapter not fully wired | **Confirmed** — flag off, no adapter classes |
| 5. Shipment tables/runtime absent | **Confirmed** |
| 6. Tracking / customer timeline absent | **Confirmed** |
| 7. Optional real WPML/WCML/WCFM/VitePOS/WoodMart adapters not implemented | **Confirmed** — detection / Null stubs |
| 8. Simple products are primary V1 test case | **Confirmed** |

## Documentation/code drift

1. **`docs/V1-RC-FLAG-MATRIX.md`** header version still `0.1.0` vs plugin `1.0.0-rc.1`.
2. **Phase implementation docs** (e.g. 1A, 2H4) still show **Plugin version: 0.1.0** in headers while RC release notes / plugin header are `1.0.0-rc.1`.
3. **`readme.txt`** Stable tag is `1.0.0-rc.1`, but earlier description prose still mentions Phase 1A / `0.1.0` wording.
4. **`.cursor/rules/project-governance.mdc` frontmatter is invalid for Cursor YAML rules:** file uses `## alwaysApply: true` inside an opened `---` block instead of YAML `alwaysApply: true` closed by `---`. Rule **does** reference `docs/PROJECT-GOVERNANCE.md`. Reported only — not rewritten in Stage 0.
5. **Staging URL / test product IDs** are absent from tracked docs (live only in local `.env.local` / historical notes).
6. **ARCHITECTURE-PLAN.md** still describes future shipment pipeline; AI handoff RC status correctly limits current scope — no code contradiction for shipments (absent).
7. **No automated test suite** exists despite handoff/testing doctrine describing desired unit/integration tests — documented gap, not a silent claim of green PHPUnit.
8. Untracked at verification: governance files + Design Expectations doc (intended project docs; not runtime drift).

## Governance files check

| File | Status |
|------|--------|
| `docs/PROJECT-GOVERNANCE.md` | Exists, readable |
| `.cursor/rules/project-governance.mdc` | Exists, readable; references governance doc; **`alwaysApply` not valid YAML frontmatter** (see drift #4) |

## Baseline verdict

**BASELINE NOT VERIFIED — POST-RC DEVELOPMENT BLOCKED**

**Reason:** Repository identity, schema, flags, pipeline mapping, Composer/PHP integrity, and code-level invariant review are consistent with the documented `1.0.0-rc.1` / schema `2` RC at runtime commit `8951adb`. However, the mandatory V1 RC staging smoke checklist could not be executed: `https://training.cetechbpa.com` returns Cloudflare **HTTP 525** (origin SSL failure). Without a completed staging smoke pass, the baseline is not indisputable.

## Required next action (historical — superseded by Stage 0A)

~~Restore staging HTTPS reachability for `https://training.cetechbpa.com` (fix origin SSL / Cloudflare 525), then re-run `docs/V1-RC-SMOKE-TEST-CHECKLIST.md` end-to-end and update this report to VERIFIED before Stage 1.~~

**Superseded 2026-08-10 (Stage 0A):** Project owner designated `https://flairoc.com/intl/` as the canonical development/staging target. See **Stage 0A re-verification** below. The training-site HTTP 525 must not by itself block post-RC development.

Do not begin Stage 1 architecture gap analysis until smoke sign-off passes on the current development target.

---

# Stage 0A re-verification — FLAIROC development target

## Date

2026-08-10 (same calendar day as Stage 0; Stage 0A follow-up)

## Project decision

Canonical development/staging environment changed from the unavailable training site to:

**`https://flairoc.com/intl/`**

Tracked non-secret documentation: `docs/DEVELOPMENT-ENVIRONMENT.md`.

Local gitignored `.env.local` `WP_SITE_URL` / `WP_ADMIN_URL` updated to the FLAIROC `/intl/` URLs (secrets not committed).

**Portability:** No `flairoc.com` hostname was introduced into plugin runtime/business logic.

## Governance rule correction

| Item | Result |
|------|--------|
| Previous issue | `.cursor/rules/project-governance.mdc` used invalid metadata (`## alwaysApply: true` inside an opened `---` block) instead of YAML frontmatter |
| Correction | Valid Cursor MDC frontmatter: opening `---`, `alwaysApply: true`, closing `---`, then existing governance body retained |
| Single always-apply rule | Confirmed — only `.cursor/rules/project-governance.mdc` |
| References `docs/PROJECT-GOVERNANCE.md` | Confirmed |
| Final validation | File begins with valid YAML frontmatter; substantive instructions unchanged |

## FLAIROC environment inventory (observed)

| Area | Observed value |
|------|----------------|
| Site / home URL | `https://flairoc.com/intl` |
| WordPress | Asset path evidence `wp-includes` **7.0.3** under WP Rocket cache; WC `system_status.environment.version` reports `11.0.0` (appears to reflect WooCommerce, not WP — treat WP as **7.0.3** from assets) |
| WooCommerce | **11.0.0** (plugin + `wc_database_version`) |
| PHP | **8.5.5** |
| MySQL | **8.4.8** |
| Table prefix | `flagh_` |
| HPOS | **Enabled** (`OrdersTableDataStore`; sync disabled) |
| Currency | USD |
| Theme | **Woodmart Child** 1.0.0 (child); parent **Woodmart** **8.5.7** |
| WoodMart Core plugin | 1.1.8 |
| Checkout type | Classic WooCommerce checkout page (`/checkout/` page id 909 → rendered `<div class="woocommerce"></div>` when cart empty; WoodMart overrides `checkout/form-checkout.php`). **Not** Blocks markup. V1 classic path is the applicable baseline. |
| Cart page | Classic cart page id 12 |
| Caching | **WP Rocket** 3.23.1.1; **Redis Object Cache** 2.8.0 (`external_object_cache=true`); Cloudflare / CDN in front (CF challenge on `wp-login.php`) |
| Optional integrations present | WPML CMS 4.9.6 + String Translation 3.5.3 + other WPML add-ons; WCML 5.5.7; WCFM + marketplace + membership; WoodMart + child; WP Rocket; Redis; Jetpack; Wordfence; Really Simple Security; `wc/pos` REST namespace present (POS surface) |
| Template warnings | `has_outdated_templates=true` — e.g. WoodMart `cart/cart.php` 10.8.0 vs core 11.0.0; `mini-cart.php`; `single-product/add-to-cart/grouped.php`. Variable ATC override present at 10.9.0 / core 10.9.0 |
| Safety profile | **Production-active catalogue**: ~171 products, **~1707 customers**; WC API `orders` total header **0** (may be permission-scoped or truly empty). Payment gateways **bacs/cheque/cod all disabled**. Treat as live customer site — non-destructive until isolated QA path + admin access |

## Delivery Engine on FLAIROC

| Item | Result |
|------|--------|
| Installed | Yes — `cetech-woocommerce-delivery-engine/` present; `readme.txt` Stable tag **1.0.0-rc.1**; `vendor/autoload.php` present |
| Active | Yes (listed in WC system status active plugins) |
| Schema / flags via admin | **Not readable** — no Delivery Engine REST API; wp-admin blocked |
| Storefront evidence | `enable_product_delivery_selector` **ON**; `enable_cart_delivery_selection_capture` **ON** (variable products show capture-mode notice). Sample simple products with resolved rules but empty options show public notice: “Delivery options are not available for this product.” No supplier/origin/logistics/rate-card strings observed in sampled customer HTML |
| Dedicated QA product | **Not created** — blocked pending admin CRUD access for Delivery Engine configuration |
| Minimum V1 config (zones/offers/rates/rules) | **Not completed** — requires Delivery Engine admin screens |
| Flag matrix enablement order | **Not controlled** this session — cannot read/write `cetech_de_*` options without WP admin/Application Password |

## Access limitations (blocker)

| Path | Result |
|------|--------|
| WooCommerce REST (`wc/v3`) with local consumer key/secret | **Works** — system status, products, gateways, shipping zones |
| WordPress REST (`wp/v2/users/me`) with admin password | **403 Forbidden** |
| WordPress cookie login (`wp-login.php`) | **Cloudflare managed JS challenge** (403) — cannot establish admin session from agent automation |
| `WP_ADMIN_APP_PASSWORD` in `.env.local` | **Empty** |
| Delivery Engine REST | **None** (by design for V1) |

Without wp-admin (or an Application Password that can reach Delivery Engine admin / options), Stage 0A cannot configure offers/zones/rate cards/product rules, cannot safely sequence remaining flags, cannot place a classic checkout QA order with snapshots, and cannot complete the smoke checklist.

## Automated verification (re-run)

| Test / check | Result |
|--------------|--------|
| `composer validate --no-check-publish` | **PASS** |
| `composer dump-autoload -o` | **PASS** (132 classes) |
| PHP syntax lint `src/` + `database/` | **PASS** (133 OK / 0 FAIL) |
| PHPUnit / PHPCS / PHPStan / CI / JS | **NOT APPLICABLE** — still absent (documented gap; not built in Stage 0A) |

## Smoke-test results (FLAIROC)

Authoritative checklist: `docs/V1-RC-SMOKE-TEST-CHECKLIST.md`.

| Checklist section | Result | Notes |
|-------------------|--------|-------|
| Baseline — all flags off | **FAIL / not met** | Selector + capture already ON on live catalogue; not a clean flags-off baseline |
| Plugin present / no fatal on sampled storefront | **PASS** (partial) | Public product pages load; Delivery Engine markup renders notices |
| Admin CRUD / System Status | **BLOCKED** | No admin session |
| Selector only / capture / cart / checkout / rates / snapshots / email | **BLOCKED** | No admin config + no payment gateway + no admin order inspection |
| Privacy scan (sampled product HTML) | **PASS** (partial) | No supplier/origin/LP/rate-card/hash in sampled selector HTML; full cart/checkout/email/order surfaces not exercised |
| HPOS | **PASS** (environment) | HPOS enabled; order snapshot path not live-proven |
| Sign-off | **Fail / incomplete** | Admin access blocker |

**Counts (Stage 0A):** PASS `limited storefront/environment rows` · FAIL `1` (flags-off baseline already violated) · BLOCKED `majority of checklist rows requiring admin/checkout/order` · NOT APPLICABLE `automated PHPUnit suite`

## Hard-invariant verification (Stage 0A update)

| Invariant | Verdict | Evidence |
|-----------|---------|----------|
| **A. Server authority** | **PASS** | Unchanged code review; no live checkout contradiction found |
| **B. No silent Delivery Offer replacement** | **PASS** | Code paths unchanged; live path not fully exercised |
| **C. No accidental free shipping** | **PASS** | Code paths unchanged; live missing-rate case not fully exercised (no configured rate card path completed) |
| **D. Customer privacy** | **PARTIAL → improved evidence** | Live sampled product HTML: public notice only, no private ops strings. Residual code notes remain: offer `display_key` may encode offer id in interactive mode; no `is_protected_meta` / `woocommerce_hidden_order_itemmeta` registration found. Full thank-you/email/order privacy still **not live-verified** → remain **PARTIAL** until QA order path completes |
| **E. HPOS** | **PASS** | Environment HPOS on; CRUD code paths unchanged |
| **F. Protected snapshot metadata** | **PARTIAL** | Still not live-proven on FLAIROC (no QA order). Code uses `_cetech_de_*`; protection filters still not registered |
| **G. Feature-flag safety** | **PARTIAL** | Defaults in code remain off, but **this deployment already has selector+capture enabled** on a customer-facing catalogue without completed V1 offer configuration (customers see “not available” notices) |

## Baseline verdict (Stage 0A)

**BASELINE NOT VERIFIED — POST-RC DEVELOPMENT BLOCKED**

**Reason:** Governance rule fixed; canonical development target documented and reachable; repository automated checks green; plugin `1.0.0-rc.1` is installed/active on FLAIROC with Composer `vendor/`. However, the mandatory V1 RC smoke checklist cannot be completed because **WordPress admin access is unavailable to the coding agent** (Cloudflare login challenge + empty Application Password + WP REST 403). Delivery Engine admin configuration, controlled flag sequencing, payment/test checkout, and protected snapshot verification remain outstanding. Additionally, selector+capture are already enabled on live products without a completed simple-product offer path.

## Required next action (Stage 0A)

1. Provide a working **WordPress Application Password** (or equivalent non-interactive admin API access) for the FLAIROC `/intl/` development site that can reach Delivery Engine admin screens / options — and/or allowlist agent automation past Cloudflare for `wp-login` / admin AJAX.
2. Then: create dedicated **FLAIROC Delivery Engine QA Product** (simple), configure minimum V1 offers/zones/rate cards/rules, enable remaining flags **only** per `docs/V1-RC-FLAG-MATRIX.md`, enable a **safe test payment** (e.g. COD for QA only), and re-run `docs/V1-RC-SMOKE-TEST-CHECKLIST.md` end-to-end.
3. Update this document to **VERIFIED** only after that pass.

Do **not** begin Stage 1 until smoke sign-off passes.
