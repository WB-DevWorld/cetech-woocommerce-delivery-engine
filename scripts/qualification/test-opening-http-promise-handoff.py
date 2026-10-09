#!/usr/bin/env python3
"""Pure protocol negatives; these never stand in for native customer requests."""
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('p04_http_protocol', Path(__file__).with_name('opening-http-promise-handoff.py'))
p04 = importlib.util.module_from_spec(spec); spec.loader.exec_module(p04)


class ProtocolTest(unittest.TestCase):
    def case(self, index=0):
        case_id, fields = p04.protocol()[index]
        return {'id': case_id, 'status': 'PASS', 'evidence': dict.fromkeys(fields, True)}

    def test_source_inventory_is_closed_and_distinct_from_retained(self):
        protocol = p04.protocol()
        self.assertEqual(14, len(protocol))
        self.assertEqual(14, len(dict(protocol)))
        self.assertTrue(all(case_id.startswith('HTTP-W2P04-') for case_id, _ in protocol))
        self.assertTrue(all(p04.case_valid(self.case(index)) for index in range(14)))

    def test_case_private_extra_missing_substitution_and_wrong_types_refuse(self):
        original = self.case()
        mutations = []
        changed = copy.deepcopy(original); changed['evidence']['cookie'] = 'PRIVATE'; mutations.append(changed)
        changed = copy.deepcopy(original); changed['evidence'].pop(next(iter(changed['evidence']))); mutations.append(changed)
        changed = copy.deepcopy(original); changed['evidence'] = list(changed['evidence'].values()); mutations.append(changed)
        for value in (1, 'true', None, False):
            changed = copy.deepcopy(original); changed['evidence'][next(iter(changed['evidence']))] = value; mutations.append(changed)
        changed = copy.deepcopy(original); changed['id'] = 'HTTP-W2Q06-CLASSIC-PAID-SEALED-BEFORE-GATEWAY'; mutations.append(changed)
        changed = copy.deepcopy(original); changed['status'] = 'RUNNING'; mutations.append(changed)
        for changed in mutations:
            with self.subTest(changed=changed): self.assertFalse(p04.case_valid(changed))

    def test_boolean_failure_is_not_passing_evidence(self):
        changed = self.case(); changed['status'] = 'FAIL'; changed['evidence'][next(iter(changed['evidence']))] = False
        self.assertTrue(p04.case_valid(changed))
        changed['status'] = 'PASS'; self.assertFalse(p04.case_valid(changed))

    def test_recorder_duplicate_and_private_payload_refuse(self):
        with tempfile.TemporaryDirectory() as folder:
            recorder = p04.Recorder(Path(folder) / 'report.json', {'cases': []})
            first = self.case(); recorder.check(first['id'], True, first['evidence'])
            with self.assertRaises(RuntimeError): recorder.check(first['id'], True, first['evidence'])
            second = self.case(1); second['evidence']['private_row'] = 'PRIVATE'
            with self.assertRaises(RuntimeError): recorder.check(second['id'], True, second['evidence'])

    def test_receipt_symlink_and_private_state_modes_refuse(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); protected = root / 'protected'; protected.write_text('unchanged')
            alias = root / 'alias'; alias.symlink_to(protected)
            with self.assertRaises(RuntimeError): p04.Recorder(alias, {'cases': []})
            self.assertEqual('unchanged', protected.read_text())
            protected.chmod(0o644)
            with self.assertRaises(RuntimeError): p04.load_private(protected)
            with self.assertRaises(RuntimeError): p04.load_private(alias)

    def test_finalizer_requires_full_ordered_cases_and_actual_browser_tuple(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'report.json'
            original = {'status': 'RUNNING', 'cases': [self.case(index) for index in range(13)], 'browser_runtime': {'playwright': '1.58.2', 'chromium': '145.0.7632.6'}}
            for changed in (dict(original, cases=original['cases'][:-1]), dict(original, cases=list(reversed(original['cases']))), dict(original, browser_runtime={'playwright': '1.58.2'}), dict(original, status='FAIL')):
                path.write_text(json.dumps(changed)); p04.finalize(path, True, True, True, True)
                self.assertEqual('FAIL', json.loads(path.read_text())['status'])
            path.write_text(json.dumps(original)); p04.finalize(path, True, True, True, True)
            self.assertEqual('PASS', json.loads(path.read_text())['status'])

    def test_cleanup_failure_cannot_be_replaced_by_success(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'report.json'; path.write_text(json.dumps({'status': 'RUNNING', 'cases': [self.case(index) for index in range(13)], 'browser_runtime': {'playwright': '1.58.2', 'chromium': '145.0.7632.6'}}))
            with self.assertRaises(RuntimeError): p04.finalize(path, False, True, True, True)
            report = json.loads(path.read_text()); self.assertEqual('FAIL', report['status']); self.assertEqual('FAIL', report['cases'][-1]['status'])
            with self.assertRaises(RuntimeError): p04.finalize(path, True, True, True, True)
            self.assertEqual('FAIL', json.loads(path.read_text())['status'])

    def test_fixture_decorator_forwards_exact_new_profile_evidence(self):
        code = r"""
require $argv[1];
$source=file_get_contents($argv[2]);$start=strpos($source,'final class CetechQuotePlacementHttpFixture {');$end=strpos($source,"\nif (!class_exists('CetechOpeningQuotePlacementGateway'",$start);
if(false===$start||false===$end){throw new RuntimeException('Fixture boundary unavailable.');}eval(substr($source,$start,$end-$start));
$runtime=(new ReflectionClass(CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime::class))->newInstanceWithoutConstructor();
$evidence=(new ReflectionClass(CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementEvidence::class))->newInstanceWithoutConstructor();
$guard=new class implements CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementSavedEvidenceGuard {public function tables(CetechDeliveryEngine\Domain\Operation\OperationSession $s):array{return [];}public function verify(CetechDeliveryEngine\Domain\Operation\OperationSession $s,CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding $b):bool{return true;}};
$produced=clone $guard;$sequence=[];
$prior=static function($g,$e)use($guard,$evidence,$produced,&$sequence){if($g!==$guard||$e!==$evidence){throw new RuntimeException('Exact evidence identity changed.');}$sequence[]='production';return $produced;};
$property=new ReflectionProperty($runtime,'decorate_guard');$property->setValue($runtime,$prior);
CetechQuotePlacementHttpFixture::install_seal_barrier($runtime,static function()use(&$sequence){$sequence[]='selected';return true;},static function()use(&$sequence){$sequence[]='barrier';});
$decorator=$property->getValue($runtime);if($decorator($guard,$evidence)!==$produced||$sequence!==['production','selected','barrier']){throw new RuntimeException('Native evidence or guard handoff changed.');}echo 'exact_new_evidence_forwarded';
"""
        root = Path(__file__).resolve().parents[2]
        result = subprocess.run([os.environ.get('CETECH_DE_QUALIFICATION_PHP', 'php'), '-r', code, str(root / 'vendor/autoload.php'), str(root / 'scripts/qualification/opening-http-quote-placement-support.php')], capture_output=True, timeout=20, check=False)
        self.assertEqual(0, result.returncode, (result.stderr + result.stdout).decode(errors='replace')[:1000])
        self.assertEqual(b'exact_new_evidence_forwarded', result.stdout)

    def test_failed_or_different_original_prerequisite_refuses_before_effects(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); sources = {'src/fixture.php': 'd' * 64}
            report = {'status': 'PASS', 'source_head': 'a' * 40, 'candidate_head': 'b' * 40, 'source_tree': 'c' * 40, 'installed_php_sources': sources, 'installed_php_sources_hash': hashlib.sha256(json.dumps(sources, separators=(',', ':')).encode()).hexdigest()}
            for kind, (name, count) in p04.PRIOR.items():
                (root / name).write_text(json.dumps(dict(report, cases=[{'status': 'PASS'}] * (count or 32))))
            env = {'CETECH_DE_QUALIFICATION_HEAD': 'a' * 40, 'CETECH_DE_QUALIFICATION_CANDIDATE_HEAD': 'b' * 40, 'CETECH_DE_QUALIFICATION_TREE': 'c' * 40}
            with patch.dict(os.environ, env):
                self.assertEqual(7, len(p04.prerequisites(root)[1]))
                path = root / p04.PRIOR['p04_cpt'][0]; changed = json.loads(path.read_text()); changed['cases'][0]['status'] = 'FAIL'; path.write_text(json.dumps(changed))
                with self.assertRaises(RuntimeError): p04.prerequisites(root)
                changed['cases'][0]['status'] = 'PASS'; changed['source_head'] = 'f' * 40; path.write_text(json.dumps(changed))
                with self.assertRaises(RuntimeError): p04.prerequisites(root)


if __name__ == '__main__': unittest.main()
