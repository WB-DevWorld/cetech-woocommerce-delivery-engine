# Post-RC.9 per-item customer context — Stages 1–6

**Identity:** `1.0.0-dev.peritem.1` (unchanged; no new release identity)  
**Branch:** `feat/post-rc9-per-item-context`  
**Schema:** `5` (unchanged)  
**Not:** RC.10, Stage 15, packaging, FLAIROC, training, WCFM, WPML, Blocks location editor

## Provenance

Rooted from immutable RC.9 `v1.0.0-rc.9` / `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5`.

Cherry-picks only:

| Stream | Source SHA | Cherry-pick on this branch |
|---|---|---|
| `1.0.0-dev.cartstate.1` | `39f61c1bc79e6248f1e64cdb945f62edf61b357b` | `f6b5c12` |
| `1.0.0-dev.blocks-snapshot.1` | `54c9894f492906a24d30c939f831f4538d6b0255` | `729f4e8` |

WCFM, WPML, and training streams were not merged.

Stages 1–2 remain the domain + cart-state foundation. Stages 3–6 add package-destination quoting and Classic customer UX. Full Blocks location UI is **not** implemented.

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

Classic presentation handlers call `CartCustomerContextEditorService`, which commits only through this mutation service. Presentation never edits cart arrays directly.

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

Full Blocks location editor is **not** in this stage. Store API package quoting, cartstate reconciliation, snapshot v2, pickup, and shipment planning must not regress. Managed lines **without** `CustomerCartContext` keep the WooCommerce package destination (v1/Blocks compatibility).

## Privacy

Raw addresses never appear in WooCommerce cart keys, group IDs, HTML ids/`data-*` attributes, or generic logs. Logger strips `first_name`, `last_name`, `phone`, `address`, `address_1`, `address_2`, `recipient`.

Browsing convenience stores matching-level geography only (country/state/city/postcode) in the WooCommerce session. Street, name, and phone are never stored as a browsing default.

## Package destination quoting

For every managed **Delivery** package that has `CustomerCartContext`, `package['destination']` is built from that package’s customer context:

- `country`
- `state`
- `city`
- `postcode`
- `address`
- `address_2`

The global WooCommerce customer shipping address is **not** used for those managed packages.

`DestinationZoneMatcher` continues to match on country / state / city / postcode only. Street does not change destination-area matching.

Two different streets in the same city remain distinct packages because `delivery_location_identity` differs. They may legitimately share the same rate card.

**Unmanaged** residual WooCommerce packages are left untouched and continue to use the ordinary WooCommerce shipping address and native shipping methods.

**Pickup:**

- no delivery destination (empty destination fields)
- zero charge
- no delivery shipment
- no WooCommerce shipping-address implication

Customer-safe rate labels use locality, not streets or group IDs, for example `Delivery — Accra` or `Store Pickup — QA Accra Pickup`. Package headings override the WooCommerce default only when a locality label is present; v1/pickup heading behaviour is otherwise unchanged.

## Classic PDP — location first

Classic PDP collects **matching location only** (not a full street address):

- Country
- State / Region
- City
- Postcode

WooCommerce country/state data is used where available.

Customer flow:

1. Choose/enter matching location.
2. Server resolves valid Delivery Engine availability/options for that location (`LocationAwareDeliveryOptions` + `LocationOfferQuoteProbe`).
3. Customer chooses fulfilment / Delivery Option.
4. Add to cart. The cart line receives its own `CustomerCartContext` copy.

Store Pickup does not require a delivery destination. International Air/Sea hard constraints are unchanged. In Warehouse remains local Delivery only. In Store may offer Delivery and/or Store Pickup according to configuration.

If exactly one active eligible pickup location exists, it may be preselected. If more than one exists, the customer must choose. New per-item-context orders persist a real `pickup_location_id`, never generic historical `pickup` as sufficient identity.

## Browsing location persistence

`CustomerBrowsingLocationStore` keeps a matching-level default in the WooCommerce session (`cetech_de_browsing_matching_location`).

This is a convenience default, **not** authoritative cart state.

- Each cart line receives its own `CustomerCartContext` copy on add-to-cart.
- Later changing the browsing default does **not** rewrite existing cart lines.
- Existing cart lines keep their own location.
- The customer can explicitly apply a location to other items later.

## Classic cart — per-line editing

Each managed cart line shows a customer-safe summary, for example:

- Delivery / QA Local Standard / Delivering to: Accra / Estimated delivery: 2–4 business days / **Change delivery**
- Store Pickup / QA Accra Pickup / public address / Ready in 1–2 days / **Change pickup**

The editor (where valid) allows matching location, fulfilment choice, Delivery Option, pickup location, and a complete physical delivery address (address lines, country/state/city/postcode, recipient/contact).

Quantity > 1 offers an explicit choice:

- Apply to all N items
- Move some quantity (1..N)

Example: QA Chair × 3 Accra, move 1 unit to Kumasi → QA Chair × 2 Accra + QA Chair × 1 Kumasi. Target context consolidates when genuinely identical. Admin fingerprint refresh after edit does not create a ghost line.

Do not expose group IDs, hashes, fingerprints, rule IDs, internal codes, or rate card IDs.

