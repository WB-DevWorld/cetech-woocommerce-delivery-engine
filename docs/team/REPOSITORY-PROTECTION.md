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
