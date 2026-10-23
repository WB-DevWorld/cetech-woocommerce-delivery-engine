"""N18 direct native HTTP review proof on an explicitly owned Q05-compatible fixture.

Fixture seeding only prepares a real Woo session. Production Classic and Store
API routes perform every review mutation; no quote/service spy is accepted.
The receipt is separate from all retained qualification inventories.
"""
import argparse
import importlib.util
import json
import sys
import uuid
from pathlib import Path


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).with_name(filename))
    value = importlib.util.module_from_spec(spec)
    sys.modules[name] = value
    spec.loader.exec_module(value)
    return value


common = module("cetech_n18_http_common", "opening-http-driver.py")
public = module("cetech_n18_quote_public", "opening-http-quote-cart-driver.py")
IDS = (
    "HTTP-N18-PROMOTION-READONLY",
    "HTTP-N18-PROMOTION-NATIVE-NONCES",
    "HTTP-N18-PROMOTION-CLASSIC-REFRESH",
    "HTTP-N18-PROMOTION-PRIVATE-AND-STALE-REFUSAL",
    "HTTP-N18-PROMOTION-GUEST-OWNER-ISOLATION",
    "HTTP-N18-PROMOTION-STORE-CONFIRM",
    "HTTP-N18-PROMOTION-REPEAT-READ",
)


def private_state(path):
    if path.is_symlink() or not path.is_file() or path.stat().st_mode & 0o077 or path.stat().st_size > 262144:
        raise RuntimeError("N18 requires a private bounded native fixture state")
    state = json.loads(path.read_text())
    expected = state.get("promotion_expected_money")
    if not isinstance(expected, dict) or set(expected) != {"currency", "list_price", "promotion", "final_price", "display_total"}:
        raise RuntimeError("N18 declared promotion fixture economics absent")
    for key in ("list_price", "promotion", "final_price"):
        if not public.money({"amount": expected[key], "currency": expected["currency"], "precision": 6}):
            raise RuntimeError("N18 promotion economics are not bounded money")
    if not public.money({"amount": expected["display_total"], "currency": expected["currency"], "precision": 2}):
        raise RuntimeError("N18 display economics are not bounded native money")
    return state


