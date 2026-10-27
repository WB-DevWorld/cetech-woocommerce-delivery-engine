#!/usr/bin/env python3
"""R24 audit of authenticated original28 archives, exact executed source and native30 closure.
Adapted from root_original_audit.py. No PHP/SQL/HTTP replay; validators consume original public bytes only.
Run only after run.json/jobs.json/artifacts.json/originals/<id>.zip,
sql-original.log and its authenticated sql-log-witness.json exist.
"""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import zipfile
import xml.etree.ElementTree as ET
import yaml

sys.dont_write_bytecode = True
SLUG = 'cetech-woocommerce-delivery-engine'
REPOSITORY = 'WB-DevWorld/' + SLUG

def need(value, message):
    if not value:
        raise RuntimeError(message)

def sha(value):
    return hashlib.sha256(value).hexdigest()

def unique(pairs):
    out = {}
    for key, value in pairs:
        need(key not in out, 'Duplicate JSON key: ' + key)
        out[key] = value
    return out

def decode(value):
    return json.loads(value, object_pairs_hook=unique,
                      parse_constant=lambda value: (_ for _ in ()).throw(ValueError('Nonfinite JSON')))

def closed(value, fields, message):
    need(type(value) is dict and set(value) == set(fields), message)

def sql_log_witness(base, run, jobs, workflow, sql_raw):
    """Bind the observed log bytes to a recorded fresh authenticated job fetch."""
    raw = (base / 'sql-log-witness.json').read_bytes()
    witness = decode(raw)
    closed(witness, ('format', 'repository', 'run_id', 'run_attempt', 'head_sha',
                     'job', 'fetch', 'snapshots', 'log'), 'SQL log witness shape differs')
    closed(witness['job'], ('id', 'name', 'parent_run_id', 'parent_run_attempt',
                            'parent_head_sha'), 'SQL log job witness shape differs')
    closed(witness['fetch'], ('tool', 'inputs'), 'SQL log fetch witness shape differs')
    closed(witness['fetch']['inputs'], ('repo_full_name', 'job_id'), 'SQL log fetch inputs differ')
    closed(witness['snapshots'], ('run_json_sha256', 'jobs_json_sha256'), 'SQL log snapshot witness shape differs')
    closed(witness['log'], ('filename', 'sha256', 'bytes'), 'SQL log byte witness shape differs')
    names = [job.get('name', key) for key, job in workflow.items()
             if any('cetech-de-real-db.junit.xml' in step.get('run', '') for step in job['steps'])]
    need(len(names) == 1, 'Physical SQL source job ambiguous')
    matches = [job for job in jobs['jobs'] if job['name'] == names[0]]
    need(len(matches) == 1, 'Physical SQL original job ambiguous')
    job = matches[0]
    need(run['repository']['full_name'] == REPOSITORY, 'Original run repository differs')
    need(all(type(value) is int for value in (witness['run_id'], witness['run_attempt'],
             witness['job']['id'], witness['job']['parent_run_id'],
             witness['job']['parent_run_attempt'], witness['fetch']['inputs']['job_id'],
             witness['log']['bytes'])), 'SQL log witness integer types differ')
    need(witness['format'] == 'cetech-r24-sql-log-witness-v1'
         and witness['repository'] == REPOSITORY
         and witness['run_id'] == run['id'] and witness['run_attempt'] == run['run_attempt']
         and witness['head_sha'] == run['head_sha'], 'SQL log witness run identity differs')
    need(witness['job'] == {'id': job['id'], 'name': job['name'],
                           'parent_run_id': job['run_id'], 'parent_run_attempt': job['run_attempt'],
                           'parent_head_sha': job['head_sha']}
         and job['run_id'] == run['id'] and job['run_attempt'] == run['run_attempt']
         and job['head_sha'] == run['head_sha'], 'SQL log witness parent relation differs')
    need(witness['fetch'] == {'tool': 'mcp__codex_apps__github_fetch_workflow_job_logs',
                              'inputs': {'repo_full_name': REPOSITORY, 'job_id': job['id']}},
         'SQL log witness is not the exact original job fetch')
    need(witness['snapshots'] == {'run_json_sha256': sha((base / 'run.json').read_bytes()),
                                  'jobs_json_sha256': sha((base / 'jobs.json').read_bytes())},
         'SQL log witness metadata snapshots differ')
    need(witness['log'] == {'filename': 'sql-original.log', 'sha256': sha(sql_raw), 'bytes': len(sql_raw)},
         'SQL log witness raw bytes differ')
    return {'sha256': sha(raw), 'bytes': len(raw), 'witness': witness}

