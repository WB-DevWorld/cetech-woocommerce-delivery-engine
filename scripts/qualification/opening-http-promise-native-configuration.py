#!/usr/bin/env python3
"""Actual P05 customer requests; retained proofs remain separate prerequisites."""
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
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError
sys.dont_write_bytecode = True

SOURCE = Path(__file__).resolve().parent
CONTEXT = "Authenticated P05 native administration and customer views; protected real page POSTs and actual Blocks checkout buttons"
BACKGROUND = "WP Cron disabled; Action Scheduler async request runner suppressed in this process"
LIMITS = [
    "Marked disposable native WordPress; default promise adoption remains OFF.",
    "Protected native administrative requests and truthful preliminary/final shopper views; real Blocks paid/free final buttons use pinned Chromium.",
    "Only owned native fixture gateways, default cache and pinned theme are exercised; deployed provider/theme/cache certification remains separate.",
    "All eight P01-P04 primary receipts and both P05 native storage modes pass independently on the same installed source.",
]
PRIOR = {
    "native": ("opening-qualification-results.json", 495),
    "cpt": ("opening-quote-placement-cpt-results.json", 20),
    "http": ("opening-http-qualification-results.json", 143),
    "p02": ("opening-promise-storage-results.json", 19),
    "p03": ("opening-promise-calculation-results.json", 49),
    "p04_hpos": ("opening-promise-handoff-results.json", 33),
    "p04_cpt": ("opening-promise-handoff-cpt-results.json", 33),
    "p04_http": ("opening-http-promise-handoff-results.json", 14),
    "p05_hpos": ("opening-promise-native-configuration-results.json", None),
    "p05_cpt": ("opening-promise-native-configuration-cpt-results.json", None),
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
        if isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute) and isinstance(node.func.value, ast.Name) and node.func.value.id == "recorder" and node.func.attr == "check" and len(node.args) == 3 and isinstance(node.args[0], ast.Constant) and isinstance(node.args[0].value, str) and node.args[0].value.startswith("HTTP-W2P05-"):
            if not isinstance(node.args[2], ast.Dict) or not node.args[2].keys or any(not isinstance(key, ast.Constant) or not isinstance(key.value, str) or not re.fullmatch(r"[a-z0-9_]+", key.value) for key in node.args[2].keys):
                raise ValueError("Ambiguous P05 HTTP source observations")
            result.append((node.lineno, node.args[0].value, tuple(key.value for key in node.args[2].keys)))
    result.sort()
    if not result or len(result) > 64 or len({item[1] for item in result}) != len(result) or any(len(fields) != len(set(fields)) for _, _, fields in result):
        raise ValueError("Ambiguous P05 HTTP source inventory")
    return tuple((case_id, fields) for _, case_id, fields in result)


def case_valid(case):
    expected = dict(protocol())
    return isinstance(case, dict) and set(case) == {"id", "status", "evidence"} and case.get("id") in expected and case.get("status") in {"PASS", "FAIL"} and isinstance(case.get("evidence"), dict) and set(case["evidence"]) == set(expected[case["id"]]) and all(type(value) is bool for value in case["evidence"].values()) and (case["status"] != "PASS" or all(case["evidence"].values()))


def load_private(path):
    path = Path(path)
    metadata = path.lstat()
    if not stat.S_ISREG(metadata.st_mode) or stat.S_IMODE(metadata.st_mode) != 0o600 or metadata.st_size > 16777216:
        raise RuntimeError("P05 private fixture file refused")
    return json.loads(path.read_text())


def prerequisites(work):
    prior = {}
    report = None
    for kind, (name, count) in PRIOR.items():
        path = Path(work) / name
        if path.is_symlink() or not path.is_file() or path.stat().st_size > 16777216:
            raise RuntimeError("P05 requires original complete preceding receipts")
        observed = json.loads(path.read_text())
        if report is None:
            report = observed
        if observed.get("status") != "PASS" or not isinstance(observed.get("cases"), list) or not observed["cases"] or (count is not None and len(observed["cases"]) != count) or any(case.get("status") != "PASS" for case in observed["cases"]):
            raise RuntimeError("P05 cannot compensate for incomplete preceding qualification")
        if any(observed.get(key) != report.get(key) for key in IDENTITY_KEYS + ("installed_php_sources",)):
            raise RuntimeError("P05 preceding source identity differs")
        prior[kind] = {"sha256": hashlib.sha256(path.read_bytes()).hexdigest(), "cases": len(observed["cases"])}
    for key, env in (("source_head", "CETECH_DE_QUALIFICATION_HEAD"), ("candidate_head", "CETECH_DE_QUALIFICATION_CANDIDATE_HEAD"), ("source_tree", "CETECH_DE_QUALIFICATION_TREE")):
        if not re.fullmatch(r"[0-9a-f]{40}", str(report.get(key, ""))) or report[key] != os.environ.get(env):
            raise RuntimeError("P05 immutable source authority differs")
    sources = report.get("installed_php_sources")
    if not isinstance(sources, dict) or not sources or list(sources) != sorted(sources) or report["installed_php_sources_hash"] != hashlib.sha256(json.dumps(sources, separators=(",", ":"), ensure_ascii=False).encode()).hexdigest():
        raise RuntimeError("P05 source map is not canonical")
    return report, prior


