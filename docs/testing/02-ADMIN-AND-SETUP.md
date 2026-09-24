# 02 — Admin and Setup

## DE-QA-ADMIN-001 — Main Delivery Engine menu

### Steps

1. Log in as Administrator.
2. Open Delivery Engine.
3. Visit each normal menu page.

### Expected

Normal staff pages load without fatal errors, broken layouts, or developer-only clutter.

Check at least:

- Overview
- Site-wide Defaults
- Delivery Options
- Delivery Areas
- Delivery Charges
- Pickup Locations
- Product Exceptions
- Needs Attention
- Settings

Record any unexpected menu item, missing page, duplicate page, or permission error.

---

## DE-QA-ADMIN-002 — Setup Guide

If an authorized tester already completed the Setup Guide, confirm it and continue.

Otherwise follow it from beginning to end.

### Expected

- instructions are understandable;
- links/buttons go to the correct place;
- completed steps stay completed;
- no step asks staff to do something impossible;
- the guide does not require developer knowledge.

---

## DE-QA-ADMIN-003 — Empty states

Where a section has no records yet, open it before creating anything.

### Expected

The page explains what to do next.

An empty page must not look broken or show technical errors.

---

## DE-QA-ADMIN-004 — Basic form validation

On a safe test form, deliberately leave one required field empty.

### Expected

- save is refused or clearly explained;
- the message tells the tester what is wrong;
- existing valid data is not lost.

---

## DE-QA-ADMIN-005 — Notices and messages

Create or edit one safe test record.

### Expected

Success/error messages are clear, readable and disappear/behave normally.

Avoid raw PHP, SQL or internal IDs in ordinary staff messages.
