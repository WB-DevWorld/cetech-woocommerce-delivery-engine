#!/usr/bin/env python3
"""Exact source-derived P06 lifecycle receipts, complete retained chain and immutable packages."""
from dataclasses import dataclass
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import subprocess
import sys
import zipfile
sys.dont_write_bytecode = True
HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("p06_lifecycle_p05", HERE / "verify-opening-promise-native-configuration.py")
p05 = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = p05
spec.loader.exec_module(p05)
placement = p05.placement
require, closed, load = placement.require, placement.closed, placement.load
P04_HEAD = "3b57ffc53b64f485aa9ad30e2d9e5d424afc3468"
P05_HEAD = "8a7a7815b249c7bc117a64ffcc36db1dd8f73ee0"
CONTEXT = "Fresh native P06 sealed promise and original/current shipment operational lifecycle; complete marked disposable physical history"
BACKGROUND = "WP Cron disabled; Action Scheduler async request runner suppressed in this process"
LIMITS = [
    "Disposable native HPOS/CPT qualification only; all eleven retained primary receipts remain separately mandatory.",
    "The sealed fixture is authored by current candidate; code-only exact accepted P05 to current package transition preserves it. Schema11 remains11; no reverse migration.",
    "Supported P04 rollback is an isolated forward historical reader with exact production autoload and old plugin writers unmounted; compatible base storage readiness is observed, P05 shipment capabilities are absent, and P05/unknown bytes are preserved.",
    "No live activation, deployment, release, target-stack cache/payment/pilot certification or destructive retention grant."
]
KINDS = ("native", "cpt", "http", "p02", "p03", "p04_hpos", "p04_cpt", "p04_http", "p05_hpos", "p05_cpt", "p05_http")
COUNTS = (495, 20, 143, 19, 49, 33, 33, 14, 27, 27, 19)
PRODUCERS = (
    "scripts/qualification/opening-promise-operational-lifecycle.php",
    "scripts/qualification/opening-promise-operational-lifecycle-support.php",
    "scripts/qualification/opening-promise-operational-lifecycle-reader.php",
    "scripts/qualification/opening-promise-operational-lifecycle-standalone.php",
    "scripts/qualification/opening-promise-operational-lifecycle-runner.php",
    "scripts/ci-promise-operational-lifecycle-hpos-off.sh",
    "scripts/qualification/verify-promise-operational-lifecycle-receipts.py",
    "scripts/qualification/build-promise-qualification-package.py",
    "scripts/qualification/extract-promise-qualification-package.py",
    "scripts/ci-promise-qualification-packages.sh",
)


def protocol():
    text = (HERE / "opening-promise-operational-lifecycle.php").read_text()
    result = []
    for match in re.finditer(r"\$check\(\s*'NATIVE-W2P06-", text):
        args = placement.php_parts(placement.php_block(text, match.start() + len("$check")), "(", ")")
        require(len(args) == 3 and re.fullmatch(r"'NATIVE-W2P06-[A-Z0-9-]+'", args[0]), "Unknown P06 source case")
        fields = []
        for entry in placement.php_parts(args[2], "[", "]"):
            field = re.fullmatch(r"'([a-z0-9_]+)'\s*=>\s*.+", entry, re.S)
            require(field is not None and field[1] not in fields, "Ambiguous P06 source field")
            fields.append(field[1])
        require(fields, "Empty P06 source field")
        result.append((args[0][1:-1], tuple(fields)))
    require(result and len(result) <= 64 and len({item[0] for item in result}) == len(result), "Ambiguous P06 source inventory")
    require(result[-1][0] == "NATIVE-W2P06-TRACKED-OPERATIONAL-LIFECYCLE-CLEANUP", "P06 cleanup inventory missing")
    return tuple(result)


def git_php_map(head):
    paths = placement.git("ls-tree", "-r", "--name-only", head).decode().splitlines()
    return {path: hashlib.sha256(placement.git("show", head + ":" + path)).hexdigest()
            for path in paths if path.endswith(".php") and (path.startswith(("src/", "database/")) or path in ("cetech-woocommerce-delivery-engine.php", "uninstall.php"))}


def package_summary(path, head):
    """Original ZIP + adjacent report + immutable Git source via root package verifier."""
    path = Path(path)
    metadata = path.with_suffix(".json")
    spec = importlib.util.spec_from_file_location("p06_lifecycle_package", HERE / "extract-promise-qualification-package.py")
    checker = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(checker)
    report = checker.verify(path, metadata, HERE.parent.parent, head)
    return {"source_head": head, "source_tree": report["source_tree"], "zip_sha256": report["zip_sha256"], "package_report_sha256": hashlib.sha256(metadata.read_bytes()).hexdigest(), "production_php_sources": report["production_php_sources"], "production_php_sources_hash": report["production_php_sources_hash"]}


@dataclass(frozen=True)
class Authority:
    p05: object
    cases: tuple
    packages: dict
    counts: tuple = COUNTS