class Recorder:
    def __init__(self, path, report):
        self.path = Path(path)
        self.report = report
        self.write()

    def write(self):
        temporary = self.path.with_suffix(".tmp")
        if temporary.is_symlink() or self.path.is_symlink():
            raise RuntimeError("P05 receipt allocation refused")
        temporary.write_text(json.dumps(self.report, indent=2) + "\n")
        temporary.replace(self.path)

    def check(self, case_id, condition, evidence):
        case = {"id": case_id, "status": "PASS" if condition and all(value is True for value in evidence.values()) else "FAIL", "evidence": evidence}
        if not case_valid(case) or any(item["id"] == case_id for item in self.report["cases"]):
            raise RuntimeError("P05 receipt case schema refused")
        self.report["cases"].append(case)
        self.write()
        print("opening_http_promise_case=" + case_id + " result=" + case["status"], flush=True)
        if case["status"] != "PASS":
            raise RuntimeError("P05 actual customer case diverged")


def initialize(work, receipt):
    primary, prior = prerequisites(work)
    environment = primary["environment"]
    report = {"format": "cetech-opening-http-promise-native-configuration-v1", **{key: primary[key] for key in IDENTITY_KEYS}, "installed_php_sources": primary["installed_php_sources"],
              "environment": {**{key: environment[key] for key in ("php", "wordpress", "woocommerce", "database_version", "hpos", "schema_before")}, "context": CONTEXT, "background_requests": BACKGROUND},
              "limits": LIMITS, "preceding_receipts": prior, "browser_runtime": {}, "status": "RUNNING", "cases": []}
    return Recorder(receipt, report)


def safe_promise(value):
    if not isinstance(value, dict) or set(value) != {'contract_version','original','groups'} or type(value['contract_version']) is not int or value['contract_version'] != 1 or value['original'] is not True or not isinstance(value['groups'], list) or not 0 < len(value['groups']) <= 200:
        return False
    for group in value['groups']:
        if not isinstance(group, dict) or set(group) != {'views','customer_text'} or not isinstance(group['views'], list) or not 0 < len(group['views']) <= 16 or not isinstance(group['customer_text'], str) or not 0 < len(group['customer_text'].encode()) <= 2048 or re.search(r'[\x00-\x1f\x7f<>]',group['customer_text']):
            return False
        for view in group['views']:
            if not isinstance(view, dict): return False
            state = view.get('state')
            fields = {'format_version','service_label','state','display_timezone','reason_codes'}
            fields |= {'from','until'} if state == 'absolute_window' else {'relative_explanation','min','max','unit','known_zero'} if state == 'relative_window' else set()
            reasons={'estimate_unavailable','missing_source','unsupported_policy','capacity_unknown','capacity_unavailable','capacity_stale','outside_service_window','missing_destination','unsupported_anchor','budget_exceeded','unknown_timezone','source_changed','acceptance_expired'}
            if state not in {'absolute_window','relative_window','unavailable','ineligible'} or set(view) != fields or type(view['format_version']) is not int or view['format_version'] != 1 or not isinstance(view['service_label'], str) or not 0 < len(view['service_label']) <= 160 or re.search(r'[\x00-\x1f\x7f<>]',view['service_label']) or not isinstance(view['display_timezone'], str) or not 0 < len(view['display_timezone']) <= 128 or not re.fullmatch(r'[A-Za-z0-9_+\-/]+',view['display_timezone']) or not isinstance(view['reason_codes'], list) or any(not isinstance(reason,str) or reason not in reasons for reason in view['reason_codes']) or view['reason_codes'] != sorted(set(view['reason_codes'])): return False
            try: ZoneInfo(view['display_timezone'])
            except (ZoneInfoNotFoundError,ValueError): return False
            if (state in {'absolute_window','relative_window'}) != (view['reason_codes'] == []): return False
            if state == 'absolute_window' and (not all(isinstance(view[key], str) and re.fullmatch(r'\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}', view[key]) for key in ('from','until')) or view['from'] > view['until']): return False
            if state == 'absolute_window':
                try:
                    for key in ('from','until'): datetime.datetime.strptime(view[key],'%Y-%m-%d %H:%M:%S.%f')
                except ValueError:return False
            if state == 'relative_window' and (view['relative_explanation'] != 'after_payment_confirmation' or type(view['min']) is not int or type(view['max']) is not int or view['min'] < 0 or not view['min'] <= view['max'] <= 9007199254740991 or view['unit'] not in {'elapsed_minutes','calendar_days','business_minutes','business_days'} or type(view['known_zero']) is not bool or view['known_zero'] != (view['min'] == 0 and view['max'] == 0)): return False
    return not any(marker in json.dumps(value) for marker in ('policy_id','source_id','principal_hash','material_digest','origin_id','supplier_id','input_json','result_json','component_key','capacity_source'))


