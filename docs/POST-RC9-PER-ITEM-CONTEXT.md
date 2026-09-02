# Post-RC.9 per-item customer context — Stages 1–6 Classic + Blocks customer UX

**Identity:** `1.0.0-dev.peritem.1` (unchanged; no new release identity)  
**Branch:** `feat/post-rc9-per-item-context`  
**Classic foundation SHA:** `72b979cc8b1fcc716d8ecfd4a4e0b97a80acb6a3`  
**Classic Chrome completion SHA:** `2ae5a957f254d903c4270c54ac92790b6ad311e4`  
**Schema:** `5` (unchanged)  
**Not:** RC.10, Stage 15, packaging, FLAIROC, training, WCFM, WPML

Classic Stages 1–6 remain **CLOSED PASS**. Their business semantics were not redesigned. Blocks is an adapter over the same PHP/domain/cart services.

## Provenance

Rooted from immutable RC.9 `v1.0.0-rc.9` / `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5`.

Cherry-picks only:

| Stream | Source SHA | Cherry-pick on this branch |
|---|---|---|
| `1.0.0-dev.cartstate.1` | `39f61c1bc79e6248f1e64cdb945f62edf61b357b` | `f6b5c12` |
| `1.0.0-dev.blocks-snapshot.1` | `54c9894f492906a24d30c939f831f4538d6b0255` | `729f4e8` |

WCFM, WPML, and training streams were not merged.

Stages 1–2 remain the domain + cart-state foundation. Stages 3–6 add package-destination quoting and Classic customer UX. The Blocks customer UX stage adapts those same services onto Store API / Cart and Checkout Blocks. Classic semantics are unchanged.

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

Blocks Cart/Checkout is an adapter over the same services as Classic:

- `CustomerCartContext`
- `CartCustomerContextMutationService`
- `CartCustomerContextEditorService`
- cartstate reconciler
- `DeliveryGroupIdentity`
- package builder
- checkout validator
- v2 snapshot builder/persister

Blocks JS collects and displays customer choices and calls server-authoritative PHP. It does not invent prices, split quantity locally, or treat the global Blocks shipping address as cart-line authority.

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

Classic two-destination capture (this completion):

1. Context is present in `cart_item_data` before `generateCartId`.
2. Same product Accra vs Kumasi produces distinct cart IDs.
3. Browsing default is not read as submitted matching location.
4. Authoritative `cetech_de_pdp_context` JSON wins over stale Accra POST fields.
5. Stale display keys cannot validate against a destination that does not quote (Lagos).
6. One-cart Accra + Kumasi package quoting is **GHS 15 + GHS 22 = 37** (`test_lab_accra_and_kumasi_quote_15_and_22_in_one_cart`).
7. Same complete destination consolidates.
8. Variation options request includes location (`need_location` when missing; Accra vs Lagos filter).
9. ETA renderer does not double-prefix (`format_product_estimate_line`).
10. Existing cartstate reconciliation remains in the suite.
11. Blocks snapshot / Store API tests remain in the suite.
12. Schema target remains **`5`**.

Blocks non-regression: v1 managed lines without `CustomerCartContext` keep the WooCommerce package destination; existing Store API snapshot, pickup, and cartstate tests remain in the suite.

- Focused PHPUnit (capture / quoting / variation / estimate / cartstate / schema / Store API / Blocks UX): **112 tests, 504 assertions, OK**
- Full PHPUnit: **973 tests, 5387 assertions, OK** (5 pre-existing deprecations)
- JS: **6 files / 38 tests passed**
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

## Classic two-destination root cause (Chrome)

Domain fixtures already allowed two destination contexts. The unstable Classic browser cart was **not** a missing random unique key.

WooCommerce 11.0.1 Classic add-to-cart order in the local lab (`class-wc-cart.php`):

