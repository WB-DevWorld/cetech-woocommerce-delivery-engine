"""Exercise early failure, owned-child cleanup and receipt privacy without PHP."""
import importlib.util
import copy
import hashlib
import json
import os
import subprocess
import tempfile
import textwrap
import unittest
from unittest.mock import patch
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("store_receipt", Path(__file__).with_name("store-smoke-receipt.py"))
writer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(writer)


class ReceiptBoundaryTest(unittest.TestCase):
    def test_stack_and_log_payloads_are_not_published(self):
        with tempfile.TemporaryDirectory() as temp:
            private = Path(temp)
            (private / "php-server.log").write_text("Uncaught TypeError: SECRET_SQL_COOKIE\n")
            (private / "stack.json").write_text(json.dumps({
                "format": "cetech-opening-native-stack-v2", "status": "symbols_captured",
                "frames": [{"symbol": "execute_ex", "module": "php8.5", "source_file": "/private/SECRET.c",
                            "source_line": 42, "mapping_known": True, "args": "SECRET_ARGS", "pc": "SECRET_ADDRESS"},
                           {"symbol": "bad symbol SECRET", "module": "/secret/lib.so", "source_line": True}],
                "symbol_validation": {"status": "PASS", "exact_executable_loaded": True,
                                      "executable_build_id": "38d664ac197cc3a2", "executable_module": "php8.5",
                                      "private_path": "SECRET_PATH"}, "memory": "SECRET_MEMORY"}))
            output = private / "receipt.json"
            writer.receipt([str(private), str(output), "store_api_cart", "52", "exited", "139", "11", "0", "1", "1", "collected", "a" * 40, "b" * 40, "c" * 40])
            self.assertEqual(writer.finalize(str(output), "1"), 1)
            text = output.read_text()
            self.assertNotIn("SECRET", text)
            report = json.loads(text)
            self.assertEqual(report["command_exit"], 52)
            self.assertEqual(report["allowlisted_error_codes"], ["CURL_EMPTY_REPLY", "PHP_SERVER_SIGSEGV"])
            self.assertEqual(report["allowlisted_error_classes"], ["TypeError"])
            self.assertEqual(report["native_crash_diagnostic"]["stack"]["frames"][0]["symbol"], "execute_ex")
            self.assertIsNone(report["native_crash_diagnostic"]["stack"]["frames"][0]["source_file"])
            self.assertIsNone(report["native_crash_diagnostic"]["stack"]["frames"][1]["source_line"])
            self.assertNotIn("cases", report)

    def test_empty_reply_is_not_a_sigsegv_and_cleanup_does_not_pass_it(self):
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp) / "receipt.json"
            writer.receipt([temp, str(output), "store_api_cart", "52", "running", "143", "15", "1", "1", "1", "not_requested", "a" * 40, "a" * 40, "b" * 40])
            self.assertEqual(writer.finalize(str(output), "1"), 1)
            report = json.loads(output.read_text())
            self.assertEqual(report["status"], "FAIL")
            self.assertEqual(report["allowlisted_error_codes"], ["CURL_EMPTY_REPLY"])

    def test_cleanup_failure_fails_an_otherwise_complete_smoke(self):
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp) / "receipt.json"
            writer.receipt([temp, str(output), "complete", "0", "running", "143", "15", "1", "1", "0", "not_requested", "a" * 40, "a" * 40, "b" * 40])
            self.assertEqual(writer.finalize(str(output), "1"), 1)
            self.assertEqual(json.loads(output.read_text())["status"], "FAIL")


