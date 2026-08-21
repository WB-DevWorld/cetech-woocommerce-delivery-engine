# RC.6 Compatibility & Plugin Lifecycle Qualification

**Date:** 2026-08-21  
**Plugin version:** `1.0.0-rc.6` (schema target `4`)  
**Protected tag:** `v1.0.0-rc.6` **not changed**  
**FLAIROC:** not modified  
**Stage 15:** not started  
**RC.6 runtime/product code:** not modified (qualification tests and this report only)

---

## Verdict

**PASS — RC.6 LIFECYCLE AND COMPATIBILITY QUALIFIED**

This does **not** authorise Stage 15, a new RC tag, FLAIROC deploy, or retagging `v1.0.0-rc.6`.

No storefront-safety defect was found that requires an RC.6 code repair. Known uninstall residual-option gaps (delete-data path only) are documented below and were **not** silently patched.

---

## How this was proved

| Layer | What ran | Result |
|-------|----------|--------|
| Source audit | `Activator`, `Deactivator`, `Uninstaller`, `uninstall.php`, `Plugin::boot()`, `MigrationRunner`, shipping registry, feature flags | Policy recorded below |
| Automated integration tests | PHPUnit `--testsuite Integration` **20 tests / 115 assertions** | **OK** |
| Full PHPUnit | **601 tests / 3232 assertions** (includes the 20 new tests); 3 pre-existing `ReflectionMethod` deprecations | **OK** |
| Disposable real WordPress | Docker Compose **outside** this git repository (`cetech-de-rc6-lifecycle-qual`). MariaDB **11.4**, WordPress **6.9.4**, PHP **8.2.33**, WooCommerce **11.0.1**, Storefront. Not FLAIROC. Torn down with `docker compose down -v`. | **PASS** (`qualify.json` `pass: true`) |

The disposable stack is not a plugin runtime dependency and is not packaged in the RC.6 ZIP.

---

## Required lifecycle guarantees

| Guarantee | Result | Evidence |
|-----------|--------|----------|
| Activation is idempotent | **PASS** | Second `Activator::activate()` / `activate_plugin()` did not duplicate tables, capabilities, or the QUAL1 offer. Feature flags already set to ON were not reset. `demo_data_on_activation` is stored default **false** and has **no seeder**. No Action Scheduler / `wp_schedule_*` jobs exist in `src/`. Shipping method ID is a map key (`delivery_engine_selected_offer`) so registry listing cannot duplicate. |
| Deactivation does not destroy data | **PASS** | `Deactivator` only deletes the activation-notice transient and flushes rewrite rules. Schema 4, QUAL1 row, marker option, shipment-ops index, and capabilities remained. |
| Reactivation restores without duplicates | **PASS** | Tables stayed at **17**; shipment flag stayed ON. |
| Update preserves data and runs only required forward migrations | **PASS** | `MigrationRunner` skips `version <= current`. Schema 3→4 recreated only shipment tables and kept QUAL1 + marker. |
| Same-version reinstall is harmless | **PASS** | Deactivate + activate at schema 4: table count unchanged, QUAL1 retained. |
| Older supported schema → schema 4 is idempotent and preserves data | **PASS** | Dropped shipment tables, set `cetech_de_db_version=3`, ran the same `MigrationRunner` as `plugins_loaded` / folder-replace. Version returned to **4**; QUAL1 retained; second run did not duplicate tables. |
| Failed/partial migration cannot leave a dangerous storefront | **PASS** | Failed migration records `cetech_de_last_migration_status=failed` and **does not** bump schema. Successful earlier versions are not re-applied. Storefront rates stay behind flags (default **OFF**). Missing/mismatched rate cards return no amount (`no_matching_rate_card`), not $0. `SelectedOfferShippingMethod::calculate_shipping()` adds a rate only when `success` and `total_amount` are set. System Status / health checker emit `last_migration_failed` / `schema_version_outdated`. |
| WooCommerce missing/inactive fails safe with no fatal | **PASS** | Isolated PHPUnit process (`CETECH_DE_DISABLE_WC_STUB=1`) and real WP: plugin active, `WooCommerce` class absent, admin notice `cetech-de-woocommerce-missing`, **no** `woocommerce_shipping_methods` registration, `fatal: null`. |
| Existing WC shipping zones/methods not removed or rewritten | **PASS** | Qual Ghana zone kept Flat Rate, Free Shipping, and Local Pickup across DE activation cycles. Native method class names remain in the shipping-method map. |
| Other plugins/settings untouched | **PASS** | `woocommerce_unrelated` option survived delete-data uninstall. Capabilities unregister only Delivery Engine caps. |
| Delivery method registered exactly once | **PASS** | Filter map key; real WP `apply_filters('woocommerce_shipping_methods')` had **one** `delivery_engine_selected_offer`. |
| Existing DE data not silently reset on upgrade/reactivation | **PASS** | `FeatureFlags::ensure_defaults()` uses `add_option` only when missing. Marker option and QUAL1 survived activation, deactivation, schema 3→4, same-version reinstall, and default WordPress Delete. |

