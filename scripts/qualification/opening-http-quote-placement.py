"""Q06 actual native placement routes on the marked disposable loopback site.

Uses the existing observed 20-second HTTP client. Setup commands seed native
cart/order candidates only; placement and payment require production handlers.
"""
import json
import subprocess
import time
import uuid
from pathlib import Path

PREFIX = "HTTP-W2Q06-"
DIRECT_IDS = tuple(PREFIX + name for name in (
    "CLASSIC-PAID-SEALED-BEFORE-GATEWAY", "CLASSIC-FREE-SEALED-BEFORE-COMPLETION",
    "CLASSIC-PENDING-REBUILD-EXACT-LOGICAL-MEMBERS", "CLASSIC-FAILED-REBUILD-EXACT-LOGICAL-MEMBERS",
    "CLASSIC-SEALED-SAME-RETRY-HISTORY-PRESERVED", "CLASSIC-CHANGED-RETRY-NEW-ORDER-OLD-HISTORY",
    "STOREAPI-GET-PUT-PATCH-NO-PLACEMENT", "STOREAPI-PAID-FINAL-POST-SEALED",
    "STOREAPI-SEALED-POINTER-DETACHED-LATER-READS-PRESERVE", "STOREAPI-FREE-FINAL-POST-SEALED",
    "CLASSIC-LATE-PAUSE-NO-PAYMENT", "STOREAPI-LATE-PAUSE-NO-PAYMENT",
    "CLASSIC-LATE-EXPIRY-NO-PAYMENT", "CLASSIC-BIND-ACK-LOSS-NO-PAYMENT",
    "CLASSIC-SEAL-ACK-LOSS-NO-PAYMENT", "CLASSIC-SNAPSHOT-FAULT-NO-PAYMENT",
    "ORDERPAY-VALID-EMPTY-CART-EXACT-ORDER", "ORDERPAY-EXPIRED-303-NO-PAYMENT",
    "ORDERPAY-CHANGED-303-NO-REPRICE", "ORDERPAY-FOREIGN-ORDER-NATIVE-DENIAL",
    "ORDERPAY-LEGACY-NATIVE-CONTINUATION", "CLASSIC-POSTSAVE-MONETARY-CHANGE-NO-PAYMENT", "CLASSIC-POSTSAVE-PROTECTED-CHANGE-NO-PAYMENT", "CLASSIC-UNKNOWN-SEAL-STOREAPI-ORIGINAL-RECOVERY", "STOREAPI-UNKNOWN-SEAL-CLASSIC-ORIGINAL-RECOVERY", "ORDERPAY-NATIVE-VALIDATION-UNSAVED-BILLING-NO-PAYMENT", "ORDERPAY-NATIVE-VALIDATION-UNSAVED-LINE-MONEY-NO-PAYMENT", "PAID-HISTORY-AFTER-SOURCE-DELETION",
))
BROWSER_IDS = (PREFIX + "BLOCKS-PAID-ACTUAL-FINAL-BUTTON", PREFIX + "BLOCKS-FREE-ACTUAL-FINAL-BUTTON")
CLEANUP_ID = PREFIX + "FIXTURE-CLEANUP"
REQUIRED_IDS = DIRECT_IDS[:-1] + BROWSER_IDS + DIRECT_IDS[-1:] + (CLEANUP_ID,)
COUNTS = ("orders", "paid", "sealed", "prepared", "gateway_calls", "payment_complete_calls", "free_completion_calls")
BOOLS = (
    "actual_native_route", "actual_final_post", "known_sealed_receipt", "native_history_supported",
    "gateway_only_after_seal", "free_only_after_seal", "one_gateway_call", "one_free_completion",
    "no_gateway_or_free_completion", "native_order_reused", "exact_logical_members", "snapshot_bytes_preserved",
    "new_order_binding", "draft_pointer_detached", "reads_never_place", "barrier_triggered", "native_303_termination",
    "native_authorization_denied", "legacy_order_has_no_quote", "real_chromium", "actual_blocks_button",
    "actual_ack_masked", "uncertain_connection_retired", "verified_sql_clock", "actual_snapshot_save_fault", "old_native_items_replaced", "historical_shipment_reader_supported", "inert_component_references_present", "original_replay_same_order", "no_duplicate_order_or_binding", "no_quote_issue_or_acceptance", "alternate_native_payment_method", "postsave_change_detected", "acknowledged_before_mutation",
    "one_effective_checkout_post", "no_unknown_checkout_request", "safe_shopper_response", "same_source_identity", "native_authorization_before_private_lookup", "terminal_original_replay_refused", "fresh_explicit_quote_escape", "original_binding_preserved", "actual_native_gateway_validation", "unsaved_native_change_detected",
)
STAGES = {"ownership", "login", "fixture", "review", "confirm", "render", "submit", "observe", "browser", "cleanup", "complete"}


def integer(value):
    return type(value) is int and 0 <= value <= 1000000


def counts(value):
    return isinstance(value, dict) and set(value) == set(COUNTS) and all(integer(value[k]) for k in COUNTS)