def run(options, recorder):
    common = module('p05_native_http_client', SOURCE / 'opening-http-driver.py')
    q06 = module('p05_native_http_utilities', SOURCE / 'opening-http-quote-placement.py')
    state = load_private(options.state)
    if not state.get('p05', {}).get('active') or any(state.get('identity', {}).get(key) != recorder.report[key] for key in IDENTITY_KEYS + ('installed_php_sources',)):
        raise RuntimeError('P05 owned fixture source differs')
    headers = {'X-Cetech-P04-Fixture':state['fixture_token'], 'X-Cetech-Q06-Fixture':state['fixture_token'], 'X-Cetech-P05-Fixture':state['fixture_token']}
    client = common.HttpClient(state['base_url'])
    admin = common.HttpClient(state['base_url'])
    denied = common.HttpClient(state['base_url'])
    bridges = {}
    for name, script in (('cart','opening-http-quote-cart-fixture.php'),('placement','opening-http-quote-placement-support.php'),('promise','opening-http-promise-handoff-support.php'),('configuration','opening-http-promise-native-configuration-support.php'),('shipment','opening-http-promise-native-configuration-shipment-support.php')):
        folder = Path(options.private) / name; folder.mkdir(mode=0o700)
        bridges[name] = common.FixtureBridge(options.php,options.wpcli,options.site,str(SOURCE/'admin-context.php'),str(SOURCE/script),Path(options.state),folder)
    failure = None
    try:
        response = client.get('/?cetech_opening_http_probe=1', {'X-CETECH-Opening-Probe':state['probe_token']})
        observed = json.loads(response.body); runtime = observed.pop('runtime', None)
        expected = {'format':'cetech-opening-http-owned-listener-v1','probe_sha256':hashlib.sha256(state['probe_token'].encode()).hexdigest(),'site_path_sha256':hashlib.sha256(state['site_path'].encode()).hexdigest(),'database_name_sha256':hashlib.sha256(state['database_name'].encode()).hexdigest(),**{key:state['identity'][key] for key in IDENTITY_KEYS[:3]}}
        runtime_exact = isinstance(runtime, dict) and set(runtime) == {'sapi','php_version','binary_sha256','ini_sha256','original_ini_sha256','extensions_sha256','jit_disabled_with_opcache'} and runtime['sapi'] == 'cli-server' and runtime['php_version'] == recorder.report['environment']['php']
        if runtime_exact:
            for key, variable in (('binary_sha256','CETECH_DE_HTTP_EXPECT_BINARY_SHA256'),('ini_sha256','CETECH_DE_HTTP_EXPECT_INI_SHA256'),('original_ini_sha256','CETECH_DE_HTTP_EXPECT_ORIGINAL_INI_SHA256'),('extensions_sha256','CETECH_DE_HTTP_EXPECT_EXTENSIONS_SHA256')):
                runtime_exact = runtime_exact and re.fullmatch(r'[a-f0-9]{64}', str(runtime[key])) is not None and (not os.environ.get(variable) or runtime[key] == os.environ[variable])
            if os.environ.get('CETECH_DE_HTTP_LISTENER_JIT') == 'disable': runtime_exact = runtime_exact and runtime['jit_disabled_with_opcache'] is True
        recorder.check('HTTP-W2P05-OWNED-LISTENER', response.status == 200 and observed == expected and runtime_exact, {'owned_token_before_credentials':observed == expected,'exact_disposable_source_identity':observed == expected,'actual_native_listener_runtime':runtime_exact})
        def login(session, username, password, target):
            session.get('/wp-login.php'); response = session.post('/wp-login.php', {'log':username,'pwd':password,'wp-submit':'Log In','redirect_to':target,'testcookie':'1'})
            if response.status not in (302,303) or not any(cookie.name.startswith('wordpress_logged_in_') for cookie in session.cookies): raise RuntimeError('P05 actual native login refused')
        admin_url = state['base_url'] + '/wp-admin/admin.php?page=cetech-de-promises'
        login(admin,state['p05']['admin']['login'],state['p05']['admin']['password'],admin_url)
        rendered = admin.get(admin_url)
        def action_form(verb, response=None):
            document = rendered if response is None else response
            forms = [form for form in document.page().forms if form.fields.get('cetech_de_action') == 'cetech_de_promise_' + verb]
            if document.status != 200 or len(forms) != 1 or not forms[0].fields.get('cetech_de_nonce'): raise RuntimeError('P05 native action form missing')
            return dict(forms[0].fields)
        create_form = action_form('create')
        recorder.check('HTTP-W2P05-AUTHENTICATED-ADMIN-CONFIGURATION-PAGE', rendered.status == 200 and create_form['cetech_de_nonce'] != '', {'native_wordpress_admin_cookie':any(cookie.name.startswith('wordpress_') and cookie.path.startswith('/wp-admin') for cookie in admin.cookies),'actual_native_configuration_page':rendered.status == 200,'nonce_bound_action_form':create_form['cetech_de_action'] == 'cetech_de_promise_create' and bool(create_form['cetech_de_nonce'])})
        login(denied,state['p05']['denied']['login'],state['p05']['denied']['password'],admin_url)
        blocked = denied.get(admin_url)
        recorder.check('HTTP-W2P05-UNAUTHORIZED-PRIVATE-CONFIGURATION-REFUSED', blocked.status in (200,403) and not any(form.fields.get('cetech_de_action','').startswith('cetech_de_promise_') for form in blocked.page().forms), {'actual_native_unprivileged_login':any(cookie.name.startswith('wordpress_logged_in_') for cookie in denied.cookies),'no_private_action_forms':not any(form.fields.get('cetech_de_action','').startswith('cetech_de_promise_') for form in blocked.page().forms),'no_private_policy_or_calendar':state['p05']['token'] not in blocked.body.decode(errors='replace')})
        payload = bridges['configuration'].call('payloadcreate'); baseline = bridges['configuration'].call('inspectconfiguration')
        fields = dict(create_form, **payload); fields['cetech_de_nonce']='invalid-native-nonce'
        bad_nonce = admin.post(admin_url, fields); after_nonce = bridges['configuration'].call('inspectconfiguration')
        recorder.check('HTTP-W2P05-REAL-POST-NONCE-REFUSAL-NO-WRITE', bad_nonce.status in (302,303) and baseline == after_nonce, {'actual_admin_post_nonce_denied':bad_nonce.status in (302,303),'physical_sources_unchanged':baseline == after_nonce,'no_calendar_version_effect':after_nonce['counts']['promise_versions'] == 0})
        fields = dict(create_form, **payload)
        created = admin.post(admin_url, fields); once = bridges['configuration'].call('inspectconfiguration'); replayed = admin.post(admin_url, fields); replay = bridges['configuration'].call('inspectconfiguration')
        recorder.check('HTTP-W2P05-REAL-CREATE-POST-ORIGINAL-REPLAY-ONCE', created.status == 200 and replayed.status == 200 and once == replay and once['counts']['promise_versions'] == 1, {'actual_create_post_accepted':created.status == 200 and created.page().has_notice('success','immutable audit receipt'),'original_replay_post_accepted':replayed.status == 200 and replayed.page().has_notice('success','original request'),'one_immutable_calendar_version':once == replay and once['counts']['promise_versions'] == 1,'server_derived_native_author':once['server_authors'] == [state['p05']['admin']['id']]})
        def open_version(document):
            fields = dict(action_form('inspect', document), **{key: payload[key] for key in ('site_key','kind','logical_id','domain_version','scope_kind','scope_id')})
            return admin.post(admin_url, fields)
        opened_created = open_version(created)
        sealed_payload = bridges['configuration'].call('payloadseal'); sealed = admin.post(admin_url, dict(action_form('seal',opened_created), **sealed_payload)); opened_sealed = open_version(sealed)
        published_payload = bridges['configuration'].call('payloadpublish'); published = admin.post(admin_url, dict(action_form('publish',opened_sealed), **published_payload)); published_rows = bridges['configuration'].call('inspectconfiguration')
        recorder.check('HTTP-W2P05-REAL-SEAL-PUBLISH-POSTS-IMMUTABLE', sealed.status == 200 and published.status == 200 and published_rows['version_states'] == ['published'], {'actual_saved_version_opened':opened_created.status == 200 and opened_sealed.status == 200,'actual_seal_post_accepted':sealed.status == 200 and sealed.page().has_notice('success','immutable audit receipt'),'actual_publish_post_accepted':published.status == 200 and published.page().has_notice('success','immutable audit receipt'),'original_calendar_bytes_preserved':published_rows['version_bodies'] == once['version_bodies'],'one_published_version':published_rows['version_states'] == ['published']})
        preview_fields = dict(action_form('preview',published), site_key=state['p04']['token'], body_json=json.dumps(state['p04']['policy']), calendars_json=json.dumps({'calendars':[state['p04']['calendar']]}))
        preview = admin.post(admin_url, preview_fields); after_preview = bridges['configuration'].call('inspectconfiguration')
        recorder.check('HTTP-W2P05-REAL-PREVIEW-LABEL-NO-ADMISSION', preview.status == 200 and published_rows == after_preview and 'Hypothetical preview' in preview.body.decode(errors='replace'), {'actual_nonce_protected_preview_post':preview.status == 200,'hypothetical_label_visible':'Hypothetical preview' in preview.body.decode(errors='replace'),'explicit_no_checkout_acceptance':'Nothing was published or accepted for checkout' in preview.body.decode(errors='replace'),'physical_policy_calendar_unchanged':published_rows == after_preview})
        login(client,state['username'],state['password'],state['classic_page_url'])
        def inspect():
            response = client.get('/?cetech_p05_fixture=inspect',headers); value=json.loads(response.body)
            if response.status != 200 or value.get('success') is not True or any(value['data']['source_identity'][key] != state['identity'][key] for key in IDENTITY_KEYS): raise RuntimeError('P05 native shopper observer refused')
            return value['data']
        def fixture(mode, promise=False):
            before=inspect(); response=client.request('/?cetech_'+('p04' if promise else 'q06')+'_fixture='+mode, {'nonce':before['p04_fixture_nonce' if promise else 'nonce']},headers)
            if response.status != 200 or json.loads(response.body).get('success') is not True: raise RuntimeError('P05 tracked shopper stimulus refused')
            return inspect()
        pdp = client.get(state['p05']['product_url']); pdp_text=pdp.body.decode(errors='replace')
        from html.parser import HTMLParser
        class Estimate(HTMLParser):
            def __init__(self): super().__init__();self.active=False;self.values=[]
            def handle_starttag(self,tag,attrs):
                if tag=='span' and 'cetech-de-delivery-option__estimate' in dict(attrs).get('class','').split(): self.active=True
            def handle_endtag(self,tag):
                if tag=='span': self.active=False
            def handle_data(self,data):
                if self.active:self.values.append(data)
        estimates=Estimate();estimates.feed(pdp_text);preliminary=any('Preliminary estimate' in text for text in estimates.values)
        recorder.check('HTTP-W2P05-REAL-PDP-PRELIMINARY-NO-ACCEPTED-PROMISE', pdp.status == 200 and preliminary, {'actual_published_product_page':pdp.status == 200,'preliminary_estimate_label':preliminary,'final_checkout_review_remains_required':any('checkout' in text for text in estimates.values),'no_original_accepted_marker':'data-cetech-de-original-promise="1"' not in pdp_text})
        seeded=fixture('seed');response=client.request(state['classic_url'],{'_wpnonce':seeded['review_nonce'],'action':'refresh','generation':seeded['facts']['generation'],'review_token':str(uuid.uuid4())});facts=json.loads(response.body).get('data',{})
        if response.status != 200 or facts.get('status') != 'review_required': raise RuntimeError('P05 actual Classic Refresh refused')
        confirmed=client.request(state['classic_url'],{'_wpnonce':inspect()['review_nonce'],'action':'confirm','generation':facts['generation']});final=json.loads(confirmed.body).get('data',{});promise=final.get('quote',{}).get('promise');classic=client.get(state['classic_page_url']);classic_text=classic.body.decode(errors='replace')
        recorder.check('HTTP-W2P05-REAL-CLASSIC-FINAL-STRUCTURED-ORIGINAL-VIEW', confirmed.status == 200 and final.get('status') == 'confirmed' and safe_promise(promise), {'actual_separate_refresh_confirm_requests':response.status == 200 and confirmed.status == 200,'closed_safe_structured_original_promise':safe_promise(promise),'original_label_markup_visible':'data-cetech-de-original-promise="1"' in classic_text,'captured_customer_text_visible':all(group['customer_text'] in classic_text for group in promise.get('groups',[]))})
        store=client.get(state['store_cart_url'],{'Nonce':inspect()['store_nonce']});store_value=json.loads(store.body);store_facts=store_value.get('extensions',{}).get('cetech-delivery-quote-review',{});store_promise=store_facts.get('quote',{}).get('promise')
        recorder.check('HTTP-W2P05-REAL-STORE-API-SAME-ORIGINAL-PUBLIC-VIEW', store.status == 200 and safe_promise(store_promise) and store_promise == promise, {'actual_native_store_cart_request':store.status == 200,'closed_safe_structured_promise':safe_promise(store_promise),'same_accepted_quote_id':store_facts.get('quote',{}).get('quote_id') == final.get('quote',{}).get('quote_id'),'exact_original_view_and_text_parity':store_promise == promise})
        before_stale=inspect();fixture('normal_policy',True);stale=client.request(state['classic_url'],{'_wpnonce':inspect()['review_nonce'],'action':'confirm','generation':final['generation']});stale_value=json.loads(stale.body).get('data',{});after_stale=inspect()
        recorder.check('HTTP-W2P05-STALE-REQUIRED-PROMISE-REFUSED', stale_value.get('status') != 'confirmed' and q06.no_payment(before_stale,after_stale), {'actual_changed_published_policy_and_assignment':before_stale['p04_source_counts'] != after_stale['p04_source_counts'],'stale_submitted_confirmation_refused':stale_value.get('status') != 'confirmed','no_gateway_or_free_completion':q06.no_payment(before_stale,after_stale),'no_unguarded_payment_fallback':q06.no_payment(before_stale,after_stale)})
        bridges['cart'].call('blockspagequotecart')
        cookie_path=Path(state['browser_state_path']);browser_path=Path(options.private)/'browser-results.json'
        q06.write_browser_cookie_handoff(client,state,cookie_path)
        result=subprocess.run(['node',str(SOURCE/'promise-native-configuration-browser.cjs'),'--state',str(cookie_path),'--receipt',str(browser_path),'--base-url',client.base_url],stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=180,check=False)
        if not browser_path.is_file() or browser_path.stat().st_size>65536:raise RuntimeError('P05 actual browser receipt missing')
        browser=json.loads(browser_path.read_text());expected_keys={'format',*IDENTITY_KEYS,'runtime','status','cases'}
        if set(browser)!=expected_keys or browser['format']!='cetech-w2p05-promise-browser-v1' or any(browser[key]!=state['identity'][key] for key in IDENTITY_KEYS) or browser['runtime']!={'playwright':'1.58.2','chromium':'145.0.7632.6'} or len(browser['cases'])!=2:raise RuntimeError('P05 actual browser runtime or identity refused')
        recorder.report['browser_runtime']=browser['runtime'];recorder.write();paid_case,free_case=browser['cases'];paid=paid_case['evidence'];free=free_case['evidence']
        recorder.check('HTTP-W2P05-BLOCKS-PAID-ACTUAL-FINAL-BUTTON', result.returncode==0 and paid_case['status']=='PASS', {'real_pinned_chromium':paid['real_pinned_chromium'],'actual_blocks_final_button':paid['actual_blocks_final_button'],'actual_original_promise_markup':paid['actual_original_promise_markup'],'exact_original_shopper_saved_parity':paid['exact_original_shopper_saved_parity'],'structured_public_promise_required':paid['structured_public_promise_required'],'gateway_exactly_once_after_seal':paid['gateway_exactly_once_after_seal'],'native_order_paid':paid['native_order_paid'],'same_installed_source':paid['same_installed_source']})
        recorder.check('HTTP-W2P05-BLOCKS-FREE-ACTUAL-FINAL-BUTTON', result.returncode==0 and free_case['status']=='PASS', {'real_pinned_chromium':free['real_pinned_chromium'],'actual_blocks_final_button':free['actual_blocks_final_button'],'actual_original_promise_markup':free['actual_original_promise_markup'],'exact_original_shopper_saved_parity':free['exact_original_shopper_saved_parity'],'structured_public_promise_required':free['structured_public_promise_required'],'free_completion_exactly_once_after_seal':free['free_completion_exactly_once_after_seal'],'native_order_completed':free['native_order_completed'],'same_installed_source':free['same_installed_source']})
        shipment = bridges['shipment'].call('preparehttpafterbrowser')
        shipment_opened = admin.post(admin_url, dict(action_form('shipment_read', published), shipment_id=str(shipment['shipment_id'])))
        shipment_form = action_form('shipment_update', shipment_opened)
        bridges['shipment'].call('trackhttpstaff:' + shipment_form['request_token'])
        earliest = (datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(days=2)).strftime('%Y-%m-%d %H:%M:%S.000000')
        latest = (datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(days=3)).strftime('%Y-%m-%d %H:%M:%S.000000')
        reason = 'Tracked P05 internal shipment scheduling reason'
        current_fields = dict(shipment_form, state='absolute_window', **{'from':earliest, 'until':latest, 'display_timezone':'Africa/Accra', 'reason':reason})
        tampered = dict(current_fields, original_envelope=('A' if current_fields['original_envelope'][0] != 'A' else 'B') + current_fields['original_envelope'][1:])
        rejected = admin.post(admin_url, tampered); after_tamper = bridges['shipment'].call('inspecthttpshipment')
        recorder.check('HTTP-W2P05-SIGNED-STAFF-ENVELOPE-TAMPER-REFUSED', shipment_opened.status == 200 and rejected.status == 200 and after_tamper['material_hash'] == shipment['material_hash'], {'actual_protected_native_shipment_read':shipment_opened.status == 200 and shipment['original_text'] in shipment_opened.page().text,'server_signed_original_context_present':bool(shipment_form.get('original_envelope')),'actual_tampered_mac_post_refused':rejected.status == 200 and rejected.page().has_notice('error','could not be confirmed'),'no_current_or_reason_event_effect':after_tamper['material_hash'] == shipment['material_hash']})
        changed = admin.post(admin_url, current_fields); after_change = bridges['shipment'].call('inspecthttpshipment')
        replay_fields = action_form('shipment_update',changed); replayed_current = admin.post(admin_url,replay_fields); after_replay = bridges['shipment'].call('inspecthttpshipment')
        changed_semantic = admin.post(admin_url,dict(replay_fields,reason='Changed same-token semantic command')); after_semantic = bridges['shipment'].call('inspecthttpshipment')
        recorder.check('HTTP-W2P05-REAL-STAFF-CURRENT-UPDATE-ORIGINAL-REPLAY-ONCE', changed.status == 200 and replayed_current.status == 200 and after_change['revision'] == shipment['revision'] + 1 and after_replay == after_change and after_semantic == after_change, {'actual_nonce_protected_current_post':changed.status == 200 and changed.page().has_notice('success','separate current estimate was saved'),'same_signed_original_command_replay_once':after_replay == after_change,'same_token_changed_semantic_refused':changed_semantic.status == 200 and changed_semantic.page().has_notice('error','could not be confirmed') and after_semantic == after_change,'exact_internal_reason_audited':after_change['reason_sha256'] == hashlib.sha256(reason.encode()).hexdigest(),'original_and_saved_order_bytes_preserved':after_change['snapshot_preserved'] is True and after_change['original_preserved'] is True})
        owned_order_page = client.get(after_change['view_order_url']); owned_order_text = owned_order_page.body.decode(errors='replace')
        recorder.check('HTTP-W2P05-REAL-OWN-CUSTOMER-ORIGINAL-CURRENT-SHIPMENT-VIEW', owned_order_page.status == 200 and after_change['original_text'] in owned_order_page.page().text and after_change['current_text'] in owned_order_page.page().text, {'actual_native_owned_view_order_get':owned_order_page.status == 200,'original_recorded_estimate_visible':'data-cetech-de-shipment-original="1"' in owned_order_text and after_change['original_text'] in owned_order_page.page().text,'separate_current_estimate_visible':'data-cetech-de-shipment-current="1"' in owned_order_text and after_change['current_text'] in owned_order_page.page().text,'internal_reason_and_private_packet_excluded':reason not in owned_order_text and not any(value in owned_order_text for value in ('original_packet_digest','current_json','packet_json','event_key','native-shipment-promise'))})
        outsider_order_page = denied.get(after_change['view_order_url']); outsider_admin = denied.post(admin_url,dict(action_form('shipment_read', published),shipment_id=str(shipment['shipment_id']))); after_outsider = bridges['shipment'].call('inspecthttpshipment')
        recorder.check('HTTP-W2P05-OUTSIDER-SHIPMENT-HISTORY-REFUSED', outsider_order_page.status in (200,403) and 'data-cetech-de-shipment-original="1"' not in outsider_order_page.body.decode(errors='replace') and after_outsider == after_change, {'actual_other_native_customer_get':any(cookie.name.startswith('wordpress_logged_in_') for cookie in denied.cookies),'no_original_or_current_private_history':'data-cetech-de-shipment-original="1"' not in outsider_order_page.body.decode(errors='replace') and 'data-cetech-de-shipment-current="1"' not in outsider_order_page.body.decode(errors='replace'),'unprivileged_native_staff_post_denied':outsider_admin.status in (302,303,403) and after_change['original_text'] not in outsider_admin.body.decode(errors='replace'),'stored_current_and_original_history_unchanged':after_outsider == after_change})
    except Exception as error:
        failure=error;recorder.report['status']='FAIL';recorder.report['error']={'class':type(error).__name__ if type(error).__name__ in {'RuntimeError','ValueError','TypeError','KeyError','TimeoutExpired','JSONDecodeError'} else 'OtherError'};recorder.write()
    finally:
        try:
            shipment_cleanup=bridges['shipment'].call('cleanuphttpshipment');placement=bridges['placement'].call('cleanupplacement');configuration=bridges['configuration'].call('cleanupconfiguration');promise=bridges['promise'].call('cleanuppromise');cart=bridges['cart'].call('cleanupquotecart')
            recorder.check('HTTP-W2P05-TRACKED-FIXTURE-CLEANUP', all(value is True for document in (shipment_cleanup,placement,configuration,promise,cart) for value in document.values()), {'exact_shipment_original_current_audit_history_restored':all(value is True for value in shipment_cleanup.values()),'exact_owned_orders_bindings_removed':placement.get('owned_native_orders_removed') is True and placement.get('exact_owned_placement_namespaces_removed') is True,'native_admin_sources_and_users_restored':configuration.get('cleanup_restored') is True and configuration.get('tracked_users_removed') is True,'original_promise_history_restored':promise.get('original_promise_history_restored') is True,'protected_adoption_restored':promise.get('adoption_restored') is True,'native_sources_users_pages_options_restored':cart.get('cleanup_restored') is True,'all_connections_retired':placement.get('owned_connections_retired') is True and cart.get('all_owned_connections_retired') is True})
        except Exception as cleanup_error:failure=failure or cleanup_error
    recorder.report['status']='FAIL' if failure else 'RUNNING';recorder.write()
    if failure:raise RuntimeError('P05 request qualification incomplete; preserve the closed failed receipt') from failure


