#!/usr/bin/env python3
"""Pure native-response/setup-order contracts, never physical WordPress evidence."""
from copy import deepcopy
import importlib.util
import json
from pathlib import Path
import re
from types import SimpleNamespace
import unittest

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("promotion_placement_customer_setup_test", HERE / "opening-http-promotion-placement-driver.py")
driver = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(driver)


def facts():
    return {"contract_version": 1, "status": "no_quote", "generation": 0, "quote": None,
            "can_refresh": True, "can_confirm": False, "can_retry": False, "message_code": "no_quote",
            "correlation_id": "12345678-1234-4123-8123-123456789abc"}


def native_response():
    return {"billing_address": {**driver.FIXTURE_CHECKOUT_ADDRESS, "first_name": ""},
            "shipping_address": {**driver.FIXTURE_CHECKOUT_ADDRESS, "first_name": ""},
            "extensions": {"cetech-delivery-quote-review": facts()}}


class StopAtRefresh(Exception):
    pass


class Shopper:
    def __init__(self, state, payload=None, status=200, mutation=None):
        self.state = state
        self.payload = native_response() if payload is None else payload
        self.status = status
        self.calls = []
        self.configured = False
        self.mutation = mutation

    def json_post(self, url, payload, headers):
        self.calls.append(("customer", url, deepcopy(payload), deepcopy(headers)))
        self.configured = True
        return SimpleNamespace(status=self.status, body=json.dumps(self.payload))

    def get(self, url, headers):
        self.calls.append(("inspect", url))
        data = {"source_identity": self.state["identity"], "history_counts": dict.fromkeys(driver.public.HISTORY, 0),
                "orders_count": 0, "gateway_count": 0, "facts": facts(), "fixture_nonce": "fixture-nonce",
                "store_nonce": "store-nonce", "review_nonce": "review-nonce", "owner_digest": "fixed-owner",
                "native_session_key_digest": "fixed-session"}
        if self.configured and self.mutation:
            self.mutation(data)
        return SimpleNamespace(status=200, body=json.dumps({"success": True, "data": data}))

    def request(self, url, payload, headers):
        self.calls.append(("seed", url))
        return SimpleNamespace(status=200, body='{"success":true}')

    def post(self, url, payload):
        self.calls.append(("refresh", url, deepcopy(payload)))
        raise StopAtRefresh()


class Recorder:
    def __init__(self):
        self.report = {}

    def write(self):
        pass


class CustomerSetupTests(unittest.TestCase):
    def state(self):
        return {"identity": dict.fromkeys(("source_head", "candidate_head", "source_tree", "installed_php_sources_hash"), "fixture-source"),
                "fixture_token": "fixture-token", "fixture_url": "http://127.0.0.1/?cetech_q05_fixture=inspect",
                "seed_url": "http://127.0.0.1/?cetech_q05_fixture=seed", "classic_url": "http://127.0.0.1/?classic",
                "store_customer_url": "http://127.0.0.1/wp-json/wc/store/v1/cart/update-customer"}

    def test_declared_address_is_pinned_to_the_actual_per_line_fixture_source(self):
        source = (HERE / "opening-quote-provider-support.php").read_text()
        cart = source.split("public function cart(", 1)[1].split("public function recalculate(", 1)[0]
        declaration = re.search(r"DeliveryAddress::fromInput\(\s*\[(.*?)\]\s*\)", cart, re.S)
        self.assertIsNotNone(declaration)
        values = dict(re.findall(r"'([a-z0-9_]+)'\s*=>\s*'([^']*)'", declaration.group(1)))
        self.assertEqual(driver.FIXTURE_CHECKOUT_ADDRESS,
                         {key: values.get(key, "") for key in driver.FIXTURE_CHECKOUT_ADDRESS})

    def test_native_update_uses_the_real_nonce_and_both_explicit_address_tuples(self):
        state = self.state(); shopper = Shopper(state)
        driver.setup_native_customer(shopper, state, "real-session-nonce")
        self.assertEqual([("customer", state["store_customer_url"],
                           {"billing_address": driver.FIXTURE_CHECKOUT_ADDRESS, "shipping_address": driver.FIXTURE_CHECKOUT_ADDRESS},
                           {"Nonce": "real-session-nonce"})], shopper.calls)

    def test_every_native_address_echo_field_is_required_before_refresh(self):
        for kind in ("billing_address", "shipping_address"):
            for field in driver.FIXTURE_CHECKOUT_ADDRESS:
                for value in (None, 123, "different"):
                    with self.subTest(kind=kind, field=field, value=value):
                        packet = native_response(); packet[kind][field] = value
                        shopper = Shopper(self.state(), packet)
                        with self.assertRaises(RuntimeError):
                            driver.setup_native_customer(shopper, self.state(), "nonce")
                        self.assertEqual(["customer"], [call[0] for call in shopper.calls])

    def test_unsafe_or_missing_review_dto_and_unsuccessful_native_update_refuse(self):
        packets = [[], {}, {**native_response(), "extensions": []}, {**native_response(), "extensions": {}}]
        unsafe = native_response(); unsafe["extensions"]["cetech-delivery-quote-review"]["owner_digest"] = "private"
        packets.append(unsafe)
        for packet in packets:
            with self.subTest(packet=type(packet).__name__), self.assertRaises(RuntimeError):
                driver.setup_native_customer(Shopper(self.state(), packet), self.state(), "nonce")
        with self.assertRaises(RuntimeError):
            driver.setup_native_customer(Shopper(self.state(), status=403), self.state(), "nonce")

    def test_actual_driver_updates_and_reads_customer_before_first_refresh(self):
        state = self.state(); shopper = Shopper(state); recorder = Recorder()
        with self.assertRaises(StopAtRefresh):
            driver.run(shopper, state, recorder)
        self.assertEqual(["inspect", "seed", "inspect", "customer", "inspect", "refresh"], [call[0] for call in shopper.calls])
        self.assertEqual("native_refresh", recorder.report["stage"])

    def test_setup_readback_cannot_change_history_orders_gateways_or_session_identity(self):
        mutations = [lambda data: data["history_counts"].update(quotes=1), lambda data: data.update(orders_count=1),
                     lambda data: data.update(gateway_count=1), lambda data: data.update(owner_digest="another-owner"),
                     lambda data: data.update(native_session_key_digest="another-session")]
        for mutation in mutations:
            shopper = Shopper(self.state(), mutation=mutation)
            with self.assertRaises(RuntimeError):
                driver.run(shopper, self.state(), Recorder())
            self.assertNotIn("refresh", [call[0] for call in shopper.calls])


if __name__ == "__main__":
    unittest.main()
