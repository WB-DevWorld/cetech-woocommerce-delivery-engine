#!/usr/bin/env python3
"""Closed N18 receipts, authenticated against committed producers and installed PHP identity."""
from __future__ import annotations

import argparse
import ast
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys

sys.dont_write_bytecode = True
SOURCE = Path(__file__).resolve().parent
ROOT = SOURCE.parent.parent
spec = importlib.util.spec_from_file_location("n18_placement_receipt_helpers", SOURCE / "verify-quote-placement-receipts.py")
placement = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = placement
spec.loader.exec_module(placement)
require, closed, git = placement.require, placement.closed, placement.git
IDENTITY = ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash")
HISTORY = ("records", "events", "quotes", "accepted", "bindings", "budget")
KINDS = ("cetech-n18-promotion-cart-http-v1", "cetech-n18-promotion-cart-browser-v1", "cetech-n18-promotion-placement-http-v1")
PRODUCERS = (
    "scripts/ci-promotion-cart-http-qualification.sh",
    "scripts/qualification/opening-http-promotion-cart-support.php",
    "scripts/qualification/opening-http-promotion-cart-mu.php",
    "scripts/qualification/opening-http-promotion-cart-driver.py",
    "scripts/qualification/promotion-cart-browser.cjs",
    "scripts/qualification/promotion-cart-cleanup-diagnostic.py",
    "scripts/qualification/opening-http-promotion-placement-driver.py",
    "scripts/qualification/opening-http-promotion-placement-observer.php",
    "scripts/qualification/verify-opening-promotion-cart.py",
    "scripts/qualification/verify-quote-placement-receipts.py",
    "scripts/qualification/opening-http-driver.py",
    "scripts/qualification/opening-http-quote-cart-driver.py",
    "scripts/qualification/opening-http-fixture-common.php",
    "scripts/qualification/opening-http-quote-cart-fixture.php",
    "scripts/qualification/opening-quote-cart-support.php",
    "scripts/qualification/opening-quote-provider-support.php",
)
CLOSEOUT_FLAGS = ("direct_receipt_pass", "browser_receipt_pass", "placement_receipt_pass", "listener_stopped", "mu_removed", "private_removed", "tracked_fixture_cleanup")
CART_CLEANUP = placement.Q05_PROVIDER_CLEANUP_KEYS | {"owned_review_users_pages_removed"}


def load(path, private=False):
    """O_NOFOLLOW and bounded reads also reject duplicate members and nonfinite numbers."""
    descriptor = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0))
    try:
        info = os.fstat(descriptor)
        require(stat.S_ISREG(info.st_mode) and 0 < info.st_size <= 16 * 1024 * 1024, "N18 JSON is not a bounded ordinary file")
        require(not Path(path).is_symlink() and (not private or not info.st_mode & 0o077), "N18 private JSON permissions differ")
        with os.fdopen(descriptor, "r", encoding="utf-8", closefd=False) as stream:
            text = stream.read(16 * 1024 * 1024 + 1)
        require(len(text.encode("utf-8")) <= 16 * 1024 * 1024, "N18 JSON exceeded its bound")
        def nonfinite(_):
            raise RuntimeError("Nonfinite N18 JSON")
        return json.loads(text, object_pairs_hook=placement.unique_object, parse_constant=nonfinite)
    finally:
        os.close(descriptor)


def pin_sources(head):
    for relative in PRODUCERS:
        path = ROOT / relative
        require(path.is_file() and not path.is_symlink() and path.read_bytes() == git("show", head + ":" + relative), "Uncommitted N18 producer authority")


def literal(tree, name):
    matches = [node.value for node in tree.body if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name) and node.targets[0].id == name]
    require(len(matches) == 1, "Ambiguous N18 source constant")
    result = ast.literal_eval(matches[0])
    require(isinstance(result, (tuple, list)) and result and all(isinstance(item, str) for item in result) and len(result) == len(set(result)), "Invalid N18 source constant")
    return tuple(result)


