# POST-RC.12 — Compact cart/checkout delivery-address UX (Issue #39)

**Current candidate identity:** `1.0.0-dev.address-ux.2`  
**Schema:** `6` (unchanged; no schema 7)  
**Branch:** `fix/cart-checkout-address-ux`  
**Base:** protected `master` `ed3753d73e262d8c7467fa936e3e36060960e58c`  
**Previous `.1` runtime SHA:** `797bdce784ecbe44996cc0e46dbe8d2920a52b08`  
**Runtime / package-source SHA:** `b2acea7ba75f31cd6a7851fcbf594bf798bfd79d`  
**PR:** https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/41 (open; do not merge)

Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/39

Training remains on `1.0.0-dev.pdp-precision.2`. Do not install this candidate until owner/ChatGPT final deployment review. Frozen address-ux.1, pdp-precision.1/.2 ZIPs must not be overwritten.

## Owner defects (presentation only)

1. “Use my checkout address” visually overpowered “Add delivery address”.
2. “Add delivery address” was not the obvious required action.
3. Checkout sent customers vaguely to the cart with no line target.
4. The affected cart item did not open/focus automatically.
5. Generic “Change” was too vague.
6. The per-item editor was unnecessarily tall.
7. Existing capability and Issue #29 multi-destination safety must be kept.

No delivery quoting, geography, Location Pack, coverage, or rate changes.

## address-ux.2 technical corrections

1. **Classic nested-form ownership.** Cart-line editor and reselection no longer emit `<form>` inside WooCommerce’s `woocommerce-cart-form`. Visible controls stay beside the line with `form="FORM_ID"`. Per-line shells are queued in `CartExternalFormBuffer` and flushed on `woocommerce_after_cart` (and checkout/mini-cart counterparts), after the outer Woo form has closed. Matching location fields accept an optional form owner; PDP is unchanged when omitted.
2. **Destination-missing deep-link.** When `has_matching_location` is false, Classic and Blocks open Destination disclosure and focus the first missing visible destination control (country → region → city/town → postcode if relevant). Existing Accra destination + missing street still focuses Address line 1.
3. **Blocks Cancel resyncs method state.** `syncMethodState(editor)` is used from initial bind, method change, and `resetEditor` / Cancel. Delivery→Pickup→Cancel and Pickup→Delivery→Cancel restore visibility. Cancel sends no Store API command.
4. **No dead first-incomplete anchor.** `CheckoutAddressPolicy::summarize_cart()` skips `CART_NEEDS_RESELECTION_KEY` lines when choosing `first_incomplete_anchor`. Incomplete count, checkout validation, and reselection notices are unchanged. All-reselection carts get an empty anchor / plain cart URL. Blocks item `ui_anchor` remains null for non-editable reselection lines.

## Shared customer-safe UI anchor

`CartDeliveryUiAnchor` derives `cetech-de-delivery-{16 hex}` from SHA-256 of `cetech-de-delivery-ui|{cart_item_key}`. Deterministic, HTML/URL-fragment safe, no street/name/phone/email. Classic wrapper `id` and Blocks `details` `id` / Store API `ui_anchor` / `first_incomplete_anchor` use the same helper. Form IDs are `{anchor}-form` and `{anchor}-reselect`.

## Classic

- Incomplete Delivery: **Address needed** + summary **Add delivery address**.
- Complete Delivery: **Edit delivery details** (no Address needed).
- Store Pickup: **Edit pickup details**; not treated as a missing delivery address.
- Completeness remains `DeliveryAddress::isComplete()` (matching + Address line 1). Recipient fields stay optional.
- Compact progressive editor: Destination, Delivery method (hidden `display_key` when one option; `<select>` when several), Address line 1 required-for-completeness, optional Address line 2 / recipient / quantity disclosures, **Save delivery details**, **Cancel**, secondary **Use this address for all delivery items**.
- Checkout incomplete actions use `<div class="cetech-de-checkout-incomplete-address__actions">`. Primary **Add delivery address** whenever `incomplete_delivery > 0`, href `cart#first_incomplete_anchor` or plain cart URL. **Use my checkout address** is secondary and only when `can_apply_checkout_address=true` (hidden for heterogeneous destinations). Scoped CSS (including a minimal plugin-scoped `!important` reset for background/border/box-shadow) keeps WoodMart from promoting the secondary action.
- Deep-link: open matching `<details>`, `scrollIntoView` honoring `prefers-reduced-motion`, focus destination or Address line 1 once. No focus steal without a matching fragment. Cancel does not submit, restores rendered values, closes the editor, returns focus to the summary.

