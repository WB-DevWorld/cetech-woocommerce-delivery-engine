# Stage 14A — Shipment & Tracking Architecture Audit

**Document status:** Architecture decision / design gate only  
**Date:** 2026-08-18  
**Plugin version at audit:** `1.0.0-rc.4` (unchanged)  
**Schema target at audit:** `3` (unchanged; no migration written)  
**Runtime changes:** NONE  
**FLAIROC:** NOT MODIFIED  

This document is the Stage 14A source of truth for what Stage 14 may implement. It does **not** implement shipment records, tracking, customer timelines, carrier APIs, Checkout Blocks, proof-of-delivery, bulk import, or Stage 15.

---

## 1. In plain English

RC.4 already takes a customer from product choice through cart, checkout, genuine WooCommerce shipping, and a locked copy of that delivery choice on the paid order. Staff can see delivery information on the order. Customers see a compact Delivery option + estimated delivery block. That path is protected and must not be rewritten.

What RC.4 does **not** do is create operational shipment records after payment. There is no Shipments screen, no tracking number, no shipment status history, and no “Track shipment” control.

Stage 14 should consume the **historical checkout grouping result already stored on the order**, then create one shipment per delivery group. It must not recalculate today’s product settings to guess how an old paid order should have been split.

WooCommerce now has a native Fulfillments feature (beta, off by default, available from WooCommerce 10.2). It can store “this package of items was shipped” plus tracking number/URL. It cannot safely own CETECH’s seven operational statuses, private supplier/origin data, immutable paid-delivery snapshots, Needs Attention, or granular Delivery Engine permissions. Native Fulfillments also does not exist on every site this plugin must support (`WC requires at least: 8.0`).

**Verdict:** Stage 14 V1 should use Delivery Engine custom tables as the canonical shipment store (schema 4), behind a repository interface so a WooCommerce Fulfillments adapter can be added later. Do not make native Fulfillments the system of record in V1.

---

## 2. Repository baseline (verified 2026-08-18)

| Item | Value |
|------|--------|
| Branch | `master` |
| Local HEAD | `a4d7309d402c475045520f30459b6d0998b100ef` |
| `origin/master` | `a4d7309d402c475045520f30459b6d0998b100ef` (in sync) |
| Working tree | Clean |
| Remotes | `origin` = `wbdevworld/...`; `upstream` = `janelove-tech/...` |
| Plugin version | `1.0.0-rc.4` (`CETECH_DE_VERSION` + plugin header) |
| Schema target | `3` (`SchemaVersion::TARGET`, option `cetech_de_db_version`) |
| RC.4 annotated tag object | `69a167432a2b895c9bae97aecb98d8d0abe1b16e` |
| RC.4 peeled commit | `6b70c29b31362d1dad1c41e22fa30fa50e4ad559` |
| RC.4 package source (product) | `889072e9972b4e8341c3af3fc8c3dfb24ca93c79` |
| RC.3 tag | `v1.0.0-rc.3` / `f91da5dad677410ca05fbcb86ef8fd83596b3871` — untouched |
| RC.2 tag | `v1.0.0-rc.2` — untouched |
| Commits after RC.4 tag | `5d30616` package-source clarification; `a4d7309` RC.4 staff training docs |
| Published docs | Stage 13G finalization + training set describe RC.4 as complete |
| Unexplained dirty files | None |

`git fetch origin --prune --tags` was run. No rebase, force-push, retag, version bump, or packaging was performed.

RC.4 remains the protected runtime baseline. Master is documentation-ahead of the tag; runtime identity is still `1.0.0-rc.4` / schema `3`.

---

## 3. Existing RC.4 shipment-adjacent inventory

Classification key:

- **IMPLEMENTED AND ACTIVE** — code exists and can run when its production flags are on
- **IMPLEMENTED BUT FLAGGED OFF** — code exists; reserved/off by default
- **RESERVED/STUB ONLY** — identifier/capability/flag exists; no behaviour
- **DOCUMENTATION ONLY** — described in vision/architecture docs; no runtime class
- **ABSENT** — not in this repository

| ID | Area | Finding | Classification |
|----|------|---------|----------------|
| A | Domain classes/interfaces | No `src/Domain/Shipment/`. Existing domain covers offers, zones, rates, suppliers, origins, pickup, product rules, scoped configuration, audit. Enums `DeliveryRoute`, `FulfilmentAvailability`, `FulfilmentChoice` exist. | ABSENT (shipment domain) / IMPLEMENTED AND ACTIVE (adjacent enums) |
| B | Repositories | No shipment repository. Config/audit repositories exist (`Wpdb*Repository`). | ABSENT |
| C | Database tables | Present: `delivery_offers`, `destination_zones`, `destination_rules`, `logistics_profiles`, `suppliers`, `origins`, `pickup_locations`, `rate_cards`, `rate_card_rules`, `audit_log`, `product_delivery_rules`, `configuration_scopes`, `configuration_fields`, `configuration_collections`. | IMPLEMENTED AND ACTIVE (config) / ABSENT (shipment tables) |
| D | Reserved schema | Schema 3 migrations explicitly **forbid** creating `shipments` / `shipment_items` / `shipment_events`. Tests assert schema SQL contains no `shipment` string. | RESERVED/STUB ONLY (forbidden, not pre-created) |
| E | Feature flags | `enable_shipment_records`, `enable_tracking_links`, `enable_customer_timeline` default false, Settings UI marked `unavailable`, no runtime consumers. | RESERVED/STUB ONLY |
| F | Order metadata | `_cetech_de_delivery_quote_snapshot` (JSON groups + paid shipping total); `_cetech_de_order_delivery_snapshot_version`. | IMPLEMENTED AND ACTIVE |
| G | Order-item metadata | `_cetech_de_delivery_snapshot` (JSON); `_cetech_de_delivery_snapshot_version`. | IMPLEMENTED AND ACTIVE |
| H | Shipping-line metadata | `cetech_de_group_id` written by `SelectedOfferShippingMethod`; hidden from ordinary UI. | IMPLEMENTED AND ACTIVE |
| I | Grouping/package metadata | Runtime package key `cetech_de` on WC packages: `managed`, `group_id`, availability, choice, offer id, pickup flag, public label, display index, rate label. Cart-only — not a post-order table. | IMPLEMENTED AND ACTIVE (checkout) |
| J | Delivery snapshots | `OrderDeliverySnapshot*` builder/persister/reader/integrity/admin display. Triggered at **order creation**, not payment. Does not create shipments. | IMPLEMENTED AND ACTIVE |
| K | Customer presentation | Compact contract via `CustomerOrderDeliverySummaryBuilder` + renderer. Thank-you / My Account `woocommerce_order_details_after_order_table`. | IMPLEMENTED AND ACTIVE (when customer-summary flag on) |
| L | Email hooks | `woocommerce_email_after_order_table` compact Delivery details. | IMPLEMENTED AND ACTIVE (when email-summary flag on) |
| M | My Account hooks | Same order-view hook as thank-you. **No** `my-account/deliveries` endpoint. | IMPLEMENTED AND ACTIVE / ABSENT (separate endpoint) |
| N | Admin order presentation | Read-only “Delivery information” meta box; technical meta hidden (Stage 8C). | IMPLEMENTED AND ACTIVE |
| O | Audit logging | `delivery_engine_audit_log` + `ConfigurationAuditLogger` for **configuration** changes, not shipment events. | IMPLEMENTED AND ACTIVE (config only) / ABSENT (shipment events) |
| P | Capabilities | `manage_shipments`, `update_shipment_status`, `view_private_delivery_costs`, `view_private_origins` registered and included in Administrator recovery. Access UI does **not** expose shipment rows. | RESERVED/STUB ONLY (caps exist; no shipment screens) |
| Q | Needs Attention | `OperationalReadinessAssessor` + `NeedsAttentionQuery` scan **product catalogue readiness**, not paid-order/shipment failures. | IMPLEMENTED AND ACTIVE (catalog) / ABSENT (shipment issues) |
| R | Diagnostics | `ConfigurationHealthChecker` / hidden Technical Diagnostics; System Status. No shipment linkage checks. | IMPLEMENTED AND ACTIVE (config) |
| S | Translation/WPML | Text domain `cetech-woocommerce-delivery-engine`. `Domain Path: /languages`. No `languages/*.pot`. No `wpml-config.xml`. Integration registry is Null-only. WPML/WCML flags exist and default off. | IMPLEMENTED AND ACTIVE (gettext) / RESERVED/STUB ONLY (WPML adapter) / ABSENT (`wpml-config.xml`) |

