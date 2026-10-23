#!/usr/bin/env python3
"""Execute the actual PHP failure projector and Python consumer in subprocesses."""
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest

HERE = Path(__file__).resolve().parent
SECRET = 'PRIVATE_message_nonce_SQL_order_912345_source_path'
KEYS = {'format', 'phase', 'kind', 'error_class', 'checkout_exception_observed', 'tracked_native_order', 'checkout_endpoint_verified', 'callback_status', 'callback_observation'}
PHP_PROBE = r'''
class WC_Order {
    public function __construct(private int $id, private int $customer) {}
    public function get_id(): int { return $this->id; }
    public function get_customer_id(): int { return $this->customer; }
}
class PrivateDiagnosticError extends Exception {}
function get_current_user_id(): int { return $GLOBALS['probe_current_user']; }
require dirname($argv[1]).'/opening-http-promotion-placement-observer.php';
require $argv[1];
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$GLOBALS['probe_current_user']=$input['current_user']??0;
$GLOBALS['cetech_n18_placement_phase']=$input['phase']??'entry';
$GLOBALS['cetech_n18_placement_wp_error_returned']=$input['wp_error']??false;
$GLOBALS['cetech_n18_checkout_endpoint_verified']=$input['endpoint']??null;
$GLOBALS['cetech_n18_checkout_exception_observed']=$input['exception_observed']??false;
$GLOBALS['cetech_n18_callback_observation']=$input['callback_observation']??null;
$GLOBALS['cetech_n18_callback_status']=$input['callback_status']??'not_installed';
$GLOBALS['cetech_n18_owned_orders']=$input['owned']??[];
$GLOBALS['cetech_n18_creating_order']=$input['creating']??false;
$order=isset($input['order'])?new WC_Order($input['order'][0],$input['order'][1]):null;
$GLOBALS['cetech_n18_created_order']=$order;
if (true===($input['observe']??false)) { cetech_n18_observe_checkout_exception($order); }
$error=match($input['class']??'RuntimeException') {
    'TypeError'=>new TypeError($input['message']),
    'UnexpectedValueException'=>new UnexpectedValueException($input['message']),
    'unknown'=>new PrivateDiagnosticError($input['message']),
    default=>new RuntimeException($input['message']),
};
echo json_encode(cetech_n18_placement_failure($error),JSON_THROW_ON_ERROR),PHP_EOL;
'''
PYTHON_PROBE = r'''
import importlib.util,json,sys
from types import SimpleNamespace
spec=importlib.util.spec_from_file_location('owned_placement_diagnostic_probe',sys.argv[1])
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
request=json.load(sys.stdin)
if request.get('response_probe'):
    class Recorder:
        def __init__(self): self.report={};self.writes=0
        def write(self): self.writes+=1
    recorder=Recorder()
    try:
        data=module.closed_observation(SimpleNamespace(status=request['status'],body=request['body']),module.PLACED_KEYS,recorder)
        result={'failed':False,'data':data,'report':recorder.report,'writes':recorder.writes}
    except RuntimeError:
        result={'failed':True,'report':recorder.report,'writes':recorder.writes}
elif request.get('success_probe'):
    result={'closed':module.booleans(request['data'],module.PLACED_KEYS),'keys':list(module.PLACED_KEYS),'ids':list(module.IDS)}
else:
    result=module.placement_failure_observation(SimpleNamespace(status=request['status'],body=request['body']))
print(json.dumps(result,separators=(',',':')))
'''


