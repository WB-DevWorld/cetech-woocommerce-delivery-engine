#!/usr/bin/env python3
"""Adversarial public/receipt/URI protocol checks; not native quote qualification."""
import copy
import html
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).parent
SPEC = importlib.util.spec_from_file_location("q05_driver", ROOT / "opening-http-quote-cart-driver.py")
DRIVER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DRIVER)


def public_facts():
    value = {"amount": "7.70", "currency": "GHS", "precision": 2}
    part = {"customer_label": "Delivery", "list_price": value, "final_price": value, "tax": {"amount": "0", "currency": "GHS", "precision": 2}, "rounded_tax": None, "total": value, "display_total": value, "promotion": {"state": "none", "amount": {"amount": "0", "currency": "GHS", "precision": 2}}}
    return {"contract_version": 1, "status": "review_required", "generation": 1, "quote": {"contract_version": 1, "decision_kind": "delivery_quote", "quote_id": "a49d6a70-4dad-4a89-94a7-0bde7d1e9d23", "status": "issued", "currently_applicable": True, "expires_at": "2026-10-07T12:00:00.000000Z", "customer_label": "Delivery", "money": [part], "reason_code": None, "recovery_action": None, "correlation_id": "96e70da7-a0b7-4eb4-a291-3da4c0302af1"}, "can_refresh": True, "can_confirm": True, "can_retry": False, "message_code": "review_required", "correlation_id": "dd72a7a1-cbfc-4f70-bde1-3f00b7c9986e"}


def child_helpers(expression):
    source = (ROOT / "opening-quote-cart-browser.cjs").read_text()
    # Evaluate the pure helpers only, never launch a browser or read credentials.
    route = source[source.index("function nativeRouteMatches("):source.index("function noPlacement(")]
    schema = source[source.index("function safeMoney("):source.index("write();\n(async")]
    program = "const base=new URL('http://127.0.0.1:8085');const own=value=>{const u=new URL(value,base);return u.origin===base.origin&&!u.username&&!u.password&&!u.hash;};\n" + route + schema + "\nconsole.log(JSON.stringify(" + expression + "));"
    result = subprocess.run(["node", "-e", program], capture_output=True, timeout=20, check=False)
    if result.returncode != 0:
        raise AssertionError("Pure browser protocol did not finish")
    return json.loads(result.stdout)


