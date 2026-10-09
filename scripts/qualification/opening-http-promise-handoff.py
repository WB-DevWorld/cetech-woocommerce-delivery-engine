#!/usr/bin/env python3
"""Actual P04 customer requests; retained proofs remain separate prerequisites."""
import argparse
import ast
import datetime
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time
import uuid
sys.dont_write_bytecode = True

SOURCE = Path(__file__).resolve().parent
CONTEXT = "Authenticated P04 native customer routes and real Blocks final buttons; separate Refresh, Confirm and checkout requests"
BACKGROUND = "WP Cron disabled; Action Scheduler async request runner suppressed in this process"
LIMITS = [
    "Marked disposable loopback WordPress with explicit internal P02 publication and P04 adoption; default adoption remains OFF.",
    "Actual separate authenticated Refresh, Confirm, Classic, Store API and saved-order payment requests plus pinned Chromium Blocks final buttons.",
    "Only the registered no-capacity adapter and instrumented native fixture gateways are exercised; external gateways, deployed themes and persistent caches remain separate.",
    "Retained 495/20/143, P02 19, P03 49 and separate P04 HPOS/CPT primary receipts must independently pass on the same installed source.",
]
PRIOR = {
    "native": ("opening-qualification-results.json", 495),
    "cpt": ("opening-quote-placement-cpt-results.json", 20),
    "http": ("opening-http-qualification-results.json", 143),
    "p02": ("opening-promise-storage-results.json", 19),
    "p03": ("opening-promise-calculation-results.json", 49),
    "p04_hpos": ("opening-promise-handoff-results.json", None),
    "p04_cpt": ("opening-promise-handoff-cpt-results.json", None),
}
IDENTITY_KEYS = ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash")


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    result = importlib.util.module_from_spec(spec)
    sys.modules[name] = result
    spec.loader.exec_module(result)
    return result


def protocol():
    """Derive the closed inventory from literal observed checks, never receipt data."""
    result = []
    for node in ast.walk(ast.parse(Path(__file__).read_text())):
        if isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute) and isinstance(node.func.value, ast.Name) and node.func.value.id == "recorder" and node.func.attr == "check" and len(node.args) == 3 and isinstance(node.args[0], ast.Constant) and isinstance(node.args[0].value, str) and node.args[0].value.startswith("HTTP-W2P04-"):
            if not isinstance(node.args[2], ast.Dict) or not node.args[2].keys or any(not isinstance(key, ast.Constant) or not isinstance(key.value, str) or not re.fullmatch(r"[a-z0-9_]+", key.value) for key in node.args[2].keys):
                raise ValueError("Ambiguous P04 HTTP source observations")
            result.append((node.lineno, node.args[0].value, tuple(key.value for key in node.args[2].keys)))
    result.sort()
    if len(result) != 14 or len({item[1] for item in result}) != 14 or any(len(fields) != len(set(fields)) for _, _, fields in result):
        raise ValueError("Ambiguous P04 HTTP source inventory")
    return tuple((case_id, fields) for _, case_id, fields in result)


def case_valid(case):
    expected = dict(protocol())
    return isinstance(case, dict) and set(case) == {"id", "status", "evidence"} and case.get("id") in expected and case.get("status") in {"PASS", "FAIL"} and isinstance(case.get("evidence"), dict) and set(case["evidence"]) == set(expected[case["id"]]) and all(type(value) is bool for value in case["evidence"].values()) and (case["status"] != "PASS" or all(case["evidence"].values()))


def load_private(path):
    path = Path(path)
    metadata = path.lstat()
    if not stat.S_ISREG(metadata.st_mode) or stat.S_IMODE(metadata.st_mode) != 0o600 or metadata.st_size > 16777216:
        raise RuntimeError("P04 private fixture file refused")
    return json.loads(path.read_text())


