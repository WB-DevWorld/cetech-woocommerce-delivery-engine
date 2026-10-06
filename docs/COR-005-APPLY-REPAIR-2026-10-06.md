# COR-005 apply and checkpoint repair

Date: 2026-10-06. This follows historical checkpoint `b4776c0194dffdaea3b2ac3a48b829acb68e8a28`. It does not replace that checkpoint and does not implement P07 or P08.

Apply re-reads the variation's current parent inside the claimed mutation and returns `stale_target` when it differs from the parent stored at preparation. It does not write under the new parent.

Apply compares the preview precondition fingerprint with the current scope before reset or override. A later edit stays in place, the item is `stale_target`, and the manifest total stays fixed.

Each preparation page inserts its accepted items and stores the ceiling, cursor, and counts in one owned unit. A refused job write restores the items. A positive target id is one manifest identity, so a later SKU does not add a second row. Rejected decisions remain the persisted cursor.

PHP 8.5.0 red results before the repair: reparented Apply had a null error code; the edited scope was reported `no_valid_delivery_path`; the refused checkpoint left 1 item. After the repair, `tests/Unit/Bulk` is 186 tests / 1070 assertions, OK, with 2 existing deprecations. The 40,001-candidate walk remains in that run. The stopped full-list lookup still has no verdict.
