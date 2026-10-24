#!/usr/bin/env python3
"""Finite R22 native WordPress requests and strict source-derived receipt closure.

CLI bridges prepare/inspect/delete only predeclared owned fixture rows. Every
qualified editor action is an actual nonce/cookie protected native HTTP POST.
"""
import argparse
import ast
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
from urllib.error import URLError
from urllib.request import Request

sys.dont_write_bytecode = True
SOURCE = Path(__file__).resolve().parent
ROOT = SOURCE.parent.parent
FORMAT = "cetech-r22-refund-policy-admin-http-v1"
CONTEXT = "Actual native WordPress admin login, action nonces and separate HTTP requests on the marked disposable fixture"
LIMITS = [
    "Private Refund Policy editor only; no customer projection, assignment, checkout, payment, default policy or runtime activation.",
    "Default quote and promise adoption remain unchanged; source-site, merchant policy and theme acceptance are separate.",
    "Native completed-operation preservation is observed; interrupted-write recovery is independently tested and is not claimed by this HTTP journey.",
    "No whole-database ancillary WordPress or scheduler rollback is certified; this CI site and service are disposable.",
]
PRODUCERS = (
    "scripts/qualification/refund-policy-admin-native.php",
    "scripts/qualification/verify-refund-policy-admin-native.py",
    "scripts/ci-refund-policy-admin-native.sh",
)
REFUND_SOURCES = (
    "src/Domain/RefundPolicy/RefundPolicyContent.php",
    "src/Domain/RefundPolicy/RefundPolicyVersion.php",
    "src/Domain/RefundPolicy/RefundPolicyFamily.php",
    "src/Application/RefundPolicy/RefundPolicyCatalogService.php",
    "src/Integrations/RefundPolicy/NativeRefundPolicyAuthority.php",
    "src/Infrastructure/Persistence/WpdbRefundPolicyAdminIntent.php",
    "src/Application/RefundPolicy/RefundPolicyAdminWorkflow.php",
    "src/Presentation/Admin/RefundPolicyPage.php",
)
IDENTITY = ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash")
STACK = ("php", "wordpress", "woocommerce", "database_version", "hpos", "schema_before")
ACTION = "cetech_de_refund_policy_"
SLUG = "cetech-de-refund-policies"
FIELDS = {
    "name": 'R22 supplied café <script id="r22-proof">literal</script>',
    "customer_title": "Refunds <b>literal</b>",
    "summary": "Supplied first line.\nSupplied café second line.",
    "full_text": "Supplied wording.\r\n\t<em>Literal markup stays text.</em>\\end",
    "exceptions": "",
    "reason": "Owned native Refund editor qualification",
}


def require(condition, message):
    if not condition:
        raise ValueError(message)


def load(path, private=False):
    path = Path(path)
    info = path.lstat()
    require(stat.S_ISREG(info.st_mode) and not path.is_symlink() and info.st_size <= 16777216, "R22 ordinary bounded file required")
    require(not private or stat.S_IMODE(info.st_mode) == 0o600, "R22 private file mode differs")
    return json.loads(path.read_text(encoding="utf-8"))


def canonical(value):
    return json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode()


def protocol():
    declarations = []
    for node in ast.walk(ast.parse(Path(__file__).read_text())):
        if not (isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute) and isinstance(node.func.value, ast.Name) and node.func.value.id == "recorder" and node.func.attr == "check"):
            continue
        require(len(node.args) == 3 and not node.keywords and isinstance(node.args[0], ast.Constant) and isinstance(node.args[0].value, str) and re.fullmatch(r"R22-[A-Z0-9-]+", node.args[0].value), "Ambiguous R22 case declaration")
        require(isinstance(node.args[2], ast.Dict) and node.args[2].keys, "Missing R22 observation declaration")
        fields = []
        for key in node.args[2].keys:
            require(isinstance(key, ast.Constant) and isinstance(key.value, str) and re.fullmatch(r"[a-z0-9_]+", key.value) and key.value not in fields, "Ambiguous R22 observation")
            fields.append(key.value)
        declarations.append((node.lineno, node.args[0].value, tuple(fields)))
    declarations.sort()
    require(0 < len(declarations) <= 32 and len({item[1] for item in declarations}) == len(declarations), "Ambiguous R22 protocol")
    return tuple((case, fields) for _, case, fields in declarations)


