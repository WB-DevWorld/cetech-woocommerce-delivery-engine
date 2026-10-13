# 15 — Roles and Security

Use dedicated test accounts. Do not weaken real user permissions for convenience.

## DE-QA-ROLE-001 — Administrator

Log in as Administrator.

### Expected

Administrator can access the required Delivery Engine administration and recovery functions.

The plugin must not allow its own settings to lock the WordPress Administrator out of required Delivery Engine authority.

---

## DE-QA-ROLE-002 — Shop Manager

Log in as Shop Manager.

### Expected

The role can access only the Delivery Engine areas currently granted to it.

Record what is allowed and denied.

---

## DE-QA-ROLE-003 — Restricted role

Use a role that should not have Delivery Engine administration.

### Expected

Restricted pages are not accessible.

---

## DE-QA-ROLE-004 — Direct URL check

For a role that should be denied, paste a protected Delivery Engine admin URL directly into the browser.

### Expected

The server denies access.

Hiding a menu item alone is not enough.

---

## DE-QA-ROLE-005 — Permission change

As Administrator, change one safe test-role permission.

Log in as that role and verify the effect.

Then restore the campaign permission.

### Expected

Permission changes affect real capability checks, not only menu visibility.

---

## DE-QA-ROLE-006 — Administrator recovery

Where the campaign has a safe documented way to test recovery, verify that administrator recovery depends on native WordPress administrative authority rather than the damaged Delivery Engine capability itself.

Do not intentionally damage production roles.

---

## DE-QA-ROLE-007 — Customer privacy

As guest/customer, inspect product, cart, checkout, order and shipment views.

### Expected

No private supplier/origin, internal rate, cost/margin, grouping, priority, private note or technical identifier is exposed.