def python_protocol(filename, count, direct=False):
    tree = ast.parse((SOURCE / filename).read_text(encoding="utf-8"))
    ids = literal(tree, "IDS")
    require(len(ids) == count, "N18 source inventory count differs")
    expansions = {} if direct else {"observed": literal(tree, "PLACED_KEYS"), "HISTORY_KEYS": literal(tree, "HISTORY_KEYS")}
    fields = {}
    for call in ast.walk(tree):
        if not isinstance(call, ast.Call):
            continue
        if direct and isinstance(call.func, ast.Name) and call.func.id == "check" and call.args and isinstance(call.args[0], ast.Constant):
            index = call.args[0].value
            require(type(index) is int and index not in fields and all(keyword.arg is not None for keyword in call.keywords), "Ambiguous N18 direct observations")
            names = ["history_before", "history_after", "zero_gateway", "no_placement", *(keyword.arg for keyword in call.keywords)]
        elif not direct and isinstance(call.func, ast.Attribute) and isinstance(call.func.value, ast.Name) and call.func.value.id == "recorder" and call.func.attr == "check":
            require(len(call.args) == 3 and isinstance(call.args[0], ast.Subscript) and isinstance(call.args[0].value, ast.Name) and call.args[0].value.id == "IDS" and isinstance(call.args[0].slice, ast.Constant) and isinstance(call.args[2], ast.Dict), "Ambiguous N18 placement case")
            index = call.args[0].slice.value
            require(type(index) is int and index not in fields, "Repeated N18 placement case")
            names = []
            for key, value in zip(call.args[2].keys, call.args[2].values):
                if isinstance(key, ast.Constant) and isinstance(key.value, str):
                    names.append(key.value)
                elif key is None and isinstance(value, ast.Name) and value.id == "observed":
                    names.extend(expansions[value.id])
                elif key is None and isinstance(value, ast.DictComp) and len(value.generators) == 1 and isinstance(value.generators[0].iter, ast.Name) and value.generators[0].iter.id == "HISTORY_KEYS":
                    names.extend(expansions["HISTORY_KEYS"])
                else:
                    raise RuntimeError("Unknown N18 placement observation expansion")
        else:
            continue
        require(len(names) == len(set(names)), "Repeated N18 evidence field")
        fields[index] = tuple(names)
    require(set(fields) == set(range(count)), "Incomplete N18 producer calls")
    return tuple((case_id, fields[index]) for index, case_id in enumerate(ids))


def browser_protocol():
    text = (SOURCE / "promotion-cart-browser.cjs").read_text(encoding="utf-8")
    matches = re.findall(r"const ids\s*=\s*(\[[^;]+\]);", text)
    require(len(matches) == 1, "Ambiguous N18 browser IDs")
    ids = ast.literal_eval(matches[0])
    require(type(ids) is list and len(ids) == len(set(ids)) == 3 and all(isinstance(item, str) for item in ids), "Invalid N18 browser inventory")
    fields = {}
    for match in re.finditer(r"check\(ids\[(\d+)\],", text):
        index = int(match[1]); arguments = placement.php_parts(placement.php_block(text, match.start() + len("check")), "(", ")")
        require(index not in fields and len(arguments) == 3, "Ambiguous N18 browser observations")
        names = []
        for entry in placement.php_parts(arguments[2], "{", "}"):
            key = re.match(r"([a-z_]+)\s*:", entry)
            require(key is not None and key[1] not in names, "Unknown N18 browser observation")
            names.append(key[1])
        fields[index] = tuple(names)
    require(set(fields) == set(range(3)), "Incomplete N18 browser producer")
    return tuple((case_id, fields[index]) for index, case_id in enumerate(ids))


def protocol():
    return (python_protocol("opening-http-promotion-cart-driver.py", 7, True), browser_protocol(), python_protocol("opening-http-promotion-placement-driver.py", 3))


def authority():
    head = git("rev-parse", "HEAD").decode().strip()
    candidate = os.environ.get("CETECH_DE_QUALIFICATION_CANDIDATE_HEAD", head)
    require(re.fullmatch(r"[a-f0-9]{40}", candidate) is not None, "Invalid N18 candidate authority")
    pin_sources(head)
    tree = git("rev-parse", "HEAD^{tree}").decode().strip()
    for variable, expected in (("CETECH_DE_QUALIFICATION_HEAD", head), ("CETECH_DE_QUALIFICATION_TREE", tree)):
        require(os.environ.get(variable, expected) == expected, "N18 immutable environment authority differs")
    sources = placement.immutable_sources(head)
    return {"source_head": head, "candidate_head": candidate, "source_tree": tree, "installed_php_sources_hash": placement.canonical_hash(sources)}, sources, protocol()


