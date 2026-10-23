"""N18 real native order staging and fresh-request history, separate from review.

Only the marked fixture may create its one pending unpaid native order. The
production review routes issue and accept the fresh session's quote first.
The placement observations are exact boolean whitelists; no private packet,
order identifier, account, source body, or exception text enters the receipt.
"""
import argparse
import hashlib
import importlib.util
import json
import sys
import uuid
from pathlib import Path
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit


def load_review():
    spec = importlib.util.spec_from_file_location("cetech_n18_placement_review", Path(__file__).with_name("opening-http-promotion-cart-driver.py"))
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


review = load_review()
common, public = review.common, review.public
IDS = (
    "HTTP-N18-PROMOTION-PLACEMENT-CURRENT-REVIEW",
    "HTTP-N18-PROMOTION-NATIVE-ORDER-SEAL-HELD-NO-ADMISSION",
    "HTTP-N18-PROMOTION-FRESH-REQUEST-SEALED-HISTORY",
)
PLACED_KEYS = (
    "native_order_created", "pending_unpaid", "sealed3_current",
    "history_all_five", "saved_format3", "gateway_refused", "held_budget_unchanged",
)
HISTORY_KEYS = (
    "fresh_native_order", "original_five_authenticated", "pending_unpaid", "gateway_zero", "fresh_current_sealed",
)


def booleans(value, keys):
    return public.exact(value, keys) and all(type(value[key]) is bool for key in keys)


def fixture_mode(url, mode):
    parts = urlsplit(url)
    if mode not in ("place", "placed") or parse_qsl(parts.query, keep_blank_values=True, strict_parsing=True) != [("cetech_q05_fixture", "inspect")]:
        raise RuntimeError("N18 placement requires the exact owned fixture route")
    return urlunsplit((parts.scheme, parts.netloc, parts.path, urlencode({"cetech_q05_fixture": mode}), ""))