def prerequisites(work):
    prior = {}
    report = None
    for kind, (name, count) in PRIOR.items():
        path = Path(work) / name
        if path.is_symlink() or not path.is_file() or path.stat().st_size > 16777216:
            raise RuntimeError("P04 requires original complete preceding receipts")
        observed = json.loads(path.read_text())
        if report is None:
            report = observed
        if observed.get("status") != "PASS" or not isinstance(observed.get("cases"), list) or not observed["cases"] or (count is not None and len(observed["cases"]) != count) or any(case.get("status") != "PASS" for case in observed["cases"]):
            raise RuntimeError("P04 cannot compensate for incomplete preceding qualification")
        if any(observed.get(key) != report.get(key) for key in IDENTITY_KEYS + ("installed_php_sources",)):
            raise RuntimeError("P04 preceding source identity differs")
        prior[kind] = {"sha256": hashlib.sha256(path.read_bytes()).hexdigest(), "cases": len(observed["cases"])}
    for key, env in (("source_head", "CETECH_DE_QUALIFICATION_HEAD"), ("candidate_head", "CETECH_DE_QUALIFICATION_CANDIDATE_HEAD"), ("source_tree", "CETECH_DE_QUALIFICATION_TREE")):
        if not re.fullmatch(r"[0-9a-f]{40}", str(report.get(key, ""))) or report[key] != os.environ.get(env):
            raise RuntimeError("P04 immutable source authority differs")
    sources = report.get("installed_php_sources")
    if not isinstance(sources, dict) or not sources or list(sources) != sorted(sources) or report["installed_php_sources_hash"] != hashlib.sha256(json.dumps(sources, separators=(",", ":"), ensure_ascii=False).encode()).hexdigest():
        raise RuntimeError("P04 source map is not canonical")
    return report, prior


class Recorder:
    def __init__(self, path, report):
        self.path = Path(path)
        self.report = report
        self.write()

    def write(self):
        temporary = self.path.with_suffix(".tmp")
        if temporary.is_symlink() or self.path.is_symlink():
            raise RuntimeError("P04 receipt allocation refused")
        temporary.write_text(json.dumps(self.report, indent=2) + "\n")
        temporary.replace(self.path)

    def check(self, case_id, condition, evidence):
        case = {"id": case_id, "status": "PASS" if condition and all(value is True for value in evidence.values()) else "FAIL", "evidence": evidence}
        if not case_valid(case) or any(item["id"] == case_id for item in self.report["cases"]):
            raise RuntimeError("P04 receipt case schema refused")
        self.report["cases"].append(case)
        self.write()
        print("opening_http_promise_case=" + case_id + " result=" + case["status"], flush=True)
        if case["status"] != "PASS":
            raise RuntimeError("P04 actual customer case diverged")


def initialize(work, receipt):
    primary, prior = prerequisites(work)
    environment = primary["environment"]
    report = {"format": "cetech-opening-http-promise-handoff-v1", **{key: primary[key] for key in IDENTITY_KEYS}, "installed_php_sources": primary["installed_php_sources"],
              "environment": {**{key: environment[key] for key in ("php", "wordpress", "woocommerce", "database_version", "hpos", "schema_before")}, "context": CONTEXT, "background_requests": BACKGROUND},
              "limits": LIMITS, "preceding_receipts": prior, "browser_runtime": {}, "status": "RUNNING", "cases": []}
    return Recorder(receipt, report)


