# Existing emergency-control SQL observation repair

The owner approved W2-QUOTE-LIFECYCLE-1 and instructed implementation of W2-Q01 on 2026-10-07 at 05:01:14 UTC, explicitly requiring resolution of the existing SQL discrepancy before integration. [Issue #83](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/83) tracks this prerequisite and Q01.

## Failure and supported cause

Design candidate `a7151a49a819858eaa294e096da74d854acbec95`, tree `972234a38cf3e51a00d1e553459f6a18603b79fd`, failed the substantive MariaDB and Required Gates jobs in PR CI37573222546 attempt1. SQL173/4078/1failure/2deprecations reported “The real second native connection did not enter a lock wait” in the initialized-admission ordering test. Its native380/finalHTTP98 preservation receipts passed; they do not clear SQL. The separate natural push37573218476 passed173/4086 on the same candidate. Neither run is silently rewritten or treated as a retry that explains the failure.

The fixture polled INNODB_LOCK_WAITS/LOCKS every10ms. [MariaDB11.4.13 source](https://github.com/MariaDB/server/blob/mariadb-11.4.13/storage/innobase/trx/trx0i_s.cc) defines CACHE_MIN_IDLE_TIME_NS=100000000: the shared metadata cache refreshes only after more than100ms since its last read; each metadata read updates last_read. Rapid polling can preserve a pre-dispatch empty snapshot throughout the existing1.5s observation bound.

A controlled comparative native reproduction on PHP8.3.6/MariaDB10.11.14 primed an empty metadata snapshot while the second OS process was stopped before dispatch. The old10ms observer failed at1.5s (1test/23assertions/1failure). After120ms without reading that cache, the exact waiter14/blocker13 pair was observed (count1). The physical production2s lock wait was unchanged. This establishes an observer defect; it does not retrospectively recover the failed CI run's unrecorded cache state or substitute the local runtime for pinned CI.

## Correction and qualification boundary

Only the two emergency-control SQL fixture files change. The observer keeps its1.5s total bound and allows120ms idle gaps. Atomic barrier signals record each worker's actual native connection and OS process IDs. The query requires the exact waiter/blocker pair, options table, option_name index and LOCK WAIT state. The new regression primes an empty snapshot before dispatch, observes the actual wait, proves the control/operation state is unchanged while blocked, then releases admission and checks the accepted pause with zero lock timeouts. Existing absent-key and initialized ordering proofs use the same exact observer.

Local comparative emergency group:31tests/903assertions, PASS, no skips; the disposable database was stopped. This is separate from required CI's PHP8.5.11/MariaDB11.4.13. Fresh exact-candidate required CI, including actual operation/rule/data/emergency SQL cases and native/HTTP preservation, must pass before the approved design integrates. Exact candidate/run/artifact fingerprints are attached to PR#82 after execution, avoiding a source self-receipt loop.

No production source, runtime profile, timeout, schema, version, fixture assertion or required gate is weakened. Production remains615PHP files and its map remains `7563a48389b93dd865a279646cb5763800fdeca64a6973ed1fd616a7a909af30`. Q01 implementation is a separate checkpoint.
