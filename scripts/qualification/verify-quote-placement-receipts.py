#!/usr/bin/env python3
"""Closed primary Q06 receipts: complete inventories, pinned runtime and immutable Git bytes.

The retained ordered inventories were checked against the complete native/HTTP
PASS artifacts and producers at Q05_INTEGRATED_HEAD. Only ordered case IDs are
pinned here; no private rows, monetary payloads or former case dictionaries are
copied. Their hashes preserve every old case without rewriting legacy evidence.
Q06 case order and exact native evidence keys are derived from current producers.
"""
from __future__ import annotations

from dataclasses import dataclass
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
SOURCE = Path(__file__).resolve().parent
Q05_INTEGRATED_HEAD = "6cc1edd6153aeb21ec9b3bbec467f7ec7b7c0799"
Q05_NATIVE_COUNT = 475
Q05_NATIVE_IDS_HASH = "dbd9e94bf82d1b4b1dac8ba3501c231d352184219a873d387c8972264746f64b"
Q05_HTTP_COUNT = 112
Q05_HTTP_IDS_HASH = "bf18990bb8d20d4d01f1693b1c5d6a523d96abcb3278c7b9df04ca6476ce8b48"
NATIVE_PREFIX = "NATIVE-W2Q06-"
CPT_PREFIX = "NATIVE-W2Q06-HPOS-OFF-"
IDENTITY_KEYS = ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash")
COMMON_KEYS = {"format", "source_head", "candidate_head", "source_tree", "installed_php_sources", "installed_php_sources_hash", "environment", "status", "cases"}
HTTP_KEYS = COMMON_KEYS | {"identity_verified", "mode", "principal_role", "principal_user_id", "fixture_diagnostic", "native_crash_diagnostic", "diagnostic_runtime", "fixture_origin_preflight", "fixture_lifecycle"}
POLICY = {"profile": "php85-ci-opcache-jit-disabled", "scope": "disposable_web_listeners", "opcache_jit": "disable", "qualifies_jit1235_runtime": False}
RUNTIME_PINS = {"php_binary_sha256": "CETECH_DE_HTTP_EXPECT_BINARY_SHA256", "full_ini_sha256": "CETECH_DE_HTTP_EXPECT_INI_SHA256", "full_ini_jit1235_sha256": "CETECH_DE_HTTP_EXPECT_ORIGINAL_INI_SHA256", "extensions_sha256": "CETECH_DE_HTTP_EXPECT_EXTENSIONS_SHA256"}
ENVIRONMENT_SCOPE = {
    "native": {"context": "WP-CLI with native wp-admin flag and explicitly selected fixture principals", "background_requests": "WP Cron disabled; Action Scheduler async request runner suppressed in this process"},
    "cpt": {"context": "fresh WP-CLI CPT authority; actual Woo CRUD/native C07/production quote placement"},
    "http": {"transport": "HTTP to isolated PHP listener, native WordPress login/cookies/nonces/redirects and wp-admin/admin-ajax callbacks", "background_requests": "WP Cron disabled; marked fixture MU plugin suppresses the Action Scheduler async runner across requests"},
}
NATIVE_LIMITS = ["No HTTP/browser authentication or session transport proof.", "No live site, order, payment, theme/cache or release qualification.", "Migration option-write denials are simulated hooks; no physical storage crash/atomicity proof.", "Canonical scenarios and policy-dependent portions retain their separate gates."]
CPT_LIMITS = ["HTTP/gateway/browser routes have their separate qualification.", "This receipt covers the declared marked native CPT disposable process."]
Q05_PROVIDER_CLEANUP_KEYS = {"cleanup_restored", "domain35_and_quote_operation_history_restored", "raw_options_restored", "native_tax_method_session_rows_restored", "native_taxonomy_restored", "owned_products_removed", "native_wc_objects_restored", "all_owned_connections_retired"}
RETAINED_CLEANUP_KEYS = {
    "NATIVE-W2Q04-OWNED-NATIVE-PROVIDER-FIXTURE-CLEANUP": Q05_PROVIDER_CLEANUP_KEYS,
    "NATIVE-W2Q05-CART-FIXTURE-CLEANUP": Q05_PROVIDER_CLEANUP_KEYS,
    "NATIVE-W2Q05-READER-CLEANUP": {"orders_removed", "items_removed", "physical_meta_removed", "products_removed", "currency_option_restored"},
    "HTTP-W2Q05-FIXTURE-CLEANUP": Q05_PROVIDER_CLEANUP_KEYS | {"owned_review_users_pages_removed"},
}


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def canonical_hash(value):
    return hashlib.sha256(json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode()).hexdigest()


