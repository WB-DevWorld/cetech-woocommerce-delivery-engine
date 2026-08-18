# Stage 14G — Full Stage 14 Qualification

**Date:** 2026-08-18  
**Plugin version:** `1.0.0-rc.4` (unchanged)  
**Schema target:** `4`  
**Starting HEAD (approved Stage 14F):** `3ec1653c42069d8fbce88c420adb4886d6e46010`  
**Branch:** `master`  
**FLAIROC:** not modified  
**Feature flag defaults:** `enable_shipment_records`, `enable_tracking_links`, `enable_customer_timeline` remain **OFF**

---

## Verdict

**BLOCKED** — not qualified for an owner QA package.

The release-critical real MySQL/MariaDB schema 3→4 gate could not be run. No safe, supported local WordPress + MySQL/MariaDB environment was available. Docker Desktop is installed but the daemon is not running. There is no LocalWP site, no `mysql` client, nothing listening on port 3306, and no nearby `wp-config.php`. FLAIROC was not used to manufacture a pass.

All other Stage 14G gates were completed against the PHPUnit / fake-`wpdb` harness, source audit, and supported repository tooling.

---

## 1. Environments actually used

| Environment | Used? | Notes |
|-------------|-------|-------|
| Git `master` working tree | Yes | Qualification source |
| PHP 8.5.0 + PHPUnit 10.5.64 | Yes | Full unit suite |
| Fake `wpdb` test double | Yes | Schema, persistence, workflow, security tests |
| Node / Vitest 3.2.7 | Yes | Existing frontend JS tests |
| Composer | Yes | `composer validate --no-check-publish` |
| Local WordPress + MySQL/MariaDB | **No** | Not present |
| Docker daemon / WP containers | **No** | Client present; daemon not running |
| WPML / WCML | **No** | Not present |
| Playwright | **No** | Stage 13B specs exist; not a current Stage 14 npm gate |
| FLAIROC | **No** | Explicitly out of scope |
| Physical browser | **No** | Not claimed |

---

## 2. Real schema 3 → 4 migration

**REAL MYSQL/MARIADB SCHEMA 3→4 QUALIFICATION: BLOCKED / NOT AVAILABLE**

Not claimed PASS.

What was proven instead (source + fake `wpdb` / `SchemaV4MigrationTest`):

- Migration `20260818140000_create_shipment_tables` is schema `4` and `VerifiableMigrationInterface`
- `dbDelta` CREATE TABLE for `shipments`, `shipment_items`, `shipment_events`
- Unique `idempotency_key` and `order_group (order_id, delivery_group_id)`
- Indexes: `order_id`, `status`, `status_updated`, item `shipment_item`, event `shipment_time`
- No `FOREIGN KEY` / `REFERENCES` in `src/`
- Table names use WordPress `$wpdb->prefix` + `cetech_de_`
- No `DROP TABLE` in the v4 migration
- CREATE SQL does **not** pin `ENGINE=InnoDB`; WordPress/MySQL defaults are typically InnoDB, but the real engine was not inspected

Fresh install 0→4, second-run idempotency, uniqueness, and transaction rollback against a real engine remain **unproven**.

---

## 3. Fresh install schema 4

**NOT AVAILABLE** on real MySQL.

PHPUnit schema inspection and repository tests still require empty shipment tables after CREATE; no runtime rows are invented by migration SQL.

---

## 4. Real DB transaction / uniqueness

**NOT AVAILABLE** on real MySQL.

Fake-`wpdb` Stage 14C tests still cover:

- duplicate `order_id + delivery_group_id` rejected
- aggregate write uses `START TRANSACTION` / `COMMIT` / `ROLLBACK`
- item or event failure rolls back
- retry yields one complete aggregate (shipment + items + created event)

Architecture assumption remains InnoDB/transaction-safe. If a future real engine is MyISAM, that is an architecture defect and must stop the release.

---

## 5. Feature-flag matrix

Qualified in PHPUnit (`Stage14BRuntimeFreezeTest`, workspace, customer presentation, creation, operations).

| Matrix | Result |
|--------|--------|
| A. records OFF | RC.4 menu; no Shipments menu; no creation; no customer cards; tracking flag alone cannot emit Track shipment |
| B. records ON, tracking OFF | Workspace by capability; creation active; cards per compact contract; tracking number may display; **no** Track shipment control |
| C. records ON, tracking ON | Safe http/https URL → Track shipment; missing/invalid URL → no control |
| D. `enable_customer_timeline` | Reserved/unavailable; no runtime consumer under `src/` except defaults, labels, Settings exclusion, uninstall |

