#!/usr/bin/env python3
"""Closed P04 native HPOS/CPT receipts; never replaces the five preceding primary proofs."""
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
spec = importlib.util.spec_from_file_location("promise_calculation_for_handoff", SOURCE / "verify-promise-calculation-receipts.py")
calculation = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = calculation
spec.loader.exec_module(calculation)
placement = calculation.placement
require, closed, load = placement.require, placement.closed, placement.load
CONTEXT = "Fresh WP-CLI native P04 handoff; actual Woo CRUD, native promise capture and original physical Q06 seal"
BACKGROUND = "WP Cron disabled; Action Scheduler async request runner suppressed in this process"
LIMITS = [
    "Marked disposable native WordPress with explicit internal fixture adopter; default promise adoption remains OFF.",
    "Actual native capture and physical saved-order/seal evidence; no public configuration UI or customer-route parity claim.",
    "Only explicit no-capacity adapter is mounted; required capacity is refused, never inferred or reserved.",
    "Retained 495/20/143, P02 19 and P03 49 primary receipts must pass independently on the same installed source.",
]
PRODUCERS = (
    "scripts/qualification/opening-promise-handoff.php",
    "scripts/qualification/opening-promise-handoff-support.php",
    "scripts/qualification/opening-promise-handoff-reader.php",
    "scripts/qualification/opening-promise-handoff-runner.php",
    "scripts/qualification/verify-promise-handoff-receipts.py",
    "scripts/ci-promise-handoff-hpos-off.sh",
    "scripts/qualification/opening-quote-placement-hpos-control.php",
    "scripts/qualification/opening-quote-placement-support.php",
    "scripts/qualification/opening-http-quote-placement-support.php",
    "scripts/qualification/opening-promise-storage-support.php",
    "scripts/qualification/opening-http-promise-handoff.py",
    "scripts/qualification/opening-http-promise-handoff-support.php",
    "scripts/qualification/opening-http-promise-handoff-mu.php",
    "scripts/qualification/promise-handoff-browser.cjs",
    "scripts/ci-promise-handoff-http-qualification.sh",
)


def protocol():
    text = (SOURCE / "opening-promise-handoff.php").read_text(encoding="utf-8")
    result = []
    for match in re.finditer(r"\$check\(\s*'NATIVE-W2P04-", text):
        args = placement.php_parts(placement.php_block(text, match.start() + len("$check")), "(", ")")
        require(len(args) == 3 and re.fullmatch(r"'NATIVE-W2P04-[A-Z0-9-]+'", args[0]), "Ambiguous P04 source case")
        require(args[2].startswith("[") and args[2].endswith("]"), "Unknown P04 evidence source")
        fields = []
        for entry in placement.php_parts(args[2], "[", "]"):
            field = re.fullmatch(r"'([a-z0-9_]+)'\s*=>\s*.+", entry, re.S)
            require(field is not None and field[1] not in fields, "Ambiguous P04 source observation")
            fields.append(field[1])
        require(fields, "Empty P04 observation")
        result.append((args[0][1:-1], tuple(fields)))
    require(0 < len(result) <= 64 and len({item[0] for item in result}) == len(result), "Ambiguous P04 source inventory")
    return tuple(result)


def http_protocol():
    """Read authored literal observation declarations without executing the driver."""
    tree = ast.parse((SOURCE / "opening-http-promise-handoff.py").read_text(encoding="utf-8"))
    declarations = {}
    for node in tree.body:
        if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name) and node.targets[0].id in {"CONTEXT", "BACKGROUND", "LIMITS"}:
            require(node.targets[0].id not in declarations, "Ambiguous P04 HTTP scope")
            declarations[node.targets[0].id] = ast.literal_eval(node.value)
    require(set(declarations) == {"CONTEXT", "BACKGROUND", "LIMITS"} and isinstance(declarations["CONTEXT"], str) and declarations["BACKGROUND"] == BACKGROUND and isinstance(declarations["LIMITS"], list) and all(isinstance(value, str) for value in declarations["LIMITS"]), "Unknown P04 HTTP declared scope")
    result = []
    for node in ast.walk(tree):
        if not (isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute) and isinstance(node.func.value, ast.Name) and node.func.value.id == "recorder" and node.func.attr == "check"):
            continue
        require(len(node.args) == 3 and not node.keywords and isinstance(node.args[0], ast.Constant) and isinstance(node.args[0].value, str) and re.fullmatch(r"HTTP-W2P04-[A-Z0-9-]+", node.args[0].value), "Ambiguous P04 HTTP source case")
        require(isinstance(node.args[2], ast.Dict) and node.args[2].keys, "Missing P04 HTTP source observations")
        fields = []
        for field in node.args[2].keys:
            require(isinstance(field, ast.Constant) and isinstance(field.value, str) and re.fullmatch(r"[a-z0-9_]+", field.value) and field.value not in fields, "Ambiguous P04 HTTP observation")
            fields.append(field.value)
        result.append((node.lineno, node.args[0].value, tuple(fields)))
    result.sort()
    require(0 < len(result) <= 64 and len({item[1] for item in result}) == len(result), "Ambiguous P04 HTTP source inventory")
    return tuple((case_id, fields) for _, case_id, fields in result), declarations


