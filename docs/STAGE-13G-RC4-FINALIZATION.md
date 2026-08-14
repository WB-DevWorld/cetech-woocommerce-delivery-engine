# Stage 13G — RC.4 finalization

**Document status:** Final Stage 13F / RC.4 release record  
**Release version:** `1.0.0-rc.4`  
**Schema target:** `3` (unchanged; no migration)  
**Branch:** `feat/site-wide-delivery-defaults`  
**Base:** tagged `v1.0.0-rc.3` (`f91da5dad677410ca05fbcb86ef8fd83596b3871`) — **untouched**  
**RC.2 tag:** `v1.0.0-rc.2` — **untouched**  
**RC.4 tag:** `v1.0.0-rc.4`  
**Date:** 2026-08-14  
**FLAIROC:** **NOT MODIFIED** in this stage (owner installs after reviewing this package)

---

## 1. Purpose

Finalize the owner-approved Stage 13F customer presentation polish (physically tested as `1.0.0-rc.4-qa.1`) as the tagged release candidate:

`1.0.0-rc.4`

This is **release finalization only**. It does **not** begin Stage 14, shipments, tracking, Blocks, carrier integrations, or any new feature work.

---

## 2. Owner acceptance baseline (authoritative)

The owner physically installed and tested **`1.0.0-rc.4-qa.1`** on FLAIROC.

**Owner verdict: ALL PASS**

Confirmed:

| Check | Result |
|-------|--------|
| QA.1 installed successfully | PASS |
| SHA matched | PASS |
| Plugin remained active | PASS |
| Immediate PHP log | PASS |
| Compact customer-facing product delivery presentation | PASS |
| QA/internal delivery description removed from customer presentation | PASS |
| Estimated delivery presentation | PASS |
| No customer-facing technical/private delivery data | PASS |
| Cart selection persistence | PASS |
| Checkout validation | PASS |
| Genuine WooCommerce shipping | PASS |
| Shipping amount matched configured Delivery Charge | PASS |
| Shipping rate uses public Delivery Option label | PASS |
| Thank-you / order customer delivery presentation | PASS |
| Fulfilment removed from customer-facing delivery details | PASS |
| Internal delivery method removed from customer-facing delivery details | PASS |
| Duplicate delivery charge removed | PASS |
| Duplicate Shipping summary removed | PASS |
| No supplier/origin/logistics/priority/internal identifiers exposed | PASS |
| Final Delivery Engine PHP log | PASS |
| No new Delivery Engine / fatal errors | PASS |

**No additional QA build was required.** Finalization proceeds directly from the owner-accepted QA.1 product surface to `1.0.0-rc.4`.

---

## 3. What RC.4 finalizes

| Area | Outcome |
|------|---------|
| Stage 13F | Compact public customer delivery presentation (product / thank-you / My Account / email) |
| Stage 13F | WooCommerce shipping rate label prefers selected public Delivery Option label |
| Stage 13F | Customer surfaces omit fulfilment labels, generic delivery method, duplicate charge / Shipping summary, and private logistics data |
| Version identity | `1.0.0-rc.4` (plugin header, `CETECH_DE_VERSION`, readme Stable tag) |
| Schema | Target remains `3` |

Inherited unchanged from RC.3:

- Stage 13 / 13B / 13C / 13D / 13D-R1 admin product (site-wide defaults, Overview, Setup Guide, normal-menu simplification, real role Access, Administrator recovery)

---

## 4. Source audit (pre-finalization)

| Item | Value |
|------|-------|
| Branch | `feat/site-wide-delivery-defaults` |
| Pre-finalization HEAD | `f91da5dad677410ca05fbcb86ef8fd83596b3871` (= `v1.0.0-rc.3`) |
| Working tree | Dirty with Stage 13F presentation polish + QA.1 version identity (owner-tested set) |
| Schema target | `3` |
| RC.3 tag target | `f91da5dad677410ca05fbcb86ef8fd83596b3871` — not moved |
| RC.2 tag | untouched |

Stage 13F working-tree product changes matched the owner-tested QA.1 package contents (presentation renderers, shipping package public label preference, frontend CSS/JS, unit tests, packaging verifier touch). No Stage 13F product code was discarded.

