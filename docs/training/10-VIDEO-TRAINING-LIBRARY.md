# Video Training Library — CETECH Delivery Engine

**Plugin version:** 1.0.0-rc.12 (schema 6)  
**Status:** Recapture **deferred**. Written guides are the teaching authority until new recordings exist.

Older RC.2 recordings and the historical `docs/staff-training-rc2` branch use different menus and the pre-canonical geography model. **Do not use them as current truth.** Salvage notes live in the plugin repository (`docs/RC12-TRAINING-RC2-SALVAGE.md`), not in this staff guide set.

When recordings are made later, replace binaries under [`assets/videos/`](assets/videos/) and write narration against the live RC.12 screens. Until then, trainers tick [13](13-FEATURE-COVERAGE-AND-CONFIRMATION.md) on the live site.

---

## How to learn until videos exist

| Role | Use instead |
|------|-------------|
| First-time setup / each menu | [12-SETUP-CONFIGURE-AND-TEST](12-SETUP-CONFIGURE-AND-TEST.md) |
| Geography / Location Packs | [14](14-LOCATION-PACKS-AND-GEOGRAPHY.md), [15](15-DELIVERY-AREAS-AND-COVERAGE-GROUPS.md) |
| New staff | [01-QUICK-START](01-QUICK-START.md) then [05-STAFF-TRAINING-MANUAL](05-STAFF-TRAINING-MANUAL.md) |
| Everyday configuration | [02-COMPLETE-ADMIN-GUIDE](02-COMPLETE-ADMIN-GUIDE.md) + [03-USE-CASE-PLAYBOOK](03-USE-CASE-PLAYBOOK.md) |
| Shipments (when enabled) | [11-STAGE-14-SHIPMENTS](11-STAGE-14-SHIPMENTS.md) |
| Customer journey | Manual Module 8–9 + Visual Walkthrough sections 14–17 |
| Problem solving | [07-TROUBLESHOOTING-FAQ](07-TROUBLESHOOTING-FAQ.md) |
| Trainers | [06-TRAINER-GUIDE](06-TRAINER-GUIDE.md) with live demos and [13 — Feature coverage](13-FEATURE-COVERAGE-AND-CONFIRMATION.md) |

Companion written tour: [04-VISUAL-WALKTHROUGH](04-VISUAL-WALKTHROUGH.md).

---

## Intended RC.12 video modules (record these first)

Content must match live **1.0.0-rc.12** screens. Do not narrate RC.2 condition tables as current.

### G1 — Geography in 5 minutes

| Field | Detail |
|-------|--------|
| **What they will learn** | Location Pack vs Delivery Area vs Coverage Group vs Option vs Charge. Teaching example GH₵30. |
| **Related written guide** | [00](00-START-HERE.md), [01](01-QUICK-START.md) |
| **Status** | Deferred |

### G2 — Installing a Location Pack

| Field | Detail |
|-------|--------|
| **What they will learn** | Menu, Country, upload vs official download, Operation, Install / update pack, statuses, Continue / retry, attribution. |
| **Related written guide** | [14](14-LOCATION-PACKS-AND-GEOGRAPHY.md) |
| **Status** | Deferred |

### G3 — Understanding Delivery Areas and Coverage Groups

| Field | Detail |
|-------|--------|
| **What they will learn** | Add Delivery Area; Coverage group help text; AND/OR; add another group. |
| **Related written guide** | [15](15-DELIVERY-AREAS-AND-COVERAGE-GROUPS.md) |
| **Status** | Deferred |

### G4 — Entire / Selected / Except

| Field | Detail |
|-------|--------|
| **What they will learn** | Exact labels **Entire selected area**, **Selected locations**, **Entire selected area except…**. Teaching cities Accra, Tema, Madina, Adenta. |
| **Status** | Deferred |

### G5 — Priorities and overlaps

| Field | Detail |
|-------|--------|
| **What they will learn** | Lower numbers first; Accra 8 vs Greater Accra 25; Test an address Primary / Also matches; not smallest-area-wins. |
| **Status** | Deferred |

### G6 — Fixing Review Required

| Field | Detail |
|-------|--------|
| **What they will learn** | Data not destroyed; install pack; Run safe legacy reconciliation; I reviewed this migrated coverage; do not delete areas. |
| **Status** | Deferred |

### G7 — Creating a Delivery Charge

| Field | Detail |
|-------|--------|
| **What they will learn** | Delivery Charges menu; area + option + GH₵30; pack is not a price. |
| **Related written guide** | [12](12-SETUP-CONFIGURE-AND-TEST.md) |
| **Status** | Deferred |

### G8 — Testing PDP → Cart → Checkout

| Field | Detail |
|-------|--------|
| **What they will learn** | Country → Region → Locality cascade; delivery card name/ETA/fee; cart; Classic and/or Blocks; stop before payment. |
| **Related written guide** | [04](04-VISUAL-WALKTHROUGH.md) |
| **Status** | Deferred |

## Still useful later (recapture against RC.12, do not use RC.2 tape)

Keep these topics after G1–G8. Rewrite narration; do not reuse RC.2 binaries as authority.

### 01 — Getting started overview through 16 — Bulk Tools

The previous RC.9 topic list (Site-wide Defaults, simple/variable products, Preview, multi-product, orders, troubleshooting, boundaries, shipments, mixed pickup, Bulk Tools) remains valid **as topics**. Record them on RC.12 screens only.

---

## Capture notes (when recapture is authorised)

- Use QA products only (#39705, #39717–#39719).  
- Do not place extra paid orders.  
- Do not capture credentials, Cloudflare challenge, customer PII, real emails, API keys, or private cost/margin data.  
- Prefer demonstration/test data. Do not mutate live business configuration for prettier shots without owner permission.  
- Auth state stays in gitignored `training/playwright/auth/`.  
- Update this file’s Status column when a binary actually exists and matches the live 1.0.0-rc.12 screens.