@dataclass(frozen=True)
class Authority:
    calculation: object
    cases: tuple
    http_cases: tuple = ()
    http_scope: object = None


def authority():
    prior = calculation.authority()
    retained = prior.p02.retained
    for name in PRODUCERS:
        require((SOURCE.parent.parent / name).read_bytes() == placement.git("show", retained.source_head + ":" + name), "Uncommitted P04 producer authority")
    schema = placement.git("show", retained.source_head + ":src/Core/Versioning/SchemaVersion.php").decode()
    require(len(re.findall(r"public const TARGET\s*=\s*'11'\s*;", schema)) == 1, "P04 schema authority differs")
    http_cases, http_scope = http_protocol()
    return Authority(prior, protocol(), http_cases, http_scope)


def verify(reports, p03_report, p02_report, retained_reports, hashes, expected):
    require(isinstance(hashes, dict) and set(hashes) == {"native", "cpt", "http", "p02", "p03"}, "Unknown P04 preceding raw-byte hashes")
    calculation.verify(p03_report, p02_report, retained_reports, {key: hashes[key] for key in ("native", "cpt", "http", "p02")}, expected.calculation)
    require(isinstance(reports, list) and len(reports) == 2, "Separate complete P04 HPOS and fresh CPT receipts are required")
    retained = expected.calculation.p02.retained
    for report, native_mode in zip(reports, ("yes", "no")):
        closed(report, placement.COMMON_KEYS | {"limits", "preceding_receipts"}, "Unknown P04 receipt envelope")
        require(report["format"] == "cetech-opening-promise-handoff-v1" and report["status"] == "PASS", "Incomplete P04 native handoff")
        for key in ("source_head", "candidate_head", "source_tree"):
            require(report[key] == getattr(retained, key), "P04 immutable execution identity differs")
        require(report["installed_php_sources"] == retained.sources and report["installed_php_sources_hash"] == placement.canonical_hash(retained.sources), "P04 installed production bytes differ")
        require(report["limits"] == LIMITS, "P04 native scope differs")
        environment = report["environment"]
        closed(environment, {"php", "wordpress", "woocommerce", "database_version", "hpos", "schema_before", "context", "background_requests"}, "Unknown P04 runtime")
        for key, value in {"php": "8.5.11", "wordpress": "7.1.2", "woocommerce": "11.1.2", "hpos": native_mode, "schema_before": "11", "context": CONTEXT, "background_requests": BACKGROUND}.items():
            require(environment[key] == value, "Pinned P04 native runtime or fresh storage mode differs")
        require(isinstance(environment["database_version"], str) and re.fullmatch(r"11\.4\.13-MariaDB(?:-[A-Za-z0-9_.]+)*", environment["database_version"]), "Pinned P04 database differs")
        closed(report["preceding_receipts"], {"native", "cpt", "http", "p02", "p03"}, "Unknown P04 preceding receipts")
        for kind, prior in zip(("native", "cpt", "http", "p02", "p03"), list(retained_reports) + [p02_report, p03_report]):
            link = report["preceding_receipts"][kind]
            closed(link, {"sha256", "cases"}, "Unknown P04 preceding receipt fact")
            require(placement.sha256(hashes[kind]) and link["sha256"] == hashes[kind], "P04 preceding original bytes differ")
            require(type(link["cases"]) is int and link["cases"] == len(prior["cases"]), "P04 preceding inventory differs")
        cases = report["cases"]
        require(isinstance(cases, list) and len(cases) == len(expected.cases), "Incomplete or extra P04 native inventory")
        for observed, (case_id, fields) in zip(cases, expected.cases):
            closed(observed, {"id", "status", "evidence"}, "Unknown P04 case")
            require(observed["id"] == case_id and observed["status"] == "PASS", "Failed, duplicate or reordered P04 case")
            closed(observed["evidence"], fields, "Unknown P04 observation")
            require(all(value is True for value in observed["evidence"].values()), "P04 observation is not an exact passing boolean")
    return len(expected.cases)


