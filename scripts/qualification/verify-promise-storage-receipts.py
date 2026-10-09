#!/usr/bin/env python3
"""P02 primary receipt: closed source-derived cases plus unchanged retained qualification.

No private policy bodies or SQL rows enter this protocol. PASS observations are
actual booleans, and source/runtime authority comes from immutable committed Git.
"""
from dataclasses import dataclass
import hashlib
import importlib.util
from pathlib import Path
import re
import subprocess
import sys
sys.dont_write_bytecode = True

SOURCE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("promise_retained_verifier", SOURCE / "verify-quote-placement-receipts.py")
placement = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = placement
spec.loader.exec_module(placement)
require, closed, load = placement.require, placement.closed, placement.load
LIMITS = [
    "Native wpdb and owned SQL only; no HTTP/customer promise calculation or receipt mounting.",
    "Explicit internal fixture adopter; no settings/import/job/editor or checkout adoption.",
    "Publication refusals are simulated WordPress hooks; no crash/atomicity claim.",
    "Historical quote seed uses the existing synthetic profile; no native order or payment claim.",
]
CONTEXT = "WP-CLI native P02 storage; explicit unmounted internal authorizer and isolated owned SQL namespace"
BACKGROUND = "WP Cron disabled; Action Scheduler async request runner suppressed in this process"
PRODUCERS = ("opening-promise-storage.php", "opening-promise-storage-support.php", "opening-promise-storage-reader.php", "opening-promise-storage-runner.php", "verify-promise-storage-receipts.py", "verify-quote-placement-receipts.py")


def protocol():
    text = (SOURCE / "opening-promise-storage.php").read_text(encoding="utf-8")
    result = []
    for match in re.finditer(r"\$check\(\s*'NATIVE-W2P02-", text):
        args = placement.php_parts(placement.php_block(text, match.start() + len("$check")), "(", ")")
        require(len(args) == 3 and re.fullmatch(r"'NATIVE-W2P02-[A-Z0-9-]+'", args[0]), "Ambiguous P02 source case")
        require(args[2].startswith("[") and args[2].endswith("]"), "Unknown P02 evidence producer")
        fields = []
        for entry in placement.php_parts(args[2], "[", "]"):
            field = re.fullmatch(r"'([a-z0-9_]+)'\s*=>\s*.+", entry, re.S)
            require(field is not None and field[1] not in fields, "Ambiguous P02 evidence field")
            fields.append(field[1])
        require(fields, "Empty P02 evidence")
        result.append((args[0][1:-1], tuple(fields)))
    require(0 < len(result) <= 64 and len({item[0] for item in result}) == len(result), "Ambiguous P02 inventory")
    return tuple(result)


@dataclass(frozen=True)
class Authority:
    retained: object
    cases: tuple


def authority():
    retained = placement.authority()
    for name in PRODUCERS:
        require((SOURCE / name).read_bytes() == placement.git("show", retained.source_head + ":scripts/qualification/" + name), "Uncommitted P02 producer authority")
    schema = placement.git("show", retained.source_head + ":src/Core/Versioning/SchemaVersion.php").decode()
    require(len(re.findall(r"public const TARGET\s*=\s*'10'\s*;", schema)) == 1, "P02 schema authority differs")
    return Authority(retained, protocol())


def verify(report, retained_reports, retained_hashes, expected):
    # An additional passing storage module never repairs a failing retained checkout receipt.
    placement.verify_reports(*retained_reports, expected.retained)
    keys = placement.COMMON_KEYS | {"limits", "retained_receipts"}
    closed(report, keys, "Unknown P02 receipt envelope")
    require(report["format"] == "cetech-opening-promise-storage-v1" and report["status"] == "PASS", "Incomplete P02 qualification")
    for key in ("source_head", "candidate_head", "source_tree"):
        require(report[key] == getattr(expected.retained, key), "P02 immutable identity differs")
    require(report["installed_php_sources"] == expected.retained.sources and report["installed_php_sources_hash"] == placement.canonical_hash(expected.retained.sources), "P02 installed production bytes differ")
    require(report["limits"] == LIMITS, "P02 scope differs")
    environment = report["environment"]
    closed(environment, {"php", "wordpress", "woocommerce", "database_version", "hpos", "schema_before", "context", "background_requests"}, "Unknown P02 runtime")
    for key, value in {"php": "8.5.11", "wordpress": "7.1.2", "woocommerce": "11.1.2", "hpos": "yes", "schema_before": "10", "context": CONTEXT, "background_requests": BACKGROUND}.items():
        require(environment[key] == value, "Pinned P02 runtime differs")
    require(isinstance(environment["database_version"], str) and re.fullmatch(r"11\.4\.13-MariaDB(?:-[A-Za-z0-9_.]+)*", environment["database_version"]), "Pinned P02 database differs")
    closed(report["retained_receipts"], {"native", "cpt", "http"}, "Unknown preceding receipts")
    for index, kind in enumerate(("native", "cpt", "http")):
        closed(report["retained_receipts"][kind], {"sha256", "cases"}, "Unknown preceding receipt linkage")
        require(report["retained_receipts"][kind]["sha256"] == retained_hashes[index] and placement.sha256(retained_hashes[index]), "Preceding receipt bytes differ")
        count = report["retained_receipts"][kind]["cases"]
        require(type(count) is int and count == len(retained_reports[index]["cases"]), "Preceding receipt inventory differs")
    cases = report["cases"]
    require(isinstance(cases, list) and len(cases) == len(expected.cases), "Incomplete or extra P02 inventory")
    for observed, (case_id, fields) in zip(cases, expected.cases):
        closed(observed, {"id", "status", "evidence"}, "Unknown P02 case")
        require(observed["id"] == case_id and observed["status"] == "PASS", "Reordered or failed P02 case")
        closed(observed["evidence"], fields, "Unknown P02 observations")
        require(all(value is True for value in observed["evidence"].values()), "P02 observation is not an exact passing boolean")
    return len(cases)


def main(argv):
    require(len(argv) == 4, "P02 and three preceding primary receipts are required")
    retained = [load(path) for path in argv[1:]]
    hashes = [hashlib.sha256(Path(path).read_bytes()).hexdigest() for path in argv[1:]]
    count = verify(load(argv[0]), retained, hashes, authority())
    print(f"promise_storage_receipts=PASS native={count} retained=495/20/143")


if __name__ == "__main__":
    try:
        main(sys.argv[1:])
    except (RuntimeError, ValueError, TypeError, KeyError, OSError, subprocess.SubprocessError):
        print("promise_storage_receipts=FAIL", file=sys.stderr)
        sys.exit(1)