def case_valid(case):
    expected = dict(protocol())
    return isinstance(case, dict) and set(case) == {"id", "status", "evidence"} and case.get("id") in expected and case.get("status") in {"PASS", "FAIL"} and isinstance(case.get("evidence"), dict) and set(case["evidence"]) == set(expected[case["id"]]) and all(type(value) is bool for value in case["evidence"].values()) and (case["status"] != "PASS" or all(case["evidence"].values()))


def prior(work):
    path = Path(work) / "opening-qualification-results.json"
    document = load(path)
    require(document.get("status") == "PASS" and len(document.get("cases", [])) == 495 and len({case.get("id") for case in document["cases"]}) == 495 and all(case.get("status") == "PASS" for case in document["cases"]), "Retained native qualification is incomplete")
    for key, variable in (("source_head", "CETECH_DE_QUALIFICATION_HEAD"), ("candidate_head", "CETECH_DE_QUALIFICATION_CANDIDATE_HEAD"), ("source_tree", "CETECH_DE_QUALIFICATION_TREE")):
        require(re.fullmatch(r"[0-9a-f]{40}", str(document.get(key, ""))) and (not os.environ.get(variable) or document[key] == os.environ[variable]), "R22 immutable native authority differs")
    sources = document.get("installed_php_sources")
    require(isinstance(sources, dict) and len(sources) == 955 and all(path in sources for path in REFUND_SOURCES) and list(sources) == sorted(sources) and all(isinstance(path, str) and re.fullmatch(r"[0-9a-f]{64}", str(digest)) for path, digest in sources.items()) and document["installed_php_sources_hash"] == hashlib.sha256(canonical(sources)).hexdigest(), "R22 production map is not canonical")
    return document, hashlib.sha256(path.read_bytes()).hexdigest()


def producers(head):
    pins = {}
    for name in PRODUCERS:
        actual = (ROOT / name).read_bytes()
        committed = subprocess.run(["git", "show", head + ":" + name], cwd=ROOT, check=True, capture_output=True, timeout=15).stdout
        require(actual == committed, "R22 producer differs from executed source authority")
        pins[name] = hashlib.sha256(actual).hexdigest()
    return pins


class Recorder:
    def __init__(self, path, report):
        self.path, self.report = Path(path), report

    def write(self):
        temporary = self.path.with_suffix(".tmp")
        require(not self.path.is_symlink() and not temporary.is_symlink(), "R22 receipt allocation refused")
        temporary.write_text(json.dumps(self.report, indent=2) + "\n")
        temporary.replace(self.path)

    def check(self, case_id, condition, evidence):
        case = {"id": case_id, "status": "PASS" if condition and all(value is True for value in evidence.values()) else "FAIL", "evidence": evidence}
        require(case_valid(case) and not any(item["id"] == case_id for item in self.report["cases"]), "R22 observed case does not match authored protocol")
        self.report["cases"].append(case)
        self.write()
        print("refund_policy_admin_case=" + case_id + " result=" + case["status"], flush=True)
        require(case["status"] == "PASS", "R22 native observation diverged")


def initialize(options):
    native, digest = prior(options.work)
    require(not Path(options.receipt).exists() and not Path(options.receipt).is_symlink(), "R22 refuses an existing receipt")
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
    request = Request(client.resolve("/?cetech_refund_policy_probe=1"), headers={"X-CETECH-Opening-Probe": state["probe_token"]})
    while True:
        try:
            with client.opener.open(request, timeout=1) as response:
                body = response.read(16385)
                require(len(body) <= 16384, "R22 listener probe response exceeded bound")
                return response.code, json.loads(body)
        except (URLError, OSError, TimeoutError):
            if time.monotonic() >= deadline:
                raise RuntimeError("R22 owned listener did not start before its finite deadline")
            time.sleep(0.1)


