"""C07 real authenticated handlers, checkout, Store API and order-pay qualification.

Uses the shared observed 20-second HTTP transport. Private fixture cart preparation
is explicit; only the subsequent checkout/payment requests are admission proofs.
"""
import copy
import json
import os
import subprocess
import uuid
from pathlib import Path
from urllib.parse import urljoin, urlsplit


def run_emergency(client, state, bridge, recorder, Page, login):
    prefix = "HTTP-C07-"
    settings = client.resolve("/wp-admin/admin.php?page=cetech-delivery-engine-settings")
    action = "cetech_de_change_checkout_control"
    private_headers = {"X-CETECH-C07-FIXTURE": state["fixture_token"]}

    def snapshot():
        return bridge.call("snapshotemergency")["snapshot"]

    def follow(response):
        return client.get(client.location(response)) if response.status in (302, 303) else response

    def unchanged(before, after):
        return all(before[k] == after[k] for k in ("control_hash", "revision", "events_count", "protected_order_hash", "preserved_hashes", "gateway_count"))

    def evidence(before, after, response=None):
        value = {"before": {k: before[k] for k in ("state", "revision", "control_hash", "events_count", "gateway_count", "protected_order_hash")},
                 "after": {k: after[k] for k in ("state", "revision", "control_hash", "events_count", "gateway_count", "protected_order_hash")}}
        if response is not None:
            value["http"] = response.evidence()
        return value

    def form(response):
        forms = [x for x in response.page().forms if x.attrs.get("id") == "cetech-de-emergency-control"]
        if len(forms) != 1 or forms[0].fields.get("cetech_de_action") != action or not forms[0].fields.get("cetech_de_nonce"):
            raise RuntimeError("Expected the actual nonce-bearing emergency settings form")
        item = forms[0]
        item.destination = client.resolve(urljoin(response.url, item.attrs.get("action") or response.url))
        return item

    def submit(item, desired=None, reason=None, changes=None):
        fields = copy.deepcopy(item.fields)
        if desired is not None:
            fields.update(desired_state=desired, reason_code=reason)
        if changes:
            fields.update(changes)
        bridge.call("trackemergency", fields["request_token"])
        return client.post(item.destination, fields)

    def fixture(mode="inspect", fields=None):
        response = client.request("/?cetech_c07_fixture=" + mode, fields, private_headers)
        value = json.loads(response.body)
        if response.status != 200 or value.get("success") is not True:
            raise RuntimeError("C07 private native cart fixture refused preparation")
        return value["data"], response

    def seed(scenario):
        bridge.call("resumeemergency")
        current, _ = fixture()
        return fixture("seed", {"nonce": current["nonce"], "scenario": scenario})[0]

    def checkout_fields(page, prepared):
        candidates = [x for x in page.forms if "woocommerce-process-checkout-nonce" in x.fields]
        if len(candidates) != 1:
            raise RuntimeError("Actual Classic checkout nonce form was not rendered")
        fields = dict(candidates[0].fields)
        fields.update({"billing_first_name": "Synthetic", "billing_last_name": "Shopper",
                       "billing_country": "GH", "billing_address_1": "PRIVATE-C07-SYNTHETIC-ADDRESS",
                       "billing_city": "Accra", "billing_state": "AA", "billing_postcode": "00001",
                       "billing_phone": "0200000000", "billing_email": "c07-shopper@example.invalid",
                       "payment_method": "cetech_c07_local_gateway", "terms": "1", "terms-field": "1"})
        fields.pop("ship_to_different_address", None)
        for index, package in enumerate(prepared["packages"]):
            rates = package["rates"]
            if rates:
                fields["shipping_method[" + str(index) + "]"] = rates[0]["id"]
        return fields

    def classic(scenario, arm=None, pause=False, flags_off=False):
        bridge.call("classicpageemergency")
        prepared = seed(scenario)
        rendered = client.get(state["classic_url"])
        fields = checkout_fields(rendered.page(), prepared)
        if arm:
            bridge.call(arm)
        if pause:
            bridge.call("pauseemergency")
        if flags_off:
            bridge.call("disableflagsemergency")
        before = snapshot()
        response = client.post("/?wc-ajax=checkout", fields)
        after = snapshot()
        if flags_off:
            bridge.call("restoreflagsemergency")
        return prepared, rendered, response, json.loads(response.body), before, after

    def blocked(case, data):
        prepared, rendered, response, result, before, after = data
        denied = result.get("result") == "failure" and "temporarily paused" in result.get("messages", "")
        recorder.check(prefix + case, response.status == 200 and denied and before["gateway_count"] == after["gateway_count"]
                       and before["protected_order_hash"] == after["protected_order_hash"]
                       and all(not order["paid"] for key, order in after["orders"].items() if key not in before["orders"]),
                       dict(evidence(before, after, response), page_rendered=rendered.status == 200,
                            fixture_preparation="existing server-validated cart choices; actual checkout request follows", barrier=after["barrier"]))

    response = login(client, state, settings, recorder, "HTTP-C07")
    opened = form(response)
    recorder.check(prefix + "CURRENT-NONCE-REVISION-ORIGINAL-ENVELOPE-FORM", response.status == 200
                   and set(("opened_row_id", "opened_revision", "opened_bytes", "request_token")).issubset(opened.fields)
                   and int(opened.fields["opened_revision"]) == snapshot()["revision"], response.evidence())
    before = snapshot(); denied = submit(opened, "checkout_suspended", "operator_pause", {"cetech_de_nonce": "invalid-c07-native-nonce"}); after = snapshot()
    recorder.check(prefix + "BAD-NONCE-NO-CONTROL-NO-AUDIT", unchanged(before, after)
                   and "Security check failed" in follow(denied).page().text, evidence(before, after, denied))

    opened = form(client.get(settings)); stale = copy.deepcopy(opened)
    before = snapshot(); response = submit(opened, "checkout_suspended", "operator_pause"); landing = follow(response); after = snapshot()
    recorder.check(prefix + "ADMIN-PAUSE-NATIVE-POST-COMMITTED", response.status in (302, 303)
                   and after["state"] == "checkout_suspended" and after["revision"] == before["revision"] + 1
                   and after["events_count"] == before["events_count"] + 1
                   and "Checkout controls were saved" in landing.page().text, evidence(before, after, response))
    before = snapshot(); repeated = submit(opened, "checkout_suspended", "operator_pause"); after = snapshot()
    recorder.check(prefix + "REPEATED-ORIGINAL-POST-RECORDED-COMPLETION", unchanged(before, after)
                   and "already completed" in follow(repeated).page().text, evidence(before, after, repeated))
    # A different request token and the actual older opened revision is a stale form.
    stale.fields["request_token"] = str(uuid.uuid4())
    before = snapshot(); response = submit(stale, "enabled", "resume_verified"); after = snapshot()
    recorder.check(prefix + "STALE-RENDERED-FORM-CANNOT-OVERWRITE", unchanged(before, after)
                   and "Reload the current state" in follow(response).page().text, evidence(before, after, response))
    opened = form(client.get(settings)); bridge.call("emergencycaps", 0); before = snapshot()
    response = submit(opened, "enabled", "resume_verified"); after = snapshot()
    denied_text = follow(response).page().text.lower()
    recorder.check(prefix + "REVOKED-CURRENT-CAPABILITY-DENIES-POST", unchanged(before, after)
                   and before["grants"].get("manage_delivery_settings") is False and after["grants_match_physical"]
                   and response.status in (302, 303, 403, 500) and ("permission" in denied_text or "not allowed to access" in denied_text), evidence(before, after, response))
    bridge.call("emergencycaps", 1)
    opened = form(client.get(settings)); bridge.call("vendorprincipalemergency"); before = snapshot(); response = submit(opened, "enabled", "resume_verified"); after = snapshot()
    recorder.check(prefix + "CURRENT-WCFM-IDENTITY-ISOLATION-DENIES-STALE-GRANTS", unchanged(before, after)
                   and after["grants"].get("manage_delivery_settings") is True and response.status in (403, 500)
                   and ("permission" in response.page().text.lower() or "not allowed to access" in response.page().text.lower()),
                   dict(evidence(before, after, response), boundary="explicit disposable equivalent of WCFM identity function; not installed WCFM plugin certification"))
    bridge.call("staffprincipalemergency")
    bridge.call("resumeemergency"); opened = form(client.get(settings)); before = snapshot(); response = submit(opened, "enabled", "resume_verified"); after = snapshot()
    recorder.check(prefix + "SAME-STATE-NO-MATERIAL-AUDIT", unchanged(before, after)
                   and "Nothing changed" in follow(response).page().text, evidence(before, after, response))

    blocked("CLASSIC-AFTER-RENDER-PAUSE-NO-PAYMENT", classic("managed", pause=True))
    blocked("CLASSIC-FINAL-AFTER-VALIDATION-PAUSE-NO-PAYMENT", classic("managed", arm="armclassicemergency"))
    recorder.check(prefix + "CLASSIC-LATE-TRANSITION-BARRIER-ACTUALLY-TRIGGERED", snapshot()["barrier"].get("triggered") is True and snapshot()["barrier"].get("phase") == "classic_after_validation")
    blocked("CLASSIC-FREE-PICKUP-PAUSE-NO-FREE-ADMISSION", classic("pickup", arm="armclassicemergency"))
    blocked("CLASSIC-MIXED-OWNERSHIP-ALL-OR-NONE", classic("mixed", pause=True))
    blocked("CLASSIC-INACTIVE-FLAGS-DO-NOT-CAUSE-NATIVE-FALLBACK", classic("managed", pause=True, flags_off=True))
    ordinary = classic("unmanaged", pause=True)
    recorder.check(prefix + "CLASSIC-UNMANAGED-CHECKOUT-UNAFFECTED", ordinary[3].get("result") == "success"
                   and ordinary[5]["gateway_count"] == ordinary[4]["gateway_count"] + 1,
                   evidence(ordinary[4], ordinary[5], ordinary[2]))
    emptied = classic("managed", arm="armemptyemergency")
    recorder.check(prefix + "CLASSIC-EMPTY-CART-FINAL-OWNERSHIP-LATCH", emptied[3].get("result") == "success"
                   and emptied[5]["gateway_count"] == emptied[4]["gateway_count"] + 1
                   and emptied[5]["barrier"].get("triggered") is True,
                   dict(evidence(emptied[4], emptied[5], emptied[2]), cart_emptied_after_order_creation=True))

    prepared = seed("managed"); before = snapshot(); bridge.call("armstoreemergency")
    cart, _ = fixture(); address = {"first_name": "Synthetic", "last_name": "Shopper", "company": "", "address_1": "PRIVATE-C07-SYNTHETIC-ADDRESS", "address_2": "", "city": "Accra", "state": "AA", "postcode": "00001", "country": "GH", "phone": "0200000000"}
    billing = dict(address, email="c07-shopper@example.invalid")
    response = client.json_post("/wp-json/wc/store/v1/checkout", {"billing_address": billing, "shipping_address": address, "payment_method": "cetech_c07_local_gateway", "payment_data": [], "customer_note": ""}, {"Nonce": cart["store_nonce"]})
    result = json.loads(response.body); after = snapshot()
    recorder.check(prefix + "DIRECT-STORE-API-LATE-PAUSE-409-NO-PAYMENT", response.status == 409
                   and result.get("code") == "cetech_de_checkout_control" and "temporarily paused" in result.get("message", "")
                   and after["barrier"].get("triggered") is True and before["gateway_count"] == after["gateway_count"]
                   and before["protected_order_hash"] == after["protected_order_hash"],
                   dict(evidence(before, after, response), barrier=after["barrier"], required_error_code=result.get("code")))
    recorder.check(prefix + "SHOPPER-HTTP-ERROR-HAS-SAFE-CORRELATION-NO-PRIVATE-CONTROL", "Reference:" in result.get("message", "")
                   and not any(value in response.body.decode("utf-8", "replace") for value in (state["password"], "actor_user_id", "opened_bytes", "PRIVATE-C07", "incident_pause")), response.evidence())

    # Actual Woo before_pay_action route, outside the native gateway try/catch.
    bridge.call("pauseemergency")
    for kind in ("new", "legacy"):
        rendered = client.get(state[kind + "_order_pay_url"])
        forms = [x for x in rendered.page().forms if "woocommerce-pay-nonce" in x.fields]
        if len(forms) != 1:
            raise RuntimeError("Actual native unpaid order payment form missing")
        before = snapshot(); fields = dict(forms[0].fields, payment_method="cetech_c07_local_gateway", woocommerce_pay="1")
        response = client.post(state[kind + "_order_pay_url"], fields); after = snapshot(); landing = follow(response)
        recorder.check(prefix + "ORDER-PAY-" + kind.upper() + "-PAUSE-SAFE-TERMINAL-GATEWAY-ZERO", response.status == 303
                       and urlsplit(client.location(response)).path == urlsplit(state["classic_url"]).path
                       and before["gateway_count"] == after["gateway_count"] and before["protected_order_hash"] == after["protected_order_hash"]
                       and "temporarily paused" in landing.page().text, evidence(before, after, response))

    bridge.call("resumeemergency"); bridge.call("ratechangeemergency")
    rendered = client.get(state["new_order_pay_url"]); forms = [x for x in rendered.page().forms if "woocommerce-pay-nonce" in x.fields]
    if len(forms) != 1:
        raise RuntimeError("Resume price comparison requires an actual unpaid payment form")
    before = snapshot(); response = client.post(state["new_order_pay_url"], dict(forms[0].fields, payment_method="cetech_c07_local_gateway", woocommerce_pay="1")); after = snapshot()
    recorder.check(prefix + "ORDER-PAY-RESUME-PRICE-MISMATCH-NO-REPRICE", response.status == 303
                   and before["gateway_count"] == after["gateway_count"] and before["protected_order_hash"] == after["protected_order_hash"]
                   and "need to be checked again" in follow(response).page().text, evidence(before, after, response))
    bridge.call("restorepriceemergency")
    rendered = client.get(state["missing_order_pay_url"]); forms = [x for x in rendered.page().forms if "woocommerce-pay-nonce" in x.fields]
    if len(forms) != 1:
        raise RuntimeError("Missing facts comparison requires an actual unpaid payment form")
    before = snapshot(); response = client.post(state["missing_order_pay_url"], dict(forms[0].fields, payment_method="cetech_c07_local_gateway", woocommerce_pay="1")); after = snapshot()
    recorder.check(prefix + "ORDER-PAY-MISSING-FACTS-NO-HISTORY-INFERENCE", response.status == 303
                   and before["gateway_count"] == after["gateway_count"] and before["protected_order_hash"] == after["protected_order_hash"], evidence(before, after, response))
    rendered = client.get(state["new_order_pay_url"]); forms = [x for x in rendered.page().forms if "woocommerce-pay-nonce" in x.fields]
    before = snapshot(); response = client.post(state["new_order_pay_url"], dict(forms[0].fields, payment_method="cetech_c07_local_gateway", woocommerce_pay="1")); after = snapshot()
    recorder.check(prefix + "ORDER-PAY-RESUME-CURRENT-QUOTE-EQUAL-GATEWAY-ONCE", response.status in (302, 303)
                   and after["gateway_count"] == before["gateway_count"] + 1
                   and after["orders"][str(state["new_order_id"])]["paid"] is True
                   and after["orders"][str(state["new_order_id"])]["total"] == before["orders"][str(state["new_order_id"])]["total"], evidence(before, after, response))

    bridge.call("blockspageemergency"); seed("pickup")
    browser_path = Path(__file__).with_name("opening-emergency-browser.cjs")
    browser_receipt = bridge.workdir / "emergency-browser.json"
    result = subprocess.run(["node", str(browser_path), "--state", str(bridge.state_path), "--receipt", str(browser_receipt), "--base-url", client.base_url], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120, check=False)
    if result.returncode != 0:
        log = bridge.workdir / "emergency-browser-private-failure.log"
        descriptor = os.open(log, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(descriptor, "wb") as out:
            out.write(result.stdout + b"\n" + result.stderr)
    if not browser_receipt.is_file() or browser_receipt.stat().st_size > 64 * 1024:
        raise RuntimeError("C07 actual Blocks browser qualification failed without a bounded receipt; inspect private log")
    report = json.loads(browser_receipt.read_text(encoding="utf-8"))
    if report.get("format") != "cetech-c07-blocks-browser-v1" or any(report.get(key) != state["identity"][key] for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources_hash")):
        raise RuntimeError("C07 browser receipt candidate binding mismatch")
    allowed = {"HTTP-C07-BLOCKS-REAL-CHROMIUM-UI-RENDERED", "HTTP-C07-BLOCKS-AFTER-RENDER-PAUSE-UI-SUBMIT-REFUSED"}
    stages = {"ownership", "login", "fixture", "cart", "render", "form", "pause", "submit", "refusal", "complete"}
    errors = {"SyntaxError", "TypeError", "TimeoutError", "Error"}
    cases = report.get("cases")
    if not isinstance(cases, list) or len(cases) > 2 or len({case.get("id") for case in cases}) != len(cases) or any(case.get("id") not in allowed or case.get("status") not in ("PASS", "FAIL") or not isinstance(case.get("evidence"), dict) for case in cases):
        raise RuntimeError("C07 browser receipt has invalid bounded case identities")
    if report.get("stage") not in stages or (report.get("status") == "FAIL" and report.get("error_class") not in errors):
        raise RuntimeError("C07 browser receipt has invalid bounded failure diagnostics")
    for case in cases:
        if case["status"] == "FAIL" and case["evidence"].get("required_case_incomplete"):
            diagnostic = case["evidence"]
            if set(diagnostic) != {"stage", "error_class", "dom", "required_case_incomplete"} or diagnostic["stage"] not in stages or diagnostic["error_class"] not in errors or set(diagnostic["dom"]) != {"checkout_visible", "place_order_visible", "shopper_pause_visible"} or any(not isinstance(value, bool) for value in diagnostic["dom"].values()):
                raise RuntimeError("C07 browser failed case has invalid safe DOM diagnostics")
    for case in report["cases"]:
        recorder.check(case["id"], case["status"] == "PASS", case.get("evidence", {}))
    if result.returncode != 0 or report.get("status") != "PASS" or {case["id"] for case in cases} != allowed:
        raise RuntimeError("C07 actual Blocks browser qualification incomplete; valid partial cases were retained")
    final = snapshot()
    recorder.check(prefix + "NON-CONTROL-DOMAIN-HISTORY-PRESERVED", final["preserved_hashes"] == before["preserved_hashes"], {"exact_non_control_tables": 30, "all_physical_table_hashes_unchanged": final["preserved_hashes"] == before["preserved_hashes"]})
