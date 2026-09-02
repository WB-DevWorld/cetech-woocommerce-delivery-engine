# Post-RC.9 per-item customer context — Stages 1–2 foundation

**Identity:** `1.0.0-dev.peritem.1`  
**Branch:** `feat/post-rc9-per-item-context`  
**Schema:** `5` (unchanged)  
**Not:** RC.10, Stage 15, storefront UI, packaging, FLAIROC, training, WCFM, WPML

## Provenance

Rooted from immutable RC.9 `v1.0.0-rc.9` / `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5`.

Cherry-picks only:

| Stream | Source SHA | Cherry-pick on this branch |
|---|---|---|
| `1.0.0-dev.cartstate.1` | `39f61c1bc79e6248f1e64cdb945f62edf61b357b` | `f6b5c12` |
| `1.0.0-dev.blocks-snapshot.1` | `54c9894f492906a24d30c939f831f4538d6b0255` | `729f4e8` |

WCFM, WPML, and training streams were not merged.

## CustomerCartContext contract

Cart item key: `cetech_de_customer_context` (not stuffed into `cetech_de_delivery_selection`).

Fields:

- `contract_version` (1)
- `fulfilment_choice`
- `delivery_offer_id` (null for pickup)
- `pickup_location_id` (required when `store_pickup`; fail closed if missing)
- `matching_location`
- `delivery_address` (complete physical address + nested `recipient`)
- `matching_identity` (SHA-256)
- `delivery_location_identity` (SHA-256)

Administrator-derived labels, ETA, fingerprints, hashes, `rule_id` are not stored here.

## Identities

**Matching identity:** country, state, city, postcode.  
**Delivery-location identity:** those fields plus `address_1` / `address_2`.  
**Recipient/contact:** `first_name`, `last_name`, `company`, `phone` — frozen on v2 order snapshots, never in cart keys or group IDs.

Canonical serialization is versioned JSON with `ksort` + `json_encode` (not `wp_json_encode`, so WP filters cannot change hashes).

## Cart identity

`CartLineCustomerIdentity` uses: product, variation, variation attributes, fulfilment choice, offer **or** pickup location id, customer location hash, other non-`cetech_de_*` cart data.

Never: raw address JSON, recipient, phone, labels, ETA, `rule_id`, fingerprint, selection hash, `issued_at`.

Incomplete delivery uses `matching_identity`; complete delivery uses `delivery_location_identity`. Incomplete → complete may rekey.

## Mutation

`CartCustomerContextMutationService` is the only cart rekey/split/move service:

- `updateWholeLine` — move all quantity
- `splitQuantity` — move N (`N > 0` and `N <= qty`), decrement source, create or consolidate target
- invalid quantity is rejected against the original contents (atomic)

## DeliveryGroupIdentity

- v1: `availability|choice|offer_or_pickup`
- v2: `availability|choice|offer_or_pickup|destination`
- runtime only: `|reselect` (never historical)
- pickup destination segment: `pickup`; offer segment `p{id}`
- delivery destination: first 16 hex chars of the appropriate identity hash (`m` prefix when matching-only)

`ShippingPackageBuilder` and `SelectedOfferShippingRateCalculator` both compare `fromCartItem()`.

## Group-id column length (schema 5)

`delivery_group_id varchar(191)` on `shipments`.  
`idempotency_key varchar(255)` = `order_id|delivery_group_id`.

`DeliveryGroupIdentity::worstCaseLength(true)` includes the incomplete matching `m` prefix and runtime `|reselect`: **87** characters. Historical (no `|reselect`): **78**. Column is `varchar(191)`. `idempotency_key` worst case (`20` digit order id + `|` + historical group id) is **99**, against `varchar(255)`. Schema stays **5**.

## Snapshot v2

New orders with `CustomerCartContext` write `snapshot_version = 2` including frozen matching location, delivery address/recipient, destination hashes, pickup location id, and destination-aware group id.

v1 orders remain readable and are never rewritten. Historical planner accepts 3-part v1 and 4-part v2 and rejects `|reselect`.

## Blocks mapping

Transient `_cetech_de_cart_item_key` remains preferred. Fallback product/variation/qty mapping claims a candidate only when exactly one remains. Ambiguous fallback fails closed.

## Privacy

Raw addresses never appear in WooCommerce cart keys, group IDs, HTML ids, or generic logs. Logger strips `first_name`, `last_name`, `phone`, `address_1`, `address_2`.

## Automated tests

- Focused + full PHPUnit: **923 tests, 5155 assertions, OK** (5 pre-existing deprecations)
- JS: **6 files / 33 tests passed**
- PHP lint `src` + `database`: 0 failures
- `composer validate --no-check-publish`: valid

## Local Docker QA (no storefront UI)

Lab: `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-local-qa` (`http://localhost:8088`). Fixture: `wp-cli/per-item-context-qa.php`. Plugin copied, not packaged.

| Check | Result |
|---|---|
| A same product / two destinations → two WC cart keys | **PASS** |
| B same destination consolidates | **PASS** |
| C qty 2 split one unit → qty1 + qty1 | **PASS** |
| D admin relabel, still two destination lines | **PASS** |
| E invalidate offer, destinations kept + reselection | **PASS** |
| F session JSON restore, no address in cart id | **PASS** |
| G Blocks Store API two same-product lines / different destinations → correct v2 snapshots, no cross-assignment, transient keys removed | **PASS** (order 29) |

Not packaged. Not deployed. RC.10 not created. Storefront UI stages not started.
