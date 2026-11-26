# 01 — Clean Baseline

This is the only part of the full campaign, together with the Setup Guide, that may be confirmation-only if already completed by an authorized tester.

## DE-QA-BASE-001 — Release identity

### Steps

1. Open the Delivery Engine administration.
2. Record the installed plugin version.
3. Record schema version where visible.

### Expected

For the current campaign:

- version `1.0.0-rc.12`
- schema `6`

### Record

Result / Evidence / Notes.

---

## DE-QA-BASE-002 — Original clean-state truth

The original clean campaign state was approximately:

- Ghana Location Pack: Ready
- Delivery Options: 0
- Delivery Areas: 0
- Delivery Charges: 0
- Pickup Locations: 0
- Product/Variation Exceptions: 0
- Shipments: 0
- Bulk history: 0
- Site-wide delivery settings: empty
- Needs Attention: high because many products were genuinely unconfigured

If this was already confirmed, do not reset the campaign to recreate it.

Later functional setup will change these counts.

### Expected

No unexplained configuration is present at the original baseline, and later changes can be explained by test activity.

---

## DE-QA-BASE-003 — Ghana pack preserved

### Steps

1. Open Location Packs.
2. Find Ghana.
3. Confirm status.

### Expected

Ghana = **Ready**.

### Important

Do not download or reinstall Ghana merely to repeat the test.

If Ghana is missing/Failed, capture evidence and mark affected testing BLOCKED.
