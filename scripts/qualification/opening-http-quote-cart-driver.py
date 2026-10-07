"""Q05 real-session quote review; reads are not acceptance or placement.

All requests use the shared observed twenty-second HTTP client. The private
fixture seeds a real native cart and inspects physical state; only production
nonce-bearing Classic/Store API requests exercise refresh and confirmation.
"""
import hashlib
import json
import os
import re
import subprocess
import uuid
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlencode, urljoin, urlsplit


DIRECT_IDS = (
    "HTTP-W2Q05-CLASSIC-READONLY-CURRENT-NO-ACCEPT",
    "HTTP-W2Q05-CLASSIC-REFRESH-EXPLICIT-REVIEW",
    "HTTP-W2Q05-CLASSIC-CONFIRM-EXPLICIT-ONCE",
    "HTTP-W2Q05-STOREAPI-GET-PATCH-TOTALS-NO-ACCEPT",
    "HTTP-W2Q05-STOREAPI-REFRESH-EXPLICIT-REVIEW",
    "HTTP-W2Q05-STOREAPI-CONFIRM-EXPLICIT-ONCE",
    "HTTP-W2Q05-TWO-GUEST-SESSIONS-PRIVATE-QUOTE-ISOLATION",
    "HTTP-W2Q05-TWO-USER-AUTH-TOKENS-PRIVATE-QUOTE-ISOLATION",
    "HTTP-W2Q05-SOURCE-CHANGE-NEW-PRICE-BEFORE-CONFIRM",
    "HTTP-W2Q05-DTO-PRIVACY-STALE-GENERATION-NO-ACCEPT",
)
BROWSER_IDS = (
    "HTTP-W2Q05-BLOCKS-VISIBLE-REVIEW-CONTROLS",
    "HTTP-W2Q05-BLOCKS-REFRESH-PRICE-REQUIRES-CONFIRM",
    "HTTP-W2Q05-BLOCKS-CONFIRM-ONE-ACCEPT-NO-PLACEMENT",
)
BROWSER_BOOLS = (
    ("real_chromium", "actual_native_blocks_ui", "visible_review_controls", "native_seed_is_setup_only", "read_render_no_quote_acceptance", "no_checkout_post", "no_placement_or_payment"),
    ("actual_refresh_button_clicked", "actual_store_api_extensions_post", "safe_shopper_dto", "native_price_visible", "explicit_confirm_required", "issued_not_accepted", "no_checkout_post", "no_placement_or_payment"),
    ("actual_confirm_button_clicked", "actual_store_api_extensions_post", "safe_shopper_dto", "one_acceptance", "confirmed_visible", "repeat_reads_no_acceptance", "no_checkout_post", "no_placement_or_payment"),
)
STATUS = {"no_quote", "review_required", "confirmed", "expired", "changed", "unconfirmed", "unavailable"}
HISTORY = ("records", "events", "quotes", "accepted", "bindings", "budget")
UUID = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")


def exact(value, keys):
    return isinstance(value, dict) and set(value) == set(keys)


def choice(value, allowed):
    return (value is None or isinstance(value, str)) and value in allowed


def integer(value, low=0, high=9007199254740991):
    return type(value) is int and low <= value <= high


def money(value):
    return (exact(value, ("amount", "currency", "precision"))
            and isinstance(value["amount"], str)
            and re.fullmatch(r"(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?", value["amount"]) is not None
            and isinstance(value["currency"], str) and re.fullmatch(r"[A-Z]{3}", value["currency"]) is not None
            and integer(value["precision"], 0, 6)
            and len(value["amount"].split(".")[1] if "." in value["amount"] else "") <= value["precision"])


def plain(value, maximum=120):
    return isinstance(value, str) and 0 < len(value) <= maximum and re.search(r"[\x00-\x1f\x7f<>]", value) is None