1. Classic form POST
2. `woocommerce_add_to_cart_validation` → `CartDeliverySelectionCapture::validate_add_to_cart`
3. `WC_Cart::add_to_cart()`
4. `woocommerce_add_cart_item_data` (priority 10) — **CustomerCartContext must be attached here**
5. `generate_cart_id()` → `woocommerce_cart_id` → `CartDeliverySelectionReconciler::filter_cart_id` → `CartLineCustomerIdentity::generateCartId()`
6. `find_product_in_cart` / merge if the ID matches
7. `woocommerce_add_to_cart`
8. session write; later `reconcile_cart`

If matching location is missing at step 4, Accra and Kumasi collapse to the same cart ID.

**Real Classic POST / JS defects:**

1. **No single authoritative payload.** Scattered `cetech_de_matching_*` + `cetech_de_delivery_option_key`. Duplicate names (PDP + cart/mini-cart), WooCommerce country/state hidden clones, and first-match `querySelector` could submit Accra after the UI showed Kumasi.
2. **Location AJAX had no request token.** An Accra response could overwrite Kumasi options. After AJAX, required radios could be unchecked/disabled (`setPanelActive`), so HTML5 blocked submit (empty notices, no PHP). Playwright `requestSubmit()` without the add-to-cart button as submitter omitted `add-to-cart=16`, so WooCommerce never added.
3. **Browsing session is a PDP convenience default only.** Capture already did not read `cetech_de_browsing_matching_location` as cart authority. Prefill + stale fields still made the second add look like a merge. Cart restore does not use browsing as item state.
4. **Not** “need a random unique cart key.” Separation comes only from legitimate `CustomerCartContext` before `generate_cart_id`.

**Exact fix:** one JSON field `cetech_de_pdp_context` (`ClassicPdpContextPayload`). Server order: payload → individual POST fields → Store API filter. Never browsing. Server sanitizes → `MatchingLocation` → validates the selected option against that location (`LocationOfferQuoteProbe` / `LocationAwareDeliveryOptions`) → `CustomerCartContext` → cart item. Never trust browser price/ETA/label. Classic delivery without a matching location does **not** attach a location-less delivery context (that would merge). JS writes the payload on submit, scopes field reads to the selector root, aborts stale location AJAX, and enables the checked radio before submit.

Lab ATC mu-plugin (`wp-content/mu-plugins/cetech-de-atc-trace.php`, not shipped) after the fix:

| Add | City | Cart key | Context before cart ID |
|---|---|---|---|
| 1 | Accra | `4247f7a0caed579afc46d9a43a14496c` | yes |
| 2 | Kumasi | `87a9998c68cc168a4703ffd570fa905b` | yes |

PHP received Kumasi. WooCommerce did not merge. Reconciliation did not overwrite Kumasi with Accra. Raw city does not appear in the cart key.

Answers to the ten add-to-cart questions after the fix:

1. Browser submitted Kumasi — **yes** (`cetech_de_pdp_context` + matching fields).
2. PHP received Kumasi — **yes**.
3. CustomerCartContext built as Kumasi — **yes**.
4. Context present before cart ID — **yes**.
5. Distinct cart ID — **yes**.
6. WooCommerce merge afterward — **no**.
7. Reconciliation overwrite with Accra — **no**.
8. Browsing overwrite submitted item — **no**.
9. Stale hidden fields — **mitigated** by payload-first.
10. AJAX visual/new vs submit old — **fixed** with request token + payload written on submit.

## Estimate copy

Stored duration text was `Estimated %1$d–%2$d %3$s`. The PHP renderer prefixes `Estimated delivery:`. AJAX JS concatenated the prefix again → `Estimated delivery: Estimated 3–5 business days`.

One formatting boundary:

- Source duration is duration-only (`3–5 business days`).
- `DeliveryPresentationLabels::format_product_estimate_line()` prefixes once.
- Historical snapshots that already store `Estimated …` are stripped only at display.
- AJAX responses include `estimate_line`. JS prefers that field.

Chrome cart: **Estimated delivery: 3–5 business days** (no double prefix).

## Variable product location-first