class RuntimePolicyTest(unittest.TestCase):
    def check_runtime(self, changes=None):
        # Execute the actual readiness guard, without starting WordPress.
        shell = (ROOT / "scripts/ci-opening-http-qualification.sh").read_text()
        start = shell.index('        if os.environ.get("CETECH_DE_HTTP_CRASH_DIAGNOSTIC") == "1":')
        end = shell.index('        os.kill(pid, 0)', start)
        guard = compile(textwrap.dedent(shell[start:end]), "actual-runtime-readiness-guard", "exec")
        runtime = {"sapi": "cli-server", "php_version": "8.5.11", "extensions": {"Core": "8.5.11"},
                   "php_binary_sha256": "c" * 64, "full_ini_sha256": "b" * 64,
                   "full_ini_jit1235_sha256": "a" * 64, "safe_ini": {"opcache.jit": "disable"},
                   "opcache_state_at_existing_probe": {"opcache_enabled": True,
                                                       "jit": {"enabled": False, "on": False}}}
        runtime.update(copy.deepcopy(changes or {}))
        with tempfile.TemporaryDirectory() as temp:
            env = {"CETECH_DE_HTTP_CRASH_DIAGNOSTIC": "1", "CETECH_DE_HTTP_LISTENER_JIT": "disable",
                   "CETECH_DE_HTTP_EXPECT_BINARY_SHA256": "c" * 64,
                   "CETECH_DE_HTTP_EXPECT_INI_SHA256": "b" * 64,
                   "CETECH_DE_HTTP_EXPECT_ORIGINAL_INI_SHA256": "a" * 64,
                   "CETECH_DE_HTTP_EXPECT_EXTENSIONS_SHA256": hashlib.sha256(b'{"Core":"8.5.11"}').hexdigest(),
                   "CETECH_DE_HTTP_PRIVATE_DIR": temp}
            with patch.dict(os.environ, env):
                exec(guard, {"os": os, "json": json, "hashlib": hashlib, "Path": Path, "runtime": runtime})
            return json.loads((Path(temp) / "runtime.json").read_text())

    def test_actual_and_original_ini_fingerprints_are_both_required(self):
        report = self.check_runtime()
        self.assertTrue(all(report["expected_qualification_runtime"].values()))
        self.assertFalse(report["qualification_runtime_policy"]["qualifies_jit1235_runtime"])
        self.assertNotIn("comparison_to_56855ba", report)
        for field in ("full_ini_sha256", "full_ini_jit1235_sha256"):
            with self.subTest(field=field), self.assertRaises(SystemExit):
                self.check_runtime({field: "d" * 64})

    def test_actual_opcache_and_jit_state_are_required(self):
        for state in ({"opcache_enabled": False, "jit": {"enabled": False, "on": False}},
                      {"opcache_enabled": True, "jit": {"enabled": True, "on": True}}):
            with self.subTest(state=state), self.assertRaises(SystemExit):
                self.check_runtime({"opcache_state_at_existing_probe": state})