def run(client, state, recorder):
    headers = {"X-Cetech-Q05-Fixture": state["fixture_token"]}

    def stage(value):
        recorder.report["stage"] = value
        recorder.write()

    def decoded(response):
        value = json.loads(response.body)
        if not isinstance(value, dict):
            raise RuntimeError("N18 placement native response was not an object")
        return value

    def inspection():
        response = client.get(state["fixture_url"], headers)
        value = decoded(response)
        data = value.get("data")
        if response.status != 200 or value.get("success") is not True or not isinstance(data, dict):
            raise RuntimeError("N18 placement owned inspection refused")
        if any(data.get("source_identity", {}).get(key) != state["identity"][key] for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash")):
            raise RuntimeError("N18 placement native source identity differs")
        if not public.exact(data.get("history_counts"), public.HISTORY) or any(not public.integer(data["history_counts"][key]) for key in public.HISTORY) or any(not public.integer(data.get(key)) for key in ("orders_count", "gateway_count")) or not public.safe_facts(data.get("facts")):
            raise RuntimeError("N18 placement observations are not finite")
        return data

    def same_history(before, after):
        return before["history_counts"] == after["history_counts"] and before["orders_count"] == after["orders_count"] and before["gateway_count"] == after["gateway_count"] == 0

    def promotion(facts):
        if not public.safe_facts(facts) or facts["quote"] is None or len(facts["quote"]["money"]) != 1:
            return False
        part, expected = facts["quote"]["money"][0], state["promotion_expected_money"]
        return (part["list_price"]["amount"] == expected["list_price"] and part["final_price"]["amount"] == expected["final_price"]
                and part["promotion"]["state"] == "applied" and part["promotion"]["amount"]["amount"] == expected["promotion"]
                and (part["display_total"] or part["total"])["amount"] == expected["display_total"]
                and all(part[key]["currency"] == expected["currency"] for key in ("list_price", "final_price", "tax", "total")))

    def closed_observation(response, keys):
        payload = decoded(response)
        if response.status != 200 or not public.exact(payload, ("success", "data")) or payload["success"] is not True or not booleans(payload["data"], keys):
            raise RuntimeError("N18 native placement returned an unapproved observation shape")
        return payload["data"]

    stage("native_seed")
    initial = inspection()
    seeded = client.request(state["seed_url"], {"nonce": initial["fixture_nonce"]}, headers)
    if seeded.status != 200 or decoded(seeded).get("success") is not True:
        raise RuntimeError("N18 placement selected native cart seed refused")
    before = inspection()
    if before["facts"]["status"] != "no_quote":
        raise RuntimeError("N18 placement requires its own fresh native session")
    stage("native_refresh")
    refresh = client.post(state["classic_url"], {"_wpnonce": before["review_nonce"], "action": "refresh", "generation": "0", "review_token": str(uuid.uuid4())})
    packet = decoded(refresh)
    issued = packet.get("data") if packet.get("success") is True else None
    if refresh.status != 200 or not promotion(issued) or issued["status"] != "review_required" or not issued["can_confirm"]:
        raise RuntimeError("N18 placement actual refresh did not produce current review")
    current = inspection()
    stage("native_confirm")
    confirm = client.json_post(state["store_extensions_url"], {"namespace": "cetech-delivery-quote-review", "data": {"action": "confirm", "generation": issued["generation"]}}, {"Nonce": current["store_nonce"]})
    confirmed = decoded(confirm).get("extensions", {}).get("cetech-delivery-quote-review")
    after = inspection()
    recorder.check(IDS[0], bool(confirm.status == 200 and promotion(confirmed) and confirmed["status"] == "confirmed" and confirmed["quote"]["status"] == "accepted" and confirmed["quote"]["currently_applicable"] and not confirmed["can_confirm"] and promotion(after["facts"]) and after["facts"]["status"] == "confirmed" and after["history_counts"]["accepted"] == before["history_counts"]["accepted"] + 1 and after["history_counts"]["quotes"] == before["history_counts"]["quotes"] + 1 and after["history_counts"]["bindings"] == before["history_counts"]["bindings"] and after["orders_count"] == before["orders_count"] and after["gateway_count"] == before["gateway_count"] == 0), {
        "fresh_native_session": True, "native_seed_is_setup_only": True,
        "actual_classic_refresh": True, "actual_store_confirm": True,
        "native_nonces": True, "safe_shopper_dto": public.safe_facts(confirmed),
        "declared_promotion_money": promotion(confirmed), "gateway_zero": after["gateway_count"] == 0,
        "history_before": before["history_counts"], "history_after": after["history_counts"],
    })

    before = after
    stage("native_place")
    place = client.request(fixture_mode(state["fixture_url"], "place"), {"nonce": before["fixture_nonce"]}, headers)
    observed = closed_observation(place, PLACED_KEYS)
    after = inspection()
    recorder.check(IDS[1], bool(all(observed.values()) and after["orders_count"] > before["orders_count"] and after["history_counts"]["bindings"] == before["history_counts"]["bindings"] + 1 and after["history_counts"]["accepted"] == before["history_counts"]["accepted"] and after["history_counts"]["quotes"] == before["history_counts"]["quotes"] and after["gateway_count"] == before["gateway_count"] == 0), {
        **observed, "actual_native_http": True, "fixture_post_once": True,
        "closed_source_observation": True, "history_before": before["history_counts"],
        "history_after": after["history_counts"],
    })

    before = after
    stage("fresh_history")
    reads = []
    for _ in range(2):
        response = client.get(fixture_mode(state["fixture_url"], "placed"), headers)
        reads.append(closed_observation(response, HISTORY_KEYS))
    after = inspection()
    recorder.check(IDS[2], bool(all(all(value.values()) for value in reads) and same_history(before, after)), {
        **{key: all(value[key] for value in reads) for key in HISTORY_KEYS},
        "separate_native_http_requests": True, "repeated_read_no_history_rewrite": same_history(before, after),
        "closed_source_observation": True, "history_before": before["history_counts"],
        "history_after": after["history_counts"],
    })
    stage("complete")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--state", type=Path, required=True)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--receipt", type=Path, required=True)
    args = parser.parse_args()
    if args.receipt.exists() or args.receipt.is_symlink():
        raise RuntimeError("N18 refuses replacing a placement receipt")
    state = review.private_state(args.state)
    client = common.HttpClient(args.base_url)
    recorder = common.Recorder(args.receipt, state["identity"])
    recorder.report["format"] = "cetech-n18-promotion-placement-http-v1"
    recorder.report["stage"] = "listener_identity"
    try:
        response = client.get("/?cetech_opening_http_probe=1", {"X-CETECH-Opening-Probe": state["probe_token"]})
        observed = json.loads(response.body)
        if response.status != 200 or any(observed.get(key) != state["identity"][key] for key in ("source_head", "candidate_head", "source_tree")) or observed.get("probe_sha256") != hashlib.sha256(state["probe_token"].encode()).hexdigest() or observed.get("site_path_sha256") != hashlib.sha256(state["site_path"].encode()).hexdigest() or observed.get("database_name_sha256") != hashlib.sha256(state["database_name"].encode()).hexdigest():
            raise RuntimeError("N18 placement owned listener not proved")
        run(client, state, recorder)
        if tuple(item["id"] for item in recorder.report["cases"]) != IDS:
            raise RuntimeError("N18 placement case inventory incomplete")
        recorder.finish("PASS")
    except BaseException as error:
        recorder.finish("FAIL", type(error).__name__)
        raise RuntimeError("N18 placement HTTP qualification failed; private bodies were not published") from None


if __name__ == "__main__":
    main()