Location is part of `cetech_de_variation_delivery_options`. Changing variation, country, state, city, or postcode invalidates the cache and refetches. Missing location returns `need_location`. A display key resolved for variation A / Accra cannot remain selectable for variation B / Kumasi unless server validation still quotes.

Chrome: QA Variable Table Oak / Accra + Walnut / Kumasi → two cart lines, Accra GHS 15 + Kumasi GHS 22 in one cart.

## Local Docker QA — Classic Stages 3–6

Classic Cart: `/classic-cart/`  
Classic Checkout: `/classic-checkout/`  
Lab rates: Accra Standard **GHS 15**, Kumasi Standard **GHS 22** (not the 15/35 fixture amounts).

Plugin copied, not packaged. Identity `1.0.0-dev.peritem.1`, schema **5**.  
Evidence: `cetech-de-local-qa/evidence/peritem.classic/`.

Chrome (Playwright Desktop Chrome against `http://localhost:8088`):

| Flow | Result |
|---|---|
| 1 PDP Accra + Delivery + QA Chair | **PASS** — matching location fields, QA Local Standard, Delivering to: Accra |
| 2 same chair → Kumasi second line | **PASS** — 2 WC lines; payload city Kumasi; cart keys `4247f7a0…` and `87a9998c…` |
| 3 separate packages/rates in one cart | **PASS** — Accra GHS 15 + Kumasi GHS 22; total DE shipping GHS 37 |
| 4 consolidate Kumasi → Accra | **PASS** — lines=1 |
| 5 qty split to another Accra street | **PASS** — qty 1,1 |
| 6 Delivery + Pickup | **PASS** — pickup zero; delivery quoted |
| 7 Use this address for all eligible | **PASS** — pickup stayed pickup |
| 8 multi-address / mixed checkout | **PASS** — after FLOW 5 (two Accra streets completed): checkout notice *Items in this order will be delivered to multiple destinations. Each item keeps its own delivery address.* After FLOW 7: mixed *Store Pickup and Delivery* notice; pickup ignores checkout shipping. Accra+Kumasi simultaneous rates remain FLOW 3 (matching-only PDP lines fail closed until street complete — FLOW 9). |
| 9 incomplete + explicit checkout address | **PASS** — Place Order blocked; apply control present |
| 10 admin invalidate / reselection | **PASS** — product + Accra destination survive; line needs reselection |
| Estimate copy | **PASS** — no `Estimated delivery: Estimated` |
| Variable Oak/Accra + Walnut/Kumasi | **PASS** — 2 lines; Accra 15 + Kumasi 22 |

Chrome defects found and fixed during QA:

- Cart editor used `woocommerce_form_field` country inside `<details>`, which dumped country names into the item remove control. Cart now uses native matching-location fields and `woocommerce_after_cart_item_name`.
- Classic same-product two-destination add-to-cart (payload / AJAX token / submitter / location-less context skip) as above.

Privacy (DOM / request): cart keys and group IDs are hashes; matching identities are SHA-256; generic `data-*` do not carry street, name, or phone. Editor HTML ids are `cetech-de-ctx-{cartKeyHash}-{field}` (suffixes such as `-phone` / `-address-1` name the control, not the customer value). Forms submit customer-owned address fields to the server. ATC trace is lab-only.

Remaining limitations (not blockers for Classic Stages 3–6 or Blocks customer UX):

- Per-destination tax engine is still not claimed.
- FLOW 8 / FLOW 9 Classic Chrome do not click WooCommerce **Place order** through to a paid Classic order; Blocks FLOW I/J do place Store API orders.
- WooCommerce Blocks PluginArea React slot-fills are not required for the customer editor; the cart/checkout page also mounts a DOM editor from Store API data so Change delivery/pickup remains visible.

## Intentionally excluded

- Packaging / ZIP
- RC.10 / Stage 15
- FLAIROC / training / WCFM / WPML
- Per-destination tax engine
- Schema 6

## Blocks customer UX architecture

