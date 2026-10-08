#!/usr/bin/env python3
"""Disposable authenticated HTTP qualification for bounded plugin workflows.

Actual WordPress cookies, native nonces, redirects and AJAX dispatch the
qualified handlers. Dedicated C07 fixtures add native checkout/order-pay and
an actual Blocks browser submission with an instrumented local gateway.
CLI bridges prepare isolated fixtures and inspect state; they do not substitute
for the qualified HTTP handlers. External payments and release proof are separate.
"""
from __future__ import annotations

import argparse
import hashlib
from html.parser import HTMLParser
import http.cookiejar
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import sys
import time
from dataclasses import dataclass
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, urlencode, urljoin, urlsplit, urlunsplit
from urllib.request import build_opener, HTTPCookieProcessor, HTTPRedirectHandler, ProxyHandler, Request


SLUG = "cetech-delivery-engine-bulk-tools"
IMPORT = "cetech_de_bulk_config_import"
APPLY = "cetech_de_bulk_apply"
PROGRESS = "cetech_de_bulk_job_status"


@dataclass
class Form:
    attrs: dict[str, str]
    fields: dict[str, str]


class Page(HTMLParser):
    """Read server-generated forms and JSON localization without JS evaluation."""
    def __init__(self, html: str):
        super().__init__(convert_charrefs=True)
        self.forms: list[Form] = []
        self.anchors: list[dict[str, str]] = []
        self.job_ids: set[str] = set()
        self.notices: list[dict[str, str]] = []
        self.scripts: list[str] = []
        self._text: list[str] = []
        self._form: Form | None = None
        self._anchor: dict[str, str] | None = None
        self._script: list[str] | None = None
        self._textarea: tuple[str, list[str]] | None = None
        self._select: tuple[str, list[tuple[str, bool]]] | None = None
        self._option: tuple[str | None, bool, list[str]] | None = None
        self._notice: tuple[int, dict[str, str], list[str]] | None = None
        self._depth = 0
        self.feed(html)
        self.close()
        self.text = " ".join(self._text)

    def handle_starttag(self, tag, attrs):
        data = {key: value if value is not None else "" for key, value in attrs}
        self._depth += 1
        if tag == "form":
            if self._form is not None:
                raise RuntimeError("Unexpected nested form in qualification page")
            self._form = Form(data, {})
        if self._form is not None and tag == "input" and "name" in data:
            if "disabled" not in data and (data.get("type", "text") not in ("checkbox", "radio") or "checked" in data):
                self._form.fields[data["name"]] = data.get("value", "")
        if self._form is not None and tag == "textarea" and "name" in data:
            self._textarea = (data["name"], [])
        if self._form is not None and tag == "select" and "name" in data:
            self._select = (data["name"], [])
        if self._select is not None and tag == "option":
            self._option = (data.get("value"), "selected" in data, [])
        if tag == "a":
            self._anchor = {"href": data.get("href", ""), "text": ""}
        if tag == "script":
            self._script = []
        if "data-cetech-de-job-id" in data:
            self.job_ids.add(data["data-cetech-de-job-id"])
        classes = data.get("class", "").split()
        if "notice" in classes and self._notice is None:
            self._notice = (self._depth, {"class": " ".join(classes), "text": ""}, [])
        if tag in ("input", "meta", "link", "br", "hr", "img", "wbr", "area", "base", "col", "embed", "param", "source", "track"):
            self._depth -= 1

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)
        if tag not in ("input", "meta", "link", "br", "hr", "img", "wbr", "area", "base", "col", "embed", "param", "source", "track"):
            self.handle_endtag(tag)

    def handle_endtag(self, tag):
        if tag == "textarea" and self._textarea is not None:
            if self._form is not None:
                self._form.fields[self._textarea[0]] = "".join(self._textarea[1])
            self._textarea = None
        if tag == "option" and self._option is not None and self._select is not None:
            value, selected, text = self._option
            self._select[1].append((value if value is not None else "".join(text), selected))
            self._option = None
        if tag == "select" and self._select is not None:
            name, options = self._select
            if self._form is not None and options:
                self._form.fields[name] = next((value for value, selected in options if selected), options[0][0])
            self._select = None
        if tag == "form" and self._form is not None:
            self.forms.append(self._form)
            self._form = None
        if tag == "a" and self._anchor is not None:
            self.anchors.append(self._anchor)
            self._anchor = None
        if tag == "script" and self._script is not None:
            self.scripts.append("".join(self._script))
            self._script = None
        if self._notice is not None and self._notice[0] == self._depth:
            _, notice, text = self._notice
            notice["text"] = " ".join(text)
            self.notices.append(notice)
            self._notice = None
        self._depth = max(0, self._depth - 1)

    def handle_data(self, data):
        if self._script is not None:
            self._script.append(data)
            return
        self._text.append(data.strip())
        if self._anchor is not None:
            self._anchor["text"] += data
        if self._textarea is not None:
            self._textarea[1].append(data)
        if self._option is not None:
            self._option[2].append(data)
        if self._notice is not None:
            self._notice[2].append(data.strip())

    def form_for_action(self, action: str) -> Form:
        matching = [form for form in self.forms if form.fields.get("cetech_de_action") == action]
        if len(matching) != 1:
            raise RuntimeError("Expected one rendered form for " + action)
        form = matching[0]
        if form.attrs.get("method", "get").lower() != "post" or not form.fields.get("cetech_de_nonce"):
            raise RuntimeError("Expected native nonce-bearing POST form for " + action)
        return form

    def localized(self, name: str) -> dict:
        pattern = re.compile(r"\b(?:var\s+)?" + re.escape(name) + r"\s*=\s*")
        matches = [(script, match.end()) for script in self.scripts for match in pattern.finditer(script)]
        if len(matches) != 1:
            raise RuntimeError("Expected one rendered localization for " + name)
        script, offset = matches[0]
        value, _ = json.JSONDecoder().raw_decode(script[offset:])
        if not isinstance(value, dict):
            raise RuntimeError("Expected localized JSON object")
        return value

    def has_notice(self, kind: str, contains: str) -> bool:
        return any("notice-" + kind in item["class"].split() and contains in item["text"] for item in self.notices)