def sha256(value):
    return isinstance(value, str) and re.fullmatch(r"[a-f0-9]{64}", value) is not None


def integer(value, maximum=1000000):
    return type(value) is int and 0 <= value <= maximum


def closed(value, keys, message):
    require(isinstance(value, dict) and set(value) == set(keys), message)


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, "Duplicate JSON member")
        result[key] = value
    return result


def load(path):
    path = Path(path)
    require(path.is_file() and 0 < path.stat().st_size <= 16 * 1024 * 1024, "Receipt size unavailable")
    def reject_constant(_):
        raise RuntimeError("Nonfinite JSON number")
    return json.loads(path.read_text(encoding="utf-8"), object_pairs_hook=unique_object, parse_constant=reject_constant)


def php_parts(text, opening, closing):
    """Split one finite PHP call/array without evaluating its conditions or callbacks."""
    require(text.startswith(opening) and text.endswith(closing), "Malformed source protocol")
    text = text[1:-1]
    parts, start, stack, quote, index = [], 0, [], None, 0
    while index < len(text):
        char = text[index]
        if quote:
            if char == "\\":
                index += 2
                continue
            if char == quote:
                quote = None
        elif char in "'\"":
            quote = char
        elif char in "([{" :
            stack.append(char)
        elif char in ")]}" :
            require(stack and {")": "(", "]": "[", "}": "{"}[char] == stack.pop(), "Malformed source delimiter")
        elif char == "," and not stack:
            parts.append(text[start:index].strip())
            start = index + 1
        index += 1
    require(not stack and not quote, "Incomplete source protocol")
    parts.append(text[start:].strip())
    return [part for part in parts if part]


def php_block(text, start, opening="(", closing=")"):
    depth, quote, index = 0, None, start
    while index < len(text):
        char = text[index]
        if quote:
            if char == "\\":
                index += 2
                continue
            if char == quote:
                quote = None
        elif char in "'\"":
            quote = char
        elif char == opening:
            depth += 1
        elif char == closing:
            depth -= 1
            if depth == 0:
                return text[start:index + 1]
        index += 1
    raise RuntimeError("Incomplete source block")


def native_protocol():
    producer = (SOURCE / "opening-quote-placement.php").read_text(encoding="utf-8")
    support = (SOURCE / "opening-quote-placement-support.php").read_text(encoding="utf-8")
    vocabulary = {}
    for kind in ("BOOLS", "COUNTERS"):
        match = re.search(r"public const " + kind + r" = (\[[^;]+\]);", support)
        require(match is not None, "Missing native vocabulary")
        values = re.findall(r"'([a-z_]+)'", match.group(1))
        require(values and len(values) == len(set(values)), "Ambiguous native vocabulary")
        vocabulary[kind] = frozenset(values)
    require(not vocabulary["BOOLS"] & vocabulary["COUNTERS"], "Overlapping native vocabulary")
    cleanup = re.search(r"\$cleanup = \$this->cart->cleanup\(\);\s*return\s*(\[)", support)
    require(cleanup is not None, "Missing native cleanup protocol")
    cleanup_array = php_block(support, cleanup.start(1), "[", "]")
    specs = []
    for match in re.finditer(r"\$case\(\s*'NATIVE-W2Q06-", producer):
        args = php_parts(php_block(producer, match.start() + len("$case")), "(", ")")
        require(len(args) == 3 and re.fullmatch(r"'NATIVE-W2Q06-[A-Z0-9-]+'", args[0]), "Ambiguous native case")
        case_id = args[0][1:-1]
        evidence = cleanup_array if args[2] == "$cleanup" else args[2]
        require(evidence.startswith("[") and evidence.endswith("]"), "Unknown native evidence producer")
        fields = {}
        for entry in php_parts(evidence, "[", "]"):
            field = re.fullmatch(r"'([a-z_]+)'\s*=>\s*(.+)", entry, re.S)
            require(field is not None and field[1] not in fields, "Ambiguous native evidence field")
            fields[field[1]] = field[2].strip()
        require(fields and set(fields) <= vocabulary["BOOLS"] | vocabulary["COUNTERS"], "Unknown native evidence field")
        specs.append((case_id, fields))
    require(specs and len(specs) <= 100 and len({case for case, _ in specs}) == len(specs), "Ambiguous native source inventory")
    return tuple(specs), vocabulary


