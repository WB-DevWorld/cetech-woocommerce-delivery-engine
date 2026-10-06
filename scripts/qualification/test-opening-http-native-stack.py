"""Verify the diagnostic's output boundary without requiring a local debugger."""
import json
import os
from pathlib import Path
import runpy
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch


class Frame:
    def __init__(self, name, older=None):
        self.value, self.previous = name, older

    def name(self):
        return self.value

    def pc(self):
        return 123456

    def older(self):
        return self.previous

    def find_sal(self):
        return SimpleNamespace(symtab=None, line=0)


class NativeStackOutputTest(unittest.TestCase):
    def capture(self, frame, module=None, raises=False, debug_id=None, full_symbol=True, mode=None, mappings="", mapped_object=False):
        class DebuggerError(Exception):
            pass

        def newest():
            if raises:
                raise DebuggerError("password=private; SQL and cookies must never be emitted")
            return frame

        main_id = "abcdefabcdefabcd" * 2
        main = SimpleNamespace(filename="/private/password/php8.5", owner=None, build_id=main_id)
        debug = SimpleNamespace(filename="/private/password/php.debug", owner=main, build_id=debug_id or main_id)
        symtab = SimpleNamespace(objfile=debug, filename="/private/cookie/zend_execute.c") if full_symbol else None
        progspace = SimpleNamespace(objfile_for_address=lambda _: main if mapped_object else None)
        debugger = SimpleNamespace(error=DebuggerError, newest_frame=newest, solib_name=lambda _: module,
            objfiles=lambda: [main, debug], lookup_global_symbol=lambda _: SimpleNamespace(symtab=symtab),
            lookup_type=lambda _: SimpleNamespace(sizeof=80), current_progspace=lambda: progspace,
            execute=lambda *args, **kwargs: mappings)
        with tempfile.TemporaryDirectory() as private:
            output = Path(private, "stack.json")
            environment = {"CETECH_DE_HTTP_STACK_OUTPUT": str(output), "CETECH_DE_HTTP_DEBUG_EXECUTABLE": main.filename,
                           "CETECH_DE_HTTP_DEBUG_MODE": mode or "core"}
            with patch.dict(sys.modules, {"gdb": debugger}), patch.dict(os.environ, environment):
                runpy.run_path(str(Path(__file__).with_name("opening-http-native-stack.py")))
            return json.loads(output.read_text()), output.read_text()

    def test_arguments_and_private_directories_are_removed(self):
        report, raw = self.capture(Frame("zend_execute(password=private-cookie)"), "/private/password=secret/opcache.so")
        self.assertEqual(report["frames"][0]["symbol"], "zend_execute")
        self.assertEqual(report["frames"][0]["module"], "opcache.so")
        self.assertNotIn("private", raw)
        self.assertNotIn("cookie", raw)
        self.assertNotIn("123456", raw)

    def test_arbitrary_debugger_text_is_rejected(self):
        report, raw = self.capture(Frame("password=secret SQL"), "/tmp/private-cookie=secret")
        self.assertIsNone(report["frames"][0]["symbol"])
        self.assertIsNone(report["frames"][0]["module"])
        self.assertEqual(report["status"], "symbols_unavailable")
        self.assertNotIn("secret", raw)

    def test_no_frame_does_not_claim_location(self):
        report, _ = self.capture(None)
        self.assertEqual(report["status"], "symbols_unavailable")
        self.assertEqual(report["frames"], [])

    def test_debugger_error_does_not_publish_exception_message(self):
        report, raw = self.capture(None, raises=True)
        self.assertEqual(report["status"], "debugger_unavailable")
        self.assertNotIn("password", raw)

    def test_trace_is_bounded(self):
        frame = None
        for _ in range(100):
            frame = Frame("execute_ex", frame)
        report, _ = self.capture(frame)
        self.assertEqual(len(report["frames"]), 32)

    def test_oversize_symbol_is_not_published(self):
        report, _ = self.capture(Frame("z" * 200))
        self.assertIsNone(report["frames"][0]["symbol"])

    def test_preflight_requires_matching_separate_debug_build_id(self):
        report, _ = self.capture(None, debug_id="f" * 32, mode="preflight")
        self.assertEqual(report["status"], "preflight_fail")
        self.assertIsNone(report["symbol_validation"]["matching_separate_debug_build_id"])

    def test_dynamic_symbol_without_full_metadata_is_refused(self):
        report, _ = self.capture(None, full_symbol=False, mode="preflight")
        self.assertEqual(report["status"], "preflight_fail")
        self.assertFalse(report["symbol_validation"]["zend_execute_full_symbol"])

    def test_preflight_pass_has_no_runtime_frame_claim(self):
        report, raw = self.capture(None, mode="preflight")
        self.assertEqual(report["status"], "preflight_pass")
        self.assertEqual(report["frames"], [])
        self.assertNotIn("password", raw)

    def test_main_executable_is_mapped_without_solib_name(self):
        report, _ = self.capture(Frame(None), mapped_object=True)
        self.assertEqual(report["frames"][0]["module"], "php8.5")
        self.assertTrue(report["frames"][0]["mapping_known"])
        self.assertEqual(report["status"], "symbols_unavailable")

    def test_core_mapping_fallback_never_publishes_addresses_or_paths(self):
        mappings = "0x10000 0x40000 0x30000 0x0 r-xp /private/password/php8.5\n"
        report, raw = self.capture(Frame(None), mappings=mappings)
        self.assertEqual(report["frames"][0]["module"], "php8.5")
        self.assertNotIn("0x", raw)
        self.assertNotIn("password", raw)


if __name__ == "__main__":
    unittest.main()
