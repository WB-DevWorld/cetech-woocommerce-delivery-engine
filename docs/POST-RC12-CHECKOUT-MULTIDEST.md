# POST-RC.12 Checkout Multi-Destination Stabilization

**Issue:** [#29](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/29)  
**Candidate identity:** `1.0.0-dev.checkout-mdest.1`  
**Schema:** `6` (unchanged)  
**Branch:** `fix/checkout-multi-destination-preservation`  
**Base:** protected `master` `c4cee3c37360789d47f3238207aaa4e74ba86109`  
**Immutable RC.12 source:** `78594ad8962868683726373f58f4a8b1b48e4d0e` / tag `v1.0.0-rc.12`  
**Status:** AWAITING REVIEW — not RC.13 — not deployed

## Package (dev qualification, after CI)

- Source SHA: `b07c3eb1ee3556e5b184ce072c1833ba04459cf9`
- Filename: `cetech-woocommerce-delivery-engine-1.0.0-dev.checkout-mdest.1.zip`
- Bytes: `1,778,523`
- SHA-256: `8ceb27dfa3381db23917fbcae45f31453e8588598241f0c4e9af4fd7855c5df1`
- Built from a clean committed tree after GitHub CI SUCCESS. Do not treat this ZIP as RC.13 or as a replacement for RC.12.

## Owner reproduction (training.cetechbpa.com, RC.12 runtime)

Cart: two identical products with distinct Delivery Engine customer contexts.

| Line | Destination | Option | Fee |
|---|---|---|---|
| A | GH | Standard Delivery | GH₵30 |
| B | Accra | Standard Delivery | GH₵50 |

**Before Use my checkout address**
- Subtotal GH₵60
- Delivery GH GH₵30
- Delivery Accra GH₵50
- **Total GH₵140**
- Checkout initially preserved both groups.

**After Use my checkout address** (checkout address Accra)
- Both items Accra
- Only GH₵50 delivery remained
- **Total GH₵110**

## Root cause

`CheckoutAddressPolicy` called `ApplyCustomerContextToEligibleLinesService::applyCheckoutAddressToIncomplete()`.

That method skipped only lines with a complete `DeliveryAddress`. An incomplete Delivery line that already had a selected `MatchingLocation` was rebuilt as:

`CustomerCartContext::delivery($offer_id, $checkout->matching, $checkout)`

That replaced GH with Accra whenever the current Delivery Option could quote at Accra. `CartCustomerContextMutationService` then rekeyed/consolidated lines, collapsing the cart to one Accra destination and one Accra fee.

Quote-success is **not** a safe compatibility test. A country-level GH destination can still quote at Accra.

Blocks used the same service (`BlocksCartContextCommandHandler::ACTION_APPLY_CHECKOUT_ADDRESS`).

## Behavior chosen

Proven compatibility rule: **identical matching-location identity**.

Street/recipient may be completed only when the line’s selected matching identity equals the checkout address matching identity. That cannot change delivery geography or fees.

Heterogeneous incomplete destinations (two or more distinct selected matching identities among incomplete Delivery lines):

- preserve every selected destination;
- do not mutate the cart;
- hide Classic and Blocks bulk actions;
- explain: “These items are going to different destinations. Add a delivery address for each item. Your selected destinations will be kept.”

Other rules:

1. Complete per-item `DeliveryAddress`: never change.
2. Pickup: never change.
3. Incomplete Delivery with no meaningful matching destination: checkout address may be applied if the current option quotes there and local/international fulfilment is not crossed.
4. Incomplete matching compatible with checkout (same matching identity): complete the street address only.
5. International vs local: never cross-convert (store-base country vs checkout country XOR line `InternationalFulfilment`).

No GH-matching + Accra-street hybrid address is created.

## Delivery Areas copy

Admin overlap notice now states configured priority first (lower number first), then geographic specificity on ties, then a stable tie-break. `DestinationZoneMatcher` runtime ordering is unchanged.

## Secondary investigations (not runtime-fixed in this candidate)

### Shipment customer label
`ShipmentWorkspaceQuery::customer_label()` returns “Customer unavailable” only when `WC_Order` is null. Live training reproduction was **not** completed here (Location Pack import in progress; no admin mutation). Code risk: `load_orders()` uses `wc_get_orders()` with `type=shop_order` and returns without a `wc_get_order()` fallback. If an HPOS order that opens in Woo admin is missing from that query, that would be a defect. If the Woo order truly no longer exists, clearer “Order/customer unavailable” wording would be appropriate later. Do not invent/store duplicate PII. Runtime left unchanged.

### Overview Needs attention count
Proven contract mismatch in code, not live-confirmed on training:

- Overview card uses `NeedsAttentionQuery::count()` — product setup problems only.
- Needs Attention page also lists COD awaiting shipment creation, paid-order shipment creation problems, operational review rows, and stalled Bulk jobs.
- Admin-menu badge already uses `NeedsAttentionCountQuery`, which matches the page.

If the card links to the whole page and says “Needs attention”, a zero product-setup count misrepresents actionable work. Not changed in this checkout patch.

### International ETA
`OverviewPage` and Site-wide Defaults **summary cards** both use `SiteWideDefaultSummary::eta_label()`:

1. site-wide `ESTIMATED_DELIVERY` override when set;
2. otherwise first assigned Delivery Option `default_processing_* + default_transit_*` days.

The International **edit form** shows the `ESTIMATED_DELIVERY` field with placeholder `3–5 days`. Overview `7–14 days` is consistent with offer min–max fallback or different saved states. Not changed; do not guess.

## Explicit non-actions

- RC.12 tag/ZIP not moved or rebuilt
- RC.13 not created
- schema/migrations not changed
- training/FLAIROC/production/POS not deployed or mutated
- Ghana Location Pack import not touched
- Stage 15 not started
