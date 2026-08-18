# Stage 14G-R1 — Real MariaDB Schema 3→4 Qualification

**Date:** 2026-08-18  
**Plugin version:** `1.0.0-rc.4` (unchanged)  
**Schema:** `4`  
**Starting HEAD (approved Stage 14G):** `3934cbd14381957315e45e2092b73d3f8035e83a`  
**FLAIROC:** not modified  
**Feature flag defaults:** remain **OFF**

---

## Verdict

**PASS — STAGE 14 QUALIFIED FOR OWNER QA PACKAGE**

This does **not** mean owner QA passed, Stage 14 released, or RC.5 released.

---

## 1. Local qualification environment

Disposable Docker Compose stack **outside** the plugin git repository (`C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-14g-r1-qual`). Not a plugin runtime dependency. Not packaged. No production/FLAIROC credentials.

| Piece | Actual |
|-------|--------|
| Method | Docker Desktop 29.6.1, compose project `cetech-14g-r1` |
| Database | MariaDB **11.4.12** (`11.4.12-MariaDB-ubu2404`) |
| Default storage engine | **InnoDB** |
| Server charset/collation | utf8mb4 / utf8mb4_unicode_ci |
| WordPress table charset | utf8mb4 / utf8mb4_unicode_520_ci |
| PHP (WordPress container) | **8.2.33** |
| WordPress | **7.0.4** |
| WooCommerce | **11.0.1** (host zip; container wp.org download failed TLS verify) |
| Plugin bootstrap PHP (host tests) | 8.5.0 + PHPUnit 10.5.64 |

Ports bound to localhost only: WordPress `127.0.0.1:18080` / `18081`, MariaDB `127.0.0.1:33077`. Disposable local-only DB credentials were used and are **not** recorded here.

WooCommerce was activated **after** the pinned schema 3→4 proof so the migration itself used the plugin’s normal `MigrationRunner` on `plugins_loaded` / activation, which does not require WooCommerce.

---

## 2. Schema-3 baseline

**Method:** tagged RC.4 source `v1.0.0-rc.4` (`git archive` → composer autoload) installed and activated on a clean WordPress database `wp_upgrade`. RC.4 tag was not modified.

Before the Stage 14 overlay:

- Plugin version: `1.0.0-rc.4`
- `cetech_de_db_version` = **3**
- Shipment tables **absent**
- Existing Delivery Engine tables present (`wp_delivery_engine_*` configuration / rules / rate cards / etc.), InnoDB
- Marker option `cetech_de_14g_r1_marker` = `schema3-baseline-keep`
- Marker scope row `slice_key=14g_r1_marker`
- Historical quote fixture SHA-256 `69a76519893b5ba6011c0b4c677b1b301828f2f4adb5373cab29df137f714e8b`
- Feature flags OFF

This baseline was rebuilt **after** the InnoDB pin so the release-critical 3→4 proof used the hardened CREATE TABLE SQL.

---

## 3. Real schema 3 → 4 migration

Folder-replace of the active plugin directory with current Stage 14 source. The plugin’s `MigrationRunner::run()` on WordPress bootstrap applied `20260818140000_create_shipment_tables`. Tables were **not** created by hand.

| Check | Result |
|-------|--------|
| `cetech_de_db_version` | **4** |
| PHP fatal | none |
| SQL errors | none on the migration path |
| Shipment rows created by migration | **0** |

An earlier unpinned 3→4 on the same MariaDB also produced InnoDB tables via server default. After the source pin, 3→4 was re-run from a **clean schema-3** WordPress database.

---

## 4. Shipment tables (actual)

Prefix: `wp_`

| Full name | Engine | Collation | Rows after migrate |
|-----------|--------|-----------|--------------------|
| `wp_delivery_engine_shipments` | InnoDB | utf8mb4_unicode_520_ci | 0 |
| `wp_delivery_engine_shipment_items` | InnoDB | utf8mb4_unicode_520_ci | 0 |
| `wp_delivery_engine_shipment_events` | InnoDB | utf8mb4_unicode_520_ci | 0 |

`SHOW CREATE TABLE` for shipments includes the expected columns (identity, snapshot Delivery Option, original/current ETA, paid amount, tracking, public/private notes, reserved `wc_fulfillment_id`, timestamps) and:

```
PRIMARY KEY (`id`)
UNIQUE KEY `idempotency_key` (`idempotency_key`)
UNIQUE KEY `order_group` (`order_id`,`delivery_group_id`)
KEY `order_id` (`order_id`)
KEY `status` (`status`)
KEY `status_updated` (`status`,`updated_at`)
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
```

Items: `UNIQUE KEY shipment_item (shipment_id, order_item_id)`, `KEY shipment_id`, `KEY order_item_id`, InnoDB.

Events: `KEY shipment_id`, `KEY shipment_time (shipment_id, event_at)`, InnoDB.

No `FOREIGN KEY` to WooCommerce tables.

---

## 5. Transaction engine verdict

All three Stage 14 tables are **InnoDB** and support the repository `START TRANSACTION` / `COMMIT` / `ROLLBACK` path.

---

## 6. Storage engine design decision

**Relying on the host `default_storage_engine` is not acceptable** for a reusable plugin whose shipment aggregate integrity depends on transactions.

Evidence:

- First unpinned 3→4 on this MariaDB produced InnoDB only because `default_storage_engine=InnoDB`.
- Existing schema-3 Delivery Engine tables also omit ENGINE in CREATE SQL and happened to be InnoDB here.
- A host with MyISAM default would accept the unpinned CREATE TABLE and silently defeat rollback.