Ordinary Settings cannot enable the three shipment flags (`UNAVAILABLE_EXPERIMENTAL_FLAGS`).

---

## 6. End-to-end happy path

Qualified on the PHPUnit harness, **not** a physical browser and **not** a live WooCommerce store.

Covered logically: historical snapshot → paid creation → staff list/detail → processing → tracking → dispatched → in transit → current ETA update → customer View Order card → delivered.

At each step tests assert identity, item linkage, Delivery Option label, status, original ETA preserved, current ETA, paid amount unchanged, events, and customer privacy.

---

## 7. Multiple shipments

PASS (planner + creation + customer cards + cancel/refund sibling tests).

Two historical groups (Air + Sea) produce two shipments, distinct group identities, separate items/status/tracking/ETA. One update or refund review does not mutate the sibling. Customer sees two cards. Paid shipping is not duplicated by shipment writes.

---

## 8. Pickup + delivery

PASS (planner, creation, customer presentation).

Delivery group creates a shipment. Store Pickup does not. Pickup remains on the existing RC.4 compact summary. Shipment cards do not duplicate pickup. Pickup-only zero-shipment is valid. Needs Attention does not flag pickup-only zero shipment.

---

## 9. Creation / aggregate idempotency

PASS on the test harness.

Repeated payment-complete, processing/completed fallbacks, manual retry, repeated WC cancel/refund hooks, repeated identical tracking/status/ETA saves do not duplicate shipment, item, creation/status/tracking/ETA events, or operational issues (beyond the compact option’s de-duplicated codes).

---

## 10. Aggregate failure / recovery

PASS on fake `wpdb` (`ShipmentAggregateAtomicityTest`).

Partial item or event failure rolls back. Retry produces one complete aggregate.

---

## 11. Status workflow

PASS (`ShipmentStatusWorkflowTest`).

Normal: `awaiting_fulfilment` → `processing` → `dispatched` → `in_transit` → `delivered`.  
Cancellation only where approved. Delayed entry/recovery. Terminal statuses have no ordinary next step. Skip-ahead and invalid codes rejected. Same-status save is a no-op (no extra event).

---

## 12. Delayed / correction

PASS.

Correction requires `update_shipment_status`, mandatory reason, retains old status in history, records source/actor internally, customer sees only current status, paid/snapshot unchanged. Unauthorized POST is rejected (capability + nonce via `AdminActionHandler::verify_post`).

---

## 13. ETA

PASS (`ShipmentEtaUpdateTest`).

Original immutable. Current editable with required reason. Identical text creates no event. Customer sees current estimate. No resolver / Rate Card / Product Exception re-resolution.

---

## 14. Dispatch date / timezones

PASS (`ShipmentDispatchDateTimezoneTest`).

UTC storage from site-timezone midnight; display round-trip for `America/New_York`, `Pacific/Auckland`, and `gmt_offset +5.5`. No previous/next-day shift in those cases. Dispatch date does not mutate status. Status change does not invent a dispatch date.

Physical Ghana/browser timezone was not run.

---

## 15. Cancellation

PASS (`OrderShipmentCancelRefundTest`).

| Shipment status | Behaviour |
|-----------------|-----------|
| awaiting_fulfilment, processing | Auto-cancel |
| dispatched, in_transit, delayed, delivered | Keep status; `order_cancelled_after_progress` |
| cancelled | No-op |

Physical-history truth wins over pretending an already-moving parcel vanished.

---

## 16. Refunds

PASS on the WooCommerce quantity API used by `ShipmentRefundInspector` (`WC_Order::get_qty_refunded_for_item()` in tests).

Full refund before dispatch auto-cancels that shipment only. After dispatch, partial, and delivered refunds keep status and raise `refund_requires_review`. Sibling unaffected. Paid historical shipping unchanged. Repeated refund hook is idempotent. Money remains WooCommerce-owned.

---

## 17. Needs Attention lifecycle

**Defect found and repaired (smallest change).**

Stage 14F only called `clear_shipment()` when status became `cancelled`. Qualification proved a stale path:

1. Order cancelled after the shipment had progressed (`in_transit`) → `order_cancelled_after_progress`.
2. Staff later mark **Delivered** (approved physical-history resolution, including correction).
3. The operational issue remained forever even though the shipment was complete.

Repair:

- `ShipmentStatusService` clears the compact issue index when status becomes `cancelled` **or** `delivered`.
- `ShipmentOperationsIssueQuery` does not list leftover issues for already-cancelled shipments.
- Delayed issues still clear by leaving `delayed` (query-based).

