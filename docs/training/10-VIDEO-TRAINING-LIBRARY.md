# Video Training Library — CETECH Delivery Engine (RC.5)

**Plugin version:** 1.0.0-rc.5  
**Status:** Recapture **deferred**. Written guides are the teaching authority until new recordings exist.

Older recordings use different menu names and an older customer layout. **Do not use them as current truth.** If an old name appears in a leftover file, the mapping is only in the [Troubleshooting FAQ](07-TROUBLESHOOTING-FAQ.md).

When recordings are made later, replace binaries under [`assets/videos/`](assets/videos/) and update this index. Narration scripts in `video-scripts/` will need a rewrite to match the live RC.5 screens.

---

## How to learn until videos exist

| Role | Use instead |
|------|-------------|
| First-time setup / each menu | [12-SETUP-CONFIGURE-AND-TEST](12-SETUP-CONFIGURE-AND-TEST.md) |
| New staff | [01-QUICK-START](01-QUICK-START.md) then [05-STAFF-TRAINING-MANUAL](05-STAFF-TRAINING-MANUAL.md) |
| Everyday configuration | [02-COMPLETE-ADMIN-GUIDE](02-COMPLETE-ADMIN-GUIDE.md) + [03-USE-CASE-PLAYBOOK](03-USE-CASE-PLAYBOOK.md) |
| Shipments (when enabled) | [11-STAGE-14-SHIPMENTS](11-STAGE-14-SHIPMENTS.md) |
| Customer journey | Manual Module 8–9 + Visual Walkthrough sections 14–17 |
| Problem solving | [07-TROUBLESHOOTING-FAQ](07-TROUBLESHOOTING-FAQ.md) |
| Trainers | [06-TRAINER-GUIDE](06-TRAINER-GUIDE.md) with live demos |

Companion written tour: [04-VISUAL-WALKTHROUGH](04-VISUAL-WALKTHROUGH.md).

---

## Intended video index (for later recapture)

Keep this list so future recordings match the staff course. Filenames may stay the same; **content must match RC.5**.

### 01 — Getting started overview

| Field | Detail |
|-------|--------|
| **What they will learn** | Overview as everyday home; Site-wide Defaults; Product Exceptions; Preview Delivery; compact customer selector |
| **Related written guide** | [00-START-HERE](00-START-HERE.md), [01-QUICK-START](01-QUICK-START.md) |
| **Practice** | Open Overview + Site-wide Defaults; stay on the everyday menu |
| **Status** | Deferred |

### 02 — Site-wide Defaults

| Field | Detail |
|-------|--------|
| **What they will learn** | Fulfilment types, primary default, Save Changes, inherited fields update later without mass copy |
| **Related written guide** | [02-COMPLETE-ADMIN-GUIDE](02-COMPLETE-ADMIN-GUIDE.md) |
| **Status** | Deferred |

### 03 — Configure a simple product

| Field | Detail |
|-------|--------|
| **What they will learn** | Product Delivery tab; leave inherited; Customize This Product for one field |
| **Practice** | QA #39705 |
| **Status** | Deferred |

### 04 — Configure a variable product

| Field | Detail |
|-------|--------|
| **What they will learn** | Parent #39717; variations inherit Product Settings |
| **Status** | Deferred |

### 05 — Variation exceptions

| Field | Detail |
|-------|--------|
| **What they will learn** | Customize This Variation; field-by-field; Use Product Setting |
| **Status** | Deferred |

### 06 — Preview Delivery

| Field | Detail |
|-------|--------|
| **What they will learn** | Contextual Preview; Ready / Needs Attention; Source column |
| **Status** | Deferred |

### 07 — Customer product, cart, checkout

| Field | Detail |
|-------|--------|
| **What they will learn** | Compact option + Estimated delivery; shipping line uses public option label |
| **Status** | Deferred |

### 08 — Multi-product shipping

| Field | Detail |
|-------|--------|
| **What they will learn** | Compatible shared charge vs separated paths; QA order #39724 as read-only evidence |
| **Status** | Deferred |

### 09 — Order Delivery information

| Field | Detail |
|-------|--------|
| **What they will learn** | Staff panel vs compact customer Delivery details; historical orders do not change |
| **Status** | Deferred |

### 10 — Staff troubleshooting

| Field | Detail |
|-------|--------|
| **What they will learn** | Needs Attention first checks; no PHP/SQL/SSH |
| **Status** | Deferred |

### 11 — Boundaries (Settings and Access)

| Field | Detail |
|-------|--------|
| **What they will learn** | Everyday menu only; ask before Settings, Access, or hidden support screens |
| **Status** | Deferred |

### 12 — Complete staff walkthrough

| Field | Detail |
|-------|--------|
| **What they will learn** | End-to-end RC.5 path from Overview to order |
| **Status** | Deferred |

### 13 — Shipments (when enabled)

| Field | Detail |
|-------|--------|
| **What they will learn** | Shipments menu, status path, tracking, COD create-from-order, customer View Order card |
| **Related written guide** | [11-STAGE-14-SHIPMENTS](11-STAGE-14-SHIPMENTS.md), playbook use cases 20–30 |
| **Status** | Deferred |

---

## Capture notes (when recapture is authorised)

- Use QA products only (#39705, #39717–#39719).  
- Do not place extra paid orders.  
- Do not capture credentials, Cloudflare challenge, or customer PII.  
- Auth state stays in gitignored `training/playwright/auth/`.  
- Update this file’s Status column when a binary actually exists and matches RC.5.