## Use this address for all eligible delivery items

Explicit action only. Never silent.

`ApplyCustomerContextToEligibleLinesService::applyDeliveryLocation`:

1. Clone the requested customer location/address.
2. Validate against current product configuration.
3. Confirm the existing semantic Delivery Option remains valid.
4. Confirm a valid rate exists.
5. Commit only if legal.

Never convert Pickup ↔ Delivery, local ↔ International, or weaken fulfilment constraints. Unmanaged WooCommerce products remain untouched. Failed lines stay unchanged and return a visible per-item reason.

## Checkout address policy

WooCommerce still owns billing and **one** checkout shipping address. Delivery Engine owns per-item delivery addresses.

| Case | Behaviour |
|---|---|
| A. All managed Delivery items share one complete address | WooCommerce shipping fields may be aligned to that address. Per-item DE context remains authoritative. |
| B. Multiple DE delivery addresses | Do **not** overwrite them with the global checkout shipping address. Show a multi-destination notice. |
| C. Delivery + Pickup | Pickup ignores WC shipping address. Delivery lines use their own context. |
| D. Unmanaged products | Residual/native package still uses WooCommerce shipping address. |
| E. Matching location but incomplete street | Checkout fails closed until the customer completes that line’s address **or** explicitly chooses to apply the checkout shipping address. Never silent copy. |

Explicit action: **Use checkout shipping address for incomplete delivery items**. It uses the same revalidation service. It may not change fulfilment choice, change offer unless the customer reselects, overwrite already-complete per-item addresses, apply to Pickup, or apply to incompatible International/local products. Items that cannot be delivered to that address stay unchanged with a visible reason.

## Checkout validation

Every managed Delivery line at Place Order must have:

- valid current customer context
- complete delivery address
- valid current fulfilment choice
- valid current Delivery Option
- valid destination match
- valid DE quote for its exact package destination

Every Pickup line must have:

- valid pickup fulfilment
- valid active `pickup_location_id`
- zero delivery charge

Any invalid managed line fails closed. No native shipping fallback.

Lines **without** `CustomerCartContext` (v1 / Blocks without this UI) keep the previous selection-only checkout rules so Blocks is not broken by Classic context checks.

## Tax limitation

WooCommerce still has **one** taxation / customer-location model. This stage does **not** build a second tax engine and does **not** claim per-destination taxation. Shipping quotes still use each managed package’s own Delivery Engine destination.

## Automated tests

Covered at minimum:

PDP 1–6; package quoting 7–13; cart edit 14–19; use-for-all 20–24; checkout 25–32; privacy 33–36.

Blocks non-regression: v1 managed lines without `CustomerCartContext` keep the WooCommerce package destination; existing Store API snapshot, pickup, and cartstate tests remain in the suite.

- Full PHPUnit: **950 tests, 5284 assertions, OK** (5 pre-existing deprecations)
- JS: **6 files / 33 tests passed**
- PHP lint `src` + `database`: 0 failures
- `composer validate --no-check-publish`: valid

## Stages 1–2 local Docker QA (foundation, no storefront UI)

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

## Local Docker QA — Classic Stages 3–6

Classic Cart: `/classic-cart/`  
Classic Checkout: `/classic-checkout/`  
Lab rates: Accra Standard **GHS 15**, Kumasi Standard **GHS 22** (not the 15/35 fixture amounts).

Plugin copied, not packaged. Identity `1.0.0-dev.peritem.1`, schema **5**.

Chrome (Playwright Desktop Chrome against `http://localhost:8088`):

| Flow | Result |
|---|---|
| 1 PDP Accra + Delivery + QA Chair | **PASS** — matching location fields, QA Local Standard, Delivering to: Accra |
| 2 same chair → Kumasi second line | **PARTIAL** — Kumasi add/quote (GHS 22) proven; two simultaneous cart lines not stably achieved in Chrome |
| 3 separate packages/rates | **PARTIAL** — Accra GHS 15 and Kumasi GHS 22 proven on separate carts; not together in one Chrome cart |
| 4 consolidate Kumasi → Accra | **NOT COMPLETED** in Chrome (depends on two lines) |
| 5 qty split to another street | **NOT COMPLETED** in Chrome |
| 6 Delivery + Pickup | **NOT COMPLETED** in Chrome |
| 7 Use this address for all eligible | **NOT COMPLETED** in Chrome |
| 8 checkout two addresses | **NOT COMPLETED** in Chrome |
| 9 incomplete + explicit checkout address | **NOT COMPLETED** in Chrome |
| 10 admin invalidate / reselection | **NOT COMPLETED** in Chrome |

Chrome defects found and fixed during QA:

- Cart editor used `woocommerce_form_field` country inside `<details>`, which dumped country names into the item remove control. Cart now uses native matching-location fields and `woocommerce_after_cart_item_name`.

Remaining Chrome gaps are Playwright/add-to-cart stability, not a substitute for the PHPUnit coverage of package quoting, mutation, use-for-all, and checkout policy.

## Intentionally excluded

- Packaging / ZIP
- RC.10 / Stage 15
- FLAIROC / training / WCFM / WPML
- Full Blocks location editor (next stage)
- Per-destination tax engine
- Schema 6