### Runtime contracts / FeatureFlags

`RuntimeContracts` only preloads migration + variation-inspector interfaces. It has **no** shipment contracts.

`FeatureFlags` defaults include the three reserved shipment flags at `false`. Settings page lists them under Experimental as unavailable future features.

### Search terms that match only docs or unrelated words

Code hits for `carrier`, `dispatch`, `processing`, `package`, `group`, `snapshot` are almost entirely offers/rates/checkout/grouping/snapshots — not a shipment engine. No `fulfillment_id` / `fulfilment_id` persistence exists.

---

## 4. Historical order snapshot contract

Contract version: `OrderDeliverySnapshot::VERSION = '1'` (additive `delivery_group_id` / `groups[]` from Stage 8A). Selection intent contract: `ProductDeliverySelectionIntent::CONTRACT_VERSION = '1'`.

Snapshots persist on:

- `woocommerce_checkout_create_order_line_item`
- `woocommerce_checkout_order_created`

They are **order-creation** snapshots, not payment-time snapshots. They must remain immutable after write. Stage 14 must not mutate them to “fix” missing fields on historical orders.

### 4.1 Line snapshot (`_cetech_de_delivery_snapshot`)

| Field | Present after RC.4 checkout? | Stage 14 use |
|-------|------------------------------|--------------|
| Fulfilment Availability (code) | Yes | Consume |
| Fulfilment Choice (code) | Yes | Consume |
| Delivery Option ID | Yes (`delivery_offer_id`) | Consume |
| Public Delivery Option label | Yes | Consume (customer + staff public label) |
| Public offer description | Yes (looked up at snapshot time) | Optional; compact customer UI currently hides it |
| Route (`air` / `sea` / `local_delivery`) | **No** | Must freeze onto shipment at creation from offer record **by snapshotted offer ID**, never from current product config |
| Service level | **No** | Same as route |
| Public carrier | **No** | Same; offer has `carrier_visibility` + `carrier_name` |
| Private supplier | **No** | Do not recompute from current product. Optional one-time copy from snapshotted `rule_id` / offer at creation, then freeze. Treat as best-effort operational hint, not checkout truth, until additive snapshot enrichment exists |
| Private origin | **No** | Same |
| Logistics Profile | **No** | Same |
| Destination/service zone | Yes (`destination_zone_id`) | Consume (resolved at order creation from shipping address) |
| Configured Delivery Charge (line quote) | Yes (`quoted_amount`) when quote succeeded | Do **not** use as the shipment’s customer-paid total when the group used `fixed_per_shipment` |
| Paid shipping amount | Not on the line | Use group/package snapshot + WC shipping line |
| Checkout currency | Yes | Consume; never reconvert |
| Rate-card identity | Yes (`rate_card_id`, `rate_card_code`) | Consume as historical identity |
| Processing / transit / final-mile separately | **No** | Only combined `estimate_text` |
| Final estimated delivery | Yes (`estimate_text`) | Consume as **original** ETA |
| Selection fingerprint/hash | Cart has hash; **not snapshotted** | Do not use cart hash. Group id is the stable post-order key |
| Configuration fingerprint | On cart intent when ECR; **not snapshotted** | Do not require |
| Grouping identity | Yes (`delivery_group_id`) | **Authoritative shipment grouping key** |
| Pickup location | **No** (Stage 13F explicitly deferred) | Pickup is out of delivery-shipment V1 |

### 4.2 Package / group snapshot (`_cetech_de_delivery_quote_snapshot`)

| Field | Present? | Stage 14 use |
|-------|----------|--------------|
| Shipping method ID | Yes (`delivery_engine_selected_offer`) | Consume |
| Shipping method label | Yes (public option label after Stage 13F) | Display only; **not** identity |
| Package total delivery amount | Yes (sum of managed shipping lines) | Order-level paid shipping total |
| Per-group amount | Yes (`groups[].package_total_delivery_amount`) | **Customer-paid shipping for that shipment** |
| Currency | Yes | Consume |
| Destination zone | Yes | Consume |
| Groups[] | Yes when Stage 8 grouping ran | One planned shipment per non-pickup group |
| Group id | Yes | Idempotency + item linkage |
| is_pickup | Yes | Exclude from delivery shipments |
| display_index | Yes | Customer-friendly shipment number seed (`Delivery 1` → shipment 1) |

