#!/usr/bin/env python3
"""Finite R23 native WordPress requests and strict source-derived receipt closure.

CLI bridges prepare/inspect/delete only predeclared owned fixture rows. Every
qualified editor action is an actual nonce/cookie protected native HTTP POST.
"""
import argparse
import ast
import hashlib
from html.parser import HTMLParser
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time
from urllib.error import URLError
from urllib.request import Request

sys.dont_write_bytecode = True
SOURCE = Path(__file__).resolve().parent
ROOT = SOURCE.parent.parent
FORMAT = "cetech-r23-policy-assignment-admin-http-v1"
CONTEXT = "Actual native WordPress admin login, action nonces and separate HTTP requests on the marked disposable fixture"
LIMITS = [
    "Saved-state global/product/variation Return and Refund assignment and native admin preview only; no customer/order writer or checkout admission.",
    "Real selected delivery offers remain independent of availability slices. Existing delivery configuration and default quote/promise adoption remain unchanged.",
    "Current published logical policies are observed as of SQL evaluation time; later publication may change future previews. Historical policy writing is not certified.",
    "Native completed-operation preservation and stale CAS are observed; interrupted native HTTP writes are not physically injected.",
    "Owned fixtures are deleted exactly; no whole-database ancillary WordPress or scheduler rollback, target merchant/theme acceptance, payment, merge or deployment is certified.",
]

PRODUCERS = (
    "scripts/qualification/policy-assignment-admin-native.php",
    "scripts/qualification/verify-policy-assignment-admin-native.py",
    "scripts/ci-policy-assignment-admin-native.sh",
)
ASSIGNMENT_SOURCES = (
    "src/Domain/PolicyAssignment/PolicyAssignmentMap.php",
    "src/Domain/PolicyAssignment/PolicyAssignmentState.php",
    "src/Domain/PolicyAssignment/PolicyAssignmentCommand.php",
    "src/Domain/PolicyAssignment/PolicyEffectiveSelection.php",
    "src/Infrastructure/Persistence/PolicyAssignmentStore.php",
    "src/Application/PolicyAssignment/PolicyAssignmentOperationProfile.php",
    "src/Application/PolicyAssignment/PolicyAssignmentService.php",
    "src/Application/PolicyAssignment/PolicyCurrentCatalog.php",
    "src/Application/PolicyAssignment/PolicyAssignmentCurrentService.php",
    "src/Integrations/PolicyAssignment/NativePolicyAssignmentAuthority.php",
    "src/Infrastructure/Persistence/WpdbPolicyAssignmentAdminIntent.php",
    "src/Application/PolicyAssignment/PolicyAssignmentAdminWorkflow.php",
    "src/Presentation/Admin/PolicyAssignmentPage.php",
)

IDENTITY = ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash")
STACK = ("php", "wordpress", "woocommerce", "database_version", "hpos", "schema_before")
ACTION = "cetech_de_policy_assignment_"
SLUG = "cetech-de-policy-assignments"
FAMILIES = ("return_policy_v1", "refund_policy_v1")


def require(condition, message):
    if not condition:
        raise ValueError(message)


def load(path, private=False):
    path = Path(path)
    info = path.lstat()
    require(stat.S_ISREG(info.st_mode) and not path.is_symlink() and info.st_size <= 16777216, "R23 ordinary bounded file required")
    require(not private or stat.S_IMODE(info.st_mode) == 0o600, "R23 private file mode differs")
    return json.loads(path.read_text(encoding="utf-8"))


def canonical(value):
    return json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode()


def protocol():
    declarations = []
    for node in ast.walk(ast.parse(Path(__file__).read_text())):
        if not (isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute) and isinstance(node.func.value, ast.Name) and node.func.value.id == "recorder" and node.func.attr == "check"):
            continue
        require(len(node.args) == 3 and not node.keywords and isinstance(node.args[0], ast.Constant) and isinstance(node.args[0].value, str) and re.fullmatch(r"R23-[A-Z0-9-]+", node.args[0].value), "Ambiguous R23 case declaration")
        require(isinstance(node.args[2], ast.Dict) and node.args[2].keys, "Missing R23 observation declaration")
        fields = []
        for key in node.args[2].keys:
            require(isinstance(key, ast.Constant) and isinstance(key.value, str) and re.fullmatch(r"[a-z0-9_]+", key.value) and key.value not in fields, "Ambiguous R23 observation")
            fields.append(key.value)
        declarations.append((node.lineno, node.args[0].value, tuple(fields)))
    declarations.sort()
    require(0 < len(declarations) <= 32 and len({item[1] for item in declarations}) == len(declarations), "Ambiguous R23 protocol")
    return tuple((case, fields) for _, case, fields in declarations)


def case_valid(case):
    expected = dict(protocol())
    return isinstance(case, dict) and set(case) == {"id", "status", "evidence"} and case.get("id") in expected and case.get("status") in {"PASS", "FAIL"} and isinstance(case.get("evidence"), dict) and set(case["evidence"]) == set(expected[case["id"]]) and all(type(value) is bool for value in case["evidence"].values()) and (case["status"] != "PASS" or all(case["evidence"].values()))


