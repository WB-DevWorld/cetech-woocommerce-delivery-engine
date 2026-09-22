# Repository Protection — GitHub Free / Public Repository

The organization currently uses GitHub Free. Protected branches and repository rulesets are therefore available while this repository is public; converting it to private on GitHub Free removes those private-repository protection capabilities.

After CI has produced stable check names, configure protection for `master` with at least:
- require a pull request before merging;
- require at least one approving review;
- dismiss stale approvals when new commits are pushed where practical;
- require Code Owner review for owned paths;
- require conversation resolution;
- require the Delivery Engine CI checks;
- block force pushes;
- block branch deletion;
- apply the rule to administrators where practical, with only deliberate emergency bypass;
- protect `v*` release tags from deletion/update through a tag ruleset if available.

The connected ChatGPT GitHub App can inspect rulesets but its exposed actions cannot create/edit repository rules. Until a human configures these settings in GitHub, documentation/CI are controls but not enforcement.


## Current observation — 2026-09-22

Protected `master` is active, but the branch-protection API still reports historical required-status context names (`JavaScript / Vitest`, `PHP / PHPUnit 8.2`, `Runtime PHP 8.1`, `Control Plane`) while the current CI workflow has moved to PHP 8.3 minimum / PHP 8.5 production-target gates plus compatibility, MariaDB, WordPress/WooCommerce, Vitest, Control Plane, and `CI Required Gates`.

Do not interpret the historical branch-protection context list as the current PHP support policy. The current support policy is PHP 8.3–8.5.x. Repository protection should be reviewed in GitHub UI/rulesets so enforced check names match the current CI workflow; this documentation task does not silently alter repository rules.