def http_protocol():
    spec = importlib.util.spec_from_file_location("placement_http_receipt_protocol", SOURCE / "opening-http-quote-placement.py")
    require(spec is not None and spec.loader is not None, "Missing HTTP protocol")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    require(0 < len(module.REQUIRED_IDS) <= 100 and len(set(module.REQUIRED_IDS)) == len(module.REQUIRED_IDS), "Ambiguous HTTP source inventory")
    return module


def git(*args):
    return subprocess.check_output(["git", *args], cwd=ROOT, timeout=20)


def immutable_sources(head):
    paths = sorted(path for path in git("ls-tree", "-r", "-z", "--name-only", head).decode().split("\0") if path.endswith(".php") and (path.startswith(("src/", "database/")) or path in ("cetech-woocommerce-delivery-engine.php", "uninstall.php")))
    require(paths and len(paths) <= 10000 and all(re.fullmatch(r"[A-Za-z0-9_./-]+", path) for path in paths), "Invalid production source inventory")
    process = subprocess.run(["git", "cat-file", "--batch"], cwd=ROOT, input="".join(head + ":" + path + "\n" for path in paths).encode(), capture_output=True, timeout=30, check=True)
    output, offset, result = process.stdout, 0, {}
    for path in paths:
        end = output.find(b"\n", offset)
        require(end >= offset, "Missing immutable production blob")
        header = output[offset:end].split()
        require(len(header) == 3 and header[1] == b"blob" and header[2].isdigit(), "Invalid immutable production blob")
        size = int(header[2]); offset = end + 1
        require(offset + size < len(output) and output[offset + size:offset + size + 1] == b"\n", "Incomplete immutable production blob")
        result[path] = hashlib.sha256(output[offset:offset + size]).hexdigest(); offset += size + 1
    require(offset == len(output), "Extra immutable production blob")
    return result


def immutable_runtime_pins(head):
    """Use the candidate's committed CI authority, never receipt comparison claims."""
    workflow = git("show", head + ":.github/workflows/ci.yml").decode("utf-8")
    pins = {}
    for field, name in RUNTIME_PINS.items():
        occurrences = re.findall(r"(?m)^\s*" + name + r"\s*:", workflow)
        values = re.findall(r"(?m)^\s*" + name + r"\s*:\s*'([a-f0-9]{64})'\s*$", workflow)
        require(len(occurrences) == len(values) == 1, "Missing or ambiguous immutable runtime fingerprint")
        pins[field] = values[0]
    return pins


@dataclass(frozen=True)
class Authority:
    source_head: str
    candidate_head: str
    source_tree: str
    sources: dict
    native_specs: tuple
    vocabulary: dict
    http: object
    native_baseline_count: int = Q05_NATIVE_COUNT
    native_baseline_hash: str = Q05_NATIVE_IDS_HASH
    http_baseline_count: int = Q05_HTTP_COUNT
    http_baseline_hash: str = Q05_HTTP_IDS_HASH
    runtime_fingerprints: dict | None = None


def authority():
    head = git("rev-parse", "HEAD").decode().strip()
    candidate = os.environ.get("CETECH_DE_QUALIFICATION_CANDIDATE_HEAD", head)
    require(re.fullmatch(r"[0-9a-f]{40}", candidate), "Invalid candidate authority")
    git("merge-base", "--is-ancestor", Q05_INTEGRATED_HEAD, head)
    # Current producer authority must be committed with this receipt candidate.
    # Working-tree edits cannot change the required inventory of a past SHA.
    for name in ("opening-quote-placement.php", "opening-quote-placement-support.php", "opening-http-quote-placement.py", "opening-runner.php", "opening-http-driver.py"):
        require((SOURCE / name).read_bytes() == git("show", head + ":scripts/qualification/" + name), "Uncommitted placement producer authority")
    specs, vocabulary = native_protocol()
    return Authority(head, candidate, git("rev-parse", "HEAD^{tree}").decode().strip(), immutable_sources(head), specs, vocabulary, http_protocol(), runtime_fingerprints=immutable_runtime_pins(head))


