#!/usr/bin/env python3
"""Physical SQL RED/GREEN control, separate from Woo/payment qualification.

All child streams, SQL-bearing failure XML and runtime source paths stay in an
owned temporary directory. Only a closed structural report may leave it.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import selectors
import shutil
import signal
import subprocess
import tarfile
import tempfile
import time
import xml.etree.ElementTree as ET


OLD_HEAD = "8a7a7815b249c7bc117a64ffcc36db1dd8f73ee0"
OLD_TREE = "89c6fb79bf438fa880bbb3dfa55f0150835da2d4"
OLD_MAP = "379c46ac30c5578339555c5a8f6a7c547dba8278d89c30a686a94fdd1309c8c8"
CORRECTION_HEAD = "e4340bfbeb75e8e96444ee240fff873748c36449"
CORRECTION_TREE = "19c237227d9f3fd9d8d16b4c7d136fad23f83b07"
CURRENT_MAP = "d9881e6fc70b374080d8a0b1782a80efb22e11cf1807055b489cce5fd3efc300"
DATABASE_NAME = "cetech_operation_source_read_control"
PRODUCTION_COUNT = 857
TEST_PATH = "tests/Integration/ServicePromise/PromiseQualificationBoundsRealDatabaseTest.php"
TEST_BLOB = "fa2d9f43ae35a57518ba2145ae5b1ed042823935"
LIFETIME_PATH = "tests/Integration/ServicePromise/PromisePrimedSourceLifetimeRealDatabaseTest.php"
LIFETIME_BLOB = "2c68600148eefcf44939f42ba1b72bfa8af6d349"
CLASS_PATHS = {
    "CetechDeliveryEngine\\Infrastructure\\Persistence\\WpdbPromiseHandoffSources": "src/Infrastructure/Persistence/WpdbPromiseHandoffSources.php",
    "CetechDeliveryEngine\\Application\\ServicePromise\\Handoff\\PromiseNativeCaptureService": "src/Application/ServicePromise/Handoff/PromiseNativeCaptureService.php",
    "CetechDeliveryEngine\\Application\\ServicePromise\\Handoff\\PromiseHandoffSourceFence": "src/Application/ServicePromise/Handoff/PromiseHandoffSourceFence.php",
}
TARGET_METHOD = "test_actual_capture_queries_are_deduplicated_and_authority_callbacks_run_only_outside_owned_sql"
BOUNDS_CLASS = "CetechDeliveryEngine\\Tests\\Integration\\ServicePromise\\PromiseQualificationBoundsRealDatabaseTest"
LIFETIME_CLASS = "CetechDeliveryEngine\\Tests\\Integration\\ServicePromise\\PromisePrimedSourceLifetimeRealDatabaseTest"
EXPECTED_CURRENT = {
    BOUNDS_CLASS: [
        TARGET_METHOD,
        "test_a_second_transaction_never_reuses_a_cached_eligible_policy_after_physical_retirement",
        "test_stack_local_prime_bulk_loads_unique_shared_policy_calendar_and_assignment_census_once",
    ],
    LIFETIME_CLASS: [
        "test_a_new_prime_on_the_same_held_owner_rechecks_modified_source_receipts",
        "test_lost_owner_after_source_reads_refuses_at_the_uncached_final_probe_without_replacement",
        'test_reusing_source_and_native_owner_after_a_unit_end_reloads_acknowledged_absence_changes with data set "commit"',
        'test_reusing_source_and_native_owner_after_a_unit_end_reloads_acknowledged_absence_changes with data set "rollback"',
    ],
}
EXPECTED_CURRENT = {name: sorted(methods) for name, methods in sorted(EXPECTED_CURRENT.items())}
EXPECTED_FAILURE = "The exact source or acknowledged-receipt SELECT was sent twice in one prime."
XML_LIMIT = 256 * 1024
STREAM_LIMIT = 256 * 1024


class ProofRejected(Exception):
    """Only this fixed structural code, never underlying child text, is public."""

    def __init__(self, code):
        if not re.fullmatch(r"[a-z0-9_]{1,64}", code):
            code = "proof_rejected"
        self.code = code


def require(condition, code):
    if not condition:
        raise ProofRejected(code)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def canonical(value):
    return json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode()


def unique(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, "duplicate_json_member")
        result[key] = value
    return result


def load_json(path, limit=512 * 1024):
    require(path.is_file() and not path.is_symlink() and 0 < path.stat().st_size <= limit, "private_json_unavailable")
    return json.loads(path.read_text(), object_pairs_hook=unique,
                      parse_constant=lambda _: (_ for _ in ()).throw(ProofRejected("nonfinite_json")))


def command(args, cwd, private, label, timeout=180, limit=STREAM_LIMIT, env=None, watched=None):
    """Cap both private streams and deadline; no child byte is printed."""
    require(re.fullmatch(r"[a-z0-9_]+", label) is not None, "invalid_capture_label")
    child_env = dict(os.environ)
    if env:
        child_env.update(env)
    child_env.pop("COMPOSER_VENDOR_DIR", None)
    child_env.pop("COMPOSER", None)
    child = subprocess.Popen(args, cwd=cwd, env=child_env, stdin=subprocess.DEVNULL,
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True)
    outputs = {"stdout": bytearray(), "stderr": bytearray()}
    failure = None
    deadline = time.monotonic() + timeout
    selector = selectors.DefaultSelector()
    try:
        for name in outputs:
            stream = getattr(child, name)
            os.set_blocking(stream.fileno(), False)
            selector.register(stream, selectors.EVENT_READ, name)
        while selector.get_map():
            if time.monotonic() > deadline:
                failure = "child_deadline"
                break
            if watched is not None and watched.exists() and watched.stat().st_size > XML_LIMIT:
                failure = "xml_capture_limit"
                break
            for key, _ in selector.select(0.05):
                data = os.read(key.fileobj.fileno(), min(16384, limit - len(outputs[key.data]) + 1))
                if not data:
                    selector.unregister(key.fileobj)
                else:
                    outputs[key.data].extend(data)
                    if len(outputs[key.data]) > limit:
                        failure = "child_stream_limit"
                        break
            if failure:
                break
        if failure:
            os.killpg(child.pid, signal.SIGKILL)
        try:
            code = child.wait(timeout=max(0.1, deadline - time.monotonic()))
        except subprocess.TimeoutExpired:
            os.killpg(child.pid, signal.SIGKILL)
            child.wait()
            failure = "child_deadline"
            code = -1
    finally:
        selector.close()
        for name in outputs:
            getattr(child, name).close()
        if child.poll() is None:
            os.killpg(child.pid, signal.SIGKILL)
            child.wait()
    for name, data in outputs.items():
        path = private / (label + "." + name)
        path.write_bytes(bytes(data[:limit]))
        path.chmod(0o600)
    require(failure is None, failure or "child_capture_rejected")
    return code, bytes(outputs["stdout"]), bytes(outputs["stderr"])


def git(repo, private, *args):
    label = "git_" + digest(canonical(list(args)))[:16]
    code, output, _ = command(["git", "-C", str(repo), *args], repo, private, label, timeout=60, limit=2 * 1024 * 1024)
    require(code == 0, "git_source_lookup_failed")
    return output


def production(path):
    return path.endswith(".php") and (path.startswith(("src/", "database/")) or path in
        ("cetech-woocommerce-delivery-engine.php", "uninstall.php"))


def source_manifest(repo, private, ref):
    paths = git(repo, private, "ls-tree", "-r", "--name-only", ref).decode().splitlines()
    sources = {}
    for path in sorted(filter(production, paths)):
        sources[path] = digest(git(repo, private, "show", ref + ":" + path))
    require(len(sources) == PRODUCTION_COUNT, "production_census_changed")
    return sources


def stage(repo, private, ref, name, expected):
    target = private / name
    target.mkdir(mode=0o700)
    archive = private / (name + ".tar")
    code, _, _ = command(["git", "-C", str(repo), "archive", "--format=tar", "--output=" + str(archive), ref],
                          repo, private, name.replace("-", "_") + "_archive", timeout=60)
    require(code == 0 and archive.is_file() and 0 < archive.stat().st_size <= 256 * 1024 * 1024, "source_archive_unavailable")
    with tarfile.open(archive) as bundle:
        seen = set()
        for member in bundle.getmembers():
            parts = Path(member.name).parts
            require(not Path(member.name).is_absolute() and ".." not in parts and member.name not in seen,
                    "unsafe_source_archive_member")
            require(member.isdir() or member.isfile(), "source_archive_link_forbidden")
            seen.add(member.name)
        bundle.extractall(target, filter="data")
    verify_staged(target, expected)
    return target


def verify_staged(root, expected):
    actual = {}
    for path in sorted(root.rglob("*")):
        if path.relative_to(root).parts[0] == "vendor":
            continue
        if production(path.relative_to(root).as_posix()):
            require(path.is_file() and not path.is_symlink(), "staged_production_link")
            actual[path.relative_to(root).as_posix()] = digest(path.read_bytes())
    require(actual == expected, "staged_production_bytes_changed")


BOOTSTRAP = r'''<?php
declare(strict_types=1);
// This wrapper and its output exist only in the owned private control directory.
$root = realpath((string) getenv('SOURCE_CONTROL_ROOT'));
$manifestFile = (string) getenv('SOURCE_CONTROL_MANIFEST');
$output = (string) getenv('SOURCE_CONTROL_LOADED');
$expected = json_decode((string) file_get_contents($manifestFile), true, 8, JSON_THROW_ON_ERROR);
if (!is_string($root) || !is_array($expected) || is_link($root . '/vendor') || realpath($root . '/vendor') !== $root . '/vendor') { exit(97); }
require $root . '/tests/bootstrap.php';
$classes = [
 'CetechDeliveryEngine\\Infrastructure\\Persistence\\WpdbPromiseHandoffSources' => 'src/Infrastructure/Persistence/WpdbPromiseHandoffSources.php',
 'CetechDeliveryEngine\\Application\\ServicePromise\\Handoff\\PromiseNativeCaptureService' => 'src/Application/ServicePromise/Handoff/PromiseNativeCaptureService.php',
 'CetechDeliveryEngine\\Application\\ServicePromise\\Handoff\\PromiseHandoffSourceFence' => 'src/Application/ServicePromise/Handoff/PromiseHandoffSourceFence.php',
];
$check = static function(string $class) use ($root, $expected): string {
 $file = (new ReflectionClass($class))->getFileName();
 if (!is_string($file) || !str_starts_with($file, $root . '/src/') || realpath($file) !== $file || is_link($file)) { exit(97); }
 $relative = substr($file, strlen($root) + 1);
 if (!isset($expected[$relative]) || !hash_equals($expected[$relative], hash_file('sha256', $file))) { exit(97); }
 return $relative;
};
foreach ($classes as $class => $relative) { if ($check($class) !== $relative) { exit(97); } }
register_shutdown_function(static function() use ($root, $expected, $output, $classes, $check): void {
 $count = 0;
 foreach (array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()) as $class) {
  if (str_starts_with($class, 'CetechDeliveryEngine\\') && !str_starts_with($class, 'CetechDeliveryEngine\\Tests\\')) { $check($class); ++$count; }
 }
 $hashes = [];
 foreach ($classes as $class => $relative) { if ($check($class) !== $relative) { exit(97); } $hashes[$relative] = $expected[$relative]; }
 ksort($hashes, SORT_STRING);
 $safe = ['mandatory_class_count' => count($hashes), 'mandatory_hashes' => $hashes, 'all_loaded_production_classes_verified' => true, 'loaded_production_count' => $count];
 if (file_put_contents($output, json_encode($safe, JSON_THROW_ON_ERROR)) === false) { exit(97); }
 chmod($output, 0600);
});
'''


DATABASE_PROBE = r'''
mysqli_report(MYSQLI_REPORT_OFF);
if (getenv('CETECH_DE_REAL_DB_NAME') !== 'cetech_operation_source_read_control' || getenv('CETECH_DE_REAL_DB_HOST') !== '127.0.0.1' || getenv('CETECH_DE_REAL_DB_PORT') !== '3306') { exit(97); }
$db = new mysqli('127.0.0.1', (string)getenv('CETECH_DE_REAL_DB_USER'), (string)getenv('CETECH_DE_REAL_DB_PASSWORD'), 'cetech_operation_source_read_control', 3306);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) { exit(97); }
$v = $db->query('SELECT VERSION() AS version');
$tables = $db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME');
$tx = $db->query("SELECT COUNT(*) AS count FROM information_schema.INNODB_TRX t JOIN information_schema.PROCESSLIST p ON p.ID=t.trx_mysql_thread_id WHERE p.DB=DATABASE()");
if (!$v instanceof mysqli_result || !$tables instanceof mysqli_result || !$tx instanceof mysqli_result) { exit(97); }
$value = ['php'=>PHP_VERSION, 'mariadb'=>$v->fetch_assoc()['version'], 'tables'=>array_column($tables->fetch_all(MYSQLI_ASSOC), 'TABLE_NAME'), 'active_transactions'=>(int)$tx->fetch_assoc()['count'], 'mysqli'=>extension_loaded('mysqli'), 'phpunit_extension_ready'=>extension_loaded('dom')];
$db->close();
echo json_encode($value, JSON_THROW_ON_ERROR);
'''


def database_probe(private, label):
    code, raw, _ = command(["php", "-r", DATABASE_PROBE], private, private, label, timeout=30, limit=8192)
    require(code == 0, "physical_database_probe_failed")
    value = json.loads(raw, object_pairs_hook=unique)
    require(set(value) == {"php", "mariadb", "tables", "active_transactions", "mysqli", "phpunit_extension_ready"}, "database_probe_shape")
    require(value["php"] == "8.5.11" and value["mysqli"] is True and value["phpunit_extension_ready"] is True, "php_runtime_pin_changed")
    require(isinstance(value["mariadb"], str) and re.fullmatch(r"11\.4\.13-MariaDB[-a-zA-Z0-9.+~]*", value["mariadb"]) is not None, "mariadb_runtime_pin_changed")
    require(value["tables"] == [] and value["active_transactions"] == 0, "owned_database_not_empty")
    return value


def install_dev(root, private, label):
    require(not (root / "vendor").exists(), "vendor_not_independent")
    code, _, _ = command(["composer", "install", "--no-interaction", "--prefer-dist", "--no-progress"],
                          root, private, label, timeout=420, limit=2 * 1024 * 1024)
    require(code == 0, "composer_dev_install_failed")
    require((root / "vendor").is_dir() and not (root / "vendor").is_symlink()
            and (root / "vendor").resolve() == root / "vendor", "vendor_source_link")
    installed = load_json(root / "vendor/composer/installed.json", limit=4 * 1024 * 1024)
    require(isinstance(installed, dict) and installed.get("dev") is True, "development_dependencies_missing")
    packages = {p["name"]: p["version"] for p in installed.get("packages", [])}
    require("phpunit/phpunit" in packages and re.fullmatch(r"v?10\.5\.[0-9]+", packages["phpunit/phpunit"]) is not None, "phpunit_missing_or_wrong_major")
    return packages


def source_inventory(root):
    bounds = (root / TEST_PATH).read_text()
    lifetime = (root / LIFETIME_PATH).read_text()
    require(sorted(re.findall(r"public function (test_\w+)\(", bounds)) == EXPECTED_CURRENT[BOUNDS_CLASS], "bounds_source_inventory_changed")
    methods = re.findall(r"public function (test_\w+)\(", lifetime)
    expected_bases = {name.split(' with data set ', 1)[0] for name in EXPECTED_CURRENT[LIFETIME_CLASS]}
    require(set(methods) == expected_bases and len(methods) == 3, "lifetime_source_inventory_changed")
    require("'rollback' => [ false ], 'commit' => [ true ]" in lifetime, "lifetime_dataset_inventory_changed")


def junit(path, old):
    require(path.is_file() and not path.is_symlink() and 0 < path.stat().st_size <= XML_LIMIT, "junit_capture_unavailable")
    raw = path.read_bytes()
    require(b"<!DOCTYPE" not in raw and b"<!ENTITY" not in raw, "junit_declaration_forbidden")
    root = ET.fromstring(raw)
    cases = list(root.iter("testcase"))
    failures = [(case, failure) for case in cases for failure in case.findall("failure")]
    errors = sum(len(case.findall("error")) for case in cases)
    skips = sum(len(case.findall("skipped")) for case in cases)
    require(errors == 0 and skips == 0, "test_error_or_skip")
    require(all(re.fullmatch(r"[0-9]+", case.get("assertions", "")) is not None for case in cases), "assertion_count_unavailable")
    assertions = sum(int(case.get("assertions")) for case in cases)
    inventory = {}
    for case in cases:
        inventory.setdefault(case.get("class"), []).append(case.get("name"))
    inventory = {name: sorted(methods) for name, methods in sorted(inventory.items())}
    if old:
        require(inventory == {BOUNDS_CLASS: [TARGET_METHOD]} and len(failures) == 1 and assertions >= 12, "old_expected_test_not_executed")
        failure = failures[0][1]
        text = "".join(failure.itertext())
        require(failure.get("type") == "PHPUnit\\Framework\\ExpectationFailedException", "old_failure_type_changed")
        require(EXPECTED_FAILURE in text and "Failed asserting that an array does not have the key" in text,
                "old_duplicate_predicate_missing")
        require(re.search(r"SELECT [\s\S]{1,32768}? FROM `gc6_[a-f0-9]{12}_delivery_engine_(?:promise_(?:objects|versions|assignments)|operation_(?:records|changes))`", text) is not None,
                "old_physical_select_witness_missing")
    else:
        require(inventory == EXPECTED_CURRENT and len(failures) == 0 and assertions == 228, "current_seven_case_control_failed")
    return {"tests": len(cases), "assertions": assertions, "failures": len(failures), "errors": errors, "skips": skips,
            "inventory_sha256": digest(canonical(inventory)), "expected_duplicate_predicate_verified": old}


def run_test(root, private, name, expected, old):
    manifest = private / (name + "-manifest.json")
    manifest.write_bytes(canonical(expected)); manifest.chmod(0o600)
    loaded = private / (name + "-loaded.json")
    xml = private / (name + "-junit.xml")
    bootstrap = private / "source-control-bootstrap.php"
    bootstrap.write_text(BOOTSTRAP); bootstrap.chmod(0o600)
    env = {"SOURCE_CONTROL_ROOT": str(root), "SOURCE_CONTROL_MANIFEST": str(manifest), "SOURCE_CONTROL_LOADED": str(loaded)}
    pattern = TARGET_METHOD + "$" if old else "(?:PromisePrimedSourceLifetimeRealDatabaseTest|PromiseQualificationBoundsRealDatabaseTest)::"
    args = ["php", str(root / "vendor/bin/phpunit"), "-c", str(root / "phpunit.real-db.xml"),
            "--bootstrap", str(bootstrap), "--colors=never", "--do-not-cache-result", "--log-junit", str(xml), "--filter", pattern]
    if old:
        args.append(str(root / TEST_PATH))
    code, _, _ = command(args, root, private, name + "_phpunit", timeout=180, env=env, watched=xml)
    require(code == (1 if old else 0), "unexpected_phpunit_exit")
    evidence = junit(xml, old)
    load = load_json(loaded)
    require(set(load) == {"mandatory_class_count", "mandatory_hashes", "all_loaded_production_classes_verified", "loaded_production_count"}, "autoload_report_shape")
    require(load["mandatory_class_count"] == 3 and load["all_loaded_production_classes_verified"] is True
            and type(load["loaded_production_count"]) is int and load["loaded_production_count"] >= 3,
            "actual_production_autoload_not_verified")
    require(load["mandatory_hashes"] == {p: expected[p] for p in sorted(CLASS_PATHS.values())}, "actual_class_source_bytes_changed")
    evidence["autoload"] = load
    verify_staged(root, expected)
    return evidence


def prove(repo, private):
    require(os.environ.get("CETECH_DE_REAL_DB_NAME") == DATABASE_NAME, "database_lease_mismatch")
    require(os.environ.get("CETECH_DE_REAL_DB_HOST") == "127.0.0.1" and os.environ.get("CETECH_DE_REAL_DB_PORT") == "3306", "database_host_lease_mismatch")
    require(git(repo, private, "status", "--porcelain=v1") == b"", "proof_checkout_not_clean")
    current = git(repo, private, "rev-parse", "HEAD").decode().strip()
    require(re.fullmatch(r"[a-f0-9]{40}", current) is not None, "current_head_invalid")
    require(git(repo, private, "rev-list", "--parents", "-n", "1", current).decode().split() == [current, CORRECTION_HEAD], "proof_parent_changed")
    require(git(repo, private, "rev-parse", OLD_HEAD + "^{tree}").decode().strip() == OLD_TREE, "old_tree_changed")
    require(git(repo, private, "rev-parse", CORRECTION_HEAD + "^{tree}").decode().strip() == CORRECTION_TREE, "correction_tree_changed")
    current_tree = git(repo, private, "rev-parse", current + "^{tree}").decode().strip()
    changed = set(git(repo, private, "diff", "--name-only", CORRECTION_HEAD, current).decode().splitlines())
    require(changed == {".github/workflows/ci.yml", "scripts/qualification/run-source-read-control.py"}, "proof_branch_source_scope_changed")
    protected = ["src", "database", "cetech-woocommerce-delivery-engine.php", "uninstall.php", "tests/Support", "composer.json", "phpunit.xml", "phpunit.real-db.xml"]
    delta = set(git(repo, private, "diff", "--name-only", OLD_HEAD, current, "--", *protected).decode().splitlines())
    require(delta == set(CLASS_PATHS.values()), "production_or_fixture_dependency_changed")
    require(git(repo, private, "rev-parse", current + ":" + TEST_PATH).decode().strip() == TEST_BLOB
            and git(repo, private, "rev-parse", current + ":" + LIFETIME_PATH).decode().strip() == LIFETIME_BLOB,
            "control_test_blob_changed")
    old_sources = source_manifest(repo, private, OLD_HEAD)
    current_sources = source_manifest(repo, private, current)
    require(digest(canonical(old_sources)) == OLD_MAP and digest(canonical(current_sources)) == CURRENT_MAP, "pinned_source_map_changed")
    old = stage(repo, private, OLD_HEAD, "old-source", old_sources)
    current_stage = stage(repo, private, current, "current-source", current_sources)
    source_inventory(current_stage)
    old_test = old / TEST_PATH
    require(not old_test.exists(), "old_test_overlay_already_exists")
    old_test.write_bytes(git(repo, private, "show", current + ":" + TEST_PATH)); old_test.chmod(0o644)
    old_phpunit = install_dev(current_stage, private, "current_composer")
    lock = current_stage / "composer.lock"
    require(lock.is_file() and not lock.is_symlink(), "resolved_dependency_lock_unavailable")
    lock_hash = digest(lock.read_bytes())
    require(not (old / "composer.lock").exists(), "unexpected_old_dependency_lock")
    shutil.copyfile(lock, old / "composer.lock")
    require(install_dev(old, private, "old_composer") == old_phpunit, "control_dependencies_differ")
    require(digest((old / "composer.lock").read_bytes()) == lock_hash, "auxiliary_dependency_lock_changed")
    require((old / "vendor").resolve() != (current_stage / "vendor").resolve(), "vendors_share_source_root")
    before_old = database_probe(private, "database_before_old")
    try:
        old_result = run_test(old, private, "old", old_sources, True)
    finally:
        after_old = database_probe(private, "database_after_old")
    before_current = database_probe(private, "database_before_current")
    try:
        current_result = run_test(current_stage, private, "current", current_sources, False)
    finally:
        after_current = database_probe(private, "database_after_current")
    require(before_old == after_old == before_current == after_current, "runtime_or_cleanup_census_changed")
    return {
        "format": "cetech-p05-controlled-source-read-proof-v1", "status": "PASS",
        "source": {"old_head": OLD_HEAD, "old_tree": OLD_TREE, "old_production_count": PRODUCTION_COUNT,
                   "old_production_map_sha256": OLD_MAP, "correction_head": CORRECTION_HEAD,
                   "correction_tree": CORRECTION_TREE, "proof_head": current, "proof_tree": current_tree,
                   "current_production_count": PRODUCTION_COUNT, "current_production_map_sha256": CURRENT_MAP,
                   "unchanged_capture_test_git_blob": TEST_BLOB, "lifetime_test_git_blob": LIFETIME_BLOB},
        "runtime": {"php": before_old["php"], "mariadb": before_old["mariadb"],
                    "phpunit": old_phpunit["phpunit/phpunit"], "auxiliary_composer_lock_sha256": lock_hash,
                    "separate_physical_dev_vendors": True},
        "old_expected_red": old_result, "current_expected_green": current_result,
        "cleanup": {"before_old_table_count": 0, "after_old_table_count": 0,
                    "before_current_table_count": 0, "after_current_table_count": 0,
                    "active_transactions_before_after": 0, "exact_empty_census_restored": True,
                    "private_raw_files_removed": True},
        "limits": {"synthetic_detached_capture_context": True, "physical_source_read_duplication_only": True,
                   "woocommerce_payment_stage_attribution": False, "old_woocommerce_child_timing_causality": False,
                   "new_native_checkout_qualification": False},
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--repo", type=Path, required=True)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    report = {"format": "cetech-p05-controlled-source-read-proof-v1", "status": "FAIL", "rejection_code": "proof_incomplete"}
    success = False
    owned_path = None
    try:
        with tempfile.TemporaryDirectory(prefix="cetech-source-read-control-private-") as owned:
            private = Path(owned)
            owned_path = private
            private.chmod(0o700)
            report = prove(args.repo.resolve(), private)
        # Tempdir has removed archives, vendors, streams, SQL-bearing XML and manifests.
        success = True
    except ProofRejected as error:
        report = {"format": "cetech-p05-controlled-source-read-proof-v1", "status": "FAIL", "rejection_code": error.code}
    except BaseException:
        report = {"format": "cetech-p05-controlled-source-read-proof-v1", "status": "FAIL", "rejection_code": "unexpected_control_failure"}
    raw_removed = owned_path is not None and not owned_path.exists()
    if not raw_removed:
        success = False
        report = {"format": "cetech-p05-controlled-source-read-proof-v1", "status": "FAIL", "rejection_code": "private_cleanup_not_verified"}
    args.report.parent.mkdir(parents=True, exist_ok=True)
    require(not args.report.exists() and not args.report.is_symlink(), "report_path_already_exists")
    encoded = json.dumps(report, ensure_ascii=False, indent=2) + "\n"
    require(len(encoded.encode()) <= 16384, "safe_report_too_large")
    with args.report.open("x") as output:
        output.write(encoded)
    print(json.dumps({"status": report["status"], "private_raw_files_removed": raw_removed}))
    raise SystemExit(0 if success else 1)


if __name__ == "__main__":
    main()
