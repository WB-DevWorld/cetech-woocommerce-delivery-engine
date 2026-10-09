#!/usr/bin/env python3
"""Closed P05 native HPOS/CPT receipts; never replaces the eight preceding primary proofs."""
from dataclasses import dataclass
import ast
import hashlib
import importlib.util
from pathlib import Path
import re
import subprocess
import sys
sys.dont_write_bytecode = True

SOURCE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("p05_retained_handoff", SOURCE / "verify-promise-handoff-receipts.py")
calculation = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = calculation
spec.loader.exec_module(calculation)
placement = calculation.placement
require, closed, load = placement.require, placement.closed, placement.load
CONTEXT = "Fresh WP-CLI native P05 configuration; actual protected native administration and immutable original/current shipment facts"
BACKGROUND = "WP Cron disabled; Action Scheduler async request runner suppressed in this process"
LIMITS = [
    "Marked disposable native WordPress; default promise adoption remains OFF.",
    "Authentic native authorization, immutable policy lifecycle and saved order/shipment proofs; customer request/browser parity has a separate receipt.",
    "No live payment, external gateway, target theme, persistent cache or operational certification claim.",
    "All eight preceding P01-P04 primary receipts pass independently on the same installed production source."
]
PRODUCERS = (
    "scripts/qualification/opening-promise-native-configuration.php",
    "scripts/qualification/opening-promise-native-configuration-shipment.php",
    "scripts/qualification/opening-promise-native-configuration-shipment-payment.php",
    "scripts/qualification/opening-promise-native-configuration-support.php",
    "scripts/qualification/opening-promise-native-configuration-runner.php",
    "scripts/qualification/verify-opening-promise-native-configuration.py",
    "scripts/ci-promise-native-configuration-hpos-off.sh",
    "scripts/qualification/opening-http-promise-native-configuration.py",
    "scripts/qualification/opening-http-promise-native-configuration-support.php",
    "scripts/qualification/opening-http-promise-native-configuration-shipment-support.php",
    "scripts/qualification/opening-http-promise-native-configuration-mu.php",
    "scripts/qualification/promise-native-configuration-browser.cjs",
    "scripts/ci-promise-native-configuration-http-qualification.sh",
)


def parse_php_protocol(filename):
    text = (SOURCE / filename).read_text(encoding="utf-8")
    result = []
    for match in re.finditer(r"\$check\(\s*'NATIVE-W2P05-", text):
        args = placement.php_parts(placement.php_block(text, match.start() + len("$check")), "(", ")")
        require(len(args) == 3 and re.fullmatch(r"'NATIVE-W2P05-[A-Z0-9-]+'", args[0]), "Ambiguous P05 source case")
        require(args[2].startswith("[") and args[2].endswith("]"), "Unknown P05 evidence source")
        fields = []
        for entry in placement.php_parts(args[2], "[", "]"):
            field = re.fullmatch(r"'([a-z0-9_]+)'\s*=>\s*.+", entry, re.S)
            require(field is not None and field[1] not in fields, "Ambiguous P05 source observation")
            fields.append(field[1])
        require(fields, "Empty P05 observation")
        result.append((args[0][1:-1], tuple(fields)))
    require(0 < len(result) <= 64 and len({item[0] for item in result}) == len(result), "Ambiguous P05 source inventory")
    return tuple(result)


def protocol():
    config = parse_php_protocol("opening-promise-native-configuration.php")
    shipment = parse_php_protocol("opening-promise-native-configuration-shipment.php")
    require(config[-1][0] == "NATIVE-W2P05-TRACKED-NATIVE-CONFIGURATION-CLEANUP" and config[-2][0] == "NATIVE-W2P05-GUEST-PRIVATE-CONFIGURATION-REFUSED", "P05 execution inventory ordering differs")
    result = config[:-2] + shipment + config[-2:]
    require(len(result) <= 64 and len({item[0] for item in result}) == len(result), "Ambiguous combined P05 source inventory")
    return result


def http_protocol():
    """Read authored literal observation declarations without executing the driver."""
    tree = ast.parse((SOURCE / "opening-http-promise-native-configuration.py").read_text(encoding="utf-8"))
    declarations = {}
    for node in tree.body:
        if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name) and node.targets[0].id in {"CONTEXT", "BACKGROUND", "LIMITS"}:
            require(node.targets[0].id not in declarations, "Ambiguous P05 HTTP scope")
            declarations[node.targets[0].id] = ast.literal_eval(node.value)
    require(set(declarations) == {"CONTEXT", "BACKGROUND", "LIMITS"} and isinstance(declarations["CONTEXT"], str) and declarations["BACKGROUND"] == BACKGROUND and isinstance(declarations["LIMITS"], list) and all(isinstance(value, str) for value in declarations["LIMITS"]), "Unknown P05 HTTP declared scope")
    result = []
    for node in ast.walk(tree):
        if not (isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute) and isinstance(node.func.value, ast.Name) and node.func.value.id == "recorder" and node.func.attr == "check"):
            continue
        require(len(node.args) == 3 and not node.keywords and isinstance(node.args[0], ast.Constant) and isinstance(node.args[0].value, str) and re.fullmatch(r"HTTP-W2P05-[A-Z0-9-]+", node.args[0].value), "Ambiguous P05 HTTP source case")
        require(isinstance(node.args[2], ast.Dict) and node.args[2].keys, "Missing P05 HTTP source observations")
        fields = []
        for field in node.args[2].keys:
            require(isinstance(field, ast.Constant) and isinstance(field.value, str) and re.fullmatch(r"[a-z0-9_]+", field.value) and field.value not in fields, "Ambiguous P05 HTTP observation")
            fields.append(field.value)
        result.append((node.lineno, node.args[0].value, tuple(fields)))
    result.sort()
    require(0 < len(result) <= 64 and len({item[1] for item in result}) == len(result), "Ambiguous P05 HTTP source inventory")
    return tuple((case_id, fields) for _, case_id, fields in result), declarations