def finalize(receipt,listener_stopped,mu_removed,private_removed,tracked_cleanup):
    report=json.loads(Path(receipt).read_text());recorder=Recorder(receipt,report)
    try:
        recorder.check('HTTP-W2P05-OWNED-LISTENER-AND-PRIVATE-CLEANUP',listener_stopped and mu_removed and private_removed and tracked_cleanup,{'owned_listener_stopped_and_waited':listener_stopped,'exact_owned_mu_removed':mu_removed,'private_credentials_and_logs_removed':private_removed,'tracked_database_cleanup_completed':tracked_cleanup})
    finally:
        expected=[case_id for case_id,_ in protocol()];report['status']='PASS' if report.get('status')!='FAIL' and [case['id'] for case in report['cases']]==expected and all(case_valid(case) and case['status']=='PASS' for case in report['cases']) and report['browser_runtime']=={'playwright':'1.58.2','chromium':'145.0.7632.6'} else 'FAIL';recorder.write()


def main():
    parser=argparse.ArgumentParser();parser.add_argument('--work',required=True);parser.add_argument('--receipt',required=True);parser.add_argument('--initialize',action='store_true');parser.add_argument('--finalize',nargs=4)
    for name in ('php','wpcli','site','state','private'):parser.add_argument('--'+name)
    options=parser.parse_args()
    if options.initialize:initialize(options.work,options.receipt)
    elif options.finalize:finalize(options.receipt,*(value=='1' for value in options.finalize));return 0 if json.loads(Path(options.receipt).read_text())['status']=='PASS' else 1
    else:run(options,Recorder(options.receipt,json.loads(Path(options.receipt).read_text())))
    return 0
if __name__=='__main__':
    try:sys.exit(main())
    except Exception:print('opening_http_promise_native_configuration=FAIL; inspect closed receipt',file=sys.stderr);sys.exit(1)
