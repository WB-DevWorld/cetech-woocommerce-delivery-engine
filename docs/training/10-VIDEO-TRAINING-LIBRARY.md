# Video Training Library — CETECH Delivery Engine (RC.2)

**Plugin version:** 1.0.0-rc.2  
**Purpose:** Staff learn by reading + screenshots + **walkthrough videos** + practice.  
**Video folder:** [`assets/videos/`](assets/videos/) (see storage policy in that README)  
**Narration scripts:** [`video-scripts/`](video-scripts/)  
**Recording harness:** `training/playwright-videos/` (deliberate pacing; **not** the normal smoke suite)

Capture method labels: **PLAYWRIGHT** | **HUMAN** | **MIXED**.  
As of Stage 12C setup: binaries are **not yet committed**; scripts/transcripts/index are in Git. Recordings use QA fixtures only.

---

## How to use this library

| Role | Start with |
|------|------------|
| New staff | Video **01**, then **12**, then practise with [05-STAFF-TRAINING-MANUAL](05-STAFF-TRAINING-MANUAL.md) |
| Everyday configuration | **02**, **03**, **04**, **05**, **06** |
| Customer journey support | **07**, **08**, **09** |
| Problem solving | **10**, then [07-TROUBLESHOOTING-FAQ](07-TROUBLESHOOTING-FAQ.md) |
| Boundaries | **11** |
| Trainers | Full set + [06-TRAINER-GUIDE](06-TRAINER-GUIDE.md) |

Screenshots companion: [04-VISUAL-WALKTHROUGH](04-VISUAL-WALKTHROUGH.md).

---

## Video index

### 01 — Getting started overview

| Field | Detail |
|-------|--------|
| **Title** | Getting started overview |
| **Who should watch** | New staff, trainers |
| **Duration** | 5–8 minutes |
| **What they will learn** | Where Delivery Engine lives; Delivery Settings as everyday home; Default / Product / Variation; Preview; offers/zones/rate cards at a high level; order Delivery information |
| **Prerequisite** | WordPress admin access |
| **Video file** | `assets/videos/01-getting-started-overview.webm` _(pending recording — see storage README)_ |
| **Transcript / script** | [video-scripts/01-getting-started-overview.md](video-scripts/01-getting-started-overview.md) · [assets/videos/transcripts/01-getting-started-overview.vtt](assets/videos/transcripts/01-getting-started-overview.vtt) |
| **Related written guide** | [00-START-HERE](00-START-HERE.md), [01-QUICK-START](01-QUICK-START.md) |
| **Practice exercise** | Open Delivery Settings + Preview; do not edit Legacy Rules |
| **Capture method** | **HUMAN** (Playwright harness ready; auth/Cloudflare blocked unattended capture in Stage 12C) |
| **Status** | Script + VTT ready · binary pending — follow HUMAN shot list |

### 02 — Default delivery settings

| Field | Detail |
|-------|--------|
| **Title** | Default delivery settings |
| **Who should watch** | Staff who set store-wide defaults |
| **Duration** | 4–6 minutes |
| **What they will learn** | Opening Default Settings; inheritance; fulfilment / delivery method / offers; save → Preview |
| **Prerequisite** | Video 01 or Quick Start |
| **Video file** | `assets/videos/02-default-delivery-settings.webm` _(pending)_ |
| **Transcript / script** | [video-scripts/02-default-delivery-settings.md](video-scripts/02-default-delivery-settings.md) · [.vtt](assets/videos/transcripts/02-default-delivery-settings.vtt) |
| **Related written guide** | [02-COMPLETE-ADMIN-GUIDE](02-COMPLETE-ADMIN-GUIDE.md) |
| **Practice exercise** | Read Default Settings without unauthorised saves |
| **Capture method** | **HUMAN** |
| **Status** | Script + VTT ready · binary pending |

### 03 — Configure a simple product