**Repair (schema 4, no schema 5):** `ShipmentSchema::create_table_statements()` now ends each statement with `ENGINE=InnoDB {$charset_collate}`. Unrelated configuration tables were not rewritten.

3→4 was re-proved from a clean RC.4 schema-3 database after this pin.

---

## 7. Index verification (actual DB metadata)

| Need | Present |
|------|---------|
| Primary shipment id | `PRIMARY` on `id` |
| UNIQUE(order_id, delivery_group_id) | `order_group` |
| Unique idempotency | `idempotency_key` |
| order_id lookup | `KEY order_id` |
| status / updated_at | `KEY status`, `KEY status_updated` |
| item relationship | `UNIQUE shipment_item`, `KEY shipment_id`, `KEY order_item_id` |
| event history | `KEY shipment_id`, `KEY shipment_time` |

---

## 8. Existing data integrity

After 3→4:

- Marker option unchanged
- Marker configuration_scopes row unchanged
- Quote snapshot SHA-256 unchanged
- Schema-3 table set retained (no drop)
- Plugin remained active
- Shipment tables empty until explicit smoke inserts

---

## 9. Migration idempotency

Second normal bootstrap: `cetech_de_db_version` stayed **4**. No SQL errors. No duplicate tables. Marker/snapshot unchanged. No extra shipment rows from the second boot (zero until smoke).

---

## 10. Fresh install → schema 4

Separate database `wp_fresh` + current Stage 14 source activated on empty WordPress:

- schema **4**
- all expected Delivery Engine tables present
- shipment tables InnoDB and **empty**
- flags OFF
- no shipment records from install alone

---

## 11. Real unique constraint

Repository `ensureCompleteAggregate` for the same `order_id + delivery_group_id` returned the same shipment and did not add a second created event or item.

Raw `INSERT` of the same identity failed. Shipment count did not increase.

---

## 12. Real transaction rollback

MariaDB `BEFORE INSERT` trigger on `wp_delivery_engine_shipment_items` forced `qual_forced_item_failure` after the shipment row insert inside `WpdbShipmentRepository::transact()`.

Result: exception `Failed to persist shipment item.`, **no** `findByOrderAndGroup` row for that identity, **no** leftover items/events. InnoDB `AUTO_INCREMENT` skipped ids (retry shipment id **4** after failed id **3**), which is expected after rollback.

That SQL error is the **intentional** failure injection, not a migration defect.

---

## 13. Retry after failure

Same aggregate retried after dropping the trigger: **one** complete shipment, expected items (qty 2), exactly one `created` event. No duplicates.

Final smoke counts: 26 shipments / 26 items / 30 events (26 created + tracking/status/ETA events on the first shipment). A leaked partial would have been 27 shipments.

---

## 14. Repository CRUD / pagination smoke

Exercised on real SQL: create, findById, findByOrderAndGroup, findByOrderId, list LIMIT/OFFSET (10+10 distinct), status filter (`delayed` ≥ 1, no leak), search by shipment number, order_id filter, `countItemsByShipmentIds`, tracking save, status → processing, current ETA update with original ETA preserved.

`decimal(18,6)` hydrates paid amount as `25.000000`; numeric value remained 25 (not a tracking mutation).

---

## 15. Feature flag defaults

Beginning and end: `enable_shipment_records`, `enable_tracking_links`, `enable_customer_timeline` all **OFF** in options. Source defaults unchanged.

---

## 16. Local PHP / WordPress / DB logs

- No `wp-content/debug.log` file was created by Apache.
- wp-cli printed the **intentional** item-insert trigger error during rollback.
- No migration fatals or unexpected SQL errors.

---

## 17. Defects found

1. Schema 4 CREATE TABLE did not pin `ENGINE=InnoDB`, so transactional safety depended on the host default. Release-blocking portability risk for a reusable plugin.

---

## 18. Repairs made

- Pin `ENGINE=InnoDB` on the three shipment CREATE TABLE statements
- `SchemaV4MigrationTest` + `required_markers` regression
- Governing rules + Stage 14B note
- Re-prove 3→4 from clean schema-3 after the pin

---

## 19. Automated regression

| Gate | Result |
|------|--------|
| composer validate --no-check-publish | valid |
| Production PHP lint | **311 files, 0 failures** |
| PHPUnit | **477 tests, 2667 assertions** |
| Deprecations | **3** pre-existing `ReflectionMethod::setAccessible()` PHP 8.5 |
| npm run test:js | **11 passed** |
| Autoload/Linux-case | Dev-tree run of `php scripts/verify-production-package-autoload.php .` **FAILED as expected**: PHPUnit in vendor + `CetechDeliveryEngine\Tests\...` Linux-case noise. No production `src/` mismatch in the failure list. Required Stage 14 classes remain in the script’s required list. `--no-dev` QA ZIP not built. |

Playwright not run.

---

## 20. Environment cleanup

`docker compose down -v` completed: containers `cetech-14g-r1-mariadb`, `cetech-14g-r1-wp-upgrade`, `cetech-14g-r1-wp-fresh`, network, and volumes `dbdata` / `wp_upgrade` / `wp_fresh` removed.

The qualification directory remains **outside** the plugin repo (`C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-14g-r1-qual`) with disposable compose files and local-only credentials. It is **not** committed and is **not** promoted to permanent project tooling. Owner may delete that folder; a reusable integration harness would be useful later but needs an explicit approval before becoming repo infrastructure.

---

## 21. Remaining limitations

- WPML/WCML-present tests still unavailable
- Physical owner QA / browser / FLAIROC still not done
- WooCommerce was not required for schema migration; WC 11.0.1 was activated afterward for version recording
- Refund-after-delivered Needs Attention acknowledge still not implemented (Stage 14G known limitation)