def run(client, state, recorder):
    headers = {"X-Cetech-Q05-Fixture": state["fixture_token"]}

    def decoded(response):
        value = json.loads(response.body)
        if not isinstance(value, dict):
            raise RuntimeError("N18 native response is not an object")
        return value

    def inspect(shopper):
        response = shopper.get(state["fixture_url"], headers)
        value = decoded(response)
        data = value.get("data")
        if response.status != 200 or value.get("success") is not True or not isinstance(data, dict):
            raise RuntimeError("N18 owned native inspection refused")
        if any(data.get("source_identity", {}).get(key) != state["identity"][key] for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash")):
            raise RuntimeError("N18 native source identity mismatch")
        if not public.exact(data.get("history_counts"), public.HISTORY) or any(not public.integer(data["history_counts"][key]) for key in public.HISTORY) or any(not public.integer(data.get(key)) for key in ("orders_count", "gateway_count")) or not public.safe_facts(data.get("facts")):
            raise RuntimeError("N18 native observations are not finite")
        return data

    def seed(shopper):
        initial = inspect(shopper)
        response = shopper.request(state["seed_url"], {"nonce": initial["fixture_nonce"]}, headers)
        if response.status != 200 or decoded(response).get("success") is not True:
            raise RuntimeError("N18 native cart seed refused")
        return inspect(shopper)

    def no_placement(before, after):
        return before["orders_count"] == after["orders_count"] and before["gateway_count"] == after["gateway_count"] == 0 and before["history_counts"]["bindings"] == after["history_counts"]["bindings"]

    def same(before, after):
        return before["history_counts"] == after["history_counts"] and no_placement(before, after)

    def check(index, condition, before, after, **facts):
        recorder.check(IDS[index], bool(condition and no_placement(before, after)), {"history_before": before["history_counts"], "history_after": after["history_counts"], "zero_gateway": after["gateway_count"] == 0, "no_placement": no_placement(before, after), **facts})

    def promotion(facts):
        if not public.safe_facts(facts) or not facts["quote"] or len(facts["quote"]["money"]) != 1:
            return False
        part = facts["quote"]["money"][0]
        expected = state["promotion_expected_money"]
        return (part["list_price"]["amount"] == expected["list_price"] and part["final_price"]["amount"] == expected["final_price"]
                and part["promotion"]["state"] == "applied" and part["promotion"]["amount"]["amount"] == expected["promotion"]
                and (part["display_total"] or part["total"])["amount"] == expected["display_total"]
                and all(part[key]["currency"] == expected["currency"] for key in ("list_price", "final_price", "tax", "total")))

    def classic(fields, nonce=None):
        fields = dict(fields, _wpnonce=nonce if nonce is not None else inspect(client)["review_nonce"])
        response = client.post(state["classic_url"], fields)
        value = decoded(response)
        return response, value.get("data") if value.get("success") is True else None

    def store(shopper, fields, nonce=None):
        response = shopper.json_post(state["store_extensions_url"], {"namespace": "cetech-delivery-quote-review", "data": fields}, {"Nonce": nonce if nonce is not None else inspect(shopper)["store_nonce"]})
        return response, decoded(response).get("extensions", {}).get("cetech-delivery-quote-review")

    # Fixture seeding refreshes Woo's site-wide shipping cache version. Prepare
    # both owners before issuing the primary quote so the isolation setup cannot
    # invalidate the native calculation whose current authority we later prove.
    other = common.HttpClient(client.base_url)
    seed(other)

    # Read-only rendering and repeated real Store GETs must not issue a quote.
    before = seed(client)
    page = client.get(state["classic_page_url"])
    cart = client.get(state["store_cart_url"], {"Nonce": before["store_nonce"]})
    facts = decoded(cart).get("extensions", {}).get("cetech-delivery-quote-review")
    after = inspect(client)
    check(0, page.status == cart.status == 200 and public.safe_facts(facts) and facts["status"] == "no_quote" and same(before, after), before, after, actual_native_http=True, native_seed_is_setup_only=True)

    before = after
    classic_bad, _ = classic({"action": "confirm", "generation": "0"}, "invalid-native-nonce")
    store_bad, _ = store(client, {"action": "confirm", "generation": 0}, "invalid-native-nonce")
    after = inspect(client)
    check(1, classic_bad.status == store_bad.status == 403 and same(before, after), before, after, classic_status=classic_bad.status, store_status=store_bad.status, real_native_nonce_layer=True)

    before = after
    response, facts = classic({"action": "refresh", "generation": "0", "review_token": str(uuid.uuid4())})
    after = inspect(client)
    check(2, response.status == 200 and promotion(facts) and facts["status"] == "review_required" and facts["quote"]["status"] == "issued" and facts["can_confirm"] and after["history_counts"]["quotes"] == before["history_counts"]["quotes"] + 1 and after["history_counts"]["accepted"] == before["history_counts"]["accepted"], before, after, declared_promotion_money=promotion(facts), safe_shopper_dto=public.safe_facts(facts), http=response.evidence())
    generation = facts["generation"]

    before = after
    extra, _ = classic({"action": "confirm", "generation": str(generation), "account_key": "a" * 64})
    stale, stale_facts = classic({"action": "confirm", "generation": str(generation - 1)})
    after = inspect(client)
    check(3, extra.status == 400 and stale.status == 200 and public.safe_facts(stale_facts) and stale_facts["status"] != "confirmed" and same(before, after), before, after, extra_authority_refused=True, stale_generation_refused=True)

    other_before = inspect(other)
    foreign, foreign_facts = store(other, {"action": "confirm", "generation": generation})
    other_after = inspect(other)
    after = inspect(client)
    check(4, before["owner_digest"] != other_after["owner_digest"] and other_before["facts"]["status"] == "no_quote" and foreign.status == 200 and public.safe_facts(foreign_facts) and foreign_facts["status"] != "confirmed" and same(before, after) and same(other_before, other_after) and promotion(after["facts"]) and after["facts"]["status"] == "review_required" and after["facts"]["can_confirm"] and after["facts"]["generation"] == generation, before, after, distinct_native_owner=True, foreign_session_has_no_quote=True)

    before = after
    response, facts = store(client, {"action": "confirm", "generation": generation})
    after = inspect(client)
    check(5, response.status == 200 and promotion(facts) and facts["status"] == "confirmed" and facts["quote"]["status"] == "accepted" and not facts["can_confirm"] and after["history_counts"]["accepted"] == before["history_counts"]["accepted"] + 1, before, after, safe_shopper_dto=public.safe_facts(facts), declared_promotion_money=promotion(facts), http=response.evidence())

    before = after
    for _ in range(2):
        cart = client.get(state["store_cart_url"], {"Nonce": inspect(client)["store_nonce"]})
        facts = decoded(cart).get("extensions", {}).get("cetech-delivery-quote-review")
        if cart.status != 200 or not promotion(facts) or facts["status"] != "confirmed":
            raise RuntimeError("N18 repeated current read lost exact confirmed projection")
    after = inspect(client)
    check(6, same(before, after), before, after, repeated_http_reads=True, no_duplicate_acceptance=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--state", type=Path, required=True)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--receipt", type=Path, required=True)
    args = parser.parse_args()
    if args.receipt.exists() or args.receipt.is_symlink():
        raise RuntimeError("N18 refuses to replace an HTTP receipt")
    state = private_state(args.state)
    client = common.HttpClient(args.base_url)
    recorder = common.Recorder(args.receipt, state["identity"])
    recorder.report["format"] = "cetech-n18-promotion-cart-http-v1"
    try:
        # Authenticate the actual marked listener before sending fixture secrets.
        response = client.get("/?cetech_opening_http_probe=1", {"X-CETECH-Opening-Probe": state["probe_token"]})
        observed = json.loads(response.body)
        import hashlib
        if response.status != 200 or any(observed.get(key) != state["identity"][key] for key in ("source_head", "candidate_head", "source_tree")) or observed.get("probe_sha256") != hashlib.sha256(state["probe_token"].encode()).hexdigest() or observed.get("site_path_sha256") != hashlib.sha256(state["site_path"].encode()).hexdigest() or observed.get("database_name_sha256") != hashlib.sha256(state["database_name"].encode()).hexdigest():
            raise RuntimeError("N18 owned listener identity not proved")
        run(client, state, recorder)
        if tuple(item["id"] for item in recorder.report["cases"]) != IDS:
            raise RuntimeError("N18 HTTP case inventory is incomplete")
        recorder.finish("PASS")
    except BaseException as error:
        recorder.finish("FAIL", type(error).__name__)
        raise RuntimeError("N18 HTTP qualification failed; raw request facts remain private") from None


if __name__ == "__main__":
    main()
