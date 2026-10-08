#!/usr/bin/env python3
"""Closed Q06 receipt and actual native browser route correlation protocol.

These adversarial checks do not claim disposable WordPress route qualification.
"""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).parent
SPEC = importlib.util.spec_from_file_location('q06_http', ROOT / 'opening-http-quote-placement.py')
DRIVER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DRIVER)
IDENTITY = {'source_head': '1' * 40, 'candidate_head': '2' * 40, 'source_tree': '3' * 40, 'installed_php_sources_hash': '4' * 64}


def evidence(case_id):
    booleans = DRIVER.DIRECT_BOOLS.get(case_id, DRIVER.BROWSER_BOOLS + (('one_free_completion',) if case_id == DRIVER.BROWSER_IDS[1] else ('one_gateway_call',)))
    return {'before': dict.fromkeys(DRIVER.COUNTS, 0), 'after': dict.fromkeys(DRIVER.COUNTS, 1), 'observations': dict.fromkeys(booleans, True), 'http_status': 200}


def browser_report():
    return dict(format='cetech-w2q06-placement-browser-v1', runtime={'playwright': '1.58.2', 'chromium': '145.0.7632.6'}, status='PASS', cases=[{'id': case, 'status': 'PASS', 'evidence': evidence(case)} for case in DRIVER.BROWSER_IDS], **IDENTITY)


def helpers(expression):
    source = (ROOT / 'quote-placement-browser.cjs').read_text()
    functions = source[source.index('function nativeRouteMatches('):source.index('write();\n(async')]
    script = "const base=new URL('http://127.0.0.1:8085');const state={store_extensions_url:'/?rest_route=/wc/store/v1/cart/extensions',checkout_url:'/?rest_route=/wc/store/v1/checkout'};const own=value=>{try{const u=new URL(value,base);return u.origin===base.origin&&!u.username&&!u.password&&!u.hash;}catch(_){return false;}};\n" + functions + '\nconsole.log(JSON.stringify(' + expression + '));'
    result = subprocess.run(['node', '-e', script], capture_output=True, timeout=20, check=False)
    if result.returncode != 0:
        raise AssertionError('Pure native placement browser correlation failed')
    return json.loads(result.stdout)


def operational_plan(rows):
    code = "require $argv[1]; require $argv[2]; $input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR); echo json_encode(CetechQuotePlacementOperationalCleanup::plan($input['before'],$input['orders'],$input['rows']),JSON_THROW_ON_ERROR);"
    fixture = {'before': {name: [] for name in ('shipments', 'shipment_items', 'shipment_events', 'audit_log')}, 'orders': {'10': {'groups': ['group'], 'items': [101]}}, 'rows': rows}
    result = subprocess.run([os.environ.get('CETECH_DE_QUALIFICATION_PHP', 'php'), '-r', code, str(ROOT.parent.parent / 'vendor/autoload.php'), str(ROOT / 'opening-quote-placement-support.php')], input=json.dumps(fixture).encode(), capture_output=True, timeout=20, check=False)
    if result.returncode != 0:
        raise AssertionError('Owned native operational cleanup policy failed')
    return json.loads(result.stdout)