---

## WordPress Delete / uninstall policy (current, deliberate)

WordPress **Plugins → Delete** runs `uninstall.php` **then** deletes plugin files.

**Folder delete / FTP remove / folder-replace does not run `uninstall.php`.** Those paths only remove files. Database objects remain unless an Administrator later uses WordPress Delete with delete-data enabled.

`uninstall.php` exits immediately unless `WP_UNINSTALL_PLUGIN` is defined. It does not fatal when WooCommerce is absent.

Default option `cetech_de_delete_data_on_uninstall` is **0**. The Settings checkbox **Delete plugin data when uninstalling** is the explicit opt-in. Deactivation and update never use this path.

### Default WordPress Delete (delete-data **off**) — **PASS**

| Object | Behaviour |
|--------|-----------|
| Plugin tables (`wp_delivery_engine_*`, 17 suffixes) | **Retained** |
| Feature-flag and schema options | **Retained** |
| Site-wide defaults / setup wizard options | **Retained** |
| Protected order/item meta (`_cetech_de_*`) | **Retained** (never deleted by uninstall) |
| Shipment / history table rows | **Retained** |
| Capabilities / roles | **Retained** |
| Scheduled jobs | None registered; nothing to clean |
| Transients | Activation notice already cleared on deactivate; remaining admin transients **retained** |
| WooCommerce shipping-zone method instances | **Retained** |

Reinstall after this Delete recovered QUAL1 and schema 4 without reseeding defaults over stored flags.

### Explicit delete-data WordPress Delete — **PASS** (destructive by design)

When `cetech_de_delete_data_on_uninstall=1`, `Uninstaller::uninstall()` (vendor present, as in the RC.6 ZIP):

| Object | Behaviour |
|--------|-----------|
| All `ConfigurationTables` suffixes (offers, areas, charges, scoped config, **shipments / items / events**, …) | **Dropped** |
| Feature-flag options from `FeatureFlags::defaults()` | **Removed** |
| `cetech_de_db_version`, `cetech_de_last_migration_status`, `cetech_de_delete_data_on_uninstall` | **Removed** |
| `cetech_de_sitewide_defaults`, `cetech_de_setup_wizard` | **Removed** |
| Shipment-creation failure index, COD awaiting index | **Removed** |
| Delivery Engine capabilities on known roles + `cetech_de_capabilities_version` | **Removed** |
| Protected order/item meta `_cetech_de_*` | **Retained** (WooCommerce order data is not purged) |
| WooCommerce shipping-zone Delivery instances | **Retained** (zone rows are WooCommerce data) |
| Foreign options (`woocommerce_*` and non-DE options) | **Retained** |

### Residual options on delete-data (not a storefront defect)

These Delivery Engine options are **not** cleared by `Uninstaller::remove_options()` today:

- `cetech_de_shipment_ops_issues`
- `cetech_de_global_configuration_version`
- `cetech_de_v3_config_migration_report`
- admin notice/draft transients (`cetech_de_admin_notice_*`, `cetech_de_admin_draft_*`)
- user meta `_cetech_de_shipments_reviewed_event_id` / dismissed-notice meta

The no-vendor `uninstall.php` fallback list is also narrower (it omits Site-wide Defaults, setup wizard, COD index, and the ECR runtime flag option names). The packaged ZIP includes `vendor/`, so WordPress Delete uses `Uninstaller`.

**Do not treat this residual list as permission to change tagged RC.6.** It is a documented delete-data completeness gap, not accidental $0 shipping.

---

## Precise lifecycle behaviour (audit + execution)

### Fresh installation / first activation

`register_activation_hook` → `Activator::activate()`:

1. PHP &lt; 8.1 → deactivate + `wp_die`.
2. `Capabilities::register()` (administrator + shop_manager; diagnostics cap administrator-only).
3. `FeatureFlags::ensure_defaults()` (customer/runtime flags **OFF**; `enable_classic_checkout_adapter` default **ON**).
4. `SchemaVersion::ensure_initialized()` then `MigrationRunner` (schema `0`→`1`→`2`→`3`→`4`).
5. Activation-notice transient (1 minute) + `flush_rewrite_rules()`.

`plugins_loaded` → `Plugin::boot()` also runs migrations (folder-replace without reactivation still advances schema). Capabilities `ensure_current()` self-heals the matrix without resetting subordinate Access edits (v2+ additive).

### WooCommerce inactive

Boot still runs PHP check, service registration, migrations, and capability self-heal, then registers the WooCommerce-missing admin error and **returns before** storefront/shipping/order hooks. HPOS compatibility is declared on `before_woocommerce_init` when WooCommerce later loads.

### Interrupted / repeated migration

`MigrationRunner::apply_migration()` catches `Throwable`, writes failed status, leaves schema at the last **successful** version, and retries only pending versions on the next boot/activation.

---

## Compatibility matrix

