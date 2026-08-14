# Stage 13F — Customer-facing delivery presentation polish + final rate verification

**Document status:** Implementation complete; owner QA.1 **PASSED**; finalized as `1.0.0-rc.4` in Stage 13G  
**Base release:** `1.0.0-rc.3` — **untouched** (do not retag)  
**Final release:** `1.0.0-rc.4` — see `docs/STAGE-13G-RC4-FINALIZATION.md`  
**Schema target:** `3` (unchanged)  
**Date:** 2026-08-14  
**FLAIROC:** owner tested QA.1; finalization stage itself did **not** modify FLAIROC

---

## 1. Verdict

**PASS (local).** Customer-facing delivery presentation is now compact, non-technical, and non-duplicative. WooCommerce shipping rate labels safely use the selected public Delivery Option label. Order `#39727` USD **250.00** is consistent with a configured rate-card `base_amount` of **250.00** for quantity 1 — not a repository multiplication defect. Owner must verify the live FLAIROC Delivery Charge value. No RC.4 package yet.

---

## 2. Plain-English summary

Shoppers now see a clean delivery choice on the product page (option name + estimated delivery), and one compact **Delivery details** block on thank-you / My Account / customer emails (option + estimate). Internal fulfilment labels, generic “Delivery method: Delivery”, and repeated shipping prices were removed from customer surfaces. WooCommerce can show `$250.00 via FLAIROC QA Standard Delivery` instead of `via Delivery`. The 250.00 amount on order `#39727` matches how the quote engine stores a configured 250.00 flat charge for one item — check the live Delivery Charge record before assuming a code bug.

---

## 3. Exact customer-facing changes

### Public rendering contract (one contract)

Customer surfaces show only:

1. **Delivery option** — public offer label  
2. **Estimated delivery** / **Ready for pickup** — public ETA (prefix stripped when duplicated)  
3. **Pickup-only extras** when present in the public summary payload: pickup location, address, instructions  

Explicitly omitted from customer output:

- Fulfilment availability (“In Warehouse”)
- Generic fulfilment choice / “Delivery method: Delivery”
- Public description on the **compact product selector** (decision **b** — remain in data/admin contract; omitted from compact product UX)
- Delivery charge / shipping summary duplication (WooCommerce order totals already show shipping)
- Supplier / origin / logistics profile / priority / IDs / fingerprints / rate-card codes

### Product page

- Hierarchy: radio + bold public label; secondary line `Estimated delivery: …`
- No fulfilment group headings
- No public description in the compact selector
- Shared CSS: `assets/frontend/product-delivery-selector.css`

### Order / thank-you / My Account / customer emails

- Single **Delivery details** section
- Compact list: Delivery option + Estimated delivery
- Multi-line orders show product name then the same compact rows
- Removed custom “Shipping summary” package block
- Removed duplicated delivery charge

### WooCommerce shipping rate label

- Managed package `rate_label` prefers `delivery_offer_public_label` when present
- Fallback remains `Delivery` / `Store pickup` (and numbered variants for multi-package without a public label)
- Multi-package with public labels: `{label} (N)`
- Method ID `delivery_engine_selected_offer`, grouping, validation, snapshots, server authority unchanged

### Pickup

- Compact contract uses **Ready for pickup** for ETA
- Location / address / instructions render **only if** present on the public summary payload
- V1 protected line snapshots do **not** yet store pickup location fields — richer pickup display remains deferred without expanding Stage 8 snapshot architecture

---

## 4. Before → after examples

### Product page

**Before:**  
`FLAIROC QA Standard Delivery QA-only delivery option for Delivery Engine Stage 0B. Estimated 3–6 business days`

**After:**

```text
○ FLAIROC QA Standard Delivery
  Estimated delivery: 3–6 business days
```

### Thank-you / My Account / email

**Before:** Fulfilment / Delivery method / Estimated delivery / Delivery charge + Shipping summary (method + charge)

**After:**

```text
Delivery details
Delivery option: FLAIROC QA Standard Delivery
Estimated delivery: 3–6 business days
```

### WooCommerce shipping line

**Before:** `$250.00 via Delivery`  
**After:** `$250.00 via FLAIROC QA Standard Delivery`

---

## 5. USD 250 investigation (order #39727)

| Evidence | Finding |
|----------|---------|
| Product `#39717` / Variation `#39718` / option FLAIROC QA Standard Delivery | Matches Stage 6B QA catalog |
| Product price USD 19.99 + shipping 250.00 = total 269.99 | Quantity **1** for the product line |
| Line snapshot `quoted_amount = 250.0000` | Consistent with package `package_total_delivery_amount = 250.0000` |
| Historical QA expectation | `flairoc_qa_rate_card` was **25.00** (`fixed_per_shipment`) on earlier orders (`#39706`, `#39711`, `#39724`) |
| Quote engine (`RateQuoteEngine::calculate_amount`) | `fixed_per_shipment` returns `base_amount` unchanged; `fixed_per_item` multiplies by quantity only |
| Local reproduction | qty 1 + `base_amount` 25.00 → **25.0000**; qty 1 + `base_amount` 250.00 → **250.0000**; no silent ×10 path for qty 1 |
| Currency / multicurrency | Quote uses store WooCommerce currency; snapshot currency matches; no transform that turns 25 into 250 in code |
| Snapshot corruption | Ruled out — line and package both 250.0000 and PHP log PASS |