def report_common(report, expected, kind):
    require(isinstance(report, dict) and set(report) == (HTTP_KEYS if kind == "http" else COMMON_KEYS | {"limits"}), "Unknown receipt envelope")
    require(report["format"] == ("cetech-opening-http-qualification-v1" if kind == "http" else "cetech-opening-native-qualification-v1") and report["status"] == "PASS", "Incomplete placement qualification")
    require(report["source_head"] == expected.source_head and report["candidate_head"] == expected.candidate_head and report["source_tree"] == expected.source_tree, "Qualification source identity differs")
    require(report["installed_php_sources"] == expected.sources and report["installed_php_sources_hash"] == canonical_hash(expected.sources), "Installed immutable production files differ")
    cases = report["cases"]
    require(isinstance(cases, list) and 0 < len(cases) <= 1000, "Invalid qualification inventory")
    for case in cases:
        require(isinstance(case, dict) and set(case) == {"id", "status", "evidence"} and isinstance(case["id"], str) and re.fullmatch(r"[A-Za-z0-9_-]{1,200}", case["id"]) and case["status"] == "PASS" and (isinstance(case["evidence"], dict) or case["evidence"] == []), "Malformed or failed qualification case")
    require(len({case["id"] for case in cases}) == len(cases), "Duplicate qualification case")
    environment = report["environment"]
    expected_keys = {"php", "wordpress", "woocommerce", "database_version", "hpos"} | set(ENVIRONMENT_SCOPE[kind])
    if kind != "cpt":
        expected_keys.add("schema" if kind == "http" else "schema_before")
    closed(environment, expected_keys, "Missing or unknown native runtime")
    require(all(environment[key] == value for key, value in ENVIRONMENT_SCOPE[kind].items()), "Native qualification scope differs")
    for key, value in {"php": "8.5.11", "wordpress": "7.1.2", "woocommerce": "11.1.2", "hpos": "no" if kind == "cpt" else "yes"}.items():
        require(environment.get(key) == value, "Pinned native runtime differs")
    require(isinstance(environment.get("database_version"), str) and re.fullmatch(r"11\.4\.13-MariaDB(?:-[A-Za-z0-9_.]+)*", environment["database_version"]), "Pinned database runtime differs")
    if kind != "cpt":
        require(environment.get("schema" if kind == "http" else "schema_before") == "9", "Installed schema differs")
    if kind != "http":
        require(report["limits"] == (CPT_LIMITS if kind == "cpt" else NATIVE_LIMITS), "Invalid native scope")
    return cases


def baseline(cases, prefix, count, digest):
    retained = [case for case in cases if not case["id"].startswith(prefix)]
    require(len(retained) == count and canonical_hash([case["id"] for case in retained]) == digest, "Retained Q05 inventory differs")
    # Do not reinterpret legacy negative predicates or alter their dictionaries.
    for case in retained:
        facts = case["evidence"]
        if case["id"] in RETAINED_CLEANUP_KEYS:
            closed(facts, RETAINED_CLEANUP_KEYS[case["id"]], "Retained fixture cleanup observations incomplete")
            require(all(value is True for value in facts.values()), "Retained fixture cleanup failed")
        if isinstance(facts, dict) and "cleanup_restored" in facts:
            require(facts["cleanup_restored"] is True, "Retained fixture cleanup failed")
    for case in retained:
        if case["id"] == "NATIVE-FIXTURE-SCHEMA-RESTORED":
            require(case["evidence"] == {"schema_after": "9"}, "Retained schema cleanup differs")
        if case["id"] == "HTTP-FIXTURE-MU-AND-CREDENTIAL-FILES-REMOVED":
            require(case["evidence"] == {"fixture_mu_removed": True, "private_directory_removed": True}, "Retained HTTP cleanup differs")


