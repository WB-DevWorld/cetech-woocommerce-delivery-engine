#!/usr/bin/env python3
"""Adversarial receipt boundary tests; these packets make no native qualification claim.

Small synthetic legacy inventories exercise preservation without copying prior
financial payloads. Current Q06 producer protocols supply the required vocabulary.
"""
from copy import deepcopy
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch


HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("placement_receipt_verifier", HERE / "verify-quote-placement-receipts.py")
VERIFIER = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = VERIFIER
SPEC.loader.exec_module(VERIFIER)


def case(case_id, facts):
    return {"id": case_id, "status": "PASS", "evidence": facts}


def packet_set():
    specs, vocabulary = VERIFIER.native_protocol()
    driver = VERIFIER.http_protocol()
    native_old = [
        case("LEGACY-NATIVE-NEGATIVE-OBSERVATION", {"role_exists": False, "cleanup_restored": True}),
        case("NATIVE-W2Q05-READER-CLEANUP", {key: True for key in VERIFIER.RETAINED_CLEANUP_KEYS["NATIVE-W2Q05-READER-CLEANUP"]}),
        case("NATIVE-FIXTURE-SCHEMA-RESTORED", {"schema_after": "9"}),
    ]
    http_old = [
        case("LEGACY-HTTP-NEGATIVE-OBSERVATION", {"user_exists": False, "cleanup_restored": True}),
        case("HTTP-W2Q05-BLOCKS-CONFIRM-ONE-ACCEPT-NO-PLACEMENT", {"synthetic_retained_observation": True}),
        case("HTTP-W2Q05-FIXTURE-CLEANUP", {key: True for key in VERIFIER.RETAINED_CLEANUP_KEYS["HTTP-W2Q05-FIXTURE-CLEANUP"]}),
        case("HTTP-FIXTURE-MU-AND-CREDENTIAL-FILES-REMOVED", {"fixture_mu_removed": True, "private_directory_removed": True}),
    ]
    sources = {"cetech-woocommerce-delivery-engine.php": "1" * 64, "src/Bounded.php": "2" * 64, "uninstall.php": "3" * 64}
    extensions = {"Core": "8.5.11", "Zend OPcache": "8.5.11"}
    fingerprints = {"php_binary_sha256": "4" * 64, "full_ini_sha256": "5" * 64, "full_ini_jit1235_sha256": "6" * 64, "extensions_sha256": VERIFIER.canonical_hash(extensions)}
    expected = VERIFIER.Authority(
        "a" * 40, "b" * 40, "c" * 40, sources, specs, vocabulary, driver,
        len(native_old), VERIFIER.canonical_hash([entry["id"] for entry in native_old]),
        len(http_old), VERIFIER.canonical_hash([entry["id"] for entry in http_old]),
        runtime_fingerprints=fingerprints,
    )

    def native_cases(hpos):
        result = []
        for case_id, fields in specs:
            facts = {}
            for key, expression in fields.items():
                if key in vocabulary["BOOLS"]:
                    facts[key] = hpos if expression == "$hpos" else expression != "false"
                else:
                    facts[key] = {"native_line_count": 2, "native_shipping_count": 1, "binding_commits": 3}.get(key, 1)
            result.append(case(case_id if hpos else case_id.replace(VERIFIER.NATIVE_PREFIX, VERIFIER.CPT_PREFIX, 1), facts))
        return result

    new_http = []
    for case_id in driver.REQUIRED_IDS:
        if case_id == driver.CLEANUP_ID:
            new_http.append(case(case_id, {key: True for key in driver.CLEANUP_KEYS}))
            continue
        flags = driver.DIRECT_BOOLS.get(case_id, driver.BROWSER_BOOLS + (("one_free_completion",) if case_id == driver.BROWSER_IDS[1] else ("one_gateway_call",)))
        before = dict.fromkeys(driver.COUNTS, 0)
        after = before.copy()
        if "one_gateway_call" in flags:
            after["gateway_calls"] = 1
        if "one_free_completion" in flags:
            after["free_completion_calls"] = 1
        new_http.append(case(case_id, {"before": before, "after": after, "observations": dict.fromkeys(flags, True), "http_status": 303 if "native_303_termination" in flags else 200}))

    def envelope(kind, cases):
        environment = {"php": "8.5.11", "wordpress": "7.1.2", "woocommerce": "11.1.2", "database_version": "11.4.13-MariaDB-ubu2404", "hpos": "no" if kind == "cpt" else "yes", **VERIFIER.ENVIRONMENT_SCOPE[kind]}
        if kind != "cpt":
            environment["schema" if kind == "http" else "schema_before"] = "9"
        result = {"format": "cetech-opening-http-qualification-v1" if kind == "http" else "cetech-opening-native-qualification-v1", "source_head": expected.source_head, "candidate_head": expected.candidate_head, "source_tree": expected.source_tree, "installed_php_sources": sources.copy(), "installed_php_sources_hash": VERIFIER.canonical_hash(sources), "environment": environment, "status": "PASS", "cases": cases}
        if kind != "http":
            result["limits"] = (VERIFIER.CPT_LIMITS if kind == "cpt" else VERIFIER.NATIVE_LIMITS).copy()
        return result

    native = envelope("native", native_old[:2] + native_cases(True) + native_old[2:])
    cpt = envelope("cpt", native_cases(False))
    http = envelope("http", http_old[:2] + new_http + http_old[2:])
    http.update({"identity_verified": True, "mode": "prepare", "principal_role": "cetech_http_import_" + "a" * 16, "principal_user_id": 7})
    http["diagnostic_runtime"] = {
        "sapi": "cli-server", "php_version": "8.5.11", "php_binary_sha256": "4" * 64,
        "extensions": extensions, "extensions_sha256": VERIFIER.canonical_hash(extensions), "full_ini_sha256": "5" * 64,
        "full_ini_jit1235_sha256": "6" * 64, "historical_ini_comparison_available": False,
        "safe_ini": {"memory_limit": "-1", "max_execution_time": "30", "opcache.enable": "1", "opcache.enable_cli": "0", "opcache.jit": "disable", "opcache.jit_buffer_size": "256M", "opcache.optimization_level": "0x7FFEBFFF", "opcache.protect_memory": "0"},
        "opcache_state_at_existing_probe": {"status_available": True, "opcache_enabled": True, "cache_full": False, "restart_pending": False, "restart_in_progress": False, "statistics": {"num_cached_scripts": 1, "hits": 0, "misses": 1}, "jit": {"enabled": False, "on": False, "kind": 0, "opt_level": 0, "opt_flags": 0, "buffer_size": 0, "buffer_free": 0}},
        "qualification_runtime_policy": deepcopy(VERIFIER.POLICY),
        "expected_qualification_runtime": dict.fromkeys(("binary", "ini", "extensions", "original_ini_except_opcache_jit"), True),
    }
    symbols = {"exact_executable_loaded": True, "zend_execute_full_symbol": True, "zend_execute_data_type": True, "status": "PASS", "executable_build_id": "1" * 40, "matching_separate_debug_build_id": "1" * 40, "executable_module": "php8.5"}
    http["native_crash_diagnostic"] = {
        "capture_status": "no_listener_core", "kernel_core_pattern_restored": True, "raw_core_or_debugger_output_retained": False, "frames": [],
        "limits": "Symbols narrow native execution location; they do not establish a product defect. Prior failure did not record an INI fingerprint.",
        "synthetic_validation_is_product_reproduction": False,
        "debugger_preflight": {"frames": [], "symbol_validation": symbols.copy(), "capture_status": "preflight_pass"},
        "synthetic_core_validation": {"frames": [{"depth": 0, "symbol": "execute_ex", "module": "php8.5", "mapping_known": True, "source_file": "zend_vm_execute.h", "source_line": 1}], "symbol_validation": symbols.copy(), "capture_status": "symbols_captured"},
    }
    http["fixture_diagnostic"] = {"stage": "complete", "command_log_present": True, "command_log_sha256": "7" * 64, "allowlisted_error_codes": [], "allowlisted_error_classes": [], "raw_output_retained": False, "listener_exit_before_cleanup": "running", "listener_signal_before_cleanup": None, "owned_listener_wait_exit": "143", "owned_listener_wait_signal": "15", "listener_cleanup_requested_sigterm": True, "server_log_present": True, "server_log_sha256": "8" * 64, "database_connect_before_cleanup": "connected", "database_connect_wait_ms": 2}
    http["fixture_lifecycle"] = dict.fromkeys(("listener_started", "owned_listener_attested", "listener_stopped", "fixture_mu_removed", "private_files_removed", "tracked_cleanup_command_success"), True)
    http["fixture_lifecycle"]["partial_init_failure_fallback"] = "entire dedicated CI database/site/service are disposable; no total-database rollback claim"
    observation = {"value_type": "string", "sha256": hashlib.sha256(b"http://127.0.0.1:8085").hexdigest(), "exact_expected": True, "scheme": "http", "host": "127.0.0.1", "port": 8085, "root_path": True, "query_present": False, "fragment_present": False, "userinfo_present": False}
    origin_facts = {layer: {slot: observation.copy() for slot in ("home", "siteurl")} for layer in ("physical", "native")}
    http["fixture_origin_preflight"] = {"format": "cetech-opening-http-origin-preflight-v1", "status": "PASS", "expected_origin": "http://127.0.0.1:8085", "same_run_native_pass_before_mutations": True, "before": deepcopy(origin_facts), "after": deepcopy(origin_facts), "option_update_returns": {"home": True, "siteurl": True}, "identity": {key: http[key] for key in VERIFIER.IDENTITY_KEYS}, "installed_php_source_files": len(sources)}
    return expected, (native, cpt, http)


