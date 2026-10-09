# 07 — Site-wide Defaults, Product Exceptions and Variation Exceptions

The main rule is:

**Site-wide Defaults → Product changes → Variation changes**

A lower level should only replace the specific field that is changed.

## DE-QA-INHERIT-001 — Site-wide Defaults

If the campaign has no defaults yet, create the minimum settings needed for the test.

If they already exist, inspect them and reuse them.

### Expected

A normal product can inherit the intended delivery setup without repeating every setting.

---

## DE-QA-INHERIT-002 — Product inherits defaults

Choose a simple product with no product-specific change.

### Expected

The product receives the effective Site-wide Defaults.

---

## DE-QA-INHERIT-003 — Product changes one field

Change one safe product-level field.

### Expected

That field changes for the product.

Unrelated fields continue to come from the Site-wide Defaults.

---

## DE-QA-INHERIT-004 — Variation inherits product/default

Choose a variable product with a variation that has no special change.

### Expected

The variation receives the correct effective parent/default behavior.

---

## DE-QA-INHERIT-005 — Variation changes one field

Create/use one small variation-specific change.

### Expected

Only that field changes for the selected variation.

Other values continue to inherit correctly.

---

## DE-QA-INHERIT-006 — Disabled/None is not inherit

Where a setting supports explicitly disabling something, disable it at Product or Variation level.

### Expected

The setting remains disabled.

It must not fall back to the higher-level Yes/Enabled value merely because the value is empty/false.

---

## DE-QA-INHERIT-007 — Explicit zero is not inherit

Where a valid override can be zero, test it.

### Expected

Zero remains zero.

It must not silently inherit the higher-level amount.

---

## DE-QA-INHERIT-008 — Remove override

Remove/reset a safe test override.

### Expected

The field returns to the correct inherited value.