def sql_summary(log, tests, source, full_command):
    """Observe PHPUnit summaries without reclassifying actual deprecations as a clean run."""
    text = re.sub(r'\x1b\[[0-?]*[ -/]*[@-~]', '', log)
    lines = [re.sub(r'^\d{4}-\d{2}-\d{2}T[0-9:.]+Z\s?', '', line).strip() for line in text.splitlines()]
    clean = [re.fullmatch(r'OK \(' + str(tests) + r' tests, ([1-9][0-9]*) assertions\)', line) for line in lines]
    clean = [match for match in clean if match]
    issues = [(i, re.fullmatch(r'Tests: ' + str(tests) + r', Assertions: ([1-9][0-9]*)(.*)\.', line)) for i, line in enumerate(lines)]
    issues = [(i, match) for i, match in issues if match]
    need(len(clean) + len(issues) == 1, 'Exactly one physical PHPUnit summary required: ' + str(tests))
    if clean:
        return {'tests': tests, 'assertions': int(clean[0][1]), 'summary': 'clean', 'deprecations': 0, 'phpunit_deprecations': 0, 'total_deprecations': 0}
    i, match = issues[0]
    prior = [line for line in lines[:i] if line]
    need(prior and prior[-1] == 'OK, but there were issues!', 'Physical PHPUnit issue summary is not a successful run')
    suffix = match[2]
    pieces = re.findall(r', (Deprecations|PHPUnit Deprecations): ([1-9][0-9]*)', suffix)
    need(pieces and ''.join(', ' + label + ': ' + value for label, value in pieces) == suffix and len({label for label, _ in pieces}) == len(pieces), 'Only explicitly observed deprecations may accompany physical success')
    counts = dict(pieces)
    config = ET.parse(source / 'phpunit.real-db.xml').getroot().attrib
    forbidden = {'--fail-on-all-issues'}
    if 'Deprecations' in counts: forbidden.add('--fail-on-deprecation')
    if 'PHPUnit Deprecations' in counts: forbidden.add('--fail-on-phpunit-deprecation')
    need(not any(re.search(r'(?<!\S)' + re.escape(flag) + r'(?=\s|$)', full_command) for flag in forbidden), 'Executed source treats observed deprecations as failure')
    attrs = ['failOnAllIssues']
    if 'Deprecations' in counts: attrs.append('failOnDeprecation')
    if 'PHPUnit Deprecations' in counts: attrs.extend(['failOnPhpunitDeprecation', 'failOnPHPUnitDeprecation'])
    need(not any(config.get(key, 'false').lower() in ('true', '1') for key in attrs), 'Exact PHPUnit config treats observed deprecations as failure')
    native = int(counts.get('Deprecations', 0)); phpunit = int(counts.get('PHPUnit Deprecations', 0))
    return {'tests': tests, 'assertions': int(match[1]), 'summary': 'success_with_deprecations', 'deprecations': native, 'phpunit_deprecations': phpunit, 'total_deprecations': native + phpunit, 'source_ci_allows_observed_deprecations': True, 'attribution': 'not inferred from summary'}