Blocks does not own a second rule set. Store API add-to-cart and cart updates sanitize input, resolve current configuration, validate the choice, attach `CustomerCartContext` before cart identity, then let the existing mutation / package / checkout / snapshot services run.

**Add to cart:** `product-delivery-selector.js` injects Store API extensions (`delivery_option_key`, `matching_location`, `pdp_context`) onto `/wc/store/v1/cart/add-item`. `BlocksAddToCartBridge` maps those onto `cetech_de_submitted_*` filters. Capture refuses location-less Delivery context so Accra vs Kumasi cannot merge.

**Public Store API fields (cart item):** fulfilment choice, public option/pickup labels, locality, estimate copy, completeness, `needs_reselection`, `can_edit_context`, `can_split`, matching location (country/state/city/postcode), customer-owned `delivery_address` for this session, `available_options`. Forbidden: group IDs, destination hashes, fingerprints, rule IDs, supplier/origin IDs, private costs, rate-card IDs, configuration fingerprint.

**Cart-level fields:** packages, multi-destination / mixed-fulfilment / incomplete notices, `can_apply_checkout_address`, `mutation_result` (`updated` / `failed` / `unchanged` / `skipped`).

**Commands** (single `woocommerce_store_api_register_update_callback` namespace `cetech-delivery-engine`):

| Action | Service |
|---|---|
| `reselect_option` (or omitted `action` + `display_key`) | `CartDeliveryReselectionService::apply_selection` |
| `set_item_context` | `CartCustomerContextMutationService::updateWholeLine` |
| `split_item_context` | `CartCustomerContextMutationService::splitQuantity` |
| `use_for_all` | `ApplyCustomerContextToEligibleLinesService::applyDeliveryLocation` |
| `apply_checkout_address` | `ApplyCustomerContextToEligibleLinesService::applyCheckoutAddressToIncomplete` |

Handlers never write WooCommerce cart arrays directly. Global Blocks customer/shipping updates only `calculate_shipping()`; they do not mutate complete per-item `CustomerCartContext`.

**UI:** `assets/frontend/blocks-checkout.js` on `/blocks-cart/` and `/blocks-checkout/`. Change delivery / Change pickup, quantity split, use-for-all, checkout-address apply, multi-destination notice, existing cartstate reselection panel.

## Local Docker QA — Blocks flows A–K

Lab: `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-local-qa` (`http://localhost:8088`). Plugin copied, not packaged. Identity `1.0.0-dev.peritem.1`, schema **5**. Evidence: `cetech-de-local-qa/evidence/peritem.blocks/`. Playwright: `playwright/tests/per-item-blocks.spec.ts`. Qualification used `/blocks-cart/` and `/blocks-checkout/` only (not Classic Cart/Checkout).

| Flow | Result |
|---|---|
| A Accra + Kumasi QA Chair | **PASS** — 2 lines, localities Accra\|Kumasi, DE shipping **GHS 37** |
| B Change Kumasi → Accra | **PASS** — consolidated to 1 Accra line |
| C qty 2 split 1 to 99 Ring Road | **PASS** — qty 1 + 1 |
| D Delivery + Pickup | **PASS** — separate; pickup remains pickup |
| E Use this address for all eligible | **PASS** — Pickup untouched |
| F two complete destinations | **PASS** — Accra Independence + Kumasi Prempeh retained; multi-destination notice; global Blocks shipping form did not overwrite them |
| G incomplete Place Order | **PASS** — blocked; explicit apply completed the eligible line |
| H invalidate offer | **PASS** — product + Accra survive; reselection required |
| I Store API order two destinations | **PASS** — order **32**, `created_via=store-api`, line snapshots **v2**, Accra 15 + Kumasi 22, no `_cetech_de_cart_item_key`, two historical groups, **2 shipment plans** |
| J Pickup-only Blocks order | **PASS** — order **33**, shipping 0, pickup v2 snapshot, **0** delivery shipment plans |
| K post-order admin relabel | **PASS** — order 32 still `QA Local Standard` |