def evidence_valid(value, *, failure=False):
    """Closed typed evidence: arbitrary strings, private rows and credentials refuse."""
    if not isinstance(value, dict):
        return False
    if failure:
        return set(value) == {"stage", "error_class", "required_case_incomplete"} and value["stage"] in STAGES and value["error_class"] in {"RuntimeError", "ValueError", "TypeError", "KeyError", "TimeoutError", "OtherError"} and value["required_case_incomplete"] is True
    if set(value) != {"before", "after", "observations", "http_status"} or not counts(value["before"]) or not counts(value["after"]):
        return False
    observations = value["observations"]
    return isinstance(observations, dict) and bool(observations) and set(observations).issubset(BOOLS) and all(type(v) is bool for v in observations.values()) and type(value["http_status"]) is int and 100 <= value["http_status"] <= 599


BASE_PLACEMENT_BOOLS = ("actual_native_route", "actual_final_post", "known_sealed_receipt", "native_history_supported", "gateway_only_after_seal", "free_only_after_seal")
DIRECT_BOOLS = {
    DIRECT_IDS[0]: BASE_PLACEMENT_BOOLS + ("one_gateway_call", "inert_component_references_present"),
    DIRECT_IDS[1]: BASE_PLACEMENT_BOOLS + ("one_free_completion",),
    DIRECT_IDS[2]: BASE_PLACEMENT_BOOLS + ("one_gateway_call", "native_order_reused", "exact_logical_members", "old_native_items_replaced"),
    DIRECT_IDS[3]: BASE_PLACEMENT_BOOLS + ("one_gateway_call", "native_order_reused", "exact_logical_members", "old_native_items_replaced"),
    DIRECT_IDS[4]: ("actual_native_route", "snapshot_bytes_preserved", "no_gateway_or_free_completion", "native_303_termination"),
    DIRECT_IDS[5]: ("new_order_binding", "snapshot_bytes_preserved", "known_sealed_receipt"),
    DIRECT_IDS[6]: ("actual_native_route", "reads_never_place", "no_gateway_or_free_completion"),
    DIRECT_IDS[7]: BASE_PLACEMENT_BOOLS + ("one_gateway_call",),
    DIRECT_IDS[8]: ("draft_pointer_detached", "snapshot_bytes_preserved", "no_gateway_or_free_completion"),
    DIRECT_IDS[9]: BASE_PLACEMENT_BOOLS + ("one_free_completion",),
    DIRECT_IDS[10]: ("actual_native_route", "actual_final_post", "no_gateway_or_free_completion", "barrier_triggered", "terminal_original_replay_refused", "fresh_explicit_quote_escape", "snapshot_bytes_preserved", "original_binding_preserved"),
    DIRECT_IDS[11]: ("actual_native_route", "actual_final_post", "no_gateway_or_free_completion", "barrier_triggered", "terminal_original_replay_refused", "fresh_explicit_quote_escape", "snapshot_bytes_preserved", "original_binding_preserved"),
    DIRECT_IDS[12]: ("actual_native_route", "actual_final_post", "no_gateway_or_free_completion", "barrier_triggered", "verified_sql_clock", "terminal_original_replay_refused", "fresh_explicit_quote_escape", "snapshot_bytes_preserved", "original_binding_preserved"),
    DIRECT_IDS[13]: ("actual_native_route", "actual_final_post", "no_gateway_or_free_completion", "actual_ack_masked", "uncertain_connection_retired", "original_replay_same_order", "no_duplicate_order_or_binding"),
    DIRECT_IDS[14]: ("actual_native_route", "actual_final_post", "no_gateway_or_free_completion", "barrier_triggered", "actual_ack_masked", "uncertain_connection_retired", "original_replay_same_order", "no_duplicate_order_or_binding"),
    DIRECT_IDS[15]: ("actual_native_route", "actual_final_post", "no_gateway_or_free_completion", "barrier_triggered", "actual_snapshot_save_fault", "original_replay_same_order", "no_duplicate_order_or_binding"),
    DIRECT_IDS[16]: ("actual_native_route", "one_gateway_call", "known_sealed_receipt", "snapshot_bytes_preserved", "no_quote_issue_or_acceptance", "alternate_native_payment_method"),
    DIRECT_IDS[17]: ("native_303_termination", "snapshot_bytes_preserved", "no_gateway_or_free_completion", "no_quote_issue_or_acceptance"),
    DIRECT_IDS[18]: ("native_303_termination", "snapshot_bytes_preserved", "no_gateway_or_free_completion", "no_quote_issue_or_acceptance"),
    DIRECT_IDS[19]: ("native_authorization_denied", "native_authorization_before_private_lookup", "no_gateway_or_free_completion"),
    DIRECT_IDS[20]: ("legacy_order_has_no_quote", "one_gateway_call"),
    DIRECT_IDS[21]: ("actual_native_route", "actual_final_post", "barrier_triggered", "postsave_change_detected", "acknowledged_before_mutation", "no_gateway_or_free_completion", "original_replay_same_order", "no_duplicate_order_or_binding"),
    DIRECT_IDS[22]: ("actual_native_route", "actual_final_post", "barrier_triggered", "postsave_change_detected", "acknowledged_before_mutation", "no_gateway_or_free_completion", "original_replay_same_order", "no_duplicate_order_or_binding"),
    DIRECT_IDS[23]: ("actual_native_route", "actual_final_post", "actual_ack_masked", "uncertain_connection_retired", "original_replay_same_order", "no_duplicate_order_or_binding", "snapshot_bytes_preserved", "known_sealed_receipt", "no_gateway_or_free_completion"),
    DIRECT_IDS[24]: ("actual_native_route", "actual_final_post", "actual_ack_masked", "uncertain_connection_retired", "original_replay_same_order", "no_duplicate_order_or_binding", "snapshot_bytes_preserved", "known_sealed_receipt", "no_gateway_or_free_completion"),
    DIRECT_IDS[25]: ("actual_native_route", "acknowledged_before_mutation", "actual_native_gateway_validation", "unsaved_native_change_detected", "snapshot_bytes_preserved", "native_history_supported", "no_gateway_or_free_completion", "no_quote_issue_or_acceptance"),
    DIRECT_IDS[26]: ("actual_native_route", "acknowledged_before_mutation", "actual_native_gateway_validation", "unsaved_native_change_detected", "snapshot_bytes_preserved", "native_history_supported", "no_gateway_or_free_completion", "no_quote_issue_or_acceptance"),
    DIRECT_IDS[27]: ("snapshot_bytes_preserved", "native_history_supported", "no_gateway_or_free_completion", "historical_shipment_reader_supported"),
}
BROWSER_BOOLS = ("real_chromium", "actual_blocks_button", "actual_native_route", "actual_final_post", "known_sealed_receipt", "native_history_supported", "gateway_only_after_seal", "free_only_after_seal", "one_effective_checkout_post", "no_unknown_checkout_request", "safe_shopper_response", "same_source_identity")