**Likely source:** the live matched Delivery Charge / rate card `base_amount` was **250.00** at quote time (config change or different matching card), not a quantity-multiplication bug in this repository.

**Owner action:** verify current FLAIROC Delivery Charge for FLAIROC QA Standard Delivery. Restore to **25.00** if that remains the intended QA rate. Do **not** rewrite historical order `#39727` snapshots.

**No code fix** for the amount — presentation stage must not invent rate changes.

---

## 6. Files changed

| Path | Role |
|------|------|
| `src/Presentation/Shared/DeliveryPresentationLabels.php` | Compact public contract |
| `src/Presentation/Frontend/ProductDeliverySelectorRenderer.php` | Product hierarchy + CSS enqueue |
| `src/Presentation/Frontend/CustomerOrderDeliverySummaryRenderer.php` | Compact thank-you / My Account |
| `src/Presentation/Email/CustomerOrderDeliveryEmailSummaryRenderer.php` | Compact customer emails |
| `src/Application/Order/CustomerOrderDeliverySummaryBuilder.php` | Optional pickup DTO fields (null until snapshotted) |
| `src/Application/Shipping/ShippingPackageBuilder.php` | Public label as WC rate label |
| `src/Infrastructure/WooCommerce/Shipping/SelectedOfferShippingMethod.php` | Comment only |
| `src/Presentation/Frontend/VariableDeliverySelectorAssets.php` | Shared CSS + i18n estimate labels |
| `assets/frontend/product-delivery-selector.css` | **New** hierarchy styles |
| `assets/frontend/customer-order-delivery-summary.css` | **New** compact summary styles |
| `assets/frontend/variable-delivery-selector.css` | Shell-only styles |
| `assets/frontend/variable-delivery-selector.js` | Compact JS hierarchy; omit description |
| `tests/Unit/Presentation/Stage13FCustomerPresentationTest.php` | **New** regression suite |
| `tests/Unit/Presentation/DeliveryPresentationCleanupTest.php` | Updated expectations |
| `tests/Unit/Shipping/ShippingPackageGroupingTest.php` | Multi-package label expectations |
| `tests/Unit/Runtime/RateQuoteSafetyTest.php` | 25 / 250 qty-1 safety |
| `tests/Unit/Frontend/VariableDeliverySelectorAssetsTest.php` | Shared CSS enqueue |
| `tests/js/variable-delivery-selector.test.js` | Hierarchy + omit description |
| `tests/bootstrap.php` | `esc_html__` / `esc_attr__` stubs |
| `docs/STAGE-13F-CUSTOMER-PRESENTATION-POLISH.md` | This record |
| `docs/AI-HANDOFF.md` | Status pointer |

---

## 7. Tests / counts

| Gate | Result |
|------|--------|
| `composer validate --no-check-publish` | PASS |
| PHP lint (changed PHP files) | PASS |
| Full PHPUnit | **322** tests, **1697** assertions, 0 failures |
| `npm run test:js` | **11** tests, 0 failures |

---

## 8. Runtime / inheritance / shipping / snapshot semantics

**Not rewritten.** Presentation and shipping **label** only.

Preserved:

- GLOBAL → PRODUCT → VARIATION
- Stage 6 variable selection
- Stage 8 grouping / package architecture
- HPOS / protected `_cetech_de_*` snapshots / immutability
- Server authority; no silent offer replacement
- Explicit zero remains valid free shipping
- Unresolved / malformed rate still never becomes free
- Access / capability architecture
- Legacy UI remains retired

---

## 9–11. Release hygiene

- **FLAIROC NOT MODIFIED**
- **NO PACKAGE**
- **NO COMMIT / TAG**
- **`v1.0.0-rc.3` untouched**

---

## 12. Does this warrant RC.4?

**Yes — after owner FLAIROC smoke of presentation + Delivery Charge verification.**

RC.4 would be a presentation / label polish release on top of RC.3, not a new architecture stage. Include:

1. Owner confirms live Delivery Charge is intentional (25 vs 250)  
2. One smoke order shows compact product / thank-you / shipping-via-public-label  
3. Final PHP log PASS  
4. Then package / commit / tag `1.0.0-rc.4` only when instructed

**Do not** start Stage 14 / shipments / Blocks from this stage.

---

## STOP