| Field | Detail |
|-------|--------|
| **Title** | Configure a simple product |
| **Who should watch** | Everyday staff |
| **Duration** | 5–8 minutes |
| **What they will learn** | Product settings for QA **#39705**; inherit vs override; Preview; customer Delivery options |
| **Prerequisite** | Videos 01–02 |
| **Video file** | `assets/videos/03-configure-simple-product.webm` _(pending)_ |
| **Transcript / script** | [video-scripts/03-configure-simple-product.md](video-scripts/03-configure-simple-product.md) · [.vtt](assets/videos/transcripts/03-configure-simple-product.vtt) |
| **Related written guide** | [03-USE-CASE-PLAYBOOK](03-USE-CASE-PLAYBOOK.md), Staff Manual Module 4 |
| **Practice exercise** | Configure/Preview #39705 only; restore if changed |
| **Capture method** | **HUMAN** |
| **Status** | Script + VTT ready · binary pending |

### 04 — Configure a variable product

| Field | Detail |
|-------|--------|
| **Title** | Configure a variable product |
| **Who should watch** | Everyday staff |
| **Duration** | 7–10 minutes |
| **What they will learn** | Parent **#39717**, variations **#39718** / **#39719**, Preview, storefront variation selector |
| **Prerequisite** | Video 03 |
| **Video file** | `assets/videos/04-configure-variable-product.webm` _(pending)_ |
| **Transcript / script** | [video-scripts/04-configure-variable-product.md](video-scripts/04-configure-variable-product.md) · [.vtt](assets/videos/transcripts/04-configure-variable-product.vtt) |
| **Related written guide** | Use-Case Playbook (variable), Staff Manual Module 5 |
| **Practice exercise** | Open parent + both variations; Preview; storefront select variation (no payment) |
| **Capture method** | **HUMAN** |
| **Status** | Script + VTT ready · binary pending |

### 05 — Variation inheritance and overrides

| Field | Detail |
|-------|--------|
| **Title** | Variation inheritance and overrides |
| **Who should watch** | Staff and trainers |
| **Duration** | 6–9 minutes |
| **What they will learn** | Default → Product → Variation; Use inherited setting / Set a different value here / Turn off; offer collections |
| **Prerequisite** | Videos 02–04 |
| **Video file** | `assets/videos/05-variation-inheritance-overrides.webm` _(pending)_ |
| **Transcript / script** | [video-scripts/05-variation-inheritance-overrides.md](video-scripts/05-variation-inheritance-overrides.md) · [.vtt](assets/videos/transcripts/05-variation-inheritance-overrides.vtt) |
| **Related written guide** | [01-QUICK-START](01-QUICK-START.md) inheritance table |
| **Practice exercise** | Identify each mode label on QA screens without unwanted saves |
| **Capture method** | **HUMAN** |
| **Status** | Script + VTT ready · binary pending |

### 06 — Delivery Settings Preview

| Field | Detail |
|-------|--------|
| **Title** | Delivery Settings Preview |
| **Who should watch** | Everyday staff |
| **Duration** | 4–6 minutes |
| **What they will learn** | Ready, Needs configuration, Currently using; safe response when incomplete |
| **Prerequisite** | Video 01 |
| **Video file** | `assets/videos/06-delivery-settings-preview.webm` _(pending)_ |
| **Transcript / script** | [video-scripts/06-delivery-settings-preview.md](video-scripts/06-delivery-settings-preview.md) · [.vtt](assets/videos/transcripts/06-delivery-settings-preview.vtt) |
| **Related written guide** | [04-VISUAL-WALKTHROUGH](04-VISUAL-WALKTHROUGH.md), [07-TROUBLESHOOTING-FAQ](07-TROUBLESHOOTING-FAQ.md) |
| **Practice exercise** | Preview #39705 and one variation; note Currently using |
| **Capture method** | **HUMAN** |
| **Status** | Script + VTT ready · binary pending |

### 07 — Customer product, cart, and checkout

| Field | Detail |
|-------|--------|
| **Title** | Customer product, cart, and checkout |
| **Who should watch** | Staff supporting the customer journey |
| **Duration** | 6–9 minutes |
| **What they will learn** | Product → selection → cart → reload → checkout Delivery charge; RC.2 labels |
| **Prerequisite** | Videos 03 or 04 |
| **Video file** | `assets/videos/07-customer-cart-checkout.webm` _(pending)_ |
| **Transcript / script** | [video-scripts/07-customer-cart-checkout.md](video-scripts/07-customer-cart-checkout.md) · [.vtt](assets/videos/transcripts/07-customer-cart-checkout.vtt) |
| **Related written guide** | Staff Manual Modules 8–9 |
| **Practice exercise** | Select delivery on #39705; cart; checkout; **do not pay** |
| **Capture method** | **HUMAN** |
| **Status** | Script + VTT ready · binary pending |