def redacted_route(url: str) -> str:
    parts = urlsplit(url)
    query = parse_qs(parts.query)
    allowed = {key: values[0] for key, values in query.items() if key in ("page", "tab", "job") and values}
    return urlunsplit(("", "", parts.path, urlencode(allowed), ""))


@dataclass
class Response:
    status: int
    body: bytes
    headers: dict[str, str]
    url: str
    ordinal: int = 0
    method: str = ""
    elapsed_ms: int = 0
    headers_ms: int = 0
    body_ms: int = 0

    def page(self) -> Page:
        return Page(self.body.decode("utf-8", "replace"))

    def evidence(self) -> dict:
        evidence = {
            "ordinal": self.ordinal,
            "method": self.method,
            "status": self.status,
            "route": redacted_route(self.url),
            "elapsed_ms": self.elapsed_ms,
            "headers_ms": self.headers_ms,
            "body_ms": self.body_ms,
            "phase": "body",
            "body_sha256": hashlib.sha256(self.body).hexdigest(),
            "body_bytes": len(self.body),
        }
        if "location" in self.headers:
            evidence["location"] = redacted_route(self.headers["location"])
        return evidence


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


REQUEST_OBSERVATION: dict = {}


class HttpClient:
    def __init__(self, base_url: str):
        parts = urlsplit(base_url)
        if parts.scheme != "http" or parts.hostname != "127.0.0.1" or parts.port is None or parts.username or parts.password or parts.path not in ("", "/") or parts.query or parts.fragment:
            raise RuntimeError("HTTP qualification requires explicit 127.0.0.1 origin and port")
        self.base_url = base_url.rstrip("/")
        self.origin = (parts.scheme, parts.hostname, parts.port)
        self.cookies = http.cookiejar.CookieJar()
        self.opener = build_opener(ProxyHandler({}), HTTPCookieProcessor(self.cookies), NoRedirect())
        self.ordinal = 0
        self.started = time.monotonic()

    def resolve(self, url: str) -> str:
        resolved = urljoin(self.base_url + "/", url)
        parts = urlsplit(resolved)
        if (parts.scheme, parts.hostname, parts.port) != self.origin or parts.username or parts.password or parts.fragment:
            raise RuntimeError("Refusing qualification request or redirect outside loopback origin")
        return resolved

    def json_post(self, url: str, payload: dict, headers: dict | None = None) -> Response:
        if not isinstance(payload, dict):
            raise RuntimeError("Qualification JSON payload must be an object")
        body = json.dumps(payload, separators=(",", ":"), allow_nan=False).encode("utf-8")
        if len(body) > 64 * 1024:
            raise RuntimeError("Qualification JSON request exceeded bounded size")
        return self.request(url, extra_headers=headers, raw_body=body)

    def json_patch(self, url: str, payload: dict, headers: dict | None = None) -> Response:
        if not isinstance(payload, dict):
            raise RuntimeError("Qualification JSON payload must be an object")
        body = json.dumps(payload, separators=(",", ":"), allow_nan=False).encode("utf-8")
        if len(body) > 64 * 1024:
            raise RuntimeError("Qualification JSON request exceeded bounded size")
        return self.request(url, extra_headers=headers, raw_body=body, method="PATCH")

    def request(self, url: str, fields: dict | None = None, extra_headers: dict | None = None, raw_body: bytes | None = None, method: str | None = None) -> Response:
        global REQUEST_OBSERVATION
        target = self.resolve(url)
        if fields is not None and raw_body is not None:
            raise RuntimeError("Qualification request has conflicting transports")
        data = raw_body if raw_body is not None else (urlencode(fields).encode("utf-8") if fields is not None else None)
        method = method or ("POST" if data is not None else "GET")
        if method not in ("GET", "POST", "PATCH") or (method == "GET" and data is not None):
            raise RuntimeError("Qualification request has an unsupported method")
        headers = {"User-Agent": "CETECH-Opening-HTTP-Qualification/1", "Accept": "text/html,application/json"}
        headers.update(extra_headers or {})
        if data is not None:
            headers["Content-Type"] = "application/json; charset=UTF-8" if raw_body is not None else "application/x-www-form-urlencoded; charset=UTF-8"
            headers["Origin"] = self.base_url
            headers["Referer"] = target
        request = Request(target, data=data, headers=headers, method=method)
        self.ordinal += 1
        started = time.monotonic()
        phase = "pre_response_headers"
        REQUEST_OBSERVATION = {
            "ordinal": self.ordinal,
            "method": method,
            "route": redacted_route(target),
            "phase": phase,
            "elapsed_ms": 0,
            "monotonic_ms": int((started - self.started) * 1000),
        }
        try:
            try:
                response = self.opener.open(request, timeout=20)
            except HTTPError as error:
                response = error
            phase = "headers"
            headers_ms = int((time.monotonic() - started) * 1000)
            REQUEST_OBSERVATION["phase"] = phase
            REQUEST_OBSERVATION["elapsed_ms"] = headers_ms
            with response:
                body = response.read(2 * 1024 * 1024 + 1)
            if len(body) > 2 * 1024 * 1024:
                raise RuntimeError("Qualification response exceeded bounded size")
            elapsed_ms = int((time.monotonic() - started) * 1000)
            REQUEST_OBSERVATION["phase"] = "body"
            REQUEST_OBSERVATION["elapsed_ms"] = elapsed_ms
            return Response(response.code, body, {key.lower(): value for key, value in response.headers.items()}, target, self.ordinal, method, elapsed_ms, headers_ms, max(0, elapsed_ms - headers_ms))
        except (TimeoutError, socket.timeout, URLError, OSError) as error:
            REQUEST_OBSERVATION["phase"] = phase
            REQUEST_OBSERVATION["elapsed_ms"] = int((time.monotonic() - started) * 1000)
            REQUEST_OBSERVATION["error_class"] = type(error).__name__
            raise

    def get(self, url: str, headers: dict | None = None) -> Response:
        return self.request(url, extra_headers=headers)

    def post(self, url: str, fields: dict) -> Response:
        return self.request(url, fields)

    def location(self, response: Response) -> str:
        if "location" not in response.headers:
            raise RuntimeError("Expected terminal HTTP Location header")
        return self.resolve(urljoin(response.url, response.headers["location"]))