**Remaining limitation (not redesigned):** a refund recorded **after** the shipment is already delivered still needs review. There is no acknowledge/dismiss action. That issue remains until a later stage adds one, or until an approved cancel/correction exists.

Pickup-only zero-shipment still does not appear.

---

## 18. Access / Administrator recovery

PASS (`ShipmentOperationsAccessTest`, Stage 13D-R1 recovery tests still green).

Administrator is a protected full-access role, not an editable Access row. `manage_options` recovery restores the full protected set, including `manage_shipments` and `update_shipment_status`.

Combinations tested in unit form:

| Caps | Expected |
|------|----------|
| none | No Shipments menu; POST rejected |
| `manage_shipments` only | List/detail/tracking; status/ETA/correction POST rejected |
| `update_shipment_status` only | No menu; status POST still capability-checked (menu hide is not security) |
| both | Full operational workspace |

Direct URLs/POST use capability + nonce. Feature flag OFF still blocks mutations.

---

## 19. Customer ownership / security

PASS (`CustomerShipmentPresentationTest`).

Cards load only `findByOrderId` for the `WC_Order` WooCommerce already authorised on View Order. There is no customer shipment-ID URL. Order 902’s shipment does not appear for order 903. Guest behaviour remains WooCommerce’s; no guest portal was added.

---

## 20. Tracking security

PASS (`TrackingUrl`, staff tracking tests, customer renderer).

| Input | Customer clickable link |
|-------|-------------------------|
| https | Allowed when tracking-links flag ON |
| http | Allowed by policy |
| invalid / malformed | None |
| `javascript:` / `data:` / `file:` | Rejected |
| long URL | Rejected at 500 chars |

Renderer uses `esc_url` + `esc_html`. Long tracking numbers wrap (`overflow-wrap: anywhere`).

---

## 21. Input / output security

PASS at code/unit level (not destructive FLAIROC testing).

Public note, internal reason, carrier, tracking number/URL, and ETA text are sanitised on save (`sanitize_textarea_field` / `sanitize_text_field` / `TrackingUrl`). Admin and customer output use `esc_html` / `esc_attr` / `esc_url`. Capability and nonce required for POST. No new public REST routes (`register_rest_route` is only mentioned inside `ConfigurationHealthChecker` inspection).

---

## 22. Privacy audit

PASS for customer HTML, `CustomerShipmentCard` JSON, View Order renderer, and existing email path (emails were not extended).

Customer must not receive, and tests/source do not emit:

- supplier / origin / Logistics Profile / Rate Card / internal cost
- `delivery_group_id` / idempotency key
- private note / internal correction reason / actor ID / event payload
- private priority / raw event codes as customer copy

Staff Operations section may show historical supplier/origin/internal cost to privileged users. That is internal, not customer output.

No new public REST exposure.

---

## 23. Money / shipping integrity

PASS.

Shipment creation, tracking, status, ETA, correction, and refund-review do not write WooCommerce shipping line amounts, tax, order total, or historical delivery snapshots. Refund money stays WooCommerce-owned.

---

## 24. Historical snapshot integrity

PASS (`HistoricalShipmentPlannerTest` and related creation tests).

Shipment/customer meaning uses saved snapshot fields (`delivery_offer_public_label`, ETA text, paid amount). Workspace query does not consult current product configuration, EffectiveConfigurationResolver, or Rate Cards.

A live “change current config then reopen old shipment” browser fixture was not run; the code path cannot re-resolve current config for those fields.

---

## 25. RC.4 product / cart / checkout regression

PASS via the existing full PHPUnit suite (simple/variable ECR, selector, inheritance, Delivery Options/Areas/Charges, Store Pickup, explicit zero, fail-closed missing/malformed rates, cart, classic checkout shipping label, order snapshot, compact customer presentation).

Stage 14 hooks:

- `PaidOrderShipmentSubscriber` only on `woocommerce_payment_complete` / processing / completed
- `OrderShipmentOperationsSubscriber` only on cancelled / refunded
- `ShipmentService::create_for_paid_order` returns `FeatureDisabled` immediately when the flag is OFF (no shipment table work after that check except the flag option read)

No shipment-list query on product, cart, or checkout paths.

---

## 26. Performance

### Product / cart / checkout

No shipment list query. No site-wide Stage 14 scan. Flag-off paid-order handler exits before planner/repository writes.

### Shipment list