### 08 — Multi-product shipping

| Field | Detail |
|-------|--------|
| **Title** | Multi-product shipping |
| **Who should watch** | Staff handling multi-line carts |
| **Duration** | 5–8 minutes |
| **What they will learn** | Compatible products sharing one charge (QA expectation **25.00**); incompatible paths separated |
| **Prerequisite** | Video 07 |
| **Video file** | `assets/videos/08-multi-product-shipping.webm` _(pending)_ |
| **Transcript / script** | [video-scripts/08-multi-product-shipping.md](video-scripts/08-multi-product-shipping.md) · [.vtt](assets/videos/transcripts/08-multi-product-shipping.vtt) |
| **Related written guide** | Staff Manual / FAQ multi-product; reference order **#39724** |
| **Practice exercise** | Two compatible QA products in cart; confirm shared charge; no new order |
| **Capture method** | **HUMAN** |
| **Status** | Script + VTT ready · binary pending |

### 09 — Order Delivery information

| Field | Detail |
|-------|--------|
| **Title** | Order Delivery information |
| **Who should watch** | Everyday staff |
| **Duration** | 4–6 minutes |
| **What they will learn** | Read Delivery information on **#39721** / **#39724**; operational fields only |
| **Prerequisite** | Video 01 |
| **Video file** | `assets/videos/09-order-delivery-information.webm` _(pending)_ |
| **Transcript / script** | [video-scripts/09-order-delivery-information.md](video-scripts/09-order-delivery-information.md) · [.vtt](assets/videos/transcripts/09-order-delivery-information.vtt) |
| **Related written guide** | [04-VISUAL-WALKTHROUGH](04-VISUAL-WALKTHROUGH.md) order section |
| **Practice exercise** | Open #39721 or #39724 read-only; list operational fields |
| **Capture method** | **HUMAN** |
| **Status** | Script + VTT ready · binary pending |

### 10 — Staff troubleshooting

| Field | Detail |
|-------|--------|
| **Title** | Staff troubleshooting |
| **Who should watch** | Everyday staff |
| **Duration** | 7–10 minutes |
| **What they will learn** | Needs configuration; missing choices; wrong inheritance; charges; multi-product surprises; escalate rules — **no** SSH/SQL/PHP/Redis/Nginx/Code Snippets |
| **Prerequisite** | Videos 06–09 |
| **Video file** | `assets/videos/10-staff-troubleshooting.webm` _(pending)_ |
| **Transcript / script** | [video-scripts/10-staff-troubleshooting.md](video-scripts/10-staff-troubleshooting.md) · [.vtt](assets/videos/transcripts/10-staff-troubleshooting.vtt) |
| **Related written guide** | [07-TROUBLESHOOTING-FAQ](07-TROUBLESHOOTING-FAQ.md) |
| **Practice exercise** | Walk one FAQ scenario with Preview → Settings → escalate |
| **Capture method** | **HUMAN** |
| **Status** | Script + VTT ready · binary pending |

### 11 — Legacy Delivery Rules explained

| Field | Detail |
|-------|--------|
| **Title** | Legacy Delivery Rules explained |
| **Who should watch** | All staff (boundary) |
| **Duration** | 3–5 minutes |
| **What they will learn** | Delivery Settings = normal; Legacy Rules = compatibility/migration only |
| **Prerequisite** | Video 01 |
| **Video file** | `assets/videos/11-legacy-rules-explained.webm` _(pending)_ |
| **Transcript / script** | [video-scripts/11-legacy-rules-explained.md](video-scripts/11-legacy-rules-explained.md) · [.vtt](assets/videos/transcripts/11-legacy-rules-explained.vtt) |
| **Related written guide** | [00-START-HERE](00-START-HERE.md) surface map |
| **Practice exercise** | Open Legacy once with trainer; leave without editing |
| **Capture method** | **HUMAN** |
| **Status** | Script + VTT ready · binary pending |

