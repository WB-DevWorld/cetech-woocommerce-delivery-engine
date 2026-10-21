#!/usr/bin/env python3
"""Synthetic closed-receipt adversarial controls; these are not native HTTP proof."""
from copy import deepcopy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
sys.dont_write_bytecode = True
HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("staff_guide_http_verifier_controls", HERE / "verify-opening-promise-staff-guide-http.py")
V = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = V
spec.loader.exec_module(V)


def packet():
    protocol, scope = V.p05.http_protocol()
    sources = {"cetech-woocommerce-delivery-engine.php": "1" * 64, "src/Bounded.php": "2" * 64, "uninstall.php": "3" * 64}
    primary = {
        "format": "cetech-opening-http-promise-native-configuration-v1", "status": "PASS",
        "source_head": "a" * 40, "candidate_head": "b" * 40, "source_tree": "c" * 40,
        "installed_php_sources": sources, "installed_php_sources_hash": V.p05.placement.canonical_hash(sources),
        "environment": {"php": "8.5.11", "wordpress": "7.1.2", "woocommerce": "11.1.2", "database_version": "11.4.13-MariaDB-ubu2404", "hpos": "yes", "schema_before": "11", "context": scope["CONTEXT"], "background_requests": scope["BACKGROUND"]},
        "limits": scope["LIMITS"], "browser_runtime": {"playwright": "1.58.2", "chromium": "145.0.7632.6"},
        "preceding_receipts": {kind: {"sha256": "e" * 64, "cases": count} for kind, count in zip(V.PRIMARY_KINDS, (495, 20, 143, 19, 49, 33, 33, 14, len(V.p05.protocol()), len(V.p05.protocol())))},
        "cases": [{"id": case_id, "status": "PASS", "evidence": dict.fromkeys(fields, True)} for case_id, fields in protocol],
    }
    sidecar = {key: deepcopy(primary[key]) for key in V.IDENTITY_KEYS + ("installed_php_sources", "environment")}
    sidecar.update(format=V.FORMAT, status="PASS", limits=V.LIMITS.copy(),
                   cases=[{"id": case_id, "status": "PASS", "evidence": dict.fromkeys(fields, True)} for case_id, fields in V.PROTOCOL],
                   shared_cleanup=dict.fromkeys(V.SHARED_CLEANUP_KEYS, True))
    return sidecar, primary


