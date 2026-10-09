#!/usr/bin/env python3
"""Synthetic adversarial verifier controls only; these packets claim no native proof."""
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


V = module("p05_protocol_verifier_controls", "verify-opening-promise-native-configuration.py")
P04 = module("p05_retained_p04_controls", "test-verify-promise-handoff-receipts.py")


def packet():
    p04, p03, p02, retained, hashes, authority = P04.packet()
    control = P04.HandoffReceiptTests()
    control.reports, control.p03, control.p02, control.retained, control.hashes, control.expected = p04, p03, p02, retained, hashes, authority
    p04_http, hashes, authority = control.http_packet()
    hashes["p04_http"] = "e" * 64
    priors = list(retained) + [p02, p03] + p04 + [p04_http]
    http_cases, scope = V.http_protocol()
    expected = V.Authority(authority, V.protocol(), http_cases, scope)
    kinds = ("native", "cpt", "http", "p02", "p03", "p04_hpos", "p04_cpt", "p04_http")
    reports = []
    for mode in ("yes", "no"):
        report = {key: deepcopy(p03[key]) for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources", "installed_php_sources_hash")}
        report.update({"format": "cetech-opening-promise-native-configuration-v1", "status": "PASS", "limits": V.LIMITS.copy(),
                       "environment": {"php": "8.5.11", "wordpress": "7.1.2", "woocommerce": "11.1.2", "database_version": "11.4.13-MariaDB-ubu2404", "hpos": mode, "schema_before": "11", "context": V.CONTEXT, "background_requests": V.BACKGROUND},
                       "preceding_receipts": {kind: {"sha256": hashes[kind], "cases": len(prior["cases"])} for kind, prior in zip(kinds, priors)},
                       "cases": [{"id": case_id, "status": "PASS", "evidence": dict.fromkeys(fields, True)} for case_id, fields in expected.cases]})
        reports.append(report)
    return reports, priors, hashes, expected


class NativeConfigurationReceiptControls(unittest.TestCase):
    def setUp(self):
        self.reports, self.priors, self.hashes, self.expected = packet()

    def check(self):
        return V.verify(self.reports, self.priors, self.hashes, self.expected)

    def http_packet(self):
        hashes = {**self.hashes, "p05_hpos": "f" * 64, "p05_cpt": "1" * 64}
        report = {key: deepcopy(self.reports[0][key]) for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources", "installed_php_sources_hash", "environment")}
        report["environment"].update({"context": self.expected.http_scope["CONTEXT"], "background_requests": self.expected.http_scope["BACKGROUND"]})
        kinds = ("native", "cpt", "http", "p02", "p03", "p04_hpos", "p04_cpt", "p04_http", "p05_hpos", "p05_cpt")
        report.update({"format": "cetech-opening-http-promise-native-configuration-v1", "status": "PASS", "limits": self.expected.http_scope["LIMITS"].copy(),
                       "browser_runtime": {"playwright": "1.58.2", "chromium": "145.0.7632.6"},
                       "preceding_receipts": {kind: {"sha256": hashes[kind], "cases": len(prior["cases"])} for kind, prior in zip(kinds, self.priors + self.reports)},
                       "cases": [{"id": case_id, "status": "PASS", "evidence": dict.fromkeys(fields, True)} for case_id, fields in self.expected.http_cases]})
        return report, hashes

    def http_check(self, report, hashes):
        self.check()
        return V.verify_http(report, self.reports, self.priors, hashes, self.expected)

    def test_source_derived_controls_accept_exact_native_and_http_protocol(self):
        self.assertEqual(len(self.expected.cases), self.check())
        report, hashes = self.http_packet()
        self.assertEqual(len(self.expected.http_cases), self.http_check(report, hashes))

    def test_missing_extra_duplicate_reordered_failed_case_refuses_each_lane(self):
        for lane in (0, 1, "http"):
            for mutation in ("missing", "extra", "duplicate", "reordered", "failed"):
                self.setUp()
                report, hashes = self.http_packet()
                cases = report["cases"] if lane == "http" else self.reports[lane]["cases"]
                if mutation == "missing": cases.pop()
                if mutation == "extra": cases.append(deepcopy(cases[0]))
                if mutation == "duplicate": cases[1] = deepcopy(cases[0])
                if mutation == "reordered": cases[0], cases[1] = cases[1], cases[0]
                if mutation == "failed": cases[0]["status"] = "FAIL"
                with self.subTest(lane=lane, mutation=mutation), self.assertRaises(RuntimeError):
                    self.http_check(report, hashes) if lane == "http" else self.check()

    def test_forged_pass_typed_observation_or_private_payload_refuses(self):
        for lane in (0, 1, "http"):
            for value in (False, 1, "true", None, [], {"private_packet": "untrusted"}):
                self.setUp(); report, hashes = self.http_packet()
                evidence = (report if lane == "http" else self.reports[lane])["cases"][0]["evidence"]
                evidence[next(iter(evidence))] = value
                with self.subTest(lane=lane, value=value), self.assertRaises(RuntimeError):
                    self.http_check(report, hashes) if lane == "http" else self.check()
            self.setUp(); report, hashes = self.http_packet()
            (report if lane == "http" else self.reports[lane])["cases"][0]["evidence"]["private_packet"] = "untrusted"
            with self.assertRaises(RuntimeError):
                self.http_check(report, hashes) if lane == "http" else self.check()

    def test_immutable_identity_map_and_pinned_runtime_refuse_mutations(self):
        for lane in (0, 1, "http"):
            for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash", "installed_php_sources"):
                self.setUp(); report, hashes = self.http_packet(); target = report if lane == "http" else self.reports[lane]
                target[key] = {} if key == "installed_php_sources" else "9" * len(target[key])
                with self.subTest(lane=lane, key=key), self.assertRaises(RuntimeError):
                    self.http_check(report, hashes) if lane == "http" else self.check()
            for key, value in (("php", "8.3.6"), ("hpos", "no" if lane != 1 else "yes"), ("schema_before", "10"), ("database_version", "10.11.14-MariaDB"), ("context", "synthetic alternate")):
                self.setUp(); report, hashes = self.http_packet(); target = report if lane == "http" else self.reports[lane]; target["environment"][key] = value
                with self.subTest(lane=lane, key=key), self.assertRaises(RuntimeError):
                    self.http_check(report, hashes) if lane == "http" else self.check()

    def test_every_original_prior_hash_inventory_and_failure_is_independent(self):
        for kind in self.hashes:
            for mutation in ("hash", "count", "missing"):
                self.setUp(); link = self.reports[0]["preceding_receipts"][kind]
                if mutation == "hash": link["sha256"] = "9" * 64
                if mutation == "count": link["cases"] -= 1
                if mutation == "missing": self.reports[0]["preceding_receipts"].pop(kind)
                with self.subTest(kind=kind, mutation=mutation), self.assertRaises(RuntimeError): self.check()
        for index in range(8):
            self.setUp(); self.priors[index]["cases"][0]["status"] = "FAIL"
            with self.subTest(index=index), self.assertRaises(RuntimeError): self.check()

    def test_cleanup_failure_cannot_be_overridden_by_pass_envelope(self):
        for lane in (0, 1, "http"):
            self.setUp(); report, hashes = self.http_packet(); target = report if lane == "http" else self.reports[lane]
            cleanup = [case for case in target["cases"] if "CLEANUP" in case["id"]]
            self.assertTrue(cleanup)
            cleanup[-1]["evidence"][next(iter(cleanup[-1]["evidence"]))] = False
            with self.assertRaises(RuntimeError):
                self.http_check(report, hashes) if lane == "http" else self.check()

    def test_separate_modes_and_browser_exact_tuple_required(self):
        for mutation in ("missing", "swapped", "duplicate"):
            self.setUp()
            if mutation == "missing": self.reports.pop()
            if mutation == "swapped": self.reports.reverse()
            if mutation == "duplicate": self.reports[1] = deepcopy(self.reports[0])
            with self.assertRaises(RuntimeError): self.check()
        for key, value in (("playwright", "1.57.0"), ("chromium", "145.0.7632.5"), ("caller_claimed", True)):
            self.setUp(); report, hashes = self.http_packet(); report["browser_runtime"][key] = value
            with self.assertRaises(RuntimeError): self.http_check(report, hashes)


if __name__ == "__main__": unittest.main()