def run(options, recorder):
    common = module("p04_retained_http_client", SOURCE / "opening-http-driver.py")
    q06 = module("p04_retained_http_utilities", SOURCE / "opening-http-quote-placement.py")
    state = load_private(options.state)
    if any(state.get("identity", {}).get(key) != recorder.report[key] for key in IDENTITY_KEYS + ("installed_php_sources",)) or state.get("site_path") != str(Path(options.site).resolve()) or not state.get("p04", {}).get("active"):
        raise RuntimeError("P04 prepared fixture identity refused")
    client = common.HttpClient(state["base_url"])
    headers = {"X-Cetech-P04-Fixture": state["fixture_token"], "X-Cetech-Q06-Fixture": state["fixture_token"]}
    bridge_dirs = {}
    for name in ("cart", "placement", "promise"):
        bridge_dirs[name] = Path(options.private) / name
        bridge_dirs[name].mkdir(mode=0o700)
    bridges = {name: common.FixtureBridge(options.php, options.wpcli, options.site, str(SOURCE / "admin-context.php"), str(SOURCE / source), Path(options.state), bridge_dirs[name]) for name, source in (("cart", "opening-http-quote-cart-fixture.php"), ("placement", "opening-http-quote-placement-support.php"), ("promise", "opening-http-promise-handoff-support.php"))}
    failure = None
    try:
        response = client.get("/?cetech_opening_http_probe=1", {"X-CETECH-Opening-Probe": state["probe_token"]})
        observed = json.loads(response.body)
        runtime = observed.pop('runtime', None)
        pinned_runtime = isinstance(runtime, dict) and set(runtime) == {'sapi', 'php_version', 'binary_sha256', 'ini_sha256', 'original_ini_sha256', 'extensions_sha256', 'jit_disabled_with_opcache'} and runtime['sapi'] == 'cli-server' and runtime['php_version'] == recorder.report['environment']['php']
        if pinned_runtime:
            for key, variable in (('binary_sha256', 'CETECH_DE_HTTP_EXPECT_BINARY_SHA256'), ('ini_sha256', 'CETECH_DE_HTTP_EXPECT_INI_SHA256'), ('original_ini_sha256', 'CETECH_DE_HTTP_EXPECT_ORIGINAL_INI_SHA256'), ('extensions_sha256', 'CETECH_DE_HTTP_EXPECT_EXTENSIONS_SHA256')):
                expected_hash = os.environ.get(variable)
                pinned_runtime = pinned_runtime and re.fullmatch(r'[0-9a-f]{64}', str(runtime[key])) is not None and (not expected_hash or runtime[key] == expected_hash)
            if os.environ.get('CETECH_DE_HTTP_LISTENER_JIT') == 'disable':
                pinned_runtime = pinned_runtime and runtime['jit_disabled_with_opcache'] is True
        expected = {"format": "cetech-opening-http-owned-listener-v1", "probe_sha256": hashlib.sha256(state["probe_token"].encode()).hexdigest(), "site_path_sha256": hashlib.sha256(state["site_path"].encode()).hexdigest(), "database_name_sha256": hashlib.sha256(state["database_name"].encode()).hexdigest(), **{key: state["identity"][key] for key in IDENTITY_KEYS[:3]}}
        recorder.check('HTTP-W2P04-OWNED-LISTENER', response.status == 200 and observed == expected and pinned_runtime, {'owned_token_before_credentials': observed == expected, 'exact_disposable_source_identity': observed == expected, 'observed_native_listener': response.status == 200, 'actual_pinned_listener_runtime': pinned_runtime})
        client.get("/wp-login.php")
        response = client.post("/wp-login.php", {"log": state["username"], "pwd": state["password"], "wp-submit": "Log In", "redirect_to": state["classic_page_url"], "testcookie": "1"})

        def inspect():
            response = client.get("/?cetech_p04_fixture=inspect", headers)
            value = json.loads(response.body)
            if response.status != 200 or value.get("success") is not True or not q06.counts(value.get("data", {}).get("counts")) or any(value["data"].get("source_identity", {}).get(key) != state["identity"][key] for key in IDENTITY_KEYS):
                raise RuntimeError("P04 authenticated private observation refused")
            return value["data"]

        baseline = inspect()
        recorder.check('HTTP-W2P04-AUTHENTICATED-SHOPPER', response.status in (302, 303) and baseline['native_user_id'] == state['user_id'], {'native_wordpress_login_cookie': response.status in (302, 303) and any(cookie.name.startswith('wordpress_logged_in_') for cookie in client.cookies), 'exact_owned_customer_principal': baseline['native_user_id'] == state['user_id'], 'fresh_authenticated_request': baseline['source_identity'] == {key: state['identity'][key] for key in IDENTITY_KEYS}})
        recorder.check('HTTP-W2P04-CONFIGURED-OFF-EXPLICIT-ADOPTION-ON', state['p04']['configured_off'] and baseline['adoption']['enabled'], {'protected_configuration_acknowledged_off': state['p04']['configured_off'] is True, 'explicit_revisioned_adoption_on': baseline['adoption']['enabled'] is True and baseline['adoption']['revision'] == 2, 'real_published_calendar_policy_assignment': baseline['p04_source_counts']['promise_objects'] == 2 and baseline['p04_source_counts']['promise_versions'] == 2 and baseline['p04_source_counts']['promise_assignments'] == 1})

        def fixture(mode, promise=False):
            before = inspect()
            endpoint = "p04" if promise else "q06"
            nonce = before["p04_fixture_nonce" if promise else "nonce"]
            response = client.request("/?cetech_" + endpoint + "_fixture=" + mode, {"nonce": nonce}, headers)
            value = json.loads(response.body)
            if response.status != 200 or value.get("success") is not True:
                raise RuntimeError("P04 tracked setup command refused")
            return inspect()

        def review(free=False):
            seeded = fixture("free" if free else "seed")
            started = time.monotonic()
            while seeded["budget_attempts"] >= 19:
                if time.monotonic() - started > 65:
                    raise RuntimeError("P04 original preparation window did not advance")
                time.sleep(min(1.0, max(0.05, seeded["budget_window_remaining_ms"] / 1000)))
                seeded = inspect()
            response = client.request(state["classic_url"], {"_wpnonce": seeded["review_nonce"], "action": "refresh", "generation": seeded["facts"]["generation"], "review_token": str(uuid.uuid4())})
            value = json.loads(response.body)
            facts = value.get("data", {})
            if response.status != 200 or value.get("success") is not True or facts.get("status") != "review_required" or facts.get("can_confirm") is not True:
                raise RuntimeError("P04 actual separate Refresh refused")
            response = client.request(state["classic_url"], {"_wpnonce": inspect()["review_nonce"], "action": "confirm", "generation": facts["generation"]})
            value = json.loads(response.body)
            if response.status != 200 or value.get("success") is not True or value.get("data", {}).get("status") != "confirmed":
                raise RuntimeError("P04 actual fresh-request Confirm refused")
            return inspect()

        address = {"first_name": "Synthetic", "last_name": "Shopper", "company": "", "address_1": "PRIVATE-Q04-NATIVE-FIXTURE-ADDRESS", "address_2": "", "city": "Accra", "state": "AA", "postcode": "00001", "country": "GH", "phone": "0200000000"}

        def classic(free=False):
            rendered = client.get(state["classic_page_url"])
            forms = [form for form in rendered.page().forms if "woocommerce-process-checkout-nonce" in form.fields]
            if rendered.status != 200 or len(forms) != 1:
                raise RuntimeError("P04 native Classic form refused")
            fields = dict(forms[0].fields)
            fields.update({"billing_" + key: value for key, value in address.items()})
            fields.update({"billing_email": "q06@example.invalid", "payment_method": "" if free else "cetech_q06_local_gateway", "terms": "1", "terms-field": "1"})
            fields.pop("ship_to_different_address", None)
            before = inspect()
            fields.update({"shipping_method[" + str(index) + "]": method for index, method in enumerate(before["chosen_methods"])})
            response = client.post("/?wc-ajax=checkout", fields)
            return before, inspect(), response, json.loads(response.body)

        def store(free=False):
            before = inspect()
            response = client.json_post(state["checkout_url"], {"billing_address": dict(address, email="q06@example.invalid"), "shipping_address": address, "payment_method": "" if free else "cetech_q06_local_gateway", "payment_data": [], "customer_note": ""}, {"Nonce": before["store_nonce"]})
            return before, inspect(), response, json.loads(response.body)

        def placement(transport, free=False):
            review(free)
            before, after, response, result = classic(free) if transport == "classic" else store(free)
            order = after["orders"].get(str(after["last_order_id"]), {})
            native_success = response.status == 200 and (result.get("result") == "success" if transport == "classic" else result.get("payment_result", {}).get("payment_status") == "success" and type(result.get('order_id')) is int and result['order_id'] == after['last_order_id'])
            exact_quote = before['facts'].get('quote', {}).get('quote_id') == order.get('quote_id') and re.fullmatch(r'[0-9a-f-]{36}', str(order.get('quote_id', ''))) is not None
            exact_effect = after['counts']['orders'] == before['counts']['orders'] + 1 and str(after['last_order_id']) not in before['orders'] and after['counts']['sealed'] == before['counts']['sealed'] + 1 and order.get('binding_state') == 'sealed' and order.get('binding_revision') == 3
            return native_success and exact_quote and exact_effect, before, after, order

        success, before, after, order = placement('classic')
        recorder.check('HTTP-W2P04-CLASSIC-PAID-REQUIRED-PACKET-SEALED', success and order.get('paid') is True, {'actual_separate_refresh_confirm_checkout': success, 'saved_outer3_marker2_packet': order.get('outer3') is True and order.get('marker2') is True and order.get('required_packet_present') is True, 'exact_confirmed_quote_and_new_seal': success, 'actual_promise_profile': order.get('promise_profile') is True, 'original_q06_seal_linked': order.get('original_seal_linked') is True, 'gateway_exactly_once_after_seal': after['counts']['gateway_calls'] == before['counts']['gateway_calls'] + 1 and not after['gateway_before_seal'], 'native_payment_completed': order.get('paid') is True})
        success, before, after, order = placement('classic', True)
        recorder.check('HTTP-W2P04-CLASSIC-FREE-REQUIRED-PACKET-SEALED', success and order.get('paid') is True, {'actual_separate_refresh_confirm_checkout': success, 'saved_outer3_marker2_packet': order.get('outer3') is True and order.get('marker2') is True and order.get('required_packet_present') is True, 'exact_confirmed_quote_and_new_seal': success, 'actual_promise_profile': order.get('promise_profile') is True, 'original_q06_seal_linked': order.get('original_seal_linked') is True, 'free_completion_exactly_once_after_seal': after['counts']['free_completion_calls'] == before['counts']['free_completion_calls'] + 1 and not after['completion_before_seal'], 'native_free_order_completed': order.get('paid') is True})
        success, before, after, order = placement('store')
        recorder.check('HTTP-W2P04-STOREAPI-PAID-REQUIRED-PACKET-SEALED', success and order.get('paid') is True, {'actual_separate_refresh_confirm_final_post': success, 'saved_outer3_marker2_packet': order.get('outer3') is True and order.get('marker2') is True and order.get('required_packet_present') is True, 'exact_confirmed_quote_and_new_seal': success, 'actual_promise_profile': order.get('promise_profile') is True, 'original_q06_seal_linked': order.get('original_seal_linked') is True, 'gateway_exactly_once_after_seal': after['counts']['gateway_calls'] == before['counts']['gateway_calls'] + 1 and not after['gateway_before_seal'], 'native_payment_completed': order.get('paid') is True})
        success, before, after, order = placement('store', True)
        recorder.check('HTTP-W2P04-STOREAPI-FREE-REQUIRED-PACKET-SEALED', success and order.get('paid') is True, {'actual_separate_refresh_confirm_final_post': success, 'saved_outer3_marker2_packet': order.get('outer3') is True and order.get('marker2') is True and order.get('required_packet_present') is True, 'exact_confirmed_quote_and_new_seal': success, 'actual_promise_profile': order.get('promise_profile') is True, 'original_q06_seal_linked': order.get('original_seal_linked') is True, 'free_completion_exactly_once_after_seal': after['counts']['free_completion_calls'] == before['counts']['free_completion_calls'] + 1 and not after['completion_before_seal'], 'native_free_order_completed': order.get('paid') is True})

        def held():
            review()
            fixture("hold")
            _, after, response, result = classic()
            order_id = after["last_order_id"]
            order = after["orders"].get(str(order_id), {})
            if response.status != 200 or result.get("result") != "success" or order.get("binding_state") != "sealed" or order.get("original_seal_linked") is not True or order.get("paid") is not False:
                raise RuntimeError("P04 original sealed unpaid setup refused")
            fixture("release")
            fixture("empty")
            return order_id

        def pay_fields(order_id):
            target = inspect()["orders"][str(order_id)]["order_pay_url"]
            rendered = client.get(target)
            forms = [form for form in rendered.page().forms if "woocommerce-pay-nonce" in form.fields]
            if rendered.status != 200 or len(forms) != 1:
                raise RuntimeError("P04 native original order-pay form refused")
            return target, dict(forms[0].fields, payment_method="cetech_q06_alternate_gateway", woocommerce_pay="1")

        order_id = held(); target, fields = pay_fields(order_id); before = inspect()
        response = client.post(target, fields); after = inspect(); order = after['orders'][str(order_id)]
        recorder.check('HTTP-W2P04-ORDERPAY-EMPTY-CART-ORIGINAL-SEAL', response.status in (302, 303) and order['paid'], {'actual_native_saved_order_post': response.status in (302, 303), 'original_packet_and_money_preserved': q06.preserved(before, after, order_id), 'original_q06_seal_linked': order['original_seal_linked'] is True, 'no_new_quote_or_acceptance': all(before['history_counts'][key] == after['history_counts'][key] for key in ('quotes', 'accepted')), 'gateway_exactly_once': after['counts']['gateway_calls'] == before['counts']['gateway_calls'] + 1, 'native_original_order_paid': order['paid'] is True})
        order_id = held(); target, fields = pay_fields(order_id); fixture('adoption_off', True); before = inspect()
        response = client.post(target, fields); after = inspect(); order = after['orders'][str(order_id)]
        recorder.check('HTTP-W2P04-ADOPTION-OFF-SAVED-ORDER-STAYS-PROTECTED', response.status == 303 and q06.no_payment(before, after), {'revisioned_adoption_off': before['adoption']['enabled'] is False and after['adoption']['enabled'] is False, 'actual_native_payment_post_denied': response.status == 303, 'no_legacy_gateway_fallback': q06.no_payment(before, after), 'historical_promise_packet_readable': order['history_supported'] is True and order['promise_profile'] is True, 'original_packet_and_money_preserved': q06.preserved(before, after, order_id), 'original_q06_seal_linked': order['original_seal_linked'] is True})
        fixture('adoption_on', True); fixture('deadline_policy', True); order_id = held(); target, fields = pay_fields(order_id); before = inspect()
        deadline = datetime.datetime.strptime(before['orders'][str(order_id)]['promise_deadline'], '%Y-%m-%d %H:%M:%S.%f').replace(tzinfo=datetime.timezone.utc).timestamp()
        original_expiry = datetime.datetime.strptime(before['orders'][str(order_id)]['quote_expires_at'], '%Y-%m-%d %H:%M:%S.%f').replace(tzinfo=datetime.timezone.utc).timestamp()
        if not 0 < deadline - time.time() <= 125:
            raise RuntimeError('P04 actual original policy deadline is not bounded')
        while time.time() <= deadline + 0.05:
            time.sleep(min(0.5, max(0.01, deadline + 0.06 - time.time())))
        response = client.post(target, fields); after = inspect(); order = after['orders'][str(order_id)]
        recorder.check('HTTP-W2P04-ORIGINAL-PROMISE-DEADLINE-NO-PAYMENT', response.status == 303 and q06.no_payment(before, after), {'actual_published_policy_deadline_elapsed': time.time() >= deadline, 'original_quote_still_unexpired': deadline < original_expiry and time.time() < original_expiry, 'actual_native_original_payment_post_denied': response.status == 303, 'no_gateway_or_free_completion': q06.no_payment(before, after), 'original_packet_and_money_preserved': q06.preserved(before, after, order_id), 'original_historical_packet_readable': order['history_supported'] is True, 'no_quote_reissue_or_acceptance': all(before['history_counts'][key] == after['history_counts'][key] for key in ('quotes', 'accepted'))})
        fixture('normal_policy', True)
        bridges['cart'].call('blockspagequotecart')
        cookie_path = Path(state['browser_state_path']); q06.write_browser_cookie_handoff(client, state, cookie_path)
        browser_path = Path(options.private) / 'promise-browser-results.json'
        result = subprocess.run(['node', str(SOURCE / 'promise-handoff-browser.cjs'), '--state', str(cookie_path), '--receipt', str(browser_path), '--base-url', client.base_url], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=180, check=False)
        if not browser_path.is_file() or browser_path.stat().st_size > 65536:
            raise RuntimeError('P04 bounded actual browser receipt missing')
        browser = json.loads(browser_path.read_text())
        expected_keys = {'format', *IDENTITY_KEYS, 'runtime', 'status', 'cases'}
        if set(browser) != expected_keys or browser['format'] != 'cetech-w2p04-promise-browser-v1' or any(browser[key] != state['identity'][key] for key in IDENTITY_KEYS) or browser['runtime'] != {'playwright': '1.58.2', 'chromium': '145.0.7632.6'} or len(browser['cases']) != 2 or any(not case_valid(case) for case in browser['cases']):
            raise RuntimeError('P04 actual browser identity or closed protocol refused')
        recorder.report['browser_runtime'] = browser['runtime']; recorder.write()
        paid_browser, free_browser = browser['cases']
        paid = paid_browser['evidence']; free = free_browser['evidence']
        recorder.check('HTTP-W2P04-BLOCKS-PAID-ACTUAL-FINAL-BUTTON', result.returncode == 0 and paid_browser['id'] == 'HTTP-W2P04-BLOCKS-PAID-ACTUAL-FINAL-BUTTON' and paid_browser['status'] == 'PASS', {'real_pinned_chromium': paid['real_pinned_chromium'], 'actual_blocks_final_button': paid['actual_blocks_final_button'], 'separate_native_refresh_confirm_final_post': paid['separate_native_refresh_confirm_final_post'], 'exactly_one_checkout_post': paid['exactly_one_checkout_post'], 'safe_native_shopper_response': paid['safe_native_shopper_response'], 'exact_confirmed_quote_and_new_seal': paid['exact_confirmed_quote_and_new_seal'], 'actual_promise_profile_packet': paid['actual_promise_profile_packet'], 'original_q06_seal_linked': paid['original_q06_seal_linked'], 'gateway_exactly_once_after_seal': paid['gateway_exactly_once_after_seal'], 'native_order_paid': paid['native_order_paid'], 'same_installed_source': paid['same_installed_source']})
        recorder.check('HTTP-W2P04-BLOCKS-FREE-ACTUAL-FINAL-BUTTON', result.returncode == 0 and free_browser['id'] == 'HTTP-W2P04-BLOCKS-FREE-ACTUAL-FINAL-BUTTON' and free_browser['status'] == 'PASS', {'real_pinned_chromium': free['real_pinned_chromium'], 'actual_blocks_final_button': free['actual_blocks_final_button'], 'separate_native_refresh_confirm_final_post': free['separate_native_refresh_confirm_final_post'], 'exactly_one_checkout_post': free['exactly_one_checkout_post'], 'safe_native_shopper_response': free['safe_native_shopper_response'], 'exact_confirmed_quote_and_new_seal': free['exact_confirmed_quote_and_new_seal'], 'actual_promise_profile_packet': free['actual_promise_profile_packet'], 'original_q06_seal_linked': free['original_q06_seal_linked'], 'free_completion_exactly_once_after_seal': free['free_completion_exactly_once_after_seal'], 'native_order_completed': free['native_order_completed'], 'same_installed_source': free['same_installed_source']})
    except Exception as error:
        failure = error
        recorder.report['status'] = 'FAIL'; recorder.report['error'] = {'class': type(error).__name__ if type(error).__name__ in {'RuntimeError', 'ValueError', 'TypeError', 'KeyError', 'TimeoutExpired', 'JSONDecodeError'} else 'OtherError'}; recorder.write()
    finally:
        try:
            placement = bridges['placement'].call('cleanupplacement')
            promise = bridges['promise'].call('cleanuppromise')
            cart = bridges['cart'].call('cleanupquotecart')
            recorder.check('HTTP-W2P04-TRACKED-FIXTURE-CLEANUP', all(value is True for value in placement.values()) and all(value is True for value in promise.values()) and all(value is True for value in cart.values()), {'exact_owned_native_orders_and_bindings_removed': placement.get('owned_native_orders_removed') is True and placement.get('exact_owned_placement_namespaces_removed') is True, 'original_promise_history_restored': promise.get('original_promise_history_restored') is True, 'protected_adoption_option_restored': promise.get('adoption_restored') is True, 'exact_owned_promise_namespaces_removed': promise.get('exact_owned_namespaces_removed') is True, 'native_sources_users_pages_options_restored': cart.get('cleanup_restored') is True, 'all_owned_connections_retired': placement.get('owned_connections_retired') is True and cart.get('all_owned_connections_retired') is True})
        except Exception as cleanup_error:
            failure = failure or cleanup_error
    recorder.report['status'] = 'FAIL' if failure else 'RUNNING'; recorder.write()
    if failure:
        raise RuntimeError('P04 HTTP qualification incomplete; preserve the closed failed receipt') from failure


