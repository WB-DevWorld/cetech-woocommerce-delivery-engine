# Three-Person Collaboration Model

Milestone batching does not combine human ownership. A batch PR is one combined review surface, not permission for one agent to implement every task.

Owner unavailable != takeover. Cross-owner reassignment requires explicit human authorization in `CURRENT-WORK.md`.

For cross-owner dependencies, publish an exact tested commit SHA into a provisional integration baseline; dependent owners branch from that known baseline rather than waiting for every predecessor to merge to `master`.

Long-running assignments may contain an ordered same-owner queue. For each task: understand -> implement -> test -> inspect diff -> commit -> record checkpoint -> continue. Stop for genuine blockers, architecture/contract changes without authority, ownership conflicts, unsafe/destructive operations, credentials, live production effects, milestone completion or context limits.

Review fixes normally return to the original owner. WS3 imports the new exact tested SHA.

At final handoff, perform exactly two bounded freshness passes against current remote/batch truth. Do not create endless Pass 3+ loops.