def run(options, recorder):
    state = load(options.state, private=True)
    require(all(state["identity"].get(key) == recorder.report.get(key) for key in IDENTITY + ("installed_php_sources",)), "R22 private fixture identity differs")
    observed_stack = state["identity"].get("environment", {})
    stack_exact = all(observed_stack.get(key) == recorder.report["environment"].get(key) for key in STACK if key != "schema_before") and observed_stack.get("schema") == recorder.report["environment"].get("schema_before") == "12"
    require(stack_exact, "R22 measured native stack differs from retained source fixture")
    common = module("r22_native_http_common", "opening-http-driver.py")
    bridge_directory = Path(options.private) / "bridge"
    bridge_directory.mkdir(mode=0o700)
    bridge = common.FixtureBridge(options.php, options.wpcli, options.site, str(SOURCE / "admin-context.php"), str(SOURCE / "refund-policy-admin-native.php"), Path(options.state), bridge_directory)
    admin, denied, anonymous = (common.HttpClient(state["base_url"]) for _ in range(3))
    url = state["base_url"] + "/wp-admin/admin.php?page=" + SLUG
    failure = None
    try:
        status, observed = listener_probe(anonymous, state)
        expected = {"format": "cetech-r22-owned-listener-v1", "probe_sha256": hashlib.sha256(state["probe_token"].encode()).hexdigest(), "site_path_sha256": hashlib.sha256(state["site_path"].encode()).hexdigest(), "database_name_sha256": hashlib.sha256(state["database_name"].encode()).hexdigest(), **{key: state["identity"][key] for key in IDENTITY[:3]}, "php": recorder.report["environment"]["php"], "sapi": "cli-server"}
        recorder.check("R22-OWNED-NATIVE-LISTENER", status == 200 and observed == expected and stack_exact, {"possession_proved_before_credentials": status == 200 and observed == expected, "exact_native_source_and_php": observed == expected, "measured_native_stack_matches_retained": stack_exact})

        def login(client, actor):
            client.get("/wp-login.php")
            result = client.post("/wp-login.php", {"log": actor["login"], "pwd": actor["password"], "wp-submit": "Log In", "redirect_to": url, "testcookie": "1"})
            require(result.status in (302, 303) and any(cookie.name.startswith("wordpress_logged_in_") for cookie in client.cookies), "R22 native login refused")

        def form(document, verb):
            return dict(document.page().form_for_action(ACTION + verb).fields)

        def post(document, verb, extra=None):
            return admin.post(url, dict(form(document, verb), **(extra or {})))

        def success(document):
            return document.status == 200 and document.page().has_notice("success", "policy change was confirmed")

        def open_version(document, version):
            forms = [item for item in document.page().forms if item.fields.get("cetech_de_action") == ACTION + "open" and item.fields.get("policy_uuid") == version["policy_uuid"] and item.fields.get("version_uuid") == version["version_uuid"]]
            require(len(forms) == 1, "R22 exact native version selector missing")
            return admin.post(url, dict(forms[0].fields))

        login(admin, state["admin"])
        rendered = admin.get(url)
        new_form = form(rendered, "new")
        recorder.check("R22-AUTHENTICATED-PRIVATE-EDITOR", rendered.status == 200 and bool(new_form["cetech_de_nonce"]), {"native_admin_cookie": any(cookie.name.startswith("wordpress_") and cookie.path.startswith("/wp-admin") for cookie in admin.cookies), "native_page_and_action_nonce": rendered.status == 200 and new_form["cetech_de_action"] == ACTION + "new" and bool(new_form["cetech_de_nonce"]), "no_json_authority_fields": not any(key in item.fields for item in rendered.page().forms for key in ("command_json", "author_user_id", "site_id", "request_token", "logical_revision"))})
        login(denied, state["denied"])
        denied_page = denied.get(url)
        recorder.check("R22-NATIVE-UNPRIVILEGED-ACTOR-REFUSED", denied_page.status in (200, 403) and not any(item.fields.get("cetech_de_action", "").startswith(ACTION) for item in denied_page.page().forms), {"actual_separate_actor_login": any(cookie.name.startswith("wordpress_logged_in_") for cookie in denied.cookies), "private_action_forms_absent": not any(item.fields.get("cetech_de_action", "").startswith(ACTION) for item in denied_page.page().forms)})

        opened = post(rendered, "new")
        create_fields = form(opened, "create")
        bridge.call("register")
        baseline = bridge.call("inspect")
        bad_nonce = admin.post(url, dict(create_fields, **FIELDS, cetech_de_nonce="r22-invalid-native-nonce"))
        after_nonce = bridge.call("inspect")
        recorder.check("R22-NATIVE-NONCE-REFUSAL-ZERO-EFFECT", bad_nonce.status in (302, 303) and baseline == after_nonce, {"actual_native_nonce_refused": bad_nonce.status in (302, 303), "physical_sources_and_journal_unchanged": baseline == after_nonce, "no_refund_version_created": after_nonce["versions"] == []})
        forged = admin.post(url, dict(create_fields, **FIELDS, author_user_id=str(state["denied"]["id"])))
        after_forged = bridge.call("inspect")
        # A genuine Refund review ticket/nonce cannot dispatch the independent
        # Return action. The native handler owns the refusal, with no fixture stub.
        cross_family = admin.post(url, dict(create_fields, **FIELDS, cetech_de_action="cetech_de_return_policy_create"))
        after_cross_family = bridge.call("inspect")
        recorder.check("R22-POSTED-AUTHOR-REFUSED-ZERO-EFFECT", forged.status == 200 and baseline == after_forged and cross_family.status in (200, 302, 303) and baseline == after_cross_family, {"native_handler_rejects_extra_author_field": forged.status == 200 and forged.page().has_notice("error", "could not be confirmed"), "server_original_review_and_sources_unchanged": baseline == after_forged, "return_action_cannot_dispatch_refund": cross_family.status in (200, 302, 303) and baseline == after_cross_family and after_cross_family["return_policy_unchanged"]})

        created = admin.post(url, dict(create_fields, **FIELDS))
        once = bridge.call("inspect")
        require(len(once["versions"]) == 1, "R22 one original draft expected")
        original = once["versions"][0]
        recorder.check("R22-NATIVE-CREATE-EXACT-AUTHORED-BYTES", success(created) and original["fields"] == {key: value for key, value in FIELDS.items() if key != "reason"}, {"actual_native_create_confirmed": success(created), "unicode_multiline_markup_and_slash_preserved": original["fields"] == {key: value for key, value in FIELDS.items() if key != "reason"}, "server_author_and_reason": original["author"] == state["admin"]["id"] and original["reason"] == FIELDS["reason"], "one_draft_one_operation": original["state"] == "draft" and once["operation_count"] == 1})
        replayed = admin.post(url, dict(create_fields, **FIELDS))
        replay = bridge.call("inspect")
        recorder.check("R22-REPEATED-COMPLETED-POST-NO-REWRITE", replayed.status == 200 and replay == once, {"separate_native_request": replayed.status == 200, "original_receipt_and_body_unchanged": replay == once, "no_duplicate_version_or_operation": len(replay["versions"]) == 1 and replay["operation_count"] == 1})

        reopened = open_version(created, original)
        edit_form = form(reopened, "edit")
        body = reopened.body.decode("utf-8", "strict")
        recorder.check("R22-FRESH-REOPEN-ESCAPED-PRIVATE-TEXT", reopened.status == 200 and all(edit_form[key] == value for key, value in FIELDS.items()), {"fresh_native_get_and_post_open": reopened.status == 200, "exact_editor_fields": all(edit_form[key] == value for key, value in FIELDS.items()), "markup_escaped_in_native_html": '<script id="r22-proof">literal</script>' not in body and "&lt;script" in body and "&lt;em&gt;" in body})
        second_open = open_version(reopened, original)
        second_form = form(second_open, "edit")
        bridge.call("register")
        before_stale = bridge.call("inspect")
        changed = dict(FIELDS, summary=FIELDS["summary"] + "\nA supplied edit.", reason="Owned native reviewed edit")
        stale = admin.post(url, dict(edit_form, **changed))
        after_stale = bridge.call("inspect")
        recorder.check("R22-SUPERSEDED-REVIEW-TICKET-REFUSED", stale.status == 200 and before_stale == after_stale and edit_form["ticket"] != second_form["ticket"], {"server_review_ticket_rotated": edit_form["ticket"] != second_form["ticket"], "stale_native_post_has_no_effect": stale.status == 200 and before_stale == after_stale, "current_review_remains_usable": after_stale["journal_state"] == "review"})
        edited = admin.post(url, dict(second_form, **changed))
        after_edit = bridge.call("inspect")
        require(len(after_edit["versions"]) == 1, "R22 edited draft census differs")
        saved = after_edit["versions"][0]
        recorder.check("R22-NATIVE-DRAFT-EDIT-EXACT", success(edited) and saved["fields"] == {key: value for key, value in changed.items() if key != "reason"}, {"actual_edit_post_confirmed": success(edited), "server_target_retained": saved["policy_uuid"] == original["policy_uuid"] and saved["version_uuid"] == original["version_uuid"], "supplied_edit_author_and_reason": saved["fields"] == {key: value for key, value in changed.items() if key != "reason"} and saved["author"] == state["admin"]["id"] and saved["reason"] == changed["reason"], "one_draft_two_operations": saved["state"] == "draft" and after_edit["operation_count"] == 2})
        review_publish = open_version(edited, saved)
        bridge.call("register")
        published = post(review_publish, "publish")
        after_publish = bridge.call("inspect")
        published_version = after_publish["versions"][0]
        recorder.check("R22-NATIVE-PUBLISH-ORIGINAL-SAVED-CONTENT", success(published) and published_version["state"] == "published", {"actual_publish_post_confirmed": success(published), "saved_content_digest_unchanged": published_version["body_sha256"] == saved["body_sha256"], "saved_author_and_reason_unchanged": published_version["author"] == saved["author"] and published_version["reason"] == saved["reason"], "one_published_version_three_operations": len(after_publish["versions"]) == 1 and published_version["state"] == "published" and after_publish["operation_count"] == 3})
        review_retire = open_version(published, published_version)
        bridge.call("register")
        retired = post(review_retire, "retire")
        after_retire = bridge.call("inspect")
        retired_version = after_retire["versions"][0]
        recorder.check("R22-NATIVE-RETIRE-PRESERVES-SAVED-CONTENT", success(retired) and retired_version["state"] == "retired", {"actual_retire_post_confirmed": success(retired), "exact_saved_body_author_reason_retained": retired_version["body_sha256"] == saved["body_sha256"] and retired_version["author"] == saved["author"] and retired_version["reason"] == saved["reason"], "one_retained_version_four_operations": len(after_retire["versions"]) == 1 and retired_version["state"] == "retired" and after_retire["operation_count"] == 4})
        before_revoke = bridge.call("inspect")
        revoked = bridge.call("revoke")
        revoked_page = admin.get(url)
        after_revoke = bridge.call("inspect")
        recorder.check("R22-NATIVE-LIVE-CAPABILITY-REVOCATION", revoked == {"revoked": True} and before_revoke == after_revoke, {"current_native_capability_revoked": revoked == {"revoked": True}, "fresh_request_private_forms_absent": revoked_page.status in (200, 403) and not any(item.fields.get("cetech_de_action", "").startswith(ACTION) for item in revoked_page.page().forms), "saved_private_name_not_disclosed": FIELDS["name"] not in revoked_page.body.decode(errors="replace") and "r22-proof" not in revoked_page.body.decode(errors="replace"), "physical_rows_unchanged": before_revoke == after_revoke})
        recorder.check("R22-NATIVE-PRESERVATION-AND-ADOPTION-OFF", after_revoke["protected_unchanged"] and after_revoke["return_policy_unchanged"] and after_revoke["adoption_unchanged"] and after_revoke["adoption_off"] and after_revoke["schema"] == "12", {"other_domain_orders_and_shipments_preserved": after_revoke["protected_unchanged"], "independent_return_catalog_and_journal_preserved": after_revoke["return_policy_unchanged"], "original_adoption_bytes_preserved": after_revoke["adoption_unchanged"], "production_decoders_both_enabled_false": after_revoke["adoption_off"], "schema_unchanged": after_revoke["schema"] == "12"})
    except Exception as error:
        failure = error
        recorder.report["status"] = "FAIL"
        recorder.report["error"] = {"class": type(error).__name__ if type(error).__name__ in {"RuntimeError", "ValueError", "TypeError", "KeyError", "TimeoutExpired", "JSONDecodeError"} else "OtherError"}
        recorder.write()
    finally:
        try:
            cleanup = bridge.call("cleanup")
            recorder.check("R22-TRACKED-PHYSICAL-FIXTURE-CLEANUP", all(value is True for value in cleanup.values()), {"original_c01_rows_restored": cleanup.get("original_c01_rows_restored") is True, "protected_domain_and_native_rows_preserved": cleanup.get("protected_domain_and_native_rows_preserved") is True, "owned_users_removed": cleanup.get("owned_users_removed") is True, "owned_journal_removed": cleanup.get("owned_journal_removed") is True})
        except Exception as error:
            failure = failure or error
            recorder.report["status"] = "FAIL"
            recorder.write()
    if failure:
        raise RuntimeError("R22 native qualification incomplete; inspect closed receipt") from failure


