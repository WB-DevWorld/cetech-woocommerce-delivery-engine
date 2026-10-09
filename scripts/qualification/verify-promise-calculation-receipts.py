#!/usr/bin/env python3
"""Strict separate pure P03 receipt; never substitutes for retained/native P02 proof."""
from dataclasses import dataclass
import hashlib
import importlib.util
from pathlib import Path
import re
import subprocess
import sys
sys.dont_write_bytecode = True

SOURCE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("promise_storage_for_calculation", SOURCE / "verify-promise-storage-receipts.py")
storage = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = storage
spec.loader.exec_module(storage)
placement = storage.placement
require, closed, load = placement.require, placement.closed, placement.load
LIMITS = [
    "Pure manual captured-input vectors; no native clock/event/admission or shopper-flow claim.",
    "No WordPress, SQL owner, network, capacity observer, reservation or payment is invoked.",
    "Retained 495/20/143 and P02 19 primary receipts must pass independently on the same installed source.",
]
CONTEXT = "Pure CLI P03 calculation with installed production-only autoload; no WordPress bootstrap"
RUNTIME_ORIGIN = "Independent explicit qualification fixture context; consistency only, no native collector or timezone-file attestation"
PRODUCERS = ("promise-calculation-cases.php", "promise-calculation-vectors.php", "opening-promise-calculation-runner.php", "verify-promise-calculation-receipts.py", "verify-promise-storage-receipts.py", "verify-quote-placement-receipts.py")


def protocol():
    text = (SOURCE / "promise-calculation-cases.php").read_text(encoding="utf-8")
    result = []
    for match in re.finditer(r"\$check\(\s*'PURE-W2P03-", text):
        args = placement.php_parts(placement.php_block(text, match.start() + len("$check")), "(", ")")
        require(len(args) == 4 and re.fullmatch(r"'PURE-W2P03-[A-Z0-9-]+'", args[0]) and args[3] in ("'calculation'", "'calendar_math'", "'cart'"), "Ambiguous P03 source case or witness kind")
        require(args[2].startswith("[") and args[2].endswith("]"), "Unknown P03 evidence source")
        fields = []
        for entry in placement.php_parts(args[2], "[", "]"):
            field = re.fullmatch(r"'([a-z0-9_]+)'\s*=>\s*.+", entry, re.S)
            require(field is not None and field[1] not in fields, "Ambiguous P03 observation")
            fields.append(field[1])
        require(fields, "Empty P03 observation")
        result.append((args[0][1:-1], tuple(fields), args[3][1:-1]))
    require(0 < len(result) <= 96 and len({item[0] for item in result}) == len(result), "Ambiguous P03 source inventory")
    return tuple(result)


@dataclass(frozen=True)
class Authority:
    p02: object
    cases: tuple


def authority():
    p02 = storage.authority()
    for name in PRODUCERS:
        require((SOURCE / name).read_bytes() == placement.git("show", p02.retained.source_head + ":scripts/qualification/" + name), "Uncommitted P03 producer authority")
    return Authority(p02, protocol())


def verify(report, p02_report, retained_reports, hashes, expected):
    require(isinstance(hashes, dict) and set(hashes) == {"native", "cpt", "http", "p02"}, "Unknown preceding raw-byte hashes")
    storage.verify(p02_report, retained_reports, [hashes[kind] for kind in ("native", "cpt", "http")], expected.p02)
    closed(report, placement.COMMON_KEYS | {"limits", "preceding_receipts"}, "Unknown P03 receipt envelope")
    require(report["format"] == "cetech-opening-promise-calculation-v1" and report["status"] == "PASS", "Incomplete P03 pure qualification")
    retained = expected.p02.retained
    for key in ("source_head", "candidate_head", "source_tree"):
        require(report[key] == getattr(retained, key), "P03 immutable execution identity differs")
    require(report["installed_php_sources"] == retained.sources and report["installed_php_sources_hash"] == placement.canonical_hash(retained.sources), "P03 installed production source differs")
    require(report["limits"] == LIMITS, "P03 pure scope differs")
    environment = report["environment"]
    closed(environment, {"php", "timezone_data_version", "context", "runtime_origin"}, "Unknown P03 runtime")
    require(environment["php"] == "8.5.11" and environment["context"] == CONTEXT and environment["runtime_origin"] == RUNTIME_ORIGIN, "Pinned P03 runtime or explicit fixture origin differs")
    require(isinstance(environment["timezone_data_version"], str) and 0 < len(environment["timezone_data_version"].encode()) <= 128 and re.fullmatch(r"[A-Za-z0-9._+-]+", environment["timezone_data_version"]), "P03 timezone-data label is unknown")
    closed(report["preceding_receipts"], {"native", "cpt", "http", "p02"}, "Unknown P03 prerequisite linkage")
    for index, kind in enumerate(("native", "cpt", "http", "p02")):
        prior = retained_reports[index] if index < 3 else p02_report
        link = report["preceding_receipts"][kind]
        closed(link, {"sha256", "cases"}, "Unknown P03 prerequisite fact")
        require(placement.sha256(hashes[kind]) and link["sha256"] == hashes[kind], "P03 prerequisite raw bytes differ")
        require(type(link["cases"]) is int and link["cases"] == len(prior["cases"]), "P03 prerequisite inventory differs")
    cases = report["cases"]
    require(isinstance(cases, list) and len(cases) == len(expected.cases), "Missing or additional P03 pure cases")
    for observed, (case_id, fields, witness_kind) in zip(cases, expected.cases):
        closed(observed, {"id", "status", "evidence", "fingerprints"}, "Unknown P03 case")
        require(observed["id"] == case_id and observed["status"] == "PASS", "Failed or reordered P03 case")
        closed(observed["evidence"], fields, "Unknown P03 observation")
        require(all(value is True for value in observed["evidence"].values()), "P03 observation is not an exact passing boolean")
        fingerprints = observed["fingerprints"]
        closed(fingerprints, {"kind", "input_digest", "result_digest", "calendar_digests", "steps"}, "Unknown P03 private fingerprint projection")
        require(fingerprints["kind"] == witness_kind, "P03 witness subject differs from exact source case")
        require(placement.sha256(fingerprints["input_digest"]) and placement.sha256(fingerprints["result_digest"]), "Invalid P03 input/result fingerprint")
        calendars = fingerprints["calendar_digests"]
        require(isinstance(calendars, list) and len(calendars) <= 16 and all(placement.sha256(value) for value in calendars), "Invalid P03 calendar fingerprints")
        require(type(fingerprints["steps"]) is int and 0 <= fingerprints["steps"] <= 100000, "Invalid P03 bounded-work witness")
    return len(cases)


def main(argv):
    require(len(argv) == 5, "P03, P02 and three retained primary receipts are required")
    p02 = load(argv[1]); retained = [load(path) for path in argv[2:]]
    paths = dict(zip(("p02", "native", "cpt", "http"), argv[1:]))
    hashes = {kind: hashlib.sha256(Path(path).read_bytes()).hexdigest() for kind, path in paths.items()}
    count = verify(load(argv[0]), p02, retained, hashes, authority())
    print(f"promise_calculation_receipts=PASS pure={count} p02=19 retained=495/20/143")


if __name__ == "__main__":
    try:
        main(sys.argv[1:])
    except (RuntimeError, ValueError, TypeError, KeyError, OSError, subprocess.SubprocessError):
        print("promise_calculation_receipts=FAIL", file=sys.stderr)
        sys.exit(1)
