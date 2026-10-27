#!/usr/bin/env python3
"""Validate the two finite N17 receipts against their committed producers and ZIP sources."""
import importlib.util
import os
from pathlib import Path
import re
import sys

sys.dont_write_bytecode = True
SOURCE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("n17_placement_protocol", SOURCE / "verify-quote-placement-receipts.py")
placement = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = placement
spec.loader.exec_module(placement)
require, closed, load = placement.require, placement.closed, placement.load
PRODUCERS = (
    "scripts/qualification/opening-promotion-native.php",
    "scripts/qualification/opening-promotion-native-runner.php",
    "scripts/qualification/verify-opening-promotion-native.py",
    "scripts/ci-promotion-native-hpos-off.sh",
    "tests/Support/DeliveryPromotion/PromotionNativeHandoffFixture.php",
)
CONTEXT = "Fresh WP-CLI N17 process; installed package autoload, actual Woo CRUD, owned SQL physical guards and saved format3 history"
BACKGROUND = "WP Cron disabled; Action Scheduler async request runner suppressed in this process"
LIMITS = [
    "Trusted synthetic economics, source inputs and typed prepared1 acknowledgment are explicit test inputs; they are not merchant funding, C03 history authority, checkout or payment proof.",
    "Actual native HPOS/CPT order capture, saved packet bytes and second-connection mutation refusals are qualified only in this marked disposable fixture.",
    "Promise adoption remains OFF; no default promotion provider, gateway, payment or queue runner is mounted.",
    "Retained 495 native, 20 CPT and 143 HTTP inventories remain separate and unchanged.",
]


def protocol():
    source = (SOURCE / "opening-promotion-native.php").read_text()
    result = []
    for match in re.finditer(r"\$check\(\s*'NATIVE-N17-", source):
        args = placement.php_parts(placement.php_block(source, match.start() + len("$check")), "(", ")")
        require(len(args) == 3 and re.fullmatch(r"'NATIVE-N17-[A-Z0-9-]+'", args[0]), "Ambiguous N17 source case")
        fields = []
        for entry in placement.php_parts(args[2], "[", "]"):
            field = re.fullmatch(r"'([a-z0-9_]+)'\s*=>\s*.+", entry, re.S)
            require(field is not None and field[1] not in fields, "Ambiguous N17 source observation")
            fields.append(field[1])
        require(fields, "Empty N17 source observation")
        result.append((args[0][1:-1], tuple(fields)))
    require(len(result) == 12 and len({row[0] for row in result}) == 12, "N17 requires twelve unique source cases")
    return tuple(result)


def verify(report, mode, identity, sources, cases):
    closed(report, {"format", "source_head", "candidate_head", "source_tree", "installed_php_sources", "installed_php_sources_hash", "environment", "limits", "status", "cases"}, "N17 receipt")
    require(report["format"] == "cetech-opening-promotion-native-v1" and report["status"] == "PASS", "Incomplete N17 receipt")
    require(all(report[key] == value for key, value in identity.items()), "N17 execution identity differs")
    require(report["installed_php_sources"] == sources and report["installed_php_sources_hash"] == placement.canonical_hash(sources), "N17 installed immutable production sources differ")
    require(report["limits"] == LIMITS, "N17 declared scope differs")
    env = report["environment"]
    closed(env, {"php", "wordpress", "woocommerce", "database_version", "hpos", "schema_before", "context", "background_requests"}, "N17 environment")
    require(all(isinstance(value, str) for value in env.values()), "N17 environment values are not strings")
    require(re.fullmatch(r"8\.5\.\d+", env["php"]) and env["wordpress"] == "7.1.2" and env["woocommerce"] == "11.1.2", "N17 pinned native runtime differs")
    require(env["hpos"] == mode and env["schema_before"] == "12" and env["context"] == CONTEXT and env["background_requests"] == BACKGROUND, "N17 native authority differs")
    require(isinstance(report["cases"], list) and len(report["cases"]) == len(cases), "N17 finite case count differs")
    for row, (case_id, fields) in zip(report["cases"], cases):
        closed(row, {"id", "status", "evidence"}, "N17 case")
        require(row["id"] == case_id and row["status"] == "PASS", "N17 source case ordering or outcome differs")
        closed(row["evidence"], set(fields), "N17 observations")
        require(all(value is True for value in row["evidence"].values()), "N17 observation did not pass")


def main(paths):
    require(len(paths) == 2, "N17 requires HPOS and fresh CPT receipts")
    head = placement.git("rev-parse", "HEAD").decode().strip()
    candidate = os.environ.get("CETECH_DE_QUALIFICATION_CANDIDATE_HEAD", head)
    require(re.fullmatch(r"[0-9a-f]{40}", candidate), "Invalid N17 candidate authority")
    for path in PRODUCERS:
        require((SOURCE.parent.parent / path).read_bytes() == placement.git("show", head + ":" + path), "Uncommitted N17 producer authority")
    sources = placement.immutable_sources(head)
    require(len(sources) == 1004 and "src/Integrations/DeliveryQuote/FixedPromotionNativeOrderEvidence.php" in sources, "N17 production inventory differs")
    identity = {"source_head": head, "candidate_head": candidate, "source_tree": placement.git("rev-parse", "HEAD^{tree}").decode().strip()}
    cases = protocol()
    for path, mode in zip(paths, ("yes", "no")):
        verify(load(path), mode, identity, sources, cases)
    print("opening_promotion_native_receipts=PASS modes=2 cases_per_mode=12 production_php=1004")


if __name__ == "__main__":
    try:
        main(sys.argv[1:])
    except (AssertionError, ValueError, RuntimeError, OSError, KeyError, TypeError) as error:
        raise SystemExit("N17 receipt refused: " + str(error))
