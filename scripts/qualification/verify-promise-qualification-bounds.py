#!/usr/bin/env python3
"""Closed P06 pure/native measured receipts, after independently strict retained P05 verification."""
from dataclasses import dataclass
import hashlib
import importlib.util
from pathlib import Path
import re
import sys
sys.dont_write_bytecode = True
HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("p06_bounds_retained_p05", HERE / "verify-opening-promise-native-configuration.py")
P05 = importlib.util.module_from_spec(spec); sys.modules[spec.name] = P05; spec.loader.exec_module(P05)
P = P05.placement
require, closed, load = P.require, P.closed, P.load
PURE_CONTEXT = "Pure CLI P06 measured boundary vectors using extracted production-only autoload; no WordPress, SQL or native cart claim"
NATIVE_CONTEXT = "Fresh WP-CLI P06 actual loaded-cart boundaries and captured native source-owner query observation"
BACKGROUND = "WP Cron disabled; Action Scheduler async request runner suppressed in this process"
PURE_LIMITS = ["Pure manual captured values and codec work observations only.", "Actual native cart and owned SQL query/callback observations have distinct evidence.", "The 200-group input bound does not promise a winning oversized serialized packet.", "No network, capacity reservation, mounted capacity adapter or live operational acceptance."]
NATIVE_LIMITS = ["Actual marked disposable Woo loaded-cart boundaries; cloned loaded members, no successful full 200-group placement claim.", "Actual native capture and final source-owner fences; WordPress HTTP/query and authority callback observations are limited to these two operations.", "Same Day and Standard are authorized hypothetical native previews; no registered shopper alternative-service breadth claim.", "Default adoption and tracked sources are restored; no live operational acceptance, mounted capacity or provider payment."]
PURE_ORDER = tuple("PURE-W2P06-" + suffix for suffix in (
    "GRAPH-EXACT-NODES-EDGES", "GRAPH-NODES-PLUS-ONE", "GRAPH-EDGES-PLUS-ONE", "RECORD-BYTES-EXACT", "RECORD-BYTES-PLUS-ONE", "PACKET-BYTES-EXACT", "PACKET-BYTES-PLUS-ONE", "CART-STEPS-EXACT", "CART-STEPS-PLUS-ONE", "HORIZON-EXACT", "HORIZON-PLUS-ONE", "CALENDAR-INTERVALS-EXACT-PLUS-ONE", "CALENDAR-EXCEPTIONS-EXACT-PLUS-ONE", "CALENDAR-REFERENCES-EXACT", "CALENDAR-REFERENCES-PLUS-ONE", "CART-GROUPS-EXACT-PACKET-REFUSAL", "CART-GROUPS-PLUS-ONE", "ACTUAL-CART-SHARED-STEP-EXHAUSTION"))
PRODUCERS = tuple("scripts/qualification/" + name for name in (
    "promise-qualification-bounds-cases.php", "opening-promise-qualification-bounds-runner.php", "opening-promise-qualification-native-bounds.php", "opening-promise-qualification-native-bounds-runner.php", "promise-qualification-native-source-observer.php", "verify-promise-qualification-bounds.py", "test-verify-promise-qualification-bounds.py")) + ("tests/Integration/ServicePromise/PromiseQualificationBoundsRealDatabaseTest.php", "tests/Unit/ServicePromise/Qualification/PromiseQualificationBoundsProtocolTest.php", "scripts/ci-promise-qualification-bounds-hpos-off.sh", "scripts/ci-wordpress-php85-smoke.sh")


def protocol(filename, prefix):
    text = (HERE / filename).read_text(encoding="utf-8"); result = {}
    for match in re.finditer(r"\$check\(\s*'" + prefix, text):
        args = P.php_parts(P.php_block(text, match.start() + len("$check")), "(", ")")
        require(len(args) == 4 and re.fullmatch(r"'" + prefix + r"[A-Z0-9-]+'", args[0]), "Ambiguous P06 numeric source case")
        fields = []
        for array in args[2:]:
            require(array.startswith("[") and array.endswith("]"), "P06 source observation is not a literal closed map")
            names = []
            for entry in P.php_parts(array, "[", "]"):
                item = re.fullmatch(r"'([a-z0-9_]+)'\s*=>\s*.+", entry, re.S)
                require(item is not None and item[1] not in names, "Ambiguous P06 source observation")
                names.append(item[1])
            require(names, "Empty P06 source observation"); fields.append(tuple(names))
        case_id = args[0][1:-1]; require(case_id not in result, "Duplicated P06 source ID"); result[case_id] = tuple(fields)
    require(0 < len(result) <= 32, "Unknown P06 source inventory")
    if prefix == "PURE-W2P06-":
        require(set(result) == set(PURE_ORDER), "P06 exact bounded vector inventory changed without protocol review")
        return tuple((key, *result[key]) for key in PURE_ORDER)
    require(len(result) == 7 and list(result)[-1] == "NATIVE-W2P06-TRACKED-NATIVE-BOUNDS-CLEANUP", "P06 native inventory or cleanup differs")
    return tuple((key, *fields) for key, fields in result.items())


