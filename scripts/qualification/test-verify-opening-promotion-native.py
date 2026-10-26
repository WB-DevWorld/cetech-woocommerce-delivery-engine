#!/usr/bin/env python3
"""Counterfeit PASS receipts must not bypass the finite native N17 evidence contract."""
import copy
import importlib.util
from pathlib import Path
import sys
import unittest

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("n17_receipt_verifier_test", Path(__file__).with_name("verify-opening-promotion-native.py"))
verifier = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = verifier
spec.loader.exec_module(verifier)


class NativeReceiptRefusals(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.cases = verifier.protocol()
        cls.identity = {"source_head": "a" * 40, "candidate_head": "b" * 40, "source_tree": "c" * 40}
        cls.sources = {"src/example.php": "d" * 64}
        cls.report = {
            "format": "cetech-opening-promotion-native-v1", **cls.identity,
            "installed_php_sources": cls.sources, "installed_php_sources_hash": verifier.placement.canonical_hash(cls.sources),
            "limits": verifier.LIMITS, "status": "PASS",
            "environment": {"php": "8.5.12", "wordpress": "7.1.2", "woocommerce": "11.1.2", "database_version": "11.4.13-MariaDB", "hpos": "yes", "schema_before": "12", "context": verifier.CONTEXT, "background_requests": verifier.BACKGROUND},
            "cases": [{"id": case_id, "status": "PASS", "evidence": dict.fromkeys(fields, True)} for case_id, fields in cls.cases],
        }

    def check(self, report):
        verifier.verify(report, "yes", self.identity, self.sources, self.cases)

    def test_closed_positive_receipt(self):
        self.check(copy.deepcopy(self.report))

    def test_counterfeit_observation_and_incomplete_inventory_refused(self):
        for change in ("false", "integer", "extra", "missing", "duplicate", "reordered", "old_inventory_key"):
            with self.subTest(change=change):
                report = copy.deepcopy(self.report)
                observations = report["cases"][0]["evidence"]
                first = next(iter(observations))
                if change == "false": observations[first] = False
                elif change == "integer": observations[first] = 1
                elif change == "extra": observations["invented"] = True
                elif change == "missing": del observations[first]
                elif change == "duplicate": report["cases"][-1] = report["cases"][0]
                elif change == "old_inventory_key": observations["installed1003_production_php"] = observations.pop("installed1004_production_php")
                else: report["cases"].reverse()
                with self.assertRaises((AssertionError, ValueError, RuntimeError)):
                    self.check(report)

    def test_stale_or_alternate_native_authority_refused(self):
        for field, value in (("hpos", "no"), ("schema_before", "11"), ("wordpress", "7.1.3"), ("woocommerce", "11.2.0"), ("context", "claimed native")):
            with self.subTest(field=field):
                report = copy.deepcopy(self.report)
                report["environment"][field] = value
                with self.assertRaises((AssertionError, ValueError, RuntimeError)):
                    self.check(report)

    def test_foreign_sources_and_widened_scope_refused(self):
        for change in ("head", "sources", "limits", "envelope"):
            with self.subTest(change=change):
                report = copy.deepcopy(self.report)
                if change == "head": report["candidate_head"] = "e" * 40
                elif change == "sources": report["installed_php_sources"]["src/example.php"] = "e" * 64
                elif change == "limits": report["limits"] = []
                else: report["invented"] = True
                with self.assertRaises((AssertionError, ValueError, RuntimeError)):
                    self.check(report)


if __name__ == "__main__":
    unittest.main()