def prior(work):
    path = Path(work) / "opening-qualification-results.json"
    document = load(path)
    require(document.get("status") == "PASS" and len(document.get("cases", [])) == 495 and len({case.get("id") for case in document["cases"]}) == 495 and all(case.get("status") == "PASS" for case in document["cases"]), "Retained native qualification is incomplete")
    for key, variable in (("source_head", "CETECH_DE_QUALIFICATION_HEAD"), ("candidate_head", "CETECH_DE_QUALIFICATION_CANDIDATE_HEAD"), ("source_tree", "CETECH_DE_QUALIFICATION_TREE")):
        require(re.fullmatch(r"[0-9a-f]{40}", str(document.get(key, ""))) and (not os.environ.get(variable) or document[key] == os.environ[variable]), "R23 immutable native authority differs")
    sources = document.get("installed_php_sources")
    require(isinstance(sources, dict) and len(sources) == 968 and all(path in sources for path in ASSIGNMENT_SOURCES) and list(sources) == sorted(sources) and all(isinstance(path, str) and re.fullmatch(r"[0-9a-f]{64}", str(digest)) for path, digest in sources.items()) and document["installed_php_sources_hash"] == hashlib.sha256(canonical(sources)).hexdigest(), "R23 production map is not canonical")
    return document, hashlib.sha256(path.read_bytes()).hexdigest()


def producers(head):
    pins = {}
    for name in PRODUCERS:
        actual = (ROOT / name).read_bytes()
        committed = subprocess.run(["git", "show", head + ":" + name], cwd=ROOT, check=True, capture_output=True, timeout=15).stdout
        require(actual == committed, "R23 producer differs from executed source authority")
        pins[name] = hashlib.sha256(actual).hexdigest()
    return pins


class Recorder:
    def __init__(self, path, report):
        self.path, self.report = Path(path), report

    def write(self):
        temporary = self.path.with_suffix(".tmp")
        require(not self.path.is_symlink() and not temporary.is_symlink(), "R23 receipt allocation refused")
        temporary.write_text(json.dumps(self.report, indent=2) + "\n")
        temporary.replace(self.path)

    def check(self, case_id, condition, evidence):
        case = {"id": case_id, "status": "PASS" if condition and all(value is True for value in evidence.values()) else "FAIL", "evidence": evidence}
        require(case_valid(case) and not any(item["id"] == case_id for item in self.report["cases"]), "R23 observed case does not match authored protocol")
        self.report["cases"].append(case)
        self.write()
        print("policy_assignment_admin_case=" + case_id + " result=" + case["status"], flush=True)
        require(case["status"] == "PASS", "R23 native observation diverged")


def initialize(options):
    native, digest = prior(options.work)
    require(not Path(options.receipt).exists() and not Path(options.receipt).is_symlink(), "R23 refuses an existing receipt")
    report = {"format": FORMAT, "status": "RUNNING", **{key: native[key] for key in IDENTITY}, "installed_php_sources": native["installed_php_sources"], "environment": {key: native["environment"][key] for key in STACK}, "context": CONTEXT, "limits": LIMITS, "preceding_native": {"sha256": digest, "cases": 495}, "producer_sha256": producers(native["source_head"]), "cases": []}
    Recorder(options.receipt, report).write()


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, SOURCE / filename)
    result = importlib.util.module_from_spec(spec)
    sys.modules[name] = result
    spec.loader.exec_module(result)
    return result


def listener_probe(client, state):
    """Finite startup allowance; no credential is sent during possession proof."""
    deadline = time.monotonic() + 10
    request = Request(client.resolve("/?cetech_policy_assignment_probe=1"), headers={"X-CETECH-Opening-Probe": state["probe_token"]})
    while True:
        try:
            with client.opener.open(request, timeout=1) as response:
                body = response.read(16385)
                require(len(body) <= 16384, "R23 listener probe response exceeded bound")
                return response.code, json.loads(body)
        except (URLError, OSError, TimeoutError):
            if time.monotonic() >= deadline:
                raise RuntimeError("R23 owned listener did not start before its finite deadline")
            time.sleep(0.1)


