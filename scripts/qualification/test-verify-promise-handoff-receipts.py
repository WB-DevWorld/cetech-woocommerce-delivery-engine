#!/usr/bin/env python3
"""Adversarial P04 protocol packets are synthetic and supply no native authority."""
from copy import deepcopy
import importlib.util
from pathlib import Path
import sys
sys.dont_write_bytecode = True
import unittest

HERE = Path(__file__).resolve().parent


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, HERE / filename)
    result = importlib.util.module_from_spec(spec); sys.modules[name] = result
    spec.loader.exec_module(result)
    return result


V = module("promise_handoff_verifier_tests", "verify-promise-handoff-receipts.py")
P03 = module("promise_handoff_p03_packet_tests", "test-verify-promise-calculation-receipts.py")


def packet():
    p03, p02, retained, hashes, prior = P03.packet()
    hashes["p03"] = "b" * 64
    expected = V.Authority(prior, V.protocol())
    reports = []
    for mode in ("yes", "no"):
        reports.append({
            "format": "cetech-opening-promise-handoff-v1", "source_head": p03["source_head"], "candidate_head": p03["candidate_head"], "source_tree": p03["source_tree"],
            "installed_php_sources": p03["installed_php_sources"].copy(), "installed_php_sources_hash": p03["installed_php_sources_hash"],
            "environment": {"php": "8.5.11", "wordpress": "7.1.2", "woocommerce": "11.1.2", "database_version": "11.4.13-MariaDB-ubu2404", "hpos": mode, "schema_before": "10", "context": V.CONTEXT, "background_requests": V.BACKGROUND},
            "limits": V.LIMITS.copy(), "status": "PASS",
            "preceding_receipts": {kind: {"sha256": hashes[kind], "cases": len(prior_report["cases"])} for kind, prior_report in zip(("native", "cpt", "http", "p02", "p03"), list(retained) + [p02, p03])},
            "cases": [{"id": case_id, "status": "PASS", "evidence": dict.fromkeys(fields, True)} for case_id, fields in expected.cases],
        })
    return reports, p03, p02, retained, hashes, expected


