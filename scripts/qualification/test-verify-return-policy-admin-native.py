#!/usr/bin/env python3
"""Adversarial offline protocol checks; no WordPress or physical database boot."""
import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from unittest.mock import patch
from unittest.mock import Mock

SOURCE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("r21_native_verifier", SOURCE / "verify-return-policy-admin-native.py")
verifier = importlib.util.module_from_spec(spec)
spec.loader.exec_module(verifier)


class ReturnPolicyNativeProtocolTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.root = Path(self.directory.name)
        self.path = self.root / "receipt.json"
        self.native = {
            "source_head": "a" * 40, "candidate_head": "a" * 40, "source_tree": "b" * 40,
            "installed_php_sources": {"src/Example.php": "c" * 64},
            "installed_php_sources_hash": "d" * 64,
            "environment": {"php": "8.5.11", "wordpress": "7.1.2", "woocommerce": "11.1.2", "database_version": "11.4.13-MariaDB", "hpos": "yes", "schema_before": "12"},
        }
        self.pins = {name: str(index) * 64 for index, name in enumerate(verifier.PRODUCERS, 1)}
        self.report = {
            "format": verifier.FORMAT, "status": "PASS", **{key: self.native[key] for key in verifier.IDENTITY},
            "installed_php_sources": self.native["installed_php_sources"], "environment": self.native["environment"],
            "context": verifier.CONTEXT, "limits": verifier.LIMITS, "preceding_native": {"sha256": "e" * 64, "cases": 495},
            "producer_sha256": self.pins,
            "cases": [{"id": case, "status": "PASS", "evidence": dict.fromkeys(fields, True)} for case, fields in verifier.protocol()],
        }
        self.options = types.SimpleNamespace(receipt=str(self.path), work=str(self.root))

    def tearDown(self):
        self.directory.cleanup()

    def verify(self, report=None):
        self.path.write_text(json.dumps(self.report if report is None else report))
        with patch.object(verifier, "prior", return_value=(self.native, "e" * 64)), patch.object(verifier, "producers", return_value=self.pins):
            return verifier.verify(self.options)

    def reject(self, mutate):
        report = copy.deepcopy(self.report)
        mutate(report)
        with self.assertRaises(ValueError):
            self.verify(report)

    def test_authored_closed_complete_protocol_is_accepted(self):
        self.assertEqual(0, self.verify())
        self.assertEqual(16, len(verifier.protocol()))

    def test_each_missing_case_is_rejected(self):
        for index in range(len(self.report["cases"])):
            with self.subTest(index=index):
                self.reject(lambda report: report["cases"].pop(index))

    def test_reordered_and_duplicate_cases_are_rejected(self):
        self.reject(lambda report: report["cases"].reverse())
        self.reject(lambda report: report["cases"].append(copy.deepcopy(report["cases"][0])))
        self.reject(lambda report: report["cases"].__setitem__(1, copy.deepcopy(report["cases"][0])))

    def test_every_observation_requires_actual_boolean_true(self):
        for index, case in enumerate(self.report["cases"]):
            field = next(iter(case["evidence"]))
            for value in (False, 1, "true", None, [], {}):
                with self.subTest(case=case["id"], value=value):
                    self.reject(lambda report: report["cases"][index]["evidence"].__setitem__(field, value))

    def test_unknown_and_missing_observation_fields_are_rejected(self):
        self.reject(lambda report: report["cases"][0]["evidence"].__setitem__("invented", True))
        self.reject(lambda report: report["cases"][0]["evidence"].pop(next(iter(report["cases"][0]["evidence"]))))
        self.reject(lambda report: report["cases"][0].__setitem__("raw_private_output", "forbidden"))

    def test_all_identity_map_and_pin_drift_is_rejected(self):
        for key in verifier.IDENTITY:
            with self.subTest(key=key):
                self.reject(lambda report: report.__setitem__(key, "f" * len(report[key])))
        self.reject(lambda report: report["installed_php_sources"].__setitem__("src/Example.php", "f" * 64))
        self.reject(lambda report: report["producer_sha256"].__setitem__(verifier.PRODUCERS[0], "f" * 64))
        self.reject(lambda report: report["preceding_native"].__setitem__("sha256", "f" * 64))
        self.reject(lambda report: report["preceding_native"].__setitem__("cases", 494))

    def test_scope_environment_and_status_cannot_be_relabelled(self):
        self.reject(lambda report: report.__setitem__("status", "RUNNING"))
        self.reject(lambda report: report.__setitem__("format", "other-v1"))
        self.reject(lambda report: report.__setitem__("context", "browser acceptance"))
        self.reject(lambda report: report["limits"].clear())
        self.reject(lambda report: report["environment"].__setitem__("php", "8.4.0"))
        self.reject(lambda report: report.__setitem__("private_credentials", {}))

    def test_cleanup_observations_are_not_optional(self):
        for index in (-2, -1):
            for field in self.report["cases"][index]["evidence"]:
                with self.subTest(field=field):
                    self.reject(lambda report: report["cases"][index]["evidence"].__setitem__(field, False))

    def test_finalize_retains_a_failed_journey(self):
        report = copy.deepcopy(self.report)
        report["cases"].pop()
        report["status"] = "FAIL"
        self.path.write_text(json.dumps(report))
        self.options.finalize = ["1"] * 4
        with patch.object(verifier, "prior", return_value=(self.native, "e" * 64)), patch.object(verifier, "producers", return_value=self.pins), self.assertRaises(ValueError):
            verifier.finalize(self.options)
        self.assertEqual("FAIL", json.loads(self.path.read_text())["status"])

    def test_prior_rejects_incomplete_or_noncanonical_source_map(self):
        native = copy.deepcopy(self.native)
        native.update(status="PASS", cases=[{"id": "synthetic-prior-" + str(index), "status": "PASS"} for index in range(495)])
        path = self.root / "opening-qualification-results.json"
        native["installed_php_sources_hash"] = verifier.hashlib.sha256(verifier.canonical(native["installed_php_sources"])).hexdigest()
        path.write_text(json.dumps(native))
        with patch.dict(verifier.os.environ, {}, clear=True):
            self.assertEqual(native, verifier.prior(self.root)[0])
            native["installed_php_sources_hash"] = "f" * 64
            path.write_text(json.dumps(native))
            with self.assertRaises(ValueError):
                verifier.prior(self.root)

    def test_symlink_and_nonprivate_credential_files_are_rejected(self):
        self.path.write_text("{}")
        link = self.root / "linked.json"
        link.symlink_to(self.path)
        with self.assertRaises(ValueError):
            verifier.load(link)
        self.path.chmod(0o644)
        with self.assertRaises(ValueError):
            verifier.load(self.path, private=True)

    def test_actual_copied_mu_is_inert_during_cli_bridges(self):
        copied = self.root / "cetech-return-policy-admin-fixture.php"
        copied.write_bytes((SOURCE / "return-policy-admin-native.php").read_bytes())
        code = "define('WP_CLI',true);function add_filter(...$args){if($args[0]!=='action_scheduler_allow_async_request_runner')throw new RuntimeException();}require $argv[1];if(function_exists('cetech_r21_rows'))throw new RuntimeException('MU registered private CLI helpers');echo 'inert-cli-mu';"
        result = subprocess.run(["php", "-r", code, str(copied)], capture_output=True, text=True, check=False, timeout=10, env={**verifier.os.environ, "CETECH_DE_NATIVE_OPENING_QUALIFICATION": "1", "CETECH_DE_HTTP_OPENING_QUALIFICATION": "1"})
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("inert-cli-mu", result.stdout)

    def test_actual_copied_mu_then_original_cli_include_does_not_redeclare(self):
        copied = self.root / "cetech-return-policy-admin-fixture.php"
        copied.write_bytes((SOURCE / "return-policy-admin-native.php").read_bytes())
        # No WordPress boot: after the actual MU returns, original execution must
        # reach its explicit CLI argument guard, rather than a compilation fatal.
        code = "define('WP_CLI',true);function add_filter(...$args){}require $argv[1];if(function_exists('cetech_r21_rows'))throw new RuntimeException('MU helper leaked');try{require $argv[2];}catch(RuntimeException $error){if($error->getMessage()!=='R21 expects MODE PRIVATE_STATE OUTPUT.')throw $error;echo 'dual-include-guarded';}"
        result = subprocess.run(["php", "-r", code, str(copied), str(SOURCE / "return-policy-admin-native.php")], capture_output=True, text=True, check=False, timeout=10, env={**verifier.os.environ, "CETECH_DE_NATIVE_OPENING_QUALIFICATION": "1", "CETECH_DE_HTTP_OPENING_QUALIFICATION": "1"})
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("dual-include-guarded", result.stdout)

    def test_startup_retry_sends_only_possession_probe_before_credentials(self):
        response = Mock()
        response.__enter__ = Mock(return_value=response)
        response.__exit__ = Mock(return_value=False)
        response.code = 200
        response.read.return_value = b'{"probe":true}'
        opener = Mock()
        opener.open.side_effect = [verifier.URLError("not listening yet"), response]
        client = types.SimpleNamespace(resolve=lambda path: "http://127.0.0.1:8085" + path, opener=opener)
        with patch.object(verifier.time, "sleep") as sleep, patch.object(verifier.time, "monotonic", side_effect=[0, 0.1]):
            self.assertEqual((200, {"probe": True}), verifier.listener_probe(client, {"probe_token": "a" * 48}))
        self.assertEqual(2, opener.open.call_count)
        sleep.assert_called_once_with(0.1)
        for call in opener.open.call_args_list:
            request = call.args[0]
            self.assertEqual("GET", request.get_method())
            self.assertIsNone(request.data)
            self.assertIsNone(request.get_header("Cookie"))
            self.assertIsNone(request.get_header("Authorization"))
            self.assertEqual(1, call.kwargs["timeout"])

    def test_unavailable_listener_cannot_poll_past_finite_deadline(self):
        opener = Mock()
        opener.open.side_effect = verifier.URLError("not listening")
        client = types.SimpleNamespace(resolve=lambda path: "http://127.0.0.1:8085" + path, opener=opener)
        with patch.object(verifier.time, "sleep") as sleep, patch.object(verifier.time, "monotonic", side_effect=[0, 11]), self.assertRaises(RuntimeError):
            verifier.listener_probe(client, {"probe_token": "a" * 48})
        self.assertEqual(1, opener.open.call_count)
        sleep.assert_not_called()


if __name__ == "__main__":
    unittest.main()