class SavedPolicyPreview(HTMLParser):
    """Bind native wording to one real preview and its separate family articles."""
    VOID = {"area", "base", "br", "col", "embed", "hr", "img", "input", "link", "meta", "param", "source", "track", "wbr"}
    def __init__(self, body):
        super().__init__(convert_charrefs=True)
        self.stack, self.articles = [], []
        self.markers, self.closed, self.invalid = 0, 0, False
        self.feed(body)
        self.close()

    def handle_starttag(self, tag, attrs):
        ids = [value for key, value in attrs if key == "id"]
        target = "cetech-policy-assignment-preview" in ids
        if target:
            self.markers += 1
            self.invalid |= tag != "div" or len(ids) != 1 or any(node["target"] for node in self.stack)
        inside = any(node["target"] for node in self.stack)
        article = {"heading": [], "text": []} if tag == "article" and inside else None
        if tag not in self.VOID:
            self.stack.append({"tag": tag, "target": target, "article": article})
        elif target:
            self.invalid = True

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)
        if tag not in self.VOID:
            self.handle_endtag(tag)

    def handle_endtag(self, tag):
        positions = [i for i, node in enumerate(self.stack) if node["tag"] == tag]
        if not positions:
            if any(node["target"] for node in self.stack):
                self.invalid = True
            return
        index = positions[-1]
        removed = self.stack[index:]
        if any(node["target"] for node in self.stack) and index != len(self.stack) - 1:
            self.invalid = True
        for node in removed:
            if node["article"] is not None:
                self.articles.append(node["article"])
            if node["target"]:
                self.closed += 1
        self.stack = self.stack[:index]

    def handle_data(self, data):
        if not any(node["target"] for node in self.stack) or any(node["tag"] in {"script", "style", "template", "noscript"} for node in self.stack):
            return
        active = [node["article"] for node in self.stack if node["article"] is not None]
        if not active:
            return
        active[-1]["text"].append(data.strip())
        if any(node["tag"] == "h3" for node in self.stack):
            active[-1]["heading"].append(data.strip())


def preview_wording_visible(document, captured):
    parsed = SavedPolicyPreview(document.body.decode("utf-8", "strict"))
    if document.status != 200 or parsed.invalid or parsed.markers != 1 or parsed.closed != 1 or any(node["target"] for node in parsed.stack) or len(parsed.articles) != 2 or set(captured["selections"]) != set(FAMILIES):
        return False
    articles = {" ".join(article["heading"]): " ".join(article["text"]) for article in parsed.articles}
    if set(articles) != {"Return Policy", "Refund Policy"}:
        return False
    for family, label in (("return_policy_v1", "Return Policy"), ("refund_policy_v1", "Refund Policy")):
        selection = captured["selections"][family]
        text = articles[label]
        if selection["disposition"] == "assigned":
            if not all(not selection["fields"][field] or selection["fields"][field] in text for field in ("customer_title", "summary", "full_text", "exceptions")):
                return False
        elif selection["disposition"] == "disabled":
            if "Disabled for this selection" not in text:
                return False
        elif selection["disposition"] == "not_assigned":
            if "No policy assigned at these settings" not in text:
                return False
        elif selection["disposition"] == "unresolved":
            if "The assigned policy is unavailable" not in text:
                return False
        else:
            return False
    return True


def no_javascript_modes(document, return_mode=None, refund_mode=None):
    """Post the real previously selected UUIDs, just as successful no-JS controls do."""
    values = dict(document.page().form_for_action(ACTION + "save").fields)
    for field, mode in (("return_mode", return_mode), ("refund_mode", refund_mode)):
        if mode is not None:
            require(mode in {"inherit", "disabled"}, "R23 no-JS mode transition differs")
            values[field] = mode
    values["reason"] = "Owned R23 no-JavaScript mode change"
    return values