@dataclass(frozen=True)
class Authority:
    calculation: object
    cases: tuple
    http_cases: tuple = ()
    http_scope: object = None


def authority():
    prior = calculation.authority()
    retained = prior.calculation.p02.retained
    for name in PRODUCERS:
        require((SOURCE.parent.parent / name).read_bytes() == placement.git("show", retained.source_head + ":" + name), "Uncommitted P05 producer authority")
    schema = placement.git("show", retained.source_head + ":src/Core/Versioning/SchemaVersion.php").decode()
    require(len(re.findall(r"public const TARGET\s*=\s*'11'\s*;", schema)) == 1, "P05 schema authority differs")
    http_cases, http_scope = http_protocol()
    return Authority(prior, protocol(), http_cases, http_scope)


def verify(reports, prior_reports, hashes, expected):
    kinds = ("native", "cpt", "http", "p02", "p03", "p04_hpos", "p04_cpt", "p04_http")
    require(isinstance(hashes, dict) and set(hashes) == set(kinds) and len(prior_reports) == 8, "Unknown P05 prerequisite authority")
    require(isinstance(reports, list) and len(reports) == 2, "Separate complete P05 HPOS and fresh CPT receipts are required")
    retained = expected.calculation.calculation.p02.retained
    for report, native_mode in zip(reports, ("yes", "no")):
        closed(report, placement.COMMON_KEYS | {"limits", "preceding_receipts"}, "Unknown P05 receipt envelope")
        require(report["format"] == "cetech-opening-promise-native-configuration-v1" and report["status"] == "PASS", "Incomplete P05 native configuration")
        for key in ("source_head", "candidate_head", "source_tree"):
            require(report[key] == getattr(retained, key), "P05 immutable execution identity differs")
        require(report["installed_php_sources"] == retained.sources and report["installed_php_sources_hash"] == placement.canonical_hash(retained.sources), "P05 installed production bytes differ")
        require(report["limits"] == LIMITS, "P05 native scope differs")
        environment = report["environment"]
        closed(environment, {"php", "wordpress", "woocommerce", "database_version", "hpos", "schema_before", "context", "background_requests"}, "Unknown P05 runtime")
        for key, value in {"php": "8.5.11", "wordpress": "7.1.2", "woocommerce": "11.1.2", "hpos": native_mode, "schema_before": "11", "context": CONTEXT, "background_requests": BACKGROUND}.items():
            require(environment[key] == value, "Pinned P05 native runtime or fresh storage mode differs")
        require(isinstance(environment["database_version"], str) and re.fullmatch(r"11\.4\.13-MariaDB(?:-[A-Za-z0-9_.]+)*", environment["database_version"]), "Pinned P05 database differs")
        closed(report["preceding_receipts"], kinds, "Unknown P05 preceding receipts")
        for kind, prior in zip(kinds, prior_reports):
            require(prior["status"] == "PASS" and all(case["status"] == "PASS" for case in prior["cases"]), "P05 cannot compensate for prior failure")
            link = report["preceding_receipts"][kind]
            closed(link, {"sha256", "cases"}, "Unknown P05 preceding receipt fact")
            require(placement.sha256(hashes[kind]) and link["sha256"] == hashes[kind], "P05 preceding original bytes differ")
            require(type(link["cases"]) is int and link["cases"] == len(prior["cases"]), "P05 preceding inventory differs")
        cases = report["cases"]
        require(isinstance(cases, list) and len(cases) == len(expected.cases), "Incomplete or extra P05 native inventory")
        for observed, (case_id, fields) in zip(cases, expected.cases):
            closed(observed, {"id", "status", "evidence"}, "Unknown P05 case")
            require(observed["id"] == case_id and observed["status"] == "PASS", "Failed, duplicate or reordered P05 case")
            closed(observed["evidence"], fields, "Unknown P05 observation")
            require(all(value is True for value in observed["evidence"].values()), "P05 observation is not an exact passing boolean")
    return len(expected.cases)