@dataclass(frozen=True)
class Authority:
    retained: object
    pure: tuple
    native: tuple


def authority():
    prior = P05.authority(); retained = prior.calculation.calculation.p02.retained
    for filename in PRODUCERS:
        require((HERE.parent.parent / filename).read_bytes() == P.git("show", retained.source_head + ":" + filename), "Uncommitted P06 bounded-proof producer")
    return Authority(retained, protocol("promise-qualification-bounds-cases.php", "PURE-W2P06-"), protocol("opening-promise-qualification-native-bounds.php", "NATIVE-W2P06-"))


def numeric_rules(case_id, observed):
    suffix = case_id.removeprefix("PURE-W2P06-")
    exact = {
        "GRAPH-EXACT-NODES-EDGES": {"nodes": 16, "edges": 32, "steps": 293},
        "GRAPH-NODES-PLUS-ONE": {"nodes": 17, "limit": 16}, "GRAPH-EDGES-PLUS-ONE": {"edges": 33, "limit": 32},
        "RECORD-BYTES-EXACT": {"encoded_bytes": 32768, "limit": 32768}, "RECORD-BYTES-PLUS-ONE": {"attempted_bytes": 32769, "limit": 32768},
        "PACKET-BYTES-EXACT": {"encoded_bytes": 65536, "limit": 65536}, "PACKET-BYTES-PLUS-ONE": {"attempted_bytes": 65537, "limit": 65536},
        "CART-STEPS-EXACT": {"used_steps": 100000, "limit": 100000}, "CART-STEPS-PLUS-ONE": {"attempted_steps": 100001, "used_steps": 100000},
        "HORIZON-EXACT": {"days": 730, "limit": 730, "steps": 35}, "HORIZON-PLUS-ONE": {"days": 731, "limit": 730, "steps": 30},
        "CALENDAR-INTERVALS-EXACT-PLUS-ONE": {"accepted_intervals": 8, "attempted_intervals": 9}, "CALENDAR-EXCEPTIONS-EXACT-PLUS-ONE": {"accepted_dates": 366, "attempted_dates": 367},
        "CALENDAR-REFERENCES-EXACT": {"unique_calendars": 16, "steps": 225}, "CALENDAR-REFERENCES-PLUS-ONE": {"attempted_calendars": 17, "limit": 16},
        "CART-GROUPS-EXACT-PACKET-REFUSAL": {"inputs": 200, "outputs": 200, "steps": 4000}, "CART-GROUPS-PLUS-ONE": {"attempted_inputs": 201, "last_completed_steps": 4000},
        "ACTUAL-CART-SHARED-STEP-EXHAUSTION": {"inputs": 30, "used_steps": 100000, "limit": 100000},
    }
    for key, value in exact.get(suffix, {}).items(): require(observed[key] == value, "P06 observed exact/plus-one work differs")
    if suffix == "GRAPH-EXACT-NODES-EDGES": require(0 < observed["input_bytes"] <= 32768, "P06 complete graph carrier exceeds record budget")
    if "LOADED-LINES-200-" in case_id or "LOADED-LINES-201-" in case_id:
        count = 201 if "-201-" in case_id else 200
        require(observed == {"loaded_lines": count, "wordpress_queries": 0, "source_connections": 0}, "P06 native loaded bound or early refusal differs")
    if case_id.endswith("REAL-LOADED-CART-CONTROL") or case_id.endswith("LOADED-CART-RESTORE-POSITIVE-CONTROL"):
        require(0 < observed["loaded_lines"] < 200, "P06 native positive control unavailable")
    if case_id.endswith("ACTUAL-SOURCE-OWNER-QUERY-CALLBACK-FENCE"):
        require(0 < observed["native_groups"] < 200 and observed["connections"] == 2 and 0 < observed["capture_source_selects"] <= 2000 and observed["capture_source_selects"] == observed["final_source_selects"] and observed["authority_callbacks"] > 0, "P06 native source owner count or deduplicated trace differs")
        require(all(observed[key] == 0 for key in ("callbacks_during_owned_sql", "owned_network_requests", "owned_wordpress_queries")), "P06 forbidden owned callback observed")
    if case_id.endswith("IMPOSSIBLE-SAME-DAY-HONEST-ALTERNATIVE"):
        require(observed["duration_minutes"] == 1500 and 0 < observed["selected_methods"] <= 200, "P06 native hypothetical comparison or prior selection missing")
    if case_id.endswith("TRACKED-NATIVE-BOUNDS-CLEANUP"): require(0 < observed["cleanup_checks"] <= 32, "P06 exact tracked cleanup absent")