def safe_facts(value):
    """Exact public projection, including nested money; no generic leaf exemption."""
    if not exact(value, ("contract_version", "status", "generation", "quote", "can_refresh", "can_confirm", "can_retry", "message_code", "correlation_id")):
        return False
    if value["contract_version"] != 1 or type(value["contract_version"]) is not int or not choice(value["status"], STATUS) or value["message_code"] != value["status"] or not integer(value["generation"]) or not isinstance(value["correlation_id"], str) or not UUID.fullmatch(value["correlation_id"]):
        return False
    if any(type(value[key]) is not bool for key in ("can_refresh", "can_confirm", "can_retry")):
        return False
    quote = value["quote"]
    if quote is not None:
        if not exact(quote, ("contract_version", "decision_kind", "quote_id", "status", "currently_applicable", "expires_at", "customer_label", "money", "reason_code", "recovery_action", "correlation_id")):
            return False
        if type(quote["contract_version"]) is not int or quote["contract_version"] != 1 or quote["decision_kind"] != "delivery_quote" or not choice(quote["status"], {"issued", "accepted", "invalidated", "expired", "stripped"}) or type(quote["currently_applicable"]) is not bool or not plain(quote["customer_label"]):
            return False
        if any(not isinstance(quote[key], str) or not UUID.fullmatch(quote[key]) for key in ("quote_id", "correlation_id")):
            return False
        if not isinstance(quote["expires_at"], str) or not re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?Z", quote["expires_at"]) or not choice(quote["reason_code"], {None, "quote_expired", "quote_invalidated", "quote_unavailable"}) or not choice(quote["recovery_action"], {None, "refresh_and_review", "retry_later"}):
            return False
        if not isinstance(quote["money"], list) or len(quote["money"]) > 200:
            return False
        for part in quote["money"]:
            if not exact(part, ("customer_label", "list_price", "promotion", "final_price", "tax", "rounded_tax", "total", "display_total")) or not plain(part["customer_label"]) or any(not money(part[key]) for key in ("list_price", "final_price", "tax", "total")) or any(part[key] is not None and not money(part[key]) for key in ("rounded_tax", "display_total")):
                return False
            promo = part["promotion"]
            if not exact(promo, ("state", "amount")) or not choice(promo["state"], {"none", "applied", "unavailable"}) or (promo["amount"] is not None if promo["state"] == "unavailable" else not money(promo["amount"])):
                return False
    if value["can_confirm"] and (value["status"] != "review_required" or quote is None or not quote["currently_applicable"]):
        return False
    # Bounded exact schema plus sentinel bans protect labels and renamed values too.
    encoded = json.dumps(value, ensure_ascii=False, separators=(",", ":"))
    return len(encoded.encode()) <= 65536 and not any(marker in encoded for marker in ("PRIVATE-", "acceptance_handle", "owner_digest", "session_hash", "principal_hash", "body_digest", "material_digest", "origin_id", "supplier", "rate_card", "provider_json", "issue_context_json", "review_token"))


class ReviewPage(HTMLParser):
    def __init__(self, body):
        super().__init__(convert_charrefs=True)
        self.facts = []
        self.feed(body.decode("utf-8", "strict") if isinstance(body, bytes) else body)
        self.close()

    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        if values.get("data-quote-review-transport") == "classic":
            try:
                self.facts.append(json.loads(values.get("data-quote-review-facts", "null")))
            except (ValueError, TypeError):
                self.facts.append(None)


def native_failure_observation(value):
    """Exact original observer fields only; no exception text or private facts."""
    booleans = ("prepare_entered", "prepare_returned", "evidence_called", "evidence_returned", "native_chosen_cache_present", "native_totals_cache_present", "native_shipping_cache_present")
    counters = ("source_reads", "quote_writes", "budget_writes")
    keys = ("observation", "prepare_error_class", "prepare_refusal_site", "prepare_refusal_line", "native_shipping_debug_enabled", *booleans, *counters)
    if not exact(value, keys) or value["observation"] != "original_native_attempt":
        return False
    if any(type(value[key]) is not bool for key in booleans) or any(not integer(value[key], 0, 1000000) for key in counters):
        return False
    if value["native_shipping_debug_enabled"] is not None and type(value["native_shipping_debug_enabled"]) is not bool:
        return False
    if not choice(value["prepare_error_class"], {None, "RuntimeException", "InvalidArgumentException", "Error"}):
        return False
    if not choice(value["prepare_refusal_site"], {None, "native_environment", "native_shipping", "native_preparation", "legacy_source", "source_local_binding", "native_context", "native_receipt", "source_snapshot"}):
        return False
    if value["prepare_refusal_site"] is None:
        if value["prepare_refusal_line"] is not None:
            return False
    elif not integer(value["prepare_refusal_line"], 1, 100000):
        return False
    if value["prepare_returned"] and not value["prepare_entered"] or value["evidence_returned"] and not value["evidence_called"]:
        return False
    if value["prepare_error_class"] is not None and (not value["prepare_entered"] or value["prepare_returned"]):
        return False
    if value["prepare_refusal_site"] is not None and value["prepare_error_class"] is None:
        return False
    return value["prepare_entered"] and not value["prepare_returned"] or value["evidence_called"] and not value["evidence_returned"]