def native_cases(cases, expected, hpos):
    prefix = NATIVE_PREFIX if hpos else CPT_PREFIX
    selected = [case for case in cases if case["id"].startswith(prefix)]
    ids = [case if hpos else case.replace(NATIVE_PREFIX, CPT_PREFIX, 1) for case, _ in expected.native_specs]
    require([case["id"] for case in selected] == ids, "Native placement inventory differs")
    if hpos:
        start = cases.index(selected[0])
        require(start > 0 and cases[start - 1]["id"] == "NATIVE-W2Q05-READER-CLEANUP" and cases[start:start + len(selected)] == selected, "Native placement producer order differs")
    for case, (_, fields) in zip(selected, expected.native_specs):
        facts = case["evidence"]
        require(isinstance(facts, dict) and set(facts) == set(fields), "Native required evidence differs")
        for key, expression in fields.items():
            value = facts[key]
            if key in expected.vocabulary["BOOLS"]:
                wanted = hpos if expression == "$hpos" else expression != "false"
                require(type(value) is bool and value is wanted, "Native placement predicate differs")
            else:
                require(type(value) is int and 0 <= value <= 100000, "Native counter differs")
                if key in {"binding_commits", "masked_binding_acks", "verified_sql_clocks"}:
                    require(value > 0, "Required native effect not observed")
                if key in {"native_line_count", "native_shipping_count"}:
                    require(value == (2 if key == "native_line_count" else 1), "Native fixture membership differs")
                if key == "gateway_calls" and case["id"].endswith("ACTUAL-PAID-CALLBACKS-KEEP-SEALED-HISTORY"):
                    require(value == 1, "Required native paid gateway effect differs")
    return selected


def http_cases(cases, expected):
    selected = [case for case in cases if case["id"].startswith(expected.http.PREFIX)]
    require([case["id"] for case in selected] == list(expected.http.REQUIRED_IDS), "HTTP placement inventory differs")
    start = cases.index(selected[0]); end = start + len(selected)
    require(start > 0 and cases[start - 1]["id"] == "HTTP-W2Q05-BLOCKS-CONFIRM-ONE-ACCEPT-NO-PLACEMENT" and cases[start:end] == selected and end < len(cases) and cases[end]["id"] == "HTTP-W2Q05-FIXTURE-CLEANUP", "HTTP placement producer order differs")
    for case in selected:
        require(expected.http.case_valid(case), "HTTP required placement evidence differs")
        if case["id"] == expected.http.CLEANUP_ID:
            continue
        evidence = case["evidence"]; before, after, facts = evidence["before"], evidence["after"], evidence["observations"]
        if facts.get("one_gateway_call"):
            require(after["gateway_calls"] == before["gateway_calls"] + 1, "HTTP gateway counter contradicts receipt")
        if facts.get("one_free_completion"):
            require(after["free_completion_calls"] == before["free_completion_calls"] + 1, "HTTP completion counter contradicts receipt")
        if facts.get("no_gateway_or_free_completion"):
            require(all(before[key] == after[key] for key in ("gateway_calls", "payment_complete_calls", "free_completion_calls", "paid")), "HTTP no-payment counter contradicts receipt")
        if facts.get("reads_never_place"):
            require(all(before[key] == after[key] for key in ("sealed", "prepared")), "HTTP readonly counter contradicts receipt")
        if facts.get("native_303_termination"):
            require(evidence["http_status"] == 303, "HTTP continuation status differs")
    return selected