def environment(value):
    scope = placement.ENVIRONMENT_SCOPE["http"]
    closed(value, {"php", "wordpress", "woocommerce", "database_version", "hpos", "schema"} | set(scope), "N18 HTTP runtime fields differ")
    require(all(isinstance(item, str) for item in value.values()) and re.fullmatch(r"8\.5\.\d+", value["php"]) is not None, "N18 native PHP differs")
    require(value["wordpress"] == "7.1.2" and value["woocommerce"] == "11.1.2" and value["hpos"] == "yes" and value["schema"] == "12", "N18 native stack differs")
    require(re.fullmatch(r"11\.4\.13-MariaDB(?:-[A-Za-z0-9_.]+)*", value["database_version"]) is not None and all(value[key] == item for key, item in scope.items()), "N18 database or native scope differs")


def history(value):
    closed(value, HISTORY, "N18 history fields differ")
    require(all(placement.integer(item) for item in value.values()) and value["accepted"] <= value["quotes"], "N18 history counters differ")


def http(value):
    closed(value, {"ordinal", "method", "status", "route", "elapsed_ms", "headers_ms", "body_ms", "phase", "body_sha256", "body_bytes"}, "N18 HTTP observation fields differ")
    require(placement.integer(value["ordinal"]) and value["ordinal"] > 0 and value["method"] == "POST" and type(value["status"]) is int and value["status"] == 200 and value["phase"] == "body", "N18 native POST observation differs")
    require(all(placement.integer(value[key]) for key in ("elapsed_ms", "headers_ms", "body_ms", "body_bytes")) and value["body_bytes"] > 0 and placement.sha256(value["body_sha256"]), "N18 bounded HTTP response differs")
    require(value["elapsed_ms"] >= value["headers_ms"] and value["elapsed_ms"] >= value["body_ms"] and isinstance(value["route"], str) and re.fullmatch(r"/[A-Za-z0-9_./%-]{0,511}", value["route"]) is not None, "N18 HTTP timing or redacted route differs")


def arithmetic(before, after, action):
    history(before); history(after)
    if action == "same":
        require(before == after, "N18 no-effect history changed")
        return
    quotes, accepted, bindings, minimum = {"refresh": (1, 0, 0, 1), "confirm": (0, 1, 0, 1), "review": (1, 1, 0, 2), "place": (0, 0, 1, 3)}[action]
    require(all(after[key] == before[key] + delta for key, delta in (("quotes", quotes), ("accepted", accepted), ("bindings", bindings))), "N18 required quote/accept/binding arithmetic differs")
    require(all(after[key] >= before[key] + minimum for key in ("records", "events")), "N18 claimed producer history is absent")
    require(after["budget"] >= before["budget"] if action in ("refresh", "review") else after["budget"] == before["budget"], "N18 preparation budget history differs")