class Recorder:
    def __init__(self, receipt_path: Path, identity: dict):
        self.path = receipt_path
        self.report = dict(identity, format="cetech-opening-http-qualification-v1", status="RUNNING", cases=[])
        self.write()

    def write(self):
        self.path.write_text(json.dumps(self.report, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    def check(self, case_id: str, condition: bool, evidence: dict | None = None):
        if any(case["id"] == case_id for case in self.report["cases"]):
            raise RuntimeError("Duplicate HTTP qualification case ID")
        self.report["cases"].append({"id": case_id, "status": "PASS" if condition else "FAIL", "evidence": evidence or {}})
        self.write()
        print("opening_http_case=" + case_id + " result=" + ("PASS" if condition else "FAIL"), flush=True)
        if not condition:
            raise RuntimeError("HTTP qualification diverged: " + case_id)

    def finish(self, status: str, error: str | None = None):
        self.report["status"] = status
        if error:
            self.report["error"] = error
        self.write()


def quote_cart_setup_diagnostic(path: Path):
    """Reviewed failure facts only; captured bridge stderr remains private."""
    try:
        if not path.is_file() or path.stat().st_size > 1024:
            return None
        value = json.loads(path.read_text(encoding="utf-8"))
        if not isinstance(value, dict) or set(value) != {"phase", "error_class", "source_key", "source_line"}:
            return None
        if value["phase"] not in {"identity", "control", "install", "cache", "user", "pages", "export", "write"} or value["error_class"] not in {"RuntimeException", "LogicException", "InvalidArgumentException", "WC_Data_Exception", "Error", "OtherError"}:
            return None
        if value["source_key"] is None:
            return value if value["source_line"] is None else None
        if value["source_key"] not in {"native_fixture", "quote_bridge", "cart_support"} or type(value["source_line"]) is not int or not 1 <= value["source_line"] <= 100000:
            return None
        return value
    except (OSError, UnicodeDecodeError, ValueError, TypeError):
        return None


class FixtureBridge:
    def __init__(self, php: str, wpcli: str, site: str, admin_context: str, bridge_path: str, state_path: Path, workdir: Path):
        self.command = [php, wpcli, "--allow-root", "--path=" + site, "--require=" + admin_context, "eval-file", bridge_path, "--use-include"]
        self.state_path = state_path
        self.workdir = workdir
        self.sequence = 0

    def call(self, mode: str, job_id: int | None = None) -> dict:
        self.sequence += 1
        output = self.workdir / ("bridge-" + str(self.sequence) + ".json")
        command = self.command + [mode, str(self.state_path), str(output)]
        if job_id is not None:
            command.append(str(job_id))
        result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60, check=False)
        if result.returncode != 0 or not output.is_file():
            log_path = self.workdir / ("bridge-" + str(self.sequence) + "-failure.log")
            descriptor = os.open(log_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(descriptor, "wb") as log:
                log.write(result.stdout + b"\n" + result.stderr)
            failure = RuntimeError("HTTP fixture bridge failed in " + mode + "; inspect the private bridge log")
            if mode == "preparequotecart":
                failure.quote_cart_fixture_diagnostic = quote_cart_setup_diagnostic(Path(str(output) + ".failure.json"))
            raise failure
        return json.loads(output.read_text(encoding="utf-8"))


def verify_listener(client: HttpClient, state: dict, recorder: Recorder, prefix: str):
    response = client.get("/?cetech_opening_http_probe=1", {"X-CETECH-Opening-Probe": state["probe_token"]})
    value = json.loads(response.body)
    expected = state["identity"]
    condition = response.status == 200 and value.get("format") == "cetech-opening-http-owned-listener-v1" and value.get("probe_sha256") == hashlib.sha256(state["probe_token"].encode()).hexdigest() and value.get("site_path_sha256") == hashlib.sha256(state["site_path"].encode()).hexdigest() and value.get("database_name_sha256") == hashlib.sha256(state["database_name"].encode()).hexdigest() and all(value.get(key) == expected.get(key) for key in ("source_head", "candidate_head", "source_tree"))
    recorder.check(prefix + "-OWNED-LISTENER-ATTESTED", condition, dict(response.evidence(), listener=value, boundary="credential and private package POSTs start only after this owned listener/site/database/source match"))


def login(client: HttpClient, state: dict, redirect_url: str, recorder: Recorder, prefix: str) -> Response:
    redirect_url = client.resolve(redirect_url)
    verify_listener(client, state, recorder, prefix)
    response = client.get("/wp-login.php?" + urlencode({"redirect_to": redirect_url}))
    forms = [form for form in response.page().forms if form.attrs.get("id") == "loginform"]
    recorder.check(prefix + "-LOGIN-FORM", response.status == 200 and len(forms) == 1 and any(cookie.name == "wordpress_test_cookie" for cookie in client.cookies), response.evidence())
    form = forms[0]
    fields = dict(form.fields, log=state["username"], pwd=state["password"], redirect_to=redirect_url, testcookie="1")
    response = client.post(form.attrs.get("action") or "/wp-login.php", fields)
    location = client.location(response)
    recorder.check(prefix + "-LOGIN-COOKIE-REDIRECT", response.status == 302 and location == redirect_url and any(cookie.name.startswith("wordpress_logged_in_") for cookie in client.cookies), response.evidence())
    response = client.get(location)
    recorder.check(prefix + "-AUTHENTICATED-ADMIN-ADMISSION", response.status == 200 and urlsplit(response.url).path == "/wp-admin/admin.php", response.evidence())
    return response


STATE_KEYS = ("jobs_count", "jobs_hash", "items_count", "items_hash", "offer_count", "offers_hash", "private_hashes", "job_row_hash")


def same_state(before: dict, after: dict) -> bool:
    return all(before.get(key) == after.get(key) for key in STATE_KEYS)


def snapshot_evidence(snapshot: dict) -> dict:
    return {key: snapshot.get(key) for key in STATE_KEYS + ("job", "item_target_types", "items_private_marker", "grants", "role_exists", "user_exists")}


def run_public_import(bridge: FixtureBridge, state: dict, recorder: Recorder, baseline: dict):
    client = HttpClient(state["base_url"])
    anonymous = HttpClient(state["base_url"])
    import_url = client.resolve("/wp-admin/admin.php?" + urlencode({"page": SLUG, "tab": "import"}))
    package_json = Path(state["packages"]["public_mixed"]).read_text(encoding="utf-8")
    sentinel = state["private_sentinel"]
    recorder.check("PUBLIC-IMPORT-HTTP-FIXTURE-AUTHORITY", baseline["grants"]["import_delivery_data"] and baseline["grants"]["manage_product_delivery_rules"] and not baseline["grants"]["manage_private_sources"] and baseline["offer_count"] == 0, snapshot_evidence(baseline))
    response = anonymous.get(import_url)
    location = anonymous.location(response)
    query = parse_qs(urlsplit(location).query)
    recorder.check("PUBLIC-IMPORT-HTTP-UNAUTHENTICATED-ADMIN-REDIRECT", response.status == 302 and urlsplit(location).path == "/wp-login.php" and query.get("redirect_to") == [import_url], response.evidence())
    response = login(client, state, import_url, recorder, "PUBLIC-IMPORT-HTTP")
    form = response.page().form_for_action(IMPORT)
    recorder.check("PUBLIC-IMPORT-HTTP-RENDERED-IMPORT-FORM", "package_json" in form.fields and form.fields.get("conflict_mode") == "skip_conflicts" and sentinel.encode() not in response.body, response.evidence())
    fields = dict(form.fields, package_json=package_json)
    before = bridge.call("snapshot")
    response = anonymous.post(import_url, fields)
    location = anonymous.location(response)
    recorder.check("PUBLIC-IMPORT-HTTP-UNAUTHENTICATED-INITIATION-DENIED", response.status == 302 and urlsplit(location).path == "/wp-login.php" and same_state(before, bridge.call("snapshot")), response.evidence())
    bad_fields = dict(fields, cetech_de_nonce="qualification-invalid-nonce")
    response = client.post(import_url, bad_fields)
    location = client.location(response)
    final = client.get(location)
    recorder.check("PUBLIC-IMPORT-HTTP-BAD-INITIATING-NONCE-DENIED", response.status == 302 and parse_qs(urlsplit(location).query).get("page") == [SLUG] and final.status == 200 and final.page().has_notice("error", "Security check failed") and same_state(before, bridge.call("snapshot")), {"post": response.evidence(), "final": final.evidence()})
    response = client.post(import_url, fields)
    location = client.location(response)
    query = parse_qs(urlsplit(location).query)
    raw_job_id = query.get("job", [""])[0]
    recorder.check("PUBLIC-IMPORT-HTTP-INITIATING-POST-TERMINAL-JOB-REDIRECT", response.status == 302 and urlsplit(location).path == "/wp-admin/admin.php" and query.get("page") == [SLUG] and query.get("tab") == ["jobs"] and raw_job_id.isdigit() and int(raw_job_id) > 0, response.evidence())
    job_id = int(raw_job_id)
    created = bridge.call("snapshot", job_id)
    job = created["job"]
    recorder.check("PUBLIC-IMPORT-HTTP-STORED-PUBLIC-PROJECTION", created["jobs_count"] == baseline["jobs_count"] + 1 and job["actor_user_id"] == state["user_id"] and job["status"] == "previewing" and job["dry_run"] and not job["include_private_sources"] and not job["package_include_private_sources"] and not job["private_sections_present"] and not job["private_manifest_keys_present"] and not job["contains_private_marker"] and created["offer_count"] == 0 and created["private_hashes"] == baseline["private_hashes"], snapshot_evidence(created))
    final = client.get(location)
    page = final.page()
    recorder.check("PUBLIC-IMPORT-HTTP-REAL-FINAL-SUCCESS-NOTICE", final.status == 200 and page.has_notice("success", "Preview job " + job["job_code"] + " started") and str(job_id) in page.job_ids and sentinel.encode() not in final.body, final.evidence())
    history = [anchor for anchor in page.anchors if parse_qs(urlsplit(urljoin(final.url, anchor["href"])).query).get("job") == [str(job_id)] and anchor["text"].strip() == job["job_code"] and client.resolve(urljoin(final.url, anchor["href"])) == location]
    recorder.check("PUBLIC-IMPORT-HTTP-DETAIL-AND-RECENT-HISTORY", len(history) == 1, {"job_id": job_id, "job_code": job["job_code"], "history_links": len(history)})
    localized = page.localized("cetechDeBulk")
    ajax_url = client.resolve(localized["ajaxUrl"])
    recorder.check("PUBLIC-IMPORT-HTTP-HTML-AJAX-NONCE", localized.get("action") == PROGRESS and bool(localized.get("nonce")) and urlsplit(ajax_url).path == "/wp-admin/admin-ajax.php", {"nonce_source": "server-rendered cetechDeBulk JSON; value intentionally excluded", "route": redacted_route(ajax_url)})

    def progress(advance: bool, request_client: HttpClient = client, nonce: str | None = None) -> Response:
        return request_client.post(ajax_url, {"action": localized["action"], "nonce": localized["nonce"] if nonce is None else nonce, "job_id": str(job_id), "advance": "1" if advance else "0"})

    def payload(response: Response) -> dict:
        value = json.loads(response.body)
        if not isinstance(value, dict):
            raise RuntimeError("Expected production AJAX JSON envelope")
        return value

    before = bridge.call("snapshot", job_id)
    response = progress(False)
    value = payload(response)
    recorder.check("PUBLIC-IMPORT-HTTP-PROGRESS-READ-READONLY", response.status == 200 and value.get("success") is True and value["data"].get("code") == job["job_code"] and value["data"].get("status") == "previewing" and same_state(before, bridge.call("snapshot", job_id)) and sentinel.encode() not in response.body, dict(response.evidence(), progress=value))
    response = progress(True, anonymous)
    recorder.check("PUBLIC-IMPORT-HTTP-UNAUTHENTICATED-AJAX-DENIED", response.status == 400 and response.body.strip() == b"0" and same_state(before, bridge.call("snapshot", job_id)), dict(response.evidence(), boundary="WordPress core has no wp_ajax_nopriv handler for the production action"))
    response = progress(True, nonce="qualification-invalid-nonce")
    recorder.check("PUBLIC-IMPORT-HTTP-BAD-AJAX-NONCE-DENIED", response.status == 403 and response.body.strip() == b"-1" and same_state(before, bridge.call("snapshot", job_id)), response.evidence())
    for tick in range(1, 21):
        response = progress(True)
        value = payload(response)
        recorder.check("PUBLIC-IMPORT-HTTP-PREVIEW-ADVANCE-" + str(tick), response.status == 200 and value.get("success") is True and value["data"].get("code") == job["job_code"] and sentinel.encode() not in response.body, dict(response.evidence(), progress=value))
        if value["data"].get("status") == "ready":
            break
    ready = bridge.call("snapshot", job_id)
    ready_job = ready["job"]
    recorder.check("PUBLIC-IMPORT-HTTP-READY-READONLY-PUBLIC-ITEM", ready_job["status"] == "ready" and ready_job["dry_run"] and ready_job["total_count"] == 1 and ready_job["processed_count"] == 1 and ready_job["changed_count"] == 1 and ready_job["failed_count"] == 0 and ready["items_count"] == 1 and ready["item_target_types"] == ["delivery_options"] and not ready["items_private_marker"] and ready["offer_count"] == 0 and ready["private_hashes"] == baseline["private_hashes"], snapshot_evidence(ready))
    response = client.get(location)
    apply_form = response.page().form_for_action(APPLY)
    recorder.check("PUBLIC-IMPORT-HTTP-RENDERED-READY-APPLY-FORM", response.status == 200 and "data-cetech-de-apply-preview" in apply_form.attrs and apply_form.fields.get("job_id") == str(job_id), response.evidence())
    apply_fields = dict(apply_form.fields)
    response = client.post(location, dict(apply_fields, cetech_de_nonce="qualification-invalid-nonce"))
    denial = client.get(client.location(response))
    recorder.check("PUBLIC-IMPORT-HTTP-BAD-APPLY-NONCE-DENIED", response.status == 302 and denial.status == 200 and denial.page().has_notice("error", "Security check failed") and same_state(ready, bridge.call("snapshot", job_id)), {"post": response.evidence(), "final": denial.evidence()})
    revoked = bridge.call("revoke", job_id)
    recorder.check("PUBLIC-IMPORT-HTTP-IMPORT-CAPABILITY-REVOKED", not revoked["grants"]["import_delivery_data"] and not revoked["grants"]["manage_private_sources"] and same_state(ready, revoked), snapshot_evidence(revoked))
    response = progress(True)
    value = payload(response)
    recorder.check("PUBLIC-IMPORT-HTTP-REVOKED-AJAX-DENIED", response.status == 403 and value.get("success") is False and value.get("data", {}).get("message") == "forbidden" and same_state(ready, bridge.call("snapshot", job_id)), dict(response.evidence(), progress=value))
    response = client.post(location, apply_fields)
    denial = client.get(client.location(response))
    page = denial.page()
    recorder.check("PUBLIC-IMPORT-HTTP-REVOKED-APPLY-DETAIL-HISTORY-DENIED", response.status == 302 and denial.status == 200 and page.has_notice("error", "permission") and str(job_id) not in page.job_ids and job["job_code"] not in page.text and not any(form.fields.get("cetech_de_action") == APPLY for form in page.forms) and same_state(ready, bridge.call("snapshot", job_id)), {"post": response.evidence(), "final": denial.evidence()})
    response = client.post(import_url, fields)
    denial = client.get(client.location(response))
    recorder.check("PUBLIC-IMPORT-HTTP-REVOKED-INITIATION-DENIED", response.status == 302 and denial.status == 200 and denial.page().has_notice("error", "permission") and same_state(ready, bridge.call("snapshot", job_id)), {"post": response.evidence(), "final": denial.evidence()})
    restored = bridge.call("grant", job_id)
    recorder.check("PUBLIC-IMPORT-HTTP-IMPORT-CAPABILITY-RESTORED", restored["grants"]["import_delivery_data"] and not restored["grants"]["manage_private_sources"] and same_state(ready, restored), snapshot_evidence(restored))
    response = client.post(location, apply_fields)
    apply_location = client.location(response)
    queued = bridge.call("snapshot", job_id)
    recorder.check("PUBLIC-IMPORT-HTTP-ACTUAL-APPLY-POST-QUEUED", response.status == 302 and parse_qs(urlsplit(apply_location).query).get("job") == [str(job_id)] and queued["job"]["status"] == "queued" and not queued["job"]["dry_run"] and queued["offer_count"] == 0 and queued["private_hashes"] == baseline["private_hashes"], dict(response.evidence(), sql=snapshot_evidence(queued), authority="same authenticated cookie and previously rendered Apply nonce after persisted role regrant"))
    final = client.get(apply_location)
    recorder.check("PUBLIC-IMPORT-HTTP-APPLY-FINAL-SUCCESS-NOTICE", final.status == 200 and final.page().has_notice("success", "Bulk job " + job["job_code"] + " is applying") and sentinel.encode() not in final.body, final.evidence())
    for tick in range(1, 21):
        response = progress(True)
        value = payload(response)
        recorder.check("PUBLIC-IMPORT-HTTP-APPLY-ADVANCE-" + str(tick), response.status == 200 and value.get("success") is True and value["data"].get("code") == job["job_code"] and sentinel.encode() not in response.body, dict(response.evidence(), progress=value))
        if value["data"].get("terminal"):
            break
    completed = bridge.call("snapshot", job_id)
    done_job = completed["job"]
    recorder.check("PUBLIC-IMPORT-HTTP-COMPLETED-PUBLIC-ROW-PRIVATE-STORES-UNCHANGED", done_job["status"] == "completed" and not done_job["dry_run"] and done_job["changed_count"] == 1 and done_job["failed_count"] == 0 and completed["offer_count"] == 1 and not done_job["contains_private_marker"] and not completed["items_private_marker"] and completed["private_hashes"] == baseline["private_hashes"] and not completed["grants"]["manage_private_sources"], snapshot_evidence(completed))
    response = client.get(apply_location)
    page = response.page()
    recorder.check("PUBLIC-IMPORT-HTTP-COMPLETED-DETAIL-RETAINED", response.status == 200 and str(job_id) in page.job_ids and job["job_code"] in page.text and "Completed" in page.text and not any(form.fields.get("cetech_de_action") == APPLY for form in page.forms) and sentinel.encode() not in response.body, response.evidence())
    recorder.check("PUBLIC-IMPORT-HTTP-BOUNDARY-RECORDED", True, {"proved": ["actual WordPress login form and authentication cookies", "native form/AJAX nonces obtained from server HTML", "initiating and Apply HTTP POSTs with real terminal302 redirects and success flashes", "actual PHP admin detail/current history", "real admin-ajax preview/Apply progression and persisted role revocation", "physical SQL projection/public row/private-store hashes"], "unproved": ["browser JavaScript execution or accessibility", "theme/customer frontend/cache/Redis isolation", "TLS or deployed-site cookie configuration", "external worker scheduling or worker principal policy", "private transitive references or granular field policy", "job-history pagination", "orders/payments/release/production"]})


def main() -> int:
    parser = argparse.ArgumentParser()
    for name in ("php", "wpcli", "site", "admin-context", "bridge", "state", "work", "receipt"):
        parser.add_argument("--" + name, required=True)
    parser.add_argument("--prepare-output", help="Redacted prepare output when the guarded shell prepared the fixture before starting its listener")
    parser.add_argument("--configuration-driver", help="Reviewed qualification-only module for the independent configuration principal")
    parser.add_argument("--configuration-bridge")
    parser.add_argument("--configuration-state")
    parser.add_argument("--emergency-driver")
    parser.add_argument("--emergency-bridge")
    parser.add_argument("--emergency-state")
    parser.add_argument("--quote-cart-driver")
    parser.add_argument("--quote-cart-bridge")
    parser.add_argument("--quote-cart-state")
    parser.add_argument("--quote-placement-driver")
    parser.add_argument("--quote-placement-bridge")
    options = parser.parse_args()
    if os.environ.get("CETECH_DE_NATIVE_OPENING_QUALIFICATION") != "1" or os.environ.get("CETECH_DE_HTTP_OPENING_QUALIFICATION") != "1" or os.environ.get("CETECH_DE_WP_DB_HOST") != "127.0.0.1":
        raise RuntimeError("Refusing HTTP qualification without explicit disposable loopback gates")
    workdir = Path(options.work)
    workdir.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(workdir, 0o700)
    receipt_path = Path(options.receipt)
    receipt_path.parent.mkdir(parents=True, exist_ok=True)
    bridge = FixtureBridge(options.php, options.wpcli, options.site, options.admin_context, options.bridge, Path(options.state), workdir)
    configuration_bridge = None
    emergency_bridge = None
    quote_cart_bridge = None
    quote_placement_bridge = None
    if any((options.configuration_driver, options.configuration_bridge, options.configuration_state)):
        if not all((options.configuration_driver, options.configuration_bridge, options.configuration_state)):
            raise RuntimeError("Configuration HTTP qualification requires all three explicit paths")
        # Avoid output-file collisions between independent fixture bridges.
        configuration_work = workdir / "configuration-snapshots"
        configuration_work.mkdir(mode=0o700, exist_ok=True)
        configuration_bridge = FixtureBridge(options.php, options.wpcli, options.site, options.admin_context, options.configuration_bridge, Path(options.configuration_state), configuration_work)
    if any((options.emergency_driver, options.emergency_bridge, options.emergency_state)):
        if not all((options.emergency_driver, options.emergency_bridge, options.emergency_state)):
            raise RuntimeError("Emergency HTTP qualification requires all three explicit paths")
        emergency_work = workdir / "emergency-snapshots"
        emergency_work.mkdir(mode=0o700, exist_ok=True)
        emergency_bridge = FixtureBridge(options.php, options.wpcli, options.site, options.admin_context, options.emergency_bridge, Path(options.emergency_state), emergency_work)
    if any((options.quote_cart_driver, options.quote_cart_bridge, options.quote_cart_state)):
        if not all((options.quote_cart_driver, options.quote_cart_bridge, options.quote_cart_state)):
            raise RuntimeError("Quote cart HTTP qualification requires all three explicit paths")
        quote_work = workdir / "quote-cart-snapshots"
        quote_work.mkdir(mode=0o700, exist_ok=True)
        quote_cart_bridge = FixtureBridge(options.php, options.wpcli, options.site, options.admin_context, options.quote_cart_bridge, Path(options.quote_cart_state), quote_work)
    if any((options.quote_placement_driver, options.quote_placement_bridge)):
        if not all((options.quote_placement_driver, options.quote_placement_bridge)) or quote_cart_bridge is None:
            raise RuntimeError("Quote placement requires its live quote cart fixture and both module paths")
        placement_work = workdir / "quote-placement-snapshots"
        placement_work.mkdir(mode=0o700, exist_ok=True)
        quote_placement_bridge = FixtureBridge(options.php, options.wpcli, options.site, options.admin_context, options.quote_placement_bridge, Path(options.quote_cart_state), placement_work)
    recorder = Recorder(receipt_path, {"source_head": os.environ.get("CETECH_DE_QUALIFICATION_HEAD", ""), "candidate_head": os.environ.get("CETECH_DE_QUALIFICATION_CANDIDATE_HEAD", ""), "source_tree": os.environ.get("CETECH_DE_QUALIFICATION_TREE", ""), "identity_verified": False})
    error = None
    quote_setup_stage = None
    prepared = False
    try:
        identity = json.loads(Path(options.prepare_output).read_text(encoding="utf-8")) if options.prepare_output else bridge.call("prepare")
        prepared = True
        state = json.loads(Path(options.state).read_text(encoding="utf-8"))
        recorder.report.update({key: value for key, value in identity.items() if key != "baseline"})
        recorder.report["identity_verified"] = True
        recorder.write()
        run_public_import(bridge, state, recorder, identity["baseline"])
        if configuration_bridge is not None:
            import importlib.util
            module_spec = importlib.util.spec_from_file_location("opening_http_configuration_driver", options.configuration_driver)
            if module_spec is None or module_spec.loader is None:
                raise RuntimeError("Could not load the qualification configuration module")
            module = importlib.util.module_from_spec(module_spec)
            module_spec.loader.exec_module(module)
            configuration_prepared = configuration_bridge.call("prepareconfig")
            configuration_identity = configuration_prepared["identity"]
            configuration_state = json.loads(Path(options.configuration_state).read_text(encoding="utf-8"))
            recorder.check("CONFIGURATION-HTTP-SAME-INSTALLED-SOURCE-IDENTITY", all(configuration_identity.get(key) == identity.get(key) for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources", "installed_php_sources_hash")), {"source_hash": configuration_identity.get("installed_php_sources_hash"), "installed_php_files": len(configuration_identity.get("installed_php_sources", {}))})
            module.run_configuration(HttpClient(configuration_state["base_url"]), configuration_state, configuration_bridge, recorder, Page, login)
        if emergency_bridge is not None:
            import importlib.util
            module_spec = importlib.util.spec_from_file_location("opening_http_emergency_driver", options.emergency_driver)
            if module_spec is None or module_spec.loader is None:
                raise RuntimeError("Could not load the emergency qualification module")
            module = importlib.util.module_from_spec(module_spec)
            module_spec.loader.exec_module(module)
            emergency_prepared = emergency_bridge.call("prepareemergency")
            emergency_identity = emergency_prepared["identity"]
            emergency_state = json.loads(Path(options.emergency_state).read_text(encoding="utf-8"))
            recorder.check("C07-HTTP-SAME-INSTALLED-SOURCE-IDENTITY", all(emergency_identity.get(key) == identity.get(key) for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources", "installed_php_sources_hash")), {"source_hash": emergency_identity.get("installed_php_sources_hash"), "installed_php_files": len(emergency_identity.get("installed_php_sources", {}))})
            module.run_emergency(HttpClient(emergency_state["base_url"]), emergency_state, emergency_bridge, recorder, Page, login)
        if quote_cart_bridge is not None:
            quote_setup_stage = "module"
            import importlib.util
            module_spec = importlib.util.spec_from_file_location("opening_http_quote_cart_driver", options.quote_cart_driver)
            if module_spec is None or module_spec.loader is None:
                raise RuntimeError("Could not load the quote cart qualification module")
            module = importlib.util.module_from_spec(module_spec)
            module_spec.loader.exec_module(module)
            quote_setup_stage = "prepare"
            quote_prepared = quote_cart_bridge.call("preparequotecart")
            quote_setup_stage = "identity"
            quote_identity = quote_prepared["identity"]
            if not all(quote_identity.get(key) == identity.get(key) for key in ("source_head", "candidate_head", "source_tree", "installed_php_sources", "installed_php_sources_hash")):
                raise RuntimeError("Quote cart fixture source identity does not match the qualified listener")
            quote_setup_stage = "state"
            quote_state = json.loads(Path(options.quote_cart_state).read_text(encoding="utf-8"))
            quote_setup_stage = "requests"
            module.run_quote_cart(HttpClient(quote_state["base_url"]), quote_state, quote_cart_bridge, recorder, Page, login)
        if quote_placement_bridge is not None:
            quote_setup_stage = "placement_prepare"
            module_spec = importlib.util.spec_from_file_location("opening_http_quote_placement", options.quote_placement_driver)
            if module_spec is None or module_spec.loader is None:
                raise RuntimeError("Could not load the quote placement qualification module")
            module = importlib.util.module_from_spec(module_spec)
            module_spec.loader.exec_module(module)
            placement_prepared = quote_placement_bridge.call("prepareplacement")
            if placement_prepared.get("ready") is not True or placement_prepared.get("identity") != quote_identity:
                raise RuntimeError("Quote placement source identity does not match its live fixture")
            quote_state = json.loads(Path(options.quote_cart_state).read_text(encoding="utf-8"))
            quote_setup_stage = "placement_requests"
            module.run_quote_placement(HttpClient(quote_state["base_url"]), quote_state, quote_placement_bridge, recorder, Page, login)
    except Exception as failure:
        error = type(failure).__name__ + ": HTTP qualification failed; inspect recorded case status and private runner logs"
        if quote_setup_stage is not None:
            recorder.report["quote_cart_failure_stage"] = quote_setup_stage
            safe_fixture_failure = getattr(failure, "quote_cart_fixture_diagnostic", None)
            if safe_fixture_failure is not None:
                recorder.report["quote_cart_fixture_failure"] = safe_fixture_failure
        if REQUEST_OBSERVATION:
            recorder.report["request_observation"] = {key: value for key, value in REQUEST_OBSERVATION.items() if key in ("ordinal", "method", "route", "phase", "elapsed_ms", "monotonic_ms", "error_class")}
            recorder.write()
    finally:
        if quote_placement_bridge is not None and quote_placement_bridge.state_path.is_file():
            try:
                state = json.loads(quote_placement_bridge.state_path.read_text(encoding="utf-8"))
                if "q06" in state:
                    cleaned = quote_placement_bridge.call("cleanupplacement")
                    module.record_cleanup(recorder, cleaned)
            except Exception as failure:
                error = error or (type(failure).__name__ + ": quote placement fixture cleanup failed")
        if quote_cart_bridge is not None and quote_cart_bridge.state_path.is_file():
            try:
                cleaned = quote_cart_bridge.call("cleanupquotecart")
                cleanup_keys = ("cleanup_restored", "domain35_and_quote_operation_history_restored", "raw_options_restored", "native_tax_method_session_rows_restored", "native_taxonomy_restored", "owned_products_removed", "native_wc_objects_restored", "all_owned_connections_retired", "owned_review_users_pages_removed")
                safe_cleanup = {key: cleaned.get(key) is True for key in cleanup_keys}
                recorder.check("HTTP-W2Q05-FIXTURE-CLEANUP", set(cleaned) == set(cleanup_keys) and all(safe_cleanup.values()), safe_cleanup)
            except Exception as failure:
                error = error or (type(failure).__name__ + ": quote cart fixture cleanup failed")
        if emergency_bridge is not None and emergency_bridge.state_path.is_file():
            try:
                cleaned = emergency_bridge.call("cleanupemergency")
                recorder.check("C07-HTTP-FIXTURE-CLEANUP", cleaned.get("cleanup_restored") is True and not cleaned.get("role_exists", True) and not cleaned.get("user_exists", True), {key: value for key, value in cleaned.items() if key in ("cleanup_restored", "role_exists", "user_exists", "history_preserved", "control_restored", "fixture_orders_removed", "fixture_products_removed")})
            except Exception as failure:
                error = error or (type(failure).__name__ + ": emergency fixture cleanup failed")
        if prepared or bridge.state_path.is_file():
            try:
                cleaned = bridge.call("cleanup")
                if recorder is not None:
                    recorder.check("PUBLIC-IMPORT-HTTP-FIXTURE-PRINCIPAL-CLEANUP", not cleaned["role_exists"] and not cleaned["user_exists"] and cleaned.get("cleanup_restored") is True, {"role_exists": cleaned["role_exists"], "user_exists": cleaned["user_exists"], "cleanup_restored": cleaned.get("cleanup_restored"), "sql": snapshot_evidence(cleaned)})
            except Exception as failure:
                error = error or (type(failure).__name__ + ": fixture cleanup failed")
        if configuration_bridge is not None and configuration_bridge.state_path.is_file():
            try:
                cleaned = configuration_bridge.call("cleanupconfig")
                recorder.check("CONFIGURATION-HTTP-FIXTURE-PRINCIPAL-CLEANUP", cleaned.get("cleanup_restored") is True and not cleaned.get("role_exists", True) and not cleaned.get("user_exists", True), {key: value for key, value in cleaned.items() if key in ("cleanup_restored", "role_exists", "user_exists", "cleaned_products", "no_tracked_field_orphans", "no_tracked_collection_orphans", "unrelated_configuration_and_private_sources_preserved", "restored_hashes", "cleanup_scope")})
            except Exception as failure:
                error = error or (type(failure).__name__ + ": configuration fixture cleanup failed")
        if recorder is not None:
            recorder.finish("FAIL" if error else "PASS", error)
    if error:
        print("opening_http_qualification=FAIL " + error, file=sys.stderr)
        return 1
    print("opening_http_qualification=PASS cases=" + str(len(recorder.report["cases"])))
    return 0


if __name__ == "__main__":
    sys.exit(main())
