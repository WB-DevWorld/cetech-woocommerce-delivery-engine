#!/usr/bin/env python3
"""Adversarial synthetic P06 verifier controls; never a native lifecycle proof."""
from copy import deepcopy
import importlib.util
from pathlib import Path
import sys
import unittest
sys.dont_write_bytecode = True
HERE = Path(__file__).resolve().parent


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, HERE / filename)
    result = importlib.util.module_from_spec(spec)
    sys.modules[name] = result
    spec.loader.exec_module(result)
    return result


V = module("p06_lifecycle_protocol", "verify-promise-operational-lifecycle-receipts.py")
P05 = module("p06_lifecycle_prior_controls", "test-verify-opening-promise-native-configuration.py")


def packets():
    p05, priors, hashes, prior_authority = P05.packet()
    control = P05.NativeConfigurationReceiptControls()
    control.reports, control.priors, control.hashes, control.expected = p05, priors, hashes, prior_authority
    http, hashes = control.http_packet()
    hashes["p05_http"] = "2" * 64
    retained = prior_authority.calculation.calculation.p02.retained
    packages = {}
    for kind, head in (("current", retained.source_head), ("previous", V.P05_HEAD), ("reader", V.P04_HEAD)):
        sources = deepcopy(retained.sources) if kind == "current" else {"src/private-fixture.php": "3" * 64}
        packages[kind] = {"source_head": head, "source_tree": retained.source_tree if kind == "current" else "4" * 40, "zip_sha256": "5" * 64, "package_report_sha256": "6" * 64, "production_php_sources": sources, "production_php_sources_hash": V.placement.canonical_hash(sources)}
    primary_priors = priors + p05 + [http]
    counts = tuple(len(prior["cases"]) for prior in primary_priors)
    expected = V.Authority(prior_authority, V.protocol(), packages, counts)
    reports = []
    for mode in ("yes", "no"):
        report = {key: deepcopy(p05[0][key]) for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources", "installed_php_sources_hash")}
        report.update({"format": "cetech-opening-promise-operational-lifecycle-v1", "status": "PASS", "limits": deepcopy(V.LIMITS), "package_inputs": deepcopy(packages),
                       "environment": {"php": "8.5.11", "wordpress": "7.1.2", "woocommerce": "11.1.2", "database_version": "11.4.13-MariaDB-ubu2404", "hpos": mode, "schema_before": "11", "context": V.CONTEXT, "background_requests": V.BACKGROUND},
                       "preceding_receipts": {kind: {"sha256": hashes[kind], "cases": count} for kind, count in zip(V.KINDS, counts)},
                       "cases": [{"id": case_id, "status": "PASS", "evidence": dict.fromkeys(fields, True)} for case_id, fields in expected.cases]})
        reports.append(report)
    return reports, priors + p05 + [http], hashes, expected


class LifecycleReceiptControls(unittest.TestCase):
    def setUp(self):
        self.reports, self.priors, self.hashes, self.expected = packets()

    def check(self):
        return V.verify(self.reports, self.priors, self.hashes, self.expected)

    def test_complete_source_derived_native_protocol_accepts(self):
        self.assertEqual((495,20,143,19,49,33,33,14,27,27,19), V.COUNTS)
        self.assertEqual(11, self.check())

    def test_missing_extra_duplicate_reordered_failed_cases_refuse_both_modes(self):
        for mode in range(2):
            for change in ("missing", "extra", "duplicate", "reordered", "failed"):
                self.setUp()
                cases = self.reports[mode]["cases"]
                if change == "missing": cases.pop()
                if change == "extra": cases.append(deepcopy(cases[0]))
                if change == "duplicate": cases[1] = deepcopy(cases[0])
                if change == "reordered": cases[0], cases[1] = cases[1], cases[0]
                if change == "failed": cases[0]["status"] = "FAIL"
                with self.assertRaises((RuntimeError, ValueError)): self.check()

    def test_unknown_missing_false_and_integer_observations_refuse(self):
        for mode in range(2):
            for value in (False, 1, None, "true", [], {}):
                self.setUp()
                fields = self.reports[mode]["cases"][0]["evidence"]
                fields[next(iter(fields))] = value
                with self.assertRaises((RuntimeError, ValueError)): self.check()
            for change in ("missing", "extra"):
                self.setUp()
                fields = self.reports[mode]["cases"][0]["evidence"]
                if change == "missing": fields.pop(next(iter(fields)))
                else: fields["unknown"] = True
                with self.assertRaises((RuntimeError, ValueError)): self.check()
            for field in ("compatible_p04_base_storage_ready", "new_shipment_writer_unavailable", "old_plugin_writers_unmounted"):
                self.setUp()
                fields = next(case["evidence"] for case in self.reports[mode]["cases"] if case["id"] == "NATIVE-W2P06-SUPPORTED-P04-FORWARD-READER-PRESERVES-P05-UNKNOWN-DATA")
                self.assertNotIn("new_schema_writer_readiness_refused", fields)
                self.assertIs(True, fields[field])
                fields[field] = False
                with self.assertRaises((RuntimeError, ValueError)): self.check()

    def test_changed_original_package_refs_maps_zip_hashes_and_report_hashes_refuse(self):
        for kind in ("current", "previous", "reader"):
            for field in ("source_head", "source_tree", "zip_sha256", "package_report_sha256", "production_php_sources", "production_php_sources_hash"):
                self.setUp()
                self.reports[0]["package_inputs"][kind][field] = {} if field == "production_php_sources" else "a" * 64
                with self.assertRaises((RuntimeError, ValueError)): self.check()

    def test_failed_missing_or_changed_each_retained_original_refuses(self):
        for index, kind in enumerate(V.KINDS):
            for change in ("failed", "raw", "count", "missing"):
                self.setUp()
                if change == "failed": self.priors[index]["cases"][0]["status"] = "FAIL"
                if change == "raw": self.reports[0]["preceding_receipts"][kind]["sha256"] = "9" * 64
                if change == "count": self.reports[0]["preceding_receipts"][kind]["cases"] += 1
                if change == "missing": self.reports[0]["preceding_receipts"].pop(kind)
                with self.assertRaises((RuntimeError, ValueError)): self.check()

    def test_source_runtime_scope_and_schema_changes_refuse(self):
        for mode in range(2):
            for key, value in (("source_head", "9" * 40), ("source_tree", "9" * 40), ("candidate_head", "9" * 40), ("installed_php_sources", {}), ("limits", [])):
                self.setUp(); self.reports[mode][key] = value
                with self.assertRaises((RuntimeError, ValueError)): self.check()
            for key, value in (("php", "8.3.6"), ("wordpress", "0"), ("woocommerce", "0"), ("hpos", "maybe"), ("schema_before", "10"), ("database_version", "10.11.14"), ("context", "wrong"), ("background_requests", "unknown")):
                self.setUp(); self.reports[mode]["environment"][key] = value
                with self.assertRaises((RuntimeError, ValueError)): self.check()

    def test_single_lane_unknown_envelopes_and_forged_cleanup_refuse(self):
        self.reports.pop()
        with self.assertRaises((RuntimeError, ValueError)): self.check()
        for location in ("top", "case", "environment", "packages", "cleanup"):
            self.setUp()
            if location == "top": self.reports[0]["unknown"] = True
            if location == "case": self.reports[0]["cases"][0]["unknown"] = True
            if location == "environment": self.reports[0]["environment"]["unknown"] = True
            if location == "packages": self.reports[0]["package_inputs"]["unknown"] = {}
            if location == "cleanup": self.reports[0]["cases"][-1]["evidence"]["all39_original_business_rows_restored"] = False
            with self.assertRaises((RuntimeError, ValueError)): self.check()


if __name__ == "__main__": unittest.main()