`WpdbShipmentRepository::list`: filtered `WHERE`, `ORDER BY updated_at, id`, `LIMIT`/`OFFSET`, separate `COUNT(*)`. Search bounded to shipment number / tracking / numeric id or order id. Item counts: one `countItemsByShipmentIds` `IN (...)` query. Orders: one `wc_get_orders(include)` batch. Events are **not** loaded on list.

Non-trivial live fixture (hundreds of real rows) was **not** available. Query strategy is recorded from source, not production benchmarks.

### Customer View Order

`findByOrderId` (list with `order_id`, per_page 100) then `findItems` per shipment on **that order**. No event timeline. No current-product resolver. N+1 is bounded to the current order’s shipment count (typically small).

### Needs Attention

Delayed: indexed `status = delayed` list, limit 50. Cancel/refund: compact option `cetech_de_shipment_ops_issues`, then `findById` per stored id. No all-order scan. No all-shipment scan.

---

## 27. Query findings (source)

| Surface | Queries |
|---------|---------|
| List page | 2 SQL (count + page) + 1 item-count aggregate + 1 WC order batch |
| Detail | 1 shipment + items + events + 1 order |
| Customer cards | 1 order-scoped shipment list + items per shipment |
| Operations NA | option read + findById per open issue + 1 delayed status page |
| Product/cart/checkout | none |

---

## 28. Asset scope / caching

- Admin shipment CSS lives in `assets/admin/delivery-engine-admin.css`, enqueued by `AdminUxAssets` only on Delivery Engine admin pages and product/order screens — not site-wide frontend.
- Customer shipment rules live in `assets/frontend/customer-order-delivery-summary.css`, enqueued on order-received / view-order / account pages when the compact summary flag is on (existing RC.4 surface). Not product, category, home, blog, or shop archive.
- No new frontend JavaScript for Stage 14.
- Customer shipment data is not injected into public structured data, sitemap, feed, or OG metadata.
- My Account / View Order remain private WooCommerce surfaces.

---

## 29. Language / gettext

Stage 14 visible strings use `__()`, `esc_html__()`, `_n()`, and translator comments where placeholders exist.

Mild remaining pattern: staff history joins two already-translated fragments with ` — `. Not rewritten.

Customer item lines append ` × quantity` (symbol, not a sentence). Plurals use `_n()`.

ARIA strings (`Track shipment %s`, search labels, pagination) are localized.

Machine codes (`awaiting_fulfilment`, event types, sources, error codes) are mapped through presentation helpers; customers do not see raw codes.

---

## 30. Machine-code integrity

PASS.

Comparisons use enums / `tryFromMachineCode` / stored option codes, never translated labels, for status, event type, source, result/error codes, Delivery Option identity (historical id + snapshot), and idempotency keys.

---

## 31. WPML

**WPML-PRESENT QUALIFICATION: NOT AVAILABLE / NOT CLAIMED**

WPML absence remains a PASS condition for core plugin operation (`enable_wpml_adapter` default OFF).

---

## 32. WCML

**WCML-PRESENT QUALIFICATION: NOT AVAILABLE / NOT CLAIMED**

Historical shipment money uses the order snapshot / `currency_code` varchar. No today’s-exchange-rate reconversion exists in Stage 14 code.

---

## 33. Accessibility

Markup/code audit only. **No automated accessibility tool is part of supported project tooling — automated a11y PASS is not claimed.**

Findings:

- Native form controls and submit buttons (keyboard)
- Associated labels / screen-reader-text on list filters
- Semantic `h1`/`h2`/`h3` and customer `<section>`/`<article>`
- Status badge includes the text label (not colour-only)
- WordPress admin notices
- Required reason fields labelled
- Track shipment has distinct link text plus `aria-label`
- Track control min 44×44px; admin actions wrap

Physical keyboard/screen-reader session was not run.

---

## 34. Mobile

Code/CSS audit only. No device lab.

Customer cards wrap long tracking numbers and notes (`overflow-wrap: anywhere`). Multiple cards stack. Admin list uses existing table overflow; status/tracking/correction forms wrap and go full width below 782px. No critical hover-only shipment action.

---

## 35. Unknown event resilience

PASS (`UnknownShipmentEventResilienceTest`) after 14F event types.

Unknown event type hydrates, history loads, raw code preserved internally, staff see generic “Shipment update”, payload/code not leaked to customers, known events still behave, unknown **current status** still rejected.

---

## 36. Feature-off RC.4 compatibility

PASS.