### 4.3 Shipping line

WooCommerce shipping line total is the genuine charged amount. Meta `cetech_de_group_id` links the line to the group. Stage 14 should prefer snapshot `groups[]`; shipping-line meta is a corroborating fallback, not a second grouping engine.

### 4.4 What exists only in cart/checkout (not snapshotted)

- Configuration fingerprint
- Cart selection hash
- `display_key`
- Runtime package array `cetech_de` (except what was copied into snapshots / shipping-line meta)
- Supplier / origin / logistics profile IDs
- Route / service level / carrier visibility
- Separate processing / transit / final-mile integers
- Pickup location id / address / instructions

### 4.5 What Stage 14 must never recompute from current product configuration

Anything already on the order snapshot:

- selected offer id and public label
- fulfilment availability/choice
- quoted/paid amounts and currency
- estimate_text (original promise)
- destination zone id captured at checkout
- rate-card id/code
- `delivery_group_id` / package groups

If a product’s current Site-wide Defaults or exceptions changed after payment, that must not reinterpret the paid order.

### 4.6 Recommended freeze-at-creation enrichment (not a second quote)

When creating a shipment, copy onto the **shipment** record (not back onto the order snapshot):

1. Directly from snapshots: group id, offer id, public label, estimate_text, currency, paid group amount, zone id, rate-card identity, availability, choice, order item ids/qty, product names from the WC order items.
2. One-time lookup of `delivery_offers` by **snapshotted offer ID**: freeze `route`, `service_level`, `carrier_visibility`, `carrier_name`. If the offer row is missing, store nulls and raise Needs Attention — do not substitute another offer.
3. Do **not** call `EffectiveConfigurationResolver` for historical orders.

Optional later (not required to start 14B): additive snapshot contract fields for new checkouts only. Historical RC.4 orders stay on snapshot version `1` as stored.

---

## 5. Existing grouping contract

Authoritative RC.4 grouping is Stage 8A:

**Class:** `DeliveryGroupIdentity` + `ShippingPackageBuilder` (`woocommerce_cart_shipping_packages`).

**Group id (never customer-visible):**

```text
{fulfilment_availability}|{fulfilment_choice}|{offer_id|pickup}
```

Examples: `in_warehouse|delivery|10`, `international_fulfilment|delivery|30`, `in_store|store_pickup|pickup`.

| Design expectation | RC.4 actual |
|--------------------|-------------|
| Air vs Sea | **Yes** — different offer IDs (Air vs Sea are different Delivery Options) |
| Delivery vs Pickup | **Yes** |
| Local vs International | **Yes** — availability is a group dimension |
| Same offer consolidates | **Yes** |
| Supplier/origin split | **No** — deferred in Stage 8A |
| Logistics-profile / separate-shipment restriction | **No** — not in the group key |
| Pickup location split | **No** — pickup is one shared `pickup` segment |
| Store Pickup shipping | Explicit **$0** managed rate so checkout can complete; not a delivery charge |

**Can Stage 14 derive shipment groups from historical snapshots?**

**Yes.** Line `delivery_group_id` + package `groups[]` + shipping-line `cetech_de_group_id` **are** the trusted checkout grouping result. Stage 14 must plan:

```text
historical order snapshot groups
→ one delivery shipment per non-pickup group
→ shipment items = order items whose line snapshot shares that group_id
```

**Do not** build a second grouping engine. Do not apply current consolidation rules to old orders.

**Known limitation versus full design:** two items that share availability + choice + offer but differ in supplier/origin will be **one** RC.4 shipment. That is the accepted RC.4 truth. A future grouping enhancement is a separate stage; it must not silently split historical orders.

---

## 6. WooCommerce native Fulfillments audit

Official sources used (2026-08-18):