def case_valid(case):
    if not isinstance(case, dict) or set(case) != {"id", "status", "evidence"} or case["id"] not in REQUIRED_IDS or case["status"] not in {"PASS", "FAIL"}:
        return False
    if case["id"] == CLEANUP_ID:
        return cleanup_valid(case["evidence"]) and (case["status"] != "PASS" or all(case["evidence"].values()))
    if case["status"] == "FAIL" and evidence_valid(case["evidence"], failure=True):
        return True
    if not evidence_valid(case["evidence"]):
        return False
    expected = DIRECT_BOOLS.get(case["id"], BROWSER_BOOLS + (("one_free_completion",) if case["id"] == BROWSER_IDS[1] else ("one_gateway_call",)))
    return set(case["evidence"]["observations"]) == set(expected) and (case["status"] != "PASS" or all(case["evidence"]["observations"].values()))


def browser_case_valid(case):
    return isinstance(case, dict) and case.get("id") in BROWSER_IDS and case_valid(case)


def browser_report_valid(report, identity):
    expected = {"format", "source_head", "candidate_head", "source_tree", "installed_php_sources_hash", "runtime", "status", "cases"}
    if not isinstance(report, dict) or set(report) != expected or report["format"] != "cetech-w2q06-placement-browser-v1" or report["status"] not in {"PASS", "FAIL"} or any(report[k] != identity.get(k) for k in ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash")):
        return False
    runtime = report["runtime"]
    if not isinstance(runtime, dict) or set(runtime) not in ({"playwright"}, {"playwright", "chromium"}) or runtime["playwright"] != "1.58.2" or ("chromium" in runtime and runtime["chromium"] != "145.0.7632.6"):
        return False
    cases = report["cases"]
    if not isinstance(cases, list) or not 1 <= len(cases) <= 2 or any(not browser_case_valid(case) or case["id"] != BROWSER_IDS[index] for index, case in enumerate(cases)):
        return False
    return report["status"] != "PASS" or (len(cases) == 2 and set(runtime) == {"playwright", "chromium"} and all(case["status"] == "PASS" for case in cases))


def request_json(response):
    value = json.loads(response.body)
    if not isinstance(value, dict):
        raise RuntimeError("Q06 native response was not an object")
    return value


def no_payment(before, after):
    return all(before["counts"][key] == after["counts"][key] for key in ("gateway_calls", "payment_complete_calls", "free_completion_calls", "paid"))


def preserved(before, after, order_id):
    left = before["orders"].get(str(order_id))
    right = after["orders"].get(str(order_id))
    return isinstance(left, dict) and isinstance(right, dict) and left["snapshot_hash"] == right["snapshot_hash"]


def run_quote_placement(client, state, bridge, recorder, Page=None, login=None):
    del Page, login
    stage = "ownership"
    active_case = DIRECT_IDS[0]
    headers = {"X-Cetech-Q06-Fixture": state["fixture_token"]}
    fixture_url = client.resolve("/?cetech_q06_fixture=inspect")

    def inspect():
        value = request_json(client.get(fixture_url, headers))
        if value.get("success") is not True or not isinstance(value.get("data"), dict):
            raise RuntimeError("Q06 authenticated native fixture refused")
        observed = value["data"]
        if not counts(observed.get("counts")) or any(observed.get("source_identity", {}).get(k) != state["identity"].get(k) for k in ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash")):
            raise RuntimeError("Q06 fixture identity or count schema diverged")
        return observed

    def fixture(mode):
        current = inspect()
        response = client.request("/?cetech_q06_fixture=" + mode, {"nonce": current["nonce"]}, headers)
        value = request_json(response)
        if response.status != 200 or value.get("success") is not True:
            raise RuntimeError("Q06 native setup command refused")
        return inspect()

    def check(case, condition, before, after, observations, response):
        value = {"before": before["counts"], "after": after["counts"], "observations": observations, "http_status": response.status}
        if set(observations) != set(DIRECT_BOOLS[case]) or not evidence_valid(value):
            raise RuntimeError("Q06 receipt schema refused private or untyped facts")
        recorder.check(case, bool(condition) and all(observations.values()), value)

    def review(free=False):
        nonlocal stage
        stage = "fixture"; seeded = fixture("free" if free else "seed")
        # Preserve the production 20-per-owner SQL-minute limit. Wait before a
        # fresh explicit attempt, never extend an existing admission lease.
        started = time.monotonic()
        while seeded["budget_attempts"] >= 19:
            if time.monotonic() - started > 65:
                raise RuntimeError("Q06 native preparation window did not advance")
            time.sleep(min(1.0, max(0.05, seeded["budget_window_remaining_ms"] / 1000)))
            seeded = inspect()
        stage = "review"
        response = client.request(state["classic_url"], {"_wpnonce": seeded["review_nonce"], "action": "refresh", "generation": seeded["facts"]["generation"], "review_token": str(uuid.uuid4())})
        value = request_json(response)
        facts = value.get("data")
        if response.status != 200 or value.get("success") is not True or not isinstance(facts, dict) or facts.get("status") != "review_required" or facts.get("can_confirm") is not True:
            raise RuntimeError("Q06 actual shopper Refresh did not produce review")
        if free and any((part.get("display_total") or part.get("total") or {}).get("amount") not in ("0.00", "0") for part in facts["quote"]["money"]):
            raise RuntimeError("Q06 configured zero quote did not show native zero")
        stage = "confirm"
        response = client.request(state["classic_url"], {"_wpnonce": inspect()["review_nonce"], "action": "confirm", "generation": facts["generation"]})
        value = request_json(response)
        if response.status != 200 or value.get("success") is not True or value.get("data", {}).get("status") != "confirmed":
            raise RuntimeError("Q06 actual shopper Confirm was not acknowledged")
        return inspect()

    def classic_fields(free=False):
        nonlocal stage
        stage = "render"
        rendered = client.get(state["classic_page_url"])
        forms = [x for x in rendered.page().forms if "woocommerce-process-checkout-nonce" in x.fields]
        if rendered.status != 200 or len(forms) != 1:
            raise RuntimeError("Q06 actual Classic checkout form was not rendered")
        fields = dict(forms[0].fields)
        fields.update({"billing_first_name": "Synthetic", "billing_last_name": "Shopper", "billing_country": "GH", "billing_state": "AA", "billing_city": "Accra", "billing_postcode": "00001", "billing_address_1": "PRIVATE-Q04-NATIVE-FIXTURE-ADDRESS", "billing_email": "q06@example.invalid", "billing_phone": "0200000000", "payment_method": "" if free else "cetech_q06_local_gateway", "terms": "1", "terms-field": "1"})
        fields.pop("ship_to_different_address", None)
        for index, chosen in enumerate(inspect()["chosen_methods"]):
            fields["shipping_method[" + str(index) + "]"] = chosen
        return fields

    def classic(free=False):
        nonlocal stage
        fields = classic_fields(free)
        before = inspect(); stage = "submit"
        response = client.post("/?wc-ajax=checkout", fields)
        stage = "observe"; after = inspect()
        try:
            result = request_json(response)
        except (ValueError, RuntimeError):
            result = {}
        return before, after, response, result

    address = {"first_name": "Synthetic", "last_name": "Shopper", "company": "", "address_1": "PRIVATE-Q04-NATIVE-FIXTURE-ADDRESS", "address_2": "", "city": "Accra", "state": "AA", "postcode": "00001", "country": "GH", "phone": "0200000000"}
    payload = {"billing_address": dict(address, email="q06@example.invalid"), "shipping_address": address, "payment_method": "cetech_q06_local_gateway", "payment_data": [], "customer_note": ""}

    def store(free=False):
        nonlocal stage
        before = inspect(); stage = "submit"
        body = dict(payload, payment_method="" if free else "cetech_q06_local_gateway")
        response = client.json_post(state["checkout_url"], body, {"Nonce": before["store_nonce"]})
        stage = "observe"; after = inspect()
        try:
            result = request_json(response)
        except (ValueError, RuntimeError):
            result = {}
        return before, after, response, result

    def paid(case, transport="classic", free=False, extra=None):
        before, after, response, result = classic(free) if transport == "classic" else store(free)
        order = after["orders"].get(str(after["last_order_id"]), {})
        success = result.get("result") == "success" if transport == "classic" else response.status == 200 and result.get("payment_result", {}).get("payment_status") == "success"
        obs = {"actual_native_route": True, "actual_final_post": response.method == "POST", "known_sealed_receipt": order.get("binding_state") == "sealed", "native_history_supported": order.get("history_supported") is True, "gateway_only_after_seal": not after["gateway_before_seal"], "free_only_after_seal": not after["completion_before_seal"]}
        obs["one_free_completion" if free else "one_gateway_call"] = after["counts"]["free_completion_calls" if free else "gateway_calls"] == before["counts"]["free_completion_calls" if free else "gateway_calls"] + 1
        if case == DIRECT_IDS[0]:
            obs["inert_component_references_present"] = before["inert_component_references_present"] is True
        if extra:
            obs.update(extra(before, after))
        check(case, success and order.get("paid") is True, before, after, obs, response)
        return after["last_order_id"]

    try:
        stage = "login"
        rendered = client.get("/wp-login.php")
        response = client.post("/wp-login.php", {"log": state["username"], "pwd": state["password"], "wp-submit": "Log In", "redirect_to": state["classic_page_url"], "testcookie": "1"})
        if rendered.status != 200 or response.status not in (302, 303) or not any(c.name.startswith("wordpress_logged_in_") for c in client.cookies):
            raise RuntimeError("Q06 native shopper login refused")
        review(); paid_order = paid(DIRECT_IDS[0])
        active_case = DIRECT_IDS[1]; review(True); paid(active_case, free=True)
        for index, mode in ((2, "resume_pending"), (3, "resume_failed")):
            active_case = DIRECT_IDS[index]; review(); prepared = fixture(mode); reused = prepared["reuse_order_id"]
            paid(active_case, extra=lambda before, after: {"native_order_reused": after["last_order_id"] == reused, "exact_logical_members": after["orders"].get(str(reused), {}).get("line_count") == 2, "old_native_items_replaced": not set(prepared["reuse_old_item_ids"]).intersection(after["orders"].get(str(reused), {}).get("line_ids", []))})
        active_case = DIRECT_IDS[4]; review(); fixture("hold"); before, after, response, result = classic(); held_id = after["last_order_id"]
        if result.get("result") != "success" or after["orders"].get(str(held_id), {}).get("binding_state") != "sealed":
            raise RuntimeError("Q06 native unpaid sealed continuation setup failed")
        before, after, response, result = classic()
        check(active_case, response.status == 303 and client.location(response) == client.resolve(before["orders"][str(held_id)]["order_pay_url"]) and str(held_id) in after["orders"], before, after, {"actual_native_route": True, "snapshot_bytes_preserved": preserved(before, after, held_id), "no_gateway_or_free_completion": no_payment(before, after), "native_303_termination": response.status == 303}, response)
        active_case = DIRECT_IDS[5]; old = inspect(); review(); before, after, response, result = classic()
        check(active_case, result.get("result") == "success", before, after, {"new_order_binding": after["last_order_id"] != held_id, "snapshot_bytes_preserved": preserved(old, after, held_id), "known_sealed_receipt": after["orders"].get(str(after["last_order_id"]), {}).get("binding_state") == "sealed"}, response)
        active_case = DIRECT_IDS[6]; review(); before = inspect()
        responses = [client.get(state["checkout_url"], {"Nonce": before["store_nonce"]})]
        responses.append(client.json_post(state["checkout_url"], payload, {"Nonce": inspect()["store_nonce"], "X-HTTP-Method-Override": "PUT"}))
        responses.append(client.json_patch(state["checkout_url"], payload, {"Nonce": inspect()["store_nonce"]}))
        after = inspect()
        check(active_case, all(r.status == 200 for r in responses), before, after, {"actual_native_route": True, "reads_never_place": all(before["counts"][key] == after["counts"][key] for key in ("sealed", "prepared")), "no_gateway_or_free_completion": no_payment(before, after)}, responses[-1])
        active_case = DIRECT_IDS[7]; store_id = paid(active_case, transport="store")
        active_case = DIRECT_IDS[8]; before = inspect(); fixture("refill")
        response = client.get(state["checkout_url"], {"Nonce": before["store_nonce"]})
        response = client.json_patch(state["checkout_url"], payload, {"Nonce": inspect()["store_nonce"]}); after = inspect()
        check(active_case, response.status == 200, before, after, {"draft_pointer_detached": before["draft_pointer"] != store_id and after["draft_pointer"] != store_id, "snapshot_bytes_preserved": preserved(before, after, store_id), "no_gateway_or_free_completion": no_payment(before, after)}, response)
        active_case = DIRECT_IDS[9]; review(True); paid(active_case, transport="store", free=True)
        for index, arm, transport in ((10, "arm_late_pause", "classic"), (11, "arm_late_pause", "store"), (12, "arm_late_expiry", "classic"), (13, "arm_bind_ack", "classic"), (14, "arm_seal_ack", "classic"), (15, "arm_snapshot_fault", "classic")):
            active_case = DIRECT_IDS[index]; review(); fixture(arm)
            before, after, response, result = classic() if transport == "classic" else store()
            denied = result.get("result") == "failure" if transport == "classic" else response.status in (400, 403, 409, 500) and isinstance(result.get("code"), str)
            obs = {"actual_native_route": True, "actual_final_post": response.method == "POST", "no_gateway_or_free_completion": no_payment(before, after)}
            if arm in ("arm_bind_ack", "arm_seal_ack"):
                obs["actual_ack_masked"] = after["masked_binding_acks"] == before["masked_binding_acks"] + 1
                obs["uncertain_connection_retired"] = after["uncertain_connection_retired"] is True
            if arm == "arm_late_expiry":
                obs["verified_sql_clock"] = after["verified_sql_clocks"] > before["verified_sql_clocks"]
            if arm == "arm_snapshot_fault":
                obs["actual_snapshot_save_fault"] = after["barrier_triggered"] is True and after["orders"].get(str(after["last_order_id"]), {}).get("binding_state") == "prepared"
            if arm != "arm_bind_ack":
                obs["barrier_triggered"] = after["barrier_triggered"] is True
            original_id = after["last_order_id"]
            if index in (10, 11, 12):
                fixture("clear_fault"); fixture("resume")
                _, replayed, replay_response, replay_result = classic() if transport == "classic" else store()
                refused = replay_result.get("result") == "failure" or replay_response.status == 303 and client.location(replay_response) == client.resolve(state["classic_page_url"]) if transport == "classic" else replay_response.status in (400, 403, 409, 500)
                obs["terminal_original_replay_refused"] = refused and no_payment(after, replayed) and replayed["counts"]["orders"] == after["counts"]["orders"]
                review(); _, escaped, escape_response, escape_result = classic() if transport == "classic" else store()
                new_id = escaped["last_order_id"]; new_order = escaped["orders"].get(str(new_id), {})
                obs["fresh_explicit_quote_escape"] = new_id != original_id and new_order.get("binding_state") == "sealed" and new_order.get("paid") is True and (escape_result.get("result") == "success" if transport == "classic" else escape_response.status == 200 and escape_result.get("payment_result", {}).get("payment_status") == "success")
                obs["snapshot_bytes_preserved"] = preserved(after, escaped, original_id)
                obs["original_binding_preserved"] = escaped["orders"].get(str(original_id), {}).get("binding_state") == "prepared" and escaped["orders"][str(original_id)].get("binding_revision") == 2 and escaped["orders"][str(original_id)].get("history_supported") is True and escaped["orders"][str(original_id)].get("paid") is False
                check(active_case, denied, before, after, obs, response)
                continue
            if not denied or original_id < 1 or str(original_id) not in after["orders"]:
                check(active_case, False, before, after, dict(obs, original_replay_same_order=False, no_duplicate_order_or_binding=False), response)
            fixture("clear_fault"); fixture("resume")
            recovery_before, recovered, recovery_response, recovery_result = classic() if transport == "classic" else store()
            recovery_ok = (recovery_response.status == 303 and client.location(recovery_response) == client.resolve(after["orders"][str(original_id)]["order_pay_url"])) if transport == "classic" else (recovery_response.status == 200 and recovery_result.get("payment_result", {}).get("payment_status") == "success" and recovery_result.get("order_id") == original_id)
            obs["original_replay_same_order"] = recovery_ok and recovered["last_order_id"] == original_id and recovered["orders"].get(str(original_id), {}).get("binding_state") == "sealed" and recovered["orders"][str(original_id)].get("binding_revision") == 3 and recovered["orders"][str(original_id)].get("history_supported") is True
            obs["no_duplicate_order_or_binding"] = recovered["counts"]["orders"] == after["counts"]["orders"] and sum(recovered["counts"][key] for key in ("sealed", "prepared")) == sum(after["counts"][key] for key in ("sealed", "prepared")) and no_payment(after, recovered)
            check(active_case, denied, before, after, obs, response)
        def held():
            review(); fixture("hold"); before, after, response, result = classic()
            if result.get("result") != "success":
                raise RuntimeError("Q06 actual unpaid native order setup refused")
            fixture("release"); return after["last_order_id"]
        def orderpay(order_id, payment_method="cetech_q06_local_gateway"):
            target = inspect()["orders"][str(order_id)]["order_pay_url"]
            rendered = client.get(target)
            forms = [x for x in rendered.page().forms if "woocommerce-pay-nonce" in x.fields]
            if rendered.status != 200 or len(forms) != 1:
                raise RuntimeError("Q06 native exact-order payment form missing")
            before = inspect(); response = client.post(target, dict(forms[0].fields, payment_method=payment_method, woocommerce_pay="1")); after = inspect()
            return before, after, response
        active_case = DIRECT_IDS[16]; valid_id = held(); fixture("empty"); before, after, response = orderpay(valid_id, "cetech_q06_alternate_gateway")
        check(active_case, response.status in (302, 303) and after["orders"][str(valid_id)]["paid"] is True, before, after, {"actual_native_route": True, "one_gateway_call": after["counts"]["gateway_calls"] == before["counts"]["gateway_calls"] + 1, "known_sealed_receipt": after["orders"][str(valid_id)]["binding_state"] == "sealed", "alternate_native_payment_method": after["orders"][str(valid_id)]["payment_method"] == "cetech_q06_alternate_gateway", "no_quote_issue_or_acceptance": all(before["history_counts"][key] == after["history_counts"][key] for key in ("quotes", "accepted")), "snapshot_bytes_preserved": preserved(before, after, valid_id)}, response)
        for index, change in ((17, "expire"), (18, "change")):
            active_case = DIRECT_IDS[index]; old_id = held(); fixture("empty"); fixture(change); before, after, response = orderpay(old_id)
            check(active_case, response.status == 303 and client.location(response) == client.resolve(before["native_checkout_url"]), before, after, {"native_303_termination": response.status == 303, "no_quote_issue_or_acceptance": all(before["history_counts"][key] == after["history_counts"][key] for key in ("quotes", "accepted")), "snapshot_bytes_preserved": preserved(before, after, old_id), "no_gateway_or_free_completion": no_payment(before, after)}, response)
        active_case = DIRECT_IDS[19]; fixture("resume"); fixture("clear_fault"); foreign = fixture("foreign"); foreign_id = foreign["foreign_order_id"]
        before = inspect(); target = before["orders"][str(foreign_id)]["order_pay_url"]
        rendered = client.get(target); forms = [x for x in rendered.page().forms if "woocommerce-pay-nonce" in x.fields]; response = client.post(target, {"woocommerce-pay-nonce": before["native_pay_nonce"], "woocommerce_pay": "1", "payment_method": "cetech_q06_local_gateway"}); after = inspect()
        check(active_case, not forms and response.status in (200, 302, 303, 403), before, after, {"native_authorization_denied": not forms and before["foreign_native_authorized"] is False and after["foreign_native_authorized"] is False, "native_authorization_before_private_lookup": before["foreign_payment_private_reads"] == after["foreign_payment_private_reads"] == 0, "no_gateway_or_free_completion": no_payment(before, after)}, response)
        # The foreign authorization case owns one temporary customer transfer.
        # Restore that exact tracked native field before a later independent case.
        fixture("restore_foreign")
        active_case = DIRECT_IDS[20]; legacy = fixture("legacy"); legacy_id = legacy["last_order_id"]; before, after, response = orderpay(legacy_id)
        check(active_case, response.status in (302, 303) and after["orders"][str(legacy_id)]["paid"] is True, before, after, {"legacy_order_has_no_quote": before["orders"][str(legacy_id)]["binding_state"] is None, "one_gateway_call": after["counts"]["gateway_calls"] == before["counts"]["gateway_calls"] + 1}, response)
        for index, arm in ((21, "arm_postsave_money"), (22, "arm_postsave_protected")):
            active_case = DIRECT_IDS[index]; review(); fixture(arm)
            before, after, response, result = classic(); original_id = after["last_order_id"]
            denied = result.get("result") == "failure"
            changed = after["barrier_triggered"] is True and (after["orders"].get(str(original_id), {}).get("total") == "999.99" if index == 21 else after["orders"].get(str(original_id), {}).get("history_supported") is False)
            if not denied or not changed:
                check(active_case, False, before, after, {"actual_native_route": True, "actual_final_post": response.method == "POST", "barrier_triggered": after["barrier_triggered"] is True, "postsave_change_detected": changed, "acknowledged_before_mutation": after["acknowledged_before_mutation"] is True, "no_gateway_or_free_completion": no_payment(before, after), "original_replay_same_order": False, "no_duplicate_order_or_binding": False}, response)
            fixture("restore_mutation"); fixture("clear_fault"); fixture("resume")
            _, recovered, recovery_response, _ = classic()
            check(active_case, denied, before, after, {"actual_native_route": True, "actual_final_post": response.method == "POST", "barrier_triggered": after["barrier_triggered"] is True, "postsave_change_detected": changed, "acknowledged_before_mutation": after["acknowledged_before_mutation"] is True, "no_gateway_or_free_completion": no_payment(before, after), "original_replay_same_order": recovery_response.status == 303 and client.location(recovery_response) == client.resolve(after["orders"][str(original_id)]["order_pay_url"]) and recovered["orders"].get(str(original_id), {}).get("binding_state") == "sealed" and recovered["orders"][str(original_id)]["history_supported"] is True, "no_duplicate_order_or_binding": recovered["counts"]["orders"] == after["counts"]["orders"] and sum(recovered["counts"][key] for key in ("sealed", "prepared")) == sum(after["counts"][key] for key in ("sealed", "prepared")) and no_payment(after, recovered)}, response)
        for index, original, retry in ((23, "classic", "store"), (24, "store", "classic")):
            active_case = DIRECT_IDS[index]; review(); fixture("arm_seal_ack")
            before, after, response, result = classic() if original == "classic" else store(); original_id = after["last_order_id"]
            denied = result.get("result") == "failure" if original == "classic" else response.status in (400, 403, 409, 500)
            fixture("clear_fault"); fixture("resume")
            _, recovered, recovery_response, recovery_result = classic() if retry == "classic" else store()
            recovered_original = recovery_response.status == 303 and client.location(recovery_response) == client.resolve(after["orders"][str(original_id)]["order_pay_url"]) if retry == "classic" else recovery_response.status == 200 and recovery_result.get("order_id") == original_id and recovery_result.get("payment_result", {}).get("payment_status") == "success"
            check(active_case, denied, before, recovered, {"actual_native_route": True, "actual_final_post": response.method == recovery_response.method == "POST", "actual_ack_masked": after["masked_binding_acks"] == before["masked_binding_acks"] + 1, "uncertain_connection_retired": after["uncertain_connection_retired"] is True, "original_replay_same_order": recovered_original and recovered["last_order_id"] == original_id, "no_duplicate_order_or_binding": recovered["counts"]["orders"] == after["counts"]["orders"] and sum(recovered["counts"][key] for key in ("sealed", "prepared")) == sum(after["counts"][key] for key in ("sealed", "prepared")), "snapshot_bytes_preserved": preserved(after, recovered, original_id), "known_sealed_receipt": recovered["orders"].get(str(original_id), {}).get("binding_state") == "sealed" and recovered["orders"][str(original_id)].get("binding_revision") == 3 and recovered["orders"][str(original_id)].get("history_supported") is True, "no_gateway_or_free_completion": no_payment(before, recovered)}, recovery_response)
        for index, arm in ((25, "arm_validate_billing"), (26, "arm_validate_money")):
            active_case = DIRECT_IDS[index]; native_id = held(); fixture("empty"); fixture(arm); before, after, response = orderpay(native_id)
            check(active_case, response.status == 200 and after["orders"][str(native_id)]["paid"] is False, before, after, {"actual_native_route": True, "acknowledged_before_mutation": after["acknowledged_before_mutation"] is True, "actual_native_gateway_validation": after["gateway_validation_calls"] == before["gateway_validation_calls"] + 1, "unsaved_native_change_detected": after["unsaved_native_change_detected"] is True, "snapshot_bytes_preserved": preserved(before, after, native_id), "native_history_supported": after["orders"][str(native_id)]["history_supported"] is True, "no_gateway_or_free_completion": no_payment(before, after), "no_quote_issue_or_acceptance": all(before["history_counts"][key] == after["history_counts"][key] for key in ("quotes", "accepted"))}, response)
            fixture("clear_fault")
        stage = "browser"; run_browser(client, state, recorder)
        active_case = DIRECT_IDS[27]; before = inspect(); response = client.request("/?cetech_q06_fixture=historychange", {"nonce": before["nonce"]}, headers); after = inspect()
        check(active_case, response.status == 200, before, after, {"snapshot_bytes_preserved": preserved(before, after, paid_order), "native_history_supported": after["orders"][str(paid_order)]["history_supported"] is True, "historical_shipment_reader_supported": after["orders"][str(paid_order)]["shipment_reader_supported"] is True, "no_gateway_or_free_completion": no_payment(before, after)}, response)
        stage = "complete"
    except Exception as error:
        if not any(item["id"] == active_case for item in recorder.report["cases"]):
            diagnostic = {"stage": stage, "error_class": type(error).__name__ if type(error).__name__ in {"RuntimeError", "ValueError", "TypeError", "KeyError", "TimeoutError"} else "OtherError", "required_case_incomplete": True}
            try:
                recorder.check(active_case, False, diagnostic)
            except RuntimeError:
                pass
        raise


def run_browser(client, state, recorder):
    path = Path(state.get("browser_state_path", ""))
    if not path.is_file():
        raise RuntimeError("Q06 private browser state missing")
    receipt = path.with_name("quote-placement-browser-receipt.json")
    if receipt.exists():
        raise RuntimeError("Q06 refuses to replace existing browser evidence")
    result = subprocess.run(["node", str(Path(__file__).with_name("quote-placement-browser.cjs")), "--state", str(path), "--receipt", str(receipt), "--base-url", client.base_url], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=180, check=False)
    if not receipt.is_file() or receipt.stat().st_size > 65536:
        raise RuntimeError("Q06 bounded browser receipt missing")
    report = json.loads(receipt.read_text())
    if not browser_report_valid(report, state["identity"]):
        raise RuntimeError("Q06 browser identity or schema refused")
    seen = []
    for case in report["cases"]:
        if not browser_case_valid(case) or case["id"] in seen or case["id"] != BROWSER_IDS[len(seen)]:
            raise RuntimeError("Q06 browser case inventory refused")
        seen.append(case["id"]); recorder.check(case["id"], case["status"] == "PASS", case["evidence"])
    if result.returncode != 0 or seen != list(BROWSER_IDS) or report.get("status") != "PASS":
        raise RuntimeError("Q06 actual final Blocks button flow incomplete")


CLEANUP_KEYS = ("cleanup_restored", "owned_native_orders_removed", "exact_owned_placement_namespaces_removed", "no_gateway_before_seal", "no_completion_before_seal", "owned_connections_retired")

def record_preparation_failure(recorder, error):
    """A failed native fixture setup is a missing first required case, with no raw cause."""
    name = type(error).__name__
    allowed = {"RuntimeError", "ValueError", "TypeError", "KeyError", "TimeoutError"}
    recorder.check(DIRECT_IDS[0], False, {"stage": "ownership", "error_class": name if name in allowed else "OtherError", "required_case_incomplete": True})

def cleanup_valid(value):
    return isinstance(value, dict) and set(value) == set(CLEANUP_KEYS) and all(type(value[key]) is bool for key in CLEANUP_KEYS)

def record_cleanup(recorder, value):
    if not cleanup_valid(value):
        raise RuntimeError("Q06 exact cleanup receipt schema refused")
    recorder.check(CLEANUP_ID, all(value.values()), value)