def main(args):
    base, source = args.directory.resolve(), args.source.resolve()
    def git(*values):
        return subprocess.check_output(['git', *values], cwd=source)
    head = git('rev-parse', 'HEAD').decode().strip()
    tree = git('rev-parse', 'HEAD^{tree}').decode().strip()
    need(head == args.source_head and not git('status', '--porcelain'), 'Exact clean source required')
    run = decode((base / 'run.json').read_bytes())
    jobs = decode((base / 'jobs.json').read_bytes())
    artifacts = decode((base / 'artifacts.json').read_bytes())
    need(run['id'] == args.run_id and run['run_attempt'] == 1 and run['head_sha'] == args.candidate,
         'Run identity differs')
    need(run['path'] == '.github/workflows/ci.yml' and run['status'] == 'completed'
         and run['conclusion'] == 'success', 'Run is incomplete or failed')
    workflow = yaml.safe_load((source / '.github/workflows/ci.yml').read_text())['jobs']
    expected_jobs = {job.get('name', key): job for key, job in workflow.items()}
    need(jobs['total_count'] == len(jobs['jobs']) == len(expected_jobs) == 8
         and {job['name'] for job in jobs['jobs']} == set(expected_jobs), 'Eight source-declared jobs required')
    for job in jobs['jobs']:
        need(job['status'] == 'completed' and job['conclusion'] == 'success'
             and job['run_id'] == args.run_id and job['run_attempt'] == 1
             and job['head_sha'] == args.candidate, 'Executed job identity/result differs')
        declared = [step['name'] for step in expected_jobs[job['name']]['steps'] if 'name' in step]
        actual = {step['name']: step for step in job['steps']}
        for name in declared:
            need(name in actual and actual[name]['status'] == 'completed'
                 and actual[name]['conclusion'] == 'success', 'Declared step incomplete: ' + name)
    membership = {}
    for step in workflow['php85-wordpress']['steps']:
        if step.get('uses') == 'actions/upload-artifact@v4':
            name = step['with']['name']
            members = [line.rsplit('/', 1)[-1] for line in step['with']['path'].splitlines()]
            if any('*' in member for member in members):
                need(args.profile == 'r24', 'Unknown wildcard package inventory')
                members = ['current.zip', 'current.json', 'previous.zip', 'previous.json', 'reader.zip', 'reader.json']
            need(name not in membership and len(set(members)) == len(members), 'Ambiguous artifact inventory')
            membership[name] = set(members)
    expected_count = 28
    need(artifacts['total_count'] == len(artifacts['artifacts']) == len(membership) == expected_count
         and {a['name'] for a in artifacts['artifacts']} == set(membership), 'Original archive closure differs')
    members_dir = base / 'root-r24-members'
    need(not members_dir.is_symlink(), 'Unsafe audit member directory')
    members_dir.mkdir(exist_ok=True)
    ledger, primary = [], []
    all_members = {}
    for artifact in sorted(artifacts['artifacts'], key=lambda item: item['name']):
        path = base / 'originals' / (str(artifact['id']) + '.zip')
        raw = path.read_bytes()
        need(not artifact['expired'] and artifact['digest'] == 'sha256:' + sha(raw)
             and artifact['size_in_bytes'] == len(raw), 'Original ZIP digest/size differs')
        need(artifact['workflow_run']['id'] == args.run_id
             and artifact['workflow_run']['head_sha'] == args.candidate
             and artifact['created_at'] >= run['run_started_at'], 'Artifact source/run differs')
        with zipfile.ZipFile(path) as archive:
            need(archive.testzip() is None and len(archive.namelist()) == len(set(archive.namelist()))
                 and set(archive.namelist()) == membership[artifact['name']], 'ZIP closure/CRC differs')
            hashes = {}
            for name in archive.namelist():
                need(Path(name).name == name and name not in all_members, 'Unsafe or repeated member')
                content = archive.read(name)
                all_members[name] = content
                need(not (members_dir / name).is_symlink(), 'Unsafe extracted original path')
                (members_dir / name).write_bytes(content)
                hashes[name] = {'sha256': sha(content), 'bytes': len(content)}
                if name.endswith('.json') and name.startswith('opening-'):
                    report = decode(content)
                    if 'cases' in report: primary.append(report)
        ledger.append({'id': artifact['id'], 'name': artifact['name'], 'zip_sha256': sha(raw),
                       'zip_bytes': len(raw), 'members': hashes})
    count = 970  # retained P06 933 + N17 24 + N18 13; R21/R22/R23/R24 remain distinct
    need(sum(len(report['cases']) for report in primary) == count, 'Primary census differs')
    source_paths = [p for p in git('ls-tree', '-r', '--name-only', head).decode().splitlines()
                    if p.endswith('.php') and (p.startswith(('src/', 'database/'))
                    or p in (SLUG + '.php', 'uninstall.php'))]
    source_map = {p: sha(git('show', head + ':' + p)) for p in sorted(source_paths)}
    source_hash = sha(json.dumps(source_map, separators=(',', ':')).encode())
    need(len(source_map) == 1004, 'Production source census differs')
    for report in primary:
        need(report['status'] == 'PASS' and report['source_head'] == head
             and report['candidate_head'] == args.candidate and report['source_tree'] == tree
             and report.get('installed_php_sources', source_map) == source_map
             and report['installed_php_sources_hash'] == source_hash,
             'Primary result/source/map differs')
        need(len({c['id'] for c in report['cases']}) == len(report['cases'])
             and all(c['status'] == 'PASS' for c in report['cases']), 'Failed or duplicate cases')
    os.environ['CETECH_DE_QUALIFICATION_CANDIDATE_HEAD'] = args.candidate
    os.environ['CETECH_DE_QUALIFICATION_HEAD'] = head
    os.environ['CETECH_DE_QUALIFICATION_TREE'] = tree
    def member(name):
        return str(members_dir / name)
    retained = [member(name) for name in (
        'opening-promise-native-configuration-results.json', 'opening-promise-native-configuration-cpt-results.json',
        'opening-qualification-results.json', 'opening-quote-placement-cpt-results.json',
        'opening-http-qualification-results.json', 'opening-promise-storage-results.json',
        'opening-promise-calculation-results.json', 'opening-promise-handoff-results.json',
        'opening-promise-handoff-cpt-results.json', 'opening-http-promise-handoff-results.json',
        'opening-http-promise-native-configuration-results.json')]
    checks = [('verify-opening-promise-native-configuration.py', retained)]
    if args.profile == 'r24':
        checks += [('verify-promise-qualification-bounds.py', [member(name) for name in (
            'opening-promise-qualification-bounds-results.json',
            'opening-promise-qualification-native-bounds-results.json',
            'opening-promise-qualification-native-bounds-cpt-results.json')] + retained),
            ('verify-promise-operational-lifecycle-receipts.py', [member(name) for name in (
                'opening-promise-operational-lifecycle-results.json',
                'opening-promise-operational-lifecycle-cpt-results.json')]
                + retained[2:10] + retained[:2] + retained[10:]
                + [member(name + '.zip') for name in ('current', 'previous', 'reader')])]
    checks += [
        ('verify-opening-promotion-native.py', [member('opening-promotion-native-results.json'), member('opening-promotion-native-cpt-results.json')]),
        ('verify-return-policy-admin-native.py', ['--work', str(members_dir), '--receipt', member('return-policy-admin-native-results.json')]),
        ('verify-refund-policy-admin-native.py', ['--work', str(members_dir), '--receipt', member('refund-policy-admin-native-results.json')]),
        ('verify-policy-assignment-admin-native.py', ['--work', str(members_dir), '--receipt', member('policy-assignment-admin-native-results.json')]),
        ('verify-customer-policy-native.py', ['--repo', str(source), '--work', str(members_dir), '--package', member('current.zip')]),
    ]
    strict = {}
    for script, values in checks:
        result = subprocess.run([sys.executable, str(source / 'scripts/qualification' / script), *values],
                                cwd=source, text=True, capture_output=True)
        (base / ('root-' + script + '.log')).write_text(result.stdout + result.stderr)
        need(result.returncode == 0, 'Exact source strict verifier failed: ' + script)
        strict[script] = result.stdout
    smoke = decode(all_members['store-smoke-diagnostic.json'])
    need(smoke['format'] == 'cetech-store-smoke-diagnostic-v1'
         and smoke['status'] == 'PASS' and smoke['stage'] == 'complete' and smoke['command_exit'] == 0
         and smoke['source_head'] == head and smoke['source_tree'] == tree
         and smoke['candidate_head'] == args.candidate, 'Smoke identity/result differs')
    need(smoke['listener']['stopped_and_waited'] is True
         and smoke['listener']['owned_wait_signal'] in (None, 15)
         and smoke['cleanup'] == {'kernel_core_pattern_restored': True, 'private_files_removed': True,
                                 'interrupted_by_signal': None}
         and smoke['raw_core_log_or_response_published'] is False
         and smoke['allowlisted_error_codes'] == [] and smoke['allowlisted_error_classes'] == [],
         'Smoke restoration/privacy differs')
    spec = importlib.util.spec_from_file_location('root_exact_package', source / 'scripts/qualification/extract-promise-qualification-package.py')
    package_module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(package_module)
    package_refs = {'current': head}
    if args.profile == 'r24':
        package_refs.update(previous='543275af6844d649268f9ac02db518420dac9fe6',
                            reader='3b57ffc53b64f485aa9ad30e2d9e5d424afc3468')
    packages = {}
    for name, ref in package_refs.items():
        report = package_module.verify(members_dir / (name + '.zip'), members_dir / (name + '.json'), source, ref)
        packages[name] = {'head': ref, 'zip_sha256': report['zip_sha256'],
                          'report_sha256': sha(all_members[name + '.json']),
                          'files': len(report['package_files']), 'production_map': report['production_php_sources_hash']}
    # N18 has no retained private state by design. The committed verifier's public
    # predicates require only its immutable identity/environment anchor; no fixture
    # credentials, request replay, synthetic observations or private SQL are supplied.
    def module(name, filename):
        specification = importlib.util.spec_from_file_location(name, source / 'scripts/qualification' / filename)
        loaded = importlib.util.module_from_spec(specification)
        sys.modules[name] = loaded
        specification.loader.exec_module(loaded)
        return loaded
    n18 = module('r24_original_n18', 'verify-opening-promotion-cart.py')
    n18_identity, n18_sources, n18_specs = n18.authority()
    n18_reports = [decode(all_members[name]) for name in ('opening-http-promotion-cart-results.json', 'opening-http-promotion-cart-browser-results.json', 'opening-http-promotion-placement-results.json')]
    n18_context = {'identity': {**n18_identity, 'installed_php_sources': n18_sources, 'environment': n18_reports[0]['environment']}}
    need(n18.verify_reports(n18_reports, n18_context, n18_identity, n18_sources, n18_specs) == (7, 3, 3), 'Retained N18 public semantics differ')
    n18.closeout(decode(all_members['opening-http-promotion-cart-closeout.json']))
    strict['verify-opening-promotion-cart.py public predicates'] = 'PASS direct=7 browser=3 placement=3 exact closed closeout; no removed private state recreated'
    r24 = module('r24_original_protocol', 'verify-customer-policy-native.py')
    r24_auth = r24.authority(source, members_dir, members_dir / 'current.zip')
    r24_reports = {backend: decode(all_members['customer-policy-native-' + backend + '-results.json']) for backend in ('hpos', 'cpt')}
    r24_closeout = decode(all_members['customer-policy-native-closeout.json'])
    r24_aggregate = decode(all_members['customer-policy-native-results.json'])
    need(r24.verify_aggregate(r24_aggregate, r24_auth, r24_reports, r24_closeout), 'R24 exact aggregate closure differs')
    need(len(r24_aggregate['cases']) == 30 and all(len(value['cases']) == 26 for value in r24_reports.values()), 'R24 source matrix census differs')
    need(r24_auth['production_php_count'] == 1004 and r24_auth['installed_php_sources'] == source_map, 'R24 package source map differs')
    need(membership['customer-policy-native'] == {'customer-policy-native-results.json', 'customer-policy-native-hpos-results.json', 'customer-policy-native-cpt-results.json', 'customer-policy-native-closeout.json'}, 'R24 new28th artifact member set differs')
    # JUnit is not uploaded by the retained workflow. Require original successful
    # physical job log results plus each source-declared count guard separately.
    sql_raw = (base / 'sql-original.log').read_bytes()
    sql_witness = sql_log_witness(base, run, jobs, workflow, sql_raw)
    sql = sql_raw.decode('utf-8')
    need('real_db tests=585 skipped=0 executed=585 failures=0 errors=0' in sql, 'Physical SQL585 aggregate differs')
    need('real_db tests=36 skipped=0 executed=36 failures=0 errors=0' in sql, 'Early physical R24 SQL36 aggregate differs')
    script = workflow['php85-real-db']['steps'][-1]['run'] if 'php85-real-db' in workflow else ''
    if not script:
        possible = [step['run'] for job in workflow.values() for step in job['steps'] if 'run' in step and 'cetech-de-real-db.junit.xml' in step['run']]
        need(len(possible) == 1, 'Physical SQL step ambiguous')
        script = possible[0]
    final_marker = script.index('--log-junit "${RUNNER_TEMP}/cetech-de-real-db.junit.xml"')
    full_command = script[script.rfind('vendor/bin/phpunit', 0, final_marker):script.find('\n', final_marker)]
    sql_full_summary = sql_summary(sql, 585, source, full_command)
    sql_early_summary = sql_summary(sql, 36, source, script)
    guards = re.findall(r'php scripts/ci-assert-junit-counts\.php "\$\{RUNNER_TEMP\}/cetech-de-real-db\.junit\.xml" 585 \\\n\s+\'([^\']+)\' (\d+)', script)
    need(len(guards) == 32, 'Source-declared SQL585 guard census differs')
    for klass, minimum in guards:
        pattern = 'required_real_db_class=' + re.escape(klass) + r' executed=([0-9]+) minimum=' + minimum + r'(?:\s|$)'
        observed = re.findall(pattern, sql)
        need(observed and all(int(value) >= int(minimum) for value in observed), 'Physical SQL guard absent: ' + klass)
    r24_class_counts = {'CustomerPolicyReceiptRealDatabaseTest': 15, 'CustomerPolicyCurrentRealDatabaseTest': 14, 'CustomerPolicyNativeReadRealDatabaseTest': 7}
    for klass, exact in r24_class_counts.items():
        need(re.search(re.escape(klass) + ' executed=' + str(exact) + ' minimum=' + str(exact) + r'(?:\s|$)', sql), 'New physical SQL class census differs')
    output = {'status': 'PASS', 'run_id': args.run_id, 'source_head': head, 'candidate_head': args.candidate,
              'tree': tree, 'event': run['event'], 'attempt': 1, 'observations': count,
              'artifacts': ledger, 'packages': packages, 'production_files': len(source_map),
              'production_map': source_hash, 'strict_verifiers': strict,
              'counts': {name: len(decode(raw)['cases']) for name, raw in all_members.items()
                         if name.startswith('opening-') and name.endswith('.json') and 'cases' in decode(raw)},
              'jobs': [{'id': j['id'], 'name': j['name']} for j in jobs['jobs']],
              'r24': {'aggregate_cases': 30, 'backend_cases': {'hpos': 26, 'cpt': 26}, 'closed_public_receipts': 4, 'producer_sha256': r24_auth['producer_sha256'], 'closeout_sha256': r24_aggregate['closeout_sha256']},
              'sql': {'full_tests': 585, 'early_r24_tests': 36, 'r24_classes': r24_class_counts, 'source_guards': len(guards), 'full_summary': sql_full_summary, 'early_r24_summary': sql_early_summary, 'original_log_sha256': sha(sql_raw), 'original_log_bytes': len(sql_raw), 'original_log_fetch': sql_witness},
              'local_php_replay': False, 'sql_junit_uploaded': False}
    (base / 'root-r24-original-audit.json').write_text(json.dumps(output, indent=2) + '\n')
    print(json.dumps({key: output[key] for key in ('status', 'run_id', 'observations', 'production_map')}))

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--directory', type=Path, required=True)
    parser.add_argument('--source', type=Path, required=True)
    parser.add_argument('--source-head', required=True)
    parser.add_argument('--candidate', required=True)
    parser.add_argument('--run-id', type=int, required=True)
    parser.add_argument('--profile', choices=['r24'], default='r24')
    main(parser.parse_args())
