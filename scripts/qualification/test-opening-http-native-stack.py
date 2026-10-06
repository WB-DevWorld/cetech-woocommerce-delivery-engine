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


class NativeStackOutputTest(unittest.TestCase):
    def capture(self, frame, module=None, raises=False):
        class DebuggerError(Exception):
            pass

        def newest():
            if raises:
                raise DebuggerError("password=private; SQL and cookies must never be emitted")
            return frame

        debugger = SimpleNamespace(error=DebuggerError, newest_frame=newest, solib_name=lambda _: module)
        with tempfile.TemporaryDirectory() as private:
            output = Path(private, "stack.json")
            with patch.dict(sys.modules, {"gdb": debugger}), patch.dict(os.environ, {"CETECH_DE_HTTP_STACK_OUTPUT": str(output)}):
                runpy.run_path(str(Path(__file__).with_name("opening-http-native-stack.py")))
            return json.loads(output.read_text()), output.read_text()

    def test_arguments_and_private_directories_are_removed(self):
        report, raw = self.capture(Frame("zend_execute(password=private-cookie)"), "/private/password=secret/opcache.so")
        self.assertEqual(report["frames"], [{"depth": 0, "symbol": "zend_execute", "module": "opcache.so"}])
        self.assertNotIn("private", raw)
        self.assertNotIn("cookie", raw)
        self.assertNotIn("123456", raw)

    def test_arbitrary_debugger_text_is_rejected(self):
        report, raw = self.capture(Frame("password=secret SQL"), "/tmp/private-cookie=secret")
        self.assertEqual(report["frames"], [{"depth": 0, "symbol": None, "module": None}])
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


if __name__ == "__main__":
    unittest.main()