def finalize(receipt, listener_stopped, mu_removed, private_removed, tracked_cleanup):
    report = json.loads(Path(receipt).read_text())
    recorder = Recorder(receipt, report)
    try:
        recorder.check('HTTP-W2P04-OWNED-LISTENER-AND-PRIVATE-CLEANUP', listener_stopped and mu_removed and private_removed and tracked_cleanup, {'owned_listener_stopped_and_waited': listener_stopped, 'exact_owned_mu_removed': mu_removed, 'private_credentials_and_logs_removed': private_removed, 'tracked_database_cleanup_completed': tracked_cleanup})
    finally:
        expected_ids = [case_id for case_id, _ in protocol()]
        report['status'] = 'PASS' if report.get('status') != 'FAIL' and [case['id'] for case in report['cases']] == expected_ids and all(case_valid(case) and case['status'] == 'PASS' for case in report['cases']) and report['browser_runtime'] == {'playwright': '1.58.2', 'chromium': '145.0.7632.6'} else 'FAIL'
        recorder.write()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--work', required=True); parser.add_argument('--receipt', required=True)
    parser.add_argument('--initialize', action='store_true'); parser.add_argument('--finalize', nargs=4)
    for name in ('php', 'wpcli', 'site', 'state', 'private'):
        parser.add_argument('--' + name)
    options = parser.parse_args()
    if options.initialize:
        initialize(options.work, options.receipt)
    elif options.finalize:
        finalize(options.receipt, *(value == '1' for value in options.finalize))
        return 0 if json.loads(Path(options.receipt).read_text())['status'] == 'PASS' else 1
    else:
        run(options, Recorder(options.receipt, json.loads(Path(options.receipt).read_text())))
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Exception:
        print('opening_http_promise_handoff=FAIL; inspect the closed receipt', file=sys.stderr)
        sys.exit(1)