class StaffGuideReceiptControls(unittest.TestCase):
    def test_complete_sidecar_and_original_nineteen_observations_pass(self):
        sidecar, primary = packet()
        self.assertEqual(7, V.verify(sidecar, primary))

    def test_case_inventory_and_failed_cases_refuse_in_both_receipts(self):
        for lane in ("guide", "primary"):
            for mutation in ("missing", "extra", "duplicate", "reordered", "failed", "wrong_id"):
                sidecar, primary = packet()
                cases = (sidecar if lane == "guide" else primary)["cases"]
                if mutation == "missing": cases.pop()
                if mutation == "extra": cases.append(deepcopy(cases[0]))
                if mutation == "duplicate": cases[1] = deepcopy(cases[0])
                if mutation == "reordered": cases[0], cases[1] = cases[1], cases[0]
                if mutation == "failed": cases[0]["status"] = "FAIL"
                if mutation == "wrong_id": cases[0]["id"] = "FORGED-PASS"
                with self.subTest(lane=lane, mutation=mutation), self.assertRaises(RuntimeError):
                    V.verify(sidecar, primary)

    def test_bool_coercion_missing_and_private_observations_refuse(self):
        for lane in ("guide", "primary"):
            for value in (False, 1, "true", None, [], {"private_packet": "untrusted"}):
                sidecar, primary = packet()
                evidence = (sidecar if lane == "guide" else primary)["cases"][0]["evidence"]
                evidence[next(iter(evidence))] = value
                with self.subTest(lane=lane, value=value), self.assertRaises(RuntimeError):
                    V.verify(sidecar, primary)
            for mutation in ("missing", "private"):
                sidecar, primary = packet()
                evidence = (sidecar if lane == "guide" else primary)["cases"][0]["evidence"]
                if mutation == "missing": evidence.pop(next(iter(evidence)))
                else: evidence["private_packet"] = "untrusted"
                with self.subTest(lane=lane, mutation=mutation), self.assertRaises(RuntimeError):
                    V.verify(sidecar, primary)
        sidecar, primary = packet()
        evidence = sidecar["cases"][3]["evidence"]
        evidence["original_request_replay_once"] = evidence.pop("original_stale_form_repost_has_no_effect")
        with self.assertRaises(RuntimeError):
            V.verify(sidecar, primary)
        sidecar, primary = packet()
        evidence = sidecar["cases"][5]["evidence"]
        evidence["no_checkout_or_payment_during_guide"] = evidence.pop("protected_order_and_shipment_history_unchanged")
        with self.assertRaises(RuntimeError):
            V.verify(sidecar, primary)

    def test_unknown_envelope_status_and_scope_refuse(self):
        for lane in ("guide", "primary"):
            for mutation in ("extra", "missing", "format", "status", "limits"):
                sidecar, primary = packet()
                target = sidecar if lane == "guide" else primary
                if mutation == "extra": target["private"] = "untrusted"
                if mutation == "missing": target.pop("status")
                if mutation == "format": target["format"] = "alternate-v1"
                if mutation == "status": target["status"] = "RUNNING"
                if mutation == "limits": target["limits"] = []
                with self.subTest(lane=lane, mutation=mutation), self.assertRaises(RuntimeError):
                    V.verify(sidecar, primary)

    def test_exact_identity_source_map_and_pinned_runtime_refuse_skew(self):
        for key in V.IDENTITY_KEYS + ("installed_php_sources", "environment"):
            sidecar, primary = packet()
            sidecar[key] = {} if key in ("installed_php_sources", "environment") else "9" * len(sidecar[key])
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                V.verify(sidecar, primary)
        for key, value in (("php", "8.3.6"), ("hpos", "no"), ("schema_before", "10"), ("database_version", "10.11.14-MariaDB"), ("context", "alternate proof"), ("background_requests", "queue enabled")):
            sidecar, primary = packet()
            sidecar["environment"][key] = primary["environment"][key] = value
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                V.verify(sidecar, primary)
        for mutation in ("unsorted", "empty", "digest", "unsafe_path", "invalid_member"):
            sidecar, primary = packet()
            if mutation == "unsorted": primary["installed_php_sources"] = dict(reversed(list(primary["installed_php_sources"].items())))
            if mutation == "empty": primary["installed_php_sources"] = {}
            if mutation == "digest": primary["installed_php_sources_hash"] = "0" * 64
            if mutation == "unsafe_path": primary["installed_php_sources"] = {"../private.php": "1" * 64}
            if mutation == "invalid_member": primary["installed_php_sources"] = {"src/Bounded.php": "invalid"}
            if mutation != "digest": primary["installed_php_sources_hash"] = V.p05.placement.canonical_hash(primary["installed_php_sources"])
            sidecar["installed_php_sources"] = deepcopy(primary["installed_php_sources"])
            sidecar["installed_php_sources_hash"] = primary["installed_php_sources_hash"]
            with self.subTest(mutation=mutation), self.assertRaises(RuntimeError):
                V.verify(sidecar, primary)

    def test_shared_cleanup_and_original_prerequisites_are_not_optional(self):
        for value in (False, 1, "true", None):
            for key in V.SHARED_CLEANUP_KEYS:
                sidecar, primary = packet()
                sidecar["shared_cleanup"][key] = value
                with self.subTest(key=key, value=value), self.assertRaises(RuntimeError):
                    V.verify(sidecar, primary)
        for mutation in ("missing", "extra", "digest", "count", "bool_count", "browser"):
            sidecar, primary = packet()
            if mutation == "missing": primary["preceding_receipts"].pop("native")
            if mutation == "extra": primary["preceding_receipts"]["untrusted"] = {}
            if mutation == "digest": primary["preceding_receipts"]["native"]["sha256"] = "invalid"
            if mutation == "count": primary["preceding_receipts"]["native"]["cases"] = 494
            if mutation == "bool_count": primary["preceding_receipts"]["native"]["cases"] = True
            if mutation == "browser": primary["browser_runtime"]["chromium"] = "unqualified"
            with self.subTest(mutation=mutation), self.assertRaises(RuntimeError):
                V.verify(sidecar, primary)

    def test_loader_refuses_duplicate_members_and_nonfinite_json(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "control.json"
            for body in ('{"status":"PASS","status":"FAIL"}', '{"status":NaN}', '{"status":Infinity}'):
                path.write_text(body)
                with self.subTest(body=body), self.assertRaises(RuntimeError):
                    V.load(path)

    def test_cli_verifies_only_the_two_complete_receipts(self):
        sidecar, primary = packet()
        with tempfile.TemporaryDirectory() as directory:
            paths = [Path(directory) / name for name in ("guide.json", "primary.json")]
            for path, report in zip(paths, (sidecar, primary)): path.write_text(json.dumps(report))
            result = subprocess.run([sys.executable, str(HERE / "verify-opening-promise-staff-guide-http.py"), *map(str, paths)], capture_output=True, timeout=20)
            self.assertEqual(0, result.returncode, result.stderr.decode(errors="replace"))
            self.assertEqual(b"promise_staff_guide_http_receipt=PASS guide=7 retained_http=19\n", result.stdout)
            result = subprocess.run([sys.executable, str(HERE / "verify-opening-promise-staff-guide-http.py"), str(paths[0])], capture_output=True, timeout=20)
            self.assertNotEqual(0, result.returncode)
            self.assertEqual(b"promise_staff_guide_http_receipt=FAIL\n", result.stderr)


if __name__ == "__main__":
    unittest.main()
