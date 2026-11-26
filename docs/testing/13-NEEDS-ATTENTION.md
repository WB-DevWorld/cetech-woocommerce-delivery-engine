# 13 — Needs Attention

Needs Attention should tell staff what genuinely needs action.

Do not judge it only by a fixed number. Counts will change as the campaign configures products and creates orders/shipments.

## DE-QA-ATTN-001 — Initial catalog attention

Open Needs Attention.

### Expected

Products that genuinely lack required delivery configuration are shown.

---

## DE-QA-ATTN-002 — Resolve a product issue

Take one safe test product listed as needing attention.

Complete the missing configuration.

Refresh/revisit Needs Attention.

### Expected

The resolved item disappears or changes state correctly.

---

## DE-QA-ATTN-003 — Do not hide unresolved problems

Use a deliberately unconfigured test product.

### Expected

It remains visible as needing attention where appropriate.

---

## DE-QA-ATTN-004 — Shipment attention

Create or use a safe test shipment with a condition intended to require staff attention.

### Expected

The relevant operational attention item appears and can be understood.

---

## DE-QA-ATTN-005 — Resolve shipment attention

Resolve the test shipment condition.

### Expected

Needs Attention updates truthfully.

---

## DE-QA-ATTN-006 — Counts and links

Click the attention item/count.

### Expected

It takes staff to the correct place to fix the problem.

Counts should not be obviously stale, impossible, or disconnected from the underlying records.