def verify_http(report, native_reports, prior_reports, hashes, expected):
    kinds = ("native", "cpt", "http", "p02", "p03", "p04_hpos", "p04_cpt", "p04_http", "p05_hpos", "p05_cpt")
    require(isinstance(hashes, dict) and set(hashes) == set(kinds) and len(prior_reports) == 8 and len(native_reports) == 2 and expected.http_cases and isinstance(expected.http_scope, dict), "P05 HTTP preceding authority differs")
    retained = expected.calculation.calculation.p02.retained
    closed(report, placement.COMMON_KEYS | {"limits", "preceding_receipts", "browser_runtime"}, "Unknown P05 HTTP receipt envelope")
    require(report["format"] == "cetech-opening-http-promise-native-configuration-v1" and report["status"] == "PASS", "Incomplete P05 native customer routes")
    for key in ("source_head", "candidate_head", "source_tree"):
        require(report[key] == getattr(retained, key), "P05 HTTP immutable identity differs")
    require(report["installed_php_sources"] == retained.sources and report["installed_php_sources_hash"] == placement.canonical_hash(retained.sources), "P05 HTTP production bytes differ")
    require(report["limits"] == expected.http_scope["LIMITS"], "P05 HTTP scope differs")
    environment = report["environment"]
    closed(environment, {"php", "wordpress", "woocommerce", "database_version", "hpos", "schema_before", "context", "background_requests"}, "Unknown P05 HTTP runtime")
    for key, value in {"php": "8.5.11", "wordpress": "7.1.2", "woocommerce": "11.1.2", "hpos": "yes", "schema_before": "11", "context": expected.http_scope["CONTEXT"], "background_requests": expected.http_scope["BACKGROUND"]}.items():
        require(environment[key] == value, "Pinned P05 HTTP runtime differs")
    require(isinstance(environment["database_version"], str) and re.fullmatch(r"11\.4\.13-MariaDB(?:-[A-Za-z0-9_.]+)*", environment["database_version"]), "Pinned P05 HTTP database differs")
    closed(report["browser_runtime"], {"playwright", "chromium"}, "Unknown P05 browser runtime")
    require(report["browser_runtime"] == {"playwright": "1.58.2", "chromium": "145.0.7632.6"}, "Pinned real P05 browser runtime differs")
    closed(report["preceding_receipts"], kinds, "Unknown P05 HTTP preceding receipts")
    for kind, prior in zip(kinds, list(prior_reports) + list(native_reports)):
        link = report["preceding_receipts"][kind]
        closed(link, {"sha256", "cases"}, "Unknown P05 HTTP prior fact")
        require(prior["status"] == "PASS" and all(case["status"] == "PASS" for case in prior["cases"]), "P05 HTTP cannot compensate for prior failure")
        require(placement.sha256(hashes[kind]) and link["sha256"] == hashes[kind] and type(link["cases"]) is int and link["cases"] == len(prior["cases"]), "P05 HTTP prior raw bytes or inventory differ")
    require(isinstance(report["cases"], list) and len(report["cases"]) == len(expected.http_cases), "Incomplete P05 HTTP source inventory")
    for observed, (case_id, fields) in zip(report["cases"], expected.http_cases):
        closed(observed, {"id", "status", "evidence"}, "Unknown P05 HTTP case")
        require(observed["id"] == case_id and observed["status"] == "PASS", "Failed, duplicate or reordered P05 HTTP case")
        closed(observed["evidence"], fields, "Unknown P05 HTTP observation")
        require(all(value is True for value in observed["evidence"].values()), "P05 HTTP observation is not an exact passing boolean")
    return len(expected.http_cases)


def verify_native_file_paths(paths):
    require(len(paths) == 10, "P05 HPOS/CPT and eight preceding original receipts are required")
    reports = [load(path) for path in paths[:2]]
    priors = [load(path) for path in paths[2:]]
    kinds = ("native", "cpt", "http", "p02", "p03", "p04_hpos", "p04_cpt", "p04_http")
    hashes = {kind: hashlib.sha256(Path(path).read_bytes()).hexdigest() for kind, path in zip(kinds, paths[2:])}
    expected = authority()
    calculation.main([paths[7], paths[8], paths[6], paths[5], paths[2], paths[3], paths[4], paths[9]])
    count = verify(reports, priors, hashes, expected)
    return count, reports, priors, hashes, expected


def main(argv):
    if argv and argv[0] == "--native-only":
        count, *_ = verify_native_file_paths(argv[1:])
        print(f"promise_native_configuration_native_preflight=PASS hpos={count} fresh_cpt={count} retained=806")
        return
    require(len(argv) == 11, "P05 HPOS/CPT, eight preceding original receipts and separate P05 HTTP/browser receipt are required")
    count, reports, priors, hashes, expected = verify_native_file_paths(argv[:10])
    hashes.update({kind: hashlib.sha256(Path(path).read_bytes()).hexdigest() for kind, path in zip(("p05_hpos", "p05_cpt"), argv[:2])})
    http_count = verify_http(load(argv[10]), reports, priors, hashes, expected)
    print(f"promise_native_configuration_receipts=PASS hpos={count} fresh_cpt={count} http_browser={http_count} retained=806")


if __name__ == "__main__":
    try:
        main(sys.argv[1:])
    except (RuntimeError, ValueError, TypeError, KeyError, OSError, subprocess.SubprocessError):
        print("promise_native_configuration_receipts=FAIL", file=sys.stderr)
        sys.exit(1)