## Blocks

- `Add delivery address` renders whenever `incomplete_delivery > 0`, independent of `can_apply_checkout_address` (the previous bug gated both actions on the optional bulk apply).
- Heterogeneous: Add delivery address visible; Use my checkout address absent.
- Compatible incomplete: both actions, secondary styling on Use my checkout address.
- Checkout/cart Add delivery address href includes `first_incomplete_anchor` when an editable target exists.
- Cart editor `id` matches `ui_anchor`. Company round-trips via `readEditor()`. `openKeys` preserved; deep-link focus once.

## Issue #29 safety (unchanged)

- Heterogeneous incomplete destinations are never bulk flattened (`can_apply_checkout_address=false`).
- Complete per-item `DeliveryAddress` objects are not overwritten.
- Pickup is never converted to Delivery.
- Checkout-address application remains under the existing matching-identity compatibility rule.
- `CheckoutMultiDestinationStabilizationTest` retains blocked heterogeneous apply and compatible Accra completion.

## Frozen package (address-ux.1)

Do not overwrite or rebuild this ZIP.

- Source SHA: `797bdce784ecbe44996cc0e46dbe8d2920a52b08`
- Filename: `cetech-woocommerce-delivery-engine-1.0.0-dev.address-ux.1.zip`
- Bytes: `1,832,223`
- SHA-256: `2a28798140fafaa7d7b4e20bc4e3878fd1949f7014af854b002960a3ae1120fe`

## Package (address-ux.2, after CI)

Built from a clean committed tree after GitHub CI SUCCESS on the runtime/package-source SHA. Do not treat this ZIP as RC.13 or as a replacement for RC.12, frozen address-ux.1, or frozen pdp-precision ZIPs.

- Source SHA: `b2acea7ba75f31cd6a7851fcbf594bf798bfd79d`
- Filename: `cetech-woocommerce-delivery-engine-1.0.0-dev.address-ux.2.zip`
- Bytes: `1,837,972`
- SHA-256: `e914efc0ee73132efffab6899cf9627ac3d01ecf6b579f190b3050cdd43e117f`
- Production-package verifier: PASS (staged and extracted)
- Packaged PHP lint: `496 files / 0 failures` (PHP 8.5.0, vendor excluded)
- GitHub CI on runtime SHA: SUCCESS (`35660589914` push; `35660593955` pull_request) — PHP 8.3 Minimum Supported, PHP 8.4 Compatibility, PHP 8.5 CETECH Production Target, PHP 8.5 MariaDB Geography/Migrations, PHP 8.5 WordPress/WooCommerce, CI Required Gates, JavaScript / Vitest, Control Plane
- PHPUnit (local PHP 8.5.0): Tests: 1367, Assertions: 8682, Deprecations: 14, Skipped: 1
- Vitest: 99 passed / 8 files
- Composer validate: PASS
- Team control plane: PASS
- Product control plane: PASS (372 Requirement IDs)
- MariaDB real-DB: Tests: 26, Assertions: 1738, skipped=0, failures=0, errors=0
- Not deployed to training

## Explicitly not done

- Issues #31 and #32
- Ashanti Region rename
- Accra / Kumasi / Greater Accra coverage or charges
- Location Packs
- Schema 7
- RC.13
- Pilot / FLAIROC / production / POS