def verify_http(report, native_reports, prior_reports, hashes, expected):
    kinds = ("native", "cpt", "http", "p02", "p03", "p04_hpos", "p04_cpt")
    require(isinstance(hashes, dict) and set(hashes) == set(kinds) and len(prior_reports) == 5 and len(native_reports) == 2 and expected.http_cases and isinstance(expected.http_scope, dict), "P04 HTTP preceding authority differs")
    retained = expected.calculation.p02.retained
    closed(report, placement.COMMON_KEYS | {"limits", "preceding_receipts", "browser_runtime"}, "Unknown P04 HTTP receipt envelope")
    require(report["format"] == "cetech-opening-http-promise-handoff-v1" and report["status"] == "PASS", "Incomplete P04 native customer routes")
    for key in ("source_head", "candidate_head", "source_tree"):
        require(report[key] == getattr(retained, key), "P04 HTTP immutable identity differs")
    require(report["installed_php_sources"] == retained.sources and report["installed_php_sources_hash"] == placement.canonical_hash(retained.sources), "P04 HTTP production bytes differ")
    require(report["limits"] == expected.http_scope["LIMITS"], "P04 HTTP scope differs")
    environment = report["environment"]
    closed(environment, {"php", "wordpress", "woocommerce", "database_version", "hpos", "schema_before", "context", "background_requests"}, "Unknown P04 HTTP runtime")
    for key, value in {"php": "8.5.11", "wordpress": "7.1.2", "woocommerce": "11.1.2", "hpos": "yes", "schema_before": "11", "context": expected.http_scope["CONTEXT"], "background_requests": expected.http_scope["BACKGROUND"]}.items():
        require(environment[key] == value, "Pinned P04 HTTP runtime differs")
    require(isinstance(environment["database_version"], str) and re.fullmatch(r"11\.4\.13-MariaDB(?:-[A-Za-z0-9_.]+)*", environment["database_version"]), "Pinned P04 HTTP database differs")
    closed(report["browser_runtime"], {"playwright", "chromium"}, "Unknown P04 browser runtime")
    require(report["browser_runtime"] == {"playwright": "1.58.2", "chromium": "145.0.7632.6"}, "Pinned real P04 browser runtime differs")
    closed(report["preceding_receipts"], kinds, "Unknown P04 HTTP preceding receipts")
    for kind, prior in zip(kinds, list(prior_reports) + list(native_reports)):
        link = report["preceding_receipts"][kind]
        closed(link, {"sha256", "cases"}, "Unknown P04 HTTP prior fact")
        require(prior["status"] == "PASS" and all(case["status"] == "PASS" for case in prior["cases"]), "P04 HTTP cannot compensate for prior failure")
        require(placement.sha256(hashes[kind]) and link["sha256"] == hashes[kind] and type(link["cases"]) is int and link["cases"] == len(prior["cases"]), "P04 HTTP prior raw bytes or inventory differ")
    require(isinstance(report["cases"], list) and len(report["cases"]) == len(expected.http_cases), "Incomplete P04 HTTP source inventory")
    for observed, (case_id, fields) in zip(report["cases"], expected.http_cases):
        closed(observed, {"id", "status", "evidence"}, "Unknown P04 HTTP case")
        require(observed["id"] == case_id and observed["status"] == "PASS", "Failed, duplicate or reordered P04 HTTP case")
        closed(observed["evidence"], fields, "Unknown P04 HTTP observation")
        require(all(value is True for value in observed["evidence"].values()), "P04 HTTP observation is not an exact passing boolean")
    return len(expected.http_cases)


def verify_native_file_paths(paths):
    """Strict seven-file preflight before new HTTP effects; no final HTTP dependency."""
    require(len(paths) == 7, "Separate P04 HPOS/CPT and five original preceding receipts are required")
    reports = [load(path) for path in paths[:2]]
    p03 = load(paths[2]); p02 = load(paths[3]); retained = [load(path) for path in paths[4:7]]
    raw_paths = dict(zip(("p03", "p02", "native", "cpt", "http"), paths[2:7]))
    hashes = {kind: hashlib.sha256(Path(path).read_bytes()).hexdigest() for kind, path in raw_paths.items()}
    expected = authority()
    count = verify(reports, p03, p02, retained, hashes, expected)
    return count, reports, p03, p02, retained, hashes, expected


def main(argv):
    if argv and argv[0] == "--native-only":
        count, *_ = verify_native_file_paths(argv[1:])
        print(f"promise_handoff_native_preflight=PASS hpos={count} fresh_cpt={count} p03=49 p02=19 retained=495/20/143")
        return
    require(len(argv) == 8, "P04 HPOS/CPT, P03, P02, three retained primary receipts and separate P04 HTTP/browser receipt are required")
    count, reports, p03, p02, retained, hashes, expected = verify_native_file_paths(argv[:7])
    hashes.update({kind: hashlib.sha256(Path(path).read_bytes()).hexdigest() for kind, path in zip(("p04_hpos", "p04_cpt"), argv[:2])})
    http_count = verify_http(load(argv[7]), reports, list(retained) + [p02, p03], hashes, expected)
    print(f"promise_handoff_receipts=PASS hpos={count} fresh_cpt={count} http_browser={http_count} p03=49 p02=19 retained=495/20/143")


if __name__ == "__main__":
    try:
        main(sys.argv[1:])
    except (RuntimeError, ValueError, TypeError, KeyError, OSError, subprocess.SubprocessError):
        print("promise_handoff_receipts=FAIL", file=sys.stderr)
        sys.exit(1)