class ListenerLifecycleTest(unittest.TestCase):
    def run_fixture(self, crash, diagnostic=False, interrupt=False, jit_policy="", authorized=True):
        with tempfile.TemporaryDirectory() as temp:
            work = Path(temp)
            binaries = work / "bin"
            binaries.mkdir()
            php = binaries / "php"
            php_code = 'import os,time;from pathlib import Path;Path(os.environ["TEST_WORK"],"listener-ready").write_text(str(os.getpid()));time.sleep(30)'
            if crash:
                php_code = "import os,signal;"
                if diagnostic:
                    php_code += 'from pathlib import Path;Path(os.environ["TEST_WORK"],"store-smoke-private","core."+str(os.getpid())).write_bytes(b"PRIVATE_CORE");'
                php_code += "os.kill(os.getpid(), signal.SIGSEGV)"
            php.write_text("#!/bin/bash\nprintf '%s\\n' \"$@\" > \"$TEST_WORK/listener-args\"\nulimit -c 0\nexec python3 -c '" + php_code + "'\n")
            php.chmod(0o700)
            git = binaries / "git"
            git.write_text("#!/bin/bash\nprintf '%040d\\n' 1\n")
            git.chmod(0o700)
            if diagnostic:
                # Stub the privileged write and debugger; never change kernel
                # core handling or make a real core in this local test.
                sudo = binaries / "sudo"
                sudo.write_text('''#!/usr/bin/env python3
import os,sys
from pathlib import Path
with Path(os.environ["TEST_WORK"], "core-mutations.log").open("a") as f:
    f.write(sys.stdin.read())
''')
                sudo.chmod(0o700)
                cat = binaries / "cat"
                cat.write_text("#!/bin/bash\nprintf 'fixture_core_pattern\\n'\n")
                cat.chmod(0o700)
                gdb = binaries / "gdb"
                gdb.write_text('''#!/usr/bin/env python3
import json,os,signal
from pathlib import Path
preflight = os.environ.get("CETECH_DE_HTTP_DEBUG_MODE") == "preflight"
if not preflight:
    pid = int(Path(os.environ["TEST_WORK"], "owned.pid").read_text())
    try:
        os.kill(pid,0)
    except ProcessLookupError:
        pass
    else:
        raise SystemExit("Owned child was not waited before decoding")
    mutations = Path(os.environ["TEST_WORK"], "core-mutations.log").read_text().splitlines()
    if len(mutations) != 2 or mutations[1] != "fixture_core_pattern":
        raise SystemExit("Kernel setting was not restored before decoding")
    if os.environ.get("TEST_INTERRUPT") == "1":
        os.kill(int(os.environ["TEST_PARENT_PID"]), signal.SIGTERM)
payload = {"format":"cetech-opening-native-stack-v2", "status":"preflight_pass" if preflight else "symbols_captured",
           "symbol_validation":{"status":"PASS"}, "frames":[] if preflight else [{"symbol":"execute_ex", "module":"php8.5", "args":"PRIVATE_ARGS"}]}
Path(os.environ["CETECH_DE_HTTP_STACK_OUTPUT"]).write_text(json.dumps(payload))
print("PRIVATE_DEBUGGER_OUTPUT")
''')
                gdb.chmod(0o700)
            (work / "store-cart.json").write_text("PRIVATE_RESPONSE")
            script = '''set -euo pipefail
ROOT="$TEST_ROOT"
WORK="$TEST_WORK"
CLEAN="$WORK/clean"
DB_HOST=127.0.0.1
STORE_JSON="$WORK/store-cart.json"
export TEST_PARENT_PID="$BASHPID"
source "$ROOT/scripts/qualification/store-smoke-diagnostic.sh"
start_store_smoke_listener
printf '%s' "$STORE_SMOKE_PID" > "$WORK/owned.pid"
'''
            if crash:
                # On Linux a signaled child can still be present while its
                # kernel teardown finishes. Observe exit instead of sampling
                # a fixed delay and racing the trap's intentional SIGTERM.
                script += '''for attempt in {1..500}; do
    if ! kill -0 "$STORE_SMOKE_PID" 2>/dev/null; then break; fi
    sleep 0.01
done
if kill -0 "$STORE_SMOKE_PID" 2>/dev/null; then exit 1; fi
STORE_SMOKE_STAGE=store_api_cart
exit 52
'''
            else:
                script += '''for attempt in {1..500}; do
    if [[ -f "$WORK/listener-ready" ]]; then break; fi
    sleep 0.01
done
if [[ ! -f "$WORK/listener-ready" ]]; then exit 1; fi
STORE_SMOKE_STAGE=complete
finish_store_smoke_listener 0
'''
            if diagnostic:
                script = 'ulimit() { printf "%s\\n" "$BASHPID" >> "$TEST_WORK/core-limit-processes.log"; }\n' + script
            env = {**os.environ, "PATH": str(binaries) + os.pathsep + os.environ["PATH"],
                   "TEST_ROOT": str(ROOT), "TEST_WORK": str(work), "CETECH_DE_HTTP_CRASH_DIAGNOSTIC": "1" if diagnostic else "0",
                   "TEST_INTERRUPT": "1" if interrupt else "0",
                   "GITHUB_ACTIONS": "true" if authorized else "false",
                   "CETECH_DE_NATIVE_OPENING_QUALIFICATION": "1",
                   "CETECH_DE_HTTP_OPENING_QUALIFICATION": "1",
                   "CETECH_DE_HTTP_LISTENER_JIT": jit_policy}
            run = subprocess.run(["bash", "-c", script], env=env, capture_output=True, text=True, timeout=10)
            report = json.loads((work / "store-smoke-diagnostic.json").read_text())
            if jit_policy and (not authorized or jit_policy != "disable"):
                self.assertNotEqual(run.returncode, 0)
                self.assertEqual(report["status"], "FAIL")
                self.assertFalse((work / "listener-args").exists())
                self.assertFalse((work / "store-smoke-private").exists())
                return run, report
            self.assertTrue((work / "owned.pid").exists(), "Listener did not start: " + run.stderr + run.stdout + json.dumps(report))
            expected_args = ["-S", "127.0.0.1:8085", "-t", str(work / "clean")]
            if jit_policy == "disable":
                expected_args = ["-d", "opcache.jit=disable"] + expected_args
            self.assertEqual((work / "listener-args").read_text().splitlines(), expected_args)
            pid = int((work / "owned.pid").read_text())
            with self.assertRaises(ProcessLookupError):
                os.kill(pid, 0)
            self.assertFalse((work / "store-smoke-private").exists())
            self.assertFalse((work / "store-cart.json").exists())
            if diagnostic:
                mutations = (work / "core-mutations.log").read_text().splitlines()
                self.assertEqual(mutations[0], str(work / "store-smoke-private/core.%p"))
                self.assertEqual(mutations[1], "fixture_core_pattern")
                self.assertEqual(len(mutations), 2)
                self.assertEqual((work / "core-limit-processes.log").read_text().splitlines(), [str(pid)])
                self.assertEqual(report["native_crash_diagnostic"]["capture_status"], "collected" if crash else "no_listener_core")
                self.assertNotIn("PRIVATE", json.dumps(report))
            else:
                self.assertEqual(report["native_crash_diagnostic"]["capture_status"], "not_requested")
            return run, report

    def test_complete_smoke_stops_and_waits_its_listener(self):
        run, report = self.run_fixture(False)
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(report["status"], "PASS")
        self.assertTrue(report["listener"]["cleanup_requested_sigterm"])
        self.assertEqual(report["listener"]["owned_wait_exit"], 143)

    def test_qualification_policy_only_changes_owned_listener_arguments(self):
        run, report = self.run_fixture(False, diagnostic=True, jit_policy="disable")
        self.assertEqual(run.returncode, 0, run.stderr)
        self.assertEqual(report["status"], "PASS")

    def test_qualification_policy_refuses_non_ci_and_unsupported_settings(self):
        for policy, authorized in (("disable", False), ("off", True)):
            with self.subTest(policy=policy, authorized=authorized):
                self.run_fixture(False, jit_policy=policy, authorized=authorized)

    def test_early_failed_request_preserves_exit_and_owned_signal(self):
        run, report = self.run_fixture(True)
        self.assertEqual(run.returncode, 52, run.stderr)
        self.assertEqual(report["status"], "FAIL")
        self.assertEqual(report["listener"]["owned_wait_signal"], 11)
        self.assertFalse(report["listener"]["cleanup_requested_sigterm"])
        self.assertEqual(report["allowlisted_error_codes"], ["CURL_EMPTY_REPLY", "PHP_SERVER_SIGSEGV"])

    def test_owned_core_is_decoded_after_wait_and_kernel_setting_restored(self):
        run, report = self.run_fixture(True, diagnostic=True)
        self.assertEqual(run.returncode, 52, run.stderr)
        self.assertEqual(report["native_crash_diagnostic"]["stack"]["frames"][0]["symbol"], "execute_ex")
        self.assertTrue(report["cleanup"]["kernel_core_pattern_restored"])

    def test_term_during_decoding_preserves_cleanup_and_original_failure(self):
        run, report = self.run_fixture(True, diagnostic=True, interrupt=True)
        self.assertEqual(run.returncode, 52, run.stderr)
        self.assertEqual(report["cleanup"]["interrupted_by_signal"], 143)
        self.assertTrue(report["cleanup"]["kernel_core_pattern_restored"])
        self.assertTrue(report["cleanup"]["private_files_removed"])


if __name__ == "__main__":
    unittest.main()