def run(options, recorder):
    state = load(options.state, private=True)
    require(all(state['identity'].get(key) == recorder.report.get(key) for key in IDENTITY + ('installed_php_sources',)), 'R23 private identity differs')
    observed_stack = state['identity'].get('environment', {})
    stack_exact = all(observed_stack.get(key) == recorder.report['environment'].get(key) for key in STACK if key != 'schema_before') and observed_stack.get('schema') == recorder.report['environment'].get('schema_before') == '12'
    require(stack_exact, 'R23 measured native stack differs')
    common = module('r23_native_http_common', 'opening-http-driver.py')
    bridge_directory = Path(options.private) / 'bridge'
    bridge_directory.mkdir(mode=0o700)
    bridge = common.FixtureBridge(options.php, options.wpcli, options.site, str(SOURCE / 'admin-context.php'), str(SOURCE / 'policy-assignment-admin-native.php'), Path(options.state), bridge_directory)
    admin, other, denied, anonymous = (common.HttpClient(state['base_url']) for _ in range(4))
    url = state['base_url'] + '/wp-admin/admin.php?page=' + SLUG
    failure = None
    try:
        status, observed = listener_probe(anonymous, state)
        expected = {'format': 'cetech-r23-owned-listener-v1', 'probe_sha256': hashlib.sha256(state['probe_token'].encode()).hexdigest(), 'site_path_sha256': hashlib.sha256(state['site_path'].encode()).hexdigest(), 'database_name_sha256': hashlib.sha256(state['database_name'].encode()).hexdigest(), **{key: state['identity'][key] for key in IDENTITY[:3]}, 'php': recorder.report['environment']['php'], 'sapi': 'cli-server'}
        recorder.check('R23-OWNED-NATIVE-LISTENER', status == 200 and observed == expected and stack_exact, {'possession_proved_before_credentials': status == 200 and observed == expected, 'exact_native_source_and_php': observed == expected, 'measured_native_stack_matches_retained': stack_exact})

        def login(client, actor):
            client.get('/wp-login.php')
            response = client.post('/wp-login.php', {'log': actor['login'], 'pwd': actor['password'], 'wp-submit': 'Log In', 'redirect_to': url, 'testcookie': '1'})
            require(response.status in (302, 303) and any(cookie.name.startswith('wordpress_logged_in_') for cookie in client.cookies), 'R23 actual native login refused')

        def form(document, verb):
            return dict(document.page().form_for_action(ACTION + verb).fields)

        def open_review(client, kind, offer=0, object_id=None):
            document = client.get(url)
            target_id = 0 if kind == 'global' else state['products']['parent' if kind == 'product' else 'variation']['id']
            return client.post(url, dict(form(document, 'open'), scope_type=kind, scope_id=str(target_id if object_id is None else object_id), offer_id=str(offer)))

        def fields(return_mode='inherit', return_key=None, refund_mode='inherit', refund_key=None, reason='Owned R23 native assignment'):
            return {'return_mode': return_mode, 'return_policy_uuid': '' if return_key is None else state['catalogs'][return_key]['policy_uuid'], 'refund_mode': refund_mode, 'refund_policy_uuid': '' if refund_key is None else state['catalogs'][refund_key]['policy_uuid'], 'reason': reason}

        def save(client, kind, offer, values):
            opened = open_review(client, kind, offer)
            return client.post(url, dict(form(opened, 'save'), **values))

        def success(document):
            return document.status == 200 and document.page().has_notice('success', 'assignment')

        def preview(client, kind, offer, mode):
            opened = open_review(client, kind, offer)
            result = client.post(url, form(opened, 'preview'))
            captured = bridge.call('capture-' + mode)
            return result, captured

        def assigned(captured, family, key, replacement=False):
            selected = captured['selections'][family]
            entry = state['catalogs'][key]
            return selected['disposition'] == 'assigned' and selected['reference']['logical_uuid'] == entry['policy_uuid'] and selected['reference']['version_uuid'] == entry['replacement_uuid' if replacement else 'version_uuid'] and selected['reference']['family'] == family and selected['reference']['hypothetical'] is False and selected['fields']['customer_title'] == entry['fields']['customer_title'] + (' — replacement' if replacement else '')

        def visible(document, captured):
            return preview_wording_visible(document, captured)

        login(admin, state['actors']['a'])
        login(other, state['actors']['b'])
        rendered = admin.get(url)
        opened = open_review(admin, 'global')
        save_fields = form(opened, 'save')
        prohibited = ('command_json', 'author_user_id', 'actor_user_id', 'site_id', 'request_token', 'row_id', 'revision', 'generation', 'parent_id', 'logical_revision', 'version_uuid', 'content_hash', 'reviewed')
        recorder.check('R23-AUTHENTICATED-PRIVATE-ASSIGNMENT-EDITOR', rendered.status == 200 and bool(save_fields['cetech_de_nonce']), {'native_manager_cookie_and_nonce': rendered.status == 200 and any(cookie.name.startswith('wordpress_') and cookie.path.startswith('/wp-admin') for cookie in admin.cookies) and bool(save_fields['cetech_de_nonce']), 'business_fields_and_opaque_ticket': all(name in save_fields for name in ('ticket', 'return_mode', 'return_policy_uuid', 'refund_mode', 'refund_policy_uuid', 'reason')), 'no_client_authority_or_cas_fields': not any(key in item.fields for item in opened.page().forms for key in prohibited), 'default_slot_has_no_effective_preview': not any(item.fields.get('cetech_de_action') == ACTION + 'preview' for item in opened.page().forms)})
        login(denied, state['actors']['denied'])
        denied_page = denied.get(url)
        recorder.check('R23-NATIVE-UNPRIVILEGED-ACTOR-REFUSED', denied_page.status in (200, 403) and not any(item.fields.get('cetech_de_action', '').startswith(ACTION) for item in denied_page.page().forms), {'separate_authentic_native_actor': any(cookie.name.startswith('wordpress_logged_in_') for cookie in denied.cookies), 'assignment_forms_absent': not any(item.fields.get('cetech_de_action', '').startswith(ACTION) for item in denied_page.page().forms), 'private_supplied_policy_names_absent': all(entry['fields']['name'] not in denied_page.body.decode(errors='replace') for entry in state['catalogs'].values())})

        baseline = bridge.call('inspect')
        root_values = fields('assigned', 'return_a', 'assigned', 'refund_a')
        bad_nonce = admin.post(url, dict(save_fields, **root_values, cetech_de_nonce='r23-invalid-native-nonce'))
        after_nonce = bridge.call('inspect')
        recorder.check('R23-NATIVE-NONCE-REFUSAL-ZERO-EFFECT', bad_nonce.status in (302, 303) and baseline == after_nonce, {'native_nonce_refused': bad_nonce.status in (302, 303), 'physical_options_c01_and_review_unchanged': baseline == after_nonce, 'no_assignment_state_authored': after_nonce['states'] == {}})
        forged = admin.post(url, dict(save_fields, **root_values, actor_user_id=str(state['actors']['b']['id'])))
        after_forged = bridge.call('inspect')
        foreign_family = admin.post(url, dict(save_fields, **fields('assigned', 'refund_a', 'assigned', 'refund_a')))
        after_family = bridge.call('inspect')
        cross_action = admin.post(url, dict(save_fields, **root_values, cetech_de_action='cetech_de_return_policy_create'))
        after_cross = bridge.call('inspect')
        recorder.check('R23-FORGED-AUTHORITY-AND-CROSS-FAMILY-REFUSED', forged.status == 200 and baseline == after_forged and baseline == after_family and baseline == after_cross, {'posted_actor_cannot_rebind': forged.status == 200 and baseline == after_forged, 'refund_logical_cannot_be_return_reference': foreign_family.status == 200 and baseline == after_family, 'foreign_editor_action_cannot_dispatch_assignment': cross_action.status in (200, 302, 303) and baseline == after_cross})
        saved_root = admin.post(url, dict(save_fields, **root_values))
        once = bridge.call('inspect')
        require('global' in once['states'], 'R23 native root assignment missing')
        root_state = once['states']['global']
        recorder.check('R23-NATIVE-ATOMIC-GLOBAL-DEFAULTS', success(saved_root) and root_state['revision'] == 1, {'native_save_confirmed': success(saved_root), 'both_families_saved_in_one_revision': root_state['revision'] == 1 and root_state['maps']['return_policy_v1']['default'] == {'mode': 'assigned', 'policy_uuid': state['catalogs']['return_a']['policy_uuid']} and root_state['maps']['refund_policy_v1']['default'] == {'mode': 'assigned', 'policy_uuid': state['catalogs']['refund_a']['policy_uuid']}, 'one_owned_assignment_operation': once['operation_count'] == 1, 'original_delivery_fields_unchanged': once['configuration_unchanged']})
        replayed = admin.post(url, dict(save_fields, **root_values))
        after_replay = bridge.call('inspect')
        recorder.check('R23-REPEATED-COMPLETED-POST-NO-REWRITE', replayed.status == 200 and after_replay == once, {'separate_native_request': replayed.status == 200, 'exact_saved_maps_and_original_operation_unchanged': after_replay == once, 'no_duplicate_scope_or_operation': len(after_replay['states']) == 1 and after_replay['operation_count'] == 1})

        old_review = open_review(admin, 'global')
        old_form = form(old_review, 'save')
        other_review = open_review(other, 'global')
        other_form = form(other_review, 'save')
        other_saved = other.post(url, dict(other_form, **fields('assigned', 'return_a', 'assigned', 'refund_b', 'Owned second native manager')))
        other_state = bridge.call('inspect')
        stale = admin.post(url, dict(old_form, **root_values))
        after_stale = bridge.call('inspect')
        restored = save(admin, 'global', 0, root_values)
        restored_state = bridge.call('inspect')
        recorder.check('R23-AUTHENTIC-SECOND-MANAGER-STALE-CAS', success(other_saved) and stale.status == 200 and success(restored), {'second_authentic_cookie_and_own_nonce': any(cookie.name.startswith('wordpress_logged_in_') for cookie in other.cookies) and success(other_saved) and old_form['ticket'] != other_form['ticket'], 'second_manager_advanced_actual_row_revision': other_state['states']['global']['revision'] == root_state['revision'] + 1, 'first_opened_cas_cannot_overwrite_newer_row': stale.status == 200 and after_stale['states'] == other_state['states'] and stale.page().has_notice('error', ''), 'fresh_native_review_can_restore_intended_defaults': success(restored) and restored_state['states']['global']['revision'] == other_state['states']['global']['revision'] + 1})

        offer_a = state['offers']['a']['id']
        offer_b = state['offers']['b']['id']
        saved_a = save(admin, 'global', offer_a, fields('assigned', 'return_b', 'inherit'))
        shown_a, captured_a = preview(admin, 'global', offer_a, 'global-a')
        recorder.check('R23-REAL-OFFER-INDEPENDENT-OVERRIDE', success(saved_a) and visible(shown_a, captured_a) and assigned(captured_a, 'return_policy_v1', 'return_b') and assigned(captured_a, 'refund_policy_v1', 'refund_a'), {'actual_positive_native_offer_saved': success(saved_a) and offer_a > 0, 'whole_return_override_selected': assigned(captured_a, 'return_policy_v1', 'return_b'), 'independent_refund_default_retained': assigned(captured_a, 'refund_policy_v1', 'refund_a'), 'actual_saved_preview_matches_current_capture': visible(shown_a, captured_a)})
        shown_b, captured_b = preview(admin, 'global', offer_b, 'global-b')
        recorder.check('R23-SECOND-REAL-OFFER-USES-ROOT-DEFAULT', visible(shown_b, captured_b) and assigned(captured_b, 'return_policy_v1', 'return_a') and assigned(captured_b, 'refund_policy_v1', 'refund_a'), {'different_real_delivery_offer': offer_b > 0 and offer_b != offer_a, 'root_return_default_survives_other_offer_override': assigned(captured_b, 'return_policy_v1', 'return_a'), 'root_refund_default_selected': assigned(captured_b, 'refund_policy_v1', 'refund_a'), 'saved_native_preview_matches': visible(shown_b, captured_b)})
        saved_product = save(admin, 'product', 0, fields('disabled', None, 'assigned', 'refund_b'))
        shown_product, captured_product = preview(admin, 'product', offer_a, 'product-a')
        recorder.check('R23-PRODUCT-DEFAULT-INDEPENDENT-PRECEDENCE', success(saved_product) and visible(shown_product, captured_product) and captured_product['selections']['return_policy_v1']['disposition'] == 'disabled' and assigned(captured_product, 'refund_policy_v1', 'refund_b'), {'actual_native_product_target': success(saved_product), 'product_default_overrides_ancestor_offer': captured_product['selections']['return_policy_v1']['disposition'] == 'disabled', 'independent_product_refund_selected': assigned(captured_product, 'refund_policy_v1', 'refund_b'), 'saved_native_preview_matches': visible(shown_product, captured_product)})
        saved_variation = save(admin, 'variation', 0, fields('assigned', 'return_a', 'inherit'))
        shown_variation, captured_variation = preview(admin, 'variation', offer_a, 'variation-a')
        recorder.check('R23-VARIATION-ASSIGNMENT-OVERRIDES-ANCESTOR-DISABLE', success(saved_variation) and visible(shown_variation, captured_variation) and assigned(captured_variation, 'return_policy_v1', 'return_a') and assigned(captured_variation, 'refund_policy_v1', 'refund_b'), {'server_derived_real_variation_parent': success(saved_variation) and state['products']['variation']['id'] != state['products']['parent']['id'], 'specific_assignment_can_override_ancestor_disable': assigned(captured_variation, 'return_policy_v1', 'return_a'), 'other_family_inherits_product_reference': assigned(captured_variation, 'refund_policy_v1', 'refund_b'), 'saved_native_preview_matches': visible(shown_variation, captured_variation)})
        seeded_offer = save(admin, 'variation', offer_a, fields('assigned', 'return_a', 'assigned', 'refund_a'))
        disable_review = open_review(admin, 'variation', offer_a)
        disable_post = no_javascript_modes(disable_review, return_mode='disabled')
        require(disable_post['return_policy_uuid'] == state['catalogs']['return_a']['policy_uuid'] and disable_post['refund_policy_uuid'] == state['catalogs']['refund_a']['policy_uuid'], 'R23 no-JS disable must retain actual prior selected UUIDs')
        saved_disabled = admin.post(url, disable_post)
        disabled_state = bridge.call('inspect')
        shown_disabled, captured_disabled = preview(admin, 'variation', offer_a, 'variation-a')
        recorder.check('R23-EXPLICIT-DISABLE-DOES-NOT-FALL-BACK', success(saved_disabled) and visible(shown_disabled, captured_disabled) and captured_disabled['selections']['return_policy_v1']['disposition'] == 'disabled' and assigned(captured_disabled, 'refund_policy_v1', 'refund_a'), {'actual_native_disable_saved': success(seeded_offer) and success(saved_disabled) and disable_post['return_policy_uuid'] == state['catalogs']['return_a']['policy_uuid'], 'disabled_return_has_no_version_or_customer_terms': captured_disabled['selections']['return_policy_v1']['disposition'] == 'disabled' and captured_disabled['selections']['return_policy_v1']['reference'] is None and captured_disabled['selections']['return_policy_v1']['fields'] is None and disabled_state['states']['variation']['maps']['return_policy_v1']['offers'][str(offer_a)] == {'mode': 'disabled'}, 'separate_refund_assignment_remains_active': assigned(captured_disabled, 'refund_policy_v1', 'refund_a'), 'saved_native_preview_matches': visible(shown_disabled, captured_disabled)})
        inherit_offer_review = open_review(admin, 'variation', offer_a)
        inherit_offer_post = no_javascript_modes(inherit_offer_review, 'inherit', 'inherit')
        require(inherit_offer_post['refund_policy_uuid'] == state['catalogs']['refund_a']['policy_uuid'], 'R23 no-JS inherit must retain actual prior Refund UUID')
        inherit_offer = admin.post(url, inherit_offer_post)
        inherited_offer_state = bridge.call('inspect')
        _, inherited_default = preview(admin, 'variation', offer_a, 'variation-a')
        inherit_default_review = open_review(admin, 'variation', 0)
        inherit_default_post = no_javascript_modes(inherit_default_review, 'inherit', 'inherit')
        require(inherit_default_post['return_policy_uuid'] == state['catalogs']['return_a']['policy_uuid'], 'R23 no-JS inherit must retain actual prior Return UUID')
        inherit_default = admin.post(url, inherit_default_post)
        inherited_default_state = bridge.call('inspect')
        shown_inherited, inherited_parent = preview(admin, 'variation', offer_a, 'variation-a')
        recorder.check('R23-EXPLICIT-INHERIT-RESTORES-DEFAULT-AND-PARENT', success(inherit_offer) and success(inherit_default) and visible(shown_inherited, inherited_parent), {'native_offer_inherit_restores_same_scope_default': success(inherit_offer) and inherited_offer_state['states']['variation']['maps']['refund_policy_v1']['offers'][str(offer_a)] == {'mode': 'inherit'} and assigned(inherited_default, 'return_policy_v1', 'return_a') and assigned(inherited_default, 'refund_policy_v1', 'refund_b'), 'native_default_inherit_restores_parent_disable': success(inherit_default) and inherited_default_state['states']['variation']['maps']['return_policy_v1']['default'] == {'mode': 'inherit'} and inherited_parent['selections']['return_policy_v1']['disposition'] == 'disabled', 'parent_refund_reference_preserved': assigned(inherited_parent, 'refund_policy_v1', 'refund_b'), 'saved_native_preview_matches': visible(shown_inherited, inherited_parent)})
        _, before_follow = preview(admin, 'global', offer_b, 'global-b')
        before_publication = bridge.call('inspect')
        replacement = bridge.call('replace')
        after_publication = bridge.call('inspect')
        shown_follow, after_follow = preview(admin, 'global', offer_b, 'global-b')
        recorder.check('R23-FOLLOW-CURRENT-PUBLISHED-LOGICAL-POLICY', replacement['replaced'] and visible(shown_follow, after_follow) and assigned(after_follow, 'return_policy_v1', 'return_a', True), {'assignment_map_bytes_remain_identical': before_publication['states'] == after_publication['states'], 'fresh_saved_preview_uses_owned_replacement_head': visible(shown_follow, after_follow) and assigned(after_follow, 'return_policy_v1', 'return_a', True), 'return_material_fingerprint_changed': before_follow['selections']['return_policy_v1']['fingerprint'] != after_follow['selections']['return_policy_v1']['fingerprint'], 'independent_refund_version_and_fingerprint_preserved': before_follow['selections']['refund_policy_v1'] == {**after_follow['selections']['refund_policy_v1'], 'evaluated_at': before_follow['selections']['refund_policy_v1']['evaluated_at']}})
        before_scope = bridge.call('inspect')
        wrong_type = open_review(admin, 'variation', offer_a, state['products']['parent']['id'])
        missing_offer = open_review(admin, 'product', 9223372036854775807)
        bad_open = admin.get(url)
        forged_parent = admin.post(url, dict(form(bad_open, 'open'), scope_type='variation', scope_id=str(state['products']['variation']['id']), offer_id=str(offer_a), parent_id=str(state['products']['variation']['id'])))
        after_scope = bridge.call('inspect')
        recorder.check('R23-NATIVE-SCOPE-PARENT-AND-OFFER-REFUSALS', before_scope['states'] == after_scope['states'] and before_scope['c01_sha256'] == after_scope['c01_sha256'], {'product_id_cannot_be_variation_target': wrong_type.status == 200 and wrong_type.page().has_notice('error', ''), 'missing_real_offer_refused': missing_offer.status == 200 and missing_offer.page().has_notice('error', ''), 'client_parent_authority_refused': forged_parent.status == 200 and forged_parent.page().has_notice('error', ''), 'physical_saved_maps_and_c01_unchanged': before_scope['states'] == after_scope['states'] and before_scope['c01_sha256'] == after_scope['c01_sha256']})
        shown_current, current = preview(admin, 'global', offer_b, 'global-b')
        _, current_again = preview(admin, 'global', offer_b, 'global-b')
        preserved = bridge.call('inspect')
        recorder.check('R23-SAVED-CURRENT-PREVIEW-AND-DELIVERY-PRESERVATION', visible(shown_current, current) and preserved['protected_unchanged'] and preserved['configuration_unchanged'] and preserved['adoption_unchanged'] and preserved['adoption_off'] and preserved['schema'] == '12', {'native_saved_preview_exact_current_wording': visible(shown_current, current) and assigned(current, 'return_policy_v1', 'return_a', True), 'unchanged_material_has_stable_fingerprints': all(current['selections'][family]['fingerprint'] == current_again['selections'][family]['fingerprint'] for family in FAMILIES), 'fresh_sql_evaluation_time_not_reversed': all(current_again['selections'][family]['evaluated_at'] >= current['selections'][family]['evaluated_at'] for family in FAMILIES), 'all_original_nine_delivery_fields_preserved': preserved['configuration_unchanged'], 'other_domain_orders_and_shipments_preserved': preserved['protected_unchanged'], 'original_adoption_bytes_and_both_off_decoders': preserved['adoption_unchanged'] and preserved['adoption_off'], 'schema_unchanged': preserved['schema'] == '12'})
        before_revoke = bridge.call('inspect')
        revoked = bridge.call('revoke')
        revoked_page = admin.get(url)
        after_revoke = bridge.call('inspect')
        recorder.check('R23-NATIVE-LIVE-CAPABILITY-REVOCATION', revoked == {'revoked': True} and before_revoke == after_revoke, {'current_native_capability_revoked': revoked == {'revoked': True}, 'fresh_private_forms_absent': revoked_page.status in (200, 403) and not any(item.fields.get('cetech_de_action', '').startswith(ACTION) for item in revoked_page.page().forms), 'private_policy_names_not_disclosed': all(entry['fields']['name'] not in revoked_page.body.decode(errors='replace') for entry in state['catalogs'].values()), 'physical_original_state_unchanged': before_revoke == after_revoke})
    except Exception as error:
        failure = error
        recorder.report['status'] = 'FAIL'
        recorder.report['error'] = {'class': type(error).__name__ if type(error).__name__ in {'RuntimeError', 'ValueError', 'TypeError', 'KeyError', 'TimeoutExpired', 'JSONDecodeError'} else 'OtherError'}
        recorder.write()
    finally:
        try:
            cleanup = bridge.call('cleanup')
            recorder.check('R23-TRACKED-PHYSICAL-FIXTURE-CLEANUP', all(value is True for value in cleanup.values()), {'original_c01_rows_restored': cleanup.get('original_c01_rows_restored') is True, 'original_delivery_configuration_preserved': cleanup.get('original_delivery_configuration_preserved') is True, 'protected_domain_and_native_rows_preserved': cleanup.get('protected_domain_and_native_rows_preserved') is True, 'owned_native_products_and_offers_removed': cleanup.get('owned_native_products_and_offers_removed') is True, 'owned_assignment_options_removed': cleanup.get('owned_assignment_options_removed') is True, 'owned_users_removed': cleanup.get('owned_users_removed') is True, 'owned_journal_removed': cleanup.get('owned_journal_removed') is True})
        except Exception as error:
            failure = failure or error
            recorder.report['status'] = 'FAIL'
            recorder.write()
    if failure:
        raise RuntimeError('R23 native qualification incomplete; inspect closed receipt') from failure


