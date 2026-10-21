#!/usr/bin/env python3
"""Closed supplemental staff-guide HTTP receipt; original P05 proof stays separate."""
import importlib.util
from pathlib import Path
import re
import sys
sys.dont_write_bytecode = True

SOURCE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("staff_guide_retained_p05", SOURCE / "verify-opening-promise-native-configuration.py")
p05 = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = p05
spec.loader.exec_module(p05)
require, closed, load = p05.placement.require, p05.placement.closed, p05.placement.load

FORMAT = "cetech-opening-http-promise-staff-guide-v1"
IDENTITY_KEYS = ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash")
LIMITS = [
    "Supplemental native staff guide HTTP proof on the same owned P05 listener; all original19 observations remain separate.",
    "The owned P04 fixture is explicitly OFF before guide requests; this guide phase issues only admin requests and never enables adoption.",
    "Prepared disposable values are test-only; merchant approval and target-stack certification remain separate.",
    "No HTTP fault injection or interrupted multi-store save is claimed; focused PHP fault tests exercise that protocol separately.",
]
PROTOCOL = (
    ("GUIDE01-OWNED-OFF-PREFLIGHT", ("owned_disposable_site", "exact_installed_source", "native_admin_cookie", "predeclared_guide_targets_absent", "adoption_off_before_guide")),
    ("GUIDE02-INVALID-NONCE-NO-WRITE", ("native_form_nonce_present", "invalid_nonce_post_refused", "physical_sources_unchanged", "private_guide_options_unchanged")),
    ("GUIDE03-PREVIEW-NO-WRITE", ("actual_guide_preview_post", "hypothetical_wording", "physical_sources_unchanged", "adoption_stays_off")),
    ("GUIDE04-DRAFT-REVIEW-EDIT-REPLAY", ("actual_first_draft_post", "actual_review_and_edited_replacement", "actual_reopen_after_edit", "original_stale_form_repost_has_no_effect", "only_expected_draft_versions", "native_author_and_scope")),
    ("GUIDE05-CALENDAR-AND-POLICY-PUBLICATION", ("native_calendar_seal", "native_calendar_publish", "native_policy_seal", "native_policy_publish", "exact_published_calendar_reference", "original_draft_bodies_immutable", "adoption_stays_off")),
    ("GUIDE06-ASSIGNMENT-OFF", ("native_assignment_form_post", "exact_published_policy_reference", "native_original_request_replay_once", "adoption_stays_off", "protected_order_and_shipment_history_unchanged")),
    ("GUIDE07-EXACT-TRACKED-CLEANUP", ("exact_owned_sources_removed", "exact_owned_operation_namespaces_removed", "exact_private_index_restored", "exact_pending_journal_restored", "protected_history_unchanged", "adoption_stays_off")),
)
SHARED_CLEANUP_KEYS = ("listener_stopped", "mu_removed", "private_removed", "tracked_cleanup")
PRIMARY_KINDS = ("native", "cpt", "http", "p02", "p03", "p04_hpos", "p04_cpt", "p04_http", "p05_hpos", "p05_cpt")
PRIMARY_KEYS = p05.placement.COMMON_KEYS | {"limits", "preceding_receipts", "browser_runtime"}
SIDECAR_KEYS = p05.placement.COMMON_KEYS | {"limits", "shared_cleanup"}


def passing_cases(cases, protocol, label):
    require(isinstance(cases, list) and len(cases) == len(protocol), label + " inventory differs")
    for case, (case_id, fields) in zip(cases, protocol):
        closed(case, {"id", "status", "evidence"}, label + " case envelope differs")
        require(case["id"] == case_id and case["status"] == "PASS", label + " case failed, duplicated or reordered")
        closed(case["evidence"], fields, label + " observation fields differ")
        require(all(value is True for value in case["evidence"].values()), label + " observations must be exact true booleans")


