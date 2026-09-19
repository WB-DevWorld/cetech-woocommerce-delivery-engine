# Current Work — Post-RC.11 Baseline and Authorized Streams

Status: RC.11 TAGGED / PRODUCT TRUTH OWNER-ACCEPTED / GEO.12 IS THE ONLY ACTIVE RUNTIME STREAM / NOT DEPLOYED

## Canonical repository truth
- Canonical organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Canonical development branch: protected `master`.
- Current tagged release candidate: `v1.0.0-rc.11`.
- RC.11 annotated tag object: `acaae9bfc9758cdee1b3f2ec47e94848e83f87da`.
- RC.11 tag peels to immutable release source: `384f564f64a2db766ae6907392e95fb366fb8533`.
- RC.11 version identity: `1.0.0-rc.11`; schema: `5`.
- Final qualified RC.11 ZIP: `cetech-woocommerce-delivery-engine-1.0.0-rc.11.zip`.
- Final qualified ZIP bytes: `1,545,789`.
- Final qualified ZIP SHA-256: `97423a95273f6148ee855d1fb8a66c6c66e47aa20cb2c5868ecf2f84b2a9a521`.
- Prior RC.10 remains immutable: `v1.0.0-rc.10` → `d1409258caf1a90675b689ab105471460de4c713`.
- Stage 15: NOT STARTED.

## RC.11 qualification truth
- Issue #18 PDP delivery-price correction: owner accepted, integrated, closed completed.
- RC.11 promotion issue #20: closed completed.
- RC.11 promotion PR #21 merged to release source `384f564f64a2db766ae6907392e95fb366fb8533`.
- PR #21 exact-head CI run `35144591865`: PASS on all four required jobs.
- Protected-master post-merge CI run `35144871814`: PASS on all four required jobs.
- Final package + WordPress qualification run `35145775218`: PASS.
- Production package verifier: PASS.
- Packaged PHP lint: `448 files / 0 failures`.
- Clean install: `version=1.0.0-rc.11 schema=5 tables=all_tables_ok`.
- RC.10 → RC.11 upgrade/data retention: `version=1.0.0-rc.11 schema=5 tables=all_tables_ok sentinel=1 option=keep_me`.

## What RC.11 adds over RC.10
The accepted issue #18 correction adds authoritative customer-facing delivery prices on location-qualified PDP delivery options while preserving cart/checkout pricing truth. It includes quantity-aware fixed-per-item pricing, fixed-per-shipment behavior, price + configured ETA cards, Delivery/Pickup capability before quoting, no implicit store-country PDP quote, fail-closed unquoted delivery, public-safe pricing payloads, and qualified Storefront/WoodMart/mobile behavior.

## Development baseline after release closeout
- Immutable release anchor: `v1.0.0-rc.11` / `384f564f64a2db766ae6907392e95fb366fb8533`.
- New development work should branch from the **latest protected `master`**, which may advance beyond the immutable release source through docs-only or later owner-authorized work.
- Never move/reuse the RC.11 tag or overwrite the qualified ZIP identity.

## Owner-approved product control plane
- On 2026-09-19 the owner accepted `PRODUCT-TRUTH-BASELINE-1`, resolved all six recorded product decisions, and approved the realignment plan.
- The authoritative product-control-plane package is proposed under `docs/product/` on `docs/product-control-plane` for independent review into protected `master`.
- `docs/AUTHORITY.md` assigns governing responsibility by artifact. The 372 Requirement IDs are frozen and must not be renumbered.
- Stable 1.0 scope checkpoint: `STABLE-1.0-SCOPE-1`.

## Environment authorization
- FLAIROC: no RC.11 deployment authorized.
- Training site: no RC.11 deployment authorized.
- Production: no RC.11 deployment authorized.
- POS repository: outside scope / must not be touched.
- CETECH is the first production Pilot customer, but no Pilot creation or deployment is authorized until geo.12 has technical closure and physical owner QA.
- WPML/WCML remains separate and must be certified before Stable 1.0 only if it will be advertised as supported at launch.
- WP Rocket certification remains separate/unqualified.
- Stage 15 remains not started.

## Current next work
1. Publish the accepted product-control-plane package through the dedicated reviewed documentation PR; no product/runtime code belongs in that PR.
2. In parallel, continue only the finite geo.12 closure work on issue #23 / Draft PR #24 / `feat/canonical-geography-coverage`.
3. After geo.12 technical closure and physical owner QA, create a controlled CETECH production Pilot/release candidate. This does not pre-authorize deployment and does not assume the name RC.12.
4. Do not begin any other runtime implementation stream concurrently. Later realignment waves follow the approved dependency gates and bounded requirement-ID work.
