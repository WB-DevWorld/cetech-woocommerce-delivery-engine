# COR-007 local completion

Date: 2026-10-06. This follows the COR-005 checkpoint on `fix/cor-005-007-scan-save`. It does not implement P07 or P08, and it does not add schema, a ledger, or an outbox.

## Completion boundary

A changed save is complete only when the scoped settings, scope identity, revision, and sanitized audit are accepted in one database unit. The global revision option is published after that unit commits. If `update_option` does not publish the new revision, the save is not reported as complete. The same request token can retry and publish the recorded revision without a second settings write or a second audit.

A known rejected write or a COMMIT that was not sent rolls back and reports `Settings were not saved.` An unsent COMMIT adds `The commit was not sent.` A COMMIT that was sent but whose acknowledgement was lost is not rolled back and reports `Save outcome could not be confirmed.` Native WordPress `wpdb` has no way to say whether a false COMMIT was sent, so that path reports the unconfirmed outcome and does not roll back. The sent-versus-unsent distinction is proved on the disposable adapter that records `commit_was_sent()`.

The request token is stored with a hash of the action, current user, scope, slice, parent, scope row id, and field payload. The same token with a different intent does not replay. The same token and the same intent returns the recorded completion. An identical submission with a different token, against the current revision, is a no-change result and does not add an audit. A recreated scope with a new row id is rejected even when the version number is reused.

Reset uses the same unit, token, and row identity. Global deletion stays prohibited.

## Visible copy

These strings are what the admin page shows. WS1 has not reviewed them.

- Known failure: `Settings were not saved.`
- Unknown commit outcome: `Save outcome could not be confirmed.`
- Stale editor: `These settings are out of date. Reload the current settings and submit the draft again.`
- Accepted save replay: `This save was already completed. The recorded settings were not changed again.`
- Accepted reset replay: `This reset was already completed. The recorded settings were not changed again.`
- No-change: `No semantic changes detected. Configuration version unchanged.`

A failed save stores the submitted fields and the same request token in the user transient `cetech_de_scoped_draft_{user}` for 15 minutes. The editor overlays those values for the same scope, slice, and parent. A successful save deletes the transient.

## Proofs

PHP 8.5.0.

Disposable MariaDB, database `cetech_cor004_cor007`, prefix `cor007_`, InnoDB. `phpunit.cor007-sql.xml`: 8 tests / 80 assertions, OK. That run saw a paused save still showing the previous priority to an independent connection, rejected field insert, audit append, parent revision, and child delete, an unsent COMMIT rolled back, a lost acknowledgement left committed and then reconciled by a fresh connection, a failed reset keeping the sibling slice and global settings, a recreated row rejecting the old row id, two processes with one expected revision, and explicit priority `0`, Disable, Replace-empty, the `in_store` slice, reset replay, and a variation whose injected parent check denied the write.

Disposable WordPress 7.1.2, database `cetech_cor004_wp007`, prefix `cor007_`, default `WP_Object_Cache`, no object-cache drop-in. The revision option autoload value is `off`. `tests/Integration/cor007-wordpress-cache.php` exited 0 and printed `result=PASS`. Before the first save, `get_option` missed and the key was in `notoptions` and absent from `alloptions`. After success, the same request and an independent request agreed on `get_option`, the object-cache value, the option row, and the resolver priority. A rejected option update left the new settings and audit committed, left the durable option unchanged, and the same-request cache matched that durable option. Retry of the same token published the revision without another audit. A rejected field insert left the prewarmed resolver and a fresh resolver on the previous priority.

Configuration and Bulk unit directories together: 330 tests / 1709 assertions, OK, 2 existing deprecations. The earlier audit-rejection regression was `Failed asserting that true is false` because the second audit returned false and the save still reported success. Local full suite: 1522 tests / 9721 assertions, 1 skip, 14 existing deprecations, OK. The console printed `The system cannot find the path specified.` once and the suite exited 0. That count is local and is not a CI count. JavaScript: 8 files / 102 tests, OK. Production lint: 494 files / 0 failures.

## Limits

PHP 8.3 and 8.4 are not installed here. The SQL group and the WordPress script are outside default CI. The variation-parent denial used an injected checker because this WordPress site has no WooCommerce catalog. The option publication is not inside the settings transaction. This run did not show the same-request cache moving ahead of the durable option. WS1 review of the copy and draft screen is pending. No merge, release, or live-site write is claimed.
