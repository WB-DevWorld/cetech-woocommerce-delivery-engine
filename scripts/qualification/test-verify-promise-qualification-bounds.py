#!/usr/bin/env python3
"""Synthetic adverse protocol packets only; no native, SQL, network or budget proof."""
from copy import deepcopy
import importlib.util
from pathlib import Path
import sys
import unittest
sys.dont_write_bytecode = True
HERE = Path(__file__).resolve().parent


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, HERE / filename); result = importlib.util.module_from_spec(spec); sys.modules[name] = result; spec.loader.exec_module(result); return result


V = module("p06_numeric_verifier_controls", "verify-promise-qualification-bounds.py")
P05 = module("p06_numeric_retained_controls", "test-verify-opening-promise-native-configuration.py")


def packet():
    controls = P05.NativeConfigurationReceiptControls(); controls.setUp(); http, _ = controls.http_packet(); priors = controls.reports + [http]
    retained = controls.expected.calculation.calculation.p02.retained
    expected = V.Authority(retained, V.protocol("promise-qualification-bounds-cases.php", "PURE-W2P06-"), V.protocol("opening-promise-qualification-native-bounds.php", "NATIVE-W2P06-"))
    # Manual closed finite measurements, independent of calculator execution.
    pure_rows = [(16, 32, 293, 9600), (17, 16), (33, 32), (32768, 32768), (32769, 32768), (65536, 65536), (65537, 65536), (100000, 100000), (100001, 100000), (730, 730, 35), (731, 730, 30), (8, 9), (366, 367), (16, 225), (17, 16), (200, 200, 4000), (201, 4000), (30, 100000, 100000)]
    native_rows = [(2,), (200, 0, 0), (201, 0, 0), (2,), (2, 12, 12, 2, 8, 0, 0, 0), (1500, 2), (9,)]
    hashes = dict(zip(("p05_hpos", "p05_cpt", "p05_http"), ("1" * 64, "2" * 64, "3" * 64))); reports = []
    for index in range(3):
        pure = index == 0; report = {key: deepcopy(priors[0][key]) for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources", "installed_php_sources_hash")}
        report.update({"format": "cetech-opening-promise-qualification-bounds-v1" if pure else "cetech-opening-promise-qualification-native-bounds-v1", "status": "PASS", "limits": V.PURE_LIMITS.copy() if pure else V.NATIVE_LIMITS.copy(), "preceding_receipts": {kind: {"sha256": hashes[kind], "cases": len(prior["cases"])} for kind, prior in zip(hashes, priors)}, "cases": []})
        report["environment"] = {"php": "8.5.11", "timezone_data_version": "0.system", "context": V.PURE_CONTEXT} if pure else {"php": "8.5.11", "wordpress": "7.1.2", "woocommerce": "11.1.2", "database_version": "11.4.13-MariaDB-ubu2404", "hpos": "yes" if index == 1 else "no", "schema_before": "11", "context": V.NATIVE_CONTEXT, "background_requests": V.BACKGROUND}
        for (case_id, evidence, fields), row in zip(expected.pure if pure else expected.native, pure_rows if pure else native_rows):
            assert len(fields) == len(row)
            report["cases"].append({"id": case_id, "status": "PASS", "evidence": dict.fromkeys(evidence, True), "observations": dict(zip(fields, row))})
        reports.append(report)
    return reports, priors, hashes, expected


class BoundReceiptControls(unittest.TestCase):
    def setUp(self): self.reports, self.priors, self.hashes, self.expected = packet()
    def check(self): return V.verify(self.reports, self.priors, self.hashes, self.expected)
    def test_exact_source_protocol_accepts_separate_pure_and_native_modes(self): self.assertEqual((18, 7), self.check())
    def test_missing_extra_reordered_failed_duplicate_case_refuses(self):
        for lane in range(3):
            for mutation in ("missing", "extra", "reordered", "failed", "duplicate"):
                self.setUp(); cases = self.reports[lane]["cases"]
                if mutation == "missing": cases.pop()
                if mutation == "extra": cases.append(deepcopy(cases[0]))
                if mutation == "reordered": cases[0], cases[1] = cases[1], cases[0]
                if mutation == "failed": cases[0]["status"] = "FAIL"
                if mutation == "duplicate": cases[1] = deepcopy(cases[0])
                with self.subTest(lane=lane, mutation=mutation), self.assertRaises(RuntimeError): self.check()
    def test_closed_exact_boolean_and_numeric_types_refuse(self):
        for lane in range(3):
            for field in ("evidence", "observations"):
                for value in (False, True, 1.0, "1", None, [], {"private": "body"}):
                    if field == "evidence" and value is True: continue
                    self.setUp(); mapping = self.reports[lane]["cases"][0][field]; mapping[next(iter(mapping))] = value
                    with self.subTest(lane=lane, field=field, value=value), self.assertRaises(RuntimeError): self.check()
                self.setUp(); self.reports[lane]["cases"][0][field]["private_body"] = 1
                with self.assertRaises(RuntimeError): self.check()
    def test_every_exact_work_observation_refuses_changed_manual_number(self):
        for case_index, case in enumerate(self.reports[0]["cases"]):
            for key in case["observations"]:
                if key == "input_bytes": continue
                self.setUp(); self.reports[0]["cases"][case_index]["observations"][key] += 1
                with self.subTest(case=case_index, key=key), self.assertRaises(RuntimeError): self.check()
        for lane in (1, 2):
            for case_index, key in ((1, "loaded_lines"), (2, "wordpress_queries"), (4, "owned_network_requests"), (4, "callbacks_during_owned_sql"), (4, "connections"), (5, "duration_minutes")):
                self.setUp(); self.reports[lane]["cases"][case_index]["observations"][key] += 1
                with self.subTest(lane=lane, case=case_index, key=key), self.assertRaises(RuntimeError): self.check()
    def test_every_identity_hash_source_runtime_and_earlier_receipt_is_required(self):
        for lane in range(3):
            for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash", "installed_php_sources"):
                self.setUp(); self.reports[lane][key] = {} if key == "installed_php_sources" else "9" * len(self.reports[lane][key])
                with self.subTest(lane=lane, key=key), self.assertRaises(RuntimeError): self.check()
            self.setUp(); self.reports[lane]["environment"]["php"] = "8.3.6"
            with self.assertRaises(RuntimeError): self.check()
            for kind in self.hashes:
                for key in ("sha256", "cases"):
                    self.setUp(); self.reports[lane]["preceding_receipts"][kind][key] = "9" * 64 if key == "sha256" else 1
                    with self.subTest(lane=lane, kind=kind, key=key), self.assertRaises(RuntimeError): self.check()
        for index in range(3):
            self.setUp(); self.priors[index]["cases"][0]["status"] = "FAIL"
            with self.assertRaises(RuntimeError): self.check()
    def test_distinct_modes_cleanup_private_fields_and_scope_cannot_be_overridden(self):
        for lane in (1, 2):
            self.setUp(); self.reports[lane]["environment"]["hpos"] = "no" if lane == 1 else "yes"
            with self.assertRaises(RuntimeError): self.check()
            self.setUp(); cleanup = self.reports[lane]["cases"][-1]["evidence"]; cleanup[next(iter(cleanup))] = False
            with self.assertRaises(RuntimeError): self.check()
        for lane in range(3):
            self.setUp(); self.reports[lane]["limits"] = ["Everything production-ready"]
            with self.assertRaises(RuntimeError): self.check()
            self.setUp(); self.reports[lane]["private_packet"] = "untrusted"
            with self.assertRaises(RuntimeError): self.check()


if __name__ == "__main__": unittest.main()
