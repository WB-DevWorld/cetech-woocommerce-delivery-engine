# Commerce Bridge interface: what the Delivery Engine gives an order reader

Written 2026-11-28 for phase 0 of the M3–M8 batch (authorization §7, "Commerce Bridge interface").

The Commerce Bridge is a separate system in the same GitHub organisation. Its repository is private and was read only, at its default branch head of 25 November 2026 (version 0.11.0); nothing there was changed. This public note keeps to what the Delivery Engine (DE) offers and what any order reader needs from it. The detailed reading notes on the Bridge, with file references, were handed to Frank outside this repository, because describing a private repository's code in a public one is Frank's call.

## 1. How the Bridge reads orders, in short

- It **pulls** orders from the WooCommerce REST API v3 (`/wp-json/wc/v3/orders`), using a WooCommerce REST key, either when a person asks or hourly. It reads orders changed since its last read, with an overlap, so a repeated read is harmless.
- It writes nothing back to an order.
- It receives no webhooks today: its receiving endpoint is built but switched off, and staging suppresses WooCommerce webhooks anyway.
- As written, it can reach only `/wp-json/wc/v3/` routes.
- It copies the order currency (GHS) as it is and does no conversion.

## 2. What the DE writes on an order today (`master` `08422f06`)

All of this sits behind feature flags whose code defaults are off (`src/Bootstrap/FeatureFlags.php`).

### Shipping lines

- One WooCommerce shipping line per delivery group. All of them use method id `delivery_engine_selected_offer` (`src/Infrastructure/WooCommerce/Shipping/SelectedOfferShippingMethod.php`). Each line's meta `cetech_de_group_id` is the group identity.
- **Pickup** is a `delivery_engine_selected_offer` line at an explicit **₵0**, titled "Store Pickup" (`SelectedOfferShippingRateCalculator.php`). It is not WooCommerce's own `local_pickup`.
- **Delivery lines** are titled "Delivery to {locality}". Two groups to the same town therefore carry the same title, so a reader must key on the line, not the title.
- The group identity (`DeliveryGroupIdentity.php`) encodes internal profile, supplier and origin ids. It is an internal key, not a public field.

### Order and line meta

The registry of these keys is `DataLifecycleManifest::WOO_META_KEYS`. They are versioned, protected meta: an internal contract, not a published API.

- Line snapshot `_cetech_de_delivery_snapshot`. It holds per-line delivery or pickup details, including the customer's delivery address on multi-destination orders.
- Package snapshot `_cetech_de_delivery_quote_snapshot`, with `groups[]`.
- Shipment-creation state and a cash-on-delivery marker.

### Shipments

- Shipments live in the DE's own tables (`delivery_engine_shipments`, `_items`, `_events`). They are numbered `{order number}-D{n}`, for example `50386-D1`.
- Status codes: `awaiting_fulfilment`, `processing`, `dispatched`, `in_transit`, `delayed`, `delivered`, `cancelled`.
- A shipment is created when payment completes. The DE never changes the order status.
- Pickup groups get no shipment, so "ready for collection" and "collected" are not recorded anywhere.
- A shipment change does not save the WooCommerce order. A reader that polls by order modification date therefore does not see dispatch or delivery. This is inferred from the code and has not been run.

### Events and routes

- No REST routes and no webhook topics.
- The order's REST output is not extended.
- The only action fired is the boot-time `cetech_de_services_registered`.

## 3. What an order reader needs, and how M8 provides it on the DE side

| Need | DE answer, built in M8 (lane L7 API, lane L8 events) | Registry |
|---|---|---|
| One delivery charge per group, not one summed figure | A published, versioned **delivery block** per order. For each group: the shipping line id, fulfilment choice (`delivery` / `store_pickup`), public label, customer-paid amount, tax and currency, pickup location name and public address, the shipment number(s) and status. The internal ids are never included | `DE-API-*` |
| Reach it with the Bridge's existing WooCommerce key on `/wc/v3/` | The same block added to the WooCommerce order REST response (`woocommerce_rest_prepare_shop_order_object`), plus the DE's own read-only route (`/wp-json/cetech-de/v1/…`). Whether a WooCommerce key authenticates on a DE namespace must be proven in a test; if it does not, the order-response block is the supported path | `DE-API-*`, `DE-SEC-*` |
| Notice shipment changes without order changes | A **changed-since cursor** on the shipments read route, which fits a pull reader. Domain events (`shipment.created`, `shipment.status_changed`, `shipment.tracking_updated`, `order.delivery_frozen`) with ids and occurrence times, and signed, replay-protected webhooks with retries for when webhooks are allowed | `DE-EVENT-*` |
| Stable field names | An OpenAPI document and JSON Schemas for the delivery block, shipments and events, versioned under the namespace | `DE-API-*` |
| No private data | No supplier, origin, cost, rate card or precise customer address in any public payload, log or cache (constitution §15). The block shows the pickup location's public address, never a customer address | `DE-SEC-*` |
| Pickup collection state | Not in M8. It needs a pickup fulfilment record (ready / collected), which belongs with M7 pickup hours and capacity. Listed for Frank | `DE-PICKUP-*` |

Not changed by the DE: order status, order totals, fee lines, billing and shipping address fields.

## 4. Open checks for the DE side

1. Whether WooCommerce REST v3 (`context=view`) already returns the underscored `_cetech_de_*` meta and `cetech_de_group_id` in `meta_data`. If it does, a REST reader is already receiving the protected snapshots, which hold delivery addresses. One read of a staging order settles it; M8's security review treats it as a finding to close.
2. The flag values on staging, read at phase 1. The live site is not known and not reachable.
3. WooCommerce key authentication on a DE namespace (see the table above).

## 5. For Frank: changes on the Bridge side

Claude does not touch the Bridge repository. Hand these to its owner:

1. Read shipping lines one by one (id, method, total, tax, `cetech_de_group_id`) instead of the de-duplicated titles. Consider one charge row per line.
2. Recognise the DE pickup shape (a ₵0 `delivery_engine_selected_offer` line titled "Store Pickup") as well as core `local_pickup`.
3. If the DE's own route is used, allow the client to reach it. Today it can only reach `/wc/v3/`.
4. Decide how DE shipment numbers map to ERPNext records (for example Delivery Notes), and when.
5. Line up refund handling. The Bridge sends any refund to a person. The DE cancels a shipment only on a full-quantity refund and flags the rest for review.
6. Do not read "order completed" as "delivered". Delivered state lives only in DE shipments.
7. The Bridge's own rules currently forbid touching the DE. They need Frank's word before delivery work starts there.
8. If DE snapshots do reach the Bridge's stored copy of orders (check 1 above), its personal-data clearing must cover them.