def runtime_evidence(runtime, fingerprints):
    closed(runtime, {"sapi", "php_version", "php_binary_sha256", "extensions", "safe_ini", "full_ini_sha256", "full_ini_jit1235_sha256", "historical_ini_comparison_available", "opcache_state_at_existing_probe", "qualification_runtime_policy", "extensions_sha256", "expected_qualification_runtime"}, "Unknown HTTP runtime evidence")
    policy = runtime["qualification_runtime_policy"]
    closed(policy, POLICY, "Unknown HTTP runtime policy")
    require(all(type(policy[key]) is type(value) and policy[key] == value for key, value in POLICY.items()), "HTTP runtime policy differs")
    require(runtime["sapi"] == "cli-server" and runtime["php_version"] == "8.5.11" and runtime["historical_ini_comparison_available"] is False, "HTTP primary runtime differs")
    require(all(sha256(runtime[key]) for key in ("php_binary_sha256", "full_ini_sha256", "full_ini_jit1235_sha256", "extensions_sha256")), "Malformed HTTP runtime fingerprint")
    closed(fingerprints, RUNTIME_PINS, "Missing immutable HTTP runtime authority")
    require(all(sha256(fingerprints[key]) and runtime[key] == fingerprints[key] for key in RUNTIME_PINS), "HTTP immutable runtime fingerprint differs")
    extensions = runtime["extensions"]
    require(isinstance(extensions, dict) and 0 < len(extensions) <= 200 and all(isinstance(key, str) and re.fullmatch(r"[A-Za-z0-9_ -]{1,80}", key) and (value is False or isinstance(value, str) and re.fullmatch(r"[A-Za-z0-9_.@+ -]{1,80}", value)) for key, value in extensions.items()), "Malformed native extension versions")
    require(runtime["extensions_sha256"] == canonical_hash(dict(sorted(extensions.items()))), "Native extension fingerprint differs")
    ini = runtime["safe_ini"]
    closed(ini, {"memory_limit", "max_execution_time", "opcache.enable", "opcache.enable_cli", "opcache.jit", "opcache.jit_buffer_size", "opcache.optimization_level", "opcache.protect_memory"}, "Unknown HTTP INI evidence")
    require(all(isinstance(value, str) and re.fullmatch(r"[A-Za-z0-9_.+-]{1,32}", value) for value in ini.values()) and ini["opcache.jit"] == "disable" and ini["opcache.enable"] == "1", "HTTP INI policy differs")
    opcache = runtime["opcache_state_at_existing_probe"]
    closed(opcache, {"status_available", "opcache_enabled", "cache_full", "restart_pending", "restart_in_progress", "statistics", "jit"}, "Unknown native OPcache evidence")
    require(opcache["status_available"] is True and opcache["opcache_enabled"] is True and all(type(opcache[key]) is bool for key in ("cache_full", "restart_pending", "restart_in_progress")), "Native OPcache observation differs")
    closed(opcache["statistics"], {"num_cached_scripts", "hits", "misses"}, "Unknown native OPcache counters")
    require(all(integer(value, 1000000000) for value in opcache["statistics"].values()), "Malformed native OPcache counters")
    jit = opcache["jit"]
    closed(jit, {"enabled", "on", "kind", "opt_level", "opt_flags", "buffer_size", "buffer_free"}, "Unknown native JIT evidence")
    require(jit["enabled"] is False and jit["on"] is False and all(integer(jit[key], 1000000000) for key in ("kind", "opt_level", "opt_flags", "buffer_size", "buffer_free")), "Native JIT policy differs")
    comparisons = runtime["expected_qualification_runtime"]
    closed(comparisons, {"binary", "ini", "extensions", "original_ini_except_opcache_jit"}, "Unknown pinned HTTP runtime comparison")
    require(all(value is True for value in comparisons.values()), "HTTP pinned runtime comparison failed")


def stack_evidence(stack, capture_status):
    closed(stack, {"frames", "symbol_validation", "capture_status"}, "Unknown native symbol evidence")
    require(stack["capture_status"] == capture_status and isinstance(stack["frames"], list) and len(stack["frames"]) <= 32, "Native debugger validation failed")
    symbols = stack["symbol_validation"]
    closed(symbols, {"exact_executable_loaded", "zend_execute_full_symbol", "zend_execute_data_type", "status", "executable_build_id", "matching_separate_debug_build_id", "executable_module"}, "Unknown native symbol validation")
    require(symbols["status"] == "PASS" and all(symbols[key] is True for key in ("exact_executable_loaded", "zend_execute_full_symbol", "zend_execute_data_type")), "Native debugger symbols unavailable")
    require(isinstance(symbols["executable_build_id"], str) and re.fullmatch(r"[a-f0-9]{16,128}", symbols["executable_build_id"]) and symbols["matching_separate_debug_build_id"] == symbols["executable_build_id"] and isinstance(symbols["executable_module"], str) and re.fullmatch(r"[A-Za-z0-9_.+-]{1,100}", symbols["executable_module"]), "Native debugger build identity differs")
    for index, frame in enumerate(stack["frames"]):
        closed(frame, {"depth", "symbol", "module", "mapping_known", "source_file", "source_line"}, "Unknown native debugger frame")
        require(type(frame["depth"]) is int and frame["depth"] == index and type(frame["mapping_known"]) is bool, "Malformed native debugger depth")
        for key, pattern in (("symbol", r"[A-Za-z_][A-Za-z0-9_:.$~]{0,159}"), ("module", r"[A-Za-z0-9_.+-]{1,100}"), ("source_file", r"[A-Za-z0-9_.+-]{1,100}")):
            require(frame[key] is None or isinstance(frame[key], str) and re.fullmatch(pattern, frame[key]), "Malformed native debugger symbol")
        require(frame["source_line"] is None or type(frame["source_line"]) is int and 0 < frame["source_line"] <= 1000000, "Malformed native debugger line")
    require(not stack["frames"] if capture_status == "preflight_pass" else bool(stack["frames"]), "Missing native debugger validation frames")


