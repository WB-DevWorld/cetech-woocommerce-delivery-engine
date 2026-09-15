<?php
declare(strict_types=1);

$required = [
    'AGENTS.md',
    'CONTRIBUTING.md',
    'OWNERSHIP.md',
    'CURRENT-WORK.md',
    'SECURITY.md',
    'LICENSE.md',
    'docs/AUTHORITY.md',
    'docs/STATUS_CURRENT.md',
    'docs/team/COLLABORATION.md',
    'docs/team/ENVIRONMENTS.md',
    'docs/team/RELEASE-GOVERNANCE.md',
    'docs/team/REPOSITORY-PROTECTION.md',
    'docs/workstreams/ws1/TASKS.md',
    'docs/workstreams/ws1/STATUS.md',
    'docs/workstreams/ws1/HANDOFF.md',
    'docs/workstreams/ws2/TASKS.md',
    'docs/workstreams/ws2/STATUS.md',
    'docs/workstreams/ws2/HANDOFF.md',
    'docs/workstreams/ws3/TASKS.md',
    'docs/workstreams/ws3/STATUS.md',
    'docs/workstreams/ws3/HANDOFF.md',
    '.github/CODEOWNERS',
    '.github/pull_request_template.md',
    '.github/ISSUE_TEMPLATE/task.md',
    '.github/workflows/ci.yml',
    '.github/workflows/release-package.yml',
];

$missing = array_values(array_filter(
    $required,
    static fn(string $path): bool => !is_file($path)
));

if ($missing !== []) {
    fwrite(STDERR, "Missing control-plane files:\n- " . implode("\n- ", $missing) . "\n");
    exit(1);
}

$forbiddenStatusPhrases = [
    'RC.10: PUBLISHED',
    'Stage 15: STARTED',
];

$status = file_get_contents(__DIR__ . '/../docs/STATUS_CURRENT.md');
if ($status === false) {
    fwrite(STDERR, "Unable to read docs/STATUS_CURRENT.md\n");
    exit(1);
}

foreach ($forbiddenStatusPhrases as $phrase) {
    if (str_contains($status, $phrase)) {
        fwrite(STDERR, "Unsafe bootstrap status claim found: {$phrase}\n");
        exit(1);
    }
}

echo "Delivery Engine control plane: OK\n";
