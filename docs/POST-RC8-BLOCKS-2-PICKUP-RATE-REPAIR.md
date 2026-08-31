# POST-RC.8 Blocks.2 — Mixed Pickup fail-closed false warning

**Document status:** Repair record. Not RC.9. Schema 5.  
**Date:** 2026-08-31  
**Identity:** `1.0.0-dev.blocks.2`  
**Branch:** `feat/post-rc8-integrations`  
**Physical QA evidence:** training.cetechbpa.com mixed In Store Blocks cart (blocks.1)

## Root cause

`BlocksCheckoutValidation::managed_packages_have_de_rates()` decided a Delivery Engine-managed package had no valid DE rate when Store API cart/checkout errors were collected (`woocommerce_store_api_cart_errors`).

WooCommerce `WC_Shipping::calculate_shipping()` / `get_packages()` returns **package arrays** with a `rates` key. The Blocks.1 validator required an **object** with `->rates`. That branch never matched.

It then fell back to `WC()->cart->get_shipping_packages()[$i]['rates']`, which is the uncalculated package shell and is typically empty.

A valid mixed cart therefore looked like:

- Delivery package: DE rate GHS 50 (shown by WooCommerce / Store API)
- Pickup package: DE rate GHS 0 (shown as FREE)

while the validator saw **empty rates** on the Pickup (and potentially Delivery) managed package and appended:

> Delivery pricing is not available for one or more items in your cart. Native shipping methods cannot be used as a fallback.

Store Pickup at GHS 0 is a valid priced fulfilment path (`SelectedOfferShippingRateCalculator` already quotes `'0.0000'` for pickup). It must not be treated as “no delivery rate.”

This was **not** solved by hiding the notice. Fail-closed remains when a managed **Delivery** package has no DE quote or only native WooCommerce methods.

## Repair

- Read rates from array-shaped calculated packages (`['rates']`), with object `->rates` as a secondary shape.
- Prefer already-calculated `WC()->shipping()->get_packages()` during Store API cart errors.
- Treat a DE method rate with cost 0 as a valid Pickup quote.
- Native Flat Rate / Local Pickup on a managed package still fail closed.
- Unmanaged packages remain unaffected.
- Classic checkout hooks and quoting are unchanged.

## Owner recheck

One mixed In Store Blocks cart/checkout only. Do not restart International / In Warehouse until that passes.