Privacy: Store API extension payloads had no forbidden DE internals. Cart keys remain hashes. Customer street exists only in authorized cart/edit payloads, not in DOM ids.

## Final combined regression + UX hardening

**Identity remains** `1.0.0-dev.peritem.1`. Schema remains **`5`**. Not packaged. Not RC.10.

**Verdict:** READY TO PACKAGE. Do not package until the owner asks.

Narrow runtime fixes in this pass:

- Blocks customer editor mounts from Store API cart/checkout DOM (`#cetech-de-blocks-dom-ui`). PluginArea React context editors are **not** registered, so Change delivery cannot duplicate.
- Subscribe is debounced; unchanged cart signatures skip `innerHTML` rebuild; click handlers are delegated once; open `<details>` survive refresh.
- Field `id`/`for` values are `cetech-de-b-{hashedCartKey}-{field}` (never street, name, or phone).
- Classic quantity radios have unique ids (`-apply-all` / `-apply-split`).
- Blocks field labels match Classic: Address line 1 / Address line 2 / Quantity to move / Fulfilment and delivery option.
- Classic + Blocks editors: `max-width: 100%`, overflow protection, 44px tap targets.
- Pickup option public label uses the location name when present, so two eligible pickup locations are distinguishable (for example QA Accra Pickup vs QA Kumasi Pickup).

WooCommerce still owns **one** taxation / customer-location model. Per-item destinations do **not** mean per-destination tax. Customer copy must not promise that.

On a store with site-wide In Warehouse defaults, an ordinary catalog product is still Delivery Engine-managed unless a product exception removes applicable rules. Lab mixed-cart proof used product-level opt-out (`QA Unmanaged Mug`) plus a native WooCommerce zone method (`QA Native Flat Rate`).

Lab Playwright `per-item-final-regression.spec.ts` (Chromium, `http://localhost:8088`, 2026-09-02): **1 passed**. Evidence: `cetech-de-local-qa/evidence/peritem.final-regression/`.

| Check | Result |
|------|--------|
| Classic mixed Delivery + unmanaged | PASS |
| Blocks mixed Delivery + unmanaged (DE Accra package + native residual) | PASS |
| Classic mixed Pickup + unmanaged | PASS |
| Blocks mixed Pickup + unmanaged | PASS |
| Two managed destinations + unmanaged | PASS |
| Classic / Blocks refresh session | PASS |
| Blocks quantity rerender (editors 2→2) | PASS |
| Responsive 1440 / 768 / 390 Classic + Blocks (no overflow) | PASS |
| Unique field IDs / Address line 1 copy | PASS |
| Two pickup locations must choose; separate contexts | PASS |
| Large Classic + Blocks cart (Chair Accra, Lamp Kumasi, Table Accra, Pickup, unmanaged) | PASS |
| Checkout address does not rewrite complete Accra/Kumasi | PASS |
| No matching area failure copy | PASS |
| Admin invalidation requires reselection | PASS |
| ORDER A Blocks two destinations `#38` — two shipment plans | PASS |
| ORDER B Classic Delivery + Pickup `#39` — Delivery plan, pickup skipped | PASS |
| ORDER C Blocks managed + unmanaged `#40` — DE snapshot on Chair only; native flat rate on Mug; mug not a DE shipment item | PASS |
| Historical ORDER A unchanged after admin relabel | PASS |
| Store API privacy / console / no per-destination tax promise | PASS |

Orders:

- **A** `#38` Store API: Accra 15 + Kumasi 22; two destination groups → two plans.
- **B** `#39` Classic checkout: Store Pickup QA Accra Pickup (0) + Delivery Accra 15; pickup skipped, one Delivery plan.
- **C** `#40` Store API: Chair v2 snapshot Accra 15; Mug has no `_cetech_de_delivery_snapshot`; shipping `delivery_engine_selected_offer` + native `flat_rate`; planner includes only the Chair.

Known limitation kept: WooCommerce owns one tax/customer-location model. Per-item destinations are not per-destination tax.
