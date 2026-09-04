# Post-RC.9 customer UX cleanup — `1.0.0-dev.integrated.2`

**Document status:** Owner visual acceptance **APPROVED**. Presentation-only storefront cleanup frozen as `1.0.0-dev.integrated.2`.  
**Identity:** `1.0.0-dev.integrated.2`  
**Branch:** `feat/post-rc9-customer-ux`  
**Source:** `ac2bc94056ebc73f1c6f8ab4a0434f4e68ce7300` (`1.0.0-dev.integrated.1`)  
**Schema:** `5` (unchanged)  
**Not:** RC.10, Stage 15, FLAIROC, training, WPML

```text
READ → AUDIT → PLAN → IMPLEMENT → TEST → DOCUMENT → REVIEW → STOP
```

## Intent

Owner accepted the integrated.1 functionality. This pass is presentation only.

The engine may be complex. The customer experience must not feel complex.

Do not redesign cart identity, per-item destinations, rates, checkout validation, Store API, snapshots, shipments, WCFM security, fulfilment constraints, or schema.

## Duplication sources removed

| Surface | Before | After |
|--------|--------|--------|
| Classic cart line | WooCommerce `woocommerce_get_item_data` **and** `CartCustomerContextEditorRenderer` | Custom renderer only on cart/checkout. Item-data kept for mini-cart and reselection. |
| PDP instruction | Matching-location intro **and** status line both said “Enter your delivery location…” | One prompt: “Where do you want this item?” |
| Cart totals | `Delivery — Accra` heading, `Delivery — Accra (1)` rate, “Shipping to…”, product names | `Delivery to Accra` once; package details hidden |
| Checkout | Per-line metadata plus shipping lines plus notices | Compact “Your deliveries” plan + short notices |
| Blocks | Change delivery mixed into the summary, large checkout-address button | Closed summary + Change; “Use my checkout address” |

## Exact customer copy

| Before | After |
|--------|--------|
| Delivery options | Delivery & pickup |
| Enter your delivery location to see delivery options / available options | Where do you want this item? |
| Change delivery / Change pickup | Change |
| Delivery — Accra / Delivery — Accra (1) | Delivery to Accra |
| Fulfilment / Fulfilment and delivery option | Delivery / Delivery & pickup |
| Store pickup | Store Pickup |
| Use this address for all eligible delivery items | Use this address for all delivery items |
| Use checkout shipping address for incomplete delivery items | Use my checkout address |
| One or more items need a complete delivery address… | Complete the delivery address for 1 item before placing your order. |
| Items in this order will be delivered to multiple destinations. Each item keeps its own delivery address. | Your items will be delivered to different locations. |
| This order includes Store Pickup and Delivery. Pickup items ignore the checkout shipping address. | This order includes Store Pickup and Delivery. |
| (new helper) | Items with their own delivery address will keep that address. |
| (new primary action) | Add delivery address |

## Surfaces

**PDP:** one Delivery & pickup panel. Location fields sit under one prompt. Delivery and Store Pickup are visually separate. Pickup hides location fields. The red missing-selection WooCommerce notice is dismissed in the browser once a valid option is selected.

**Cart:** one compact line summary. Change reveals the editor. After save the editor stays closed.

**Checkout:** Your deliveries confirmation. Classic checkout does not repeat the Change editor under each product. Incomplete-address fail-closed is unchanged; the customer copy and actions changed only.

Classic and Blocks share `CustomerStorefrontCopy`. HTML is not identical.

## Automated tests

- PHPUnit: **994 tests, 5597 assertions, OK** (5 deprecations, pre-existing)
- Vitest: **41 tests, OK**
- Local Playwright `customer-ux-screenshots.spec.ts` against `http://localhost:8088`: **PASS**
- Lab identity: `1.0.0-dev.integrated.2`, schema **5**
- WCFM vendor denied Delivery Engine admin (`evidence/integrated.2/wcfm.json`)
- Mobile cart overflow: **none** (`scrollWidth` 390)
- Classic item-data duplicate rows on cart: **0**

## Screenshot evidence

Stored under `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-local-qa\evidence\integrated.2\`:

1. `01-pdp-delivery.png` — Delivery selected
2. `02-pdp-pickup.png` — Store Pickup selected
3. `03-classic-cart-accra-kumasi.png` — Accra + Kumasi (GHS 15 + 22)
4. `04-classic-checkout-two-destinations.png` — two destinations
5. `05-blocks-cart.png`
6. `06-blocks-checkout.png`
7. `07-mobile-classic-cart.png` — 390px

Supporting: `03b-classic-cart-change-open.png`, `03c-classic-cart-768.png`, `08-classic-cart-delivery-pickup.png`, `09-wcfm-vendor-denied.png`.

Owner visual acceptance of these screenshots: **APPROVED** (2026-09-04).

## Package

This ZIP was built from the frozen presentation source. **Do not rebuild.** ZIP checksums were added in a later docs-only commit; they do not change the packaged source.

**PACKAGED SOURCE SHA:** `10028a2216619f514dda3ecf7cd1cbb7d50296cc`

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.integrated.2.zip` |
| Version | `1.0.0-dev.integrated.2` |
| Schema | `5` |
| Branch | `feat/post-rc9-customer-ux` |
| Bytes | `1484938` |
| SHA-256 | `a16a7840f32c8aa95fde3d4ef25c97c39ec995fe1d4ee6036fbb03b8d1a1a9c9` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-dev.integrated.2.zip` |
| Desktop copy | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-dev.integrated.2.zip` |
| Build | `scripts/build-v1-rc-package.ps1 -Version 1.0.0-dev.integrated.2 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-dev.integrated.2.zip` (clean tree; **not** `-AllowDirty`) |
| Autoload verification | Package verification OK |
| RC.9 tag | `v1.0.0-rc.9` still peels to `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5` |
| integrated.1 ZIP | untouched (`571f738aa263676d64be6d285686a10a5fea2c48245449b55fe2240466a2d247`) |

Not RC.10. Do not retag RC.9. Do not modify FLAIROC.

## Known presentation limits

- PDP option cards do not show quoted prices. `ProductDeliveryOption` has no quote amount; adding prices would require quoting, which this pass does not change.
- WooCommerce Blocks still prints a combined shipping line (“Delivery to Accra, Delivery to Kumasi”) in the order summary. The plugin-owned “Your deliveries” plan is the confirmation.

## Unchanged

- Schema 5
- RC.9 tag and ZIP
- `1.0.0-dev.integrated.1` ZIP (`571f738aa263676d64be6d285686a10a5fea2c48245449b55fe2240466a2d247`)
- FLAIROC / training
- Rates, quoting, snapshots, WCFM isolation, Store API commands
