# POST-RC.12 — Compact cart/checkout delivery-address UX (Issue #39)

**Current candidate identity:** `1.0.0-dev.address-ux.1`  
**Schema:** `6` (unchanged; no schema 7)  
**Branch:** `fix/cart-checkout-address-ux`  
**Base:** protected `master` `ed3753d73e262d8c7467fa936e3e36060960e58c`  
**Runtime / package-source SHA:** `797bdce784ecbe44996cc0e46dbe8d2920a52b08`  
**Not RC.13. Not deployed. Do not merge until owner/ChatGPT technical review.**

Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/39

Training remains on `1.0.0-dev.pdp-precision.2`. Do not install this candidate until owner review. Frozen pdp-precision.1/.2 ZIPs must not be overwritten.

## Owner defects (presentation only)

1. “Use my checkout address” visually overpowered “Add delivery address”.
2. “Add delivery address” was not the obvious required action.
3. Checkout sent customers vaguely to the cart with no line target.
4. The affected cart item did not open/focus automatically.
5. Generic “Change” was too vague.
6. The per-item editor was unnecessarily tall.
7. Existing capability and Issue #29 multi-destination safety must be kept.

No delivery quoting, geography, Location Pack, coverage, or rate changes.

## Shared customer-safe UI anchor

`CartDeliveryUiAnchor` derives `cetech-de-delivery-{16 hex}` from SHA-256 of `cetech-de-delivery-ui|{cart_item_key}`. Deterministic, HTML/URL-fragment safe, no street/name/phone/email. Classic wrapper `id` and Blocks `details` `id` / Store API `ui_anchor` / `first_incomplete_anchor` use the same helper.

## Classic

- Incomplete Delivery: **Address needed** + summary **Add delivery address**.
- Complete Delivery: **Edit delivery details** (no Address needed).
- Store Pickup: **Edit pickup details**; not treated as a missing delivery address.
- Completeness remains `DeliveryAddress::isComplete()` (matching + Address line 1). Recipient fields stay optional.
- Compact progressive editor: Destination, Delivery method (hidden `display_key` when one option; `<select>` when several), Address line 1 required-for-completeness, optional Address line 2 / recipient / quantity disclosures, **Save delivery details**, **Cancel**, secondary **Use this address for all delivery items**.
- Checkout primary **Add delivery address** whenever `incomplete_delivery > 0`, href `cart#first_incomplete_anchor`. **Use my checkout address** is secondary and only when `can_apply_checkout_address=true` (hidden for heterogeneous destinations). Scoped CSS (including a minimal plugin-scoped `!important` reset for background/border/box-shadow) keeps WoodMart from promoting the secondary action.
- Deep-link: open matching `<details>`, `scrollIntoView` honoring `prefers-reduced-motion`, focus Address line 1 once. No focus steal without a matching fragment. Cancel does not submit, restores rendered values, closes the editor, returns focus to the summary.

## Blocks

- `Add delivery address` renders whenever `incomplete_delivery > 0`, independent of `can_apply_checkout_address` (the previous bug gated both actions on the optional bulk apply).
- Heterogeneous: Add delivery address visible; Use my checkout address absent.
- Compatible incomplete: both actions, secondary styling on Use my checkout address.
- Checkout/cart Add delivery address href includes `first_incomplete_anchor`.
- Cart editor `id` matches `ui_anchor`. Company round-trips via `readEditor()`. `openKeys` preserved; deep-link focus once.

## Issue #29 safety (unchanged)

- Heterogeneous incomplete destinations are never bulk flattened (`can_apply_checkout_address=false`).
- Complete per-item `DeliveryAddress` objects are not overwritten.
- Pickup is never converted to Delivery.
- Checkout-address application remains under the existing matching-identity compatibility rule.
- `CheckoutMultiDestinationStabilizationTest` retains blocked heterogeneous apply and compatible Accra completion.

## Package (address-ux.1, after CI)

Built from a clean committed tree after GitHub CI SUCCESS on the runtime/package-source SHA. Do not treat this ZIP as RC.13 or as a replacement for RC.12 or frozen pdp-precision ZIPs.

- Source SHA: `797bdce784ecbe44996cc0e46dbe8d2920a52b08`
- Filename: `cetech-woocommerce-delivery-engine-1.0.0-dev.address-ux.1.zip`
- Bytes: `1,832,223`
- SHA-256: `2a28798140fafaa7d7b4e20bc4e3878fd1949f7014af854b002960a3ae1120fe`
- Production-package verifier: PASS (staged and extracted)
- Packaged PHP lint: `495 files / 0 failures` (PHP 8.5.0, vendor excluded)
- GitHub CI on runtime SHA: SUCCESS (`35655551926` push) — PHP 8.3 Minimum Supported, PHP 8.4 Compatibility, PHP 8.5 CETECH Production Target, PHP 8.5 MariaDB Geography/Migrations, PHP 8.5 WordPress/WooCommerce, CI Required Gates, JavaScript / Vitest, Control Plane
- PHPUnit (local PHP 8.5.0): Tests: 1357, Assertions: 8616, Deprecations: 14, Skipped: 1
- Vitest: 94 passed / 8 files
- Composer validate: PASS
- Team control plane: PASS
- Product control plane: PASS
- Not deployed to training

## Explicitly not done

- Issues #31 and #32
- Ashanti Region rename
- Accra / Kumasi / Greater Accra coverage or charges
- Location Packs
- Schema 7
- RC.13
- Pilot / FLAIROC / production / POS