def origin_evidence(origin, expected):
    closed(origin, {"format", "status", "expected_origin", "same_run_native_pass_before_mutations", "before", "after", "option_update_returns", "identity", "installed_php_source_files"}, "Unknown HTTP origin preflight")
    require(origin["format"] == "cetech-opening-http-origin-preflight-v1" and origin["status"] == "PASS" and origin["expected_origin"] == "http://127.0.0.1:8085" and origin["same_run_native_pass_before_mutations"] is True and type(origin["installed_php_source_files"]) is int and origin["installed_php_source_files"] == len(expected.sources), "HTTP source preflight differs")
    identity = {"source_head": expected.source_head, "candidate_head": expected.candidate_head, "source_tree": expected.source_tree, "installed_php_sources_hash": canonical_hash(expected.sources)}
    require(origin["identity"] == identity, "HTTP preflight source identity differs")
    closed(origin["option_update_returns"], {"home", "siteurl"}, "Unknown HTTP origin writes")
    require(all(type(value) is bool for value in origin["option_update_returns"].values()), "Malformed HTTP origin write observations")
    for phase in ("before", "after"):
        observed_phase = origin[phase]
        closed(observed_phase, {"physical", "native"}, "HTTP origin observations incomplete")
        for layer in observed_phase.values():
            closed(layer, {"home", "siteurl"}, "HTTP origin slots incomplete")
            for observed in layer.values():
                closed(observed, {"value_type", "sha256", "exact_expected", "scheme", "host", "port", "root_path", "query_present", "fragment_present", "userinfo_present"}, "Unknown HTTP origin observations")
                require(observed["value_type"] == "string" and sha256(observed["sha256"]) and observed["scheme"] in {"http", "https", "other_or_missing"} and observed["host"] in {"127.0.0.1", "other_or_missing"} and (observed["port"] is None or integer(observed["port"], 65535)) and all(type(observed[key]) is bool for key in ("exact_expected", "root_path", "query_present", "fragment_present", "userinfo_present")), "Malformed HTTP origin observations")
                if phase == "after":
                    require(observed["sha256"] == hashlib.sha256(b"http://127.0.0.1:8085").hexdigest() and observed["exact_expected"] is True and observed["root_path"] is True and observed["scheme"] == "http" and observed["host"] == "127.0.0.1" and observed["port"] == 8085 and all(observed[key] is False for key in ("query_present", "fragment_present", "userinfo_present")), "HTTP origin differs")