def finalize(options):
    report = load(options.receipt)
    recorder = Recorder(options.receipt, report)
    stopped, mu, private, cleanup = (value == "1" for value in options.finalize)
    try:
        recorder.check("R23-OWNED-LISTENER-AND-PRIVATE-CLOSEOUT", stopped and mu and private and cleanup, {"owned_listener_stopped_and_waited": stopped, "exact_owned_mu_removed": mu, "private_credentials_and_logs_removed": private, "tracked_cleanup_acknowledged": cleanup})
    finally:
        report["status"] = "PASS" if report.get("status") != "FAIL" and [case["id"] for case in report["cases"]] == [case for case, _ in protocol()] and all(case_valid(case) and case["status"] == "PASS" for case in report["cases"]) else "FAIL"
        recorder.write()
    return verify(options)


def verify(options):
    report = load(options.receipt)
    native, digest = prior(options.work)
    require(set(report) == {"format", "status", *IDENTITY, "installed_php_sources", "environment", "context", "limits", "preceding_native", "producer_sha256", "cases"}, "R23 receipt has unknown envelope")
    require(report["format"] == FORMAT and report["status"] == "PASS" and report["context"] == CONTEXT and report["limits"] == LIMITS, "R23 scope or status differs")
    require(all(report[key] == native[key] for key in IDENTITY + ("installed_php_sources",)) and report["environment"] == {key: native["environment"][key] for key in STACK} and report["preceding_native"] == {"sha256": digest, "cases": 495}, "R23 exact native prerequisite differs")
    require(report["producer_sha256"] == producers(report["source_head"]), "R23 executed producer identity differs")
    require(isinstance(report["cases"], list) and [case.get("id") for case in report["cases"]] == [case for case, _ in protocol()] and all(case_valid(case) and case["status"] == "PASS" for case in report["cases"]), "R23 missing, reordered or unproved observations")
    print("policy_assignment_admin_native=PASS cases=" + str(len(report["cases"])))
    return 0