class QuoteCartProtocol(unittest.TestCase):
    def test_source_registration_counterfactual_is_finite_optional_and_failure_only(self):
        probe = {"observation": "pure_registration_counterfactual_original_attempt_not_retried", "native_query_singleton_present": True, "native_query_tuple_count": 1, "pre_get_posts_callback_count": 1, "capture_without_exact_tuple": True, "original_hook_restored": True}
        facts = {"observation": "original_native_attempt", "prepare_entered": True, "prepare_returned": False, "prepare_error_class": "RuntimeException", "prepare_refusal_site": "source_local_binding", "prepare_refusal_line": 51, "evidence_called": False, "evidence_returned": False, "native_shipping_debug_enabled": None, "native_chosen_cache_present": False, "native_totals_cache_present": False, "native_shipping_cache_present": False, "source_reads": 0, "quote_writes": 0, "budget_writes": 0}
        self.assertTrue(DRIVER.native_failure_observation(facts))
        facts["source_registration_probe"] = probe
        self.assertTrue(DRIVER.native_failure_observation(facts))
        for key, value in (("observation", "PRIVATE-COOKIE"), ("native_query_tuple_count", True), ("native_query_tuple_count", 257), ("pre_get_posts_callback_count", 0), ("capture_without_exact_tuple", "PRIVATE-COOKIE"), ("original_hook_restored", 1), ("native_query_singleton_present", False)):
            with self.subTest(key=key, value=value):
                bad = copy.deepcopy(facts); bad["source_registration_probe"][key] = value
                self.assertFalse(DRIVER.native_failure_observation(bad))
        bad = copy.deepcopy(facts); bad["source_registration_probe"]["private_callback"] = "PRIVATE-COOKIE"; self.assertFalse(DRIVER.native_failure_observation(bad))
        bad = copy.deepcopy(facts); bad["prepare_refusal_site"] = "legacy_source"; self.assertFalse(DRIVER.native_failure_observation(bad))
        bad = copy.deepcopy(facts); bad["prepare_returned"] = True; self.assertFalse(DRIVER.native_failure_observation(bad))
        bad = copy.deepcopy(facts); bad["source_registration_probe"].update(native_query_tuple_count=2, pre_get_posts_callback_count=2, capture_without_exact_tuple=None); self.assertTrue(DRIVER.native_failure_observation(bad))
        bad["source_registration_probe"]["capture_without_exact_tuple"] = True; self.assertFalse(DRIVER.native_failure_observation(bad))

    def test_exact_valid_dto_agrees_in_python_and_browser(self):
        facts = public_facts()
        self.assertTrue(DRIVER.safe_facts(facts))
        self.assertTrue(child_helpers("safeFacts(" + json.dumps(facts) + ")"))

    def test_nested_private_field_cannot_hide_behind_money_or_promotion(self):
        values = []
        for place in ("root", "quote", "money", "promotion"):
            facts = public_facts()
            target = facts if place == "root" else facts["quote"] if place == "quote" else facts["quote"]["money"][0] if place == "money" else facts["quote"]["money"][0]["promotion"]
            target["renamed_internal_context"] = {"data": "PRIVATE-address"}
            values.append(facts)
            self.assertFalse(DRIVER.safe_facts(facts))
        self.assertEqual([False] * 4, child_helpers(json.dumps(values) + ".map(safeFacts)"))

    def test_reference_or_address_value_cannot_hide_in_safe_label(self):
        for marker in ("PRIVATE-address", "acceptance_handle", "owner_digest", "session_hash", "rate_card", "supplier", "origin_id", "issue_context_json"):
            with self.subTest(marker=marker):
                facts = public_facts(); facts["quote"]["money"][0]["customer_label"] = marker
                self.assertFalse(DRIVER.safe_facts(facts))

    def test_unknown_status_array_and_false_integer_refuse(self):
        for key, value in (("status", {}), ("generation", True), ("contract_version", True), ("can_confirm", 1)):
            facts = public_facts(); facts[key] = value
            self.assertFalse(DRIVER.safe_facts(facts))

    def test_money_fraction_not_rounded_into_claimed_precision(self):
        facts = public_facts(); facts["quote"]["money"][0]["tax"] = {"amount": "0.235", "currency": "GHS", "precision": 2}
        self.assertFalse(DRIVER.safe_facts(facts)); self.assertFalse(child_helpers("safeFacts(" + json.dumps(facts) + ")"))

    def test_unavailable_promotion_is_null_not_inferred_zero(self):
        facts = public_facts(); facts["quote"]["money"][0]["promotion"] = {"state": "unavailable", "amount": None}
        self.assertTrue(DRIVER.safe_facts(facts))
        facts["quote"]["money"][0]["promotion"]["amount"] = {"amount": "0", "currency": "GHS", "precision": 2}
        self.assertFalse(DRIVER.safe_facts(facts))

    def test_component_budget_and_private_sentinel_are_bounded(self):
        facts = public_facts(); facts["quote"]["money"] *= 201
        self.assertFalse(DRIVER.safe_facts(facts))

    def test_classic_html_bytes_are_read_without_js_execution(self):
        facts = public_facts(); body = ('<div data-quote-review-transport="classic" data-quote-review-facts="' + html.escape(json.dumps(facts), quote=True) + '"></div>').encode()
        self.assertEqual([facts], DRIVER.ReviewPage(body).facts)

    def test_native_plain_pretty_encoded_routes_and_ambiguous_rejections(self):
        native_plain = "/?rest_route=/wc/store/v1/cart/extensions"
        native_pretty = "/wp-json/wc/store/v1/cart/extensions"
        pairs = [(native_plain, native_plain, True), ("/?rest_route=%2Fwc%2Fstore%2Fv1%2Fcart%2Fextensions", native_plain, True), (native_pretty + "/", native_pretty, True), ("/?rest_route=/wc/store/v1/cart/extensions&rest_route=/other", native_plain, False), ("/?rest_route=/other&rest_route=/wc/store/v1/cart/extensions", native_plain, False), ("http://127.0.0.1:8086/?rest_route=/wc/store/v1/cart/extensions", native_plain, False), ("http://user@127.0.0.1:8085/?rest_route=/wc/store/v1/cart/extensions", native_plain, False), (native_plain + "#fragment", native_plain, False), ("/?rest_route=/wc/store/v1/cart", native_plain, False)]
        expression = json.dumps(pairs) + ".map(([observed,native,expected])=>nativeRouteMatches(observed,native)===expected)"
        self.assertEqual([True] * len(pairs), child_helpers(expression))

    def test_browser_receipt_rejects_arbitrary_nested_private_diagnostics(self):
        case = {"id": DRIVER.BROWSER_IDS[0], "status": "FAIL", "evidence": {"stage": "render", "error_class": "TimeoutError", "dom": dict.fromkeys(("blocks_visible", "review_visible", "refresh_visible", "confirm_visible", "price_visible", "confirmed_visible"), False), "required_case_incomplete": True}}
        self.assertTrue(DRIVER.browser_evidence(case))
        bad = copy.deepcopy(case); bad["evidence"]["error_message"] = "PRIVATE-cookie"; self.assertFalse(DRIVER.browser_evidence(bad))
        bad = copy.deepcopy(case); bad["evidence"]["dom"]["review_visible"] = "PRIVATE-cookie"; self.assertFalse(DRIVER.browser_evidence(bad))
        bad = copy.deepcopy(case); bad["evidence"]["stage"] = "PRIVATE-form"; self.assertFalse(DRIVER.browser_evidence(bad))

    def test_browser_pass_requires_all_finite_fields_and_actual_runtime(self):
        counts = dict.fromkeys(DRIVER.HISTORY, 0)
        evidence = dict.fromkeys(DRIVER.BROWSER_BOOLS[0], True)
        evidence.update(history_before=counts, history_after=counts, runtime={"playwright": "1.58.2", "chromium": "145.0.7632.6"})
        case = {"id": DRIVER.BROWSER_IDS[0], "status": "PASS", "evidence": evidence}
        self.assertTrue(DRIVER.browser_evidence(case))
        bad = copy.deepcopy(case); del bad["evidence"]["runtime"]; self.assertFalse(DRIVER.browser_evidence(bad))
        bad = copy.deepcopy(case); bad["evidence"]["runtime"]["chromium"] = "PRIVATE-path"; self.assertFalse(DRIVER.browser_evidence(bad))
        bad = copy.deepcopy(case); bad["evidence"]["history_after"]["accepted"] = True; self.assertFalse(DRIVER.browser_evidence(bad))

    def test_fixed_inventory_is_thirteen_unique_ids(self):
        ids = DRIVER.DIRECT_IDS + DRIVER.BROWSER_IDS
        self.assertEqual(13, len(ids)); self.assertEqual(13, len(set(ids)))

    def test_valid_partial_browser_failure_is_retained_before_rejection(self):
        counts = dict.fromkeys(DRIVER.HISTORY, 0)
        first = dict.fromkeys(DRIVER.BROWSER_BOOLS[0], True)
        first.update(history_before=counts, history_after=counts, runtime={"playwright": "1.58.2", "chromium": "145.0.7632.6"})
        partial = [{"id": DRIVER.BROWSER_IDS[0], "status": "PASS", "evidence": first}, {"id": DRIVER.BROWSER_IDS[1], "status": "FAIL", "evidence": {"stage": "refresh", "error_class": "TimeoutError", "dom": dict.fromkeys(("blocks_visible", "review_visible", "refresh_visible", "confirm_visible", "price_visible", "confirmed_visible"), False), "required_case_incomplete": True}}]
        identity = dict.fromkeys(("source_head", "candidate_head", "source_tree", "installed_php_sources_hash"), "fixture")
        report = dict(identity, format="cetech-w2q05-cart-browser-v1", status="FAIL", cases=partial)
        class Recorder:
            def __init__(self): self.cases = []
            def check(self, case_id, condition, evidence):
                self.cases.append((case_id, condition, evidence))
                if not condition: raise RuntimeError("Protocol recorded failure")
        recorder = Recorder()
        with tempfile.TemporaryDirectory() as directory:
            state_path = Path(directory) / "private-state.json"; state_path.write_text("{}")
            def child(command, **unused):
                receipt = Path(command[command.index("--receipt") + 1]); receipt.write_text(json.dumps(report))
                return subprocess.CompletedProcess(command, 1, b"", b"")
            with patch.object(DRIVER.subprocess, "run", child):
                with self.assertRaises(RuntimeError):
                    DRIVER.run_browser({"state_path": str(state_path), "identity": identity}, recorder, type("Client", (), {"base_url": "http://127.0.0.1:8085"})())
        self.assertEqual([(DRIVER.BROWSER_IDS[0], True), (DRIVER.BROWSER_IDS[1], False)], [(case_id, condition) for case_id, condition, unused in recorder.cases])


if __name__ == "__main__":
    unittest.main()
