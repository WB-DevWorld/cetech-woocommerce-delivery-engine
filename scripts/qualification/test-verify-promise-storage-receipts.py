#!/usr/bin/env python3
"""Bounded adversarial protocol tests; synthetic packets are never native proof."""
from copy import deepcopy
import importlib.util
import json
from pathlib import Path
import sys
sys.dont_write_bytecode = True
import tempfile
import unittest

HERE = Path(__file__).resolve().parent


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, HERE / filename)
    result = importlib.util.module_from_spec(spec)
    sys.modules[name] = result
    spec.loader.exec_module(result)
    return result


V = module("promise_storage_verifier_tests", "verify-promise-storage-receipts.py")
P = module("retained_placement_packet_tests", "test-verify-quote-placement-receipts.py")


def packet():
    retained, reports = P.packet_set()
    expected = V.Authority(retained, V.protocol())
    hashes = ["4" * 64, "5" * 64, "6" * 64]
    environment = reports[0]["environment"].copy()
    environment["context"] = V.CONTEXT
    report = {
        "format": "cetech-opening-promise-storage-v1", "source_head": retained.source_head,
        "candidate_head": retained.candidate_head, "source_tree": retained.source_tree,
        "installed_php_sources": retained.sources.copy(), "installed_php_sources_hash": V.placement.canonical_hash(retained.sources),
        "environment": environment, "limits": V.LIMITS.copy(), "status": "PASS",
        "cases": [{"id": case_id, "status": "PASS", "evidence": dict.fromkeys(fields, True)} for case_id, fields in expected.cases],
        "retained_receipts": {kind: {"sha256": hashes[i], "cases": len(reports[i]["cases"])} for i, kind in enumerate(("native", "cpt", "http"))},
    }
    return report, reports, hashes, expected


class PromiseReceiptTests(unittest.TestCase):
    def setUp(self):
        self.report, self.retained, self.hashes, self.expected = packet()

    def test_complete_source_protocol_accepts(self):
        self.assertEqual(len(self.expected.cases), V.verify(self.report, self.retained, self.hashes, self.expected))

    def test_missing_extra_reordered_duplicate_or_failed_case_refuses(self):
        for alteration in ("missing", "extra", "reordered", "duplicate", "failed"):
            with self.subTest(alteration=alteration):
                changed = deepcopy(self.report)
                if alteration == "missing": changed["cases"].pop()
                if alteration == "extra": changed["cases"].append(deepcopy(changed["cases"][-1]))
                if alteration == "reordered": changed["cases"][0], changed["cases"][1] = changed["cases"][1], changed["cases"][0]
                if alteration == "duplicate": changed["cases"][1] = deepcopy(changed["cases"][0])
                if alteration == "failed": changed["cases"][0]["status"] = "FAIL"
                with self.assertRaises(RuntimeError): V.verify(changed, self.retained, self.hashes, self.expected)

    def test_bool_type_and_closed_evidence_refuse(self):
        for value in (False, 1, "true", None, [], {}):
            with self.subTest(value=value):
                changed = deepcopy(self.report)
                key = next(iter(changed["cases"][0]["evidence"]))
                changed["cases"][0]["evidence"][key] = value
                with self.assertRaises(RuntimeError): V.verify(changed, self.retained, self.hashes, self.expected)
        for mutation in ("extra", "missing"):
            changed = deepcopy(self.report)
            if mutation == "extra": changed["cases"][0]["evidence"]["caller_claimed"] = True
            else: changed["cases"][0]["evidence"].popitem()
            with self.assertRaises(RuntimeError): V.verify(changed, self.retained, self.hashes, self.expected)

    def test_cleanup_boolean_cannot_be_omitted_or_false(self):
        cleanup = self.report["cases"][-1]
        for key in cleanup["evidence"]:
            with self.subTest(key=key):
                changed = deepcopy(self.report); changed["cases"][-1]["evidence"][key] = False
                with self.assertRaises(RuntimeError): V.verify(changed, self.retained, self.hashes, self.expected)

    def test_foreign_identity_and_installed_source_refuse(self):
        for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash"):
            with self.subTest(key=key):
                changed = deepcopy(self.report); changed[key] = "f" * len(changed[key])
                with self.assertRaises(RuntimeError): V.verify(changed, self.retained, self.hashes, self.expected)
        changed = deepcopy(self.report); changed["installed_php_sources"]["uninstall.php"] = "f" * 64
        with self.assertRaises(RuntimeError): V.verify(changed, self.retained, self.hashes, self.expected)

    def test_runtime_schema_or_scope_refuses(self):
        for key in self.report["environment"]:
            with self.subTest(key=key):
                changed = deepcopy(self.report); changed["environment"][key] = "unknown"
                with self.assertRaises(RuntimeError): V.verify(changed, self.retained, self.hashes, self.expected)
        changed = deepcopy(self.report); changed["environment"]["schema_before"] = "9"
        with self.assertRaises(RuntimeError): V.verify(changed, self.retained, self.hashes, self.expected)
        changed = deepcopy(self.report); changed["limits"].pop()
        with self.assertRaises(RuntimeError): V.verify(changed, self.retained, self.hashes, self.expected)

    def test_preceding_failure_or_wrong_raw_bytes_cannot_be_compensated(self):
        for index in range(3):
            with self.subTest(index=index):
                retained = deepcopy(self.retained); retained[index]["status"] = "FAIL"
                with self.assertRaises(RuntimeError): V.verify(self.report, retained, self.hashes, self.expected)
                hashes = self.hashes.copy(); hashes[index] = "f" * 64
                with self.assertRaises(RuntimeError): V.verify(self.report, self.retained, hashes, self.expected)
        changed = deepcopy(self.report); changed["retained_receipts"]["native"]["cases"] = True
        with self.assertRaises(RuntimeError): V.verify(changed, self.retained, self.hashes, self.expected)

    def test_private_or_unknown_envelope_fields_refuse(self):
        changed = deepcopy(self.report); changed["private_policy_body"] = {"secret": "unused"}
        with self.assertRaises(RuntimeError): V.verify(changed, self.retained, self.hashes, self.expected)
        changed = deepcopy(self.report); changed["retained_receipts"]["native"]["caller_claimed"] = True
        with self.assertRaises(RuntimeError): V.verify(changed, self.retained, self.hashes, self.expected)

    def test_duplicate_json_member_and_nonfinite_number_refuse(self):
        for raw in ('{"status":"PASS","status":"PASS"}', '{"number":NaN}'):
            with tempfile.TemporaryDirectory() as directory:
                path = Path(directory) / "receipt.json"; path.write_text(raw)
                with self.assertRaises(RuntimeError): V.load(path)


if __name__ == "__main__":
    unittest.main()