def http_completion(report, expected):
    require(report["identity_verified"] is True and report["mode"] == "prepare" and type(report["principal_user_id"]) is int and report["principal_user_id"] > 0 and isinstance(report["principal_role"], str) and re.fullmatch(r"cetech_http_import_[a-f0-9]{16}", report["principal_role"]), "HTTP native principal authority differs")
    runtime_evidence(report["diagnostic_runtime"], expected.runtime_fingerprints)
    crash = report["native_crash_diagnostic"]
    closed(crash, {"capture_status", "kernel_core_pattern_restored", "raw_core_or_debugger_output_retained", "frames", "limits", "debugger_preflight", "synthetic_core_validation", "synthetic_validation_is_product_reproduction"}, "Unknown HTTP crash evidence")
    require(crash["capture_status"] == "no_listener_core" and crash["kernel_core_pattern_restored"] is True and crash["raw_core_or_debugger_output_retained"] is False and crash["frames"] == [] and crash["synthetic_validation_is_product_reproduction"] is False and crash["limits"] == "Symbols narrow native execution location; they do not establish a product defect. Prior failure did not record an INI fingerprint.", "HTTP crash or restoration differs")
    stack_evidence(crash["debugger_preflight"], "preflight_pass")
    stack_evidence(crash["synthetic_core_validation"], "symbols_captured")
    diagnostic = report["fixture_diagnostic"]
    closed(diagnostic, {"stage", "command_log_present", "command_log_sha256", "allowlisted_error_codes", "allowlisted_error_classes", "raw_output_retained", "listener_exit_before_cleanup", "listener_signal_before_cleanup", "owned_listener_wait_exit", "owned_listener_wait_signal", "listener_cleanup_requested_sigterm", "server_log_present", "server_log_sha256", "database_connect_before_cleanup", "database_connect_wait_ms"}, "Unknown HTTP fixture diagnostic")
    require(diagnostic["stage"] == "complete" and diagnostic["allowlisted_error_codes"] == [] and diagnostic["allowlisted_error_classes"] == [] and diagnostic["raw_output_retained"] is False and diagnostic["owned_listener_wait_signal"] == "15" and diagnostic["owned_listener_wait_exit"] == "143" and diagnostic["listener_cleanup_requested_sigterm"] is True and diagnostic["listener_exit_before_cleanup"] == "running" and diagnostic["listener_signal_before_cleanup"] is None and diagnostic["database_connect_before_cleanup"] == "connected" and integer(diagnostic["database_connect_wait_ms"], 10000), "HTTP completion or listener retirement differs")
    require(all(type(diagnostic[key]) is bool for key in ("command_log_present", "server_log_present")) and diagnostic["server_log_present"] is True and all(diagnostic[key] is None or sha256(diagnostic[key]) for key in ("command_log_sha256", "server_log_sha256")) and sha256(diagnostic["server_log_sha256"]), "Malformed HTTP fixture log fingerprints")
    lifecycle = report["fixture_lifecycle"]
    booleans = {"listener_started", "owned_listener_attested", "listener_stopped", "fixture_mu_removed", "private_files_removed", "tracked_cleanup_command_success"}
    require(isinstance(lifecycle, dict) and set(lifecycle) == booleans | {"partial_init_failure_fallback"} and all(lifecycle[key] is True for key in booleans) and lifecycle["partial_init_failure_fallback"] == "entire dedicated CI database/site/service are disposable; no total-database rollback claim", "HTTP fixture lifecycle incomplete")
    origin_evidence(report["fixture_origin_preflight"], expected)


def verify_reports(native, cpt, http, expected):
    native_all = report_common(native, expected, "native")
    cpt_all = report_common(cpt, expected, "cpt")
    http_all = report_common(http, expected, "http")
    baseline(native_all, NATIVE_PREFIX, expected.native_baseline_count, expected.native_baseline_hash)
    baseline(http_all, expected.http.PREFIX, expected.http_baseline_count, expected.http_baseline_hash)
    native_selected = native_cases(native_all, expected, True)
    cpt_selected = native_cases(cpt_all, expected, False)
    require(len(cpt_all) == len(cpt_selected), "Extra CPT qualification case")
    http_selected = http_cases(http_all, expected)
    http_completion(http, expected)
    return len(native_all), len(cpt_all), len(http_all), len(native_selected), len(http_selected)


def main(argv):
    require(len(argv) == 3, "Three placement receipts are required")
    totals = verify_reports(*(load(path) for path in argv), authority())
    print(f"quote_placement_receipts=PASS native={totals[0]} cpt={totals[1]} http={totals[2]} q06_native={totals[3]} q06_http={totals[4]}")


if __name__ == "__main__":
    try:
        main(sys.argv[1:])
    except (RuntimeError, ValueError, TypeError, KeyError, OSError, subprocess.SubprocessError):
        print("quote_placement_receipts=FAIL", file=sys.stderr)
        sys.exit(1)
