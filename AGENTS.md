# CETECH WooCommerce Delivery Engine — Agent Operating Rules

This repository is a production-intent WooCommerce plugin maintained by three human developers using ChatGPT and Cursor.

## Authority
Read `docs/AUTHORITY.md`, `docs/STATUS_CURRENT.md`, `OWNERSHIP.md`, `CURRENT-WORK.md`, the relevant task/issue, and existing project governance before implementation. Repository truth outranks private AI memory.

## Human workstreams
- WS1 — Ben (`@Ben-001-sys`): customer experience, browser-facing delivery/pickup UX, theme/frontend compatibility, accessibility and browser evidence.
- WS2 — Emmanuel (`@Emmanuel-coder-prog`): WooCommerce runtime, HPOS/order/shipment lifecycle, Store API/server integrations and Woo-specific adapters/tests.
- WS3 — `@wbdevworld`: core/domain, persistence/schema, security, CI/release, governance and neutral milestone integration.

Integration authority is not permission to implement another owner's work. Cross-workstream reassignment requires explicit human authorization recorded in `CURRENT-WORK.md`.

## Branches
Canonical release branch: `master` unless a later approved repository decision changes it.
Contributor branches: `ws1/**`, `ws2/**`, `ws3/**`, `fix/**`.
Multi-owner milestone integration: temporary `batch/**` branch owned by the integration editor.
Do not create permanent `develop`, `qa`, `uat` or `integration` branches merely for coordination.

## Required discipline
- one bounded task at a time;
- task scope is narrower than broad ownership;
- one active editor for central/high-conflict files;
- exact tested SHA handoffs;
- no force-push/reset of another developer's branch;
- review fixes normally return to the original owner;
- automatic continuation only within the same owner's authorized queue;
- record durable checkpoints in Git/repository state;
- perform the bounded two-pass freshness check at final handoff, not after every commit.

## Environment safety
Git worktrees do not isolate WordPress/WooCommerce databases, carts, sessions, orders, Redis, WP Rocket, Action Scheduler or external effects. Every task must declare its authorized environment and permitted mutations. Production access and destructive actions remain human-controlled.

## Release truth
Implemented != tests passed != CI green != approved != merged != staging accepted != production approved.
Never move an existing release tag or overwrite a release artifact under the same version.
Release packages must be built from a clean committed SHA and the extracted ZIP must be verified.

## Current release baseline
See `docs/STATUS_CURRENT.md`. Do not infer RC.10 exists unless repository/release truth proves it.
