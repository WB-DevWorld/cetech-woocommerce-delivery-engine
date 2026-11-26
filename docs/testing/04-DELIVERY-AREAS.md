# 04 — Delivery Areas

Do not assume a Delivery Area already exists.

The first tester may need to create the campaign fixture. Later testers should reuse it and still run the cases.

## DE-QA-AREA-001 — Create and save Greater Accra area

### If the campaign area does not exist

Create a clearly named Greater Accra test Delivery Area.

Use the intended canonical Ghana geography.

### If it already exists

Open it and test it again.

### Expected

- save succeeds;
- the correct geography remains selected;
- reopening shows the same intended coverage.

---

## DE-QA-AREA-002 — Accra coverage

Use the Greater Accra area.

### Expected

Accra is covered when configured to be covered.

---

## DE-QA-AREA-003 — Tema coverage

### Expected

Tema is covered when configured to be covered.

---

## DE-QA-AREA-004 — Unsupported location

Choose a locality or region that should not be covered.

### Expected

It does not match the Greater Accra area by mistake.

---

## DE-QA-AREA-005 — Selected locations

Create or use a test Area that covers selected locations only.

### Expected

Selected locations match. Other locations at the same level do not automatically match.

---

## DE-QA-AREA-006 — Entire Area Except

Create or use an Area that covers a wider area except one excluded location.

### Expected

The broader area works while the excluded location fails.

---

## DE-QA-AREA-007 — Overlap behavior

Where two Delivery Areas may both match the same destination, test the intended business rule.

### Expected

The customer receives the correct applicable result without duplicate/confusing delivery choices.

Record the two Area names and the location tested.

---

## DE-QA-AREA-008 — Edit without losing geography

Edit the Area name or another harmless field and save.

### Expected

Existing canonical geography coverage is not silently removed.