def primary_identity(primary):
    """Validate the retained envelope, not rerun its independent prerequisite proofs."""
    closed(primary, PRIMARY_KEYS, "Unknown retained P05 HTTP envelope")
    require(primary["format"] == "cetech-opening-http-promise-native-configuration-v1" and primary["status"] == "PASS", "Retained P05 HTTP proof is incomplete")
    for key in IDENTITY_KEYS[:3]:
        require(isinstance(primary[key], str) and re.fullmatch(r"[0-9a-f]{40}", primary[key]), "Retained source identity is malformed")
    sources = primary["installed_php_sources"]
    require(isinstance(sources, dict) and sources and list(sources) == sorted(sources), "Retained production map is not canonical")
    require(all(isinstance(path, str) and path.endswith(".php") and not path.startswith("/") and ".." not in path.split("/") and not re.search(r"[\x00-\x1f\x7f]", path) and p05.placement.sha256(digest) for path, digest in sources.items()), "Retained production source member is malformed")
    require(p05.placement.sha256(primary["installed_php_sources_hash"]) and primary["installed_php_sources_hash"] == p05.placement.canonical_hash(sources), "Retained production map digest differs")
    protocol, scope = p05.http_protocol()
    require(len(protocol) == 19, "Original nineteen P05 HTTP observations differ")
    passing_cases(primary["cases"], protocol, "Retained P05 HTTP")
    require(primary["limits"] == scope["LIMITS"], "Retained P05 HTTP limits differ")
    environment = primary["environment"]
    closed(environment, {"php", "wordpress", "woocommerce", "database_version", "hpos", "schema_before", "context", "background_requests"}, "Unknown retained P05 HTTP runtime")
    for key, value in {"php": "8.5.11", "wordpress": "7.1.2", "woocommerce": "11.1.2", "hpos": "yes", "schema_before": "11", "context": scope["CONTEXT"], "background_requests": scope["BACKGROUND"]}.items():
        require(environment[key] == value, "Retained pinned P05 HTTP runtime differs")
    require(isinstance(environment["database_version"], str) and re.fullmatch(r"11\.4\.13-MariaDB(?:-[A-Za-z0-9_.]+)*", environment["database_version"]), "Retained pinned P05 database differs")
    closed(primary["browser_runtime"], {"playwright", "chromium"}, "Unknown retained P05 browser")
    require(primary["browser_runtime"] == {"playwright": "1.58.2", "chromium": "145.0.7632.6"}, "Retained pinned P05 browser differs")
    closed(primary["preceding_receipts"], PRIMARY_KINDS, "Unknown retained P05 prerequisite links")
    counts = (495, 20, 143, 19, 49, 33, 33, 14, len(p05.protocol()), len(p05.protocol()))
    for kind, count in zip(PRIMARY_KINDS, counts):
        link = primary["preceding_receipts"][kind]
        closed(link, {"sha256", "cases"}, "Unknown retained P05 prerequisite fact")
        require(p05.placement.sha256(link["sha256"]) and type(link["cases"]) is int and link["cases"] == count, "Retained P05 prerequisite digest or inventory differs")


def verify(report, primary):
    primary_identity(primary)
    closed(report, SIDECAR_KEYS, "Unknown staff guide HTTP envelope")
    require(report["format"] == FORMAT and report["status"] == "PASS", "Staff guide HTTP proof is incomplete")
    for key in IDENTITY_KEYS + ("installed_php_sources", "environment"):
        require(report[key] == primary[key], "Staff guide and original P05 source/runtime identity differ")
    require(report["limits"] == LIMITS, "Staff guide HTTP scope differs")
    passing_cases(report["cases"], PROTOCOL, "Staff guide HTTP")
    closed(report["shared_cleanup"], SHARED_CLEANUP_KEYS, "Unknown shared staff guide listener cleanup")
    require(all(value is True for value in report["shared_cleanup"].values()), "Staff guide shared listener cleanup is incomplete")
    return len(PROTOCOL)


def main(argv):
    require(len(argv) == 2, "Staff guide sidecar and original P05 HTTP receipt are required")
    count = verify(load(argv[0]), load(argv[1]))
    print(f"promise_staff_guide_http_receipt=PASS guide={count} retained_http=19")


if __name__ == "__main__":
    try:
        main(sys.argv[1:])
    except (RuntimeError, ValueError, TypeError, KeyError, OSError):
        print("promise_staff_guide_http_receipt=FAIL", file=sys.stderr)
        sys.exit(1)