---

## 5. Final normal Delivery Engine menu

Unchanged from RC.3:

- Overview
- Site-wide Defaults
- Delivery Options
- Delivery Areas
- Delivery Charges
- Pickup Locations
- Product Exceptions
- Needs Attention
- Settings

Setup Guide remains visible while incomplete and reopenable from Settings.

- No normal **Legacy Delivery Rules** submenu
- No normal **Technical Diagnostics** submenu

---

## 6. Customer presentation contract (RC.4)

Customer surfaces show only:

1. **Delivery option** — public offer label  
2. **Estimated delivery** / **Ready for pickup** — public ETA  
3. **Pickup-only extras** when present in the public summary payload  

Explicitly omitted from customer output:

- Fulfilment availability labels
- Generic “Delivery method: Delivery”
- Public description on the compact product selector
- Duplicate delivery charge / custom Shipping summary blocks
- Supplier / origin / logistics profile / priority / IDs / fingerprints / rate-card codes

WooCommerce shipping line may show the public Delivery Option label (fallback remains Delivery / Store pickup).

---

## 7. Administrator access / recovery

Unchanged from Stage 13D-R1 / RC.3:

- Administrator is a protected full-access role
- Subordinate real WordPress roles remain configurable
- Independent Restore Administrator Access uses native `manage_options` + nonce
- Recovery does **not** require `view_delivery_diagnostics`

---

## 8. Runtime non-regression (preserved; not rewritten)

Finalization did **not** alter:

- Server authority for eligibility, rates, pricing, ETA, transactional decisions
- GLOBAL → PRODUCT → VARIATION field-level inheritance
- Field-by-field exception behavior
- Stage 6 simple + variable runtime
- Stage 8 package/grouping, genuine WooCommerce shipping, checkout validation, protected HPOS order snapshots
- Snapshot immutability
- Explicit configured numeric zero handling
- Missing / malformed / nonnumeric rate fail-closed behavior (never silent free shipping)
- No silent Delivery Option replacement
- Supplier / origin / logistics privacy
- International Delivery = Air and/or Sea only
- Administrator protected full access
- `manage_options` recovery path

---

## 9. Final test gates (local)

| Gate | Result |
|------|--------|
| `composer validate --no-check-publish` | PASS |
| PHP lint (bootstrap + `src` + `database`) | **258** files / **0** fails |
| Full PHPUnit | **322** tests / **1697** assertions / PASS (3 pre-existing deprecations) |
| `npm run test:js` | **11** tests / PASS |
| Playwright | **Not claimed** — no supported root Playwright setup for this gate |
| Production package / autoload / Linux-case verification | Recorded after ZIP build (section 11) |

---

## 10. Deliberately deferred

- Stage 14 / shipment records / tracking / customer timeline
- WooCommerce Blocks checkout architecture
- Carrier API integrations / live quotes
- Bulk import tooling
- Any new feature work beyond finalized Stage 13F presentation polish

---

## 11. Package identity

| Item | Value |
|------|-------|
| ZIP | `cetech-woocommerce-delivery-engine-1.0.0-rc.4.zip` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.4.zip` |
| Desktop path | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.4.zip` |
| Root folder | `cetech-woocommerce-delivery-engine/` |
| Schema target | `3` |
| Tag | `v1.0.0-rc.4` |
| Built with | `scripts/build-v1-rc-package.ps1` (**no** `-AllowDirty`) |

Exact commit SHA, ZIP byte size, and SHA-256 are filled after the clean tagged build in the completion table below.

### Completion table (post-build)

| Item | Value |
|------|-------|
| Final commit SHA | _(filled after commit)_ |
| ZIP bytes | _(filled after build)_ |
| SHA-256 | _(filled after build)_ |

---

## 12. FLAIROC status for this stage

**NOT MODIFIED.**

Owner installs final RC.4 via clean folder replace and performs only a very short confirmation:

1. Plugin version  
2. One product-page delivery display  
3. One checkout shipping display  
4. Final PHP log  

Do **not** place another QA order unless something is wrong.

---

## 13. Next step

After the short owner confirmation above: **STOP**.

Do not start Stage 14 or any new feature.
