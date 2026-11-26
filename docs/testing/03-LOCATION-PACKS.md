# 03 — Location Packs and Geography

## DE-QA-GEO-001 — Ghana pack status

### Steps

1. Open Location Packs.
2. Find Ghana.

### Expected

Status = **Ready**.

Do not reinstall Ghana for this campaign.

---

## DE-QA-GEO-002 — Country → region → locality

Find:

- Ghana
- Greater Accra
- Accra
- Tema
- Ashanti
- Kumasi

### Expected

The hierarchy is understandable and the locations can be found without entering database IDs.

---

## DE-QA-GEO-003 — Search

Search for Accra, Tema and Kumasi.

### Expected

Correct results appear and can be identified clearly.

---

## DE-QA-GEO-004 — Ambiguous names

Where two locations have the same or similar name, check how the UI distinguishes them.

### Expected

The tester should be able to tell which location is intended.

The plugin must not silently choose the wrong place.

---

## DE-QA-GEO-005 — Parent change clears old child

In a customer/admin location control:

1. select Ghana → Greater Accra → Accra;
2. change Greater Accra to another region.

### Expected

Old locality information is cleared or replaced.

Accra must not remain silently attached to a different region.

---

## DE-QA-GEO-006 — Saved location restoration

Where the UI saves a browsing/test location:

1. select a valid location;
2. leave/reload;
3. return.

### Expected

A valid saved location restores correctly.

If the saved location is no longer valid, the plugin should ask for a valid choice rather than silently quoting from stale data.

---

## DE-QA-GEO-007 — No unnecessary redownload

Navigate through geography/setup pages repeatedly.

### Expected

A Ready Ghana pack stays Ready and the UI does not tell ordinary staff to reinstall it unnecessarily.
