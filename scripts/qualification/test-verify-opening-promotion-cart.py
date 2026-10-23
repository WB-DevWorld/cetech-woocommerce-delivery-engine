#!/usr/bin/env python3
"""Counterfeit N18 PASS packets; synthetic observations never qualify a native site."""
from copy import deepcopy
from contextlib import redirect_stdout
import importlib.util
import io
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("n18_cart_receipt_verifier_test", Path(__file__).with_name("verify-opening-promotion-cart.py"))
verifier = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = verifier
spec.loader.exec_module(verifier)


def packets():
    sources = {"src/DeclaredFixture.php": "d" * 64}
    identity = {"source_head": "a" * 40, "candidate_head": "b" * 40, "source_tree": "c" * 40, "installed_php_sources_hash": verifier.placement.canonical_hash(sources)}
    environment = {"php": "8.5.12", "wordpress": "7.1.2", "woocommerce": "11.1.2", "database_version": "11.4.13-MariaDB-ubu2404", "hpos": "yes", "schema": "12", **verifier.placement.ENVIRONMENT_SCOPE["http"]}
    state = {"identity": {**identity, "installed_php_sources": sources, "environment": environment}, "fixture_token": "synthetic test only"}
    specifications = verifier.protocol()
    runtime = {"playwright": "1.58.2", "chromium": "145.0.7632.6"}
    counter = dict.fromkeys(verifier.HISTORY, 0)
    reports = []
    for kind, specs in enumerate(specifications):
        report = {"format": verifier.KINDS[kind], "status": "PASS", **identity, "cases": []}
        if kind == 1:
            report.update(runtime=deepcopy(runtime), stage="complete")
        else:
            report["environment"] = deepcopy(environment)
            if kind == 2:
                report["stage"] = "complete"
        for index, (case_id, fields) in enumerate(specs):
            before = deepcopy(counter)
            action = (("same", "same", "refresh", "same", "same", "confirm", "same"), ("same", "refresh", "confirm"), ("review", "place", "same"))[kind][index]
            if action in ("refresh", "review"):
                counter["quotes"] += 1; counter["budget"] += 1
            if action in ("confirm", "review"):
                counter["accepted"] += 1
            if action == "place":
                counter["bindings"] += 1
            for key in ("records", "events"):
                counter[key] += {"same": 0, "refresh": 1, "confirm": 1, "review": 2, "place": 3}[action]
            facts = dict.fromkeys(fields, True)
            facts.update(history_before=before, history_after=deepcopy(counter))
            if "runtime" in facts:
                facts["runtime"] = deepcopy(runtime)
            if "status" in facts:
                facts["status"] = 200
            for key in ("classic_status", "store_status"):
                if key in facts:
                    facts[key] = 403
            if "http" in facts:
                facts["http"] = {"ordinal": 4, "method": "POST", "status": 200, "route": "/", "elapsed_ms": 30, "headers_ms": 20, "body_ms": 10, "phase": "body", "body_sha256": "e" * 64, "body_bytes": 500}
            report["cases"].append({"id": case_id, "status": "PASS", "evidence": facts})
        reports.append(report)
    return reports, state, identity, sources, specifications


