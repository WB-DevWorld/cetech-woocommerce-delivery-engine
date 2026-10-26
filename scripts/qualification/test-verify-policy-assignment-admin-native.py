#!/usr/bin/env python3
"""Adversarial offline protocol checks; no WordPress or physical database boot."""
import copy
import contextlib
import io
import html
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
spec = importlib.util.spec_from_file_location("r23_native_verifier", SOURCE / "verify-policy-assignment-admin-native.py")
verifier = importlib.util.module_from_spec(spec)
spec.loader.exec_module(verifier)


class PolicyAssignmentNativeProtocolTest(unittest.TestCase):
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
        self.expected_protocol = verifier.protocol()
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
        with patch.object(verifier, "prior", return_value=(self.native, "e" * 64)), patch.object(verifier, "producers", return_value=self.pins), patch.object(verifier, "protocol", return_value=self.expected_protocol):
            return verifier.verify(self.options)

    def reject(self, mutate):
        report = copy.deepcopy(self.report)
        mutate(report)
        with self.assertRaises(ValueError):
            self.verify(report)

    def test_authored_closed_complete_protocol_is_accepted(self):
        self.assertEqual(0, self.verify())
        self.assertEqual(20, len(verifier.protocol()))

    def test_native_route_and_action_are_the_actual_independent_assignment_page(self):
        page = (verifier.ROOT / "src/Presentation/Admin/PolicyAssignmentPage.php").read_text()
        retained = (verifier.ROOT / "src/Presentation/Admin/ReturnPolicyPage.php").read_text()
        for name, value in (("SLUG", verifier.SLUG), ("ACTION_PREFIX", verifier.ACTION)):
            declared = verifier.re.search(r"public const " + name + r" = '([^']+)';", page)
            retained_declared = verifier.re.search(r"public const " + name + r" = '([^']+)';", retained)
            self.assertIsNotNone(declared)
            self.assertIsNotNone(retained_declared)
            self.assertEqual(value, declared.group(1))
            self.assertNotEqual(retained_declared.group(1), value)
        self.assertEqual("cetech-de-policy-assignments", verifier.SLUG)
        self.assertEqual("cetech_de_policy_assignment_", verifier.ACTION)

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
            for field in case["evidence"]:
                for value in (False, 1, "true", None, [], {}):
                    with self.subTest(case=case["id"], field=field, value=value):
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
        native["installed_php_sources"] = dict(sorted({**{path: "c" * 64 for path in verifier.ASSIGNMENT_SOURCES}, **{"src/Synthetic%04d.php" % index: "d" * 64 for index in range(1004-len(verifier.ASSIGNMENT_SOURCES))}}.items()))
        native["installed_php_sources_hash"] = verifier.hashlib.sha256(verifier.canonical(native["installed_php_sources"])).hexdigest()
        path.write_text(json.dumps(native))
        with patch.dict(verifier.os.environ, {}, clear=True):
            self.assertEqual(native, verifier.prior(self.root)[0])
            native["installed_php_sources_hash"] = "f" * 64
            path.write_text(json.dumps(native))
            with self.assertRaises(ValueError):
                verifier.prior(self.root)

    def test_prior_rejects_missing_assignment_source_even_with_valid_census_and_digest(self):
        native = copy.deepcopy(self.native)
        native.update(status="PASS", cases=[{"id": "synthetic-prior-" + str(index), "status": "PASS"} for index in range(495)])
        sources = {path: "c" * 64 for path in verifier.ASSIGNMENT_SOURCES}
        sources.update({"src/Synthetic%04d.php" % index: "d" * 64 for index in range(1004-len(sources))})
        sources.pop(verifier.ASSIGNMENT_SOURCES[0])
        sources["src/Substituted.php"] = "e" * 64
        native["installed_php_sources"] = dict(sorted(sources.items()))
        native["installed_php_sources_hash"] = verifier.hashlib.sha256(verifier.canonical(native["installed_php_sources"])).hexdigest()
        (self.root / "opening-qualification-results.json").write_text(json.dumps(native))
        with patch.dict(verifier.os.environ, {}, clear=True), self.assertRaises(ValueError):
            verifier.prior(self.root)

    def test_independent_family_and_native_preservation_observations_are_mandatory(self):
        cross = next(index for index, case in enumerate(self.report["cases"]) if case["id"] == "R23-FORGED-AUTHORITY-AND-CROSS-FAMILY-REFUSED")
        kept = next(index for index, case in enumerate(self.report["cases"]) if case["id"] == "R23-SAVED-CURRENT-PREVIEW-AND-DELIVERY-PRESERVATION")
        self.reject(lambda report: report["cases"][cross]["evidence"].__setitem__("refund_logical_cannot_be_return_reference", False))
        self.reject(lambda report: report["cases"][kept]["evidence"].__setitem__("all_original_nine_delivery_fields_preserved", False))

    def test_symlink_and_nonprivate_credential_files_are_rejected(self):
        self.path.write_text("{}")
        link = self.root / "linked.json"
        link.symlink_to(self.path)
        with self.assertRaises(ValueError):
            verifier.load(link)
        self.path.chmod(0o644)
        with self.assertRaises(ValueError):
            verifier.load(self.path, private=True)

    def test_actual_html_parser_requires_all_saved_public_wording_and_literal_markup(self):
        common = verifier.module("r23_protocol_actual_html", "opening-http-driver.py")
        selections = {family: {"disposition": "assigned", "fields": {"name": "Private internal name", "customer_title": family + " café", "summary": "First line.\nSecond café line.", "full_text": "Supplied <em>literal</em> full text.", "exceptions": "Supplied exception."}} for family in verifier.FAMILIES}
        def response(omit=None, raw_markup=False):
            sections = []
            for family, selected in selections.items():
                fields = selected["fields"]
                label = "Return Policy" if family == "return_policy_v1" else "Refund Policy"
                sections.append("<article><h3>" + label + "</h3>" + "".join("<p>" + (value if raw_markup and key == "full_text" else html.escape(value)) + "</p>" for key, value in fields.items() if key != "name" and key != omit) + "</article>")
            body = ('<div id="cetech-policy-assignment-preview">' + "".join(sections) + '</div>').encode()
            return common.Response(200, body, {}, "http://127.0.0.1:8085/")
        self.assertTrue(verifier.preview_wording_visible(response(), {"selections": selections}))
        for omitted in ("customer_title", "summary", "full_text", "exceptions"):
            with self.subTest(omitted=omitted):
                self.assertFalse(verifier.preview_wording_visible(response(omitted), {"selections": selections}))
        self.assertFalse(verifier.preview_wording_visible(response(raw_markup=True), {"selections": selections}))
        incomplete = {"selections": {verifier.FAMILIES[0]: selections[verifier.FAMILIES[0]]}}
        self.assertFalse(verifier.preview_wording_visible(response(), incomplete))
        original = response()
        decoded = original.body.decode()
        outside = common.Response(200, ('<div id="cetech-policy-assignment-preview"></div>' + decoded.replace('id="cetech-policy-assignment-preview"', '')).encode(), {}, original.url)
        fake = common.Response(200, decoded.replace('<div id="cetech-policy-assignment-preview">', '<!-- id="cetech-policy-assignment-preview" --><div>').encode(), {}, original.url)
        duplicate = common.Response(200, original.body + original.body, {}, original.url)
        swapped = common.Response(200, decoded.replace('<h3>Return Policy</h3>', '<h3>SWAP</h3>').replace('<h3>Refund Policy</h3>', '<h3>Return Policy</h3>').replace('<h3>SWAP</h3>', '<h3>Refund Policy</h3>').encode(), {}, original.url)
        for corrupted in (outside, fake, duplicate, swapped):
            self.assertFalse(verifier.preview_wording_visible(corrupted, {"selections": selections}))


    def test_no_javascript_post_preserves_actual_prior_selected_uuid_controls(self):
        common = verifier.module("r23_protocol_nojs_html", "opening-http-driver.py")
        return_uuid, refund_uuid = "00000000-0000-4000-8000-000000000001", "00000000-0000-4000-8000-000000000002"
        body = ('<form method="post"><input name="cetech_de_action" value="' + verifier.ACTION + 'save"><input name="cetech_de_nonce" value="real-native-nonce"><input name="ticket" value="opaque-review"><select name="return_mode"><option value="assigned" selected>Assigned</option></select><select name="return_policy_uuid"><option value="' + return_uuid + '" selected>Return</option></select><select name="refund_mode"><option value="assigned" selected>Assigned</option></select><select name="refund_policy_uuid"><option value="' + refund_uuid + '" selected>Refund</option></select><input name="reason" value=""></form>').encode()
        document = common.Response(200, body, {}, "http://127.0.0.1:8085/")
        for return_mode, refund_mode in (("disabled", None), ("inherit", "inherit")):
            with self.subTest(return_mode=return_mode, refund_mode=refund_mode):
                posted = verifier.no_javascript_modes(document, return_mode, refund_mode)
                self.assertEqual(return_uuid, posted["return_policy_uuid"])
                self.assertEqual(refund_uuid, posted["refund_policy_uuid"])
                self.assertEqual(return_mode, posted["return_mode"])
                self.assertEqual("assigned" if refund_mode is None else refund_mode, posted["refund_mode"])
                self.assertEqual("real-native-nonce", posted["cetech_de_nonce"])
                self.assertEqual("opaque-review", posted["ticket"])
        with self.assertRaises(ValueError):
            verifier.no_javascript_modes(document, "unknown", None)

    def test_setup_diagnostic_emits_only_closed_step_without_private_log_body(self):
        path = self.root / "prepare.log"
        path.write_text("private password or exception body\npolicy_assignment_admin_fixture_step=seed_refund_a\nsecret\npolicy_assignment_admin_fixture_step=forged-private-value\n")
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            self.assertEqual(0, verifier.setup_diagnostic(path))
        self.assertEqual("policy_assignment_admin_last_entered_fixture_step=seed_refund_a\n", output.getvalue())

    def test_setup_diagnostic_refuses_links_oversized_and_absent_logs(self):
        source = self.root / "real.log"
        source.write_text("policy_assignment_admin_fixture_step=ready\n")
        linked = self.root / "linked.log"
        linked.symlink_to(source)
        oversized = self.root / "big.log"
        oversized.write_text("x" * 262145)
        for path in (linked, oversized, self.root / "absent.log"):
            with self.subTest(path=path.name):
                output = io.StringIO()
                with contextlib.redirect_stdout(output):
                    verifier.setup_diagnostic(path)
                self.assertEqual("policy_assignment_admin_last_entered_fixture_step=unavailable\n", output.getvalue())

    def test_actual_php_inspect_bridge_preserves_empty_and_populated_scope_maps(self):
        # Execute the original helper's inspect branch and guarded JSON writer.
        # Only native functions/physical reads are owned in-memory stubs; the
        # actual assignment Map/State codecs preserve nested offers objects.
        code = r'''
spl_autoload_register(static function(string $class):void {
    $prefix='CetechDeliveryEngine\\';
    if(str_starts_with($class,$prefix))require $GLOBALS['source_root'].'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
});
$GLOBALS['source_root']=$argv[2];$work=$argv[3];$populated='1'===$argv[4];
define('WP_CLI',true);define('WP_ADMIN',true);define('DISABLE_WP_CRON',true);define('ARRAY_A','ARRAY_A');
define('ABSPATH',$work.'/site/');define('DB_HOST','127.0.0.1');define('DB_NAME','cetech_wp_opening_qualification_r23shape');
class WC_Product {}
function add_filter(...$arguments):void {}
function get_option(string $key):mixed { return match($key){'cetech_opening_qualification_disposable'=>'1','home','siteurl'=>'http://127.0.0.1:8085','cetech_de_db_version'=>'12',default=>null}; }
$store=new CetechDeliveryEngine\Infrastructure\Persistence\PolicyAssignmentStore(1,'wp_');
$coordinate=CetechDeliveryEngine\Domain\PolicyAssignment\PolicyAssignmentState::make_coordinate(1,'global',0);
$name=$store->name($coordinate);$rows=[];
if($populated){
    $return=CetechDeliveryEngine\Domain\PolicyAssignment\PolicyAssignmentMap::empty(1,'return_policy_v1')->with_instruction(0,['mode'=>'assigned','policy_uuid'=>'00000000-0000-4000-8000-000000000001']);
    $refund=CetechDeliveryEngine\Domain\PolicyAssignment\PolicyAssignmentMap::empty(1,'refund_policy_v1')->with_instruction(7,['mode'=>'assigned','policy_uuid'=>'00000000-0000-4000-8000-000000000002']);
    $bytes=CetechDeliveryEngine\Domain\PolicyAssignment\PolicyAssignmentState::record_json($coordinate,'00000000-0000-4000-8000-000000000003',3,$return,$refund,5,'Owned shape regression','2026-10-25 00:00:00.000000');
    $rows=[['option_id'=>'11','option_name'=>$name,'option_value'=>$bytes,'autoload'=>'off']];
}
$wpdb=new class($rows) {
    public string $prefix='wp_';public string $options='wp_options';public string $last_error='';
    public function __construct(private array $rows){}
    public function prepare(string $sql,mixed ...$values):string{return $sql.' -- '.json_encode($values,JSON_THROW_ON_ERROR);}
    public function esc_like(string $value):string{return $value;}
    public function get_var(string $sql):mixed{return null;}
    public function get_results(string $sql,mixed $format):array{return str_contains($sql,'WHERE option_name LIKE')&&str_contains($sql,CetechDeliveryEngine\Infrastructure\Persistence\PolicyAssignmentStore::PREFIX)?$this->rows:[];}
};
foreach(['CETECH_DE_NATIVE_OPENING_QUALIFICATION'=>'1','CETECH_DE_HTTP_OPENING_QUALIFICATION'=>'1','CETECH_DE_WP_DB_HOST'=>'127.0.0.1','CETECH_DE_HTTP_FIXTURE_SITE'=>ABSPATH,'CETECH_DE_HTTP_WORK_PATH'=>$work,'CETECH_DE_HTTP_PRIVATE_DIR'=>$work.'/private']as$key=>$value)putenv($key.'='.$value);
$state_path=$work.'/private/state.json';$output_path=$work.'/private/inspect.json';
$state=['site_path'=>realpath(ABSPATH),'database_name'=>DB_NAME,'site_id'=>1,'prefix'=>'wp_','products'=>[],'offers'=>[],'actors'=>[],'option_names'=>['global'=>$name],'journal_names'=>[],'targets'=>[],'protected'=>['adoption'=>[]],'configuration'=>[]];
file_put_contents($state_path,json_encode($state,JSON_THROW_ON_ERROR));chmod($state_path,0600);
$args=['inspect',$state_path,$output_path];require $argv[1];
$internal=cetech_r23_inspect($state);
echo json_encode(['inspection'=>json_decode(file_get_contents($output_path),false,512,JSON_THROW_ON_ERROR),'internal_scope_states_are_array'=>is_array($internal['states']),'internal_empty_options_are_array'=>$populated||[]===cetech_r23_options($state)],JSON_THROW_ON_ERROR);
'''
        for populated in (False, True):
            with self.subTest(populated=populated):
                work = self.root / ("populated" if populated else "empty")
                (work / "site").mkdir(parents=True)
                (work / "private").mkdir()
                result = subprocess.run(["php", "-r", code, str(SOURCE / "policy-assignment-admin-native.php"), str(verifier.ROOT), str(work), "1" if populated else "0"], capture_output=True, text=True, check=False, timeout=10)
                self.assertEqual(0, result.returncode, result.stderr)
                observed = json.loads(result.stdout)
                self.assertTrue(observed["internal_scope_states_are_array"])
                self.assertTrue(observed["internal_empty_options_are_array"])
                inspection = observed["inspection"]
                self.assertIsInstance(inspection["states"], dict)
                self.assertEqual(0, inspection["operation_count"])
                self.assertEqual("12", inspection["schema"])
                self.assertIs(inspection["adoption_off"], True)
                if not populated:
                    self.assertEqual({}, inspection["states"])
                else:
                    self.assertEqual({"global"}, set(inspection["states"]))
                    actual = inspection["states"]["global"]
                    self.assertEqual((11, 3), (actual["row_id"], actual["revision"]))
                    self.assertEqual({"mode": "assigned", "policy_uuid": "00000000-0000-4000-8000-000000000001"}, actual["maps"]["return_policy_v1"]["default"])
                    self.assertEqual({}, actual["maps"]["return_policy_v1"]["offers"])
                    self.assertEqual({"7": {"mode": "assigned", "policy_uuid": "00000000-0000-4000-8000-000000000002"}}, actual["maps"]["refund_policy_v1"]["offers"])

    def test_actual_copied_mu_is_inert_during_cli_bridges(self):
        copied = self.root / "cetech-policy-assignment-admin-fixture.php"
        copied.write_bytes((SOURCE / "policy-assignment-admin-native.php").read_bytes())
        code = "define('WP_CLI',true);function add_filter(...$args){if($args[0]!=='action_scheduler_allow_async_request_runner')throw new RuntimeException();}require $argv[1];if(function_exists('cetech_r23_rows'))throw new RuntimeException('MU registered private CLI helpers');echo 'inert-cli-mu';"
        result = subprocess.run(["php", "-r", code, str(copied)], capture_output=True, text=True, check=False, timeout=10, env={**verifier.os.environ, "CETECH_DE_NATIVE_OPENING_QUALIFICATION": "1", "CETECH_DE_HTTP_OPENING_QUALIFICATION": "1"})
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("inert-cli-mu", result.stdout)

    def test_actual_copied_mu_then_original_cli_include_does_not_redeclare(self):
        copied = self.root / "cetech-policy-assignment-admin-fixture.php"
        copied.write_bytes((SOURCE / "policy-assignment-admin-native.php").read_bytes())
        # No WordPress boot: after the actual MU returns, original execution must
        # reach its explicit CLI argument guard, rather than a compilation fatal.
        code = "define('WP_CLI',true);function add_filter(...$args){}require $argv[1];if(function_exists('cetech_r23_rows'))throw new RuntimeException('MU helper leaked');try{require $argv[2];}catch(RuntimeException $error){if($error->getMessage()!=='R23 expects MODE PRIVATE_STATE OUTPUT.')throw $error;echo 'dual-include-guarded';}"
        result = subprocess.run(["php", "-r", code, str(copied), str(SOURCE / "policy-assignment-admin-native.php")], capture_output=True, text=True, check=False, timeout=10, env={**verifier.os.environ, "CETECH_DE_NATIVE_OPENING_QUALIFICATION": "1", "CETECH_DE_HTTP_OPENING_QUALIFICATION": "1"})
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