class HandoffReceiptTests(unittest.TestCase):
    def setUp(self):
        self.reports, self.p03, self.p02, self.retained, self.hashes, self.expected = packet()

    def http_packet(self):
        cases, scope = V.http_protocol()
        expected = V.Authority(self.expected.calculation, self.expected.cases, cases, scope)
        hashes = {**self.hashes, "p04_hpos": "c" * 64, "p04_cpt": "d" * 64}
        primary = self.reports[0]
        report = {key: deepcopy(primary[key]) for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources", "installed_php_sources_hash")}
        report.update({"format": "cetech-opening-http-promise-handoff-v1", "status": "PASS", "environment": {**deepcopy(primary["environment"]), "context": scope["CONTEXT"], "background_requests": scope["BACKGROUND"]}, "limits": scope["LIMITS"].copy(), "browser_runtime": {"playwright": "1.58.2", "chromium": "145.0.7632.6"}, "preceding_receipts": {kind: {"sha256": hashes[kind], "cases": len(prior["cases"])} for kind, prior in zip(("native", "cpt", "http", "p02", "p03", "p04_hpos", "p04_cpt"), list(self.retained) + [self.p02, self.p03] + self.reports)}, "cases": [{"id": case_id, "status": "PASS", "evidence": dict.fromkeys(fields, True)} for case_id, fields in cases]})
        return report, hashes, expected

    def http_check(self, report, hashes, expected):
        self.check()
        return V.verify_http(report, self.reports, list(self.retained) + [self.p02, self.p03], hashes, expected)

    def test_separate_closed_new_http_inventory_accepts(self):
        report, hashes, expected = self.http_packet()
        self.assertEqual(len(expected.http_cases), self.http_check(report, hashes, expected))

    def test_http_missing_duplicate_extra_reordered_failed_case_refuses(self):
        for mutation in ("missing", "duplicate", "extra", "reordered", "failed"):
            report, hashes, expected = self.http_packet(); cases = report["cases"]
            if mutation == "missing": cases.pop()
            if mutation == "duplicate": cases[1] = deepcopy(cases[0])
            if mutation == "extra": cases.append(deepcopy(cases[0]))
            if mutation == "reordered": cases[0], cases[1] = cases[1], cases[0]
            if mutation == "failed": cases[0]["status"] = "FAIL"
            with self.subTest(mutation=mutation), self.assertRaises(RuntimeError): self.http_check(report, hashes, expected)

    def test_http_browser_runtime_is_exact_and_closed(self):
        for field, value in (("playwright", "1.57.0"), ("chromium", "145.0.7632.5"), ("caller_claimed", True)):
            report, hashes, expected = self.http_packet(); report["browser_runtime"][field] = value
            with self.subTest(field=field), self.assertRaises(RuntimeError): self.http_check(report, hashes, expected)

    def test_http_exact_boolean_and_private_payload_refuses(self):
        for mutation in (False, 1, "true", None, {"private_packet": "untrusted"}):
            report, hashes, expected = self.http_packet(); key = next(iter(report["cases"][0]["evidence"])); report["cases"][0]["evidence"][key] = mutation
            with self.assertRaises(RuntimeError): self.http_check(report, hashes, expected)
        report, hashes, expected = self.http_packet(); report["private_receipt"] = "untrusted"
        with self.assertRaises(RuntimeError): self.http_check(report, hashes, expected)

    def test_http_native_proof_hash_or_inventory_mutation_refuses(self):
        for kind in ("p04_hpos", "p04_cpt"):
            for mutation in ("hash", "count", "missing"):
                report, hashes, expected = self.http_packet()
                if mutation == "hash": report["preceding_receipts"][kind]["sha256"] = "f" * 64
                if mutation == "count": report["preceding_receipts"][kind]["cases"] -= 1
                if mutation == "missing": report["preceding_receipts"].pop(kind)
                with self.subTest(kind=kind, mutation=mutation), self.assertRaises(RuntimeError): self.http_check(report, hashes, expected)

    def test_http_cannot_compensate_for_failed_native_mode(self):
        report, hashes, expected = self.http_packet(); self.reports[1]["cases"][0]["status"] = "FAIL"
        with self.assertRaises(RuntimeError): self.http_check(report, hashes, expected)

    def test_http_source_environment_or_scope_mutation_refuses(self):
        for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash"):
            report, hashes, expected = self.http_packet(); report[key] = "f" * len(report[key])
            with self.assertRaises(RuntimeError): self.http_check(report, hashes, expected)
        for key, value in (("hpos", "no"), ("php", "8.3.6"), ("context", "fixture-only"), ("database_version", "10.11.14-MariaDB")):
            report, hashes, expected = self.http_packet(); report["environment"][key] = value
            with self.assertRaises(RuntimeError): self.http_check(report, hashes, expected)
        report, hashes, expected = self.http_packet(); report["limits"].append("unqualified route")
        with self.assertRaises(RuntimeError): self.http_check(report, hashes, expected)

    def check(self, reports=None, p03=None, p02=None, retained=None, hashes=None):
        return V.verify(reports if reports is not None else self.reports, p03 if p03 is not None else self.p03, p02 if p02 is not None else self.p02, retained if retained is not None else self.retained, hashes if hashes is not None else self.hashes, self.expected)

    def test_complete_separate_source_inventory_accepts(self):
        self.assertEqual(len(self.expected.cases), self.check())

    def test_missing_duplicate_or_swapped_native_mode_refuses(self):
        for mutation in ("missing", "extra", "duplicated", "swapped"):
            with self.subTest(mutation=mutation):
                reports = deepcopy(self.reports)
                if mutation == "missing": reports.pop()
                if mutation == "extra": reports.append(deepcopy(reports[0]))
                if mutation == "duplicated": reports[1] = deepcopy(reports[0])
                if mutation == "swapped": reports.reverse()
                with self.assertRaises(RuntimeError): self.check(reports=reports)

    def test_missing_extra_duplicate_reordered_failed_cases_in_either_mode_refuse(self):
        for index in range(2):
            for mutation in ("missing", "extra", "duplicate", "reordered", "failed"):
                with self.subTest(index=index, mutation=mutation):
                    reports = deepcopy(self.reports); cases = reports[index]["cases"]
                    if mutation == "missing": cases.pop()
                    if mutation == "extra": cases.append(deepcopy(cases[-1]))
                    if mutation == "duplicate": cases[1] = deepcopy(cases[0])
                    if mutation == "reordered": cases[0], cases[1] = cases[1], cases[0]
                    if mutation == "failed": cases[0]["status"] = "FAIL"
                    with self.assertRaises(RuntimeError): self.check(reports=reports)

    def test_exact_booleans_and_closed_observations_refuse(self):
        for index in range(2):
            for value in (False, 1, "true", None, [], {}):
                reports = deepcopy(self.reports); field = next(iter(reports[index]["cases"][0]["evidence"]))
                reports[index]["cases"][0]["evidence"][field] = value
                with self.assertRaises(RuntimeError): self.check(reports=reports)
            for mutation in ("extra", "missing"):
                reports = deepcopy(self.reports)
                if mutation == "extra": reports[index]["cases"][0]["evidence"]["caller_claimed"] = True
                else: reports[index]["cases"][0]["evidence"].popitem()
                with self.assertRaises(RuntimeError): self.check(reports=reports)

    def test_source_candidate_tree_or_installed_source_mismatch_refuses(self):
        for index in range(2):
            for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash"):
                reports = deepcopy(self.reports); reports[index][key] = "f" * len(reports[index][key])
                with self.assertRaises(RuntimeError): self.check(reports=reports)
            reports = deepcopy(self.reports); reports[index]["installed_php_sources"]["src/CallerClaimed.php"] = "f" * 64
            with self.assertRaises(RuntimeError): self.check(reports=reports)

    def test_each_preceding_primary_failure_cannot_be_compensated(self):
        for key in ("status", "case"):
            p03 = deepcopy(self.p03)
            if key == "status": p03["status"] = "FAIL"
            else: p03["cases"][0]["status"] = "FAIL"
            with self.assertRaises(RuntimeError): self.check(p03=p03)
            p02 = deepcopy(self.p02)
            if key == "status": p02["status"] = "FAIL"
            else: p02["cases"][0]["status"] = "FAIL"
            with self.assertRaises(RuntimeError): self.check(p02=p02)
        for index in range(3):
            retained = deepcopy(self.retained); retained[index]["status"] = "FAIL"
            with self.assertRaises(RuntimeError): self.check(retained=retained)

    def test_original_raw_sha_count_and_unknown_linkage_refuse(self):
        for kind in ("native", "cpt", "http", "p02", "p03"):
            hashes = self.hashes.copy(); hashes[kind] = "f" * 64
            with self.assertRaises(RuntimeError): self.check(hashes=hashes)
            for index in range(2):
                reports = deepcopy(self.reports); reports[index]["preceding_receipts"][kind]["cases"] = True
                with self.assertRaises(RuntimeError): self.check(reports=reports)
                reports = deepcopy(self.reports); reports[index]["preceding_receipts"][kind]["authority"] = True
                with self.assertRaises(RuntimeError): self.check(reports=reports)
        hashes = self.hashes.copy(); hashes["caller_claimed"] = "f" * 64
        with self.assertRaises(RuntimeError): self.check(hashes=hashes)

    def test_runtime_and_scope_mutations_refuse(self):
        for index in range(2):
            for key in ("php", "wordpress", "woocommerce", "database_version", "hpos", "schema_before", "context", "background_requests"):
                reports = deepcopy(self.reports); reports[index]["environment"][key] = "caller_claimed"
                with self.assertRaises(RuntimeError): self.check(reports=reports)
            reports = deepcopy(self.reports); reports[index]["limits"].pop()
            with self.assertRaises(RuntimeError): self.check(reports=reports)

    def test_unknown_envelope_case_or_private_payload_refuses(self):
        for index in range(2):
            for container in ("envelope", "case", "environment"):
                reports = deepcopy(self.reports)
                target = reports[index] if container == "envelope" else reports[index]["cases"][0] if container == "case" else reports[index]["environment"]
                target["private_input_body"] = "must_not_be_disclosed"
                with self.assertRaises(RuntimeError): self.check(reports=reports)

    def test_running_fail_or_error_envelopes_refuse(self):
        for index in range(2):
            for status in ("RUNNING", "FAIL", True):
                reports = deepcopy(self.reports); reports[index]["status"] = status
                with self.assertRaises(RuntimeError): self.check(reports=reports)
            reports = deepcopy(self.reports); reports[index]["error"] = {"class": "RuntimeException"}
            with self.assertRaises(RuntimeError): self.check(reports=reports)


if __name__ == "__main__":
    unittest.main()