class PlacementDiagnosticTests(unittest.TestCase):
    def execute(self, command, packet, env=None):
        result = subprocess.run(command, input=json.dumps(packet), text=True, capture_output=True, check=False, env=env)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertNotIn(SECRET, result.stdout + result.stderr)
        self.assertNotIn('PrivateDiagnosticError', result.stdout + result.stderr)
        return json.loads(result.stdout)

    def producer(self, **overrides):
        packet = {'message': SECRET, **overrides}
        env = {**os.environ, 'CETECH_DE_HTTP_OPENING_QUALIFICATION': '0', 'CETECH_DE_NATIVE_OPENING_QUALIFICATION': '0'}
        value = self.execute(['php', '-r', PHP_PROBE, str(HERE / 'opening-http-promotion-cart-mu.php')], packet, env)
        self.assertEqual(KEYS, set(value))
        return value

    def consume(self, value, status=503, raw=None):
        body = raw if raw is not None else json.dumps({'success': False, 'data': {'cetech_n18_placement_failure': value}})
        return self.execute([sys.executable, '-c', PYTHON_PROBE, str(HERE / 'opening-http-promotion-placement-driver.py')], {'status': status, 'body': body})

    def valid(self):
        return {'format': 'cetech-n18-placement-failure-v1', 'phase': 'explicit_stage', 'kind': 'throwable', 'error_class': 'UnexpectedValueException', 'checkout_exception_observed': False, 'tracked_native_order': True, 'checkout_endpoint_verified': True, 'callback_status': 'not_installed', 'callback_observation': None}

    def rejected(self, **arguments):
        value = self.consume(**arguments)
        self.assertFalse(value['packet_valid'])
        self.assertIsNone(value['observation'])

    def test_real_php_throwable_projection_round_trips_without_message_or_path(self):
        value = self.producer(phase='explicit_stage', endpoint=True, order=[17, 0], owned=[17], **{'class': 'UnexpectedValueException'})
        result = self.consume(value)
        self.assertTrue(result['packet_valid'])
        self.assertEqual(value, result['observation'])
        self.assertEqual('UnexpectedValueException', value['error_class'])
        self.assertTrue(value['tracked_native_order'])

    def test_returned_wp_error_does_not_invent_original_throwable_class(self):
        value = self.producer(phase='create_order_wp_error', wp_error=True, endpoint=True)
        self.assertEqual('wp_error', value['kind'])
        self.assertIsNone(value['error_class'])
        self.assertTrue(self.consume(value)['packet_valid'])
        for overrides in ({'phase': 'create_order'}, {'error_class': 'RuntimeException'}):
            self.rejected(value={**value, **overrides})

    def test_callback_observation_is_separate_closed_original_exception_evidence(self):
        observation = {'format': 'cetech-n18-stage-classic-failure-v1', 'error_class': 'RuntimeException', 'cause_class': 'UnexpectedValueException', 'guard_site': 'plan_destination'}
        value = self.producer(phase='create_order_wp_error', wp_error=True, callback_status='threw', callback_observation=observation)
        self.assertIsNone(value['error_class']); self.assertEqual(observation, value['callback_observation'])
        self.assertTrue(self.consume(value)['packet_valid'])
        for invalid in ({**observation, 'message': SECRET}, {**observation, 'guard_site': SECRET}, {**observation, 'cause_class': SECRET}, [], SECRET, True):
            with self.subTest(invalid=type(invalid).__name__):
                self.assertIsNone(self.producer(callback_observation=invalid)['callback_observation'])
                self.rejected(value={**self.valid(), 'callback_observation': invalid})

    def test_callback_progress_is_closed_and_does_not_claim_restoration_as_completion(self):
        for status in ('not_installed', 'installed', 'entered', 'completed', 'threw'):
            value = self.producer(callback_status=status)
            self.assertEqual(status, value['callback_status']); self.assertTrue(self.consume(value)['packet_valid'])
        for status in (SECRET, 1, True, [], {}):
            self.assertEqual('not_installed', self.producer(callback_status=status)['callback_status'])
            self.rejected(value={**self.valid(), 'callback_status': status})

    def test_exception_hook_observes_only_creating_tracked_current_principal_order(self):
        base = dict(phase='create_order_wp_error', wp_error=True, order=[17, 0], owned=[17], creating=True, observe=True)
        value = self.producer(**base)
        self.assertTrue(value['checkout_exception_observed'])
        for changes in ({'creating': False}, {'creating': 1}, {'owned': []}, {'owned': ['17']}, {'current_user': 9}, {'order': [0, 0]}):
            with self.subTest(changes=changes):
                self.assertFalse(self.producer(**{**base, **changes})['checkout_exception_observed'])

    def test_producer_normalizes_unknown_class_phase_and_nonboolean_flags(self):
        value = self.producer(phase=SECRET, endpoint=SECRET, exception_observed=1, wp_error=1, **{'class': 'unknown'})
        self.assertEqual(('entry', 'throwable', 'OtherError'), (value['phase'], value['kind'], value['error_class']))
        self.assertFalse(value['checkout_exception_observed'])
        self.assertIsNone(value['checkout_endpoint_verified'])
        self.assertTrue(self.consume(value)['packet_valid'])

    def test_every_phase_and_nullable_endpoint_stays_closed(self):
        for phase in ('entry', 'current_bundle', 'checkout_address', 'namespace_tracking', 'budget_before', 'create_order', 'create_order_wp_error', 'created_order_identity', 'explicit_stage', 'final_admission', 'sealed_read', 'saved_history', 'package_read', 'persist_placed_order', 'complete'):
            for endpoint in (True, False, None):
                with self.subTest(phase=phase, endpoint=endpoint):
                    value = {**self.valid(), 'phase': phase, 'checkout_endpoint_verified': endpoint}
                    self.assertTrue(self.consume(value)['packet_valid'])

    def test_secret_foreign_or_missing_fields_never_cross_consumer(self):
        for key in KEYS:
            value = self.valid(); del value[key]
            self.rejected(value=value)
        self.rejected(value={**self.valid(), 'message': SECRET})
        self.rejected(value={**self.valid(), SECRET: SECRET})
        for key in ('phase', 'kind', 'error_class', 'format'):
            for invalid in (SECRET, [], {}, None, 1):
                self.rejected(value={**self.valid(), key: invalid})

    def test_nonboolean_flags_and_invalid_status_refuse(self):
        for key in ('checkout_exception_observed', 'tracked_native_order', 'checkout_endpoint_verified'):
            for invalid in (0, 1, SECRET, [], {}):
                self.rejected(value={**self.valid(), key: invalid})
        for status in (True, None, '503', 0, 99, 600, 200, 500):
            result = self.consume(self.valid(), status=status)
            self.assertFalse(result['packet_valid'])
            self.assertEqual(status if type(status) is int and 100 <= status <= 599 else None, result['http_status'])

    def test_duplicate_malformed_oversize_and_foreign_envelopes_refuse(self):
        packet = {'success': False, 'data': {'cetech_n18_placement_failure': self.valid()}}
        valid = json.dumps(packet)
        for raw in ('{', SECRET, valid.replace('"success": false', '"success": true,"success": false'), valid.replace('"phase":', '"phase":"entry","phase":'), valid.replace('"phase": "explicit_stage"', '"phase": NaN'), json.dumps({**packet, 'message': SECRET}), json.dumps({'success': False, 'data': {**packet['data'], 'message': SECRET}}), ' ' * 4096 + valid):
            self.rejected(value=None, raw=raw)

    def test_actual_response_gate_records_nonobject_and_malformed_failure_bodies(self):
        command = [sys.executable, '-c', PYTHON_PROBE, str(HERE / 'opening-http-promotion-placement-driver.py')]
        for raw in ('[]', 'null', 'true', '123', json.dumps(SECRET), '{', json.dumps({'message': SECRET})):
            with self.subTest(body=raw[:10]):
                value = self.execute(command, {'response_probe': True, 'status': 503, 'body': raw})
                self.assertTrue(value['failed']); self.assertEqual(1, value['writes'])
                self.assertEqual({'http_status': 503, 'packet_valid': False, 'observation': None}, value['report']['failure_observation'])
        raw = json.dumps({'success': False, 'data': {'cetech_n18_placement_failure': self.valid()}})
        value = self.execute(command, {'response_probe': True, 'status': 503, 'body': raw})
        self.assertTrue(value['failed']); self.assertTrue(value['report']['failure_observation']['packet_valid'])

    def test_actual_positive_response_gate_returns_original_shape_without_failure_fields(self):
        keys = ('native_order_created', 'pending_unpaid', 'sealed3_current', 'history_all_five', 'saved_format3', 'gateway_refused', 'held_budget_unchanged')
        data = dict.fromkeys(keys, True)
        value = self.execute([sys.executable, '-c', PYTHON_PROBE, str(HERE / 'opening-http-promotion-placement-driver.py')], {'response_probe': True, 'status': 200, 'body': json.dumps({'success': True, 'data': data})})
        self.assertFalse(value['failed']); self.assertEqual(data, value['data'])
        self.assertEqual({}, value['report']); self.assertEqual(0, value['writes'])

    def test_positive_seven_boolean_shape_and_three_case_inventory_are_unchanged(self):
        keys = ('native_order_created', 'pending_unpaid', 'sealed3_current', 'history_all_five', 'saved_format3', 'gateway_refused', 'held_budget_unchanged')
        command = [sys.executable, '-c', PYTHON_PROBE, str(HERE / 'opening-http-promotion-placement-driver.py')]
        value = self.execute(command, {'success_probe': True, 'data': dict.fromkeys(keys, True)})
        self.assertTrue(value['closed']); self.assertEqual(list(keys), value['keys'])
        self.assertEqual(['HTTP-N18-PROMOTION-PLACEMENT-CURRENT-REVIEW', 'HTTP-N18-PROMOTION-NATIVE-ORDER-SEAL-HELD-NO-ADMISSION', 'HTTP-N18-PROMOTION-FRESH-REQUEST-SEALED-HISTORY'], value['ids'])
        for data in ({**dict.fromkeys(keys, True), 'message': SECRET}, {**dict.fromkeys(keys, True), keys[0]: 1}):
            self.assertFalse(self.execute(command, {'success_probe': True, 'data': data})['closed'])


if __name__ == '__main__':
    unittest.main()