def verify_reports(reports, state, identity, sources, specifications):
    require(type(reports) in (list, tuple) and len(reports) == len(specifications) == 3, "N18 requires all three independent receipts")
    require(isinstance(state, dict) and isinstance(state.get("identity"), dict), "N18 private state identity absent")
    trusted = state["identity"]
    closed(trusted, set(IDENTITY) | {"environment", "installed_php_sources"}, "N18 private identity fields differ")
    require(all(trusted[key] == identity[key] for key in IDENTITY) and trusted["installed_php_sources"] == sources, "N18 state does not match immutable source authority")
    environment(trusted["environment"])
    previous_suite = None
    for kind, (report, specs) in enumerate(zip(reports, specifications)):
        extra = {"runtime", "stage"} if kind == 1 else {"environment"} | ({"stage"} if kind == 2 else set())
        closed(report, set(IDENTITY) | {"format", "status", "cases"} | extra, "N18 receipt envelope differs")
        require(report["format"] == KINDS[kind] and report["status"] == "PASS" and all(report[key] == identity[key] for key in IDENTITY), "N18 receipt source or completion differs")
        if kind == 1:
            closed(report["runtime"], {"playwright", "chromium"}, "N18 browser runtime differs")
            require(report["runtime"]["playwright"] == "1.58.2" and isinstance(report["runtime"]["chromium"], str) and re.fullmatch(r"[1-9][0-9]{0,2}(?:\.[0-9]{1,6}){3}", report["runtime"]["chromium"]), "N18 browser pin or Chromium observation absent")
        else:
            environment(report["environment"])
            require(report["environment"] == trusted["environment"], "N18 native receipt runtime differs from the owned fixture")
        if kind != 0:
            require(report["stage"] == "complete", "N18 source run did not complete")
        require(isinstance(report["cases"], list) and len(report["cases"]) == len(specs), "N18 finite receipt inventory differs")
        previous = previous_suite
        for index, (case, (case_id, fields)) in enumerate(zip(report["cases"], specs)):
            closed(case, {"id", "status", "evidence"}, "N18 case fields differ")
            require(case["id"] == case_id and case["status"] == "PASS", "N18 source case ordering or result differs")
            facts = case["evidence"]; closed(facts, fields, "N18 required observations differ")
            complex_fields = {"history_before", "history_after", "runtime", "http", "status", "classic_status", "store_status"}
            require(all(value is True for key, value in facts.items() if key not in complex_fields), "N18 required observation did not pass")
            before, after = facts["history_before"], facts["history_after"]
            if previous is not None:
                require(before == previous, "N18 receipt history continuity differs")
            action = (("same", "same", "refresh", "same", "same", "confirm", "same"), ("same", "refresh", "confirm"), ("review", "place", "same"))[kind][index]
            arithmetic(before, after, action); previous = after
            if "http" in facts:
                http(facts["http"])
            for key in ("status", "classic_status", "store_status"):
                if key in facts:
                    require(type(facts[key]) is int and facts[key] == (403 if key in ("classic_status", "store_status") else 200), "N18 native HTTP status differs")
            if "runtime" in facts:
                require(facts["runtime"] == report["runtime"], "N18 browser case runtime differs")
        previous_suite = previous
    return (7, 3, 3)


def cleanup(promotion, cart):
    closed(promotion, {"tracked_promotion_cleanup_restored"}, "N18 promotion cleanup fields differ")
    closed(cart, CART_CLEANUP, "N18 cart cleanup fields differ")
    require(all(value is True for value in (*promotion.values(), *cart.values())), "N18 tracked cleanup incomplete")


def closeout(value):
    closed(value, {"format", "status", "stage", *CLOSEOUT_FLAGS}, "N18 closeout fields differ")
    require(value["format"] == "cetech-n18-promotion-http-closeout-v1" and value["status"] == "PASS" and value["stage"] == "complete" and all(value[key] is True for key in CLOSEOUT_FLAGS), "N18 closeout incomplete")


def main(argv):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("paths", nargs="*", type=Path)
    parser.add_argument("--cleanup", nargs=2, type=Path)
    parser.add_argument("--closeout", type=Path)
    args = parser.parse_args(argv)
    if args.closeout is not None:
        require(not args.paths and args.cleanup is None, "N18 closeout mode accepts one receipt only")
        authority(); closeout(load(args.closeout)); print("promotion_cart_closeout=PASS")
        return
    require(len(args.paths) == 4 and args.cleanup is not None, "N18 requires direct, browser, placement, private state and both cleanup receipts")
    identity, sources, specifications = authority()
    verify_reports([load(path) for path in args.paths[:3]], load(args.paths[3], private=True), identity, sources, specifications)
    cleanup(*(load(path) for path in args.cleanup))
    print("promotion_cart_receipts=PASS direct=7 browser=3 placement=3")


if __name__ == "__main__":
    try:
        main(sys.argv[1:])
    except (AssertionError, RuntimeError, ValueError, TypeError, KeyError, OSError, SyntaxError, RecursionError, subprocess.SubprocessError):
        print("promotion_cart_receipts=FAIL", file=sys.stderr)
        sys.exit(1)