class PromotionCartReceiptRefusals(unittest.TestCase):
    def setUp(self):
        self.reports, self.state, self.identity, self.sources, self.specifications = packets()

    def check(self, reports=None, state=None):
        return verifier.verify_reports(self.reports if reports is None else reports, self.state if state is None else state, self.identity, self.sources, self.specifications)

    def test_complete_closed_source_inventory_passes(self):
        self.assertEqual((7, 3, 3), self.check())
        self.assertEqual([7, 3, 3], [len(suite) for suite in self.specifications])

    def test_repeated_arbitrary_status_pass_objects_cannot_qualify_any_lane(self):
        for kind in range(3):
            reports = deepcopy(self.reports); reports[kind]["cases"] = [{"status": "PASS"}] * len(reports[kind]["cases"])
            with self.subTest(kind=kind), self.assertRaises(RuntimeError):
                self.check(reports)

    def test_missing_duplicate_reordered_or_foreign_cases_refuse(self):
        for kind in range(3):
            for change in ("missing", "duplicate", "reorder", "foreign", "extra"):
                reports = deepcopy(self.reports); cases = reports[kind]["cases"]
                if change == "missing": cases.pop()
                elif change == "duplicate": cases[-1] = deepcopy(cases[0])
                elif change == "reorder": cases.reverse()
                elif change == "foreign": cases[0]["id"] = "HTTP-OTHER-PASS"
                else: cases.append(deepcopy(cases[0]))
                with self.subTest(kind=kind, change=change), self.assertRaises(RuntimeError):
                    self.check(reports)
        with self.assertRaises(RuntimeError):
            self.check(self.reports[:2])

    def test_false_integer_extra_or_missing_evidence_refuses_each_lane(self):
        for kind in range(3):
            for change in ("false", "integer", "extra", "missing"):
                reports = deepcopy(self.reports); facts = reports[kind]["cases"][0]["evidence"]
                key = next(key for key, value in facts.items() if value is True)
                if change == "false": facts[key] = False
                elif change == "integer": facts[key] = 1
                elif change == "extra": facts["private_packet"] = "unapproved"
                else: del facts[key]
                with self.subTest(kind=kind, change=change), self.assertRaises(RuntimeError):
                    self.check(reports)

    def test_receipt_and_state_identity_cannot_self_declare_a_different_source(self):
        for field in verifier.IDENTITY:
            reports = deepcopy(self.reports); state = deepcopy(self.state)
            for report in reports: report[field] = "f" * len(report[field])
            state["identity"][field] = reports[0][field]
            with self.subTest(field=field), self.assertRaises(RuntimeError):
                self.check(reports, state)
        state = deepcopy(self.state); state["identity"]["installed_php_sources"]["src/DeclaredFixture.php"] = "f" * 64
        with self.assertRaises(RuntimeError): self.check(state=state)

    def test_top_level_errors_extras_and_alternate_native_runtime_refuse(self):
        for kind in range(3):
            for change in ("extra", "failed", "format"):
                reports = deepcopy(self.reports)
                if change == "extra": reports[kind]["error"] = "hidden failure"
                elif change == "failed": reports[kind]["status"] = "FAIL"
                else: reports[kind]["format"] = verifier.KINDS[(kind + 1) % 3]
                with self.subTest(kind=kind, change=change), self.assertRaises(RuntimeError): self.check(reports)
        for field, value in (("hpos", "no"), ("schema", "11"), ("wordpress", "7.1.3"), ("database_version", "11.8.8-MariaDB")):
            reports = deepcopy(self.reports); reports[0]["environment"][field] = value
            with self.subTest(field=field), self.assertRaises(RuntimeError): self.check(reports)

    def test_browser_must_complete_on_pinned_playwright_with_actual_chromium(self):
        for change in ("stage", "playwright", "chromium", "runtime_extra", "case_runtime"):
            reports = deepcopy(self.reports); browser = reports[1]
            if change == "stage": browser["stage"] = "refresh"
            elif change == "playwright": browser["runtime"]["playwright"] = "1.58.3"
            elif change == "chromium": browser["runtime"]["chromium"] = None
            elif change == "runtime_extra": browser["runtime"]["invented"] = True
            else: browser["cases"][0]["evidence"]["runtime"]["chromium"] = "0.0.0.0"
            with self.subTest(change=change), self.assertRaises(RuntimeError): self.check(reports)

    def test_counterfeit_history_arithmetic_and_zero_effect_claims_refuse(self):
        for kind, index, field in ((0, 0, "records"), (0, 2, "quotes"), (0, 5, "accepted"), (1, 1, "quotes"), (1, 2, "accepted"), (2, 0, "quotes"), (2, 1, "bindings"), (2, 2, "events")):
            reports = deepcopy(self.reports); reports[kind]["cases"][index]["evidence"]["history_after"][field] += 1
            with self.subTest(kind=kind, index=index, field=field), self.assertRaises(RuntimeError): self.check(reports)
        for change in ("boolean", "negative", "extra", "continuity", "all_zero"):
            reports = deepcopy(self.reports); facts = reports[0]["cases"][0]["evidence"]
            if change == "boolean": facts["history_before"]["records"] = True
            elif change == "negative": facts["history_before"]["records"] = -1
            elif change == "extra": facts["history_before"]["orders"] = 0
            elif change == "continuity": reports[1]["cases"][0]["evidence"]["history_before"]["records"] += 10
            else:
                for report in reports:
                    for case in report["cases"]:
                        for side in ("history_before", "history_after"): case["evidence"][side] = dict.fromkeys(verifier.HISTORY, 0)
            with self.subTest(change=change), self.assertRaises(RuntimeError): self.check(reports)

    def test_http_observations_are_closed_typed_native_post_facts(self):
        for field, value in (("status", True), ("method", "GET"), ("route", "https://example.invalid/private"), ("body_bytes", 0), ("body_sha256", "not a digest"), ("ordinal", 0)):
            reports = deepcopy(self.reports); reports[0]["cases"][2]["evidence"]["http"][field] = value
            with self.subTest(field=field), self.assertRaises(RuntimeError): self.check(reports)

    def test_cleanup_and_closeout_cannot_accept_arbitrary_repeated_pass_flags(self):
        promotion = {"tracked_promotion_cleanup_restored": True}; cart = dict.fromkeys(verifier.CART_CLEANUP, True)
        verifier.cleanup(promotion, cart)
        for value in ({"status": "PASS"}, {}, {**promotion, "extra": True}, {"tracked_promotion_cleanup_restored": 1}):
            with self.assertRaises(RuntimeError): verifier.cleanup(value, cart)
        for value in ({"status": "PASS"}, {**cart, "extra": True}, {**cart, "cleanup_restored": False}):
            with self.assertRaises(RuntimeError): verifier.cleanup(promotion, value)
        good = {"format": "cetech-n18-promotion-http-closeout-v1", "status": "PASS", "stage": "complete", **dict.fromkeys(verifier.CLOSEOUT_FLAGS, True)}
        verifier.closeout(good)
        for key, value in (("stage", "allocated"), ("status", "FAIL"), ("private_removed", 1), ("listener_stopped", False), ("extra", True)):
            with self.subTest(key=key), self.assertRaises(RuntimeError): verifier.closeout({**good, key: value})

    def test_bounded_normal_json_refuses_duplicates_symlinks_nonfinite_and_public_state(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "receipt.json"; path.write_text('{"status":"FAIL","status":"PASS"}')
            with self.assertRaises(RuntimeError): verifier.load(path)
            path.write_text('{"value":NaN}')
            with self.assertRaises(RuntimeError): verifier.load(path)
            path.write_text('{}'); os.chmod(path, 0o644)
            with self.assertRaises(RuntimeError): verifier.load(path, private=True)
            link = Path(folder) / "alias.json"; link.symlink_to(path)
            with self.assertRaises((RuntimeError, OSError)): verifier.load(link)
            with path.open('wb') as stream: stream.truncate(16 * 1024 * 1024 + 1)
            with self.assertRaises(RuntimeError): verifier.load(path)
            fifo = Path(folder) / "fifo"; os.mkfifo(fifo)
            with self.assertRaises(RuntimeError): verifier.load(fifo)

    def test_working_tree_producer_bytes_cannot_replace_committed_authority(self):
        with patch.object(verifier, "git", return_value=b"counterfeit producer"):
            with self.assertRaises(RuntimeError): verifier.pin_sources("a" * 40)

    def test_cli_checks_all_receipts_and_cleanup_before_success(self):
        with tempfile.TemporaryDirectory() as folder:
            paths = [Path(folder) / (name + '.json') for name in ('direct', 'browser', 'placement', 'state', 'promotion-cleanup', 'cart-cleanup')]
            values = [*self.reports, self.state, {"tracked_promotion_cleanup_restored": True}, dict.fromkeys(verifier.CART_CLEANUP, True)]
            for path, value in zip(paths, values): path.write_text(json.dumps(value)); os.chmod(path, 0o600)
            with patch.object(verifier, "authority", return_value=(self.identity, self.sources, self.specifications)), redirect_stdout(io.StringIO()) as output:
                verifier.main([*(str(path) for path in paths[:4]), '--cleanup', *(str(path) for path in paths[4:])])
            self.assertEqual('promotion_cart_receipts=PASS direct=7 browser=3 placement=3\n', output.getvalue())
            paths[0].write_text(json.dumps({"status": "PASS"}))
            with patch.object(verifier, "authority", return_value=(self.identity, self.sources, self.specifications)), self.assertRaises(RuntimeError):
                verifier.main([*(str(path) for path in paths[:4]), '--cleanup', *(str(path) for path in paths[4:])])


if __name__ == '__main__':
    unittest.main()