def finalize(options):
    report = load(options.receipt)
    recorder = Recorder(options.receipt, report)
    stopped, mu, private, cleanup = (value == "1" for value in options.finalize)
    try:
        recorder.check("R22-OWNED-LISTENER-AND-PRIVATE-CLOSEOUT", stopped and mu and private and cleanup, {"owned_listener_stopped_and_waited": stopped, "exact_owned_mu_removed": mu, "private_credentials_and_logs_removed": private, "tracked_cleanup_acknowledged": cleanup})
    finally:
        report["status"] = "PASS" if report.get("status") != "FAIL" and [case["id"] for case in report["cases"]] == [case for case, _ in protocol()] and all(case_valid(case) and case["status"] == "PASS" for case in report["cases"]) else "FAIL"
        recorder.write()
    return verify(options)


def verify(options):
    report = load(options.receipt)
    native, digest = prior(options.work)
    require(set(report) == {"format", "status", *IDENTITY, "installed_php_sources", "environment", "context", "limits", "preceding_native", "producer_sha256", "cases"}, "R22 receipt has unknown envelope")
    require(report["format"] == FORMAT and report["status"] == "PASS" and report["context"] == CONTEXT and report["limits"] == LIMITS, "R22 scope or status differs")
    require(all(report[key] == native[key] for key in IDENTITY + ("installed_php_sources",)) and report["environment"] == {key: native["environment"][key] for key in STACK} and report["preceding_native"] == {"sha256": digest, "cases": 495}, "R22 exact native prerequisite differs")
    require(report["producer_sha256"] == producers(report["source_head"]), "R22 executed producer identity differs")
    require(isinstance(report["cases"], list) and [case.get("id") for case in report["cases"]] == [case for case, _ in protocol()] and all(case_valid(case) and case["status"] == "PASS" for case in report["cases"]), "R22 missing, reordered or unproved observations")
    print("refund_policy_admin_native=PASS cases=" + str(len(report["cases"])))
    return 0


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--work", required=True)
    parser.add_argument("--receipt", required=True)
    parser.add_argument("--initialize", action="store_true")
    parser.add_argument("--run", action="store_true")
    parser.add_argument("--finalize", nargs=4)
    for name in ("php", "wpcli", "site", "state", "private"):
        parser.add_argument("--" + name)
    options = parser.parse_args()
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
        print("refund_policy_admin_native=FAIL; inspect closed receipt", file=sys.stderr)
        sys.exit(1)
