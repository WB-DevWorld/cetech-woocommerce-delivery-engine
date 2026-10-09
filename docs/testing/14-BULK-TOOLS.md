# 14 — Bulk Tools

Use test products only unless the campaign explicitly authorizes broader catalog changes.

## DE-QA-BULK-001 — Dry run

Prepare a small bulk operation.

Run it in dry-run/preview mode where supported.

### Expected

The preview explains what would change without actually changing the products.

---

## DE-QA-BULK-002 — Apply a small bulk change

Apply a controlled change to a small known test set.

### Expected

Only the intended products/fields change.

---

## DE-QA-BULK-003 — Variation safety

Include a product with variation-specific delivery settings.

### Expected

Bulk changes do not silently destroy valid variation overrides unless the operation explicitly says they will.

---

## DE-QA-BULK-004 — Invalid row/input

Use one deliberately invalid row/value in a safe import/test file.

### Expected

The problem is reported clearly.

Valid/invalid behavior follows the documented import rules without silently corrupting data.

---

## DE-QA-BULK-005 — Job progress

Run a bulk task that creates a background job.

### Expected

Staff can see understandable status/progress and final result.

---

## DE-QA-BULK-006 — Rollback

For a supported rollback operation, reverse the controlled test change.

### Expected

The intended prior values are restored without harming unrelated product/variation settings.

---

## DE-QA-BULK-007 — Import/export

Export a small controlled configuration set and inspect it.

Where safe, re-import according to the documented workflow.

### Expected

The data is understandable, portable and does not lose important configuration meaning.