With all Stage 14 flags OFF: normal RC.4 customer experience, no Shipments menu, no creation, no tracking UI, no status workflow customer output, no operational side effects (cancel/refund subscriber returns no shipments when the flag is OFF). Schema 4 empty tables would exist only after a real migration, which was not run here.

---

## 37. Package-readiness audit

Fixes applied:

- `scripts/verify-production-package-autoload.php` now requires Stage 14 runtime classes and asserts shipment feature-flag defaults remain false.

Run against the **development tree** (vendor includes PHPUnit / autoload-dev):

- Expected failure: “PHPUnit appears present in production vendor”
- Expected noise: `CetechDeliveryEngine\Tests\...` Linux-case mappings under autoload-dev
- No production `src/` Linux-case mismatch was reported
- Required Stage 14 classes resolved
- No credentials, local absolute paths, or debug artifacts in `src/`
- Design Reference PNGs are existing product mocks, not Stage 14 harness dumps
- Composer production `require` is PHP only; PHPUnit is `require-dev`

A `--no-dev` QA ZIP was **not** built (Stage 14H).

---

## 38. Defects found

1. **Needs Attention stale after Delivered** — cancel/refund review issues cleared only on `cancelled`. Staff marking delivered (normal or correction) left a permanent open issue. Governing rule: operational issues must not stay stale when an approved state resolution exists; do not pretend a completed physical shipment still needs the same review.

---

## 39. Repairs made

| Change | Why |
|--------|-----|
| `ShipmentStatusService` clears issues on `cancelled` **or** `delivered` | Smallest lifecycle repair |
| `ShipmentOperationsIssueQuery` skips cancelled shipments | Display safety net |
| Needs Attention copy + 14F doc | Match actual lifecycle |
| Four regression tests in `OrderShipmentCancelRefundTest` | Prove clear-on-delivered; prove refund-after-delivered still lists |
| Autoload verification list + flag defaults | Package-readiness |

No redesign. No new Stage 14 features. No version/tag/package/FLAIROC.

---

## 40. Tests

| Gate | Result |
|------|--------|
| `composer validate --no-check-publish` | valid |
| Production PHP lint (`cetech-woocommerce-delivery-engine.php` + `uninstall.php` + `src/**/*.php`) | **311 files, 0 failures** |
| PHPUnit | **476 tests, 2658 assertions** |
| Deprecations | **3** (`ReflectionMethod::setAccessible()` PHP 8.5), same unique notices as Stage 14F; 8 tests trigger them; **no new deprecation type** |
| `npm run test:js` | **11 tests, 2 files, all passed** |
| Autoload / Linux-case | Dev-tree run as above; production classes OK |
| PHP static analysis | **Not present** in project tooling; not added |
| Playwright | **Not run** (no current Stage 14 npm script; Stage 13B specs only) |
| WPML / WCML | **Not available** |

Baseline before this stage: 472 tests / 2645 assertions / 3 deprecations. Delta: +4 tests / +13 assertions from the lifecycle repair.

Recommendation (not done): test-only removal of `ReflectionMethod::setAccessible()` under PHP 8.5 is trivial and isolated, but it is unrelated cleanup and was not mixed into qualification.

---

## 41. Remaining limitations

- Real MySQL/MariaDB 3→4, fresh 0→4, uniqueness, InnoDB/transaction proof
- WPML-present and WCML-present qualification
- Physical browser, keyboard, and mobile-device passes
- Production query counts / benchmarks
- Refund-after-delivered Needs Attention has no acknowledge action
- CREATE TABLE does not pin `ENGINE=InnoDB`
- No carrier APIs, emails, timeline, guest portal, labels, Fulfillments dual-write, Checkout Blocks, Stage 15

---

## 42. Blockers

1. **No safe local WordPress + MySQL/MariaDB environment** for the release-critical schema 3→4 gate.

---

## 43. Recommended next stage

**Stage 14G-R1 — real MySQL/MariaDB schema 3→4 + uniqueness/transaction qualification** in a safe local WordPress environment (not FLAIROC).

Do **not** start Stage 14H until that gate passes.

After 14G-R1 PASS, Stage 14H is: controlled Stage 14 QA package + owner physical testing + training documentation finalization.

---

## 44. Intentionally excluded

Carrier APIs, tracking polling/webhooks, customer timeline, shipment emails, guest portal, labels, WooCommerce Fulfillments dual-write, historical backfill, Checkout Blocks, bulk import, driver app, GPS, OTP, QR, proof of delivery, signatures, unrelated admin features, Stage 15, version bump, RC.5, tags, QA ZIP, FLAIROC.
