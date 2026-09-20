#!/usr/bin/env node

import { existsSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

function readText(path) {
  return readFileSync(path, 'utf8').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
}

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const productDirectory = join(repositoryRoot, 'docs', 'product');
const expectedRequirementCount = 372;
const expectedArtifacts = [
  'README.md',
  'PRODUCT-CONSTITUTION.md',
  'CAPABILITY-REGISTRY.yaml',
  'CAPABILITY-REGISTRY.md',
  'DESIGN-CODE-TRACEABILITY.md',
  'DECISION-CONFLICT-REGISTER.md',
  'COMPATIBILITY-CERTIFICATION.md',
  'RELEASE-SCOPE.md',
  'REALIGNMENT-PLAN.md',
  'AUDIT-MANIFEST.yaml',
];
const requiredRequirementFields = [
  'id',
  'domain',
  'requirement',
  'authority_status',
  'release_intent',
  'conformance',
  'source_ref',
  'evidence_ref',
];
const allowedReleaseIntents = new Set([
  'REQUIRED_BEFORE_CURRENT_ACTIVE_STREAM_CLOSES',
  'REQUIRED_BEFORE_STABLE_1_0',
  'ACCEPTED_POST_1_0_PRODUCT_COMPLETION',
  'OPTIONAL_CERTIFICATION',
  'FUTURE_ADVANCED_INTEGRATION',
  'CONNECTED_ONLY',
  'EXPLICITLY_DEFERRED',
]);
const allowedConformance = new Set([
  'ALIGNED_EXACT',
  'ALIGNED_SEMANTICALLY_EQUIVALENT',
  'IMPLEMENTATION_SUPERIOR_ADOPT',
  'DESIGN_SUPERIOR_REALIGN_CODE',
  'PARTIAL_IMPLEMENTATION',
  'MISSING_IMPLEMENTATION',
  'CERTIFICATION_GAP',
  'CONNECTED_ONLY',
  'DEFERRED',
  'INTENTIONALLY_NON_CODE',
]);

function invariant(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function scalar(rawValue, field, id = 'registry') {
  const raw = rawValue.trim();
  invariant(raw !== '', `${id}: ${field} must not be empty`);

  if (raw.startsWith('"') || raw.endsWith('"')) {
    invariant(raw.startsWith('"') && raw.endsWith('"'), `${id}: ${field} has malformed quoting`);
    try {
      return JSON.parse(raw);
    } catch {
      throw new Error(`${id}: ${field} is not a valid quoted scalar`);
    }
  }

  return raw;
}

function singleTopLevelValue(source, key) {
  const matches = [...source.matchAll(new RegExp(`^${key}:\\s*(.+)$`, 'gm'))];
  invariant(matches.length === 1, `Expected exactly one top-level ${key} field`);
  return scalar(matches[0][1], key);
}

function parseDeclaredConformanceCounts(lines, countsStart, requirementsStart) {
  invariant(countsStart >= 0 && countsStart < requirementsStart, 'conformance_counts must precede requirements');
  const counts = new Map();

  for (const line of lines.slice(countsStart + 1, requirementsStart)) {
    const match = line.match(/^  ([A-Z][A-Z0-9_]*): ([0-9]+)$/);
    invariant(match !== null, `Malformed conformance_counts entry: ${line}`);
    invariant(!counts.has(match[1]), `Duplicate conformance_counts entry: ${match[1]}`);
    counts.set(match[1], Number(match[2]));
  }

  invariant(counts.size === allowedConformance.size, 'conformance_counts must declare every allowed conformance state exactly once');
  for (const state of allowedConformance) {
    invariant(counts.has(state), `Missing conformance_counts entry: ${state}`);
  }

  return counts;
}

function validateRequirement(record) {
  for (const field of requiredRequirementFields) {
    invariant(Object.hasOwn(record, field), `${record.id ?? 'Unknown requirement'}: missing ${field}`);
  }
  invariant(Object.keys(record).length === requiredRequirementFields.length, `${record.id}: unexpected or duplicate fields`);

  const idMatch = record.id.match(/^DE-([A-Z][A-Z0-9]*)-[0-9]{3}$/);
  invariant(idMatch !== null, `${record.id}: malformed Requirement ID`);
  invariant(record.domain === idMatch[1], `${record.id}: domain does not match the Requirement ID prefix`);
  invariant(record.authority_status === 'CURRENT_AUTHORITATIVE', `${record.id}: unsupported authority_status`);
  invariant(allowedReleaseIntents.has(record.release_intent), `${record.id}: unsupported release_intent`);
  invariant(allowedConformance.has(record.conformance), `${record.id}: unsupported conformance`);
  invariant(record.requirement.length > 0, `${record.id}: empty requirement`);
  invariant(record.source_ref.length > 0, `${record.id}: empty source_ref`);
  invariant(record.evidence_ref.length > 0, `${record.id}: empty evidence_ref`);
}

function validateRegistry(source) {
  const lines = source.replace(/\r\n/g, '\n').split('\n');
  const requirementsStart = lines.indexOf('requirements:');
  const supersessionStart = lines.indexOf('supersession_policy:');
  const countsStart = lines.indexOf('conformance_counts:');

  invariant(singleTopLevelValue(source, 'registry_version') === 'PRODUCT-TRUTH-BASELINE-1', 'Unexpected registry_version');
  invariant(singleTopLevelValue(source, 'baseline_status') === 'OWNER_ACCEPTED_FROZEN', 'Registry baseline is not owner-accepted and frozen');
  invariant(requirementsStart >= 0, 'Missing requirements section');
  invariant(supersessionStart > requirementsStart, 'Missing or misplaced supersession_policy section');
  invariant(lines.filter((line) => line === 'requirements:').length === 1, 'Expected exactly one requirements section');
  invariant(lines.filter((line) => line === 'supersession_policy:').length === 1, 'Expected exactly one supersession_policy section');

  const declaredCount = Number(singleTopLevelValue(source, 'requirement_count'));
  invariant(Number.isInteger(declaredCount), 'requirement_count must be an integer');
  invariant(declaredCount === expectedRequirementCount, `requirement_count must be ${expectedRequirementCount}, got ${declaredCount}`);

  const declaredConformance = parseDeclaredConformanceCounts(lines, countsStart, requirementsStart);
  const requirements = [];
  let current = null;

  for (const line of lines.slice(requirementsStart + 1, supersessionStart)) {
    const idMatch = line.match(/^  - id: (.+)$/);
    if (idMatch) {
      if (current !== null) {
        validateRequirement(current);
        requirements.push(current);
      }
      current = { id: scalar(idMatch[1], 'id') };
      continue;
    }

    const fieldMatch = line.match(/^    ([a-z][a-z0-9_]*): (.+)$/);
    invariant(fieldMatch !== null && current !== null, `Malformed requirement structure: ${line}`);
    const [, field, rawValue] = fieldMatch;
    invariant(requiredRequirementFields.includes(field), `${current.id}: unexpected field ${field}`);
    invariant(!Object.hasOwn(current, field), `${current.id}: duplicate field ${field}`);
    current[field] = scalar(rawValue, field, current.id);
  }

  invariant(current !== null, 'The requirements section is empty');
  validateRequirement(current);
  requirements.push(current);

  invariant(requirements.length === declaredCount, `Expected ${declaredCount} requirements, parsed ${requirements.length}`);

  const ids = new Set();
  const actualConformance = new Map([...allowedConformance].map((state) => [state, 0]));
  for (const requirement of requirements) {
    invariant(!ids.has(requirement.id), `Duplicate Requirement ID: ${requirement.id}`);
    ids.add(requirement.id);
    actualConformance.set(requirement.conformance, actualConformance.get(requirement.conformance) + 1);
  }

  for (const state of allowedConformance) {
    invariant(
      actualConformance.get(state) === declaredConformance.get(state),
      `${state}: declared ${declaredConformance.get(state)}, parsed ${actualConformance.get(state)}`,
    );
  }

  const supersession = lines.slice(supersessionStart, supersessionStart + 3);
  invariant(supersession[1] === '  ids_frozen: true', 'supersession_policy must freeze Requirement IDs');
  invariant(/^  rule: ".+"$/.test(supersession[2] ?? ''), 'supersession_policy must contain a quoted rule');

  return requirements;
}

function validateArtifactSet() {
  for (const artifact of expectedArtifacts) {
    const artifactPath = join(productDirectory, artifact);
    invariant(existsSync(artifactPath) && statSync(artifactPath).isFile(), `Missing product-control-plane file: docs/product/${artifact}`);
  }

  const manifest = readText(join(productDirectory, 'AUDIT-MANIFEST.yaml'));
  const artifactBlock = manifest.match(/^artifacts:\n((?:  - .+\n)+)/m);
  invariant(artifactBlock !== null, 'AUDIT-MANIFEST.yaml has no valid artifacts list');
  const referencedArtifacts = artifactBlock[1]
    .trimEnd()
    .split('\n')
    .map((line) => line.replace(/^  - /, ''));
  invariant(JSON.stringify(referencedArtifacts) === JSON.stringify(expectedArtifacts), 'AUDIT-MANIFEST.yaml artifact list does not match the required control-plane files');

  const authority = readText(join(repositoryRoot, 'docs', 'AUTHORITY.md'));
  for (const artifact of expectedArtifacts) {
    invariant(authority.includes(`docs/product/${artifact}`), `docs/AUTHORITY.md does not reference docs/product/${artifact}`);
  }
}

function expectInvalid(label, source, expectedMessage) {
  try {
    validateRegistry(source);
  } catch (error) {
    invariant(error instanceof Error && error.message.includes(expectedMessage), `${label}: rejected for the wrong reason: ${error}`);
    return;
  }
  throw new Error(`${label}: malformed registry was accepted`);
}

function runNegativeFixtures(validSource) {
  expectInvalid(
    'duplicate ID fixture',
    validSource.replace('  - id: DE-FAM-002', '  - id: DE-FAM-001'),
    'Duplicate Requirement ID',
  );
  expectInvalid(
    'count mismatch fixture',
    validSource.replace('requirement_count: 372', 'requirement_count: 371'),
    'requirement_count must be 372',
  );
  expectInvalid(
    'missing field fixture',
    validSource.replace('    conformance: INTENTIONALLY_NON_CODE\n', ''),
    'missing conformance',
  );
}

try {
  validateArtifactSet();
  const registrySource = readText(join(productDirectory, 'CAPABILITY-REGISTRY.yaml'));
  const requirements = validateRegistry(registrySource);

  if (process.argv.includes('--self-test')) {
    runNegativeFixtures(registrySource);
    console.log('Product control plane negative fixtures: OK (duplicate ID, count mismatch, missing field rejected)');
  }

  console.log(`Product control plane: OK (${expectedArtifacts.length} files; ${requirements.length} unique Requirement IDs; structure and conformance counts valid)`);
} catch (error) {
  console.error(`Product control plane: FAIL\n${error instanceof Error ? error.message : String(error)}`);
  process.exit(1);
}