def authority(package_paths):
    prior = p05.authority()
    retained = prior.calculation.calculation.p02.retained
    for filename in PRODUCERS:
        require((HERE.parent.parent / filename).read_bytes() == placement.git("show", retained.source_head + ":" + filename), "Uncommitted P06 producer authority")
    packages = {kind: package_summary(path, head) for kind, path, head in zip(("current", "previous", "reader"), package_paths, (retained.source_head, P05_HEAD, P04_HEAD))}
    require(len(packages) == 3 and packages["current"]["production_php_sources"] == retained.sources, "P06 current package authority differs")
    return Authority(prior, protocol(), packages)


def verify(reports, priors, hashes, expected):
    require(isinstance(priors, list) and len(priors) == 11 and isinstance(hashes, dict) and set(hashes) == set(KINDS), "Unknown P06 prior authority")
    p04 = p05.calculation
    p04.verify(priors[5:7], priors[4], priors[3], priors[:3], {kind: hashes[kind] for kind in KINDS[:5]}, expected.p05.calculation)
    p04.verify_http(priors[7], priors[5:7], priors[:5], {kind: hashes[kind] for kind in KINDS[:7]}, expected.p05.calculation)
    p05.verify(priors[8:10], priors[:8], {kind: hashes[kind] for kind in KINDS[:8]}, expected.p05)
    p05.verify_http(priors[10], priors[8:10], priors[:8], {kind: hashes[kind] for kind in KINDS[:10]}, expected.p05)
    retained = expected.p05.calculation.calculation.p02.retained
    require(isinstance(reports, list) and len(reports) == 2, "Separate P06 HPOS and CPT receipts required")
    for report, mode in zip(reports, ("yes", "no")):
        closed(report, placement.COMMON_KEYS | {"limits", "preceding_receipts", "package_inputs"}, "Unknown P06 lifecycle envelope")
        require(report["format"] == "cetech-opening-promise-operational-lifecycle-v1" and report["status"] == "PASS", "Incomplete P06 lifecycle")
        for key in ("source_head", "candidate_head", "source_tree"):
            require(report[key] == getattr(retained, key), "P06 source identity differs")
        require(report["installed_php_sources"] == retained.sources and report["installed_php_sources_hash"] == placement.canonical_hash(retained.sources), "P06 installed production differs")
        require(report["limits"] == LIMITS and report["package_inputs"] == expected.packages, "P06 scope or original package authority differs")
        environment = report["environment"]
        closed(environment, {"php", "wordpress", "woocommerce", "database_version", "hpos", "schema_before", "context", "background_requests"}, "Unknown P06 runtime")
        for key, value in {"php": "8.5.11", "wordpress": "7.1.2", "woocommerce": "11.1.2", "hpos": mode, "schema_before": "11", "context": CONTEXT, "background_requests": BACKGROUND}.items():
            require(environment[key] == value, "Pinned P06 native runtime differs")
        require(isinstance(environment["database_version"], str) and re.fullmatch(r"11\.4\.13-MariaDB(?:-[A-Za-z0-9_.]+)*", environment["database_version"]), "Pinned P06 database differs")
        closed(report["preceding_receipts"], KINDS, "Unknown P06 preceding receipts")
        for kind, prior, count in zip(KINDS, priors, expected.counts):
            link = report["preceding_receipts"][kind]
            closed(link, {"sha256", "cases"}, "Unknown P06 preceding raw bytes")
            require(prior["status"] == "PASS" and len(prior["cases"]) == count and link["sha256"] == hashes[kind] and placement.sha256(hashes[kind]) and type(link["cases"]) is int and link["cases"] == count, "P06 preceding receipt raw bytes/count differ")
        require(isinstance(report["cases"], list) and len(report["cases"]) == len(expected.cases), "Missing/extra P06 lifecycle case")
        for case, (case_id, fields) in zip(report["cases"], expected.cases):
            closed(case, {"id", "status", "evidence"}, "Unknown P06 case envelope")
            require(case["id"] == case_id and case["status"] == "PASS", "P06 duplicate/reordered/failed case")
            closed(case["evidence"], fields, "Unknown P06 observation")
            require(all(value is True for value in case["evidence"].values()), "P06 nonboolean/failing observation")
    return len(expected.cases)


def main(argv):
    require(len(argv) == 16, "P06 HPOS/CPT, eleven priors and three original ZIPs with adjacent reports are required")
    reports = [load(path) for path in argv[:2]]
    priors = [load(path) for path in argv[2:13]]
    hashes = {kind: hashlib.sha256(Path(path).read_bytes()).hexdigest() for kind, path in zip(KINDS, argv[2:13])}
    count = verify(reports, priors, hashes, authority(argv[13:]))
    print(f"promise_operational_lifecycle_receipts=PASS hpos={count} fresh_cpt={count} retained=879")


if __name__ == "__main__":
    try:
        main(sys.argv[1:])
    except (RuntimeError, ValueError, TypeError, KeyError, OSError, subprocess.SubprocessError, zipfile.BadZipFile):
        print("promise_operational_lifecycle_receipts=FAIL", file=sys.stderr)
        sys.exit(1)