def verify(reports, priors, hashes, expected):
    require(isinstance(reports, list) and len(reports) == 3 and len(priors) == 3 and set(hashes) == {"p05_hpos", "p05_cpt", "p05_http"}, "P06 requires separate pure/HPOS/CPT and P05 authority")
    retained = expected.retained
    for index, report in enumerate(reports):
        closed(report, P.COMMON_KEYS | {"limits", "preceding_receipts"}, "Unknown P06 bounded receipt envelope")
        pure = index == 0
        require(report["format"] == ("cetech-opening-promise-qualification-bounds-v1" if pure else "cetech-opening-promise-qualification-native-bounds-v1") and report["status"] == "PASS", "P06 bounded receipt incomplete")
        for key in ("source_head", "candidate_head", "source_tree"): require(report[key] == getattr(retained, key), "P06 immutable execution identity differs")
        require(report["installed_php_sources"] == retained.sources and report["installed_php_sources_hash"] == P.canonical_hash(retained.sources), "P06 extracted/native source map differs")
        require(report["limits"] == (PURE_LIMITS if pure else NATIVE_LIMITS), "P06 bounded proof scope differs")
        env = report["environment"]
        if pure:
            closed(env, {"php", "timezone_data_version", "context"}, "Unknown P06 pure environment")
            require(env["php"] == "8.5.11" and env["context"] == PURE_CONTEXT and isinstance(env["timezone_data_version"], str) and re.fullmatch(r"[A-Za-z0-9._+-]{1,128}", env["timezone_data_version"]), "P06 pinned pure runtime differs")
        else:
            closed(env, {"php", "wordpress", "woocommerce", "database_version", "hpos", "schema_before", "context", "background_requests"}, "Unknown P06 native environment")
            for key, value in {"php": "8.5.11", "wordpress": "7.1.2", "woocommerce": "11.1.2", "hpos": "yes" if index == 1 else "no", "schema_before": "11", "context": NATIVE_CONTEXT, "background_requests": BACKGROUND}.items(): require(env[key] == value, "P06 pinned native runtime or mode differs")
            require(isinstance(env["database_version"], str) and re.fullmatch(r"11\.4\.13-MariaDB(?:-[A-Za-z0-9_.]+)*", env["database_version"]), "P06 pinned database differs")
        closed(report["preceding_receipts"], hashes, "Unknown P06 preceding receipt linkage")
        for kind, prior in zip(("p05_hpos", "p05_cpt", "p05_http"), priors):
            require(prior["status"] == "PASS" and all(case["status"] == "PASS" for case in prior["cases"]), "P06 cannot override failed prior evidence")
            link = report["preceding_receipts"][kind]; closed(link, {"sha256", "cases"}, "Unknown P06 prior witness")
            require(P.sha256(hashes[kind]) and link["sha256"] == hashes[kind] and type(link["cases"]) is int and link["cases"] == len(prior["cases"]), "P06 original prerequisite bytes or inventory differs")
        cases = expected.pure if pure else expected.native
        require(isinstance(report["cases"], list) and len(report["cases"]) == len(cases), "P06 incomplete numeric source inventory")
        for observed, (case_id, evidence, measurements) in zip(report["cases"], cases):
            closed(observed, {"id", "status", "evidence", "observations"}, "Unknown P06 numeric source case")
            require(observed["id"] == case_id and observed["status"] == "PASS", "P06 failed, reordered or duplicate case")
            closed(observed["evidence"], evidence, "Unknown P06 boolean evidence"); require(all(value is True for value in observed["evidence"].values()), "P06 boolean evidence is not exact PASS")
            closed(observed["observations"], measurements, "Unknown P06 work measurement")
            require(all(type(value) is int and 0 <= value <= 2000000 for value in observed["observations"].values()), "P06 numeric measurement type or range differs")
            numeric_rules(case_id, observed["observations"])
        if not pure: require(report["cases"][0]["observations"]["loaded_lines"] == report["cases"][3]["observations"]["loaded_lines"], "P06 native cart restore count differs")
    return len(expected.pure), len(expected.native)


def main(argv):
    require(len(argv) == 14, "P06 requires pure/HPOS/CPT and exact eleven P05 aggregate inputs")
    P05.main(argv[3:])  # Includes the full retained P01-P04 cascade and strict P05 browser/runtime/cleanup.
    expected = authority(); priors = [load(argv[i]) for i in (3, 4, 13)]
    hashes = {kind: hashlib.sha256(Path(argv[i]).read_bytes()).hexdigest() for kind, i in zip(("p05_hpos", "p05_cpt", "p05_http"), (3, 4, 13))}
    pure, native = verify([load(path) for path in argv[:3]], priors, hashes, expected)
    print(f"promise_qualification_bounds_receipts=PASS pure={pure} hpos={native} fresh_cpt={native} retained=879")


if __name__ == "__main__":
    try: main(sys.argv[1:])
    except (RuntimeError, OSError, ValueError, KeyError, TypeError) as error:
        print(f"promise_qualification_bounds_receipts=FAIL reason={error}", file=sys.stderr); sys.exit(1)