def run_quote_cart(client, state, bridge, recorder, Page, login):
    del login  # Its admin-only redirects and four extra IDs do not apply to shopper jars.
    stage = "ownership"
    active_case = DIRECT_IDS[0]
    headers = {"X-Cetech-Q05-Fixture": state["fixture_token"]}

    def request_json(response):
        value = json.loads(response.body)
        if not isinstance(value, dict):
            raise RuntimeError("Native quote response was not an object")
        return value

    def inspect(shopper):
        value = request_json(shopper.get(state["fixture_url"], headers))
        if value.get("success") is not True or not isinstance(value.get("data"), dict):
            raise RuntimeError("Owned native quote fixture inspection refused")
        data = value["data"]
        identity = data.get("source_identity", {})
        if any(identity.get(key) != state["identity"].get(key) for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash")):
            raise RuntimeError("Native quote fixture installed identity mismatch")
        if not exact(data.get("history_counts"), HISTORY) or any(not integer(data["history_counts"][key]) for key in HISTORY):
            raise RuntimeError("Native quote fixture counts were not finite")
        return data

    def seeded(shopper):
        before = inspect(shopper)
        response = shopper.request(state["seed_url"], {"nonce": before["fixture_nonce"]}, headers)
        value = request_json(response)
        if response.status != 200 or value.get("success") is not True:
            raise RuntimeError("Native quote cart seed refused")
        return inspect(shopper)

    def no_placement(before, after):
        return before["history_counts"]["bindings"] == after["history_counts"]["bindings"] and before["orders_count"] == after["orders_count"] and before["gateway_count"] == after["gateway_count"]

    def unchanged(before, after):
        return before["history_counts"] == after["history_counts"] and no_placement(before, after)

    def check(case, condition, before, after, components=None, response=None):
        evidence = {"history_before": before["history_counts"], "history_after": after["history_counts"], "no_placement_or_payment": no_placement(before, after), "components": components or {}}
        if response is not None:
            evidence["http"] = response.evidence()
        if not condition and "failure_observation" in after:
            diagnostic = after["failure_observation"]
            if not native_failure_observation(diagnostic):
                raise RuntimeError("Native quote failure observation was not finite")
            evidence["failure_observation"] = diagnostic
        recorder.check(case, bool(condition and no_placement(before, after)), evidence)

    def classic(shopper, action, generation, token=None, extra=None):
        current = inspect(shopper)
        fields = {"_wpnonce": current["review_nonce"], "action": action, "generation": str(generation)}
        if token is not None:
            fields["review_token"] = token
        if extra:
            fields.update(extra)
        response = shopper.post(state["classic_url"], fields)
        value = request_json(response)
        facts = value.get("data") if value.get("success") is True else None
        return response, facts

    def cart(shopper):
        response = shopper.get(state["store_cart_url"], {"Nonce": inspect(shopper)["store_nonce"]})
        value = request_json(response)
        facts = value.get("extensions", {}).get("cetech-delivery-quote-review")
        return response, facts

    def store(shopper, action, generation, token=None):
        data = {"action": action, "generation": generation}
        if token is not None:
            data["review_token"] = token
        response = shopper.json_post(state["store_extensions_url"], {"namespace": "cetech-delivery-quote-review", "data": data}, {"Nonce": inspect(shopper)["store_nonce"]})
        value = request_json(response)
        return response, value.get("extensions", {}).get("cetech-delivery-quote-review")

    def login_shopper(shopper):
        destination = shopper.resolve(state["classic_page_url"])
        form_response = shopper.get("/wp-login.php?" + urlencode({"redirect_to": destination}))
        forms = [form for form in form_response.page().forms if form.attrs.get("id") == "loginform"]
        if form_response.status != 200 or len(forms) != 1 or not any(cookie.name == "wordpress_test_cookie" for cookie in shopper.cookies):
            raise RuntimeError("Actual shopper login form was not rendered")
        fields = dict(forms[0].fields, log=state["username"], pwd=state["password"], redirect_to=destination, testcookie="1")
        response = shopper.post(forms[0].attrs.get("action") or "/wp-login.php", fields)
        cookies = tuple(cookie.value for cookie in shopper.cookies if cookie.name.startswith("wordpress_logged_in_"))
        if response.status != 302 or shopper.location(response) != destination or len(cookies) != 1:
            raise RuntimeError("Actual shopper login session was not established")
        return cookies[0]

    try:
        # Credentials and cart mutation only follow the fixed listener/site/source ownership proof.
        probe = client.get("/?cetech_opening_http_probe=1", {"X-CETECH-Opening-Probe": state["probe_token"]})
        identity = request_json(probe)
        if probe.status != 200 or identity.get("probe_sha256") != hashlib.sha256(state["probe_token"].encode()).hexdigest() or identity.get("site_path_sha256") != hashlib.sha256(state["site_path"].encode()).hexdigest() or identity.get("database_name_sha256") != hashlib.sha256(state["database_name"].encode()).hexdigest() or any(identity.get(key) != state["identity"].get(key) for key in ("source_head", "candidate_head", "source_tree")):
            raise RuntimeError("Owned quote listener was not confirmed")
        guest_a = type(client)(state["base_url"])
        guest_b = type(client)(state["base_url"])
        bridge.call("classicpagequotecart")
        stage = "classic_seed"; before = seeded(guest_a)
        stage = "classic_read"; rendered = guest_a.get(state["classic_page_url"]); rendered_facts = ReviewPage(rendered.body).facts
        denied_get = guest_a.get(state["classic_url"])
        after = inspect(guest_a)
        check(active_case, rendered.status == 200 and bool(rendered_facts) and all(safe_facts(item) and item["status"] == "no_quote" for item in rendered_facts) and denied_get.status == 405 and unchanged(before, after), before, after,
              {"actual_classic_page": rendered.status == 200, "read_projects_no_quote": bool(rendered_facts) and all(item and item.get("status") == "no_quote" for item in rendered_facts), "get_action_refused": denied_get.status == 405, "history_unchanged": unchanged(before, after)})

        active_case = DIRECT_IDS[1]; stage = "classic_refresh"; token_a = str(uuid.uuid4()); before = inspect(guest_a)
        response, review_a = classic(guest_a, "refresh", 0, token_a); after = inspect(guest_a)
        check(active_case, response.status == 200 and safe_facts(review_a) and review_a["status"] == "review_required" and review_a["can_confirm"] and after["history_counts"]["quotes"] == before["history_counts"]["quotes"] + 1 and after["history_counts"]["accepted"] == before["history_counts"]["accepted"], before, after,
              {"explicit_refresh_post": True, "review_required": bool(review_a and review_a.get("status") == "review_required"), "still_unaccepted": after["history_counts"]["accepted"] == before["history_counts"]["accepted"], "safe_dto": safe_facts(review_a)}, response)

        active_case = DIRECT_IDS[2]; stage = "classic_confirm"; before = inspect(guest_a)
        response, confirmed_a = classic(guest_a, "confirm", review_a["generation"]); accepted = inspect(guest_a)
        replay_response, replay_a = classic(guest_a, "confirm", review_a["generation"]); after = inspect(guest_a)
        check(active_case, response.status == replay_response.status == 200 and safe_facts(confirmed_a) and safe_facts(replay_a) and confirmed_a["status"] == replay_a["status"] == "confirmed" and accepted["history_counts"]["accepted"] == before["history_counts"]["accepted"] + 1 and unchanged(accepted, after), before, after,
              {"explicit_confirm_post": True, "confirmed_once": accepted["history_counts"]["accepted"] == before["history_counts"]["accepted"] + 1, "replay_no_effect_or_audit": unchanged(accepted, after)}, response)

        active_case = DIRECT_IDS[3]; stage = "store_seed"; before = seeded(guest_b)
        stage = "store_read"; get_response, get_facts = cart(guest_b)
        totals = guest_b.json_post(state["store_customer_url"], {"billing_address": {"country": "GH", "state": "AA", "city": "Accra", "postcode": "00001"}, "shipping_address": {"country": "GH", "state": "AA", "city": "Accra", "postcode": "00001"}}, {"Nonce": inspect(guest_b)["store_nonce"]})
        totals_value = request_json(totals); totals_facts = totals_value.get("extensions", {}).get("cetech-delivery-quote-review")
        patched = guest_b.json_patch(state["store_cart_url"], {}, {"Nonce": inspect(guest_b)["store_nonce"]})
        after = inspect(guest_b)
        check(active_case, get_response.status == totals.status == 200 and safe_facts(get_facts) and safe_facts(totals_facts) and get_facts["status"] == totals_facts["status"] == "no_quote" and patched.status in (404, 405) and unchanged(before, after), before, after,
              {"real_store_api_get": get_response.status == 200, "actual_native_update_customer_totals": totals.status == 200, "unsupported_native_cart_patch_refused": patched.status in (404, 405), "patch_is_not_a_supported_totals_update": True, "read_totals_no_acceptance": unchanged(before, after)})

        active_case = DIRECT_IDS[4]; stage = "store_refresh"; before = inspect(guest_b)
        response, review_b = store(guest_b, "refresh", 0, str(uuid.uuid4())); after = inspect(guest_b)
        check(active_case, response.status == 200 and safe_facts(review_b) and review_b["status"] == "review_required" and review_b["can_confirm"] and after["history_counts"]["quotes"] == before["history_counts"]["quotes"] + 1 and after["history_counts"]["accepted"] == before["history_counts"]["accepted"], before, after,
              {"actual_nonce_store_extensions_post": True, "review_required": bool(review_b and review_b.get("status") == "review_required"), "safe_dto": safe_facts(review_b)}, response)

        active_case = DIRECT_IDS[5]; stage = "store_confirm"; before = inspect(guest_b)
        response, confirmed_b = store(guest_b, "confirm", review_b["generation"]); accepted = inspect(guest_b)
        replay_response, replay_b = store(guest_b, "confirm", review_b["generation"]); after = inspect(guest_b)
        check(active_case, response.status == replay_response.status == 200 and safe_facts(confirmed_b) and safe_facts(replay_b) and confirmed_b["status"] == replay_b["status"] == "confirmed" and accepted["history_counts"]["accepted"] == before["history_counts"]["accepted"] + 1 and unchanged(accepted, after), before, after,
              {"explicit_confirm_post": True, "confirmed_once": accepted["history_counts"]["accepted"] == before["history_counts"]["accepted"] + 1, "replay_no_effect_or_audit": unchanged(accepted, after)}, response)

        active_case = DIRECT_IDS[6]; stage = "guest_isolation"; a = inspect(guest_a); before = inspect(guest_b)
        stolen, _ = classic(guest_b, "confirm", review_a["generation"], extra={"quote_id": review_a["quote"]["quote_id"]}); after = inspect(guest_b)
        check(active_case, a["owner_digest"] != before["owner_digest"] and review_a["quote"]["quote_id"] != review_b["quote"]["quote_id"] and stolen.status == 400 and unchanged(before, after), before, after,
              {"two_actual_guest_cookie_jars": True, "owners_differ": a["owner_digest"] != before["owner_digest"], "opaque_quote_ids_differ": review_a["quote"]["quote_id"] != review_b["quote"]["quote_id"], "stolen_quote_input_refused": stolen.status == 400, "no_cross_session_effect": unchanged(before, after)}, stolen)

        active_case = DIRECT_IDS[7]; stage = "two_native_logins"; user_a = type(client)(state["base_url"]); user_b = type(client)(state["base_url"])
        cookie_a = login_shopper(user_a); cookie_b = login_shopper(user_b)
        seeded(user_a); seeded(user_b); before = inspect(user_a)
        response, user_review = classic(user_a, "refresh", 0, str(uuid.uuid4())); owned = inspect(user_a); other = inspect(user_b)
        stolen_nonce = user_b.post(state["classic_url"], {"_wpnonce": owned["review_nonce"], "action": "confirm", "generation": str(user_review["generation"])})
        after_stolen_nonce = inspect(user_b)
        other_response, other_facts = classic(user_b, "confirm", user_review["generation"]); after = inspect(user_b)
        check(active_case, cookie_a != cookie_b and owned["owner_digest"] != other["owner_digest"] and response.status == 200 and safe_facts(user_review) and user_review["status"] == "review_required" and other["facts"]["status"] == "no_quote" and stolen_nonce.status == 403 and unchanged(other, after_stolen_nonce) and other_response.status == 200 and safe_facts(other_facts) and other_facts["status"] != "confirmed" and unchanged(other, after) and before["history_counts"]["accepted"] == after["history_counts"]["accepted"] and owned["native_user_id"] == other["native_user_id"] == state["user_id"], before, after,
              {"same_actual_wordpress_user": owned["native_user_id"] == other["native_user_id"] == state["user_id"], "distinct_native_login_tokens": cookie_a != cookie_b, "distinct_quote_owners": owned["owner_digest"] != other["owner_digest"], "second_session_no_quote": other["facts"]["status"] == "no_quote", "stolen_other_login_nonce_refused": stolen_nonce.status == 403, "stolen_nonce_no_effect": unchanged(other, after_stolen_nonce), "foreign_generation_cannot_accept": bool(other_facts and other_facts.get("status") != "confirmed"), "no_acceptance": before["history_counts"]["accepted"] == after["history_counts"]["accepted"]})

        active_case = DIRECT_IDS[8]; stage = "source_change"; before = inspect(guest_a); original_body = before["quote_body_digest"]; choice = before["choice_digest"]
        bridge.call("ratechangequotecart"); after_change = inspect(guest_a)
        if after_change["facts"]["status"] not in ("changed", "unavailable"):
            raise RuntimeError("Changed physical quote source was not refused")
        seeded(guest_a)
        response, new_review = classic(guest_a, "refresh", review_a["generation"], str(uuid.uuid4())); after = inspect(guest_a)
        amount = new_review["quote"]["money"][0]["display_total"]["amount"] if safe_facts(new_review) and new_review["quote"] and new_review["quote"]["money"] else None
        original_preserved = original_body == after["quote_body_digests"].get(review_a["quote"]["quote_id"])
        check(active_case, response.status == 200 and safe_facts(new_review) and new_review["status"] == "review_required" and new_review["quote"]["quote_id"] != review_a["quote"]["quote_id"] and amount == "9.90" and after["facts"]["quote"]["status"] == "issued" and before["history_counts"]["accepted"] == after["history_counts"]["accepted"] and choice == after["choice_digest"] and original_preserved, before, after,
              {"changed_source_refused_before_refresh": after_change["facts"]["status"] in ("changed", "unavailable"), "new_price_visible_before_confirmation": amount == "9.90", "new_quote_still_issued": after["facts"]["quote"]["status"] == "issued", "choices_destination_retained": choice == after["choice_digest"], "previous_quote_body_unchanged": original_preserved}, response)

        active_case = DIRECT_IDS[9]; stage = "stale_generation"; before = inspect(guest_a)
        response, stale = classic(guest_a, "confirm", review_a["generation"]); after = inspect(guest_a)
        privacy = all(safe_facts(item) for item in (review_a, confirmed_a, review_b, confirmed_b, user_review, other_facts, new_review, stale))
        check(active_case, response.status == 200 and safe_facts(stale) and stale["status"] == "changed" and unchanged(before, after) and privacy and after["facts"]["quote"]["status"] == "issued", before, after,
              {"exact_nested_shopper_schema": privacy, "private_references_owners_sources_addresses_absent": privacy, "stale_generation_no_acceptance": unchanged(before, after), "new_price_remains_unaccepted": after["facts"]["quote"]["status"] == "issued"}, response)
        bridge.call("restorepricequotecart"); bridge.call("blockspagequotecart")
        stage = "browser"; active_case = BROWSER_IDS[0]
        run_browser(state, recorder, client)
    except Exception as error:
        if not any(item["id"] == active_case for item in recorder.report["cases"]):
            diagnostic = {"stage": stage, "error_class": type(error).__name__ if type(error).__name__ in {"RuntimeError", "ValueError", "TypeError", "KeyError", "JSONDecodeError", "TimeoutError", "UnicodeDecodeError"} else "OtherError", "required_case_incomplete": True}
            try:
                recorder.check(active_case, False, diagnostic)
            except RuntimeError:
                pass
        raise


def browser_evidence(case):
    """No arbitrary strings, nested leaves or credentials enter the parent receipt."""
    if not exact(case, ("id", "status", "evidence")) or case["id"] not in BROWSER_IDS or case["status"] not in ("PASS", "FAIL") or not isinstance(case["evidence"], dict):
        return False
    index = BROWSER_IDS.index(case["id"])
    evidence = case["evidence"]
    basic = set(BROWSER_BOOLS[index]) | {"history_before", "history_after"} | ({"runtime"} if index == 0 else {"status"})
    diagnostic = {"stage", "error_class", "dom", "required_case_incomplete"}
    if set(evidence) - basic - diagnostic:
        return False
    if case["status"] == "PASS" and set(evidence) != basic:
        return False
    if not basic.issubset(evidence) and case["status"] == "FAIL" and set(evidence) != diagnostic:
        return False
    for key in BROWSER_BOOLS[index]:
        if key in evidence and (type(evidence[key]) is not bool or (case["status"] == "PASS" and not evidence[key])):
            return False
    for key in ("history_before", "history_after"):
        if key in evidence and (not exact(evidence[key], HISTORY) or any(not integer(evidence[key][count]) for count in HISTORY)):
            return False
    if "status" in evidence and (not integer(evidence["status"], 100, 599) or (case["status"] == "PASS" and evidence["status"] != 200)):
        return False
    if "runtime" in evidence:
        runtime = evidence["runtime"]
        if not exact(runtime, ("playwright", "chromium")) or runtime["playwright"] != "1.58.2" or not isinstance(runtime["chromium"], str) or re.fullmatch(r"[0-9]{1,4}(?:\.[0-9]{1,8}){1,3}", runtime["chromium"]) is None:
            return False
    if diagnostic & set(evidence):
        if case["status"] != "FAIL" or not {"stage", "error_class", "dom"}.issubset(evidence) or not choice(evidence["stage"], {"ownership", "login", "fixture", "render", "refresh", "confirm", "complete"}) or not choice(evidence["error_class"], {"Error", "SyntaxError", "TypeError", "TimeoutError"}) or not exact(evidence["dom"], ("blocks_visible", "review_visible", "refresh_visible", "confirm_visible", "price_visible", "confirmed_visible")) or any(type(value) is not bool for value in evidence["dom"].values()):
            return False
        if "required_case_incomplete" in evidence and evidence["required_case_incomplete"] is not True:
            return False
    return True


def run_browser(state, recorder, client):
    """Import bounded partial browser evidence before rejecting a failed UI run."""
    state_path = state.get("browser_state_path", state.get("state_path"))
    script = Path(__file__).with_name("opening-quote-cart-browser.cjs")
    if not state_path or not Path(state_path).is_file():
        raise RuntimeError("Private quote browser state path was not provided")
    receipt_path = Path(state_path).with_name("quote-cart-browser-receipt.json")
    if receipt_path.exists():
        raise RuntimeError("Refusing to replace prior quote browser evidence")
    result = subprocess.run(["node", str(script), "--state", str(state_path), "--receipt", str(receipt_path), "--base-url", client.base_url], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=180, check=False)
    if not receipt_path.is_file() or receipt_path.stat().st_size > 65536:
        raise RuntimeError("Bounded quote browser receipt was not produced")
    report = json.loads(receipt_path.read_text(encoding="utf-8"))
    if report.get("format") != "cetech-w2q05-cart-browser-v1" or any(report.get(key) != state["identity"].get(key) for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash")) or not isinstance(report.get("cases"), list) or len(report["cases"]) > 3:
        raise RuntimeError("Quote browser receipt identity/schema mismatch")
    seen = set()
    for case in report["cases"]:
        if not browser_evidence(case) or case["id"] in seen or case["id"] != BROWSER_IDS[len(seen)]:
            raise RuntimeError("Quote browser receipt case schema mismatch")
        seen.add(case["id"])
        recorder.check(case["id"], case["status"] == "PASS", case["evidence"])
    if result.returncode != 0 or report.get("status") != "PASS" or seen != set(BROWSER_IDS) or any(case["status"] != "PASS" for case in report["cases"]):
        raise RuntimeError("Actual quote browser flow did not complete")
