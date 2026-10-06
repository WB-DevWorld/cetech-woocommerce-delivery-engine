# COR-007 save repair — 2026-10-06

This follows the historical COR-007 checkpoint `f7054338d9956c33a0fa50f84aa239e4f3dae90d`. It does not change schema, the ledger, or the outbox. P07 and P08 stay outside.

The global revision option is updated only when the stored value is lower than the revision being published. A later writer that has already published a higher revision leaves that value in place, and the older publisher is unconfirmed. The option cache is refreshed to the value read back from the row.

Request-token lookup reads the exact token from the audit JSON. It does not stop at the newest 100 audit rows.

A lost acknowledgement clears the prewarmed resolver. Recovery opens a new WordPress connection, copies the closed connection's table prefix, and leaves the closed object unable to query. The error and the draft transient are written on the new connection.

Save and reset forms on the scoped editor, the customize screen, the product panel, and the exceptions list submit the opened revision, the opened scope-row identity, and a request token. Save and reset tokens are distinct. A failed attempt keeps that original identity for the same scope only. A successful save on a different scope does not delete it. An unchanged customize save uses the unchanged-version notice.

The technical form and recovery review in this repair is the agent review recorded in `CURRENT-WORK.md`. It is not Ben's or Emmanuel's acceptance.
