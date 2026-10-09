#!/usr/bin/env python3
"""P03 adversarial protocol tests; these synthetic packets are not runtime proof."""
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


V = module("promise_calculation_verifier_tests", "verify-promise-calculation-receipts.py")
P02 = module("promise_calculation_p02_packet_tests", "test-verify-promise-storage-receipts.py")


def packet():
    p02, retained, old_hashes, p02_authority = P02.packet()
    expected = V.Authority(p02_authority, V.protocol())
    hashes = dict(zip(("native", "cpt", "http"), old_hashes)); hashes["p02"] = "7" * 64
    report = {
        "format": "cetech-opening-promise-calculation-v1", "source_head": p02["source_head"], "candidate_head": p02["candidate_head"], "source_tree": p02["source_tree"],
        "installed_php_sources": p02["installed_php_sources"].copy(), "installed_php_sources_hash": p02["installed_php_sources_hash"],
        "environment": {"php": "8.5.11", "timezone_data_version": "0.system", "context": V.CONTEXT, "runtime_origin": V.RUNTIME_ORIGIN},
        "limits": V.LIMITS.copy(), "status": "PASS",
        "preceding_receipts": {kind: {"sha256": hashes[kind], "cases": len(prior["cases"])} for kind, prior in zip(("native", "cpt", "http", "p02"), list(retained) + [p02])},
        "cases": [{"id": case_id, "status": "PASS", "evidence": dict.fromkeys(fields, True), "fingerprints": {"kind": kind, "input_digest": "8" * 64, "result_digest": "9" * 64, "calendar_digests": ["a" * 64], "steps": 100000}} for case_id, fields, kind in expected.cases],
    }
    return report, p02, retained, hashes, expected


class CalculationReceiptTests(unittest.TestCase):
    def setUp(self):
        self.report, self.p02, self.retained, self.hashes, self.expected = packet()

    def check(self, report=None, p02=None, retained=None, hashes=None):
        return V.verify(report if report is not None else self.report, p02 if p02 is not None else self.p02, retained if retained is not None else self.retained, hashes if hashes is not None else self.hashes, self.expected)

    def test_complete_source_inventory_accepts(self):
        self.assertEqual(len(self.expected.cases), self.check())

    def test_missing_extra_duplicate_reordered_or_failed_case_refuses(self):
        for mutation in ("missing", "extra", "duplicate", "reordered", "failed"):
            with self.subTest(mutation=mutation):
                report = deepcopy(self.report)
                if mutation == "missing": report["cases"].pop()
                if mutation == "extra": report["cases"].append(deepcopy(report["cases"][-1]))
                if mutation == "duplicate": report["cases"][1] = deepcopy(report["cases"][0])
                if mutation == "reordered": report["cases"][0], report["cases"][1] = report["cases"][1], report["cases"][0]
                if mutation == "failed": report["cases"][0]["status"] = "FAIL"
                with self.assertRaises(RuntimeError): self.check(report=report)

    def test_exact_booleans_and_closed_observations(self):
        for value in (False, 1, "true", None, [], {}):
            report = deepcopy(self.report); field = next(iter(report["cases"][0]["evidence"]))
            report["cases"][0]["evidence"][field] = value
            with self.assertRaises(RuntimeError): self.check(report=report)
        for mutation in ("extra", "missing"):
            report = deepcopy(self.report)
            if mutation == "extra": report["cases"][0]["evidence"]["caller_claimed"] = True
            else: report["cases"][0]["evidence"].popitem()
            with self.assertRaises(RuntimeError): self.check(report=report)

    def test_work_budget_type_bounds_and_plus_one(self):
        for value in (-1, 100001, True, "100000", None):
            report = deepcopy(self.report); report["cases"][0]["fingerprints"]["steps"] = value
            with self.assertRaises(RuntimeError): self.check(report=report)

    def test_fingerprint_projection_and_calendar_bound(self):
        for value in ("caller_claimed", "cart", "calendar_math", 1, None, True):
            report = deepcopy(self.report); report["cases"][0]["fingerprints"]["kind"] = value
            with self.assertRaises(RuntimeError): self.check(report=report)
        for key in ("input_digest", "result_digest"):
            report = deepcopy(self.report); report["cases"][0]["fingerprints"][key] = "unknown"
            with self.assertRaises(RuntimeError): self.check(report=report)
        for value in (["a" * 64] * 17, ["unknown"], "a" * 64, None):
            report = deepcopy(self.report); report["cases"][0]["fingerprints"]["calendar_digests"] = value
            with self.assertRaises(RuntimeError): self.check(report=report)
        report = deepcopy(self.report); report["cases"][0]["fingerprints"]["private_body"] = {}
        with self.assertRaises(RuntimeError): self.check(report=report)

    def test_retained_or_p02_failure_cannot_be_compensated(self):
        p02 = deepcopy(self.p02); p02["status"] = "FAIL"
        with self.assertRaises(RuntimeError): self.check(p02=p02)
        p02 = deepcopy(self.p02); p02["cases"][0]["status"] = "FAIL"
        with self.assertRaises(RuntimeError): self.check(p02=p02)
        for index in range(3):
            retained = deepcopy(self.retained); retained[index]["status"] = "FAIL"
            with self.assertRaises(RuntimeError): self.check(retained=retained)

    def test_preceding_raw_sha_count_and_unknown_member(self):
        for kind in ("native", "cpt", "http", "p02"):
            hashes = self.hashes.copy(); hashes[kind] = "f" * 64
            with self.assertRaises(RuntimeError): self.check(hashes=hashes)
            report = deepcopy(self.report); report["preceding_receipts"][kind]["cases"] = True
            with self.assertRaises(RuntimeError): self.check(report=report)
        report = deepcopy(self.report); report["preceding_receipts"]["p02"]["authority"] = True
        with self.assertRaises(RuntimeError): self.check(report=report)

    def test_candidate_execution_tree_and_installed_source(self):
        for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash"):
            report = deepcopy(self.report); report[key] = "f" * len(report[key])
            with self.assertRaises(RuntimeError): self.check(report=report)
        report = deepcopy(self.report); report["installed_php_sources"]["uninstall.php"] = "f" * 64
        with self.assertRaises(RuntimeError): self.check(report=report)

    def test_runtime_origin_scope_and_private_extra_refuse(self):
        for key in self.report["environment"]:
            report = deepcopy(self.report); report["environment"][key] = ""
            with self.assertRaises(RuntimeError): self.check(report=report)
        report = deepcopy(self.report); report["limits"].pop()
        with self.assertRaises(RuntimeError): self.check(report=report)
        report = deepcopy(self.report); report["policy"] = {"private": "unused"}
        with self.assertRaises(RuntimeError): self.check(report=report)


if __name__ == "__main__":
    unittest.main()
