"""Bounded COR-002 native HTTP form qualification.

Imported by the shared HTTP driver; all helpers are supplied from that driver.
This module never constructs cookies/nonces, bypasses handlers or runs a worker.
"""

import copy
import hashlib
import json
from urllib.parse import urlencode, urlsplit, parse_qs


def run_configuration(client, state, bridge, recorder, Page, login):
    """Exercise scoped save/reset and exception reset using a separate principal."""
    prefix = "HTTP-COR002-"
    save_action = "cetech_de_save_scoped_configuration"
    reset_action = "cetech_de_reset_scoped_configuration"
    exception_action = "cetech_de_reset_exception"
    scoped_path = "/wp-admin/admin.php?page=cetech-delivery-engine-scoped-config"
    exceptions_path = client.resolve("/wp-admin/admin.php?page=cetech-delivery-engine-product-exceptions")
    target = {
        "scope_type": "variation",
        "scope_id": str(state["variation_id"]),
        "parent_product_id": str(state["parent_id"]),
        "slice_key": "in_store",
        "customize": "1",
    }
    selected_id = int(state["selected_scope_row_id"])
    target_url = client.resolve(scoped_path + "&" + urlencode(target))
    form_destinations = {}

    def snapshot():
        return bridge.call("snapshotconfig")["snapshot"]

    def same_data(before, after):
        return all(before[k] == after[k] for k in (
            "scopes", "fields", "collections", "audit", "options", "private_snapshot"
        ))

    def evidence(before, after, response=None):
        result = {"before_hashes": before["hashes"], "after_hashes": after["hashes"],
                  "private_before": before["private_snapshot"], "private_after": after["private_snapshot"]}
        if response is not None:
            result["http"] = response.evidence()
        return result

    def scope_row(snap):
        matches = [row for row in snap["scopes"] if int(row["id"]) == selected_id]
        return matches[0] if len(matches) == 1 else None

    def excluded(rows, key):
        return [row for row in rows if int(row[key]) != selected_id]

    def scalar(value):
        if isinstance(value, list):
            return str(value[0]) if len(value) == 1 else None
        return str(value) if value is not None else None

    def page_for(response):
        return response.page()

    def permission_terminal(response):
        text = page_for(response).text.lower()
        return response.status in (403, 500) and ("permission" in text or "not allowed to access this page" in text)

    def form_for(response, action, item_id=None):
        page = page_for(response)
        if item_id is None:
            form = page.form_for_action(action)
        else:
            forms = [form for form in page.forms
                     if scalar(form.fields.get("cetech_de_action")) == action
                     and scalar(form.fields.get("item_id")) == str(item_id)]
            if len(forms) != 1:
                raise RuntimeError("Expected exactly one rendered target reset form.")
            form = forms[0]
        if form.attrs.get("method", "get").lower() != "post" or not scalar(form.fields.get("cetech_de_nonce")):
            raise RuntimeError("Expected rendered native nonce-bearing POST form.")
        # Browser submission uses the rendered action, or the exact document URL
        # when action is absent. The current simplified form has no action;
        # the advanced editor explicitly posts to the scoped page slug only.
        from urllib.parse import urljoin
        form_destinations[id(form)] = client.resolve(urljoin(response.url, form.attrs.get("action") or response.url))
        return form

    def submit(form, changes=None, endpoint=None):
        destination = form_destinations[id(form)]
        if endpoint is not None and client.resolve(endpoint) != destination:
            raise RuntimeError("Qualification POST override differs from the rendered form destination.")
        fields = copy.deepcopy(form.fields)
        if changes:
            fields.update(changes)
        return client.post(destination, fields), fields

    def follow(response):
        if response.status in (302, 303):
            return client.get(client.location(response))
        return response

    def denial(case, form, changes=None, endpoint=None, reason=None, allow_native_admission=False):
        destination = form_destinations[id(form)]
        # Signed cookie values stay in memory only. Preserve the exact logged-in
        # cookie tuple across the opted-in revoked POST, rather than accepting
        # mere presence of an unrelated or newly issued authentication cookie.
        auth_cookie_before = tuple(sorted(
            (cookie.domain, cookie.path, cookie.name, cookie.value, cookie.secure, cookie.expires)
            for cookie in client.cookies if cookie.name.startswith("wordpress_logged_in_")
        )) if allow_native_admission else ()
        before = snapshot()
        response, _ = submit(form, changes, endpoint)
        after = snapshot()
        if allow_native_admission:
            admission_cases = {
                "REVOKED-PLUGIN-CAPABILITY-SAVE-DENIED": (save_action, "cetech-delivery-engine-scoped-config"),
                "REVOKED-PLUGIN-CAPABILITY-EXCEPTION-RESET-DENIED": (exception_action, "cetech-delivery-engine-product-exceptions"),
            }
            if case not in admission_cases:
                raise RuntimeError("Native admission evidence is reserved for the explicit revoked-capability cases.")
            expected_action, expected_page = admission_cases[case]
            if scalar(form.fields.get("cetech_de_action")) != expected_action or parse_qs(urlsplit(destination).query).get("page") != [expected_page]:
                raise RuntimeError("Revoked native admission target differs from its actual rendered form route.")
            if response.status == 403:
                # WordPress admission may deny the revoked principal before the
                # plugin page's POST callback. An earlier nonce flash is then
                # unchanged; it is not a new validation witness for this POST.
                native_sentence = "Sorry, you are not allowed to access this page."
                physical_revoked = all(
                    snap["authority"]["product_gate"] is False
                    and snap["authority"]["persisted_role_product_gate"] is True
                    and snap["authority"]["persisted_user_override"] is False
                    and snap["authority"]["persisted_effective_matches_native"] is True
                    and snap["authority"]["own_parent"] is True
                    and snap["authority"]["own_variation"] is True
                    for snap in (before, after)
                )
                flash_unchanged = before.get("flash_notice") == after.get("flash_notice")
                permission_body_matched = native_sentence in page_for(response).text
                auth_cookie_after = tuple(sorted(
                    (cookie.domain, cookie.path, cookie.name, cookie.value, cookie.secure, cookie.expires)
                    for cookie in client.cookies if cookie.name.startswith("wordpress_logged_in_")
                ))
                retained_auth_cookie = bool(auth_cookie_before) and auth_cookie_before == auth_cookie_after
                recorder.check(prefix + case,
                               "location" not in response.headers
                               and permission_body_matched
                               and response.url == destination
                               and physical_revoked and same_data(before, after) and flash_unchanged
                               and retained_auth_cookie,
                               {**evidence(before, after, response), "terminal": response.evidence(),
                                "denial_path": "native wp-admin admission before plugin POST callback",
                                "permission_body_matched": permission_body_matched,
                                "retained_auth_cookie": retained_auth_cookie,
                                "authority_before": before["authority"], "authority_after": after["authority"],
                                "flash_unchanged": flash_unchanged,
                                "unchanged_flash_sha256": hashlib.sha256(json.dumps(before.get("flash_notice"), sort_keys=True, separators=(",", ":")).encode()).hexdigest(),
                                "notice_proof": "No new plugin notice claimed; any prior flash remains unchanged."})
                return
        landing = follow(response)
        notice = after.get("flash_notice") or {}
        redirect_ok = response.status in (302, 303) and parse_qs(urlsplit(client.location(response)).query).get("page") == parse_qs(urlsplit(destination).query).get("page")
        reason = reason or ("security check failed" if "NONCE" in case else
                            "permission to edit the parent product" if "FOREIGN-PARENT" in case else
                            "valid delivery setup" if "UNKNOWN-SLICE" in case else
                            "insufficient capability" if "REVOKED" in case else
                            "does not belong to the selected parent product")
        terminal_denial = (landing.status == 200 and page_for(landing).has_notice("error", notice.get("message", ""))) or permission_terminal(landing)
        recorder.check(prefix + case,
                       redirect_ok and same_data(before, after) and terminal_denial
                       and notice.get("type") == "error" and reason in notice.get("message", "").lower()
                       and any(cookie.name.startswith("wordpress_logged_in_") for cookie in client.cookies),
                       {**evidence(before, after, response), "terminal": landing.evidence(),
                        "production_error_notice": notice.get("message"),
                        "notice_proof": "physical native transient read after POST, before real redirect GET"})

    prepared = bridge.call("snapshotconfig")["snapshot"]
    authority = prepared["authority"]
    recorder.check(prefix + "SEPARATE-NATIVE-OBJECT-AUTHORITY",
                   authority["product_gate"] and authority["own_parent"]
                   and authority["persisted_role_product_gate"] and authority["persisted_effective_matches_native"]
                   and authority["wrong_owned_parent"] and authority["own_variation"]
                   and authority["foreign_parent_variation_native_primitive"]
                   and not authority["foreign_parent"] and not authority["global_gate"]
                   and not authority["private_sources"],
                   {"actor": state["user_id"], "authority": authority,
                    "limit": "Native variation primitive does not grant parent edit authority."})

    login(client, state, target_url, recorder, prefix + "LOGIN-")
    response = client.get(target_url)
    form = form_for(response, save_action)
    required = {"cetech_de_action": save_action, **{key: value for key, value in target.items() if key != "customize"}}
    recorder.check(prefix + "RENDERED-EXACT-SLICE-SAVE-FORM",
                   response.status == 200
                   and all(scalar(form.fields.get(k)) == v for k, v in required.items())
                   and bool(scalar(form.fields.get("cetech_de_nonce")))
                   and "fields[estimated_delivery][mode]" in form.fields
                   and "fields[estimated_delivery][value]" in form.fields,
                   {"http": response.evidence(), "scope_id": state["variation_id"],
                    "parent_id": state["parent_id"], "slice_key": "in_store",
                    "form_class": form.attrs.get("class", ""),
                    "form_action_present": bool(form.attrs.get("action")),
                    "posted_customize": scalar(form.fields.get("customize")),
                    "nonce": "obtained from actual rendered HTTP form; value omitted"})

    changed = {"fields[estimated_delivery][mode]": "override",
               "fields[estimated_delivery][value]": "Opening HTTP after save"}
    denial("WRONG-AUTHORIZED-PARENT-SAVE-DENIED", form,
           {**changed, "parent_product_id": str(state["wrong_parent_id"])})
    denial("FOREIGN-PARENT-SAVE-DENIED", form,
           {**changed, "scope_id": str(state["foreign_variation_id"]),
            "parent_product_id": str(state["foreign_parent_id"])})
    denial("UNKNOWN-SLICE-SAVE-DENIED", form, {**changed, "slice_key": "invalid_http_slice"})
    denial("INVALID-NONCE-SAVE-DENIED", form, {**changed, "cetech_de_nonce": "invalid-qualification-nonce"})

    bridge.call("configcaps", 0)
    revoked = snapshot()
    recorder.check(prefix + "PLUGIN-CAPABILITY-REVOKED",
                   not revoked["authority"]["product_gate"] and revoked["authority"]["own_parent"]
                   and revoked["authority"]["persisted_role_product_gate"]
                   and revoked["authority"]["persisted_user_override"] is False
                   and revoked["authority"]["persisted_effective_matches_native"],
                   {"actor": state["user_id"], "authority": revoked["authority"]})
    revoked_render = client.get(target_url)
    recorder.check(prefix + "REVOKED-SCOPED-READ-DENIED",
                   permission_terminal(revoked_render)
                   and same_data(revoked, snapshot()), revoked_render.evidence())
    denial("REVOKED-PLUGIN-CAPABILITY-SAVE-DENIED", form, changed, allow_native_admission=True)
    bridge.call("configcaps", 1)
    restored = snapshot()
    recorder.check(prefix + "PLUGIN-CAPABILITY-RESTORED",
                   restored["authority"]["product_gate"] and restored["authority"]["persisted_user_override"] is True
                   and restored["authority"]["persisted_effective_matches_native"] and same_data(revoked, restored),
                   evidence(revoked, restored))

    before = snapshot()
    response, saved_fields = submit(form, changed)
    after = snapshot()
    before_row, after_row = scope_row(before), scope_row(after)
    selected_fields = [row for row in after["fields"] if int(row["scope_row_id"]) == selected_id]
    estimate_fields = [row for row in selected_fields if row["field_key"] == "estimated_delivery"]
    new_audits = [row for row in after["audit"] if row not in before["audit"]]
    recorder.check(prefix + "ACTUAL-SAVE-PERSISTED-ONE-SLICE",
                   response.status in (302, 303) and before_row is not None and after_row is not None
                   and int(after_row["config_version"]) == int(before_row["config_version"]) + 1
                   and after_row["slice_key"] == "in_store"
                   and int(after_row["parent_product_id"]) == int(state["parent_id"])
                   and len(estimate_fields) == 1
                   and estimate_fields[0]["mode"] == "override"
                   and estimate_fields[0]["value_text"] == "Opening HTTP after save"
                   and excluded(before["scopes"], "id") == excluded(after["scopes"], "id")
                   and excluded(before["fields"], "scope_row_id") == excluded(after["fields"], "scope_row_id")
                   and excluded(before["collections"], "scope_row_id") == excluded(after["collections"], "scope_row_id")
                   and before["options"] == after["options"]
                   and before["private_snapshot"] == after["private_snapshot"]
                   and len(new_audits) == 1
                   and new_audits[0]["action"] == "scoped_configuration_updated"
                   and int(new_audits[0]["actor_user_id"]) == int(state["user_id"])
                   and int(new_audits[0]["entity_id"]) == selected_id,
                   {**evidence(before, after, response), "scope_row_id": selected_id,
                    "saved_synthetic_estimate": "Opening HTTP after save", "new_audit_ids": [a["id"] for a in new_audits]})
    landing = follow(response)
    customize_posted = scalar(saved_fields.get("customize")) == "1"
    successful_notice = "Delivery settings saved" if customize_posted else "Scoped configuration saved. Version is now"
    redirect_parameters = parse_qs(urlsplit(client.location(response)).query)
    recorder.check(prefix + "REAL-SAVE-REDIRECT-AND-NOTICE",
                   landing.status == 200
                   and page_for(landing).has_notice("success", successful_notice)
                   and (redirect_parameters.get("customize") == ["1"] if customize_posted else "customize" not in redirect_parameters)
                   and all(redirect_parameters.get(key) == [value] for key, value in required.items() if key != "cetech_de_action")
                   and scalar(form_for(landing, save_action).fields.get("fields[estimated_delivery][value]")) == "Opening HTTP after save",
                   {"http": landing.evidence(), "redirect_parameters": redirect_parameters,
                    "posted_customize": customize_posted, "notice_checked": successful_notice})

    # Submit the actual scoped reset form with bad nonce and relationship controls.
    scoped_reset = form_for(landing, reset_action)
    denial("WRONG-AUTHORIZED-PARENT-SCOPED-RESET-DENIED", scoped_reset,
           {"parent_product_id": str(state["wrong_parent_id"])})
    denial("INVALID-NONCE-SCOPED-RESET-DENIED", scoped_reset,
           {"cetech_de_nonce": "invalid-qualification-nonce"})

    response = client.get(exceptions_path)
    exception_form = form_for(response, exception_action, state["variation_id"])
    recorder.check(prefix + "ACTUAL-EXCEPTION-RESET-FORM-EXACT-SLICE",
                   response.status == 200
                   and scalar(exception_form.fields.get("item_type")) == "variation"
                   and scalar(exception_form.fields.get("slice_key")) == "in_store"
                   and scalar(exception_form.fields.get("parent_product_id")) == str(state["parent_id"])
                   and bool(scalar(exception_form.fields.get("cetech_de_nonce"))),
                   {"http": response.evidence(), "scope_row_id": selected_id,
                    "scope_id": state["variation_id"], "slice_key": "in_store"})
    denial("WRONG-AUTHORIZED-PARENT-EXCEPTION-RESET-DENIED", exception_form,
           {"parent_product_id": str(state["wrong_parent_id"])}, exceptions_path)
    denial("INVALID-NONCE-EXCEPTION-RESET-DENIED", exception_form,
           {"cetech_de_nonce": "invalid-qualification-nonce"}, exceptions_path)
    bridge.call("configcaps", 0)
    denial("REVOKED-PLUGIN-CAPABILITY-EXCEPTION-RESET-DENIED", exception_form,
           endpoint=exceptions_path, reason="permission to perform this action", allow_native_admission=True)
    bridge.call("configcaps", 1)
    recorder.check(prefix + "EXCEPTION-RESET-CAPABILITY-RESTORED",
                   snapshot()["authority"]["product_gate"], {"actor": state["user_id"]})

    before = snapshot()
    response, submitted_reset = submit(exception_form, endpoint=exceptions_path)
    after = snapshot()
    new_audits = [row for row in after["audit"] if row not in before["audit"]]
    previous_payload = json.loads(new_audits[0]["previous_value"]) if len(new_audits) == 1 else {}
    new_payload = json.loads(new_audits[0]["new_value"]) if len(new_audits) == 1 else {}
    recorder.check(prefix + "ACTUAL-EXCEPTION-RESET-EXACT-SLICE-SQL",
                   response.status in (302, 303) and scope_row(after) is None
                   and after["scopes"] == excluded(before["scopes"], "id")
                   and after["fields"] == excluded(before["fields"], "scope_row_id")
                   and after["collections"] == excluded(before["collections"], "scope_row_id")
                   and len(after["scopes"]) == len(before["scopes"]) - 1
                   and before["options"] == after["options"]
                   and before["private_snapshot"] == after["private_snapshot"]
                   and len(new_audits) == 1 and new_audits[0]["action"] == "scoped_configuration_reset"
                   and int(new_audits[0]["actor_user_id"]) == int(state["user_id"])
                   and int(new_audits[0]["entity_id"]) == selected_id
                   and previous_payload.get("scope_type") == "variation"
                   and previous_payload.get("scope_id") == int(state["variation_id"])
                   and previous_payload.get("slice_key") == "in_store"
                   and previous_payload.get("parent_product_id") == int(state["parent_id"])
                   and previous_payload.get("config_version") == int(scope_row(before)["config_version"])
                   and previous_payload.get("scalars", {}).get("estimated_delivery", {}).get("mode") == "override"
                   and previous_payload.get("scalars", {}).get("estimated_delivery", {}).get("value") == "Opening HTTP after save"
                   and new_payload.get("scope_type") == "variation"
                   and new_payload.get("scope_id") == int(state["variation_id"])
                   and new_payload.get("slice_key") == "in_store"
                   and new_payload.get("parent_product_id") == int(state["parent_id"])
                   and new_payload.get("inherits") is True,
                   {**evidence(before, after, response), "scope_row_id": selected_id,
                    "ordinary_reset_audit_ids": [a["id"] for a in new_audits],
                    "limit": "Successful append only; COR-007 crash/transaction/audit-failure policy reserved."})
    landing = follow(response)
    recorder.check(prefix + "EXCEPTION-RESET-REAL-REDIRECT-NOTICE",
                   landing.status == 200 and page_for(landing).has_notice("success", "variation now uses the product settings"),
                   {"http": landing.evidence()})

    before = snapshot()
    replay = client.post(exceptions_path, submitted_reset)
    after = snapshot()
    landing = follow(replay)
    recorder.check(prefix + "DELETED-SLICE-REPLAY-NOOP-NO-AUDIT",
                   replay.status in (302, 303) and same_data(before, after)
                   and landing.status == 200
                   and page_for(landing).has_notice("error", "Nothing was reset"),
                   evidence(before, after, replay))
    recorder.check(prefix + "SIBLING-PARENT-GLOBAL-REMAIN",
                   any(row["scope_type"] == "variation" and int(row["scope_id"]) == int(state["variation_id"])
                       and row["slice_key"] == "in_warehouse" for row in after["scopes"])
                   and any(row["scope_type"] == "product" and int(row["scope_id"]) == int(state["parent_id"])
                           for row in after["scopes"])
                   and excluded(prepared["scopes"], "id") == excluded(after["scopes"], "id"),
                   {"remaining_configuration_hashes": after["hashes"], "scope_row_id_removed": selected_id})
    return {"actor": state["user_id"], "selected_scope_row_id": selected_id,
            "checks_completed": "COR-002 native login/form save and exact represented exception reset",
            "final_hashes": after["hashes"]}