class QuotePlacementProtocol(unittest.TestCase):
    def test_preparation_failure_discloses_no_private_cause(self):
        class Recorder:
            def check(self, case_id, condition, facts):
                self.case = {'id': case_id, 'status': 'PASS' if condition else 'FAIL', 'evidence': facts}
        class PrivateSqlCredentialError(Exception):
            pass
        for error, expected in ((ValueError('PRIVATE SQL credential payload'), 'ValueError'), (PrivateSqlCredentialError('PRIVATE raw session token'), 'OtherError')):
            recorder = Recorder(); DRIVER.record_preparation_failure(recorder, error)
            self.assertEqual(DRIVER.DIRECT_IDS[0], recorder.case['id'])
            self.assertEqual('FAIL', recorder.case['status'])
            self.assertEqual({'stage': 'ownership', 'error_class': expected, 'required_case_incomplete': True}, recorder.case['evidence'])
            self.assertTrue(DRIVER.case_valid(recorder.case))
            self.assertNotIn('PRIVATE', json.dumps(recorder.case))
            self.assertNotIn('PrivateSqlCredentialError', json.dumps(recorder.case))

    def test_late_unrelated_operational_rows_never_gain_cleanup_authority(self):
        import hashlib
        rows = {
            'shipments': [{'id': '1', 'order_id': '10', 'delivery_group_id': 'group', 'idempotency_key': '10|group', 'shipment_number': 'DE-10-' + hashlib.sha1(b'group').hexdigest()[:8]}],
            'shipment_items': [{'id': '2', 'shipment_id': '1', 'order_id': '10', 'order_item_id': '101'}],
            'shipment_events': [{'id': '3', 'shipment_id': '1', 'event_type': 'created', 'source': 'system', 'actor_user_id': None}],
            'audit_log': [{'id': '4', 'entity_type': 'order', 'entity_id': '10', 'action': 'shipment_creation_succeeded', 'actor_user_id': None, 'previous_value': None, 'site_context': None, 'new_value': json.dumps({'outcome': 'created', 'source': 'system', 'shipment_ids': [1]})}],
        }
        plan = operational_plan(rows)
        self.assertTrue(plan['ownership_complete'])
        self.assertEqual({'shipments': [1], 'shipment_items': [2], 'shipment_events': [3], 'audit_log': [4]}, plan['authorized'])
        # These arrive after the baseline snapshot, including an audit with the
        # same numeric entity ID but a different entity type or receipt owner.
        for suffix, mutation in (
            ('shipments', {'id': '91', 'order_id': '999'}),
            ('shipment_items', {'id': '92', 'shipment_id': '91', 'order_id': '999'}),
            ('shipment_events', {'id': '93', 'shipment_id': '91'}),
            ('audit_log', {'id': '94', 'entity_type': 'product'}),
            ('audit_log', {'id': '95', 'new_value': json.dumps({'outcome': 'created', 'source': 'system', 'shipment_ids': [91]})}),
            ('audit_log', {'id': '96', 'action': 'configuration_changed'}),
        ):
            late = copy.deepcopy(rows); unrelated = dict(late[suffix][0], **mutation); late[suffix].append(unrelated)
            actual = operational_plan(late)
            self.assertFalse(actual['ownership_complete'])
            self.assertNotIn(int(unrelated['id']), actual['authorized'][suffix])
            remaining = [row for row in late[suffix] if int(row['id']) not in actual['authorized'][suffix]]
            self.assertIn(unrelated, remaining)

    def test_inventory_is_unique_and_exact(self):
        self.assertEqual(28, len(DRIVER.DIRECT_IDS))
        self.assertEqual(2, len(DRIVER.BROWSER_IDS))
        self.assertEqual(31, len(DRIVER.REQUIRED_IDS))
        self.assertEqual(31, len(set(DRIVER.REQUIRED_IDS)))
        self.assertEqual(set(DRIVER.DIRECT_IDS), set(DRIVER.DIRECT_BOOLS))
        self.assertTrue(all(set(value).issubset(DRIVER.BOOLS) for value in DRIVER.DIRECT_BOOLS.values()))

    def test_every_case_requires_its_exact_closed_observations(self):
        for case_id in DRIVER.DIRECT_IDS + DRIVER.BROWSER_IDS:
            case = {'id': case_id, 'status': 'PASS', 'evidence': evidence(case_id)}
            self.assertTrue(DRIVER.case_valid(case), case_id)
            for key in list(case['evidence']['observations']):
                missing = copy.deepcopy(case); del missing['evidence']['observations'][key]
                self.assertFalse(DRIVER.case_valid(missing), case_id + key)
                false = copy.deepcopy(case); false['evidence']['observations'][key] = False
                self.assertFalse(DRIVER.case_valid(false), case_id + key)
                wrong = copy.deepcopy(case); wrong['evidence']['observations'][key] = 1
                self.assertFalse(DRIVER.case_valid(wrong), case_id + key)
            private = copy.deepcopy(case); private['evidence']['observations']['private_quote_row'] = 'PRIVATE'
            self.assertFalse(DRIVER.case_valid(private))

    def test_counters_are_exact_bounded_integers(self):
        passed = evidence(DRIVER.DIRECT_IDS[0])
        for bad in (True, -1, 1000001, 'PRIVATE-NATIVE-ID', None, 1.0):
            changed = copy.deepcopy(passed); changed['before']['orders'] = bad
            self.assertFalse(DRIVER.evidence_valid(changed))
        for key in ('private_order_key', 'nonce', 'session'):
            changed = copy.deepcopy(passed); changed['after'][key] = 'PRIVATE'
            self.assertFalse(DRIVER.evidence_valid(changed))
        for status in (True, 99, 600, '200'):
            changed = copy.deepcopy(passed); changed['http_status'] = status
            self.assertFalse(DRIVER.evidence_valid(changed))

    def test_browser_requires_exact_identity_runtime_order_and_completion(self):
        report = browser_report(); self.assertTrue(DRIVER.browser_report_valid(report, IDENTITY))
        for mutation in ('source', 'version', 'chromium', 'duplicate', 'reverse', 'missing', 'extra_case', 'private_top', 'private_case', 'false_predicate', 'wrong_id', 'not_pass'):
            bad = copy.deepcopy(report)
            if mutation == 'source': bad['installed_php_sources_hash'] = '9' * 64
            elif mutation == 'version': bad['runtime']['playwright'] = '1.59.0'
            elif mutation == 'chromium': bad['runtime']['chromium'] = '145.0.0.0'
            elif mutation == 'duplicate': bad['cases'][1] = copy.deepcopy(bad['cases'][0])
            elif mutation == 'reverse': bad['cases'].reverse()
            elif mutation == 'missing': bad['cases'].pop()
            elif mutation == 'extra_case': bad['cases'].append(copy.deepcopy(bad['cases'][0]))
            elif mutation == 'private_top': bad['password'] = 'PRIVATE'
            elif mutation == 'private_case': bad['cases'][0]['evidence']['quote_body'] = 'PRIVATE'
            elif mutation == 'false_predicate': bad['cases'][0]['evidence']['observations']['known_sealed_receipt'] = False
            elif mutation == 'wrong_id': bad['cases'][0]['id'] = DRIVER.DIRECT_IDS[0]
            elif mutation == 'not_pass': bad['status'] = 'RUNNING'
            self.assertFalse(DRIVER.browser_report_valid(bad, IDENTITY), mutation)

    def test_failure_and_cleanup_never_publish_private_strings(self):
        failed = {'stage': 'submit', 'error_class': 'TimeoutError', 'required_case_incomplete': True}
        self.assertTrue(DRIVER.evidence_valid(failed, failure=True))
        for key, value in (('stage', 'PRIVATE-URI'), ('error_class', 'PRIVATE-ERROR'), ('required_case_incomplete', 1), ('message', 'PRIVATE')):
            bad = dict(failed); bad[key] = value
            self.assertFalse(DRIVER.evidence_valid(bad, failure=True))
        cleaned = dict.fromkeys(DRIVER.CLEANUP_KEYS, True)
        self.assertTrue(DRIVER.cleanup_valid(cleaned))
        self.assertTrue(DRIVER.case_valid({'id': DRIVER.CLEANUP_ID, 'status': 'PASS', 'evidence': cleaned}))
        self.assertFalse(DRIVER.case_valid({'id': DRIVER.CLEANUP_ID, 'status': 'PASS', 'evidence': dict(cleaned, cleanup_restored=False)}))
        for key in DRIVER.CLEANUP_KEYS:
            bad = dict(cleaned); del bad[key]
            self.assertFalse(DRIVER.cleanup_valid(bad))

    def test_effective_native_method_distinguishes_final_posts_from_updates(self):
        checkout = '/?rest_route=/wc/store/v1/checkout'
        args = ",'POST','{}',state.store_extensions_url,state.checkout_url,"
        self.assertEqual(1, helpers('nativeCheckoutPosts(' + json.dumps(checkout) + args + '{})'))
        self.assertEqual(0, helpers('nativeCheckoutPosts(' + json.dumps(checkout) + args + "{'X-HTTP-Method-Override':'PUT'})"))
        self.assertEqual(0, helpers('nativeCheckoutPosts(' + json.dumps(checkout) + args + "{'x-http-method-override':'PATCH'})"))
        self.assertEqual(1, helpers('nativeCheckoutPosts(' + json.dumps(checkout + '&_method=POST') + args + "{'x-http-method-override':'PUT'})"))
        for url, headers in ((checkout + '&_method=PUT&_method=POST', {}), (checkout, {'X-HTTP-Method-Override': 'POST'}), (checkout, {'x-http-method-override': 'PUT,POST'}), (checkout + '&_method[]=POST', {}), ('http://127.0.0.1:8086/?rest_route=/wc/store/v1/checkout', {})):
            self.assertIsNone(helpers('checkoutSelection(' + json.dumps(url) + ",'POST'," + json.dumps(headers) + ",'{}')"))

    def test_actual_final_batch_response_is_correlated_to_same_index(self):
        batch = {'requests': [{'path': '/wc/store/v1/cart', 'method': 'GET', 'body': {}}, {'path': '/wc/store/v1/checkout', 'method': 'POST', 'body': {}}]}
        selection = helpers("checkoutSelection('/?rest_route=/wc/store/v1/batch','POST',{}," + json.dumps(json.dumps(batch)) + ')')
        self.assertEqual({'transport': 'batch', 'index': 1, 'count': 2}, selection)
        del batch['requests'][0]['body']
        self.assertEqual(selection, helpers("checkoutSelection('/?rest_route=/wc/store/v1/batch','POST',{}," + json.dumps(json.dumps(batch)) + ')'))
        returned = {'payment_result': {'payment_status': 'success'}}
        payload = {'responses': [{'status': 200, 'headers': {}, 'body': {'private_sibling': 'UNOBSERVED'}}, {'status': 200, 'headers': {}, 'body': returned}]}
        self.assertEqual(returned, helpers('checkoutResponse(' + json.dumps(selection) + ',' + json.dumps(payload) + ',207)'))
        self.assertIsNone(helpers('checkoutResponse(' + json.dumps(selection) + ',' + json.dumps(payload) + ',200)'))
        payload['responses'][1]['status'] = 500
        self.assertIsNone(helpers('checkoutResponse(' + json.dumps(selection) + ',' + json.dumps(payload) + ',207)'))
        batch['requests'].append(copy.deepcopy(batch['requests'][1]))
        self.assertIsNone(helpers("checkoutSelection('/?rest_route=/wc/store/v1/batch','POST',{}," + json.dumps(json.dumps(batch)) + ')'))

    def test_actual_route_correlation_refuses_foreign_ambiguous_and_unbounded_requests(self):
        checkout = '/?rest_route=/wc/store/v1/checkout'
        for observed in ('http://foreign.invalid/?rest_route=/wc/store/v1/checkout', checkout + '&rest_route=/other', checkout + '#hidden', 'http://user@127.0.0.1:8085' + checkout):
            self.assertIsNone(helpers('checkoutSelection(' + json.dumps(observed) + ",'POST',{},'{}')"))
        for requests in ([], [None], [{'path': 'https://foreign.invalid/wc/store/v1/checkout', 'method': 'POST', 'body': {}}], [{'path': '/wc/store/v1/batch', 'method': 'POST', 'body': {}}], [{'path': '/wc/store/v1/cart', 'method': 'GET', 'body': {}}] * 26):
            self.assertIsNone(helpers("checkoutSelection('/?rest_route=/wc/store/v1/batch','POST',{}," + json.dumps(json.dumps({'requests': requests})) + ')'))
        self.assertIsNone(helpers("checkoutSelection('/?rest_route=/wc/store/v1/batch','POST',{},JSON.stringify({requests:[{path:'/wc/store/v1/checkout',method:'POST',body:{padding:'x'.repeat(262145)}}]}))"))


if __name__ == '__main__':
    unittest.main()