class ReceiptBoundaryTest(unittest.TestCase):
    def setUp(self):
        self.authority, self.reports = packet_set()

    def refused(self, change):
        reports = deepcopy(self.reports)
        change(reports)
        with self.assertRaises((RuntimeError, ValueError, TypeError, KeyError)):
            VERIFIER.verify_reports(*reports, self.authority)

    def test_complete_closed_packets_pass_without_rewriting_negative_legacy_facts(self):
        before = deepcopy(self.reports)
        count = VERIFIER.verify_reports(*self.reports, self.authority)
        self.assertEqual(count, (self.authority.native_baseline_count + len(self.authority.native_specs), len(self.authority.native_specs), self.authority.http_baseline_count + len(self.authority.http.REQUIRED_IDS), len(self.authority.native_specs), len(self.authority.http.REQUIRED_IDS)))
        self.assertEqual(self.reports, before)
        self.assertIs(self.reports[0]["cases"][0]["evidence"]["role_exists"], False)
        self.assertIs(self.reports[2]["cases"][0]["evidence"]["user_exists"], False)

    def test_current_producer_inventories_keep_both_stores_and_actual_browser_buttons(self):
        ids = tuple(entry[0] for entry in self.authority.native_specs)
        self.assertEqual(len(ids), len(set(ids)))
        self.assertIn("NATIVE-W2Q06-PREFREEZE-NATIVE-MONEY-MUTATION-DENIED", ids)
        self.assertIn("NATIVE-W2Q06-PREFREEZE-NATIVE-METADATA-MUTATION-DENIED", ids)
        self.assertEqual(ids[-1], "NATIVE-W2Q06-EXACT-FIXTURE-CLEANUP")
        self.assertEqual(len(self.authority.http.REQUIRED_IDS), len(self.authority.http.DIRECT_IDS) + len(self.authority.http.BROWSER_IDS) + 1)
        self.assertEqual(len(self.authority.http.BROWSER_IDS), 2)
        self.assertEqual(self.authority.http.REQUIRED_IDS[-1], self.authority.http.CLEANUP_ID)

    def test_every_native_required_fact_is_typed_present_and_true_as_declared(self):
        for report_index, hpos in ((0, True), (1, False)):
            for case_index, entry in enumerate(self.reports[report_index]["cases"]):
                if not entry["id"].startswith(VERIFIER.NATIVE_PREFIX):
                    continue
                for key, original in entry["evidence"].items():
                    path = (report_index, case_index, key)
                    replacements = (not original, 1, None) if type(original) is bool else (True, -1, "1", 100001)
                    for value in replacements:
                        with self.subTest(path=path, value=value):
                            self.refused(lambda reports, ri=report_index, ci=case_index, k=key, v=value: reports[ri]["cases"][ci]["evidence"].__setitem__(k, v))
                    with self.subTest(path=path, missing=True):
                        self.refused(lambda reports, ri=report_index, ci=case_index, k=key: reports[ri]["cases"][ci]["evidence"].pop(k))
                self.refused(lambda reports, ri=report_index, ci=case_index: reports[ri]["cases"][ci]["evidence"].__setitem__("private_packet", "forbidden"))

    def test_every_http_required_observation_is_present_typed_and_true(self):
        for index, entry in enumerate(self.reports[2]["cases"]):
            if not entry["id"].startswith(self.authority.http.PREFIX):
                continue
            target = entry["evidence"] if entry["id"] == self.authority.http.CLEANUP_ID else entry["evidence"]["observations"]
            def target_of(reports, ci=index):
                evidence = reports[2]["cases"][ci]["evidence"]
                return evidence if "cleanup_restored" in evidence else evidence["observations"]
            for key in target:
                for value in (False, 1, None):
                    with self.subTest(case=entry["id"], field=key, value=value):
                        self.refused(lambda reports, k=key, v=value: target_of(reports).__setitem__(k, v))
                self.refused(lambda reports, k=key: target_of(reports).pop(k))
            self.refused(lambda reports: target_of(reports).__setitem__("private_rows", True))

    def test_missing_extra_duplicate_reordered_failed_or_split_inventory_refuses(self):
        for ri in range(3):
            with self.subTest(report=ri):
                self.refused(lambda reports, i=ri: reports[i]["cases"].pop())
                self.refused(lambda reports, i=ri: reports[i]["cases"].append(case("UNKNOWN-CASE", {})))
                self.refused(lambda reports, i=ri: reports[i]["cases"].append(deepcopy(reports[i]["cases"][0])))
                self.refused(lambda reports, i=ri: reports[i]["cases"].reverse())
                self.refused(lambda reports, i=ri: reports[i]["cases"][0].__setitem__("status", "FAIL"))
                self.refused(lambda reports, i=ri: reports[i]["cases"][0].__setitem__("unexpected", True))
                self.refused(lambda reports, i=ri: reports[i].__setitem__("error", "private diagnostic"))
                self.refused(lambda reports, i=ri: reports[i].__setitem__("cases", {}))
        for ri in (0, 2):
            self.refused(lambda reports, i=ri: reports[i]["cases"].insert(0, reports[i]["cases"].pop(2)))
            self.refused(lambda reports, i=ri: reports[i]["cases"].__setitem__(slice(0, 2), list(reversed(reports[i]["cases"][:2]))))

    def test_all_receipts_and_preflight_require_same_identity_and_exact_file_map(self):
        for ri in range(3):
            for key in VERIFIER.IDENTITY_KEYS + ("installed_php_sources",):
                with self.subTest(report=ri, key=key):
                    self.refused(lambda reports, i=ri, k=key: reports[i].__setitem__(k, {} if k == "installed_php_sources" else "0" * 40))
            self.refused(lambda reports, i=ri: reports[i]["installed_php_sources"].__setitem__("src/Extra.php", "a" * 64))
            self.refused(lambda reports, i=ri: reports[i]["installed_php_sources"].pop("src/Bounded.php"))
        self.refused(lambda reports: reports[2]["fixture_origin_preflight"]["identity"].__setitem__("candidate_head", "0" * 40))
        self.refused(lambda reports: reports[2]["fixture_origin_preflight"].__setitem__("installed_php_source_files", True))
        reordered = deepcopy(self.reports)
        for report in reordered:
            report["installed_php_sources"] = dict(reversed(list(report["installed_php_sources"].items())))
        VERIFIER.verify_reports(*reordered, self.authority)

    def test_cli_rejection_keeps_private_error_content_out_of_output(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "receipt.json"
            path.write_text('{"private_payload":"DO-NOT-PUBLISH","value":NaN}')
            result = subprocess.run([sys.executable, str(HERE / "verify-quote-placement-receipts.py"), str(path), str(path), str(path)], text=True, capture_output=True, check=False)
            self.assertEqual(result.returncode, 1)
            self.assertEqual(result.stdout, "")
            self.assertEqual(result.stderr, "quote_placement_receipts=FAIL\n")

    def test_asserted_payment_and_readonly_facts_cannot_contradict_native_counters(self):
        for index, entry in enumerate(self.reports[2]["cases"]):
            evidence = entry["evidence"]
            if "observations" not in evidence:
                continue
            flags = evidence["observations"]
            for flag, counter in (("one_gateway_call", "gateway_calls"), ("one_free_completion", "free_completion_calls")):
                if flag in flags:
                    self.refused(lambda reports, ci=index, c=counter: reports[2]["cases"][ci]["evidence"]["after"].__setitem__(c, 0))
            for flag, counters in (("no_gateway_or_free_completion", ("gateway_calls", "payment_complete_calls", "free_completion_calls", "paid")), ("reads_never_place", ("sealed", "prepared"))):
                if flag in flags:
                    for counter in counters:
                        self.refused(lambda reports, ci=index, c=counter: reports[2]["cases"][ci]["evidence"]["after"].__setitem__(c, 1))
            if "native_303_termination" in flags:
                self.refused(lambda reports, ci=index: reports[2]["cases"][ci]["evidence"].__setitem__("http_status", 200))
        index = next(i for i, entry in enumerate(self.reports[2]["cases"]) if "observations" in entry["evidence"])
        for value in (True, -1, "1", 1000001):
            self.refused(lambda reports, v=value: reports[2]["cases"][index]["evidence"]["before"].__setitem__("orders", v))

    def test_actual_native_paid_history_requires_exactly_one_gateway_effect(self):
        for ri in (0, 1):
            index = next(i for i, entry in enumerate(self.reports[ri]["cases"]) if entry["id"].endswith("ACTUAL-PAID-CALLBACKS-KEEP-SEALED-HISTORY"))
            self.assertIs(self.reports[ri]["cases"][index]["evidence"]["no_payment_invoked"], False)
            for value in (0, 2):
                self.refused(lambda reports, i=ri, ci=index, v=value: reports[i]["cases"][ci]["evidence"].__setitem__("gateway_calls", v))

    def test_well_formed_runtime_fingerprint_drift_refuses_even_with_true_comparison_claims(self):
        for key in VERIFIER.RUNTIME_PINS:
            self.assertTrue(all(self.reports[2]["diagnostic_runtime"]["expected_qualification_runtime"].values()))
            with self.subTest(fingerprint=key):
                self.refused(lambda reports, k=key: reports[2]["diagnostic_runtime"].__setitem__(k, "0" * 64))

    def test_pinned_primary_runtime_policy_and_native_observations_are_mandatory(self):
        for ri in range(3):
            for key, value in (("php", "8.4.0"), ("wordpress", "7.1.1"), ("woocommerce", "11.1.1"), ("database_version", "11.4.12-MariaDB"), ("hpos", "unknown"), ("error", "private")):
                self.refused(lambda reports, i=ri, k=key, v=value: reports[i]["environment"].__setitem__(k, v))
        for key, value in (("qualifies_jit1235_runtime", 0), ("qualifies_jit1235_runtime", True), ("profile", "other-runtime"), ("private", "forbidden")):
            self.refused(lambda reports, k=key, v=value: reports[2]["diagnostic_runtime"]["qualification_runtime_policy"].__setitem__(k, v))
        for key in self.reports[2]["diagnostic_runtime"]["expected_qualification_runtime"]:
            self.refused(lambda reports, k=key: reports[2]["diagnostic_runtime"]["expected_qualification_runtime"].__setitem__(k, False))
        self.refused(lambda reports: reports[2]["diagnostic_runtime"]["opcache_state_at_existing_probe"]["jit"].__setitem__("on", True))
        self.refused(lambda reports: reports[2]["diagnostic_runtime"]["extensions"].__setitem__("Core", "8.5.12"))
        self.refused(lambda reports: reports[2]["diagnostic_runtime"].__setitem__("private_configuration", {}))

    def test_incomplete_diagnostics_cleanup_and_origin_cannot_be_overall_pass(self):
        for key, value in self.reports[2]["fixture_lifecycle"].items():
            if type(value) is bool:
                self.refused(lambda reports, k=key: reports[2]["fixture_lifecycle"].__setitem__(k, False))
            self.refused(lambda reports, k=key: reports[2]["fixture_lifecycle"].pop(k))
        for key, value in (("stage", "http_driver"), ("allowlisted_error_codes", ["PHP_SERVER_SIGSEGV"]), ("allowlisted_error_classes", ["RuntimeException"]), ("owned_listener_wait_signal", "11"), ("raw_output_retained", True), ("private_log", "forbidden")):
            self.refused(lambda reports, k=key, v=value: reports[2]["fixture_diagnostic"].__setitem__(k, v))
        for key, value in (("capture_status", "collected"), ("kernel_core_pattern_restored", False), ("raw_core_or_debugger_output_retained", True), ("synthetic_validation_is_product_reproduction", True)):
            self.refused(lambda reports, k=key, v=value: reports[2]["native_crash_diagnostic"].__setitem__(k, v))
        self.refused(lambda reports: reports[2]["native_crash_diagnostic"]["debugger_preflight"]["symbol_validation"].__setitem__("status", "FAIL"))
        self.refused(lambda reports: reports[2]["native_crash_diagnostic"]["synthetic_core_validation"]["frames"][0].__setitem__("source_file", "/private/path.php"))
        for layer in ("physical", "native"):
            for slot in ("home", "siteurl"):
                self.refused(lambda reports, la=layer, sl=slot: reports[2]["fixture_origin_preflight"]["after"][la][sl].__setitem__("exact_expected", False))
                self.refused(lambda reports, la=layer, sl=slot: reports[2]["fixture_origin_preflight"]["after"][la][sl].__setitem__("private_url", "forbidden"))
        for ri in (0, 2):
            self.refused(lambda reports, i=ri: reports[i]["cases"][0]["evidence"].__setitem__("cleanup_restored", False))
            index = next(i for i, entry in enumerate(self.reports[ri]["cases"]) if entry["id"] in VERIFIER.RETAINED_CLEANUP_KEYS)
            key = next(iter(self.reports[ri]["cases"][index]["evidence"]))
            self.refused(lambda reports, i=ri, ci=index, k=key: reports[i]["cases"][ci]["evidence"].pop(k))

    def test_json_duplicate_members_nonfinite_numbers_and_size_bounds_refuse(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "receipt.json"
            for content in ('{"status":"PASS","status":"PASS"}', '{"nested":{"a":1,"a":1}}', '{"value":NaN}', '{"value":Infinity}', ''):
                path.write_text(content)
                with self.subTest(content=content), self.assertRaises((RuntimeError, ValueError)):
                    VERIFIER.load(path)
            path.write_bytes(b" " * (16 * 1024 * 1024 + 1))
            with self.assertRaises(RuntimeError):
                VERIFIER.load(path)

    def test_immutable_source_map_excludes_new_or_changed_working_tree_bytes(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "src").mkdir()
            (root / "src/Bounded.php").write_bytes(b"<?php /* committed */\n")
            (root / "cetech-woocommerce-delivery-engine.php").write_bytes(b"<?php\n")
            (root / "uninstall.php").write_bytes(b"<?php\n")
            (root / ".github/workflows").mkdir(parents=True)
            committed_pins = {key: str(index + 1) * 64 for index, key in enumerate(VERIFIER.RUNTIME_PINS)}
            (root / ".github/workflows/ci.yml").write_text("\n".join(name + ": '" + committed_pins[field] + "'" for field, name in VERIFIER.RUNTIME_PINS.items()) + "\n")
            def git(*arguments):
                return subprocess.check_output(["git", *arguments], cwd=root, stderr=subprocess.DEVNULL)
            git("init", "-q")
            git("add", ".")
            git("-c", "user.name=Protocol Test", "-c", "user.email=protocol@example.invalid", "commit", "-qm", "Synthetic immutable source authority")
            head = git("rev-parse", "HEAD").decode().strip()
            (root / "src/Bounded.php").write_bytes(b"<?php /* uncommitted */\n")
            (root / "src/Extra.php").write_bytes(b"<?php\n")
            (root / ".github/workflows/ci.yml").write_text("runtime pins changed after commit\n")
            with patch.object(VERIFIER, "ROOT", root):
                mapping = VERIFIER.immutable_sources(head)
                pins = VERIFIER.immutable_runtime_pins(head)
            self.assertEqual(mapping["src/Bounded.php"], hashlib.sha256(b"<?php /* committed */\n").hexdigest())
            self.assertNotIn("src/Extra.php", mapping)
            self.assertEqual(pins, committed_pins)

    def test_missing_duplicate_or_malformed_committed_runtime_pin_refuses(self):
        pins = {name: str(index + 1) * 64 for index, name in enumerate(VERIFIER.RUNTIME_PINS.values())}
        lines = [name + ": '" + value + "'" for name, value in pins.items()]
        for source in ("\n".join(lines[1:]), "\n".join(lines + [lines[0]]), "\n".join(lines).replace(next(iter(pins.values())), "not-a-sha256")):
            with self.subTest(source_shape=len(source)), patch.object(VERIFIER, "git", lambda *args: source.encode()), self.assertRaises(RuntimeError):
                VERIFIER.immutable_runtime_pins("a" * 40)

    def test_uncommitted_producer_cannot_redefine_an_old_candidate_inventory(self):
        def git(*arguments):
            if arguments == ("rev-parse", "HEAD"):
                return b"a" * 40
            if arguments[:1] == ("merge-base",):
                return b""
            if arguments[:1] == ("show",):
                return b"uncommitted producer differs"
            raise AssertionError("Unexpected Git observation")
        with patch.object(VERIFIER, "git", git), self.assertRaises(RuntimeError):
            VERIFIER.authority()


if __name__ == "__main__":
    unittest.main()