### 12 — Complete staff walkthrough

| Field | Detail |
|-------|--------|
| **Title** | Complete staff walkthrough |
| **Who should watch** | New staff onboarding |
| **Duration** | 15–25 minutes |
| **What they will learn** | End-to-end path: overview → settings → simple/variable → Preview → customer journey → multi-product → orders → troubleshooting → Legacy → approval boundaries |
| **Prerequisite** | None (or Quick Start) |
| **Video file** | `assets/videos/12-complete-staff-walkthrough.webm` _(pending)_ |
| **Transcript / script** | [video-scripts/12-complete-staff-walkthrough.md](video-scripts/12-complete-staff-walkthrough.md) · [.vtt](assets/videos/transcripts/12-complete-staff-walkthrough.vtt) |
| **Related written guide** | [05-STAFF-TRAINING-MANUAL](05-STAFF-TRAINING-MANUAL.md) |
| **Practice exercise** | Module 1 practical test + Preview on #39705 |
| **Capture method** | **HUMAN** |
| **Status** | Script + VTT ready · binary pending |

---

## Capture status summary

| Title | Duration | Capture method | Status |
|-------|----------|----------------|--------|
| 01 Getting started overview | 5–8 min | **HUMAN** | Script + VTT ready; binary pending |
| 02 Default delivery settings | 4–6 min | **HUMAN** | Script + VTT ready; binary pending |
| 03 Configure simple product | 5–8 min | **HUMAN** | Script + VTT ready; binary pending |
| 04 Configure variable product | 7–10 min | **HUMAN** | Script + VTT ready; binary pending |
| 05 Inheritance / overrides | 6–9 min | **HUMAN** | Script + VTT ready; binary pending |
| 06 Delivery Settings Preview | 4–6 min | **HUMAN** | Script + VTT ready; binary pending |
| 07 Customer cart checkout | 6–9 min | **HUMAN** | Script + VTT ready; binary pending |
| 08 Multi-product shipping | 5–8 min | **HUMAN** | Script + VTT ready; binary pending |
| 09 Order Delivery information | 4–6 min | **HUMAN** | Script + VTT ready; binary pending |
| 10 Staff troubleshooting | 7–10 min | **HUMAN** | Script + VTT ready; binary pending |
| 11 Legacy rules explained | 3–5 min | **HUMAN** | Script + VTT ready; binary pending |
| 12 Complete staff walkthrough | 15–25 min | **HUMAN** | Script + VTT ready; binary pending |

**Stage 12C capture note (closed early by owner request):** Playwright video harness is ready (`training/playwright-videos/`, base `https://flairoc.com/intl/`). **1/12** local draft `.webm` exists on disk (`09-order-delivery-information.webm`, gitignored). **Videos 01–08 and 10–12 binaries are missing** — use [`video-scripts/HUMAN-RECORDING-SHOT-LIST.md`](video-scripts/HUMAN-RECORDING-SHOT-LIST.md) or `npm run training:video` later. Do not invent capture success.

**Human fallback:** [`video-scripts/HUMAN-RECORDING-SHOT-LIST.md`](video-scripts/HUMAN-RECORDING-SHOT-LIST.md)

---

## Recording commands

```bash
# 1) Human-assisted auth (shared with screenshot harness; gitignored storage state)
npm run training:auth

# 2) Install video harness deps (once)
npm --prefix training/playwright-videos install
npx --prefix training/playwright-videos playwright install chrome

# 3) Record paced training videos (headed; requires auth for admin videos)
npm run training:video
# or a single video:
npm run training:video -- --grep @video01
```

If Cloudflare still blocks automation after human login, record with the human shot list and set capture method to **HUMAN**.

---

## Storage recommendation

Keep scripts, this index, and `.vtt` files in Git. Store large `.webm`/`.mp4` on a **private GitHub Release** or **private Drive / internal training storage**, and link paths here after upload. See [`assets/videos/README.md`](assets/videos/README.md).
