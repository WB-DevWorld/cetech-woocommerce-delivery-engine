# Environment Ownership and Side-Effect Policy

Worktree isolation is not environment isolation.

Every task/issue must declare:
- environment;
- database/site instance;
- permitted writes;
- prohibited effects;
- test data ownership;
- cleanup/reconciliation requirements.

Recommended lanes:
- WS1: isolated browser/frontend-compatible QA environment.
- WS2: isolated WooCommerce runtime/integration environment.
- WS3: isolated core/integration/release environment.
- CI: disposable automated verification only.
- shared staging/training: serialized, explicitly authorized qualification.
- production/FLAIROC: human-authorized only.

Do not let parallel agents mutate the same carts, sessions, orders, shipment records, options, Redis namespace/cache, product metadata or Action Scheduler state without an explicit serialized test plan.

Git rollback does not undo orders, emails, webhooks, remote DB mutations or cache/session effects. Record remote side effects in handoffs.