def setup_diagnostic(path):
    """Failure-only closed last-entered setup step; never disclose private logs."""
    allowed = {"guarded", "actors", "products", "offers", "targets", "seed_return_a", "seed_return_b", "seed_refund_a", "seed_refund_b", "ready"}
    phase = "unavailable"
    try:
        file = Path(path)
        info = file.lstat()
        if stat.S_ISREG(info.st_mode) and not file.is_symlink() and info.st_size <= 262144:
            for line in file.read_text(encoding="utf-8", errors="replace").splitlines():
                if line.startswith("policy_assignment_admin_fixture_step="):
                    candidate = line.removeprefix("policy_assignment_admin_fixture_step=")
                    if candidate in allowed:
                        phase = candidate
    except OSError:
        pass
    print("policy_assignment_admin_last_entered_fixture_step=" + phase)
    return 0


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--work", required=True)
    parser.add_argument("--receipt", required=True)
    parser.add_argument("--initialize", action="store_true")
    parser.add_argument("--run", action="store_true")
    parser.add_argument("--setup-diagnostic")
    parser.add_argument("--finalize", nargs=4)
    for name in ("php", "wpcli", "site", "state", "private"):
        parser.add_argument("--" + name)
    options = parser.parse_args()
    if options.setup_diagnostic:
        return setup_diagnostic(options.setup_diagnostic)
    if options.initialize:
        initialize(options)
    elif options.run:
        run(options, Recorder(options.receipt, load(options.receipt)))
    elif options.finalize:
        return finalize(options)
    else:
        return verify(options)
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception:
        print("policy_assignment_admin_native=FAIL; inspect closed receipt", file=sys.stderr)
        sys.exit(1)
