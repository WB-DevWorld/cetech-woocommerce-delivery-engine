#!/usr/bin/env python3
"""Controlled OS child probes of the exact proposed transport source; no WP or DB."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).parent
PHP = os.environ.get("P05_DIAGNOSTIC_TEST_PHP", "php")
SOURCE = Path(os.environ.get("P05_DIAGNOSTIC_SOURCE", ROOT / "opening-promise-native-configuration-shipment.php"))
PREFIX = "p05_native_payment_transport="
PRIVATE = "PRIVATE-CAPTURE-SENTINEL-do-not-log"
FIELDS = {"format", "stop_reason", "elapsed_ms", "running_at_stop", "timed_out", "stdout_bytes", "stderr_bytes", "stdout_overflow", "stderr_overflow", "exit_code", "exit_ok", "json_valid", "json_array", "pid_matches"}

CHILD = r'''<?php
$case=$argv[1]??'';$result=['status'=>'PASS','process_id'=>getmypid(),'private_field'=>'PRIVATE-CAPTURE-SENTINEL-do-not-log'];$json=json_encode($result);
if('timeout'===$case){sleep(14);exit(0);}
if('stdout_plus_one'===$case){echo $json.str_repeat(' ',4097-strlen($json));usleep(250000);exit(0);}
if('stderr_plus_one'===$case){fwrite(STDERR,str_repeat('PRIVATE-CAPTURE-SENTINEL-do-not-log',600));echo $json;usleep(250000);exit(0);}
if('exit_nonzero'===$case){echo $json;exit(7);}
if('json_invalid'===$case){echo '{PRIVATE-CAPTURE-SENTINEL-do-not-log';exit(0);}
if('json_scalar'===$case){echo json_encode('PRIVATE-CAPTURE-SENTINEL-do-not-log');exit(0);}
if('pid_mismatch'===$case){$result['process_id']=getmypid()+1;echo json_encode($result);exit(0);}
if('pid_private'===$case){$result['process_id']='PRIVATE-CAPTURE-SENTINEL-do-not-log';echo json_encode($result);exit(0);}
if('exact_bytes'===$case){echo $json.str_repeat(' ',4096-strlen($json));fwrite(STDERR,substr(str_repeat('PRIVATE-CAPTURE-SENTINEL-do-not-log',600),0,16384));exit(0);}
echo $json;
'''


class NativePaymentTransportDiagnosticTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        source = SOURCE.read_text()
        start = source.index("   $transport_started=hrtime(true);$process=proc_open(")
        end = source.index("   if('PASS'!==($result['status']??null))", start)
        cls.segment = source[start:end]
        assert "$deadline=microtime(true)+12;" in cls.segment
        assert "strlen($out)>4096||strlen($errors)>16384" in cls.segment
        assert "0!==($exit>=0?$exit:$closed)||!is_array($result)||($result['process_id']??null)!==$child_pid" in cls.segment
        cls.temp = tempfile.TemporaryDirectory(prefix="p05-transport-closed-")
        cls.folder = Path(cls.temp.name)
        (cls.folder / "opening-promise-native-configuration-shipment-payment.php").write_text(CHILD)
        harness = "<?php\ndeclare(strict_types=1);\n$path=$argv[1];$process=null;$pipes=[];\ntry{\n" + cls.segment + "\necho json_encode(['transport_accepted'=>true]);\n}catch(RuntimeException $error){echo json_encode(['transport_accepted'=>false,'bounded_refusal'=>$error->getMessage()==='P05 bounded native payment transport unavailable.']);}\n"
        (cls.folder / "parent.php").write_text(harness)

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def probe(self, case):
        completed = subprocess.run([PHP, str(self.folder / "parent.php"), case], capture_output=True, timeout=18)
        self.assertEqual(0, completed.returncode)
        combined = (completed.stdout + completed.stderr).decode()
        self.assertNotIn(PRIVATE, combined)
        output = json.loads(completed.stdout)
        stderr = completed.stderr.decode()
        if output["transport_accepted"]:
            self.assertEqual("", stderr)
            return output, None
        self.assertTrue(output["bounded_refusal"])
        self.assertTrue(stderr.startswith(PREFIX))
        self.assertEqual(1, len(stderr.splitlines()))
        record = json.loads(stderr[len(PREFIX):])
        self.assertEqual(FIELDS, set(record))
        self.assertEqual("p05-native-payment-transport-v1", record["format"])
        self.assertIn(record["stop_reason"], {"completed", "deadline", "stdout_overflow", "stderr_overflow"})
        for name in ["running_at_stop", "timed_out", "stdout_overflow", "stderr_overflow", "exit_ok", "json_valid", "json_array", "pid_matches"]:
            self.assertIs(type(record[name]), bool)
        for name in ["elapsed_ms", "stdout_bytes", "stderr_bytes", "exit_code"]:
            self.assertIs(type(record[name]), int)
        self.assertGreaterEqual(record["elapsed_ms"], 0)
        self.assertLess(record["elapsed_ms"], 17000)
        self.assertGreaterEqual(record["stdout_bytes"], 0)
        self.assertGreaterEqual(record["stderr_bytes"], 0)
        self.assertNotIn("process_id", record)
        self.assertNotIn("child_pid", record)
        return output, record

    def test_positive_and_exact_byte_bounds_keep_transport_acceptance_and_no_diagnostic(self):
        for case in ["valid", "exact_bytes"]:
            with self.subTest(case=case):
                self.assertTrue(self.probe(case)[0]["transport_accepted"])

    def test_original_hard_timeout_is_classified_without_raw_output(self):
        _, record = self.probe("timeout")
        self.assertEqual("deadline", record["stop_reason"])
        self.assertTrue(record["running_at_stop"])
        self.assertTrue(record["timed_out"])
        self.assertFalse(record["stdout_overflow"])
        self.assertFalse(record["stderr_overflow"])
        self.assertGreaterEqual(record["elapsed_ms"], 12000)
        self.assertFalse(record["exit_ok"])

    def test_stdout_overflow_is_distinct_from_timeout_and_keeps_json_pid_truth(self):
        _, record = self.probe("stdout_plus_one")
        self.assertEqual("stdout_overflow", record["stop_reason"])
        self.assertTrue(record["stdout_overflow"])
        self.assertFalse(record["timed_out"])
        self.assertEqual(4097, record["stdout_bytes"])
        self.assertTrue(record["json_valid"])
        self.assertTrue(record["json_array"])
        self.assertTrue(record["pid_matches"])

    def test_stderr_overflow_is_distinct_without_printing_private_stderr(self):
        _, record = self.probe("stderr_plus_one")
        self.assertEqual("stderr_overflow", record["stop_reason"])
        self.assertTrue(record["stderr_overflow"])
        self.assertFalse(record["timed_out"])

    def test_nonzero_exit_is_distinct_from_valid_result_and_matching_pid(self):
        _, record = self.probe("exit_nonzero")
        self.assertEqual("completed", record["stop_reason"])
        self.assertEqual(7, record["exit_code"])
        self.assertFalse(record["exit_ok"])
        self.assertTrue(record["json_valid"])
        self.assertTrue(record["json_array"])
        self.assertTrue(record["pid_matches"])

    def test_invalid_json_and_valid_scalar_are_distinct(self):
        _, bad = self.probe("json_invalid")
        _, scalar = self.probe("json_scalar")
        self.assertFalse(bad["json_valid"])
        self.assertFalse(bad["json_array"])
        self.assertTrue(scalar["json_valid"])
        self.assertFalse(scalar["json_array"])
        self.assertTrue(bad["exit_ok"])
        self.assertTrue(scalar["exit_ok"])

    def test_numeric_or_private_pid_substitution_is_boolean_only(self):
        for case in ["pid_mismatch", "pid_private"]:
            with self.subTest(case=case):
                _, record = self.probe(case)
                self.assertTrue(record["json_array"])
                self.assertTrue(record["exit_ok"])
                self.assertFalse(record["pid_matches"])


if __name__ == "__main__":
    unittest.main()