- [WooCommerce Order Fulfillment merchant docs](https://woocommerce.com/document/order-fulfillment/)
- [Developer blog: Call for Testing — Order Fulfillments](https://developer.woocommerce.com/2025/09/23/call-for-testing-woocommerce-order-fulfillments/) (2025-09-23; comments into 2026)
- [WooCommerce 10.7 release notes](https://developer.woocommerce.com/2026/04/15/woocommerce-10-7/)
- WooCommerce trunk `FulfillmentUtils.php` (`Automattic\WooCommerce\Admin\Features\Fulfillments`)
- GitHub PRs #57429 (tables/datastore), #58713 (customer order display), #63573 (typed tracking getters), #63908 (developer docs)

Do not treat older internal assumptions as current.

### 6.1 Capability and stability

| Topic | Official position |
|-------|-------------------|
| Availability | WooCommerce **10.2+** |
| Enablement | Option `woocommerce_feature_fulfillments_enabled` = `yes`. Documented CLI: `wp option update woocommerce_feature_fulfillments_enabled yes`. Default **off**. Merchant UI toggle was “future” as of the 2025-09 call for testing |
| Stability | Still described as **beta**. WooCommerce 10.7 improved the PHP API; “coming out of beta is still TBD” (maintainer comment on the developer blog) |
| Namespace risk | Already moved `Internal\Fulfillments` → `Admin\Features\Fulfillments` in 10.7 |
| This plugin’s declared WC range | Requires **8.0+**, tested up to **10.9**. Fulfillments cannot be assumed present |
| FLAIROC (Stage 0B note) | WooCommerce **11.0.0** recorded 2026-08-10 — Fulfillments *code* likely present; **enabled state unknown** and must not be required |

### 6.2 PHP API / persistence

- Classes: `Fulfillment`, `FulfillmentsManager`, `FulfillmentsDataStore`
- Data store registered via `woocommerce_data_stores` (overridable)
- Table: `{prefix}wc_order_fulfillments` — `entity_type`, `entity_id`, `status`, `is_fulfilled`, `date_updated`, `date_deleted` (soft delete)
- Items: fulfillment line quantities (`item_id`, `qty`) — partial fulfillment is supported
- Tracking: meta `_tracking_number`, `_shipping_provider` / `_shipment_provider`, `_tracking_url`; 10.7 typed getters/setters
- Extensibility: public vs private (`_`-prefixed) fulfillment meta; custom statuses via `woocommerce_fulfillment_fulfillment_statuses`; order-level statuses via `woocommerce_fulfillment_order_fulfillment_statuses`
- REST: `/wc/v3/orders/{order_id}/fulfillments`
- Permissions: merchant docs cite `manage_woocommerce`
- HPOS: fulfillments live in dedicated tables keyed by order entity id — compatible in principle, independent of posts/postmeta
- Customer My Account: **fulfilled** fulfillments only; drafts hidden (PR #58713)
- Emails: notify on fulfill / update / delete of fulfilled records; drafts silent; templates under WooCommerce → Settings → Emails when feature enabled
- Auto-fulfill: downloadable items auto-fulfilled **by default**; virtual optional

### 6.3 Native statuses (not 1:1 with Stage 14)

**Per fulfillment (default):**

- `unfulfilled` (`is_fulfilled` = false) — merchant UI “Draft”
- `fulfilled` (`is_fulfilled` = true)

**Per order (computed):**

- `no_fulfillments` / `unfulfilled` / `partially_fulfilled` / `fulfilled`

Stage 14 needs: Awaiting fulfilment, Processing, Dispatched, In transit, Delayed / issue, Delivered, Cancelled.

Those are **not** native equivalents. Custom statuses can be registered, but `is_fulfilled` remains a boolean shipped/not-shipped flag, and order-level rollup only understands fulfilled vs not. Mapping seven CETECH states onto that boolean is a semantic mismatch, not a translation problem.

### 6.4 Gaps and risks for CETECH

- Beta + default-off + WC 8.0 portability
- Two-state model vs seven-state operations
- Customer UI/email behaviour fights the RC.4 compact contract (and can show “No tracking number available”)
- Fulfilled records may notify customers on edit/delete
- Soft-delete vs CETECH “never delete history because money moved”
- No first-class private supplier/origin/logistics/rate/ETA-original fields
- No Needs Attention
- No Delivery Engine capabilities
- REST exposure of fulfillment payloads unless tightly controlled
- Duplicate staff UIs if we also ship a Shipments workspace
- Auto-fulfill of downloadable items can create unexpected native records on mixed carts

**Conclusion:** Native Fulfillments is a useful **future projection/adapter**, not a safe V1 system of record.

---

## 7. Native vs Delivery Engine gap matrix

| REQUIREMENT | RC.4 ALREADY HAS | WOOCOMMERCE FULFILLMENTS HAS | DELIVERY ENGINE STILL NEEDS | RECOMMENDED OWNER |
|-------------|------------------|------------------------------|-----------------------------|-------------------|
| Multiple shipments per order | Multiple WC shipping packages + snapshot `groups[]` | Multiple fulfillments per order | Operational shipment records from those groups | DELIVERY ENGINE OWNS (consume RC.4 groups) |
| Items per shipment | Line snapshots + group id | Fulfillment items (`item_id`, `qty`) | Shipment-item rows linked to WC order items | DELIVERY ENGINE OWNS |
| Shipment number | `display_index` only | Fulfillment ID | Customer-friendly number | DELIVERY ENGINE OWNS |
| Internal status (7 states) | No | `unfulfilled` / `fulfilled` only | Machine codes + transitions | DELIVERY ENGINE OWNS |
| Public status label | No | Translated native labels | Gettext labels from machine codes | DELIVERY ENGINE OWNS |
| Private supplier | Config; **not** order-snapshotted | No | Freeze on shipment if available; hide from customers | DELIVERY ENGINE OWNS |
| Private origin | Same | No | Same | DELIVERY ENGINE OWNS |
| Logistics Profile | Config; not snapshotted | No | Same | DELIVERY ENGINE OWNS |
| Delivery Option | Offer id + public label snapshotted | No first-class field | Copy onto shipment | DELIVERY ENGINE OWNS |
| Air/Sea/local route | On offer record; not snapshotted | No | Freeze from offer id at creation | DELIVERY ENGINE OWNS |
| Service level | On offer record; not snapshotted | No | Same | DELIVERY ENGINE OWNS |
| Customer ETA snapshot | `estimate_text` | Optional “estimated delivery when available” | Original + optional later update fields | DELIVERY ENGINE OWNS |
| Later ETA update | No | Not a first-class original-vs-current model | `eta_original` immutable; `eta_current` updatable | DELIVERY ENGINE OWNS |
| Original ETA preservation | Snapshot text is immutable | No CETECH original/current split | Keep snapshot as original | DELIVERY ENGINE OWNS |
| Customer-paid shipping snapshot | Group amount + WC shipping line | No | Copy group amount/currency onto shipment | WOOCOMMERCE OWNS money; DELIVERY ENGINE snapshots it |
| Private internal cost | Rate cards may have private cost; not on order | No | Optional later; not required for 14 V1 tracking | DELIVERY ENGINE OWNS (defer column if unused) |
| Dispatch date | No | Possible via meta / fulfilled date | Explicit dispatch date | DELIVERY ENGINE OWNS; may *project* to WC later |
| Tracking carrier | Offer may name carrier; not shipment-level | Shipping provider | Manual public display name | DELIVERY ENGINE OWNS; WC can receive a copy later |
| Tracking number | No | Yes | Manual entry | DELIVERY ENGINE OWNS V1; SHARED THROUGH ADAPTER later |
| Tracking URL | No | Yes | Validated URL | Same |
| Public shipment note | No | Customer note on fulfilled updates | Sanitized public note | DELIVERY ENGINE OWNS |
| Private internal note | No | Private meta possible | Private note + capability | DELIVERY ENGINE OWNS |
| Status event history | No | Limited (update/delete/emails) | `shipment_events` | DELIVERY ENGINE OWNS |
| Audit history | Config audit log | Order notes (`FULFILLMENT` group in 10.7) | Shipment-sensitive audit events | DELIVERY ENGINE OWNS |
| Customer My Account cards | Compact Delivery details (no status/tracking) | Fulfilled-only native block | Extend compact contract | DELIVERY ENGINE EXTENDS existing renderer; do not replace with native block in V1 |
| Email presentation | Compact Delivery details | Native fulfillment emails | Optional shipment update email later; V1 may skip extra emails | DELIVERY ENGINE OWNS V1 copy; do not enable native fulfillment emails as authority |
| Customer notification | Order emails only | Native fulfill/update/delete | Flag-gated; failure must not break order | DELIVERY ENGINE OWNS |
| Staff shipment workspace | Order Delivery information box | Native fulfillments drawer | Dedicated Shipments list/detail | DELIVERY ENGINE OWNS |
| Needs Attention integration | Product readiness only | No | Paid-order creation failures, invalid tracking, broken links | DELIVERY ENGINE OWNS |
| Localization/WPML | Gettext; Null WPML adapter | WC i18n | All new UI strings gettext; copy operational IDs | DELIVERY ENGINE OWNS |
| Permissions | Caps exist; Access UI omits them | `manage_woocommerce` | Wire caps to screens; Access rows | DELIVERY ENGINE OWNS |
| Idempotent automatic creation | Snapshots once per checkout | No CETECH group key | Unique `(order_id, delivery_group_id)` | DELIVERY ENGINE OWNS |

---

## 8. Recommended data ownership

**WooCommerce remains authoritative for:** order, items, quantities, refunds, payments, order status, shipping address, shipping line **charged amount**, customer account, standard order emails.

**Delivery Engine remains authoritative for:** delivery configuration, grouping result (already snapshotted), shipment operational state, tracking as entered by staff, private logistics, ETA original/current, shipment event history.

**Do not create a second authoritative paid shipping amount.** Copy the WC/group snapshot onto the shipment and never recalculate it.

**Fields that stay Delivery Engine-specific even if a future WC fulfillment row exists:** supplier, origin, logistics profile, delivery offer id, route, service level, rate-card identity, original ETA, current ETA, internal notes, internal cost, idempotency key, delivery_group_id, status machine code beyond fulfilled/unfulfilled, Needs Attention reasons, Delivery Engine audit ids.

---

## 9. Recommended persistence architecture

Evaluate:

| Approach | Verdict for Stage 14 V1 |
|----------|-------------------------|
| **A. Native Fulfillments as canonical repository** | Reject. Beta, optional, two-state, incomplete domain, portability failure below WC 10.2, customer/email/privacy mismatch. |
| **B. Custom tables as canonical repository** | Accept as **V1 write path**. |
| **C. Domain + repository abstraction with WC adapter or custom fallback** | Accept as **structure**. V1 wires only the custom repository. WC adapter is reserved, unwired, and must not dual-write. |

Recommended boundary (names chosen to avoid colliding with WooCommerce’s `Fulfillment` class):

```text
ShipmentService
    ↓
ShipmentRepositoryInterface
    ↓
WpdbShipmentRepository          ← V1 canonical
WooCommerceFulfillmentsAdapter  ← reserved; not V1 system of record
```

This matches the Design/Expectations adapter *boundary* without pretending native Fulfillments can own CETECH operational truth today.

Rollback: disable `enable_shipment_records`. Do not drop tables. Do not delete rows. Checkout continues on RC.4 snapshots.

---

## 10. Shipment identity / idempotency

**Do not use** translated labels, current product settings, public shipping method titles, or cart hashes.

**V1 unique identity:**

```text
idempotency_key = {order_id}|{delivery_group_id}
```

`delivery_group_id` already includes availability, choice, and offer id (or `pickup`). Order item IDs belong on `shipment_items`, not in the unique shipment key (or one compatible group would explode into N shipments).

Source/origin are **not** in the RC.4 group key and must not be added for historical orders.

| Scenario | Behaviour |
|----------|-----------|
| First paid-order creation | Insert shipment + items + `created` event |
| Payment callback retry | Unique key hit → no-op success |
| Repeated status transition to Processing | Creation hook no-op; status service is separate and itself idempotent per transition |
| Plugin reload / folder replace | Same key; no duplicates |
| Manual retry after failed creation | Same planner; insert missing groups only |
| Partial create (shipment row written, items failed) | Needs Attention + retry completes items; do not insert a second shipment for the same key |
| Flag off then on | Existing rows remain; creation resumes for orders still lacking rows |
| Destructive rollback | Forbidden. Feature-flag off only |

Pickup groups (`…|store_pickup|pickup`) are **not** inserted (see Store Pickup).

Customer-facing number: `display_index` from snapshot, e.g. `#{order_number}-D{n}` for delivery groups. Do not expose raw group ids.

---

## 11. Creation trigger

RC.4 snapshots already write at **order created**. That must not change.

Shipment creation is a **post-payment** operation.

| Candidate | Safe for V1? |
|-----------|----------------|
| `woocommerce_checkout_create_order_line_item` / `woocommerce_checkout_order_created` | **No** — unpaid pending orders would get operational shipments; checkout would be coupled to shipment writes |
| `woocommerce_payment_complete` | **Yes** — primary |
| Status → `processing` / `completed` | **Yes** — fallback for gateways that skip `payment_complete` or COD/manual later |
| Configurable paid-state trigger | **Yes** — default `payment_confirmed`; do not default to order-created |
| Manual “Create shipments” | **Yes** — recovery only, same planner, same idempotency |

Recommended implementation:

```text
woocommerce_payment_complete
+ woocommerce_order_status_changed → processing|completed when order is paid
→ ShipmentCreationService::create_for_order( $order )
```

Rules:

- Checkout / payment success **must not** depend on shipment creation.
- On failure: leave order and paid amount intact; log details; Needs Attention; staff retry.
- `$0` Store Pickup–only paid orders: planner returns zero delivery shipments (success, not a failure).
- Mixed carts: create delivery shipments; skip pickup groups.

There is currently **no** `woocommerce_payment_complete` listener in this plugin.

---

## 12. Status model

Machine codes (snake_case, never translated, never compared as labels). Align with `ARCHITECTURE-PLAN.md` and existing enums:

| Code | Public default label (gettext) |
|------|--------------------------------|
| `awaiting_fulfilment` | Awaiting fulfilment |
| `processing` | Processing |
| `dispatched` | Dispatched |
| `in_transit` | In transit |
| `delayed` | Delayed / issue |
| `delivered` | Delivered |
| `cancelled` | Cancelled |

`processing` is a **shipment** code. It must never be compared to WooCommerce order status `processing`.

**Normal transitions**

```text
awaiting_fulfilment → processing → dispatched → in_transit → delivered
awaiting_fulfilment → cancelled
processing → cancelled
in_transit → delayed → in_transit
delayed → delivered
dispatched → delayed
```

**Corrective transitions** (capability + required internal note): reverse one step, or `delivered` → `in_transit` / `delayed` for mis-clicks. Do not silently jump Air/Sea or rewrite snapshots.

**WooCommerce order status:** do **not** auto-complete the parent order when one shipment is Delivered. Parent completion remains a WooCommerce/staff decision. Document a future optional policy (“all delivery shipments delivered”) — not V1 automation.

Native Fulfillments mapping if a later adapter exists (not V1 writes):

- CETECH `awaiting_fulfilment` / `processing` / `cancelled` → WC `unfulfilled`
- CETECH `dispatched` / `in_transit` / `delayed` / `delivered` → WC `fulfilled` **only if** we deliberately treat “handed to carrier” as fulfilled — delayed/in-transit being `fulfilled` is already a semantic lie. This is why WC must not be canonical.

---

## 13. Tracking model

V1 is **manual**. No carrier API, polling, labels, or webhooks.

| Field | Owner | Rules |
|-------|--------|------|
| Public carrier display name | Delivery Engine | Optional until tracking exists; sanitized text |
| Tracking number | Delivery Engine | Optional; trim; no empty Track control |
| Tracking URL | Delivery Engine | If present: `esc_url_raw`, allow only `http`/`https`, reject javascript/data; invalid URL → refuse save + Needs Attention |
| Dispatch date | Delivery Engine | Optional datetime; not inferred from “today” without staff input |
| Public shipment note | Delivery Engine | Sanitized; customer-visible |
| Private note | Delivery Engine | Capability `view_private_origins` is **not** the right cap; use shipment manage + do not send to customers |

Show **Track shipment** only when a valid URL exists (number-only is not enough for a control that claims to track). If number exists without URL, staff see both; customers see the number as text without a fake button.

---

## 14. Store Pickup

**Recommend A:** leave Store Pickup **outside** delivery shipment records in Stage 14 V1.

Reasons:

- RC.4 already stores pickup as a snapshot group with `$0` shipping
- Compact customer contract already has Ready for pickup
- No courier tracking, QR, or OTP in V1
- Native Fulfillments “no shipment information” still creates a fulfillment record and status model that does not match pickup-ready/collected
- Smallest correct behaviour: pickup-only paid orders create **zero** delivery shipments (not an error)

Staff continue using the WooCommerce order + existing Delivery information box for pickup lines. Pickup-ready/collected is a later stage.

Mixed orders: delivery groups → shipments; pickup groups → skipped.

---

## 15. Admin UX

RC.4 normal menu:

1. Overview  
2. Site-wide Defaults  
3. Delivery Options  
4. Delivery Areas  
5. Delivery Charges  
6. Pickup Locations  
7. Product Exceptions  
8. Needs Attention  
9. Settings  

**Insert Shipments after Product Exceptions, before Needs Attention.**

That is configure → exceptions → **operate** → issues → settings. Do not restore Legacy Delivery Rules or normal Technical Diagnostics.

Use a normal `WP_List_Table` screen (search, status/delivery-option/tracking filters, pagination) plus a detail view. Link to the WooCommerce order. Do not embed a SPA.

**List columns:** shipment number, order, customer, public Delivery Option, status, ETA (current if set else original), tracking state (none/invalid/ready), updated.

**Detail:** customer-safe summary, items, status actions, tracking fields, public note, private operations (capability), event/audit history.

Progressive disclosure: Customer Support can view status/tracking/public notes; private origin/cost remain hidden without `view_private_origins` / `view_private_delivery_costs`.

---

## 16. Customer / My Account UX

Audit of RC.4 compact contract (Stage 13F):

Customer sees only Delivery option + Estimated delivery (+ pickup extras if present). No fulfilment labels, no duplicate shipping totals, no private logistics.

Stage 14 should **extend** that block on **My Account → Orders → View Order** (and thank-you if the same hook runs), grouped by shipment:

```text
Shipment {customer-friendly number}
{Public Delivery Option}
Status: {translated public status}
Estimated delivery: {eta_current or eta_original}
{Items}
[Track shipment]  ← only if valid URL
{optional public note}
```

**Do not** add `My Account → Deliveries` in V1. No public tracking pages. No native Fulfillments customer block as the CETECH UI (if WC feature is on, document coexistence risk; V1 should not require it and should not rely on it).

Assets: enqueue only on view-order / thank-you / shipment admin screens, matching current customer-summary CSS gating.

---

## 17. Needs Attention

Today: product operational readiness only (`NeedsAttentionQuery` scans published products).

Stage 14 should add a **separate operational-issues list**, not mix shipment failures into product exception reasons.

Surface (actionable only):

- Paid Delivery Engine order with missing snapshots
- Paid order, shipment flag on, creation failed
- Partial shipment (header without items, or items without header)
- Invalid tracking URL stored (should be blocked on save; if present, repair)
- Idempotency conflict / duplicate detected
- Snapshotted offer ID missing when route freeze is required for staff routing

Do **not** surface:

- Pickup-only orders with zero shipments
- Flag off (expected)
- Happily awaiting fulfilment with no tracking yet
- Native Fulfillments disabled

Do not full-scan shipment tables for Overview badges without indexed counts.

---

## 18. Permissions / Administrator recovery

Existing caps already in `Capabilities::ALL` and `ADMINISTRATOR_RECOVERY`:

- `manage_shipments`
- `update_shipment_status`
- `view_private_delivery_costs`
- `view_private_origins`

`AdministratorAccessRecovery` uses native `manage_options` and restores **all** `ADMINISTRATOR_RECOVERY` caps. Adding shipment screens must **not** introduce a new recovery dependency. Do not gate recovery on `manage_shipments`.

`RoleAccessService` currently omits shipment permissions from the Access matrix. Stage 14 should add Access rows (view/manage shipments, update status) without making Administrator configurable. Bump `Capabilities::VERSION` additively if new caps are introduced; prefer reusing the four existing ones first.

Suggested split:

- `manage_shipments` — list/detail, tracking, public notes, retry creation
- `update_shipment_status` — status transitions
- `view_private_origins` — supplier/origin on shipment detail
- `view_private_delivery_costs` — internal cost if/when shown

Server-side checks on every action + nonce. Hidden UI is not authorization.

---

## 19. Language / WPML / WCML

**Text domain:** `cetech-woocommerce-delivery-engine`  
**wpml-config.xml:** **absent** today.

Without WPML: site language via normal WordPress localization. Absence must not error (already true via Null integration).

With WPML (future adapter; not required to ship 14 V1 strings):

**Translate:** public status labels, Track shipment / Estimated delivery UI, public notes if multilingual authoring is added, public carrier descriptions, customer emails, My Account/admin labels, accessibility strings.

**Copy/share (never translate as authority):** shipment id/number, order/item linkage, status machine code, offer/supplier/origin/profile IDs, tracking number/URL, dispatch date, paid amount, currency, rate identity, group id, audit ids.

Rules: no business logic on translated strings; placeholders; `_n()` for counts; locale dates/money; email language follows WooCommerce/customer order language when available; language switch must not change shipment rows.

**Does Stage 14 need `wpml-config.xml`?**  
**Yes, add a starter file in implementation** if any customer-visible strings are stored as WordPress/WooCommerce meta or a CPT. If V1 stores public notes only in custom tables, `wpml-config.xml` cannot translate those rows; document that WPML public-note translation waits for the WPML adapter. Still add `wpml-config.xml` for any order-meta we might add, and keep all UI in gettext. Do not block 14B on WPML.

**WCML:** historical paid shipping stays in snapshotted currency/amount. No reconversion. Currency never drives route eligibility. Internal cost, if added later, is private and distinct.

---

## 20. Security / privacy

Public-safe DTO (`CustomerShipmentView`) may include: customer shipment number, public offer label, public status label, ETA text, item names/qty, tracking URL/number when valid, public note.

Private DTO / staff record may include: supplier, origin, logistics profile, rate-card ids, group id, internal notes, internal cost, actor ids, raw status codes.

Required: capability + nonce on admin POSTs; prepared SQL; sanitize/validate/escape; order ownership on My Account (`$order->get_customer_id()` vs current user; pay-for-order keys already WC-owned); tracking URL allow-list; no private fields in REST/Store API (do not add a public shipment REST in V1); audit status/tracking/private-note changes.

Existing snapshot strippers already hide `_cetech_de_*` and `cetech_de_*` from formatted item meta — keep that.

---

## 21. Performance / caching

- No unpaginated shipment lists
- No site-wide `SELECT *` for Overview
- Indexed filters: `order_id`, `status+updated_at`, unique `idempotency_key`
- Planner loads the one order via WC CRUD; batch order items; no N+1 offer lookups (preload offer ids)
- Do not enqueue shipment CSS/JS on product/cart/checkout
- My Account / view-order / shipment AJAX: exclude from full-page cache (already required for My Account)
- Do not use Action Scheduler for ordinary single-order creation (synchronous on payment hook, isolated in try/catch). Optional retry queue is later if volume requires it
- Product, cart, and checkout query budgets must remain unchanged when the shipment flag is off **and** when it is on (hooks must return immediately on unrelated requests)

---

## 22. Refund / cancellation behaviour

WooCommerce owns money. Shipment history is not a ledger.

| Event | Shipment behaviour |
|-------|-------------------|
| Full order cancelled before dispatch | Staff or policy may mark remaining shipments `cancelled`; do not auto-delete |
| Full refund after dispatch | Do not auto-cancel in-transit/delivered; staff decide; history remains |
| Line refund | Do not auto-cancel sibling shipments; optionally Needs Attention if a delivered item is fully refunded |
| Cancel shipment before dispatch | Allowed; does not refund money by itself |
| Mixed Air + Sea | Independent |

Never recalculate original paid delivery snapshot. Never delete events because a refund happened.

---

## 23. Failure / recovery

| Failure | Behaviour |
|---------|-----------|
| Creation exception | Catch, log, Needs Attention, order intact |
| Partial writes | Retry is additive/idempotent; unique key prevents dupes |
| Invalid/missing snapshot | Do not invent grouping; Needs Attention |
| Native Fulfillments unavailable/disabled/API change | Irrelevant to V1 canonical path |
| Tracking save invalid | Refuse; no silent empty Track button |
| Email notification failure | Log; shipment state already saved |
| Corrupted item linkage | Needs Attention + staff retry; do not checkout-fail |
| Feature flag off | Stop new writes; keep data |

Prefer feature-flag rollback over uninstall/drop tables.

---

## 24. Proposed schema change

**RC.4 schema target stays `3` during this audit.**

**Stage 14 implementation requires Schema 4.** Native Fulfillments cannot replace these tables for V1.

Keep the existing `TableNames` convention (`{prefix}delivery_engine_{suffix}`). Historical names remain optimal:

- `delivery_engine_shipments`
- `delivery_engine_shipment_items`
- `delivery_engine_shipment_events`

Proposed columns (documentation only — not migrated in 14A):

**shipments**

- `id` PK
- `order_id` NOT NULL
- `shipment_number` VARCHAR
- `idempotency_key` VARCHAR UNIQUE
- `delivery_group_id` VARCHAR
- `status` VARCHAR (machine code)
- `fulfilment_availability` VARCHAR
- `fulfilment_choice` VARCHAR
- `delivery_offer_id` NULL
- `delivery_offer_public_label` VARCHAR
- `route` VARCHAR NULL
- `service_level` VARCHAR NULL
- `carrier_visibility` VARCHAR NULL
- `public_carrier_name` VARCHAR NULL
- `destination_zone_id` NULL
- `logistics_profile_id` NULL (private)
- `supplier_id` NULL (private)
- `origin_id` NULL (private)
- `currency_code` CHAR
- `customer_paid_shipping_amount` DECIMAL
- `rate_card_id` / `rate_card_code` NULL
- `eta_original` VARCHAR NULL
- `eta_current` VARCHAR NULL
- `tracking_number` / `tracking_url` / `tracking_carrier_display` NULL
- `dispatch_at` / `delivered_at` NULL
- `public_note` TEXT NULL
- `private_note` TEXT NULL
- `wc_fulfillment_id` BIGINT NULL (reserved for future adapter; unused in V1)
- `created_at` / `updated_at`

**shipment_items**

- `id` PK
- `shipment_id` / `order_id` / `order_item_id`
- `product_id` / `variation_id` NULL
- `quantity`
- `product_name_snapshot`
- UNIQUE (`shipment_id`, `order_item_id`)

**shipment_events**

- `id` PK
- `shipment_id`
- `event_type`
- `from_status` / `to_status` NULL
- `public_note` / `internal_note` NULL
- `actor_user_id` NULL
- `source` (`system` / `staff` / `retry`)
- `event_at` / `created_at`

Indexes: UNIQUE `idempotency_key`; KEY `order_id`; KEY (`status`, `updated_at`); KEY `shipment_items.order_item_id`; KEY (`shipment_id`, `event_at`).

Do not store translated status text. Do not FK to WooCommerce tables. Soft-retain rows; do not hard-delete on refund.

Internal cost column may be reserved NULL in schema 4 or deferred to a later schema if unused — prefer a nullable column now to avoid schema 5 for one field.

---

## 25. Proposed feature flags

Reuse the three reserved flags. Do **not** invent a parallel set.

| Flag | V1 recommendation |
|------|-------------------|
| `enable_shipment_records` | Master switch: schema consumers, paid-order creation, staff Shipments menu. Default **off** until Stage 14 is accepted and enabled deliberately |
| `enable_tracking_links` | Customer Track shipment control. Staff may still enter tracking when shipment records are on. Default off until customer QA passes |
| `enable_customer_timeline` | Keep reserved/off. V1 uses extended compact order cards, not a separate timeline or My Account endpoint |

Do not auto-enable on update. Checkout flags stay independent. Turning shipment flags off must not disable RC.4 snapshots or shipping calculation.

---

## 26. Test strategy

Claim only tests this repo can actually run: PHPUnit (currently 322 tests locally in RC.4 finalization) + JS unit tests. Playwright is not a supported root gate. FLAIROC physical QA is owner-run.

### UNIT

- Snapshot groups → shipment plan (pickup excluded; Air/Sea split; compatible consolidate)
- Idempotency key stability (retries, item-id independence)
- Status transition matrix + rejected illegal jumps
- Tracking URL validation
- Public DTO excludes private fields
- Status codes are language-neutral

### INTEGRATION (WP/WC test harness where present)

- Paid order → N shipments
- One group / several groups
- Store Pickup zero shipments
- `payment_complete` retry
- HPOS CRUD only
- Capability deny/allow
- Audit/event writes

### REGRESSION (must stay green; no checkout edits in 14A–14C except isolated hooks)

- RC.4 selector, simple + variable, cart persistence, shipping amount, public label, checkout validation, immutable snapshots, compact customer presentation, Access/recovery

### CUSTOMER (manual / later E2E)

- View order cards, tracking link presence/absence, delayed + public note, no private leakage, mobile, logged-in

### LANGUAGE

- WPML absent (required)
- WPML present only if a test site has it — do not claim FLAIROC WPML coverage without a run
- Language switch does not change ids/status codes

### PERFORMANCE

- Product/cart/checkout query count unchanged with flag off
- Shipment list paginates
- My Account adds a bounded shipment query for that order id only

---

## 27. Seven-day implementation plan

Adjusted from the tentative calendar after the audit: schema is required; native Fulfillments is not the first write path; grouping already exists; customer UI is an extension of Stage 13F.

| Day | Stage | Independently testable outcome |
|-----|-------|--------------------------------|
| 1 | **14B** Persistence foundation | Schema 4 migration (idempotent), domain objects, `ShipmentRepositoryInterface` + WPDB repo, flags remain off, **zero checkout/runtime behaviour change** |
| 2 | **14C** Planner + creation | Snapshot→plan, payment/paid-status hooks, idempotency, failure logging + Needs Attention stub, manual retry. Flag still default off |
| 3 | **14D** Staff workspace | Shipments submenu, list table, detail, order link. Caps enforced |
| 4 | **14E** Tracking + customer cards | Manual tracking validation; extend compact My Account/thank-you contract; `enable_tracking_links` |
| 5 | **14F** Status, refunds, audit, Access | Transitions, no auto-complete, refund non-deletion, Access rows, Administrator recovery regression |
| 6 | **14G** Automated gates | Unit/integration/regression/language-neutral/performance assertions that exist in-repo |
| 7 | **14H** Package + operational QA + docs/training | ZIP/identity only after 14B–14G accepted; FLAIROC only when instructed; no Code Snippets |

Do not combine 14B–14H into one commit train. Do not start carrier APIs, Blocks, POD, or bulk import.

**Recommended Stage 14B (next, after this audit is reviewed):** schema 4 + repository + domain model only. No shipment creation hooks yet unless explicitly included as dead code behind the still-off flag. Prefer hooks in 14C so 14B is a no-behaviour migration.

---

## 28. Documentation / publication state

Updated by this stage:

- `docs/STAGE-14A-SHIPMENT-ARCHITECTURE-AUDIT.md` (this file)
- `docs/AI-HANDOFF.md` current-status block only

Not updated: plugin version, schema target, runtime code, training screenshots (still RC.4 checkout-era), `PROJECT-GOVERNANCE.md` stale “rc.3” baseline line (noted; not rewritten here).

---

## 29. Blockers / decisions needed before 14B

1. **Accept custom tables as V1 canonical store** (recommended) versus forcing native Fulfillments now.  
2. **Accept RC.4 grouping as historical truth**, including no supplier/origin split.  
3. **Accept Store Pickup = no delivery shipment in V1.**  
4. **Accept schema 4** on implementation (not during this audit).  
5. Confirm **no FLAIROC / no packaging** until an implementation stage is explicitly instructed.

None of these require runtime work in 14A.

---

## 30. Completion report (14A)

### Summary

Stage 14A audited RC.4 shipment-adjacent code, the order snapshot/grouping contract, and current WooCommerce Fulfillments. Recommendation: Delivery Engine schema-4 tables as canonical V1 persistence behind a repository interface; consume historical snapshot groups; do not implement shipment runtime yet.

### Scope intentionally excluded

All shipment implementation, schema 4 migration, flags enabled, checkout changes, FLAIROC, packaging, tags, version bump, WC Fulfillments adapter implementation, carrier APIs, Blocks, POD, bulk import, Stage 15.

### Files changed

Documentation only (see AI-HANDOFF + this file).

### Schema / migration impact

None in 14A. Schema 4 is **proposed** for 14B+.

### Runtime behaviour

Unchanged.

### Security / privacy / shipping-integrity / compatibility / performance

No runtime change. Design preserves snapshot immutability, private logistics, HPOS CRUD, WC 8.0 portability, and checkout isolation.

### Tests

No new tests run as a release gate; this stage is documentation. Existing PHPUnit suite was not re-executed solely for a docs change.

### Staging checks required

None for 14A. Do not install anything on FLAIROC for this stage.

### Known limitations

RC.4 snapshots omit route, supplier, origin, logistics profile, and split ETA components. V1 freezes route/service from the snapshotted offer ID at shipment creation. Supplier/origin grouping is not in RC.4 group keys.

### Recommended next phase

**Stage 14B — persistence foundation (schema 4 + domain/repository).** Do not start until this audit is reviewed.