| Case | Result | Notes |
|------|--------|-------|
| Fresh store + first activation | **PASS** | Schema 4, 17 tables, flags default off |
| Existing Flat Rate / Free Shipping / Local Pickup | **PASS** | Preserved on Qual Ghana zone |
| Multiple zones / third-party method class in registry | **PASS** | Native + `custom_ship` kept; Delivery listed once |
| HPOS off | **PASS** | Compatibility declared; plugin does not require COT |
| HPOS on | **PASS** | Disposable site: `custom_orders_table_enabled: true` after WC COT options enabled |
| Default WooCommerce-compatible theme (Storefront) | **PASS** | Disposable site stylesheet `storefront` |
| WoodMart | **PASS (prior owner QA)** | Not installed in the disposable stack (licensed theme). Owner physical PASS of `1.0.0-rc.6-qa.2` on training.cetechbpa.com remains the WoodMart evidence. Core must not depend on WoodMart. |
| Feature flags OFF | **PASS** | Rate gate inactive; native package rates not stripped |
| Feature flags ON (shipment records) | **PASS** | Stored ON survived reactivation; not reset to defaults |
| Simple / variable products | **PASS (automated + prior QA)** | New tests cover fail-closed quotes and inheritance-related health; variable storefront remains covered by existing unit tests and RC.6 owner QA, not re-browsered here |
| Site-wide inheritance / explicit overrides | **PASS (existing suite + health checker)** | Incomplete catalog emits Needs Attention diagnostics |
| Multiple currencies | **PASS (fail-closed)** | GHS card vs USD request → no amount |
| Tax setups | **PASS (method-level)** | Delivery method default `tax_status=taxable`; no accidental free shipping from tax. Full multi-jurisdiction tax UI was not a separate Docker store. |
| Country/state code and label | **PASS** | Ghana `AA` ↔ `Greater Accra`; Nigeria `AA` does not match Ghana Greater Accra |
| Missing rate cards | **PASS** | Quote failure, not $0; health `zero_rate_cards` |
| Incomplete areas | **PASS** | Health `zero_destination_zones` / `active_zone_without_rules` / missing tables |
| No fatal | **PASS** | PHPUnit + real WP `fatals: []` |
| No accidental zero/free shipping | **PASS** | Missing/invalid quotes do not add a rate |
| No silent offer replacement | **PASS** | No activation seeder; stored flags/options not overwritten |
| No duplicate Delivery method | **PASS** | |
| No corruption of WC shipping configuration | **PASS** | |
| Fail-closed | **PASS** | |
| System Status / Needs Attention actionable when incomplete | **PASS** | Health checker warnings/errors with non-empty messages |

WoodMart live checkout was **not** repeated in Docker. That is a scope limit of this disposable stack, not an RC.6 code FAIL.

---

## Individual required scores

| Area | Score |
|------|-------|
| Install | **PASS** |
| Activation | **PASS** |
| Deactivation | **PASS** |
| Reactivation | **PASS** |
| Update (schema 3→4 / same version) | **PASS** |
| WordPress Delete / uninstall (default preserve) | **PASS** |
| WordPress Delete / uninstall (explicit delete-data) | **PASS** |
| Reinstall after preserve-Delete | **PASS** |
| Schema / data preservation | **PASS** |
| Dependency failure (WooCommerce inactive) | **PASS** |
| Compatibility matrix | **PASS** (WoodMart via prior owner QA, not this Docker) |

---

## Tests added in this repository

- `tests/Integration/PluginLifecycleQualificationTest.php`
- `tests/Integration/CompatibilityMatrixQualificationTest.php`
- `tests/Integration/LifecycleHarness.php`
- `tests/Integration/fixtures/uninstall-runner.php`
- `tests/Integration/fixtures/woocommerce-missing-boot.php`
- `tests/Integration/stubs/wp-admin/includes/upgrade.php`
- `phpunit.xml` Integration suite (runs first)
- `FakeWpdb` SHOW TABLES / DROP TABLE / SHOW INDEX support
- `CETECH_DE_DISABLE_WC_STUB` so WooCommerce-missing boot can run in a child PHP process

These tests do not ship in the production plugin ZIP.

---

## What was intentionally not done

- No Stage 15
- No RC.6 runtime repair for residual delete-data options
- No retag / rebuild of `v1.0.0-rc.6` or the RC.6 ZIP
- No FLAIROC change
- No WoodMart install in Docker
- No browser checkout campaign on the disposable site (rates remain flag-gated; fail-closed quotes were executed in PHPUnit)

---

## Security / privacy / shipping integrity

- Uninstall default path keeps configuration and shipment history.
- Delete-data drops DE tables only after explicit opt-in; order snapshots stay on the WooCommerce order.
- Suppliers/origins remain in DE tables (private); they are not copied to storefront options.
- Missing configuration does not become free shipping.
- Server remains authoritative; activation does not insert Delivery into zones.

---

## Recommended next step (not started)

If the owner wants delete-data uninstall to also drop the residual options listed above, that is a **future explicit uninstall-completeness task**, not an RC.6 silent patch and not Stage 15.
